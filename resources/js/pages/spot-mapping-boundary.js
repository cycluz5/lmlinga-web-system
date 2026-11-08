/**
 * Spot Mapping — official Barangay La Medalla boundary outline (panel refinement SR-11, step SM-01).
 * Display only: the server validates the GeoJSON (App\Support\Geo\BarangayBoundary) and sends it only when
 * valid. Leaflet is passed in so this module stays testable without a browser.
 * GeoJSON positions are [longitude, latitude]; L.geoJSON converts them to Leaflet LatLng.
 */

export const BOUNDARY_STYLE = Object.freeze({
    color: '#15803d',
    weight: 2,
    opacity: 0.9,
    fillColor: '#22c55e',
    fillOpacity: 0.04,
});

export const OUTSIDE_MASK_STYLE = Object.freeze({
    stroke: false,
    fillColor: '#1f2937',
    fillOpacity: 0.45,
    fillRule: 'evenodd',
});

const WORLD_RING = Object.freeze([[-90, -180], [-90, 180], [90, 180], [90, -180]]);

/** Returns the boundary Feature from the page root, or null (missing/invalid/malformed → nothing drawn). */
export function parseBoundaryGeoJson(root) {
    if (!root || root.getAttribute('data-boundary-state') !== 'valid') {
        return null;
    }

    const raw = root.getAttribute('data-boundary');
    if (!raw) {
        return null;
    }

    try {
        const feature = JSON.parse(raw);
        const type = feature?.geometry?.type;
        if (feature?.type !== 'Feature' || (type !== 'MultiPolygon' && type !== 'Polygon')
            || !Array.isArray(feature.geometry.coordinates) || feature.geometry.coordinates.length === 0) {
            return null;
        }
        return feature;
    } catch {
        return null;
    }
}

/**
 * Mask rings for the area outside the barangay: a world ring, then each outer boundary ring as a hole.
 * Rings are [latitude, longitude] for L.polygon. Returns null when the geometry cannot be read.
 */
export function outsideMaskLatLngs(feature) {
    const { type, coordinates } = feature?.geometry ?? {};
    let outerRings = null;
    if (type === 'Polygon') {
        outerRings = [coordinates?.[0]];
    } else if (type === 'MultiPolygon' && Array.isArray(coordinates)) {
        outerRings = coordinates.map((polygon) => polygon?.[0]);
    }

    if (!outerRings?.length || !outerRings.every((ring) => Array.isArray(ring) && ring.length >= 4)) {
        return null;
    }

    return [WORLD_RING, ...outerRings.map((ring) => ring.map(([lng, lat]) => [lat, lng]))];
}

/**
 * Draws the outline under the household markers, over a mask that dims everything outside the barangay
 * (panel: only the barangay should be seen). Display only: both layers are non-interactive, so map clicks
 * for manual plotting still reach the map, and nothing outside is hidden, refused or saved differently.
 * Returns the outline layer, or null when there is nothing valid to draw.
 */
export function drawBarangayBoundary(map, feature, leaflet) {
    if (!map || !feature || !leaflet?.geoJSON) {
        return null;
    }

    const maskLatLngs = leaflet.polygon ? outsideMaskLatLngs(feature) : null;
    if (maskLatLngs) {
        leaflet.polygon(maskLatLngs, { ...OUTSIDE_MASK_STYLE, interactive: false }).addTo(map);
    }

    return leaflet.geoJSON(feature, {
        interactive: false,
        style: () => ({ ...BOUNDARY_STYLE }),
    }).addTo(map);
}
