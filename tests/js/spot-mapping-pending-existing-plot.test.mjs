/**
 * Spot Mapping — existing pending household plot wiring (source contract).
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';

const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
const bladeSource = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');
const mapBase = readFileSync(path.resolve('resources/js/maps/la-medalla-base.js'), 'utf8');

describe('spot mapping existing pending household plot', () => {
    it('exposes pending picker markup and plot-existing control', () => {
        assert.match(bladeSource, /data-spot-map-pending/);
        assert.match(bladeSource, /data-spot-map-pending-list/);
        assert.match(bladeSource, /data-spot-map-plot-existing/);
        assert.match(bladeSource, /Pending Households/);
        assert.match(bladeSource, /Plot Selected Household/);
        assert.match(bladeSource, /data-plot-url="\{\{ route\('spot-mapping\.plot'\) \}\}"/);
        assert.match(bladeSource, /data-plot-new-url="\{\{ route\('spot-mapping\.plot-new'\) \}\}"/);
        assert.match(bladeSource, /data-pending-candidates=/);
    });

    it('parses pending candidates and binds selected household_no on temp markers', () => {
        assert.match(spotSource, /function parsePendingCandidates\(root\)/);
        assert.match(spotSource, /pendingCandidates = parsePendingCandidates\(root\)/);
        assert.match(spotSource, /selectedPendingHousehold/);
        assert.match(spotSource, /plotMode: 'existing'/);
        assert.match(spotSource, /householdNo: pending\.householdNo/);
        assert.match(spotSource, /householdNo: 'HH-NEW'/);
        assert.match(spotSource, /plotMode: 'new'/);
        assert.match(spotSource, /const pending = selectedPendingHousehold;/);
    });

    it('confirm for existing pending posts spot-mapping.plot and not plot-new', () => {
        const existingBranch = spotSource.slice(
            spotSource.indexOf("const isExistingPendingPlot = confirmedMarker.__lmlData?.plotMode === 'existing';"),
            spotSource.indexOf('const selectedZone = validation.values.zone;'),
        );

        assert.match(existingBranch, /data-plot-url/);
        assert.match(existingBranch, /\/spot-mapping\/plot/);
        assert.match(existingBranch, /household_no: validation\.values\.householdNo/);
        assert.match(existingBranch, /lat: confirmedMarker\.__lmlData\?\.lat/);
        assert.match(existingBranch, /lng: confirmedMarker\.__lmlData\?\.lng/);
        assert.match(existingBranch, /consent: true/);
        assert.doesNotMatch(existingBranch, /confirm_replot/);
        assert.doesNotMatch(existingBranch, /plot-new/);
        assert.doesNotMatch(existingBranch, /queuePlotNewHousehold/);
        assert.match(existingBranch, /removePendingCandidate/);
        assert.match(existingBranch, /upsertMappedMarker/);
        assert.match(existingBranch, /applyStats/);
    });

    it('keeps Plot New Household on plot-new with HH-NEW path', () => {
        assert.match(spotSource, /data-spot-map-plot/);
        assert.match(spotSource, /clearPendingSelection\(\)/);
        assert.match(spotSource, /data-plot-new-url/);
        assert.match(spotSource, /queuePlotNewHousehold\(plotPayload/);
        assert.match(bladeSource, /Plot New Household/);
    });

    it('still uses GPS then manual map-click for placing', () => {
        assert.match(spotSource, /function startGpsPlot\(\)/);
        assert.match(spotSource, /navigator\.geolocation\.getCurrentPosition/);
        assert.match(spotSource, /placeTemporaryMarker\(lat, lng, \{ source: 'manual' \}\)/);
        assert.match(spotSource, /data-spot-map-plot-existing/);
    });

    it('preserves Leaflet maxZoom 22 and maxNativeZoom 19', () => {
        assert.match(mapBase, /LA_MEDALLA_MAX_ZOOM = 22/);
        assert.match(mapBase, /LA_MEDALLA_MAX_NATIVE_ZOOM = 19/);
        assert.match(mapBase, /maxNativeZoom: LA_MEDALLA_MAX_NATIVE_ZOOM/);
    });
});
