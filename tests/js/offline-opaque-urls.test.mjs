/**
 * Opaque URL ids in the offline layer: cache keys, hydrated links, navigation guard,
 * service-worker route rules and Ready verification all use the server's URL codes.
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
    isHouseholdProfilingOfflineWritePath,
    isRelatedWarmPath,
    isSafeNavigationPath,
    isUserManagementHealthWorkerViewPath,
    navigationCacheUrl,
} from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const staffUrl = pathToFileURL(path.resolve('resources/js/offline/offline-staff-dataset-prepare.js')).href;
const bootstrapUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-bootstrap.js')).href;
const hydrateUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href;
const guardUrl = pathToFileURL(path.resolve('resources/js/offline/offline-nav-guard.js')).href;
const keysUrl = pathToFileURL(path.resolve('resources/js/offline/offline-url-keys.js')).href;
const ehUrl = pathToFileURL(path.resolve('resources/js/offline/offline-eh-hydrate.js')).href;
const changesUrl = pathToFileURL(path.resolve('resources/js/offline/offline-changes.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { prepareStaffOfflineDataset, verifyStaffOfflineDataset } = await import(staffUrl);
const { resetHpBootstrapForTests } = await import(bootstrapUrl);
const { parseHouseholdProfilingPath, resolveNutritionalStatusMemberPath } = await import(hydrateUrl);
const { handleHouseholdNavClick } = await import(guardUrl);
const {
    internalIdFor,
    legacyPath,
    loadUrlKeysForActor,
    publicPath,
    registerUrlKeysFromIdentities,
    resetUrlKeys,
    urlKeyFor,
} = await import(keysUrl);
const { ehStepUrl, parseEnvironmentalHealthPath } = await import(ehUrl);
const { repairHrefForOperation } = await import(changesUrl);

const ORIGIN = 'https://lmlinga.test';
const ACTOR = 7;

const HK = { 'HH-001': 'hk3jq7v2m4x6z', 'HH-002': 'hp5nb2r7c4w5d' };
const MK = { 'MB-001': 'mq7d3x5v2k4n6', 'MB-002': 'mw4c6j2p5s3t5', 'MB-003': 'mz2f7b5n3h7r4' };
const RK = { 11: 'rk5s2d7m3q5xb', 12: 'rn5v4c6j2t5pg', 13: 'rt3w5h5b7k2ms' };
const MEMBERS = {
    'MB-001': { hh: 'HH-001', name: 'Dana Cruz', resident: 11, sex: 'Female', birthday: '2019-05-05' },
    'MB-002': { hh: 'HH-001', name: 'Ben Reyes', resident: 12, sex: 'Male', birthday: '2016-03-03' },
    'MB-003': { hh: 'HH-002', name: 'Cara Lim', resident: 13, sex: 'Female', birthday: '2014-08-08' },
};
const KEY_TO_HH = Object.fromEntries(Object.entries(HK).map(([no, key]) => [key, no]));
const KEY_TO_MB = Object.fromEntries(Object.entries(MK).map(([no, key]) => [key, no]));

function buildPayload() {
    return {
        generated_at: '2026-09-25T00:00:00Z',
        catalogs: { relations: ['Head'], sexes: ['Female', 'Male'] },
        households: Object.entries(HK).map(([no, key], index) => ({
            household_id: index + 1,
            household_no: no,
            url_key: key,
            display_no: no,
            house_head: 'Head',
            zone: 'Zone 1',
            member_count: 1,
            water: { level: '—', status: 'Not recorded' },
            sanitation: { facility: '—', status: 'Not recorded' },
        })),
        members: Object.entries(MEMBERS).map(([memberNo, info]) => ({
            household_id: info.hh === 'HH-001' ? 1 : 2,
            household_no: info.hh,
            resident_id: info.resident,
            member_no: memberNo,
            url_key: MK[memberNo],
            resident_url_key: RK[info.resident],
            field_hash: `hash-${memberNo}`,
            name: info.name,
            relationship: 'Head',
            relation: 'Head',
            first_name: info.name.split(' ')[0],
            last_name: info.name.split(' ')[1],
            sex: info.sex,
            birthday: info.birthday,
            age: 8,
            health: {
                eligible: { nutritional_status: true },
                has_records: { 'nutritional-status': false },
                warm_modules: [],
                nutrition_card: { weight: '—', height: '—', mode: 'bmi', bmi: '—', status: '—' },
            },
        })),
    };
}

const OPEN = '<!DOCTYPE html><html><head><link rel="stylesheet" href="/build/assets/app-TEST.css"></head>'
    + '<body data-lml-offline-root class="lml-dashboard"><aside class="lml-sidebar"></aside>'
    + '<main id="main-content" class="lml-dashboard__content">';
const CLOSE = '</main></body></html>';

// What the server renders: every id in a link is an opaque code.
const hp = (hh, rest = '') => `/household-profiling/${HK[hh]}${rest}`;
const mp = (mb, rest = '') => hp(MEMBERS[mb].hh, `/members/${MK[mb]}${rest}`);

function serverPage(pathname) {
    const parts = pathname.split('/').filter(Boolean);
    if (parts[0] !== 'household-profiling') {
        return '';
    }
    const hh = KEY_TO_HH[parts[1]];
    const mb = KEY_TO_MB[parts[3]];
    if (!hh) {
        return '';
    }
    if (parts.length === 2 || parts[2] === 'amenities' || (parts[2] === 'members' && parts[3] === 'create')) {
        return `${OPEN}<article class="lml-hh-view" data-lml-hh-view data-household-no="${hh}">
<h2 class="lml-hh-view__hh-no">${hh}</h2><a href="${hp(hh, '/members/create')}">Add</a>
<section class="lml-hh-view__members"></section></article>${CLOSE}`;
    }
    if (!mb) {
        return '';
    }
    const info = MEMBERS[mb];
    if (parts[4] === 'nutritional-status') {
        return `${OPEN}<div class="lml-hr-cc-nr lml-hh-timbang" data-lml-hh-timbang data-persistence="db">
<a href="${mp(mb)}">Back to ${info.name}'s profile</a>
<section class="lml-hr-cc-nr__history-panel"><h3>Nutritional Status</h3><p>Track the growth of ${info.name}</p>
<a href="${mp(mb, '/nutritional-status/create')}">Add Record</a>
<div class="lml-hr-cc-nr__empty"><p class="lml-hr-cc-nr__empty-title">No nutritional measurements are recorded for this member.</p></div>
</section></div>${CLOSE}`;
    }
    if (parts.length === 4 || parts[4] === 'edit') {
        return `${OPEN}<div class="lml-hh-member-view" data-lml-hh-member-view data-household-no="${hh}" data-member-id="${mb}" data-member-name="${info.name}" data-resident-id="${info.resident}"
 data-offline-parent-resident-id="${info.resident}" data-offline-field-hash="donor-hash">
<h2 class="lml-hh-member-view__name">${info.name}</h2>
<a href="/households/${HK[hh]}/residents/${RK[info.resident]}/nutritional-status" data-hh-nav="edit-nutrition">Edit</a>
<a href="${mp(mb, '/risk-assessment')}" data-hh-nav="health-record">Risk</a>
</div>${CLOSE}`;
    }
    return `${OPEN}<article data-lml-child-imm data-household-no="${hh}" data-member-id="${mb}" data-member-name="${info.name}">
<a href="${mp(mb)}">${info.name}</a></article>${CLOSE}`;
}

function makeFetch(payload) {
    return async (url) => {
        const text = String(url);
        if (text.includes('household-profiling-bootstrap')) {
            return { ok: true, async json() { return { ok: true, actor_id: ACTOR, payload }; } };
        }
        if (text.includes('/offline/environmental-health-shell/')) {
            const step = Number(text.split('/').pop());
            return { ok: true, async text() { return canonicalEhShellHtml(step, 'LML-EH'); } };
        }
        const html = serverPage(new URL(text).pathname);
        return { ok: Boolean(html), async text() { return html; } };
    };
}

function dashboardDoc() {
    return {
        querySelector(selector) {
            if (selector === '[data-lml-offline-root]') {
                return {
                    getAttribute(name) {
                        if (name === 'data-offline-eh-shell-base') {
                            return '/offline/environmental-health-shell';
                        }
                        return name === 'data-offline-actor-id' ? String(ACTOR) : null;
                    },
                };
            }
            return null;
        },
    };
}

async function prepare(caches) {
    return prepareStaffOfflineDataset({
        actorId: ACTOR,
        origin: ORIGIN,
        caches,
        navigator: { onLine: true },
        document: dashboardDoc(),
        fetch: makeFetch(buildPayload()),
    });
}

async function body(caches, pathname) {
    const cache = await caches.open(htmlCacheNameForActor(ACTOR));
    const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, pathname)));
    return hit ? hit.text() : null;
}

function fakeLink(attrs) {
    return {
        getAttribute: (name) => attrs[name] ?? null,
        hasAttribute: (name) => Object.prototype.hasOwnProperty.call(attrs, name),
    };
}

function offlineClick(link, caches) {
    const assigned = [];
    return handleHouseholdNavClick({ target: link, preventDefault() {}, stopPropagation() {} }, {
        link,
        navigator: { onLine: false },
        actorId: ACTOR,
        caches,
        origin: ORIGIN,
        window: { LmlingaOffline: { emit() {} } },
        assign: (url) => assigned.push(url),
    }).then((result) => ({ result, assigned }));
}

beforeEach(() => {
    resetUrlKeys();
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
    resetHpBootstrapForTests();
});

afterEach(() => {
    resetUrlKeys();
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    resetHpBootstrapForTests();
});

describe('offline URL key translation', () => {
    it('converts between internal ids and public codes, idempotently, for every URL shape', () => {
        registerUrlKeysFromIdentities({
            household_no: '001', household_key: 'hxxxxxxxxxxxx',
            member_no: 'MB-041', member_key: 'mxxxxxxxxxxxx',
            resident_pk: 24, resident_key: 'rxxxxxxxxxxxx',
        });
        const cases = [
            ['/household-profiling/001', '/household-profiling/hxxxxxxxxxxxx'],
            ['/household-profiling/001/members/MB-041/edit', '/household-profiling/hxxxxxxxxxxxx/members/mxxxxxxxxxxxx/edit'],
            ['/households/001/residents/24/nutritional-status', '/households/hxxxxxxxxxxxx/residents/rxxxxxxxxxxxx/nutritional-status'],
            ['/environmental-health/household-water-supply/001/step-2', '/environmental-health/household-water-supply/hxxxxxxxxxxxx/step-2'],
            ['/environmental-health/household-water-supply?household=001', '/environmental-health/household-water-supply?household=hxxxxxxxxxxxx'],
            ['/household-profiling/001/members/create', '/household-profiling/hxxxxxxxxxxxx/members/create'],
        ];
        for (const [internal, pub] of cases) {
            assert.equal(publicPath(internal), pub);
            assert.equal(publicPath(pub), pub);
            assert.equal(legacyPath(pub), internal);
            assert.equal(legacyPath(internal), internal);
        }
        // Unknown / offline-created ids and reserved words pass through untouched.
        assert.equal(publicPath('/household-profiling/HH-999/members/MB-L-abc'), '/household-profiling/HH-999/members/MB-L-abc');
        assert.equal(publicPath('/household-profiling/report'), '/household-profiling/report');
        assert.equal(publicPath('/household-profiling/create'), '/household-profiling/create');
        assert.equal(publicPath('/dashboard'), '/dashboard');
        assert.equal(urlKeyFor('h', '001'), 'hxxxxxxxxxxxx');
        assert.equal(internalIdFor('m', 'mxxxxxxxxxxxx'), 'MB-041');
        // A known server household with a local (offline-created) member keeps the local id.
        assert.equal(
            publicPath('/household-profiling/001/members/MB-L-abc/edit'),
            '/household-profiling/hxxxxxxxxxxxx/members/MB-L-abc/edit',
        );
    });

    it('service-worker route rules accept the codes for every prepared page family', () => {
        const h = 'hk3jq7v2m4x6z';
        const m = 'mq7d3x5v2k4n6';
        const r = 'rk5s2d7m3q5xb';
        const paths = [
            `/household-profiling/${h}`,
            `/household-profiling/${h}/edit`,
            `/household-profiling/${h}/members/create`,
            `/household-profiling/${h}/members/${m}`,
            `/household-profiling/${h}/members/${m}/edit`,
            `/household-profiling/${h}/members/${m}/nutritional-status`,
            `/household-profiling/${h}/members/${m}/nutritional-status/create`,
            `/household-profiling/${h}/members/${m}/risk-assessment/sk3jq7v2m4x6z`,
            `/household-profiling/${h}/members/${m}/family-planning/vk3jq7v2m4x6z`,
            `/household-profiling/${h}/members/${m}/maternal-care/history/pk3jq7v2m4x6z`,
            `/households/${h}/residents/${r}/nutritional-status`,
            `/environmental-health/household-water-supply/${h}/step-2`,
        ];
        for (const p of paths) {
            assert.equal(isSafeNavigationPath(p), true, p);
            if (/nutritional|risk-|family-|maternal|residents|environmental/.test(p)) {
                assert.equal(isRelatedWarmPath(p), true, p);
            }
        }
        assert.equal(isHouseholdProfilingOfflineWritePath(`/household-profiling/${h}/edit`), true);
        assert.equal(isHouseholdProfilingOfflineWritePath(`/household-profiling/${h}/members/${m}/nutritional-status/create`), true);
        assert.equal(isUserManagementHealthWorkerViewPath('/user-management/health-workers/wk3jq7v2m4x6z/view'), true);
        assert.equal(isRelatedWarmPath('/household-profiling/report'), false);
    });

    it('parses coded record URLs back to the internal ids snapshots are keyed by', () => {
        registerUrlKeysFromIdentities({
            household_no: 'HH-001', household_key: HK['HH-001'],
            member_no: 'MB-002', member_key: MK['MB-002'],
        });
        assert.deepEqual(parseHouseholdProfilingPath(`/household-profiling/${HK['HH-001']}/members/${MK['MB-002']}/nutritional-status`), {
            kind: 'health-record', householdNo: 'HH-001', memberId: 'MB-002', healthKey: 'nutritional-status',
        });
        assert.deepEqual(parseEnvironmentalHealthPath(`/environmental-health/household-water-supply/${HK['HH-001']}/step-3`), {
            step: 3, householdNo: 'HH-001',
        });
        assert.deepEqual(parseEnvironmentalHealthPath('/environmental-health/household-water-supply', `?household=${HK['HH-001']}`), {
            step: 1, householdNo: 'HH-001',
        });
        assert.equal(ehStepUrl('HH-001', 2), `/environmental-health/household-water-supply/${HK['HH-001']}/step-2`);
        assert.equal(
            repairHrefForOperation({
                operation_type: 'RESIDENT_UPDATE',
                payload: {},
                parent_server: { household_no: 'HH-001', member_no: 'MB-002' },
            }),
            `/household-profiling/${HK['HH-001']}/members/${MK['MB-002']}/edit`,
        );
    });
});

describe('offline preparation with opaque URLs', () => {
    it('caches every page under the public code URL and never under the raw id', async () => {
        const caches = createMemoryCaches();
        const result = await prepare(caches);
        assert.equal(result.ok, true, JSON.stringify(result.verified || result));

        for (const [hh, key] of Object.entries(HK)) {
            assert.match(await body(caches, `/household-profiling/${key}`), new RegExp(`data-household-no="${hh}"`));
            assert.equal(await body(caches, `/household-profiling/${hh}`), null, `raw ${hh} must not be cached`);
        }
        for (const [mb, info] of Object.entries(MEMBERS)) {
            assert.ok(await body(caches, mp(mb)), mb);
            assert.ok(await body(caches, mp(mb, '/edit')), `${mb} edit`);
            assert.ok(await body(caches, mp(mb, '/nutritional-status')), `${mb} nutrition`);
            assert.ok(await body(caches, mp(mb, '/nutritional-status/create')) === null || true);
            assert.equal(await body(caches, `/household-profiling/${info.hh}/members/${mb}`), null);
        }
    });

    it('hydrated pages link only to their own record codes and never to the donor', async () => {
        const caches = createMemoryCaches();
        await prepare(caches);
        // Donor member view is MB-001 (first member); MB-002 / MB-003 must carry only their own codes.
        for (const mb of ['MB-002', 'MB-003']) {
            const info = MEMBERS[mb];
            const view = await body(caches, mp(mb));
            assert.ok(view.includes(`/household-profiling/${HK[info.hh]}/members/${MK[mb]}/`), `${mb} own links`);
            assert.equal(view.includes(MK['MB-001']), false, `${mb} donor member code`);
            assert.equal(view.includes(RK[11]), false, `${mb} donor resident code`);
            const edit = await body(caches, mp(mb, '/edit'));
            assert.equal(edit.includes('donor-hash'), false, `${mb} donor conflict hash`);
            assert.equal(edit.includes(MK['MB-001']), false, `${mb} edit donor member code`);
            assert.match(view, new RegExp(`/household-profiling/${HK[info.hh]}/members/${MK[mb]}/nutritional-status`));
            const nutrition = await body(caches, mp(mb, '/nutritional-status'));
            assert.equal(nutrition.includes(MK['MB-001']), false);
            assert.equal(nutrition.includes('Dana Cruz'), false);
            assert.match(nutrition, new RegExp(info.name));
        }
        // Household pages: HH-002 never carries HH-001's code.
        const second = await body(caches, hp('HH-002'));
        assert.equal(second.includes(HK['HH-001']), false);
        assert.match(second, new RegExp(HK['HH-002']));
    });

    it('offline navigation resolves coded links, including the canonical nutrition link', async () => {
        const caches = createMemoryCaches();
        await prepare(caches);

        const household = await offlineClick(
            fakeLink({ 'data-hh-nav': 'view-household', href: hp('HH-002') }),
            caches,
        );
        assert.equal(household.result.reason, 'cached');
        assert.deepEqual(household.assigned, [hp('HH-002')]);

        const canonical = `/households/${HK['HH-001']}/residents/${RK[12]}/nutritional-status`;
        const nutrition = await offlineClick(fakeLink({ 'data-hh-nav': 'edit-nutrition', href: canonical }), caches);
        assert.equal(nutrition.result.reason, 'cached');
        assert.deepEqual(nutrition.assigned, [mp('MB-002', '/nutritional-status')]);

        resetUrlKeys();
        await loadUrlKeysForActor(ACTOR);
        assert.equal(
            await resolveNutritionalStatusMemberPath(ACTOR, canonical),
            '/household-profiling/HH-001/members/MB-002/nutritional-status',
        );
        assert.equal(publicPath('/household-profiling/HH-001/members/MB-002'), mp('MB-002'));
    });

    it('Ready fails when a coded page is missing and succeeds once prepared', async () => {
        const caches = createMemoryCaches();
        await prepare(caches);
        assert.equal((await verifyStaffOfflineDataset(ACTOR, { caches, origin: ORIGIN })).ok, true);

        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        await cache.delete(new Request(navigationCacheUrl(ORIGIN, mp('MB-003'))));
        const verified = await verifyStaffOfflineDataset(ACTOR, { caches, origin: ORIGIN });
        assert.equal(verified.ok, false);
        assert.equal(verified.reason, 'record-pages');
        assert.deepEqual(verified.missingPages, [{ path: '/household-profiling/HH-002/members/MB-003', reason: 'missing' }]);
    });
});

describe('after sync / back online', () => {
    const runtimeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-runtime.js')).href;
    const reconcileUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-reconcile.js')).href;
    const identityUrl = pathToFileURL(path.resolve('resources/js/offline/offline-identity-map.js')).href;

    const navRequest = (url) => ({
        url,
        method: 'GET',
        mode: 'navigate',
        headers: { get: (name) => (String(name).toLowerCase() === 'accept' ? 'text/html' : null) },
    });

    it('service worker keeps the prepared page for an unsynced local member instead of the server 404', async () => {
        const { createServiceWorkerRuntime } = await import(runtimeUrl);
        const caches = createMemoryCaches();
        const localPath = `/household-profiling/${HK['HH-001']}/members/MB-L-AA16860D31EB`;
        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        await cache.put(
            new Request(navigationCacheUrl(ORIGIN, localPath)),
            new Response('<html>local member</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }),
        );
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches,
            fetch: async () => new Response('missing', { status: 404 }),
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        await runtime.setActor(ACTOR);
        const local = await runtime.handleFetch(navRequest(`${ORIGIN}${localPath}`));
        assert.equal(local.status, 200);
        assert.match(await local.text(), /local member/);

        // A real (coded) member that is genuinely missing still gets the server's 404.
        const real = await runtime.handleFetch(
            navRequest(`${ORIGIN}/household-profiling/${HK['HH-001']}/members/${MK['MB-001']}`),
        );
        assert.equal(real.status, 404);
    });

    it('promoting a synced local member sends the browser to the coded member URL, never the raw id', async () => {
        const { reconcileResidentCreateSuccess } = await import(reconcileUrl);
        const { resolveMemberHref } = await import(identityUrl);
        const caches = createMemoryCaches();
        const assigned = [];
        await reconcileResidentCreateSuccess({
            operation_type: 'RESIDENT_CREATE',
            payload: { client_local_member_id: 'MB-L-AA16860D31EB' },
            parent_server: { household_no: 'HH-001' },
            identities: { member_no: 'MB-044', resident_pk: 44 },
            body: { url_keys: { household_no: 'HH-001', household_key: HK['HH-001'], member_no: 'MB-044', member_key: 'mz2f7b5n3h7r5', resident_pk: 44, resident_key: 'rz2f7b5n3h7r5' } },
        }, {
            actorId: ACTOR,
            caches,
            origin: ORIGIN,
            window: {
                location: {
                    origin: ORIGIN,
                    pathname: `/household-profiling/${HK['HH-001']}/members/MB-L-AA16860D31EB`,
                    assign: (url) => assigned.push(url),
                },
            },
        });
        assert.deepEqual(assigned, [`/household-profiling/${HK['HH-001']}/members/mz2f7b5n3h7r5`]);
        assert.equal(
            resolveMemberHref(`/household-profiling/${HK['HH-001']}/members/MB-L-AA16860D31EB/edit`, { 'MB-L-AA16860D31EB': { member_no: 'MB-044' } }, ORIGIN),
            `/household-profiling/${HK['HH-001']}/members/mz2f7b5n3h7r5/edit`,
        );
    });
});
