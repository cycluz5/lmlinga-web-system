/**
 * User Management Health Worker offline navigation: View / Edit / Add.
 * Add is always blocked offline. View/Edit require an exact actor-scoped cache hit.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';

import { htmlCacheNameForActor, navigationCacheUrl, WARMUP_PATHS_ADMIN } from '../../resources/js/offline/offline-sw-policy.js';
import {
    UM_NAV_KINDS,
    handleUserManagementNavClick,
    hasCachedUserManagementNav,
    isUserManagementNavPath,
    kindFromUserManagementNavLink,
    messageForUserManagementNavKind,
} from '../../resources/js/offline/offline-um-nav-guard.js';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from '../../resources/js/offline/offline-status.js';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';

const guardSource = readFileSync(path.resolve('resources/js/offline/offline-um-nav-guard.js'), 'utf8');
const pageSource = readFileSync(path.resolve('resources/js/pages/user-management.js'), 'utf8');
const indexBlade = readFileSync(path.resolve('resources/views/pages/user-management/index.blade.php'), 'utf8');
const cardBlade = readFileSync(
    path.resolve('resources/views/components/lml/user-management/health-worker-card.blade.php'),
    'utf8',
);
const policySource = readFileSync(path.resolve('resources/js/offline/offline-sw-policy.js'), 'utf8');
const ORIGIN = 'https://lmlinga.test';

const CASES = [
    {
        kind: UM_NAV_KINDS.VIEW_WORKER,
        href: '/user-management/health-workers/12/view',
        message: 'Reconnect to open this health worker.',
        attr: { 'data-um-nav': 'view-worker' },
    },
    {
        kind: UM_NAV_KINDS.EDIT_WORKER,
        href: '/user-management/health-workers/12/edit',
        message: 'Reconnect to edit this health worker.',
        attr: { 'data-um-nav': 'edit-worker' },
    },
    {
        kind: UM_NAV_KINDS.ADD_WORKER,
        href: '/user-management/health-workers/create',
        message: 'Reconnect to add a health worker.',
        attr: { 'data-um-nav': 'add-worker' },
    },
];

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

async function seedActorPage(caches, actorId, pathname) {
    const cache = await caches.open(htmlCacheNameForActor(actorId));
    await cache.put(
        new Request(navigationCacheUrl(ORIGIN, pathname)),
        new Response('<html>cached</html>', { headers: { 'Content-Type': 'text/html' } }),
    );
}

describe('user management offline nav source contract', () => {
    it('marks Blade links and does not enqueue, open IndexedDB, or call /offline/sync', () => {
        assert.match(indexBlade, /data-um-nav="add-worker"/);
        assert.match(cardBlade, /data-um-nav="view-worker"/);
        assert.match(cardBlade, /data-um-nav="edit-worker"/);
        assert.match(cardBlade, /data-um-worker-view-url/);
        assert.match(cardBlade, /data-um-worker-edit-url/);
        assert.match(pageSource, /handleUserManagementNavClick/);
        assert.match(pageSource, /bindUserManagementOfflineNav/);
        assert.doesNotMatch(guardSource, /enqueueOperation/);
        assert.doesNotMatch(guardSource, /indexedDB/);
        assert.doesNotMatch(guardSource, /\/offline\/sync/);
        assert.doesNotMatch(pageSource, /enqueueOperation/);
        assert.doesNotMatch(policySource, /health-workers\/1\/edit/);
        assert.equal(WARMUP_PATHS_ADMIN.includes('/user-management'), false);
        assert.equal(
            WARMUP_PATHS_ADMIN.some((path) => /health-workers\/.+/.test(path)),
            false,
        );
        assert.equal(OFFLINE_MESSAGES.viewHealthWorkerOffline, 'Reconnect to open this health worker.');
        assert.equal(OFFLINE_MESSAGES.editHealthWorkerOffline, 'Reconnect to edit this health worker.');
        assert.equal(OFFLINE_MESSAGES.addHealthWorkerOffline, 'Reconnect to add a health worker.');
        assert.equal(isUserManagementNavPath('/user-management/health-workers/12/view', UM_NAV_KINDS.VIEW_WORKER), true);
        assert.equal(isUserManagementNavPath('/user-management/health-workers/12/edit', UM_NAV_KINDS.EDIT_WORKER), true);
        assert.equal(isUserManagementNavPath('/user-management/health-workers/create', UM_NAV_KINDS.ADD_WORKER), true);
        assert.equal(isUserManagementNavPath('/user-management/health-workers/hw-001/edit', UM_NAV_KINDS.EDIT_WORKER), false);
    });
});

describe('user management offline nav guard', () => {
    it('lets online View / Edit / Add clicks navigate normally', async () => {
        for (const spec of CASES) {
            const doc = createDocument();
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            const event = clickEvent(link);
            const assigned = [];
            const result = await handleUserManagementNavClick(event, {
                link,
                navigator: { onLine: true },
                assign: (url) => assigned.push(url),
            });
            assert.equal(result.intercepted, false);
            assert.equal(result.reason, 'online');
            assert.equal(result.kind, spec.kind);
            assert.equal(event.defaultPrevented, false);
            assert.equal(assigned.length, 0);
            assert.equal(kindFromUserManagementNavLink(link), spec.kind);
            assert.equal(messageForUserManagementNavKind(spec.kind), spec.message);
        }
    });

    it('allows offline View/Edit when the exact URL is in the current actor cache', async () => {
        for (const spec of CASES.filter((item) => item.kind !== UM_NAV_KINDS.ADD_WORKER)) {
            const caches = createMemoryCaches();
            await seedActorPage(caches, 7, spec.href);
            const doc = createDocument();
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            const event = clickEvent(link);
            const assigned = [];
            const notices = [];
            const result = await handleUserManagementNavClick(event, {
                link,
                navigator: { onLine: false },
                actorId: 7,
                caches,
                origin: ORIGIN,
                assign: (url) => assigned.push(url),
                window: {
                    LmlingaOffline: {
                        emit(name, detail) {
                            notices.push({ name, detail });
                        },
                    },
                },
            });
            assert.equal(result.intercepted, true);
            assert.equal(result.navigated, true);
            assert.equal(result.reason, 'cached');
            assert.deepEqual(assigned, [spec.href]);
            assert.equal(notices.length, 0);
        }
    });

    it('blocks uncached View/Edit and always blocks Add while offline', async () => {
        for (const spec of CASES) {
            const caches = createMemoryCaches();
            if (spec.kind === UM_NAV_KINDS.ADD_WORKER) {
                await seedActorPage(caches, 7, spec.href);
            }
            const doc = createDocument();
            const root = el(doc, 'div', { 'data-lml-user-mgmt': '' });
            const toast = el(doc, 'p', { 'data-um-toast': '' });
            toast.hidden = true;
            root.appendChild(toast);
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            root.appendChild(link);
            const event = clickEvent(link);
            const assigned = [];
            const notices = [];
            const result = await handleUserManagementNavClick(event, {
                root,
                link,
                navigator: { onLine: false },
                actorId: 7,
                caches,
                origin: ORIGIN,
                toastSelector: '[data-um-toast]',
                assign: (url) => assigned.push(url),
                window: {
                    LmlingaOffline: {
                        emit(name, detail) {
                            notices.push({ name, detail });
                        },
                    },
                },
            });
            assert.equal(result.intercepted, true);
            assert.equal(result.navigated, false);
            assert.equal(result.message, spec.message);
            assert.equal(assigned.length, 0);
            assert.equal(event.defaultPrevented, true);
            assert.equal(notices[0]?.name, OFFLINE_EVENTS.NOTICE);
            assert.equal(toast.textContent, spec.message);
            if (spec.kind === UM_NAV_KINDS.ADD_WORKER) {
                assert.equal(await hasCachedUserManagementNav(spec.href, {
                    actorId: 7,
                    caches,
                    origin: ORIGIN,
                    kind: spec.kind,
                }), false);
            }
        }
    });
});
