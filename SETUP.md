# Local Setup for Testers

## Requirements
- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl` and **`intl`**
  (XAMPP ships with `intl` disabled: enable `extension=intl` in your `php.ini`).
- Composer 2
- Node.js 20+ and npm
- MariaDB **10.11** (recommended) on `127.0.0.1`. Avoid the MariaDB 10.4 bundled with older XAMPP.
- Optional: Ollama with a local model, only if you test the chatbot.

## Configuration you receive separately
The project owner gives you a **testing** `.env` and, if needed, a **test** database. They are not in
this repository and must never be committed (`.gitignore` already blocks `.env`, `.env.*`, `*.sql`).
If you did not receive them, create your own as described below. Never use a production `.env` or a
dump that contains real resident data.

## Steps
1. Install dependencies:
   `composer install` then `npm ci`
2. Configuration (skip if you received a testing `.env`):
   copy `.env.example` to `.env`, then run `php artisan key:generate`.
3. At-rest encryption key (must differ from `APP_KEY`), put it in `LMLINGA_AT_REST_KEY`:
   `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
4. Create a local database and user (MariaDB client, use your own password):
   `CREATE DATABASE lmlinga_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
   `CREATE USER 'lmlinga_testing'@'127.0.0.1' IDENTIFIED BY '<your-own-password>';`
   `GRANT ALL PRIVILEGES ON lmlinga_testing.* TO 'lmlinga_testing'@'127.0.0.1';`
   Put the same values in `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
5. Administrator password: set `SUPER_ADMIN_PASSWORD` (and optionally `SUPER_ADMIN_EMAIL`) in `.env`
   **before** step 6. There is no built-in default password; without it the administrator is not created.
6. Create the tables (only on your own empty test database):
   `php artisan migrate`
   On an empty database this loads `database/schema/mysql-schema.sql` (needs the `mysql`/`mariadb`
   client on PATH, or set `DB_CLIENT_BIN_PATH`) and then runs the newer migrations.
   To create the administrator later: `php artisan db:seed --class=SuperAdminSeeder`
7. `php artisan storage:link`
8. Build and run: `npm run build` then `php artisan serve`, open http://127.0.0.1:8000
9. Add test records through the app with **fictional** data only.

## Safe testing settings
- `MAIL_MAILER=log`: e-mails and OTP codes go to `storage/logs/laravel.log`; nothing is sent.
- `SEMAPHORE_API_KEY` empty: SMS is disabled.
- `LMLINGA_DEMO_LOCATION_ENABLED=false` unless rehearsing the Spot Mapping demo.
- `APP_DEBUG=true` only on your own machine.

## Automated tests
- PHP (in-memory SQLite, no MariaDB needed), one file or folder at a time, e.g.
  `php vendor/bin/phpunit tests/Feature/SpotMappingDemoLocationTest.php`
- JavaScript: `npm run test:js` or `node --test tests/js/<file>.test.mjs`
- Build check: `npm run build`

## Never run against a copy of real data
`php artisan migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`.

## Docker
`Dockerfile` / `docker-compose.yml` come from an earlier deployment and are **not supported** for this
testing round. Use the local steps above.

## Reporting a problem
Page, role (Admin / BHW / BNS / BSPO / Linguist / Resident), steps, expected vs actual result, and a
screenshot that shows **no real personal data**.
