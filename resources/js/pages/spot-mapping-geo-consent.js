/**
 * Spot Mapping — in-app permission before using the device GPS.
 * Asked once per login session: consent is stored in sessionStorage together with the
 * current CSRF token. Laravel issues a new token on login and logout, so a new session
 * (or a closed browser, which clears sessionStorage) asks again.
 * Every storage access is guarded; any failure means "ask".
 */

export const GEO_CONSENT_KEY = 'lml.spotMap.geoConsent';

export const GEO_CONSENT_MANUAL_MESSAGE = 'Click on the map to plot a location.';

export const GEO_CONSENT_CONFIRM_TEXT =
    'Allow location access?\n\n'
    + "LMLinga will use your device's GPS to place this household on the map. "
    + 'Your location is used only for this plot.\n\n'
    + 'Press OK to allow, or Cancel to place the household manually.';

/** Current session token from <meta name="csrf-token">, or '' when unavailable. */
export function currentSessionToken(doc = globalThis.document) {
    try {
        return doc?.querySelector?.('meta[name="csrf-token"]')?.getAttribute('content') || '';
    } catch {
        return '';
    }
}

/** Safe sessionStorage lookup (null when blocked or unavailable). */
export function sessionStore(win = globalThis.window) {
    try {
        return win?.sessionStorage ?? null;
    } catch {
        return null;
    }
}

export function hasGeoConsent(storage, sessionToken) {
    if (!storage || !sessionToken) {
        return false;
    }

    try {
        return storage.getItem(GEO_CONSENT_KEY) === sessionToken;
    } catch {
        return false;
    }
}

export function rememberGeoConsent(storage, sessionToken) {
    if (!storage || !sessionToken) {
        return false;
    }

    try {
        storage.setItem(GEO_CONSENT_KEY, sessionToken);
        return true;
    } catch {
        return false;
    }
}
