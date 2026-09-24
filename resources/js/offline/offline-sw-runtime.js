/**
 * OFFLINE-7 fetch/install runtime. Inject caches/fetch so Node tests can exercise it.
 */

import {
    ACTOR_META_PATH,
    ASSETS_CACHE,
    CACHE_VERSION,
    FALLBACK_CACHE,
    META_CACHE,
    avatarCacheNameForActor,
    avatarPathFromUrl,
    fallbackHtml,
    hasUnsafeQuery,
    headersForCachedResponse,
    htmlCacheNameForActor,
    htmlUsesViteDevAssets,
    isCacheableAssetRequest,
    isCacheableNavigationRequest,
    isDevelopmentOnlyPath,
    isLogoutRequest,
    isManagedStaffAvatarPath,
    isMutationMethod,
    isNeverCachePath,
    isSameOrigin,
    isStaticAssetPath,
    MESSAGE_CLEAR_HTML,
    MESSAGE_ENSURE_BUILD_ASSETS,
    MESSAGE_ENSURE_BUILD_ASSETS_RESULT,
    MESSAGE_SET_ACTOR,
    MESSAGE_WARMUP_COMPLETE,
    MESSAGE_WARMUP_CORE,
    MESSAGE_WARMUP_PROGRESS,
    MESSAGE_WARMUP_READY_QUERY,
    MESSAGE_WARMUP_READY_RESULT,
    MESSAGE_WARMUP_RELATED,
    MESSAGE_WARMUP_UM_WORKERS,
    criticalBuildAssetPathsFromManifest,
    isLocalMemberEditPath,
    isLocalMemberHealthPath,
    isLocalMemberViewPath,
    isRelatedWarmPath,
    isUserManagementHealthWorkerWarmPath,
    navigationCacheUrl,
    normalizePathname,
    obsoleteOwnedCaches,
    sanitizeCachedHtml,
    SHELL_STATIC_PATHS,
    shouldCacheActorAvatarResponse,
    shouldCacheNavigationResponse,
    warmPathStatusLabel,
    warmupPathsForRole,
} from './offline-sw-policy.js';

const FALLBACK_URL_PATH = '/__lmlinga/offline-unavailable';

export function createServiceWorkerRuntime(env = {}) {
    const origin = env.origin || 'http://localhost';
    const cachesApi = env.caches;
    const fetchImpl = env.fetch;
    const skipWaiting = env.skipWaiting;
    const clientsClaim = env.clientsClaim;
    const clientsApi = env.clients || null;
    let warmupInFlight = null;
    let umWarmupInFlight = null;
    let relatedWarmupInFlight = null;

    function requestUrl(request) {
        try {
            return new URL(request.url, origin);
        } catch {
            return null;
        }
    }

    async function readMeta() {
        try {
            const cache = await cachesApi.open(META_CACHE);
            const hit = await cache.match(new Request(`${origin}${ACTOR_META_PATH}`));
            if (!hit) {
                return { actorId: null, avatarPath: null };
            }
            const payload = await hit.json();
            const id = Number(payload?.actorId);
            return {
                actorId: Number.isInteger(id) && id > 0 ? id : null,
                avatarPath: avatarPathFromUrl(payload?.avatarPath || '', origin),
            };
        } catch {
            return { actorId: null, avatarPath: null };
        }
    }

    async function putMeta(actorId, avatarPath = null) {
        const cache = await cachesApi.open(META_CACHE);
        const body = JSON.stringify({
            actorId: actorId || null,
            avatarPath: avatarPathFromUrl(avatarPath || '', origin),
        });
        await cache.put(
            new Request(`${origin}${ACTOR_META_PATH}`),
            new Response(body, {
                headers: { 'Content-Type': 'application/json', 'X-Lmlinga-Offline-Cache': '1' },
            }),
        );
    }

    async function getActorId() {
        const meta = await readMeta();
        return meta.actorId;
    }

    async function deletePrivateCaches(exceptNames = []) {
        const keep = new Set(exceptNames.filter(Boolean));
        const names = await cachesApi.keys();
        await Promise.all(
            names
                .filter((name) => typeof name === 'string' && (
                    name.startsWith('lmlinga-html-') || name.startsWith('lmlinga-avatar-')
                ))
                .filter((name) => !keep.has(name))
                .map((name) => cachesApi.delete(name)),
        );
    }

    async function setActor(actorId) {
        const nextId = Number.isInteger(actorId) && actorId > 0 ? actorId : null;
        const current = await getActorId();
        if (current && nextId && current !== nextId) {
            await deletePrivateCaches([
                htmlCacheNameForActor(nextId),
                avatarCacheNameForActor(nextId),
            ]);
        }
        if (!nextId) {
            await deletePrivateCaches();
        }
        await putMeta(nextId, nextId && current === nextId ? (await readMeta()).avatarPath : null);
    }

    async function clearPrivateHtml() {
        await deletePrivateCaches();
        await putMeta(null, null);
    }

    function fallbackResponse() {
        return new Response(fallbackHtml(), {
            status: 200,
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
                'X-Lmlinga-Offline-Fallback': '1',
            },
        });
    }

    function assetUnavailableResponse() {
        return new Response('', {
            status: 504,
            statusText: 'Asset Unavailable Offline',
            headers: {
                'Cache-Control': 'no-store',
                'X-Lmlinga-Offline-Asset': 'miss',
            },
        });
    }

    async function precacheFallback() {
        const cache = await cachesApi.open(FALLBACK_CACHE);
        await cache.put(
            new Request(`${origin}${FALLBACK_URL_PATH}`),
            fallbackResponse(),
        );
    }

    async function precacheBuiltAssets() {
        if (typeof fetchImpl !== 'function') {
            return;
        }
        let manifest;
        try {
            const response = await fetchImpl(`${origin}/build/manifest.json`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response || !response.ok) {
                return;
            }
            manifest = await response.json();
        } catch {
            return;
        }

        const cache = await cachesApi.open(ASSETS_CACHE);
        try {
            await cache.put(
                new Request(`${origin}/build/manifest.json`),
                new Response(JSON.stringify(manifest), {
                    headers: { 'Content-Type': 'application/json', 'X-Lmlinga-Offline-Cache': '1' },
                }),
            );
        } catch {
            // Manifest snapshot is helpful but not required.
        }

        const files = new Set();
        Object.values(manifest || {}).forEach((entry) => {
            if (entry && typeof entry.file === 'string') {
                files.add(entry.file);
            }
            if (entry && Array.isArray(entry.css)) {
                entry.css.forEach((file) => files.add(file));
            }
            if (entry && Array.isArray(entry.assets)) {
                entry.assets.forEach((file) => files.add(file));
            }
        });

        await Promise.all(
            [...files].map(async (file) => {
                const path = String(file || '').replace(/^\/+/, '');
                if (!path.startsWith('assets/') && !path.startsWith('build/assets/')) {
                    return;
                }
                const assetPath = path.startsWith('build/') ? `/${path}` : `/build/${path}`;
                try {
                    const response = await fetchImpl(`${origin}${assetPath}`, { credentials: 'same-origin' });
                    if (response && response.ok && (response.type === 'basic' || response.type === 'default')) {
                        await cache.put(new Request(`${origin}${assetPath}`), response.clone());
                    }
                } catch {
                    // Skip missing hashed files; runtime cache-first will fill later.
                }
            }),
        );
    }

    async function precacheShellAssets() {
        if (typeof fetchImpl !== 'function') {
            return;
        }
        const cache = await cachesApi.open(ASSETS_CACHE);
        await Promise.all(
            SHELL_STATIC_PATHS.map(async (assetPath) => {
                try {
                    const response = await fetchImpl(`${origin}${assetPath}`, { credentials: 'same-origin' });
                    if (response && response.ok && (response.type === 'basic' || response.type === 'default')) {
                        await cache.put(new Request(`${origin}${assetPath}`), response.clone());
                    }
                } catch {
                    // Branding files are optional at install; runtime cache-first fills them on first visit.
                }
            }),
        );
    }

    async function readBuildManifest() {
        if (typeof fetchImpl === 'function') {
            try {
                const response = await fetchImpl(`${origin}/build/manifest.json`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                if (response && response.ok) {
                    return await response.json();
                }
            } catch {
                // Fall through to cached snapshot.
            }
        }
        try {
            const cache = await cachesApi.open(ASSETS_CACHE);
            const hit = await cache.match(new Request(`${origin}/build/manifest.json`));
            if (hit) {
                return await hit.json();
            }
        } catch {
            // Ignore cache read failures.
        }
        return null;
    }

    async function verifyBuiltAssets(manifest) {
        const checked = criticalBuildAssetPathsFromManifest(manifest);
        if (!checked.length) {
            return {
                ok: false,
                reason: 'empty-manifest',
                checked: [],
                missing: [],
                version: CACHE_VERSION,
            };
        }
        const cache = await cachesApi.open(ASSETS_CACHE);
        const missing = [];
        for (const assetPath of checked) {
            const hit = await cache.match(new Request(`${origin}${assetPath}`));
            if (!hit) {
                missing.push(assetPath);
            }
        }
        return {
            ok: missing.length === 0,
            reason: missing.length === 0 ? 'ok' : 'missing-assets',
            checked,
            missing,
            version: CACHE_VERSION,
        };
    }

    /**
     * Idempotent: re-read current manifest, fill ASSETS_CACHE, verify critical paths.
     * Survives CACHE_VERSION staying put while npm run build changes hashes.
     */
    async function ensureBuiltAssets() {
        await precacheBuiltAssets();
        const manifest = await readBuildManifest();
        if (!manifest || typeof manifest !== 'object') {
            return {
                ok: false,
                reason: 'no-manifest',
                checked: [],
                missing: [],
                version: CACHE_VERSION,
            };
        }
        return verifyBuiltAssets(manifest);
    }

    async function install() {
        await precacheFallback();
        await precacheBuiltAssets();
        await precacheShellAssets();
        if (typeof skipWaiting === 'function') {
            skipWaiting();
        }
    }

    async function activate() {
        const names = await cachesApi.keys();
        await Promise.all(obsoleteOwnedCaches(names).map((name) => cachesApi.delete(name)));
        if (typeof clientsClaim === 'function') {
            clientsClaim();
        }
    }

    async function cacheFirstAsset(request, url) {
        const cache = await cachesApi.open(ASSETS_CACHE);
        const hit = await cache.match(request);
        if (hit) {
            return hit;
        }
        const keyed = await cache.match(new Request(`${origin}${url.pathname}`));
        if (keyed) {
            return keyed;
        }
        const response = await fetchImpl(request);
        if (response && response.ok && (response.type === 'basic' || response.type === 'default')) {
            await cache.put(new Request(`${origin}${url.pathname}`), response.clone());
        }
        return response;
    }

    async function sanitizeHtmlResponse(response) {
        const html = sanitizeCachedHtml(await response.text());
        return new Response(html, {
            status: 200,
            statusText: 'OK',
            headers: headersForCachedResponse(response.headers),
        });
    }

    async function responseUsesViteDevAssets(response) {
        try {
            return htmlUsesViteDevAssets(await response.clone().text());
        } catch {
            return false;
        }
    }

    async function storeNavigation(url, response) {
        const actorId = await getActorId();
        const cacheName = htmlCacheNameForActor(actorId);
        if (!cacheName) {
            return;
        }
        const cache = await cachesApi.open(cacheName);
        const sanitized = await sanitizeHtmlResponse(response.clone());
        await cache.put(new Request(navigationCacheUrl(origin, url.pathname, url.search)), sanitized);
    }

    async function matchNavigation(url) {
        const actorId = await getActorId();
        const cacheName = htmlCacheNameForActor(actorId);
        if (!cacheName) {
            return null;
        }
        const cache = await cachesApi.open(cacheName);
        return cache.match(new Request(navigationCacheUrl(origin, url.pathname, url.search)));
    }

    async function storeActorAvatar(actorId, avatarPath, response) {
        const cacheName = avatarCacheNameForActor(actorId);
        if (!cacheName || !avatarPath) {
            return;
        }
        const cache = await cachesApi.open(cacheName);
        const headers = new Headers();
        if (response.headers && typeof response.headers.forEach === 'function') {
            response.headers.forEach((value, key) => {
                if (String(key).toLowerCase() !== 'set-cookie') {
                    headers.append(key, value);
                }
            });
        }
        headers.set('X-Lmlinga-Offline-Cache', '1');
        const body = await response.clone().arrayBuffer();
        await cache.put(
            new Request(`${origin}${avatarPath}`),
            new Response(body, {
                status: 200,
                statusText: 'OK',
                headers,
            }),
        );
    }

    async function matchActorAvatar(url) {
        const meta = await readMeta();
        if (!meta.actorId) {
            return null;
        }
        const path = normalizePathname(url.pathname);
        if (!isManagedStaffAvatarPath(path)) {
            return null;
        }
        const cacheName = avatarCacheNameForActor(meta.actorId);
        if (!cacheName) {
            return null;
        }
        const cache = await cachesApi.open(cacheName);
        return cache.match(new Request(`${origin}${path}`));
    }

    async function handleActorAvatar(request, url) {
        const meta = await readMeta();

        try {
            const response = await fetchImpl(request);
            const finalUrl = response?.url || url.href;
            if (meta.actorId && shouldCacheActorAvatarResponse(response, finalUrl, origin)) {
                const storedPath = avatarPathFromUrl(finalUrl, origin);
                if (storedPath) {
                    try {
                        await storeActorAvatar(meta.actorId, storedPath, response);
                    } catch {
                        // Avatar cache write must not block the live image.
                    }
                }
            }
            return response;
        } catch {
            const cached = await matchActorAvatar(url);
            if (cached) {
                return cached;
            }
            return new Response('', { status: 404, headers: { 'Cache-Control': 'no-store' } });
        }
    }

    async function warmActorAvatar(actorId, avatarUrl) {
        const avatarPath = avatarPathFromUrl(avatarUrl, origin);
        if (!actorId || !avatarPath) {
            return false;
        }
        const current = await readMeta();
        if (current.actorId !== actorId) {
            return false;
        }
        await putMeta(actorId, avatarPath);

        const cacheName = avatarCacheNameForActor(actorId);
        if (cacheName) {
            const cache = await cachesApi.open(cacheName);
            const existing = await cache.match(new Request(`${origin}${avatarPath}`));
            if (existing) {
                return true;
            }
        }
        if (typeof fetchImpl !== 'function') {
            return false;
        }

        try {
            const request = new Request(`${origin}${avatarPath}`, {
                method: 'GET',
                credentials: 'same-origin',
            });
            const response = await fetchImpl(request);
            const finalUrl = response?.url || request.url;
            if (!shouldCacheActorAvatarResponse(response, finalUrl, origin)) {
                return false;
            }
            if (avatarPathFromUrl(finalUrl, origin) !== avatarPath) {
                return false;
            }
            await storeActorAvatar(actorId, avatarPath, response);
            return true;
        } catch {
            return false;
        }
    }

    async function notifyClients(message, ports = []) {
        const list = Array.isArray(ports) ? ports.filter((port) => port && typeof port.postMessage === 'function') : [];
        list.forEach((port) => {
            try {
                port.postMessage(message);
            } catch {
                // Ignore closed ports.
            }
        });
        if (typeof clientsApi?.matchAll === 'function') {
            try {
                const windows = await clientsApi.matchAll({ type: 'window', includeUncontrolled: true });
                windows.forEach((client) => {
                    try {
                        client.postMessage(message);
                    } catch {
                        // Ignore.
                    }
                });
            } catch {
                // Ignore.
            }
        }
    }

    async function coreWarmupReadyState(actorId, role) {
        const paths = warmupPathsForRole(role);
        if (!actorId || !paths.length) {
            return {
                ready: false,
                actorId,
                role: role || null,
                version: CACHE_VERSION,
                total: paths.length,
                present: 0,
                missing: paths.slice(),
            };
        }
        const cacheName = htmlCacheNameForActor(actorId);
        if (!cacheName || typeof cachesApi?.open !== 'function') {
            return {
                ready: false,
                actorId,
                role,
                version: CACHE_VERSION,
                total: paths.length,
                present: 0,
                missing: paths.slice(),
            };
        }
        const cache = await cachesApi.open(cacheName);
        const missing = [];
        let present = 0;
        for (const path of paths) {
            const requestUrl = navigationCacheUrl(origin, path);
            const hit = await cache.match(new Request(requestUrl));
            // Stale DEV-generated HTML is not offline-ready in BUILD mode.
            if (hit && !(await responseUsesViteDevAssets(hit))) {
                present += 1;
            } else {
                missing.push(path);
            }
        }
        return {
            ready: missing.length === 0 && present === paths.length,
            actorId,
            role,
            version: CACHE_VERSION,
            total: paths.length,
            present,
            missing,
        };
    }

    async function warmCoreNavigation(requestedActorId = null, options = {}) {
        const actorId = await getActorId();
        const ports = Array.isArray(options.ports) ? options.ports : [];
        if (!actorId) {
            const result = { ok: false, reason: 'no-actor', warmed: [], failed: [], total: 0, completed: 0 };
            await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION }, ports);
            return result;
        }
        if (requestedActorId && requestedActorId !== actorId) {
            const result = { ok: false, reason: 'actor-mismatch', warmed: [], failed: [], total: 0, completed: 0 };
            await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION, actorId }, ports);
            return result;
        }
        if (typeof fetchImpl !== 'function') {
            const result = { ok: false, reason: 'no-fetch', warmed: [], failed: [], total: 0, completed: 0 };
            await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION, actorId }, ports);
            return result;
        }
        const paths = warmupPathsForRole(options.role);
        if (!paths.length) {
            const result = { ok: false, reason: 'no-role', warmed: [], failed: [], total: 0, completed: 0 };
            await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result, version: CACHE_VERSION, actorId }, ports);
            return result;
        }
        if (warmupInFlight) {
            return warmupInFlight;
        }

        const total = paths.length;
        warmupInFlight = (async () => {
            const warmed = [];
            const failed = [];
            const cacheName = htmlCacheNameForActor(actorId);
            const cache = cacheName ? await cachesApi.open(cacheName) : null;

            await notifyClients({
                type: MESSAGE_WARMUP_PROGRESS,
                actorId,
                version: CACHE_VERSION,
                completed: 0,
                total,
                percentage: 0,
                label: 'Starting offline preparation…',
                url: null,
            }, ports);

            for (const path of paths) {
                const requestUrl = navigationCacheUrl(origin, path);
                const request = new Request(requestUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html' },
                });

                let okPath = false;
                let staleDevHtml = false;
                /** @type {{ pathname: string, success: boolean, httpStatus: number|null, redirected: boolean, cacheable: boolean|null, failReason: string|null, fromCache: boolean, staleDevAssets: boolean }} */
                const diag = {
                    pathname: path,
                    success: false,
                    httpStatus: null,
                    redirected: false,
                    cacheable: null,
                    failReason: null,
                    fromCache: false,
                    staleDevAssets: false,
                };

                if (cache) {
                    const existing = await cache.match(request);
                    if (existing && await responseUsesViteDevAssets(existing)) {
                        // Left over from a Vite DEV session: refetch below instead of trusting it.
                        staleDevHtml = true;
                        diag.staleDevAssets = true;
                    } else if (existing) {
                        warmed.push(path);
                        okPath = true;
                        diag.success = true;
                        diag.fromCache = true;
                        diag.httpStatus = existing.status || 200;
                        diag.cacheable = true;
                        diag.failReason = null;
                    }
                }

                if (!okPath) {
                    try {
                        const response = await fetchImpl(request);
                        const parsed = new URL(requestUrl, origin);
                        const finalUrl = response?.url || requestUrl;
                        let finalPathname = path;
                        try {
                            finalPathname = new URL(finalUrl, origin).pathname || path;
                        } catch {
                            finalPathname = path;
                        }
                        diag.httpStatus = typeof response?.status === 'number' ? response.status : null;
                        diag.redirected = Boolean(response?.redirected)
                            || (normalizePathname(finalPathname) !== normalizePathname(path));

                        const responseCacheable = shouldCacheNavigationResponse(response, finalUrl, origin);
                        const requestCacheable = isCacheableNavigationRequest(request, parsed, origin);
                        diag.cacheable = responseCacheable && requestCacheable;

                        if (!responseCacheable) {
                            failed.push(path);
                            diag.failReason = 'response-not-cacheable';
                        } else if (!requestCacheable) {
                            failed.push(path);
                            diag.failReason = 'request-not-cacheable';
                        } else if (staleDevHtml && await responseUsesViteDevAssets(response)) {
                            // Server is still emitting DEV assets: keep the old entry, do not claim warmed.
                            failed.push(path);
                            diag.cacheable = false;
                            diag.failReason = 'stale-dev-assets';
                        } else {
                            await storeNavigation(parsed, response);
                            warmed.push(path);
                            okPath = true;
                            diag.success = true;
                            diag.failReason = null;
                        }
                    } catch {
                        failed.push(path);
                        diag.failReason = 'fetch-error';
                        diag.cacheable = false;
                    }
                }

                // TEMP diagnostics — safe pathname/status only (no HTML/body).
                console.log('[PREP PERF] core-path', diag);

                const completed = warmed.length;
                // Success bar tracks warmed only — failures must not push UI toward 100%.
                const percentage = total > 0 ? Math.round((warmed.length / total) * 100) : 0;
                await notifyClients({
                    type: MESSAGE_WARMUP_PROGRESS,
                    actorId,
                    version: CACHE_VERSION,
                    completed,
                    failed: failed.length,
                    total,
                    percentage,
                    label: warmPathStatusLabel(path),
                    url: path,
                    ok: okPath,
                    diag,
                }, ports);
            }

            if (options.avatarUrl) {
                await warmActorAvatar(actorId, options.avatarUrl);
            }

            const allOk = failed.length === 0 && warmed.length === total;
            const result = {
                ok: allOk,
                reason: allOk ? 'warmed' : 'partial',
                warmed,
                failed,
                total,
                completed: warmed.length,
                percentage: allOk ? 100 : Math.round((warmed.length / total) * 100),
                actorId,
                role: options.role || null,
                version: CACHE_VERSION,
            };
            await notifyClients({ type: MESSAGE_WARMUP_COMPLETE, ...result }, ports);
            return result;
        })();

        try {
            return await warmupInFlight;
        } finally {
            warmupInFlight = null;
        }
    }

    function uniqueNonEmpty(values) {
        const seen = new Set();
        const out = [];
        (Array.isArray(values) ? values : []).forEach((value) => {
            const next = String(value || '').trim();
            if (!next || seen.has(next)) {
                return;
            }
            seen.add(next);
            out.push(next);
        });
        return out;
    }

    /**
     * Refresh listed Health Worker View/Edit HTML and managed photos into the
     * current actor caches. Does not skip existing entries. Never mutates
     * meta.avatarPath (topbar photo stays the signed-in actor).
     */
    async function warmUserManagementListedWorkers(requestedActorId = null, options = {}) {
        const actorId = await getActorId();
        if (!actorId) {
            return { ok: false, reason: 'no-actor', warmed: [], avatars: [] };
        }
        if (requestedActorId && requestedActorId !== actorId) {
            return { ok: false, reason: 'actor-mismatch', warmed: [], avatars: [] };
        }
        if (typeof fetchImpl !== 'function') {
            return { ok: false, reason: 'no-fetch', warmed: [], avatars: [] };
        }

        const paths = uniqueNonEmpty(options.paths).filter((path) => isUserManagementHealthWorkerWarmPath(path));
        const avatars = uniqueNonEmpty(options.avatars)
            .map((raw) => avatarPathFromUrl(raw, origin))
            .filter(Boolean);

        if (!paths.length && !avatars.length) {
            return { ok: true, reason: 'empty', warmed: [], avatars: [] };
        }
        if (umWarmupInFlight) {
            return umWarmupInFlight;
        }

        umWarmupInFlight = (async () => {
            const warmed = [];
            const warmedAvatars = [];

            for (const path of paths) {
                const requestUrl = navigationCacheUrl(origin, path);
                const request = new Request(requestUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html' },
                });
                try {
                    const response = await fetchImpl(request);
                    const parsed = new URL(requestUrl, origin);
                    const finalUrl = response?.url || requestUrl;
                    if (!shouldCacheNavigationResponse(response, finalUrl, origin)) {
                        continue;
                    }
                    if (!isCacheableNavigationRequest(request, parsed, origin)) {
                        continue;
                    }
                    if (!isUserManagementHealthWorkerWarmPath(parsed.pathname)) {
                        continue;
                    }
                    await storeNavigation(parsed, response);
                    warmed.push(path);
                } catch {
                    // Per-item failure must not abort remaining workers.
                }
            }

            for (const avatarPath of avatars) {
                const request = new Request(`${origin}${avatarPath}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                });
                try {
                    const response = await fetchImpl(request);
                    const finalUrl = response?.url || request.url;
                    if (!shouldCacheActorAvatarResponse(response, finalUrl, origin)) {
                        continue;
                    }
                    const storedPath = avatarPathFromUrl(finalUrl, origin);
                    if (!storedPath) {
                        continue;
                    }
                    await storeActorAvatar(actorId, storedPath, response);
                    warmedAvatars.push(storedPath);
                } catch {
                    // Per-item avatar failure must not abort remaining workers.
                }
            }

            return { ok: true, reason: 'warmed', warmed, avatars: warmedAvatars };
        })();

        try {
            return await umWarmupInFlight;
        } finally {
            umWarmupInFlight = null;
        }
    }

    async function warmRelatedPages(requestedActorId = null, options = {}) {
        const actorId = await getActorId();
        if (!actorId) {
            return { ok: false, reason: 'no-actor', warmed: [] };
        }
        if (requestedActorId && requestedActorId !== actorId) {
            return { ok: false, reason: 'actor-mismatch', warmed: [] };
        }
        if (typeof fetchImpl !== 'function') {
            return { ok: false, reason: 'no-fetch', warmed: [] };
        }

        const paths = uniqueNonEmpty(options.paths).filter((entry) => {
            try {
                const parsed = new URL(String(entry), origin);
                return isSameOrigin(parsed, origin)
                    && !hasUnsafeQuery(parsed)
                    && isRelatedWarmPath(parsed.pathname);
            } catch {
                return isRelatedWarmPath(entry);
            }
        });

        if (!paths.length) {
            return { ok: true, reason: 'empty', warmed: [] };
        }
        if (relatedWarmupInFlight) {
            return relatedWarmupInFlight;
        }

        relatedWarmupInFlight = (async () => {
            const warmed = [];

            for (const entry of paths) {
                let requestUrl;
                let parsed;
                try {
                    parsed = new URL(String(entry), origin);
                    requestUrl = navigationCacheUrl(origin, parsed.pathname, parsed.search);
                    parsed = new URL(requestUrl, origin);
                } catch {
                    requestUrl = navigationCacheUrl(origin, entry);
                    parsed = new URL(requestUrl, origin);
                }

                if (!isRelatedWarmPath(parsed.pathname)) {
                    continue;
                }

                const request = new Request(requestUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { Accept: 'text/html' },
                });
                try {
                    const response = await fetchImpl(request);
                    const finalUrl = response?.url || requestUrl;
                    let finalParsed;
                    try {
                        finalParsed = new URL(finalUrl, origin);
                    } catch {
                        continue;
                    }
                    if (normalizePathname(finalParsed.pathname) !== normalizePathname(parsed.pathname)) {
                        continue;
                    }
                    if (!shouldCacheNavigationResponse(response, finalUrl, origin)) {
                        continue;
                    }
                    if (!isCacheableNavigationRequest(request, parsed, origin)) {
                        continue;
                    }
                    await storeNavigation(parsed, response);
                    warmed.push(parsed.pathname + (parsed.search || ''));
                } catch {
                    // Per-item failure must not abort remaining related pages.
                }
            }

            return { ok: true, reason: 'warmed', warmed };
        })();

        try {
            return await relatedWarmupInFlight;
        } finally {
            relatedWarmupInFlight = null;
        }
    }

    function isLocalMemberPath(pathname) {
        return isLocalMemberViewPath(pathname)
            || isLocalMemberEditPath(pathname)
            || isLocalMemberHealthPath(pathname);
    }

    async function handleNavigation(request, url) {
        try {
            const response = await fetchImpl(request);
            // A member created offline (MB-L-…) does not exist on the server until it syncs:
            // keep showing the prepared local page instead of the server's 404.
            if (response && response.status === 404 && isLocalMemberPath(url.pathname)) {
                const local = await matchNavigation(url);
                if (local) {
                    return local;
                }
            }
            const finalUrl = response?.url || url.href;
            if (shouldCacheNavigationResponse(response, finalUrl, origin) && isCacheableNavigationRequest(request, url, origin)) {
                try {
                    await storeNavigation(url, response);
                } catch {
                    // Cache write failures must not block the live page.
                }
            }
            return response;
        } catch {
            const cached = await matchNavigation(url);
            if (cached) {
                return cached;
            }
            return fallbackResponse();
        }
    }

    async function handleFetch(request) {
        const url = requestUrl(request);
        if (!url) {
            return fetchImpl(request);
        }

        if (!isSameOrigin(url, origin)) {
            return fetchImpl(request);
        }

        if (isLogoutRequest(request, url)) {
            await clearPrivateHtml();
            return fetchImpl(request);
        }

        if (isMutationMethod(request.method)) {
            return fetchImpl(request);
        }

        if (String(request.method || 'GET').toUpperCase() !== 'GET') {
            return fetchImpl(request);
        }

        if (isManagedStaffAvatarPath(url.pathname) && String(request.method || 'GET').toUpperCase() === 'GET') {
            try {
                return await handleActorAvatar(request, url);
            } catch {
                return new Response('', { status: 404, headers: { 'Cache-Control': 'no-store' } });
            }
        }

        if (isNeverCachePath(url.pathname) || isDevelopmentOnlyPath(url.pathname) || hasUnsafeQuery(url)) {
            if (request.mode === 'navigate') {
                try {
                    return await fetchImpl(request);
                } catch {
                    return fallbackResponse();
                }
            }
            return fetchImpl(request);
        }

        if (isStaticAssetPath(url.pathname) && isCacheableAssetRequest(request, url, origin)) {
            try {
                return await cacheFirstAsset(request, url);
            } catch {
                const cache = await cachesApi.open(ASSETS_CACHE);
                const hit = await cache.match(new Request(`${origin}${url.pathname}`));
                if (hit) {
                    return hit;
                }
                // Never fall through to HTML offline shell for CSS/JS/fonts.
                return assetUnavailableResponse();
            }
        }

        if (isCacheableNavigationRequest(request, url, origin)) {
            return handleNavigation(request, url);
        }

        if (request.mode === 'navigate') {
            try {
                return await fetchImpl(request);
            } catch {
                return fallbackResponse();
            }
        }

        return fetchImpl(request);
    }

    async function handleMessage(data, extras = {}) {
        const type = data && typeof data.type === 'string' ? data.type : '';
        const ports = Array.isArray(extras.ports) ? extras.ports : [];
        if (type === MESSAGE_SET_ACTOR) {
            const id = Number(data.actorId || data.actor_id || 0);
            await setActor(Number.isInteger(id) && id > 0 ? id : null);
            return;
        }
        if (type === MESSAGE_CLEAR_HTML) {
            await clearPrivateHtml();
            return;
        }
        if (type === MESSAGE_ENSURE_BUILD_ASSETS) {
            const state = await ensureBuiltAssets();
            const payload = { type: MESSAGE_ENSURE_BUILD_ASSETS_RESULT, ...state };
            await notifyClients(payload, ports);
            return payload;
        }
        if (type === MESSAGE_WARMUP_READY_QUERY) {
            const id = Number(data.actorId || data.actor_id || 0);
            const state = await coreWarmupReadyState(
                Number.isInteger(id) && id > 0 ? id : await getActorId(),
                data.role,
            );
            await notifyClients({ type: MESSAGE_WARMUP_READY_RESULT, ...state }, ports);
            return state;
        }
        if (type === MESSAGE_WARMUP_CORE) {
            const id = Number(data.actorId || data.actor_id || 0);
            await warmCoreNavigation(Number.isInteger(id) && id > 0 ? id : null, {
                role: data.role,
                avatarUrl: data.avatarUrl || data.avatar_url || null,
                ports,
            });
            return;
        }
        if (type === MESSAGE_WARMUP_UM_WORKERS) {
            const id = Number(data.actorId || data.actor_id || 0);
            await warmUserManagementListedWorkers(Number.isInteger(id) && id > 0 ? id : null, {
                paths: data.paths,
                avatars: data.avatars || data.avatarUrls || data.avatar_urls,
            });
            return;
        }
        if (type === MESSAGE_WARMUP_RELATED) {
            const id = Number(data.actorId || data.actor_id || 0);
            await warmRelatedPages(Number.isInteger(id) && id > 0 ? id : null, {
                paths: data.paths,
            });
        }
    }

    return {
        install,
        activate,
        handleFetch,
        handleMessage,
        getActorId,
        setActor,
        clearPrivateHtml,
        warmCoreNavigation,
        warmUserManagementListedWorkers,
        warmRelatedPages,
        coreWarmupReadyState,
        ensureBuiltAssets,
        verifyBuiltAssets,
        fallbackResponse,
        assetUnavailableResponse,
        normalizePathname,
    };
}
