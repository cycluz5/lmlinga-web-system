/**
 * Staff offline dataset preparation — HP bootstrap integrated into login prep.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { canonicalEhShellHtml } from './support/eh-canonical-shell.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const storeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href;
const staffUrl = pathToFileURL(path.resolve('resources/js/offline/offline-staff-dataset-prepare.js')).href;
const bootstrapUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-bootstrap.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    replaceHouseholdProfilingSnapshots,
    getHouseholdSnapshot,
    appendLocalMemberToHousehold,
    getMeta,
    listMemberSnapshots,
} = await import(storeUrl);
const {
    prepareStaffOfflineDataset,
    verifyStaffOfflineDataset,
} = await import(staffUrl);
const { resetHpBootstrapForTests } = await import(bootstrapUrl);

const ORIGIN = 'https://lmlinga.test';
const payload = {
    generated_at: '2026-09-08T00:00:00Z',
    catalogs: { relations: ['Spouse'], sexes: ['Female', 'Male'] },
    households: [
        {
            household_id: 11,
            household_no: 'HH-200',
            display_no: 'HH 200',
            house_head: 'Ana Rivera',
            zone: 'Zone 1',
            member_count: 1,
            water: { title: 'Access to Safe Water', level: 'Level II', status: 'Basic' },
            sanitation: { title: 'Sanitation Services', facility: 'Pour flush', status: 'Improved' },
        },
    ],
    members: [
        {
            household_id: 11,
            household_no: 'HH-200',
            resident_id: 21,
            member_no: 'MB-021',
            field_hash: 'hash-mb-021',
            name: 'Ana Rivera',
            relationship: 'Head',
            relation: 'Head',
            first_name: 'Ana',
            last_name: 'Rivera',
            sex: 'Female',
            birthday: '1990-01-01',
            health: {
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
                    'nutritional-status': false,
                    deworming: false,
                    'risk-assessment': false,
                    'family-planning': false,
                    'maternal-care': false,
                },
                warm_modules: [],
                nutrition_card: { weight: '—', height: '—', mode: 'bmi', bmi: '—', status: '—' },
                maternal_care_has_history: false,
            },
        },
    ],
};

const emptyPayload = {
    generated_at: '2026-09-08T01:00:00Z',
    catalogs: { relations: [] },
    households: [],
    members: [],
};

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
                            return '7';
                        }
                        return null;
                    },
                };
            }
            return null;
        },
    };
}

function makeFetch(bootstrapPayload = payload, { failBootstrap = false } = {}) {
    return async (url) => {
        const text = String(url);
        if (text.includes('household-profiling-bootstrap')) {
            if (failBootstrap) {
                return { ok: false, status: 500, async json() { return {}; } };
            }
            return {
                ok: true,
                async json() {
                    return { ok: true, actor_id: 7, payload: bootstrapPayload };
                },
            };
        }
        if (text.includes('/offline/environmental-health-shell/')) {
            const step = Number(text.split('/').pop());
            return { ok: true, async text() { return canonicalEhShellHtml(step, 'LML-EH'); } };
        }
        return { ok: true, async text() {
            return `<!DOCTYPE html><html><body data-lml-offline-root class="lml-dashboard">
<aside class="lml-sidebar"></aside>
<main id="main-content" class="lml-dashboard__content">
<article class="lml-hh-view" data-household-no="HH-200" data-member-id="MB-021" data-lml-child-imm>
<section class="lml-hh-view__members"></section>
<form method="post" data-offline-operation="HEALTH_SERVICE_WRITE" data-offline-parent-household-no="HH-200" data-offline-parent-member-no="MB-021">
<button type="submit">Save</button>
</form>
</article>
</main></body></html>`;
        } };
    };
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

describe('staff offline dataset preparation', () => {
    it('TEST A/B — bootstrap stores households and members for staff actor', async () => {
        const result = await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(),
        });
        assert.equal(result.ok, true);
        assert.equal(result.householdCount, 1);
        assert.equal(result.memberCount, 1);
        assert.equal((await getHouseholdSnapshot(7, 'HH-200'))?.house_head, 'Ana Rivera');
        assert.equal((await listMemberSnapshots(7, 'HH-200')).length, 1);
    });

    it('TEST I — zero-household bootstrap can succeed with generated_at', async () => {
        const result = await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(emptyPayload),
        });
        assert.equal(result.ok, true);
        assert.equal(result.householdCount, 0);
        assert.equal(result.memberCount, 0);
        assert.equal(await getMeta(7, 'generated_at'), emptyPayload.generated_at);
    });

    it('TEST H — bootstrap failure does not verify as prepared', async () => {
        const result = await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(payload, { failBootstrap: true }),
        });
        assert.equal(result.ok, false);
        const verified = await verifyStaffOfflineDataset(7);
        assert.equal(verified.ok, false);
    });

    it('TEST F — pending local MB-L-* survives server bootstrap refresh', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        await appendLocalMemberToHousehold(7, 'HH-200', {
            client_local_member_id: 'MB-L-local1',
            first_name: 'Maria',
            last_name: 'Santos',
            relation: 'Daughter',
            sex: 'Female',
            birthday: '2015-01-01',
        });
        const result = await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(),
        });
        assert.equal(result.ok, true);
        const members = await listMemberSnapshots(7, 'HH-200');
        assert.ok(members.some((row) => row.member_no === 'MB-L-local1'));
        assert.ok(members.some((row) => row.member_no === 'MB-021'));
    });

    it('Ready fails until every server member carries its own conflict hash', async () => {
        const noHash = {
            ...payload,
            members: payload.members.map(({ field_hash: _drop, ...row }) => row),
        };
        const stale = await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(noHash),
        });
        assert.equal(stale.ok, false);
        assert.equal(stale.reason, 'member-field-hash');

        resetHpBootstrapForTests();
        const fresh = await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(payload),
        });
        assert.equal(fresh.ok, true);
    });

    it('TEST G — actor B cannot read actor A dataset verification', async () => {
        await prepareStaffOfflineDataset({
            actorId: 7,
            origin: ORIGIN,
            caches: createMemoryCaches(),
            navigator: { onLine: true },
            document: dashboardDoc(),
            fetch: makeFetch(),
        });
        const a = await verifyStaffOfflineDataset(7);
        const b = await verifyStaffOfflineDataset(9);
        assert.equal(a.ok, true);
        assert.equal(b.ok, false);
    });
});
