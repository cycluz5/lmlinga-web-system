/**
 * Spot Mapping location check (Phase 3A). Pure functions: no Leaflet, no network, no storage.
 *
 * - Boundary awareness uses the existing INDICATIVE La Medalla boundary GeoJSON. It only informs and warns;
 *   it never rejects a location (the server does not check the boundary either).
 * - The inside test mirrors App\Support\Geo\BarangayBoundary::contains (ray casting, edges count as inside,
 *   holes excluded) so the page and the server agree.
 * - Source wording separates device GPS, hand-placed (unverified) and simulated Demo Mode locations.
 *   Saved markers do not record their source yet, so they are described honestly as "source not recorded".
 */

/** "Near the edge" margin outside the indicative boundary (the outline is coarse: 12 points). */
export const NEAR_BOUNDARY_METERS = 200;

/** GPS accuracy levels (metres, as reported by the device). */
export const GPS_GOOD_METERS = 30;
export const GPS_WEAK_METERS = 100;

const EDGE_EPSILON = 1e-9;
const EARTH_RADIUS_METERS = 6371000;

function polygonsOf(feature) {
    const geometry = feature?.geometry;
    if (!geometry || !Array.isArray(geometry.coordinates)) {
        return [];
    }
    if (geometry.type === 'Polygon') {
        return [geometry.coordinates];
    }
    if (geometry.type === 'MultiPolygon') {
        return geometry.coordinates;
    }
    return [];
}

function onEdge(x, y, ring) {
    for (let i = 0; i < ring.length - 1; i++) {
        const [x1, y1] = ring[i];
        const [x2, y2] = ring[i + 1];
        const cross = (x - x1) * (y2 - y1) - (y - y1) * (x2 - x1);
        if (Math.abs(cross) > EDGE_EPSILON) {
            continue;
        }
        if (x >= Math.min(x1, x2) - EDGE_EPSILON && x <= Math.max(x1, x2) + EDGE_EPSILON
            && y >= Math.min(y1, y2) - EDGE_EPSILON && y <= Math.max(y1, y2) + EDGE_EPSILON) {
            return true;
        }
    }
    return false;
}

function inRing(x, y, ring) {
    if (onEdge(x, y, ring)) {
        return true;
    }
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) {
            inside = !inside;
        }
    }
    return inside;
}

/** Same rule as BarangayBoundary::contains. Returns false for a missing/invalid feature. */
export function boundaryContains(feature, lat, lng) {
    const x = Number(lng);
    const y = Number(lat);
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
        return false;
    }
    for (const rings of polygonsOf(feature)) {
        if (!Array.isArray(rings?.[0]) || !inRing(x, y, rings[0])) {
            continue;
        }
        const inHole = rings.slice(1).some((hole) => inRing(x, y, hole) && !onEdge(x, y, hole));
        if (!inHole) {
            return true;
        }
    }
    return false;
}

/** Shortest distance in metres from the point to the boundary outline (any ring). Infinity when unknown. */
export function distanceToBoundaryMeters(feature, lat, lng) {
    const lat0 = Number(lat);
    const lng0 = Number(lng);
    if (!Number.isFinite(lat0) || !Number.isFinite(lng0)) {
        return Infinity;
    }
    // Local flat projection around the point: accurate to well under 1% at barangay scale.
    const kx = (Math.PI / 180) * EARTH_RADIUS_METERS * Math.cos((lat0 * Math.PI) / 180);
    const ky = (Math.PI / 180) * EARTH_RADIUS_METERS;
    let best = Infinity;
    for (const rings of polygonsOf(feature)) {
        for (const ring of rings) {
            for (let i = 0; i < ring.length - 1; i++) {
                const ax = (ring[i][0] - lng0) * kx;
                const ay = (ring[i][1] - lat0) * ky;
                const bx = (ring[i + 1][0] - lng0) * kx;
                const by = (ring[i + 1][1] - lat0) * ky;
                const dx = bx - ax;
                const dy = by - ay;
                const len2 = dx * dx + dy * dy;
                const t = len2 > 0 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / len2)) : 0;
                best = Math.min(best, Math.hypot(ax + t * dx, ay + t * dy));
            }
        }
    }
    return best;
}

/**
 * @returns {{ status: 'inside'|'near'|'outside'|'unchecked', distanceMeters: number|null }}
 *   near = outside the indicative outline but within NEAR_BOUNDARY_METERS of it.
 */
export function boundaryStatus(feature, lat, lng) {
    if (polygonsOf(feature).length === 0 || !Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) {
        return { status: 'unchecked', distanceMeters: null };
    }
    if (boundaryContains(feature, lat, lng)) {
        return { status: 'inside', distanceMeters: 0 };
    }
    const distance = distanceToBoundaryMeters(feature, lat, lng);
    return {
        status: distance <= NEAR_BOUNDARY_METERS ? 'near' : 'outside',
        distanceMeters: Number.isFinite(distance) ? Math.round(distance) : null,
    };
}

/** @returns {'good'|'weak'|'rough'|'unknown'} */
export function accuracyLevel(meters) {
    const m = Number(meters);
    if (meters === null || meters === undefined || !Number.isFinite(m) || m < 0) {
        return 'unknown';
    }
    if (m <= GPS_GOOD_METERS) {
        return 'good';
    }
    return m <= GPS_WEAK_METERS ? 'weak' : 'rough';
}

/**
 * Kind of location, for labels and marker styling.
 * @returns {'gps'|'gps-weak'|'manual'|'simulated'|'saved'}
 */
export function locationKind({ source = null, accuracy = null, isTemp = false } = {}) {
    if (source === 'simulated') {
        return 'simulated';
    }
    if (!isTemp) {
        return 'saved';
    }
    if (source === 'gps') {
        return accuracyLevel(accuracy) === 'good' ? 'gps' : 'gps-weak';
    }
    return 'manual';
}

const KIND_LABELS = {
    gps: 'GPS location',
    'gps-weak': 'GPS location (weak signal)',
    manual: 'Unverified: placed by hand',
    simulated: 'Simulated (demo) - never saved',
    saved: 'Saved location (source not recorded)',
};

export function locationKindLabel(kind) {
    return KIND_LABELS[kind] || KIND_LABELS.saved;
}

/**
 * Plain-language check shown in the plot panel. Never blocks saving; `needsConfirm` asks the health worker
 * to tick a box only when the point is clearly outside the indicative boundary.
 *
 * @returns {{ kind: string, kindLabel: string, accuracyText: string, boundaryText: string,
 *             adviceText: string, needsConfirm: boolean, level: 'ok'|'note'|'warn', boundary: object }}
 */
export function describeLocationCheck({ feature = null, lat, lng, source = null, accuracy = null, isTemp = true } = {}) {
    const kind = locationKind({ source, accuracy, isTemp });
    const boundary = boundaryStatus(feature, lat, lng);
    const level = accuracyLevel(accuracy);
    const rounded = Number.isFinite(Number(accuracy)) ? Math.round(Number(accuracy)) : null;

    let accuracyText = '';
    if (source === 'gps') {
        accuracyText = rounded === null ? 'Accuracy: not reported by the device.' : `Accuracy: about ±${rounded} m.`;
    } else if (source === 'manual') {
        accuracyText = 'Accuracy: unknown (placed by hand on the map).';
    }

    let adviceText = '';
    if (source === 'gps' && (level === 'weak' || level === 'rough' || level === 'unknown')) {
        adviceText = 'The GPS signal is weak. If you can, step outside or wait a moment, then tap Try GPS again.';
    } else if (source === 'manual') {
        adviceText = 'Check that the pin is on the right house before saving.';
    }

    const boundaryText = {
        inside: 'Inside the indicative La Medalla boundary.',
        // Phase 3B: saved households get wording about checking, not saving (they are already saved).
        near: isTemp
            ? `Just outside the indicative La Medalla boundary (about ${boundary.distanceMeters} m). The boundary is approximate; you can still save.`
            : `Just outside the indicative La Medalla boundary (about ${boundary.distanceMeters} m). The boundary is approximate.`,
        outside: isTemp
            ? `Outside the indicative La Medalla boundary (about ${formatDistance(boundary.distanceMeters)} away). Check the location before saving.`
            : `Outside the indicative La Medalla boundary (about ${formatDistance(boundary.distanceMeters)} away). Please check this saved location.`,
        unchecked: 'Boundary not available, so the location was not checked.',
    }[boundary.status];

    const warn = boundary.status === 'outside' || (source === 'gps' && level === 'rough');
    const note = boundary.status === 'near' || source === 'manual' || (source === 'gps' && level !== 'good');

    return {
        kind,
        kindLabel: locationKindLabel(kind),
        accuracyText,
        boundaryText,
        adviceText,
        needsConfirm: boundary.status === 'outside' && source !== 'simulated',
        level: warn ? 'warn' : (note ? 'note' : 'ok'),
        boundary,
    };
}

function formatDistance(meters) {
    if (!Number.isFinite(Number(meters))) {
        return 'an unknown distance';
    }
    return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${meters} m`;
}

export const OUTSIDE_CONFIRM_LABEL = 'I checked: this household is in La Medalla (the boundary is approximate).';

/** Household-number labels appear from this zoom level, and only for markers on screen. */
export const LABEL_MIN_ZOOM = 18;
export const LABEL_MAX_ON_SCREEN = 200;

/** Show labels only when zoomed in enough and not too many markers are on screen. */
export function shouldShowHouseholdLabels(zoom, onScreenCount) {
    return Number(zoom) >= LABEL_MIN_ZOOM && Number(onScreenCount) <= LABEL_MAX_ON_SCREEN;
}

/** "HH-0012", or "HH-0012 +2" for a group of overlapping households. Never shows names. */
export function householdLabelText(householdNo, count = 1) {
    const no = String(householdNo || '').trim();
    if (!no || no === 'HH-NEW') {
        return '';
    }
    return count > 1 ? `${no} +${count - 1}` : no;
}
