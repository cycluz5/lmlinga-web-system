/**
 * Spot Mapping marker view (Phase 3B). Pure helpers: no Leaflet, no network, no storage.
 *
 * - Zoom bands let the page scale markers so they stay readable when zoomed out and easy to tap when zoomed in.
 * - A saved household clearly outside the INDICATIVE boundary gets a "check location" flag. This is computed
 *   from the saved coordinates on the fly; it never changes or stores anything.
 * - The popup card uses only data the Spot Mapping page already shows to signed-in staff.
 */

import { boundaryStatus, householdLabelText } from './spot-mapping-location-check.js';

/** @returns {'low'|'mid'|'high'} low < 16 (small dots), mid 16–17 (numbered markers), high ≥ 18 (large + labels). */
export function zoomBand(zoom) {
    const z = Number(zoom);
    if (!Number.isFinite(z) || z < 16) {
        return 'low';
    }
    return z >= 18 ? 'high' : 'mid';
}

/**
 * "check" when a saved location is clearly outside the indicative boundary (beyond the near-edge margin), else null.
 * Unsaved pins and simulated (demo) points are handled by the plot panel instead.
 */
export function savedLocationFlag(feature, lat, lng) {
    return boundaryStatus(feature, lat, lng).status === 'outside' ? 'check' : null;
}

export const CHECK_LOCATION_TEXT = 'Location to check: outside the indicative La Medalla boundary.';

function esc(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function present(value) {
    const text = String(value ?? '').trim();
    return text !== '' && text !== '—' ? text : '';
}

/**
 * Compact popup card for a saved household marker. Every value is escaped.
 * @param {object} data marker data (householdNo, zone, zoneLabel, houseHead, members, householdType, householdKey,
 *   source, locationFlag)
 * @param {{ viewBase?: string }} [options]
 */
export function householdPopupHtml(data = {}, { viewBase = '/household-profiling/' } = {}) {
    const no = present(data.householdNo);
    const zone = present(data.zoneLabel) || (present(data.zone) ? `Zone ${present(data.zone)}` : '');
    const rows = [
        ['Zone', zone],
        ['Household head', present(data.houseHead)],
        ['Members', data.members != null && present(data.members) ? String(data.members) : ''],
        ['Type', present(data.householdType)],
    ].filter(([, value]) => value);

    const location = data.source === 'simulated'
        ? 'Simulated (demo) location: never saved.'
        : 'Saved location (source not recorded).';
    const flag = data.locationFlag === 'check'
        ? `<p class="lml-spot-map__popup-flag">${esc(CHECK_LOCATION_TEXT)}</p>`
        : '';
    const canView = no && no !== 'HH-NEW' && data.source !== 'simulated';
    const link = canView
        ? `<a class="lml-spot-map__popup-link" href="${esc(viewBase + encodeURIComponent(data.householdKey || no))}">View household</a>`
        : '';

    return `<div class="lml-spot-map__popup-card">`
        + `<p class="lml-spot-map__popup-title">${esc(householdLabelText(no) || 'Household')}</p>`
        + `<dl class="lml-spot-map__popup-fields">${rows.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}</dl>`
        + `<p class="lml-spot-map__popup-location">${esc(location)}</p>`
        + flag
        + link
        + `</div>`;
}
