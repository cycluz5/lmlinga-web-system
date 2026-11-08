/**
 * New Born anthropometric derivation — focused matrix coverage.
 *
 * Runtime: Node.js built-in test runner (node:test) — no Vitest/Jest/Playwright.
 * Imports pure helpers exported from resources/js/pages/child-nutrition.js.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const moduleUrl = pathToFileURL(
    path.resolve('resources/js/pages/child-nutrition.js')
).href;

const {
    classifyNewbornMetric,
    combineNewbornStatuses,
    deriveNewbornStatus,
    normalizeNewbornSex,
    NEWBORN_THRESHOLDS,
} = await import(moduleUrl);

describe('normalizeNewbornSex', () => {
    it('maps Male/Female display values', () => {
        assert.equal(normalizeNewbornSex('Male'), 'male');
        assert.equal(normalizeNewbornSex('Female'), 'female');
        assert.equal(normalizeNewbornSex(' male '), 'male');
    });

    it('rejects unknown or empty sex without guessing', () => {
        assert.equal(normalizeNewbornSex(''), null);
        assert.equal(normalizeNewbornSex(null), null);
        assert.equal(normalizeNewbornSex('Unknown'), null);
        assert.equal(normalizeNewbornSex('Other'), null);
    });
});

describe('boys weight boundaries', () => {
    const band = NEWBORN_THRESHOLDS.male.weight;

    it('classifies approved boy weight cutovers', () => {
        assert.equal(classifyNewbornMetric(2.49, band), 'below_normal');
        assert.equal(classifyNewbornMetric(2.5, band), 'normal');
        assert.equal(classifyNewbornMetric(4.4, band), 'normal');
        assert.equal(classifyNewbornMetric(4.41, band), 'above_normal');
    });
});

describe('boys height boundaries', () => {
    const band = NEWBORN_THRESHOLDS.male.height;

    it('classifies approved boy height cutovers', () => {
        assert.equal(classifyNewbornMetric(46.0, band), 'below_normal');
        assert.equal(classifyNewbornMetric(46.1, band), 'normal');
        assert.equal(classifyNewbornMetric(53.7, band), 'normal');
        assert.equal(classifyNewbornMetric(53.8, band), 'above_normal');
    });
});

describe('girls weight boundaries', () => {
    const band = NEWBORN_THRESHOLDS.female.weight;

    it('classifies approved girl weight cutovers', () => {
        assert.equal(classifyNewbornMetric(2.39, band), 'below_normal');
        assert.equal(classifyNewbornMetric(2.4, band), 'normal');
        assert.equal(classifyNewbornMetric(4.2, band), 'normal');
        assert.equal(classifyNewbornMetric(4.21, band), 'above_normal');
    });
});

describe('girls height boundaries', () => {
    const band = NEWBORN_THRESHOLDS.female.height;

    it('classifies approved girl height cutovers', () => {
        assert.equal(classifyNewbornMetric(45.3, band), 'below_normal');
        assert.equal(classifyNewbornMetric(45.4, band), 'normal');
        assert.equal(classifyNewbornMetric(52.9, band), 'normal');
        assert.equal(classifyNewbornMetric(53.0, band), 'above_normal');
    });
});

describe('overall result matrix', () => {
    it('applies approved precedence combinations', () => {
        assert.equal(combineNewbornStatuses('normal', 'normal'), 'normal');
        assert.equal(combineNewbornStatuses('below_normal', 'normal'), 'below_normal');
        assert.equal(combineNewbornStatuses('normal', 'below_normal'), 'below_normal');
        assert.equal(combineNewbornStatuses('below_normal', 'below_normal'), 'below_normal');
        assert.equal(combineNewbornStatuses('above_normal', 'normal'), 'above_normal');
        assert.equal(combineNewbornStatuses('normal', 'above_normal'), 'above_normal');
        assert.equal(combineNewbornStatuses('above_normal', 'above_normal'), 'above_normal');
        assert.equal(combineNewbornStatuses('below_normal', 'above_normal'), 'below_normal');
    });
});

describe('deriveNewbornStatus empty and invalid inputs', () => {
    it('returns No record when weight is missing', () => {
        const result = deriveNewbornStatus({
            sex: 'Male',
            weightKg: '',
            heightCm: 50,
        });
        assert.equal(result.overall, 'no_record');
    });

    it('returns No record when height is missing', () => {
        const result = deriveNewbornStatus({
            sex: 'Female',
            weightKg: 3.0,
            heightCm: '',
        });
        assert.equal(result.overall, 'no_record');
    });

    it('returns No record when sex is missing or unrecognized', () => {
        assert.equal(
            deriveNewbornStatus({ sex: '', weightKg: 3.0, heightCm: 50 }).overall,
            'no_record'
        );
        assert.equal(
            deriveNewbornStatus({ sex: 'Unknown', weightKg: 3.0, heightCm: 50 }).overall,
            'no_record'
        );
    });
});

describe('deriveNewbornStatus end-to-end examples', () => {
    it('derives male normal / below / above overall results', () => {
        assert.equal(
            deriveNewbornStatus({ sex: 'Male', weightKg: 3.2, heightCm: 50 }).overall,
            'normal'
        );
        assert.equal(
            deriveNewbornStatus({ sex: 'Male', weightKg: 2.4, heightCm: 50 }).overall,
            'below_normal'
        );
        assert.equal(
            deriveNewbornStatus({ sex: 'Male', weightKg: 4.5, heightCm: 50 }).overall,
            'above_normal'
        );
    });

    it('derives female normal overall at mid-band values', () => {
        assert.equal(
            deriveNewbornStatus({ sex: 'Female', weightKg: 3.0, heightCm: 49 }).overall,
            'normal'
        );
    });

    it('gives Below precedence over Above when mixed', () => {
        assert.equal(
            deriveNewbornStatus({ sex: 'Male', weightKg: 2.4, heightCm: 54 }).overall,
            'below_normal'
        );
    });
});
