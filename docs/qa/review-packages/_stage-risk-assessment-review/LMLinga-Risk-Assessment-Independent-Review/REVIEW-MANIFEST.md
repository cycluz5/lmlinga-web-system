# LMLinga Risk Assessment — Independent Review Package

## Review Target
Health Records → Risk Assessment

Named route: `health-records.risk-assessment.index`  
URI: `/health-records/risk-assessment`

## Review Stage
Phase 3.2 — Targeted Figma Proportion + Typography Refinement

## Current Status
IMPLEMENTATION COMPLETE  
READY FOR INDEPENDENT REVIEW  
NOT YET PRODUCTION FROZEN

## Scope
This package supports independent review of the barangay-wide **Health Records → Risk Assessment** page after the Phase 3.2 Figma proportion and typography refinement.

Review focus:
- filter toolbar proportions (Search / Zone / Year)
- Add + Export Data control scale
- summary card styling (Total Assessed Clients + Zone 1–5)
- table header color and typography
- responsive desktop / tablet / mobile presentation
- preservation of functional behavior and production-freeze boundaries

This is **not** the frozen Household Profiling → Member Risk Assessment module.

## Files Included

### Manifest
- `REVIEW-MANIFEST.md`

### Source (original repository paths)
- `resources/views/pages/health-records/risk-assessment.blade.php`
- `resources/css/pages/health-records-risk-assessment.css`
- `resources/js/pages/health-records-risk-assessment.js`
- `app/Http/Controllers/HealthRecords/RiskAssessmentSummaryController.php`
- `app/Support/HealthRecordsRiskAssessment.php`
- `app/Support/UiRole.php` (sidebar active-key mapping for Health Records children)
- `resources/views/components/lml/dashboard/sidebar.blade.php` (named-route resolution for Risk Assessment)
- `resources/views/components/lml/dashboard/sidebar-collapse-children.blade.php`

### Packaging excerpts (derived for review; application source not modified)
- `source/routes/web-risk-assessment-excerpt.php` — excerpt of the Risk Assessment route from `routes/web.php`
- `source/entrypoint-imports.txt` — relevant import lines from `resources/css/app.css` and `resources/js/app.js`

### Tests (original repository paths)
- `tests/Feature/HealthRecordsRiskAssessmentTest.php`
- `tests/Feature/HealthRecordsSidebarNavigationTest.php`
- `tests/Feature/HealthRecordsChildCareSummaryTest.php`
- `tests/Feature/HealthRecordsVitaminATest.php`

### Visual evidence
- `review-evidence/screenshots/desktop/risk-assessment-1440x900.png`
- `review-evidence/screenshots/desktop/risk-assessment-1366x768.png`
- `review-evidence/screenshots/tablet/risk-assessment-820x1180.png`
- `review-evidence/screenshots/tablet/risk-assessment-768x1024.png`
- `review-evidence/screenshots/mobile/risk-assessment-390x844.png`
- `review-evidence/screenshots/mobile/risk-assessment-360x800.png`
- `review-evidence/screenshots/states/risk-assessment-1440x900-empty-filter.png`
- `review-evidence/screenshots/states/risk-assessment-1440x900-add-toast.png`
- `review-evidence/screenshots/states/risk-assessment-1440x900-export-toast.png`

### Figma reference
- `review-evidence/figma-reference/FIGMA-REFERENCE-NOT-LOCALLY-AVAILABLE.txt`

## Files Modified During Final Refinement (Phase 3.2)

Confirmed implementation change for Phase 3.2:

- `resources/css/pages/health-records-risk-assessment.css`

Packaging/evidence helper only (not application runtime):

- `scripts/capture-hr-risk-phase3.mjs` (screenshot capture helper used for evidence; not required for page runtime)

No Phase 3.2 changes to:
- Blade page
- controller
- fixture/support semantics
- routes
- JS behavior
- feature tests (except earlier Phase 3 header-sublabel assertion adjustments already present in the included test file)

## Test Evidence

Verified immediately before packaging:

### Risk Assessment
- Command: `php artisan test --compact tests/Feature/HealthRecordsRiskAssessmentTest.php`
- Test file(s): `tests/Feature/HealthRecordsRiskAssessmentTest.php`
- Exit code: `0`
- Passed: `8`
- Assertions: `92`

### Sidebar Navigation
- Command: `php artisan test --compact tests/Feature/HealthRecordsSidebarNavigationTest.php`
- Test file(s): `tests/Feature/HealthRecordsSidebarNavigationTest.php`
- Exit code: `0`
- Passed: `14`
- Assertions: `195`

### Child Care + Vitamin A regression
- Command: `php artisan test --compact tests/Feature/HealthRecordsChildCareSummaryTest.php tests/Feature/HealthRecordsVitaminATest.php`
- Test file(s):
  - `tests/Feature/HealthRecordsChildCareSummaryTest.php`
  - `tests/Feature/HealthRecordsVitaminATest.php`
- Exit code: `0`
- Passed: `19`
- Assertions: `127`

## Build Evidence

Verified immediately before packaging:

- Command: `npm run build`
- Exit code: `0`
- Result: Vite production build succeeded (`✓ 147 modules transformed`, CSS/JS assets emitted)
- Warning (non-blocking): `npm warn Unknown env config "devdir". This will stop working in the next major version of npm.`

## Responsive Evidence

Included Phase 3.2 captures from `docs/qa/screenshots/health-records-risk-assessment-phase3/`:

| Viewport | File |
|---|---|
| 1440×900 | `review-evidence/screenshots/desktop/risk-assessment-1440x900.png` |
| 1366×768 | `review-evidence/screenshots/desktop/risk-assessment-1366x768.png` |
| 820×1180 | `review-evidence/screenshots/tablet/risk-assessment-820x1180.png` |
| 768×1024 | `review-evidence/screenshots/tablet/risk-assessment-768x1024.png` |
| 390×844 | `review-evidence/screenshots/mobile/risk-assessment-390x844.png` |
| 360×800 | `review-evidence/screenshots/mobile/risk-assessment-360x800.png` |
| Empty filter (1440×900) | `review-evidence/screenshots/states/risk-assessment-1440x900-empty-filter.png` |
| Add toast (1440×900) | `review-evidence/screenshots/states/risk-assessment-1440x900-add-toast.png` |
| Export toast (1440×900) | `review-evidence/screenshots/states/risk-assessment-1440x900-export-toast.png` |

## Functional Scope Confirmation

Phase 3.2 refinement changed **presentation CSS only**.

Inspection supports these were **not** changed by Phase 3.2:

- controller behavior: unchanged
- route behavior: unchanged
- database behavior: unchanged (no migrations/schema/persistence)
- filtering behavior: unchanged (client-side Search/Zone/Year intersection)
- Export behavior: unchanged (UI-phase toast)
- Add behavior: unchanged (UI-phase toast directing to Household Profiling member flow)
- fixture values: unchanged (Total = 8; Zones = 2 / 2 / 2 / 1 / 1)
- business logic: unchanged

## Known Intentional Figma Deviations

- Established Health Records shell/in-card title pattern remains (topbar title + in-panel title)
- Fixture/demo totals differ from the Figma sample (`8` / zone counts vs sample `60` / `0`)
- Exact pixel parity may vary slightly because of browser/font rendering

## Production-Freeze Boundary

Household Profiling → Member Risk Assessment remains production frozen and is **not** included in this package as a review target.
