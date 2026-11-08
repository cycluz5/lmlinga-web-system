/**
 * Household Profiling offline navigation guard: Add / member View / member Edit /
 * amenities Details stay on the current page unless the exact URL is in the
 * current actor HTML cache.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';

import { htmlCacheNameForActor, navigationCacheUrl } from '../../resources/js/offline/offline-sw-policy.js';
import {
    HH_NAV_KINDS,
    handleHouseholdNavClick,
    hasCachedHouseholdNav,
    isHouseholdNavPath,
    kindFromHouseholdNavLink,
    messageForHouseholdNavKind,
} from '../../resources/js/offline/offline-nav-guard.js';
import { handleHouseholdViewClick } from '../../resources/js/pages/household-profiling.js';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from '../../resources/js/offline/offline-status.js';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';

const helperSource = readFileSync(path.resolve('resources/js/offline/offline-nav-guard.js'), 'utf8');
const listPageSource = readFileSync(path.resolve('resources/js/pages/household-profiling.js'), 'utf8');
const viewPageSource = readFileSync(path.resolve('resources/js/pages/household-view.js'), 'utf8');
const listBlade = readFileSync(path.resolve('resources/views/pages/household-profiling/index.blade.php'), 'utf8');
const viewBlade = readFileSync(path.resolve('resources/views/pages/household-profiling/view.blade.php'), 'utf8');
const formsSource = readFileSync(path.resolve('resources/js/offline/offline-forms.js'), 'utf8');
const ORIGIN = 'https://lmlinga.test';

const CASES = [
    {
        kind: HH_NAV_KINDS.ADD_MEMBER,
        href: '/household-profiling/999/members/create',
        message: 'Reconnect to add a member.',
        attr: { 'data-hh-nav': 'add-member' },
    },
    {
        kind: HH_NAV_KINDS.VIEW_MEMBER,
        href: '/household-profiling/999/members/MB-1',
        message: 'Reconnect to open this member.',
        attr: { 'data-hh-nav': 'view-member' },
    },
    {
        kind: HH_NAV_KINDS.EDIT_MEMBER,
        href: '/household-profiling/999/members/MB-1/edit',
        message: 'Reconnect to edit this member.',
        attr: { 'data-hh-nav': 'edit-member' },
    },
    {
        kind: HH_NAV_KINDS.AMENITIES,
        href: '/household-profiling/999/amenities',
        message: 'Reconnect to open household details.',
        attr: { 'data-hh-nav': 'amenities' },
    },
    {
        kind: HH_NAV_KINDS.AMENITIES_EDIT,
        href: '/household-profiling/999/amenities/edit',
        message: 'Reconnect to open household details.',
        attr: { 'data-hh-nav': 'amenities-edit' },
    },
    {
        kind: HH_NAV_KINDS.HEALTH_RECORD,
        href: '/household-profiling/999/members/MB-1/child-immunization',
        message: 'Reconnect to open this health record.',
        attr: { 'data-hh-nav': 'health-record' },
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

describe('household profiling offline nav source contract', () => {
    it('marks Blade links and does not enqueue, open IndexedDB, or call /offline/sync', () => {
        assert.match(listBlade, /data-hh-nav="add-member"/);
        assert.match(viewBlade, /data-hh-nav="add-member"/);
        assert.match(viewBlade, /data-hh-nav="view-member"/);
        assert.match(viewBlade, /data-hh-nav="edit-member"/);
        assert.match(viewBlade, /data-hh-nav="amenities"/);
        assert.match(listPageSource, /handleHouseholdNavClick/);
        assert.match(viewPageSource, /handleHouseholdNavClick/);
        assert.match(helperSource, /htmlCacheNameForActor/);
        assert.match(helperSource, /navigationCacheUrl/);
        assert.doesNotMatch(helperSource, /enqueueOperation/);
        assert.doesNotMatch(helperSource, /indexedDB/);
        assert.doesNotMatch(helperSource, /\/offline\/sync/);
        assert.doesNotMatch(listPageSource, /enqueueOperation/);
        assert.doesNotMatch(viewPageSource, /enqueueOperation/);
        assert.doesNotMatch(listPageSource, /indexedDB/);
        assert.doesNotMatch(viewPageSource, /indexedDB/);
        assert.doesNotMatch(listPageSource, /\/offline\/sync/);
        assert.doesNotMatch(viewPageSource, /\/offline\/sync/);
        assert.match(formsSource, /RESIDENT_CREATE/);
        assert.match(formsSource, /RESIDENT_UPDATE/);
        assert.equal(OFFLINE_MESSAGES.addMemberOffline, 'Reconnect to add a member.');
        assert.equal(OFFLINE_MESSAGES.viewMemberOffline, 'Reconnect to open this member.');
        assert.equal(OFFLINE_MESSAGES.editMemberOffline, 'Reconnect to edit this member.');
        assert.equal(OFFLINE_MESSAGES.amenitiesOffline, 'Reconnect to open household details.');
        assert.equal(isHouseholdNavPath('/household-profiling/999/members/create', HH_NAV_KINDS.ADD_MEMBER), true);
        assert.equal(isHouseholdNavPath('/household-profiling/999/members/MB-1', HH_NAV_KINDS.VIEW_MEMBER), true);
        assert.equal(isHouseholdNavPath('/household-profiling/999/members/MB-1/edit', HH_NAV_KINDS.EDIT_MEMBER), true);
        assert.equal(isHouseholdNavPath('/household-profiling/999/members/MB-L-abc12/edit', HH_NAV_KINDS.EDIT_MEMBER), true);
        assert.equal(isHouseholdNavPath('/household-profiling/999/members/MB-L-abc12/child-immunization', HH_NAV_KINDS.HEALTH_RECORD), true);
        assert.equal(isHouseholdNavPath('/household-profiling/999/amenities', HH_NAV_KINDS.AMENITIES), true);
        assert.equal(isHouseholdNavPath('/household-profiling/999/members/create', HH_NAV_KINDS.VIEW_MEMBER), false);
    });
});

describe('household profiling offline nav guard', () => {
    it('lets online Add / member View / member Edit / amenities clicks navigate normally', async () => {
        for (const spec of CASES) {
            const doc = createDocument();
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            const event = clickEvent(link);
            const assigned = [];
            const result = await handleHouseholdNavClick(event, {
                link,
                navigator: { onLine: true },
                assign: (url) => assigned.push(url),
            });
            assert.equal(result.intercepted, false);
            assert.equal(result.reason, 'online');
            assert.equal(result.kind, spec.kind);
            assert.equal(event.defaultPrevented, false);
            assert.equal(assigned.length, 0);
            assert.equal(kindFromHouseholdNavLink(link), spec.kind);
            assert.equal(messageForHouseholdNavKind(spec.kind), spec.message);
        }
    });

    it('allows offline navigation when the exact URL is in the current actor cache', async () => {
        for (const spec of CASES) {
            const caches = createMemoryCaches();
            await seedActorPage(caches, 7, spec.href);
            const doc = createDocument();
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            const event = clickEvent(link);
            const assigned = [];
            const notices = [];
            const result = await handleHouseholdNavClick(event, {
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
            assert.equal(result.kind, spec.kind);
            assert.deepEqual(assigned, [spec.href]);
            assert.equal(notices.length, 0);
        }
    });

    it('blocks offline misses with the correct reconnect copy and does not enqueue', async () => {
        for (const spec of CASES) {
            const caches = createMemoryCaches();
            const doc = createDocument();
            const html = doc.createElement('html');
            doc.documentElement = html;
            const root = el(doc, 'div', { 'data-lml-hh-view': '' });
            const toast = el(doc, 'div', { 'data-hh-view-toast': '' });
            toast.hidden = true;
            root.appendChild(toast);
            html.appendChild(root);
            const link = el(doc, 'a', { ...spec.attr, href: spec.href });
            root.appendChild(link);
            const event = clickEvent(link);
            const assigned = [];
            const notices = [];
            const result = await handleHouseholdNavClick(event, {
                root,
                link,
                navigator: { onLine: false },
                actorId: 7,
                caches,
                origin: ORIGIN,
                toastSelector: '[data-hh-view-toast]',
                ensureSnapshotCached: async () => false,
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
            assert.equal(notices[0]?.name, OFFLINE_EVENTS.NOTICE);
            assert.equal(notices[0]?.detail?.message, spec.message);
            assert.equal(toast.textContent, spec.message);
        }
    });

    it('does not treat another actor’s cached page as available and fails closed without an actor', async () => {
        const href = '/household-profiling/999/members/create';
        const caches = createMemoryCaches();
        await seedActorPage(caches, 7, href);
        const cachedForA = await hasCachedHouseholdNav(href, {
            actorId: 7,
            caches,
            origin: ORIGIN,
            kind: HH_NAV_KINDS.ADD_MEMBER,
        });
        const cachedForB = await hasCachedHouseholdNav(href, {
            actorId: 9,
            caches,
            origin: ORIGIN,
            kind: HH_NAV_KINDS.ADD_MEMBER,
        });
        const noActor = await hasCachedHouseholdNav(href, {
            caches,
            origin: ORIGIN,
            kind: HH_NAV_KINDS.ADD_MEMBER,
        });
        assert.equal(cachedForA, true);
        assert.equal(cachedForB, false);
        assert.equal(noActor, false);

        const link = el(createDocument(), 'a', { 'data-hh-nav': 'add-member', href });
        const event = clickEvent(link);
        const assigned = [];
        const result = await handleHouseholdNavClick(event, {
            link,
            navigator: { onLine: false },
            actorId: 9,
            caches,
            origin: ORIGIN,
            ensureSnapshotCached: async () => false,
            assign: (url) => assigned.push(url),
            window: { LmlingaOffline: { emit() {} } },
        });
        assert.equal(result.navigated, false);
        assert.equal(result.message, 'Reconnect to add a member.');
        assert.equal(assigned.length, 0);
    });

    it('cached Add/Edit navigation does not enqueue; existing View guard still works', async () => {
        const caches = createMemoryCaches();
        await seedActorPage(caches, 7, '/household-profiling/999/members/create');
        await seedActorPage(caches, 7, '/household-profiling/999/members/MB-1/edit');
        await seedActorPage(caches, 7, '/household-profiling/999');

        const addLink = el(createDocument(), 'a', {
            'data-hh-nav': 'add-member',
            href: '/household-profiling/999/members/create',
        });
        const editLink = el(createDocument(), 'a', {
            'data-hh-nav': 'edit-member',
            href: '/household-profiling/999/members/MB-1/edit',
        });
        const viewLink = el(createDocument(), 'a', {
            'data-hh-view': '',
            href: '/household-profiling/999',
        });

        const assigned = [];
        const addResult = await handleHouseholdNavClick(clickEvent(addLink), {
            link: addLink,
            navigator: { onLine: false },
            actorId: 7,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
        });
        const editResult = await handleHouseholdNavClick(clickEvent(editLink), {
            link: editLink,
            navigator: { onLine: false },
            actorId: 7,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
        });
        const viewResult = await handleHouseholdViewClick(clickEvent(viewLink), {
            link: viewLink,
            navigator: { onLine: false },
            actorId: 7,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
        });

        assert.equal(addResult.navigated, true);
        assert.equal(editResult.navigated, true);
        assert.equal(viewResult.navigated, true);
        assert.equal(viewResult.kind, HH_NAV_KINDS.VIEW_HOUSEHOLD);
        assert.deepEqual(assigned, [
            '/household-profiling/999/members/create',
            '/household-profiling/999/members/MB-1/edit',
            '/household-profiling/999',
        ]);
        assert.doesNotMatch(helperSource, /queueSupportedOperation/);
        assert.doesNotMatch(helperSource, /RESIDENT_CREATE/);
        assert.doesNotMatch(helperSource, /RESIDENT_UPDATE/);
    });

    it('blocks death certificate write pages offline even when HTML was cached', async () => {
        const href = '/household-profiling/999/members/MB-1/death/create';
        const caches = createMemoryCaches();
        await seedActorPage(caches, 7, href);
        const link = el(createDocument(), 'a', { 'data-hh-nav': 'death-write', href });
        const event = clickEvent(link);
        const assigned = [];
        const result = await handleHouseholdNavClick(event, {
            link,
            navigator: { onLine: false },
            actorId: 7,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
            window: { LmlingaOffline: { emit() {} } },
        });
        assert.equal(result.navigated, false);
        assert.equal(result.message, OFFLINE_MESSAGES.deathCertificateOffline);
        assert.equal(assigned.length, 0);
        assert.equal(isHouseholdNavPath(href, HH_NAV_KINDS.DEATH_WRITE), true);
    });

    it('opens never-visited households from snapshots without a reconnect notice', async () => {
        const households = [
            '/household-profiling/HH-200',
            '/household-profiling/HH-201/members/create',
        ];
        for (const href of households) {
            const caches = createMemoryCaches();
            const link = el(createDocument(), 'a', {
                'data-hh-nav': href.endsWith('/create') ? 'add-member' : undefined,
                'data-hh-view': href.endsWith('/create') ? undefined : '',
                href,
            });
            if (!href.endsWith('/create')) {
                link.setAttribute('data-hh-view', '');
            }
            const event = clickEvent(link);
            const assigned = [];
            const notices = [];
            const result = await handleHouseholdNavClick(event, {
                link,
                navigator: { onLine: false },
                actorId: 7,
                caches,
                origin: ORIGIN,
                ensureSnapshotCached: async () => true,
                assign: (url) => assigned.push(url),
                window: {
                    LmlingaOffline: {
                        emit(name, detail) {
                            notices.push({ name, detail });
                        },
                    },
                },
            });
            assert.equal(result.navigated, true);
            assert.equal(result.reason, 'snapshot');
            assert.deepEqual(assigned, [href]);
            assert.equal(notices.length, 0);
        }
    });
});
