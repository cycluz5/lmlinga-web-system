/**
 * Information-first offline Household create must follow the ONLINE journey:
 * Initial HH Save → Environmental Health → Household Information → Member → Health.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { canonicalEhShellHtml } from './support/eh-canonical-shell.mjs';
import {
    htmlCacheNameForActor,
    navigationCacheUrl,
    fallbackHtml,
} from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { enqueueOperation, listOperationsForActor } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const { getHouseholdSnapshot, getMeta, putMeta, getMemberSnapshot } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href
);
const {
    handleHouseholdCreateQueued,
    EH_RETURN_TO_HOUSEHOLD_META,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-local-household.js')).href);
const {
    ehStep1Url,
    ehStepUrl,
    handleEnvironmentalStepQueued,
    isCanonicalEhShellHtml,
    isPlainEhFallbackHtml,
    resetEhShellWarmupForTests,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-eh-hydrate.js')).href);
const { handleResidentCreateQueued } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-local-member.js')).href
);
const { handleHealthServiceQueued } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-local-health.js')).href
);
const { listHealthSnapshots } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-health-store.js')).href
);

const ORIGIN = 'https://lmlinga.test';
const ACTOR_A = 7;
const ACTOR_B = 9;

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
    resetEhShellWarmupForTests();
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

function dashboardHpViewShell(householdNo = '999') {
    return `<!DOCTYPE html><html><head>
<link rel="stylesheet" href="/build/assets/app.css">
<script type="module" src="/build/assets/app.js"></script>
</head><body>
<div class="lml-dashboard" data-lml-offline-root data-offline-actor-id="${ACTOR_A}">
<aside class="lml-sidebar">Sidebar</aside>
<header class="lml-topbar">Topbar <span data-lml-offline-pending>0</span></header>
<main id="main-content">
<article class="lml-hh-view" data-lml-hh-view data-household-no="${householdNo}">
<h2 class="lml-hh-view__hh-no">HH ${householdNo}</h2>
<p class="lml-hh-view__head"><span class="lml-hh-view__head-label">Head of Household</span>
<span class="lml-hh-view__head-name">—</span></p>
<span class="lml-hh-view__members-badge" aria-label="0 members"><span>0 members</span></span>
<dl class="lml-hh-view__meta">
<div class="lml-hh-view__meta-item"><dt><span>Zone</span></dt><dd data-hh-view-zone>Zone 1</dd></div>
<div class="lml-hh-view__meta-item"><dt><span>Accomplished Date</span></dt><dd data-hh-view-date>—</dd></div>
</dl>
<article class="lml-hh-view__amenity lml-hh-view__amenity--water">
<p class="lml-hh-view__amenity-detail">—</p>
<span class="lml-hh-view__amenity-badge">Not recorded</span>
</article>
<article class="lml-hh-view__amenity lml-hh-view__amenity--sanitation">
<p class="lml-hh-view__amenity-detail">—</p>
<span class="lml-hh-view__amenity-badge">Not recorded</span>
</article>
<section class="lml-hh-view__members" aria-labelledby="lml-hh-view-members-title">
<div class="lml-hh-view__members-header">
<h2 id="lml-hh-view-members-title" class="lml-hh-view__members-title">Household Members (0)</h2>
<a href="/household-profiling/${householdNo}/members/create" class="lml-hh-view__add-member" data-hh-nav="add-member"><span>Add Household Member</span></a>
</div>
</section>
</article>
</main>
</div>
</body></html>`;
}

async function seedShells(actorId = ACTOR_A) {
    for (const step of [1, 2, 3, 4]) {
        await putMeta(actorId, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
    }
    await putMeta(actorId, 'shell:eh-household_no', 'LML-EH');
    await putMeta(actorId, 'shell:view', dashboardHpViewShell('999'));
    await putMeta(actorId, 'shell:household_no', '999');
    await putMeta(actorId, 'shell:create', `<!DOCTYPE html><html><body class="lml-dashboard">
<form data-offline-operation="RESIDENT_CREATE" data-offline-parent-household-no="999">
<input name="first_name"><input name="last_name"><select name="sex"><option>Female</option></select>
<select name="relation"><option>Daughter</option></select>
<button type="submit">Save</button></form></body></html>`);
}

async function cachedHtml(caches, pathname, search = '', actorId = ACTOR_A) {
    const cached = await caches.match(
        new Request(navigationCacheUrl(ORIGIN, pathname, search)),
        { cacheName: htmlCacheNameForActor(actorId) },
    );
    return cached ? cached.text() : '';
}

function assertNotEmergencyOrRaw(html) {
    assert.ok(html.length > 0, 'expected cached HTML');
    assert.doesNotMatch(html, /data-lml-offline-fallback/);
    assert.doesNotMatch(html, /You are offline/);
    assert.match(html, /lml-dashboard/);
    assert.match(html, /\/build\/assets\/app\.(css|js)/);
}

describe('offline information-first HH → EH → Household Information journey', () => {
    it('TEST 1: initial save queues HOUSEHOLD_CREATE and transitions to EH (not HH view)', async () => {
        await seedShells();
        const caches = createMemoryCaches();
        const navigated = [];
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
        const result = await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: queued.payload,
        }, {
            actorId: ACTOR_A,
            caches,
            origin: ORIGIN,
            assign: (url) => navigated.push(url),
        });

        assert.equal(result.ok, true);
        assert.equal(result.path, ehStep1Url('128'));
        assert.deepEqual(navigated, [ehStep1Url('128')]);
        assert.doesNotMatch(result.path, /^\/household-profiling\/128$/);
        assert.equal(result.path.startsWith('/household-profiling/128'), false);

        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(snap.local, true);
        assert.equal(snap.pending_sync, true);
        assert.equal(snap.household_id, null);
        assert.equal(await getMeta(ACTOR_A, EH_RETURN_TO_HOUSEHOLD_META), '128');

        const ops = await listOperationsForActor(ACTOR_A);
        assert.equal(ops.some((row) => row.operation_type === 'HOUSEHOLD_CREATE'), true);
    });

    it('TEST 2: EH Step 1 is normal styled shell for local HH 128', async () => {
        await seedShells();
        const caches = createMemoryCaches();
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });

        const html = await cachedHtml(
            caches,
            '/environmental-health/household-water-supply',
            '?household=128',
        );
        assert.equal(isCanonicalEhShellHtml(html, 1), true);
        assert.equal(isPlainEhFallbackHtml(html), false);
        assertNotEmergencyOrRaw(html);
        assert.match(html, /data-household-no="128"/);
        assert.match(html, /data-offline-local-household="1"/);
        assert.doesNotMatch(html, /data-offline-parent-household-id=/);
    });

    it('TEST 3: EH save queues mutation, updates snapshot, advances to next online-equivalent step', async () => {
        await seedShells();
        const caches = createMemoryCaches();
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });

        await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
            payload: {
                household_no: '128',
                _eh_step: 1,
                water_supply_status: 'level_ii',
                water_source_location: 'yes',
                water_availability: 'yes',
            },
            parent_server: { household_no: '128' },
        });
        const navigated = [];
        const continued = await handleEnvironmentalStepQueued({
            operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
            payload: {
                household_no: '128',
                _eh_step: 1,
                water_supply_status: 'level_ii',
                water_source_location: 'yes',
                water_availability: 'yes',
            },
            parent_server: { household_no: '128' },
        }, {
            actorId: ACTOR_A,
            caches,
            origin: ORIGIN,
            assign: (url) => navigated.push(url),
        });

        assert.equal(continued.path, ehStepUrl('128', 2));
        assert.deepEqual(navigated, [ehStepUrl('128', 2)]);
        const snap = await getHouseholdSnapshot(ACTOR_A, '128');
        assert.equal(snap.water.status, 'level_ii');
        assert.equal(snap.eh.completed_step, 1);
        assertNotEmergencyOrRaw(await cachedHtml(caches, '/environmental-health/household-water-supply/128/step-2'));
    });

    it('TEST 4+5: EH completion lands on styled Household Information (not raw/emergency)', async () => {
        await seedShells();
        const caches = createMemoryCaches();
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3', date_registered: '2026-09-22' },
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });

        const steps = [
            { step: 1, payload: { household_no: '128', _eh_step: 1, water_supply_status: 'level_i', water_source_location: 'yes', water_availability: 'yes' } },
            { step: 2, payload: { household_no: '128', _eh_step: 2 } },
            { step: 3, payload: { household_no: '128', _eh_step: 3, toilet_type: 'flush_to_septic_tank', open_defecation_practiced: 'no', shared_toilet: 'no', sewage_disposal_method: 'off_site_collected_and_treated' } },
            { step: 4, payload: { household_no: '128', _eh_step: 4, solid_waste_practices: ['waste_segregation'] } },
        ];
        let lastPath = '';
        for (const row of steps) {
            const result = await handleEnvironmentalStepQueued({
                operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
                payload: row.payload,
                parent_server: { household_no: '128' },
            }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });
            lastPath = result.path;
        }

        assert.equal(lastPath, '/household-profiling/128');
        const hp = await cachedHtml(caches, '/household-profiling/128');
        assertNotEmergencyOrRaw(hp);
        assert.match(hp, /Waiting to sync/i);
        assert.match(hp, /lml-hh-view/);
        assert.match(hp, /Add Household Member/);
        assert.match(hp, /data-hh-nav="add-member"/);
        assert.match(hp, /Zone 3|data-hh-view-zone/);
        assert.doesNotMatch(hp, /<!DOCTYPE html><html><head><meta charset="utf-8"><title>128/);
        assert.equal(String(await getMeta(ACTOR_A, EH_RETURN_TO_HOUSEHOLD_META) || ''), '');
    });

    it('TEST 6: Add Member under local HH still works (Phase 2)', async () => {
        await seedShells();
        const caches = createMemoryCaches();
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });

        const member = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-JOURNEY01',
                first_name: 'Maria',
                last_name: 'Santos',
                sex: 'Female',
                relation: 'Daughter',
                birthday: '2015-05-01',
                education: 'Elementary Level',
            },
            parent_server: { household_no: '128' },
        });
        await handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: member.payload,
            parent_server: member.parent_server,
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });

        const snap = await getMemberSnapshot(ACTOR_A, '128', 'MB-L-JOURNEY01');
        assert.ok(snap);
        assert.equal(snap.first_name, 'Maria');
        assert.match(String(snap.member_no), /^MB-L-/i);
    });

    it('TEST 7: Health continuum under local Member still works', async () => {
        await seedShells();
        const caches = createMemoryCaches();
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });
        await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'RESIDENT_CREATE',
            payload: {
                client_local_member_id: 'MB-L-JOURNEY01',
                first_name: 'Maria',
                last_name: 'Santos',
                sex: 'Female',
                relation: 'Daughter',
                birthday: '2015-05-01',
                education: 'Elementary Level',
            },
            parent_server: { household_no: '128' },
        }).then((row) => handleResidentCreateQueued({
            operation_type: 'RESIDENT_CREATE',
            payload: row.payload,
            parent_server: row.parent_server,
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false }));

        await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                client_local_member_id: 'MB-L-JOURNEY01',
                _health_action: 'timbang_record_store',
                weight_kg: '18.5',
                height_cm: '105',
                overall_nutritional_status: 'Normal',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-JOURNEY01', client_local_member_id: 'MB-L-JOURNEY01' },
        });
        await handleHealthServiceQueued({
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                client_local_member_id: 'MB-L-JOURNEY01',
                _health_action: 'timbang_record_store',
                weight_kg: '18.5',
                height_cm: '105',
                overall_nutritional_status: 'Normal',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-JOURNEY01', client_local_member_id: 'MB-L-JOURNEY01' },
        }, { actorId: ACTOR_A });

        const health = await listHealthSnapshots(ACTOR_A, 'MB-L-JOURNEY01');
        assert.ok(health.length >= 1);
        assert.equal(health[0].pending_sync, true);
    });

    it('TEST 8: Actor B cannot open Actor A local HH/EH tree', async () => {
        await seedShells(ACTOR_A);
        const caches = createMemoryCaches();
        await handleHouseholdCreateQueued({
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '128', zone: '3' },
        }, { actorId: ACTOR_A, caches, origin: ORIGIN, navigate: false });

        assert.equal(await getHouseholdSnapshot(ACTOR_B, '128'), undefined);
        assert.ok([undefined, null, ''].includes(await getMeta(ACTOR_B, EH_RETURN_TO_HOUSEHOLD_META)));
        const bEh = await caches.match(
            new Request(navigationCacheUrl(ORIGIN, '/environmental-health/household-water-supply', '?household=128')),
            { cacheName: htmlCacheNameForActor(ACTOR_B) },
        );
        assert.equal(bEh, undefined);
    });

    it('TEST 10: emergency fallback HTML remains distinct from journey pages', () => {
        const html = fallbackHtml();
        assert.match(html, /You are offline/);
        assert.match(html, /data-lml-offline-fallback/);
        assert.doesNotMatch(html, /lml-hws__program-title/);
    });
});

describe('online household create → EH redirect regression (source contract)', () => {
    it('TEST 9: controller still redirects create to EH handoff (not Spot Mapping)', async () => {
        const fs = await import('node:fs');
        const controller = fs.readFileSync(
            path.resolve('app/Http/Controllers/HouseholdProfiling/HouseholdProfilingController.php'),
            'utf8',
        );
        const ehController = fs.readFileSync(
            path.resolve('app/Http/Controllers/EnvironmentalHealth/HouseholdWaterSupplyController.php'),
            'utf8',
        );
        assert.match(controller, /environmental-health\.household-water-supply/);
        assert.match(controller, /rememberForHouseholdProfilingCreate/);
        assert.match(ehController, /consumeIfMatches/);
        assert.match(ehController, /household-profiling\.view/);

        // After the spot-mapping branch closes, the default create path must EH-handoff.
        const afterSpot = controller.slice(controller.indexOf("} // end spot") === -1
            ? controller.indexOf('$token = $this->handoff')
            : controller.indexOf('$token = $this->handoff'));
        assert.match(afterSpot, /environmental-health\.household-water-supply/);
        assert.match(afterSpot, /rememberForHouseholdProfilingCreate/);
        assert.equal(afterSpot.includes("route('spot-mapping.index'"), false);
    });
});
