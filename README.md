# LMLinga

**LMLinga: A Web-based Household Profiling and Automated Spot-Mapping System with Chatbot for the Barangay Health Center of La Medalla, Iriga City.**

This README explains how the system works. Part 1 is the overview. Part 2 is the detailed route-by-route reference for the resident chatbot, the staff dashboard, offline mode and Docker.

## Part 1. How the system works

### Who uses it

| User | Where | What they do |
|---|---|---|
| **Resident** | Chatbot (`/chatbot`) | Registers with an email-verified account, asks health questions in English, Tagalog or Bikol-Iriga, and (after verification) views their own household's records read-only. |
| **Health worker** (`bhw`, `bns`, `bspo`) | Staff dashboard | Profiles households and residents, plots them on the map (spot mapping) and records health services (immunization, nutrition, deworming, risk assessment, family planning, maternal care). |
| **Admin** | Staff dashboard | Everything a health worker can do, plus user management, household and death request approvals, announcements and the **Chatbot Datasets** (the health information the chatbot answers from). |

### Technology

- **Backend:** Laravel 12 on PHP 8.2+ (production runs PHP-FPM 8.3), MySQL/MariaDB.
- **Front end:** Blade views, Vite 6, Tailwind 4 and Bootstrap 5, Leaflet for maps, plain JS modules in `resources/js`.
- **Chatbot AI:** [Ollama](https://ollama.com) (local server) for embeddings and for the chat models.
- **Hosting:** a Docker image for local use. Production is nginx + PHP-FPM + MariaDB on AWS EC2, deployed by GitHub Actions on every merge to `main` (see `docs/DEPLOYMENT_CICD.md`).

### The main parts

1. **Household profiling and spot mapping.** Health workers create households and members and plot each household on a map. The La Medalla boundary is a polygon (a GeoJSON file); a location is accepted only inside it and is rejected by the server otherwise. Most modules read and write the newer ERD tables (see "ERD mode vs. legacy mode" in Part 2).
2. **Health records.** Modules per resident: child and adult immunization, nutrition, deworming, risk assessment, family planning, maternal care and death records. Rules such as eligibility and female-only maternal care are enforced on the server.
3. **Accounts and security.** Staff accounts are created by an Admin. The new person gets a temporary password by email and must enter a 6-digit code (valid 5 minutes, single use) at first sign-in. Residents verify their email with a code when they register. Resident and staff sessions are separate. Sensitive health columns are encrypted at rest (`docs/AT_REST_ENCRYPTION.md`).
4. **Offline mode.** Health workers can keep working without a connection. Changes are queued in the browser and replayed to the server when it is back (Part 2, section 9).
5. **Resident chatbot.** Answers health questions from verified documents (below) and gives residents a read-only view of their own household after verification (Part 2).
6. **Announcements.** Staff post announcements, and matching residents get a notification in the chatbot.

### How the chatbot answers

1. The resident picks a language: English, Tagalog or Bikol-Iriga.
2. Small talk ("hello") is answered directly. A health question is looked up in the **health documents**: one text file per topic, indexed in the `health_chunks` table. Matching uses topic names and headings first, then Ollama embeddings as a fallback.
3. A document is only used if it is really *about* the asked topic, so the bot does not answer from a document that merely mentions the word.
4. If nothing on-topic is found, the bot says it does not have enough information. It never guesses a health answer.

**Bikol-Iriga has three chatbot types** that the resident chooses first (there is no default):

| Type | How it answers |
|---|---|
| **Context-based** | Only from the verified health documents. No AI model. |
| **Local Model** | Health questions are answered from the verified documents. A small Gemma model, trained on Central Bikol text and Bikol-Iriga conversations and run by the local Ollama, chats about everyday topics. The team's Iriga word list is applied to its replies. It never answers health questions. |
| **Cloud AI (Gemma)** | A hosted Gemma model re-tells the verified answer and chats naturally, using Iriga words. Needs internet. |

English and Tagalog use the document lookup and the chat model, with no type switch.

### Chatbot Datasets (Admin)

The health documents are managed from **Chatbot Datasets** in the admin dashboard (`/chatbot-datasets`):

- **Add Dataset.** The Admin uploads a plain `.txt` file. The form explains how to write it and offers an English, Tagalog and Bikol-Iriga template. The file is screened (real text, size, encoding, no personal data, and the Admin confirms it holds no resident information), then saved and sent to the chatbot at once: it is written to `storage/app/health_docs_admin/<language>/<category>/` and indexed. The chatbot prefers an admin file over a built-in file of the same name.
- **Default datasets.** The 73 documents shipped in `resources/health_docs_v2` are listed as **Default**: read-only, with no history.
- **Change, retire, restore, re-index.** Admin datasets can be replaced, removed from the chatbot, restored, and re-indexed if the AI embedding model was unreachable when they were saved. Each change is kept in the dataset's History.
- **Languages and translations.** A dataset belongs to one language, and only that language's chatbot reads it. The same topic (same category and source name) can exist in all three languages. The dataset page shows which are missing and offers **Add translation**.
- **Translate with AI (draft).** The Admin can ask the local model for a draft translation of a live dataset. A draft is **not** used by the chatbot until the Admin corrects it and presses **Verify and send to chatbot**.
- **Finding a new topic.** The chatbot finds a document from its title and headings. Use the words residents say in the title. In English and Tagalog, add a `MAIN TOPIC:` / `PANGUNAHING PAKSA:` line with other names for the topic.

### Where things are in the repo

| Path | What is there |
|---|---|
| `app/Http/Controllers`, `app/Services`, `app/Support` | Controllers by area, business rules (services) and helpers such as `RagService` (the chatbot) and `StaffRole`. |
| `resources/views`, `resources/js`, `resources/css` | Blade views, front-end modules (including `offline/`) and styles. |
| `resources/health_docs_v2/{en,tl,bcl}` | The 73 built-in chatbot documents per language. |
| `resources/ml` | Small model files the chatbot reads at run time (intent and word lists). |
| `ml/` | Training material for the Bikol models and datasets. Not read at run time. |
| `database/migrations`, `database/schema` | Migrations and the schema dump (see the migration note in Part 2, section 9). |
| `routes/web.php` | All web routes. |
| `tests/` | PHP feature and unit tests, and `tests/js` front-end tests. |
| `.github/workflows`, `scripts/deploy`, `docker/` | CI, deployment and container setup. |
| `docs/` | Deployment, encryption and QA documents. |

### Run it and test it

- **Docker:** see Part 2, section 10 (`docker compose up -d --build`).
- **Without Docker:** `composer install`, `npm ci`, copy `.env.example` to `.env`, `php artisan key:generate`, point `.env` at a database, then `npm run dev` and `php artisan serve`. For the chatbot, install Ollama, pull the embedding model and run `php artisan health:index` to index the documents.
- **Tests:** run only the tests that cover what you changed, for example `php artisan test tests/Feature/ChatbotDatasetsDirectPublishTest.php` or `node --test tests/js/name-suggest.test.mjs`. Do not run the whole suite (see `CLAUDE.md`).
- **Deploy:** merging to `main` deploys to lmlinga.com. Only migration files that are new since the last deploy are run.

---

## Part 2. Detailed reference

### Resident Chatbot Flow

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
3. **Retrieval** — for a real health question, `RagService::retrieve()` searches the `health_chunks` table (indexed from `resources/health_docs_v2/{en,tl,bcl}/...txt` via `php artisan health:index`) using keyword matching first, then embedding similarity as a fallback (via a local Ollama instance).
4. **Topic identity gate** — a retrieved document is only used if it is genuinely *about* the asked topic (matched by title/heading, not just a body mention). This deliberately prevents the bot from answering, e.g., "what is fever?" by paraphrasing an unrelated vaccination document that merely mentions fever in passing.
5. **Answer generation** — English and Tagalog pass the matched content to the chat model (`gemma4:31b-cloud` via Ollama) to generate a natural-language answer. **Bikol-Iriga health answers are extractive** — they are pulled directly from a matching document heading to avoid language drift, in all three chatbot types. The type the resident chose (Context-based, Local Model or Cloud AI) only changes how everyday, non-health messages are handled (see Part 1).
6. **Intent recognition** differs slightly per language — English/Tagalog distinguish Definition / Symptoms / Causes / Prevention / Treatment / Benefits; Bikol merges Prevention and Treatment into one `management` intent and additionally recognizes `warning` (urgent/unsafe-symptom phrasing) and `schedule` (age/month eligibility questions).
7. If no on-topic content is found, the bot returns a canned "I don't have enough information" message (localized per language) rather than guessing.

Both the user's question and the bot's reply are persisted as rows in `chatbot_messages`, grouped under a `chatbot_conversations` row.

## 4. Household Record Access (Verification)

The **Access Household Record** button starts a verification flow so a resident can prove they belong to a specific household before seeing any real household/member data:

`GET/POST /chatbot/household/verification` (`chatbot.household.verification`, `.store`) → `HouseholdRecordRequestController`
→ `GET /chatbot/household/verification/method` (`.otp-method`) — redirects to the SMS step; the email step has its own routes
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
