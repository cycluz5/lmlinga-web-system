/**
 * In-app offline unavailable panel + module classification contracts.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const unavailableUrl = pathToFileURL(path.resolve('resources/js/offline/offline-unavailable.js')).href;
const appSource = readFileSync(path.resolve('resources/js/app.js'), 'utf8');

const {
    CACHE_VERSION,
    classifyOfflineNavigation,
    isHouseholdProfilingOfflineWritePath,
    isRelatedWarmPath,
    shouldCacheNavigationResponse,
} = await import(policyUrl);

const { showOfflineUnavailableInShell, unavailableCopy } = await import(unavailableUrl);

const ORIGIN = 'https://lmlinga.test';

describe('offline capability classification', () => {
    it('keeps user management index and create online-only', () => {
        assert.equal(classifyOfflineNavigation('/user-management').kind, 'online-only');
        assert.equal(classifyOfflineNavigation('/user-management/').module, 'User Management');
        assert.equal(classifyOfflineNavigation('/user-management/health-workers/create').kind, 'online-only');
        assert.match(
            classifyOfflineNavigation('/user-management').support,
            /view and manage user accounts/i,
        );
    });

    it('does not cache user-management index HTML', () => {
        assert.equal(
            shouldCacheNavigationResponse(
                { ok: true, status: 200, type: 'basic', headers: { get: () => 'text/html' } },
                `${ORIGIN}/user-management`,
                ORIGIN,
            ),
            false,
        );
    });

    it('treats nutritional-status create as an offline-capable write GET', () => {
        const legacy = '/household-profiling/HH-121/members/MB-001/nutritional-status/create';
        const canonical = '/households/HH-121/residents/42/nutritional-status/create';
        assert.equal(isHouseholdProfilingOfflineWritePath(legacy), true);
        assert.equal(isHouseholdProfilingOfflineWritePath(canonical), true);
        assert.equal(isRelatedWarmPath(legacy), true);
        assert.equal(isRelatedWarmPath(canonical), true);
        assert.equal(classifyOfflineNavigation(legacy).kind, 'offline-capable');
    });

    it('classifies adult immunization and death certificate writes as online-only', () => {
        assert.equal(
            classifyOfflineNavigation('/household-profiling/HH-1/members/MB-1/adult-immunization').kind,
            'online-only',
        );
        assert.equal(
            classifyOfflineNavigation('/household-profiling/HH-1/members/MB-1/death/create').kind,
            'online-only',
        );
    });
});

describe('offline unavailable in-shell panel', () => {
    it('renders online-only copy for user management', () => {
        const copy = unavailableCopy(classifyOfflineNavigation('/user-management'));
        assert.match(copy.title, /User Management isn’t available while you’re offline/i);
        assert.match(copy.body, /view and manage user accounts/i);
        assert.equal(copy.reason, 'online-only');
    });

    it('renders not-cached copy for offline-capable misses', () => {
        const copy = unavailableCopy({
            kind: 'offline-capable',
            module: 'Household Profiling',
            support: 'Reconnect to the internet and open this page once to make it available for offline use.',
        });
        assert.match(copy.title, /isn’t available offline yet/i);
        assert.equal(copy.reason, 'not-cached');
    });

    it('injects panel into #main-content without replacing the shell host', () => {
        const doc = {
            querySelector(selector) {
                if (selector === '#main-content') {
                    return this.main;
                }
                return null;
            },
            main: { innerHTML: '<p>old</p>', querySelector() { return null; } },
        };
        assert.equal(showOfflineUnavailableInShell(doc, classifyOfflineNavigation('/user-management')), true);
        assert.match(doc.main.innerHTML, /data-lml-offline-unavailable/);
        assert.match(doc.main.innerHTML, /User Management/);
        assert.match(doc.main.innerHTML, /Try Again/);
    });

    it('boots the module nav guard from app.js', () => {
        assert.match(appSource, /offline-module-nav-guard/);
        assert.equal(CACHE_VERSION, 'offline-7-v20');
    });
});
