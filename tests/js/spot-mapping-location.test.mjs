/**
 * SR-10 Demo Location Mode (Phase A): real GPS by default, simulated only with the server's demo payload,
 * never offline, labelled, and never saved. Fictional test coordinates only.
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const modUrl = pathToFileURL(path.resolve('resources/js/pages/spot-mapping-location.js')).href;
const {
    DEMO_SAVE_BLOCKED_MESSAGE,
    DEMO_INDICATOR_LABEL,
    DEMO_INDICATOR_LABEL_UNVERIFIED,
    demoLocatedMessage,
    drawDemoLocationIndicator,
    locationSourceFor,
    pageHasDemoLocation,
    parseDemoLocation,
    simulatedPosition,
    syncDemoLocationIndicator,
} = await import(modUrl);
import { readFileSync } from 'node:fs';

const policyUrl = pathToFileURL(path.resolve('resources/js/offline/offline-sw-policy.js')).href;
const { isNeverCachePath } = await import(policyUrl);

function root(attr) {
    return { getAttribute: (name) => (name === 'data-demo-location' ? attr : null) };
}

const future = Math.floor(Date.now() / 1000) + 3600;
const demoJson = JSON.stringify({ key: 'test-inside', label: 'Test point (fictional)', lat: 11, lng: 100.5, expires_at: future });

describe('Demo Location Mode client', () => {
    it('uses real GPS when the page has no demo payload', () => {
        assert.equal(parseDemoLocation(root(null)), null);
        assert.equal(locationSourceFor({ offline: false, demo: null }), 'gps');
        assert.equal(pageHasDemoLocation(root(null)), false);
    });

    it('uses the simulated point only with a valid server payload', () => {
        const demo = parseDemoLocation(root(demoJson));
        assert.deepEqual({ key: demo.key, lat: demo.lat, lng: demo.lng }, { key: 'test-inside', lat: 11, lng: 100.5 });
        assert.equal(locationSourceFor({ offline: false, demo }), 'simulated');
        const pos = simulatedPosition(demo);
        assert.equal(pos.simulated, true);
        assert.deepEqual([pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy], [11, 100.5, null]);
    });

    it('never simulates while offline', () => {
        assert.equal(locationSourceFor({ offline: true, demo: parseDemoLocation(root(demoJson)) }), 'offline');
    });

    it('rejects malformed or expired payloads (falls back to real GPS) but still blocks saving on that page', () => {
        for (const bad of ['{', '{"key":1,"lat":11,"lng":100.5}', '{"key":"x","lat":95,"lng":100.5}', '{"key":"x","lat":"a","lng":1}']) {
            assert.equal(parseDemoLocation(root(bad)), null, bad);
        }
        const expired = JSON.stringify({ key: 'test-inside', label: 'x', lat: 11, lng: 100.5, expires_at: 1 });
        assert.equal(parseDemoLocation(root(expired)), null);
        assert.equal(pageHasDemoLocation(root(expired)), true, 'saves stay blocked until the page reloads');
        assert.match(DEMO_SAVE_BLOCKED_MESSAGE, /not saved/);
    });

    it('never caches the demo toggle', () => {
        assert.equal(isNeverCachePath('/spot-mapping/demo-location'), true);
        assert.equal(isNeverCachePath('/spot-mapping'), false, 'Spot Mapping page caching is unchanged');
    });
});

function fakeLeaflet() {
    const calls = { markers: [], tooltips: [], added: [], removed: [] };
    const leaflet = {
        divIcon: (opts) => ({ kind: 'divIcon', opts }),
        marker: (latlng, opts) => {
            const m = {
                latlng,
                opts,
                bindTooltip(text, tOpts) { calls.tooltips.push({ text, tOpts }); return m; },
                addTo(map) { calls.added.push(m); map.layers.add(m); return m; },
            };
            calls.markers.push(m);
            return m;
        },
    };
    const map = {
        layers: new Set(),
        removeLayer(layer) { calls.removed.push(layer); this.layers.delete(layer); },
    };
    return { leaflet, map, calls };
}

describe('Demo Location indicator (immediate, display-only)', () => {
    const unverified = JSON.stringify({ key: 'test-point', label: 'Test (fictional)', lat: 11, lng: 100.5, expires_at: future, verified: false });
    const verified = JSON.stringify({ key: 'test-point', label: 'Test (fictional)', lat: 11, lng: 100.5, expires_at: future, verified: true });

    it('draws a non-interactive, non-draggable marker labelled UNVERIFIED at the server-provided point', () => {
        const { leaflet, map, calls } = fakeLeaflet();
        const layer = drawDemoLocationIndicator(map, parseDemoLocation(root(unverified)), leaflet);
        assert.ok(layer);
        assert.deepEqual(layer.latlng, [11, 100.5], 'coordinates come from the payload unchanged');
        assert.equal(layer.opts.interactive, false);
        assert.equal(layer.opts.draggable, false);
        assert.equal(layer.opts.keyboard, false);
        assert.equal(calls.tooltips[0].text, 'DEMO / SIMULATED LOCATION — UNVERIFIED');
        assert.equal(calls.tooltips[0].text, DEMO_INDICATOR_LABEL_UNVERIFIED);
        assert.equal(calls.tooltips[0].tOpts.permanent, true);
        assert.equal(calls.tooltips[0].tOpts.interactive, false);
        assert.equal(map.layers.size, 1);
    });

    it('treats only verified === true as verified', () => {
        const a = fakeLeaflet();
        drawDemoLocationIndicator(a.map, parseDemoLocation(root(verified)), a.leaflet);
        assert.equal(a.calls.tooltips[0].text, DEMO_INDICATOR_LABEL);
        const b = fakeLeaflet();
        drawDemoLocationIndicator(b.map, parseDemoLocation(root(JSON.stringify({ key: 'x', lat: 11, lng: 100.5, verified: 'true' }))), b.leaflet);
        assert.equal(b.calls.tooltips[0].text, DEMO_INDICATOR_LABEL_UNVERIFIED);
        assert.match(demoLocatedMessage(false), /UNVERIFIED/);
        assert.doesNotMatch(demoLocatedMessage(true), /UNVERIFIED/);
        assert.equal(simulatedPosition(parseDemoLocation(root(unverified))).verified, false);
    });

    it('draws nothing without Demo Mode, with an expired payload, or offline', () => {
        const { leaflet, map, calls } = fakeLeaflet();
        assert.equal(syncDemoLocationIndicator({ map, root: root(null), leaflet }), null);
        const expired = JSON.stringify({ key: 'test-point', lat: 11, lng: 100.5, expires_at: 1, verified: false });
        assert.equal(syncDemoLocationIndicator({ map, root: root(expired), leaflet }), null);
        assert.equal(syncDemoLocationIndicator({ map, root: root(unverified), leaflet, offline: true }), null);
        assert.equal(calls.markers.length, 0);
    });

    it('is removed when Demo Mode ends, expires or the client goes offline, and drawn only once', () => {
        const { leaflet, map, calls } = fakeLeaflet();
        let current = syncDemoLocationIndicator({ map, root: root(unverified), leaflet });
        assert.ok(current);
        current = syncDemoLocationIndicator({ map, root: root(unverified), leaflet, current });
        assert.equal(calls.markers.length, 1, 'not duplicated');
        current = syncDemoLocationIndicator({ map, root: root(unverified), leaflet, offline: true, current });
        assert.equal(current, null);
        assert.equal(map.layers.size, 0);
        assert.equal(calls.removed.length, 1);
    });

    it('page wiring never opens the panel, moves the map, or touches plotting', () => {
        const src = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
        const start = src.indexOf('// SR-10: show the active Demo Location point immediately');
        const end = src.indexOf('let tileOfflineNoted', start);
        assert.ok(start > 0 && end > start, 'indicator block found after the boundary outline');
        const block = src.slice(start, end);
        for (const forbidden of ['openPanel', 'flyTo', 'setView', 'fitBounds', 'panTo', 'placeTemporaryMarker', 'fetch(', 'tempMarker']) {
            assert.equal(block.includes(forbidden), false, forbidden);
        }
        assert.ok(src.indexOf('drawBarangayBoundary(map') < start, 'boundary drawn first, unchanged');
    });
});
