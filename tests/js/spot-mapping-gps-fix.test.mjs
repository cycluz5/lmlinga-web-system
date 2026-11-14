import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
    GPS_GOOD_ACCURACY_M,
    betterGpsFix,
    gpsFixGoodEnough,
    isUsableGpsFix,
    outsideBoundaryGpsMessage,
} from '../../resources/js/pages/spot-mapping-gps-fix.js';

const fix = (accuracy, latitude = 13.3806, longitude = 123.4312) => ({
    coords: { latitude, longitude, accuracy },
});

describe('betterGpsFix', () => {
    it('keeps the more accurate fix', () => {
        const coarse = fix(800);
        const fine = fix(20);
        assert.equal(betterGpsFix(null, coarse), coarse);
        assert.equal(betterGpsFix(coarse, fine), fine);
        assert.equal(betterGpsFix(fine, coarse), fine);
    });

    it('never replaces a usable fix with an unusable one', () => {
        const good = fix(50);
        assert.equal(betterGpsFix(good, { coords: { latitude: NaN, longitude: 1 } }), good);
        assert.equal(betterGpsFix(null, null), null);
    });

    it('treats a missing accuracy as the least accurate', () => {
        const unknown = { coords: { latitude: 13.38, longitude: 123.43 } };
        const known = fix(500);
        assert.equal(betterGpsFix(unknown, known), known);
        assert.equal(betterGpsFix(known, unknown), known);
    });
});

describe('gpsFixGoodEnough', () => {
    it('stops waiting at or below the threshold only', () => {
        assert.equal(gpsFixGoodEnough(fix(GPS_GOOD_ACCURACY_M)), true);
        assert.equal(gpsFixGoodEnough(fix(GPS_GOOD_ACCURACY_M + 1)), false);
        assert.equal(gpsFixGoodEnough(null), false);
        assert.equal(isUsableGpsFix({ coords: {} }), false);
    });
});

describe('outsideBoundaryGpsMessage', () => {
    const base = 'You cannot plot outside the Barangay La Medalla boundary.';

    it('shows coordinates, accuracy and distance', () => {
        const message = outsideBoundaryGpsMessage(base, {
            lat: 13.37512, lng: 123.43001, accuracy: 45, distanceMeters: 320,
        });
        assert.match(message, /13\.37512, 123\.43001, ±45 m/);
        assert.match(message, /about 320 m outside/);
        assert.doesNotMatch(message, /weak/);
    });

    it('adds a weak-signal hint for poor accuracy', () => {
        const message = outsideBoundaryGpsMessage(base, { lat: 13.3, lng: 123.4, accuracy: 600 });
        assert.match(message, /GPS signal is weak/);
    });

    it('returns the base message when coordinates are unknown', () => {
        assert.equal(outsideBoundaryGpsMessage(base, {}), base);
    });
});
