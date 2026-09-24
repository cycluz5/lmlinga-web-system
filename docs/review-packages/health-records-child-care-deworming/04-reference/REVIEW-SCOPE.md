# Review Scope — Health Records → Child Care → Deworming

## Feature

Health Records → Child Care → Deworming

## Workflow

Deworming Summary → Individual Deworming Record → Add Deworming Record

## Production status

NOT YET PRODUCTION FROZEN — this package is for independent Claude review.

---

## SUMMARY PAGE

Route: `/health-records/child-care/deworming`

- Remains the Child Care Deworming monitoring summary
- Child Care service pills remain here (Vitamin A / Deworming / Operation Timbang)
- Deworming pill is active (`aria-current="page"`)
- Actions: **+ Add** and **Export Data**
- Six resident rows with **View** actions each
- No user-facing Non-Residents navigation

Resident coverage (all must have View → resident Deworming record):

- Kristine B. Reyes
- Jacob A. Magistrado
- Haziel H. Santos
- Andrei B. Malaya
- Crisley F. Fernando
- Gabriel Allan S. Chua

---

## INDIVIDUAL PAGE

Route: `/health-records/child-care/deworming/{childKey}`

- H1: **Child Care | Deworming**
- Subtitle: Deworming record for the selected child.
- Back navigation to Deworming summary
- **No** Vitamin A / Deworming / Operation Timbang service pills
- Complete resident profile (no dash placeholders)
- Deworming Record section with decorative semantic icon (`bi-capsule`, `aria-hidden="true"`)
- History table: Year, Round, SE Status, Date Given, Remarks
- **+ Add Record** → resident create page

---

## ADD RECORD PAGE

Route: `/health-records/child-care/deworming/{childKey}/create`

- H1: **Child Care | Deworming**
- Subtitle: Add a Deworming record for the selected child.
- Back navigation to Individual Deworming Record
- **No** service pills
- Complete resident profile (same no-placeholder rule)
- Add Deworming Record section with decorative icon (`bi-journal-medical`, `aria-hidden="true"`)
- Round Information fields: Year, Deworming Round, SE Status, Date Given, Remarks
- Cancel / Save

---

## DATA RULE

No meaningless placeholders in visible Deworming profile/history cells:

- `-`
- `—`
- `--`
- `---`

Use descriptive values, e.g. **Not yet school-aged**, **No concerns reported**, **NHTS** / **Non-NHTS**.

---

## NON-RESIDENT BOUNDARY

The reviewed resident workflow must **not** navigate users into:

`health-records.child-care.non-residents.*`

View / show / create destinations must be resident Deworming routes only.

---

## PERSISTENCE LIMITATION (must be reviewed honestly)

Add Deworming Record **Save** remains the approved **UI-phase preview** behavior.

There is **no** resident Deworming POST/store persistence route in this package.
