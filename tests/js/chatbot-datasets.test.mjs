import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { matchesFilters } from '../../resources/js/pages/chatbot-datasets.js';

const row = { title: 'Breast Cancer', source: 'Breast Cancer.txt', category: 'other', status: 'approved' };

describe('Chatbot datasets list filters', () => {
    it('matches everything when no filter is set', () => {
        assert.equal(matchesFilters(row), true);
        assert.equal(matchesFilters(row, { query: '', category: 'all', status: 'all' }), true);
    });

    it('searches the title and the source name, ignoring case and spaces around the text', () => {
        assert.equal(matchesFilters(row, { query: '  CANCER ' }), true);
        assert.equal(matchesFilters(row, { query: 'cancer.txt' }), true);
        assert.equal(matchesFilters(row, { query: 'diabetes' }), false);
    });

    it('filters by category and status', () => {
        assert.equal(matchesFilters(row, { category: 'other' }), true);
        assert.equal(matchesFilters(row, { category: 'child_care' }), false);
        assert.equal(matchesFilters(row, { status: 'approved' }), true);
        assert.equal(matchesFilters(row, { status: 'draft' }), false);
    });

    it('needs every filter to match', () => {
        assert.equal(matchesFilters(row, { query: 'cancer', category: 'other', status: 'approved' }), true);
        assert.equal(matchesFilters(row, { query: 'cancer', category: 'other', status: 'draft' }), false);
    });

    it('treats missing row fields as empty', () => {
        assert.equal(matchesFilters({}, { query: 'x' }), false);
        assert.equal(matchesFilters({}, {}), true);
    });
});
