/**
 * Household Profiling View is blocked offline unless that exact URL is in
 * the current actor's HTML cache.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

import { htmlCacheNameForActor, navigationCacheUrl, WARMUP_PATHS_SHARED } from '../../resources/js/offline/offline-sw-policy.js';
import { OFFLINE_EVENTS, OFFLINE_MESSAGES } from '../../resources/js/offline/offline-status.js';
import {
    applyListHouseholdNoDisplay,
    displayHouseholdNo,
    handleHouseholdViewClick,
    hasCachedHouseholdView,
    isHouseholdViewPath,
} from '../../resources/js/pages/household-profiling.js';
import { createMemoryCaches } from './support/fake-caches.mjs';
import { createDocument } from './support/sidebar-mini-dom.mjs';

const pageSource = readFileSync(path.resolve('resources/js/pages/household-profiling.js'), 'utf8');
const bladeSource = readFileSync(path.resolve('resources/views/pages/household-profiling/index.blade.php'), 'utf8');
const policySource = readFileSync(path.resolve('resources/js/offline/offline-sw-policy.js'), 'utf8');
const ORIGIN = 'https://lmlinga.test';

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

async function seedActorView(caches, actorId, pathname) {
    const cache = await caches.open(htmlCacheNameForActor(actorId));
    await cache.put(
        new Request(navigationCacheUrl(ORIGIN, pathname)),
        new Response('<html>view</html>', { headers: { 'Content-Type': 'text/html' } }),
    );
}

describe('household profiling offline view source contract', () => {
    it('does not enqueue, replay, or call /offline/sync', () => {
        assert.match(bladeSource, /data-hh-view/);
        assert.match(pageSource, /handleHouseholdViewClick/);
        assert.match(pageSource, /offline-nav-guard/);
        assert.doesNotMatch(pageSource, /enqueueOperation/);
        assert.doesNotMatch(pageSource, /createReplayCoordinator/);
        assert.doesNotMatch(pageSource, /\/offline\/sync/);
        assert.doesNotMatch(pageSource, /indexedDB/);
        assert.doesNotMatch(policySource, /\/household-profiling\/999/);
        assert.match(policySource, /Dynamic IDs are never listed here/);
        assert.match(bladeSource, /lml-hh-profiling__hh-no">\{\{ \$household\['displayNo'\]/);
        assert.match(bladeSource, /data-household-no="\{\{ \$household\['householdNo'\] \}\}"/);
        assert.match(pageSource, /applyListHouseholdNoDisplay/);
        assert.equal(WARMUP_PATHS_SHARED.includes('/household-profiling'), true);
        assert.equal(WARMUP_PATHS_SHARED.includes('/household-profiling/create'), true);
        assert.equal(
            WARMUP_PATHS_SHARED.some((path) => /\/household-profiling\/(?:HH-[0-9]+|[0-9]{3})(?:\/|$)/.test(path)),
            false,
        );
        assert.equal(OFFLINE_MESSAGES.viewHouseholdOffline, 'Reconnect to open this household.');
        assert.equal(isHouseholdViewPath('/household-profiling/999'), true);
        assert.equal(isHouseholdViewPath('/household-profiling'), false);
        assert.equal(isHouseholdViewPath('/household-profiling/999/edit'), false);
    });
});

describe('household profiling offline view guard', () => {
    it('lets an online View click navigate normally', async () => {
        const doc = createDocument();
        const link = el(doc, 'a', { 'data-hh-view': '', href: '/household-profiling/999' });
        const event = clickEvent(link);
        const assigned = [];
        const result = await handleHouseholdViewClick(event, {
            link,
            navigator: { onLine: true },
            assign: (url) => assigned.push(url),
        });

        assert.equal(result.intercepted, false);
        assert.equal(result.reason, 'online');
        assert.equal(event.defaultPrevented, false);
        assert.equal(assigned.length, 0);
    });

    it('allows offline navigation when the exact View URL is in the actor cache', async () => {
        const caches = createMemoryCaches();
        await seedActorView(caches, 7, '/household-profiling/999');
        const doc = createDocument();
        const link = el(doc, 'a', { 'data-hh-view': '', href: '/household-profiling/999' });
        const event = clickEvent(link);
        const assigned = [];
        const notices = [];
        const result = await handleHouseholdViewClick(event, {
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
        assert.equal(event.defaultPrevented, true);
        assert.deepEqual(assigned, ['/household-profiling/999']);
        assert.equal(notices.length, 0);
    });

    it('blocks offline View and shows reconnect copy when the URL is not cached', async () => {
        const caches = createMemoryCaches();
        const doc = createDocument();
        const html = doc.createElement('html');
        doc.documentElement = html;
        const root = el(doc, 'div', { 'data-lml-hh-profiling': '' });
        const toast = el(doc, 'div', { 'data-hh-toast': '' });
        toast.hidden = true;
        root.appendChild(toast);
        html.appendChild(root);
        const link = el(doc, 'a', { 'data-hh-view': '', href: '/household-profiling/999' });
        root.appendChild(link);
        const event = clickEvent(link);
        const assigned = [];
        const notices = [];
        const result = await handleHouseholdViewClick(event, {
            root,
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
        assert.equal(result.navigated, false);
        assert.equal(result.message, 'Reconnect to open this household.');
        assert.equal(assigned.length, 0);
        assert.equal(notices[0]?.name, OFFLINE_EVENTS.NOTICE);
        assert.equal(notices[0]?.detail?.message, 'Reconnect to open this household.');
        assert.equal(toast.textContent, 'Reconnect to open this household.');
    });

    it('does not treat another actor’s cached View as available', async () => {
        const caches = createMemoryCaches();
        await seedActorView(caches, 7, '/household-profiling/999');
        const cachedForA = await hasCachedHouseholdView('/household-profiling/999', {
            actorId: 7,
            caches,
            origin: ORIGIN,
        });
        const cachedForB = await hasCachedHouseholdView('/household-profiling/999', {
            actorId: 9,
            caches,
            origin: ORIGIN,
        });
        const noActor = await hasCachedHouseholdView('/household-profiling/999', {
            caches,
            origin: ORIGIN,
        });

        assert.equal(cachedForA, true);
        assert.equal(cachedForB, false);
        assert.equal(noActor, false);

        const link = el(createDocument(), 'a', { 'data-hh-view': '', href: '/household-profiling/999' });
        const event = clickEvent(link);
        const assigned = [];
        const result = await handleHouseholdViewClick(event, {
            link,
            navigator: { onLine: false },
            actorId: 9,
            caches,
            origin: ORIGIN,
            assign: (url) => assigned.push(url),
            window: { LmlingaOffline: { emit() {} } },
        });
        assert.equal(result.navigated, false);
        assert.equal(result.message, 'Reconnect to open this household.');
        assert.equal(assigned.length, 0);
    });
});

describe('household profiling list HH No. display', () => {
    it('strips a leading HH- prefix without integer conversion', () => {
        assert.equal(displayHouseholdNo('HH-001'), '001');
        assert.equal(displayHouseholdNo('HH-002'), '002');
        assert.equal(displayHouseholdNo('HH-003'), '003');
        assert.equal(displayHouseholdNo('hh-001'), '001');
        assert.equal(displayHouseholdNo('001'), '001');
        assert.equal(displayHouseholdNo('121'), '121');
        assert.equal(displayHouseholdNo('999'), '999');
        assert.equal(displayHouseholdNo('xHH-001'), 'xHH-001');
        assert.notEqual(displayHouseholdNo('HH-001'), '1');
    });

    it('rewrites cached list cells from stored data-household-no and leaves hrefs intact', () => {
        const doc = createDocument();
        const root = el(doc, 'div', { 'data-lml-hh-profiling': '' });
        const row = el(doc, 'tr', {
            'data-hh-row': '',
            'data-household-no': 'HH-001',
        });
        const cell = el(doc, 'span', { class: 'lml-hh-profiling__hh-no' });
        cell.textContent = 'HH-001';
        const view = el(doc, 'a', {
            'data-hh-view': '',
            href: '/household-profiling/HH-001',
        });
        row.appendChild(cell);
        row.appendChild(view);
        const tbody = el(doc, 'tbody', { 'data-hh-tbody': '' });
        tbody.appendChild(row);
        root.appendChild(tbody);
        applyListHouseholdNoDisplay(root);
        assert.equal(cell.textContent, '001');
        assert.equal(row.getAttribute('data-household-no'), 'HH-001');
        assert.equal(view.getAttribute('href'), '/household-profiling/HH-001');
    });
});
