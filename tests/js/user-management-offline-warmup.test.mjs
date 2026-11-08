/**
 * User Management listed-worker warmup: discover rendered View/Edit/avatar
 * URLs, refresh the current actor cache, never enqueue or sync.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { createMemoryCaches } from './support/fake-caches.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';
import {
    AVATAR_CACHE_PREFIX,
    HTML_CACHE_PREFIX,
    MESSAGE_WARMUP_UM_WORKERS,
    WARMUP_PATHS_ADMIN,
    fallbackHtml,
    htmlCacheNameForActor,
    navigationCacheUrl,
} from '../../resources/js/offline/offline-sw-policy.js';
import {
    UM_NAV_KINDS,
    handleUserManagementNavClick,
    hasCachedUserManagementNav,
} from '../../resources/js/offline/offline-um-nav-guard.js';

const runtimeUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-runtime.js')).href;
const warmupUrl = pathToFileURL(path.resolve('resources/js/offline/offline-um-warmup.js')).href;

const { createServiceWorkerRuntime } = await import(runtimeUrl);
const {
    bindManagedAvatarFallback,
    collectUserManagementWarmupPayload,
    discoverUserManagementWorkers,
    resetUserManagementWarmupForTests,
    warmListedUserManagementWorkers,
} = await import(warmupUrl);

const warmupSource = readFileSync(path.resolve('resources/js/offline/offline-um-warmup.js'), 'utf8');
const runtimeSource = readFileSync(path.resolve('resources/js/offline/offline-sw-runtime.js'), 'utf8');
const pageSource = readFileSync(path.resolve('resources/js/pages/user-management.js'), 'utf8');
const cardBlade = readFileSync(
    path.resolve('resources/views/components/lml/user-management/health-worker-card.blade.php'),
    'utf8',
);
const viewBlade = readFileSync(
    path.resolve('resources/views/pages/user-management/health-worker-view.blade.php'),
    'utf8',
);
const ORIGIN = 'https://lmlinga.test';
const VIEW_30 = '/user-management/health-workers/30/view';
const EDIT_30 = '/user-management/health-workers/30/edit';
const VIEW_12 = '/user-management/health-workers/12/view';
const EDIT_12 = '/user-management/health-workers/12/edit';
const CREATE = '/user-management/health-workers/create';
const AVATAR_30 = '/storage/health-workers/profile-photos/maria.png';
const AVATAR_12 = '/storage/health-workers/profile-photos/levi.png';

afterEach(() => {
    resetUserManagementWarmupForTests();
});

function el(doc, tag, attrs = {}) {
    const node = doc.createElement(tag);
    Object.entries(attrs).forEach(([name, value]) => node.setAttribute(name, String(value)));
    return node;
}

function clickEvent(target) {
    return {
        target,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        stopPropagation() {},
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

function imageResponse(body) {
    return new Response(body, { headers: { 'Content-Type': 'image/png' } });
}

function navRequest(pathname) {
    return {
        url: `${ORIGIN}${pathname}`,
        method: 'GET',
        mode: 'navigate',
        destination: 'document',
        headers: new Headers({ Accept: 'text/html' }),
    };
}

function statusOk(overrides = {}) {
    return {
        ok: true,
        status: 200,
        json: async () => ({
            ok: true,
            is_active: true,
            user_id: 7,
            role: 'admin',
            ...overrides,
        }),
    };
}

function umIndexRoot(doc, workers) {
    const root = el(doc, 'div', { 'data-lml-user-mgmt': '' });
    workers.forEach((worker) => {
        const attrs = {
            'data-hw-card': '',
            'data-hw-id': String(worker.id),
        };
        if (worker.numeric !== false) {
            attrs['data-um-worker-id'] = String(worker.id);
            attrs['data-um-worker-view-url'] = worker.viewUrl || `/user-management/health-workers/${worker.id}/view`;
            attrs['data-um-worker-edit-url'] = worker.editUrl || `/user-management/health-workers/${worker.id}/edit`;
        }
        if (worker.avatarUrl) {
            attrs['data-um-worker-avatar-url'] = worker.avatarUrl;
        }
        root.appendChild(el(doc, 'article', attrs));
    });
    root.appendChild(el(doc, 'a', { 'data-um-nav': 'add-worker', href: CREATE }));
    return root;
}

function setupRuntime() {
    const caches = createMemoryCaches();
    const network = new Map();
    const fetches = [];
    const runtime = createServiceWorkerRuntime({
        origin: ORIGIN,
        caches,
        fetch: async (request) => {
            const url = typeof request === 'string' ? request : request.url;
            const method = String(request.method || 'GET').toUpperCase();
            fetches.push({ method, url });
            const key = `${method} ${url}`;
            if (!network.has(key)) {
                throw new TypeError('Failed to fetch');
            }
            return network.get(key)();
        },
    });
    return { caches, network, runtime, fetches };
}

describe('user management warmup source contract', () => {
    it('discovers worker URLs from cards and never enqueues, syncs, or warms create', () => {
        assert.match(cardBlade, /data-um-worker-id/);
        assert.match(cardBlade, /data-um-worker-view-url/);
        assert.match(cardBlade, /data-um-worker-edit-url/);
        assert.match(cardBlade, /data-um-worker-avatar-url/);
        assert.match(cardBlade, /data-um-avatar/);
        assert.match(cardBlade, /data-um-avatar-fallback/);
        assert.match(viewBlade, /data-um-avatar/);
        assert.match(pageSource, /warmListedUserManagementWorkers/);
        assert.match(pageSource, /bindManagedAvatarFallback/);
        assert.match(warmupSource, /MESSAGE_WARMUP_UM_WORKERS/);
        assert.doesNotMatch(warmupSource, /enqueueOperation/);
        assert.doesNotMatch(warmupSource, /\/offline\/sync/);
        assert.doesNotMatch(warmupSource, /HEALTH_WORKER_CREATE/);
        assert.doesNotMatch(warmupSource, /method:\s*['"]POST['"]/);
        assert.doesNotMatch(warmupSource, /method:\s*['"]PUT['"]/);
        assert.doesNotMatch(warmupSource, /method:\s*['"]PATCH['"]/);
        assert.doesNotMatch(warmupSource, /method:\s*['"]DELETE['"]/);
        assert.match(runtimeSource, /warmUserManagementListedWorkers/);
        assert.match(runtimeSource, /putMeta\(actorId, avatarPath\)/);
        assert.equal(WARMUP_PATHS_ADMIN.includes('/user-management'), false);
        assert.equal(WARMUP_PATHS_ADMIN.some((item) => /health-workers\/.+/.test(item)), false);
        assert.equal(MESSAGE_WARMUP_UM_WORKERS, 'lmlinga:warmup-um-workers');
    });
});

describe('user management listed worker discovery', () => {
    it('discovers rendered numeric View/Edit/avatar URLs and skips create and hw-* ids', () => {
        const doc = createDocument();
        const root = umIndexRoot(doc, [
            { id: '30', avatarUrl: AVATAR_30 },
            { id: '12', avatarUrl: AVATAR_12 },
            { id: 'hw-001', numeric: false },
        ]);
        root.appendChild(el(doc, 'article', {
            'data-hw-card': '',
            'data-hw-id': '99',
            'data-um-worker-id': '99',
            'data-um-worker-view-url': CREATE,
            'data-um-worker-edit-url': CREATE,
        }));

        const discovered = discoverUserManagementWorkers(root, { origin: ORIGIN });
        const payload = collectUserManagementWarmupPayload(root, { origin: ORIGIN });

        assert.deepEqual(discovered.map((item) => item.id), ['30', '12', '99']);
        assert.equal(discovered[0].viewUrl, VIEW_30);
        assert.equal(discovered[0].editUrl, EDIT_30);
        assert.equal(discovered[0].avatarUrl, AVATAR_30);
        assert.equal(payload.paths.includes(VIEW_30), true);
        assert.equal(payload.paths.includes(EDIT_30), true);
        assert.equal(payload.paths.includes(VIEW_12), true);
        assert.equal(payload.paths.includes(EDIT_12), true);
        assert.equal(payload.paths.includes(CREATE), false);
        assert.equal(payload.paths.includes(`/user-management/health-workers/hw-001/view`), false);
        assert.deepEqual(payload.avatars.sort(), [AVATAR_12, AVATAR_30].sort());
        assert.equal(payload.workers.find((item) => item.id === '99').viewUrl, '/user-management/health-workers/99/view');
    });
});

describe('user management listed worker page warmup', () => {
    it('posts discovered View/Edit/avatar URLs to the current actor worker after /offline/status', async () => {
        const doc = createDocument();
        const offlineRoot = el(doc, 'div', {
            'data-lml-offline-root': '',
            'data-offline-actor-id': '7',
            'data-offline-status-url': '/offline/status',
        });
        const root = umIndexRoot(doc, [{ id: '30', avatarUrl: AVATAR_30 }]);
        offlineRoot.appendChild(root);

        const messages = [];
        const urls = [];
        const result = await warmListedUserManagementWorkers({
            document: doc,
            root,
            actorId: 7,
            origin: ORIGIN,
            navigator: { onLine: true },
            worker: {
                postMessage(payload) {
                    messages.push(payload);
                },
            },
            fetch: async (url, init) => {
                urls.push({ url, init });
                return statusOk();
            },
            statusUrl: '/offline/status',
        });

        assert.equal(result.ok, true);
        assert.equal(urls.length, 1);
        assert.equal(urls[0].url, '/offline/status');
        assert.equal(urls[0].init.method, 'GET');
        assert.equal(messages.length, 1);
        assert.equal(messages[0].type, MESSAGE_WARMUP_UM_WORKERS);
        assert.equal(messages[0].actorId, 7);
        assert.deepEqual(messages[0].paths.sort(), [EDIT_30, VIEW_30].sort());
        assert.deepEqual(messages[0].avatars, [AVATAR_30]);
        assert.equal(messages[0].paths.includes(CREATE), false);
        assert.equal(Object.prototype.hasOwnProperty.call(messages[0], 'enqueue'), false);
    });

    it('does not run while offline or when the actor does not match /offline/status', async () => {
        const doc = createDocument();
        const root = umIndexRoot(doc, [{ id: '30' }]);
        const offline = await warmListedUserManagementWorkers({
            document: doc,
            root,
            actorId: 7,
            origin: ORIGIN,
            navigator: { onLine: false },
            worker: { postMessage() {} },
            fetch: async () => statusOk(),
        });
        assert.equal(offline.ok, false);

        resetUserManagementWarmupForTests();
        const mismatch = await warmListedUserManagementWorkers({
            document: doc,
            root,
            actorId: 7,
            origin: ORIGIN,
            navigator: { onLine: true },
            worker: { postMessage() {} },
            fetch: async () => statusOk({ user_id: 9 }),
        });
        assert.equal(mismatch.ok, false);
        assert.equal(mismatch.reason, 'actor-mismatch');
    });
});

describe('user management listed worker service worker warmup', () => {
    it('warms View/Edit/avatar into the current actor cache only and skips create, login, and failures', async () => {
        const { caches, network, runtime, fetches } = setupRuntime();
        await runtime.setActor(7);

        network.set(`GET ${ORIGIN}${VIEW_30}`, () => htmlResponse('<html>maria-view</html>'));
        network.set(`GET ${ORIGIN}${EDIT_30}`, () => htmlResponse('<html>maria-edit</html>'));
        network.set(`GET ${ORIGIN}${VIEW_12}`, () => htmlResponse('<html>levi-view</html>'));
        network.set(`GET ${ORIGIN}${EDIT_12}`, () => {
            const response = htmlResponse('<html>login</html>');
            Object.defineProperty(response, 'url', { value: `${ORIGIN}/login` });
            return response;
        });
        network.set(`GET ${ORIGIN}${CREATE}`, () => htmlResponse('<html>create-secret</html>'));
        network.set(`GET ${ORIGIN}${AVATAR_30}`, () => imageResponse('maria-photo'));
        network.set(`GET ${ORIGIN}${AVATAR_12}`, () => {
            throw new TypeError('Failed to fetch');
        });

        const result = await runtime.handleMessage({
            type: MESSAGE_WARMUP_UM_WORKERS,
            actorId: 7,
            paths: [VIEW_30, EDIT_30, VIEW_12, EDIT_12, CREATE, '/household-profiling/1'],
            avatars: [AVATAR_30, AVATAR_12],
        });
        assert.equal(result, undefined);

        const warmed = await runtime.warmUserManagementListedWorkers(7, {
            paths: [VIEW_30, EDIT_30, VIEW_12, EDIT_12, CREATE, '/household-profiling/1'],
            avatars: [AVATAR_30, AVATAR_12],
        });

        assert.equal(warmed.ok, true);
        assert.equal(warmed.warmed.includes(VIEW_30), true);
        assert.equal(warmed.warmed.includes(EDIT_30), true);
        assert.equal(warmed.warmed.includes(VIEW_12), true);
        assert.equal(warmed.warmed.includes(EDIT_12), false);
        assert.equal(warmed.warmed.includes(CREATE), false);
        assert.deepEqual(warmed.avatars, [AVATAR_30]);
        assert.equal(fetches.every((item) => item.method === 'GET'), true);
        assert.equal(fetches.some((item) => item.url.includes('/offline/sync')), false);
        assert.equal(fetches.some((item) => item.url.includes(CREATE)), false);

        const html7 = await caches.open(`${HTML_CACHE_PREFIX}7`);
        const cachedView = await (await html7.match(new Request(navigationCacheUrl(ORIGIN, VIEW_30)))).text();
        const cachedEdit = await (await html7.match(new Request(navigationCacheUrl(ORIGIN, EDIT_30)))).text();
        const cachedLevi = await (await html7.match(new Request(navigationCacheUrl(ORIGIN, VIEW_12)))).text();
        assert.match(cachedView, /maria-view/);
        assert.match(cachedEdit, /maria-edit/);
        assert.match(cachedLevi, /levi-view/);
        assert.match(cachedView, /lmlinga-offline-cache/);
        assert.ok(await html7.match(new Request(navigationCacheUrl(ORIGIN, VIEW_12))));
        assert.equal(await html7.match(new Request(navigationCacheUrl(ORIGIN, EDIT_12))), undefined);
        assert.equal(await html7.match(new Request(navigationCacheUrl(ORIGIN, CREATE))), undefined);
        assert.equal(await html7.match(new Request(navigationCacheUrl(ORIGIN, '/login'))), undefined);

        const avatar7 = await caches.open(`${AVATAR_CACHE_PREFIX}7`);
        assert.equal(await (await avatar7.match(new Request(`${ORIGIN}${AVATAR_30}`))).text(), 'maria-photo');
        assert.equal(await avatar7.match(new Request(`${ORIGIN}${AVATAR_12}`)), undefined);

        const html8 = await caches.open(`${HTML_CACHE_PREFIX}8`);
        assert.equal(await html8.match(new Request(navigationCacheUrl(ORIGIN, VIEW_30))), undefined);
        const avatar8 = await caches.open(`${AVATAR_CACHE_PREFIX}8`);
        assert.equal(await avatar8.match(new Request(`${ORIGIN}${AVATAR_30}`)), undefined);

        network.set(`GET ${ORIGIN}${EDIT_30}`, () => htmlResponse('<html>maria-edit-fresh</html>'));
        await runtime.warmUserManagementListedWorkers(7, { paths: [EDIT_30] });
        assert.match(await (await html7.match(new Request(navigationCacheUrl(ORIGIN, EDIT_30)))).text(), /maria-edit-fresh/);

        network.set(`GET ${ORIGIN}${VIEW_30}`, () => htmlResponse('<html>maria-view-live</html>'));
        const liveView = await runtime.handleFetch(navRequest(VIEW_30));
        assert.equal(await liveView.text(), '<html>maria-view-live</html>');

        network.clear();
        const offlineView = await runtime.handleFetch(navRequest(VIEW_30));
        assert.match(await offlineView.text(), /maria-view-live/);
        const offlineEdit = await runtime.handleFetch(navRequest(EDIT_30));
        assert.match(await offlineEdit.text(), /maria-edit-fresh/);
        const offlineAvatar = await runtime.handleFetch(new Request(`${ORIGIN}${AVATAR_30}`));
        assert.equal(await offlineAvatar.text(), 'maria-photo');
        const missingAvatar = await runtime.handleFetch(new Request(`${ORIGIN}${AVATAR_12}`));
        assert.equal(missingAvatar.status, 404);

        await runtime.setActor(8);
        const otherActor = await runtime.handleFetch(navRequest(VIEW_30));
        assert.equal(await otherActor.text(), fallbackHtml());
        assert.equal(await caches.open(`${HTML_CACHE_PREFIX}8`).then((cache) => cache.match(new Request(navigationCacheUrl(ORIGIN, VIEW_30)))), undefined);
    });

    it('lets the nav guard open warmed View/Edit without a prior manual navigation and still blocks Add', async () => {
        const { caches, network, runtime } = setupRuntime();
        await runtime.setActor(7);
        network.set(`GET ${ORIGIN}${VIEW_30}`, () => htmlResponse('<html>maria-view</html>'));
        network.set(`GET ${ORIGIN}${EDIT_30}`, () => htmlResponse('<html>maria-edit</html>'));
        await runtime.warmUserManagementListedWorkers(7, { paths: [VIEW_30, EDIT_30] });

        assert.equal(await hasCachedUserManagementNav(VIEW_30, {
            actorId: 7,
            caches,
            origin: ORIGIN,
            kind: UM_NAV_KINDS.VIEW_WORKER,
        }), true);
        assert.equal(await hasCachedUserManagementNav(EDIT_30, {
            actorId: 7,
            caches,
            origin: ORIGIN,
            kind: UM_NAV_KINDS.EDIT_WORKER,
        }), true);

        const doc = createDocument();
        for (const spec of [
            { kind: UM_NAV_KINDS.VIEW_WORKER, href: VIEW_30, attr: { 'data-um-nav': 'view-worker' } },
            { kind: UM_NAV_KINDS.EDIT_WORKER, href: EDIT_30, attr: { 'data-um-nav': 'edit-worker' } },
        ]) {
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            const assigned = [];
            const result = await handleUserManagementNavClick(clickEvent(link), {
                link,
                navigator: { onLine: false },
                actorId: 7,
                caches,
                origin: ORIGIN,
                assign: (url) => assigned.push(url),
            });
            assert.equal(result.reason, 'cached');
            assert.deepEqual(assigned, [spec.href]);
        }

        const addLink = el(doc, 'a', { 'data-um-nav': 'add-worker', href: CREATE });
        const addResult = await handleUserManagementNavClick(clickEvent(addLink), {
            link: addLink,
            navigator: { onLine: false },
            actorId: 7,
            caches,
            origin: ORIGIN,
            assign: () => {},
        });
        assert.equal(addResult.navigated, false);
        assert.equal(addResult.message, 'Reconnect to add a health worker.');
    });

    it('rejects a warmup requested for a different actor', async () => {
        const { runtime } = setupRuntime();
        await runtime.setActor(7);
        const mismatch = await runtime.warmUserManagementListedWorkers(8, { paths: [VIEW_30] });
        assert.equal(mismatch.ok, false);
        assert.equal(mismatch.reason, 'actor-mismatch');
    });
});

describe('user management avatar fallback', () => {
    it('hides a broken profile image and shows the existing placeholder', () => {
        const doc = createDocument();
        const wrap = el(doc, 'div');
        const img = el(doc, 'img', {
            'data-um-avatar': '',
            src: '/storage/health-workers/profile-photos/missing.png',
        });
        const icon = el(doc, 'i', { 'data-um-avatar-fallback': '', hidden: '' });
        wrap.appendChild(img);
        wrap.appendChild(icon);

        bindManagedAvatarFallback(wrap);
        img.dispatchEvent({ type: 'error' });

        assert.equal(img.hidden, true);
        assert.equal(img.getAttribute('hidden'), '');
        assert.equal(icon.hasAttribute('hidden'), false);
        assert.equal(icon.hidden, false);
        assert.equal(img.getAttribute('src'), '/storage/health-workers/profile-photos/missing.png');
    });
});
