/**
 * FR-05 — Spot Mapping visual overlap grouping (presentation layer only).
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';
import { pathToFileURL } from 'node:url';

const overlapHref = pathToFileURL(
    path.resolve('resources/js/pages/spot-mapping-overlap.js'),
).href;

const {
    MARKER_OVERLAP_PX,
    applyOverlapLayerPlan,
    buildOverlapGroups,
    buildSelectorEntries,
    chooseSelectorHousehold,
    coordinateKey,
    createGroupSelectorState,
    groupAriaLabel,
    planOverlapVisuals,
} = await import(overlapHref);

const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
const exportSource = readFileSync(path.resolve('resources/js/pages/spot-mapping-export.js'), 'utf8');

function hh(overrides) {
    return {
        id: overrides.id,
        householdNo: overrides.householdNo,
        houseHead: overrides.houseHead ?? 'Head Name',
        zoneLabel: overrides.zoneLabel ?? 'Zone 1',
        lat: overrides.lat,
        lng: overrides.lng,
    };
}

function webMercatorPoint(lat, lng, zoom) {
    const scale = 256 * (2 ** zoom);
    const x = ((lng + 180) / 360) * scale;
    const latRad = (lat * Math.PI) / 180;
    const y = (
        (1 - Math.log(Math.tan(latRad) + (1 / Math.cos(latRad))) / Math.PI) / 2
    ) * scale;
    return { x, y };
}

function planFor(items, zoom) {
    const snapshot = items.map((item) => ({ ...item }));
    const groups = buildOverlapGroups(snapshot, {
        getLayerPoint: (lat, lng) => webMercatorPoint(lat, lng, zoom),
        threshold: MARKER_OVERLAP_PX,
    });
    return { snapshot, groups, plan: planOverlapVisuals(groups) };
}

describe('FR-05 spot mapping overlap grouping', () => {
    it('groups two households with identical coordinates and keeps both in state', () => {
        const a = hh({
            id: 'hh-A',
            householdNo: 'HH-101',
            houseHead: 'Ana Cruz',
            lat: 13.3811,
            lng: 123.4306,
        });
        const b = hh({
            id: 'hh-B',
            householdNo: 'HH-102',
            houseHead: 'Ben Cruz',
            lat: 13.3811,
            lng: 123.4306,
        });
        const { snapshot, groups, plan } = planFor([a, b], 16);

        assert.equal(groups.length, 1);
        assert.equal(groups[0].count, 2);
        assert.deepEqual(plan.stacked[0].memberIds.sort(), ['hh-A', 'hh-B']);
        assert.equal(plan.individuals.length, 0);
        assert.equal(snapshot[0].lat, 13.3811);
        assert.equal(snapshot[0].lng, 123.4306);
        assert.equal(snapshot[1].lat, a.lat);
        assert.equal(snapshot[1].lng, a.lng);
        assert.equal(coordinateKey(a.lat, a.lng), coordinateKey(b.lat, b.lng));
    });

    it('opens a selector that contains both households', () => {
        const members = [
            hh({ id: 'hh-A', householdNo: 'HH-101', houseHead: 'Ana Cruz', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', houseHead: 'Ben Cruz', lat: 13.3811, lng: 123.4306 }),
        ];
        const state = createGroupSelectorState(members);
        assert.equal(state.heading, 'Multiple households at this location');
        assert.deepEqual(state.entries.map((entry) => entry.householdNo).sort(), ['HH-101', 'HH-102']);
        assert.ok(state.entries.every((entry) => entry.householdNo));
    });

    it('selecting the first household opens existing details for that household', () => {
        const members = [
            hh({ id: 'hh-A', householdNo: 'HH-101', houseHead: 'Ana Cruz', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', houseHead: 'Ben Cruz', lat: 13.3811, lng: 123.4306 }),
        ];
        const opened = [];
        const entries = buildSelectorEntries(members);
        const chosen = chooseSelectorHousehold(entries, 'HH-101');
        opened.push(chosen);
        assert.equal(opened[0].id, 'hh-A');
        assert.equal(opened[0].householdNo, 'HH-101');
    });

    it('selecting the second household opens existing details for that household', () => {
        const members = [
            hh({ id: 'hh-A', householdNo: 'HH-101', houseHead: 'Ana Cruz', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', houseHead: 'Ben Cruz', lat: 13.3811, lng: 123.4306 }),
        ];
        const chosen = chooseSelectorHousehold(buildSelectorEntries(members), 'HH-102');
        assert.equal(chosen.id, 'hh-B');
        assert.equal(chosen.householdNo, 'HH-102');
    });

    it('does not mutate stored marker lat/lng while grouping', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.4306 }),
        ];
        const frozen = items.map((item) => ({ lat: item.lat, lng: item.lng }));
        buildOverlapGroups(items, {
            getLayerPoint: (lat, lng) => webMercatorPoint(lat, lng, 16),
        });
        assert.deepEqual(items.map((item) => ({ lat: item.lat, lng: item.lng })), frozen);
    });

    it('renders distinct non-overlapping households as separate markers', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3900, lng: 123.4400 }),
        ];
        const { plan } = planFor(items, 16);
        assert.equal(plan.stacked.length, 0);
        assert.deepEqual(plan.individuals.sort(), ['hh-A', 'hh-B']);
    });

    it('groups near markers when icon centers overlap at a low zoom', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.431029 }),
        ];
        const { plan } = planFor(items, 16);
        assert.equal(plan.stacked.length, 1);
        assert.equal(plan.stacked[0].count, 2);
    });

    it('separates near-but-distinct coordinates after zooming in', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.431029 }),
        ];
        const low = planFor(items, 16);
        const high = planFor(items, 19);
        assert.equal(low.plan.stacked.length, 1);
        assert.equal(high.plan.stacked.length, 0);
        assert.deepEqual(high.plan.individuals.sort(), ['hh-A', 'hh-B']);
    });

    it('keeps exact-coordinate households grouped after zoom-in', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.4306 }),
        ];
        const high = planFor(items, 19);
        assert.equal(high.plan.stacked.length, 1);
        assert.equal(high.plan.stacked[0].count, 2);
    });

    it('upsert regrouping does not duplicate Leaflet layers', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.4306 }),
        ];
        const first = applyOverlapLayerPlan(planFor(items, 16).plan);
        const second = applyOverlapLayerPlan(planFor(items, 16).plan, first);
        assert.equal(first.groupCount, 1);
        assert.equal(second.groupCount, 1);
        assert.equal(second.duplicateVisuals.length, 0);
        assert.equal(second.groupKeyCollision, false);
        assert.equal(second.householdCount, 0);
    });

    it('adds a new marker into an existing overlap group and updates the count', () => {
        const pair = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.4306 }),
        ];
        const before = applyOverlapLayerPlan(planFor(pair, 16).plan);
        const withNew = [
            ...pair,
            hh({ id: 'hh-C', householdNo: 'HH-103', lat: 13.3811, lng: 123.4306 }),
        ];
        const after = applyOverlapLayerPlan(planFor(withNew, 16).plan, before);
        assert.equal(before.groupCount, 1);
        assert.equal(after.groupCount, 1);
        assert.equal(planFor(withNew, 16).plan.stacked[0].count, 3);
        assert.equal(after.duplicateVisuals.length, 0);
    });

    it('moves a marker out of a group through an upsert-style coordinate change', () => {
        const items = [
            hh({ id: 'hh-A', householdNo: 'HH-101', lat: 13.3811, lng: 123.4306 }),
            hh({ id: 'hh-B', householdNo: 'HH-102', lat: 13.3811, lng: 123.4306 }),
        ];
        const before = applyOverlapLayerPlan(planFor(items, 16).plan);
        items[1] = { ...items[1], lat: 13.3900, lng: 123.4400 };
        const afterPlan = planFor(items, 16).plan;
        const after = applyOverlapLayerPlan(afterPlan, before);
        assert.equal(before.groupCount, 1);
        assert.equal(after.groupCount, 0);
        assert.deepEqual(afterPlan.individuals.sort(), ['hh-A', 'hh-B']);
        assert.equal(after.duplicateVisuals.length, 0);
    });

    it('group selector uses existing household identity / householdNo', () => {
        const entries = buildSelectorEntries([
            hh({ id: 'hh-HH-221', householdNo: 'HH-221', lat: 13.3811, lng: 123.4306 }),
        ]);
        assert.equal(entries[0].id, 'hh-HH-221');
        assert.equal(entries[0].householdNo, 'HH-221');
        assert.match(spotSource, /openPanel\(member/);
        assert.match(spotSource, /data-household-no/);
    });

    it('never writes display coordinates back onto household records', () => {
        assert.equal(spotSource.includes('households.latitude'), false);
        assert.match(spotSource, /syncVisualGroups/);
        assert.match(spotSource, /map\.on\('zoomend'/);
        assert.doesNotMatch(spotSource, /marker-cluster/);
        assert.doesNotMatch(spotSource, /unspiderfy/);
        assert.match(exportSource, /onRestoreComplete/);
        assert.equal(groupAriaLabel(3), '3 households at this location');
    });
});
