/**
 * Spot Mapping offline view (Option 3, Phase 1).
 *
 * Without a connection the map has no background imagery, but the page (cached per signed-in staff user) still
 * carries the indicative La Medalla boundary and the households that were plotted when the page was last loaded
 * online. This module only decides what to tell the user and which CSS state to apply; it never plots, saves,
 * queues or changes household coordinates, and plotting stays online-only.
 */

export const OFFLINE_CLASS = 'lml-spot-map--offline';

export const IMAGERY_MISSING_CLASS = 'lml-spot-map--imagery-missing';

/** Phase 2B: the saved offline map (OpenStreetMap vector tiles) is drawn instead of the online imagery. */
export const SAVED_MAP_CLASS = 'lml-spot-map--saved-map';

/** Lead sentence whenever a usable saved offline map is available. */
export const SAVED_MAP_AVAILABLE_MESSAGE = 'Offline map is available. Showing saved map data.';

/** Online, but the online imagery keeps failing and the saved map is shown instead (data date appended when known). */
export const SAVED_MAP_ONLINE_FALLBACK_MESSAGE =
    'Online map imagery is not loading. Offline map is available. Showing saved map data.';

/**
 * Online (still connected to LMLinga), but the online map pictures are not loading and no usable saved map exists.
 * This is NOT a connection problem, so it must not say plotting is disabled (it is not).
 * Replaces the earlier "Map imagery needs a connection…" wording, which read like the user was offline.
 */
export const TILES_UNAVAILABLE_MESSAGE =
    'Map pictures are not loading right now. You are still connected, so Spot Mapping still works. '
    + 'The barangay boundary and households are still shown. This may be a slow signal or a problem with the map service.';

/** Short note under the map when the user is really offline and no saved map can be shown. */
export const MAP_OFFLINE_NOTE = 'You are offline. Map pictures will come back when you are online again.';

/**
 * @param {string|null|undefined} renderedAt ISO 8601 time the page was rendered by the server
 * @param {string} [locale]
 * @returns {string} human "as of" time, or '' when unknown
 */
export function formatAsOf(renderedAt, locale = 'en-PH') {
    if (!renderedAt) {
        return '';
    }
    const date = new Date(renderedAt);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString(locale, {
        month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    });
}

/**
 * @param {{ offline?: boolean, imageryFailed?: boolean, renderedAt?: string|null, locale?: string,
 *           savedMap?: boolean, savedMapUsable?: boolean, savedMapDate?: string }} input
 *   savedMap: the saved offline map is currently drawn (Phase 2B).
 *   savedMapUsable: a complete, verified saved map exists and its renderer works (it may still be switching in).
 *   savedMapDate: the saved map's OpenStreetMap data date.
 * @returns {{ showNotice: boolean, offline: boolean, imageryMissing: boolean, message: string, savedMap?: true }}
 */
export function offlineViewState({
    offline = false, imageryFailed = false, renderedAt = null, locale,
    savedMap = false, savedMapUsable = false, savedMapDate = '',
} = {}) {
    const usable = savedMap || savedMapUsable;
    const dataNote = savedMapDate ? ` (OpenStreetMap data, ${savedMapDate})` : ' (OpenStreetMap data)';

    if (!offline && imageryFailed && savedMap) {
        return {
            showNotice: true,
            offline: false,
            imageryMissing: false,
            savedMap: true,
            message: SAVED_MAP_ONLINE_FALLBACK_MESSAGE.replace(/.$/, `${dataNote}.`)
                + ' The barangay boundary and plotted households are still shown.',
        };
    }

    if (!offline && imageryFailed && usable) {
        // A few online tiles failed but the saved map can take over: never claim imagery "needs a connection".
        return { showNotice: false, offline: false, imageryMissing: false, message: '' };
    }

    if (!offline) {
        return {
            showNotice: imageryFailed,
            offline: false,
            imageryMissing: imageryFailed,
            message: imageryFailed ? TILES_UNAVAILABLE_MESSAGE : '',
        };
    }

    const asOf = formatAsOf(renderedAt, locale);
    const householdsAsOf = asOf ? `as of ${asOf}` : 'when this page was last loaded online';

    if (usable) {
        return {
            showNotice: true,
            offline: true,
            imageryMissing: false,
            ...(savedMap ? { savedMap: true } : {}),
            message: SAVED_MAP_AVAILABLE_MESSAGE.replace(/.$/, `${dataNote}.`)
                + ` The indicative La Medalla boundary and households plotted ${householdsAsOf} are shown.`
                + ' Plotting is disabled until you are online.',
        };
    }

    return {
        showNotice: true,
        offline: true,
        imageryMissing: true,
        message: 'You are offline. Map pictures need internet, and the offline map is not saved on this device yet. '
            + `The indicative La Medalla boundary and households plotted ${householdsAsOf} are still shown.`
            + ' Plotting is disabled until you are online.',
    };
}

/**
 * Apply the state to the page: notice text/visibility and CSS classes on the Spot Mapping root.
 */
export function applyOfflineView(root, state) {
    if (!root) {
        return;
    }
    root.classList?.toggle(OFFLINE_CLASS, state.offline);
    root.classList?.toggle(IMAGERY_MISSING_CLASS, state.imageryMissing);
    root.classList?.toggle(SAVED_MAP_CLASS, state.savedMap === true);

    const notice = root.querySelector?.('[data-spot-map-offline-view-notice]');
    if (notice) {
        notice.textContent = state.message;
        notice.hidden = !state.showNotice;
    }
}
