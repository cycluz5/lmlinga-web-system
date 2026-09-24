# Production Freeze Record

## Module

Dashboard

## Status

**PRODUCTION FROZEN**

## Frozen sub-scope

Dashboard home UI (layout, Overview cards, Spot Mapping / La Medalla map, household snapshot table, Health Indicators).

This freeze does **not** convert Dashboard fixture counts into live database aggregates.

---

## Independent reviewer verdict

**A. APPROVED — READY FOR PRODUCTION FREEZE**

The independent reviewer classified PHPUnit and `npm run build` results as **supplied evidence**. Those commands were not independently reproduced by the reviewer because the review bundle did not contain the full Laravel runtime.

## Freeze date

**2026-08-16**

---

## Approved implementation files (frozen)

Do not alter these files except under the PRODUCTION FREEZE BUG PATCH / CHANGE REQUEST workflow.

- `app/Support/DashboardUiData.php`
- `resources/views/pages/dashboard/index.blade.php`
- `resources/views/components/lml/dashboard/count-card.blade.php`
- `resources/views/components/lml/dashboard/indicator-pictogram.blade.php`
- `resources/css/pages/dashboard.css`
- `resources/js/pages/dashboard-home.js`
- `resources/js/maps/la-medalla-base.js`

## Approved test files (frozen)

- `tests/Unit/DashboardUiDataTest.php`
- `tests/Feature/DashboardSummaryUiTest.php`

---

## Approved Dashboard baseline

The frozen Dashboard preserves:

- Dashboard title / subheading
- Temporary UI demo-value note
- Five Overview cards (Total Household, Total Residents, NHTS, Non NHTS, Non NHTS Poor)
- No Overview icons
- Spot Mapping / La Medalla map
- Household summary table
- Health Indicators section
- Exactly 13 Health Indicators
- Complementary Food remains absent
- Approved semantic pictograms
- Approved gradient / tint card treatment
- Sanitary-toilet final spanning tile
- Responsive desktop / tablet / mobile behavior
- Visible Leaflet / OpenStreetMap attribution
- Corrected mobile map caption placement (no overlap with attribution)

Displayed totals remain UI fixtures, not live database aggregates.

---

## Approved responsive baseline

### Desktop — 1440px

- Five Overview cards in one row
- Map / table left; Health Indicators right
- No page-level horizontal overflow (`scrollWidth` 1440 = `clientWidth` 1440)

### Laptop — 1366px

- Desktop side-by-side composition retained
- No page-level horizontal overflow (`scrollWidth` 1366 = `clientWidth` 1366)

### Tablet — 820px

- Map, table, and Health Indicators stacked
- Approximately 772px shared width
- Matching left / right boundaries (left 24 / right 796)
- Health Indicator two-column grid retained
- No page-level horizontal overflow (`scrollWidth` 820 = `clientWidth` 820)

### Mobile — 390px

- Map, table, and Health Indicators stacked
- Approximately 358px shared width
- Matching left / right boundaries (left 16 / right 374)
- Health Indicator two-column grid retained
- Final sanitary-toilet tile spans both columns
- Map caption does not overlap attribution
- Internal household-table scrolling is allowed
- No page-level horizontal overflow (`scrollWidth` 390 = `clientWidth` 390)

---

## Review findings

### F-1 — RESOLVED (was MAJOR / freeze-blocking)

Mobile Spot Mapping caption no longer overlaps Leaflet / OpenStreetMap attribution.

Verified 390px clearance: **24px**, overlap: **false** (caption bottom 688, attribution top 712).

### F-2 — RESOLVED (was MINOR)

Dashboard temporary / demo fixture-note contrast corrected.

- Foreground: `#4b5563`
- Background: `#ffffff`
- Verified contrast: approximately **7.56:1**
- WCAG AA: **PASS**

### F-3 — RESOLVED (was MINOR / packaging)

Review / test packaging preserves:

- `tests/Unit/DashboardUiDataTest.php`
- `tests/Feature/DashboardSummaryUiTest.php`

### F-4 — OPTIONAL / NON-BLOCKING

Unused Overview `icon` data in `DashboardUiData::primaryCards()`.

**Do not change** during freeze declaration.

### F-5 — OPTIONAL / NON-BLOCKING

Existing Health Indicator pictogram reuse (differentiated by color / label).

**Do not change** during freeze declaration.

---

## Test baseline (supplied evidence)

| Field | Value |
|-------|--------|
| Test files | `tests/Unit/DashboardUiDataTest.php`, `tests/Feature/DashboardSummaryUiTest.php` |
| Command | `php vendor/bin/phpunit tests/Unit/DashboardUiDataTest.php tests/Feature/DashboardSummaryUiTest.php` |
| Exit code | `0` |
| Tests | `5` |
| Assertions | `76` |

This was supplied test evidence during independent review. Do not claim independent reviewer execution.

## Build baseline (supplied evidence)

| Field | Value |
|-------|--------|
| Command | `npm run build` |
| Exit code | `0` |

This was supplied build evidence during independent review and was not independently reproduced by the reviewer.

---

## Final independent re-review evidence

- Review bundle: `docs/qa/review-bundles/dashboard-ui-post-refinement-review.zip`
- Post-fix screenshots / overflow: `docs/qa/screenshots/dashboard-map-caption-overlap-fix/`
- Prior (pre-refinement) bundle, historical only: `docs/qa/review-bundles/dashboard-ui-independent-review.zip`

---

## Freeze policy

After this declaration, Dashboard files listed above are frozen.

Future Dashboard modifications are permitted **only** when one of the following exists:

A. Verified functional bug  
B. Verified accessibility defect  
C. Verified responsive regression  
D. Approved requirement / change request  
E. Security issue  
F. Required integration change  

Any future modification must use the **PRODUCTION FREEZE BUG PATCH / CHANGE REQUEST** workflow.

Future work must **not** opportunistically:

- Modernize
- Redesign
- Refactor
- Clean up CSS
- Rename components
- Replace icons
- Alter layouts
- Alter responsive breakpoints
- Change unrelated Dashboard behavior

while addressing another issue.

---

## Final status

**DASHBOARD — PRODUCTION FROZEN**
