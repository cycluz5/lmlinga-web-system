/**
 * Spot Mapping location check (Phase 3A): boundary awareness, GPS accuracy wording and location kinds.
 * Uses geometry test points derived from the indicative boundary (tests/fixtures/spot-mapping/
 * boundary-check-points.json, expected answers produced by App\Support\Geo\BarangayBoundary::contains).
 * These are not household locations and nothing is stored.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const check = await import(pathToFileURL(path.resolve('resources/js/pages/spot-mapping-location-check.js')).href);
const feature = JSON.parse(readFileSync(path.resolve('resources/geo/la-medalla-boundary.geojson'), 'utf8'));
const fixture = JSON.parse(readFileSync(path.resolve('tests/fixtures/spot-mapping/boundary-check-points.json'), 'utf8'));

describe('boundary inside test matches the server', () => {
    it('agrees with BarangayBoundary::contains on every fixture point', () => {
        assert.ok(fixture.points.length >= 300);
        const mismatches = fixture.points.filter((p) => check.boundaryContains(feature, p.lat, p.lng) !== p.inside);
        assert.deepEqual(mismatches.map((p) => p.label), []);
    });

    it('edges and corners count as inside; the map centre is inside', () => {
        for (const p of fixture.points.filter((x) => /^vertex|midpoint$/.test(x.label))) {
            assert.equal(check.boundaryContains(feature, p.lat, p.lng), true, p.label);
        }
        assert.equal(check.boundaryContains(feature, 13.3806, 123.4312), true);
    });

    it('missing or malformed boundary: never inside, status "unchecked"', () => {
        for (const bad of [null, {}, { geometry: { type: 'Point', coordinates: [1, 2] } }]) {
            assert.equal(check.boundaryContains(bad, 13.38, 123.43), false);
            assert.equal(check.boundaryStatus(bad, 13.38, 123.43).status, 'unchecked');
        }
        assert.equal(check.boundaryStatus(feature, 'x', null).status, 'unchecked');
    });
});

describe('near / outside classification', () => {
    const offsets = fixture.points.filter((p) => typeof p.offsetMeters === 'number' && !p.inside);

    it('outside points are measured no farther than their offset from the edge', () => {
        assert.ok(offsets.length > 20);
        for (const p of offsets) {
            const d = check.distanceToBoundaryMeters(feature, p.lat, p.lng);
            // 2% tolerance: the fixture generator and the module use slightly different metres-per-degree figures.
            assert.ok(d <= Math.abs(p.offsetMeters) * 1.02 + 1, `${p.label}: ${d}`);
        }
    });

    it('50 m outside is "near"; status always matches the 200 m margin', () => {
        for (const p of offsets) {
            const s = check.boundaryStatus(feature, p.lat, p.lng);
            if (Math.abs(p.offsetMeters) === 50) {
                assert.equal(s.status, 'near', p.label);
            }
            assert.equal(s.status, s.distanceMeters <= check.NEAR_BOUNDARY_METERS ? 'near' : 'outside', p.label);
        }
        assert.ok(offsets.some((p) => check.boundaryStatus(feature, p.lat, p.lng).status === 'outside'));
    });

    it('the old 2 km circle problem is gone: inside corners far from the centre are "inside"', () => {
        const far = fixture.points.filter((p) => p.inside && Math.hypot((p.lat - 13.3806) * 110570, (p.lng - 123.4312) * 108300) > 2000);
        assert.ok(far.length > 0, 'fixture has inside points beyond 2 km of the centre');
        for (const p of far) {
            assert.equal(check.boundaryStatus(feature, p.lat, p.lng).status, 'inside', p.label);
        }
    });
});

describe('GPS accuracy and location kinds', () => {
    it('accuracy levels: ≤30 good, ≤100 weak, >100 rough, missing unknown', () => {
        assert.deepEqual([5, 30, 30.5, 100, 101, null, undefined, -1, 'x'].map(check.accuracyLevel),
            ['good', 'good', 'weak', 'weak', 'rough', 'unknown', 'unknown', 'unknown', 'unknown']);
    });

    it('kinds separate GPS, weak GPS, hand-placed, simulated and saved', () => {
        assert.equal(check.locationKind({ source: 'gps', accuracy: 12, isTemp: true }), 'gps');
        assert.equal(check.locationKind({ source: 'gps', accuracy: 60, isTemp: true }), 'gps-weak');
        assert.equal(check.locationKind({ source: 'gps', accuracy: null, isTemp: true }), 'gps-weak');
        assert.equal(check.locationKind({ source: 'manual', isTemp: true }), 'manual');
        assert.equal(check.locationKind({ source: 'simulated', isTemp: true }), 'simulated');
        assert.equal(check.locationKind({ source: 'simulated', isTemp: false }), 'simulated');
        assert.equal(check.locationKind({ isTemp: false }), 'saved');
        assert.equal(check.locationKindLabel('saved'), 'Saved location (source not recorded)');
        assert.match(check.locationKindLabel('manual'), /^Unverified/);
        assert.match(check.locationKindLabel('simulated'), /never saved/);
    });
});

describe('panel wording never blocks saving', () => {
    const inside = { lat: 13.3806, lng: 123.4312 };
    const far = fixture.points.find((p) => !p.inside && check.boundaryStatus(feature, p.lat, p.lng).status === 'outside');
    const near = fixture.points.find((p) => p.offsetMeters && Math.abs(p.offsetMeters) === 50 && !p.inside);

    it('good GPS inside: no warning, no confirmation', () => {
        const d = check.describeLocationCheck({ feature, ...inside, source: 'gps', accuracy: 8 });
        assert.equal(d.level, 'ok');
        assert.equal(d.needsConfirm, false);
        assert.equal(d.kindLabel, 'GPS location');
        assert.equal(d.accuracyText, 'Accuracy: about ±8 m.');
        assert.equal(d.boundaryText, 'Inside the indicative La Medalla boundary.');
        assert.equal(d.adviceText, '');
    });

    it('near the edge: a note, still no confirmation', () => {
        const d = check.describeLocationCheck({ feature, lat: near.lat, lng: near.lng, source: 'gps', accuracy: 10 });
        assert.equal(d.level, 'note');
        assert.equal(d.needsConfirm, false);
        assert.match(d.boundaryText, /^Just outside the indicative La Medalla boundary \(about \d+ m\)\. The boundary is approximate; you can still save\.$/);
    });

    it('clearly outside: a warning and a tick-box, never a rejection', () => {
        const d = check.describeLocationCheck({ feature, lat: far.lat, lng: far.lng, source: 'gps', accuracy: 10 });
        assert.equal(d.level, 'warn');
        assert.equal(d.needsConfirm, true);
        assert.match(d.boundaryText, /^Outside the indicative La Medalla boundary \(about .+ away\)\. Check the location before saving\.$/);
        assert.doesNotMatch(JSON.stringify(d), /cannot save|not allowed|rejected|blocked/i);
    });

    it('weak / rough GPS: advice to try again, saving still allowed', () => {
        const weak = check.describeLocationCheck({ feature, ...inside, source: 'gps', accuracy: 60 });
        assert.equal(weak.level, 'note');
        assert.match(weak.adviceText, /GPS signal is weak/);
        const rough = check.describeLocationCheck({ feature, ...inside, source: 'gps', accuracy: 250 });
        assert.equal(rough.level, 'warn');
        assert.equal(rough.needsConfirm, false);
    });

    it('hand-placed: labelled unverified with a check-the-pin note', () => {
        const d = check.describeLocationCheck({ feature, ...inside, source: 'manual' });
        assert.match(d.kindLabel, /^Unverified/);
        assert.equal(d.accuracyText, 'Accuracy: unknown (placed by hand on the map).');
        assert.match(d.adviceText, /right house/);
    });

    it('simulated (demo) points never ask for the outside tick-box', () => {
        const d = check.describeLocationCheck({ feature, lat: far.lat, lng: far.lng, source: 'simulated' });
        assert.equal(d.needsConfirm, false);
        assert.match(d.kindLabel, /never saved/);
    });

    it('no boundary: says it was not checked, never blocks', () => {
        const d = check.describeLocationCheck({ feature: null, ...inside, source: 'gps', accuracy: 8 });
        assert.equal(d.boundaryText, 'Boundary not available, so the location was not checked.');
        assert.equal(d.needsConfirm, false);
    });
});

describe('household-number labels', () => {
    it('show from zoom 18 with at most 200 markers on screen', () => {
        assert.equal(check.shouldShowHouseholdLabels(17, 5), false);
        assert.equal(check.shouldShowHouseholdLabels(18, 5), true);
        assert.equal(check.shouldShowHouseholdLabels(20, 200), true);
        assert.equal(check.shouldShowHouseholdLabels(20, 201), false);
    });

    it('text is the household number only (never names), with "+N" for groups', () => {
        assert.equal(check.householdLabelText('HH-0012'), 'HH-0012');
        assert.equal(check.householdLabelText('HH-0012', 3), 'HH-0012 +2');
        assert.equal(check.householdLabelText('HH-NEW'), '');
        assert.equal(check.householdLabelText(''), '');
    });
});

describe('Spot Mapping page wiring (source checks)', () => {
    const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
    const css = readFileSync(path.resolve('resources/css/pages/spot-mapping.css'), 'utf8');
    const blade = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');

    it('markers show the zone number and the location kind', () => {
        assert.match(src, /lml-spot-map__marker-zone/);
        assert.match(src, /lml-spot-map__marker--kind-\$\{kind\}/);
        assert.match(css, /\.lml-spot-map__marker-zone \{[^}]*color: #111827;/);
        assert.match(css, /--lml-spot-zone-5: #8b5cf6;/); // approved palette unchanged (dashboard must match)
    });

    it('the outside tick-box is checked in BOTH plot flows, and only adds to the form errors', () => {
        assert.equal((src.match(/requireBoundaryConfirm\(invalid\);/g) || []).length, 2);
        assert.match(blade, /data-spot-map-boundary-confirm-input/);
        assert.match(blade, /data-error-for="boundaryConfirm"/);
    });

    it('GPS accuracy circle is tied to the unsaved GPS pin and removed with it', () => {
        // Phase 3B: the class also carries the accuracy level (good / weak / rough).
        assert.match(src, /className: `lml-spot-map__accuracy-circle lml-spot-map__accuracy-circle--/);
        assert.match(src, /marker\.on\('remove', \(\) => circle\.remove\(\)\)/);
    });

    it('labels follow zoom/pan and overlap groups', () => {
        assert.match(src, /map\.on\('zoomend moveend', \(\) => syncHouseholdLabels\(\)\);/);
        assert.match(src, /syncHouseholdLabels\(\);\n    \}/);
    });

    it('safeguards untouched: offline click still blocked, server requests have no boundary rule', () => {
        const click = src.slice(src.indexOf("map.on('click', (event) => {"));
        assert.ok(click.indexOf('isClientOffline()') < click.indexOf('placeTemporaryMarker('));
        assert.match(src, /export const SPOT_MAP_OFFLINE_MESSAGE = 'Plotting household is not available when offline\.'/);
    });
});

describe('saved-household wording (Phase 3B)', () => {
    const far = fixture.points.find((p) => p.label === 'grid 0,0');
    const near = fixture.points.find((p) => Math.abs(p.offsetMeters || 0) === 50 && !p.inside);

    it('saved households are asked to be checked, never told "before saving" / "you can still save"', () => {
        const out = check.describeLocationCheck({ feature, lat: far.lat, lng: far.lng, source: null, isTemp: false });
        assert.match(out.boundaryText, /Please check this saved location\.$/);
        assert.doesNotMatch(out.boundaryText, /before saving/);
        const nr = check.describeLocationCheck({ feature, lat: near.lat, lng: near.lng, source: null, isTemp: false });
        assert.match(nr.boundaryText, /The boundary is approximate\.$/);
        assert.doesNotMatch(nr.boundaryText, /still save/);
    });

    it('new pins keep the saving wording', () => {
        const out = check.describeLocationCheck({ feature, lat: far.lat, lng: far.lng, source: 'gps', accuracy: 5, isTemp: true });
        assert.match(out.boundaryText, /Check the location before saving\.$/);
    });
});
