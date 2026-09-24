# REVIEW MANIFEST

## 1. Review title

LMLinga — Health Records → Family Planning — Remove Non-Residents user-facing access  
Post-refinement / pre-production-freeze — Claude independent review package

## 2. Exact requirement

Health Records → Family Planning must no longer provide a separate Non-Residents tab/page/navigation destination in the resident UI.

## 3. Approved scope

Targeted UI/navigation removal only. No Family Planning redesign. No deletion of legacy Non-Resident backend, routes, pages, fixtures, or data. No Child Care / Maternal / Risk Assessment / Death / Household Profiling work.

## 4. What existed before

The resident Family Planning page was **not** a Residents / Non-Residents tab pair. The only user-facing entry from that page was a header badge/link:

- href: `route('health-records.family-planning.non-residents.index')`
- class: `lml-hr-fp__badge`
- text: `Non - Residents Client`
- aria-label: `Open Non-Residents Client listing`
- wrapper: `.lml-hr-fp__title-row`

Sidebar had no Non-Residents child under Family Planning.

## 5. What was removed

- The badge/link and its aria-label
- The now-unnecessary `.lml-hr-fp__title-row` wrapper
- CSS that existed only for that badge/title-row (including small-viewport badge rules)

No replacement tab was added.

## 6. What intentionally remains

- Resident Family Planning listing workflow (heading, description, Add, Export, summary cards, search, zone/year filters, table)
- Health Records sidebar + Family Planning active state
- Legacy Non-Resident Family Planning routes/controller/views/CSS/JS/fixtures (still directly URL-reachable)
- Unrelated modules

## 7. Exact files inspected

- `resources/views/pages/health-records/family-planning.blade.php`
- `resources/css/pages/health-records-family-planning.css`
- `resources/js/pages/health-records-family-planning.js`
- `resources/views/components/lml/dashboard/sidebar.blade.php`
- `app/Http/Controllers/HealthRecords/FamilyPlanningSummaryController.php`
- `app/Support/HealthRecordsFamilyPlanning.php`
- `routes/web.php`
- Non-Resident Family Planning controller, support, views, CSS, JS, demo fixture
- `tests/Feature/HealthRecordsFamilyPlanningTest.php`
- `tests/Feature/HealthRecordsNonResidentFamilyPlanningTest.php`
- `tests/Feature/HealthRecordsSidebarNavigationTest.php`

`resources/js/pages/family-planning.js` is Household Profiling, not this Health Records listing — not copied.

## 8. Exact production files modified (the patch; not this packaging task)

- `resources/views/pages/health-records/family-planning.blade.php`
- `resources/css/pages/health-records-family-planning.css`

## 9. Exact test files modified (the patch)

- `tests/Feature/HealthRecordsFamilyPlanningTest.php`
- `tests/Feature/HealthRecordsNonResidentFamilyPlanningTest.php`

## 10. Exact files included in ZIP

See `05-reports/FILE-INVENTORY.txt`.

## 11. Legacy Non-Resident files included

Complete copies under `01-source/legacy-non-residents/` (controller, support, listing/create/show/visit blades, partials, CSS, JS, demo fixture). Routes also appear in `01-source/resident/web.php` and `03-evidence/family-planning-routes-excerpt.txt`.

Distinction for the reviewer:

- **A.** User-facing navigation removed from the resident Family Planning page (no badge/link).
- **B.** Legacy backend/routes intentionally preserved and still URL-reachable.

## 12. Exact tests executed

1. `tests/Feature/HealthRecordsFamilyPlanningTest.php`
2. `tests/Feature/HealthRecordsNonResidentFamilyPlanningTest.php`
3. `tests/Feature/HealthRecordsSidebarNavigationTest.php` (additional navigation contract)

## 13. Exact commands

```
php artisan test --filter="HealthRecordsFamilyPlanningTest|HealthRecordsNonResidentFamilyPlanningTest"
php artisan test --filter=HealthRecordsSidebarNavigationTest
npm run build
```

## 14. Exit codes

- Targeted FP tests: **0**
- Sidebar navigation tests: **0**
- `npm run build`: **0**

## 15. Tests passed

- Targeted: **24 passed**
- Sidebar: **14 passed**

## 16. Assertions

- Targeted: **315**
- Sidebar: **200**

## 17. Build command/result

`npm run build` — exit code **0** — Vite v6.4.3, 155 modules transformed, built in ~6.02s. Full log: `03-evidence/build-results.txt`.

## 18. Screenshot inventory

- `04-screenshots/family-planning-1440x900.png`
- `04-screenshots/family-planning-1366x768.png`
- `04-screenshots/family-planning-820x1180.png`
- `04-screenshots/family-planning-390x844.png`

See `03-evidence/screenshot-provenance.txt`.

## 19. Responsive/overflow evidence

`04-screenshots/layout-measurements.json` (also copied to `03-evidence/layout-measurements.json`).

Required viewports: page-level overflow **false** at 1440, 1366, 820, 390. Internal table min-width may still scroll inside a container; that is not page overflow.

## 20. Accessibility verification

See `03-evidence/accessibility-evidence.txt`. No leftover Non-Residents aria-label, no tablist/tab roles on the resident page, heading `h2#lml-hr-fp-heading` remains, Add/Export labels remain, sidebar Family Planning remains `aria-current="page"` (tests).

## 21. Regression verification

See `03-evidence/regression-evidence.txt`. Resident listing controls remain. Scoped git diff does not touch Child Care, Maternal, Risk Assessment, Death, Household Profiling, schema, or the Non-Resident backend implementation.

## 22. Git diff scope

`03-evidence/git-diff.txt` is limited to the four patch files listed in sections 8–9. Staged diff for those paths is empty.

## 23. Unrelated dirty-tree disclosure

`03-evidence/git-status.txt` section B lists many unrelated modified/untracked files (Dashboard, Child Care, Maternal tests, other review packages, etc.). **Do not treat the full dirty tree as this Family Planning patch.**

## 24. Known limitations / informational findings

- Packaging-time Playwright recapture of screenshots into `04-screenshots/` could not be re-run after the artisan server dropped; PNGs are the post-patch captures of the same resident page (files unchanged after that capture). Provenance is documented.
- Legacy Non-Resident URLs still work if typed. That is intentional.
- `routes/web.php` is included in full because it is the production route file; only the Family Planning excerpt is the review focus.
- PHPUnit emits unrelated doc-comment metadata warnings from other test classes during bootstrap; they are not failures of this patch.

## 25. Statement that packaging itself did not modify production code

This packaging task copied files, generated evidence/reports, and created the ZIP. It did **not** modify production Blade/CSS/JS/PHP/routes. The Family Planning UI patch was already present in the working tree before packaging.

**This package does not declare Production Freeze.** The next gate is Claude independent review.
