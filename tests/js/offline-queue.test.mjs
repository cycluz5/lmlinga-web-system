/**
 * Durable IndexedDB queue — resources/js/offline/offline-db.js + offline-queue.js
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const queueSource = readFileSync(path.resolve('resources/js/offline/offline-queue.js'), 'utf8');
const dbSource = readFileSync(path.resolve('resources/js/offline/offline-db.js'), 'utf8');

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href
);
const {
    attentionUiDetail,
    enqueueOperation,
    getOperation,
    listOperations,
    listReplayableForActor,
    applyServerIdentityToDependents,
    markAttention,
    markRetry,
    markSyncing,
    recoverMalformedAttentionOnce,
    recoverStaleSyncing,
    toSyncEnvelope,
    updateOperation,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href);

function validInput(overrides = {}) {
    return {
        actor_id: 7,
        actor_username: 'bhw.ana',
        operation_type: 'HOUSEHOLD_CREATE',
        payload: {
            household_no: '121',
            zone: 'Zone 2',
            street: 'Layuan St.',
        },
        ...overrides,
    };
}

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

describe('offline durable queue', () => {
    it('survives re-instantiation against the same IndexedDB', async () => {
        const first = await enqueueOperation(validInput());
        const again = await listOperations();
        assert.equal(again.length, 1);
        assert.equal(again[0].operation_id, first.operation_id);

        const reopened = await getOperation(first.local_id);
        assert.equal(reopened.payload.household_no, '121');
        assert.equal(reopened.operation_id, first.operation_id);
    });

    it('assigns operation_id once and retries keep the same id', async () => {
        const queued = await enqueueOperation(validInput());
        const original = queued.operation_id;
        assert.match(original, /^[0-9a-f-]{36}$/i);

        const retrying = await markRetry(queued.local_id, 'RETRYABLE_ERROR', 1);
        assert.equal(retrying.operation_id, original);
        assert.equal(retrying.status, 'retry');
        assert.equal(retrying.retry_count, 1);
        assert.ok(retrying.next_retry_at > Date.now());

        const syncing = await markSyncing(queued.local_id);
        assert.equal(syncing.operation_id, original);
    });

    it('never persists CSRF tokens or secrets', async () => {
        const queued = await enqueueOperation(
            validInput({
                payload: {
                    household_no: '122',
                    _token: 'csrf-should-not-land',
                    csrf_token: 'csrf-should-not-land',
                    password: 'secret',
                    zone: 'Zone 1',
                },
            }),
        );

        const raw = JSON.stringify(queued);
        assert.equal(raw.includes('csrf'), false);
        assert.equal(raw.includes('_token'), false);
        assert.equal(raw.includes('password'), false);
        assert.equal(raw.includes('secret'), false);
        assert.equal(queued.payload.zone, 'Zone 1');
        assert.equal(queued.payload.household_no, '122');
    });

    it('strips client-invented server primary keys from the payload', async () => {
        const queued = await enqueueOperation(
            validInput({
                payload: {
                    household_no: '130',
                    id: 999,
                    household_id: 888,
                    resident_id: 777,
                    member_no: 'MB-999',
                    zone: 'Zone 3',
                },
            }),
        );

        assert.equal(queued.payload.id, undefined);
        assert.equal(queued.payload.household_id, undefined);
        assert.equal(queued.payload.resident_id, undefined);
        assert.equal(queued.payload.member_no, undefined);
        assert.equal(queued.payload.household_no, '130');
    });

    it('does not replay another actor\'s operations', async () => {
        await enqueueOperation(validInput({ actor_id: 7 }));
        await enqueueOperation(validInput({ actor_id: 9, payload: { household_no: '140', zone: 'Zone 1' } }));

        const forSeven = await listReplayableForActor(7);
        const forNine = await listReplayableForActor(9);
        assert.equal(forSeven.length, 1);
        assert.equal(forNine.length, 1);
        assert.equal(forSeven[0].actor_id, 7);
        assert.equal(forNine[0].actor_id, 9);
        assert.notEqual(forSeven[0].operation_id, forNine[0].operation_id);
    });

    it('recovers stale syncing items back to pending', async () => {
        const queued = await enqueueOperation(validInput());
        await markSyncing(queued.local_id);
        const recovered = await recoverStaleSyncing();
        assert.equal(recovered.length, 1);
        assert.equal(recovered[0].status, 'pending');
        assert.equal(recovered[0].operation_id, queued.operation_id);
    });

    it('applies bounded exponential backoff metadata', async () => {
        const queued = await enqueueOperation(validInput());
        const first = await markRetry(queued.local_id, 'RETRYABLE_ERROR', 0);
        const second = await markRetry(queued.local_id, 'RETRYABLE_ERROR', 1);
        const capped = await markRetry(queued.local_id, 'RETRYABLE_ERROR', 9);

        assert.ok(first.next_retry_at - Date.now() <= 1500);
        assert.ok(second.next_retry_at - Date.now() >= 1500);
        assert.ok(capped.next_retry_at - Date.now() >= 25000);
        assert.equal(capped.operation_id, queued.operation_id);
    });

    it('keeps attention items out of the replayable list', async () => {
        const queued = await enqueueOperation(validInput());
        await markAttention(queued.local_id, 'TARGET_CHANGED');
        const replayable = await listReplayableForActor(7);
        assert.equal(replayable.length, 0);
        const stored = await getOperation(queued.local_id);
        assert.equal(stored.status, 'attention');
        assert.equal(stored.last_safe_error_code, 'TARGET_CHANGED');
    });

    it('retries attention MALFORMED once without recreating the record', async () => {
        const queued = await enqueueOperation({
            ...validInput(),
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
        await markAttention(queued.local_id, 'MALFORMED');
        const first = await recoverMalformedAttentionOnce();
        assert.equal(first.length, 1);
        const pending = await getOperation(queued.local_id);
        assert.equal(pending.status, 'pending');
        assert.equal(pending.operation_id, queued.operation_id);
        assert.equal(pending.malformed_auto_retried, true);
        assert.equal(pending.payload.zone, 5);
        assert.equal((await listReplayableForActor(7)).length, 1);

        await markAttention(queued.local_id, 'MALFORMED');
        const second = await recoverMalformedAttentionOnce();
        assert.equal(second.length, 0);
        assert.equal((await getOperation(queued.local_id)).status, 'attention');
    });

    it('wraps the observed plot payload in a v1 sync envelope', async () => {
        const queued = await enqueueOperation({
            ...validInput(),
            operation_type: 'PLOT_HOUSEHOLD_WITH_HEAD',
            payload: {
                birthday: '1994-06-07',
                first_name: 'Zai',
                last_name: 'Kluz',
                household_no: '210',
                zone: 5,
                consent: true,
                lat: 13.376191299053865,
                lng: 123.43171525236092,
            },
        });
        const envelope = toSyncEnvelope(queued);
        assert.equal(envelope.operation_id, queued.operation_id);
        assert.equal(envelope.schema_version, 1);
        assert.equal(envelope.operation_type, 'PLOT_HOUSEHOLD_WITH_HEAD');
        assert.equal(envelope.payload.zone, 5);
        assert.equal(envelope.payload.household_no, '210');
        assert.equal(envelope.base_snapshot, null);
        assert.equal(envelope.parent_server, null);
    });

    it('does not use localStorage as a queue fallback', () => {
        assert.equal(queueSource.includes('localStorage.setItem'), false);
        assert.equal(queueSource.includes('localStorage.getItem'), false);
        assert.equal(dbSource.includes('localStorage.setItem'), false);
        assert.equal(dbSource.includes('sessionStorage.setItem'), false);
        assert.match(dbSource, /indexedDB/);
        assert.equal(dbSource.includes('sql.js'), false);
        assert.equal(dbSource.toLowerCase().includes('sqlite'), false);
    });

    it('preserves operation_id across generic updates', async () => {
        const queued = await enqueueOperation(validInput());
        const updated = await updateOperation(queued.local_id, { last_safe_error_code: 'RETRYABLE_ERROR' });
        assert.equal(updated.operation_id, queued.operation_id);
        assert.equal(updated.local_id, queued.local_id);
    });

    it('updates an existing pending amenities write instead of enqueueing a duplicate', async () => {
        const first = await enqueueOperation({
            ...validInput(),
            operation_type: 'HOUSEHOLD_AMENITIES_UPDATE',
            payload: { household_no: 'HH-121', water_supply_status: 'level_i' },
            parent_server: { household_id: 8, household_no: 'HH-121' },
        });
        const second = await enqueueOperation({
            ...validInput(),
            operation_type: 'HOUSEHOLD_AMENITIES_UPDATE',
            payload: { household_no: 'HH-121', water_supply_status: 'level_ii' },
            parent_server: { household_id: 8, household_no: 'HH-121' },
        });
        assert.equal(second.local_id, first.local_id);
        assert.equal(second.operation_id, first.operation_id);
        assert.equal(second.payload.water_supply_status, 'level_ii');
        assert.equal((await listOperations()).length, 1);
    });

    it('merges later local-member edits into the pending create', async () => {
        const created = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-tmp1',
                first_name: 'Ana',
                last_name: 'Santos',
                relation: 'Daughter',
                sex: 'Female',
                birthday: '2018-05-01',
            },
            parent_server: { household_id: 8, household_no: 'HH-121' },
        });
        const edited = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-tmp1',
                first_name: 'Anita',
                last_name: 'Santos',
                relation: 'Daughter',
                sex: 'Female',
                birthday: '2018-05-01',
            },
            parent_server: { household_id: 8, household_no: 'HH-121', member_no: 'MB-L-tmp1' },
        });
        assert.equal(edited.local_id, created.local_id);
        assert.equal(edited.operation_type, 'RESIDENT_CREATE');
        assert.equal(edited.payload.first_name, 'Anita');
        assert.equal((await listOperations()).length, 1);
    });

    it('merges local-member edits even when the queued id case differs', async () => {
        const created = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-abc12',
                first_name: 'Ana',
                last_name: 'Santos',
            },
            parent_server: { household_id: 8, household_no: 'HH-121' },
        });
        const edited = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-ABC12',
                first_name: 'Anita',
                last_name: 'Santos',
            },
            parent_server: { household_id: 8, household_no: 'HH-121', member_no: 'MB-L-ABC12' },
        });
        assert.equal(edited.local_id, created.local_id);
        assert.equal(edited.payload.client_local_member_id, 'MB-L-abc12');
        assert.equal(edited.payload.first_name, 'Anita');
        assert.equal((await listOperations()).length, 1);
    });

    it('rewrites dependent updates after the local member is assigned a server id', async () => {
        await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_UPDATE',
            payload: { client_local_member_id: 'MB-L-tmp2', first_name: 'Ben' },
            parent_server: { household_no: 'HH-200', member_no: 'MB-L-tmp2' },
        });
        const health = await enqueueOperation({
            ...validInput(),
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { client_local_member_id: 'MB-L-tmp2', _health_action: 'child_immunization_store' },
            parent_server: { household_no: 'HH-200', member_no: 'MB-L-tmp2' },
        });
        await applyServerIdentityToDependents(7, 'MB-L-tmp2', {
            resident_pk: 44,
            member_no: 'MB-044',
            field_hash: 'a'.repeat(64),
        });
        const updatedHealth = await getOperation(health.local_id);
        assert.equal(updatedHealth.parent_server.resident_id, 44);
        assert.equal(updatedHealth.parent_server.member_no, 'MB-044');
        const replayable = await listReplayableForActor(7);
        assert.equal(replayable[0].operation_type, 'RESIDENT_UPDATE');
        assert.equal(replayable[1].operation_type, 'HEALTH_SERVICE_WRITE');
    });

    it('repairs a legacy attention RESIDENT_CREATE instead of enqueueing a second create', async () => {
        const created = await enqueueOperation({
            ...validInput(),
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
        const stuck = await markAttention(created.local_id, 'VALIDATION_FAILED');
        assert.equal(stuck.status, 'attention');
        assert.equal(stuck.payload.sex, null);
        const detail = attentionUiDetail(stuck);
        assert.equal(detail.reason, 'Sex is required.');
        assert.equal(detail.member_name, 'Vicky Morales');
        assert.equal(detail.href, '/household-profiling/999/members/MB-L-vicky1/edit');

        const edited = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-vicky1',
                first_name: 'Vicky',
                last_name: 'Morales',
                relation: 'Daughter',
                sex: 'Male',
                birthday: '2018-05-01',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999', member_no: 'MB-L-vicky1' },
        });
        assert.equal(edited.local_id, created.local_id);
        assert.equal(edited.operation_id, created.operation_id);
        assert.equal(edited.operation_type, 'RESIDENT_CREATE');
        assert.equal(edited.status, 'pending');
        assert.equal(edited.payload.sex, 'Male');
        assert.equal(edited.payload.philhealth, '123456789012');
        assert.equal(edited.last_safe_error_code, null);
        assert.equal((await listOperations()).length, 1);
        assert.equal((await listReplayableForActor(7)).length, 1);
    });

    it('repairs an attention create when Sex is corrected to Female', async () => {
        const created = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-fem01',
                first_name: 'Vicky',
                last_name: 'Morales',
                sex: '',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999' },
        });
        await markAttention(created.local_id, 'VALIDATION_FAILED');
        const edited = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-fem01',
                first_name: 'Vicky',
                last_name: 'Morales',
                sex: 'Female',
                philhealth: '123456789012',
            },
            parent_server: { household_id: 999, household_no: '999', member_no: 'MB-L-fem01' },
        });
        assert.equal(edited.local_id, created.local_id);
        assert.equal(edited.payload.sex, 'Female');
        assert.equal(edited.status, 'pending');
        assert.equal((await listOperations()).length, 1);
    });

    it('does not mutate an attention create after an idempotency payload mismatch', async () => {
        const created = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-unsafe1',
                first_name: 'Ana',
                last_name: 'Santos',
                sex: 'Female',
            },
            parent_server: { household_id: 8, household_no: 'HH-121' },
        });
        await markAttention(created.local_id, 'IDEMPOTENCY_PAYLOAD_MISMATCH');
        const edited = await enqueueOperation({
            ...validInput(),
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-unsafe1',
                first_name: 'Anita',
                last_name: 'Santos',
                sex: 'Female',
            },
            parent_server: { household_id: 8, household_no: 'HH-121', member_no: 'MB-L-unsafe1' },
        });
        assert.notEqual(edited.local_id, created.local_id);
        assert.equal(edited.operation_type, 'RESIDENT_UPDATE');
        const original = await getOperation(created.local_id);
        assert.equal(original.status, 'attention');
        assert.equal(original.payload.first_name, 'Ana');
        assert.equal((await listOperations()).length, 2);
    });

    it('repairs the same RETRY HEALTH_SERVICE_WRITE without creating a duplicate', async () => {
        const first = await enqueueOperation({
            ...validInput(),
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-030',
                'newborn[length]': '50',
            },
            parent_server: { household_id: 9, household_no: 'HH-1', member_no: 'MB-030', resident_id: 30 },
        });
        await markRetry(first.local_id, 'RETRYABLE_ERROR', 1);
        const repaired = await enqueueOperation({
            ...validInput(),
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-030',
                newborn: { length: '50', weight: '3.2' },
                iron: { '1st': '2026-09-20' },
            },
            parent_server: { household_id: 9, household_no: 'HH-1', member_no: 'MB-030', resident_id: 30 },
        });
        assert.equal(repaired.local_id, first.local_id);
        assert.equal(repaired.operation_id, first.operation_id);
        assert.equal(repaired.status, 'pending');
        assert.equal(repaired.payload.newborn.weight, '3.2');
        assert.equal(repaired.last_safe_error_code, null);
        assert.equal((await listOperations()).length, 1);
    });

    it('repairs the same ATTENTION HEALTH_SERVICE_WRITE without creating a duplicate', async () => {
        const first = await enqueueOperation({
            ...validInput(),
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-030',
                newborn: { length: 'abc' },
            },
            parent_server: { household_id: 9, household_no: 'HH-1', member_no: 'MB-030', resident_id: 30 },
        });
        await markAttention(first.local_id, 'VALIDATION_FAILED', {
            'newborn.length': ['Length at Birth must be a number.'],
        });
        const repaired = await enqueueOperation({
            ...validInput(),
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-030',
                newborn: { length: '50', weight: '3.2' },
            },
            parent_server: { household_id: 9, household_no: 'HH-1', member_no: 'MB-030', resident_id: 30 },
        });
        assert.equal(repaired.local_id, first.local_id);
        assert.equal(repaired.status, 'pending');
        assert.equal(repaired.payload.newborn.length, '50');
        assert.equal(repaired.last_validation_errors, null);
        assert.equal((await listOperations()).length, 1);
    });
});
