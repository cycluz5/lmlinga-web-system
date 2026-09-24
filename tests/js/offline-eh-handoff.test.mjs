/**
 * Offline Spot Mapping Plot → Environmental Health Steps 1–4 → Household Profiling.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createMemoryCaches } from './support/fake-caches.mjs';
import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { canonicalEhShellHtml, plainEhFallbackHtml } from './support/eh-canonical-shell.mjs';
import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { enqueueOperation, listOperations, listReplayableForActor, compareReplayOrder } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const {
    handleSupportedFormSubmit,
    queuePlotNewHousehold,
    shouldQueueSupportedForm,
    OPERATION_TYPES,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href);
const { getHouseholdSnapshot, putMeta } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href
);
const {
    cacheEhWizardPages,
    ehStep1Url,
    ehStepUrl,
    handleEnvironmentalStepQueued,
    handlePlotHouseholdQueued,
    handlePlotHouseholdSynced,
    hasCanonicalEhShells,
    householdSnapshotFromPlot,
    isCanonicalEhShellHtml,
    isPlainEhFallbackHtml,
    nextEhUrl,
    parseEnvironmentalHealthPath,
    prepareEnvironmentalHealthShells,
    resetEhShellWarmupForTests,
    rewriteEhHouseholdHtml,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-eh-hydrate.js')).href);

const clientSource = readFileSync(path.resolve('resources/js/offline/offline-client.js'), 'utf8');
const hydrateSource = readFileSync(path.resolve('resources/js/offline/offline-eh-hydrate.js'), 'utf8');
const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
const formsSource = readFileSync(path.resolve('resources/js/offline/offline-forms.js'), 'utf8');
const swRuntime = readFileSync(path.resolve('resources/js/offline/offline-sw-runtime.js'), 'utf8');
const swPolicy = readFileSync(path.resolve('resources/js/offline/offline-sw-policy.js'), 'utf8');

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
    resetEhShellWarmupForTests();
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
});

const plotPayload = {
    first_name: 'Mae',
    middle_name: '',
    last_name: 'Tarnate',
    birthday: '1990-01-15',
    sex: 'Female',
    civil_status: 'Married',
    household_type: 'HHTS',
    zone: '1',
    date_registered: '2026-09-08',
    household_no: '180',
    lat: 13.372467,
    lng: 123.428871,
    consent: true,
};

function actorRoot() {
    return {
        getAttribute(name) {
            if (name === 'data-offline-actor-id') {
                return '7';
            }
            if (name === 'data-offline-actor-username') {
                return 'bhw.ana';
            }
            if (name === 'data-offline-eh-shell-base') {
                return '/offline/environmental-health-shell';
            }
            return '';
        },
        querySelector() {
            return null;
        },
    };
}

function canonicalShells(householdNo = 'LML-EH') {
    return {
        1: canonicalEhShellHtml(1, householdNo),
        2: canonicalEhShellHtml(2, householdNo),
        3: canonicalEhShellHtml(3, householdNo),
        4: canonicalEhShellHtml(4, householdNo),
    };
}

async function seedCanonicalShells(actorId = 7) {
    const shells = canonicalShells();
    for (const step of [1, 2, 3, 4]) {
        await putMeta(actorId, `shell:eh-step-${step}`, shells[step]);
    }
    await putMeta(actorId, 'shell:eh-household_no', 'LML-EH');
    return shells;
}

async function cachedEhHtml(caches, householdNo, step) {
    const href = ehStepUrl(householdNo, step);
    const parsed = new URL(href, 'http://localhost');
    const cached = await caches.match(
        new Request(navigationCacheUrl('http://localhost', parsed.pathname, parsed.search)),
        { cacheName: htmlCacheNameForActor(7) },
    );
    return cached ? cached.text() : '';
}

function assertRealEhHtml(html, step, householdNo) {
    assert.equal(isCanonicalEhShellHtml(html, step), true);
    assert.equal(isPlainEhFallbackHtml(html), false);
    assert.match(html, /lml-dashboard/);
    assert.match(html, /lml-hws__program-title/);
    assert.match(html, /\/build\/assets\/app\.css/);
    assert.match(html, /\/build\/assets\/app\.js/);
    assert.match(html, new RegExp(`data-household-no="${householdNo}"`));
    assert.doesNotMatch(html, /LML-EH/);
    assert.doesNotMatch(html, /data-offline-parent-household-id=/);
    assert.doesNotMatch(html, /data-hws-household-label/);
    if (step === 1) {
        assert.match(html, /lml-hws__level-grid/);
        assert.match(html, /lml-hws__level-card/);
        assert.match(html, /Water Supply Status/);
        assert.match(html, /Specify Water Source/);
        assert.match(html, /name="water_supply_status"/);
        assert.match(html, /name="specify_water_source"/);
        assert.match(html, /name="water_source_location"/);
        assert.match(html, /name="water_availability"/);
        assert.match(html, /Household Water Supply Information/);
        assert.match(html, /data-hws-safe-water-badge-text/);
        assert.match(html, /data-hws-level/);
        assert.match(html, /data-hws-location/);
        assert.match(html, /data-hws-availability/);
        assert.match(html, /data-hws-next/);
        assert.doesNotMatch(html, /data-hws-bound/);
        assert.equal((html.match(/data-lml-hws/g) || []).length, 1);
        assert.match(html, /class="lml-hws__next/);
    }
    if (step === 2) {
        assert.match(html, /data-hws-step2-form/);
        assert.match(html, /lml-hws__test-grid/);
        assert.match(html, /name="microbiological_test_date"/);
        assert.match(html, /name="physicochemical_test_date"/);
        assert.match(html, /Validation \/ Random Sampling \/ Testing/);
        assert.match(html, new RegExp(`/environmental-health/household-water-supply/${householdNo}/step-2`));
    }
    if (step === 3) {
        assert.match(html, /data-hws-step3-form/);
        assert.match(html, /name="toilet_type"/);
        assert.match(html, /pour_flush_with_septic_tank/);
        assert.match(html, /Basic Sanitation Facility/);
    }
    if (step === 4) {
        assert.match(html, /data-hws-step4-form/);
        assert.match(html, /solid_waste_practices\[\]/);
        assert.match(html, /waste_segregation/);
        assert.match(html, /Solid Waste Management/);
    }
}

function field(overrides) {
    return {
        name: '',
        type: 'text',
        value: '',
        disabled: false,
        readOnly: false,
        checked: false,
        ...overrides,
    };
}

function ehForm(step, householdNo, fields) {
    const attrs = {
        'data-offline-operation': 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
        'data-offline-eh-step': String(step),
        'data-offline-parent-household-no': householdNo,
    };
    const state = {};
    return {
        elements: fields,
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : (state[name] || null);
        },
        setAttribute(name, value) {
            state[name] = value;
            attrs[name] = value;
        },
        removeAttribute(name) {
            delete state[name];
            delete attrs[name];
        },
        querySelector() {
            return null;
        },
        closest(selector) {
            if (selector === 'form') {
                return this;
            }
            return {
                getAttribute(name) {
                    if (name === 'data-household-no') {
                        return householdNo;
                    }
                    return '';
                },
            };
        },
    };
}

describe('offline plot → environmental health handoff', () => {
    it('maps the existing EH wizard URLs without a Laravel handoff token', () => {
        assert.equal(ehStep1Url('180'), '/environmental-health/household-water-supply?household=180');
        assert.equal(ehStepUrl('180', 2), '/environmental-health/household-water-supply/180/step-2');
        assert.equal(ehStepUrl('180', 3), '/environmental-health/household-water-supply/180/step-3');
        assert.equal(ehStepUrl('180', 4), '/environmental-health/household-water-supply/180/step-4');
        assert.equal(nextEhUrl('180', 1), '/environmental-health/household-water-supply/180/step-2');
        assert.equal(nextEhUrl('180', 2), '/environmental-health/household-water-supply/180/step-3');
        assert.equal(nextEhUrl('180', 3), '/environmental-health/household-water-supply/180/step-4');
        assert.equal(nextEhUrl('180', 4), '/household-profiling/180');
        assert.deepEqual(
            parseEnvironmentalHealthPath('/environmental-health/household-water-supply', '?household=180'),
            { step: 1, householdNo: '180' },
        );
        assert.equal(parseEnvironmentalHealthPath('/environmental-health/household-water-supply', '?handoff=abc'), null);
    });

    it('rejects the fabricated plain fallback as a canonical shell', () => {
        const fallback = plainEhFallbackHtml('132', 1);
        assert.equal(isPlainEhFallbackHtml(fallback), true);
        assert.equal(isCanonicalEhShellHtml(fallback, 1), false);
        assert.equal(isCanonicalEhShellHtml(canonicalEhShellHtml(1, 'LML-EH'), 1), true);
        assert.doesNotMatch(hydrateSource, /function fallbackEhHtml/);
        assert.doesNotMatch(hydrateSource, /fallbackEhHtml\(/);
    });

    it('does not cache or open EH when the canonical production shell is missing', async () => {
        const navigated = [];
        const caches = createMemoryCaches();
        const result = await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plotPayload,
        }, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            assign: (url) => navigated.push(url),
            navigator: { onLine: false },
        });
        assert.equal(result.ok, false);
        assert.equal(result.reason, 'eh-shell-missing');
        assert.equal(navigated.length, 0);
        const html = await cachedEhHtml(caches, '180', 1);
        assert.equal(html, '');
        const snapshot = await getHouseholdSnapshot(7, '180');
        assert.equal(snapshot.household_no, '180');
    });

    it('refuses to cache the fabricated fallback even if it is supplied as a shell', async () => {
        const caches = createMemoryCaches();
        const ok = await cacheEhWizardPages(7, { household_no: '132' }, {
            caches,
            origin: 'http://localhost',
            shellHouseholdNo: 'LML-EH',
            shells: {
                1: plainEhFallbackHtml('LML-EH', 1),
                2: canonicalEhShellHtml(2),
                3: canonicalEhShellHtml(3),
                4: canonicalEhShellHtml(4),
            },
        });
        assert.equal(ok, false);
        assert.equal(await cachedEhHtml(caches, '132', 1), '');
    });

    it('warms canonical real Step 1–4 shells from the authenticated template route', async () => {
        const fetches = [];
        const prepared = await prepareEnvironmentalHealthShells({
            actorId: 7,
            document: {
                querySelector() {
                    return actorRoot();
                },
            },
            navigator: { onLine: true },
            fetch: async (url) => {
                fetches.push(String(url));
                const step = Number(String(url).slice(-1));
                return {
                    ok: true,
                    async text() {
                        return canonicalEhShellHtml(step, 'LML-EH');
                    },
                };
            },
        });
        assert.equal(prepared.ok, true);
        assert.deepEqual(fetches, [
            '/offline/environmental-health-shell/1',
            '/offline/environmental-health-shell/2',
            '/offline/environmental-health-shell/3',
            '/offline/environmental-health-shell/4',
        ]);
        const cached = await cacheEhWizardPages(7, { household_no: '132' }, {
            caches: createMemoryCaches(),
            origin: 'http://localhost',
        });
        assert.equal(cached, true);
    });

    it('strips poisoned data-hws-bound from rewritten household 538 Step 1 HTML', async () => {
        const poisoned = canonicalEhShellHtml(1, 'LML-EH').replace(
            'data-lml-hws data-hws-step="1"',
            'data-lml-hws data-hws-bound="1" data-hws-step="1"',
        );
        assert.match(poisoned, /data-hws-bound="1"/);
        await putMeta(7, 'shell:eh-step-1', poisoned);
        await putMeta(7, 'shell:eh-step-2', canonicalEhShellHtml(2));
        await putMeta(7, 'shell:eh-step-3', canonicalEhShellHtml(3));
        await putMeta(7, 'shell:eh-step-4', canonicalEhShellHtml(4));
        await putMeta(7, 'shell:eh-household_no', 'LML-EH');

        assert.equal(await hasCanonicalEhShells(7), false);

        const caches = createMemoryCaches();
        const ok = await cacheEhWizardPages(7, { household_no: '538' }, {
            caches,
            origin: 'http://localhost',
        });
        assert.equal(ok, true);
        const html = await cachedEhHtml(caches, '538', 1);
        assertRealEhHtml(html, 1, '538');
        assert.match(html, /action="\/environmental-health\/household-water-supply"/);
        assert.match(html, /name="water_supply_status" value="level_ii"/);
        assert.match(html, /data-household-no="538"/);
        assert.doesNotMatch(html, /data-hws-bound/);
    });

    it('does not treat household 1 as a global substring when rewriting household 111', async () => {
        const shell = canonicalEhShellHtml(1, '1').replace(
            'src="/build/assets/app.js"',
            'src="/build/assets/app-CVlBAonN.js"',
        );
        await putMeta(7, 'shell:eh-step-1', shell);
        await putMeta(7, 'shell:eh-step-2', canonicalEhShellHtml(2, '1'));
        await putMeta(7, 'shell:eh-step-3', canonicalEhShellHtml(3, '1'));
        await putMeta(7, 'shell:eh-step-4', canonicalEhShellHtml(4, '1'));
        await putMeta(7, 'shell:eh-household_no', '1');

        const caches = createMemoryCaches();
        const ok = await cacheEhWizardPages(7, { household_no: '111' }, {
            caches,
            origin: 'http://localhost',
        });
        assert.equal(ok, true);
        const html = await cachedEhHtml(caches, '111', 1);
        assert.match(html, /data-hws-step="1"/);
        assert.doesNotMatch(html, /data-hws-step="111"/);
        assert.match(html, /value="level_i"/);
        assert.match(html, /value="level_iii"/);
        assert.match(html, /src="\/build\/assets\/app-CVlBAonN\.js"/);
        assert.match(html, /data-household-no="111"/);
        assert.equal(isCanonicalEhShellHtml(html, 1), true);
        assert.doesNotMatch(rewriteEhHouseholdHtml(shell, '1', { household_no: '111' }), /data-hws-step="111"/);
    });

    it('queues exactly one plot CREATE, keeps numeric 180 and coordinates, then opens real EH Step 1', async () => {
        await seedCanonicalShells();
        const navigated = [];
        const caches = createMemoryCaches();
        const queued = await queuePlotNewHousehold(plotPayload, { root: actorRoot(), window: { dispatchEvent() { return true; } } });
        assert.equal(queued.ok, true);
        const result = await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: queued.record.payload,
            parent_server: queued.record.parent_server,
        }, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            assign: (url) => navigated.push(url),
        });

        assert.equal(result.ok, true);
        assert.equal(navigated.join(), '/environmental-health/household-water-supply?household=180');
        const rows = await listOperations();
        assert.equal(rows.length, 1);
        assert.equal(rows[0].operation_type, 'PLOT_HOUSEHOLD_WITH_HEAD');
        assert.equal(rows[0].payload.household_no, '180');
        assert.equal(rows[0].payload.lat, 13.372467);
        assert.equal(rows[0].payload.lng, 123.428871);
        assert.equal(rows[0].payload.handoff_token, undefined);

        const snapshot = await getHouseholdSnapshot(7, '180');
        assert.equal(snapshot.household_no, '180');
        assert.equal(snapshot.house_head, 'Mae Tarnate');
        assert.equal(snapshot.latitude, 13.372467);
        assert.equal(snapshot.longitude, 123.428871);
        assert.equal(snapshot.household_id, null);
        assert.equal(snapshot.local, true);

        const html = await cachedEhHtml(caches, '180', 1);
        assertRealEhHtml(html, 1, '180');
        assert.match(html, /data-offline-eh-step="1"/);
        assert.doesNotMatch(html, /handoff/);
        assert.equal(isPlainEhFallbackHtml(html), false);
    });

    it('does not mint a fake server PK or change OSM tile policy', () => {
        const snapshot = householdSnapshotFromPlot(plotPayload);
        assert.equal(snapshot.household_id, null);
        assert.doesNotMatch(swRuntime, /tile\.openstreetmap\.org/);
        assert.doesNotMatch(swPolicy, /tile\.openstreetmap\.org/);
        assert.match(swPolicy, /handoff/);
        assert.match(clientSource, /handlePlotHouseholdQueued/);
        assert.match(clientSource, /handleEnvironmentalStepQueued/);
        assert.doesNotMatch(formsSource, /handoff_token: /);
        const offlineBranch = spotSource.slice(
            spotSource.indexOf('const finishLocalQueue'),
            spotSource.indexOf('const response = await fetch(createUrl'),
        );
        assert.equal(offlineBranch.includes('handoff'), false);
        assert.equal(offlineBranch.includes('location.replace'), false);
        assert.match(spotSource, /fetch\(createUrl/);
        assert.match(spotSource, /body\?\.redirect_url/);
    });

    it('saves EH Step 1 locally and continues to the real Step 2 UI without a native POST', async () => {
        await seedCanonicalShells();
        const caches = createMemoryCaches();
        await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plotPayload,
        }, { actorId: 7, caches, origin: 'http://localhost', navigate: false });

        let prevented = false;
        const form = ehForm(1, '180', [
            field({ name: 'household_no', value: '180' }),
            field({ name: 'water_supply_status', type: 'radio', value: 'level_i', checked: true }),
            field({ name: 'water_source_location', type: 'radio', value: 'yes', checked: true }),
            field({ name: 'water_availability', type: 'radio', value: 'yes', checked: true }),
        ]);
        const event = {
            preventDefault() {
                prevented = true;
            },
            target: form,
        };
        const handled = await handleSupportedFormSubmit(event, {
            navigator: { onLine: false },
            root: actorRoot(),
            window: { dispatchEvent() { return true; } },
        });
        assert.equal(handled, true);
        assert.equal(prevented, true);

        const ops = await listOperations();
        const eh = ops.filter((row) => row.operation_type === 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE');
        assert.equal(eh.length, 1);
        assert.equal(eh[0].payload._eh_step, 1);
        assert.equal(eh[0].payload.household_no, '180');
        assert.equal(eh[0].payload.water_supply_status, 'level_i');
        assert.equal(eh[0].parent_server.household_no, '180');
        assert.equal(eh[0].parent_server.household_id, undefined);

        const navigated = [];
        const continued = await handleEnvironmentalStepQueued({
            operation_type: OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            payload: eh[0].payload,
            parent_server: eh[0].parent_server,
        }, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            assign: (url) => navigated.push(url),
        });
        assert.equal(continued.path, '/environmental-health/household-water-supply/180/step-2');
        assert.equal(navigated.join(), continued.path);
        const snapshot = await getHouseholdSnapshot(7, '180');
        assert.equal(snapshot.eh.completed_step, 1);
        assert.equal(snapshot.water.status, 'level_i');
        assertRealEhHtml(await cachedEhHtml(caches, '180', 2), 2, '180');
    });

    it('walks real Step 2 → 3 → 4 UI then Household Profiling from local saves', async () => {
        await seedCanonicalShells();
        const caches = createMemoryCaches();
        await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plotPayload,
        }, { actorId: 7, caches, origin: 'http://localhost', navigate: false });

        const steps = [
            { step: 1, payload: { household_no: '180', _eh_step: 1, water_supply_status: 'level_i', water_source_location: 'yes', water_availability: 'yes' }, next: '/environmental-health/household-water-supply/180/step-2' },
            { step: 2, payload: { household_no: '180', _eh_step: 2 }, next: '/environmental-health/household-water-supply/180/step-3' },
            { step: 3, payload: { household_no: '180', _eh_step: 3, toilet_type: 'open_pit_latrine', open_defecation_practiced: 'yes', shared_toilet: 'no', sewage_disposal_method: 'off_site_collected_and_treated' }, next: '/environmental-health/household-water-supply/180/step-4' },
            { step: 4, payload: { household_no: '180', _eh_step: 4, solid_waste_practices: ['waste_segregation'] }, next: '/household-profiling/180' },
        ];
        const navigated = [];
        for (const row of steps) {
            const result = await handleEnvironmentalStepQueued({
                operation_type: OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
                payload: row.payload,
                parent_server: { household_no: '180' },
            }, {
                actorId: 7,
                caches,
                origin: 'http://localhost',
                assign: (url) => navigated.push(url),
            });
            assert.equal(result.path, row.next);
        }
        assert.deepEqual(navigated, steps.map((row) => row.next));
        assertRealEhHtml(await cachedEhHtml(caches, '180', 1), 1, '180');
        assertRealEhHtml(await cachedEhHtml(caches, '180', 2), 2, '180');
        assertRealEhHtml(await cachedEhHtml(caches, '180', 3), 3, '180');
        assertRealEhHtml(await cachedEhHtml(caches, '180', 4), 4, '180');

        const hp = await caches.match(
            new Request(navigationCacheUrl('http://localhost', '/household-profiling/180')),
            { cacheName: htmlCacheNameForActor(7) },
        );
        assert.ok(hp);
        const snapshot = await getHouseholdSnapshot(7, '180');
        assert.equal(snapshot.eh.completed_step, 4);
        assert.deepEqual(snapshot.sanitation.solid_waste_practices, ['waste_segregation']);
    });

    it('reloads the local household after plot without waiting for MySQL', async () => {
        await seedCanonicalShells();
        const caches = createMemoryCaches();
        await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plotPayload,
        }, { actorId: 7, caches, origin: 'http://localhost', navigate: false });
        await handleEnvironmentalStepQueued({
            operation_type: OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE,
            payload: {
                household_no: '180',
                _eh_step: 1,
                water_supply_status: 'level_ii',
                water_source_location: 'yes',
                water_availability: 'no',
            },
            parent_server: { household_no: '180' },
        }, { actorId: 7, caches, origin: 'http://localhost', navigate: false });

        const again = await getHouseholdSnapshot(7, '180');
        assert.equal(again.household_no, '180');
        assert.equal(again.water.status, 'level_ii');
        assert.equal(again.latitude, 13.372467);
    });

    it('keeps online EH submits native and online Plot on the server handoff path', () => {
        const form = ehForm(1, 'HH-001', [field({ name: 'household_no', value: 'HH-001' })]);
        assert.equal(shouldQueueSupportedForm(form, { navigator: { onLine: true }, window: {} }), false);
        assert.equal(shouldQueueSupportedForm(form, { navigator: { onLine: false } }), true);
        assert.match(spotSource, /window\.location\.replace\(redirectUrl\)/);
        assert.match(spotSource, /handoff_token/);
        assert.match(spotSource, /fetch\(createUrl/);
    });

    it('replays plot CREATE before environmental writes and promotes the same household_no', async () => {
        await enqueueOperation({
            actor_id: 7,
            operation_type: 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE',
            payload: { household_no: '180', _eh_step: 1, water_supply_status: 'level_i' },
            parent_server: { household_no: '180' },
            created_at_client: '2026-09-08T10:00:02.000Z',
        });
        await enqueueOperation({
            actor_id: 7,
            operation_type: 'PLOT_HOUSEHOLD_WITH_HEAD',
            payload: plotPayload,
            created_at_client: '2026-09-08T10:00:01.000Z',
        });
        const ordered = await listReplayableForActor(7);
        assert.equal(ordered[0].operation_type, 'PLOT_HOUSEHOLD_WITH_HEAD');
        assert.equal(ordered[1].operation_type, 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE');
        assert.ok(compareReplayOrder(ordered[0], ordered[1]) < 0);

        await seedCanonicalShells();
        await handlePlotHouseholdQueued({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plotPayload,
        }, { actorId: 7, caches: createMemoryCaches(), origin: 'http://localhost', navigate: false });
        await handlePlotHouseholdSynced({
            operation_type: OPERATION_TYPES.PLOT_HOUSEHOLD_WITH_HEAD,
            payload: plotPayload,
            identities: { household_no: '180', household_pk: 44 },
            body: { household: { id: 44, household_no: '180' } },
        }, { actorId: 7 });
        const snapshot = await getHouseholdSnapshot(7, '180');
        assert.equal(snapshot.household_id, 44);
        assert.equal(snapshot.pending_sync, false);
        assert.equal(snapshot.household_no, '180');
    });

    it('does not mutate plotted markers when entering the EH wizard', () => {
        const finish = spotSource.slice(
            spotSource.indexOf('const finishLocalQueue'),
            spotSource.indexOf('const response = await fetch(createUrl'),
        );
        assert.equal(finish.includes('markerById'), false);
        assert.equal(finish.includes('flyTo'), false);
        assert.equal(finish.includes('setView'), false);
        assert.equal(finish.includes('fitBounds'), false);
    });
});
