# Barangay boundary data

This folder holds the Barangay La Medalla (Iriga City, Camarines Sur) boundary used by Spot Mapping (panel refinement
SR-11).

**Current status:** the boundary here is **INDICATIVE**, for demonstration only (see "Files added in SR-10 Phase B"
below). The authoritative coordinates have not been provided by the City Planning and Development Office, DENR-LMB or
NAMRIA. Do **not** present this file as official, and do **not** add approximated, traced or invented coordinates.

## Where the map starts, and why

The panel asked what the barangay's starting point is and what it is based on. These are the points the system uses
today. The panel's exact meaning of "starting point" is still to be confirmed with the adviser.

| Item | Value | Basis |
|---|---|---|
| Map opening centre | 13.3806, 123.4312 (`resources/js/maps/la-medalla-base.js`) | PhilAtlas listing for La Medalla. A convenience for opening the map; not a surveyed marker |
| Boundary | The polygon in `la-medalla-boundary.geojson` | NAMRIA/PSA COD-AB, details below. Indicative |

A boundary polygon has no official "first" point, so neither of these should be described as an official starting
point of the barangay.

## Expected file

`resources/geo/la-medalla-boundary.geojson` (path can be changed with `LMLINGA_BOUNDARY_PATH`).

Structure (a GeoJSON Feature; a FeatureCollection with exactly one Feature is also accepted):

```text
{
  "type": "Feature",
  "properties": {
    "source": "<required: authoritative source, e.g. office and document reference>",
    "name": "Barangay La Medalla, Iriga City",
    "version": "<optional>",
    "retrieved_at": "<optional: date obtained>"
  },
  "geometry": {
    "type": "Polygon",            // or "MultiPolygon"
    "coordinates": [ <rings of [longitude, latitude] positions> ]
  }
}
```

Rules checked by `App\Support\Geo\BarangayBoundary`:

- Positions are **[longitude, latitude]** (GeoJSON order), numeric, longitude −180…180, latitude −90…90.
- Each ring has at least 4 positions, is closed (first position = last position) and has non-zero area.
- The first ring of a polygon is the outer boundary; further rings are holes.
- `properties.source` is required; file size ≤ 2 MB; at most 50,000 positions.

If the file is missing, invalid or incomplete, Spot Mapping shows a warning, no outline is drawn, and no location
is treated as inside the barangay (fail closed).

## Demo points for SR-10 Demo Location Mode

Demo Location Mode (Admin-only, per session, max 2 hours, off by default via `LMLINGA_DEMO_LOCATION_ENABLED`)
reads demonstration coordinates from `resources/geo/la-medalla-demo-points.json`
(`LMLINGA_DEMO_LOCATION_POINTS_PATH`). The current file holds one **unverified** candidate (see below). Points must
not be invented, and verified ones should come from a verified source (e.g. the barangay) together with the official
boundary above:

```json
{ "points": [ { "key": "barangay-hall", "label": "Barangay Hall", "lat": 0, "lng": 0, "source": "who verified it and when" } ] }
```

- `key`: lowercase letters, digits and hyphens (max 40), unique; `label` ≤ 80 characters; `source` required.
- A point is offered only if the official boundary is valid **and** contains it. Missing boundary, missing or
  invalid file, or no point inside → Demo Mode is unavailable (fail closed).
- Simulated coordinates are never saved: Spot Mapping refuses plot requests while Demo Mode is on.

## Files added in SR-10 Phase B (2026-10-30) — INDICATIVE, demonstration only

### `la-medalla-boundary.geojson` — indicative barangay boundary

| Item | Value |
|---|---|
| Area | Barangay La Medalla, Iriga City, Camarines Sur — PSGC `0501716006` (HDX pcode `PH0501716006`) |
| Original data | NAMRIA and Philippine Statistics Authority, *Philippines – Subnational Administrative Boundaries* (COD-AB, `cod-ab-phl`, v03), published by OCHA on HDX: https://data.humdata.org/dataset/cod-ab-phl — licence **CC BY-IGO 3.0** (attribution required) |
| File used | `geojson/municities/hires/bgysubmuns-municity-501716000.0.1.json` from https://github.com/faeldon/philippines-json-maps release v1.1.0 (`geojson-hires.zip`, SHA-256 checked against the release `SHA256SUMS`); PSGC 2026-3Q; scripts MIT |
| Geometry | One `Polygon`, one closed ring, 12 positions, counter-clockwise, no self-intersection; `geom_src = source` (the dataset's own shape, copied unchanged); WGS 84 lon/lat (EPSG:4326), reprojected by the source from EPSG:32651; source area 4.26 km² |
| Checks | Passes `BarangayBoundary`; contains the HDX La Medalla centroid, the OSM `La Medalla` place node and the app's map centre; excludes La Medalla of Baao (another LGU) and the Iriga city centroid; neighbours La Trinidad, San Antonio and Santo Niño |
| **Status** | **INDICATIVE** — not a legal or survey boundary (coarse: 12 positions for ~9 km). Used to draw the outline and to validate SR-10 demo points only. **Not** used to accept or refuse real household plots (SM-03 stays off) until an official boundary is confirmed (Iriga City Planning and Development Office, DENR-LMB or NAMRIA) and separately approved. |

### `la-medalla-demo-points.json` — demonstration point (UNVERIFIED)

One candidate, `la-medalla-es-candidate`: the centre of OpenStreetMap way 878482737 "La Medalla Elementary School"
(13.3812723, 123.4313442; © OpenStreetMap contributors, ODbL; tagged DepEd School ID 114447). It lies inside the
indicative boundary (~272 m from its edge). **`"verified": false`** — it has not been confirmed on site or by the
barangay/DepEd, and the UI labels it UNVERIFIED. Set `"verified": true` only after independent confirmation.
Demo Location Mode remains off unless `LMLINGA_DEMO_LOCATION_ENABLED=true` on the demonstration deployment.
