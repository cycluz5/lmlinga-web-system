/**
 * Spot Mapping saved offline map (Phase 2B).
 *
 * A small set of OpenStreetMap vector tiles for La Medalla (+ about 1 km) shipped under /maps/la-medalla/v1/.
 * It is used ONLY as a fallback when the online OpenStreetMap imagery is unavailable (offline, or the online
 * tiles keep failing). Display only: it never plots, saves, queues or changes household coordinates.
 *
 * Readiness rule: the map is "ready" only when every tile listed in the manifest is in the map cache AND the
 * manifest itself (written last) is there. A failed or half-finished download deletes the whole map cache.
 */

import {
    OFFLINE_MAP_CACHE,
    OFFLINE_MAP_PATH_PREFIX,
} from '../offline/offline-sw-policy.js';

export const OFFLINE_MAP_MANIFEST_PATH = `${OFFLINE_MAP_PATH_PREFIX}manifest.json`;
export const OFFLINE_MAP_TILE_URL = `${OFFLINE_MAP_PATH_PREFIX}{z}/{x}/{y}.mvt`;
export const OFFLINE_MAP_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &middot; <a href="https://protomaps.com">Protomaps</a>';

/** Online tile errors in a row (while the browser says it is online) before switching to the saved map. */
export const ONLINE_TILE_ERRORS_BEFORE_FALLBACK = 3;

/** Free space needed, as a multiple of the map size, before saving (room for the browser's own overhead). */
const STORAGE_SAFETY_FACTOR = 3;

const TILE_CONTENT_TYPE = 'application/vnd.mapbox-vector-tile';
const DOWNLOAD_CONCURRENCY = 6;
const LOCK_NAME = 'lmlinga-offline-map';

let activePreparation = null;

function absolute(origin, path) {
    return `${origin}${path}`;
}

/**
 * "20261030.pmtiles" → "Oct 30, 2026"; '' when unknown.
 * @param {{ build?: string }|null|undefined} manifest
 */
export function offlineMapDataDate(manifest, locale = 'en-PH') {
    const match = /^(\d{4})(\d{2})(\d{2})/.exec(String(manifest?.build || ''));
    if (!match) {
        return '';
    }
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleDateString(locale, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}

/** Basic shape check so a broken or foreign manifest is never treated as a map. */
export function isValidOfflineMapManifest(manifest) {
    return Boolean(
        manifest
        && Array.isArray(manifest.tiles)
        && manifest.tiles.length > 0
        && manifest.tiles.length === manifest.tileCount
        && manifest.tiles.every((t) => t
            && typeof t.path === 'string'
            && /^\d{1,2}\/\d{1,7}\/\d{1,7}\.mvt$/.test(t.path)
            && /^[0-9a-f]{64}$/.test(String(t.sha256 || ''))),
    );
}

/**
 * Pure decision: which base layer to show.
 * @returns {'online'|'saved'|'none'}
 */
export function baseLayerMode({ offline = false, onlineImageryFailed = false, savedMapReady = false } = {}) {
    if (!offline && !onlineImageryFailed) {
        return 'online';
    }
    return savedMapReady ? 'saved' : 'none';
}

/**
 * @param {{ caches?: CacheStorage, origin?: string }} [env]
 * @returns {Promise<{ ready: boolean, manifest: object|null, reason?: string }>}
 */
export async function readOfflineMapStatus(env = {}) {
    const cachesApi = env.caches || globalThis.caches;
    const origin = env.origin || globalThis.location?.origin || '';
    if (!cachesApi) {
        return { ready: false, manifest: null, reason: 'unsupported' };
    }
    try {
        // Check first so a status read never creates an empty map cache.
        const exists = typeof cachesApi.has === 'function'
            ? await cachesApi.has(OFFLINE_MAP_CACHE)
            : (await cachesApi.keys()).includes(OFFLINE_MAP_CACHE);
        if (!exists) {
            return { ready: false, manifest: null, reason: 'not-saved' };
        }
        const cache = await cachesApi.open(OFFLINE_MAP_CACHE);
        const saved = await cache.match(new Request(absolute(origin, OFFLINE_MAP_MANIFEST_PATH)));
        if (!saved) {
            return { ready: false, manifest: null, reason: 'not-saved' };
        }
        const manifest = await saved.json();
        if (!isValidOfflineMapManifest(manifest)) {
            return { ready: false, manifest: null, reason: 'invalid' };
        }
        const keys = new Set((await cache.keys()).map((req) => new URL(req.url).pathname));
        const missing = manifest.tiles.filter((t) => !keys.has(`${OFFLINE_MAP_PATH_PREFIX}${t.path}`));
        if (missing.length > 0) {
            return { ready: false, manifest: null, reason: 'incomplete' };
        }
        return { ready: true, manifest };
    } catch {
        return { ready: false, manifest: null, reason: 'error' };
    }
}

async function sha256Hex(subtle, buffer) {
    const digest = await subtle.digest('SHA-256', buffer);
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Download and verify the saved map once. Never runs offline. Safe to call repeatedly: already-ready maps
 * return at once and overlapping calls share one run (per tab, plus a Web Lock across tabs when available).
 *
 * @param {{ caches?: CacheStorage, fetch?: typeof fetch, origin?: string, subtle?: SubtleCrypto,
 *           storage?: { estimate?: () => Promise<{ usage?: number, quota?: number }> },
 *           navigator?: Navigator, onProgress?: (p: { done: number, total: number }) => void }} [env]
 * @returns {Promise<{ ok: boolean, reason?: string, tiles?: number, bytes?: number, manifest?: object }>}
 */
export function prepareOfflineMap(env = {}) {
    if (activePreparation) {
        return activePreparation;
    }
    const nav = env.navigator || globalThis.navigator;
    const run = () => prepareOfflineMapNow({ ...env, navigator: nav });
    const locks = nav?.locks;
    activePreparation = (locks && typeof locks.request === 'function'
        ? locks.request(LOCK_NAME, run)
        : run()
    ).finally(() => {
        activePreparation = null;
    });
    return activePreparation;
}

async function prepareOfflineMapNow(env) {
    const cachesApi = env.caches || globalThis.caches;
    const fetchImpl = env.fetch || globalThis.fetch?.bind(globalThis);
    const origin = env.origin || globalThis.location?.origin || '';
    const subtle = env.subtle || globalThis.crypto?.subtle;
    const storage = env.storage || env.navigator?.storage;
    const onProgress = typeof env.onProgress === 'function' ? env.onProgress : () => {};

    if (!cachesApi || typeof fetchImpl !== 'function' || !subtle || !origin) {
        return { ok: false, reason: 'unsupported' };
    }
    if (env.navigator?.onLine === false) {
        return { ok: false, reason: 'offline' };
    }

    const existing = await readOfflineMapStatus({ caches: cachesApi, origin });
    if (existing.ready) {
        return { ok: true, reason: 'already-ready', tiles: existing.manifest.tiles.length, bytes: existing.manifest.totalBytes, manifest: existing.manifest };
    }

    let manifest;
    try {
        const response = await fetchImpl(absolute(origin, OFFLINE_MAP_MANIFEST_PATH), { cache: 'no-store', credentials: 'same-origin' });
        if (!response?.ok) {
            return { ok: false, reason: 'manifest-unavailable' };
        }
        manifest = await response.json();
    } catch {
        return { ok: false, reason: 'manifest-unavailable' };
    }
    if (!isValidOfflineMapManifest(manifest)) {
        return { ok: false, reason: 'manifest-invalid' };
    }

    try {
        if (typeof storage?.estimate === 'function') {
            const { usage = 0, quota = 0 } = await storage.estimate();
            if (quota && quota - usage < (Number(manifest.totalBytes) || 0) * STORAGE_SAFETY_FACTOR) {
                return { ok: false, reason: 'no-space' };
            }
        }
    } catch {
        // Unknown quota: try anyway; QuotaExceededError is handled below.
    }

    const total = manifest.tiles.length;
    let bytes = 0;
    try {
        await cachesApi.delete(OFFLINE_MAP_CACHE); // start clean; never mix two partial downloads
        const cache = await cachesApi.open(OFFLINE_MAP_CACHE);
        onProgress({ done: 0, total });
        let done = 0;
        let next = 0;
        let failed = null;
        // A few downloads at a time (small files; one at a time took ~13 s on a slow local server).
        const worker = async () => {
            while (!failed && next < total) {
                const tile = manifest.tiles[next];
                next += 1;
                const url = absolute(origin, `${OFFLINE_MAP_PATH_PREFIX}${tile.path}`);
                try {
                    const response = await fetchImpl(url, { cache: 'no-store', credentials: 'same-origin' });
                    if (!response?.ok) {
                        throw new Error('tile-unavailable');
                    }
                    const buffer = await response.arrayBuffer();
                    if (await sha256Hex(subtle, buffer) !== tile.sha256) {
                        throw new Error('tile-checksum');
                    }
                    if (failed) {
                        return;
                    }
                    await cache.put(new Request(url), new Response(buffer, { headers: { 'Content-Type': TILE_CONTENT_TYPE } }));
                    bytes += buffer.byteLength;
                    done += 1;
                    onProgress({ done, total });
                } catch (error) {
                    failed = failed || error;
                    return;
                }
            }
        };
        await Promise.all(Array.from({ length: Math.min(DOWNLOAD_CONCURRENCY, total) }, worker));
        if (failed) {
            throw failed;
        }
        // Written last: its presence (plus every tile) is what "ready" means.
        await cache.put(
            new Request(absolute(origin, OFFLINE_MAP_MANIFEST_PATH)),
            new Response(JSON.stringify(manifest), { headers: { 'Content-Type': 'application/json' } }),
        );
    } catch (error) {
        try {
            await cachesApi.delete(OFFLINE_MAP_CACHE);
        } catch {
            // Nothing else to undo.
        }
        if (error?.name === 'QuotaExceededError') {
            return { ok: false, reason: 'no-space' };
        }
        return { ok: false, reason: error?.message === 'tile-checksum' ? 'tile-checksum' : 'tile-unavailable' };
    }

    const verified = await readOfflineMapStatus({ caches: cachesApi, origin });
    if (!verified.ready) {
        try {
            await cachesApi.delete(OFFLINE_MAP_CACHE);
        } catch {
            // Nothing else to undo.
        }
        return { ok: false, reason: 'verify-failed' };
    }
    return { ok: true, tiles: total, bytes, manifest };
}

/** Plain-language status for the Spot Mapping page. */
export function offlineMapStatusText(result) {
    if (!result) {
        return '';
    }
    if (result.ok || result.ready) {
        const date = offlineMapDataDate(result.manifest);
        return `Offline map saved on this device${date ? ` (OpenStreetMap data, ${date})` : ''}.`;
    }
    switch (result.reason) {
        case 'offline':
        case 'not-saved':
            return 'Offline map not saved on this device yet. It is saved automatically the next time you are online.';
        case 'no-space':
            return 'Not enough storage space on this device to save the offline map.';
        case 'unsupported':
            return 'This browser cannot save the offline map.';
        default:
            return 'The offline map could not be saved yet. It will try again the next time you open Spot Mapping while online.';
    }
}

/**
 * Create the saved-map Leaflet layer. The renderer (protomaps-leaflet, BSD-3) is loaded only when needed.
 * Buildings get a darker fill than the stock "light" style so they stay visible at zoom 16.
 *
 * @param {typeof import('leaflet')} L
 * @param {{ maxZoom: number }} options
 */
export async function createSavedMapLayer(L, { maxZoom }) {
    if (!globalThis.L) {
        globalThis.L = L; // protomaps-leaflet extends the global Leaflet GridLayer
    }
    const { leafletLayer, PolygonSymbolizer } = await import('protomaps-leaflet');
    const layer = leafletLayer({
        url: OFFLINE_MAP_TILE_URL,
        flavor: 'light',
        lang: 'en',
        maxDataZoom: 15,
        maxZoom,
        attribution: OFFLINE_MAP_ATTRIBUTION,
    });
    if (Array.isArray(layer.paintRules)) {
        layer.paintRules.push({
            dataLayer: 'buildings',
            symbolizer: new PolygonSymbolizer({ fill: '#c7beb1', stroke: '#9c9283', width: 0.6, opacity: 1 }),
        });
    }
    return layer;
}
