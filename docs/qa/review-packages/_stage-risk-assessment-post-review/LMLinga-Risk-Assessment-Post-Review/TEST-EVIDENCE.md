# Test Evidence — Risk Assessment Post-Review Patch

Evidence recorded from the actual executions performed after the RA-F1–RA-F3 targeted patch.

## Risk Assessment

- Command: `php artisan test --compact tests/Feature/HealthRecordsRiskAssessmentTest.php`
- Test file(s): `tests/Feature/HealthRecordsRiskAssessmentTest.php`
- Exit code: `0`
- Passed: `8`
- Assertions: `92`

## Sidebar

- Command: `php artisan test --compact tests/Feature/HealthRecordsSidebarNavigationTest.php`
- Test file(s): `tests/Feature/HealthRecordsSidebarNavigationTest.php`
- Exit code: `0`
- Passed: `14`
- Assertions: `195`

## Child Care + Vitamin A Regression

- Command: `php artisan test --compact tests/Feature/HealthRecordsChildCareSummaryTest.php tests/Feature/HealthRecordsVitaminATest.php`
- Test file(s):
  - `tests/Feature/HealthRecordsChildCareSummaryTest.php`
  - `tests/Feature/HealthRecordsVitaminATest.php`
- Exit code: `0`
- Passed: `19`
- Assertions: `127`

## Build

- Command: `npm run build`
- Exit code: `0`
- Result: Vite success (`147` modules transformed)
- Warning (non-blocking): `npm warn Unknown env config "devdir". This will stop working in the next major version of npm.`
