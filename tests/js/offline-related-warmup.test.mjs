/**
 * Related household/member/health warmup — discover visible records only.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const warmupUrl = pathToFileURL(path.resolve('resources/js/offline/offline-related-warmup.js')).href;
const { discoverRelatedWarmPaths, canWarmRelatedPages } = await import(warmupUrl);

function node(attrs = {}, hrefs = []) {
    return {
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
        },
        querySelectorAll(selector) {
            if (selector === 'a[href]') {
                return hrefs.map((href) => ({
                    getAttribute(name) {
                        return name === 'href' ? href : null;
                    },
                }));
            }
            if (selector === '[data-hh-row][data-household-no]') {
                return [];
            }
            if (selector === '[data-household-no]') {
                return attrs['data-household-no'] ? [this] : [];
            }
            if (selector === '[data-member-id]') {
                return attrs['data-member-id'] ? [this] : [];
            }
            return [];
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0] || null;
        },
    };
}

describe('related warmup discovery', () => {
    it('warms the loaded household, amenities, EH steps, and listed member health pages', () => {
        const root = node(
            { 'data-household-no': 'HH-121', 'data-member-id': 'MB-001' },
            [
                '/household-profiling/HH-121/members/MB-001/family-planning/create',
                '/logout',
                '/household-profiling/HH-999',
            ],
        );
        const paths = discoverRelatedWarmPaths(root, { origin: 'https://lmlinga.test' });
        assert.equal(paths.includes('/household-profiling/HH-121'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/amenities'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/amenities/edit'), true);
        assert.equal(
            paths.includes('/environmental-health/household-water-supply?household=HH-121'),
            true,
        );
        assert.equal(paths.includes('/household-profiling/HH-121/members/MB-001/child-immunization'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/members/MB-001/death'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/members/MB-001/family-planning/create'), true);
        assert.equal(paths.includes('/logout'), false);
        assert.equal(paths.includes('/household-profiling/HH-999'), true);
        assert.equal(paths.some((entry) => entry.includes('/death/create')), false);
    });

    it('does not warm local-member health writes or run without an actor/controller', () => {
        const localRoot = node({ 'data-household-no': 'HH-121', 'data-member-id': 'MB-L-abc' });
        const paths = discoverRelatedWarmPaths(localRoot, { origin: 'https://lmlinga.test' });
        assert.equal(paths.includes('/household-profiling/HH-121'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/members/MB-L-abc'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/members/MB-L-abc/edit'), true);
        assert.equal(paths.includes('/household-profiling/HH-121/members/MB-L-abc/child-immunization'), true);
        assert.equal(paths.some((entry) => entry.includes('/death/create')), false);
        assert.equal(canWarmRelatedPages({
            actorId: 7,
            navigator: { onLine: true },
            worker: { postMessage() {} },
            paths: ['/household-profiling/HH-121'],
        }), true);
        assert.equal(canWarmRelatedPages({
            actorId: 7,
            navigator: { onLine: false },
            worker: { postMessage() {} },
            paths: ['/household-profiling/HH-121'],
        }), false);
    });
});
