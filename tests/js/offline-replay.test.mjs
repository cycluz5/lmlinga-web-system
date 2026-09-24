/**
 * Offline replay coordinator — resources/js/offline/offline-replay.js
 */

import assert from 'node:assert/strict';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href
);
const { enqueueOperation, getOperation, listOperations, markAttention } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const { createReplayCoordinator, resetReplayLock, classifySyncFailure } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-replay.js')).href
);

function createRoot(actorId = 7) {
    return {
        getAttribute(name) {
            const attrs = {
                'data-offline-status-url': '/offline/status',
                'data-offline-sync-url': '/offline/sync',
                'data-offline-actor-id': String(actorId),
                'data-offline-actor-username': 'bhw.ana',
            };
            return attrs[name] || null;
        },
    };
}

function jsonResponse(body, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

function createWin() {
    const listeners = new Map();
    const timers = [];
    return {
        navigator: { onLine: true },
        setTimeout(fn, ms) {
            const id = timers.push({ fn, ms }) && timers.length;
            return id;
        },
        clearTimeout() {},
        addEventListener(type, fn) {
            if (!listeners.has(type)) {
                listeners.set(type, []);
            }
            listeners.get(type).push(fn);
        },
        dispatchEvent(event) {
            (listeners.get(event.type) || []).forEach((handler) => handler(event));
            return true;
        },
        flushTimers() {
            const due = timers.splice(0, timers.length);
            due.forEach((item) => item.fn());
        },
    };
}

async function seed(overrides = {}) {
    return enqueueOperation({
        actor_id: 7,
        actor_username: 'bhw.ana',
        operation_type: 'HOUSEHOLD_CREATE',
        payload: { household_no: '121', zone: 'Zone 2' },
        ...overrides,
    });
}

beforeEach(() => {
    resetReplayLock();
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetReplayLock();
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

describe('offline replay coordinator', () => {
    it('removes SYNCED and ALREADY_APPLIED items and keeps CSRF out of the queue', async () => {
        const synced = await seed();
        const already = await seed({ payload: { household_no: '122', zone: 'Zone 1' } });
        const events = [];
        const posts = [];
        const fetchImpl = async (url, init) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 7,
                    username: 'bhw.ana',
                    csrf_token: 'fresh-csrf',
                    is_active: true,
                    must_change_password: true,
                });
            }
            posts.push(JSON.parse(init.body));
            const envelope = JSON.parse(init.body);
            if (envelope.operation_id === synced.operation_id) {
                return jsonResponse({ ok: true, code: 'SYNCED', operation_id: envelope.operation_id });
            }
            return jsonResponse({ ok: true, code: 'ALREADY_APPLIED', operation_id: envelope.operation_id });
        };

        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });

        await coordinator.replay();

        assert.equal((await listOperations()).length, 0);
        assert.equal(posts.length, 2);
        assert.equal(posts[0].operation_id, synced.operation_id);
        assert.equal(JSON.stringify(posts).includes('fresh-csrf'), false);
        assert.equal(JSON.stringify(await listOperations()).includes('fresh-csrf'), false);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-start'), true);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-success'), true);
        assert.ok(already.operation_id);
    });

    it('retains RETRYABLE_ERROR and network failures', async () => {
        const retryable = await seed();
        const network = await seed({ payload: { household_no: '123', zone: 'Zone 3' } });
        let calls = 0;
        const fetchImpl = async (url, init) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 7,
                    csrf_token: 'token',
                    is_active: true,
                });
            }
            calls += 1;
            if (calls === 1) {
                return jsonResponse({ ok: false, code: 'RETRYABLE_ERROR' }, 503);
            }
            throw new TypeError('Failed to fetch');
        };

        const first = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
        });
        await first.replay();
        const afterRetry = await getOperation(retryable.local_id);
        assert.equal(afterRetry.status, 'retry');
        assert.equal(afterRetry.operation_id, retryable.operation_id);

        resetReplayLock();
        const second = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
        });
        await updateToPending(network.local_id);
        await second.replay();
        const remaining = await listOperations();
        assert.ok(remaining.length >= 1);
        assert.ok(remaining.every((item) => item.status === 'retry' || item.status === 'pending' || item.status === 'syncing'));
    });

    it('moves conflict and validation codes to attention without deleting them', async () => {
        const codes = ['TARGET_CHANGED', 'TARGET_MISSING', 'VALIDATION_FAILED'];
        const records = [];
        for (const [index, code] of codes.entries()) {
            records.push(await seed({ payload: { household_no: String(200 + index), zone: 'Zone 1' } }));
        }

        let index = 0;
        const fetchImpl = async (url) => {
            if (url === '/offline/status') {
                return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
            }
            const code = codes[index];
            index += 1;
            return jsonResponse({ ok: false, code }, 409);
        };

        const events = [];
        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();

        for (const record of records) {
            const stored = await getOperation(record.local_id);
            assert.equal(stored.status, 'attention');
        }
        assert.ok(events.filter((item) => item.name === 'lmlinga:sync-attention').length >= 3);
    });

    it('does not replay another signed-in actor\'s queue', async () => {
        await seed({ actor_id: 7 });
        const events = [];
        const posts = [];
        const fetchImpl = async (url, init) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 99,
                    username: 'bhw.other',
                    csrf_token: 'token',
                    is_active: true,
                });
            }
            posts.push(init);
            return jsonResponse({ ok: true, code: 'SYNCED' });
        };

        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(99),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();

        assert.equal(posts.length, 0);
        assert.equal((await listOperations()).length, 1);
        assert.equal((await listOperations())[0].actor_id, 7);
        assert.equal(
            events.some((item) => item.name === 'lmlinga:sync-attention' && item.detail.code === 'QUEUE_ACTOR_MISMATCH'),
            true,
        );
    });

    it('prevents a concurrent duplicate replay loop', async () => {
        await seed();
        let statusStarts = 0;
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        const fetchImpl = async (url) => {
            if (url === '/offline/status') {
                statusStarts += 1;
                await gate;
                return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
            }
            return jsonResponse({ ok: true, code: 'SYNCED' });
        };

        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
        });

        const first = coordinator.replay();
        const second = coordinator.replay();
        assert.equal(first, second);
        release();
        await first;
        assert.equal(statusStarts, 1);
    });

    it('refreshes CSRF once then stops on a second mismatch', async () => {
        await seed();
        let statusCalls = 0;
        let syncCalls = 0;
        const fetchImpl = async (url) => {
            if (url === '/offline/status') {
                statusCalls += 1;
                return jsonResponse({ ok: true, user_id: 7, csrf_token: `token-${statusCalls}`, is_active: true });
            }
            syncCalls += 1;
            return jsonResponse({ ok: false, code: 'CSRF_MISMATCH' }, 419);
        };

        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
        });
        await coordinator.replay();

        assert.ok(statusCalls >= 2);
        assert.ok(syncCalls >= 2);
        assert.equal((await listOperations()).length, 1);
    });

    it('does not report all caught up when an attention operation remains', async () => {
        const queued = await seed({
            operation_type: 'PLOT_HOUSEHOLD_WITH_HEAD',
            payload: {
                birthday: '1994-06-07',
                civil_status: 'Single',
                client_marker_id: 'demo-temp-3fa85f64-5717-4562-b3fc-2c963f66afa6',
                consent: true,
                date_registered: '2026-09-06',
                first_name: 'Zai',
                household_no: '210',
                household_type: 'HHTS',
                last_name: 'Kluz',
                lat: 13.376191299053865,
                lng: 123.43171525236092,
                middle_name: '',
                sex: 'Male',
                zone: 5,
            },
        });
        await markAttention(queued.local_id, 'VALIDATION_FAILED');
        const events = [];
        const coordinator = createReplayCoordinator({
            fetch: async (url) => {
                if (url === '/offline/status') {
                    return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
                }
                return jsonResponse({ ok: true, code: 'SYNCED' });
            },
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();

        assert.equal((await getOperation(queued.local_id)).status, 'attention');
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-success'), false);
        assert.equal(
            events.some(
                (item) =>
                    item.name === 'lmlinga:sync-attention' &&
                    item.detail.code === 'VALIDATION_FAILED' &&
                    item.detail.pending === 1 &&
                    item.detail.id === queued.local_id,
            ),
            true,
        );
    });

    it('retries a stuck MALFORMED plot operation once and removes it on SYNCED', async () => {
        const queued = await seed({
            operation_type: 'PLOT_HOUSEHOLD_WITH_HEAD',
            payload: {
                birthday: '1994-06-07',
                first_name: 'Zai',
                last_name: 'Kluz',
                household_no: '210',
                household_type: 'HHTS',
                zone: 5,
                lat: 13.376191299053865,
                lng: 123.43171525236092,
                consent: true,
            },
        });
        await markAttention(queued.local_id, 'MALFORMED');
        const posts = [];
        const coordinator = createReplayCoordinator({
            fetch: async (url, init) => {
                if (url === '/offline/status') {
                    return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
                }
                posts.push(JSON.parse(init.body));
                return jsonResponse({ ok: true, code: 'SYNCED', operation_id: queued.operation_id });
            },
            root: createRoot(),
            window: createWin(),
        });
        await coordinator.replay();

        assert.equal(posts.length, 1);
        assert.equal(posts[0].operation_id, queued.operation_id);
        assert.equal(posts[0].payload.zone, 5);
        assert.equal((await listOperations()).length, 0);
    });

    it('keeps a failed create queued and removes the successful ones', async () => {
        const makeCreate = (id, name) => enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: id,
                first_name: name,
                last_name: 'Local',
                relation: 'Son',
                sex: 'Male',
                birthday: '2015-01-01',
            },
            parent_server: { household_id: 12, household_no: 'HH-201' },
        });
        const first = await makeCreate('MB-L-okA', 'A');
        const failed = await makeCreate('MB-L-failC', 'C');
        const third = await makeCreate('MB-L-okD', 'D');
        const events = [];
        const fetchImpl = async (url, init) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 7,
                    username: 'bhw.ana',
                    csrf_token: 'token',
                    is_active: true,
                });
            }
            const envelope = JSON.parse(init.body);
            if (envelope.operation_id === failed.operation_id) {
                return jsonResponse({ ok: false, code: 'VALIDATION_FAILED' }, 422);
            }
            const memberNo = envelope.operation_id === first.operation_id ? 'MB-091' : 'MB-092';
            return jsonResponse({
                ok: true,
                code: 'SYNCED',
                operation_id: envelope.operation_id,
                resident: { id: memberNo === 'MB-091' ? 91 : 92, member_no: memberNo, field_hash: 'a'.repeat(64) },
                household: { id: 12, household_no: 'HH-201' },
            });
        };

        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();

        const remaining = await listOperations();
        assert.equal(remaining.length, 1);
        assert.equal(remaining[0].operation_id, failed.operation_id);
        assert.equal(remaining[0].status, 'attention');
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-success'), false);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-attention'), true);
        assert.ok(first.operation_id && third.operation_id);
    });

    it('marks a legacy sex-null create as attention with a recoverable member hint', async () => {
        const queued = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-vicky1',
                first_name: 'Vicky',
                last_name: 'Morales',
                relation: 'Daughter',
                sex: null,
                birthday: '2018-05-01',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        const events = [];
        const fetchImpl = async (url) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 7,
                    username: 'bhw.ana',
                    csrf_token: 'token',
                    is_active: true,
                });
            }
            return jsonResponse({
                ok: false,
                code: 'VALIDATION_FAILED',
                message: 'The sex field is required.',
                errors: { sex: ['The sex field is required.'] },
            }, 422);
        };
        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();

        const remaining = await getOperation(queued.local_id);
        assert.equal(remaining.status, 'attention');
        assert.equal(remaining.last_safe_error_code, 'VALIDATION_FAILED');
        const attention = events.find((item) => item.name === 'lmlinga:sync-attention');
        assert.equal(attention.detail.code, 'VALIDATION_FAILED');
        assert.equal(attention.detail.household_no, '999');
        assert.equal(attention.detail.member_name, 'Vicky Morales');
        assert.equal(attention.detail.reason, 'The sex field is required.');
        assert.equal(attention.detail.operation_type, 'RESIDENT_CREATE');
        assert.equal(attention.detail.href, '/household-profiling/999/members/MB-L-vicky1/edit');
        assert.deepEqual(remaining.last_validation_errors, { sex: ['The sex field is required.'] });
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-success'), false);
    });

    it('replays a repaired attention create and removes the queue row', async () => {
        const queued = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-vicky1',
                first_name: 'Vicky',
                last_name: 'Morales',
                sex: null,
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        await markAttention(queued.local_id, 'VALIDATION_FAILED');
        const repaired = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-vicky1',
                first_name: 'Vicky',
                last_name: 'Morales',
                sex: 'Male',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999', member_no: 'MB-L-vicky1' },
        });
        assert.equal(repaired.local_id, queued.local_id);
        assert.equal(repaired.status, 'pending');
        assert.equal(repaired.payload.sex, 'Male');

        const events = [];
        const fetchImpl = async (url, init) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 7,
                    username: 'bhw.ana',
                    csrf_token: 'token',
                    is_active: true,
                });
            }
            const envelope = JSON.parse(init.body);
            assert.equal(envelope.operation_id, queued.operation_id);
            assert.equal(envelope.payload.sex, 'Male');
            assert.equal(envelope.payload.philhealth, '123456789012');
            return jsonResponse({
                ok: true,
                code: 'SYNCED',
                operation_id: envelope.operation_id,
                resident: { id: 88, member_no: 'MB-088', field_hash: 'a'.repeat(64) },
                household: { id: 999, household_no: '999' },
            });
        };
        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();

        assert.equal((await listOperations()).length, 0);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-attention'), false);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-success'), true);
        const success = events.find((item) => item.name === 'lmlinga:sync-success');
        assert.equal(success.detail.synced[0].identities.member_no, 'MB-088');
    });

    it('never marks a valid new create as attention', async () => {
        await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-valid1',
                first_name: 'Miles',
                last_name: 'Morales',
                relation: 'Son',
                sex: 'Male',
                birthday: '2015-01-01',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        const events = [];
        const coordinator = createReplayCoordinator({
            fetch: async (url) => {
                if (url === '/offline/status') {
                    return jsonResponse({
                        ok: true,
                        user_id: 7,
                        username: 'bhw.ana',
                        csrf_token: 'token',
                        is_active: true,
                    });
                }
                return jsonResponse({
                    ok: true,
                    code: 'SYNCED',
                    resident: { id: 77, member_no: 'MB-077', field_hash: 'a'.repeat(64) },
                    household: { id: 999, household_no: '999' },
                });
            },
            root: createRoot(),
            window: createWin(),
            onEvent: (name, detail) => events.push({ name, detail }),
        });
        await coordinator.replay();
        assert.equal((await listOperations()).length, 0);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-attention'), false);
        assert.equal(events.some((item) => item.name === 'lmlinga:sync-success'), true);
    });

    it('promotes mixed successes and leaves a repaired attention create retryable', async () => {
        const makeCreate = (id, name, sex) => enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: id,
                first_name: name,
                last_name: 'Local',
                relation: 'Son',
                sex,
                birthday: '2015-01-01',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 12, household_no: 'HH-201' },
        });
        const first = await makeCreate('MB-L-okA', 'A', 'Male');
        const failed = await makeCreate('MB-L-failB', 'B', null);
        const third = await makeCreate('MB-L-okC', 'C', 'Female');
        const fetchImpl = async (url, init) => {
            if (url === '/offline/status') {
                return jsonResponse({
                    ok: true,
                    user_id: 7,
                    username: 'bhw.ana',
                    csrf_token: 'token',
                    is_active: true,
                });
            }
            const envelope = JSON.parse(init.body);
            if (envelope.operation_id === failed.operation_id && envelope.payload.sex == null) {
                return jsonResponse({
                    ok: false,
                    code: 'VALIDATION_FAILED',
                    errors: { sex: ['The sex field is required.'] },
                }, 422);
            }
            const memberNo = envelope.operation_id === first.operation_id
                ? 'MB-091'
                : envelope.operation_id === third.operation_id
                    ? 'MB-092'
                    : 'MB-093';
            return jsonResponse({
                ok: true,
                code: 'SYNCED',
                operation_id: envelope.operation_id,
                resident: {
                    id: memberNo === 'MB-091' ? 91 : memberNo === 'MB-092' ? 92 : 93,
                    member_no: memberNo,
                    field_hash: 'a'.repeat(64),
                },
                household: { id: 12, household_no: 'HH-201' },
            });
        };
        const coordinator = createReplayCoordinator({
            fetch: fetchImpl,
            root: createRoot(),
            window: createWin(),
        });
        await coordinator.replay();
        let remaining = await listOperations();
        assert.equal(remaining.length, 1);
        assert.equal(remaining[0].operation_id, failed.operation_id);
        assert.equal(remaining[0].status, 'attention');

        const repaired = await enqueueOperation({
            actor_id: 7,
            actor_username: 'bhw.ana',
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-failB',
                first_name: 'B',
                last_name: 'Local',
                relation: 'Son',
                sex: 'Male',
                birthday: '2015-01-01',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 12, household_no: 'HH-201', member_no: 'MB-L-failB' },
        });
        assert.equal(repaired.local_id, failed.local_id);
        assert.equal(repaired.status, 'pending');
        assert.equal((await listOperations()).length, 1);

        await coordinator.replay();
        remaining = await listOperations();
        assert.equal(remaining.length, 0);
    });

    it('classifies unparseable sync bodies as retryable instead of MALFORMED', () => {
        assert.deepEqual(
            classifySyncFailure({ response: { ok: false, status: 200 }, payload: null }),
            { kind: 'retry', code: 'RETRYABLE_ERROR' },
        );
        assert.deepEqual(
            classifySyncFailure({
                response: { ok: false, status: 422 },
                payload: { message: 'First name is required.', errors: { first_name: ['First name is required.'] } },
            }),
            {
                kind: 'attention',
                code: 'VALIDATION_FAILED',
                errors: { first_name: ['First name is required.'] },
            },
        );
        assert.deepEqual(
            classifySyncFailure({
                response: { ok: false, status: 422 },
                payload: { code: 'MALFORMED', errors: { payload: ['The payload field is required.'] } },
            }),
            {
                kind: 'attention',
                code: 'MALFORMED',
                errors: { payload: ['The payload field is required.'] },
            },
        );
    });
});

async function updateToPending(localId) {
    const { updateOperation } = await import(
        pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
    );
    return updateOperation(localId, { status: 'pending', next_retry_at: null });
}
