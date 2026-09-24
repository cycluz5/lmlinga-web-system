import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    ageBand,
    BAND_ADOLESCENT,
    BAND_ADULT,
    BAND_CHILD,
    BAND_INFANT,
    completedMonths,
    completedYears,
    computeBmi,
    parseIsoDate,
} from '../../resources/js/pages/nutritional-status.js';

describe('nutritional status age-band preview (client-side mirror only)', () => {
    it('computes completed months/years the same way as Carbon diffInMonths/diffInYears', () => {
        const birth = parseIsoDate('2025-03-16');
        assert.equal(completedMonths(birth, parseIsoDate('2025-03-16')), 0);
        assert.equal(completedMonths(birth, parseIsoDate('2025-09-15')), 5);
        assert.equal(completedMonths(birth, parseIsoDate('2025-09-16')), 6);
        assert.equal(completedMonths(birth, parseIsoDate('2026-02-15')), 10);

        const adultBirth = parseIsoDate('1996-09-16');
        assert.equal(completedYears(adultBirth, parseIsoDate('2026-09-15')), 29);
        assert.equal(completedYears(adultBirth, parseIsoDate('2026-09-16')), 30);
    });

    it('maps months/years to the four bands at the exact boundaries', () => {
        assert.equal(ageBand(0, 0), BAND_INFANT);
        assert.equal(ageBand(5, 0), BAND_INFANT);
        assert.equal(ageBand(6, 0), BAND_CHILD);
        assert.equal(ageBand(59, 4), BAND_CHILD);
        assert.equal(ageBand(60, 5), BAND_ADOLESCENT);
        assert.equal(ageBand(228, 18), BAND_ADOLESCENT);
        assert.equal(ageBand(228, 19), BAND_ADULT);
        assert.equal(ageBand(600, 50), BAND_ADULT);
    });

    it('returns null band when age cannot be determined', () => {
        assert.equal(ageBand(null, null), null);
    });

    it('computes BMI the same formula as the server (weight / height_m^2, rounded to 1dp)', () => {
        assert.equal(computeBmi('50', '160'), 19.5);
        assert.equal(computeBmi('0', '160'), null);
        assert.equal(computeBmi('50', '0'), null);
        assert.equal(computeBmi('abc', '160'), null);
    });
});
