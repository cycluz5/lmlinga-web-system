/**
 * Barangay La Medalla (Iriga City, Camarines Sur) boundary outline for the shared base map.
 * Display only: the outline and the dimmed outside area are non-interactive, so map clicks
 * (manual plotting) and markers keep working. Leaflet is passed in so this module can be
 * tested without a browser.
 *
 * The boundary data (la-medalla-boundary.json) is INDICATIVE (NAMRIA/PSA COD-AB), not an
 * official survey boundary. GeoJSON positions are [longitude, latitude].
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

export const BOUNDARY_FIT_PADDING = Object.freeze([24, 24]);

const WORLD_RING = Object.freeze([[-90, -180], [-90, 180], [90, 180], [90, -180]]);

/** Outer rings ([longitude, latitude] positions) of a Polygon/MultiPolygon Feature, or null when unreadable. */
function outerRings(feature) {
    if (feature?.type !== 'Feature') {
        return null;
    }

    const { type, coordinates } = feature.geometry ?? {};
    let rings = null;
    if (type === 'Polygon' && Array.isArray(coordinates)) {
        rings = [coordinates[0]];
    } else if (type === 'MultiPolygon' && Array.isArray(coordinates)) {
        rings = coordinates.map((polygon) => polygon?.[0]);
    }

    if (!rings?.length || !rings.every((ring) => Array.isArray(ring) && ring.length >= 4)) {
        return null;
    }

    return rings;
}

/**
 * Mask rings for the area outside the barangay: a world ring, then each outer boundary ring as a hole.
 * Rings are [latitude, longitude] for L.polygon. Returns null when the geometry cannot be read.
 */
export function outsideMaskLatLngs(feature) {
    const rings = outerRings(feature);
    if (!rings) {
        return null;
    }

    return [WORLD_RING, ...rings.map((ring) => ring.map(([lng, lat]) => [lat, lng]))];
}

/**
 * Draws the dimmed outside area, then the boundary outline, both under the marker pane.
 * Returns the outline layer (use getBounds() to focus the map), or null when nothing valid was drawn.
 */
export function drawBarangayBoundary(map, feature, leaflet) {
    if (!map || !leaflet?.geoJSON || !leaflet?.polygon) {
        return null;
    }

    const maskLatLngs = outsideMaskLatLngs(feature);
    if (!maskLatLngs) {
        return null;
    }

    leaflet.polygon(maskLatLngs, { ...OUTSIDE_MASK_STYLE, interactive: false }).addTo(map);

    return leaflet.geoJSON(feature, {
        interactive: false,
        style: () => ({ ...BOUNDARY_STYLE }),
    }).addTo(map);
}
