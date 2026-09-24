/**
 * Phase-1 Health Worker UPDATE queue: safe fields only, no passwords/files,
 * no HEALTH_WORKER_CREATE, User Management reload after successful sync.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from '../../resources/js/offline/offline-status.js';
import {
    healthWorkerUpdateOfflineGuard,
    hasOfflinePasswordChange,
    hasOfflinePhotoChange,
} from '../../resources/js/offline/offline-um-forms.js';
import { bindUserManagementSyncReload } from '../../resources/js/offline/offline-um-nav-guard.js';

const formsSource = readFileSync(path.resolve('resources/js/offline/offline-forms.js'), 'utf8');
const queueSource = readFileSync(path.resolve('resources/js/offline/offline-queue.js'), 'utf8');
const typeSource = readFileSync(path.resolve('app/Support/Offline/OfflineOperationType.php'), 'utf8');
const editBlade = readFileSync(
    path.resolve('resources/views/pages/user-management/health-worker-edit.blade.php'),
    'utf8',
);
const createBlade = readFileSync(
    path.resolve('resources/views/pages/user-management/health-worker-create.blade.php'),
    'utf8',
);
const pageSource = readFileSync(path.resolve('resources/js/pages/user-management.js'), 'utf8');

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href
);
const { listOperations, enqueueOperation } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const {
    OPERATION_TYPES,
    serializeSupportedForm,
    handleSupportedFormSubmit,
    parentServerFromForm,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href);
const { createReplayCoordinator, resetReplayLock } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-replay.js')).href
);

function field(overrides) {
    return {
        name: '',
        type: 'text',
        value: '',
        disabled: false,
        readOnly: false,
        checked: false,
        files: { length: 0 },
        ...overrides,
    };
}

function fakeForm(fields, attrs = {}) {
    const data = { ...attrs };
    return {
        elements: fields,
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(data, name) ? data[name] : null;
        },
        setAttribute(name, value) {
            data[name] = value;
        },
        querySelector(selector) {
            if (selector.includes('hw_password_confirmation') || selector.includes('password_confirmation')) {
                return fields.find((item) => item.name === 'hw_password_confirmation') || null;
            }
            if (selector.includes('hw_password') || selector.includes('password')) {
                return fields.find((item) => item.name === 'hw_password') || null;
            }
            if (selector.includes('hw_photo') || selector.includes('photo-input')) {
                return fields.find((item) => item.name === 'hw_photo') || null;
            }
            if (selector.includes('hw_remove_photo') || selector.includes('remove-photo')) {
                return fields.find((item) => item.name === 'hw_remove_photo') || null;
            }
            return null;
        },
        parentElement: null,
    };
}

function actorRoot() {
    return {
        getAttribute(name) {
            if (name === 'data-offline-actor-id') {
                return '7';
            }
            if (name === 'data-offline-actor-username') {
                return 'admin.one';
            }
            return null;
        },
        closest() {
            return this;
        },
    };
}

function hwFields(overrides = {}) {
    const fields = [
        field({ name: '_token', value: 'csrf-live' }),
        field({ name: '_method', value: 'PUT' }),
        field({ name: 'worker_id', value: '12' }),
        field({ name: 'hw_remove_photo', value: '0' }),
        field({ name: 'sex', type: 'radio', value: 'Female', checked: true }),
        field({ name: 'hw_first_name', value: 'Maria' }),
        field({ name: 'hw_last_name', value: 'Reyes' }),
        field({ name: 'hw_middle_name', value: 'Cruz' }),
        field({ name: 'hw_email', value: 'maria.reyes.db@example.test' }),
        field({ name: 'hw_username', value: 'maria.reyes.db' }),
        field({ name: 'hw_status', type: 'select-one', value: 'Active' }),
        field({ name: 'hw_password', type: 'password', value: '' }),
        field({ name: 'hw_password_confirmation', type: 'password', value: '' }),
        field({ name: 'hw_photo', type: 'file', value: '', files: { length: 0 } }),
    ];
    Object.entries(overrides).forEach(([name, patch]) => {
        const existing = fields.find((item) => item.name === name);
        if (existing) {
            Object.assign(existing, patch);
            return;
        }
        fields.push(field({ name, ...patch }));
    });
    return fields;
}

const FORM_ATTRS = {
    'data-offline-operation': 'HEALTH_WORKER_UPDATE',
    'data-offline-parent-user-id': '12',
    'data-offline-field-hash': 'a'.repeat(64),
};

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

describe('health worker update source contract', () => {
    it('adds HEALTH_WORKER_UPDATE only and never stores create/passwords/files', () => {
        assert.match(formsSource, /HEALTH_WORKER_UPDATE/);
        assert.doesNotMatch(formsSource, /HEALTH_WORKER_CREATE/);
        assert.match(typeSource, /HEALTH_WORKER_UPDATE/);
        assert.doesNotMatch(typeSource, /HEALTH_WORKER_CREATE/);
        assert.match(editBlade, /data-offline-operation="HEALTH_WORKER_UPDATE"/);
        assert.match(editBlade, /data-offline-parent-user-id/);
        assert.match(editBlade, /data-offline-field-hash/);
        assert.doesNotMatch(createBlade, /data-offline-operation/);
        assert.match(queueSource, /assertNoSecrets/);
        assert.equal(OPERATION_TYPES.HEALTH_WORKER_UPDATE, 'HEALTH_WORKER_UPDATE');
        assert.equal(OPERATION_TYPES.HEALTH_WORKER_CREATE, undefined);
        assert.match(pageSource, /bindUserManagementSyncReload/);
        assert.equal(OFFLINE_MESSAGES.passwordRequiresConnection, 'Password changes require a connection.');
        assert.equal(OFFLINE_MESSAGES.photoRequiresConnection, 'Profile photo changes require a connection.');
    });
});

describe('health worker update queue', () => {
    it('queues HEALTH_WORKER_UPDATE with parent_server.user_id and strips secrets/files/pks', async () => {
        const form = fakeForm(hwFields(), FORM_ATTRS);
        const payload = serializeSupportedForm(form);
        assert.equal(payload.hw_first_name, 'Maria');
        assert.equal(payload.hw_password, undefined);
        assert.equal(payload.hw_photo, undefined);
        assert.equal(payload.worker_id, undefined);
        assert.equal(payload._token, undefined);
        assert.deepEqual(parentServerFromForm(form), { user_id: 12 });

        const event = {
            currentTarget: form,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        const queued = await handleSupportedFormSubmit(event, {
            navigator: { onLine: false },
            root: actorRoot(),
        });
        assert.equal(queued, true);
        const stored = await listOperations();
        assert.equal(stored.length, 1);
        assert.equal(stored[0].operation_type, 'HEALTH_WORKER_UPDATE');
        assert.equal(stored[0].parent_server.user_id, 12);
        assert.equal(stored[0].payload.hw_first_name, 'Maria');
        assert.equal(JSON.stringify(stored[0]).toLowerCase().includes('password'), false);
        assert.equal(JSON.stringify(stored[0]).includes('csrf'), false);
        assert.equal(stored[0].payload.hw_photo, undefined);
        assert.equal(stored[0].payload.worker_id, undefined);
        assert.match(stored[0].operation_id, /^[0-9a-f-]{36}$/i);
    });

    it('does not duplicate the queue row on a second offline submit', async () => {
        const form = fakeForm(hwFields(), FORM_ATTRS);
        const first = {
            currentTarget: form,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        await handleSupportedFormSubmit(first, { navigator: { onLine: false }, root: actorRoot() });
        const second = {
            currentTarget: form,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
        };
        await handleSupportedFormSubmit(second, { navigator: { onLine: false }, root: actorRoot() });
        assert.equal((await listOperations()).length, 1);
        assert.equal(form.getAttribute('data-offline-queued'), '1');
    });

    it('blocks password-filled offline updates and never queues the secret', async () => {
        const form = fakeForm(
            hwFields({
                hw_password: { type: 'password', value: 'SecretPass!123' },
                hw_password_confirmation: { type: 'password', value: 'SecretPass!123' },
            }),
            FORM_ATTRS,
        );
        assert.equal(hasOfflinePasswordChange(form), true);
        const guard = healthWorkerUpdateOfflineGuard(form, { navigator: { onLine: false } });
        assert.equal(guard.allow, false);
        assert.equal(guard.reason, 'password');
        assert.equal(guard.message, 'Password changes require a connection.');
        assert.equal((await listOperations()).length, 0);
        const serialized = serializeSupportedForm(form);
        assert.equal(serialized.hw_password, undefined);
    });

    it('blocks photo-selected offline updates and never serializes the file', async () => {
        const form = fakeForm(
            hwFields({
                hw_photo: { type: 'file', value: 'avatar.png', files: { length: 1 } },
            }),
            FORM_ATTRS,
        );
        assert.equal(hasOfflinePhotoChange(form), true);
        const guard = healthWorkerUpdateOfflineGuard(form, { navigator: { onLine: false } });
        assert.equal(guard.allow, false);
        assert.equal(guard.reason, 'photo');
        assert.equal(guard.message, 'Profile photo changes require a connection.');
        assert.equal(serializeSupportedForm(form).hw_photo, undefined);
        assert.equal((await listOperations()).length, 0);
    });

    it('Add while offline produces no IndexedDB operation', async () => {
        assert.equal((await listOperations()).length, 0);
        const createSource = readFileSync(
            path.resolve('resources/js/pages/user-management-create.js'),
            'utf8',
        );
        assert.doesNotMatch(createSource, /enqueueOperation/);
        assert.doesNotMatch(createSource, /HEALTH_WORKER_CREATE/);
        assert.doesNotMatch(createSource, /HEALTH_WORKER_UPDATE/);
    });
});

describe('health worker update replay and reconcile', () => {
    function jsonResponse(body, status = 200) {
        return {
            ok: status >= 200 && status < 300,
            status,
            json: async () => body,
        };
    }

    function createWin() {
        const listeners = new Map();
        return {
            setTimeout() {
                return 1;
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
            location: {
                reloadCalls: 0,
                reload() {
                    this.reloadCalls += 1;
                },
            },
        };
    }

    it('removes the IndexedDB row after SYNCED and reloads User Management', async () => {
        const queued = await enqueueOperation({
            actor_id: 7,
            actor_username: 'admin.one',
            operation_type: 'HEALTH_WORKER_UPDATE',
            payload: { hw_first_name: 'Maria' },
            parent_server: { user_id: 12 },
            base_snapshot: { field_hash: 'a'.repeat(64) },
        });
        const doc = createDocument();
        const html = doc.createElement('div');
        const root = doc.createElement('div');
        root.setAttribute('data-lml-user-mgmt', '');
        html.appendChild(root);
        doc.documentElement = html;
        const win = createWin();
        bindUserManagementSyncReload({ window: win, document: doc });

        const coordinator = createReplayCoordinator({
            fetch: async (url) => {
                if (url === '/offline/status') {
                    return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
                }
                return jsonResponse({ ok: true, code: 'SYNCED', operation_id: queued.operation_id });
            },
            root: actorRoot(),
            window: win,
        });
        await coordinator.replay();

        assert.equal((await listOperations()).length, 0);
        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_SUCCESS, detail: { pending: 0 } });
        assert.equal(win.location.reloadCalls, 1);
    });

    it('keeps the operation on 5xx and on session expiry', async () => {
        const retryable = await enqueueOperation({
            actor_id: 7,
            actor_username: 'admin.one',
            operation_type: 'HEALTH_WORKER_UPDATE',
            payload: { hw_first_name: 'Maria' },
            parent_server: { user_id: 12 },
            base_snapshot: { field_hash: 'a'.repeat(64) },
        });
        const first = createReplayCoordinator({
            fetch: async (url) => {
                if (url === '/offline/status') {
                    return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
                }
                return jsonResponse({ ok: false, code: 'RETRYABLE_ERROR' }, 503);
            },
            root: actorRoot(),
            window: createWin(),
        });
        await first.replay();
        assert.equal((await listOperations()).length, 1);
        assert.equal((await listOperations())[0].status, 'retry');
        assert.equal((await listOperations())[0].operation_id, retryable.operation_id);

        resetReplayLock();
        const sessionOp = await enqueueOperation({
            actor_id: 7,
            actor_username: 'admin.one',
            operation_type: 'HEALTH_WORKER_UPDATE',
            payload: { hw_last_name: 'Reyes' },
            parent_server: { user_id: 12 },
            base_snapshot: { field_hash: 'a'.repeat(64) },
        });
        const second = createReplayCoordinator({
            fetch: async (url) => {
                if (url === '/offline/status') {
                    return jsonResponse({ ok: true, user_id: 7, csrf_token: 'token', is_active: true });
                }
                return jsonResponse({ ok: false, code: 'SESSION_EXPIRED' }, 401);
            },
            root: actorRoot(),
            window: createWin(),
        });
        await second.replay();
        const remaining = await listOperations();
        assert.ok(remaining.some((item) => item.operation_id === sessionOp.operation_id));
        assert.ok(remaining.every((item) => item.status !== 'completed'));
        assert.equal(remaining.some((item) => item.operation_id === sessionOp.operation_id && item.status === 'pending'), true);
    });

    it('does not reload User Management after attention', async () => {
        const doc = createDocument();
        const html = doc.createElement('div');
        const root = doc.createElement('div');
        root.setAttribute('data-lml-user-mgmt', '');
        html.appendChild(root);
        doc.documentElement = html;
        const win = createWin();
        bindUserManagementSyncReload({ window: win, document: doc });
        win.dispatchEvent({ type: OFFLINE_EVENTS.SYNC_ATTENTION, detail: { code: 'VALIDATION_FAILED' } });
        assert.equal(win.location.reloadCalls, 0);
    });
});
