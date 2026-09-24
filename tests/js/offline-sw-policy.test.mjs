/**
 * OFFLINE-7 service worker contract — policy + fetch runtime.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createMemoryCaches } from './support/fake-caches.mjs';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const runtimeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-runtime.js')).href;
const registerSource = readFileSync(path.resolve('resources/js/offline/offline-sw-register.js'), 'utf8');
const swSource = readFileSync(path.resolve('resources/js/offline/offline-sw.js'), 'utf8');
const appSource = readFileSync(path.resolve('resources/js/app.js'), 'utf8');
const dbSource = readFileSync(path.resolve('resources/js/offline/offline-db.js'), 'utf8');
const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
const spotBlade = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');

const {
    ASSETS_CACHE,
    AVATAR_CACHE_PREFIX,
    CACHE_VERSION,
    CORE_WARMUP_PATHS,
    HTML_CACHE_PREFIX,
    MESSAGE_WARMUP_CORE,
    MESSAGE_WARMUP_UM_WORKERS,
    MESSAGE_WARMUP_RELATED,
    MUTATION_METHODS,
    WARMUP_PATHS_ADMIN,
    WARMUP_PATHS_SHARED,
    actorIdFromMessage,
    avatarCacheNameForActor,
    coreWarmupPaths,
    fallbackHtml,
    htmlCacheNameForActor,
    isCacheableAssetRequest,
    isCacheableNavigationRequest,
    isCoreWarmupPath,
    isLmlingaOwnedCache,
    isManagedStaffAvatarPath,
    isMutationMethod,
    isNeverCachePath,
    isRelatedWarmPath,
    isLocalMemberViewPath,
    isLocalMemberEditPath,
    isDeathCertificateWritePath,
    allowedNavigationSearch,
    isExcludedWriteGetPath,
    isSafeNavigationPath,
    isSameOrigin,
    isUserManagementHealthWorkerCreatePath,
    isUserManagementHealthWorkerWarmPath,
    obsoleteOwnedCaches,
    SHELL_STATIC_PATHS,
    sanitizeCachedHtml,
    shouldCacheNavigationResponse,
    warmupPathsForRole,
} = await import(policyUrl);

const { createServiceWorkerRuntime } = await import(runtimeUrl);

const ORIGIN = 'https://lmlinga.test';

function navRequest(path, init = {}) {
    const url = `${ORIGIN}${path}`;
    const headers = new Headers({
        Accept: 'text/html',
        ...(init.headers || {}),
    });
    return {
        url,
        method: init.method || 'GET',
        mode: 'navigate',
        destination: 'document',
        headers,
    };
}

function htmlResponse(body, init = {}) {
    return new Response(body, {
        status: init.status ?? 200,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            ...(init.headers || {}),
        },
    });
}

describe('offline service worker policy', () => {
    it('keeps a versioned owned cache family', () => {
        assert.match(CACHE_VERSION, /^offline-7-v\d+$/);
        assert.equal(isLmlingaOwnedCache(ASSETS_CACHE), true);
        assert.equal(isLmlingaOwnedCache('other-app-cache'), false);
        assert.equal(htmlCacheNameForActor(9), `${HTML_CACHE_PREFIX}9`);
        assert.equal(avatarCacheNameForActor(9), `${AVATAR_CACHE_PREFIX}9`);
        assert.equal(htmlCacheNameForActor(0), null);
        assert.equal(avatarCacheNameForActor(0), null);
    });

    it('deletes obsolete owned caches without touching unrelated names', () => {
        const existing = [
            'lmlinga-assets-offline-7-v0',
            'lmlinga-html-offline-7-v0-actor-4',
            'lmlinga-avatar-offline-7-v0-actor-4',
            ASSETS_CACHE,
            `${HTML_CACHE_PREFIX}4`,
            `${AVATAR_CACHE_PREFIX}4`,
            'workbox-precache',
            'random-cache',
        ];
        const obsolete = obsoleteOwnedCaches(existing);
        assert.deepEqual(obsolete.sort(), [
            'lmlinga-assets-offline-7-v0',
            'lmlinga-html-offline-7-v0-actor-4',
            'lmlinga-avatar-offline-7-v0-actor-4',
        ].sort());
        assert.equal(obsolete.includes('workbox-precache'), false);
        assert.equal(obsolete.includes('random-cache'), false);
    });

    it('only treats same-origin GET assets and supported pages as cache candidates', () => {
        const css = new Request(`${ORIGIN}/build/assets/app-abc.css`);
        const cssUrl = new URL(css.url);
        assert.equal(isCacheableAssetRequest(css, cssUrl, ORIGIN), true);
        assert.equal(isSameOrigin('https://tile.openstreetmap.org/1/2/3.png', ORIGIN), false);

        const create = navRequest('/household-profiling/create');
        assert.equal(isCacheableNavigationRequest(create, new URL(create.url), ORIGIN), true);
        assert.equal(isSafeNavigationPath('/spot-mapping'), true);
        assert.equal(isSafeNavigationPath('/dashboard'), true);
        assert.equal(isSafeNavigationPath('/announcements'), true);
        assert.equal(isSafeNavigationPath('/announcements/upcoming'), true);
        assert.equal(isSafeNavigationPath('/announcements/recent'), true);
        assert.equal(isSafeNavigationPath('/announcements/12'), true);
        assert.equal(isSafeNavigationPath('/household-profiling/151/edit'), true);
        assert.equal(isSafeNavigationPath('/household-profiling/151'), true);
        assert.equal(isSafeNavigationPath('/household-profiling/HH-9/members/MB-1/edit'), true);
        assert.equal(isSafeNavigationPath('/household-profiling/151/members/MB-1'), true);
        assert.equal(isSafeNavigationPath('/environmental-health'), true);
        assert.equal(isSafeNavigationPath('/health-records/child-care'), true);
        assert.equal(isSafeNavigationPath('/health-records/risk-assessment'), true);
        assert.equal(isSafeNavigationPath('/health-records/maternal'), true);
        assert.equal(isSafeNavigationPath('/health-records/death'), true);
        assert.equal(isSafeNavigationPath('/health-records/family-planning'), true);
        assert.equal(isSafeNavigationPath('/user-management'), true);
        assert.equal(isSafeNavigationPath('/household-requests'), true);
        assert.equal(isSafeNavigationPath('/death-requests'), true);
        assert.equal(isSafeNavigationPath('/profile'), true);
        const logo = new Request(`${ORIGIN}/assets/images/logo/logo.png`);
        assert.equal(isCacheableAssetRequest(logo, new URL(logo.url), ORIGIN), true);
        const seal = new Request(`${ORIGIN}/assets/images/logo/LMLogo.png`);
        assert.equal(isCacheableAssetRequest(seal, new URL(seal.url), ORIGIN), true);
        assert.equal(SHELL_STATIC_PATHS.includes('/assets/images/logo/logo.png'), true);
    });

    it('never caches mutations, sync, auth, csrf, or sensitive families', () => {
        MUTATION_METHODS.forEach((method) => {
            assert.equal(isMutationMethod(method), true);
        });

        const post = new Request(`${ORIGIN}/household-profiling`, { method: 'POST' });
        assert.equal(isCacheableNavigationRequest(post, new URL(post.url), ORIGIN), false);

        [
            '/offline/sync',
            '/offline/status',
            '/offline/environmental-health-shell/1',
            '/login',
            '/logout',
            '/change-password',
            '/forgot-password',
            '/reset-password/abc',
            '/chatbot/login',
            '/chatbot/household/verification/sms',
            '/household-profiling/export',
            '/announcements/create',
            '/announcements/12/edit',
            '/death-requests/1/certificate',
            '/health-records/death/151/MB-1/certificate',
            '/environmental-health/export',
            '/storage/uploads/other.png',
        ].forEach((pathname) => {
            assert.equal(isNeverCachePath(pathname), true);
            assert.equal(isSafeNavigationPath(pathname), false);
        });

        assert.equal(isSafeNavigationPath('/household-profiling/151'), true);
        assert.equal(isSafeNavigationPath('/household-profiling/151/members/MB-1'), true);
        assert.equal(isSafeNavigationPath('/announcements'), true);
        assert.equal(isSafeNavigationPath('/announcements/create'), false);
        assert.equal(isSafeNavigationPath('/announcements/12/edit'), false);
        assert.equal(isSafeNavigationPath('/user-management/health-workers/create'), false);
        assert.equal(isSafeNavigationPath('/user-management/health-workers/12/edit'), true);
        assert.equal(isSafeNavigationPath('/user-management/health-workers/12/view'), true);
        assert.equal(isSafeNavigationPath('/user-management/health-workers/hw-001/edit'), false);
        assert.equal(isSafeNavigationPath('/health-records/child-care/non-residents/create'), false);
        assert.equal(isSafeNavigationPath('/health-records/family-planning/non-residents/abc/visits/1/edit'), false);
    });

    it('ignores query strings for cache identity and rejects token-like queries', async () => {
        const { hasUnsafeQuery, navigationCacheUrl, isCacheableNavigationRequest } = await import(policyUrl);
        const poisoned = new URL('https://lmlinga.test/household-profiling/create?token=abc');
        assert.equal(hasUnsafeQuery(poisoned), true);
        const create = navRequest('/household-profiling/create?utm=1');
        const createUrl = new URL(create.url);
        assert.equal(hasUnsafeQuery(createUrl), false);
        assert.equal(isCacheableNavigationRequest(create, createUrl, ORIGIN), true);
        assert.equal(navigationCacheUrl(ORIGIN, createUrl.pathname), 'https://lmlinga.test/household-profiling/create');
    });

    it('strips csrf from cached HTML and keeps fallback generic', () => {
        const html = sanitizeCachedHtml(
            '<meta name="csrf-token" content="secret-token"><input name="_token" value="secret-token">',
        );
        assert.equal(html.includes('secret-token'), false);
        assert.match(html, /csrf-token/);
        assert.doesNotMatch(
            sanitizeCachedHtml('<div data-lml-hws data-hws-bound="1"><input data-hws-level></div>'),
            /data-hws-bound/,
        );

        const fallback = fallbackHtml();
        assert.match(fallback, /You are offline/);
        assert.doesNotMatch(fallback, /stack trace|csrf-token content="[^"]+"/i);
        assert.doesNotMatch(fallback, /\/offline\/sync/);
        assert.equal(actorIdFromMessage({ actorId: '12' }), 12);
    });

    it('registers from app bootstrap without touching IndexedDB', () => {
        assert.match(appSource, /offline\/offline-sw-register/);
        assert.match(registerSource, /navigator\.serviceWorker\.register/);
        assert.match(registerSource, /\/sw\.js/);
        assert.match(registerSource, /data-lml-offline-root/);
        assert.doesNotMatch(registerSource, /indexedDB\.deleteDatabase/);
        assert.doesNotMatch(swSource, /indexedDB/);
        assert.match(dbSource, /lmlinga_offline/);
        assert.doesNotMatch(registerSource, /location\.reload/);
        assert.match(registerSource, /confirmSessionAndWarmCorePages/);
        assert.match(registerSource, /offline-sw-warmup/);
    });

    it('warms authorized fixed GET pages by role and keeps writes/dynamic IDs off the warmup list', () => {
        assert.deepEqual([...WARMUP_PATHS_SHARED], [
            '/dashboard',
            '/spot-mapping',
            '/household-profiling',
            '/household-profiling/create',
            '/announcements',
            '/environmental-health',
            '/health-records/child-care',
            '/health-records/risk-assessment',
            '/health-records/maternal',
            '/health-records/death',
            '/health-records/family-planning',
            '/profile',
        ]);
        assert.deepEqual([...WARMUP_PATHS_ADMIN], [
            '/household-requests',
            '/death-requests',
        ]);
        assert.deepEqual(warmupPathsForRole('bhw'), [...WARMUP_PATHS_SHARED]);
        assert.deepEqual(warmupPathsForRole('bns'), [...WARMUP_PATHS_SHARED]);
        assert.deepEqual(warmupPathsForRole('bspo'), [...WARMUP_PATHS_SHARED]);
        assert.deepEqual(warmupPathsForRole('admin'), [...WARMUP_PATHS_SHARED, ...WARMUP_PATHS_ADMIN]);
        assert.deepEqual(warmupPathsForRole('resident'), []);
        assert.deepEqual(warmupPathsForRole(null), []);
        assert.deepEqual(coreWarmupPaths(), [...WARMUP_PATHS_SHARED]);
        assert.deepEqual([...CORE_WARMUP_PATHS], [...WARMUP_PATHS_SHARED]);

        WARMUP_PATHS_SHARED.forEach((path) => {
            assert.equal(isCoreWarmupPath(path), true);
            assert.equal(isSafeNavigationPath(path), true);
            assert.equal(isNeverCachePath(path), false);
        });
        WARMUP_PATHS_ADMIN.forEach((path) => {
            assert.equal(isCoreWarmupPath(path), false);
            assert.equal(warmupPathsForRole('bhw').includes(path), false);
            assert.equal(warmupPathsForRole('admin').includes(path), true);
            assert.equal(isSafeNavigationPath(path), true);
            assert.equal(isNeverCachePath(path), false);
        });
        [
            '/announcements/upcoming',
            '/announcements/recent',
            '/announcements/12',
            '/announcements/create',
            '/announcements/12/edit',
            '/health-records',
            '/login',
            '/logout',
            '/change-password',
            '/household-profiling/151',
            '/household-profiling/151/edit',
            '/household-requests/req-1/view',
            '/death-requests/4',
            '/user-management/health-workers/1/view',
            '/user-management/health-workers/1/edit',
            '/user-management/health-workers/create',
        ].forEach((path) => {
            assert.equal(isCoreWarmupPath(path), false);
            assert.equal(warmupPathsForRole('admin').includes(path), false);
        });
        assert.equal(warmupPathsForRole('admin').includes('/user-management'), false);
        assert.equal(isSafeNavigationPath('/user-management'), true);
        assert.equal(
            shouldCacheNavigationResponse(
                { ok: true, status: 200, type: 'basic', headers: { get: () => 'text/html' } },
                `${ORIGIN}/user-management`,
                ORIGIN,
            ),
            false,
        );
        assert.equal(isSafeNavigationPath('/announcements'), true);
        assert.equal(isNeverCachePath('/announcements'), false);
        assert.equal(isSafeNavigationPath('/household-profiling/create'), true);
        assert.equal(isCoreWarmupPath('/household-profiling/create'), true);
        assert.equal(isManagedStaffAvatarPath('/storage/health-workers/profile-photos/abc.png'), true);
        assert.equal(isManagedStaffAvatarPath('/storage/uploads/other.png'), false);
        assert.equal(MESSAGE_WARMUP_CORE, 'lmlinga:warmup-core');
        assert.equal(MESSAGE_WARMUP_UM_WORKERS, 'lmlinga:warmup-um-workers');
        assert.equal(isUserManagementHealthWorkerWarmPath('/user-management/health-workers/30/view'), true);
        assert.equal(isUserManagementHealthWorkerWarmPath('/user-management/health-workers/30/edit'), true);
        assert.equal(isUserManagementHealthWorkerWarmPath('/user-management/health-workers/create'), false);
        assert.equal(isUserManagementHealthWorkerCreatePath('/user-management/health-workers/create'), true);
        assert.equal(isUserManagementHealthWorkerWarmPath('/user-management/health-workers/hw-001/view'), false);
        assert.equal(CACHE_VERSION, 'offline-7-v20');
        assert.equal(MESSAGE_WARMUP_RELATED, 'lmlinga:warmup-related');
        assert.equal(isRelatedWarmPath('/household-profiling/HH-121'), true);
        assert.equal(isRelatedWarmPath('/household-profiling/HH-121/amenities'), true);
        assert.equal(isRelatedWarmPath('/household-profiling/HH-121/members/MB-001/child-immunization'), true);
        assert.equal(isRelatedWarmPath('/household-profiling/HH-121/members/MB-001/death/create'), false);
        assert.equal(isLocalMemberViewPath('/household-profiling/HH-121/members/MB-L-abc123'), true);
        assert.equal(isLocalMemberEditPath('/household-profiling/HH-121/members/MB-L-abc123/edit'), true);
        assert.equal(isSafeNavigationPath('/household-profiling/HH-121/members/MB-L-abc123/edit'), true);
        assert.equal(isRelatedWarmPath('/household-profiling/HH-121/members/MB-L-abc123/child-immunization'), true);
        assert.equal(isDeathCertificateWritePath('/household-profiling/HH-121/members/MB-001/death/edit'), true);
        assert.equal(isExcludedWriteGetPath('/household-profiling/HH-121/amenities/edit'), false);
        assert.equal(isExcludedWriteGetPath('/household-profiling/HH-121/members/MB-001/maternal-care/register'), false);
        assert.equal(allowedNavigationSearch('/environmental-health/household-water-supply', '?household=HH-121'), '?household=HH-121');
        assert.equal(allowedNavigationSearch('/environmental-health/household-water-supply', '?handoff=abc'), '');
    });
});

describe('offline service worker runtime', () => {
    function setup() {
        const caches = createMemoryCaches();
        const network = new Map();
        const runtime = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches,
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                const key = `${request.method || 'GET'} ${url}`;
                if (!network.has(key) && network.has(url)) {
                    return network.get(url)();
                }
                if (!network.has(key)) {
                    throw new TypeError('Failed to fetch');
                }
                return network.get(key)();
            },
        });
        return { caches, network, runtime };
    }

    it('serves a previously cached supported page offline for the same actor', async () => {
        const { network, runtime } = setup();
        await runtime.setActor(7);
        network.set('GET https://lmlinga.test/household-profiling/create', () =>
            htmlResponse('<html><body>Create HH<meta name="csrf-token" content="live-csrf"></body></html>'),
        );

        const first = await runtime.handleFetch(navRequest('/household-profiling/create'));
        assert.equal(first.status, 200);
        const live = await first.text();
        assert.match(live, /live-csrf/);
        assert.equal(live.includes('lmlinga-offline-cache'), false);

        network.clear();
        const offline = await runtime.handleFetch(navRequest('/household-profiling/create'));
        const cached = await offline.text();
        assert.match(cached, /Create HH/);
        assert.equal(cached.includes('live-csrf'), false);
        assert.match(cached, /lmlinga-offline-cache/);
        assert.equal(offline.headers.get('X-Lmlinga-Offline-Cache'), '1');
    });

    it('returns the controlled fallback for unsupported uncached navigation', async () => {
        const { runtime } = setup();
        await runtime.setActor(7);
        const response = await runtime.handleFetch(navRequest('/health-records/child-care'));
        const body = await response.text();
        assert.match(body, /You are offline/);
        assert.match(body, /emergency page/i);
        assert.equal(response.headers.get('X-Lmlinga-Offline-Fallback'), '1');
    });

    it('does not cache online-only, csrf, or mutation requests', async () => {
        const { network, runtime } = setup();
        await runtime.setActor(7);

        let loginHits = 0;
        network.set('GET https://lmlinga.test/login', () => {
            loginHits += 1;
            return htmlResponse('<html>login</html>');
        });
        await runtime.handleFetch(navRequest('/login'));
        await runtime.handleFetch(navRequest('/login'));
        assert.equal(loginHits, 2);

        let statusHits = 0;
        network.set('GET https://lmlinga.test/offline/status', () => {
            statusHits += 1;
            return new Response(JSON.stringify({ ok: true, csrf_token: 'fresh' }), {
                headers: { 'Content-Type': 'application/json' },
            });
        });
        const statusReq = new Request(`${ORIGIN}/offline/status`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        await runtime.handleFetch(statusReq);
        await runtime.handleFetch(statusReq);
        assert.equal(statusHits, 2);

        let syncHits = 0;
        network.set('POST https://lmlinga.test/offline/sync', () => {
            syncHits += 1;
            return new Response(JSON.stringify({ ok: true }), {
                headers: { 'Content-Type': 'application/json' },
            });
        });
        const syncReq = () =>
            new Request(`${ORIGIN}/offline/sync`, {
                method: 'POST',
                body: '{}',
            });
        await runtime.handleFetch(syncReq());
        await runtime.handleFetch(syncReq());
        assert.equal(syncHits, 2);
    });

    it('isolates cached HTML by actor and clears it on logout without touching IndexedDB', async () => {
        const { network, runtime } = setup();
        await runtime.setActor(7);
        network.set('GET https://lmlinga.test/dashboard', () =>
            htmlResponse('<html>actor-seven</html>'),
        );
        await runtime.handleFetch(navRequest('/dashboard'));

        await runtime.handleMessage({ type: 'lmlinga:set-actor', actorId: 8 });
        network.clear();
        const otherActor = await runtime.handleFetch(navRequest('/dashboard'));
        const otherBody = await otherActor.text();
        assert.equal(otherBody.includes('actor-seven'), false);
        assert.match(otherBody, /You are offline/);

        await runtime.setActor(7);
        network.set('GET https://lmlinga.test/dashboard', () =>
            htmlResponse('<html>actor-seven-again</html>'),
        );
        await runtime.handleFetch(navRequest('/dashboard'));
        network.set('POST https://lmlinga.test/logout', () =>
            htmlResponse('<html>login</html>', { status: 302 }),
        );
        await runtime.handleFetch(new Request(`${ORIGIN}/logout`, { method: 'POST' }));
        network.clear();
        const afterLogout = await runtime.handleFetch(navRequest('/dashboard'));
        assert.match(await afterLogout.text(), /You are offline/);
        assert.equal(await runtime.getActorId(), null);
        assert.match(dbSource, /lmlinga_offline/);
    });

    it('passes OpenStreetMap tiles through without storing them', async () => {
        const { network, runtime } = setup();
        let hits = 0;
        const tile = 'https://a.tile.openstreetmap.org/16/1/2.png';
        network.set(`GET ${tile}`, () => {
            hits += 1;
            return new Response('tile', { headers: { 'Content-Type': 'image/png' } });
        });
        const response = await runtime.handleFetch(new Request(tile));
        assert.equal(await response.text(), 'tile');
        assert.equal(hits, 1);
    });

    it('serves shell branding images from cache when the network fails', async () => {
        const { network, runtime } = setup();
        const logoUrl = `${ORIGIN}/assets/images/logo/logo.png`;
        const sealUrl = `${ORIGIN}/assets/images/logo/LMLogo.png`;
        network.set(`GET ${logoUrl}`, () => new Response('logo-bytes', { headers: { 'Content-Type': 'image/png' } }));
        network.set(`GET ${sealUrl}`, () => new Response('seal-bytes', { headers: { 'Content-Type': 'image/png' } }));

        const logoReq = new Request(logoUrl);
        const sealReq = new Request(sealUrl);
        assert.equal(await (await runtime.handleFetch(logoReq)).text(), 'logo-bytes');
        assert.equal(await (await runtime.handleFetch(sealReq)).text(), 'seal-bytes');

        network.clear();
        assert.equal(await (await runtime.handleFetch(new Request(logoUrl))).text(), 'logo-bytes');
        assert.equal(await (await runtime.handleFetch(new Request(sealUrl))).text(), 'seal-bytes');
    });

    it('does not cache storage uploads or auth responses as shell assets', async () => {
        const { network, runtime } = setup();
        let storageHits = 0;
        const storageUrl = `${ORIGIN}/storage/health-workers/profile-photos/secret.png`;
        network.set(`GET ${storageUrl}`, () => {
            storageHits += 1;
            return new Response('private-photo', { headers: { 'Content-Type': 'image/png' } });
        });
        await runtime.handleFetch(new Request(storageUrl));
        await runtime.handleFetch(new Request(storageUrl));
        assert.equal(storageHits, 2);
        network.delete(`GET ${storageUrl}`);
        const missingStorage = await runtime.handleFetch(new Request(storageUrl));
        assert.equal(missingStorage.status, 404);
        assert.equal(await missingStorage.text(), '');

        let loginHits = 0;
        network.set('GET https://lmlinga.test/login', () => {
            loginHits += 1;
            return htmlResponse('<html>login-secret</html>');
        });
        await runtime.handleFetch(navRequest('/login'));
        await runtime.handleFetch(navRequest('/login'));
        assert.equal(loginHits, 2);
        network.delete('GET https://lmlinga.test/login');
        const offlineLogin = await runtime.handleFetch(navRequest('/login'));
        const offlineBody = await offlineLogin.text();
        assert.equal(offlineBody.includes('login-secret'), false);
        assert.match(offlineBody, /You are offline/);
    });

    it('warms role-authorized GET HTML into the actor-scoped cache after the actor is known', async () => {
        const { caches, network, runtime } = setup();
        const hits = new Map();
        [...WARMUP_PATHS_SHARED, ...WARMUP_PATHS_ADMIN].forEach((path) => {
            network.set(`GET ${ORIGIN}${path}`, () => {
                hits.set(path, (hits.get(path) || 0) + 1);
                return htmlResponse(`<html><body>${path}<meta name="csrf-token" content="warm-csrf"></body></html>`);
            });
        });
        network.set(`GET ${ORIGIN}/announcements/create`, () => htmlResponse('<html>announce-create-secret</html>'));
        network.set(`GET ${ORIGIN}/login`, () => htmlResponse('<html>login-secret</html>'));

        const beforeActor = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(beforeActor.ok, false);
        assert.equal(hits.size, 0);

        await runtime.setActor(7);
        const noRole = await runtime.warmCoreNavigation(7);
        assert.equal(noRole.ok, false);
        assert.equal(noRole.reason, 'no-role');

        const warmed = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(warmed.ok, true);
        assert.deepEqual(warmed.warmed.sort(), [...WARMUP_PATHS_SHARED].sort());

        const cache = await caches.open(`${HTML_CACHE_PREFIX}7`);
        for (const path of WARMUP_PATHS_SHARED) {
            const hit = await cache.match(new Request(`${ORIGIN}${path}`));
            assert.ok(hit, path);
            const body = await hit.text();
            assert.match(body, new RegExp(path.replaceAll('/', '\\/')));
            assert.equal(body.includes('warm-csrf'), false);
            assert.match(body, /lmlinga-offline-cache/);
        }

        const other = await caches.open(`${HTML_CACHE_PREFIX}8`);
        const leaked = await other.match(new Request(`${ORIGIN}/dashboard`));
        assert.equal(leaked, undefined);

        const announcements = await cache.match(new Request(`${ORIGIN}/announcements`));
        const create = await cache.match(new Request(`${ORIGIN}/household-profiling/create`));
        const announceCreate = await cache.match(new Request(`${ORIGIN}/announcements/create`));
        const login = await cache.match(new Request(`${ORIGIN}/login`));
        const users = await cache.match(new Request(`${ORIGIN}/user-management`));
        const requests = await cache.match(new Request(`${ORIGIN}/household-requests`));
        const deaths = await cache.match(new Request(`${ORIGIN}/death-requests`));
        assert.ok(announcements);
        assert.equal((await announcements.text()).includes('warm-csrf'), false);
        assert.ok(create);
        assert.match(await create.text(), /household-profiling\/create/);
        assert.equal(announceCreate, undefined);
        assert.equal(login, undefined);
        assert.equal(users, undefined);
        assert.equal(requests, undefined);
        assert.equal(deaths, undefined);
        assert.equal(hits.get('/dashboard'), 1);
        assert.equal(hits.get('/household-profiling/create'), 1);
        assert.equal(hits.get('/user-management'), undefined);

        const again = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(again.ok, true);
        assert.equal(hits.get('/dashboard'), 1);
        assert.equal(hits.get('/spot-mapping'), 1);
        assert.equal(hits.get('/environmental-health'), 1);
    });

    it('warms admin-only indexes for admin and never stores a staff 403 for those routes', async () => {
        const { caches, network, runtime } = setup();
        [...WARMUP_PATHS_SHARED, ...WARMUP_PATHS_ADMIN].forEach((path) => {
            network.set(`GET ${ORIGIN}${path}`, () => htmlResponse(`<html>${path}</html>`));
        });
        // UM index is intentionally not warmed/cached (mutation forms + CSRF).
        network.set(`GET ${ORIGIN}/user-management`, () => htmlResponse('<html>/user-management</html>'));

        await runtime.setActor(7);
        const adminWarm = await runtime.warmCoreNavigation(7, { role: 'admin' });
        assert.equal(adminWarm.ok, true);
        assert.equal(adminWarm.warmed.includes('/user-management'), false);
        assert.equal(adminWarm.warmed.includes('/household-requests'), true);
        assert.equal(adminWarm.warmed.includes('/death-requests'), true);

        const adminCache = await caches.open(`${HTML_CACHE_PREFIX}7`);
        assert.equal(await adminCache.match(new Request(`${ORIGIN}/user-management`)), undefined);
        assert.ok(await adminCache.match(new Request(`${ORIGIN}/household-requests`)));

        await runtime.handleMessage({ type: 'lmlinga:set-actor', actorId: 8 });
        network.set(`GET ${ORIGIN}/household-requests`, () => htmlResponse('<html>forbidden</html>', { status: 403 }));
        WARMUP_PATHS_SHARED.forEach((path) => {
            network.set(`GET ${ORIGIN}${path}`, () => htmlResponse(`<html>${path}</html>`));
        });
        const staffWarm = await runtime.warmCoreNavigation(8, { role: 'bhw' });
        assert.equal(staffWarm.warmed.includes('/household-requests'), false);
        assert.equal(staffWarm.warmed.includes('/user-management'), false);
        const staffCache = await caches.open(`${HTML_CACHE_PREFIX}8`);
        assert.equal(await staffCache.match(new Request(`${ORIGIN}/household-requests`)), undefined);
        assert.equal(await staffCache.match(new Request(`${ORIGIN}/user-management`)), undefined);
        assert.ok(await staffCache.match(new Request(`${ORIGIN}/environmental-health`)));
    });

    it('does not cache user-management index HTML even when navigated online', async () => {
        const { caches, network, runtime } = setup();
        await runtime.setActor(7);
        network.set(`GET ${ORIGIN}/user-management`, () => htmlResponse('<html><meta name="csrf-token" content="live-token"></html>'));

        const response = await runtime.handleFetch(navRequest('/user-management'));
        assert.equal(response.status, 200);
        assert.match(await response.text(), /live-token/);

        const cache = await caches.open(`${HTML_CACHE_PREFIX}7`);
        assert.equal(await cache.match(new Request(`${ORIGIN}/user-management`)), undefined);
    });

    it('does not poison the HTML cache when a warmup request fails', async () => {
        const { caches, network, runtime } = setup();
        await runtime.setActor(7);
        network.set(`GET ${ORIGIN}/dashboard`, () => htmlResponse('<html>dashboard-ok</html>'));
        network.set(`GET ${ORIGIN}/spot-mapping`, () => htmlResponse('<html>broken</html>', { status: 500 }));
        network.set(`GET ${ORIGIN}/household-profiling`, () => {
            throw new TypeError('Failed to fetch');
        });
        network.set(`GET ${ORIGIN}/announcements`, () => {
            const response = htmlResponse('<html>login-redirect</html>');
            Object.defineProperty(response, 'url', { value: `${ORIGIN}/login` });
            return response;
        });
        network.set(`GET ${ORIGIN}/environmental-health`, () => htmlResponse('<html>unauth</html>', { status: 401 }));
        network.set(`GET ${ORIGIN}/health-records/child-care`, () => htmlResponse('<html>forbidden</html>', { status: 403 }));
        network.set(`GET ${ORIGIN}/profile`, () => htmlResponse('<html>expired</html>', { status: 419 }));

        const result = await runtime.warmCoreNavigation(7, { role: 'bhw' });
        assert.equal(result.ok, false);
        assert.equal(result.reason, 'partial');
        assert.deepEqual(result.warmed, ['/dashboard']);
        assert.ok(result.failed.length > 0);
        assert.ok(result.percentage < 100);

        const cache = await caches.open(`${HTML_CACHE_PREFIX}7`);
        assert.ok(await cache.match(new Request(`${ORIGIN}/dashboard`)));
        assert.equal(await cache.match(new Request(`${ORIGIN}/spot-mapping`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/household-profiling`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/announcements`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/login`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/environmental-health`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/health-records/child-care`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/profile`)), undefined);
    });

    it('rejects warmup for a mismatched actor and does not cache during install', async () => {
        const fetched = [];
        const { network, runtime } = setup();
        const originalFetch = runtime;
        network.set(`GET ${ORIGIN}/build/manifest.json`, () =>
            new Response('{}', { headers: { 'Content-Type': 'application/json' } }),
        );
        SHELL_STATIC_PATHS.forEach((path) => {
            network.set(`GET ${ORIGIN}${path}`, () => new Response('asset', { headers: { 'Content-Type': 'image/png' } }));
        });
        WARMUP_PATHS_SHARED.forEach((path) => {
            network.set(`GET ${ORIGIN}${path}`, () => htmlResponse(`<html>${path}</html>`));
        });

        const tracking = createServiceWorkerRuntime({
            origin: ORIGIN,
            caches: createMemoryCaches(),
            fetch: async (request) => {
                const url = typeof request === 'string' ? request : request.url;
                fetched.push(url);
                const key = `${request.method || 'GET'} ${url}`;
                if (!network.has(key)) {
                    throw new TypeError('Failed to fetch');
                }
                return network.get(key)();
            },
        });

        await tracking.install();
        assert.equal(fetched.some((url) => WARMUP_PATHS_SHARED.some((path) => url.endsWith(path) && !url.includes('/assets/'))), false);
        assert.equal(fetched.some((url) => url.includes('/dashboard')), false);
        assert.equal(fetched.some((url) => url.includes('/spot-mapping')), false);

        await tracking.setActor(7);
        const mismatch = await tracking.warmCoreNavigation(9, { role: 'bhw' });
        assert.equal(mismatch.ok, false);
        assert.equal(mismatch.reason, 'actor-mismatch');
        void originalFetch;
    });

    it('still runtime-caches household and member pages opened while online', async () => {
        const { network, runtime } = setup();
        await runtime.setActor(7);
        network.set(`GET ${ORIGIN}/household-profiling/151`, () => htmlResponse('<html>view-151</html>'));
        network.set(`GET ${ORIGIN}/household-profiling/151/edit`, () => htmlResponse('<html>edit-151</html>'));
        network.set(`GET ${ORIGIN}/household-profiling/151/members/create`, () => htmlResponse('<html>member-create</html>'));
        network.set(`GET ${ORIGIN}/household-profiling/151/members/MB-1/edit`, () => htmlResponse('<html>member-edit</html>'));
        network.set(`GET ${ORIGIN}/health-records/child-care`, () => htmlResponse('<html>child-care-index</html>'));
        network.set(`GET ${ORIGIN}/household-requests/req-9/view`, () => htmlResponse('<html>request-9</html>'));

        await runtime.handleFetch(navRequest('/household-profiling/151'));
        await runtime.handleFetch(navRequest('/household-profiling/151/edit'));
        await runtime.handleFetch(navRequest('/household-profiling/151/members/create'));
        await runtime.handleFetch(navRequest('/household-profiling/151/members/MB-1/edit'));
        await runtime.handleFetch(navRequest('/health-records/child-care'));
        await runtime.handleFetch(navRequest('/household-requests/req-9/view'));

        network.clear();
        assert.match(await (await runtime.handleFetch(navRequest('/household-profiling/151'))).text(), /view-151/);
        assert.match(await (await runtime.handleFetch(navRequest('/household-profiling/151/edit'))).text(), /edit-151/);
        assert.match(await (await runtime.handleFetch(navRequest('/household-profiling/151/members/create'))).text(), /member-create/);
        assert.match(await (await runtime.handleFetch(navRequest('/household-profiling/151/members/MB-1/edit'))).text(), /member-edit/);
        assert.match(await (await runtime.handleFetch(navRequest('/health-records/child-care'))).text(), /child-care-index/);
        assert.match(await (await runtime.handleFetch(navRequest('/household-requests/req-9/view'))).text(), /request-9/);
    });

    it('runtime-caches an opened numeric Health Worker view/edit but never create', async () => {
        const { caches, network, runtime } = setup();
        await runtime.setActor(7);
        network.set(`GET ${ORIGIN}/user-management/health-workers/12/view`, () => htmlResponse('<html>hw-view-12</html>'));
        network.set(`GET ${ORIGIN}/user-management/health-workers/12/edit`, () => htmlResponse('<html>hw-edit-12</html>'));
        network.set(`GET ${ORIGIN}/user-management/health-workers/create`, () => htmlResponse('<html>hw-create-secret</html>'));

        await runtime.handleFetch(navRequest('/user-management/health-workers/12/view'));
        await runtime.handleFetch(navRequest('/user-management/health-workers/12/edit'));
        await runtime.handleFetch(navRequest('/user-management/health-workers/create'));

        network.clear();
        assert.match(
            await (await runtime.handleFetch(navRequest('/user-management/health-workers/12/view'))).text(),
            /hw-view-12/,
        );
        assert.match(
            await (await runtime.handleFetch(navRequest('/user-management/health-workers/12/edit'))).text(),
            /hw-edit-12/,
        );

        const cache = await caches.open(`${HTML_CACHE_PREFIX}7`);
        assert.equal(await cache.match(new Request(`${ORIGIN}/user-management/health-workers/create`)), undefined);

        const createOffline = await runtime.handleFetch(navRequest('/user-management/health-workers/create'));
        assert.match(await createOffline.text(), /You are offline/);
    });

    it('caches announcement read HTML per actor and rejects write/error/login responses', async () => {
        const { caches, network, runtime } = setup();
        await runtime.setActor(30);
        network.set(`GET ${ORIGIN}/announcements`, () =>
            htmlResponse('<html>actor-30-notices<meta name="csrf-token" content="live-csrf"></html>'),
        );
        network.set(`GET ${ORIGIN}/announcements/12`, () => htmlResponse('<html>notice-12-body</html>'));
        network.set(`GET ${ORIGIN}/announcements/upcoming`, () => htmlResponse('<html>upcoming-list</html>'));

        await runtime.handleFetch(navRequest('/announcements'));
        await runtime.handleFetch(navRequest('/announcements/12'));
        await runtime.handleFetch(navRequest('/announcements/upcoming'));

        let createHits = 0;
        network.set(`GET ${ORIGIN}/announcements/create`, () => {
            createHits += 1;
            return htmlResponse('<html>create-form-secret</html>');
        });
        await runtime.handleFetch(navRequest('/announcements/create'));
        await runtime.handleFetch(navRequest('/announcements/create'));
        assert.equal(createHits, 2);

        let postHits = 0;
        network.set(`POST ${ORIGIN}/announcements`, () => {
            postHits += 1;
            return htmlResponse('<html>posted</html>', { status: 302 });
        });
        await runtime.handleFetch(new Request(`${ORIGIN}/announcements`, { method: 'POST', body: 'title=x' }));
        await runtime.handleFetch(new Request(`${ORIGIN}/announcements`, { method: 'POST', body: 'title=x' }));
        assert.equal(postHits, 2);

        network.clear();
        const cachedIndex = await (await runtime.handleFetch(navRequest('/announcements'))).text();
        assert.match(cachedIndex, /actor-30-notices/);
        assert.equal(cachedIndex.includes('live-csrf'), false);
        assert.match(await (await runtime.handleFetch(navRequest('/announcements/12'))).text(), /notice-12-body/);

        await runtime.handleMessage({ type: 'lmlinga:set-actor', actorId: 31 });
        const otherActor = await (await runtime.handleFetch(navRequest('/announcements'))).text();
        assert.equal(otherActor.includes('actor-30-notices'), false);
        assert.match(otherActor, /You are offline/);

        await runtime.setActor(30);
        const cache = await caches.open(`${HTML_CACHE_PREFIX}30`);
        assert.equal(await cache.match(new Request(`${ORIGIN}/announcements/create`)), undefined);

        network.set(`GET ${ORIGIN}/announcements/99`, () => htmlResponse('<html>broken-notice</html>', { status: 500 }));
        await runtime.handleFetch(navRequest('/announcements/99'));
        assert.equal(await cache.match(new Request(`${ORIGIN}/announcements/99`)), undefined);

        network.set(`GET ${ORIGIN}/announcements/100`, () => {
            const response = htmlResponse('<html>login-html</html>');
            Object.defineProperty(response, 'url', { value: `${ORIGIN}/login` });
            return response;
        });
        await runtime.handleFetch(navRequest('/announcements/100'));
        assert.equal(await cache.match(new Request(`${ORIGIN}/announcements/100`)), undefined);
        assert.equal(await cache.match(new Request(`${ORIGIN}/login`)), undefined);
    });

    it('keeps Plot New Household on the warmed Spot Mapping shell and requires a plotted location', () => {
        assert.match(spotBlade, /data-spot-map-plot/);
        assert.match(spotBlade, /data-plot-new-url="\{\{ route\('spot-mapping.plot-new'\) \}\}"/);
        assert.match(spotBlade, /name="first_name"/);
        assert.doesNotMatch(spotBlade, /household-profiling\/create/);
        assert.match(spotSource, /isEditingActiveTempPlot\(\)/);
        assert.match(spotSource, /queuePlotNewHousehold/);
        assert.match(spotSource, /data-plot-new-url/);
        assert.match(spotSource, /\/spot-mapping\/plot-new/);
        const confirmHandler = spotSource.slice(
            spotSource.indexOf("confirmBtn?.addEventListener('click'"),
            spotSource.indexOf('const createUrl'),
        );
        assert.match(confirmHandler, /isEditingActiveTempPlot/);
        assert.match(confirmHandler, /validatePlotForm/);
        assert.doesNotMatch(spotSource, /household-profiling\/create/);
        assert.equal(isCoreWarmupPath('/spot-mapping'), true);
        assert.equal(isCoreWarmupPath('/household-profiling/create'), true);
    });

    it('caches only the current actor avatar and clears it on logout', async () => {
        const { caches, network, runtime } = setup();
        const avatarPath = '/storage/health-workers/profile-photos/actor-seven.png';
        const otherPath = '/storage/health-workers/profile-photos/other-actor.png';
        WARMUP_PATHS_SHARED.forEach((path) => {
            network.set(`GET ${ORIGIN}${path}`, () => htmlResponse(`<html>${path}</html>`));
        });
        network.set(`GET ${ORIGIN}${avatarPath}`, () =>
            new Response('seven-photo', { headers: { 'Content-Type': 'image/png' } }),
        );
        network.set(`GET ${ORIGIN}${otherPath}`, () =>
            new Response('other-photo', { headers: { 'Content-Type': 'image/png' } }),
        );

        await runtime.setActor(7);
        await runtime.warmCoreNavigation(7, { role: 'bhw', avatarUrl: avatarPath });

        const avatarCache = await caches.open(`${AVATAR_CACHE_PREFIX}7`);
        const stored = await avatarCache.match(new Request(`${ORIGIN}${avatarPath}`));
        assert.ok(stored);
        assert.equal(await stored.text(), 'seven-photo');

        network.clear();
        const offlineAvatar = await runtime.handleFetch(new Request(`${ORIGIN}${avatarPath}`));
        assert.equal(await offlineAvatar.text(), 'seven-photo');
        assert.equal(offlineAvatar.headers.get('X-Lmlinga-Offline-Cache'), '1');

        const otherOffline = await runtime.handleFetch(new Request(`${ORIGIN}${otherPath}`));
        assert.equal(otherOffline.status, 404);

        network.set(`POST ${ORIGIN}/logout`, () => htmlResponse('<html>login</html>', { status: 302 }));
        await runtime.handleFetch(new Request(`${ORIGIN}/logout`, { method: 'POST' }));
        const missing = await runtime.handleFetch(new Request(`${ORIGIN}${avatarPath}`));
        assert.equal(missing.status, 404);
        assert.equal(await caches.open(`${AVATAR_CACHE_PREFIX}7`).then((cache) => cache.match(new Request(`${ORIGIN}${avatarPath}`))), undefined);
    });

    it('does not runtime-cache health-record create/edit forms', async () => {
        const { caches, network, runtime } = setup();
        await runtime.setActor(7);
        let createHits = 0;
        network.set(`GET ${ORIGIN}/health-records/child-care/non-residents/create`, () => {
            createHits += 1;
            return htmlResponse('<html>create-secret</html>');
        });
        await runtime.handleFetch(navRequest('/health-records/child-care/non-residents/create'));
        await runtime.handleFetch(navRequest('/health-records/child-care/non-residents/create'));
        assert.equal(createHits, 2);
        const cache = await caches.open(`${HTML_CACHE_PREFIX}7`);
        assert.equal(await cache.match(new Request(`${ORIGIN}/health-records/child-care/non-residents/create`)), undefined);
    });

    it('CSS/JS cache miss offline returns asset miss, not HTML offline shell', async () => {
        const { network, runtime } = setup();
        const cssUrl = `${ORIGIN}/build/assets/app-MISSING.css`;
        network.set(`GET ${cssUrl}`, () => {
            throw new Error('offline');
        });
        const response = await runtime.handleFetch(new Request(cssUrl));
        assert.equal(response.status, 504);
        assert.equal(response.headers.get('X-Lmlinga-Offline-Asset'), 'miss');
        const body = await response.text();
        assert.equal(body.includes('You are offline'), false);
        assert.equal(/<!DOCTYPE|<html/i.test(body), false);
        assert.notEqual(response.headers.get('Content-Type') || '', 'text/html; charset=utf-8');

        // Navigation still uses HTML fallback when nothing is cached.
        network.clear();
        const nav = await runtime.handleFetch(navRequest('/household-profiling'));
        assert.equal(nav.status, 200);
        assert.match(await nav.text(), /You are offline/);
    });
});
