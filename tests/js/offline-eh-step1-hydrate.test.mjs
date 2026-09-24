/**
 * Offline EH Step 1 must reuse production household-water-supply.js after hydration.
 * Sequence matches the browser: production init → live fill → init/refresh → user input → submit.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createFakeIndexedDB, resetFakeIndexedDB } from './support/fake-indexeddb.mjs';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { canonicalEhShellHtml, plainEhFallbackHtml, poisonedLaravelEhStep1Html } from './support/eh-canonical-shell.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';

const dbUrl = pathToFileURL(path.resolve('resources/js/offline/offline-db.js')).href;
const { setIndexedDBFactory, resetIndexedDBFactory } = await import(dbUrl);
const { putHouseholdSnapshot, putMeta, getHouseholdSnapshot } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-hp-store.js')).href
);
const { listOperations } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-queue.js')).href
);
const {
    handleSupportedFormSubmit,
    shouldQueueSupportedForm,
    OPERATION_TYPES,
} = await import(pathToFileURL(path.resolve('resources/js/offline/offline-forms.js')).href);
const {
    fillEhFormFromSnapshot,
    handleEnvironmentalStepQueued,
    isCanonicalEhShellHtml,
    isPlainEhFallbackHtml,
    bootEhLive,
    rewriteEhHouseholdHtml,
    cacheEhWizardPages,
    looksLikeEhWizardUi,
} = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-eh-hydrate.js')).href
);
const { htmlCacheNameForActor, navigationCacheUrl } = await import(
    pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href
);
const {
    initHouseholdWaterSupply,
    refreshHouseholdWaterSupplyState,
    bootHouseholdWaterSupply,
    readStep1State,
} = await import(
    pathToFileURL(path.resolve('resources/js/pages/household-water-supply.js')).href
);

const hwsSource = readFileSync(path.resolve('resources/js/pages/household-water-supply.js'), 'utf8');
const hydrateSource = readFileSync(path.resolve('resources/js/offline/offline-eh-hydrate.js'), 'utf8');
const bladeSource = readFileSync(
    path.resolve('resources/views/pages/environmental-health/household-water-supply.blade.php'),
    'utf8',
);
const clientSource = readFileSync(path.resolve('resources/js/offline/offline-client.js'), 'utf8');

beforeEach(() => {
    resetFakeIndexedDB();
    setIndexedDBFactory(createFakeIndexedDB());
});

afterEach(() => {
    resetIndexedDBFactory();
    resetFakeIndexedDB();
    delete globalThis.document;
    delete globalThis.window;
    delete globalThis.sessionStorage;
    delete globalThis.navigator;
});

function storage() {
    const data = new Map();
    return {
        getItem(key) {
            return data.has(key) ? data.get(key) : null;
        },
        setItem(key, value) {
            data.set(String(key), String(value));
        },
        removeItem(key) {
            data.delete(String(key));
        },
    };
}

function changeEvent(target) {
    return {
        type: 'change',
        target,
        bubbles: true,
        defaultPrevented: false,
        cancelBubble: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopPropagation() {
            this.cancelBubble = true;
        },
    };
}

function submitEvent(target) {
    return {
        type: 'submit',
        target,
        bubbles: true,
        defaultPrevented: false,
        cancelBubble: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopPropagation() {
            this.cancelBubble = true;
        },
    };
}

function asField(el, { type = 'text', name = '', value = '', checked = false, disabled = false } = {}) {
    el.type = type;
    el.setAttribute('type', type);
    el.name = name || el.getAttribute('name') || '';
    el.name = name || el.getAttribute('name') || '';
    el.value = value || el.getAttribute('value') || '';
    el.checked = checked;
    el.disabled = disabled;
    el.required = false;
    el.readOnly = false;
    el.hidden = el.hasAttribute('hidden');
    el.scrollIntoView = () => {};
    return el;
}

function radio(doc, name, value, dataAttr) {
    const label = doc.createElement('label');
    label.setAttribute('data-hws-level-card', '');
    label.classList.add('lml-hws__level-card');
    const input = asField(doc.createElement('input'), {
        type: 'radio',
        name,
        value,
    });
    input.setAttribute('name', name);
    input.setAttribute('value', value);
    input.setAttribute(dataAttr, '');
    input.classList.add('lml-hws__level-input');
    label.appendChild(input);
    const caption = doc.createElement('span');
    caption.textContent = value;
    label.appendChild(caption);
    return { label, input };
}

function choice(doc, name, value, dataAttr) {
    const label = doc.createElement('label');
    const input = asField(doc.createElement('input'), { type: 'radio', name, value });
    input.setAttribute('name', name);
    input.setAttribute('value', value);
    input.setAttribute(dataAttr, '');
    label.appendChild(input);
    return { label, input };
}

function mountStep1(householdNo = '537') {
    const doc = createDocument();
    const html = doc.createElement('html');
    const body = doc.createElement('body');
    body.dataset = {};
    body.style = {};
    html.appendChild(body);
    doc.documentElement = html;
    doc.body = body;

    const page = doc.createElement('div');
    page.setAttribute('data-lml-offline-root', '');
    page.setAttribute('data-offline-actor-id', '7');
    page.setAttribute('data-offline-actor-username', 'bhw.ana');

    const root = doc.createElement('div');
    root.setAttribute('data-lml-hws', '');
    root.setAttribute('data-hws-step', '1');
    root.setAttribute('data-household-no', householdNo);
    root.setAttribute('data-spot-mapping-url', '/spot-mapping');
    root.setAttribute('data-hws-back-url', '/spot-mapping');
    root.classList.add('lml-hws');

    const form = doc.createElement('form');
    form.setAttribute('data-hws-form', '');
    form.setAttribute('method', 'post');
    form.setAttribute('action', '/environmental-health/household-water-supply');
    form.setAttribute('data-offline-operation', 'ENVIRONMENTAL_WATER_SUPPLY_UPDATE');
    form.setAttribute('data-offline-eh-step', '1');
    form.setAttribute('data-offline-parent-household-no', householdNo);
    form.method = 'post';
    form.action = '/environmental-health/household-water-supply';

    const householdInput = asField(doc.createElement('input'), {
        type: 'hidden',
        name: 'household_no',
        value: householdNo,
    });
    householdInput.setAttribute('name', 'household_no');
    householdInput.setAttribute('value', householdNo);
    householdInput.setAttribute('data-hws-household-no', '');

    const badge = doc.createElement('p');
    badge.setAttribute('data-hws-safe-water-badge', '');
    badge.classList.add('lml-hws__safe-water-badge', 'is-pending');
    const badgeText = doc.createElement('span');
    badgeText.setAttribute('data-hws-safe-water-badge-text', '');
    badgeText.textContent = 'Not yet determined';
    badge.appendChild(badgeText);

    const levels = ['level_i', 'level_ii', 'level_iii', 'others'].map((value) => (
        radio(doc, 'water_supply_status', value, 'data-hws-level')
    ));
    const locationNo = choice(doc, 'water_source_location', 'no', 'data-hws-location');
    const locationYes = choice(doc, 'water_source_location', 'yes', 'data-hws-location');
    const availabilityNo = choice(doc, 'water_availability', 'no', 'data-hws-availability');
    const availabilityYes = choice(doc, 'water_availability', 'yes', 'data-hws-availability');

    const specifyWrap = doc.createElement('div');
    specifyWrap.setAttribute('data-hws-specify', '');
    specifyWrap.hidden = true;
    specifyWrap.setAttribute('hidden', '');
    const specifyInput = asField(doc.createElement('input'), { type: 'text', name: 'specify_water_source' });
    specifyInput.setAttribute('name', 'specify_water_source');
    specifyInput.setAttribute('data-hws-specify-input', '');
    specifyWrap.appendChild(specifyInput);

    const nextBtn = asField(doc.createElement('button'), { type: 'submit', disabled: true });
    nextBtn.setAttribute('type', 'submit');
    nextBtn.setAttribute('data-hws-next', '');
    nextBtn.setAttribute('disabled', '');
    nextBtn.setAttribute('aria-disabled', 'true');
    nextBtn.textContent = 'Next';
    nextBtn.disabled = true;

    ['water_supply_status', 'specify_water_source', 'water_source_location', 'water_availability'].forEach((field) => {
        const err = doc.createElement('p');
        err.setAttribute('data-hws-error', field);
        err.hidden = true;
        form.appendChild(err);
    });

    form.appendChild(householdInput);
    form.appendChild(badge);
    levels.forEach((row) => form.appendChild(row.label));
    form.appendChild(specifyWrap);
    form.appendChild(locationYes.label);
    form.appendChild(locationNo.label);
    form.appendChild(availabilityYes.label);
    form.appendChild(availabilityNo.label);
    form.appendChild(nextBtn);
    form.elements = [
        householdInput,
        ...levels.map((row) => row.input),
        specifyInput,
        locationYes.input,
        locationNo.input,
        availabilityYes.input,
        availabilityNo.input,
        nextBtn,
    ];

    const dialog = doc.createElement('div');
    dialog.setAttribute('data-hws-dialog', '');
    dialog.hidden = true;
    const panel = doc.createElement('div');
    panel.setAttribute('data-hws-dialog-panel', '');
    const message = doc.createElement('p');
    message.setAttribute('data-hws-dialog-message', '');
    message.textContent = 'Leave?';
    const stay = doc.createElement('button');
    stay.setAttribute('data-hws-dialog-stay', '');
    const leave = doc.createElement('button');
    leave.setAttribute('data-hws-dialog-leave', '');
    dialog.appendChild(panel);
    panel.appendChild(message);
    panel.appendChild(stay);
    panel.appendChild(leave);

    root.appendChild(form);
    root.appendChild(dialog);
    page.appendChild(root);
    body.appendChild(page);

    const session = storage();
    const win = {
        sessionStorage: session,
        navigator: { onLine: false },
        addEventListener() {},
        dispatchEvent(event) {
            win._events.push(event);
            return true;
        },
        _events: [],
        _assigned: null,
        location: {
            href: `/environmental-health/household-water-supply?household=${householdNo}`,
            pathname: '/environmental-health/household-water-supply',
            search: `?household=${householdNo}`,
            assign(url) {
                win._assigned = url;
            },
        },
    };

    globalThis.document = doc;
    globalThis.window = win;
    globalThis.sessionStorage = session;
    globalThis.navigator = win.navigator;

    return {
        doc,
        win,
        page,
        root,
        form,
        badgeText,
        badge,
        nextBtn,
        specifyWrap,
        specifyInput,
        levels: Object.fromEntries(levels.map((row) => [row.input.value, row.input])),
        locationNo: locationNo.input,
        locationYes: locationYes.input,
        availabilityNo: availabilityNo.input,
        availabilityYes: availabilityYes.input,
    };
}

function selectRadio(groupInputs, chosen) {
    groupInputs.forEach((input) => {
        input.checked = input === chosen;
    });
    chosen.dispatchEvent(changeEvent(chosen));
}

function clickCard(input) {
    const card = input.closest?.('label') || input.parentNode || input;
    const painted = card.querySelector?.('span') || card;
    if (typeof painted.click === 'function') {
        painted.click();
        return;
    }
    painted.dispatchEvent({
        type: 'click',
        target: painted,
        bubbles: true,
        defaultPrevented: false,
        cancelBubble: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopPropagation() {
            this.cancelBubble = true;
        },
    });
}

async function browserStep1Sequence(fixture, snapshot = null) {
    bootHouseholdWaterSupply(fixture.doc);
    if (snapshot) {
        fillEhFormFromSnapshot(fixture.root, snapshot);
    }
    await bootEhLive();
}

function productionControllerWouldBoot(html) {
    return /<script[^>]+src="\/build\/assets\/app[^"]*\.js"/.test(String(html || ''))
        && /\sdata-hws-step="1"/.test(String(html || ''))
        && /data-hws-specify/.test(String(html || ''))
        && /data-hws-level/.test(String(html || ''))
        && /data-hws-form/.test(String(html || ''));
}

function liveDocumentWithAppBundle() {
    const doc = createDocument();
    const html = doc.createElement('html');
    const head = doc.createElement('head');
    const script = doc.createElement('script');
    script.setAttribute('type', 'module');
    script.setAttribute('src', '/build/assets/app-BNw2EkZH.js');
    script.src = '/build/assets/app-BNw2EkZH.js';
    const css = doc.createElement('link');
    css.setAttribute('rel', 'stylesheet');
    css.setAttribute('href', '/build/assets/app-CKtgAao_.css');
    css.href = '/build/assets/app-CKtgAao_.css';
    head.appendChild(css);
    head.appendChild(script);
    html.appendChild(head);
    doc.documentElement = html;
    return doc;
}

async function cachedEhHtml(caches, householdNo, step) {
    const href = step <= 1
        ? `/environmental-health/household-water-supply?household=${householdNo}`
        : `/environmental-health/household-water-supply/${householdNo}/step-${step}`;
    const parsed = new URL(href, 'http://localhost');
    const cached = await caches.match(
        new Request(navigationCacheUrl('http://localhost', parsed.pathname, parsed.search)),
        { cacheName: htmlCacheNameForActor(7) },
    );
    return cached ? cached.text() : '';
}

describe('offline EH Step 1 production init after hydration', () => {
    it('keeps production Step 1 as the status/validation source of truth', () => {
        assert.match(hwsSource, /export function initHouseholdWaterSupply/);
        assert.match(hwsSource, /export function refreshHouseholdWaterSupplyState/);
        assert.match(hwsSource, /export function bootHouseholdWaterSupply/);
        assert.match(hwsSource, /boundHwsRoots/);
        assert.match(hwsSource, /removeAttribute\('data-hws-bound'\)/);
        assert.doesNotMatch(hwsSource, /setAttribute\('data-hws-bound'/);
        assert.match(hwsSource, /function readStep1State/);
        assert.match(hwsSource, /\$\{selector\}:checked/);
        assert.match(hwsSource, /onStep1Interact/);
        assert.match(hwsSource, /liveBadgeText/);
        assert.match(hwsSource, /removeAttribute\('disabled'\)/);
        assert.match(bladeSource, /data-hws-next/);
        assert.match(bladeSource, /disabled/);
        assert.match(bladeSource, /Not yet determined|basicSafeLabel/);
        assert.match(hydrateSource, /fillEhFormFromSnapshot\(ehRoot, household\)/);
        assert.match(hydrateSource, /initHouseholdWaterSupply\(/);
        const fillIdx = hydrateSource.indexOf('fillEhFormFromSnapshot(ehRoot, household)');
        const initIdx = hydrateSource.indexOf('initHouseholdWaterSupply(');
        assert.ok(fillIdx >= 0 && initIdx > fillIdx);
        assert.doesNotMatch(hydrateSource, /With Basic Safe Water/);
        assert.doesNotMatch(hydrateSource, /nextBtn\.disabled = false/);
        assert.match(clientSource, /handleEnvironmentalStepQueued/);
        assert.equal(isCanonicalEhShellHtml(canonicalEhShellHtml(1, '537'), 1), true);
        assert.equal(isPlainEhFallbackHtml(plainEhFallbackHtml('537', 1)), true);
    });

    it('selecting Level I after production init + hydration updates status and enables Next', async () => {
        const fixture = mountStep1('537');
        await putHouseholdSnapshot(7, { household_no: '537', water: {}, sanitation: {}, local: true });
        await browserStep1Sequence(fixture);

        assert.equal(fixture.badgeText.textContent, 'Not yet determined');
        assert.equal(fixture.nextBtn.disabled, true);

        selectRadio(Object.values(fixture.levels), fixture.levels.level_i);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.badge.classList.contains('is-with'), true);
        assert.equal(fixture.nextBtn.disabled, true);

        selectRadio([fixture.locationNo], fixture.locationNo);
        selectRadio([fixture.availabilityNo], fixture.availabilityNo);
        assert.equal(fixture.nextBtn.disabled, false);
        assert.equal(fixture.nextBtn.getAttribute('aria-disabled'), 'false');
    });

    it('Level II, Level III, and Others use the same production status rules', async () => {
        for (const [value, expected] of [
            ['level_ii', 'With Basic Safe Water'],
            ['level_iii', 'With Basic Safe Water'],
            ['others', 'Without Basic Safe Water'],
        ]) {
            const fixture = mountStep1('537');
            await browserStep1Sequence(fixture);
            selectRadio(Object.values(fixture.levels), fixture.levels[value]);
            assert.equal(fixture.badgeText.textContent, expected, value);
            if (value === 'others') {
                assert.equal(fixture.specifyWrap.hidden, false);
                fixture.specifyInput.value = 'Open dug well';
                fixture.specifyInput.dispatchEvent({
                    type: 'input',
                    target: fixture.specifyInput,
                    bubbles: true,
                    preventDefault() {},
                    stopPropagation() {},
                });
            }
            selectRadio([fixture.locationNo], fixture.locationNo);
            selectRadio([fixture.availabilityNo], fixture.availabilityNo);
            assert.equal(fixture.nextBtn.disabled, false, value);
        }
    });

    it('restoring a saved Level I from IndexedDB refreshes production status without a click', async () => {
        const fixture = mountStep1('537');
        initHouseholdWaterSupply(fixture.root);
        await putHouseholdSnapshot(7, {
            household_no: '537',
            water: { status: 'level_i', location: 'no', availability: 'no' },
            sanitation: {},
            local: true,
        });
        fillEhFormFromSnapshot(fixture.root, await getHouseholdSnapshot(7, '537'));
        initHouseholdWaterSupply(fixture.root);
        assert.equal(fixture.levels.level_i.checked, true);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.nextBtn.disabled, false);
    });

    it('calling init twice does not duplicate Level change listeners', async () => {
        const fixture = mountStep1('537');
        initHouseholdWaterSupply(fixture.root);
        initHouseholdWaterSupply(fixture.root);
        const listeners = fixture.root._listeners.get('change') || [];
        assert.equal(listeners.length, 1);
        assert.equal((fixture.root._listeners.get('click') || []).length, 1);
        assert.equal(fixture.root.getAttribute('data-hws-bound'), null);
        assert.equal(typeof fixture.root._hwsRefresh, 'function');
        assert.equal(refreshHouseholdWaterSupplyState(fixture.root), true);
    });

    it('cached poisoned Step 1 HTML still binds production listeners for household 538', async () => {
        const fixture = mountStep1('538');
        fixture.root.setAttribute('data-hws-bound', '1');
        assert.equal(fixture.doc.querySelectorAll('[data-lml-hws]').length, 1);
        assert.equal(fixture.doc.querySelectorAll('[data-hws-level]').length, 4);
        assert.equal(fixture.root.getAttribute('data-hws-bound'), '1');
        assert.equal(typeof fixture.root._hwsRefresh, 'undefined');
        assert.equal(fixture.nextBtn.disabled, true);
        assert.equal(fixture.badgeText.textContent, 'Not yet determined');

        await putHouseholdSnapshot(7, { household_no: '538', water: {}, sanitation: {}, local: true });
        for (const step of [1, 2, 3, 4]) {
            await putMeta(7, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
        }
        await putMeta(7, 'shell:eh-household_no', 'LML-EH');

        bootHouseholdWaterSupply(fixture.doc);
        assert.equal(fixture.root.getAttribute('data-hws-bound'), null);
        assert.equal(typeof fixture.root._hwsRefresh, 'function');
        assert.equal((fixture.root._listeners.get('change') || []).length, 1);
        assert.equal((fixture.root._listeners.get('input') || []).length, 1);
        assert.equal((fixture.locationNo._listeners.get('change') || []).length, 0);
        assert.equal((fixture.availabilityNo._listeners.get('change') || []).length, 0);

        await bootEhLive();
        assert.equal(fixture.doc.querySelectorAll('[data-lml-hws]').length, 1);
        assert.equal((fixture.root._listeners.get('change') || []).length, 1);
        assert.equal(fixture.badgeText.textContent, 'Not yet determined');
        assert.equal(fixture.nextBtn.disabled, true);

        selectRadio(Object.values(fixture.levels), fixture.levels.level_ii);
        assert.equal(fixture.levels.level_ii.checked, true);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.nextBtn.disabled, true);

        selectRadio([fixture.locationNo], fixture.locationNo);
        selectRadio([fixture.availabilityNo], fixture.availabilityNo);
        assert.equal(fixture.nextBtn.disabled, false);
        assert.equal(fixture.nextBtn.getAttribute('aria-disabled'), 'false');

        const nativePosts = [];
        const originalFetch = globalThis.fetch;
        globalThis.fetch = async (...args) => {
            nativePosts.push(args);
            throw new Error('native-post-should-not-run');
        };

        const event = submitEvent(fixture.form);
        fixture.form.dispatchEvent(event);
        await handleSupportedFormSubmit(event, {
            window: fixture.win,
            root: fixture.page,
            navigator: { onLine: false },
        });
        globalThis.fetch = originalFetch;

        assert.equal(event.defaultPrevented, true);
        assert.equal(nativePosts.length, 0);

        const ops = await listOperations();
        const ehOps = ops.filter((row) => row.operation_type === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE);
        assert.equal(ehOps.length, 1);
        assert.equal(ehOps[0].payload._eh_step, 1);
        assert.equal(ehOps[0].payload.household_no, '538');
        assert.equal(ehOps[0].payload.water_supply_status, 'level_ii');
        assert.equal(ehOps[0].payload.water_source_location, 'no');
        assert.equal(ehOps[0].payload.water_availability, 'no');

        const saved = fixture.win._events.find((row) => row.type === 'lmlinga:offline-saved');
        assert.ok(saved);
        const caches = createMemoryCaches();
        const continued = await handleEnvironmentalStepQueued(saved.detail, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            window: fixture.win,
        });
        assert.equal(continued.ok, true);
        assert.equal(continued.path, '/environmental-health/household-water-supply/538/step-2');
        assert.equal(fixture.win._assigned, '/environmental-health/household-water-supply/538/step-2');
    });

    it('household 539 Level I + location No + availability Yes enables Next via production boot', async () => {
        const fixture = mountStep1('539');
        assert.equal(fixture.levels.level_i.getAttribute('value'), 'level_i');
        assert.equal(fixture.locationNo.getAttribute('value'), 'no');
        assert.equal(fixture.availabilityYes.getAttribute('value'), 'yes');
        assert.equal(fixture.doc.querySelectorAll('[data-lml-hws]').length, 1);
        assert.equal(fixture.doc.querySelectorAll('[data-hws-form]').length, 1);
        assert.equal(fixture.nextBtn.disabled, true);
        assert.equal(fixture.badgeText.textContent, 'Not yet determined');

        await putHouseholdSnapshot(7, { household_no: '539', water: {}, sanitation: {}, local: true });
        for (const step of [1, 2, 3, 4]) {
            await putMeta(7, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
        }
        await putMeta(7, 'shell:eh-household_no', 'LML-EH');

        bootHouseholdWaterSupply(fixture.doc);
        await bootEhLive();

        selectRadio(Object.values(fixture.levels), fixture.levels.level_i);
        assert.equal(fixture.levels.level_i.checked, true);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.nextBtn.disabled, true);

        selectRadio([fixture.locationYes, fixture.locationNo], fixture.locationNo);
        selectRadio([fixture.availabilityYes, fixture.availabilityNo], fixture.availabilityYes);
        assert.equal(fixture.locationNo.checked, true);
        assert.equal(fixture.availabilityYes.checked, true);
        assert.equal(fixture.nextBtn.disabled, false);
        assert.equal(fixture.nextBtn.getAttribute('disabled'), null);
        assert.equal(fixture.nextBtn.getAttribute('aria-disabled'), 'false');

        const nativePosts = [];
        const originalFetch = globalThis.fetch;
        globalThis.fetch = async (...args) => {
            nativePosts.push(args);
            throw new Error('native-post-should-not-run');
        };
        const event = submitEvent(fixture.form);
        fixture.form.dispatchEvent(event);
        await handleSupportedFormSubmit(event, {
            window: fixture.win,
            root: fixture.page,
            navigator: { onLine: false },
        });
        globalThis.fetch = originalFetch;

        const ops = await listOperations();
        const ehOps = ops.filter((row) => row.operation_type === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE);
        assert.equal(ehOps.length, 1);
        assert.equal(ehOps[0].payload.household_no, '539');
        assert.equal(ehOps[0].payload.water_supply_status, 'level_i');
        assert.equal(ehOps[0].payload.water_source_location, 'no');
        assert.equal(ehOps[0].payload.water_availability, 'yes');

        const saved = fixture.win._events.find((row) => row.type === 'lmlinga:offline-saved');
        const continued = await handleEnvironmentalStepQueued(saved.detail, {
            actorId: 7,
            caches: createMemoryCaches(),
            origin: 'http://localhost',
            window: fixture.win,
        });
        assert.equal(continued.ok, true);
        assert.equal(continued.path, '/environmental-health/household-water-supply/539/step-2');
        assert.equal(nativePosts.length, 0);
    });

    it('incomplete Step 1 keeps Next disabled until location and availability are chosen', async () => {
        const fixture = mountStep1('539');
        bootHouseholdWaterSupply(fixture.doc);
        await bootEhLive();
        selectRadio(Object.values(fixture.levels), fixture.levels.level_ii);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.nextBtn.disabled, true);
        selectRadio([fixture.locationNo], fixture.locationNo);
        assert.equal(fixture.nextBtn.disabled, true);
        selectRadio([fixture.availabilityYes], fixture.availabilityYes);
        assert.equal(fixture.nextBtn.disabled, false);
    });

    it('household 600 plot-cached Step 1 boots production controller after poisoned shell repair', async () => {
        const poisoned = poisonedLaravelEhStep1Html('600');
        assert.equal(looksLikeEhWizardUi(poisoned, 1), true);
        assert.equal(isCanonicalEhShellHtml(poisoned, 1), false);
        assert.equal(productionControllerWouldBoot(poisoned), false);
        assert.match(poisoned, /data-hws-specify/);
        assert.match(poisoned, /data-hws-specify-input/);
        assert.match(poisoned, /data-hws-step="600"/);
        assert.match(poisoned, /60027\.0\.0\.600/);

        const dead = mountStep1('600');
        clickCard(dead.levels.level_ii);
        assert.equal(dead.badgeText.textContent, 'Not yet determined');
        assert.equal(dead.specifyWrap.hidden, true);
        assert.equal(dead.nextBtn.disabled, true);

        await putHouseholdSnapshot(7, { household_no: '600', water: {}, sanitation: {}, local: true });
        await putMeta(7, 'shell:eh-step-1', poisoned);
        for (const step of [2, 3, 4]) {
            await putMeta(7, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
        }
        await putMeta(7, 'shell:eh-household_no', '1');

        const caches = createMemoryCaches();
        const cached = await cacheEhWizardPages(7, { household_no: '600' }, {
            caches,
            origin: 'http://localhost',
            document: liveDocumentWithAppBundle(),
        });
        assert.equal(cached, true);
        const html = await cachedEhHtml(caches, '600', 1);
        assert.match(html, /data-hws-step="1"/);
        assert.doesNotMatch(html, /data-hws-step="600"/);
        assert.match(html, /src="\/build\/assets\/app-BNw2EkZH\.js"/);
        assert.doesNotMatch(html, /60027\.0\.0\.600/);
        assert.match(html, /data-hws-specify/);
        assert.match(html, /data-hws-specify-input/);
        assert.match(html, /data-hws-level/);
        assert.match(html, /data-hws-location/);
        assert.match(html, /data-hws-availability/);
        assert.match(html, /data-hws-safe-water-badge/);
        assert.match(html, /data-hws-next/);
        assert.equal(productionControllerWouldBoot(html), true);

        const fixture = mountStep1('600');
        if (productionControllerWouldBoot(html)) {
            bootHouseholdWaterSupply(fixture.doc);
            await bootEhLive();
        }

        clickCard(fixture.levels.level_ii);
        assert.equal(fixture.levels.level_ii.checked, true);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.specifyWrap.hidden, true);

        clickCard(fixture.levels.others);
        assert.equal(fixture.levels.others.checked, true);
        assert.equal(fixture.specifyWrap.hidden, false);
        assert.equal(fixture.badgeText.textContent, 'Without Basic Safe Water');
        clickCard(fixture.locationNo);
        clickCard(fixture.availabilityYes);
        assert.equal(fixture.nextBtn.disabled, true);

        fixture.specifyInput.value = 'Open dug well';
        fixture.specifyInput.dispatchEvent({
            type: 'input',
            target: fixture.specifyInput,
            bubbles: true,
            preventDefault() {},
            stopPropagation() {},
        });
        assert.equal(fixture.nextBtn.disabled, false);

        clickCard(fixture.levels.level_iii);
        assert.equal(fixture.levels.level_iii.checked, true);
        assert.equal(fixture.specifyWrap.hidden, true);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        clickCard(fixture.locationNo);
        clickCard(fixture.availabilityYes);
        assert.equal(fixture.nextBtn.disabled, false);
        assert.equal(fixture.nextBtn.getAttribute('disabled'), null);
        assert.equal(fixture.nextBtn.getAttribute('aria-disabled'), 'false');

        const nativePosts = [];
        const originalFetch = globalThis.fetch;
        globalThis.fetch = async (...args) => {
            nativePosts.push(args);
            throw new Error('native-post-should-not-run');
        };
        const event = submitEvent(fixture.form);
        fixture.form.dispatchEvent(event);
        await handleSupportedFormSubmit(event, {
            window: fixture.win,
            root: fixture.page,
            navigator: { onLine: false },
        });
        globalThis.fetch = originalFetch;

        const ops = await listOperations();
        const ehOps = ops.filter((row) => row.operation_type === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE);
        assert.equal(ehOps.length, 1);
        assert.equal(ehOps[0].payload.household_no, '600');
        assert.equal(ehOps[0].payload.water_supply_status, 'level_iii');
        assert.equal(nativePosts.length, 0);

        const saved = fixture.win._events.find((row) => row.type === 'lmlinga:offline-saved');
        const continued = await handleEnvironmentalStepQueued(saved.detail, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            window: fixture.win,
        });
        assert.equal(continued.ok, true);
        assert.equal(continued.path, '/environmental-health/household-water-supply/600/step-2');
        assert.equal(fixture.win._assigned, '/environmental-health/household-water-supply/600/step-2');
    });

    it('household 111 Level III card click updates status and enables Next via production boot', async () => {
        const fixture = mountStep1('111');
        assert.equal(fixture.levels.level_iii.getAttribute('value'), 'level_iii');
        assert.equal(fixture.locationNo.getAttribute('value'), 'no');
        assert.equal(fixture.availabilityYes.getAttribute('value'), 'yes');
        assert.equal(fixture.doc.querySelectorAll('[data-lml-hws]').length, 1);
        assert.equal(fixture.doc.querySelectorAll('[data-hws-form]').length, 1);
        assert.equal(fixture.nextBtn.disabled, true);
        assert.equal(fixture.nextBtn.hasAttribute('disabled'), true);
        assert.equal(fixture.badgeText.textContent, 'Not yet determined');

        await putHouseholdSnapshot(7, { household_no: '111', water: {}, sanitation: {}, local: true });
        for (const step of [1, 2, 3, 4]) {
            await putMeta(7, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
        }
        await putMeta(7, 'shell:eh-household_no', '1');

        bootHouseholdWaterSupply(fixture.doc);
        await bootEhLive();

        clickCard(fixture.levels.level_iii);
        assert.equal(fixture.levels.level_iii.checked, true);
        assert.equal(readStep1State(fixture.root).level, 'level_iii');
        assert.equal(fixture.doc.querySelector('[data-hws-level]:checked')?.value, 'level_iii');
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        assert.equal(fixture.badge.classList.contains('is-with'), true);
        assert.equal(fixture.nextBtn.disabled, true);

        clickCard(fixture.locationNo);
        clickCard(fixture.availabilityYes);
        assert.equal(fixture.locationNo.checked, true);
        assert.equal(fixture.availabilityYes.checked, true);
        assert.equal(readStep1State(fixture.root).location, 'no');
        assert.equal(readStep1State(fixture.root).availability, 'yes');
        assert.equal(fixture.nextBtn.disabled, false);
        assert.equal(fixture.nextBtn.getAttribute('disabled'), null);
        assert.equal(fixture.nextBtn.hasAttribute('disabled'), false);
        assert.equal(fixture.nextBtn.getAttribute('aria-disabled'), 'false');

        const nativePosts = [];
        const originalFetch = globalThis.fetch;
        globalThis.fetch = async (...args) => {
            nativePosts.push(args);
            throw new Error('native-post-should-not-run');
        };
        const event = submitEvent(fixture.form);
        fixture.form.dispatchEvent(event);
        await handleSupportedFormSubmit(event, {
            window: fixture.win,
            root: fixture.page,
            navigator: { onLine: false },
        });
        globalThis.fetch = originalFetch;

        assert.equal(event.defaultPrevented, true);
        assert.equal(nativePosts.length, 0);

        const ops = await listOperations();
        const ehOps = ops.filter((row) => row.operation_type === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE);
        assert.equal(ehOps.length, 1);
        assert.equal(ehOps[0].payload._eh_step, 1);
        assert.equal(ehOps[0].payload.household_no, '111');
        assert.equal(ehOps[0].payload.water_supply_status, 'level_iii');
        assert.equal(ehOps[0].payload.water_source_location, 'no');
        assert.equal(ehOps[0].payload.water_availability, 'yes');

        const saved = fixture.win._events.find((row) => row.type === 'lmlinga:offline-saved');
        assert.ok(saved);
        const continued = await handleEnvironmentalStepQueued(saved.detail, {
            actorId: 7,
            caches: createMemoryCaches(),
            origin: 'http://localhost',
            window: fixture.win,
        });
        assert.equal(continued.ok, true);
        assert.equal(continued.path, '/environmental-health/household-water-supply/111/step-2');
        assert.equal(fixture.win._assigned, '/environmental-health/household-water-supply/111/step-2');
    });

    it('rewriting a household-1 shell for household 111 does not corrupt Step 1 values', () => {
        const shell = canonicalEhShellHtml(1, '1');
        const html = rewriteEhHouseholdHtml(shell, '1', { household_no: '111' });
        assert.match(html, /data-hws-step="1"/);
        assert.doesNotMatch(html, /data-hws-step="111"/);
        assert.match(html, /value="level_i"/);
        assert.match(html, /value="level_ii"/);
        assert.match(html, /value="level_iii"/);
        assert.match(html, /value="others"/);
        assert.match(html, /data-household-no="111"/);
        assert.match(html, /src="\/build\/assets\/app\.js"/);
    });

    it('online Step 1 still recalculates status from production listeners', async () => {
        const fixture = mountStep1('538');
        fixture.win.navigator.onLine = true;
        globalThis.navigator = fixture.win.navigator;
        bootHouseholdWaterSupply(fixture.doc);

        assert.equal(fixture.badgeText.textContent, 'Not yet determined');
        assert.equal(fixture.nextBtn.disabled, true);

        selectRadio(Object.values(fixture.levels), fixture.levels.level_ii);
        assert.equal(fixture.badgeText.textContent, 'With Basic Safe Water');
        selectRadio([fixture.locationNo], fixture.locationNo);
        selectRadio([fixture.availabilityNo], fixture.availabilityNo);
        assert.equal(fixture.nextBtn.disabled, false);
        assert.equal(
            shouldQueueSupportedForm(fixture.form, { navigator: { onLine: true }, window: {} }),
            false,
        );
    });

    it('queues one Step 1 operation offline and does not native POST', async () => {
        const fixture = mountStep1('537');
        await putHouseholdSnapshot(7, { household_no: '537', water: {}, sanitation: {}, local: true });
        for (const step of [1, 2, 3, 4]) {
            await putMeta(7, `shell:eh-step-${step}`, canonicalEhShellHtml(step, 'LML-EH'));
        }
        await putMeta(7, 'shell:eh-household_no', 'LML-EH');
        await browserStep1Sequence(fixture, await getHouseholdSnapshot(7, '537'));

        selectRadio(Object.values(fixture.levels), fixture.levels.level_i);
        selectRadio([fixture.locationNo], fixture.locationNo);
        selectRadio([fixture.availabilityNo], fixture.availabilityNo);
        assert.equal(fixture.nextBtn.disabled, false);

        const nativePosts = [];
        const originalFetch = globalThis.fetch;
        globalThis.fetch = async (...args) => {
            nativePosts.push(args);
            throw new Error('native-post-should-not-run');
        };

        const event = submitEvent(fixture.form);
        fixture.form.dispatchEvent(event);
        await handleSupportedFormSubmit(event, {
            window: fixture.win,
            root: fixture.page,
            navigator: { onLine: false },
        });
        globalThis.fetch = originalFetch;

        assert.equal(event.defaultPrevented, true);
        assert.equal(nativePosts.length, 0);
        assert.equal(shouldQueueSupportedForm(fixture.form, { navigator: { onLine: true }, window: {} }), false);

        const ops = await listOperations();
        const ehOps = ops.filter((row) => row.operation_type === OPERATION_TYPES.ENVIRONMENTAL_WATER_SUPPLY_UPDATE);
        assert.equal(ehOps.length, 1);
        assert.equal(ehOps[0].payload._eh_step, 1);
        assert.equal(ehOps[0].payload.household_no, '537');
        assert.equal(ehOps[0].payload.water_supply_status, 'level_i');
        assert.equal(ehOps[0].payload.water_source_location, 'no');
        assert.equal(ehOps[0].payload.water_availability, 'no');
        assert.equal(ehOps[0].parent_server.household_no, '537');
        assert.equal(ehOps[0].parent_server.household_id, undefined);
        assert.equal(ops.filter((row) => row.operation_type === 'RESIDENT_CREATE').length, 0);

        const saved = fixture.win._events.find((row) => row.type === 'lmlinga:offline-saved');
        assert.ok(saved);
        const caches = createMemoryCaches();
        const continued = await handleEnvironmentalStepQueued(saved.detail, {
            actorId: 7,
            caches,
            origin: 'http://localhost',
            navigate: false,
            window: fixture.win,
        });
        assert.equal(continued.ok, true);
        assert.equal(continued.path, '/environmental-health/household-water-supply/537/step-2');
    });
});
