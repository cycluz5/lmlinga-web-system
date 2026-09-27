-- ERD schema integrity fixes for Final_DB_9-24-26.sql, on database `lmlinga`.
--   1. resident_statuses: drop the dangling FK to the non-existent `death_requests`
--      table; rebuild with real FKs to residents(resident_id) and death_records(death_record_id)
--      instead of varchar household_no/member_id lookups.
--   2. offline_sync_receipts / announcements: enforce the references that were soft.
-- BACK UP FIRST.  mysqldump lmlinga > before_erd_schema_fixes.sql
--
-- Pre-flight: each of these must return 0 rows before the ALTERs below will succeed.
--   SELECT COUNT(*) FROM resident_statuses;   -- expected 0 in ERD mode (status is derived from death_records)
--   SELECT r.id, r.actor_user_id FROM offline_sync_receipts r
--     LEFT JOIN user_management u ON u.user_id = r.actor_user_id WHERE u.user_id IS NULL;
--   SELECT r.id, r.household_pk FROM offline_sync_receipts r
--     LEFT JOIN households h ON h.household_id = r.household_pk WHERE r.household_pk IS NOT NULL AND h.household_id IS NULL;
--   SELECT r.id, r.resident_pk FROM offline_sync_receipts r
--     LEFT JOIN residents x ON x.resident_id = r.resident_pk WHERE r.resident_pk IS NOT NULL AND x.resident_id IS NULL;
--   SELECT a.id, a.posted_by_user_id FROM announcements a
--     LEFT JOIN user_management u ON u.user_id = a.posted_by_user_id WHERE a.posted_by_user_id IS NOT NULL AND u.user_id IS NULL;
USE `lmlinga`;

--
-- Table structure for table `resident_statuses` (ERD shape)
--
DROP TABLE IF EXISTS `resident_statuses`;
CREATE TABLE `resident_statuses` (
  `resident_status_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('Active','Deceased') NOT NULL DEFAULT 'Active',
  `death_record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resident_status_id`),
  UNIQUE KEY `uq_resstatus_resident` (`resident_id`),
  KEY `idx_resstatus_status` (`status`),
  KEY `fk_resstatus_death` (`death_record_id`),
  CONSTRAINT `fk_resstatus_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  CONSTRAINT `fk_resstatus_death` FOREIGN KEY (`death_record_id`) REFERENCES `death_records` (`death_record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Constraints for table `offline_sync_receipts`
-- Receipts are written in the same transaction, after the parent row exists.
-- SET NULL keeps the idempotency/audit receipt if the parent is later removed.
--
ALTER TABLE `offline_sync_receipts`
  ADD KEY `fk_syncrcpt_household` (`household_pk`),
  ADD KEY `fk_syncrcpt_resident` (`resident_pk`),
  ADD CONSTRAINT `fk_syncrcpt_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `user_management` (`user_id`),
  ADD CONSTRAINT `fk_syncrcpt_household` FOREIGN KEY (`household_pk`) REFERENCES `households` (`household_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_syncrcpt_resident` FOREIGN KEY (`resident_pk`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL;

--
-- Constraints for table `announcements`
-- posted_by_name / posted_by_role remain a point-in-time snapshot of the poster.
--
ALTER TABLE `announcements`
  ADD KEY `fk_announce_user` (`posted_by_user_id`),
  ADD CONSTRAINT `fk_announce_user` FOREIGN KEY (`posted_by_user_id`) REFERENCES `user_management` (`user_id`) ON DELETE SET NULL;
