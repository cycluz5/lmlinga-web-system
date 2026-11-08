/**
 * Phase 2 — MB-L-* members under information-first local Households.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    getHouseholdSnapshot,
    getMemberSnapshot,
    listMemberSnapshots,
    listHouseholdSnapshots,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href);
const {
    enqueueOperation,
    listOperations,
    listOperationsForActor,
    listReplayableForActor,
    compareReplayOrder,
    isUnresolvedLocalHouseholdParent,
    applyHouseholdIdentityToDependents,
    markAttention,
    getOperation,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href);
const {
    handleHouseholdCreateQueued,
    handleHouseholdUpdateQueued,
    reconcileHouseholdCreateSuccess,
    markHouseholdCreateAttention,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-local-household.js')).href);
const {
    handleResidentCreateQueued,
    handleResidentUpdateQueued,
    localMemberViewPath,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-local-member.js')).href);
const { cacheHydratedHouseholdPages, hydrateHouseholdViewHtml } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href
);
const { promoteLocalMemberIdentity } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href
);

const ORIGIN = 'https://lmlinga.test';
const ACTOR_A = 7;
const ACTOR_B = 9;

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

async function createLocalHousehold(actorId, householdNo = '128', zone = '3') {
    await enqueueOperation({
        actor_id: actorId,
        operation_type: 'HOUSEHOLD_CREATE',
        payload: { household_no: householdNo, zone, date_registered: '2026-09-22' },
        created_at_client: '2026-09-22T01:00:00.000Z',
    });
    await handleHouseholdCreateQueued({
        operation_type: 'HOUSEHOLD_CREATE',
        payload: { household_no: householdNo, zone, date_registered: '2026-09-22' },
    }, {
        actorId,
        navigate: false,
        caches: createMemoryCaches(),
        origin: ORIGIN,
    });
}

async function queueLocalMember(actorId, householdNo, payload) {
    const queued = await enqueueOperation({
        actor_id: actorId,
        operation_type: 'RESIDENT_CREATE',
        payload: {
            client_local_member_id: payload.client_local_member_id,
            first_name: payload.first_name,
            last_name: payload.last_name,
            sex: payload.sex || 'Female',
            relation: payload.relation || 'Spouse',
            birthday: payload.birthday || '1990-01-01',
            occupation: payload.occupation || 'Student',
            education: payload.education || 'College Graduate',
            monthly_income: payload.monthly_income || 'None / N/A',
            religion: payload.religion || 'Roman Catholic',
            fp_user: payload.fp_user || 'N/A',
            disability: payload.disability || ['None'],
            medical_history: payload.medical_history || ['None'],
            relationship_status: payload.relationship_status || 'Single',
        },
        parent_server: { household_no: householdNo },
        created_at_client: payload.created_at_client || '2026-09-22T01:01:00.000Z',
    });
    await handleResidentCreateQueued({
        operation_type: 'RESIDENT_CREATE',
        payload: queued.payload,
        parent_server: queued.parent_server,
    }, {
        actorId,
        navigate: false,
        caches: createMemoryCaches(),
        origin: ORIGIN,
    });
    return queued;
}

describe('Phase 2 local Household + MB-L-* Member', () => {
    it('A+B+C: Add Member under local HH queues RESIDENT_CREATE with MB-L-* and hp_members', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        const queued = await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });

        assert.equal(queued.operation_type, 'RESIDENT_CREATE');
        assert.match(queued.payload.client_local_member_id, /^MB-L-/i);
        assert.equal(queued.parent_server.household_no, '128');
        assert.equal(queued.parent_server.household_id, undefined);

        const member = await getMemberSnapshot(ACTOR_A, '128', 'MB-L-AAA111');
        assert.ok(member);
        assert.equal(member.first_name, 'Maria');
        assert.equal(member.local, true);
        assert.equal(member.household_id, null);
    });

    it('D: local Member appears in Household member list HTML', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });
        const household = await getHouseholdSnapshot(ACTOR_A, '128');
        const members = await listMemberSnapshots(ACTOR_A, '128');
        const html = hydrateHouseholdViewHtml(
            '<section class="lml-hh-view__members"><h2>Household Members (0)</h2></section>',
            household,
            members,
            '128',
        );
        assert.match(html, /Maria Santos/);
        assert.match(html, /MB-L-AAA111/);
        assert.match(html, /Waiting to sync/);
    });

    it('E: local Member opens offline via cached view', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });
        const caches = createMemoryCaches();
        const household = await getHouseholdSnapshot(ACTOR_A, '128');
        const members = await listMemberSnapshots(ACTOR_A, '128');
        await cacheHydratedHouseholdPages(ACTOR_A, household, members, { caches, origin: ORIGIN });

        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        const hit = await cache.match(new Request(
            navigationCacheUrl(ORIGIN, localMemberViewPath('128', 'MB-L-AAA111')),
        ));
        assert.equal(Boolean(hit), true);
        const html = await hit.text();
        assert.match(html, /Maria/);
        assert.match(html, /Waiting to sync/);
    });

    it('F+G: Member edit coalesces into RESIDENT_CREATE and updates hp_members', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
            occupation: 'Student',
        });

        const edited = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'RESIDENT_UPDATE',
            payload: {
                client_local_member_id: 'MB-L-AAA111',
                first_name: 'Maria',
                last_name: 'Santos',
                occupation: 'Teacher',
                sex: 'Female',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-AAA111' },
        });
        assert.equal(edited.operation_type, 'RESIDENT_CREATE');
        assert.equal(edited.payload.occupation, 'Teacher');

        await handleResidentUpdateQueued({
            operation_type: 'RESIDENT_UPDATE',
            payload: edited.payload,
            parent_server: { household_no: '128', member_no: 'MB-L-AAA111' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const member = await getMemberSnapshot(ACTOR_A, '128', 'MB-L-AAA111');
        assert.equal(member.occupation, 'Teacher');
        const creates = (await listOperations()).filter((row) => row.operation_type === 'RESIDENT_CREATE');
        assert.equal(creates.length, 1);
        assert.equal((await listOperations()).filter((row) => row.operation_type === 'RESIDENT_UPDATE').length, 0);
    });

    it('H: RESIDENT_CREATE does not replay while HOUSEHOLD_CREATE unresolved', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });

        const ops = await listOperationsForActor(ACTOR_A);
        const resident = ops.find((row) => row.operation_type === 'RESIDENT_CREATE');
        assert.equal(isUnresolvedLocalHouseholdParent(resident, ops), true);

        const ordered = await listReplayableForActor(ACTOR_A);
        assert.equal(ordered[0].operation_type, 'HOUSEHOLD_CREATE');
        assert.ok(compareReplayOrder(ordered[0], ordered[1]) < 0);
        // Replay loop would skip RESIDENT_CREATE while HH create remains.
        assert.equal(isUnresolvedLocalHouseholdParent(ordered[1], ops), true);
    });

    it('I+J+K+L: HOUSEHOLD_CREATE success rebinds then Member can sync/promote', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
            occupation: 'Teacher',
        });

        await reconcileHouseholdCreateSuccess({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
            identities: { household_no: '128', household_pk: 501 },
            body: { household: { id: 501, household_no: '128' } },
        }, { actorId: ACTOR_A, caches: createMemoryCaches(), origin: ORIGIN });

        const hh = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(hh.household_id, 501);
        assert.equal(hh.pending_sync, false);

        const resident = (await listOperationsForActor(ACTOR_A)).find((row) => (
            row.operation_type === 'RESIDENT_CREATE'
        ));
        assert.equal(resident.parent_server.household_id, 501);
        assert.equal(isUnresolvedLocalHouseholdParent(resident, await listOperationsForActor(ACTOR_A)), false);

        const memberBefore = await getMemberSnapshot(ACTOR_A, '128', 'MB-L-AAA111');
        assert.equal(memberBefore.household_id, 501);

        await promoteLocalMemberIdentity(ACTOR_A, '128', 'MB-L-AAA111', 'MB-42', 9001);
        const promoted = await getMemberSnapshot(ACTOR_A, '128', 'MB-42');
        assert.ok(promoted);
        assert.equal(promoted.local, false);
        assert.equal(await getMemberSnapshot(ACTOR_A, '128', 'MB-L-AAA111'), null);
    });

    it('M: multiple local Members under one local Household rebind together', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
            created_at_client: '2026-09-22T01:01:00.000Z',
        });
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-BBB222',
            first_name: 'Juan',
            last_name: 'Cruz',
            sex: 'Male',
            created_at_client: '2026-09-22T01:02:00.000Z',
        });
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-CCC333',
            first_name: 'Ana',
            last_name: 'Reyes',
            created_at_client: '2026-09-22T01:03:00.000Z',
        });

        assert.equal((await listMemberSnapshots(ACTOR_A, '128')).length, 3);

        const patched = await applyHouseholdIdentityToDependents(ACTOR_A, '128', 777);
        const creates = (await listOperationsForActor(ACTOR_A)).filter((row) => (
            row.operation_type === 'RESIDENT_CREATE'
        ));
        assert.equal(creates.length, 3);
        assert.equal(creates.every((row) => row.parent_server.household_id === 777), true);
        assert.ok(patched.length >= 3);
    });

    it('N: Household attention blocks Member replay and keeps Member local data', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });

        const hhOp = (await listOperationsForActor(ACTOR_A)).find((row) => (
            row.operation_type === 'HOUSEHOLD_CREATE'
        ));
        await markAttention(hhOp.local_id, 'VALIDATION_FAILED');
        await markHouseholdCreateAttention({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128' },
            last_safe_error_code: 'VALIDATION_FAILED',
        }, { actorId: ACTOR_A });

        const ops = await listOperationsForActor(ACTOR_A);
        const resident = ops.find((row) => row.operation_type === 'RESIDENT_CREATE');
        assert.equal(isUnresolvedLocalHouseholdParent(resident, ops), true);

        const member = await getMemberSnapshot(ACTOR_A, '128', 'MB-L-AAA111');
        assert.ok(member);
        assert.equal(member.first_name, 'Maria');
        assert.equal(member.waiting_for_household, true);
        assert.equal(member.sync_attention, true);

        const hh = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(hh.sync_attention, true);
        assert.equal(hh.household_no, '128');
    });

    it('O: Actor B cannot see Actor A local Household or Members', async () => {
        await createLocalHousehold(ACTOR_A, '128');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });

        assert.equal((await listHouseholdSnapshots(ACTOR_B)).length, 0);
        assert.equal((await listMemberSnapshots(ACTOR_B, '128')).length, 0);
        assert.equal((await listOperationsForActor(ACTOR_B)).length, 0);
        assert.equal(await getMemberSnapshot(ACTOR_B, '128', 'MB-L-AAA111'), null);
    });

    it('P: synced Household MB-L-* still queues without household-parent block', async () => {
        const queued = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-SYNC01',
                first_name: 'Lisa',
                last_name: 'Go',
                sex: 'Female',
            },
            parent_server: { household_id: 42, household_no: '099' },
        });
        assert.equal(isUnresolvedLocalHouseholdParent(queued, [queued]), false);
        assert.equal(queued.parent_server.household_id, 42);
    });

    it('Household edit before Member sync still coalesces (Phase 1 compatible)', async () => {
        await createLocalHousehold(ACTOR_A, '128', '3');
        await queueLocalMember(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-AAA111',
            first_name: 'Maria',
            last_name: 'Santos',
        });

        const updated = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HOUSEHOLD_UPDATE',
            payload: { household_no: '128', zone: '4' },
            parent_server: { household_no: '128' },
        });
        assert.equal(updated.operation_type, 'HOUSEHOLD_CREATE');
        assert.equal(updated.payload.zone, '4');

        await handleHouseholdUpdateQueued({
            operation_type: 'HOUSEHOLD_UPDATE',
            payload: updated.payload,
            parent_server: { household_no: '128' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const hh = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(hh.zone, 'Zone 4');
        assert.equal((await listMemberSnapshots(ACTOR_A, '128')).length, 1);
        const ops = await listOperationsForActor(ACTOR_A);
        assert.equal(ops.filter((r) => r.operation_type === 'HOUSEHOLD_CREATE').length, 1);
        assert.equal(ops.filter((r) => r.operation_type === 'HOUSEHOLD_UPDATE').length, 0);
        assert.equal(ops.filter((r) => r.operation_type === 'RESIDENT_CREATE').length, 1);
        assert.equal(ops.find((r) => r.operation_type === 'HOUSEHOLD_CREATE').payload.zone, '4');
    });
});