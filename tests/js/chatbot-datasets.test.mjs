import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { matchesFilters } from '../../resources/js/pages/chatbot-datasets.js';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const { isNeverCachePath, moduleLabelForPath, warmupPathsForRole } = await import(policyUrl);

const row = { title: 'Breast Cancer', source: 'Breast Cancer.txt', category: 'other' };

describe('Chatbot datasets list filters', () => {
    it('matches everything when no filter is set', () => {
        assert.equal(matchesFilters(row), true);
        assert.equal(matchesFilters(row, { query: '', category: 'all' }), true);
    });

    it('searches the title and the source name, ignoring case and spaces around the text', () => {
        assert.equal(matchesFilters(row, { query: '  CANCER ' }), true);
        assert.equal(matchesFilters(row, { query: 'cancer.txt' }), true);
        assert.equal(matchesFilters(row, { query: 'diabetes' }), false);
    });

    it('filters by category', () => {
        assert.equal(matchesFilters(row, { category: 'other' }), true);
        assert.equal(matchesFilters(row, { category: 'child_care' }), false);
    });

    it('needs every filter to match', () => {
        assert.equal(matchesFilters(row, { query: 'cancer', category: 'other' }), true);
        assert.equal(matchesFilters(row, { query: 'cancer', category: 'child_care' }), false);
    });

    it('treats missing row fields as empty', () => {
        assert.equal(matchesFilters({}, { query: 'x' }), false);
        assert.equal(matchesFilters({}, {}), true);
    });
});

describe('Chatbot Datasets offline policy (SR-07)', () => {
    it('never caches dataset management pages', () => {
        for (const p of ['/chatbot-datasets', '/chatbot-datasets/create', '/chatbot-datasets/fabc/edit']) {
            assert.equal(isNeverCachePath(p), true, p);
        }
    });

    it('names the module in offline messages and keeps it out of warm-up', () => {
        assert.equal(moduleLabelForPath('/chatbot-datasets'), 'Chatbot Datasets');
        assert.equal(warmupPathsForRole('admin').some((p) => p.startsWith('/chatbot-datasets')), false);
    });

    it('no longer has Language Management or Language Review modules', () => {
        assert.equal(moduleLabelForPath('/language-management'), 'This page');
        assert.equal(moduleLabelForPath('/language-review'), 'This page');
    });
});
