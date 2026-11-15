# LMLinga — Groupmate UAT Guide (Refinement Batches 1–6)

Local, disposable testing only. **Never** point this at the production database, never copy a real `.env`,
and never use real resident records. Use made-up names and test email addresses.

## 1. What you need

| Tool | Version used in development |
|---|---|
| PHP | 8.2+ (with `pdo_sqlite`, `openssl`, `mbstring`, `fileinfo`) |
| Composer | 2.x |
| Node.js / npm | 20+ / 10+ |
| Browser | Chrome or Edge (DevTools → Sensors is used for the boundary test) |

No MySQL is needed: testing uses a disposable SQLite file.

## 2. One-time setup (Git Bash or PowerShell, inside the project folder)

```bash
composer install
npm ci
npm run build
cp .env.example .env            # PowerShell: Copy-Item .env.example .env
php artisan key:generate
```

Edit `.env` (local values only — never real credentials):

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8091
DB_CONNECTION=sqlite
DB_DATABASE="<absolute path to this folder>/database/uat-local.sqlite"
# comment out DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD
MAIL_MAILER=log                 # emails (codes, links) are written to storage/logs/laravel.log
LMLINGA_AT_REST_KEY=<generate: php -r "echo 'base64:'.base64_encode(random_bytes(32));">
LMLINGA_AT_REST_KEY_ID=uatlocal
SUPER_ADMIN_EMAIL=uat.admin@example.test
SUPER_ADMIN_PASSWORD=<choose a local password, e.g. 12+ chars with upper, lower, digit, symbol>
SEMAPHORE_API_KEY=              # leave empty: SMS is not sent during UAT
```

Create the disposable database and run migrations **against that SQLite file only**:

```bash
# Git Bash: touch database/uat-local.sqlite      PowerShell: New-Item database/uat-local.sqlite
php artisan migrate               # creates the tables and the admin account from SUPER_ADMIN_*
php artisan serve --host=127.0.0.1 --port=8091
```

Open **http://127.0.0.1:8091/login** and sign in with `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD`.

> Reading emails: open `storage/logs/laravel.log` and search for the newest message
> (`verification code is` for codes, `reset-password/` for reset links). This is a local mail catcher;
> no real email is sent with `MAIL_MAILER=log`.

## 3. Test accounts (create them during testing — synthetic data only)

| Role | How to create |
|---|---|
| Admin | Seeded by `php artisan migrate` from `SUPER_ADMIN_*` |
| BHW / BNS / BSPO / Linguist | Admin → User Management → Add (then the account must verify its email at first login) |
| Resident (chatbot) | `/chatbot/register` (two steps), Gmail-format address such as `uat.resident1@gmail.com` |

## 4. Scenarios and expected results

### Batch 1 — Health-worker email verification
1. Admin creates a BHW with a temporary password → card shows **Email: Pending verification**.
2. Same email in capitals again → "already been taken". `not-an-email` → "valid email address".
3. Log out; BHW logs in with a **wrong** password → generic error, no code sent.
4. BHW logs in with the **right** password → **Verify Your Email** page; dashboard is not reachable.
5. Wrong code → "invalid or has expired". "Send a new code" immediately → "Please wait".
6. Correct code (from the log) → "Email verified" → BHW logs in → dashboard.
7. Wait > 5 minutes before entering a code → rejected. 5 wrong codes → code voided.
8. Admin edits a worker's email → that worker must verify the new address at next login.

### Batch 2 — Staff forgot password
1. `/forgot-password` with a registered email and with an unregistered one → **same** message both times.
2. Only the registered address gets a reset link in the log. Reset works; old password stops working.
3. Inactive or pending-verification accounts get no link.

### Batch 3 — Pagination
Open any long list (Household Profiling, Child Care, Family Planning, Household Requests…).
With more than 10 rows: "Showing 1–10 of N", page buttons, filters reset to page 1, page number kept in the URL.

### Batch 4 — Name suggestions (duplicate prevention)
Household Profiling → Add Member, Spot Mapping new household head, or a non-resident create form:
type 2+ letters of an existing resident's name → "Possible existing records" list (name, zone, household no.).
Choosing one opens that record in a new tab; the form is not filled. A BHW only sees residents in their zones.
Never shown on login, registration or chatbot pages.

### Batch 5 — Spot Mapping boundary
1. Plot a household **inside** La Medalla → saved.
2. DevTools → More tools → **Sensors** → set a location outside (e.g. 13.4745, 123.3722) → plot →
   rejected: "This location is outside the Barangay La Medalla boundary…". Nothing saved.
3. Existing markers still show on the map.

### Batch 6
1. **Two-step resident registration**: `/chatbot/register` shows only email + password ("Step 1 of 2") →
   code page → after the code, **Step 2 of 2** asks for name and zone → account is created only then →
   resident can log in. Leaving after step 1 creates no account.
2. **Approved Bikol terms in the chatbot**: Admin adds a term in Language Management → submit → a Linguist
   approves it → the chatbot prompt includes it (needs a reachable AI model to see an effect in answers).
3. **Chatbot comparison (panel requirement)**: after at least one dataset is uploaded and **approved by a
   Linguist** (Chatbot Datasets), run
   `php artisan chatbot:compare --provider=local --model=<local model> --language=en --limit=5`
   → Approach A (model only) and Approach B (approved datasets only) both produce answers; results are
   written under `storage/app/private/chatbot-evaluations/comparisons/<run-id>/`. Requires a local Ollama
   model (see limitations).

### Regression spot checks
Household Profiling create/edit, Health Records lists and exports, Environmental Health, Announcements,
Notifications, Household Requests (OTP), Death Requests, offline mode (go offline in DevTools and reopen
a prepared page).

## 5. Known limitations (state these during the defense)

| Area | Status in UAT |
|---|---|
| Email | Local log only (`MAIL_MAILER=log`). Real inbox delivery needs approved SMTP settings. |
| SMS (household OTP) | Not sent without a Semaphore key. |
| Chatbot AI | Needs an Ollama model. Default config uses a cloud model; for local AI install Ollama and set `OLLAMA_CHAT_PROVIDER=local`. Without a model, chatbot health answers fail. |
| Boundary | The La Medalla polygon is **indicative** (NAMRIA/PSA COD-AB, 12 points), not an official survey. Houses near the edge could be wrongly rejected; `LMLINGA_GEOFENCE_ENFORCE=false` is the fallback. |
| Approved datasets | No real Linguist-approved datasets exist yet; Approach B stays "pending" until one is approved. |
| Google Maps | Not used; maps are Leaflet + OpenStreetMap (works offline). |

## 6. Checklist (tick and screenshot for evidence)

- [ ] Admin login · [ ] BHW created → pending badge · [ ] BHW blocked before code · [ ] code verifies → login
- [ ] Forgot password: same message registered/unregistered · [ ] reset link works
- [ ] Pagination on 3 lists · [ ] filters + paging together
- [ ] Name suggestions appear for staff · [ ] not on chatbot registration
- [ ] Plot inside saved · [ ] plot outside rejected (Sensors)
- [ ] Two-step registration (no account after step 1) · [ ] resident login after step 2
- [ ] Term approved by Linguist · [ ] (optional, needs Ollama) comparison run
- [ ] Spot checks of household profiling, health records, offline mode
