# LMLinga — Resident Chatbot Flow

This section documents the end-to-end flow for the **resident-facing Smart Health Chatbot** (as opposed to the staff/admin side of the app). It reflects the actual routes and controllers in this codebase, not a planned/ideal design.

## 1. Entry & Account Access

| Step | Route (name) | Controller | Notes |
|---|---|---|---|
| Landing page | `GET /chatbot` (`chatbot.landing`) | — (static view) | Public entry point, links to Register / Login. |
| Register | `GET /chatbot/register` (`chatbot.register`) | — (static view) | Form: first/middle/last name, zone (1–5), email, password. |
| Register submit | `POST /chatbot/register` (`chatbot.register.store`) | `ResidentRegistrationController@store` | Creates a `resident_accounts` row. Registering does **not** by itself link the account to an official household resident — see Household Verification below. |
| Login | `GET /chatbot/login` (`chatbot.login`) | — (static view) | |
| Login submit | `POST /chatbot/login` (`chatbot.login.store`) | `ResidentLoginController@store` | Sets `ResidentAuthenticator::SESSION_ACCOUNT_ID` in session on success. |
| Logout | `POST /chatbot/logout` (`chatbot.logout`) | `ResidentLoginController@destroy` | Clears only the resident's session keys (a staff/admin session sharing the same browser is left intact) and regenerates the CSRF token. |
| Forgot / reset password | `GET/POST /chatbot/forgot-password`, `GET/POST /chatbot/reset-password` | `ResidentForgotPasswordController`, `ResidentResetPasswordController` | Standard token-based reset, emailed to the account. |

**Auth guard:** every route below the landing/register/login pages is protected by the `resident.chatbot` middleware (`EnsureResidentChatbotAccount`), which checks `session(SESSION_ACCOUNT_ID)` and redirects to `chatbot.login` if missing or if the account no longer exists. Authenticated pages also carry the `auth.nocache` middleware so a browser can't serve a stale, back-button-cached copy of the chat UI after logout.

## 2. Main Chat Page

`GET /chatbot/main` (`chatbot.main`) → `ChatbotMainController@show`

Renders the chat shell: greeting, language toggle (English / Tagalog / Bikol-Iriga), New Chat, Chat History (Pinned / Recent conversations), the Notifications bell, and the **Access Household Record** button. Conversation list and notification badges are loaded via:

- `GET /chatbot/conversations` (`chatbot.conversations`) — sidebar list.
- `GET /chatbot/conversation/{id}/history` (`chatbot.conversation.history`) — reopen a past conversation.
- `DELETE /chatbot/conversation/{id}` (`chatbot.conversation.destroy`)
- `PATCH /chatbot/conversation/{id}/pin` (`chatbot.conversation.pin`)
- `POST /chatbot/notifications/{id}/read` (`chatbot.notifications.read`) — marks a notification read when opened.

Notifications are populated when staff post an Announcement; `AnnouncementNotificationService` fans a row out to every matched resident's account, snapshotting the announcement's Who / Where / Date / Time onto the notification row at that moment.

## 3. Asking the Chatbot a Question

`POST /chatbot/ask` (`chatbot.ask`) → `ChatbotController@ask` → `RagService::ask()`

1. **Language detection** — the selected language (en/tl/bcl) can be overridden if the question text looks like a different language.
2. **Conversation resolve** — a per-language conversation service (`BikolConversationService` / `TagalogConversationService` / `EnglishConversationService`) first checks for small talk ("hello", "kumusta") and answers it directly ("canned"), bypassing retrieval entirely.
3. **Retrieval** — for a real health question, `RagService::retrieve()` searches the `health_chunks` table (indexed from `storage/app/health_docs/{en,tl,bcl}/...txt` via `php artisan health:index`) using keyword matching first, then embedding similarity as a fallback (via a local Ollama instance).
4. **Topic identity gate** — a retrieved document is only used if it is genuinely *about* the asked topic (matched by title/heading, not just a body mention). This deliberately prevents the bot from answering, e.g., "what is fever?" by paraphrasing an unrelated vaccination document that merely mentions fever in passing.
5. **Answer generation** — English and Tagalog pass the matched content to the chat model (`gemma4:31b-cloud` via Ollama) to generate a natural-language answer. **Bikol-Iriga is extractive only** — it never calls the chat model; it pulls the answer directly from a matching document heading, to avoid language drift.
6. **Intent recognition** differs slightly per language — English/Tagalog distinguish Definition / Symptoms / Causes / Prevention / Treatment / Benefits; Bikol merges Prevention and Treatment into one `management` intent and additionally recognizes `warning` (urgent/unsafe-symptom phrasing) and `schedule` (age/month eligibility questions).
7. If no on-topic content is found, the bot returns a canned "I don't have enough information" message (localized per language) rather than guessing.

Both the user's question and the bot's reply are persisted as rows in `chatbot_messages`, grouped under a `chatbot_conversations` row.

## 4. Household Record Access (Verification)

The **Access Household Record** button starts a verification flow so a resident can prove they belong to a specific household before seeing any real household/member data:

`GET/POST /chatbot/household/verification` (`chatbot.household.verification`, `.store`) → `HouseholdRecordRequestController`
→ `GET /chatbot/household/verification/method` (`.otp-method`) — choose SMS or email
→ SMS: `/chatbot/household/verification/sms` (`.sms`, `.sms.send`, `.sms.verify`)
→ Email: `/chatbot/household/verification/email` (`.email`, `.email.send`, `.email.verify`)
→ `GET /chatbot/household/verification/status` (`.status`) — polls approval state

A `record_requests` row tracks status (pending / approved / denied) plus the matched resident. Once approved **and** OTP-verified, `resident_accounts.resident_id` is linked to the matched `residents` row (`HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess()` is the single source of truth for "is this account allowed to see household data").

## 5. Viewing Household & Member Records (read-only)

Once verified:

- `GET /chatbot/household` (`chatbot.household.information`) — `HouseholdInformationController`. Lists every member of the resident's own household as a card (name, relationship, age, a per-card nutrition summary).
- `GET /chatbot/household/members/{member}` (`chatbot.household.members.show`) — `HouseholdMemberInformationController`. Per-member detail page: **Personal Information**, **Socio-Economic Details**, **Health & Welfare**. (This page intentionally does *not* duplicate Health Summary Records / Nutritional Status — that summary already lives on the household cards page above.)
- Member-scoped health modules, still directly reachable by URL for a same-household member even though the member-detail page no longer links to them:
  - `GET .../child-care` (`chatbot.household.members.child-care`)
  - `GET .../risk-assessment` (`chatbot.household.members.risk-assessment`)
  - `GET .../family-planning` (`chatbot.household.members.family-planning`)
  - `GET .../maternal` (`chatbot.household.members.maternal`) — only ever linked for female members.

All of the above enforce same-household ownership server-side (`ChatbotHouseholdMemberAccess::resolveAuthorizedMember`) — requesting a member from a different household, an invalid ID, or a soft-deleted resident returns 404, regardless of what the UI shows.

## 6. Session Security Notes

- The resident session and the staff/admin session are independent; logging out of the chatbot never affects a staff session in the same browser, and vice versa.
- `/chatbot/main` and every route under it require an active resident session (`resident.chatbot` middleware) — a logged-out visitor is redirected to `chatbot.login`, and `auth.nocache` headers stop the browser from serving a cached authenticated page after logout.
- CSRF tokens are refreshed on every fresh page load; the login and chat pages are marked `no-store` so a stale, back-button-restored copy of the page can't submit an expired token.

## 7. Admin Side

The Admin side is the same authenticated staff dashboard as Health Worker (see below) plus one extra, admin-only middleware gate around a specific subset of routes — there is no separate admin login or admin dashboard page.

**Middleware stack:** every staff route (Admin and Health Worker alike) sits under `['auth', 'staff.account-usable', 'staff.password-current', 'ui.role', 'auth.nocache']`. Admin-only routes are additionally wrapped in `ui.admin` (`EnsureAdminRole`), which normalizes the logged-in user's role via `StaffRole::normalize()` and aborts with a 403 ("Administrator access is required for this module.") if it isn't `admin`. `PersistUiRole` (`ui.role`) only keeps the sidebar's displayed role in sync — by its own docblock it "never grants authorization."

**Staff roles** (`App\Support\StaffRole`): `admin`, `bhw` (Barangay Health Worker), `bns` (Barangay Nutrition Scholar), `bspo` (Barangay Service Point Officer). Only `admin` can reach the routes below; the other three roles share identical access to everything in the Health Worker section.

### User Management (admin-only)

| Route name | Method + URI | Controller@method |
|---|---|---|
| `user-management.index` | GET `/user-management` | `Admin\HealthWorkerAccountController@index` |
| `user-management.health-workers.create` / `.store` | GET/POST `/user-management/health-workers[/create]` | `@create` / `@store` |
| `user-management.health-workers.edit` / `.update` | GET/PUT `/user-management/health-workers/{id}[/edit]` | `@edit` / `@update` |
| `user-management.health-workers.view` | GET `/user-management/health-workers/{id}/view` | `@show` |
| `user-management.health-workers.activate` / `.deactivate` | POST `/user-management/health-workers/{id}/activate\|deactivate` | `@activate` / `@deactivate` |
| `user-management.health-workers.destroy` | DELETE `/user-management/health-workers/{id}` | `@destroy` |
| `user-management.residents.view` / `.edit` / `.update` / `.destroy` | `/user-management/residents/{id}/...` | `Admin\ResidentAccountController` |

**Health worker account rules** (`HealthWorkerAccountService`), all enforced server-side regardless of what the UI allows:
- Creating an account only persists the "slim" fields (name/email/mobile/username/password/status/role) plus an incomplete appointment stub — it never invents placeholder values for the rest of the profile, and throws a validation error if a NOT-NULL column is left unfilled.
- An `admin`-role account **cannot** be activated, deactivated, or deleted through this controller ("Administrator accounts cannot be activated/deactivated from this action.").
- An admin cannot deactivate or delete their own account.
- The **last remaining active Admin** in the system cannot be deleted, demoted, or deactivated (`assertMayLoseActiveAdmin()` / `countOtherActiveAdmins()`) — this prevents the system from ever being left with zero admins.
- Demo/catalog rows (non-numeric `hw-*` IDs) are read-only through this controller; only real, numeric-ID database users can be mutated.

### Also admin-only

`household-requests.*` (review/approve incoming household-verification requests from residents) and `death-requests.*` (review/approve/reject death record submissions, including certificate generation) live in the same `ui.admin`-gated route group as User Management.

### Announcements (not admin-only)

Despite being posted from the same dashboard, `announcements.*` (`Announcements\AnnouncementController`) is reachable by **any** staff role, not just admins — `destroy()` does its own internal `UiRole` check rather than relying on route middleware. Composing an announcement selects a target audience (all residents, an age band/preset, or specific zones); `AnnouncementStoreService` computes an estimated reach live (`POST /announcements/reach-preview`) via `AnnouncementAudienceMatcher`, then on save fans a notification out to every matched resident's portal account (`AnnouncementNotificationService::fanOut()` — the same mechanism documented in the Resident Chatbot section above).

## 8. Health Worker Side (Household Profiling)

This is the core staff workflow: front-line health workers (any of `bhw`/`bns`/`bspo`, and also reachable by `admin`) create and maintain the actual health records for households and residents. It sits under the same shared staff middleware as Admin, but is **not** gated by `ui.admin` — no role-specific restriction exists at the route or controller level within this module; the few `abort(403, ...)` calls found are clinical-eligibility checks (e.g. "Maternal Care is only available for female residents"), not permission checks.

### Entry point

`GET /household-profiling` (`household-profiling.index`) → `HouseholdProfilingController@index` lists every household (search/zone-filter is client-side JS over the rendered rows). Selecting one opens `household-profiling.view` (`@show`) listing its members; selecting a member opens `household-profiling.members.show` (`HouseholdMemberController@show`) — the per-member profile page that links into every module below.

### Modules

| Module | Controller | What it manages |
|---|---|---|
| Household list/CRUD | `HouseholdProfilingController` | Household create/edit/delete, zone-filtered PDF report export. |
| Household Member | `HouseholdMemberController` | Add a resident to a household; the member profile page itself. |
| Household Amenities | `HouseholdAmenitiesController` | The household's environmental/amenities profile (water supply, toilet, waste disposal). |
| Child Immunization | `ChildImmunizationController` + `ChildBirthHistoryController` | BCG/Hepa B/DPT-HIB-HepB/OPV/IPV/PCV/MMR schedule and birth history (drives FIC/CIC status). |
| School-Based Immunization | `SchoolBasedImmunizationController` | School-age immunization doses. |
| Adult Immunization | `AdultImmunizationController` | Adult immunization records; enforces adult-eligibility. |
| Child Nutrition | `ChildNutritionController` | Child-specific nutrition indicators. |
| Nutritional Status | `NutritionalStatusController` | Age-adaptive nutrition assessment for *any* age (infant through elderly), classified from birthday + measurement date. |
| Deworming | `DewormingRecordController` | Deworming administration records. |
| Risk Assessment | `RiskAssessmentHistoryController` | Sectioned risk-assessment history (create, then edit by section). |
| Family Planning | `FamilyPlanningController` | Family-planning visit records; enforces eligibility. |
| Maternal Care | `MaternalCareController` | Pregnancy registration, trimester prenatal visits, immunizations, supplementation, labs, delivery/outcome, postnatal care, pregnancy history; enforces female-only and workflow eligibility. |
| Death | `DeathController` | Resident death information (currently session/preview state only — no permanent DB persistence or file uploads yet). |

A separate, broader **Environmental Health dashboard** (`EnvironmentalHealthDashboardController`, `HouseholdWaterSupplyController`, etc.) exists as its own top-level route group alongside — not inside — `/household-profiling`; it is a distinct reporting module from the per-household Amenities tab above.

### ERD mode vs. legacy mode

Most modules have a matching `App\Support\*ErdMode` class (e.g. `MaternalCareErdMode`, `ChildImmunizationErdMode`). There is no config flag — `isActive()` detects the mode at runtime purely via schema introspection:

```php
self::$active = Schema::hasTable('child_immunization') && ! Schema::hasTable('child_immunizations');
```

If the new normalized ERD table exists and the old legacy table doesn't, the module reads/writes the ERD schema; otherwise it falls back to legacy. This lets already-migrated households use the authoritative new schema while anything not yet migrated keeps working against the old one, without a manual switch — but it also means a half-run migration (a guarded migration that was never executed) can silently leave a module in legacy mode. See the "known migration-tracking gap" note in section 9.

## 9. Offline Mode

Staff using Household Profiling in the field may lose connectivity; the app has a dedicated offline-first subsystem so work isn't lost.

### How it works, end to end

1. **Pre-caching (while online):** `GET /offline/household-profiling-bootstrap` returns a full JSON snapshot of every household/member, which the browser stores in IndexedDB (`offline-hp-store.js`). A service worker (`resources/js/offline/offline-sw.js`, served as `/sw.js`) separately pre-warms and caches the HTML "shell" of a fixed allowlist of safe pages (dashboard, household-profiling list/create/edit/member views, announcements, environmental health, etc.) per the current user's role — CSRF tokens are stripped from cached HTML before storage.
2. **Working offline:** Opening a whitelisted household-profiling form (create/edit household, add/edit member, deworming, risk assessment, family planning, maternal care, etc.) still works from the cached shell + IndexedDB data. Submitting a form doesn't POST directly — it's captured into a local **sync queue** (`offline-queue.js`) as an operation record with a generated UUID `operation_id`, the payload, and a `base_snapshot` hash of the fields being edited. Secrets (passwords, tokens, session data) are explicitly guarded against ever entering this queue.
3. **Reconnecting:** `offline-replay.js` probes `GET /offline/status` to confirm the session/CSRF are still valid, then replays each queued operation to `POST /offline/sync`, one at a time.
4. **Server-side apply (`OfflineSyncService`):** each operation is applied inside a DB transaction. Before applying an *update*, the server recomputes a current field hash (`OfflineFieldHasher`, covering only the editable fields of the household/resident/amenities forms) and compares it to the `base_snapshot` hash the client cached when the record was opened. A mismatch means someone else changed that record on the server while this device was offline — the sync is rejected with `TARGET_CHANGED` rather than silently overwriting newer data, surfacing an "attention" state to the health worker instead.
5. **Idempotency:** every successful apply writes an `offline_sync_receipts` row (unique `operation_id`, a payload hash, and the exact JSON response that was returned). If the same operation is ever resubmitted (retry after timeout, double-tap), the server replays the stored result instead of re-applying it — this is also the concurrency safety net if two requests for the same new operation race each other.
6. **New-record ID reconciliation:** a resident created offline gets a temporary local ID (`MB-L-...`); once that create operation successfully syncs, the response's real server ID is used to patch any other still-queued operations that referenced the temporary one.

### What is and isn't offline-capable

Offline-capable: household create/edit, amenities edit, member create/edit, child-immunization birth history, deworming, risk assessment (create + sub-section edits), family planning, maternal-care registration. **Not** offline-capable (explicitly rejected by the server if attempted): health-worker account **creation** (only editing an existing one is allowed offline), any password/photo change, death-certificate/file uploads.

### Key files

- `app/Http/Middleware/EnsureOfflineJsonRequests.php` — forces `Accept: application/json` on every `/offline/*` request so auth/CSRF/validation failures return JSON the client can parse instead of an HTML redirect or error page.
- `app/Http/Controllers/Offline/{OfflineStatusController,OfflineSyncController,OfflineHouseholdProfilingBootstrapController}.php` and `app/Services/Offline/{OfflineSyncService,OfflineIdempotencyService}.php`, `app/Support/Offline/OfflineFieldHasher.php` — the server side described above.
- `resources/js/offline/` — the entire client side: `offline-sw*.js` (service worker + policy + registration), `offline-db.js` (IndexedDB wrapper), `offline-queue.js` (the sync queue), `offline-replay.js` (sync orchestration), `offline-status.js` (the UI banner/toast/attention-dialog, rendered by `resources/views/components/lml/offline-status.blade.php`).

### Known migration-tracking gap (worth knowing before trusting "Pending" migrations)

This project's database was set up by importing a schema dump rather than running every migration through Laravel, so `php artisan migrate:status` shows a large number of migrations — including ones for tables that plainly already exist and work daily — as "Pending." Three real bugs surfaced during this project's development were all guarded migrations that genuinely had never been run (`chatbot_messages.language/category`, `resident_accounts.resident_id`, `notifications.recipient_context/place/event_date/event_time`) and silently degraded instead of crashing until a resident/announcement flow exercised them. **Never run a blanket `php artisan migrate`** on this database — a `create_X_table` migration for a table that already exists could fail or, worse, partially succeed destructively. When a "Column not found" error appears, find the specific migration, confirm its `up()` is guarded with `Schema::hasTable`/`hasColumn` checks, and run only that one file with `--path=database/migrations/<file>.php --force`.

## 10. Running with Docker

The app ships as a PHP 8.2 + Apache image (front-end assets are built inside the image) plus a MySQL 8 container.

1. Make sure `.env` has `APP_KEY`, `LMLINGA_AT_REST_KEY`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` set. Compose overrides `DB_HOST` to `db`.
2. Build and start: `docker compose up -d --build`
3. Load the schema once (the database is a schema dump, not migrations — see above):
   `docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < path/to/dump.sql`
4. Open http://localhost:8080 (change with `APP_PORT`). MySQL is exposed on host port `3307` (`DB_FORWARD_PORT`).

Uploaded files and logs persist in the `app-storage` volume; database data in `db-data`. Migrations do **not** run automatically; set `RUN_MIGRATIONS=true` only on a fresh database built purely from migrations. Run artisan commands with `docker compose exec app php artisan <command>`.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
