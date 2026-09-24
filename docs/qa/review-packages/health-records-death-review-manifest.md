# Health Records → Death
## Claude Final Review Package

### Review Status
READY FOR CLAUDE INDEPENDENT REVIEW

NOT YET PRODUCTION-FROZEN.

### Production Files

| File | Why Claude needs it |
|---|---|
| `resources/views/pages/health-records/death.blade.php` | Listing markup for `/health-records/death` |
| `resources/css/pages/health-records-death.css` | Death-scoped layout, cards, filters, table, mobile width |
| `resources/js/pages/health-records-death.js` | Client-side search/zone/cause/sex/year filters |
| `app/Support/HealthRecordsDeath.php` | UI-phase fixture rows and derived summary counts |
| `app/Http/Controllers/HealthRecords/DeathSummaryController.php` | Controller wiring for the listing |
| `resources/views/layouts/dashboard.blade.php` | Dashboard content wrapper and shell composition |
| `resources/css/pages/dashboard.css` | Shared content padding / responsive shell |
| `resources/views/components/lml/dashboard/topbar.blade.php` | Page header / topbar |
| `resources/views/components/lml/dashboard/sidebar.blade.php` | Health Records navigation including Death |
| `resources/views/components/lml/dashboard/sidebar-collapse-children.blade.php` | Health Records child-link rendering / active state |
| `app/Support/UiRole.php` | Sidebar active-key mapping for `health-records.death.*` |

### Test Files

- `tests/Feature/HealthRecordsDeathTest.php`
- `tests/Feature/HouseholdProfilingDeathTest.php`

### Screenshot Evidence

- `death-1440x900.png` — desktop 1440×900
- `death-820x1180.png` — tablet 820×1180
- `death-390x844.png` — mobile 390×844 (full-page capture includes table region)

No separate additional 390 table-scroll screenshot existed beyond `death-390x844.png`.

### Responsive Evidence

- `overflow-report.json` — viewport overflow and geometry measurements
- `capture-hr-death-listing.mjs` — Playwright capture/measurement script used for final evidence

### Test Evidence

**HealthRecordsDeathTest.php**

- Command: `php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsDeathTest.php`
- Exit code: `0`
- Tests: `10`
- Assertions: `113`

**HouseholdProfilingDeathTest.php**

- Command: `php vendor/phpunit/phpunit/phpunit tests/Feature/HouseholdProfilingDeathTest.php`
- Exit code: `0`
- Tests: `14`
- Assertions: `131`

Full PHPUnit output: `review/health-records-death-test-evidence.txt`

### Build Evidence

- Command: `npm run build`
- Exit code: `0`

Full output: `review/health-records-death-build-evidence.txt`

### Final Mobile Measurements

Viewport:
390px

Death outer panel:
left = 8px
right = 382px
width = 374px

Outer margins:
8px / 8px

Shared inner components:
left = 25px
right = 365px
width = 340px

Table viewport:
left = 26px
right = 364px
width = 338px

Description → Export gap:
14px

Mobile table:
scrollWidth = 576
clientWidth = 338

Mobile page:
scrollWidth = 390
clientWidth = 390
overflow = false

### Known Non-Blocking Notes

1. Table viewport is approximately 1px inset on each side because of its border.

2. Global topbar uses shared shell padding and was intentionally not changed.

### Scope Statement

The final mobile width refinement is scoped to Health Records → Death.

No route, controller, database, business-logic, global dashboard-padding, or unrelated Health Records module changes were intentionally made as part of the final width patch.

### Freeze Statement

Health Records → Death is NOT production-frozen by this package.
Production Freeze requires Claude's independent final review verdict.
