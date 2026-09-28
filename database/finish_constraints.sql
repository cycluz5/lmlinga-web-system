-- Finishes the FOREIGN KEY step of Final_DB_9-24-26.sql (stopped at prenatal_visits).
-- Run ONLY after the orphan prenatal visit is dealt with (see DELETE below), on database `lmlinga`.
-- BACK UP FIRST.
--   SELECT * FROM prenatal_visits WHERE maternal_care_id = 6;      -- inspect
--   DELETE FROM prenatal_visits WHERE prenatal_visit_id = 12;       -- remove the orphan (one row)
USE `lmlinga`;
--
-- Constraints for table `prenatal_visits`
--
ALTER TABLE `prenatal_visits`
  ADD CONSTRAINT `fk_prenatal_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `record_requests`
--
ALTER TABLE `record_requests`
  ADD CONSTRAINT `fk_recreq_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`),
  ADD CONSTRAINT `fk_recreq_resident` FOREIGN KEY (`matched_resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `red_flags_assessment`
--
ALTER TABLE `red_flags_assessment`
  ADD CONSTRAINT `fk_redflags_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`);

--
-- Constraints for table `residents`
--
ALTER TABLE `residents`
  ADD CONSTRAINT `fk_resident_household` FOREIGN KEY (`household_id`) REFERENCES `households` (`household_id`),
  ADD CONSTRAINT `fk_resident_occupation` FOREIGN KEY (`occupation_id`) REFERENCES `occupation` (`occupation_id`),
  ADD CONSTRAINT `fk_resident_religion` FOREIGN KEY (`religion_id`) REFERENCES `religion` (`religion_id`),
  ADD CONSTRAINT `fk_resident_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `resident_accounts`
--
ALTER TABLE `resident_accounts`
  ADD CONSTRAINT `fk_resident_accounts_resident_id` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL;

--
-- Constraints for table `resident_password_resets`
--
ALTER TABLE `resident_password_resets`
  ADD CONSTRAINT `fk_residentreset_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`);

-- (skipped) resident_statuses -> death_requests: that table does not exist in Final_DB_9-24-26.sql

--
-- Constraints for table `risk_assessment`
--
ALTER TABLE `risk_assessment`
  ADD CONSTRAINT `fk_riskassess_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  ADD CONSTRAINT `fk_riskassess_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `rusf_supplementation`
--
ALTER TABLE `rusf_supplementation`
  ADD CONSTRAINT `fk_rusfsupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `school_immunization`
--
ALTER TABLE `school_immunization`
  ADD CONSTRAINT `fk_schoolimm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `staff_password_resets`
--
ALTER TABLE `staff_password_resets`
  ADD CONSTRAINT `fk_staffreset_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `syphilis_screening`
--
ALTER TABLE `syphilis_screening`
  ADD CONSTRAINT `fk_syphilis_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `td_immunization`
--
ALTER TABLE `td_immunization`
  ADD CONSTRAINT `fk_tdimm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `timbang_records`
--
ALTER TABLE `timbang_records`
  ADD CONSTRAINT `fk_timbang_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `ultrasound_screening`
--
ALTER TABLE `ultrasound_screening`
  ADD CONSTRAINT `fk_ultrasound_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `urinalysis_screening`
--
ALTER TABLE `urinalysis_screening`
  ADD CONSTRAINT `fk_urinalysis_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `user_management`
--
ALTER TABLE `user_management`
  ADD CONSTRAINT `fk_user_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `visual_screening`
--
ALTER TABLE `visual_screening`
  ADD CONSTRAINT `fk_visualscreen_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`);

--
-- Constraints for table `waste_management_practices`
--
ALTER TABLE `waste_management_practices`
  ADD CONSTRAINT `fk_waste_env` FOREIGN KEY (`env_assessment_id`) REFERENCES `environmental_sanitation` (`env_assessment_id`);

--
-- Constraints for table `worker_appointments`
--
ALTER TABLE `worker_appointments`
  ADD CONSTRAINT `fk_appt_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `worker_appointment_zones`
--
ALTER TABLE `worker_appointment_zones`
  ADD CONSTRAINT `fk_worker_appt_zones_appt` FOREIGN KEY (`appointment_id`) REFERENCES `worker_appointments` (`appointment_id`) ON DELETE CASCADE;
COMMIT;

