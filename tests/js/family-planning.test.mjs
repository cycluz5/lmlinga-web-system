/**
 * Family Planning pure helpers — visit date filter.
 * Zero-dependency Node test runner (no jsdom).
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const moduleUrl = pathToFileURL(
    path.resolve('resources/js/pages/family-planning.js')
).href;

const { filterVisitsByDate, isCustomRangeReady } = await import(moduleUrl);

const sampleRows = [
    { id: 'FP-001', visited_at: '2026-06-08' },
    { id: 'FP-002', visited_at: '2026-05-01' },
    { id: 'FP-003', visited_at: '2025-10-08' },
];

describe('filterVisitsByDate', () => {
    it('returns all rows for empty / all filters', () => {
        assert.equal(filterVisitsByDate(sampleRows, '').length, 3);
        assert.equal(filterVisitsByDate(sampleRows, 'all').length, 3);
    });

    it('filters this_year against frozen today', () => {
        const rows = filterVisitsByDate(sampleRows, 'this_year', {
            today: '2026-06-15',
        });
        assert.deepEqual(
            rows.map((r) => r.id),
            ['FP-001', 'FP-002']
        );
    });

    it('filters custom inclusive range', () => {
        const rows = filterVisitsByDate(sampleRows, 'custom', {
            from: '2026-05-01',
            to: '2026-05-01',
        });
        assert.equal(rows.length, 1);
        assert.equal(rows[0].id, 'FP-002');
    });

    it('leaves rows unfiltered when custom range is incomplete', () => {
        const rows = filterVisitsByDate(sampleRows, 'custom', {
            from: '2026-05-01',
            to: '',
        });
        assert.equal(rows.length, 3);
    });
});

describe('isCustomRangeReady', () => {
    it('requires both ends and from <= to', () => {
        assert.equal(isCustomRangeReady('2026-01-01', '2026-02-01'), true);
        assert.equal(isCustomRangeReady('2026-02-01', '2026-01-01'), false);
        assert.equal(isCustomRangeReady('', '2026-01-01'), false);
    });
});
