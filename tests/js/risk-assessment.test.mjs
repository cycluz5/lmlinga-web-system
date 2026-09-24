/**
 * Risk Assessment pure helpers — exclusive "None" logic + date filter.
 * Zero-dependency Node test runner (no jsdom).
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const moduleUrl = pathToFileURL(
    path.resolve('resources/js/pages/risk-assessment.js')
).href;

const {
    applyNoneExclusiveLogic,
    filterHistoryByDate,
    isCustomRangeReady,
    calculateBmi,
    calculateBmiStatus,
    bmiStatusFromValue,
    calculateBpStatus,
} = await import(moduleUrl);

function makeCheckbox({ value, checked = false, none = false }) {
    return {
        type: 'checkbox',
        value,
        checked,
        disabled: false,
        hasAttribute(name) {
            return none && name === 'data-risk-assess-none';
        },
    };
}

function makeGroup(inputs) {
    return {
        getAttribute(name) {
            return name === 'data-none-key' ? 'none' : null;
        },
        querySelectorAll(selector) {
            if (selector === 'input[type="checkbox"]') {
                return inputs;
            }
            return [];
        },
    };
}

describe('applyNoneExclusiveLogic', () => {
    it('clears other conditions when None is selected', () => {
        const chest = makeCheckbox({ value: 'chest_pain', checked: true });
        const none = makeCheckbox({ value: 'none', none: true });
        const group = makeGroup([chest, none]);

        none.checked = true;
        applyNoneExclusiveLogic(group, none);

        assert.equal(chest.checked, false);
        assert.equal(none.checked, true);
    });

    it('clears None when another condition is selected', () => {
        const chest = makeCheckbox({ value: 'chest_pain' });
        const none = makeCheckbox({ value: 'none', checked: true, none: true });
        const group = makeGroup([chest, none]);

        chest.checked = true;
        applyNoneExclusiveLogic(group, chest);

        assert.equal(none.checked, false);
        assert.equal(chest.checked, true);
    });

    it('allows empty selection (optional assessment)', () => {
        const chest = makeCheckbox({ value: 'chest_pain' });
        const none = makeCheckbox({ value: 'none', none: true });
        const group = makeGroup([chest, none]);

        applyNoneExclusiveLogic(group, chest);

        assert.equal(chest.checked, false);
        assert.equal(none.checked, false);
    });
});

describe('filterHistoryByDate', () => {
    const rows = [
        { id: 'RA-001', conducted_at: '2026-06-08' },
        { id: 'RA-002', conducted_at: '2026-05-01' },
        { id: 'RA-003', conducted_at: '2025-10-08' },
    ];
    const today = '2026-06-15';

    it('returns all rows for empty / All Dates', () => {
        assert.equal(filterHistoryByDate(rows, '', { today }).length, 3);
        assert.equal(filterHistoryByDate(rows, null, { today }).length, 3);
        assert.equal(filterHistoryByDate(rows, 'all', { today }).length, 3);
    });

    it('filters This Month by calendar month', () => {
        const filtered = filterHistoryByDate(rows, 'this_month', { today });
        assert.equal(filtered.length, 1);
        assert.equal(filtered[0].id, 'RA-001');
    });

    it('filters Last 3 Months with rolling inclusive window', () => {
        const filtered = filterHistoryByDate(rows, 'last_3_months', { today });
        assert.equal(filtered.length, 2);
        assert.deepEqual(
            filtered.map((row) => row.id),
            ['RA-001', 'RA-002']
        );
    });

    it('filters This Year by calendar year', () => {
        const filtered = filterHistoryByDate(rows, 'this_year', {
            today: '2026-08-09',
        });
        assert.equal(filtered.length, 2);
        assert.deepEqual(
            filtered.map((row) => row.id),
            ['RA-001', 'RA-002']
        );
    });

    it('applies Custom range with inclusive From/To bounds', () => {
        const filtered = filterHistoryByDate(rows, 'custom', {
            from: '2026-05-01',
            to: '2026-06-08',
            today,
        });
        assert.equal(filtered.length, 2);
        assert.deepEqual(
            filtered.map((row) => row.id),
            ['RA-001', 'RA-002']
        );

        const singleDay = filterHistoryByDate(rows, 'custom', {
            from: '2026-06-08',
            to: '2026-06-08',
            today,
        });
        assert.equal(singleDay.length, 1);
        assert.equal(singleDay[0].id, 'RA-001');
    });

    it('does not apply incomplete or invalid Custom range', () => {
        assert.equal(
            filterHistoryByDate(rows, 'custom', {
                from: '2026-05-01',
                today,
            }).length,
            3
        );
        assert.equal(
            filterHistoryByDate(rows, 'custom', {
                from: '2026-06-30',
                to: '2026-06-01',
                today,
            }).length,
            3
        );
    });

    it('does not mutate source assessment rows', () => {
        const snapshot = structuredClone(rows);
        filterHistoryByDate(rows, 'this_month', { today });
        filterHistoryByDate(rows, 'custom', {
            from: '2026-05-01',
            to: '2026-05-01',
            today,
        });
        assert.deepEqual(rows, snapshot);
    });
});

describe('isCustomRangeReady', () => {
    it('requires both bounds and From <= To', () => {
        assert.equal(isCustomRangeReady('2026-05-01', '2026-06-08'), true);
        assert.equal(isCustomRangeReady('2026-05-01', ''), false);
        assert.equal(isCustomRangeReady('', '2026-06-08'), false);
        assert.equal(isCustomRangeReady('2026-06-30', '2026-06-01'), false);
    });
});

describe('calculateBmi', () => {
    it('calculates 178 cm + 55 kg as 17.4', () => {
        assert.equal(calculateBmi(178, 55), '17.4');
        assert.equal(calculateBmi('178', '55'), '17.4');
    });

    it('returns empty for missing, zero, or invalid inputs', () => {
        assert.equal(calculateBmi('', 55), '');
        assert.equal(calculateBmi(178, ''), '');
        assert.equal(calculateBmi(0, 55), '');
        assert.equal(calculateBmi(178, 0), '');
        assert.equal(calculateBmi(-170, 55), '');
        assert.equal(calculateBmi('abc', 55), '');
    });
});

describe('calculateBpStatus', () => {
    it('classifies approved BP examples', () => {
        assert.equal(calculateBpStatus(118, 76), 'NORMAL');
        assert.equal(calculateBpStatus(124, 76), 'ELEVATED');
        assert.equal(calculateBpStatus(111, 80), 'STAGE 1 HYPERTENSION');
        assert.equal(calculateBpStatus(135, 75), 'STAGE 1 HYPERTENSION');
        assert.equal(calculateBpStatus(145, 82), 'STAGE 2 HYPERTENSION');
        assert.equal(calculateBpStatus(181, 100), 'SEVERE HYPERTENSION');
    });

    it('returns empty when either reading is missing or invalid', () => {
        assert.equal(calculateBpStatus('', 76), '');
        assert.equal(calculateBpStatus(118, ''), '');
        assert.equal(calculateBpStatus('x', 76), '');
    });
});

describe('bmiStatusFromValue', () => {
    it('maps the specified BMI ranges and leaves >=35 unspecified', () => {
        assert.equal(bmiStatusFromValue('18.4'), 'Underweight');
        assert.equal(bmiStatusFromValue('18.5'), 'Normal');
        assert.equal(bmiStatusFromValue('24.9'), 'Normal');
        assert.equal(bmiStatusFromValue('25.0'), 'Overweight');
        assert.equal(bmiStatusFromValue('29.9'), 'Overweight');
        assert.equal(bmiStatusFromValue('30.0'), 'Obesity');
        assert.equal(bmiStatusFromValue('34.9'), 'Obesity');
        assert.equal(bmiStatusFromValue('35.0'), '');
        assert.equal(bmiStatusFromValue('40'), '');
        assert.equal(bmiStatusFromValue(''), '');
    });
});

describe('calculateBmiStatus', () => {
    it('derives Underweight from 178 cm + 55 kg', () => {
        assert.equal(calculateBmiStatus(178, 55), 'Underweight');
    });
});
