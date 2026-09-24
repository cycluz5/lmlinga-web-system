/**
 * Spot Mapping Plot New Household: skip GPS while offline so Leaflet
 * stays on the cached La Medalla viewport.
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';

const spotSourcePath = path.resolve('resources/js/pages/spot-mapping.js');
const swRuntimePath = path.resolve('resources/js/offline/offline-sw-runtime.js');
const swPolicyPath = path.resolve('resources/js/offline/offline-sw-policy.js');
const spotSource = readFileSync(spotSourcePath, 'utf8');
const swRuntime = readFileSync(swRuntimePath, 'utf8');
const swPolicy = readFileSync(swPolicyPath, 'utf8');

function sliceInclusive(source, startNeedle, endNeedle) {
    const start = source.indexOf(startNeedle);
    const end = source.indexOf(endNeedle, start);
    assert.ok(start >= 0, `missing ${startNeedle}`);
    assert.ok(end > start, `missing terminator for ${startNeedle}`);
    return source.slice(start, end);
}

function extractPlotPlanSource(source) {
    const labels = sliceInclusive(source, 'const ZONE_LABELS = {', 'const GEO_OPTIONS');
    const normalizeZone = sliceInclusive(source, 'function normalizeZone(zone)', '\nfunction createTempHouseholdId');
    const helpers = sliceInclusive(source, 'export function queuedSpotHouseholdNo', '\nfunction initSpotMapping');
    return `${labels}\n${normalizeZone}\n${helpers}`;
}

const helperSource = extractPlotPlanSource(spotSource);
const helperModule = await import(
    `data:text/javascript;charset=utf-8,${encodeURIComponent(helperSource)}`
);

const { gpsPlotPlan, OFFLINE_PLOT_PROMPT } = helperModule;

const startGpsPlot = sliceInclusive(spotSource, 'function startGpsPlot()', '\n    function applyStats');
const gpsSuccess = sliceInclusive(spotSource, 'function handleGpsSuccess(position, requestId)', '\n    function handleGpsError');
const gpsError = sliceInclusive(spotSource, 'function handleGpsError(error, requestId)', '\n    function startGpsPlot');
const outsideScope = sliceInclusive(spotSource, 'function enterOutsideScopeFallback(requestId)', '\n    function handleGpsSuccess');
const mapClick = sliceInclusive(spotSource, "map.on('click', (event) => {", '\n    renderPendingPicker();');
const placeTemp = sliceInclusive(spotSource, 'function placeTemporaryMarker(lat, lng', '\n    function enterManualFallback');

describe('spot mapping offline plot plan', () => {
    it('skips GPS and preserves viewport while offline', () => {
        const plan = gpsPlotPlan({ offline: true });
        assert.equal(plan.requestGps, false);
        assert.equal(plan.flyTo, false);
        assert.equal(plan.placing, true);
        assert.equal(plan.locating, false);
        assert.equal(plan.preserveViewport, true);
        assert.equal(plan.zoom, null);
        assert.equal(plan.overlayMode, 'info');
        assert.equal(
            plan.overlay,
            "You're offline. Click on the map to plot the household location.",
        );
        assert.equal(plan.overlay, OFFLINE_PLOT_PROMPT);
        assert.equal(plan.branch, 'offline');
    });

    it('still requests geolocation while online', () => {
        const plan = gpsPlotPlan({ offline: false });
        assert.equal(plan.requestGps, true);
        assert.equal(plan.flyTo, false);
        assert.equal(plan.locating, true);
        assert.equal(plan.placing, false);
        assert.equal(plan.preserveViewport, true);
        assert.equal(plan.overlay, 'Getting your current location…');
        assert.equal(plan.branch, 'gps-request');
    });

    it('keeps in-scope GPS flyTo at zoom 17 while online', () => {
        const plan = gpsPlotPlan({ gpsReceived: true, inScope: true });
        assert.equal(plan.requestGps, true);
        assert.equal(plan.flyTo, true);
        assert.equal(plan.zoom, 17);
        assert.equal(plan.placing, false);
        assert.equal(plan.preserveViewport, false);
        assert.equal(plan.branch, 'gps-in-scope');
    });

    it('does not flyTo when GPS is outside La Medalla', () => {
        const plan = gpsPlotPlan({ gpsReceived: true, inScope: false });
        assert.equal(plan.flyTo, false);
        assert.equal(plan.placing, true);
        assert.equal(plan.preserveViewport, true);
        assert.equal(plan.zoom, null);
        assert.equal(plan.branch, 'gps-outside-scope');
    });

    it('keeps manual fallback on GPS error without moving the viewport', () => {
        const plan = gpsPlotPlan({ error: true });
        assert.equal(plan.requestGps, true);
        assert.equal(plan.flyTo, false);
        assert.equal(plan.placing, true);
        assert.equal(plan.preserveViewport, true);
        assert.equal(plan.overlayMode, 'error');
        assert.equal(plan.branch, 'gps-error');
    });
});

describe('spot mapping offline plot source contract', () => {
    it('skips getCurrentPosition before GPS when offline', () => {
        assert.match(startGpsPlot, /gpsPlotPlan\(\{ offline: isClientOffline\(\) \}\)/);
        const offlineAt = startGpsPlot.indexOf('isClientOffline');
        const gpsAt = startGpsPlot.indexOf('getCurrentPosition');
        const flyAt = startGpsPlot.indexOf('flyTo');
        assert.ok(offlineAt >= 0);
        assert.ok(gpsAt > offlineAt);
        assert.equal(flyAt, -1);
        assert.match(startGpsPlot, /if \(!plan\.requestGps\) \{/);
        assert.match(startGpsPlot, /enterManualFallback\(plan\.overlay, plan\.overlayMode\)/);
        assert.doesNotMatch(startGpsPlot, /setView/);
        assert.doesNotMatch(startGpsPlot, /fitBounds/);
        assert.doesNotMatch(startGpsPlot, /panTo/);
        assert.doesNotMatch(startGpsPlot, /markerById\.clear/);
        assert.doesNotMatch(startGpsPlot, /setLatLng/);
    });

    it('keeps online GPS flyTo and outside-scope / error fallbacks', () => {
        assert.match(gpsSuccess, /gpsPlotPlan\(\{\s*gpsReceived: true,\s*inScope: isWithinDemoScope\(lat, lng\),\s*\}\)/);
        assert.match(gpsSuccess, /map\.flyTo\(\[lat, lng\], plan\.zoom \?\? GPS_ZOOM/);
        assert.match(gpsSuccess, /enterOutsideScopeFallback\(requestId\)/);
        assert.match(outsideScope, /OUTSIDE_SCOPE_MESSAGE/);
        assert.doesNotMatch(outsideScope, /flyTo/);
        assert.match(gpsError, /enterManualFallback\(geolocationErrorMessage\(error\)\)/);
        assert.doesNotMatch(gpsError, /flyTo/);
        assert.match(spotSource, /const GPS_ZOOM = 17/);
        assert.match(spotSource, /navigator\.geolocation\.getCurrentPosition/);
    });

    it('drops a temporary marker on map click without moving the viewport or saving', () => {
        assert.match(mapClick, /placeTemporaryMarker\(lat, lng, \{ source: 'manual' \}\)/);
        assert.match(placeTemp, /lat,/);
        assert.match(placeTemp, /lng,/);
        assert.doesNotMatch(mapClick, /flyTo/);
        assert.doesNotMatch(mapClick, /setView/);
        assert.doesNotMatch(mapClick, /fitBounds/);
        assert.doesNotMatch(mapClick, /panTo/);
        assert.doesNotMatch(mapClick, /getCurrentPosition/);
        assert.doesNotMatch(mapClick, /plot-new/);
        assert.doesNotMatch(mapClick, /queuePlotNewHousehold/);
        assert.match(mapClick, /openPanel\(tempData/);
        assert.doesNotMatch(placeTemp, /markerById/);
    });

    it('plot confirm awaits hp_members persist on the same path as queuePlotNewHousehold', () => {
        const finish = sliceInclusive(
            spotSource,
            'const finishLocalQueue = async () => {',
            'if (isClientOffline())',
        );
        assert.match(finish, /await queuePlotNewHousehold\(plotPayload/);
        assert.match(finish, /await persistPlotHouseholdReadModel\(queued\.record\.actor_id, queued\.record\.payload\)/);
        assert.match(spotSource, /import \{ persistPlotHouseholdReadModel \} from '\.\.\/offline\/offline-hp-store'/);
        assert.doesNotMatch(finish, /RESIDENT_CREATE/);
    });

    it('does not change service-worker OSM tile policy', () => {
        assert.match(swRuntime, /if \(!isSameOrigin\(url, origin\)\) \{\s*return fetchImpl\(request\);/);
        assert.doesNotMatch(swRuntime, /tile\.openstreetmap\.org/);
        assert.doesNotMatch(swPolicy, /tile\.openstreetmap\.org/);
        assert.doesNotMatch(startGpsPlot, /caches\.open/);
        assert.doesNotMatch(startGpsPlot, /tile\.openstreetmap/);
    });
});
