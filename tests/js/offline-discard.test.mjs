/**
 * Don't Sync / discard + Retry — offline-discard.js
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const discardUrl = pathToFileURL(path.resolve('resources/js/offline/offline-discard.js')).href;
const changesUrl = pathToFileURL(path.resolve('resources/js/offline/offline-changes.js')).href;
const hpUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href;
const healthUrl = pathToFileURL(path.resolve('resources/js/offline/offline-health-store.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    enqueueOperation,
    listOperations,
    listOperationsForActor,
    markRetry,
    markAttention,
    getOperation,
    QUEUE_STATUS,
} = await import(queueUrl);
const {
    collectDiscardPlan,
    buildDiscardConfirmation,
    executeDiscardPlan,
    discardQueuedOperation,
    retryQueuedOperation,
    isDiscardDependentOf,
} = await import(discardUrl);
const {
    describeOfflineChange,
    renderOfflineChangesListHtml,
    isSyntheticOfflineChangeItem,
    listOfflineChangesForActor,
} = await import(changesUrl);
const {
    persistHouseholdCreateReadModel,
    appendLocalMemberToHousehold,
    getHouseholdSnapshot,
    getMemberSnapshot,
    listMemberSnapshots,
} = await import(hpUrl);
const {
    persistHealthWriteReadModel,
    listHealthSnapshots,
} = await import(healthUrl);

const ACTOR = 7;
const ACTOR_B = 9;

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

describe('offline discard confirmation', () => {
    it('O: requires confirmation wording and P: cancel leaves plan unused', async () => {
        const health = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                newborn: { length: '50' },
                client_local_member_id: 'MB-030',
            },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030', household_id: 1, resident_id: 30 },
        });
        const plan = await collectDiscardPlan(ACTOR, health.local_id);
        const copy = buildDiscardConfirmation(plan);
        assert.match(copy.title, /Don't sync/i);
        assert.match(copy.body, /will not be sent to the server/i);
        assert.equal(copy.confirmLabel, "Don't Sync");
        // Cancel path: do not execute — queue unchanged.
        assert.equal((await listOperations()).length, 1);
        assert.equal((await getOperation(health.local_id))?.status, QUEUE_STATUS.PENDING);
    });
});

describe('offline discard HEALTH_SERVICE_WRITE', () => {
    it('A+B+C: discards standalone health write; MB-030 and household remain', async () => {
        await persistHouseholdCreateReadModel(ACTOR, {
            household_no: 'HH-1',
            zone: 'Zone 1',
        });
        // Promote to look like a prepared/server household for discard semantics.
        const hhSnap = await getHouseholdSnapshot(ACTOR, 'HH-1');
        await persistHouseholdCreateReadModel(ACTOR, { ...hhSnap, household_no: 'HH-1' });
        const { putHouseholdSnapshot } = await import(hpUrl);
        await putHouseholdSnapshot(ACTOR, {
            ...hhSnap,
            household_id: 11,
            local: false,
            pending_sync: false,
        });
        await appendLocalMemberToHousehold(ACTOR, 'HH-1', {
            client_local_member_id: 'MB-030',
            first_name: 'Baby',
            last_name: 'Care',
            sex: 'Male',
        });
        // Treat member as server-backed identity in snapshot.
        const { putMemberSnapshot, memberRecordId } = await import(hpUrl);
        const member = await getMemberSnapshot(ACTOR, 'HH-1', 'MB-030');
        await putMemberSnapshot(ACTOR, {
            ...member,
            id: memberRecordId(ACTOR, 'HH-1', 'MB-030'),
            member_no: 'MB-030',
            local: false,
            resident_id: 30,
        });

        const health = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                newborn: { length: '50' },
                client_local_member_id: 'MB-030',
            },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030', household_id: 11, resident_id: 30 },
        });
        await persistHealthWriteReadModel(ACTOR, health);
        assert.equal((await listHealthSnapshots(ACTOR, 'MB-030')).length, 1);

        const plan = await collectDiscardPlan(ACTOR, health.local_id);
        assert.equal(plan.operations.length, 1);
        const result = await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal(result.ok, true);
        assert.equal((await listOperationsForActor(ACTOR)).length, 0);
        assert.equal((await listHealthSnapshots(ACTOR, 'MB-030')).length, 0);
        assert.ok(await getHouseholdSnapshot(ACTOR, 'HH-1'));
        assert.ok(await getMemberSnapshot(ACTOR, 'HH-1', 'MB-030'));
        assert.equal(result.pending, 0);
    });
});

describe('offline discard RESIDENT_CREATE cascade', () => {
    it('D: discard member without health removes member only', async () => {
        await persistHouseholdCreateReadModel(ACTOR, { household_no: 'HH-200', zone: 'Zone 2' });
        const { putHouseholdSnapshot } = await import(hpUrl);
        const hh = await getHouseholdSnapshot(ACTOR, 'HH-200');
        await putHouseholdSnapshot(ACTOR, { ...hh, household_id: 200, local: false, pending_sync: false });

        const create = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-maria1',
                first_name: 'Maria',
                last_name: 'Santos',
                sex: 'Female',
            },
            parent_server: { household_id: 200, household_no: 'HH-200' },
        });
        await appendLocalMemberToHousehold(ACTOR, 'HH-200', create.payload);

        const plan = await collectDiscardPlan(ACTOR, create.local_id);
        assert.equal(plan.counts.health, 0);
        const copy = buildDiscardConfirmation(plan);
        assert.equal(copy.cascade, false);
        await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal(await getMemberSnapshot(ACTOR, 'HH-200', 'MB-L-maria1'), null);
        assert.ok(await getHouseholdSnapshot(ACTOR, 'HH-200'));
    });

    it('E+F: discard member cascades health; household remains', async () => {
        await persistHouseholdCreateReadModel(ACTOR, { household_no: 'HH-200', zone: 'Zone 2' });
        const { putHouseholdSnapshot } = await import(hpUrl);
        const hh = await getHouseholdSnapshot(ACTOR, 'HH-200');
        await putHouseholdSnapshot(ACTOR, { ...hh, household_id: 200, local: false, pending_sync: false });

        const create = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-maria2',
                first_name: 'Maria',
                last_name: 'Santos',
                sex: 'Female',
            },
            parent_server: { household_id: 200, household_no: 'HH-200' },
        });
        await appendLocalMemberToHousehold(ACTOR, 'HH-200', create.payload);
        const health = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'risk_assessment_store',
                client_local_member_id: 'MB-L-maria2',
            },
            parent_server: { household_no: 'HH-200', member_no: 'MB-L-maria2' },
        });
        await persistHealthWriteReadModel(ACTOR, health);

        const plan = await collectDiscardPlan(ACTOR, create.local_id);
        assert.equal(plan.counts.health, 1);
        assert.match(buildDiscardConfirmation(plan).confirmLabel, /Member and Related/i);
        assert.equal(isDiscardDependentOf(create, health), true);
        await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal((await listOperationsForActor(ACTOR)).length, 0);
        assert.equal((await listHealthSnapshots(ACTOR, 'MB-L-maria2')).length, 0);
        assert.equal(await getMemberSnapshot(ACTOR, 'HH-200', 'MB-L-maria2'), null);
        assert.ok(await getHouseholdSnapshot(ACTOR, 'HH-200'));
    });
});

describe('offline discard HOUSEHOLD_CREATE cascade', () => {
    it('G+H+I+J: household cascade removes members/health/EH; unrelated remains', async () => {
        await persistHouseholdCreateReadModel(ACTOR, { household_no: 'HH-500', zone: 'Zone 1' });
        await persistHouseholdCreateReadModel(ACTOR, { household_no: 'HH-999', zone: 'Zone 9' });

        const hhCreate = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: 'HH-500', zone: 'Zone 1' },
            parent_server: { household_no: 'HH-500' },
        });
        const otherHh = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: 'HH-999', zone: 'Zone 9' },
            parent_server: { household_no: 'HH-999' },
        });
        const member = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-keep1',
                first_name: 'Ana',
                last_name: 'Cruz',
                sex: 'Female',
            },
            parent_server: { household_no: 'HH-500' },
        });
        await appendLocalMemberToHousehold(ACTOR, 'HH-500', member.payload);
        const health = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'child_nutrition_store',
                client_local_member_id: 'MB-L-keep1',
            },
            parent_server: { household_no: 'HH-500', member_no: 'MB-L-keep1' },
        });
        await persistHealthWriteReadModel(ACTOR, health);
        const eh = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
            payload: { household_no: 'HH-500', _eh_step: 1, water_supply_status: 'Level I' },
            parent_server: { household_no: 'HH-500' },
        });

        const plan = await collectDiscardPlan(ACTOR, hhCreate.local_id);
        assert.ok(plan.counts.members >= 1);
        assert.ok(plan.counts.health >= 1);
        assert.ok(plan.counts.eh >= 1);
        assert.match(buildDiscardConfirmation(plan).confirmLabel, /Household and Related/i);
        assert.equal(plan.operations.some((row) => row.local_id === otherHh.local_id), false);
        assert.equal(plan.operations.some((row) => row.local_id === eh.local_id), true);

        await executeDiscardPlan(plan, { actorId: ACTOR });
        const remaining = await listOperationsForActor(ACTOR);
        assert.equal(remaining.length, 1);
        assert.equal(remaining[0].local_id, otherHh.local_id);
        assert.ok(!(await getHouseholdSnapshot(ACTOR, 'HH-500')));
        assert.equal((await listMemberSnapshots(ACTOR, 'HH-500')).length, 0);
        assert.ok(await getHouseholdSnapshot(ACTOR, 'HH-999'));
    });
});

describe('offline discard actor isolation + retry', () => {
    it('K: actor B records never affected', async () => {
        const a = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'deworming_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        const b = await enqueueOperation({
            actor_id: ACTOR_B,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'deworming_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        const plan = await collectDiscardPlan(ACTOR, a.local_id);
        await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal((await listOperationsForActor(ACTOR)).length, 0);
        assert.equal((await listOperationsForActor(ACTOR_B)).length, 1);
        assert.equal((await getOperation(b.local_id))?.actor_id, ACTOR_B);
    });

    it('L+M: Retry reuses operation_id and returns to pending', async () => {
        const row = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        await markRetry(row.local_id, 'RETRYABLE_ERROR', 2);
        const before = await getOperation(row.local_id);
        const result = await retryQueuedOperation(ACTOR, row.local_id);
        assert.equal(result.ok, true);
        assert.equal(result.operation_id, before.operation_id);
        assert.equal(result.local_id, before.local_id);
        const after = await getOperation(row.local_id);
        assert.equal(after.status, QUEUE_STATUS.PENDING);
        assert.equal(after.next_retry_at, null);
        assert.equal(after.last_safe_error_code, null);
        assert.equal((await listOperations()).length, 1);
    });
});

describe('offline discard UI flags + counters', () => {
    it('N: ATTENTION does not expose Retry; retry does', () => {
        const attention = describeOfflineChange({
            local_id: 'op-att',
            operation_type: 'HEALTH_SERVICE_WRITE',
            status: QUEUE_STATUS.ATTENTION,
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
            last_safe_error_code: 'VALIDATION_FAILED',
        });
        assert.equal(attention.show_retry, false);
        assert.equal(attention.show_discard, true);
        const htmlAtt = renderOfflineChangesListHtml([attention], 'all');
        assert.doesNotMatch(htmlAtt, /data-lml-offline-change-retry/);
        assert.match(htmlAtt, /Don't Sync/);

        const retry = describeOfflineChange({
            local_id: 'op-retry',
            operation_type: 'HEALTH_SERVICE_WRITE',
            status: QUEUE_STATUS.RETRY,
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
            last_safe_error_code: 'RETRYABLE_ERROR',
        });
        assert.equal(retry.show_retry, true);
        const htmlRetry = renderOfflineChangesListHtml([retry], 'all');
        assert.match(htmlRetry, /data-lml-offline-change-retry/);
        assert.match(htmlRetry, /Don't Sync/);
    });

    it('Q+R+V: counters update; synthetic still excluded; empty after discard', async () => {
        const health = await enqueueOperation({
            actor_id: ACTOR,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
            parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
        });
        await markRetry(health.local_id, 'RETRYABLE_ERROR', 1);
        let items = await listOfflineChangesForActor(ACTOR);
        assert.equal(items.length, 1);
        assert.equal(isSyntheticOfflineChangeItem({ id: 'queue:unsynced', code: 'QUEUE_NEEDS_ATTENTION' }), true);

        const denied = await discardQueuedOperation(ACTOR, health.local_id, { confirm: false });
        assert.equal(denied.ok, false);
        assert.equal(denied.reason, 'confirmation-required');
        assert.equal((await listOperationsForActor(ACTOR)).length, 1);

        const plan = await collectDiscardPlan(ACTOR, health.local_id);
        const result = await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal(result.pending, 0);
        items = await listOfflineChangesForActor(ACTOR);
        assert.equal(items.length, 0);
    });
});

describe('offline discard does not hit the network', () => {
    it('U: discard path does not fabricate server DELETE requests', async () => {
        const fetches = [];
        const originalFetch = globalThis.fetch;
        globalThis.fetch = async (...args) => {
            fetches.push(args);
            return { ok: true, status: 200, json: async () => ({}) };
        };
        try {
            const health = await enqueueOperation({
                actor_id: ACTOR,
                operation_type: 'HEALTH_SERVICE_WRITE',
                payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-030' },
                parent_server: { household_no: 'HH-1', member_no: 'MB-030' },
            });
            const plan = await collectDiscardPlan(ACTOR, health.local_id);
            await executeDiscardPlan(plan, { actorId: ACTOR });
            assert.equal(fetches.length, 0);
        } finally {
            globalThis.fetch = originalFetch;
        }
    });
});
