# REVIEW MANIFEST

## Review subject

LMLinga — Health Records → Maternal Care — Remove Non-Residents user-facing destination  
Evidence / independent Claude review package (pre-production-freeze)

## Approved targeted change

The resident Maternal Care listing must no longer expose a user-facing Non-Residents destination/control.

Remove (and do not replace):

- the "Non Residents" pill/link
- its href to the Non-Residents listing
- its aria-label
- `data-hr-mc-non-residents`
- the now-unused left-side action wrapper

Do not introduce Residents/Non-Residents tabs, a replacement button, a new sidebar destination, or another navigation mechanism to the legacy Non-Resident pages.

Resident Maternal functionality must remain unchanged.

## What existed before

The resident page was **not** a Residents | Non-Residents tab pair. The only user-facing entry from that page was a left-side scope pill:

- `href="{{ route('health-records.maternal.non-residents.index') }}"`
- class `lml-hr-mc__scope-pill`
- `data-hr-mc-non-residents`
- `aria-label="Open Maternal Care Non Residents listing"`
- visible text: Non Residents
- wrapper: `.lml-hr-mc__action-left`

Sidebar Health Records → Maternal pointed only at `health-records.maternal.index`.

## Exact production files inspected

- `resources/views/pages/health-records/maternal.blade.php`
- `resources/views/pages/health-records/maternal-non-residents.blade.php`
- `resources/views/pages/health-records/maternal-non-residents-create.blade.php`
- `resources/views/pages/health-records/maternal-non-residents-show.blade.php`
- `resources/views/pages/health-records/partials/maternal-listing-table.blade.php`
- `resources/views/components/lml/dashboard/sidebar.blade.php`
- `resources/css/pages/health-records-maternal.css`
- `resources/js/pages/health-records-maternal.js`
- `resources/js/pages/health-records-maternal-add.js`
- `routes/web.php`
- `app/Http/Controllers/HealthRecords/MaternalSummaryController.php`
- `app/Http/Controllers/HealthRecords/NonResidentMaternalController.php`
- `app/Http/Requests/StoreNonResidentMaternalClientRequest.php`
- `app/Support/HealthRecordsMaternal.php`
- `app/Support/HealthRecordsNonResidentMaternal.php`
- `app/Support/UiRole.php`
- `resources/demo/non-resident-maternal.php`
- `tests/Feature/HealthRecordsMaternalTest.php`
- `tests/Feature/HealthRecordsSidebarNavigationTest.php`

Household Profiling maternal-care views/controllers were inspected by name only and are **not** this Health Records listing.

## Exact production files modified (the patch; not this packaging task)

- `resources/views/pages/health-records/maternal.blade.php`

## Exact test files modified (the patch)

- `tests/Feature/HealthRecordsMaternalTest.php`

## QA artifacts added (not production runtime)

- `scripts/capture-hr-maternal-hide-nr-nav.mjs`
- screenshot folders under `docs/qa/`

## Exact UI/navigation removed

See git-diff.txt. Resident listing no longer contains `$nonResidentsUrl`, `.lml-hr-mc__action-left`, or the Non Residents `<a>`.

## Resident functionality preserved

Heading Maternal Care, page description, Add, Export Data, five summary cards, search/zone/year filters, resident table columns (name, age group, LMP, G/P, EDD, delivery type, trimester, prenatal visits). Add/Export remain toast-phase on the resident listing. Clinical support class and `MaternalSummaryController` were not edited.

## Legacy Non-Resident implementation intentionally preserved

Routes (still URL-reachable):

- `health-records.maternal.non-residents.index`
- `health-records.maternal.non-residents.create`
- `health-records.maternal.non-residents.store`
- `health-records.maternal.non-residents.show`

Controller, request class, support class, demo fixture, listing/create/show views, shared listing table (View column only when `showClientView` is true — NR listing only), shared CSS/JS.

Tests still GET/POST those routes (`HealthRecordsMaternalTest.php`).

## Exact tests executed during packaging

1. File: `tests/Feature/HealthRecordsMaternalTest.php`  
   Command: `php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsMaternalTest.php`  
   Exit code: 0  
   Result: OK (23 tests, 349 assertions)  
   Full output: `03-evidence/test-results.txt`

2. File: `tests/Feature/HealthRecordsSidebarNavigationTest.php`  
   Command: `php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsSidebarNavigationTest.php`  
   Exit code: 0  
   Result: OK (14 tests, 200 assertions)  
   Full output: `03-evidence/sidebar-navigation-test-results.txt`

There is no separate `HealthRecordsNonResidentMaternalTest.php`; NR Maternal coverage lives in the same Maternal feature test file.

## Exact build executed during packaging

Command: `npm run build`  
Exit code: 0  
Vite 6.4.3 — 155 modules transformed — `✓ built in 4.95s`  
Full captured output: `03-evidence/build-results.txt`  
(PowerShell may wrap npm stderr as `NativeCommandError`; the Vite build completed with exit 0.)

## Responsive / overflow

See `04-screenshots/` and `layout-measurements.json`.

| Viewport | scrollWidth | clientWidth | PAGE-LEVEL overflow | Internal table scroll |
|---|---|---|---|---|
| 1440 | 1440 | 1440 | false | false |
| 1366 | 1366 | 1366 | false | false |
| 820 | 820 | 820 | false | true (843 > 741) |
| 390 | 390 | 390 | false | true (843 > 327) |

## Navigation verification

- Health Records collapse remains; Maternal is the active child (`aria-current="page"`).
- Maternal sidebar href: `/health-records/maternal` (resident index).
- Resident listing: no NR pill, no NR href, no replacement tab/button.
- Mobile 390px: hamburger + listing; no NR control.
- Residual paths (legacy, not from resident nav): direct NR URLs; NR listing View → show.

## Accessibility verification

See `03-evidence/accessibility-evidence.txt`. Remaining action group and table labelling remain valid. Unused `.lml-hr-mc__scope-pill` CSS was left in place on purpose.

## Regression verification

See `03-evidence/regression-evidence.txt`. Dirty tree contains unrelated Child Care / Family Planning / Dashboard work. Reviewer must not treat those as this Maternal patch.

## Git / scope

- Branch: `main` (packaging snapshot)
- Scoped implementation diff: `03-evidence/git-diff.txt`
- Full `git status`: `03-evidence/git-status.txt`

## Screenshot provenance

`03-evidence/screenshot-provenance.txt`

## Known limitations

- Legacy NR pages remain reachable by URL; this was required, not a defect of the patch.
- Shared CSS still contains unused scope-pill rules.
- Working tree is dirty with unrelated modules; only two production/test files belong to this patch.
- `npm run build` output in evidence includes a PowerShell stderr wrapper; exit code was 0.
- Capture used system Chrome via Playwright `channel: 'chrome'`.

## Production Freeze

THIS PACKAGE DOES NOT DECLARE PRODUCTION FREEZE.

Production Freeze requires the independent Claude verdict.
