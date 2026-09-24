/**
 * Shared La Medalla (Iriga City) OSM base map.
 * Center/zoom match Spot Mapping Phase 1.1 demo scope (PhilAtlas 13.3806, 123.4312).
 * Preview-only: no plotting, no persistence.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export const LA_MEDALLA_CENTER = [13.3806, 123.4312];
export const LA_MEDALLA_ZOOM = 16;
/** Highest interactive zoom (OSM overzoom beyond native tiles). */
export const LA_MEDALLA_MAX_ZOOM = 22;
/** Highest zoom with real OSM tiles; levels above this scale z19 imagery. */
export const LA_MEDALLA_MAX_NATIVE_ZOOM = 19;

/**
 * @param {HTMLElement} el
 * @param {{ zoom?: number }} [options]
 * @returns {import('leaflet').Map}
 */
export function createLaMedallaBaseMap(el, options = {}) {
    const zoom = options.zoom ?? LA_MEDALLA_ZOOM;
    const map = L.map(el, {
        zoomControl: true,
        attributionControl: true,
        maxZoom: LA_MEDALLA_MAX_ZOOM,
    }).setView(LA_MEDALLA_CENTER, zoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: LA_MEDALLA_MAX_ZOOM,
        maxNativeZoom: LA_MEDALLA_MAX_NATIVE_ZOOM,
        crossOrigin: 'anonymous',
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    requestAnimationFrame(() => {
        map.invalidateSize();
    });
    window.addEventListener('resize', () => map.invalidateSize());

    return map;
}
