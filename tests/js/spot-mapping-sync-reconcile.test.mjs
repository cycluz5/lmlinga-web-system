/**
 * Spot Mapping local reconcile after lmlinga:sync-success.
 * Helpers live in resources/js/pages/spot-mapping.js (no Leaflet import).
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';

const spotSourcePath = path.resolve('resources/js/pages/spot-mapping.js');
const spotSource = readFileSync(spotSourcePath, 'utf8');

function sliceInclusive(source, startNeedle, endNeedle) {
    const start = source.indexOf(startNeedle);
    const end = source.indexOf(endNeedle, start);
    assert.ok(start >= 0, `missing ${startNeedle}`);
    assert.ok(end > start, `missing terminator for ${startNeedle}`);
    return source.slice(start, end);
}

function extractReconcileSource(source) {
    const labels = sliceInclusive(source, 'const ZONE_LABELS = {', 'const GEO_OPTIONS');
    const normalizeZone = sliceInclusive(source, 'function normalizeZone(zone)', '\nfunction createTempHouseholdId');
    const helpers = sliceInclusive(source, 'export function queuedSpotHouseholdNo', '\nfunction initSpotMapping');
    return `${labels}\n${normalizeZone}\n${helpers}`;
}

const helperSource = extractReconcileSource(spotSource);
const helperModule = await import(
    `data:text/javascript;charset=utf-8,${encodeURIComponent(helperSource)}`
);

const {
    buildPromotedSpotMarkerFromQueued,
    queuedSpotHouseholdNo,
    plotSyncHouseholdNo,
    environmentalHealthUrlForHousehold,
    reconcileQueuedSpotPlotAfterSync,
    shouldReconcileQueuedSpotPlot,
    statsAfterQueuedSpotPlotPromotion,
} = helperModule;

function queuedContext(overrides = {}) {
    const markerById = overrides.markerById || new Map();
    const calls = {
        upsert: [],
        closePanel: [],
        applyStats: [],
        resetQueuedUi: 0,
        fetchUrls: [],
        enqueue: 0,
        replay: 0,
    };

    const context = {
        tempMarker: {
            __lmlData: {
                id: 'demo-temp-1',
                householdNo: 'HH-NEW',
                isTemp: true,
                headFirstName: 'Viktor',
                headMiddleName: '',
                headLastName: 'Morales',
                zone: 2,
                householdType: 'HHTS',
                lat: 13.3811,
                lng: 123.4306,
            },
        },
        isQueued: true,
        householdNo: '999',
        formValues: {
            headFirstName: 'Viktor',
            headMiddleName: '',
            headLastName: 'Morales',
            zone: '2',
            householdType: 'HHTS',
        },
        markerById,
        upsertMappedMarker(marker) {
            calls.upsert.push(marker);
            const id = marker.id;
            const existing = markerById.get(id);
            if (existing) {
                existing.__lmlData = marker;
                return existing;
            }
            const created = { id, __lmlData: marker };
            markerById.set(id, created);
            return created;
        },
        closePanel(options) {
            calls.closePanel.push(options);
            context.tempMarker = null;
        },
        applyStats(stats) {
            calls.applyStats.push(stats);
        },
        readStats() {
            return { total: 10, plotted: 8, pending: 2 };
        },
        resetQueuedUi() {
            calls.resetQueuedUi += 1;
            context.isQueued = false;
        },
        ...overrides,
    };

    return { context, calls, markerById };
}

describe('spot mapping sync-success source contract', () => {
    it('listens for lmlinga:sync-success only inside Spot Mapping init', () => {
        assert.match(spotSource, /querySelector\('\[data-lml-spot-map\]'\)/);
        assert.match(spotSource, /window\.addEventListener\('lmlinga:sync-success'/);
        const initAt = spotSource.indexOf('function initSpotMapping');
        const listenerAt = spotSource.indexOf("window.addEventListener('lmlinga:sync-success'");
        const earlyReturnAt = spotSource.indexOf("querySelector('[data-lml-spot-map]')");
        assert.ok(initAt >= 0 && listenerAt > initAt);
        assert.ok(earlyReturnAt > initAt && earlyReturnAt < listenerAt);
        assert.match(spotSource, /reconcileQueuedSpotPlotAfterSync/);
        assert.doesNotMatch(helperSource, /enqueueOperation/);
        assert.doesNotMatch(helperSource, /createReplayCoordinator/);
        assert.doesNotMatch(helperSource, /\/spot-mapping\/plot-new/);
        assert.doesNotMatch(helperSource, /indexedDB/);
        assert.doesNotMatch(helperSource, /location\.reload/);
        assert.doesNotMatch(helperSource, /fetch\(/);
        const handler = sliceInclusive(spotSource, 'function handleOfflineSyncSuccess', '\n    window.addEventListener');
        assert.doesNotMatch(handler, /enqueueOperation/);
        assert.doesNotMatch(handler, /createReplayCoordinator/);
        assert.doesNotMatch(handler, /plot-new/);
        assert.doesNotMatch(handler, /fetch\(/);
        assert.doesNotMatch(handler, /location\.reload/);
    });
});

describe('queued Spot Mapping reconcile helpers', () => {
    it('promotes a queued temp plot on sync-success without duplicating', () => {
        const { context, calls, markerById } = queuedContext();
        const result = reconcileQueuedSpotPlotAfterSync(context, { pending: 0 });

        assert.equal(result.promoted, true);
        assert.equal(result.markerId, 'hh-999');
        assert.equal(calls.upsert.length, 1);
        assert.deepEqual(calls.closePanel, [{ removeTemp: true }]);
        assert.equal(calls.resetQueuedUi, 1);
        assert.equal(context.tempMarker, null);
        assert.equal(markerById.size, 1);
        assert.equal(markerById.has('hh-999'), true);
        assert.equal(markerById.get('hh-999').__lmlData.isTemp, false);
        assert.equal(markerById.get('hh-999').__lmlData.householdNo, '999');
        assert.equal(markerById.get('hh-999').__lmlData.members, 1);
        assert.equal(markerById.get('hh-999').__lmlData.status, 'plotted');
        assert.equal(markerById.get('hh-999').__lmlData.houseHead, 'Viktor Morales');
        assert.equal(calls.enqueue, 0);
        assert.equal(calls.replay, 0);
        assert.deepEqual(calls.fetchUrls, []);
    });

    it('increments Total HH and Plotted once and leaves Pending unchanged', () => {
        const { context, calls } = queuedContext();
        reconcileQueuedSpotPlotAfterSync(context, { pending: 0 });

        assert.equal(calls.applyStats.length, 1);
        assert.deepEqual(calls.applyStats[0], {
            total: 11,
            plotted: 9,
            pending: 2,
        });
        assert.deepEqual(statsAfterQueuedSpotPlotPromotion({ total: 10, plotted: 8, pending: 2 }), {
            total: 11,
            plotted: 9,
            pending: 2,
        });

        reconcileQueuedSpotPlotAfterSync(context, { pending: 0 });
        assert.equal(calls.applyStats.length, 1);
        assert.equal(calls.upsert.length, 1);
    });

    it('does nothing when no queued temp plot exists', () => {
        const { context, calls } = queuedContext({
            tempMarker: null,
            isQueued: false,
        });
        const result = reconcileQueuedSpotPlotAfterSync(context, { pending: 0 });

        assert.equal(result.promoted, false);
        assert.equal(calls.upsert.length, 0);
        assert.equal(calls.closePanel.length, 0);
        assert.equal(calls.applyStats.length, 0);
        assert.equal(calls.resetQueuedUi, 0);
        assert.equal(shouldReconcileQueuedSpotPlot({
            hasTempMarker: false,
            isQueued: false,
            householdNo: '999',
            pending: 0,
        }), false);
        assert.equal(queuedSpotHouseholdNo('HH-NEW'), '');
        assert.equal(reconcileQueuedSpotPlotAfterSync(queuedContext().context, {}).promoted, false);
        assert.equal(reconcileQueuedSpotPlotAfterSync(queuedContext().context, { pending: 1 }).promoted, false);
        assert.equal(
            reconcileQueuedSpotPlotAfterSync(queuedContext({ householdNo: '' }).context, { pending: 0 }).promoted,
            false,
        );
        assert.equal(
            reconcileQueuedSpotPlotAfterSync(queuedContext({ tempMarker: null, isQueued: true }).context, { pending: 0 }).promoted,
            false,
        );
        assert.equal(
            reconcileQueuedSpotPlotAfterSync(queuedContext({ isQueued: false }).context, { pending: 0 }).promoted,
            false,
        );
    });

    it('updates an existing hh-999 marker instead of creating a duplicate', () => {
        const markerById = new Map();
        const existing = {
            id: 'hh-999',
            __lmlData: { id: 'hh-999', householdNo: '999', isTemp: false, lat: 13.38, lng: 123.43 },
        };
        markerById.set('hh-999', existing);
        const { context, calls } = queuedContext({ markerById });
        const result = reconcileQueuedSpotPlotAfterSync(context, { pending: 0 });

        assert.equal(result.promoted, true);
        assert.equal(result.alreadyHad, true);
        assert.equal(markerById.size, 1);
        assert.equal(markerById.get('hh-999'), existing);
        assert.equal(existing.__lmlData.houseHead, 'Viktor Morales');
        assert.equal(existing.__lmlData.lat, 13.3811);
        assert.equal(calls.upsert.length, 1);
        assert.equal(calls.applyStats.length, 0);
        assert.deepEqual(calls.closePanel, [{ removeTemp: true }]);
    });

    it('does not enqueue, replay, or POST plot-new while promoting', () => {
        const { context, calls } = queuedContext();
        reconcileQueuedSpotPlotAfterSync(context, { pending: 0 });
        const promoted = buildPromotedSpotMarkerFromQueued({
            householdNo: '999',
            headFirstName: 'Viktor',
            headLastName: 'Morales',
            zone: 2,
            lat: 13.3811,
            lng: 123.4306,
            householdType: 'HHTS',
        });

        assert.equal(promoted.id, 'hh-999');
        assert.equal(promoted.isTemp, false);
        assert.equal(calls.enqueue, 0);
        assert.equal(calls.replay, 0);
        assert.equal(helperSource.includes('enqueueOperation'), false);
        assert.equal(helperSource.includes('/spot-mapping/plot-new'), false);
        assert.equal(helperSource.includes('createReplayCoordinator'), false);
        assert.equal(spotSource.includes("window.addEventListener('lmlinga:sync-success'"), true);
        const handler = sliceInclusive(spotSource, 'function handleOfflineSyncSuccess', '\n    window.addEventListener');
        assert.equal(handler.includes('enqueueOperation'), false);
        assert.equal(handler.includes('createReplayCoordinator'), false);
        assert.equal(handler.includes('plot-new'), false);
        assert.equal(handler.includes('fetch('), false);
        assert.match(handler, /environmentalHealthUrlForHousehold/);
        assert.match(handler, /plotSyncHouseholdNo/);
    });

    it('reads the synced household number after a queued plot replay', () => {
        assert.equal(queuedSpotHouseholdNo('HH-210'), 'HH-210');
        assert.equal(queuedSpotHouseholdNo('210'), '210');
        assert.equal(queuedSpotHouseholdNo('21'), '');
        assert.equal(
            plotSyncHouseholdNo({
                pending: 0,
                synced: [{
                    operation_type: 'PLOT_HOUSEHOLD_WITH_HEAD',
                    identities: { household_no: 'HH-210' },
                }],
            }),
            'HH-210',
        );
        assert.equal(
            environmentalHealthUrlForHousehold('HH-210'),
            '/environmental-health/household-water-supply?household=HH-210',
        );
    });
});
