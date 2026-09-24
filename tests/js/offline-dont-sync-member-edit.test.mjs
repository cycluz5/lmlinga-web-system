/**
 * Don't Sync for needs-attention operations + member-edit conflict hash.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const discardUrl = pathToFileURL(path.resolve('resources/js/offline/offline-discard.js')).href;
const replayUrl = pathToFileURL(path.resolve('resources/js/offline/offline-replay.js')).href;
const storeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href;
const hydrateUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory, OFFLINE_DB_NAME, OFFLINE_DB_VERSION } = await import(dbUrl);
const {
    QUEUE_STATUS,
    enqueueOperation,
    getOperation,
    listOperations,
    markAttention,
    markRetry,
} = await import(queueUrl);
const {
    collectDiscardPlan,
    discardQueuedOperation,
    executeDiscardPlan,
    resolveAbsentDiscard,
} = await import(discardUrl);
const { classifySyncFailure } = await import(replayUrl);
const {
    getMemberSnapshot,
    replaceHouseholdProfilingSnapshots,
    setMemberFieldHash,
} = await import(storeUrl);
const { hydrateMemberEditHtml } = await import(hydrateUrl);

const ACTOR = 1;
const OTHER_ACTOR = 2;

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

async function seedQueue() {
    const update = await enqueueOperation({
        actor_id: ACTOR,
        operation_type: 'RESIDENT_UPDATE',
        payload: { first_name: 'Lyca', middle_name: 'Marco', last_name: 'Labubo', household_no: '561' },
        base_snapshot: { field_hash: 'donor-hash' },
        parent_server: { household_no: '561', member_no: 'MB-038', household_id: 5, resident_id: 38 },
    });
    await markAttention(update.local_id, 'TARGET_CHANGED');
    const health = await enqueueOperation({
        actor_id: ACTOR,
        operation_type: 'HEALTH_SERVICE_WRITE',
        payload: { _health_action: 'child_nutrition_store', client_local_member_id: 'MB-038' },
        parent_server: { household_no: '561', member_no: 'MB-038', household_id: 5, resident_id: 38 },
    });
    await markRetry(health.local_id, 'NETWORK_ERROR', 1);
    const foreign = await enqueueOperation({
        actor_id: OTHER_ACTOR,
        operation_type: 'RESIDENT_UPDATE',
        payload: { first_name: 'Other', last_name: 'Account' },
        parent_server: { household_no: '900', member_no: 'MB-900', household_id: 9, resident_id: 90 },
    });
    return { update, health, foreign };
}

describe("Don't Sync — needs-attention RESIDENT_UPDATE", () => {
    it('removes exactly the selected local_id and leaves every other operation untouched', async () => {
        const { update, health, foreign } = await seedQueue();
        const healthBefore = await getOperation(health.local_id);
        const foreignBefore = await getOperation(foreign.local_id);

        const result = await discardQueuedOperation(ACTOR, update.local_id);

        assert.equal(result.ok, true);
        assert.deepEqual(result.removed_ids, [update.local_id]);
        assert.equal(result.pending, 1);
        assert.equal(await getOperation(update.local_id), null);

        const rows = await listOperations();
        assert.deepEqual(rows.map((row) => row.local_id).sort(), [health.local_id, foreign.local_id].sort());
        assert.deepEqual(await getOperation(health.local_id), healthBefore);
        assert.equal((await getOperation(health.local_id)).status, QUEUE_STATUS.RETRY);
        assert.deepEqual(await getOperation(foreign.local_id), foreignBefore);
    });

    it('works even when the database has no health read-model store (single-store delete)', async () => {
        // Existing v4 database that predates hp_health_records: discard must not need it.
        const factory = createFakeIndexedDB();
        setIndexedDBFactory(factory);
        await new Promise((resolve, reject) => {
            const request = factory.open(OFFLINE_DB_NAME, OFFLINE_DB_VERSION);
            request.onupgradeneeded = () => {
                request.result.createObjectStore('operations', { keyPath: 'local_id' });
            };
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
        const { update, health } = await seedQueue();

        const result = await discardQueuedOperation(ACTOR, update.local_id);

        assert.equal(result.ok, true);
        assert.equal(await getOperation(update.local_id), null);
        assert.ok(await getOperation(health.local_id));
    });

    it('is idempotent: an already-absent row is a safe success', async () => {
        const { update, health } = await seedQueue();
        const plan = await collectDiscardPlan(ACTOR, update.local_id);
        assert.equal(plan.ok, true);

        assert.equal((await discardQueuedOperation(ACTOR, update.local_id)).ok, true);

        // Executing a stale plan for the now-missing row still satisfies the goal.
        const again = await executeDiscardPlan(plan, { actorId: ACTOR });
        assert.equal(again.ok, true);
        assert.equal(again.already_absent, true);
        assert.deepEqual(again.removed_ids, []);
        assert.equal(again.pending, 1);

        const missingPlan = await collectDiscardPlan(ACTOR, update.local_id);
        assert.equal(missingPlan.ok, false);
        assert.equal(missingPlan.reason, 'not-found');
        assert.equal(missingPlan.already_absent, true);

        const absent = await resolveAbsentDiscard(ACTOR, update.local_id);
        assert.equal(absent.ok, true);
        assert.deepEqual(absent.absent_ids, [update.local_id]);
        assert.ok(await getOperation(health.local_id));
    });

    it("a wrong actor can never discard another actor's operation", async () => {
        const { foreign, update } = await seedQueue();

        const denied = await discardQueuedOperation(ACTOR, foreign.local_id);
        assert.equal(denied.ok, false);
        assert.equal(denied.reason, 'not-found');
        assert.equal(denied.already_absent, undefined);
        assert.ok(await getOperation(foreign.local_id));

        // A stale plan from actor 1 cannot be executed as actor 2 either.
        const plan = await collectDiscardPlan(ACTOR, update.local_id);
        const crossed = await executeDiscardPlan(plan, { actorId: OTHER_ACTOR });
        assert.equal(crossed.ok, false);
        assert.equal(crossed.reason, 'actor-mismatch');
        assert.ok(await getOperation(update.local_id));

        const absent = await resolveAbsentDiscard(OTHER_ACTOR, update.local_id);
        assert.equal(absent.ok, false);
        assert.equal(absent.reason, 'not-absent');
    });

    it('rejects an invalid or missing local_id without touching anything', async () => {
        await seedQueue();
        const before = (await listOperations()).length;

        for (const bad of ['', '   ', null, undefined]) {
            const plan = await collectDiscardPlan(ACTOR, bad);
            assert.equal(plan.ok, false);
            assert.equal(plan.reason, 'invalid-target');
            assert.equal((await resolveAbsentDiscard(ACTOR, bad)).ok, false);
        }
        assert.equal((await collectDiscardPlan(0, 'x')).reason, 'invalid-target');
        assert.equal((await listOperations()).length, before);
    });

    it('refuses a syncing row', async () => {
        const { update } = await seedQueue();
        const { updateOperation } = await import(queueUrl);
        await updateOperation(update.local_id, { status: QUEUE_STATUS.SYNCING });
        const plan = await collectDiscardPlan(ACTOR, update.local_id);
        assert.equal(plan.reason, 'syncing');
        assert.ok(await getOperation(update.local_id));
    });

    it('TARGET_CHANGED detection is unchanged (still needs attention, never auto-discarded)', () => {
        const classified = classifySyncFailure({
            response: { status: 409, ok: false },
            payload: { code: 'TARGET_CHANGED' },
        });
        assert.deepEqual({ kind: classified.kind, code: classified.code }, {
            kind: 'attention',
            code: 'TARGET_CHANGED',
        });
    });
});

describe('member edit conflict hash', () => {
    const shell = `<form data-lml-hh-member-form data-mode="edit"
 data-household-no="561" data-member-id="MB-001" data-offline-operation="RESIDENT_UPDATE"
 data-offline-parent-household-no="561" data-offline-parent-member-no="MB-001"
 data-offline-parent-resident-id="11" data-offline-field-hash="DONOR-HASH">
<p>Editing <strong>Donor One</strong> in household 561</p></form>`;
    const household = { household_no: '561', household_id: 5 };

    it("uses the member's own hash, never the donor's", () => {
        const html = hydrateMemberEditHtml(
            shell,
            household,
            { member_no: 'MB-038', resident_id: 38, field_hash: 'OWN-HASH', name: 'Dan Labobu', first_name: 'Dan', last_name: 'Labobu' },
            '561',
            'MB-001',
        );
        assert.match(html, /data-offline-field-hash="OWN-HASH"/);
        assert.equal(html.includes('DONOR-HASH'), false);
        assert.match(html, /data-offline-parent-resident-id="38"/);
        assert.equal(html.includes('MB-001'), false);
    });

    it('drops the donor hash when the member has none yet (forces re-preparation)', () => {
        const html = hydrateMemberEditHtml(
            shell,
            household,
            { member_no: 'MB-038', resident_id: 38, name: 'Dan Labobu' },
            '561',
            'MB-001',
        );
        assert.equal(html.includes('DONOR-HASH'), false);
        assert.equal(/data-offline-field-hash/.test(html), false);
    });

    it('setMemberFieldHash refreshes only that member snapshot', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR, {
            generated_at: 'x',
            catalogs: {},
            households: [{ household_id: 5, household_no: '561', display_no: 'HH 561' }],
            members: [
                { household_id: 5, household_no: '561', resident_id: 38, member_no: 'MB-038', name: 'Dan', field_hash: 'old-38' },
                { household_id: 5, household_no: '561', resident_id: 39, member_no: 'MB-039', name: 'Eve', field_hash: 'old-39' },
            ],
        });

        await setMemberFieldHash(ACTOR, '561', 'MB-038', 'new-38');
        assert.equal((await getMemberSnapshot(ACTOR, '561', 'MB-038')).field_hash, 'new-38');
        assert.equal((await getMemberSnapshot(ACTOR, '561', 'MB-039')).field_hash, 'old-39');
        assert.equal(await setMemberFieldHash(ACTOR, '561', 'MB-999', 'x'), null);
        assert.equal(await setMemberFieldHash(ACTOR, '561', 'MB-038', ''), null);
        assert.equal((await getMemberSnapshot(ACTOR, '561', 'MB-038')).field_hash, 'new-38');
    });
});
