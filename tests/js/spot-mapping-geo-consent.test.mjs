import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';

import {
    currentSessionToken,
    GEO_CONSENT_KEY,
    geolocationPermissionState,
    hasGeoConsent,
    rememberGeoConsent,
    sessionStore,
} from '../../resources/js/pages/spot-mapping-geo-consent.js';

const spotSource = readFileSync(path.resolve('resources/js/pages/spot-mapping.js'), 'utf8');
const bladeSource = readFileSync(path.resolve('resources/views/pages/spot-mapping/index.blade.php'), 'utf8');

function memoryStorage() {
    const data = new Map();
    return {
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        data,
    };
}

const throwingStorage = {
    getItem() {
        throw new Error('blocked');
    },
    setItem() {
        throw new Error('blocked');
    },
};

function slice(source, start, end) {
    const from = source.indexOf(start);
    assert.ok(from >= 0, `missing ${start}`);
    const to = source.indexOf(end, from + start.length);
    return source.slice(from, to === -1 ? undefined : to);
}

describe('geo consent storage', () => {
    it('has no consent until remembered', () => {
        const storage = memoryStorage();
        assert.equal(hasGeoConsent(storage, 'token-a'), false);
        assert.equal(rememberGeoConsent(storage, 'token-a'), true);
        assert.equal(storage.data.get(GEO_CONSENT_KEY), 'token-a');
        assert.equal(hasGeoConsent(storage, 'token-a'), true);
    });

    it('asks again after the session token changes (logout / login)', () => {
        const storage = memoryStorage();
        rememberGeoConsent(storage, 'token-a');
        assert.equal(hasGeoConsent(storage, 'token-b'), false);
    });

    it('treats missing token, missing or blocked storage as "ask"', () => {
        assert.equal(hasGeoConsent(memoryStorage(), ''), false);
        assert.equal(hasGeoConsent(null, 'token-a'), false);
        assert.equal(hasGeoConsent(throwingStorage, 'token-a'), false);
        assert.equal(rememberGeoConsent(throwingStorage, 'token-a'), false);
        assert.equal(rememberGeoConsent(memoryStorage(), ''), false);
    });

    it('reads the CSRF token and survives missing document/window', () => {
        const doc = { querySelector: () => ({ getAttribute: () => 'csrf-1' }) };
        assert.equal(currentSessionToken(doc), 'csrf-1');
        assert.equal(currentSessionToken({ querySelector: () => null }), '');
        assert.equal(currentSessionToken(null), '');
        assert.equal(sessionStore(null), null);
        assert.equal(sessionStore({ get sessionStorage() { throw new Error('denied'); } }), null);
    });
});

describe('geolocationPermissionState', () => {
    const navWith = (query) => ({ permissions: { query } });

    it('returns the browser permission state', async () => {
        for (const state of ['granted', 'prompt', 'denied']) {
            assert.equal(await geolocationPermissionState(navWith(async () => ({ state }))), state);
        }
    });

    it('returns unknown when the Permissions API is missing, fails or is odd', async () => {
        assert.equal(await geolocationPermissionState(null), 'unknown');
        assert.equal(await geolocationPermissionState({}), 'unknown');
        assert.equal(await geolocationPermissionState(navWith(async () => { throw new TypeError('x'); })), 'unknown');
        assert.equal(await geolocationPermissionState(navWith(async () => ({ state: 'weird' }))), 'unknown');
    });
});

describe('spot mapping GPS permission gate', () => {
    const start = slice(spotSource, 'function startGpsPlot()', '\n    function askGeoConsent');
    const ask = slice(spotSource, 'function askGeoConsent(', '\n    function requestGpsLocation');

    it('checks consent in startGpsPlot and never calls the GPS there directly', () => {
        assert.match(start, /hasGeoConsent\(sessionStore\(\), currentSessionToken\(\)\)/);
        assert.match(start, /askGeoConsent\(requestId, plan\)/);
        assert.doesNotMatch(start, /getCurrentPosition/);
        assert.ok(start.indexOf('isClientOffline') < start.indexOf('hasGeoConsent'));
    });

    it('shows how to unblock instead of asking when the browser has blocked location', () => {
        const permAt = start.indexOf('geolocationPermissionState(navigator)');
        const deniedAt = start.indexOf("state === 'denied'");
        assert.ok(permAt >= 0 && deniedAt > permAt);
        assert.ok(deniedAt < start.indexOf('hasGeoConsent'));
        assert.match(start, /if \(state === 'denied'\) \{\s*enterManualFallback\(GEO_BLOCKED_MESSAGE\);\s*return;/);
        assert.match(start, /\.then\(\(state\) => \{\s*if \(requestId !== locateRequestId\)/);
    });

    it('allow remembers consent then requests GPS; manual falls back to map clicking', () => {
        assert.match(ask, /rememberGeoConsent\(sessionStore\(\), currentSessionToken\(\)\);\s*requestGpsLocation\(requestId, plan\)/);
        assert.match(ask, /enterManualFallback\(GEO_CONSENT_MANUAL_MESSAGE, 'info'\)/);
        assert.match(ask, /requestId !== locateRequestId/);
        assert.match(ask, /showModal\(\)/);
        assert.match(ask, /window\.confirm\(GEO_CONSENT_CONFIRM_TEXT\)/);
    });

    it('only requestGpsLocation starts the GPS (watchPosition)', () => {
        const request = slice(spotSource, 'function requestGpsLocation(', '\n    function applyStats');
        assert.match(request, /navigator\.geolocation\.watchPosition/);
        assert.equal(spotSource.split('watchPosition(').length - 1, 1);
        assert.equal(spotSource.split('getCurrentPosition(').length - 1, 0);
    });

    it('renders the permission dialog with allow and manual buttons', () => {
        assert.match(bladeSource, /<dialog[\s\S]*?data-spot-map-geo-consent\b/);
        assert.match(bladeSource, /data-spot-map-geo-consent-allow/);
        assert.match(bladeSource, /data-spot-map-geo-consent-manual/);
        assert.match(bladeSource, /Allow location access\?/);
    });
});
