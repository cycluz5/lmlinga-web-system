/**
 * Spot Mapping offline view (Option 3, Phase 1): neutral background + notice when offline or when map imagery
 * fails; boundary and cached plotted households stay; plotting stays online-only; Demo Mode pages are never
 * stored in the offline cache. No real model, network or database is used.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createMemoryCaches } from './support/fake-caches.mjs';

const viewUrl = pathToFileURL(path.resolve('resources/js/pages/spot-mapping-offline-view.js')).href;
const {
    IMAGERY_MISSING_CLASS,
    OFFLINE_CLASS,
    TILES_UNAVAILABLE_MESSAGE,
    applyOfflineView,
    formatAsOf,
    offlineViewState,
} = await import(viewUrl);

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const { CACHE_VERSION, htmlCacheNameForActor, htmlHasDemoLocation, navigationCacheUrl } = await import(policyUrl);

const runtimeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-runtime.js')).href;
const { createServiceWorkerRuntime } = await import(runtimeUrl);

const RENDERED_AT = '2026-10-31T08:15:00+08:00';

function fakeRoot() {
    const classes = new Set();
    const notice = { textContent: '', hidden: true };
    return {
        classes,
        notice,
        classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) },
        querySelector: (selector) => (selector === '[data-spot-map-offline-view-notice]' ? notice : null),
    };
}

describe('offline view state', () => {
    it('online with imagery: no notice, normal background', () => {
        assert.deepEqual(offlineViewState({ offline: false, imageryFailed: false, renderedAt: RENDERED_AT }), {
            showNotice: false, offline: false, imageryMissing: false, message: '',
        });
    });

    it('online but imagery failed: neutral background and an honest tile message', () => {
        const state = offlineViewState({ offline: false, imageryFailed: true });
        assert.equal(state.showNotice, true);
        assert.equal(state.imageryMissing, true);
        assert.equal(state.offline, false);
        assert.equal(state.message, TILES_UNAVAILABLE_MESSAGE);
        // Online: only the map pictures fail. Say so, and do not claim a connection problem or blocked plotting.
        assert.match(state.message, /^Map pictures are not loading right now\./);
        assert.match(state.message, /You are still connected/);
        assert.doesNotMatch(state.message, /offline|needs a connection|Plotting is disabled/i);
    });

    it('offline: boundary + households as of the cached time, plotting disabled', () => {
        const state = offlineViewState({ offline: true, renderedAt: RENDERED_AT });
        assert.equal(state.showNotice, true);
        assert.equal(state.offline, true);
        assert.equal(state.imageryMissing, true);
        assert.match(state.message, /^You are offline\. Map pictures need internet, and the offline map is not saved on this device yet\./);
        assert.match(state.message, /indicative La Medalla boundary/);
        assert.match(state.message, /as of .*2026/);
        assert.match(state.message, /Plotting is disabled until you are online\./);
    });

    it('offline without a valid time falls back to plain wording', () => {
        for (const renderedAt of [null, '', 'not-a-date']) {
            assert.match(offlineViewState({ offline: true, renderedAt }).message, /when this page was last loaded online/);
        }
        assert.equal(formatAsOf('not-a-date'), '');
    });

    it('never suggests offline plotting', () => {
        for (const input of [{ offline: true, renderedAt: RENDERED_AT }, { offline: false, imageryFailed: true }]) {
            assert.doesNotMatch(offlineViewState(input).message, /can still plot|you can plot/i);
        }
    });

    it('applies classes and notice to the page, and clears them when back online', () => {
        const root = fakeRoot();
        applyOfflineView(root, offlineViewState({ offline: true, renderedAt: RENDERED_AT }));
        assert.ok(root.classes.has(OFFLINE_CLASS));
        assert.ok(root.classes.has(IMAGERY_MISSING_CLASS));
        assert.equal(root.notice.hidden, false);
        assert.match(root.notice.textContent, /^You are offline\./);

        applyOfflineView(root, offlineViewState({ offline: false, imageryFailed: false }));
        assert.equal(root.classes.size, 0);
        assert.equal(root.notice.hidden, true);
        assert.equal(root.notice.textContent, '');
    });
});

describe('Demo Mode pages are never stored offline', () => {
    const ORIGIN = 'https://lmlinga.test';
    const ACTOR = 7;
    const navRequest = (url) => ({
        url,
        method: 'GET',
        mode: 'navigate',
        headers: { get: (name) => (String(name).toLowerCase() === 'accept' ? 'text/html' : null) },
    });
    const page = (body) => new Response(
        `<html><head><meta name="csrf-token" content="secret-token"></head><body>${body}</body></html>`,
        { status: 200, headers: { 'Content-Type': 'text/html' } },
    );

    it('detects the demo payload and banner markers only', () => {
        assert.equal(htmlHasDemoLocation('<div data-lml-spot-map data-demo-location=\'{"key":"x"}\'>'), true);
        assert.equal(htmlHasDemoLocation('<div class="lml-spot-map__demo-banner" data-spot-map-demo-banner>'), true);
        assert.equal(htmlHasDemoLocation('<div data-lml-spot-map data-boundary-state="valid">'), false);
        assert.equal(htmlHasDemoLocation(null), false);
    });

    async function navigateOnce(html) {
        const caches = createMemoryCaches();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches,
            fetch: async () => page(html),
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        await runtime.setActor(ACTOR);
        const served = await runtime.handleFetch(navRequest(`${ORIGIN}/spot-mapping`));
        const cache = await caches.open(htmlCacheNameForActor(ACTOR));
        const stored = await cache.match(new Request(navigationCacheUrl(ORIGIN, '/spot-mapping')));
        return { served, stored };
    }

    it('a Spot Mapping page rendered in Demo Mode is shown online but NOT cached', async () => {
        const { served, stored } = await navigateOnce('<div data-lml-spot-map data-demo-location=\'{"key":"test-point"}\'></div><div data-spot-map-demo-banner></div>');
        assert.equal(served.status, 200);
        assert.equal(stored, undefined);
    });

    it('a normal Spot Mapping page is still cached per user, with CSRF stripped', async () => {
        const { stored } = await navigateOnce('<div data-lml-spot-map data-boundary-state="valid" data-markers="[]"></div>');
        assert.ok(stored, 'normal page cached');
        const html = await stored.text();
        assert.match(html, /data-boundary-state="valid"/);
        assert.doesNotMatch(html, /secret-token/);
    });

    it('cache version bumped so any previously cached page is replaced', () => {
        assert.equal(CACHE_VERSION, 'offline-7-v26');
        assert.match(htmlCacheNameForActor(ACTOR), /offline-7-v26-actor-7$/);
    });
});

describe('page wiring stays display-only', () => {
    const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');

    it('offline view block never plots, saves, queues or moves the map', () => {
        const start = src.indexOf('// Offline view: neutral background + notice');
        const end = src.indexOf('if (tileOfflineNoted || locating)', start);
        assert.ok(start > 0 && end > start, 'offline view block found');
        const block = src.slice(start, end);
        for (const forbidden of ['openPanel', 'fetch(', 'placeTemporaryMarker', 'startGpsPlot', 'flyTo', 'setView', 'queue', 'localStorage', 'indexedDB']) {
            assert.equal(block.includes(forbidden), false, forbidden);
        }
    });

    it('the misleading "you can still plot" tile message is gone', () => {
        assert.doesNotMatch(src, /you can still plot/i);
        assert.match(src, /const TILES_OFFLINE_MESSAGE = MAP_OFFLINE_NOTE;/);
    });
});

describe('offline view notice does not collide with the existing offline toast', () => {
    const view = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');
    const moduleSrc = readFileSync(path.resolve('resources/js/pages/spot-mapping-offline-view.js'), 'utf8');

    it('the view has exactly one new notice and keeps the existing toast separate', () => {
        assert.equal((view.match(/data-spot-map-offline-view-notice/g) || []).length, 1);
        assert.equal((view.match(/data-spot-map-offline-notice(?!-)/g) || []).length, 1, 'existing plot-offline toast unchanged');
    });

    it('the module only targets its own notice element', () => {
        assert.match(moduleSrc, /\[data-spot-map-offline-view-notice\]/);
        assert.doesNotMatch(moduleSrc, /\[data-spot-map-offline-notice\]/);
    });
});
