/**
 * SR-10 Demo Location Mode — client side (Phase A foundation).
 *
 * The page carries `data-demo-location` only for the Admin session that turned Demo Mode on, and only when
 * the server verified the point inside the official boundary. Without it, Spot Mapping uses the browser's
 * real GPS exactly as before. The device's own GPS is never changed. Simulated coordinates are labelled
 * and are never saved (the server refuses Spot Mapping writes while Demo Mode is on).
 */

export const DEMO_SAVE_BLOCKED_MESSAGE =
    'Demo Location Mode is on: simulated coordinates are not saved. Turn off Demo Mode to plot real GPS locations.';

export const DEMO_LOCATED_MESSAGE = 'DEMO / SIMULATED LOCATION shown. This is not the device\'s real position and will not be saved.';

/** The demo point is UNVERIFIED unless the server says verified === true. */
export const DEMO_LOCATED_MESSAGE_UNVERIFIED =
    'DEMO / SIMULATED LOCATION — UNVERIFIED shown. This is not the device\'s real position and will not be saved.';

export const DEMO_INDICATOR_LABEL = 'DEMO / SIMULATED LOCATION';

export const DEMO_INDICATOR_LABEL_UNVERIFIED = 'DEMO / SIMULATED LOCATION — UNVERIFIED';

/**
 * @returns {{key: string, label: string, lat: number, lng: number, expires_at: number, verified: boolean} | null}
 */
export function parseDemoLocation(root) {
    const raw = root?.getAttribute?.('data-demo-location');
    if (!raw) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw);
        const lat = Number(parsed?.lat);
        const lng = Number(parsed?.lng);
        if (!parsed || typeof parsed.key !== 'string' || !Number.isFinite(lat) || !Number.isFinite(lng)
            || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
            return null;
        }
        const expiresAt = Number(parsed.expires_at);
        if (Number.isFinite(expiresAt) && expiresAt * 1000 <= Date.now()) {
            return null; // expired in this tab → Real GPS (server still refuses saves until the page reloads)
        }

        return { key: parsed.key, label: String(parsed.label || ''), lat, lng, expires_at: expiresAt, verified: parsed.verified === true };
    } catch {
        return null;
    }
}

/** True while this page was rendered with Demo Mode on (even after expiry) — used to block saves. */
export function pageHasDemoLocation(root) {
    return Boolean(root?.getAttribute?.('data-demo-location'));
}

/** A position object shaped like navigator.geolocation's, marked as simulated. */
export function simulatedPosition(demo) {
    return {
        coords: { latitude: demo.lat, longitude: demo.lng, accuracy: null },
        timestamp: Date.now(),
        simulated: true,
        verified: demo.verified === true,
    };
}

/**
 * Decide where a "Plot via GPS" location comes from.
 * @returns {'offline' | 'simulated' | 'gps'}
 */
export function locationSourceFor({ offline = false, demo = null } = {}) {
    if (offline) {
        return 'offline';
    }

    return demo ? 'simulated' : 'gps';
}

/** Located-overlay text for a simulated fix (says UNVERIFIED unless the point is verified). */
export function demoLocatedMessage(verified) {
    return verified === true ? DEMO_LOCATED_MESSAGE : DEMO_LOCATED_MESSAGE_UNVERIFIED;
}

/** Permanent label of the demo indicator. */
export function demoIndicatorLabel(demo) {
    return demo?.verified === true ? DEMO_INDICATOR_LABEL : DEMO_INDICATOR_LABEL_UNVERIFIED;
}

/**
 * Show the active Demo Location point immediately, as a display-only indicator: not a household marker,
 * not the plotting marker, not draggable, not clickable (map clicks pass through), never opens the panel,
 * never moves the map, and is not part of any form — so it cannot be saved.
 *
 * @returns the Leaflet layer, or null when there is nothing to draw
 */
export function drawDemoLocationIndicator(map, demo, leaflet) {
    if (!map || !demo || !leaflet?.marker || !leaflet?.divIcon) {
        return null;
    }

    const label = demoIndicatorLabel(demo);
    const marker = leaflet.marker([demo.lat, demo.lng], {
        icon: leaflet.divIcon({
            className: 'lml-spot-map__demo-indicator-wrap',
            html: '<span class="lml-spot-map__demo-indicator" aria-hidden="true"><i class="bi bi-broadcast-pin"></i></span>',
            iconSize: [30, 30],
            iconAnchor: [15, 15],
        }),
        interactive: false,
        draggable: false,
        keyboard: false,
        zIndexOffset: -1000,
        alt: label,
    });
    marker.bindTooltip?.(label, {
        permanent: true,
        interactive: false,
        direction: 'top',
        offset: [0, -16],
        className: 'lml-spot-map__demo-indicator-label',
    });
    marker.addTo(map);

    return marker;
}

/**
 * Keep the indicator in step with the page: shown only while the payload is valid and unexpired and the
 * client is online; removed otherwise. Returns the current layer (or null).
 */
export function syncDemoLocationIndicator({ map, root, leaflet, offline = false, current = null }) {
    const demo = offline ? null : parseDemoLocation(root);
    if (!demo) {
        if (current && map?.removeLayer) {
            map.removeLayer(current);
        }
        return null;
    }

    return current ?? drawDemoLocationIndicator(map, demo, leaflet);
}
