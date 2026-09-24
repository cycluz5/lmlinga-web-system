/**
 * Deworming monitoring empty-state vs filter-empty behavior.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(path.resolve('resources/js/pages/health-records-deworming.js')).href;
const { applyDewormingFilters } = await import(moduleUrl);

function node(attrs = {}, children = {}) {
    return {
        hidden: false,
        dataset: attrs,
        textContent: '',
        querySelector(selector) {
            return children[selector] || null;
        },
        querySelectorAll(selector) {
            return children[selector] || [];
        },
    };
}

describe('health records deworming empty state', () => {
    it('shows no-records copy when the server list is empty', () => {
        const title = { textContent: '' };
        const hint = { textContent: '' };
        const empty = node({}, {
            '[data-hr-dw-empty-title]': title,
            '[data-hr-dw-empty-hint]': hint,
        });
        const tbody = node({}, { '[data-hr-dw-row]': [] });
        const root = node({ total: '0' }, {
            '[data-hr-dw-tbody]': tbody,
            '[data-hr-dw-empty]': empty,
            '[data-hr-dw-results]': { textContent: '' },
            '.lml-hr-child-care__table-scroll--deworming': { hidden: false },
            '[data-hr-dw-search]': { value: '' },
            '[data-hr-dw-zone]': { value: 'all' },
            '[data-hr-dw-sex]': { value: 'all' },
            '[data-hr-dw-status]': { value: 'all' },
        });

        applyDewormingFilters(root);

        assert.equal(empty.hidden, false);
        assert.equal(title.textContent, 'No Deworming records found.');
    });
});
