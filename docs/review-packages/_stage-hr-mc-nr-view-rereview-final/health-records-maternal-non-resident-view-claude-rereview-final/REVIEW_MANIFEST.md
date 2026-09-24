# LMLinga Review Package

Module:
Health Records → Maternal → Non-Residents → Individual View

Review stage:
Claude Re-Review After Correction Patch (not production frozen)

This package represents the implementation AFTER the Claude correction
patch and AFTER restoration of the approved green Add Record appearance.

Do not use the earlier ZIP
`health-records-maternal-non-resident-view-review.zip`
(pre-green-fix / pre-F-1–F-7 package).

## Claude findings

F-1 — resolved
F-2 — resolved
F-3 — resolved
F-4 — resolved
F-5 — resolved
F-6 — resolved
F-7 — resolved

### F-1 — Add Record unavailable state (with visual restoration)

The Add Record control remains:

- `disabled`
- `aria-disabled="true"`
- non-interactive (no click handler while disabled; no toast; no navigation)
- no Add Record feature / no fake route

Its approved GREEN visual appearance has been restored (`#16a34a` / `rgb(22, 163, 74)`, white text, `opacity: 1`).

Green appearance does NOT indicate implemented functionality.

### F-2 — Pregnancy row false interactivity

Right-chevron removed from the static pregnancy `<article>`. No pregnancy-detail route was added.

### F-3 — Active pregnancy + delivery type

`pregnancySummary()` includes `delivery_type` only when `is_delivered` is true. Ana (active) does not render CS on the View.

### F-4 — Fabricated clinical fallbacks

`completeValue()` fabricated defaults removed. Missing clinical fields stay empty; they are not filled with invented LMP/G-P/EDD/VD/trimester/visits.

### F-5 — Tests

Focused regressions added in `tests/Feature/HealthRecordsMaternalTest.php`.

### F-6 — Capture selector

`scripts/capture-hr-maternal-nr-show.mjs` measures `.lml-hr-mc-show` (the `__workspace` wrapper no longer exists).

### F-7 — Focus style

`.lml-focus-ring:focus-visible` already exists in `resources/css/base/components.css`. Not modified. That shared file is not duplicated in this package; Claude can treat F-7 as verified-in-repo rather than in-package.

## Implementation Files

- `implementation/maternal-non-residents-show.blade.php`
- `implementation/health-records-maternal.css`
- `implementation/health-records-maternal.js`
- `implementation/HealthRecordsNonResidentMaternal.php`
- `implementation/non-resident-maternal.php`
- `implementation/NonResidentMaternalController.php`
- `tests/HealthRecordsMaternalTest.php`
- `REVIEW_ROUTE_CONTEXT.txt`

## Data Path

`GET /health-records/maternal/non-residents/{clientKey}`
(`health-records.maternal.non-residents.show`)
→ `NonResidentMaternalController::show`
→ `HealthRecordsNonResidentMaternal::findEligible` / `pregnancySummary`
→ `resources/demo/non-resident-maternal.php`
→ `maternal-non-residents-show.blade.php`

## Screenshots

Recaptured after the green Add Record CSS restore. Identity: **Ana P. Villanueva**.

Playwright check on 1440×900: Add Record `backgroundColor rgb(22, 163, 74)`, white text, `disabled true`, `aria-disabled true`. No `lml-hr-mc-show__chevron`. No `lml-hr-mc-show__status-meta`. Profile includes March 12, 1998. Status Active pregnancy.

| Package filename | Viewport | Latest |
|---|---|---|
| `screenshots/desktop-1440x900.png` | 1440×900 | YES |
| `screenshots/tablet-820x1180.png` | 820×1180 | YES |
| `screenshots/mobile-390x844.png` | 390×844 | YES |
| `screenshots/show-ana-*.png` | same | YES (original capture names) |

## Test Evidence

Test file:
tests/Feature/HealthRecordsMaternalTest.php

Command:
php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsMaternalTest.php

Exit code:
0

Tests:
23

Assertions:
347

Full output: `evidence/test-output.txt`

## Build Evidence

Command:
npm run build

Exit code:
0

Full output: `evidence/build-output.txt`

## Responsive Evidence

- `evidence/responsive/overflow-report.json`
- `evidence/responsive/overflow-extra-widths.json`
- `evidence/responsive/desktop-metrics.json`
- `evidence/responsive/listing-desktop-metrics.json`
- `evidence/responsive/width-compare-1440.json`
- `evidence/tools/capture-hr-maternal-nr-show.mjs`

## Figma Reference

FIGMA REFERENCE NOT PRESENT LOCALLY

See `reference/FIGMA-REFERENCE-NOT-LOCALLY-AVAILABLE.txt`.

## Scope

Independent re-review of the Non-Resident Maternal Individual View only.

## Known Intentional Difference

The production View uses the approved application/listing content width rather
than reproducing the narrower Figma artboard width exactly. Figma remains the
reference for visual hierarchy, card organization, profile presentation,
Maternal Care organization, Pregnancy History, controls, and overall design
intent.

The Figma chevron and a fully functional Add Record are NOT reproduced:
chevron was a false affordance; Add Record is disabled (green, non-interactive).

## Production Freeze Status

NOT YET PRODUCTION FROZEN.
PACKAGE CREATED FOR INDEPENDENT CLAUDE RE-REVIEW.
