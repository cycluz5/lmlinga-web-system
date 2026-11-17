/**
 * Shared La Medalla (Iriga City) OSM base map.
 * Opens framed on the Barangay La Medalla boundary (indicative outline, outside area dimmed).
 * Falls back to the PhilAtlas centre (13.3806, 123.4312) when the boundary cannot be drawn.
 * Preview-only: no plotting, no persistence.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import boundaryFeature from './la-medalla-boundary.json';
import { BOUNDARY_FIT_PADDING, drawBarangayBoundary } from './la-medalla-boundary';

export const LA_MEDALLA_CENTER = [13.3806, 123.4312];
export const LA_MEDALLA_ZOOM = 16;
/** Highest interactive zoom (OSM overzoom beyond native tiles). */
export const LA_MEDALLA_MAX_ZOOM = 22;
/** Highest zoom with real OSM tiles; levels above this scale z19 imagery. */
export const LA_MEDALLA_MAX_NATIVE_ZOOM = 19;
/** Indicative Barangay La Medalla boundary (GeoJSON Feature). */
export const LA_MEDALLA_BOUNDARY = boundaryFeature;

/**
 * @param {HTMLElement} el
 * @param {{ zoom?: number, showBoundary?: boolean }} [options]
 *   zoom: fallback zoom used only when the boundary is not shown.
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

    const boundaryLayer = options.showBoundary === false
        ? null
        : drawBarangayBoundary(map, LA_MEDALLA_BOUNDARY, L);
    const boundaryBounds = boundaryLayer?.getBounds?.();
    const focusOnBoundary = () => {
        if (boundaryBounds?.isValid?.()) {
            map.fitBounds(boundaryBounds, { padding: BOUNDARY_FIT_PADDING });
        }
    };
    focusOnBoundary();

    requestAnimationFrame(() => {
        map.invalidateSize();
        // The container may only get its real size now; re-fit so the whole barangay is in view.
        focusOnBoundary();
    });
    window.addEventListener('resize', () => map.invalidateSize());

    return map;
}
