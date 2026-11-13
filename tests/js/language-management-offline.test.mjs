/**
 * SR-05: Language Management (Admin) and Language Review pages are online-only.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const { isNeverCachePath, moduleLabelForPath, warmupPathsForRole } = await import(policyUrl);

describe('Language Management offline policy', () => {
    it('never caches term management or review pages', () => {
        for (const p of ['/language-management', '/language-management/create', '/language-management/babc/edit', '/language-review/terms/gabc']) {
            assert.equal(isNeverCachePath(p), true, p);
        }
    });

    it('names the modules in offline messages', () => {
        assert.equal(moduleLabelForPath('/language-management'), 'Language Management');
        assert.equal(moduleLabelForPath('/language-review/terms/gabc'), 'Language Review');
    });

    it('is not part of any offline warm-up', () => {
        for (const role of ['admin', 'bhw', 'bns', 'bspo']) {
            const paths = warmupPathsForRole(role);
            assert.equal(paths.some((p) => p.startsWith('/language-')), false, role);
        }
    });
});

describe('Chatbot Datasets offline policy (SR-07)', () => {
    it('never caches dataset management or dataset review pages', () => {
        for (const p of ['/chatbot-datasets', '/chatbot-datasets/create', '/chatbot-datasets/fabc/edit', '/language-review/datasets/jabc']) {
            assert.equal(isNeverCachePath(p), true, p);
        }
    });

    it('names the module in offline messages and keeps it out of warm-up', () => {
        assert.equal(moduleLabelForPath('/chatbot-datasets'), 'Chatbot Datasets');
        assert.equal(warmupPathsForRole('admin').some((p) => p.startsWith('/chatbot-datasets')), false);
    });
});
