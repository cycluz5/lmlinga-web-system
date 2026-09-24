# LMLinga
## Health Records → Family Planning → Non-Residents
### Add Visit / Edit Visit — Claude Review Package

Generated for independent Claude review. This package contains **copies** of production files and QA evidence only. Packaging did **not** modify application implementation.

---

## A. Review Scope

Verify Add Visit and Edit Visit UI refinement for Non-Resident Family Planning:

1. Full-width layout aligned with client banner  
2. Visit Information closed rectangular card  
3. Commodities Given closed rectangular card  
4. Section headings fully **inside** cards (no fieldset/legend border cut)  
5. Taller / Figma-like card height (~280px desktop)  
6. Add/Edit layout consistency  
7. Cancel + Save centered on the **complete** two-card form (center delta = 0)  
8. Client banner matching Non Residents Info (show) page  
9. White client name / demographic typography  
10. Banner / icon treatment (`#51c269`, `bi-person-vcard`)  
11. Delete Visit absent  
12. Commodity add/remove behavior intact  
13–15. Desktop / tablet / mobile responsiveness  
16. Accessibility (contrast, focus, labels)  
17. Regression safety  
18. Feature tests  
19. Frontend build success  
20. Production scope limited to UI files for this refinement  

**Out of scope for this package:** Non-Residents listing redesign, Create Client, View Individual alignment freeze (included only as banner source-of-truth reference), unrelated Health Records modules.

---

## B. Exact Production Files Included

| Package path | Source path |
|--------------|-------------|
| `production/visit-form.blade.php` | `resources/views/pages/health-records/non-resident-family-planning/visit-form.blade.php` |
| `production/commodity-rows.blade.php` | `resources/views/pages/health-records/non-resident-family-planning/partials/commodity-rows.blade.php` |
| `production/client-identity-banner.blade.php` | `resources/views/pages/health-records/non-resident-family-planning/partials/client-identity-banner.blade.php` |
| `production/health-records-non-resident-family-planning.css` | `resources/css/pages/health-records-non-resident-family-planning.css` |
| `production/health-records-non-resident-family-planning.js` | `resources/js/pages/health-records-non-resident-family-planning.js` |

---

## C. Reference / Source-of-Truth Files Included

| Package path | Source path | Purpose |
|--------------|-------------|---------|
| `production/non-residents-info-reference.blade.php` | `resources/views/pages/health-records/non-resident-family-planning/show.blade.php` | Non Residents Info / View Individual page Blade used as client-banner source of truth |
| `screenshots/07-non-residents-info-reference-desktop.png` | Captured at 1440×900 from `/health-records/family-planning/non-residents/roselyn-a-mendoza?role=bns` | Visual banner reference |

Show-page CSS banner rules live in the same CSS file under `[data-lml-hr-fp-nr-mode="show"]` (included in the CSS copy above).

---

## D. Exact Test Files Included

| Package path | Source path |
|--------------|-------------|
| `tests/HealthRecordsNonResidentFamilyPlanningTest.php` | `tests/Feature/HealthRecordsNonResidentFamilyPlanningTest.php` |

---

## E. Exact QA/Capture Files Included

| Package path | Source path |
|--------------|-------------|
| `qa/capture-hr-non-resident-family-planning-add-edit-visit-alignment.mjs` | `scripts/capture-hr-non-resident-family-planning-add-edit-visit-alignment.mjs` |
| `qa/layout-measurements.json` | `docs/qa/screenshots/health-records-non-resident-family-planning-add-edit-visit-actions-banner/layout-measurements.json` |

---

## F. Screenshot Evidence Included

| File | Content |
|------|---------|
| `screenshots/01-add-visit-desktop-1440x900.png` | Add Visit desktop |
| `screenshots/02-edit-visit-desktop-1440x900.png` | Edit Visit desktop |
| `screenshots/03-add-visit-tablet-820x1180.png` | Add Visit tablet |
| `screenshots/04-edit-visit-tablet-820x1180.png` | Edit Visit tablet |
| `screenshots/05-add-visit-mobile-390x844.png` | Add Visit mobile |
| `screenshots/06-edit-visit-mobile-390x844.png` | Edit Visit mobile |
| `screenshots/07-non-residents-info-reference-desktop.png` | Non Residents Info reference |

---

## G. Viewport Sizes

- Desktop: **1440×900**
- Tablet: **820×1180**
- Mobile: **390×844**

### Key desktop measurements (from `qa/layout-measurements.json`)

**Add Visit 1440×900**

- Form center X = **860**
- Action center X = **860**
- Delta = **0**
- `formMatchesBannerWidth` = true
- `cardsTopAligned` = true
- `visitCard.borderTopContinuous` / `headingInsideCard` = true
- `commoditiesCard.borderTopContinuous` / `headingInsideCard` = true
- `hasDeleteVisit` = false
- `pageOverflow` = false
- `clientNameColor` / `clientDetailsColor` = `rgb(255, 255, 255)`

**Edit Visit 1440×900**

- Form center X = **860**
- Action center X = **860**
- Delta = **0**
- Same pass flags as Add for borders, headings, banner width, overflow, Delete Visit absence, white banner text

---

## H. Test Result

- **Exact command:** `php artisan test --filter=HealthRecordsNonResidentFamilyPlanningTest`
- **Exit code:** `0`
- **Passed:** `15`
- **Assertions:** `231`
- Raw output: `evidence/test-output.txt`

---

## I. Build Result

- **Exact command:** `npm run build`
- **Exit code:** `0`
- Raw output: `evidence/build-output.txt`

---

## J. Git Evidence Included

- `evidence/git-status.txt` — full short status from git root `C:/Users/Kathlyn Cris/Desktop/LMLinga_Dev`
- `evidence/git-diff.txt` — scoped unstaged/staged diff for Add/Edit-related paths (visit-form, show, banner partial, CSS, feature test, capture script)

**Note:** The working tree also contains **unrelated WIP** outside this review scope (e.g. other Health Records / Child Care / Risk Assessment paths). See `git-status.txt`. Packaging did not stage, commit, reset, or alter git state.

---

## K. Requested UI Requirements

Checklist for Claude:

- [ ] Visit Information closed rectangular border; heading inside  
- [ ] Commodities Given closed rectangular border; heading inside  
- [ ] Continuous top borders (no legend cut)  
- [ ] Card heights ~Figma / ~280px desktop  
- [ ] Cards top-aligned on desktop  
- [ ] Form width matches client banner  
- [ ] Cancel/Save centered on full two-card form (delta ≈ 0)  
- [ ] Delete Visit absent  
- [ ] Banner matches Info page (white name/details, green `#51c269`, icon)  
- [ ] Add/Edit consistent  
- [ ] Tablet/mobile stack without overflow  
- [ ] Commodity add/remove still present in markup/JS  
- [ ] Tests + build green  

---

## L. Known Remaining Differences

- Exact Figma pixel padding may still differ by a few pixels.  
- Working tree includes unrelated module changes not part of this Add/Edit Visit UI package.  
- Screenshots 01–06 were taken from the actions-banner QA capture folder (same refinement pass measurements); screenshot 07 was captured separately for Info reference.

---

## M. Missing Evidence

None of the required Add/Edit viewport screenshots, Info reference screenshot, test output, build output, or measurement JSON are marked NOT CAPTURED.

---

## N. Statement of Scope

**Add Visit / Edit Visit UI refinement (this review subject)** was implemented as a UI-only change set:

- Blade templates for visit form / commodity rows / shared banner partial usage  
- Scoped CSS in `health-records-non-resident-family-planning.css`  
- Existing commodity JS file (behavior not redesigned for this packaging; included for review)  
- Feature test assertion updates for headings/actions  

**For this Add/Edit Visit UI refinement, the following were NOT modified as part of the intended change set:**

- Controllers  
- Routes  
- Models  
- Migrations  
- Database schema / dumps  
- Persistence / business-logic services beyond existing UI preview behavior  

**Honesty note:** Repository `git status` shows additional modified paths outside this package (other modules and possibly shared routes/controllers from other WIP). Claude should treat `git-status.txt` / `git-diff.txt` as the authority for what is dirty in the tree at packaging time, and limit Production Freeze judgment for **Add/Edit Visit** to the files listed in sections B–D unless those unrelated diffs prove otherwise.
