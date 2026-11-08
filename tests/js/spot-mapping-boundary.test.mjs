/**
 * Boundary outline helpers for resources/js/pages/spot-mapping-boundary.js (SM-01).
 * Geometry is FICTIONAL test data. Runtime: Node.js built-in test runner.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const { BOUNDARY_STYLE, parseBoundaryGeoJson, drawBarangayBoundary, outsideMaskLatLngs } = await import(
    pathToFileURL(path.resolve('resources/js/pages/spot-mapping-boundary.js')).href
);

const feature = {
    type: 'Feature',
    properties: { source: 'Test fixture (fictional)' },
    geometry: { type: 'MultiPolygon', coordinates: [[[[100, 10], [101, 10], [101, 12], [100, 12], [100, 10]]]] },
};

const root = (attrs) => ({ getAttribute: (name) => (name in attrs ? attrs[name] : null) });

describe('parseBoundaryGeoJson', () => {
    it('returns the feature only when the server marked the boundary valid', () => {
        assert.deepEqual(parseBoundaryGeoJson(root({ 'data-boundary-state': 'valid', 'data-boundary': JSON.stringify(feature) })), feature);
        assert.equal(parseBoundaryGeoJson(root({ 'data-boundary-state': 'missing', 'data-boundary': JSON.stringify(feature) })), null);
        assert.equal(parseBoundaryGeoJson(root({ 'data-boundary-state': 'invalid' })), null);
    });

    it('fails closed on malformed or unexpected data', () => {
        for (const raw of ['{bad json', '[]', JSON.stringify({ type: 'Feature', geometry: { type: 'Point', coordinates: [100, 10] } }),
            JSON.stringify({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [] } })]) {
            assert.equal(parseBoundaryGeoJson(root({ 'data-boundary-state': 'valid', 'data-boundary': raw })), null, raw);
        }
        assert.equal(parseBoundaryGeoJson(root({ 'data-boundary-state': 'valid' })), null);
        assert.equal(parseBoundaryGeoJson(null), null);
    });
});

describe('outsideMaskLatLngs', () => {
    const ring = [[123.1, 13.1], [123.2, 13.1], [123.2, 13.2], [123.1, 13.1]];

    it('turns a Polygon outer ring into a [lat, lng] hole in a world ring', () => {
        const result = outsideMaskLatLngs({ geometry: { type: 'Polygon', coordinates: [ring, ring] } });
        assert.equal(result.length, 2, 'inner rings of the boundary are not masked');
        assert.deepEqual(result[0], [[-90, -180], [-90, 180], [90, 180], [90, -180]]);
        assert.deepEqual(result[1][0], [13.1, 123.1], 'GeoJSON [lng, lat] becomes Leaflet [lat, lng]');
    });

    it('gives each part of a MultiPolygon its own hole', () => {
        const result = outsideMaskLatLngs({ geometry: { type: 'MultiPolygon', coordinates: [[ring], [ring]] } });
        assert.equal(result.length, 3);
    });

    it('returns null for missing or unreadable geometry', () => {
        assert.equal(outsideMaskLatLngs(null), null);
        assert.equal(outsideMaskLatLngs({ geometry: { type: 'Point', coordinates: [1, 2] } }), null);
        assert.equal(outsideMaskLatLngs({ geometry: { type: 'Polygon', coordinates: [[[1, 2]]] } }), null);
        assert.equal(outsideMaskLatLngs({ geometry: { type: 'MultiPolygon', coordinates: [[]] } }), null);
    });
});

describe('drawBarangayBoundary', () => {
    it('adds a non-interactive outline (map clicks still plot) using GeoJSON as given', () => {
        let added = null;
        let captured = null;
        const map = {};
        const leaflet = {
            geoJSON(data, options) {
                captured = { data, options };
                return { addTo(m) { added = m; return this; } };
            },
        };

        const layer = drawBarangayBoundary(map, feature, leaflet);

        assert.ok(layer);
        assert.equal(added, map);
        assert.equal(captured.data, feature, 'GeoJSON [longitude, latitude] passed unchanged; Leaflet converts it');
        assert.equal(captured.options.interactive, false);
        assert.deepEqual(captured.options.style(), { ...BOUNDARY_STYLE });
    });

    it('dims the area outside the barangay with a non-interactive mask drawn under the outline', () => {
        const order = [];
        let mask = null;
        const map = {};
        const leaflet = {
            polygon(latLngs, options) {
                mask = { latLngs, options };
                return { addTo(m) { assert.equal(m, map); order.push('mask'); return this; } };
            },
            geoJSON() {
                return { addTo() { order.push('outline'); return this; } };
            },
        };

        const layer = drawBarangayBoundary(map, feature, leaflet);

        assert.ok(layer);
        assert.deepEqual(order, ['mask', 'outline']);
        assert.equal(mask.options.interactive, false, 'map clicks for manual plotting must still reach the map');
        assert.equal(mask.options.stroke, false);
        assert.ok(mask.options.fillOpacity > 0 && mask.options.fillOpacity < 1, 'outside stays dimly visible, not hidden');
        assert.equal(mask.latLngs.length, 2, 'world ring plus the barangay as a hole');
    });

    it('still draws the outline when the mask is unavailable', () => {
        let outlineDrawn = false;
        const leaflet = {
            polygon() { throw new Error('must not be called for unreadable geometry'); },
            geoJSON() { return { addTo() { outlineDrawn = true; return this; } }; },
        };
        drawBarangayBoundary({}, { type: 'Feature', geometry: { type: 'Polygon', coordinates: [[[1, 2]]] } }, leaflet);
        assert.equal(outlineDrawn, true);
    });

    it('draws nothing without a valid feature or map', () => {
        const leaflet = { geoJSON: () => { throw new Error('must not be called'); } };
        assert.equal(drawBarangayBoundary({}, null, leaflet), null);
        assert.equal(drawBarangayBoundary(null, feature, leaflet), null);
    });

    it('is wired into the Spot Mapping page right after the map is created', () => {
        const source = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
        assert.match(source, /createLaMedallaBaseMap\(mapEl[^)]*\);\s*\n\s*\/\/[^\n]*\n\s*drawBarangayBoundary\(map, parseBoundaryGeoJson\(root\), L\);/);
    });
});
