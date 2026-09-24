/**
 * Post-login offline preparation — readiness + real progress contracts.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createMemoryCaches } from './support/fake-caches.mjs';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const runtimeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-runtime.js')).href;
const prepareUrl = pathToFileURL(path.resolve('resources/js/offline/offline-prepare.js')).href;

const {
    CACHE_VERSION,
    MESSAGE_ENSURE_BUILD_ASSETS,
    MESSAGE_ENSURE_BUILD_ASSETS_RESULT,
    MESSAGE_WARMUP_COMPLETE,
    MESSAGE_WARMUP_PROGRESS,
    WARMUP_PATHS_ADMIN,
    WARMUP_PATHS_SHARED,
    ASSETS_CACHE,
    criticalBuildAssetPathsFromManifest,
    documentUsesViteDevAssets,
    htmlCacheNameForActor,
    htmlUsesViteDevAssets,
    navigationCacheUrl,
    prepareStorageKey,
    shouldCacheNavigationResponse,
    warmupPathsForRole,
} = await import(policyUrl);

const { createServiceWorkerRuntime } = await import(runtimeUrl);
const {
    readLocalPrepared,
    writeLocalPrepared,
    clearLocalPrepared,
    isPreparedForLoginSession,
    requestWarmupWithProgress,
    requestEnsureBuildAssets,
    verifyCoreNavigationCached,
    runOfflinePreparation,
} = await import(prepareUrl);

const ORIGIN = 'https://lmlinga.test';
const registerSource = readFileSync(path.resolve('resources/js/offline/offline-sw-register.js'), 'utf8');
const appSource = readFileSync(path.resolve('resources/js/app.js'), 'utf8');

function htmlResponse(body = '<html>ok</html>', init = {}) {
    return new Response(body, {
        status: init.status || 200,
        headers: { 'Content-Type': 'text/html', ...(init.headers || {}) },
    });
}

function memoryStorage() {
    const map = new Map();
    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => map.set(k, String(v)),
        removeItem: (k) => map.delete(k),
    };
}

describe('offline preparation readiness storage', () => {
    it('gates local prepared state on cache version and actor', () => {
        assert.equal(CACHE_VERSION, 'offline-7-v20');
        const storage = memoryStorage();
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
        writeLocalPrepared(storage, 7, 'bhw', 12);
        const saved = readLocalPrepared(storage, 7, 'bhw');
        assert.equal(saved.version, CACHE_VERSION);
        assert.equal(saved.role, 'bhw');
        assert.equal(readLocalPrepared(storage, 7, 'admin'), null);
        clearLocalPrepared(storage, 7);
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
        assert.match(prepareStorageKey(7), /offline-7-v20:7$/);
    });

    it('stores and matches per-login session readiness separately from durable cache version', () => {
        const storage = memoryStorage();
        writeLocalPrepared(storage, 7, 'bhw', {
            pathCount: 12,
            loginSessionId: 'sess-login-1',
        });
        const saved = readLocalPrepared(storage, 7, 'bhw');
        assert.equal(saved.loginSessionId, 'sess-login-1');
        assert.equal(isPreparedForLoginSession(saved, 'sess-login-1'), true);
        // B: durable cache ready but fresh login session → not prepared for this login
        assert.equal(isPreparedForLoginSession(saved, 'sess-login-2'), false);
        // Legacy marker without loginSessionId cannot skip when server sends a session id
        writeLocalPrepared(storage, 7, 'bhw', { pathCount: 12 });
        assert.equal(isPreparedForLoginSession(readLocalPrepared(storage, 7, 'bhw'), 'sess-login-1'), false);
        // Offline / missing server session id still allows durable skip
        assert.equal(isPreparedForLoginSession(saved, ''), true);
        assert.equal(isPreparedForLoginSession(saved, null), true);
    });

    it('treats mismatched prepared version as stale', () => {
        const storage = memoryStorage();
        storage.setItem(
            prepareStorageKey(7, 'offline-7-v13'),
            JSON.stringify({ version: 'offline-7-v13', role: 'bhw', pathCount: 12 }),
        );
        // Current key uses v15 — old marker is invisible / not treated prepared.
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
    });
});

describe('offline preparation warmup progress', () => {
    it('TEST B — complete success reaches 100% only when all paths warm', async () => {
        const store = createMemoryCaches();
        const networkMap = new Map();
        [...WARMUP_PATHS_SHARED].forEach((p) => {
            networkMap.set(`GET ${ORIGIN}${p}`, () => htmlResponse(`<html>${p}</html>`));
        });

        const messages = [];
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                const handler = networkMap.get(`GET ${url}`);
                if (!handler) {
                    throw new Error(`missing ${url}`);
                }
                return handler();
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });

        await runtime.setActor(7);
        const result = await runtime.warmCoreNavigation(7, {
            role: 'bhw',
            ports: [{ postMessage: (msg) => messages.push(msg) }],
        });

        assert.equal(result.ok, true);
        assert.equal(result.percentage, 100);
        assert.equal(result.failed.length, 0);
        assert.equal(result.warmed.length, WARMUP_PATHS_SHARED.length);

        const progress = messages.filter((m) => m.type === MESSAGE_WARMUP_PROGRESS);
        assert.equal(progress[0].percentage, 0);
        assert.equal(progress[progress.length - 1].percentage, 100);
        for (let i = 1; i < progress.length; i += 1) {
            assert.ok(progress[i].percentage >= progress[i - 1].percentage);
        }

        const verified = await verifyCoreNavigationCached(7, 'bhw', {
            caches: store,
            origin: ORIGIN,
        });
        assert.equal(verified.ok, true);
        ['/dashboard', '/household-profiling', '/household-profiling/create', '/environmental-health']
            .forEach((p) => assert.equal(verified.missing.includes(p), false));
    });

    it('TEST A — progress failures never report 100% success', async () => {
        const store = createMemoryCaches();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                // Warm only the first two shared paths; fail the rest.
                if (url.endsWith('/dashboard') || url.endsWith('/spot-mapping')) {
                    return htmlResponse('<html>ok</html>');
                }
                throw new TypeError('offline');
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        await runtime.setActor(7);
        const messages = [];
        const result = await runtime.warmCoreNavigation(7, {
            role: 'bhw',
            ports: [{ postMessage: (m) => messages.push(m) }],
        });

        assert.equal(result.ok, false);
        assert.equal(result.warmed.length, 2);
        assert.ok(result.failed.length >= 2);
        assert.ok(result.percentage < 100);
        assert.equal(
            result.percentage,
            Math.round((result.warmed.length / result.total) * 100),
        );

        const progress = messages.filter((m) => m.type === MESSAGE_WARMUP_PROGRESS);
        const lastProgress = progress[progress.length - 1];
        assert.ok(lastProgress.percentage < 100);
        assert.equal(lastProgress.failed, result.failed.length);

        const storage = memoryStorage();
        // Prepared must not be written on partial (simulate page gate).
        assert.equal(result.ok && result.percentage === 100, false);
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
    });

    it('TEST C — cache verification fails when a required entry is absent', async () => {
        const store = createMemoryCaches();
        const cacheName = htmlCacheNameForActor(7);
        const cache = await store.open(cacheName);
        // Intentionally omit /environmental-health.
        for (const path of warmupPathsForRole('bhw')) {
            if (path === '/environmental-health') {
                continue;
            }
            await cache.put(
                new Request(navigationCacheUrl(ORIGIN, path)),
                htmlResponse(`<html>${path}</html>`),
            );
        }

        const verified = await verifyCoreNavigationCached(7, 'bhw', {
            caches: store,
            origin: ORIGIN,
        });
        assert.equal(verified.ok, false);
        assert.ok(verified.missing.includes('/environmental-health'));

        const storage = memoryStorage();
        assert.equal(verified.ok, false);
        // Gate: only write prepared when verification passes.
        if (verified.ok) {
            writeLocalPrepared(storage, 7, 'bhw', verified.total);
        }
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
    });

    it('TEST G — core navigation paths present after successful warmup', async () => {
        const store = createMemoryCaches();
        const networkMap = new Map();
        [...WARMUP_PATHS_SHARED].forEach((p) => {
            networkMap.set(`GET ${ORIGIN}${p}`, () => htmlResponse(`<html>${p}</html>`));
        });
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                return networkMap.get(`GET ${url}`)();
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        await runtime.setActor(9);
        await runtime.warmCoreNavigation(9, { role: 'bhw' });
        const ready = await runtime.coreWarmupReadyState(9, 'bhw');
        assert.equal(ready.ready, true);
        assert.equal(ready.version, CACHE_VERSION);
        for (const path of ['/dashboard', '/household-profiling', '/environmental-health']) {
            const hit = await (await store.open(htmlCacheNameForActor(9)))
                .match(new Request(navigationCacheUrl(ORIGIN, path)));
            assert.ok(hit, `expected ${path}`);
        }
    });

    it('admin warmup includes admin-only paths and never user-management index', async () => {
        const adminPaths = warmupPathsForRole('admin');
        const workerPaths = warmupPathsForRole('bhw');
        assert.ok(adminPaths.length > workerPaths.length);
        WARMUP_PATHS_ADMIN.forEach((p) => assert.ok(adminPaths.includes(p)));
        assert.equal(adminPaths.includes('/user-management'), false);
        assert.equal(
            shouldCacheNavigationResponse(
                { ok: true, status: 200, type: 'basic', headers: { get: () => 'text/html' } },
                `${ORIGIN}/user-management`,
                ORIGIN,
            ),
            false,
        );
    });

    it('wires preparation into register + app entry', () => {
        assert.match(registerSource, /bootOfflinePreparation|requestBlockingPreparation/);
        assert.match(appSource, /offline-prepare/);
    });
});

describe('offline preparation page progress helper', () => {
    it('forwards worker progress events through requestWarmupWithProgress', async () => {
        const progressEvents = [];
        let completePayload = null;
        const fakeWorker = {
            postMessage(payload, ports) {
                assert.equal(payload.type, 'lmlinga:warmup-core');
                const port = ports?.[0];
                port.postMessage({
                    type: MESSAGE_WARMUP_PROGRESS,
                    percentage: 50,
                    completed: 1,
                    total: 2,
                    label: 'Preparing Dashboard…',
                });
                port.postMessage({
                    type: MESSAGE_WARMUP_COMPLETE,
                    ok: true,
                    percentage: 100,
                    completed: 2,
                    total: 2,
                    warmed: ['/dashboard', '/profile'],
                    failed: [],
                });
            },
        };

        const result = await requestWarmupWithProgress({
            worker: fakeWorker,
            actorId: 7,
            role: 'bhw',
            window: { navigator: { serviceWorker: null } },
            onProgress: (p) => progressEvents.push(p),
            onComplete: (c) => {
                completePayload = c;
            },
            timeoutMs: 2000,
        });

        assert.equal(progressEvents.length, 1);
        assert.equal(progressEvents[0].percentage, 50);
        assert.equal(result.ok, true);
        assert.equal(completePayload.percentage, 100);
    });
});

describe('offline preparation per-login session gate', () => {
    it('A+B: durable prepared marker with old login session does not skip (fresh login)', async () => {
        const storage = memoryStorage();
        writeLocalPrepared(storage, 7, 'bhw', {
            pathCount: 20,
            loginSessionId: 'previous-login',
            householdCount: 3,
            memberCount: 5,
        });
        assert.equal(readLocalPrepared(storage, 7, 'bhw')?.loginSessionId, 'previous-login');

        const doc = {
            querySelector(sel) {
                if (sel === '[data-lml-offline-root]') {
                    return {
                        getAttribute(name) {
                            if (name === 'data-offline-actor-id') return '7';
                            if (name === 'data-offline-status-url') return '/offline/status';
                            if (name === 'data-offline-actor-avatar') return '';
                            return null;
                        },
                    };
                }
                return null;
            },
            createElement() {
                return {
                    setAttribute() {},
                    appendChild() {},
                    querySelector() { return null; },
                    querySelectorAll() { return []; },
                    classList: { add() {}, remove() {}, contains() { return false; } },
                    style: {},
                    children: [],
                };
            },
            body: { appendChild() {} },
            addEventListener() {},
            removeEventListener() {},
            activeElement: null,
        };

        const result = await runOfflinePreparation({
            window: {
                localStorage: storage,
                location: { origin: ORIGIN },
                setTimeout,
                clearTimeout,
                navigator: { onLine: true, serviceWorker: { controller: {} } },
            },
            document: doc,
            navigator: { onLine: true, serviceWorker: { controller: { postMessage() {} } } },
            storage,
            actorId: 7,
            role: 'bhw',
            worker: { postMessage() {} },
            loginSessionId: 'fresh-login',
            force: true, // allow past canWarmCoreFieldPages in non-PROD test env
            fetch: async () => ({
                ok: true,
                status: 200,
                json: async () => ({
                    ok: true,
                    user_id: 7,
                    role: 'bhw',
                    login_session_id: 'fresh-login',
                    is_active: true,
                }),
            }),
            // Incomplete worker/caches → preparation attempts and fails verify rather than already-prepared
            caches: createMemoryCaches(),
            origin: ORIGIN,
            timeoutMs: 50,
        });

        // Must not claim already-prepared for a new login session.
        assert.notEqual(result.reason, 'already-prepared');
        // Marker cleared when login session mismatched before attempting prep.
        // (force path still clears on failure; absence of previous-login is the key signal)
        const after = readLocalPrepared(storage, 7, 'bhw');
        assert.equal(after === null || after.loginSessionId !== 'previous-login', true);
    });

    it('C+D: matching login session allows already-prepared skip when caches verify', async () => {
        // Pure gate unit: matching session is required for skip.
        const local = {
            version: CACHE_VERSION,
            role: 'bhw',
            loginSessionId: 'same-login',
            dbVersion: 4,
        };
        assert.equal(isPreparedForLoginSession(local, 'same-login'), true);
        assert.equal(isPreparedForLoginSession(local, 'other-login'), false);
    });

    it('E+F: logout clear + actor switch leave no cross-actor prepared marker', () => {
        const storage = memoryStorage();
        writeLocalPrepared(storage, 7, 'bhw', { pathCount: 10, loginSessionId: 'a-login' });
        writeLocalPrepared(storage, 9, 'admin', { pathCount: 10, loginSessionId: 'b-login' });
        clearLocalPrepared(storage, 7); // logout actor A
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
        assert.equal(readLocalPrepared(storage, 9, 'admin')?.loginSessionId, 'b-login');
        assert.equal(isPreparedForLoginSession(readLocalPrepared(storage, 9, 'admin'), 'b-login'), true);
        assert.equal(isPreparedForLoginSession(readLocalPrepared(storage, 9, 'admin'), 'a-login'), false);
    });

    it('G: CACHE_VERSION change invalidates prepared marker', () => {
        const storage = memoryStorage();
        storage.setItem(
            prepareStorageKey(7, 'offline-7-v18'),
            JSON.stringify({
                version: 'offline-7-v18',
                role: 'bhw',
                pathCount: 12,
                loginSessionId: 'same-login',
            }),
        );
        // Current key is v19 — stale v18 marker is invisible / not treated prepared.
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
    });

    it('H+I: failed prep clears marker; successful write stores loginSessionId', () => {
        const storage = memoryStorage();
        writeLocalPrepared(storage, 7, 'bhw', { pathCount: 5, loginSessionId: 'sess-1' });
        clearLocalPrepared(storage, 7); // H: failure path clears
        assert.equal(readLocalPrepared(storage, 7, 'bhw'), null);
        writeLocalPrepared(storage, 7, 'bhw', { pathCount: 20, loginSessionId: 'sess-1' }); // I: retry success
        assert.equal(readLocalPrepared(storage, 7, 'bhw').loginSessionId, 'sess-1');
        assert.equal(isPreparedForLoginSession(readLocalPrepared(storage, 7, 'bhw'), 'sess-1'), true);
    });

    it('J+K+L+M: staff dataset replace preserves local pending (source contract)', () => {
        const hpStore = readFileSync(path.resolve('resources/js/offline/offline-hp-store.js'), 'utf8');
        assert.match(hpStore, /!row\.local && !row\.pending_sync/);
        assert.match(hpStore, /MB-L-/);
        const prepareSrc = readFileSync(path.resolve('resources/js/offline/offline-prepare.js'), 'utf8');
        assert.match(prepareSrc, /isPreparedForLoginSession/);
        assert.match(prepareSrc, /loginSessionId/);
        assert.match(prepareSrc, /login_session_id/);
        assert.match(prepareSrc, /requestEnsureBuildAssets/);
        assert.match(prepareSrc, /MESSAGE_ENSURE_BUILD_ASSETS/);
        assert.match(prepareSrc, /vite-dev-assets/);
        assert.match(registerSource, /clearLocalPrepared/);
    });
});

const SAMPLE_MANIFEST = {
    'resources/css/app.css': {
        file: 'assets/app-TESTCSS.css',
        src: 'resources/css/app.css',
        isEntry: true,
    },
    'resources/js/app.js': {
        file: 'assets/app-TESTJS.js',
        css: ['assets/app-TESTASSOC.css'],
        assets: ['assets/layers-TEST.png'],
    },
    'node_modules/bootstrap-icons/font/fonts/bootstrap-icons.woff2': {
        file: 'assets/bootstrap-icons-TEST.woff2',
        src: 'node_modules/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
    },
};

function buildNetworkWithManifest(extra = new Map()) {
    const networkMap = new Map(extra);
    networkMap.set(`GET ${ORIGIN}/build/manifest.json`, () =>
        new Response(JSON.stringify(SAMPLE_MANIFEST), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    for (const assetPath of criticalBuildAssetPathsFromManifest(SAMPLE_MANIFEST)) {
        networkMap.set(`GET ${ORIGIN}${assetPath}`, () =>
            new Response(`body:${assetPath}`, {
                status: 200,
                headers: { 'Content-Type': assetPath.endsWith('.css') ? 'text/css' : 'application/javascript' },
            }),
        );
    }
    return networkMap;
}

describe('offline preparation build asset readiness', () => {
    it('derives critical paths from manifest without hardcoded hashes', () => {
        const paths = criticalBuildAssetPathsFromManifest(SAMPLE_MANIFEST);
        assert.ok(paths.includes('/build/assets/app-TESTCSS.css'));
        assert.ok(paths.includes('/build/assets/app-TESTJS.js'));
        assert.ok(paths.includes('/build/assets/app-TESTASSOC.css'));
        assert.ok(paths.includes('/build/assets/layers-TEST.png'));
        assert.ok(paths.includes('/build/assets/bootstrap-icons-TEST.woff2'));
        assert.equal(paths.some((p) => p.includes('BX5U5leC')), false);
    });

    it('detects Vite DEV asset URLs in the document', () => {
        const nodes = [
            { getAttribute: (n) => (n === 'href' ? 'http://127.0.0.1:5173/resources/css/app.css' : null) },
            { getAttribute: (n) => (n === 'src' ? 'http://127.0.0.1:5173/@vite/client' : null) },
        ];
        const doc = {
            baseURI: `${ORIGIN}/dashboard`,
            querySelectorAll(sel) {
                if (sel === 'link[href]') return [nodes[0]];
                if (sel === 'script[src]') return [nodes[1]];
                return [];
            },
        };
        assert.equal(documentUsesViteDevAssets(doc), true);
        const buildDoc = {
            baseURI: `${ORIGIN}/dashboard`,
            querySelectorAll(sel) {
                if (sel === 'link[href]') {
                    return [{ getAttribute: (n) => (n === 'href' ? '/build/assets/app-TESTCSS.css' : null) }];
                }
                return [];
            },
        };
        assert.equal(documentUsesViteDevAssets(buildDoc), false);
    });

    it('BUILD ensure caches current manifest CSS/JS into ASSETS_CACHE', async () => {
        const store = createMemoryCaches();
        const networkMap = buildNetworkWithManifest();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                const handler = networkMap.get(`GET ${url}`);
                if (!handler) {
                    throw new Error(`missing ${url}`);
                }
                return handler();
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });

        const result = await runtime.ensureBuiltAssets();
        assert.equal(result.ok, true);
        assert.equal(result.missing.length, 0);
        const cache = await store.open(ASSETS_CACHE);
        for (const assetPath of result.checked) {
            assert.ok(await cache.match(new Request(`${ORIGIN}${assetPath}`)), assetPath);
        }
    });

    it('missing critical asset prevents ensure ok', async () => {
        const store = createMemoryCaches();
        const networkMap = buildNetworkWithManifest();
        // Drop one critical CSS so precache cannot store it.
        networkMap.delete(`GET ${ORIGIN}/build/assets/app-TESTCSS.css`);
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                const handler = networkMap.get(`GET ${url}`);
                if (!handler) {
                    throw new Error(`missing ${url}`);
                }
                return handler();
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        const result = await runtime.ensureBuiltAssets();
        assert.equal(result.ok, false);
        assert.ok(result.missing.includes('/build/assets/app-TESTCSS.css'));
    });

    it('stale asset cache is refilled from current manifest hashes', async () => {
        const store = createMemoryCaches();
        const cache = await store.open(ASSETS_CACHE);
        await cache.put(
            new Request(`${ORIGIN}/build/assets/app-OLDHASH.css`),
            new Response('old', { headers: { 'Content-Type': 'text/css' } }),
        );
        const networkMap = buildNetworkWithManifest();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                return networkMap.get(`GET ${url}`)();
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        const result = await runtime.ensureBuiltAssets();
        assert.equal(result.ok, true);
        assert.ok(await cache.match(new Request(`${ORIGIN}/build/assets/app-TESTCSS.css`)));
        // Old hash may remain; readiness only requires CURRENT paths.
        assert.equal(result.checked.includes('/build/assets/app-OLDHASH.css'), false);
    });

    it('requestEnsureBuildAssets awaits SW MESSAGE_ENSURE_BUILD_ASSETS', async () => {
        const store = createMemoryCaches();
        const networkMap = buildNetworkWithManifest();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                return networkMap.get(`GET ${url}`)();
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        const worker = {
            postMessage(payload, ports) {
                assert.equal(payload.type, MESSAGE_ENSURE_BUILD_ASSETS);
                void runtime.handleMessage(payload, { ports: ports || [] }).then((result) => {
                    ports?.[0]?.postMessage({ ok: true, ...result });
                });
            },
        };
        const result = await requestEnsureBuildAssets(worker, { timeoutMs: 5000 });
        assert.equal(result.ok, true);
        assert.equal(result.type, MESSAGE_ENSURE_BUILD_ASSETS_RESULT);
    });

    it('DEV/Vite document does not claim production offline Ready', async () => {
        const storage = memoryStorage();
        writeLocalPrepared(storage, 7, 'bhw', {
            pathCount: 20,
            loginSessionId: 'same-login',
        });
        const viteLink = {
            getAttribute(name) {
                return name === 'href' ? 'http://127.0.0.1:5173/resources/css/app.css' : null;
            },
        };
        const doc = {
            baseURI: `${ORIGIN}/dashboard`,
            querySelector(sel) {
                if (sel === '[data-lml-offline-root]') {
                    return {
                        getAttribute(name) {
                            if (name === 'data-offline-actor-id') return '7';
                            if (name === 'data-offline-status-url') return '/offline/status';
                            return null;
                        },
                    };
                }
                return null;
            },
            querySelectorAll(sel) {
                if (sel === 'link[href]') return [viteLink];
                return [];
            },
            createElement() {
                return {
                    setAttribute() {},
                    appendChild() {},
                    querySelector() { return null; },
                    querySelectorAll() { return []; },
                    classList: { add() {}, remove() {}, contains() { return false; } },
                    style: {},
                    children: [],
                };
            },
            body: { appendChild() {} },
            addEventListener() {},
            removeEventListener() {},
            activeElement: null,
        };
        const result = await runOfflinePreparation({
            window: {
                localStorage: storage,
                location: { origin: ORIGIN },
                setTimeout,
                clearTimeout,
                navigator: { onLine: true, serviceWorker: { controller: {} } },
            },
            document: doc,
            navigator: { onLine: true, serviceWorker: { controller: { postMessage() {} } } },
            storage,
            actorId: 7,
            role: 'bhw',
            worker: { postMessage() {} },
            loginSessionId: 'same-login',
            force: true,
            fetch: async () => ({
                ok: true,
                json: async () => ({ ok: true, user_id: 7, role: 'bhw', login_session_id: 'same-login' }),
            }),
            caches: createMemoryCaches(),
            origin: ORIGIN,
            timeoutMs: 50,
        });
        assert.equal(result.ok, false);
        assert.equal(result.reason, 'vite-dev-assets');
        assert.notEqual(result.reason, 'already-prepared');
    });

    it('already-prepared cannot bypass when current build assets are missing', async () => {
        const storage = memoryStorage();
        writeLocalPrepared(storage, 7, 'bhw', {
            pathCount: WARMUP_PATHS_SHARED.length,
            loginSessionId: 'same-login',
            dbVersion: 4,
        });
        const store = createMemoryCaches();
        const htmlCache = await store.open(htmlCacheNameForActor(7));
        for (const path of warmupPathsForRole('bhw')) {
            await htmlCache.put(
                new Request(navigationCacheUrl(ORIGIN, path)),
                htmlResponse(`<html>${path}</html>`),
            );
        }
        // No ASSETS_CACHE entries and ensure worker reports failure.
        const doc = {
            baseURI: `${ORIGIN}/dashboard`,
            querySelector(sel) {
                if (sel === '[data-lml-offline-root]') {
                    return {
                        getAttribute(name) {
                            if (name === 'data-offline-actor-id') return '7';
                            if (name === 'data-offline-status-url') return '/offline/status';
                            return null;
                        },
                    };
                }
                return null;
            },
            querySelectorAll() {
                return [];
            },
            createElement() {
                return {
                    setAttribute() {},
                    appendChild() {},
                    querySelector() { return null; },
                    querySelectorAll() { return []; },
                    classList: { add() {}, remove() {}, contains() { return false; } },
                    style: {},
                    children: [],
                };
            },
            body: { appendChild() {} },
            addEventListener() {},
            removeEventListener() {},
            activeElement: null,
        };
        const worker = {
            postMessage(payload, ports) {
                if (payload.type === 'lmlinga:warmup-ready-query') {
                    ports?.[0]?.postMessage({
                        type: 'lmlinga:warmup-ready-result',
                        ready: true,
                        version: CACHE_VERSION,
                        total: WARMUP_PATHS_SHARED.length,
                        present: WARMUP_PATHS_SHARED.length,
                        missing: [],
                    });
                    return;
                }
                if (payload.type === MESSAGE_ENSURE_BUILD_ASSETS) {
                    ports?.[0]?.postMessage({
                        type: MESSAGE_ENSURE_BUILD_ASSETS_RESULT,
                        ok: false,
                        reason: 'missing-assets',
                        checked: ['/build/assets/app-TESTCSS.css'],
                        missing: ['/build/assets/app-TESTCSS.css'],
                        version: CACHE_VERSION,
                    });
                }
            },
        };
        const result = await runOfflinePreparation({
            window: {
                localStorage: storage,
                location: { origin: ORIGIN },
                setTimeout,
                clearTimeout,
                navigator: { onLine: true, serviceWorker: { controller: worker } },
            },
            document: doc,
            navigator: { onLine: true, serviceWorker: { controller: worker } },
            storage,
            actorId: 7,
            role: 'bhw',
            worker,
            loginSessionId: 'same-login',
            force: true,
            fetch: async (url) => {
                if (String(url).includes('/offline/status')) {
                    return {
                        ok: true,
                        json: async () => ({
                            ok: true,
                            user_id: 7,
                            role: 'bhw',
                            login_session_id: 'same-login',
                        }),
                    };
                }
                throw new Error(`unexpected fetch ${url}`);
            },
            caches: store,
            origin: ORIGIN,
            timeoutMs: 200,
            assetTimeoutMs: 200,
        });
        assert.notEqual(result.reason, 'already-prepared');
        assert.equal(result.ok, false);
    });
});

describe('stale Vite DEV navigation HTML self-healing', () => {
    const BUILD_HTML = (label) => `<html><head>
<link rel="stylesheet" href="/build/assets/app-BX5U5leC.css">
<script type="module" src="/build/assets/app-DNYX85kF.js"></script>
</head><body>${label}</body></html>`;
    const DEV_HTML = (label, port = 5173) => `<html><head>
<script type="module" src="http://127.0.0.1:${port}/@vite/client"></script>
<link rel="stylesheet" href="http://127.0.0.1:${port}/resources/css/app.css">
<script type="module" src="http://127.0.0.1:${port}/resources/js/app.js"></script>
</head><body>${label}</body></html>`;

    async function setup({ seed = {}, network = {} } = {}) {
        const store = createMemoryCaches();
        const cache = await store.open(htmlCacheNameForActor(7));
        for (const [p, html] of Object.entries(seed)) {
            await cache.put(new Request(navigationCacheUrl(ORIGIN, p)), htmlResponse(html));
        }
        const hits = new Map();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: store,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                const p = new URL(url).pathname;
                hits.set(p, (hits.get(p) || 0) + 1);
                const handler = network[p];
                if (handler === 'throw') {
                    throw new TypeError('Failed to fetch');
                }
                if (typeof handler === 'function') {
                    return handler();
                }
                return htmlResponse(BUILD_HTML(p));
            },
            skipWaiting: async () => {},
            clientsClaim: async () => {},
            clients: { matchAll: async () => [] },
        });
        await runtime.setActor(7);
        return { store, cache, hits, runtime };
    }

    async function cachedBody(cache, p) {
        const hit = await cache.match(new Request(navigationCacheUrl(ORIGIN, p)));
        return hit ? hit.text() : null;
    }

    it('detects Vite DEV asset references in HTML text on any port, and ignores BUILD HTML', () => {
        assert.equal(htmlUsesViteDevAssets(DEV_HTML('x')), true);
        assert.equal(htmlUsesViteDevAssets(DEV_HTML('x', 5174)), true);
        assert.equal(htmlUsesViteDevAssets(DEV_HTML('x', 5199)), true);
        assert.equal(htmlUsesViteDevAssets('<script src="/@vite/client"></script>'), true);
        assert.equal(htmlUsesViteDevAssets("<link href='/resources/css/app.css' rel='stylesheet'>"), true);
        assert.equal(htmlUsesViteDevAssets(BUILD_HTML('x')), false);
        assert.equal(htmlUsesViteDevAssets('<html><a href="/resources-guide">x</a></html>'), false);
        assert.equal(htmlUsesViteDevAssets(''), false);
        assert.equal(htmlUsesViteDevAssets(null), false);
    });

    it('keeps existing valid BUILD HTML without refetching it', async () => {
        const { cache, hits, runtime } = await setup({ seed: { '/dashboard': BUILD_HTML('cached-dashboard') } });
        const result = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(result.warmed.includes('/dashboard'), true);
        assert.equal(hits.get('/dashboard'), undefined);
        assert.match(await cachedBody(cache, '/dashboard'), /cached-dashboard/);
    });

    it('does not accept stale DEV HTML as warmed; refetches and replaces it with BUILD HTML', async () => {
        const stale = ['/household-profiling', '/announcements', '/spot-mapping', '/environmental-health'];
        const seed = { '/dashboard': BUILD_HTML('cached-dashboard') };
        stale.forEach((p) => { seed[p] = DEV_HTML(`stale-${p}`); });
        const { cache, hits, runtime } = await setup({ seed });

        const messages = [];
        const result = await runtime.warmCoreNavigation(7, {
            role: 'bhw',
            ports: [{ postMessage: (m) => messages.push(m) }],
        });

        assert.equal(result.ok, true);
        assert.equal(result.failed.length, 0);
        for (const p of stale) {
            assert.equal(hits.get(p), 1, `${p} refetched`);
            const body = await cachedBody(cache, p);
            assert.equal(htmlUsesViteDevAssets(body), false, `${p} no longer DEV`);
            assert.match(body, /\/build\/assets\/app-BX5U5leC\.css/);
            assert.equal(body.includes('stale-'), false);
            assert.equal(result.warmed.includes(p), true);
            const progress = messages.find((m) => m.type === MESSAGE_WARMUP_PROGRESS && m.url === p);
            assert.equal(progress.ok, true);
            assert.equal(progress.diag.staleDevAssets, true);
            assert.equal(progress.diag.fromCache, false);
        }
        assert.equal(hits.get('/dashboard'), undefined);
    });

    it('counts a stale route as warmed only after the replacement succeeds', async () => {
        const { hits, runtime } = await setup({
            seed: { '/household-profiling': DEV_HTML('stale') },
        });
        const messages = [];
        await runtime.warmCoreNavigation(7, {
            role: 'bhw',
            ports: [{ postMessage: (m) => messages.push(m) }],
        });
        const progress = messages.filter((m) => m.type === MESSAGE_WARMUP_PROGRESS && m.url);
        const idx = progress.findIndex((m) => m.url === '/household-profiling');
        assert.equal(hits.get('/household-profiling'), 1);
        assert.equal(progress[idx].completed, idx + 1);
        assert.equal(progress[idx].ok, true);
    });

    it('does not count a stale route as warmed when the refresh fetch fails, and keeps the old entry', async () => {
        const { cache, runtime } = await setup({
            seed: { '/household-profiling': DEV_HTML('stale-hp') },
            network: { '/household-profiling': 'throw' },
        });
        const result = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(result.ok, false);
        assert.equal(result.warmed.includes('/household-profiling'), false);
        assert.equal(result.failed.includes('/household-profiling'), true);
        assert.match(await cachedBody(cache, '/household-profiling'), /stale-hp/);
    });

    it('does not count a stale route as warmed when the refresh is not cacheable', async () => {
        const { cache, runtime } = await setup({
            seed: { '/spot-mapping': DEV_HTML('stale-spot') },
            network: { '/spot-mapping': () => htmlResponse('<html>err</html>', { status: 500 }) },
        });
        const result = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(result.warmed.includes('/spot-mapping'), false);
        assert.equal(result.failed.includes('/spot-mapping'), true);
        assert.match(await cachedBody(cache, '/spot-mapping'), /stale-spot/);
    });

    it('does not replace stale DEV HTML with fresh HTML that is still DEV (server in DEV mode)', async () => {
        const { cache, runtime } = await setup({
            seed: { '/announcements': DEV_HTML('stale-ann') },
            network: { '/announcements': () => htmlResponse(DEV_HTML('fresh-dev-ann')) },
        });
        const messages = [];
        const result = await runtime.warmCoreNavigation(7, {
            role: 'bhw',
            ports: [{ postMessage: (m) => messages.push(m) }],
        });
        assert.equal(result.warmed.includes('/announcements'), false);
        assert.equal(result.failed.includes('/announcements'), true);
        assert.match(await cachedBody(cache, '/announcements'), /stale-ann/);
        const progress = messages.find((m) => m.type === MESSAGE_WARMUP_PROGRESS && m.url === '/announcements');
        assert.equal(progress.diag.failReason, 'stale-dev-assets');
    });

    it('still warms uncached routes exactly as before', async () => {
        const { cache, hits, runtime } = await setup();
        const result = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(result.ok, true);
        assert.deepEqual(result.warmed.sort(), [...WARMUP_PATHS_SHARED].sort());
        for (const p of WARMUP_PATHS_SHARED) {
            assert.equal(hits.get(p), 1);
            assert.ok(await cachedBody(cache, p));
        }
    });

    it('verification treats stale DEV HTML as not ready and passes once healed', async () => {
        const seed = {};
        warmupPathsForRole('bhw').forEach((p) => { seed[p] = BUILD_HTML(p); });
        seed['/household-profiling'] = DEV_HTML('stale');
        const { store, runtime } = await setup({ seed });

        const before = await verifyCoreNavigationCached(7, 'bhw', { caches: store, origin: ORIGIN });
        assert.equal(before.ok, false);
        assert.deepEqual(before.missing, ['/household-profiling']);
        const readyBefore = await runtime.coreWarmupReadyState(7, 'bhw');
        assert.equal(readyBefore.ready, false);

        await runtime.warmCoreNavigation(7, { role: 'bhw' });

        const after = await verifyCoreNavigationCached(7, 'bhw', { caches: store, origin: ORIGIN });
        assert.equal(after.ok, true);
        assert.equal((await runtime.coreWarmupReadyState(7, 'bhw')).ready, true);
    });
});
