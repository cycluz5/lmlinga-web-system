/**
 * Spot Mapping saved offline map (Phase 2B): shipped map package, verified preparation, service worker serving,
 * logout behaviour, layer choice and offline wording. No real network, database or browser is used.
 */

import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { createMemoryCaches } from './support/fake-caches.mjs';

const load = (file) => import(pathToFileURL(path.resolve(file)).href);
const policy = await load('resources/js/offline/offline-sw-policy.js');
const { createServiceWorkerRuntime } = await load('resources/js/offline/offline-sw-runtime.js');
const savedMap = await load('resources/js/maps/la-medalla-offline-map.js');
const view = await load('resources/js/pages/spot-mapping-offline-view.js');

const ORIGIN = 'https://lmlinga.test';
const MAP_DIR = path.resolve('public/maps/la-medalla/v1');
const manifest = JSON.parse(readFileSync(path.join(MAP_DIR, 'manifest.json'), 'utf8'));
const tileBytes = (p) => readFileSync(path.join(MAP_DIR, p));

/** Fake same-origin server backed by the real shipped map files. */
function mapServer({ corrupt = null, failAt = null } = {}) {
    let calls = 0;
    return async (url) => {
        calls += 1;
        const pathname = new URL(String(url?.url || url)).pathname;
        if (!pathname.startsWith(policy.OFFLINE_MAP_PATH_PREFIX)) {
            return new Response('nope', { status: 404 });
        }
        const rel = pathname.slice(policy.OFFLINE_MAP_PATH_PREFIX.length);
        if (failAt !== null && calls === failAt) {
            throw new TypeError('Failed to fetch');
        }
        if (!existsSync(path.join(MAP_DIR, rel))) {
            return new Response('', { status: 404 });
        }
        let body = tileBytes(rel);
        if (rel === corrupt) {
            body = Buffer.concat([body, Buffer.from([0])]);
        }
        return new Response(body, { status: 200 });
    };
}

const env = (extra = {}) => ({
    caches: createMemoryCaches(),
    fetch: mapServer(),
    origin: ORIGIN,
    subtle: globalThis.crypto.subtle,
    navigator: { onLine: true },
    ...extra,
});

describe('shipped map package', () => {
    it('manifest is valid, complete and attributed', () => {
        assert.equal(savedMap.isValidOfflineMapManifest(manifest), true);
        assert.equal(manifest.tileCount, 41);
        assert.deepEqual(manifest.missing, []);
        assert.equal(manifest.minZoom, 10);
        assert.equal(manifest.maxDataZoom, 15);
        assert.deepEqual(manifest.bbox, { west: 123.411, south: 13.352, east: 123.4567, north: 13.3959 });
        assert.match(manifest.license, /ODbL/);
        assert.match(manifest.attribution, /OpenStreetMap contributors/);
        assert.equal(savedMap.offlineMapDataDate(manifest), 'Oct 30, 2026');
    });

    it('every tile exists and matches its checksum; total stays small', () => {
        let total = 0;
        for (const tile of manifest.tiles) {
            const buf = tileBytes(tile.path);
            total += buf.length;
            assert.equal(createHash('sha256').update(buf).digest('hex'), tile.sha256, tile.path);
        }
        assert.equal(total, manifest.totalBytes);
        assert.ok(total < 1024 * 1024, 'under 1 MB');
    });

    it('the attribution notice ships with the tiles', () => {
        const notice = readFileSync(path.resolve('public/maps/la-medalla/ATTRIBUTION.txt'), 'utf8');
        assert.match(notice, /OpenStreetMap contributors/);
        assert.match(notice, /ODbL/);
        assert.match(savedMap.OFFLINE_MAP_ATTRIBUTION, /OpenStreetMap/);
        assert.match(savedMap.OFFLINE_MAP_ATTRIBUTION, /Protomaps/);
    });
});

describe('cache policy', () => {
    it('recognises only map manifest and tile files', () => {
        assert.equal(policy.isOfflineMapPath('/maps/la-medalla/v1/manifest.json'), true);
        assert.equal(policy.isOfflineMapPath('/maps/la-medalla/v1/15/27620/15152.mvt'), true);
        for (const p of ['/maps/la-medalla/v1/../x', '/maps/la-medalla/v0/15/1/1.mvt', '/maps/la-medalla/v1/a/b/c.mvt', '/spot-mapping', '/maps/la-medalla/ATTRIBUTION.txt', null]) {
            assert.equal(policy.isOfflineMapPath(p), false, String(p));
        }
    });

    it('keeps the current map cache on a release and removes old map versions', () => {
        const names = [policy.OFFLINE_MAP_CACHE, 'lmlinga-map-la-medalla-v0', policy.ASSETS_CACHE, 'lmlinga-assets-offline-7-v22'];
        assert.deepEqual(policy.obsoleteOwnedCaches(names).sort(), ['lmlinga-assets-offline-7-v22', 'lmlinga-map-la-medalla-v0']);
    });
});

describe('preparing the saved map', () => {
    it('saves every verified tile, then the manifest; status becomes ready', async () => {
        const e = env();
        const progress = [];
        const result = await savedMap.prepareOfflineMap({ ...e, onProgress: (p) => progress.push(p) });
        assert.equal(result.ok, true);
        assert.equal(result.tiles, 41);
        assert.equal(result.bytes, manifest.totalBytes);
        assert.deepEqual(progress.at(-1), { done: 41, total: 41 });
        const status = await savedMap.readOfflineMapStatus(e);
        assert.equal(status.ready, true);
        assert.equal((await (await e.caches.open(policy.OFFLINE_MAP_CACHE)).keys()).length, 42);
        assert.equal((await savedMap.prepareOfflineMap(e)).reason, 'already-ready');
    });

    it('a corrupted tile leaves nothing behind and is not ready', async () => {
        const e = env({ fetch: mapServer({ corrupt: manifest.tiles[5].path }) });
        const result = await savedMap.prepareOfflineMap(e);
        assert.deepEqual([result.ok, result.reason], [false, 'tile-checksum']);
        assert.equal((await savedMap.readOfflineMapStatus(e)).ready, false);
        assert.equal((await e.caches.keys()).includes(policy.OFFLINE_MAP_CACHE), false);
    });

    it('a connection drop half-way leaves nothing behind', async () => {
        const e = env({ fetch: mapServer({ failAt: 20 }) });
        const result = await savedMap.prepareOfflineMap(e);
        assert.equal(result.ok, false);
        assert.equal((await savedMap.readOfflineMapStatus(e)).ready, false);
        assert.equal((await e.caches.keys()).includes(policy.OFFLINE_MAP_CACHE), false);
    });

    it('does not start offline, and refuses when storage is too small', async () => {
        assert.equal((await savedMap.prepareOfflineMap(env({ navigator: { onLine: false } }))).reason, 'offline');
        const tight = env({ storage: { estimate: async () => ({ usage: 0, quota: manifest.totalBytes }) } });
        assert.equal((await savedMap.prepareOfflineMap(tight)).reason, 'no-space');
        assert.equal((await tight.caches.keys()).includes(policy.OFFLINE_MAP_CACHE), false);
    });

    it('a manifest without every tile saved is not ready', async () => {
        const e = env();
        await savedMap.prepareOfflineMap(e);
        const cache = await e.caches.open(policy.OFFLINE_MAP_CACHE);
        await cache.delete(new Request(`${ORIGIN}${policy.OFFLINE_MAP_PATH_PREFIX}${manifest.tiles[0].path}`));
        assert.deepEqual(await savedMap.readOfflineMapStatus(e), { ready: false, manifest: null, reason: 'incomplete' });
    });
});

describe('service worker', () => {
    async function preparedRuntime({ online = true } = {}) {
        const e = env();
        await savedMap.prepareOfflineMap(e);
        const network = online ? mapServer() : async () => { throw new TypeError('Failed to fetch'); };
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: e.caches,
            fetch: async (req) => (String(req?.method || 'GET').toUpperCase() === 'POST' ? new Response('', { status: 302 }) : network(req)),
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        return { runtime, caches: e.caches };
    }

    it('serves saved tiles offline, and a tile that was never saved as an empty 404 (not the offline page)', async () => {
        const { runtime } = await preparedRuntime({ online: false });
        const hit = await runtime.handleFetch(new Request(`${ORIGIN}${policy.OFFLINE_MAP_PATH_PREFIX}${manifest.tiles[3].path}`));
        assert.equal(hit.status, 200);
        assert.equal(Buffer.from(await hit.arrayBuffer()).length, manifest.tiles[3].bytes);
        const miss = await runtime.handleFetch(new Request(`${ORIGIN}${policy.OFFLINE_MAP_PATH_PREFIX}15/1/1.mvt`));
        assert.equal(miss.status, 404);
        assert.equal(await miss.text(), '');
    });

    it('logout clears private pages but keeps the public saved map', async () => {
        const { runtime, caches } = await preparedRuntime();
        await runtime.setActor(7);
        await (await caches.open(policy.htmlCacheNameForActor(7))).put(new Request(`${ORIGIN}/spot-mapping`), new Response('<html>x</html>'));
        await runtime.handleFetch(new Request(`${ORIGIN}/logout`, { method: 'POST' }));
        const names = await caches.keys();
        assert.equal(names.includes(policy.htmlCacheNameForActor(7)), false);
        assert.equal((await savedMap.readOfflineMapStatus({ caches, origin: ORIGIN })).ready, true);
    });

    it('the worker never stores map files on its own (only verified preparation does)', async () => {
        const caches = createMemoryCaches();
        const runtime = createServiceWorkerRuntime({ origin: ORIGIN, caches, fetch: mapServer(), skipWaiting: async () => {}, clientsClaim: async () => {}, clients: { matchAll: async () => [] } });
        const res = await runtime.handleFetch(new Request(`${ORIGIN}${policy.OFFLINE_MAP_PATH_PREFIX}${manifest.tiles[0].path}`));
        assert.equal(res.status, 200);
        assert.equal((await (await caches.open(policy.OFFLINE_MAP_CACHE)).keys()).length, 0);
    });
});

describe('which base layer is shown', () => {
    it('online imagery first; saved map only when offline or online imagery fails, and only when ready', () => {
        const m = savedMap.baseLayerMode;
        assert.equal(m({ offline: false, onlineImageryFailed: false, savedMapReady: true }), 'online');
        assert.equal(m({ offline: true, savedMapReady: true }), 'saved');
        assert.equal(m({ offline: false, onlineImageryFailed: true, savedMapReady: true }), 'saved');
        assert.equal(m({ offline: true, savedMapReady: false }), 'none');
        assert.equal(m({ offline: false, onlineImageryFailed: true, savedMapReady: false }), 'none');
        assert.equal(savedMap.ONLINE_TILE_ERRORS_BEFORE_FALLBACK, 3);
    });
});

describe('online imagery detection', () => {
    it('listens on the online tile layer (Leaflet does not forward tile events to the map)', () => {
        const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
        assert.ok(src.includes('const imageryEvents = onlineLayer || map;'));
        assert.ok(src.includes("imageryEvents.on('tileerror'"));
        assert.ok(src.includes("imageryEvents.on('tileload'"));
        assert.ok(!src.includes("map.on('tileerror'") && !src.includes("map.on('tileload'"));
    });
});

describe('offline wording with the saved map', () => {
    it('offline: names the saved map, its data date and the households time; plotting stays disabled', () => {
        const state = view.offlineViewState({ offline: true, renderedAt: '2026-10-31T08:15:00+08:00', savedMap: true, savedMapDate: 'Oct 30, 2026' });
        assert.equal(state.savedMap, true);
        assert.equal(state.imageryMissing, false);
        assert.match(state.message, /^Offline map is available\. Showing saved map data \(OpenStreetMap data, Oct 30, 2026\)\./);
        assert.match(state.message, /indicative La Medalla boundary/);
        assert.match(state.message, /as of .*2026/);
        assert.match(state.message, /Plotting is disabled until you are online\./);
    });

    it('online with failing imagery: says the saved map is shown', () => {
        const state = view.offlineViewState({ offline: false, imageryFailed: true, savedMap: true, savedMapDate: 'Oct 30, 2026' });
        assert.equal(state.message, 'Online map imagery is not loading. Offline map is available. Showing saved map data (OpenStreetMap data, Oct 30, 2026). The barangay boundary and plotted households are still shown.');
        assert.equal(state.imageryMissing, false);
    });

    it('"Map pictures are not loading" appears ONLY when online imagery fails and no usable saved map exists', () => {
        const NEEDS = /Map pictures are not loading right now/;
        const cases = [
            [{ offline: false, imageryFailed: false, savedMapUsable: true }, false],
            [{ offline: false, imageryFailed: true, savedMapUsable: true }, false], // saved map about to take over
            [{ offline: false, imageryFailed: true, savedMap: true }, false],
            [{ offline: true, savedMapUsable: true }, false],
            [{ offline: true, savedMap: true }, false],
            [{ offline: false, imageryFailed: true, savedMapUsable: false }, true], // neither available
            [{ offline: true, savedMapUsable: false }, false], // offline: a connection message instead
        ];
        for (const [input, expected] of cases) {
            assert.equal(NEEDS.test(view.offlineViewState(input).message), expected, JSON.stringify(input));
        }
        const pending = view.offlineViewState({ offline: false, imageryFailed: true, savedMapUsable: true });
        assert.deepEqual([pending.showNotice, pending.imageryMissing], [false, false]);
        const offlineUsable = view.offlineViewState({ offline: true, savedMapUsable: true });
        assert.match(offlineUsable.message, /^Offline map is available\. Showing saved map data/);
        assert.equal(offlineUsable.imageryMissing, false);
    });

    it('Spot Mapping writes the old map note only without a usable saved map, and clears it when the saved map shows', () => {
        const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
        const handler = src.slice(src.indexOf("imageryEvents.on('tileerror'"));
        assert.ok(handler.indexOf('if (savedMapUsable())') > -1);
        assert.ok(handler.indexOf('if (savedMapUsable())') < handler.indexOf('note.textContent = TILES_OFFLINE_MESSAGE'));
        assert.match(src, /if \(savedMapShown\) \{\s*clearTilesUnavailableNote\(\);/);
        assert.match(src, /savedMapUsable: savedMapUsable\(\),/);
        // No notice is drawn before the saved-map check, so "imagery unavailable" cannot flash first.
        assert.match(src, /if \(!savedMapChecked\) \{\s*return;/);
        assert.match(src, /savedMapStatus = status;\s*savedMapChecked = true;/);
    });

    it('never suggests offline plotting, and the saved-map class is cleared when back online', () => {
        for (const input of [{ offline: true, savedMap: true }, { offline: false, imageryFailed: true, savedMap: true }]) {
            assert.doesNotMatch(view.offlineViewState(input).message, /can still plot|you can plot/i);
        }
        const classes = new Set();
        const root = { classList: { toggle: (n, on) => (on ? classes.add(n) : classes.delete(n)) }, querySelector: () => null };
        view.applyOfflineView(root, view.offlineViewState({ offline: true, savedMap: true }));
        assert.ok(classes.has(view.SAVED_MAP_CLASS));
        view.applyOfflineView(root, view.offlineViewState({ offline: false }));
        assert.equal(classes.size, 0);
    });
});

describe('plain messages tell a connection problem apart from map pictures not loading', () => {
    it('each state has its own clear first sentence, and no message uses the old wording', () => {
        const msg = (i) => view.offlineViewState(i).message;
        assert.match(msg({ offline: true }), /^You are offline\./);
        assert.match(msg({ offline: true, savedMap: true }), /^Offline map is available\./);
        assert.match(msg({ offline: false, imageryFailed: true }), /^Map pictures are not loading right now\. You are still connected/);
        assert.match(msg({ offline: false, imageryFailed: true, savedMap: true }), /^Online map imagery is not loading\. Offline map is available\./);
        assert.equal(msg({ offline: false }), '');
        for (const i of [{ offline: true }, { offline: true, savedMap: true }, { offline: false, imageryFailed: true }, { offline: false, imageryFailed: true, savedMap: true }]) {
            assert.doesNotMatch(msg(i), /needs a connection|Offline view —/);
            assert.doesNotMatch(msg(i), /can still plot|you can plot/i);
        }
        assert.equal(view.MAP_OFFLINE_NOTE, 'You are offline. Map pictures will come back when you are online again.');
        // Map-saving problems: plain words, no "next time you are online" while the user is already online.
        assert.equal(savedMap.offlineMapStatusText({ ok: false, reason: 'tile-unavailable' }),
            'The offline map could not be saved yet. It will try again the next time you open Spot Mapping while online.');
        assert.match(savedMap.offlineMapStatusText({ ok: false, reason: 'no-space' }), /^Not enough storage space/);
    });

    it('the empty-map watermark says "offline" only when really offline', () => {
        const css = readFileSync(path.resolve('resources/css/pages/spot-mapping.css'), 'utf8');
        assert.match(css, /\.lml-spot-map--imagery-missing \.leaflet-container::before \{[^}]*content: 'Map pictures not loading';/);
        assert.match(css, /\.lml-spot-map--offline\.lml-spot-map--imagery-missing \.leaflet-container::before \{\s*content: 'No internet: map pictures unavailable';/);
        assert.doesNotMatch(css, /Map imagery unavailable offline/);
    });
});

describe('safety', () => {
    it('the saved-map code never writes household data or coordinates', () => {
        const src = readFileSync(path.resolve('resources/js/maps/la-medalla-offline-map.js'), 'utf8');
        assert.doesNotMatch(src, /method:\s*['"](?:POST|PUT|PATCH|DELETE)/i);
        assert.doesNotMatch(src, /latitude|longitude|indexedDB|household_no/i);
    });

    it('Spot Mapping keeps plotting online-only and the Demo Mode guard untouched', () => {
        const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
        assert.match(src, /export const SPOT_MAP_OFFLINE_MESSAGE = 'Plotting household is not available when offline\.'/);
        assert.match(src, /syncDemoLocationIndicator\(\{ map, root, leaflet: L, offline: isClientOffline\(\)/);
    });
});
