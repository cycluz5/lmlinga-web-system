# Known Limitations (review snapshot 2026-11-08)

## Sanitized demo catalog files
- `resources/demo/residents.php`, `resources/demo/resident-accounts.php` and
  `resources/demo/health-workers.php` are legacy UI-prototype catalogs. In this snapshot every name,
  username, e-mail address (`@example.invalid`), phone number (`0900 000 xxxx`), street and house number
  in them was replaced with clearly fictional values; structure, ids, zones and relationships are
  unchanged. A static code review found no current route, view or test that loads them.

## Unfinished or partial panel refinements
- **Barangay boundary is not enforced on save.** The polygon is displayed and the browser warns when a
  location is outside, but the server still accepts plots outside La Medalla. The polygon is an
  *indicative* NAMRIA/PSA-derived boundary, not an official survey.
- **Map is not limited to the barangay.** Areas outside the polygon are dimmed, not hidden.
- **Demo Location Mode (fake GPS)** is off by default and has one UNVERIFIED demo point.
- **PhilHealth membership type:** implemented with a new table; the official category list still has
  to be confirmed by the Barangay Health Center.
- **Bikol-Iriga terms and linguist-approved datasets** can be managed and reviewed, but the chatbot
  does not use them yet.
- **Local AI:** the default chat model is a cloud model; no local model is configured or trained. The
  chatbot cannot answer without an AI provider.
- **Two-approach comparison** (`php artisan chatbot:compare`) exists but has not been run.
- **Autofill** covers household request names and newborn data from birth history only.
- **Google Maps** was not adopted; the map uses Leaflet with OpenStreetMap.
- **E-mail and SMS codes** were tested with fake mail and SMS only, never end to end.
- **Offline mode** was tested in a desktop browser, not yet on a real phone.

## Database
- The newest migrations (education "Not Applicable", resident e-mail verification, Linguist role,
  chatbot terms, chatbot datasets, PhilHealth membership type) run on a new database but have not
  been applied to the production database.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset` or `db:wipe` against a copy of real data.

## Security notes for testers
- There are no built-in default passwords: the administrator seeder, the staff seeder and
  `php artisan client-testing:provision` refuse to create accounts until `SUPER_ADMIN_PASSWORD`,
  `SEED_STAFF_PASSWORD` or `CLIENT_TESTING_*_PASSWORD` are set in `.env`. The legacy demo staff login is
  disabled unless `DEMO_STAFF_*` values are set.
- The administrator's username is `maria.santos` (the application and tests depend on it).
- `phpunit.xml` contains a documented **test-only** encryption key used by the automated tests.

## Known test issues (from the 2026-11-07 audit)
- A few PHP tests are known to fail (non-resident Child Care / Family Planning summary pages, maternal
  pregnancy history visibility).
- `RagService` calls `set_time_limit(180)`, which stops a full single-process PHPUnit run; run tests per
  file or folder.
- `tests/js/offline-prepare.test.mjs` passes its assertions but does not exit.
- The PHP `intl` extension must be enabled.
