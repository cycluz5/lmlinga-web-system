/**
 * Spot Mapping — pure helpers for choosing a usable GPS fix.
 * Phones often return a fast, coarse Wi-Fi/cell fix first; we keep listening briefly and
 * use the most accurate fix instead of testing the first one against the barangay boundary.
 */

/** Stop waiting as soon as a fix is at least this accurate (metres). */
export const GPS_GOOD_ACCURACY_M = 30;

/** Fixes worse than this (metres) get a "weak signal" hint when they land outside the boundary. */
export const GPS_WEAK_ACCURACY_M = 100;

function accuracyOf(fix) {
    const accuracy = fix?.coords?.accuracy;
    return typeof accuracy === 'number' && Number.isFinite(accuracy) ? accuracy : Infinity;
}

/** True when the fix has finite coordinates. */
export function isUsableGpsFix(fix) {
    return Number.isFinite(fix?.coords?.latitude) && Number.isFinite(fix?.coords?.longitude);
}

/** The more accurate of two fixes; an unusable fix never replaces a usable one. */
export function betterGpsFix(previous, next) {
    if (!isUsableGpsFix(next)) {
        return isUsableGpsFix(previous) ? previous : null;
    }
    if (!isUsableGpsFix(previous)) {
        return next;
    }

    return accuracyOf(next) < accuracyOf(previous) ? next : previous;
}

/** True when the fix is accurate enough to stop waiting for a better one. */
export function gpsFixGoodEnough(fix, threshold = GPS_GOOD_ACCURACY_M) {
    return isUsableGpsFix(fix) && accuracyOf(fix) <= threshold;
}

/** Message shown when a GPS fix lands outside the boundary, with the rejected coordinates. */
export function outsideBoundaryGpsMessage(base, { lat, lng, accuracy, distanceMeters } = {}) {
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return base;
    }

    const parts = [`${lat.toFixed(5)}, ${lng.toFixed(5)}`];
    if (Number.isFinite(accuracy)) {
        parts.push(`±${Math.round(accuracy)} m`);
    }

    let message = `${base} Your GPS location (${parts.join(', ')})`;
    message += Number.isFinite(distanceMeters)
        ? ` is about ${Math.round(distanceMeters)} m outside it.`
        : ' is outside it.';

    if (Number.isFinite(accuracy) && accuracy > GPS_WEAK_ACCURACY_M) {
        message += ' GPS signal is weak. Move outdoors and tap Plot again, or tap the map to place it manually.';
    }

    return message;
}
