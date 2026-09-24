/**
 * Minimum usable offline workflow: Health under MB-L-* + EH under local HH.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href
);
const {
    enqueueOperation,
    listOperationsForActor,
    isUnresolvedLocalParent,
    isUnresolvedLocalHouseholdParent,
    applyHouseholdIdentityToDependents,
    applyServerIdentityToDependents,
    compareReplayOrder,
    listReplayableForActor,
    markAttention,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href);
const {
    handleHouseholdCreateQueued,
    reconcileHouseholdCreateSuccess,
    mergePendingLocalHouseholdsIntoEhList,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-local-household.js')).href);
const { handleResidentCreateQueued } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-local-member.js')).href
);
const { handleHealthServiceQueued, reconcileHealthServiceSuccess } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-local-health.js')).href
);
const { listHealthSnapshots, getHealthSnapshot } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-health-store.js')).href
);
const { handleEnvironmentalStepQueued } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-eh-hydrate.js')).href
);
const { getHouseholdSnapshot, getMemberSnapshot, listMemberSnapshots } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href
);
const { createDocument } = await import('./support/sidebar-mini-dom.mjs');

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

async function seedLocalTree(actorId = ACTOR_A) {
    await enqueueOperation({
        actor_id: actorId,
        operation_type: 'HOUSEHOLD_CREATE',
        payload: { household_no: '128', zone: '3' },
        created_at_client: '2026-09-22T02:00:00.000Z',
    });
    await handleHouseholdCreateQueued({
        operation_type: 'HOUSEHOLD_CREATE',
        payload: { household_no: '128', zone: '3' },
    }, { actorId, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

    const member = await enqueueOperation({
        actor_id: actorId,
        operation_type: 'RESIDENT_CREATE',
        payload: {
            client_local_member_id: 'MB-L-HEALTH01',
            first_name: 'Maria',
            last_name: 'Santos',
            sex: 'Female',
            relation: 'Spouse',
            birthday: '1990-01-01',
            occupation: 'Student',
            education: 'College Graduate',
            monthly_income: 'None / N/A',
            religion: 'Roman Catholic',
            fp_user: 'N/A',
            disability: ['None'],
            medical_history: ['None'],
            relationship_status: 'Single',
        },
        parent_server: { household_no: '128' },
        created_at_client: '2026-09-22T02:01:00.000Z',
    });
    await handleResidentCreateQueued({
        operation_type: 'RESIDENT_CREATE',
        payload: member.payload,
        parent_server: member.parent_server,
    }, { actorId, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

    const health = await enqueueOperation({
        actor_id: actorId,
        operation_type: 'HEALTH_SERVICE_WRITE',
        payload: {
            client_local_member_id: 'MB-L-HEALTH01',
            _health_action: 'timbang_record_store',
            date: '2026-09-22',
            weight: '12.5',
            height: '80',
            overall_nutritional_status: 'Normal',
        },
        parent_server: { household_no: '128', member_no: 'MB-L-HEALTH01' },
        created_at_client: '2026-09-22T02:02:00.000Z',
    });
    await handleHealthServiceQueued({
        operation_type: 'HEALTH_SERVICE_WRITE',
        payload: health.payload,
        parent_server: health.parent_server,
        local_id: health.local_id,
    }, { actorId, navigate: false });

    const eh = await enqueueOperation({
        actor_id: actorId,
        operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
        payload: {
            household_no: '128',
            _eh_step: 1,
            water_supply_status: 'level_i',
            water_source_location: 'yard',
            water_availability: 'year_round',
        },
        parent_server: { household_no: '128' },
        created_at_client: '2026-09-22T02:03:00.000Z',
    });
    await handleEnvironmentalStepQueued({
        operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
        payload: eh.payload,
        parent_server: eh.parent_server,
    }, { actorId, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

    return { member, health, eh };
}

describe('usable offline Health + EH continuum', () => {
    it('TEST 1: local HH + Member + Health + EH are all visible locally', async () => {
        await seedLocalTree();
        const hh = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(hh.pending_sync, true);
        assert.equal(hh.water.status, 'level_i');
        assert.equal((await listMemberSnapshots(ACTOR_A, '128')).length, 1);
        const healthRows = await listHealthSnapshots(ACTOR_A, 'MB-L-HEALTH01');
        assert.equal(healthRows.length, 1);
        assert.equal(healthRows[0].health_action, 'timbang_record_store');
        assert.equal(healthRows[0].payload.weight, '12.5');
        assert.equal(healthRows[0].pending_sync, true);
    });

    it('TEST 2: local Health edit coalesces via queue dedupe and updates snapshot', async () => {
        await seedLocalTree();
        const again = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                client_local_member_id: 'MB-L-HEALTH01',
                _health_action: 'timbang_record_store',
                date: '2026-09-22',
                weight: '13.0',
                height: '81',
                overall_nutritional_status: 'Normal',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-HEALTH01' },
        });
        await handleHealthServiceQueued({
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: again.payload,
            parent_server: again.parent_server,
            local_id: again.local_id,
        }, { actorId: ACTOR_A });

        const healthOps = (await listOperationsForActor(ACTOR_A)).filter((row) => (
            row.operation_type === 'HEALTH_SERVICE_WRITE'
        ));
        assert.equal(healthOps.length, 1);
        assert.equal(healthOps[0].payload.weight, '13.0');
        const snap = (await listHealthSnapshots(ACTOR_A, 'MB-L-HEALTH01'))[0];
        assert.equal(snap.payload.weight, '13.0');
    });

    it('TEST 3: dependency blocking for Member / Health / EH', async () => {
        await seedLocalTree();
        const ops = await listOperationsForActor(ACTOR_A);
        const resident = ops.find((row) => row.operation_type === 'RESIDENT_CREATE');
        const health = ops.find((row) => row.operation_type === 'HEALTH_SERVICE_WRITE');
        const eh = ops.find((row) => row.operation_type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE');

        assert.equal(isUnresolvedLocalHouseholdParent(resident, ops), true);
        assert.equal(isUnresolvedLocalHouseholdParent(eh, ops), true);
        assert.equal(isUnresolvedLocalParent(health), true);

        const ordered = await listReplayableForActor(ACTOR_A);
        assert.equal(ordered[0].operation_type, 'HOUSEHOLD_CREATE');
        assert.ok(compareReplayOrder(ordered[0], resident) < 0);
        assert.ok(compareReplayOrder(resident, health) < 0);
    });

    it('TEST 4: reconnect rebinds HH→Member/EH and Member→Health', async () => {
        await seedLocalTree();
        await reconcileHouseholdCreateSuccess({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128' },
            identities: { household_no: '128', household_pk: 501 },
            body: { household: { id: 501, household_no: '128' } },
        }, { actorId: ACTOR_A, caches: createMemoryCaches(), origin: ORIGIN });

        let ops = await listOperationsForActor(ACTOR_A);
        const resident = ops.find((row) => row.operation_type === 'RESIDENT_CREATE');
        const eh = ops.find((row) => row.operation_type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE');
        assert.equal(resident.parent_server.household_id, 501);
        assert.equal(eh.parent_server.household_id, 501);
        assert.equal(isUnresolvedLocalHouseholdParent(resident, ops), false);
        assert.equal(isUnresolvedLocalHouseholdParent(eh, ops), false);

        await applyServerIdentityToDependents(ACTOR_A, 'MB-L-HEALTH01', {
            resident_pk: 9001,
            member_no: 'MB-55',
        });
        ops = await listOperationsForActor(ACTOR_A);
        const health = ops.find((row) => row.operation_type === 'HEALTH_SERVICE_WRITE');
        assert.equal(health.parent_server.resident_id, 9001);
        assert.equal(health.parent_server.member_no, 'MB-55');
        assert.equal(isUnresolvedLocalParent(health), false);

        await reconcileHealthServiceSuccess(health, { actorId: ACTOR_A });
        const snap = (await listHealthSnapshots(ACTOR_A, 'MB-L-HEALTH01'))[0]
            || (await listHealthSnapshots(ACTOR_A, 'MB-55'))[0];
        // clearHealthPending keeps member_no as stored until promote; pending cleared.
        assert.equal(snap.pending_sync, false);
    });

    it('TEST 5: health attention keeps local snapshot', async () => {
        await seedLocalTree();
        const health = (await listOperationsForActor(ACTOR_A)).find((row) => (
            row.operation_type === 'HEALTH_SERVICE_WRITE'
        ));
        await markAttention(health.local_id, 'VALIDATION_FAILED');
        const { markHealthServiceAttention } = await import(
            pathToFileURL(path.resolve('resources/js/offline/offline-local-health.js')).href
        );
        await markHealthServiceAttention({
            ...health,
            last_safe_error_code: 'VALIDATION_FAILED',
        }, { actorId: ACTOR_A });

        const snap = (await listHealthSnapshots(ACTOR_A, 'MB-L-HEALTH01'))[0];
        assert.ok(snap);
        assert.equal(snap.sync_attention, true);
        assert.equal(snap.payload.weight, '12.5');
        assert.equal((await listOperationsForActor(ACTOR_A)).filter((r) => r.operation_type === 'HOUSEHOLD_CREATE').length, 1);
    });

    it('TEST 6: actor isolation for Health + EH local tree', async () => {
        await seedLocalTree(ACTOR_A);
        assert.equal((await listHealthSnapshots(ACTOR_B)).length, 0);
        assert.equal(await getHouseholdSnapshot(ACTOR_B, '128'), undefined);
        assert.equal(await getMemberSnapshot(ACTOR_B, '128', 'MB-L-HEALTH01'), null);
        assert.equal((await listOperationsForActor(ACTOR_B)).length, 0);

        const doc = createDocument();
        const root = doc.createElement('div');
        root.setAttribute('data-total', '0');
        const tbody = doc.createElement('tbody');
        tbody.setAttribute('data-eh-tbody', '');
        root.appendChild(tbody);
        const merged = await mergePendingLocalHouseholdsIntoEhList(root, {
            actorId: ACTOR_B,
            document: doc,
        });
        assert.equal(merged.merged, 0);
    });

    it('EH dashboard merges local pending Household 128 for Actor A', async () => {
        await seedLocalTree(ACTOR_A);
        const doc = createDocument();
        const root = doc.createElement('div');
        root.setAttribute('data-total', '0');
        const tbody = doc.createElement('tbody');
        tbody.setAttribute('data-eh-tbody', '');
        root.appendChild(tbody);
        const merged = await mergePendingLocalHouseholdsIntoEhList(root, {
            actorId: ACTOR_A,
            document: doc,
        });
        assert.equal(merged.merged, 1);
        assert.equal(tbody.querySelector('[data-eh-row]')?.getAttribute('data-household-no'), '128');
    });
});
