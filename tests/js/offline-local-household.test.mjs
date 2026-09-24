/**
 * Phase 1 — information-first HOUSEHOLD_CREATE → hp_households local continuum.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    getHouseholdSnapshot,
    listHouseholdSnapshots,
    persistPlotHouseholdReadModel,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href);
const {
    enqueueOperation,
    listOperations,
    markAttention,
    getOperation,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href);
const {
    handleHouseholdCreateQueued,
    handleHouseholdUpdateQueued,
    reconcileHouseholdCreateSuccess,
    markHouseholdCreateAttention,
    isLocalPendingHousehold,
    mergePendingLocalHouseholdsIntoList,
    localPendingHouseholdRowHtml,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-local-household.js')).href);
const {
    cacheHydratedHouseholdPages,
    parseHouseholdProfilingPath,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href);
const { applyFilters } = await import(
    pathToFileURL(path.resolve('resources/js/pages/household-profiling.js')).href
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

describe('Phase 1 local-first HOUSEHOLD_CREATE', () => {
    it('A: queues HOUSEHOLD_CREATE and persists hp_households snapshot', async () => {
        const queued = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: {
                household_no: '128',
                zone: '3',
                street: 'Layuan',
                date_registered: '2026-09-22',
            },
        });
        assert.equal(queued.operation_type, 'HOUSEHOLD_CREATE');
        assert.equal(queued.status, 'pending');

        const result = await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: queued.payload,
            parent_server: null,
        }, {
            actorId: ACTOR_A,
            navigate: false,
            caches: createMemoryCaches(),
            origin: ORIGIN,
        });

        assert.equal(result.ok, true);
        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.ok(snap);
        assert.equal(snap.local, true);
        assert.equal(snap.pending_sync, true);
        assert.equal(snap.household_id, null);
        assert.equal(snap.household_no, '128');
        assert.equal(snap.zone, 'Zone 3');
        assert.equal(snap.source, 'information');
    });

    it('B: merges local pending household into profiling list', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, {
            actorId: ACTOR_A,
            navigate: false,
            caches: createMemoryCaches(),
            origin: ORIGIN,
        });

        const doc = createDocument();
        const root = doc.createElement('div');
        root.setAttribute('data-lml-hh-profiling', '');
        root.setAttribute('data-total', '0');
        root.setAttribute('data-offline-actor-id', String(ACTOR_A));
        const offline = doc.createElement('div');
        offline.setAttribute('data-lml-offline-root', '');
        offline.setAttribute('data-offline-actor-id', String(ACTOR_A));
        offline.appendChild(root);
        const tbody = doc.createElement('tbody');
        tbody.setAttribute('data-hh-tbody', '');
        root.appendChild(tbody);
        doc.body?.appendChild?.(offline) || offline;

        const merged = await mergePendingLocalHouseholdsIntoList(root, {
            actorId: ACTOR_A,
            document: doc,
            root: offline,
        });
        assert.equal(merged.merged, 1);
        const row = tbody.querySelector('[data-hh-row]');
        assert.ok(row);
        assert.equal(row.getAttribute('data-household-no'), '128');
        assert.equal(row.getAttribute('data-zone'), 'Zone 3');
        assert.match(localPendingHouseholdRowHtml({
            household_no: '128',
            zone: 'Zone 3',
        }), /Waiting to sync/);
    });

    it('C: local household can be opened offline via hydrated cache', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, {
            actorId: ACTOR_A,
            navigate: false,
            caches: createMemoryCaches(),
            origin: ORIGIN,
        });

        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        const caches = createMemoryCaches();
        await cacheHydratedHouseholdPages(ACTOR_A, snap, [], {
            caches,
            origin: ORIGIN,
        });

        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        const hit = await cache.match(new Request(
            navigationCacheUrl(ORIGIN, '/household-profiling/128'),
        ));
        assert.equal(Boolean(hit), true);
        const html = await hit.text();
        assert.match(html, /Waiting to sync/);
        assert.match(html, /Zone 3|data-hh-view-zone/);
        assert.equal(parseHouseholdProfilingPath('/household-profiling/128')?.kind, 'view-household');
        assert.equal(parseHouseholdProfilingPath('/household-profiling/128/edit')?.kind, 'household-edit');
    });

    it('D+E+F: edit coalesces into HOUSEHOLD_CREATE and updates hp_households', async () => {
        await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3', street: 'A' },
        });
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3', street: 'A' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const update = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HOUSEHOLD_UPDATE',
            payload: { household_no: '128', zone: '4', street: 'B' },
            parent_server: { household_no: '128' },
        });

        assert.equal(update.operation_type, 'HOUSEHOLD_CREATE');
        assert.equal(update.payload.zone, '4');
        assert.equal(update.payload.street, 'B');
        assert.equal(update.payload.household_no, '128');

        const ops = await listOperations();
        assert.equal(ops.filter((row) => row.operation_type === 'HOUSEHOLD_UPDATE').length, 0);
        assert.equal(ops.filter((row) => row.operation_type === 'HOUSEHOLD_CREATE').length, 1);

        await handleHouseholdUpdateQueued({
            operation_type: 'HOUSEHOLD_UPDATE',
            payload: update.payload,
            parent_server: { household_no: '128' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(snap.zone, 'Zone 4');
        assert.equal(snap.street, 'B');
        assert.equal(snap.pending_sync, true);
        assert.equal(snap.local, true);
        assert.equal(snap.household_id, null);
    });

    it('G: reconnect reconciles local snapshot once create succeeds', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '4' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        await reconcileHouseholdCreateSuccess({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '4' },
            identities: { household_no: '128', household_pk: 501 },
            body: { household: { id: 501, household_no: '128' } },
        }, { actorId: ACTOR_A, caches: createMemoryCaches(), origin: ORIGIN });

        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(snap.household_id, 501);
        assert.equal(snap.local, false);
        assert.equal(snap.pending_sync, false);
        assert.equal(snap.household_no, '128');
        assert.equal(snap.zone, 'Zone 4');
        assert.equal((await listHouseholdSnapshots(ACTOR_A)).length, 1);
    });

    it('H: duplicate/conflict keeps local household and marks attention', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const queued = (await listOperations())[0] || await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        });
        await markAttention(queued.local_id, 'VALIDATION_FAILED');
        await markHouseholdCreateAttention({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128' },
            last_safe_error_code: 'VALIDATION_FAILED',
        }, { actorId: ACTOR_A });

        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.ok(snap);
        assert.equal(snap.pending_sync, true);
        assert.equal(snap.sync_attention, true);
        assert.equal(snap.last_sync_error, 'VALIDATION_FAILED');
        assert.equal(snap.household_no, '128');
    });

    it('I: actor B cannot see actor A local household', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        assert.equal(await isLocalPendingHousehold(ACTOR_A, '128'), true);
        assert.equal(await isLocalPendingHousehold(ACTOR_B, '128'), false);
        assert.equal((await listHouseholdSnapshots(ACTOR_B)).length, 0);

        const doc = createDocument();
        const root = doc.createElement('div');
        root.setAttribute('data-total', '0');
        const tbody = doc.createElement('tbody');
        tbody.setAttribute('data-hh-tbody', '');
        root.appendChild(tbody);

        const merged = await mergePendingLocalHouseholdsIntoList(root, {
            actorId: ACTOR_B,
            document: doc,
        });
        assert.equal(merged.merged, 0);
        assert.equal(tbody.querySelectorAll('[data-hh-row]').length, 0);
    });

    it('J: plot household offline persist still works alongside information create', async () => {
        await persistPlotHouseholdReadModel(ACTOR_A, {
            household_no: '534',
            zone: 5,
            first_name: 'Harem',
            last_name: 'Scarem',
            birthday: '1988-01-11',
            sex: 'Male',
            lat: 13.37,
            lng: 123.42,
        });
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const plot = await getHouseholdSnapshot(ACTOR_A, '534');
        const info = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(plot.source, 'plot');
        assert.equal(plot.pending_sync, true);
        assert.equal(info.source, 'information');
        assert.equal(info.pending_sync, true);
    });

    it('does not duplicate when server row already lists the household', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const doc = createDocument();
        const root = doc.createElement('div');
        root.setAttribute('data-total', '1');
        const tbody = doc.createElement('tbody');
        tbody.setAttribute('data-hh-tbody', '');
        const existing = doc.createElement('tr');
        existing.setAttribute('data-hh-row', '');
        existing.setAttribute('data-household-no', '128');
        existing.setAttribute('data-zone', 'Zone 3');
        existing.setAttribute('data-house-head', 'Server Head');
        tbody.appendChild(existing);
        root.appendChild(tbody);

        const merged = await mergePendingLocalHouseholdsIntoList(root, {
            actorId: ACTOR_A,
            document: doc,
        });
        assert.equal(merged.merged, 0);
        assert.equal(tbody.querySelectorAll('[data-hh-row]').length, 1);
    });

    it('list filters still apply to merged local rows', async () => {
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, navigate: false, caches: createMemoryCaches(), origin: ORIGIN });

        const doc = createDocument();
        const root = doc.createElement('div');
        root.setAttribute('data-total', '0');
        const tbody = doc.createElement('tbody');
        tbody.setAttribute('data-hh-tbody', '');
        root.appendChild(tbody);
        const zone = doc.createElement('select');
        zone.setAttribute('data-hh-zone', '');
        const optAll = doc.createElement('option');
        optAll.setAttribute('value', 'all');
        const opt3 = doc.createElement('option');
        opt3.setAttribute('value', 'Zone 3');
        const opt4 = doc.createElement('option');
        opt4.setAttribute('value', 'Zone 4');
        zone.appendChild(optAll);
        zone.appendChild(opt3);
        zone.appendChild(opt4);
        zone.value = 'Zone 4';
        root.appendChild(zone);

        await mergePendingLocalHouseholdsIntoList(root, {
            actorId: ACTOR_A,
            document: doc,
        });
        applyFilters(root);
        const row = tbody.querySelector('[data-hh-row]');
        assert.equal(row.hidden, true);

        zone.value = 'Zone 3';
        applyFilters(root);
        assert.equal(row.hidden, false);
    });
});
