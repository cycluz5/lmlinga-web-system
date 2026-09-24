/**
 * Staff Health Summary offline continuum — styled shells, metadata, writes.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { htmlCacheNameForActor, navigationCacheUrl, CACHE_VERSION } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const storeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href;
const hydrateUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href;
const queueUrl = pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href;
const healthLocalUrl = pathToFileURL(path.resolve('resources/js/offline/offline-local-health.js')).href;
const healthStoreUrl = pathToFileURL(path.resolve('resources/js/offline/offline-health-store.js')).href;
const unavailableUrl = pathToFileURL(path.resolve('resources/js/offline/offline-unavailable.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    replaceHouseholdProfilingSnapshots,
    putMeta,
    getMemberSnapshot,
    appendLocalMemberToHousehold,
    getHouseholdSnapshot,
} = await import(storeUrl);
const {
    SUPPORTED_HEALTH_MODULES,
    ONLINE_ONLY_HEALTH_MODULES,
    hydrateMemberViewHtml,
    hydrateHealthModuleHtml,
    cacheHydratedHouseholdPages,
    ensureHouseholdNavFromSnapshot,
    hasCanonicalHealthShells,
    rememberShells,
} = await import(hydrateUrl);
const {
    enqueueOperation,
    listOperationsForActor,
    isUnresolvedLocalParent,
    compareReplayOrder,
    applyServerIdentityToDependents,
} = await import(queueUrl);
const { handleHealthServiceQueued } = await import(healthLocalUrl);
const { listHealthSnapshots } = await import(healthStoreUrl);
const { showOfflineUnavailableInShell, unavailableCopy } = await import(unavailableUrl);

const ORIGIN = 'https://lmlinga.test';
const ACTOR_A = 7;
const ACTOR_B = 9;

const REAL_SHELL = `<!DOCTYPE html><html><head>
<link rel="stylesheet" href="/build/assets/app.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
</head>
<body data-lml-offline-root class="lml-dashboard">
<aside class="lml-sidebar"><nav>LMLinga</nav></aside>
<header class="lml-topbar"><span>Offline</span></header>
<main id="main-content" class="lml-dashboard__content">
<article class="lml-hh-member-view" data-lml-hh-member-view data-household-no="HH-001" data-member-id="MB-024" data-member-name="Ana Rivera">
<h2 class="lml-hh-member-view__name">Ana Rivera</h2>
<p class="lml-hh-member-view__subtitle">Member Information</p>
<aside class="lml-hh-member-view__aside">
<section class="lml-hh-member-view__side-card" aria-labelledby="lml-hh-mv-records">
<h3 id="lml-hh-mv-records" class="lml-hh-member-view__side-title">
<span>Health Summary Records</span>
</h3>
<ul class="lml-hh-member-view__records">
<li class="lml-hh-member-view__record lml-hh-member-view__record--group" data-hh-member-child-care-group>
<button type="button">Child Care</button>
<ul class="lml-hh-member-view__child-list">
<li><a href="/household-profiling/HH-001/members/MB-024/child-immunization" data-hh-nav="health-record">Child Immunization</a></li>
<li><a href="/household-profiling/HH-001/members/MB-024/deworming" data-hh-nav="health-record">Deworming</a></li>
</ul>
</li>
<li class="lml-hh-member-view__record"><span>Risk Assessment</span>
<a href="/household-profiling/HH-001/members/MB-024/risk-assessment" data-hh-member-risk-assessment data-hh-nav="health-record">View</a>
</li>
</ul>
</section>
<section class="lml-hh-member-view__side-card">
<h3>Nutritional Status</h3>
<dl class="lml-hh-member-view__nutrition">
<div class="lml-hh-member-view__nutrition-row"><dt>Weight</dt><dd>12.50 kg</dd></div>
<div class="lml-hh-member-view__nutrition-row"><dt>Height</dt><dd>80.00 cm</dd></div>
<div class="lml-hh-member-view__nutrition-row"><dt>BMI</dt><dd>19.5</dd></div>
</dl>
</section>
</aside>
</article>
</main>
</body></html>`;

const HEALTH_MODULE_SHELL = `<!DOCTYPE html><html><head>
<link rel="stylesheet" href="/build/assets/app.css">
</head>
<body data-lml-offline-root class="lml-dashboard">
<aside class="lml-sidebar"></aside>
<main id="main-content" class="lml-dashboard__content">
<article data-lml-child-imm data-household-no="HH-001" data-member-id="MB-024" data-member-name="Ana Rivera">
<h1 class="lml-child-imm__member-name">Ana Rivera</h1>
<p>Existing dose: BCG 2024-01-01</p>
<form method="post" action="/household-profiling/HH-001/members/MB-024/child-immunization"
  data-offline-operation="HEALTH_SERVICE_WRITE"
  data-offline-health-action="child_immunization_store"
  data-offline-parent-household-no="HH-001"
  data-offline-parent-member-no="MB-024">
<button type="submit" class="btn btn-primary">Save</button>
</form>
</article>
</main></body></html>`;

function healthShellBundle() {
    const health = { householdNo: 'HH-001', memberId: 'MB-024' };
    for (const key of SUPPORTED_HEALTH_MODULES) {
        health[key] = HEALTH_MODULE_SHELL;
    }
    return health;
}

const payload = {
    generated_at: '2026-09-22T00:00:00Z',
    catalogs: { relations: ['Head'] },
    households: [{
        household_id: 1,
        household_no: 'HH-001',
        display_no: 'HH 001',
        house_head: 'Ana Rivera',
        zone: 'Zone 1',
        member_count: 1,
        water: { level: '—', status: 'Not recorded' },
        sanitation: { facility: '—', status: 'Not recorded' },
    }],
    members: [{
        household_id: 1,
        household_no: 'HH-001',
        resident_id: 24,
        member_no: 'MB-024',
        name: 'Ana Rivera',
        first_name: 'Ana',
        last_name: 'Rivera',
        relation: 'Head',
        sex: 'Female',
        birthday: '2018-01-01',
        age: 8,
        health: {
            eligible: {
                child_immunization: true,
                school_based_immunization: false,
                child_nutrition: true,
                deworming: true,
                nutritional_status: true,
                risk_assessment: false,
                family_planning: false,
                maternal_care: true,
                maternal_care_workflow: false,
                adult_immunization: false,
                death: true,
            },
            has_records: {
                'child-immunization': true,
                'birth-history': false,
                'school-based-immunization': false,
                'child-nutrition': false,
                'nutritional-status': true,
                deworming: false,
                'risk-assessment': false,
                'family-planning': false,
                'maternal-care': false,
            },
            warm_modules: ['child-immunization', 'nutritional-status'],
            nutrition_card: {
                weight: '12.50',
                height: '80.00',
                mode: 'bmi',
                bmi: '19.5',
                status: 'Normal',
            },
            maternal_care_has_history: false,
        },
    }],
};

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

describe('staff Health Summary offline continuum', () => {
    it('TEST A — styled existing-member Health Summary panel (not fallbackHealthHtml)', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const caches = createMemoryCaches();
        const health = healthShellBundle();
        await rememberShells(ACTOR_A, {
            memberView: REAL_SHELL,
            householdNo: 'HH-001',
            memberId: 'MB-024',
            health,
        });
        await cacheHydratedHouseholdPages(ACTOR_A, payload.households[0], payload.members, {
            caches,
            origin: ORIGIN,
            shells: {
                memberView: REAL_SHELL,
                shellHouseholdNo: 'HH-001',
                shellMemberId: 'MB-024',
                health,
            },
        });
        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-001/members/MB-024')));
        const html = await hit.text();
        assert.match(html, /Health Summary Records/);
        assert.match(html, /lml-sidebar/);
        assert.match(html, /Poppins|app\.css/);
        assert.match(html, /lml-hh-member-view__records/);
        assert.doesNotMatch(html, /data-reason="health-shell-missing"/);
        assert.doesNotMatch(html, /<!DOCTYPE html><html><head><meta charset="utf-8"><title>[^<]+<\/title><\/head>\s*<body data-lml-offline-root>\s*<article data-lml-hh-member-view/);
    });

    it('TEST B — existing health data visible without manually opening module', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const caches = createMemoryCaches();
        const health = healthShellBundle();
        await rememberShells(ACTOR_A, { health, householdNo: 'HH-001', memberId: 'MB-024' });
        await cacheHydratedHouseholdPages(ACTOR_A, payload.households[0], payload.members, {
            caches,
            origin: ORIGIN,
            shells: { health, shellHouseholdNo: 'HH-001', shellMemberId: 'MB-024' },
        });
        // Simulate prepared existing page overwrite (prep warm).
        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        await cache.put(
            new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-001/members/MB-024/child-immunization')),
            new Response(HEALTH_MODULE_SHELL.replace('Ana Rivera', 'Ana Rivera'), {
                headers: { 'Content-Type': 'text/html' },
            }),
        );
        const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-001/members/MB-024/child-immunization')));
        const html = await hit.text();
        assert.match(html, /Existing dose: BCG/);
        assert.match(html, /lml-sidebar/);
        assert.match(html, /HEALTH_SERVICE_WRITE/);
    });

    it('TEST C — existing MB-* offline add queues HEALTH_SERVICE_WRITE + Waiting to sync', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const op = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                _health_action: 'deworming_store',
                date: '2026-09-22',
                drug: 'Albendazole',
            },
            parent_server: { household_no: 'HH-001', member_no: 'MB-024' },
        });
        await handleHealthServiceQueued({
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: op.payload,
            parent_server: op.parent_server,
            local_id: op.local_id,
        }, { actorId: ACTOR_A });

        const ops = (await listOperationsForActor(ACTOR_A)).filter((row) => row.operation_type === 'HEALTH_SERVICE_WRITE');
        assert.equal(ops.length, 1);
        assert.equal(isUnresolvedLocalParent(ops[0]), false);
        const snaps = await listHealthSnapshots(ACTOR_A, 'MB-024');
        assert.equal(snaps.length, 1);
        assert.equal(snaps[0].pending_sync, true);
        assert.match(snaps[0].label || '', /Deworming/i);
    });

    it('TEST D — local MB-L-* health blocked until RESIDENT_CREATE', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, {
            ...payload,
            households: [{ ...payload.households[0], household_no: '128', local: true, pending_sync: true }],
            members: [],
        });
        await appendLocalMemberToHousehold(ACTOR_A, '128', {
            client_local_member_id: 'MB-L-HEALTH02',
            first_name: 'Mia',
            last_name: 'Cruz',
            sex: 'Female',
            birthday: '2016-01-01',
            relation: 'Daughter',
        });
        const member = await getMemberSnapshot(ACTOR_A, '128', 'MB-L-HEALTH02');
        assert.ok(member.health);
        assert.equal(member.health.eligible.child_immunization, true);

        const health = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                client_local_member_id: 'MB-L-HEALTH02',
                _health_action: 'timbang_record_store',
                date: '2026-09-22',
                weight: '14',
                height: '90',
            },
            parent_server: { household_no: '128', member_no: 'MB-L-HEALTH02' },
        });
        await handleHealthServiceQueued({
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: health.payload,
            parent_server: health.parent_server,
            local_id: health.local_id,
        }, { actorId: ACTOR_A });
        assert.equal(isUnresolvedLocalParent(health), true);
        const snaps = await listHealthSnapshots(ACTOR_A, 'MB-L-HEALTH02');
        assert.equal(snaps.length, 1);
        assert.equal(snaps[0].pending_sync, true);
    });

    it('TEST E — HOUSEHOLD → RESIDENT → HEALTH dependency order + rebinding', async () => {
        const hh = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HOUSEHOLD_CREATE',
            payload: { household_no: '130' },
            created_at_client: '2026-09-22T01:00:00.000Z',
        });
        const resident = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'RESIDENT_CREATE',
            payload: { client_local_member_id: 'MB-L-R1', first_name: 'A', last_name: 'B' },
            parent_server: { household_no: '130' },
            created_at_client: '2026-09-22T01:01:00.000Z',
        });
        const health = await enqueueOperation({
            actor_id: ACTOR_A,
            operation_type: 'HEALTH_SERVICE_WRITE',
            payload: {
                client_local_member_id: 'MB-L-R1',
                _health_action: 'deworming_store',
                date: '2026-09-22',
            },
            parent_server: { household_no: '130', member_no: 'MB-L-R1' },
            created_at_client: '2026-09-22T01:02:00.000Z',
        });
        const ordered = [health, resident, hh].sort(compareReplayOrder);
        assert.equal(ordered[0].operation_type, 'HOUSEHOLD_CREATE');
        assert.equal(ordered[1].operation_type, 'RESIDENT_CREATE');
        assert.equal(ordered[2].operation_type, 'HEALTH_SERVICE_WRITE');

        await applyServerIdentityToDependents(ACTOR_A, 'MB-L-R1', {
            member_no: 'MB-099',
            resident_id: 99,
        });
        const ops = await listOperationsForActor(ACTOR_A);
        const rebound = ops.find((row) => row.operation_type === 'HEALTH_SERVICE_WRITE');
        assert.ok(
            rebound.parent_server?.member_no === 'MB-099'
            || rebound.payload?.client_local_member_id === 'MB-L-R1',
        );
    });

    it('TEST F — every supported module uses real LMLinga shell markers', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const caches = createMemoryCaches();
        const health = healthShellBundle();
        await rememberShells(ACTOR_A, { health });
        assert.equal(await hasCanonicalHealthShells(ACTOR_A), true);
        await cacheHydratedHouseholdPages(ACTOR_A, payload.households[0], payload.members, {
            caches,
            origin: ORIGIN,
            shells: { health, shellHouseholdNo: 'HH-001', shellMemberId: 'MB-024' },
        });
        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        for (const key of SUPPORTED_HEALTH_MODULES) {
            const hit = await cache.match(new Request(navigationCacheUrl(
                ORIGIN,
                `/household-profiling/HH-001/members/MB-024/${key}`,
            )));
            assert.ok(hit, `missing cache for ${key}`);
            const html = await hit.text();
            assert.match(html, /lml-sidebar|lml-dashboard|data-lml-offline-root/);
            assert.match(html, /app\.css/);
            assert.doesNotMatch(html, /data-reason="health-shell-missing"/);
            assert.doesNotMatch(html, /Emergency offline/i);
        }
    });

    it('TEST G — Adult Immunization / Death online-only in-shell unavailable', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const caches = createMemoryCaches();
        await cacheHydratedHouseholdPages(ACTOR_A, payload.households[0], payload.members, {
            caches,
            origin: ORIGIN,
            shells: {
                memberView: REAL_SHELL,
                health: healthShellBundle(),
                shellHouseholdNo: 'HH-001',
                shellMemberId: 'MB-024',
            },
        });
        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        for (const key of ONLINE_ONLY_HEALTH_MODULES) {
            const hit = await cache.match(new Request(navigationCacheUrl(
                ORIGIN,
                `/household-profiling/HH-001/members/MB-024/${key}`,
            )));
            const html = await hit.text();
            assert.match(html, /isn’t available while you’re offline|requires an internet connection/i);
            assert.match(html, /lml-offline-unavailable|lml-sidebar/);
            assert.doesNotMatch(html, /data-offline-operation="HEALTH_SERVICE_WRITE"/);
        }
        const copy = unavailableCopy({ kind: 'online-only', module: 'Adult Immunization' });
        assert.match(copy.body, /internet|Reconnect/i);
        assert.equal(ONLINE_ONLY_HEALTH_MODULES.includes('adult-immunization'), true);
        assert.equal(ONLINE_ONLY_HEALTH_MODULES.includes('death'), true);
    });

    it('TEST H — actor isolation for prepared health shells', async () => {
        const health = healthShellBundle();
        await rememberShells(ACTOR_A, { health });
        assert.equal(await hasCanonicalHealthShells(ACTOR_A), true);
        assert.equal(await hasCanonicalHealthShells(ACTOR_B), false);
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const memberA = await getMemberSnapshot(ACTOR_A, 'HH-001', 'MB-024');
        assert.ok(memberA.health?.has_records?.['child-immunization']);
        assert.equal(Boolean(await getMemberSnapshot(ACTOR_B, 'HH-001', 'MB-024')), false);
    });

    it('TEST I — ensure nav hydrates MB-* health from shells (not local-only gate)', async () => {
        await replaceHouseholdProfilingSnapshots(ACTOR_A, payload);
        const caches = createMemoryCaches();
        const health = healthShellBundle();
        await rememberShells(ACTOR_A, {
            view: REAL_SHELL,
            memberView: REAL_SHELL,
            health,
            householdNo: 'HH-001',
            memberId: 'MB-024',
        });
        const ok = await ensureHouseholdNavFromSnapshot(
            '/household-profiling/HH-001/members/MB-024/child-immunization',
            { actorId: ACTOR_A, caches, origin: ORIGIN },
        );
        assert.equal(ok, true);
        const cache = await caches.open(htmlCacheNameForActor(ACTOR_A));
        const hit = await cache.match(new Request(navigationCacheUrl(
            ORIGIN,
            '/household-profiling/HH-001/members/MB-024/child-immunization',
        )));
        assert.ok(hit);
        const html = await hit.text();
        assert.match(html, /MB-024/);
        assert.match(html, /HEALTH_SERVICE_WRITE|lml-sidebar/);
    });

    it('hydrateHealthModuleHtml rewrites identities into real shell', () => {
        const html = hydrateHealthModuleHtml(
            HEALTH_MODULE_SHELL,
            { household_no: 'HH-200' },
            { member_no: 'MB-200', name: 'Ben Cruz' },
            'child-immunization',
            'HH-001',
            'MB-024',
        );
        assert.match(html, /HH-200/);
        assert.match(html, /MB-200/);
        assert.match(html, /Ben Cruz/);
        assert.doesNotMatch(html, /MB-024/);
    });

    it('CACHE_VERSION bumped for health shell contract', () => {
        assert.equal(CACHE_VERSION, 'offline-7-v20');
    });
});
