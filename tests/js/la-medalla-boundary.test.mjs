import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';

import {
    BOUNDARY_STYLE,
    OUTSIDE_MASK_STYLE,
    drawBarangayBoundary,
    isPointInBoundary,
    outsideMaskLatLngs,
} from '../../resources/js/maps/la-medalla-boundary.js';

const feature = JSON.parse(readFileSync(path.resolve('resources/js/maps/la-medalla-boundary.json'), 'utf8'));
const mapBase = readFileSync(path.resolve('resources/js/maps/la-medalla-base.js'), 'utf8');
const LA_MEDALLA_CENTER = [13.3806, 123.4312];

function fakeLeaflet() {
    const calls = [];
    const layer = (kind, args) => ({
        addTo(map) {
            calls.push({ kind, args, map });
            return this;
        },
    });
    return {
        calls,
        polygon: (...args) => layer('polygon', args),
        geoJSON: (...args) => layer('geoJSON', args),
    };
}

describe('La Medalla boundary data', () => {
    it('is a Polygon or MultiPolygon Feature with closed outer rings', () => {
        assert.equal(feature.type, 'Feature');
        assert.ok(['Polygon', 'MultiPolygon'].includes(feature.geometry.type));
        const rings = feature.geometry.type === 'Polygon'
            ? [feature.geometry.coordinates[0]]
            : feature.geometry.coordinates.map((p) => p[0]);
        for (const ring of rings) {
            assert.ok(ring.length >= 4);
            assert.deepEqual(ring[0], ring[ring.length - 1]);
        }
    });

    it('surrounds the La Medalla map centre in Iriga City', () => {
        const ring = feature.geometry.type === 'Polygon'
            ? feature.geometry.coordinates[0]
            : feature.geometry.coordinates[0][0];
        const lngs = ring.map(([lng]) => lng);
        const lats = ring.map(([, lat]) => lat);
        const [lat, lng] = LA_MEDALLA_CENTER;
        // [lng, lat] order: longitudes near 123.4 (east), latitudes near 13.4 (north)
        assert.ok(Math.min(...lngs) > 123 && Math.max(...lngs) < 124);
        assert.ok(Math.min(...lats) > 13 && Math.max(...lats) < 14);
        assert.ok(Math.abs(lng - (Math.min(...lngs) + Math.max(...lngs)) / 2) < 0.05);
        assert.ok(Math.abs(lat - (Math.min(...lats) + Math.max(...lats)) / 2) < 0.05);
    });

    it('keeps the indicative source label', () => {
        assert.match(feature.properties.source, /INDICATIVE/);
    });
});

describe('outsideMaskLatLngs', () => {
    it('returns a world ring plus the boundary as a hole, swapped to [lat, lng]', () => {
        const rings = outsideMaskLatLngs(feature);
        assert.equal(rings.length, 2);
        assert.deepEqual(rings[0], [[-90, -180], [-90, 180], [90, 180], [90, -180]]);
        const [lng, lat] = feature.geometry.coordinates[0][0];
        assert.deepEqual(rings[1][0], [lat, lng]);
    });

    it('adds one hole per MultiPolygon part', () => {
        const ring = feature.geometry.coordinates[0];
        const multi = { type: 'Feature', geometry: { type: 'MultiPolygon', coordinates: [[ring], [ring]] } };
        assert.equal(outsideMaskLatLngs(multi).length, 3);
    });

    it('returns null for missing or malformed geometry', () => {
        assert.equal(outsideMaskLatLngs(null), null);
        assert.equal(outsideMaskLatLngs({ type: 'Feature', geometry: { type: 'Point', coordinates: [1, 2] } }), null);
        assert.equal(outsideMaskLatLngs({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [[[1, 2]]] } }), null);
    });
});

describe('isPointInBoundary', () => {
    it('is true inside La Medalla and false outside', () => {
        assert.equal(isPointInBoundary(feature, 13.3806, 123.4312), true);
        assert.equal(isPointInBoundary(feature, 13.379, 123.433), true);
        assert.equal(isPointInBoundary(feature, 13.39, 123.44), false);
        assert.equal(isPointInBoundary(feature, 14.5995, 120.9842), false);
    });

    it('returns null when the boundary or point cannot be read', () => {
        assert.equal(isPointInBoundary(null, 13.3806, 123.4312), null);
        assert.equal(isPointInBoundary(feature, Number.NaN, 123.4312), null);
    });
});

describe('spot mapping plot guard', () => {
    const spot = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');

    it('uses the boundary for the scope check and blocks outside map clicks', () => {
        assert.match(spot, /isPointInBoundary\(LA_MEDALLA_BOUNDARY, lat, lng\)/);
        assert.match(spot, /OUTSIDE_SCOPE_MESSAGE = 'You cannot plot outside the Barangay La Medalla boundary\.'/);
        assert.match(
            spot,
            /if \(!isWithinDemoScope\(lat, lng\)\) \{[\s\S]*?showOverlay\(OUTSIDE_SCOPE_MESSAGE[\s\S]*?return;\s*\}\s*const tempData = placeTemporaryMarker\(lat, lng, \{ source: 'manual' \}\)/,
        );
    });
});

describe('drawBarangayBoundary', () => {
    it('draws a non-interactive mask, then a non-interactive outline', () => {
        const L = fakeLeaflet();
        const map = {};
        const layer = drawBarangayBoundary(map, feature, L);

        assert.ok(layer);
        assert.deepEqual(L.calls.map((c) => c.kind), ['polygon', 'geoJSON']);
        assert.ok(L.calls.every((c) => c.map === map));

        const maskOptions = L.calls[0].args[1];
        assert.equal(maskOptions.interactive, false);
        assert.equal(maskOptions.fillRule, OUTSIDE_MASK_STYLE.fillRule);

        const outlineOptions = L.calls[1].args[1];
        assert.equal(outlineOptions.interactive, false);
        assert.deepEqual(outlineOptions.style(), { ...BOUNDARY_STYLE });
    });

    it('draws nothing for invalid input', () => {
        const L = fakeLeaflet();
        assert.equal(drawBarangayBoundary({}, { type: 'Feature', geometry: null }, L), null);
        assert.equal(drawBarangayBoundary(null, feature, L), null);
        assert.equal(L.calls.length, 0);
    });
});

describe('base map focus', () => {
    it('opens framed on the boundary and re-fits after the container is sized', () => {
        assert.match(mapBase, /drawBarangayBoundary\(map, LA_MEDALLA_BOUNDARY, L\)/);
        assert.equal(mapBase.match(/focusOnBoundary\(\);/g)?.length, 2);
        assert.match(mapBase, /map\.fitBounds\(boundaryBounds/);
    });
});
