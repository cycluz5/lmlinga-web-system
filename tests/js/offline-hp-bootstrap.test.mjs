/**
 * Household Profiling IndexedDB snapshots + shell hydration.
 */

import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { canonicalEhShellHtml } from './support/eh-canonical-shell.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const storeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href;
const hydrateUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href;
const bootstrapUrl = pathToFileURL(path.resolve('resources/js/offline/offline-hp-bootstrap.js')).href;

const { setIndexedDBFactory, resetIndexedDBFactory, OFFLINE_DB_VERSION, OPERATIONS_STORE, HP_HOUSEHOLDS_STORE } = await import(dbUrl);
const {
    replaceHouseholdProfilingSnapshots,
    getHouseholdSnapshot,
    appendLocalMemberToHousehold,
    listMemberSnapshots,
} = await import(storeUrl);
const {
    parseHouseholdProfilingPath,
    hydrateHouseholdViewHtml,
    cacheHydratedHouseholdPages,
    ensureHouseholdNavFromSnapshot,
} = await import(hydrateUrl);
const { prepareHouseholdProfilingOffline, resetHpBootstrapForTests } = await import(bootstrapUrl);

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

const payload = {
    generated_at: '2026-09-08T00:00:00Z',
    catalogs: { relations: ['Spouse'] },
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
        {
            household_id: 12,
            household_no: 'HH-201',
            display_no: 'HH 201',
            house_head: 'Ben Cruz',
            zone: 'Zone 2',
            member_count: 0,
            water: { title: 'Access to Safe Water', level: '—', status: 'Not recorded' },
            sanitation: { title: 'Sanitation Services', facility: '—', status: 'Not recorded' },
        },
    ],
    members: [
        {
            household_id: 11,
            household_no: 'HH-200',
            resident_id: 21,
            member_no: 'MB-021',
            name: 'Ana Rivera',
            relationship: 'Head',
            relation: 'Head',
            first_name: 'Ana',
            last_name: 'Rivera',
            sex: 'Female',
            birthday: '1990-01-01',
            health: {
                eligible: { child_immunization: true, risk_assessment: true, family_planning: true, maternal_care: true },
                has_records: {},
                warm_modules: [],
                nutrition_card: { weight: '—', height: '—', mode: 'bmi', bmi: '—', status: '—' },
                maternal_care_has_history: false,
            },
        },
    ],
};

describe('household profiling snapshots', () => {
    it('keeps the operations store and versions snapshots separately', async () => {
        assert.equal(OFFLINE_DB_VERSION, 4);
        assert.equal(OPERATIONS_STORE, 'operations');
        assert.equal(HP_HOUSEHOLDS_STORE, 'hp_households');
        await replaceHouseholdProfilingSnapshots(7, payload);
        const first = await getHouseholdSnapshot(7, 'HH-200');
        const second = await getHouseholdSnapshot(7, 'HH-201');
        assert.equal(first.house_head, 'Ana Rivera');
        assert.equal(second.house_head, 'Ben Cruz');
        assert.equal(await getHouseholdSnapshot(9, 'HH-200'), undefined);
    });

    it('hydrates two household views from one shell without extra HTML fetches', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        const caches = createMemoryCaches();
        const shell = '<html><body><article class="lml-hh-view" data-household-no="HH-200"><span class="lml-hh-view__head-name">Ana Rivera</span><section class="lml-hh-view__members">old</section></article></body></html>';
        await cacheHydratedHouseholdPages(7, payload.households[0], payload.members, {
            caches,
            origin: 'https://lmlinga.test',
            shells: { view: shell, shellHouseholdNo: 'HH-200' },
        });
        await cacheHydratedHouseholdPages(7, payload.households[1], [], {
            caches,
            origin: 'https://lmlinga.test',
            shells: { view: shell, shellHouseholdNo: 'HH-200' },
        });
        const cache = await caches.open(htmlCacheNameForActor(7));
        const a = await cache.match(new Request(navigationCacheUrl('https://lmlinga.test', '/household-profiling/HH-200')));
        const b = await cache.match(new Request(navigationCacheUrl('https://lmlinga.test', '/household-profiling/HH-201')));
        const aHtml = await a.text();
        const bHtml = await b.text();
        assert.match(aHtml, /Ana Rivera/);
        assert.match(bHtml, /Ben Cruz/);
        assert.match(bHtml, /HH-201/);
        assert.equal(parseHouseholdProfilingPath('/household-profiling/HH-201/members/create').kind, 'add-member');
    });

    it('appends a queued local member and keeps the household readable', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        await appendLocalMemberToHousehold(7, 'HH-201', {
            client_local_member_id: 'MB-L-abc12',
            first_name: 'Cora',
            last_name: 'Lim',
            relation: 'Daughter',
            sex: 'Female',
            birthday: '2018-05-01',
        });
        const members = await listMemberSnapshots(7, 'HH-201');
        assert.equal(members.some((row) => row.member_no === 'MB-L-abc12'), true);
        const household = await getHouseholdSnapshot(7, 'HH-201');
        assert.equal(household.member_count, 1);
        const html = hydrateHouseholdViewHtml(
            '<section class="lml-hh-view__members">x</section>',
            household,
            members,
            'HH-200',
        );
        assert.match(html, /Cora Lim/);
        assert.match(html, /MB-L-abc12/);
        assert.match(html, /Waiting to sync/);
    });

    it('hydrates member edit and keeps an unvisited household readable offline', async () => {
        await replaceHouseholdProfilingSnapshots(7, payload);
        const caches = createMemoryCaches();
        await cacheHydratedHouseholdPages(7, payload.households[1], [], {
            caches,
            origin: 'https://lmlinga.test',
            shells: { view: '<html><body><article class="lml-hh-view" data-household-no="HH-200"><section class="lml-hh-view__members">old</section></article></body></html>', shellHouseholdNo: 'HH-200' },
        });
        assert.equal(await ensureHouseholdNavFromSnapshot('/household-profiling/HH-201', {
            actorId: 7,
            caches,
            origin: 'https://lmlinga.test',
        }), true);
        assert.equal(await ensureHouseholdNavFromSnapshot('/household-profiling/HH-201/members/create', {
            actorId: 7,
            caches,
            origin: 'https://lmlinga.test',
        }), true);
        const cache = await caches.open(htmlCacheNameForActor(7));
        const amenities = await cache.match(new Request(navigationCacheUrl('https://lmlinga.test', '/household-profiling/HH-201/amenities')));
        assert.equal(Boolean(amenities), true);
        await cacheHydratedHouseholdPages(7, payload.households[0], payload.members, {
            caches,
            origin: 'https://lmlinga.test',
            shells: {
                view: '<html><body><article class="lml-hh-view"><section class="lml-hh-view__members">old</section></article></body></html>',
                memberEdit: '<html><body><form data-offline-operation="RESIDENT_UPDATE" data-offline-parent-member-no="MB-021"><input name="last_name" value="Rivera"></form></body></html>',
                shellHouseholdNo: 'HH-200',
                shellMemberId: 'MB-021',
            },
        });
        const editHit = await cache.match(new Request(navigationCacheUrl('https://lmlinga.test', '/household-profiling/HH-200/members/MB-021/edit')));
        assert.equal(Boolean(editHit), true);
    });

    it('prepareHouseholdProfilingOffline stores all listed households from one JSON payload', async () => {
        const fetches = [];
        const caches = createMemoryCaches();
        const status = { textContent: '', hidden: true, attributes: {} };
        const doc = {
            querySelector(selector) {
                if (selector === '[data-lml-hh-profiling]') {
                    return {
                        getAttribute(name) {
                            return name === 'data-offline-hp-bootstrap-url' ? '/offline/household-profiling-bootstrap' : null;
                        },
                    };
                }
                if (selector === '[data-hp-offline-ready]') {
                    return {
                        hidden: status.hidden,
                        textContent: status.textContent,
                        setAttribute(name, value) {
                            status.attributes[name] = value;
                        },
                        getAttribute(name) {
                            return status.attributes[name] || null;
                        },
                    };
                }
                if (selector === '[data-lml-offline-root]') {
                    return {
                        getAttribute(name) {
                            if (name === 'data-offline-eh-shell-base') {
                                return '/offline/environmental-health-shell';
                            }
                            return null;
                        },
                    };
                }
                return null;
            },
        };
        Object.defineProperty(status, 'hidden', { writable: true, value: true });
        Object.defineProperty(status, 'textContent', {
            set(value) { this._text = value; },
            get() { return this._text || ''; },
        });

        const result = await prepareHouseholdProfilingOffline({
            actorId: 7,
            origin: 'https://lmlinga.test',
            caches,
            navigator: { onLine: true },
            document: doc,
            fetch: async (url) => {
                fetches.push(String(url));
                if (String(url).includes('household-profiling-bootstrap')) {
                    return {
                        ok: true,
                        async json() {
                            return { ok: true, actor_id: 7, payload };
                        },
                    };
                }
                if (String(url).includes('/offline/environmental-health-shell/')) {
                    const step = Number(String(url).split('/').pop());
                    return {
                        ok: true,
                        async text() {
                            return canonicalEhShellHtml(step, 'LML-EH');
                        },
                    };
                }
                return {
                    ok: true,
                    async text() {
                        return `<!DOCTYPE html><html><body data-lml-offline-root class="lml-dashboard">
<aside class="lml-sidebar"></aside>
<main id="main-content">
<article class="lml-hh-view" data-household-no="HH-200" data-member-id="MB-021" data-lml-child-imm>
<section class="lml-hh-view__members"></section>
<form data-offline-operation="HEALTH_SERVICE_WRITE" data-offline-parent-household-no="HH-200" data-offline-parent-member-no="MB-021">
<button type="submit">Save</button>
</form>
</article></main></body></html>`;
                    },
                };
            },
        });

        assert.equal(result.ok, true);
        assert.equal(result.total, 2);
        // Core HP shells + supported health module shells + EH shells.
        assert.ok(fetches.filter((url) => url.includes('/household-profiling/')).length >= 5);
        assert.equal(await getHouseholdSnapshot(7, 'HH-201') ? true : false, true);
        const cached = await ensureHouseholdNavFromSnapshot('/household-profiling/HH-201', {
            actorId: 7,
            caches,
            origin: 'https://lmlinga.test',
        });
        assert.equal(cached, true);
        const cache = await caches.open(htmlCacheNameForActor(7));
        const hit = await cache.match(new Request(navigationCacheUrl('https://lmlinga.test', '/household-profiling/HH-201')));
        assert.equal(Boolean(hit), true);
    });
});
