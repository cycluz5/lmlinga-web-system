# LMLinga Death Records — Claude Review Package

## Review Scope

This package covers the current Death Records / Death Requests refinement cycle for independent Claude review:

- Health Records → Death Record submission (BHW maker step)
- Requests → Death Requests (admin queue list)
- Requests → Death Requests → Review → Verify Death Record
- Registry No. end-to-end (required, separate from Certificate No.)
- Validation and persistence
- Database migration / additive upgrade path
- Approve / reject workflow and deceased propagation
- Authorization (`ui.admin` middleware, `UiRole` preview auth)
- Sidebar / navigation behavior
- Responsive / accessibility implementation (Blade + CSS + JS)
- Relevant automated regression tests

---

## Authoritative Requirements

### Death Requests List

Desktop/tablet columns must remain:

**Resident Name | Status | Action**

Filters:

- Search Resident
- Status

Must NOT include in the list: Zone filter, Sex, Age, Member ID, Household No., Zone, Date of Death, Cause of Death, Registry No., Certificate No., Submitted By, Submitted On.

Review remains the action.

Mobile uses stacked/card presentation: Resident Name, Status, Review.

### Verify Death Record

Page title: **Verify Death Record**

Subtitle: **Review the submitted death record and supporting certificate.**

Do NOT restore duplicate inner "Verify Death Record" heading.

Preserve: back navigation, status badge, resident identity (name only), Household No., Zone, Cause of Death, Date of Death, **Registry No.**, **Certificate No.**, Submitted By, Submitted On, Death Certificate, Reject, Approve.

Registry No. and Certificate No. are **separate** fields.

Metadata labels: **bold**. Values: normal weight.

Desktop/tablet: two-column metadata layout. Mobile: single left-aligned column.

### Registry No.

- **Required** for new Death Record submissions (`required|string|max:100`)
- Own database column: `registry_no`
- Independent from `certificate_no`
- On submission form, submitted/read-only details, and Verify Death Record
- **NOT** on simplified Death Requests list

### Migration Strategy

- Original create migration must NOT be the upgrade path for Registry No. on existing databases
- Additive migration `2026_08_17_100200_add_registry_no_to_death_requests_table.php` adds the column
- Normal upgrade: `php artisan migrate` (not destructive `migrate:fresh`)

---

## Included Files

| Project-relative path | Why Claude needs it |
|---|---|
| `database/migrations/2026_08_17_100000_create_death_requests_table.php` | Original `death_requests` table schema (restored; no inline `registry_no`) |
| `database/migrations/2026_08_17_100100_create_resident_statuses_table.php` | Deceased status FK to `death_requests`; approve workflow dependency |
| `database/migrations/2026_08_17_100200_add_registry_no_to_death_requests_table.php` | Additive Registry No. migration; upgrade path for existing DBs |
| `app/Models/DeathRequest.php` | Eloquent model, statuses, display helpers, `$fillable` including `registry_no` |
| `app/Models/ResidentStatus.php` | Authoritative deceased row after approval |
| `app/Http/Requests/StoreDeathRecordRequest.php` | Server validation for submission including required `registry_no` |
| `app/Http/Requests/RejectDeathRequestRequest.php` | Rejection reason validation |
| `app/Support/DeathRecordService.php` | Submit / approve / reject business logic and persistence |
| `app/Support/DeathCertificateStorage.php` | Private certificate disk storage and download |
| `app/Support/DemoDeath.php` | Resident/household resolution for Health Records Death routes |
| `app/Support/HealthRecordsDeath.php` | Death listing rows, zones helper, catalog integration |
| `app/Support/ResidentVitalStatus.php` | Deceased label/propagation on approve |
| `app/Support/UiRole.php` | Preview auth roles (`admin`, `bhw`, etc.) used in tests and middleware |
| `app/Http/Controllers/HealthRecords/DeathRecordController.php` | Show / store / certificate download (BHW side) |
| `app/Http/Controllers/HealthRecords/DeathSummaryController.php` | Health Records → Death index listing |
| `app/Http/Controllers/DeathRequests/DeathRequestReviewController.php` | Admin list, verify, approve, reject, certificate |
| `app/Http/Middleware/EnsureAdminRole.php` | `ui.admin` — Death Requests route protection |
| `app/Http/Middleware/PersistUiRole.php` | `ui.role` — session role for dashboard preview |
| `resources/views/pages/health-records/death-record.blade.php` | BHW submission form (Registry No. field), submitted details |
| `resources/views/pages/health-records/death.blade.php` | Health Records Death listing / resident picker |
| `resources/views/pages/death-requests/index.blade.php` | Simplified Death Requests list (3 columns, filters) |
| `resources/views/pages/death-requests/show.blade.php` | Verify Death Record metadata and Reject/Approve |
| `resources/views/components/lml/dashboard/sidebar.blade.php` | Requests → Death Requests and Health Records → Death nav |
| `resources/views/layouts/dashboard.blade.php` | Page title/subtitle shell (authoritative Verify heading) |
| `resources/js/pages/health-records-death-form.js` | Submit gating including Registry No.; confirm dialog |
| `resources/js/pages/health-records-death.js` | Health Records Death listing client behavior |
| `resources/js/pages/death-requests.js` | List filters (search/status only); verify dialogs |
| `resources/js/app.js` | Vite entry importing Death-related page modules |
| `resources/css/pages/death-requests.css` | Verify two-column/mobile metadata; list layout; bold labels |
| `resources/css/pages/health-records-death.css` | BHW death form styling |
| `routes/web.php` | Full route file; Death Requests under `ui.admin`, Health Records Death routes |
| `bootstrap/app.php` | Middleware alias registration (`ui.admin`, `ui.role`) |
| `config/filesystems.php` | `death_certificates` private disk configuration |
| `tests/Feature/DeathRecordSubmissionTest.php` | Submission, validation, Registry No. persistence |
| `tests/Feature/DeathRequestAdminTest.php` | List/verify UI assertions, auth, approve/reject |
| `tests/Feature/DeathRequestsSidebarNavigationTest.php` | Sidebar visibility and active-state regression |
| `tests/Feature/HealthRecordsDeathTest.php` | Health Records Death listing and approved-record display |
| `tests/TestCase.php` | Base test case |
| `phpunit.xml` | PHPUnit configuration for running included tests |

**Not included (dependency noted only):**

- `app/Support/DemoCatalog.php` — large demo household catalog; tests use `HH-151` / `MB-002` (Kristine Reyes, Wife). Resolution is via `DemoDeath::resolveMember()`.
- `resources/css/app.css` — imports `./pages/health-records-death.css` and `./pages/death-requests.css` (lines 58–59).
- `vendor/`, `node_modules/`, `.env`, compiled assets — excluded per security/package rules.

---

## Registry No. Data Flow

1. **Form** — `resources/views/pages/health-records/death-record.blade.php`  
   Input `name="registry_no"`, label **Registry No.**, `old()` repopulation on validation/rejection errors.

2. **Client gating** — `resources/js/pages/health-records-death-form.js`  
   Submit button enabled only when Registry No. (and other required fields) are filled. UX only.

3. **Route** — `POST health-records/death/{householdNo}/{memberId}` → `DeathRecordController::store`

4. **Validation** — `StoreDeathRecordRequest`  
   `registry_no`: `required|string|max:100`

5. **Controller** — `DeathRecordController::store()` passes `$request->validated()` to service.

6. **Service** — `DeathRecordService::submit()` creates `DeathRequest` with `registry_no` and `certificate_no` as separate columns.

7. **Model / database** — `DeathRequest` model; columns `registry_no` and `certificate_no` on `death_requests` table.

8. **Retrieval** — Eloquent `$deathRequest->registry_no` on verify and submitted-details views.

9. **Verify display** — `resources/views/pages/death-requests/show.blade.php`  
   `<dt>Registry No.</dt>` before `<dt>Certificate No.</dt>` in existing metadata `<dl>`.

---

## Migration Strategy

### Original create migration

`database/migrations/2026_08_17_100000_create_death_requests_table.php`

Creates `death_requests` **without** `registry_no` (restored to pre–Registry No. state). Includes `certificate_no`, certificate file metadata, status, review fields, and SQLite partial unique indexes for one pending/approved per member.

### Additive Registry No. migration

`database/migrations/2026_08_17_100200_add_registry_no_to_death_requests_table.php`

- `up()`: adds `registry_no` (`string`, 100) after `date_of_death` if column absent
- Uses `default('')` so existing rows can receive the column non-destructively on SQLite/MySQL
- `hasColumn` guard: no-op if column already exists (transitional environments that ran earlier inline version)
- `down()`: drops only `registry_no`

### How existing databases receive the field

Run:

```
php artisan migrate
```

No `migrate:fresh` required. No table recreate.

### Local migration status (packaging environment)

As of package creation:

```
2026_08_17_100000_create_death_requests_table .............. [2] Ran
2026_08_17_100100_create_resident_statuses_table ........... [3] Ran
2026_08_17_100200_add_registry_no_to_death_requests_table .. [4] Ran
```

`php artisan migrate` was executed successfully (exit code 0) for the additive migration.

### Compatibility / backfill note

Database-level `default('')` allows additive column on tables with existing rows. Application validation still requires non-empty `registry_no` on new submissions. Any legacy rows with empty DB default would display blank Registry No. in Verify until backfilled.

---

## Known Historical Issue Resolved

Registry No. was initially inserted into the original create migration (`2026_08_17_100000_create_death_requests_table.php`). That was corrected:

1. `registry_no` removed from the create migration.
2. Separate additive migration `2026_08_17_100200_add_registry_no_to_death_requests_table.php` created.
3. Existing installations can apply the schema change via normal `php artisan migrate` without destructive reset.

---

## Tests

### Relevant test files

- `tests/Feature/DeathRecordSubmissionTest.php`
- `tests/Feature/DeathRequestAdminTest.php`
- `tests/Feature/DeathRequestsSidebarNavigationTest.php`
- `tests/Feature/HealthRecordsDeathTest.php`

### Latest executed evidence (packaging pass)

**Command:**

```
php vendor/bin/phpunit tests/Feature/DeathRecordSubmissionTest.php tests/Feature/DeathRequestAdminTest.php tests/Feature/DeathRequestsSidebarNavigationTest.php tests/Feature/HealthRecordsDeathTest.php
```

**Exit code:** `0`

**Tests:** `29 passed`

**Assertions:** `268`

Coverage includes: required Registry No., independent persistence vs Certificate No., Verify display, simplified list unchanged, authorization, approve/reject workflow, sidebar navigation.

---

## Build

**Command:**

```
npm run build
```

**Exit code:** `0`

(Vite production build; no source changes during packaging pass.)

---

## Visual Evidence Status

Fresh user visual inspection has been performed for relevant Death pages.

Known fresh visual evidence includes:

- desktop Death Requests
- desktop Verify Death Record
- 390px Death Requests
- 390px Verify Death Record

**Visual screenshots were supplied separately by the user and are not contained in this source-code review package.**

No screenshot files were copied into `claude-death-review/`.

---

## Security / Exclusions

This package excludes: `vendor/`, `node_modules/`, `.git/`, `storage/`, `bootstrap/cache/`, `public/build/`, `.env`, credentials, database dumps, unrelated modules, and generated assets.

Pre-ZIP inspection: no `.env`, API keys, passwords, or private keys included.

---

## Package Metadata

- **Created:** 2026-08-17
- **Purpose:** Independent Claude review — Death Records / Death Requests / Registry No.
- **Status:** ACTIVE REFINEMENT — NOT PRODUCTION FROZEN
