# LMLinga Review Package

Module:
Health Records → Maternal → Non-Residents → Individual View

Review stage:
Pre-Production-Freeze Independent Review

## Implementation Files

- `implementation/maternal-non-residents-show.blade.php`
  (from `resources/views/pages/health-records/maternal-non-residents-show.blade.php`)
- `implementation/health-records-maternal.css`
  (from `resources/css/pages/health-records-maternal.css`)
- `implementation/HealthRecordsNonResidentMaternal.php`
  (from `app/Support/HealthRecordsNonResidentMaternal.php`)
- `implementation/non-resident-maternal.php`
  (from `resources/demo/non-resident-maternal.php`)
- `implementation/NonResidentMaternalController.php`
  (from `app/Http/Controllers/HealthRecords/NonResidentMaternalController.php`)
- `tests/HealthRecordsMaternalTest.php`
  (from `tests/Feature/HealthRecordsMaternalTest.php`)
- `REVIEW_ROUTE_CONTEXT.txt` (excerpt of `routes/web.php`; live routes file not copied in full)

## Data Path

`GET /health-records/maternal/non-residents/{clientKey}`
(`health-records.maternal.non-residents.show`)
→ `app/Http/Controllers/HealthRecords/NonResidentMaternalController.php` (`show`)
→ `app/Support/HealthRecordsNonResidentMaternal.php` (`findEligible`, `pregnancySummary`)
→ `resources/demo/non-resident-maternal.php` (plus session-created clients)
→ `resources/views/pages/health-records/maternal-non-residents-show.blade.php`

Page title/subtitle are passed as `pageTitle` / `pageSubtitle` to `layouts.dashboard`, which renders `h1.lml-topbar__title` and `p.lml-topbar__subtitle`. View-specific styles live in `health-records-maternal.css` under `.lml-hr-mc-show*`.

## Screenshots

Latest captures from `docs/qa/screenshots/health-records-maternal-nr-show-figma/` after two-card separation, content scaling, complete profile data, and responsive refinement. Identity shown: **Ana P. Villanueva**.

| Package filename | Viewport | Identity | Latest implementation |
|---|---|---|---|
| `screenshots/desktop-1440x900.png` | 1440×900 | Ana P. Villanueva | YES |
| `screenshots/tablet-820x1180.png` | 820×1180 | Ana P. Villanueva | YES |
| `screenshots/mobile-390x844.png` | 390×844 | Ana P. Villanueva | YES |
| `screenshots/show-ana-1440x900.png` | 1440×900 | Ana P. Villanueva | YES (original capture name) |
| `screenshots/show-ana-820x1180.png` | 820×1180 | Ana P. Villanueva | YES (original capture name) |
| `screenshots/show-ana-390x844.png` | 390×844 | Ana P. Villanueva | YES (original capture name) |

## Test Evidence

Test file:
tests/Feature/HealthRecordsMaternalTest.php

Command:
php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsMaternalTest.php

Exit code:
0

Tests:
21

Assertions:
322

Full output: `evidence/test-output.txt`

## Build Evidence

Command:
npm run build

Exit code:
0

Full output: `evidence/build-output.txt`

## Responsive Evidence

- `evidence/responsive/overflow-report.json`
- `evidence/responsive/overflow-extra-widths.json` (1024, 768, 600, 480, 430, 390)
- `evidence/responsive/desktop-metrics.json`
- `evidence/responsive/listing-desktop-metrics.json`
- `evidence/responsive/width-compare-1440.json`
- `evidence/tools/capture-hr-maternal-nr-show.mjs`

## Figma Reference

FIGMA REFERENCE NOT PRESENT LOCALLY

No genuine local export of “Maternal Care – Non Resident – Interface” was found in the repository. A placeholder is in `reference/FIGMA-REFERENCE-NOT-LOCALLY-AVAILABLE.txt`.

## Scope

This package is specifically for independent review of the Non-Resident Maternal Individual View (`health-records.maternal.non-residents.show`). It does not include Child Care, Family Planning, Risk Assessment, Death, Household Profiling, Resident Maternal listing UI, or the Add Non-Resident form except as incidental comments/routes needed to understand `create` vs `{clientKey}` ordering.

## Known Intentional Difference

The production View uses the approved application/listing content width rather
than reproducing the narrower Figma artboard width exactly. Figma remains the
reference for visual hierarchy, card organization, profile presentation,
Maternal Care organization, Pregnancy History, controls, and overall design
intent.

## Production Freeze Status

NOT YET PRODUCTION FROZEN.
PACKAGE CREATED FOR INDEPENDENT REVIEW.
