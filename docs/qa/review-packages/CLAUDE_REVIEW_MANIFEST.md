# CLAUDE REVIEW MANIFEST (v2 — F1 correction)

## Scope

Health Records → Risk Assessment

## Review stage

Final independent review before Production Freeze — **corrected package after Claude F1**

**Not** Production Frozen.

## F1 correction summary

Claude F1: previous ZIP omitted `tests/js/support/sidebar-mini-dom.mjs`, which `tests/js/dashboard-sidebar.test.mjs` imports. The helper **already existed** in the repository and was not fabricated.

This v2 package includes that helper and re-captured raw test/build evidence from commands executed during this correction.

## Production files included

### Health Records listing (primary)

- `resources/views/pages/health-records/risk-assessment.blade.php`
- `resources/css/pages/health-records-risk-assessment.css`
- `resources/js/pages/health-records-risk-assessment.js`
- `app/Support/HealthRecordsRiskAssessment.php`
- `app/Http/Controllers/HealthRecords/RiskAssessmentSummaryController.php`

### Eligibility / resident source dependencies

- `app/Support/DemoCatalog.php`
- `resources/demo/households.php`

### Household Profiling History / View / Edit / Date Filter (regression)

- `app/Http/Controllers/HouseholdProfiling/RiskAssessmentHistoryController.php`
- `app/Support/DemoRiskAssessment.php`
- `app/Http/Requests/UpdateRiskAssessmentSectionRequest.php`
- `resources/demo/risk-assessments.php`
- `resources/views/pages/household-profiling/risk-assessment-history.blade.php`
- `resources/views/pages/household-profiling/risk-assessment-show.blade.php`
- `resources/views/pages/household-profiling/risk-assessment-form.blade.php`
- `resources/views/pages/household-profiling/risk-assessment-section.blade.php`
- `resources/views/pages/household-profiling/partials/risk-assessment-member-card.blade.php`
- `resources/js/pages/risk-assessment.js`
- `resources/css/pages/risk-assessment.css`

### Sidebar / navigation

- `resources/views/components/lml/dashboard/sidebar.blade.php`
- `resources/views/components/lml/dashboard/sidebar-collapse-children.blade.php`
- `resources/js/pages/dashboard-sidebar.js`
- `app/Support/UiRole.php`

### Routes / asset entrypoints

- `routes/web.php`
- `resources/css/app.css`
- `resources/js/app.js`

## Test files included

- `tests/Feature/HealthRecordsRiskAssessmentTest.php`
- `tests/Feature/HouseholdProfilingRiskAssessmentTest.php`
- `tests/Feature/HouseholdProfilingRiskAssessmentHistoryViewEditTest.php`
- `tests/Feature/HealthRecordsSidebarNavigationTest.php`
- `tests/js/risk-assessment.test.mjs`
- `tests/js/dashboard-sidebar.test.mjs`
- `tests/js/support/sidebar-mini-dom.mjs` **(F1 fix — required import helper)**

## Evidence included

### Final alignment screenshots

- `docs/qa/screenshots/health-records-risk-assessment-final-alignment/` (1440 / 820 / 390 / header crop / layout-measurements.json)

### Header-separator prior evidence

- `docs/qa/screenshots/health-records-risk-assessment-header-separator/`

### Raw command evidence (captured during F1 correction)

- `review-evidence/risk-assessment-js-test-output.txt`
- `review-evidence/risk-assessment-build-output.txt`
- `review-evidence/risk-assessment-phpunit-HealthRecordsRiskAssessmentTest.txt`
- `review-evidence/risk-assessment-phpunit-HouseholdProfilingRiskAssessmentTest.txt`
- `review-evidence/risk-assessment-phpunit-HouseholdProfilingRiskAssessmentHistoryViewEditTest.txt`
- `review-evidence/risk-assessment-phpunit-HealthRecordsSidebarNavigationTest.txt`

## Figma reference

**Not included in ZIP** — must be attached separately to Claude (Claude F2). Not fabricated.

## Final test evidence (executed during this F1 correction)

### JavaScript

- **Command:** `node --test tests/js/risk-assessment.test.mjs tests/js/dashboard-sidebar.test.mjs`
- **Exit code:** `0`
- **Tests:** `15`
- **Passed:** `15`
- **Failed:** `0`
- **Helper path:** `tests/js/support/sidebar-mini-dom.mjs` (exists in repository; included in ZIP)

### Build

- **Command:** `npm run build`
- **Exit code:** `0`
- Non-blocking warning captured in evidence: `npm warn Unknown env config "devdir"`

### PHPUnit

| Test file | Exact command | Exit | Passed | Assertions |
|---|---|---|---|---|
| `tests/Feature/HealthRecordsRiskAssessmentTest.php` | `php vendor/bin/phpunit --testdox tests/Feature/HealthRecordsRiskAssessmentTest.php` | 0 | 18 | 191 |
| `tests/Feature/HouseholdProfilingRiskAssessmentTest.php` | `php vendor/bin/phpunit tests/Feature/HouseholdProfilingRiskAssessmentTest.php` | 0 | 20 | 168 |
| `tests/Feature/HouseholdProfilingRiskAssessmentHistoryViewEditTest.php` | `php vendor/bin/phpunit tests/Feature/HouseholdProfilingRiskAssessmentHistoryViewEditTest.php` | 0 | 17 | 82 |
| `tests/Feature/HealthRecordsSidebarNavigationTest.php` | `php vendor/bin/phpunit tests/Feature/HealthRecordsSidebarNavigationTest.php` | 0 | 14 | 197 |

## Important Risk Assessment contracts

1. Only Household Profiling residents who have **reached age 19** are eligible.
2. Age **18** → excluded.
3. Day **before** the 19th birthday → excluded.
4. Exact **19th birthday** → included.
5. Age **> 19** → included.
6. Eligibility derived from **DOB / birthday**, not a stale stored age field.
7. Listing **Add** button is removed.
8. **Export Data** retained (UI-phase toast).
9. **Search / Zone / Year** retained.
10. Desktop filter ratio approximately **40% / 30% / 30%**.
11. **Full Name** wider than individual status columns.
12. **BMI Status → Chronic Disease** have title/options horizontal separators.
13. **Full Name** does **not** have that separator.
14. Vertical column borders retained.
15. History / View / Edit must remain unchanged.
16. Date Filter must remain unchanged.

## Known limitations

1. UI-phase listing (preview status vocabulary; not persisted clinical records).
2. Export Data is toast-only.
3. Add intentionally absent from barangay listing.
4. Summary totals reflect eligible demo residents (8), not Figma sample 60.
5. Figma reference not in-repo; attach separately (F2).

## Security / exclusion statement

Excluded: `.env`, credentials/secrets, `vendor/`, `node_modules/`, `.git/`, unrelated modules.

## Packaging integrity statement

No production implementation files were modified during this F1 correction/packaging.

Packaging/review artifacts only:

- updated `CLAUDE_REVIEW_MANIFEST.md` (v2 contents)
- raw evidence text files under `docs/qa/review-packages/`
- `LMLinga_Health_Records_Risk_Assessment_Claude_Final_Review_v2.zip`
