/**
 * Household Profiling list summary cards follow the visible (filtered) rows.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { applyFilters } from '../../resources/js/pages/household-profiling.js';
import { createDocument } from './support/sidebar-mini-dom.mjs';

function el(doc, tag, attrs = {}) {
    const node = doc.createElement(tag);
    Object.entries(attrs).forEach(([name, value]) => node.setAttribute(name, String(value)));
    return node;
}

function buildRoot(doc) {
    const root = el(doc, 'div', { 'data-lml-hh-profiling': '', 'data-total': '2' });

    const zone = el(doc, 'select', { 'data-hh-zone': '' });
    zone.appendChild(Object.assign(el(doc, 'option', { value: 'all' }), { selected: true }));
    zone.appendChild(el(doc, 'option', { value: 'Zone 1' }));
    zone.appendChild(el(doc, 'option', { value: 'Zone 2' }));
    root.appendChild(zone);

    for (const key of ['households', 'respondents', 'male', 'female']) {
        root.appendChild(el(doc, 'p', { 'data-stat': key }));
    }

    root.appendChild(el(doc, 'p', { 'data-hh-results': '' }));

    const tbody = el(doc, 'tbody', { 'data-hh-tbody': '' });
    tbody.appendChild(el(doc, 'tr', {
        'data-hh-row': '',
        'data-house-head': 'Ada One',
        'data-zone': 'Zone 1',
        'data-street': 'A St',
        'data-members': '3',
        'data-male': '1',
        'data-female': '2',
    }));
    tbody.appendChild(el(doc, 'tr', {
        'data-hh-row': '',
        'data-house-head': 'Bob Two',
        'data-zone': 'Zone 2',
        'data-street': 'B St',
        'data-members': '2',
        'data-male': '2',
        'data-female': '0',
    }));
    root.appendChild(tbody);

    return { root, zone };
}

describe('household profiling filter summary stats', () => {
    it('recalculates cards for All Zones and a specific zone', () => {
        const doc = createDocument();
        const { root, zone } = buildRoot(doc);

        applyFilters(root);
        assert.equal(root.querySelector('[data-stat="households"]').textContent, '2');
        assert.equal(root.querySelector('[data-stat="respondents"]').textContent, '5');
        assert.equal(root.querySelector('[data-stat="male"]').textContent, '3');
        assert.equal(root.querySelector('[data-stat="female"]').textContent, '2');

        zone.value = 'Zone 1';
        applyFilters(root);
        assert.equal(root.querySelector('[data-stat="households"]').textContent, '1');
        assert.equal(root.querySelector('[data-stat="respondents"]').textContent, '3');
        assert.equal(root.querySelector('[data-stat="male"]').textContent, '1');
        assert.equal(root.querySelector('[data-stat="female"]').textContent, '2');
        assert.match(root.querySelector('[data-hh-results]').textContent, /Showing 1 of 2 households/);
    });
});
