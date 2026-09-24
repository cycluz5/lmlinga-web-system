# Production Freeze Record

## Freeze status

**PRODUCTION FROZEN**  
(established after approved targeted patch)

**HEALTH RECORDS → MATERNAL CARE — PRODUCTION FROZEN**

## Module / frozen scope

Health Records → Maternal Care **resident-facing workflow**, including the approved removal of the user-facing Non-Residents navigation/control.

This freeze covers the resident Maternal Care summary/listing page and its Health Records sidebar destination.

The legacy Non-Resident Maternal stack is **intentionally preserved** and is **not** unfinished cleanup. It is outside normal resident navigation.

---

## Freeze date

**2026-08-16**

## Independent reviewer

**Claude**

## Claude final verdict

**A. APPROVED — READY FOR PRODUCTION FREEZE**

- Blocking defects: **NONE**
- Non-blocking defects: **NONE**

Claude independently verified:

- targeted removal is complete
- resident workflow remains intact
- legacy backend remains intentionally preserved
- resident navigation no longer exposes Non-Residents
- accessibility remains acceptable
- responsive behavior remains acceptable
- no page-level horizontal overflow at tested widths
- narrow-width internal table scrolling is intentional
- tests meaningfully cover the change
- build evidence supports successful production build
- no blocking regression exists

---

## Approved targeted change

Remove the user-facing Non-Residents destination from the normal resident Health Records → Maternal Care listing.

Specifically removed from the resident listing:

- `"Non Residents"` user-facing control/pill/link
- resident `href` to the Non-Resident listing (`health-records.maternal.non-residents.index`)
- associated `aria-label` (`Open Maternal Care Non Residents listing`)
- associated data attribute (`data-hr-mc-non-residents`)
- unnecessary/empty left-side action wrapper (`.lml-hr-mc__action-left`)

No replacement Non-Residents tab, button, pill, sidebar destination, or other normal resident navigation path was introduced.

Resident Maternal workflow remains preserved.

---

## Frozen resident functionality

Protect the current Health Records → Maternal Care resident baseline, including:

- Maternal Care page heading
- resident page description
- Total Pregnancy Clients
- High Risk Pregnancies
- Due for Prenatal Visit
- Delivered Cases
- Incomplete Prenatal
- Add
- Export Data
- search
- zone filtering
- year filtering
- resident Maternal table (name, age group, LMP, gravida/parity, EDD, delivery type, trimester, prenatal visits)
- Maternal sidebar destination
- Maternal active sidebar state
- current responsive behavior
- current accessibility behavior

The resident Maternal Care page must continue **not** to expose:

- `"Non Residents"` control/pill/link
- Residents / Non-Residents tabs
- Non-Residents sidebar entry
- empty left-side action region
- dangling accessibility attributes associated with the removed UI

Do **not** redesign, refactor, or alter clinical/business behavior of the resident listing under this freeze.

---

## Preserved legacy Non-Resident backend status

Legacy Non-Resident Maternal implementation remains **intentionally preserved** and is **NOT unfinished cleanup**.

This includes, where applicable:

- Non-Resident routes (`index`, `create`, `store`, `show`)
- `NonResidentMaternalController`
- support classes
- request/validation classes
- fixtures
- listing / create / show views
- related CSS/JS
- direct URL reachability
- tests covering those routes

Those components remain **outside** normal resident navigation. Direct URL reachability is **accepted** and is **not** a defect under this approved patch.

Do **not** delete, refactor, disable, rename, redirect, or treat this preservation as unfinished cleanup unless:

1. a verified defect/regression is found; or
2. a newly approved requirement explicitly authorizes the change.

---

## Patch scope (approved files)

Targeted production file:

- `resources/views/pages/health-records/maternal.blade.php`

Targeted test file:

- `tests/Feature/HealthRecordsMaternalTest.php`

QA capture script (not production runtime):

- `scripts/capture-hr-maternal-hide-nr-nav.mjs`

---

## Accepted test evidence

These results were independently reviewed by Claude. They were **not** re-run solely to create this freeze record.

### Maternal suite

File:

- `tests/Feature/HealthRecordsMaternalTest.php`

Command:

```
php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsMaternalTest.php
```

| Field | Value |
|-------|--------|
| Exit code | `0` |
| Tests passed | `23` |
| Assertions | `349` |

### Sidebar navigation suite

File:

- `tests/Feature/HealthRecordsSidebarNavigationTest.php`

Command:

```
php vendor/phpunit/phpunit/phpunit tests/Feature/HealthRecordsSidebarNavigationTest.php
```

| Field | Value |
|-------|--------|
| Exit code | `0` |
| Tests passed | `14` |
| Assertions | `200` |

---

## Accepted build evidence

These results were independently reviewed by Claude. The build was **not** re-run solely to create this freeze record.

| Field | Value |
|-------|--------|
| Command | `npm run build` |
| Result | successful Vite production build |
| Notes | Vite 6.4.3; 155 modules transformed |

Claude noted that the packaged `build-results.txt` did not contain a literal explicit **EXIT CODE** line, while the Vite output showed a clean successful completion. That packaging note is **INFORMATIONAL ONLY** (see INFO-2). It does **not** reopen implementation.

---

## Accepted responsive baseline

Reviewed viewports:

- 1440×900
- 1366×768
- 820×1180
- 390×844

Accepted findings:

- no page-level horizontal overflow at any tested viewport
- internal Maternal table scrolling at **820px** and **390px** is intentional and accepted
- Add / Export remain usable
- cards / filters remain usable
- mobile navigation remains functional
- no orphaned gap remains after Non-Residents control removal

Internal table scrolling is **not** page-level overflow.

---

## Informational notes (not defects)

**INFO-1:** Unused legacy `.lml-hr-mc__scope-pill` and `.lml-hr-mc__action-left` CSS may remain. Claude confirmed these rules are harmless/inert. Do **not** remove them solely as cleanup.

**INFO-2:** The review package’s build-results file did not contain a literal EXIT CODE line. The Vite output itself showed a successful build. This is an evidence-capture improvement for **future packages only**. Do **not** reopen this frozen implementation because of it.

**INFO-3:** Legacy Non-Resident Maternal pages remain directly URL-reachable. This is intentional under the approved scope. Do **not** classify this as a defect.

---

## Freeze protection rules

After this declaration, Health Records → Maternal Care (resident-facing workflow as defined above) is **PRODUCTION FROZEN**.

Do **not** subsequently:

- redesign
- modernize
- refactor
- cosmetically polish
- clean up unused CSS solely because it is unused
- reorganize Blade markup
- change spacing or typography for preference
- alter responsive behavior
- change sidebar architecture
- modify routes
- modify controllers
- modify database/schema behavior
- remove the preserved legacy Non-Resident backend merely because it is no longer exposed through resident navigation
- introduce new Maternal Care functionality

unless:

1. a verified regression/bug is discovered; or
2. a newly approved requirement explicitly authorizes a targeted post-freeze change.

Any future approved change must use the **Production Freeze Bug Patch / Limited-Scope Change Process** and remain limited to the explicitly authorized scope.

---

## Review package (evidence, not freeze itself)

`docs/qa/review-packages/LMLinga-Health-Records-Maternal-Remove-Non-Residents-Claude-Review.zip`

This freeze record is documentation/status only. It does not modify production implementation.

---

## Final statement

**HEALTH RECORDS → MATERNAL CARE — PRODUCTION FROZEN**
