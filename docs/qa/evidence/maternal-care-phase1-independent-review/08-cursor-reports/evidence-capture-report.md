# Maternal Care Phase 1 — Automated Evidence Capture Report

**Task type:** Evidence capture only  
**Date:** 2026-08-09  
**Member context:** HH-151 / MB-001  
**Application URL:** http://127.0.0.1:8765  

---

## 1. Evidence Capture Summary

Automated responsive, interaction, navigation/regression, test, and build evidence was captured for Maternal Care Phase 1 and stored under:

`docs/qa/evidence/maternal-care-phase1-independent-review/`

- Screenshots captured: **86**
- Interaction evidence files: **25**
- Navigation/regression evidence files: **7**
- Test log files: **5**
- Build log: **1**
- Capture inventory JSON: present

No Maternal Care application defects were discovered during capture.

---

## 2. Browser Automation Mechanism Used

- **Tool:** Playwright (`playwright` package already present in the repository)
- **Browser:** System Google Chrome via `chromium.launch({ channel: 'chrome' })`
- **Script:** `docs/qa/evidence/maternal-care-phase1-independent-review/capture-mc-phase1-evidence.mjs`
- **Mode:** Headless, actual viewport sizes via `page.setViewportSize`
- Device toolbar + viewport badge overlays were injected for independent dimension verification (evidence-only DOM overlays; not application source)

Note: Bundled Playwright Chromium binary was not available in the sandbox cache. Existing Playwright API was retained by using the installed Chrome channel (no new npm dependency added).

---

## 3. Application URL Used

Base: `http://127.0.0.1:8765`

Primary paths:

- `/household-profiling/HH-151/members/MB-001`
- `/household-profiling/HH-151/members/MB-001/maternal-care`
- Prenatal / Immunizations / Supplementations / Laboratory / Delivery / Postnatal sub-routes
- Regression: Family Planning, Risk Assessment, Child Immunization, Dashboard Health Records sidebar

Role query used for demo UI role: `role=bhw`

Active pregnancy was created via the existing Maternal Care session/preview registration flow when needed.

---

## 4. Viewports Actually Captured

| Viewport | Status | Folder |
|----------|--------|--------|
| 1440×900 | PASS | `01-desktop/` |
| 1366×768 | PASS | `01-desktop/` |
| 820×1180 | PASS | `02-tablet/` |
| 768×1024 | PASS | `02-tablet/` |
| 390×844 | PASS | `03-mobile/` |
| 360×800 | PASS | `03-mobile/` |

Each viewport includes browser `innerWidth×innerHeight` evidence overlays matching the requested dimensions.

---

## 5. Pages Captured

At each viewport:

- Maternal Care Overview (`maternal-overview-*`)
- Prenatal Visits (`prenatal-*` + full-page `prenatal-*-full.png`)
- Supplementations (`supplementations-*` + full-page `supplementations-*-full.png`)
- Laboratory Screening (`laboratory-*`)
- Pregnancy Delivery & Outcome (`delivery-outcome-*` + full-page `delivery-outcome-*-full.png`)
- Postnatal Care (`postnatal-*`)

Counts:

- Desktop PNGs: 18
- Tablet PNGs: 18
- Mobile PNGs: 18

---

## 6. Interaction States Captured

Folder: `04-interactions/`

| File | State |
|------|-------|
| `00-immunizations-icon-fixed.png` | Overview Immunizations icon (`bi-shield-plus`) visible |
| `01-prenatal-edit.png` | Prenatal edit mode |
| `02-prenatal-save-view.png` | Prenatal after save |
| `03-immunizations-edit.png` | Immunizations edit |
| `04-immunizations-save-view.png` | Immunizations after save |
| `05-supplementations-edit.png` | Supplementations edit |
| `06-...-deworming.png` | Deworming expanded |
| `07-...-iron-folic.png` | Iron with Folic Acid expanded |
| `08-...-micronutrient.png` | MMS expanded |
| `09-...-calcium.png` | Calcium expanded |
| `10-supplementations-save-view.png` | Supplementations after save |
| `11-laboratory-edit.png` | Laboratory edit |
| `12-laboratory-hepatitis-options.png` | Hepatitis B result selected |
| `13-laboratory-cbc-options.png` | CBC result selected |
| `14-laboratory-gdm-options.png` | GDM result selected |
| `15-laboratory-save-view.png` | Laboratory after save |
| `16-delivery-edit.png` | Delivery edit |
| `17-delivery-type-options.png` | Delivery type |
| `18-birth-attendant-options.png` | Birth attendant |
| `19-birth-attendant-others-state.png` | Others attendant revealed |
| `20-fetal-death-state.png` | FD conditional date |
| `21-abortion-state.png` | AB conditional date |
| `22-delivery-save-view.png` | Delivery after save |
| `23-postnatal-edit.png` | Postnatal edit |
| `24-postnatal-save-view.png` | Postnatal after save |

Confirmed from capture inventory: Immunizations icon class rendered as `bi bi-shield-plus`.

---

## 7. Navigation / Regression Evidence Captured

Folder: `05-navigation-regression/`

1. `01-household-member-maternal-care-link.png` — Maternal Care link on member view  
2. `02-maternal-household-profiling-active.png` — Household Profiling active while in Maternal Care  
3. `03-maternal-return-navigation.png` — Back returns to HH-151 / MB-001  
4. `04-family-planning-regression.png` — Family Planning reachable  
5. `05-risk-assessment-regression.png` — Risk Assessment reachable  
6. `06-child-care-regression.png` — Child Immunization reachable  
7. `07-health-records-sidebar-regression.png` — Health Records sidebar evidence  

Capture notes:

- Sidebar active label: `Household Profiling`
- Return URL: `http://127.0.0.1:8765/household-profiling/HH-151/members/MB-001`

---

## 8. Tests Executed

| Exact test file | Exact command | Exit code | Tests passed | Assertions |
|-----------------|---------------|-----------|--------------|------------|
| `tests/Feature/HouseholdProfilingMaternalCareTest.php` | `php artisan test tests/Feature/HouseholdProfilingMaternalCareTest.php` | 0 | 15 | 193 |
| `tests/Feature/HouseholdProfilingHouseholdMemberViewTest.php` | `php artisan test tests/Feature/HouseholdProfilingHouseholdMemberViewTest.php` | 0 | 8 | 47 |
| `tests/Feature/HouseholdProfilingFamilyPlanningTest.php` | `php artisan test tests/Feature/HouseholdProfilingFamilyPlanningTest.php` | 0 | 16 | 98 |
| `tests/Feature/HouseholdProfilingRiskAssessmentTest.php` | `php artisan test tests/Feature/HouseholdProfilingRiskAssessmentTest.php` | 0 | 20 | 168 |
| `tests/Feature/HealthRecordsSidebarNavigationTest.php` | `php artisan test tests/Feature/HealthRecordsSidebarNavigationTest.php` | 0 | 14 | 193 |

Raw logs saved under `06-test-logs/`.

---

## 9. Exact Test Results

All listed suites **PASS** (exit code 0). Combined: **73 tests passed**, **699 assertions**.

---

## 10. Build Result

- **Exact command:** `npm run build`
- **Exit code:** 0
- **Vite version:** v6.4.3
- **Modules transformed:** 145
- **Generated location:** `public/build/`
- **Duration:** 4.67s
- **Warning:** `npm warn Unknown env config "devdir"` (environment/npm config; not a Maternal Care defect)

Raw log: `07-build-log/npm-run-build.txt`

---

## 11. Missing Evidence

None required by the capture checklist.

---

## 12. Files Generated

Evidence package under `docs/qa/evidence/maternal-care-phase1-independent-review/`:

- `01-desktop/` (18 PNGs)
- `02-tablet/` (18 PNGs)
- `03-mobile/` (18 PNGs)
- `04-interactions/` (25 PNGs)
- `05-navigation-regression/` (7 PNGs)
- `06-test-logs/` (5 TXT logs)
- `07-build-log/npm-run-build.txt`
- `08-cursor-reports/capture-stdout.txt`
- `08-cursor-reports/capture-inventory.json`
- `08-cursor-reports/evidence-capture-report.md` (this file)
- `capture-mc-phase1-evidence.mjs` (evidence-only automation script)

---

## 13. Application Source Files Modified During This Task

**NONE**

Only evidence artifacts and the evidence capture script under `docs/qa/evidence/...` were created/updated. No Laravel application source (controllers, Blade pages, CSS/JS pages, routes, models) was modified for this verification task.

---

## Final Status

**EVIDENCE PACKAGE COMPLETE**
