# ERD Design Rationale (defense notes)

Short answers to the schema questions we expect from the panel. Line references are to
`Final_DB_9-24-26.sql` after the `erd_schema_fixes.sql` corrections.

## 1. `resident_statuses` — why it references `death_records`

Earlier builds of the dump had a `resident_statuses.death_request_id` FK pointing at
`death_requests`, a table from the pre-ERD prototype that does not exist in the final
database. It is corrected:

| Column | References | On delete |
|---|---|---|
| `resident_id` | `residents(resident_id)` | RESTRICT |
| `death_record_id` | `death_records(death_record_id)` | SET NULL |

The varchar `household_no` / `member_id` lookups were removed in favor of the real
`resident_id` FK, so the table follows the same FK discipline as the rest of the schema.
One row per resident (`uq_resstatus_resident`). The row is written in the same transaction
that verifies the death record, and `death_record_id` points to the verified record that
justifies the status.

Verified with: fresh import of the dump, then a check that no FK references a missing
table (0 rows), 72 FKs total.

## 2. `offline_sync_receipts` and `announcements` — enforced FKs

| Table.column | References | On delete |
|---|---|---|
| `offline_sync_receipts.actor_user_id` | `user_management(user_id)` | RESTRICT |
| `offline_sync_receipts.household_pk` | `households(household_id)` | SET NULL |
| `offline_sync_receipts.resident_pk` | `residents(resident_id)` | SET NULL |
| `announcements.posted_by_user_id` | `user_management(user_id)` | SET NULL |

**Why this is safe for offline sync:** the device queues the operation, and the server applies
it and writes the receipt **inside the same database transaction, after** the household or
resident row is inserted (`OfflineSyncService`). A receipt therefore never arrives before its
parent row, so a stale or mistyped `resident_pk` is rejected by the database instead of
silently matching nothing.

**Why SET NULL:** a receipt is the idempotency record that stops a replayed offline
operation from being applied twice. If the household or resident is later removed, the
receipt must survive so a late replay is still recognized; only the link is cleared.
`household_no` / `member_no` stay on the receipt as the human-readable trace.

## 3. `announcements.posted_by_name` / `posted_by_role` — intentional snapshot

These are a point-in-time snapshot of who posted the announcement, not accidental
duplication. An announcement is a published notice: it must keep reading
the original poster's name and role even if that staff member is later renamed, reassigned
to a different role, or deactivated. Joining to `user_management` would silently rewrite
history. The live link is kept too: `posted_by_user_id` is an enforced FK.

## 4. Maternal Care — why ~17 one-to-one child tables instead of one wide table

Each child of `maternal_care` (e.g. `hiv_screening`, `syphilis_screening`,
`hepatitis_b_screening`, `gdm_screening`, `cbc_hgb_hct_screening`, `urinalysis_screening`,
`ultrasound_screening`, `ifa_supplementation`, `mms_supplementation`, `prenatal_visits`,
`delivery_outcomes`, …) corresponds to a **distinct clinical form or checklist** in the
health center's actual workflow.

- Each form is completed at a different visit, often by a different staff member; separate
  tables let each one be saved independently.
- A consolidated table would be very wide and mostly NULL, since most patients
  have only some screenings on file at any time.
- Each table carries only the fields and constraints of its own form, so adding or revising
  one DOH form does not alter the others.
- Every child table has an enforced FK to `maternal_care(maternal_care_id)`.
