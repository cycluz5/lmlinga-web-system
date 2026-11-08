/**
 * Spot Mapping marker view (Phase 3B): zoom bands, "location to check" flag, household popup card.
 * Pure helpers + source checks. Boundary test points come from tests/fixtures/spot-mapping/boundary-check-points.json
 * (geometry inputs, not households). Nothing is stored.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const view = await import(pathToFileURL(path.resolve('resources/js/pages/spot-mapping-marker-view.js')).href);
const feature = JSON.parse(readFileSync(path.resolve('resources/geo/la-medalla-boundary.geojson'), 'utf8'));
const fixture = JSON.parse(readFileSync(path.resolve('tests/fixtures/spot-mapping/boundary-check-points.json'), 'utf8'));

describe('zoom bands', () => {
    it('low below 16, mid 16-17, high from 18', () => {
        assert.deepEqual([10, 15, 15.9, 16, 17, 17.9, 18, 22, NaN, null].map(view.zoomBand),
            ['low', 'low', 'low', 'mid', 'mid', 'mid', 'high', 'high', 'low', 'low']);
    });
});

describe('"location to check" flag for saved households', () => {
    it('inside or near the edge: no flag; clearly outside: "check"', () => {
        const inside = fixture.points.find((p) => p.label === 'map centre');
        assert.equal(view.savedLocationFlag(feature, inside.lat, inside.lng), null);
        const near = fixture.points.find((p) => Math.abs(p.offsetMeters || 0) === 50 && !p.inside);
        assert.equal(view.savedLocationFlag(feature, near.lat, near.lng), null);
        const far = fixture.points.find((p) => p.label === 'grid 0,0');
        assert.equal(far.inside, false);
        assert.equal(view.savedLocationFlag(feature, far.lat, far.lng), 'check');
    });

    it('no boundary available: never flagged', () => {
        assert.equal(view.savedLocationFlag(null, 13.0, 123.0), null);
    });
});

describe('household popup card', () => {
    const base = { householdNo: '901', householdKey: 'hkey123', zone: '2', zoneLabel: 'Zone 2', houseHead: 'Rosa Dela Prueba', members: 2, householdType: 'Non-NHTS' };

    it('shows number, zone, head, members, type, location note and a View link', () => {
        const html = view.householdPopupHtml(base);
        for (const text of ['901', 'Zone 2', 'Rosa Dela Prueba', '>2<', 'Non-NHTS', 'Saved location (source not recorded).', 'View household']) {
            assert.ok(html.includes(text), text);
        }
        assert.match(html, /href="\/household-profiling\/hkey123"/);
        assert.doesNotMatch(html, /lml-spot-map__popup-flag/);
    });

    it('flags a location to check', () => {
        const html = view.householdPopupHtml({ ...base, locationFlag: 'check' });
        assert.match(html, /Location to check: outside the indicative La Medalla boundary\./);
    });

    it('escapes every value (no HTML injection)', () => {
        const html = view.householdPopupHtml({ ...base, houseHead: '<img src=x onerror=alert(1)>', householdNo: '"><script>x</script>', householdKey: '" onmouseover="x' });
        // No real tags or injected attributes; escaped text such as "&lt;img ... onerror=" is harmless display text.
        assert.doesNotMatch(html, /<img|<script|" onmouseover/);
        assert.match(html, /href="\/household-profiling\/%22%20onmouseover%3D%22x"/);
        assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
    });

    it('simulated (demo) points: marked as never saved and no View link', () => {
        const html = view.householdPopupHtml({ ...base, source: 'simulated' });
        assert.match(html, /Simulated \(demo\) location: never saved\./);
        assert.doesNotMatch(html, /View household/);
    });

    it('skips empty fields', () => {
        const html = view.householdPopupHtml({ householdNo: '903', houseHead: '—', members: null });
        assert.doesNotMatch(html, /Household head|Members|Type/);
    });
});

describe('page wiring (source checks)', () => {
    const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
    const css = readFileSync(path.resolve('resources/css/pages/spot-mapping.css'), 'utf8');
    const blade = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');

    it('flag is computed for saved markers only and shown as a badge', () => {
        assert.match(src, /if \(!temporary && !data\.isTemp && data\.source !== 'simulated'\) \{\s*data\.locationFlag = savedLocationFlag\(boundaryFeature, data\.lat, data\.lng\);/);
        assert.match(src, /lml-spot-map__marker-flag/);
        assert.match(blade, /Location to check \(outside the indicative boundary\)/);
    });

    it('popup opens for saved markers only, and closes with the panel', () => {
        assert.match(src, /openPanel\(marker\.__lmlData, marker\.getElement\(\)\);\s*if \(!temporary\) \{\s*showHouseholdPopup\(marker\);/);
        assert.match(src, /function closePanel\(\{ removeTemp = false \} = \{\}\) \{\s*map\.closePopup\(\);/);
    });

    it('marker size follows the zoom band without re-rendering markers', () => {
        assert.match(src, /root\.setAttribute\('data-zoom-band', zoomBand\(map\.getZoom\(\)\)\)/);
        assert.match(css, /\[data-zoom-band='low'\] \.lml-spot-map__marker \{\s*scale: 0\.6;/);
        assert.match(css, /\[data-zoom-band='high'\] \.lml-spot-map__marker \{\s*scale: 1\.25;/);
    });

    it('nothing here writes coordinates or calls the server', () => {
        const mod = readFileSync(path.resolve('resources/js/pages/spot-mapping-marker-view.js'), 'utf8');
        assert.doesNotMatch(mod, /fetch\(|XMLHttpRequest|indexedDB|localStorage|\.lat\s*=|\.lng\s*=/);
    });
});

describe('GPS accuracy indicators (Phase 3B stage 3)', () => {
    const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
    const blade = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');

    it('accuracy circle colour follows the level: green good, amber weak, red rough/unknown', () => {
        assert.match(src, /good: \{ stroke: '#15803d', fill: '#22c55e' \}/);
        assert.match(src, /weak: \{ stroke: '#b45309', fill: '#f59e0b' \}/);
        assert.match(src, /rough: \{ stroke: '#b91c1c', fill: '#ef4444' \}/);
        assert.match(src, /lml-spot-map__accuracy-circle--\$\{accuracyLevel\(accuracyMeters\)\}/);
    });

    it('"Try GPS again" shows only for unsaved GPS pins that are not good', () => {
        assert.match(blade, /data-spot-map-regps hidden/);
        assert.match(src, /regpsBtn\.hidden = !\(data\.isTemp && data\.source === 'gps' && accuracyLevel\(data\.accuracy\) !== 'good'\);/);
    });

    it('"Try GPS again" respects offline and locating guards and reruns the same GPS step', () => {
        const handler = src.slice(src.indexOf("regpsBtn?.addEventListener('click'"), src.indexOf("plotExistingBtn?.addEventListener('click'"));
        assert.ok(handler.indexOf('if (locating)') > -1);
        assert.ok(handler.indexOf('isClientOffline()') < handler.indexOf('startGpsPlot()'));
        assert.doesNotMatch(handler, /clearPendingSelection|fetch\(|lat\s*=|lng\s*=/);
    });
});
