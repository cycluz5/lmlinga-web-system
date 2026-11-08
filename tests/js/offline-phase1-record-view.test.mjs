/**
 * Phase 1 offline record views — Household Record + Nutritional Status.
 *
 * Covers per-household/member hydration, donor data safety, canonical
 * Nutritional Status navigation, actor isolation and Ready verification.
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
    navigationCacheUrl,
} from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const staffUrl = pathToFileURL(path.resolve('resources/js/offline/offline-staff-dataset-prepare.js')).href;
const bootstrapUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-bootstrap.js')).href;
const hydrateUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href;
const guardUrl = pathToFileURL(path.resolve('resources/js/offline/offline-nav-guard.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { prepareStaffOfflineDataset, verifyStaffOfflineDataset } = await import(staffUrl);
const { resetHpBootstrapForTests } = await import(bootstrapUrl);
const {
    hydrateHealthModuleHtml,
    hydrateMemberViewHtml,
    resetNutritionalStatusCreateShell,
    resetNutritionalStatusIndexShell,
    resolveNutritionalStatusMemberPath,
} = await import(hydrateUrl);
const {
    HH_NAV_KINDS,
    handleHouseholdNavClick,
    isHealthSummaryNavPath,
    kindFromHouseholdNavLink,
} = await import(guardUrl);

const ORIGIN = 'https://lmlinga.test';
const ACTOR = 7;
const OTHER_ACTOR = 9;

const MEMBERS = {
    'MB-001': { hh: 'HH-001', name: 'Dana Cruz', resident: 11, sex: 'Female', birthday: '2019-05-05' },
    'MB-002': { hh: 'HH-001', name: 'Ben Reyes', resident: 12, sex: 'Male', birthday: '2016-03-03' },
    'MB-003': { hh: 'HH-002', name: 'Cara Lim', resident: 13, sex: 'Female', birthday: '2014-08-08' },
};

function healthMeta(hasNutritionRecords) {
    return {
        eligible: {
            child_immunization: true,
            school_based_immunization: true,
            child_nutrition: true,
            deworming: true,
            nutritional_status: true,
            risk_assessment: true,
            family_planning: true,
            maternal_care: true,
            maternal_care_workflow: true,
            adult_immunization: true,
            death: true,
        },
        has_records: {
            'child-immunization': false,
            'birth-history': false,
            'school-based-immunization': false,
            'child-nutrition': false,
            'nutritional-status': hasNutritionRecords,
            deworming: false,
            'risk-assessment': false,
            'family-planning': false,
            'maternal-care': false,
        },
        warm_modules: [],
        nutrition_card: { weight: '—', height: '—', mode: 'bmi', bmi: '—', status: '—' },
        maternal_care_has_history: false,
    };
}

function buildPayload({ everyoneHasRecords = false } = {}) {
    return {
        generated_at: '2026-09-25T00:00:00Z',
        catalogs: { relations: ['Head'], sexes: ['Female', 'Male'] },
        households: [
            {
                household_id: 1,
                household_no: 'HH-001',
                display_no: 'HH 001',
                house_head: 'Dana Cruz',
                zone: 'Zone 1',
                member_count: 2,
                water: { title: 'Access to Safe Water', level: 'Level II', status: 'Basic' },
                sanitation: { title: 'Sanitation Services', facility: 'Pour flush', status: 'Improved' },
            },
            {
                household_id: 2,
                household_no: 'HH-002',
                display_no: 'HH 002',
                house_head: 'Cara Lim',
                zone: 'Zone 2',
                member_count: 1,
                water: { title: 'Access to Safe Water', level: 'Level III', status: 'Safely managed' },
                sanitation: { title: 'Sanitation Services', facility: 'Septic', status: 'Improved' },
            },
        ],
        members: Object.entries(MEMBERS).map(([memberNo, info]) => ({
            household_id: info.hh === 'HH-001' ? 1 : 2,
            household_no: info.hh,
            resident_id: info.resident,
            member_no: memberNo,
            field_hash: `hash-${memberNo}`,
            name: info.name,
            relationship: 'Head',
            relation: 'Head',
            first_name: info.name.split(' ')[0],
            last_name: info.name.split(' ')[1],
            sex: info.sex,
            birthday: info.birthday,
            age: 8,
            health: healthMeta(everyoneHasRecords || memberNo === 'MB-001'),
        })),
    };
}

const PAGE_OPEN = '<!DOCTYPE html><html><head><link rel="stylesheet" href="/build/assets/app-TEST.css"></head>'
    + '<body data-lml-offline-root class="lml-dashboard"><aside class="lml-sidebar"></aside>'
    + '<main id="main-content" class="lml-dashboard__content">';
const PAGE_CLOSE = '</main></body></html>';

function householdViewShell(hh) {
    return `${PAGE_OPEN}<article class="lml-hh-view" data-lml-hh-view data-household-no="${hh}">
<h2 class="lml-hh-view__hh-no">HH ${hh}</h2>
<section class="lml-hh-view__members"><h2>Household Members (1)</h2></section>
</article>${PAGE_CLOSE}`;
}

function memberViewShell(hh, mb) {
    const info = MEMBERS[mb];
    return `${PAGE_OPEN}<div class="lml-hh-member-view" data-lml-hh-member-view data-household-no="${hh}" data-member-id="${mb}" data-member-name="${info.name}" data-resident-id="${info.resident}">
<h2 class="lml-hh-member-view__name" data-offline-local-field="name">${info.name}</h2>
<p class="lml-hh-member-view__subtitle">Member Information</p>
<ul class="lml-hh-member-view__records">
<li class="lml-hh-member-view__record"><span>Risk Assessment</span><a href="/household-profiling/${hh}/members/${mb}/risk-assessment" data-hh-nav="health-record">View</a></li>
</ul>
<section class="lml-hh-member-view__side-card">
<a href="/households/${hh}/residents/${info.resident}/nutritional-status" class="lml-hh-member-view__btn" data-hh-nav="edit-nutrition"><span>Edit</span></a>
<dl class="lml-hh-member-view__nutrition">
<div class="lml-hh-member-view__nutrition-row"><dt>Weight</dt><dd>18.50 kg</dd></div>
</dl>
</section>
</div>${PAGE_CLOSE}`;
}

function nutritionIndexShell(hh, mb, { withRows = true } = {}) {
    const info = MEMBERS[mb];
    const rows = withRows
        ? `<article class="lml-hr-cc-nr__age-box" data-lml-hh-timbang-age-group="5-19y">
<ul class="lml-hr-cc-nr__measure-list"><li class="lml-hr-cc-nr__measure-row" data-timbang-id="TB-900">
<h5>Sep 01, 2026</h5><dl><div><dt>Weight</dt><dd>18.5 kg</dd></div><div><dt>Remarks</dt><dd>Donor remark ${info.name}</dd></div></dl></li></ul>
</article>`
        : `<div class="lml-hr-cc-nr__age-box" role="status"><div class="lml-hr-cc-nr__empty"><p class="lml-hr-cc-nr__empty-title">No nutritional measurements are recorded for this member.</p></div></div>`;
    return `${PAGE_OPEN}<div class="lml-hr-cc-nr lml-hh-timbang" data-lml-hh-timbang data-persistence="db">
<a href="/household-profiling/${hh}/members/${mb}" class="lml-hr-cc-nr__page-back">Back</a>
<nav class="lml-hh-timbang__breadcrumb"><a href="/household-profiling/${hh}/members/${mb}">Back to ${info.name}'s profile</a></nav>
<section class="lml-hr-cc-nr__history-panel" aria-labelledby="lml-hh-timbang-history-title">
<div class="lml-hr-cc-nr__history-head"><div><h3>Nutritional Status</h3><p class="lml-hr-cc-nr__dash-sub">Track the growth of ${info.name}</p></div>
<a href="/household-profiling/${hh}/members/${mb}/nutritional-status/create" class="lml-hr-cc-nr__save-btn" data-hr-cc-nr-add-record>Add Record</a></div>
<p class="lml-hh-timbang__status" role="status">Saved donor measurement.</p>
${rows}
</section></div>${PAGE_CLOSE}`;
}

function nutritionCreateShell(hh, mb) {
    const info = MEMBERS[mb];
    return `${PAGE_OPEN}<div class="lml-hr-cc-nr lml-hh-timbang" data-lml-hh-timbang data-persistence="db">
<nav><a href="/household-profiling/${hh}/members/${mb}">Back to ${info.name}'s profile</a><a href="/household-profiling/${hh}/members/${mb}/nutritional-status">Nutritional Status history</a></nav>
<h2>Add Measurement for ${info.name}</h2>
<dl class="lml-hh-timbang__resident-summary"><div><dt>Resident</dt><dd>${info.name}</dd></div><div><dt>Age at measurement</dt><dd data-timbang-age-label>7 years</dd></div><div><dt>Sex</dt><dd>${info.sex}</dd></div></dl>
<form method="post" action="/household-profiling/${hh}/members/${mb}/nutritional-status" data-lml-hh-timbang-form data-resident-birthday="${info.birthday}" data-preview-url="/household-profiling/${hh}/members/${mb}/nutritional-status/preview"
 data-offline-operation="HEALTH_SERVICE_WRITE" data-offline-health-action="timbang_record_store" data-offline-parent-household-no="${hh}" data-offline-parent-resident-id="${info.resident}" data-offline-parent-member-no="${mb}">
<input type="text" readonly value="Severely Underweight" data-timbang-wfa-value>
<input type="text" readonly value="Obese" data-timbang-bmi-status-value>
<button type="submit">Save</button></form></div>${PAGE_CLOSE}`;
}

function genericHealthShell(hh, mb, key) {
    return `${PAGE_OPEN}<article data-lml-child-imm data-household-no="${hh}" data-member-id="${mb}" data-member-name="${MEMBERS[mb].name}">
<h1>${key}</h1><form method="post" data-offline-operation="HEALTH_SERVICE_WRITE" data-offline-parent-household-no="${hh}" data-offline-parent-member-no="${mb}"><button>Save</button></form>
</article>${PAGE_CLOSE}`;
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
        const pathname = new URL(text).pathname;
        const html = (() => {
            let match = pathname.match(/^\/household-profiling\/(HH-\d+)\/members\/(MB-\d+)\/nutritional-status\/create$/);
            if (match) {
                return nutritionCreateShell(match[1], match[2]);
            }
            match = pathname.match(/^\/household-profiling\/(HH-\d+)\/members\/(MB-\d+)\/nutritional-status$/);
            if (match) {
                return nutritionIndexShell(match[1], match[2], { withRows: payload.members.find((row) => row.member_no === match[2])?.health?.has_records?.['nutritional-status'] });
            }
            match = pathname.match(/^\/household-profiling\/(HH-\d+)\/members\/(MB-\d+)\/([a-z-]+)$/);
            if (match && match[3] !== 'edit') {
                return genericHealthShell(match[1], match[2], match[3]);
            }
            match = pathname.match(/^\/household-profiling\/(HH-\d+)\/members\/(MB-\d+)(\/edit)?$/);
            if (match) {
                return memberViewShell(match[1], match[2]);
            }
            match = pathname.match(/^\/household-profiling\/(HH-\d+)(?:\/(?:members\/create|amenities))?$/);
            if (match) {
                return householdViewShell(match[1]);
            }
            return '';
        })();
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
                        if (name === 'data-offline-actor-id') {
                            return String(ACTOR);
                        }
                        return null;
                    },
                };
            }
            return null;
        },
    };
}

async function prepare(payload, caches) {
    return prepareStaffOfflineDataset({
        actorId: ACTOR,
        origin: ORIGIN,
        caches,
        navigator: { onLine: true },
        document: dashboardDoc(),
        fetch: makeFetch(payload),
    });
}

async function cachedBody(caches, actorId, pathname) {
    const cache = await caches.open(htmlCacheNameForActor(actorId));
    const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, pathname)));
    return hit ? hit.text() : null;
}

function fakeLink(attrs) {
    return {
        getAttribute: (name) => attrs[name] ?? null,
        hasAttribute: (name) => Object.prototype.hasOwnProperty.call(attrs, name),
    };
}

function offlineClick(link, caches, extra = {}) {
    const assigned = [];
    const event = { target: link, preventDefault() {}, stopPropagation() {} };
    return handleHouseholdNavClick(event, {
        link,
        navigator: { onLine: false },
        actorId: ACTOR,
        caches,
        origin: ORIGIN,
        window: { LmlingaOffline: { emit() {} } },
        assign: (url) => assigned.push(url),
        ...extra,
    }).then((result) => ({ result, assigned }));
}

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
    resetHpBootstrapForTests();
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    resetHpBootstrapForTests();
});

describe('Phase 1 — Household Record', () => {
    it('preparation caches a household-specific record page for every household', async () => {
        const caches = createMemoryCaches();
        const result = await prepare(buildPayload(), caches);
        assert.equal(result.ok, true, JSON.stringify(result.verified || result));

        const first = await cachedBody(caches, ACTOR, '/household-profiling/HH-001');
        const second = await cachedBody(caches, ACTOR, '/household-profiling/HH-002');
        assert.match(first, /data-household-no="HH-001"/);
        assert.match(second, /data-household-no="HH-002"/);
        assert.equal(second.includes('HH-001'), false);
        assert.match(second, /\/build\/assets\/app-TEST\.css/);
    });

    it('offline navigation to the Household Record uses the prepared page', async () => {
        const caches = createMemoryCaches();
        await prepare(buildPayload(), caches);
        const link = fakeLink({ 'data-hh-nav': 'view-household', href: '/household-profiling/HH-002' });
        const { result, assigned } = await offlineClick(link, caches);
        assert.equal(result.navigated, true);
        assert.equal(result.reason, 'cached');
        assert.deepEqual(assigned, ['/household-profiling/HH-002']);
    });
});

describe('Phase 1 — Nutritional Status', () => {
    it('prepares the view page and a member link that resolves to the same member', async () => {
        const caches = createMemoryCaches();
        const result = await prepare(buildPayload(), caches);
        assert.equal(result.ok, true, JSON.stringify(result.verified || result));

        for (const [memberNo, info] of Object.entries(MEMBERS)) {
            const view = await cachedBody(caches, ACTOR, `/household-profiling/${info.hh}/members/${memberNo}`);
            assert.ok(view, `member view ${memberNo}`);
            assert.match(
                view,
                new RegExp(`href="/household-profiling/${info.hh}/members/${memberNo}/nutritional-status"`),
            );
            const nutrition = await cachedBody(
                caches,
                ACTOR,
                `/household-profiling/${info.hh}/members/${memberNo}/nutritional-status`,
            );
            assert.ok(nutrition, `nutrition ${memberNo}`);
            assert.match(nutrition, new RegExp(info.name));
        }
    });

    it('no donor resident primary key survives in hydrated member or nutrition pages', async () => {
        const caches = createMemoryCaches();
        await prepare(buildPayload(), caches);
        // Donor member view is MB-001 (resident 11); MB-002/MB-003 must never reference it.
        for (const memberNo of ['MB-002', 'MB-003']) {
            const info = MEMBERS[memberNo];
            const base = `/household-profiling/${info.hh}/members/${memberNo}`;
            const view = await cachedBody(caches, ACTOR, base);
            assert.equal(view.includes('/residents/11/'), false);
            assert.equal(/data-resident-id="11"/.test(view), false);
            assert.match(view, new RegExp(`data-resident-id="${info.resident}"`));
            for (const suffix of ['/nutritional-status', '/nutritional-status/create']) {
                const page = await cachedBody(caches, ACTOR, `${base}${suffix}`);
                assert.ok(page, `${base}${suffix}`);
                assert.equal(page.includes('/residents/11/'), false);
                assert.equal(/data-offline-parent-resident-id="11"/.test(page), false);
                assert.equal(page.includes('MB-001'), false);
            }
        }
    });

    it('Member B never receives Member A record content, even when the donor has records', async () => {
        const caches = createMemoryCaches();
        // Every member has records, so the donor shell (MB-001) contains measurement rows.
        const result = await prepare(buildPayload({ everyoneHasRecords: true }), caches);
        assert.equal(result.ok, true, JSON.stringify(result.verified || result));

        const donorPage = nutritionIndexShell('HH-001', 'MB-001');
        assert.match(donorPage, /data-timbang-id="TB-900"/);

        for (const memberNo of ['MB-002', 'MB-003']) {
            const info = MEMBERS[memberNo];
            const page = await cachedBody(
                caches,
                ACTOR,
                `/household-profiling/${info.hh}/members/${memberNo}/nutritional-status`,
            );
            assert.equal(page.includes('data-timbang-id'), false);
            assert.equal(page.includes('18.5 kg'), false);
            assert.equal(page.includes('Donor remark'), false);
            assert.equal(page.includes('Saved donor measurement'), false);
            assert.equal(page.includes('Dana Cruz'), false);
            assert.match(page, /No nutritional measurements are recorded/);
            assert.match(page, new RegExp(info.name));
        }
    });

    it('resets donor rows and donor form values without touching identity-free shells', () => {
        const index = resetNutritionalStatusIndexShell(nutritionIndexShell('HH-001', 'MB-001'));
        assert.equal(index.includes('data-timbang-id'), false);
        assert.equal(index.includes('data-lml-hh-timbang-age-group'), false);
        assert.match(index, /No nutritional measurements are recorded/);
        assert.match(index, /Add Record/);

        assert.equal(resetNutritionalStatusIndexShell(index), index);
        assert.equal((index.match(/lml-hr-cc-nr__empty-title/g) || []).length, 1);
        const clean = nutritionIndexShell('HH-001', 'MB-002', { withRows: false });
        assert.equal(
            (resetNutritionalStatusIndexShell(clean).match(/lml-hr-cc-nr__empty-title/g) || []).length,
            1,
        );

        const create = resetNutritionalStatusCreateShell(
            nutritionCreateShell('HH-001', 'MB-001'),
            { sex: 'Male', birthday: '2016-03-03' },
        );
        assert.match(create, /data-resident-birthday="2016-03-03"/);
        assert.equal(create.includes('2019-05-05'), false);
        assert.match(create, /<dt>Sex<\/dt><dd>Male<\/dd>/);
        assert.equal(create.includes('Severely Underweight'), false);
        assert.equal(create.includes('7 years'), false);
        assert.match(create, /data-offline-health-action="timbang_record_store"/);
    });

    it('rewrites the donor identity even when the nutrition donor differs from the fromMember hint', () => {
        const html = hydrateHealthModuleHtml(
            nutritionIndexShell('HH-001', 'MB-001'),
            { household_no: 'HH-002' },
            { member_no: 'MB-003', name: MEMBERS['MB-003'].name, resident_id: 13 },
            'nutritional-status',
            'HH-999',
            'MB-999',
        );
        assert.equal(html.includes('MB-001'), false);
        assert.equal(html.includes('HH-001'), false);
        assert.equal(html.includes('Dana Cruz'), false);
        assert.match(html, /\/household-profiling\/HH-002\/members\/MB-003\/nutritional-status\/create/);
    });

    it('member view scrub replaces a donor resident id and links to the member page', () => {
        const html = hydrateMemberViewHtml(
            memberViewShell('HH-001', 'MB-001'),
            { household_no: 'HH-002' },
            { member_no: 'MB-003', name: MEMBERS['MB-003'].name, resident_id: 13 },
            'HH-001',
            'MB-001',
        );
        assert.equal(html.includes('/residents/11/'), false);
        assert.match(html, /data-resident-id="13"/);
        assert.match(html, /href="\/household-profiling\/HH-002\/members\/MB-003\/nutritional-status"/);

        const local = hydrateMemberViewHtml(
            memberViewShell('HH-001', 'MB-001'),
            { household_no: 'HH-002' },
            { member_no: 'MB-L-abc', name: 'Local Kid', local: true },
            'HH-001',
            'MB-001',
        );
        assert.equal(/data-resident-id=/.test(local), false);
        assert.equal(local.includes('/residents/'), false);
    });

    it('canonical member-card link is recognised and routed to the prepared member page', async () => {
        const caches = createMemoryCaches();
        await prepare(buildPayload(), caches);

        const link = fakeLink({
            'data-hh-nav': 'edit-nutrition',
            href: '/households/HH-001/residents/12/nutritional-status',
        });
        assert.equal(kindFromHouseholdNavLink(link), HH_NAV_KINDS.HEALTH_RECORD);
        assert.equal(isHealthSummaryNavPath('/households/HH-001/residents/12/nutritional-status'), true);

        const { result, assigned } = await offlineClick(link, caches);
        assert.equal(result.reason, 'cached');
        assert.deepEqual(assigned, ['/household-profiling/HH-001/members/MB-002/nutritional-status']);

        const donor = await offlineClick(
            fakeLink({ 'data-hh-nav': 'edit-nutrition', href: '/households/HH-001/residents/11/nutritional-status' }),
            caches,
        );
        assert.deepEqual(donor.assigned, ['/household-profiling/HH-001/members/MB-001/nutritional-status']);

        const create = await offlineClick(
            fakeLink({ href: '/households/HH-002/residents/13/nutritional-status/create' }),
            caches,
        );
        assert.deepEqual(create.assigned, ['/household-profiling/HH-002/members/MB-003/nutritional-status/create']);

        const unknown = await offlineClick(
            fakeLink({ 'data-hh-nav': 'edit-nutrition', href: '/households/HH-001/residents/999/nutritional-status' }),
            caches,
            { ensureSnapshotCached: async () => false, ensureEhSnapshotCached: async () => false },
        );
        assert.equal(unknown.assigned.length, 0);
        assert.equal(unknown.result.reason, 'uncached');
    });

    it('offline CREATE stays supported: prepared form for each member with its own identity', async () => {
        const caches = createMemoryCaches();
        await prepare(buildPayload(), caches);

        for (const [memberNo, info] of Object.entries(MEMBERS)) {
            const path = `/household-profiling/${info.hh}/members/${memberNo}/nutritional-status/create`;
            assert.equal(isHouseholdProfilingOfflineWritePath(path), true);
            const form = await cachedBody(caches, ACTOR, path);
            assert.ok(form, path);
            assert.match(form, /data-lml-hh-timbang-form/);
            assert.match(form, /data-offline-operation="HEALTH_SERVICE_WRITE"/);
            assert.match(form, /data-offline-health-action="timbang_record_store"/);
            assert.match(form, new RegExp(`data-offline-parent-member-no="${memberNo}"`));
            assert.match(form, new RegExp(`data-offline-parent-household-no="${info.hh}"`));
            assert.match(form, new RegExp(`data-offline-parent-resident-id="${info.resident}"`));
            assert.match(form, new RegExp(`data-resident-birthday="${info.birthday}"`));
            assert.equal(form.includes('Severely Underweight'), false);
        }
        assert.equal(
            isHouseholdProfilingOfflineWritePath('/households/HH-001/residents/12/nutritional-status/create'),
            true,
        );
    });

    it('actor isolation: pages and canonical resolution never cross actors', async () => {
        const caches = createMemoryCaches();
        await prepare(buildPayload(), caches);

        assert.ok(await cachedBody(caches, ACTOR, '/household-profiling/HH-001'));
        assert.equal(await cachedBody(caches, OTHER_ACTOR, '/household-profiling/HH-001'), null);
        assert.equal(
            await resolveNutritionalStatusMemberPath(ACTOR, '/households/HH-001/residents/12/nutritional-status'),
            '/household-profiling/HH-001/members/MB-002/nutritional-status',
        );
        assert.equal(
            await resolveNutritionalStatusMemberPath(OTHER_ACTOR, '/households/HH-001/residents/12/nutritional-status'),
            null,
        );

        const { result, assigned } = await offlineClick(
            fakeLink({ 'data-hh-nav': 'edit-nutrition', href: '/households/HH-001/residents/12/nutritional-status' }),
            caches,
            { actorId: OTHER_ACTOR, ensureSnapshotCached: async () => false, ensureEhSnapshotCached: async () => false },
        );
        assert.equal(assigned.length, 0);
        assert.equal(result.reason, 'uncached');
    });
});

describe('Phase 1 — Ready for Offline verification', () => {
    async function preparedCaches(payload = buildPayload()) {
        const caches = createMemoryCaches();
        const result = await prepare(payload, caches);
        assert.equal(result.ok, true, JSON.stringify(result.verified || result));
        return caches;
    }

    async function verify(caches) {
        return verifyStaffOfflineDataset(ACTOR, { caches, origin: ORIGIN });
    }

    it('succeeds once every required Phase 1 page is prepared', async () => {
        const caches = await preparedCaches();
        const verified = await verify(caches);
        assert.equal(verified.ok, true);
    });

    it('fails when a household record page is missing', async () => {
        const caches = await preparedCaches();
        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        await cache.delete(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-002')));
        const verified = await verify(caches);
        assert.equal(verified.ok, false);
        assert.equal(verified.reason, 'record-pages');
        assert.deepEqual(verified.missingPages, [{ path: '/household-profiling/HH-002', reason: 'missing' }]);
    });

    it('fails when a member view or Nutritional Status page is missing', async () => {
        const caches = await preparedCaches();
        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        await cache.delete(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-001/members/MB-002/nutritional-status')));
        await cache.delete(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-002/members/MB-003')));
        const verified = await verify(caches);
        assert.equal(verified.ok, false);
        assert.equal(verified.reason, 'record-pages');
        const paths = verified.missingPages.map((row) => row.path).sort();
        assert.deepEqual(paths, [
            '/household-profiling/HH-001/members/MB-002/nutritional-status',
            '/household-profiling/HH-002/members/MB-003',
        ]);
    });

    it('fails when a cached page carries another member or resident, donor rows or DEV assets', async () => {
        const caches = await preparedCaches();
        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        const put = (pathname, body) => cache.put(
            new Request(navigationCacheUrl(ORIGIN, pathname)),
            new Response(body, { headers: { 'Content-Type': 'text/html' } }),
        );

        await put(
            '/household-profiling/HH-001/members/MB-002/nutritional-status',
            nutritionIndexShell('HH-001', 'MB-001'),
        );
        let verified = await verify(caches);
        assert.equal(verified.reason, 'record-pages');
        assert.equal(verified.missingPages[0].reason, 'identity-mismatch');

        // Right identity but donor measurement rows for a member without records.
        await put(
            '/household-profiling/HH-001/members/MB-002/nutritional-status',
            nutritionIndexShell('HH-001', 'MB-002'),
        );
        verified = await verify(caches);
        assert.equal(verified.missingPages[0].reason, 'donor-content');

        await put(
            '/household-profiling/HH-001/members/MB-002/nutritional-status',
            nutritionIndexShell('HH-001', 'MB-002', { withRows: false }),
        );
        await put(
            '/household-profiling/HH-002/members/MB-003',
            memberViewShell('HH-002', 'MB-003').replace('/residents/13/', '/residents/11/'),
        );
        verified = await verify(caches);
        assert.equal(verified.missingPages[0].reason, 'identity-mismatch');

        await put('/household-profiling/HH-002/members/MB-003', memberViewShell('HH-002', 'MB-003'));
        await put(
            '/household-profiling/HH-002',
            householdViewShell('HH-002').replace('/build/assets/app-TEST.css', 'http://127.0.0.1:5173/resources/css/app.css'),
        );
        verified = await verify(caches);
        assert.deepEqual(verified.missingPages, [{ path: '/household-profiling/HH-002', reason: 'dev-assets' }]);
    });

    it('is checked against the requested actor cache only', async () => {
        const caches = await preparedCaches();
        const cache = await caches.open(htmlCacheNameForActor(OTHER_ACTOR));
        assert.equal(await cache.match(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-001'))), undefined);
        // Removing actor 7's page fails actor 7 even though another actor cache exists.
        const own = await caches.open(htmlCacheNameForActor(ACTOR));
        await own.delete(new Request(navigationCacheUrl(ORIGIN, '/household-profiling/HH-001')));
        assert.equal((await verify(caches)).ok, false);
    });
});
