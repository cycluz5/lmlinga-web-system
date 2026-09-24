/**
 * Offline Plot head must appear in Household Profiling without a second member-create.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { canonicalEhShellHtml } from './support/eh-canonical-shell.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const {
    getHouseholdSnapshot,
    getMemberSnapshot,
    listMemberSnapshots,
    membersForHousehold,
    dropOrphanPlotHeads,
    replaceHouseholdProfilingSnapshots,
    ageFromBirthday,
    formatAccomplishedDate,
    appendLocalMemberToHousehold,
    putHouseholdSnapshot,
    putMeta,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href);
const { listOperations } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const { OPERATION_TYPES, queuePlotNewHousehold } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href
);
const {
    handlePlotHouseholdQueued,
    handlePlotHouseholdSynced,
    handleEnvironmentalStepQueued,
    householdSnapshotFromPlot,
    resetEhShellWarmupForTests,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-eh-hydrate.js')).href);
const { hydrateHouseholdViewHtml } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-hydrate.js')).href
);

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
    resetEhShellWarmupForTests();
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    resetEhShellWarmupForTests();
});

const plot534 = {
    first_name: 'Harem',
    middle_name: '',
    last_name: 'Scarem',
    birthday: '1988-01-11',
    sex: 'Male',
    civil_status: 'Married',
    household_type: 'HHTS',
    zone: 5,
    date_registered: '2026-09-08',
    household_no: '534',
    lat: 13.372467,
    lng: 123.428871,
    consent: true,
};

const staleViewShell = `<!DOCTYPE html><html><body>
<article class="lml-hh-view" data-lml-hh-view data-household-no="HH-151">
<h2 class="lml-hh-view__hh-no">HH 151</h2>
<p class="lml-hh-view__head"><span class="lml-hh-view__head-label">Head of Household</span>
<span class="lml-hh-view__head-name">Shell Head</span></p>
<span class="lml-hh-view__members-badge" aria-label="3 members"><span>3 members</span></span>
<dl class="lml-hh-view__meta">
<div class="lml-hh-view__meta-item"><dt><span>Zone</span></dt><dd>Zone 2</dd></div>
<div class="lml-hh-view__meta-item"><dt><span>Accomplished Date</span></dt><dd>09/04/2026</dd></div>
</dl>
<article class="lml-hh-view__amenity lml-hh-view__amenity--water">
<p class="lml-hh-view__amenity-detail">Level II</p>
<span class="lml-hh-view__amenity-badge">Basic</span>
</article>
<article class="lml-hh-view__amenity lml-hh-view__amenity--sanitation">
<p class="lml-hh-view__amenity-detail">Pour flush</p>
<span class="lml-hh-view__amenity-badge">Improved</span>
</article>
<section class="lml-hh-view__members">
<h2>Household Members (3)</h2>
</section>
</article>
</body></html>`;

async function seedEhShells(actorId = 7) {
    for (const step of [1, 2, 3, 4]) {
        await putMeta(actorId, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
    }
    await putMeta(actorId, 'shell:eh-household_no', 'LML-EH');
    await putMeta(actorId, 'shell:view', staleViewShell);
    await putMeta(actorId, 'shell:household_no', 'HH-151');
}

async function plot534Queued(caches) {
    await seedEhShells();
    const queued = await queuePlotNewHousehold(plot534, {
        root: {
            getAttribute(name) {
                if (name === 'data-offline-actor-id') {
                    return '7';
                }
                if (name === 'data-offline-actor-username') {
                    return 'bhw.ana';
                }
                return '';
            },
        },
        window: { dispatchEvent() { return true; } },
    });
    assert.equal(queued.ok, true);
    const result = await handlePlotHouseholdQueued({
        operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
        payload: queued.record.payload,
        parent_server: queued.record.parent_server,
    }, {
        actorId: 7,
        caches,
        origin: 'http://localhost',
        navigate: false,
    });
    return result;
}

describe('offline plot household profiling head', () => {
    it('writes a local HEAD read-model for 534 without queueing ADD MEMBER', async () => {
        const caches = createMemoryCaches();
        const result = await plot534Queued(caches);
        assert.equal(result.ok, true);

        const queued = await listOperations();
        assert.equal(queued.filter((row) => row.operation_type === 'PLOT_HOUSEHOLD_WITH_HEAD').length, 1);
        assert.equal(queued.filter((row) => row.operation_type === 'RESIDENT_CREATE').length, 0);
        assert.equal(queued.filter((row) => row.operation_type === 'HOUSEHOLD_CREATE').length, 0);

        const household = await getHouseholdSnapshot(7, '534');
        assert.equal(household.household_no, '534');
        assert.equal(household.house_head, 'Harem Scarem');
        assert.equal(household.zone, 'Zone 5');
        assert.equal(household.date_registered, '2026-09-08');
        assert.equal(household.accomplished_date, '09/08/2026');
        assert.equal(household.member_count, 1);
        assert.match(String(household.head_member_no), /^MB-L-/i);

        const members = await listMemberSnapshots(7, '534');
        assert.equal(members.length, 1);
        const head = members[0];
        assert.equal(head.from_plot, true);
        assert.equal(head.local, true);
        assert.equal(head.resident_id, null);
        assert.equal(head.first_name, 'Harem');
        assert.equal(head.last_name, 'Scarem');
        assert.equal(head.relation, 'Head');
        assert.equal(head.relationship, 'Head');
        assert.equal(head.sex, 'Male');
        assert.equal(head.birthday, '1988-01-11');
        assert.equal(head.age, ageFromBirthday('1988-01-11'));
        assert.equal(head.name, 'Harem Scarem');
    });

    it('renders Household Profiling 534 from the plot snapshot, not the stale shell', async () => {
        const caches = createMemoryCaches();
        await plot534Queued(caches);
        await handleEnvironmentalStepQueued({
            operation_type: OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            payload: {
                household_no: '534',
                _eh_step: 1,
                water_supply_status: 'level_i',
                water_source_location: 'yes',
                water_availability: 'yes',
            },
            parent_server: { household_no: '534' },
        }, { actorId: 7, caches, origin: 'http://localhost', navigate: false });

        const cached = await caches.match(
            new Request(navigationCacheUrl('http://localhost', '/household-profiling/534')),
            { cacheName: htmlCacheNameForActor(7) },
        );
        assert.ok(cached);
        const html = await cached.text();
        assert.match(html, /Household Members \(1\)/);
        assert.match(html, /Harem Scarem/);
        assert.match(html, /lml-hh-view__head-badge/);
        assert.match(html, />Head</);
        assert.match(html, /Male/);
        assert.match(html, /data-hh-view-zone>Zone 5/);
        assert.match(html, /data-hh-view-date>09\/08\/2026/);
        assert.doesNotMatch(html, /Zone 2/);
        assert.doesNotMatch(html, /09\/04\/2026/);
        assert.doesNotMatch(html, /Shell Head/);
        assert.doesNotMatch(html, /No household members are recorded/);
        assert.match(html, /Level I/);
        assert.equal(formatAccomplishedDate('2026-09-08'), '09/08/2026');
    });

    it('replaces stale zone and date even when hydrating a canonical shell fragment', () => {
        const html = hydrateHouseholdViewHtml(staleViewShell, {
            household_no: '534',
            display_no: '534',
            house_head: 'Harem Scarem',
            zone: 'Zone 5',
            date_registered: '2026-09-08',
            accomplished_date: '09/08/2026',
            water: { level: 'Level I', status: 'With basic safe water' },
            sanitation: { facility: 'Open pit latrine', status: 'Good practice' },
        }, [{
            member_no: 'MB-L-abc',
            name: 'Harem Scarem',
            relationship: 'Head',
            relation: 'Head',
            age: 38,
            sex: 'Male',
            local: true,
            from_plot: true,
        }], 'HH-151');
        assert.match(html, /data-household-no="534"/);
        assert.match(html, />534<\/h2>/);
        assert.match(html, /Harem Scarem/);
        assert.match(html, /Zone 5/);
        assert.match(html, /09\/08\/2026/);
        assert.doesNotMatch(html, /Zone 2/);
        assert.doesNotMatch(html, /09\/04\/2026/);
        assert.match(html, /Household Members \(1\)/);
        assert.match(html, /Level I/);
    });

    it('keeps the plot head and a second offline member without duplicating the head', async () => {
        const caches = createMemoryCaches();
        await plot534Queued(caches);
        await appendLocalMemberToHousehold(7, '534', {
            client_local_member_id: 'MB-L-spouse1',
            first_name: 'Ada',
            last_name: 'Scarem',
            relation: 'Spouse',
            sex: 'Female',
            birthday: '1990-05-02',
        });
        const members = await listMemberSnapshots(7, '534');
        assert.equal(members.length, 2);
        assert.equal(members.filter((row) => String(row.relation).toLowerCase() === 'head').length, 1);
        const household = await getHouseholdSnapshot(7, '534');
        assert.equal(household.member_count, 2);
        const html = hydrateHouseholdViewHtml(staleViewShell, household, members, 'HH-151');
        assert.match(html, /Household Members \(2\)/);
        assert.match(html, /Harem Scarem/);
        assert.match(html, /Ada Scarem/);
    });

    it('promotes the local plot head to the server resident and drops the MB-L copy', async () => {
        const caches = createMemoryCaches();
        await plot534Queued(caches);
        const before = await listMemberSnapshots(7, '534');
        const localId = before[0].member_no;
        await handlePlotHouseholdSynced({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plot534,
            identities: {
                household_no: '534',
                household_pk: 88,
                resident_pk: 91,
                member_no: 'MB-12',
            },
            body: {
                household: { id: 88, household_no: '534' },
                resident: { id: 91, member_no: 'MB-12' },
            },
        }, { actorId: 7, caches, origin: 'http://localhost' });

        const household = await getHouseholdSnapshot(7, '534');
        assert.equal(household.household_id, 88);
        assert.equal(household.head_member_no, 'MB-12');
        assert.equal(household.pending_sync, false);

        const members = await listMemberSnapshots(7, '534');
        assert.equal(members.length, 1);
        assert.equal(members[0].member_no, 'MB-12');
        assert.equal(members[0].resident_id, 91);
        assert.equal(members[0].local, false);
        assert.equal(members[0].name, 'Harem Scarem');
        assert.equal(await getMemberSnapshot(7, '534', localId), null);

        await replaceHouseholdProfilingSnapshots(7, {
            households: [{
                household_id: 88,
                household_no: '534',
                display_no: '534',
                house_head: 'Harem Scarem',
                zone: 'Zone 5',
                accomplished_date: '09/08/2026',
                member_count: 1,
            }],
            members: [{
                household_id: 88,
                household_no: '534',
                resident_id: 91,
                member_no: 'MB-12',
                name: 'Harem Scarem',
                relationship: 'Head',
                relation: 'Head',
                first_name: 'Harem',
                last_name: 'Scarem',
                sex: 'Male',
                birthday: '1988-01-11',
                age: 38,
            }],
        });
        const afterBootstrap = await listMemberSnapshots(7, '534');
        assert.equal(afterBootstrap.length, 1);
        assert.equal(afterBootstrap[0].member_no, 'MB-12');
        assert.equal(afterBootstrap.filter((row) => row.name === 'Harem Scarem').length, 1);
    });

    it('drops an unpromoted plot-head local when bootstrap already has the server Head', async () => {
        const caches = createMemoryCaches();
        await plot534Queued(caches);
        await replaceHouseholdProfilingSnapshots(7, {
            households: [{
                household_id: 88,
                household_no: '534',
                house_head: 'Harem Scarem',
                zone: 'Zone 5',
                member_count: 1,
            }],
            members: [{
                household_id: 88,
                household_no: '534',
                resident_id: 91,
                member_no: 'MB-12',
                name: 'Harem Scarem',
                relationship: 'Head',
                relation: 'Head',
                first_name: 'Harem',
                last_name: 'Scarem',
                sex: 'Male',
            }],
        });
        const members = await listMemberSnapshots(7, '534');
        assert.equal(members.length, 1);
        assert.equal(members[0].member_no, 'MB-12');
        assert.equal(members.some((row) => /^MB-L-/i.test(row.member_no)), false);
    });
});

const plot536 = {
    first_name: 'Wilma',
    middle_name: 'Dela',
    last_name: 'Vega',
    birthday: '1994-03-08',
    sex: 'Female',
    civil_status: 'Married',
    household_type: 'HHTS',
    zone: 4,
    date_registered: '2026-09-08',
    household_no: '536',
    lat: 13.372467,
    lng: 123.428871,
    consent: true,
};

function plotActorRoot() {
    return {
        getAttribute(name) {
            if (name === 'data-offline-actor-id') {
                return '7';
            }
            if (name === 'data-offline-actor-username') {
                return 'bhw.ana';
            }
            return '';
        },
    };
}

describe('offline plot 536 button path writes hp_members immediately', () => {
    it('keeps Plot-head persist on the awaited button/queue path, not a dynamic chunk', () => {
        const forms = readFileSync(path.resolve('resources/js/offline/offline-forms.js'), 'utf8');
        const spot = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
        const store = readFileSync(path.resolve('resources/js/offline/offline-hp-store.js'), 'utf8');
        assert.match(forms, /import \{ persistPlotHouseholdReadModel \} from '\.\/offline-hp-store\.js'/);
        assert.equal(forms.includes("import('./offline-hp-store.js')"), false);
        assert.match(spot, /await persistPlotHouseholdReadModel\(queued\.record\.actor_id, queued\.record\.payload\)/);
        assert.match(store, /export async function persistPlotHouseholdReadModel/);
        assert.doesNotMatch(
            store.slice(store.indexOf('export async function membersForHousehold'), store.indexOf('export async function replaceHouseholdProfilingSnapshots')),
            /catch \{/,
        );
    });

    it('queuePlotNewHousehold persists Head in hp_members before EH handler runs', async () => {
        await seedEhShells();
        const queued = await queuePlotNewHousehold(plot536, {
            root: plotActorRoot(),
            window: { dispatchEvent() { return true; } },
        });
        assert.equal(queued.ok, true);

        const ops = await listOperations();
        assert.equal(ops.filter((row) => row.operation_type === 'PLOT_HOUSEHOLD_WITH_HEAD').length, 1);
        assert.equal(ops.filter((row) => row.operation_type === 'RESIDENT_CREATE').length, 0);
        assert.equal(queued.record.payload.first_name, 'Wilma');
        assert.equal(queued.record.payload.last_name, 'Vega');
        assert.equal(queued.record.payload.sex, 'Female');
        assert.equal(queued.record.payload.household_no, '536');

        const household = await getHouseholdSnapshot(7, '536');
        assert.equal(household.household_no, '536');
        assert.equal(household.house_head, 'Wilma Dela Vega');
        assert.equal(household.zone, 'Zone 4');
        assert.equal(household.date_registered, '2026-09-08');
        assert.equal(household.head.first_name, 'Wilma');
        assert.equal(household.head.last_name, 'Vega');
        assert.equal(household.head.sex, 'Female');
        assert.equal(household.head.birthday, '1994-03-08');
        assert.equal(household.member_count, 1);
        assert.match(String(household.head_member_no), /^MB-L-/i);

        const members = await listMemberSnapshots(7, '536');
        assert.equal(members.length, 1);
        const head = members[0];
        assert.equal(head.name, 'Wilma Dela Vega');
        assert.equal(head.first_name, 'Wilma');
        assert.equal(head.last_name, 'Vega');
        assert.equal(head.relation, 'Head');
        assert.equal(head.relationship, 'Head');
        assert.equal(head.sex, 'Female');
        assert.equal(head.birthday, '1994-03-08');
        assert.equal(head.from_plot, true);
        assert.equal(head.resident_id, null);
        assert.equal(head.zone, 'Zone 4');
        assert.match(String(head.member_no), /^MB-L-/i);
        assert.equal(head.id, `7:536:${head.member_no}`);

        const caches = createMemoryCaches();
        const continued = await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: queued.record.payload,
        }, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            navigate: false,
        });
        assert.equal(continued.ok, true);
        assert.equal(continued.path, '/environmental-health/household-water-supply?household=536');
        const afterEh = await listMemberSnapshots(7, '536');
        assert.equal(afterEh.length, 1);
        assert.equal(afterEh[0].name, 'Wilma Dela Vega');

        const html = hydrateHouseholdViewHtml(
            staleViewShell,
            await getHouseholdSnapshot(7, '536'),
            afterEh,
            'HH-151',
        );
        assert.match(html, /Household Members \(1\)/);
        assert.match(html, /Wilma Dela Vega/);
        assert.match(html, /lml-hh-view__head-badge/);
        assert.match(html, /Female/);
        assert.doesNotMatch(html, /No household members are recorded/);
    });
});

const plot535 = {
    first_name: 'Lionel',
    middle_name: '',
    last_name: 'Bautista',
    birthday: '1988-01-11',
    sex: 'Male',
    civil_status: 'Married',
    household_type: 'HHTS',
    zone: 3,
    date_registered: '2026-09-08',
    household_no: '535',
    lat: 13.372467,
    lng: 123.428871,
    consent: true,
};

async function plot535Queued(caches) {
    await seedEhShells();
    const queued = await queuePlotNewHousehold(plot535, {
        root: {
            getAttribute(name) {
                if (name === 'data-offline-actor-id') {
                    return '7';
                }
                if (name === 'data-offline-actor-username') {
                    return 'bhw.ana';
                }
                return '';
            },
        },
        window: { dispatchEvent() { return true; } },
    });
    assert.equal(queued.ok, true);
    const result = await handlePlotHouseholdQueued({
        operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
        payload: queued.record.payload,
        parent_server: queued.record.parent_server,
    }, {
        actorId: 7,
        caches,
        origin: 'http://localhost',
        navigate: false,
    });
    return { queued, result };
}

describe('offline plot 535 household profiling head (browser flow)', () => {
    it('writes hp_members Head for 535, keeps it through EH 1–4, hydrates HP, then adds a second member', async () => {
        const caches = createMemoryCaches();
        const { queued } = await plot535Queued(caches);

        const opsAfterPlot = await listOperations();
        assert.equal(opsAfterPlot.filter((row) => row.operation_type === 'PLOT_HOUSEHOLD_WITH_HEAD').length, 1);
        assert.equal(opsAfterPlot.filter((row) => row.operation_type === 'RESIDENT_CREATE').length, 0);
        assert.equal(queued.record.payload.household_no, '535');
        assert.equal(queued.record.payload.first_name, 'Lionel');

        let members = await listMemberSnapshots(7, '535');
        assert.equal(members.length, 1);
        assert.equal(members[0].name, 'Lionel Bautista');
        assert.equal(members[0].relation, 'Head');
        assert.equal(members[0].from_plot, true);
        assert.equal(members[0].resident_id, null);
        assert.match(String(members[0].member_no), /^MB-L-/i);

        const ehSteps = [
            { _eh_step: 1, household_no: '535', water_supply_status: 'level_i', water_source_location: 'yes', water_availability: 'yes' },
            { _eh_step: 2, household_no: '535' },
            { _eh_step: 3, household_no: '535', toilet_type: 'open_pit_latrine', open_defecation_practiced: 'yes', shared_toilet: 'no', sewage_disposal_method: 'off_site_collected_and_treated' },
            { _eh_step: 4, household_no: '535', solid_waste_practices: ['waste_segregation'] },
        ];
        for (const payload of ehSteps) {
            await handleEnvironmentalStepQueued({
                operation_type: OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
                payload,
                parent_server: { household_no: '535' },
            }, { actorId: 7, caches, origin: 'http://localhost', navigate: false });
            members = await listMemberSnapshots(7, '535');
            assert.equal(members.length, 1, `hp_members empty after EH step ${payload._eh_step}`);
            assert.equal(members[0].name, 'Lionel Bautista');
        }

        const household = await getHouseholdSnapshot(7, '535');
        const html = hydrateHouseholdViewHtml(staleViewShell, household, members, 'HH-151');
        assert.match(html, /Household Members \(1\)/);
        assert.match(html, /Lionel Bautista/);
        assert.match(html, /lml-hh-view__head-badge/);
        assert.match(html, /Male/);
        assert.match(html, /data-hh-view-zone>Zone 3/);
        assert.match(html, /data-hh-view-date>09\/08\/2026/);
        assert.doesNotMatch(html, /Zone 2/);
        assert.doesNotMatch(html, /09\/04\/2026/);
        assert.doesNotMatch(html, /No household members are recorded/);
        assert.match(html, /Level I/);

        await appendLocalMemberToHousehold(7, '535', {
            client_local_member_id: 'MB-L-spouse535',
            first_name: 'Ada',
            last_name: 'Bautista',
            relation: 'Spouse',
            sex: 'Female',
            birthday: '1990-05-02',
        });
        const two = await membersForHousehold(7, '535');
        assert.equal(two.length, 2);
        assert.equal(two.filter((row) => String(row.relation).toLowerCase() === 'head').length, 1);
        const twoHtml = hydrateHouseholdViewHtml(staleViewShell, await getHouseholdSnapshot(7, '535'), two, 'HH-151');
        assert.match(twoHtml, /Household Members \(2\)/);
        assert.match(twoHtml, /Lionel Bautista/);
        assert.match(twoHtml, /Ada Bautista/);

        const opsAfterSecond = await listOperations();
        assert.equal(opsAfterSecond.filter((row) => row.operation_type === 'PLOT_HOUSEHOLD_WITH_HEAD').length, 1);
        assert.equal(opsAfterSecond.filter((row) => row.operation_type === 'RESIDENT_CREATE').length, 0);

        await handlePlotHouseholdSynced({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plot535,
            identities: {
                household_no: '535',
                household_pk: 101,
                resident_pk: 202,
                member_no: 'MB-20',
            },
            body: {
                household: { id: 101, household_no: '535' },
                resident: { id: 202, member_no: 'MB-20' },
            },
        }, { actorId: 7, caches, origin: 'http://localhost' });

        const afterSync = await listMemberSnapshots(7, '535');
        assert.equal(afterSync.filter((row) => row.name === 'Lionel Bautista').length, 1);
        const promoted = afterSync.find((row) => row.name === 'Lionel Bautista');
        assert.equal(promoted.member_no, 'MB-20');
        assert.equal(promoted.resident_id, 202);
        assert.equal(promoted.local, false);
        assert.equal(afterSync.filter((row) => /^MB-L-/i.test(String(row.member_no)) && row.from_plot).length, 0);
        assert.equal(afterSync.filter((row) => row.relation === 'Spouse' || row.name === 'Ada Bautista').length, 1);
    });

    it('rebuilds the Plot head from hp_households when hp_members was never written', async () => {
        const snapshot = householdSnapshotFromPlot(plot535);
        await putHouseholdSnapshot(7, snapshot);
        assert.equal((await listMemberSnapshots(7, '535')).length, 0);

        const recovered = await membersForHousehold(7, '535');
        assert.equal(recovered.length, 1);
        assert.equal(recovered[0].name, 'Lionel Bautista');
        assert.equal(recovered[0].relation, 'Head');
        assert.equal(recovered[0].sex, 'Male');
        assert.equal(recovered[0].from_plot, true);
        assert.equal(recovered[0].resident_id, null);
        assert.match(String(recovered[0].member_no), /^MB-L-/i);

        const html = hydrateHouseholdViewHtml(staleViewShell, snapshot, recovered, 'HH-151');
        assert.match(html, /Household Members \(1\)/);
        assert.match(html, /Lionel Bautista/);
        assert.doesNotMatch(html, /No household members are recorded/);
    });

    it('does not treat an unsynced Plot head as an orphan', async () => {
        const caches = createMemoryCaches();
        await plot535Queued(caches);
        assert.equal((await listMemberSnapshots(7, '535')).length, 1);

        await dropOrphanPlotHeads(7, [
            {
                household_no: '151',
                member_no: 'MB-1',
                relation: 'Head',
                resident_id: 9,
                household_id: 4,
            },
        ]);
        assert.equal((await listMemberSnapshots(7, '535')).length, 1);

        await dropOrphanPlotHeads(7, [
            {
                household_no: '535',
                member_no: 'MB-99',
                relation: 'Head',
                resident_id: null,
                household_id: null,
            },
        ]);
        assert.equal((await listMemberSnapshots(7, '535')).length, 1);

        await dropOrphanPlotHeads(7, [
            {
                household_no: '535',
                member_no: 'MB-12',
                relation: 'Head',
                resident_id: 91,
                household_id: 88,
            },
        ]);
        const stillLocal = await listMemberSnapshots(7, '535');
        assert.equal(stillLocal.length, 1);
        assert.match(String(stillLocal[0].member_no), /^MB-L-/i);
        const household = await getHouseholdSnapshot(7, '535');
        assert.equal(household.local, true);
        assert.equal(household.pending_sync, true);
        assert.equal(household.member_count, 1);
    });
});
