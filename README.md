# LMLinga — Refined System (Private Review Snapshot, 2026-11-08)

LMLinga is a web-based household profiling and automated spot-mapping system with a resident
chatbot for the Barangay Health Center of La Medalla, Iriga City (Laravel 12, PHP 8.2, MariaDB,
Vite, Leaflet/OpenStreetMap, offline support through a service worker and IndexedDB).

This repository is a **sanitized snapshot** of the post-oral-defense refinement work, prepared for
**groupmate testing and review of the panel recommendations**. It starts with a fresh Git history.

## Safety rules (read first)

- This repository contains **no `.env` file, no database, no credentials, no encryption keys and no
  real resident or health information**.
- The project owner shares a **testing** `.env` and **test** database separately. Never commit them,
  never upload them anywhere, and delete them when testing ends.
- Use **fictional test data only**: invented names, `@example.invalid` e-mail addresses and fake phone
  numbers. Never enter or import real resident or health records.
- Keep e-mail and SMS disabled while testing (`MAIL_MAILER=log`, empty `SEMAPHORE_API_KEY`).
- Screenshots in bug reports must not show real personal data.

## Getting started

- Installation: [`SETUP.md`](SETUP.md)
- Known limitations and unfinished refinements: [`KNOWN_LIMITATIONS.md`](KNOWN_LIMITATIONS.md)
- Resident chatbot flow (routes, controllers, behaviour): [`docs/CHATBOT_FLOW.md`](docs/CHATBOT_FLOW.md)
- Clinical-field encryption: [`docs/AT_REST_ENCRYPTION.md`](docs/AT_REST_ENCRYPTION.md)

## Main modules

| Area | Where |
|---|---|
| Staff login, roles (Admin, BHW, BNS, BSPO, Linguist), user management | `app/Http/Controllers/Auth`, `app/Http/Controllers/Admin`, `app/Support/StaffRole.php` |
| Household profiling and members | `app/Http/Controllers/HouseholdProfiling`, `resources/views/pages/household-profiling` |
| Health records (maternal, child care, family planning, immunization, Operation Timbang, deaths, risk assessment) | `app/Http/Controllers/HealthRecords`, `app/Http/Controllers/HouseholdProfiling` |
| Spot Mapping, barangay boundary, Demo Location Mode, offline map | `app/Http/Controllers/SpotMappingController.php`, `resources/js/pages/spot-mapping*.js`, `resources/geo`, `public/maps` |
| Offline mode (service worker, IndexedDB, sync) | `resources/js/offline`, `app/Services/Offline`, `public/sw.js` |
| Resident Portal / chatbot (registration with e-mail code, household record requests) | `app/Http/Controllers/Chatbot`, `app/Services/RagService.php`, `resources/health_docs` |
| Bikol-Iriga terms, Linguist review, chatbot datasets | `app/Http/Controllers/Admin/LanguageManagementController.php`, `app/Http/Controllers/Admin/ChatbotDatasetController.php` |
| Announcements and dashboard | `app/Http/Controllers/Announcements`, `app/Http/Controllers/DashboardController.php` |

## Panel refinement status in this snapshot

Status is taken from the project's own refinement reports (2026-11-02) and the 2026-11-07/08
verification. "Implemented" does not mean the panel has accepted it.

| ID | Refinement | Status |
|---|---|---|
| S-04 | OTP valid for 5 minutes | Implemented and verified |
| S-03 | E-mail code before registration and household request | Implemented; tested with fake mail only |
| S-02 | PhilHealth membership type per member | Implemented (new migration); official category list still to be confirmed |
| S-10 | Fake GPS / Demo Location Mode | Partial: off by default, one UNVERIFIED demo point, saving blocked in demo mode |
| S-11 | Barangay polygon boundary | Partial: indicative NAMRIA/PSA boundary, shown and checked, not enforced on save |
| S-12 | Restrict plotting outside the barangay | Partial: warning and confirmation only |
| S-15 | Only the barangay visible | Partial: outside area dimmed, not hidden |
| S-01 | Autofill of recorded information | Partial: household request names, newborn data |
| S-05 | Bikol-Iriga term management | Partial: approved terms not yet used by chatbot answers |
| S-06 | Linguist review | Implemented; needs a real linguist walkthrough |
| S-07 | Linguist-approved datasets | Partial: not yet used by the chatbot |
| S-08 | Local AI | In progress: default model is a cloud model |
| S-09 | Two-approach chatbot comparison | In progress: command exists, not yet run |
| S-13 | Google Maps | Not implemented (Leaflet/OpenStreetMap kept by decision) |
| S-14 | Barangay starting point | Needs clarification |

Manuscript recommendations (M-01 to M-18) are not part of this code repository.

## What is not in this repository

`.env` and every `.env.*` except `.env.example`, Git history of the original project, database dumps
and backups, `vendor/`, `node_modules/`, build output, uploaded files and logs under `storage/`, QA
screenshots and review packages, internal refinement and security reports, screenshot capture scripts,
and the CI image-publishing workflow. The three legacy demo catalog files in `resources/demo/`
(residents, resident accounts, health workers) contain only fictional, sanitized values.
