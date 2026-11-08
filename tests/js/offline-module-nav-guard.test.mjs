/**
 * Sidebar module nav guard — SW-backed offline-capable navigation.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

import { createMemoryCaches } from './support/fake-caches.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const guardUrl = pathToFileURL(path.resolve('resources/js/offline/offline-module-nav-guard.js')).href;

const {
    CACHE_VERSION,
    classifyOfflineNavigation,
    htmlCacheNameForActor,
    navigationCacheUrl,
} = await import(policyUrl);

const { handleModuleOfflineNavigation, hasCachedNavigation } = await import(guardUrl);

const ORIGIN = 'https://lmlinga.test';

function el(doc, tag, attrs = {}) {
    const node = doc.createElement(tag);
    Object.entries(attrs).forEach(([name, value]) => node.setAttribute(name, String(value)));
    return node;
}

function offlineWin(overrides = {}) {
    return {
        location: { origin: ORIGIN, href: `${ORIGIN}/dashboard`, assign() {} },
        navigator: {
            onLine: false,
            serviceWorker: { controller: { scriptURL: `${ORIGIN}/sw.js` } },
        },
        ...overrides,
        navigator: {
            onLine: false,
            serviceWorker: { controller: { scriptURL: `${ORIGIN}/sw.js` } },
            ...(overrides.navigator || {}),
        },
    };
}

function fixtureDoc({ actorId = 7, href = '/household-profiling', label = 'Link' } = {}) {
    const doc = createDocument();
    const html = el(doc, 'html');
    const body = el(doc, 'body');
    const root = el(doc, 'div', {
        'data-lml-offline-root': '',
        'data-offline-actor-id': String(actorId),
    });
    const main = el(doc, 'div', { id: 'main-content' });
    main.innerHTML = '<p>shell</p>';
    // Ensure querySelector('#main-content') works via id.
    main.id = 'main-content';
    const link = el(doc, 'a', { class: 'lml-sidebar__link', href, 'data-lml-module-nav': '' });
    link.textContent = label;
    body.appendChild(root);
    body.appendChild(main);
    body.appendChild(link);
    html.appendChild(body);
    doc.documentElement = html;
    doc.body = body;
    return { doc, link, main, root };
}

describe('offline module nav guard', () => {
    it('TEST D — offline-capable page miss still permits SW-backed navigation', async () => {
        const { doc, link, main } = fixtureDoc({ href: '/household-profiling' });
        const win = offlineWin();
        doc.defaultView = win;
        const store = createMemoryCaches();

        assert.equal(
            await hasCachedNavigation('/household-profiling', doc, {
                caches: store,
                origin: ORIGIN,
                actorId: 7,
            }),
            false,
        );

        const handled = await handleModuleOfflineNavigation(link, {
            document: doc,
            window: win,
            navigator: win.navigator,
            caches: store,
            origin: ORIGIN,
        });

        assert.equal(handled, false);
        assert.equal(main.innerHTML.includes('data-lml-offline-unavailable'), false);
        assert.equal(classifyOfflineNavigation('/household-profiling').kind, 'offline-capable');
    });

    it('TEST E — online-only user-management never navigates', async () => {
        const { doc, link, main } = fixtureDoc({ href: '/user-management', label: 'User Management' });
        let assigned = false;
        const win = offlineWin({
            location: {
                origin: ORIGIN,
                href: `${ORIGIN}/dashboard`,
                assign() {
                    assigned = true;
                },
            },
        });
        doc.defaultView = win;

        const handled = await handleModuleOfflineNavigation(link, {
            document: doc,
            window: win,
            navigator: win.navigator,
            caches: createMemoryCaches(),
            origin: ORIGIN,
        });

        assert.equal(handled, true);
        assert.equal(assigned, false);
        assert.match(main.innerHTML, /data-lml-offline-unavailable/);
        assert.match(main.innerHTML, /User Management isn’t available while you’re offline/i);
        assert.match(main.innerHTML, /data-reason="online-only"/);
    });

    it('TEST F — actor isolation: B never hits A cache', async () => {
        const store = createMemoryCaches();
        const cacheA = await store.open(htmlCacheNameForActor(1));
        await cacheA.put(
            new Request(navigationCacheUrl(ORIGIN, '/household-profiling')),
            new Response('<html>A</html>', { headers: { 'Content-Type': 'text/html' } }),
        );

        const { doc: docB } = fixtureDoc({ actorId: 2 });
        assert.equal(
            await hasCachedNavigation('/household-profiling', docB, {
                caches: store,
                origin: ORIGIN,
                actorId: 2,
            }),
            false,
        );
        assert.equal(
            await hasCachedNavigation('/household-profiling', docB, {
                caches: store,
                origin: ORIGIN,
                actorId: 1,
            }),
            true,
        );
        assert.match(htmlCacheNameForActor(1), new RegExp(`${CACHE_VERSION}-actor-1$`));
        assert.notEqual(htmlCacheNameForActor(1), htmlCacheNameForActor(2));
    });

    it('without controlling SW, offline-capable miss shows in-shell unavailable', async () => {
        const { doc, link, main } = fixtureDoc({ href: '/household-profiling' });
        const win = offlineWin({
            navigator: { onLine: false, serviceWorker: { controller: null } },
        });
        doc.defaultView = win;

        const handled = await handleModuleOfflineNavigation(link, {
            document: doc,
            window: win,
            navigator: win.navigator,
            caches: createMemoryCaches(),
            origin: ORIGIN,
        });
        assert.equal(handled, true);
        assert.match(main.innerHTML, /isn’t available offline yet/i);
    });

    it('page cache hit allows navigation without panel', async () => {
        const store = createMemoryCaches();
        const cache = await store.open(htmlCacheNameForActor(7));
        await cache.put(
            new Request(navigationCacheUrl(ORIGIN, '/dashboard')),
            new Response('<html>dash</html>', { headers: { 'Content-Type': 'text/html' } }),
        );
        const { doc, link, main } = fixtureDoc({ href: '/dashboard', label: 'Dashboard' });
        const win = offlineWin({
            navigator: { onLine: false, serviceWorker: { controller: null } },
        });
        doc.defaultView = win;

        const handled = await handleModuleOfflineNavigation(link, {
            document: doc,
            window: win,
            navigator: win.navigator,
            caches: store,
            origin: ORIGIN,
        });
        assert.equal(handled, false);
        assert.equal(main.innerHTML.includes('data-lml-offline-unavailable'), false);
    });
});
