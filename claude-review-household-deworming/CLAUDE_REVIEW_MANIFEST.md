# LMLinga Claude Review Package
## Household Profiling → Child Care → Deworming
### Eligibility + Resident Identity Fix

---

## 1. Review scope

Independent Claude Final Verification of the targeted refinement that:

- Keeps **Health Records → Child Care → Deworming** as barangay-wide monitoring (Export Data + View only; no Add).
- Moves individual Deworming record management to **Household Profiling → Member → Child Care → Deworming**.
- Applies the existing **0–59 month Child Care eligibility rule** to Household Profiling Deworming exposure and Add Record gating.
- Fixes resident identity resolution so `{householdNo, memberId}` always drives profile/history — no cross-household name-slug fallback.

This package contains source and test evidence only. It is not a runnable Laravel project.

---

## 2. Original defect

**Eligibility gap:** Household Profiling showed the Deworming Child Care link and allowed Add Record for ineligible adults (e.g. HH-151 / MB-001 Kristine Reyes, birthday May 4, 1991), despite the project’s existing Child Care population rule (0–59 months).

**Identity-mapping risk:** `HealthRecordsDeworming::findChildForMember()` previously called `findChild($childKey)`, which searched all households by slugified display name. When matched, only `household_no` / `member_id` were overwritten while profile fields could come from a different child with a similar name in another household.

**Observed UI symptom (pre-fix):** Adult member Kristine Reyes could reach Deworming workflow; child-specific deworming history or Add Record could appear inappropriately or identity could be inconsistent.

---

## 3. Existing 0–59 month Child Care eligibility rule

Defined in `app/Support/HealthRecordsChildCare.php`:

- `MAX_AGE_MONTHS = 59`
- `isChildCarePopulation(array $member): bool` — returns true when `ageInMonths($member) <= 59`

Already used by Health Records barangay-wide Child Care row aggregation and HR Deworming cross-lookup. This refinement applies the same rule to Household Profiling Deworming accordion visibility and record-management gating.

---

## 4. Identity-mapping issue that was corrected

**Before:** `findChildForMember()` → `findChild(slugify(displayName($member)))` → possible cross-household match → partial ID overwrite.

**After:**
- `findChildForMember()` always builds profile from the selected `{householdNo, memberId}` via `buildChildProfileFromHousehold()`.
- `recordsForMember(householdNo, memberId)` returns monitoring fixture history only when the member is Child Care–eligible and their slug matches a monitoring key.
- `memberCanManageRecords($member)` wraps `isChildCarePopulation()`.
- Household Deworming show/create wrappers expose `data-household-no` and `data-member-id`.

---

## 5. Files included

### Required implementation files
| File | Included |
|------|----------|
| `app/Support/HealthRecordsDeworming.php` | Yes |
| `app/Support/HealthRecordsChildCare.php` | Yes |
| `resources/views/pages/household-profiling/member-view.blade.php` | Yes |
| `resources/views/pages/health-records/child-care-deworming-show.blade.php` | Yes |
| `resources/views/pages/health-records/child-care-deworming-create.blade.php` | Yes |
| `routes/web.php` | Yes |

### Required test files
| Requested path | Actual repository path | Included |
|----------------|------------------------|----------|
| `tests/Feature/HouseholdProfilingDewormingTest.php` | Same | Yes |
| `tests/Feature/HouseholdProfilingHouseholdMemberViewTest.php` | Same | Yes |
| `tests/Feature/HealthRecords/DewormingTest.php` | **`tests/Feature/HealthRecordsDewormingTest.php`** | Yes (actual path) |

> Note: The repository does not contain `tests/Feature/HealthRecords/DewormingTest.php`. The actual test class/file is `HealthRecordsDewormingTest.php` at `tests/Feature/HealthRecordsDewormingTest.php`.

### Additional dependency files (directly required for review understanding)
| File | Why included |
|------|--------------|
| `app/Support/DemoCatalog.php` | Household/member normalization and lookup used by Deworming routes and support class. |
| `resources/demo/households.php` | Demo fixture for HH-151 MB-001 (ineligible adult) and MB-009 (eligible child); defines `lml_demo_find_member()`. |
| `resources/views/pages/health-records/partials/child-care-deworming-profile.blade.php` | Included by show/create views; displays resident profile fields under review. |
| `app/Support/UiRole.php` | Referenced by tests for `sidebarActiveKey()` assertions on household Deworming pages. |
| `app/Http/Controllers/HealthRecords/ChildCareSummaryController.php` | HR Deworming show route sets `canAddRecord => false` (read-only HR View path). |
| `resources/views/pages/health-records/child-care-deworming.blade.php` | HR barangay-wide monitoring summary; tests assert absence of `data-hr-dw-add` (+ Add removed). |

---

## 6. Purpose of each included file

| File | Purpose |
|------|---------|
| `HealthRecordsDeworming.php` | Core Deworming support: member-scoped profile, eligibility gate, records lookup, household profiling URLs. |
| `HealthRecordsChildCare.php` | Authoritative 0–59 month eligibility rule and age/display helpers. |
| `DemoCatalog.php` | Resolves households/members by stable IDs in routes and support code. |
| `households.php` | Demo data for verification cases (MB-001 vs MB-009). |
| `member-view.blade.php` | Child Care accordion; Deworming link conditional on eligibility. |
| `child-care-deworming-show.blade.php` | Individual Deworming record page; `canAddRecord` gate; member data attributes. |
| `child-care-deworming-create.blade.php` | Add Deworming Record form; member data attributes; cancel/save return URL. |
| `child-care-deworming-profile.blade.php` | Resident profile partial (name, DOB, age, school). |
| `child-care-deworming.blade.php` | HR monitoring summary (Export + View only). |
| `ChildCareSummaryController.php` | HR controller methods for Deworming listing/show/create. |
| `web.php` | Route definitions for household Deworming show/create and HR Deworming routes. |
| `UiRole.php` | Sidebar active-key resolution used in tests. |
| `HouseholdProfilingDewormingTest.php` | Eligibility, identity, and workflow tests for household path. |
| `HouseholdProfilingHouseholdMemberViewTest.php` | Accordion link visibility regression tests. |
| `HealthRecordsDewormingTest.php` | HR monitoring page tests including no summary + Add. |

---

## 7. Files modified by this refinement

| File | Change summary |
|------|----------------|
| `app/Support/HealthRecordsDeworming.php` | Identity fix; `recordsForMember()`, `memberCanManageRecords()`. |
| `resources/views/pages/household-profiling/member-view.blade.php` | Conditional Deworming accordion entry. |
| `routes/web.php` | Eligibility-gated household Deworming show/create routes. |
| `resources/views/pages/health-records/child-care-deworming-show.blade.php` | Back URL, `canAddRecord`, household/member data attributes. |
| `resources/views/pages/health-records/child-care-deworming-create.blade.php` | Household/member data attributes. |
| `tests/Feature/HouseholdProfilingDewormingTest.php` | New/expanded eligibility and identity tests. |
| `tests/Feature/HouseholdProfilingHouseholdMemberViewTest.php` | MB-001 no Deworming link assertion. |
| `tests/Feature/HealthRecordsDewormingTest.php` | HR summary no + Add assertions (from prior workflow migration). |

Related prior refinement (workflow ownership, not re-reviewed here but referenced by tests):
- `resources/views/pages/health-records/child-care-deworming.blade.php` — + Add removed from HR summary header.
- `app/Http/Controllers/HealthRecords/ChildCareSummaryController.php` — `canAddRecord => false` on HR show.

---

## 8. Relevant tests

| Test file | Focus |
|-----------|-------|
| `tests/Feature/HouseholdProfilingDewormingTest.php` | Eligibility rule, identity mapping, similar-name isolation, Add Record gating, create redirect for ineligible members. |
| `tests/Feature/HouseholdProfilingHouseholdMemberViewTest.php` | Accordion: 3 links for ineligible MB-001; other Child Care links intact. |
| `tests/Feature/HealthRecordsDewormingTest.php` | HR summary Export-only; View links; HR show read-only (no Add Record). |

---

## 9. Exact test commands previously executed

From project root (`lmlingaFinal`):

```
php artisan test --filter=HouseholdProfilingDewormingTest
php artisan test --filter=HouseholdProfilingHouseholdMemberViewTest
php artisan test --filter=HealthRecordsDewormingTest
```

Re-verified during package preparation on 2026-08-17 (same commands in sequence).

---

## 10. Exit codes

| Command | Exit code |
|---------|-----------|
| `php artisan test --filter=HouseholdProfilingDewormingTest` | **0** |
| `php artisan test --filter=HouseholdProfilingHouseholdMemberViewTest` | **0** |
| `php artisan test --filter=HealthRecordsDewormingTest` | **0** |
| `npm run build` | **0** |

---

## 11. Number of tests passed

| Test file | Tests passed |
|-----------|--------------|
| `HouseholdProfilingDewormingTest.php` | **10** |
| `HouseholdProfilingHouseholdMemberViewTest.php` | **8** |
| `HealthRecordsDewormingTest.php` | **17** |
| **Total** | **35** |

---

## 12. Assertion counts

| Test file | Assertions |
|-----------|------------|
| `HouseholdProfilingDewormingTest.php` | **63** |
| `HouseholdProfilingHouseholdMemberViewTest.php` | **56** |
| `HealthRecordsDewormingTest.php` | **190** |
| **Total** | **309** |

---

## 13. Build command and exit code

```
npm run build
```

Exit code: **0**

(Vite v6.4.3 production build completed successfully.)

---

## 14. User UI verification observations

User desktop UI inspection confirmed:

- **HH-151 / MB-001 — Kristine Reyes (adult):** Deworming correctly **not** shown in Child Care accordion. Child Immunization, School-Based Immunization, and Child Nutrition remain available.
- **HH-151 / MB-009 — Kristine B. Reyes (child):** Deworming correctly shown in Child Care accordion. View opens for MB-009 with correct child identity. Deworming history belongs to the selected child. + Add Record available. Add Deworming Record retains HH-151 / MB-009 identity.

---

## 15. Explicit out-of-scope behavior

- Child Immunization, School-Based Immunization, and Child Nutrition accordion links remain visible for **all** members (pre-existing; unchanged).
- Health Records Vitamin A, Operation Timbang, Risk Assessment, Family Planning, Maternal, Death modules — not modified.
- Sidebar architecture, authentication, database schema — unchanged.
- Non-resident Deworming routes — unchanged.
- Direct URL access to `/household-profiling/{householdNo}/members/{memberId}/deworming` for ineligible members still renders read-only page (link hidden from accordion; Add Record blocked).

---

## 16. Remaining known concerns

1. **MB-003 Angelo David Reyes** (age 5 ≈ 60 months) is just outside the 59-month ceiling — Deworming link will not appear. This follows the existing rule exactly.
2. **Child Care accordion (non-Deworming)** still shows for ineligible adults — intentional; only Deworming is eligibility-gated.
3. **Empty-state copy** remains child-specific: “No deworming records recorded for this child.” — appropriate because eligibility restricts to Child Care population (0–59 months).

---

## Package note for reviewers

- `routes/web.php` is the full application route file (large). Focus review on household Deworming routes (~lines 432–487) and HR Deworming routes (~lines 903–921).
- `ChildCareSummaryController.php` contains other Child Care methods; focus on `deworming()`, `dewormingShow()`, `dewormingCreate()`.
