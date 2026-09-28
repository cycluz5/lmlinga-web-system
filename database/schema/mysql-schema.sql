/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `adult_immunization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adult_immunization` (
  `adult_immunization_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `vaccine_type` varchar(64) NOT NULL,
  `date_given` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`adult_immunization_id`),
  UNIQUE KEY `uq_adult_imm_resident_vaccine` (`resident_id`,`vaccine_type`),
  KEY `idx_adult_imm_resident` (`resident_id`),
  CONSTRAINT `fk_adult_imm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcement_age_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_age_presets` (
  `announcement_age_preset_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` bigint(20) unsigned NOT NULL,
  `preset` varchar(32) NOT NULL,
  PRIMARY KEY (`announcement_age_preset_id`),
  UNIQUE KEY `uq_announcement_age_preset` (`announcement_id`,`preset`),
  CONSTRAINT `announcement_age_presets_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcement_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_zones` (
  `announcement_zone_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` bigint(20) unsigned NOT NULL,
  `zone` varchar(64) NOT NULL,
  PRIMARY KEY (`announcement_zone_id`),
  UNIQUE KEY `uq_announcement_zone` (`announcement_id`,`zone`),
  CONSTRAINT `announcement_zones_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `message` varchar(500) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `place` varchar(120) DEFAULT NULL,
  `target_group` varchar(32) NOT NULL,
  `age_min_months` smallint(5) unsigned DEFAULT NULL,
  `age_max_months` smallint(5) unsigned DEFAULT NULL,
  `estimated_reach` int(10) unsigned DEFAULT NULL,
  `posted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `posted_by_role` varchar(16) NOT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `announcements_event_date_index` (`event_date`),
  KEY `announcements_posted_at_index` (`posted_at`),
  KEY `announcements_target_group_index` (`target_group`),
  KEY `fk_announce_user` (`posted_by_user_id`),
  CONSTRAINT `fk_announce_user` FOREIGN KEY (`posted_by_user_id`) REFERENCES `user_management` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cbc_hgb_hct_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cbc_hgb_hct_screening` (
  `cbc_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('With Anemia','Without Anemia') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cbc_screening_id`),
  UNIQUE KEY `uq_cbc_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_cbc_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cc_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cc_supplementation` (
  `cc_supp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `visit_number` tinyint(3) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cc_supp_id`),
  UNIQUE KEY `uq_ccsupp_visit` (`maternal_care_id`,`visit_number`),
  CONSTRAINT `fk_ccsupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chatbot_conversations` (
  `conversation_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`conversation_id`),
  KEY `fk_chatconv_account` (`account_id`),
  CONSTRAINT `fk_chatconv_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chatbot_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chatbot_messages` (
  `message_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender` enum('Resident','Chatbot') NOT NULL,
  `message_text` text NOT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'bcl',
  `category` varchar(50) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `fk_chatmsg_conv` (`conversation_id`),
  CONSTRAINT `fk_chatmsg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `chatbot_conversations` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `child_immunizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `child_immunizations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `cpab` enum('at_least_2_doses_1_month_prior','tt3_td3_to_tt5_td5_prior') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_child_immunizations_resident` (`resident_id`),
  KEY `fk_child_immunizations_resident` (`resident_id`),
  CONSTRAINT `fk_child_immunizations_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `child_nutrition`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `child_nutrition` (
  `child_nutrition_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `length_at_birth_cm` decimal(5,2) DEFAULT NULL,
  `weight_at_birth_kg` decimal(5,2) DEFAULT NULL,
  `initiated_breastfeeding_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`child_nutrition_id`),
  KEY `fk_childnutr_resident` (`resident_id`),
  CONSTRAINT `fk_childnutr_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cvc_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cvc_screening` (
  `cvc_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `value` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`cvc_screening_id`),
  UNIQUE KEY `uq_cvc_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_cvc_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `death_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `death_records` (
  `death_record_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `cause_of_death` text NOT NULL,
  `date_of_death` date NOT NULL,
  `death_certificate_no` text NOT NULL,
  `death_certificate_file_path` varchar(255) NOT NULL,
  `verification_status` enum('Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Pending Verification',
  `rejection_reason` text DEFAULT NULL,
  `submitted_by` bigint(20) unsigned NOT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`death_record_id`),
  UNIQUE KEY `uq_death_resident` (`resident_id`),
  KEY `fk_death_submitted_by` (`submitted_by`),
  KEY `fk_death_verified_by` (`verified_by`),
  CONSTRAINT `fk_death_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  CONSTRAINT `fk_death_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `user_management` (`user_id`),
  CONSTRAINT `fk_death_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_outcomes` (
  `delivery_outcome_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `outcome` enum('FT','PT','FD','AB') DEFAULT NULL,
  `delivery_type` enum('CS','VD','CVCD') DEFAULT NULL,
  `birth_weight_kg` decimal(5,2) DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `date_time_of_delivery` datetime DEFAULT NULL,
  `date_terminated` date DEFAULT NULL,
  `birth_attendant` enum('MD','RN','MW','Others') DEFAULT NULL,
  `birth_attendant_other` varchar(150) DEFAULT NULL,
  `place_of_delivery` enum('Public Health Facility','Private Health Facility','Non-Health Facility') DEFAULT NULL,
  `facility_name` varchar(150) DEFAULT NULL,
  `bemonc_cemonc_capable` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `newborn_sex` enum('Female','Male') DEFAULT NULL,
  `plurality` enum('Single','Twins','Multiple') DEFAULT NULL,
  `plurality_number` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`delivery_outcome_id`),
  UNIQUE KEY `uq_delivery_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_delivery_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deworming_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deworming_records` (
  `deworming_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `year` year(4) NOT NULL,
  `deworming_round` enum('1','2') NOT NULL,
  `date_given` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`deworming_id`),
  UNIQUE KEY `uq_deworm_round` (`resident_id`,`year`,`deworming_round`),
  CONSTRAINT `fk_deworm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deworming_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deworming_supplementation` (
  `deworming_supp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`deworming_supp_id`),
  KEY `fk_dewormsupp_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_dewormsupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `disability_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `disability_type` (
  `disability_type_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `no_disability` text DEFAULT NULL,
  `intellectual_disability` text DEFAULT NULL,
  `mental_disability` text DEFAULT NULL,
  `physical_disability` text DEFAULT NULL,
  `other_disability` text DEFAULT NULL,
  `other_disability_specify` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`disability_type_id`),
  UNIQUE KEY `uq_disability_resident` (`resident_id`),
  CONSTRAINT `fk_disability_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `environmental_sanitation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `environmental_sanitation` (
  `env_assessment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `household_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `water_supply_status` enum('Level I','Level II','Level III','Others') NOT NULL,
  `water_source_location` varchar(100) NOT NULL,
  `water_availability` tinyint(1) NOT NULL,
  `microbiological_validation_date` date DEFAULT NULL,
  `microbio_result` enum('Passed','Failed') DEFAULT NULL,
  `physico_chem_test_date` date DEFAULT NULL,
  `physico_chem_result` enum('Passed','Failed') DEFAULT NULL,
  `toilet_type` varchar(50) DEFAULT NULL,
  `open_defecation_place` tinyint(1) DEFAULT NULL,
  `shared_toilet` tinyint(1) DEFAULT NULL,
  `sewage_disposal_method` enum('On-site Disposed','Off-site Disposed') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`env_assessment_id`),
  KEY `fk_env_household` (`household_id`),
  KEY `fk_env_user` (`user_id`),
  CONSTRAINT `fk_env_household` FOREIGN KEY (`household_id`) REFERENCES `households` (`household_id`),
  CONSTRAINT `fk_env_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `family_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `family_history` (
  `fam_history_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `risk_assessment_id` bigint(20) unsigned NOT NULL,
  `hypertension` text DEFAULT NULL,
  `stroke` text DEFAULT NULL,
  `heart_disease` text DEFAULT NULL,
  `diabetes_mellitus` text DEFAULT NULL,
  `asthma` text DEFAULT NULL,
  `cancer` text DEFAULT NULL,
  `kidney_disease` text DEFAULT NULL,
  `first_degree_cardio` text DEFAULT NULL,
  `tb` text DEFAULT NULL,
  `mental_problem` text DEFAULT NULL,
  `copd` text DEFAULT NULL,
  `none_family_history` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fam_history_id`),
  UNIQUE KEY `uq_famhistory_riskassess` (`risk_assessment_id`),
  CONSTRAINT `fk_famhistory_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `family_planning`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `family_planning` (
  `fp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `visitation_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fp_id`),
  KEY `fk_fp_resident` (`resident_id`),
  CONSTRAINT `fk_fp_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fp_commodities_given`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fp_commodities_given` (
  `commodity_given_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fp_id` bigint(20) unsigned NOT NULL,
  `commodity_name` enum('Pills','Pills-Combined','Condoms','DMPA','IUD','Implant') NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`commodity_given_id`),
  KEY `fk_fpcommodity_fp` (`fp_id`),
  CONSTRAINT `fk_fpcommodity_fp` FOREIGN KEY (`fp_id`) REFERENCES `family_planning` (`fp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gdm_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gdm_screening` (
  `gdm_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('Positive','Negative') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`gdm_screening_id`),
  UNIQUE KEY `uq_gdm_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_gdm_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gestational_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gestational_screening` (
  `gestational_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `value` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`gestational_screening_id`),
  UNIQUE KEY `uq_gestational_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_gestational_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `health_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `health_chunks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `language` varchar(10) NOT NULL,
  `category` varchar(50) NOT NULL,
  `source_file` varchar(255) NOT NULL,
  `chunk_index` int(10) unsigned NOT NULL,
  `content` text NOT NULL,
  `embedding` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `health_chunks_language_index` (`language`),
  KEY `health_chunks_category_index` (`category`),
  KEY `health_chunks_lang_cat_source_index` (`language`,`category`,`source_file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hepatitis_b_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hepatitis_b_screening` (
  `hep_b_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`hep_b_screening_id`),
  UNIQUE KEY `uq_hepb_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_hepb_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hiv_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hiv_screening` (
  `hiv_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`hiv_screening_id`),
  UNIQUE KEY `uq_hiv_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_hiv_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `households`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `households` (
  `household_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `household_no` varchar(50) NOT NULL,
  `purok` varchar(20) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `household_type` enum('NHTS','Non-NHTS') DEFAULT NULL,
  `date_registered` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`household_id`),
  UNIQUE KEY `uq_household_no` (`household_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hpv_immunization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hpv_immunization` (
  `hpv_immunization_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `dose_number` enum('1st Dose','2nd Dose') NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`hpv_immunization_id`),
  UNIQUE KEY `uq_hpvimm_dose` (`resident_id`,`dose_number`),
  CONSTRAINT `fk_hpvimm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ifa_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ifa_supplementation` (
  `ifa_supp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `visit_number` tinyint(3) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ifa_supp_id`),
  UNIQUE KEY `uq_ifasupp_visit` (`maternal_care_id`,`visit_number`),
  CONSTRAINT `fk_ifasupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `immunization_doses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `immunization_doses` (
  `dose_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `child_immunization_id` bigint(20) unsigned NOT NULL,
  `vaccine_type` enum('BCG','Hepa B','DPT-HIB-HepB','OPV','IPV','PCV','MMR') NOT NULL,
  `dose_number` tinyint(3) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`dose_id`),
  UNIQUE KEY `uq_immdose` (`child_immunization_id`,`vaccine_type`,`dose_number`),
  CONSTRAINT `fk_immunization_doses_child` FOREIGN KEY (`child_immunization_id`) REFERENCES `child_immunizations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `iron_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `iron_supplementation` (
  `iron_supp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `child_nutrition_id` bigint(20) unsigned NOT NULL,
  `month_number` enum('1','2','3') NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`iron_supp_id`),
  UNIQUE KEY `uq_ironsupp_month` (`child_nutrition_id`,`month_number`),
  CONSTRAINT `fk_ironsupp_childnutr` FOREIGN KEY (`child_nutrition_id`) REFERENCES `child_nutrition` (`child_nutrition_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `malnutrition_management`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `malnutrition_management` (
  `malnutrition_mgmt_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `child_nutrition_id` bigint(20) unsigned NOT NULL,
  `malnutrition_type` enum('MAM','SAM') NOT NULL,
  `status_type` enum('Identified','Enrolled','Cured','Non-Cured','Default','Died') NOT NULL,
  `status_date` date DEFAULT NULL,
  `action` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`malnutrition_mgmt_id`),
  UNIQUE KEY `uq_malnutrmgmt` (`child_nutrition_id`,`malnutrition_type`,`status_type`),
  CONSTRAINT `fk_malnutrmgmt_childnutr` FOREIGN KEY (`child_nutrition_id`) REFERENCES `child_nutrition` (`child_nutrition_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maternal_care`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maternal_care` (
  `maternal_care_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `lmp_date` date DEFAULT NULL,
  `gravida` tinyint(3) unsigned DEFAULT NULL,
  `edd` date DEFAULT NULL,
  `parity` tinyint(3) unsigned DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) GENERATED ALWAYS AS (`weight_kg` / pow(`height_cm` / 100,2)) STORED,
  `bp_systolic` smallint(5) unsigned DEFAULT NULL,
  `bp_diastolic` smallint(5) unsigned DEFAULT NULL,
  `pregnancy_status` enum('Active','Completed','Trans-Out') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`maternal_care_id`),
  KEY `fk_matcare_resident` (`resident_id`),
  CONSTRAINT `fk_matcare_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maternal_trans_outs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maternal_trans_outs` (
  `trans_out_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `to_facility` varchar(160) DEFAULT NULL,
  `occurred_at_stage` varchar(120) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `date_transferred_out` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`trans_out_id`),
  UNIQUE KEY `uq_transout_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_transout_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `medical_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `medical_history` (
  `medical_history_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `no_medical_history` text DEFAULT NULL,
  `diabetes_mellitus` text DEFAULT NULL,
  `heart_disease` text DEFAULT NULL,
  `hypertension` text DEFAULT NULL,
  `kidney_disease` text DEFAULT NULL,
  `tuberculosis` text DEFAULT NULL,
  `other_medical_history` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`medical_history_id`),
  UNIQUE KEY `uq_medhistory_resident` (`resident_id`),
  CONSTRAINT `fk_medhistory_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mms_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mms_supplementation` (
  `mms_supp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `visit_number` tinyint(3) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`mms_supp_id`),
  UNIQUE KEY `uq_mmssupp_visit` (`maternal_care_id`,`visit_number`),
  CONSTRAINT `fk_mmssupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `notification_type` enum('Record Request Update','Chatbot Message','System') NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `recipient_context` text DEFAULT NULL,
  `related_request_id` bigint(20) unsigned DEFAULT NULL,
  `related_conversation_id` bigint(20) unsigned DEFAULT NULL,
  `related_announcement_id` bigint(20) unsigned DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `fk_notif_account` (`account_id`),
  KEY `fk_notif_request` (`related_request_id`),
  KEY `fk_notif_conv` (`related_conversation_id`),
  KEY `fk_notif_announcement` (`related_announcement_id`),
  CONSTRAINT `fk_notif_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`),
  CONSTRAINT `fk_notif_announcement` FOREIGN KEY (`related_announcement_id`) REFERENCES `announcements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_conv` FOREIGN KEY (`related_conversation_id`) REFERENCES `chatbot_conversations` (`conversation_id`),
  CONSTRAINT `fk_notif_request` FOREIGN KEY (`related_request_id`) REFERENCES `record_requests` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nutrition_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nutrition_supplementation` (
  `supplementation_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `child_nutrition_id` bigint(20) unsigned NOT NULL,
  `supplement_type` enum('Vitamin A','MNP','LNS-SQ') NOT NULL,
  `age_group` enum('6-11 Months','12-23 Months','12-59 Months') NOT NULL,
  `dose_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`supplementation_id`),
  UNIQUE KEY `uq_nutrsupp` (`child_nutrition_id`,`supplement_type`,`age_group`,`dose_number`),
  CONSTRAINT `fk_nutrsupp_childnutr` FOREIGN KEY (`child_nutrition_id`) REFERENCES `child_nutrition` (`child_nutrition_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `occupation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `occupation` (
  `occupation_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `occupation_name` varchar(100) NOT NULL,
  PRIMARY KEY (`occupation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_sync_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `offline_sync_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(36) NOT NULL,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `operation_type` varchar(64) NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL,
  `household_pk` bigint(20) unsigned DEFAULT NULL,
  `resident_pk` bigint(20) unsigned DEFAULT NULL,
  `household_no` varchar(16) DEFAULT NULL,
  `member_no` varchar(16) DEFAULT NULL,
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offline_sync_receipts_operation_id_unique` (`operation_id`),
  KEY `offline_sync_receipts_actor_user_id_index` (`actor_user_id`),
  KEY `fk_syncrcpt_household` (`household_pk`),
  KEY `fk_syncrcpt_resident` (`resident_pk`),
  CONSTRAINT `fk_syncrcpt_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `user_management` (`user_id`),
  CONSTRAINT `fk_syncrcpt_household` FOREIGN KEY (`household_pk`) REFERENCES `households` (`household_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_syncrcpt_resident` FOREIGN KEY (`resident_pk`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `past_medical_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `past_medical_history` (
  `past_med_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `risk_assessment_id` bigint(20) unsigned NOT NULL,
  `hypertension` text DEFAULT NULL,
  `heart_diseases` text DEFAULT NULL,
  `diabetes` text DEFAULT NULL,
  `cancer` text DEFAULT NULL,
  `copd` text DEFAULT NULL,
  `asthma` text DEFAULT NULL,
  `mental_disorders` text DEFAULT NULL,
  `vision_problems` text DEFAULT NULL,
  `surgical_history` text DEFAULT NULL,
  `thyroid_disorders` text DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `none_past_medical` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`past_med_id`),
  UNIQUE KEY `uq_pastmed_riskassess` (`risk_assessment_id`),
  CONSTRAINT `fk_pastmed_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `postnatal_care_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `postnatal_care_visits` (
  `pnc_visit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_outcome_id` bigint(20) unsigned NOT NULL,
  `contact_number` tinyint(3) unsigned NOT NULL,
  `contact_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`pnc_visit_id`),
  UNIQUE KEY `uq_pnc_contact` (`delivery_outcome_id`,`contact_number`),
  CONSTRAINT `fk_pnc_delivery` FOREIGN KEY (`delivery_outcome_id`) REFERENCES `delivery_outcomes` (`delivery_outcome_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `postpartum_ifa_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `postpartum_ifa_supplementation` (
  `postpartum_ifa_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_outcome_id` bigint(20) unsigned NOT NULL,
  `visit_number` tinyint(3) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`postpartum_ifa_id`),
  UNIQUE KEY `uq_postpartumifa_visit` (`delivery_outcome_id`,`visit_number`),
  CONSTRAINT `fk_postpartumifa_delivery` FOREIGN KEY (`delivery_outcome_id`) REFERENCES `delivery_outcomes` (`delivery_outcome_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `postpartum_vitamin_a_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `postpartum_vitamin_a_supplementation` (
  `postpartum_vitamin_a_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`postpartum_vitamin_a_id`),
  UNIQUE KEY `uq_ppvita_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_ppvita_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prenatal_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prenatal_visits` (
  `prenatal_visit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `trimester` enum('1st','2nd','3rd') NOT NULL,
  `visit_number` tinyint(3) unsigned DEFAULT NULL,
  `visit_date` date DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) GENERATED ALWAYS AS (`weight_kg` / pow(`height_cm` / 100,2)) STORED,
  `bp_systolic` smallint(5) unsigned DEFAULT NULL,
  `bp_diastolic` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`prenatal_visit_id`),
  KEY `fk_prenatal_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_prenatal_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `record_request_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `record_request_otps` (
  `otp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `destination_fingerprint` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempt_count` int(10) unsigned NOT NULL DEFAULT 0,
  `resend_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `invalidated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`otp_id`),
  KEY `fk_record_request_otps_request_id` (`request_id`),
  CONSTRAINT `fk_record_request_otps_request_id` FOREIGN KEY (`request_id`) REFERENCES `record_requests` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `record_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `record_requests` (
  `request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `household_no_submitted` varchar(50) NOT NULL,
  `zone_submitted` varchar(20) NOT NULL,
  `relationship_submitted` varchar(50) NOT NULL,
  `first_name_submitted` varchar(100) NOT NULL,
  `middle_name_submitted` varchar(100) NOT NULL,
  `last_name_submitted` varchar(100) NOT NULL,
  `mobile_number_submitted` varchar(20) NOT NULL,
  `email_submitted` varchar(150) NOT NULL,
  `submitter_ip` varchar(45) DEFAULT NULL,
  `matched_resident_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('Pending','No Match','Awaiting OTP','Approved','Denied') NOT NULL DEFAULT 'Pending',
  `decision_reason` text DEFAULT NULL,
  `evaluated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  KEY `fk_recreq_account` (`account_id`),
  KEY `fk_recreq_resident` (`matched_resident_id`),
  CONSTRAINT `fk_recreq_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`),
  CONSTRAINT `fk_recreq_resident` FOREIGN KEY (`matched_resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `red_flags_assessment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `red_flags_assessment` (
  `red_flag_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `risk_assessment_id` bigint(20) unsigned NOT NULL,
  `chest_pain` text DEFAULT NULL,
  `diff_breathing` text DEFAULT NULL,
  `loss_consciousness` text DEFAULT NULL,
  `slurred_speech` text DEFAULT NULL,
  `facial_assym` text DEFAULT NULL,
  `disorientation` text DEFAULT NULL,
  `chest_retract` text DEFAULT NULL,
  `seizure` text DEFAULT NULL,
  `self_harm` text DEFAULT NULL,
  `is_agitated` text DEFAULT NULL,
  `eye_injury` text DEFAULT NULL,
  `weakness_body` text DEFAULT NULL,
  `no_red_flags` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`red_flag_id`),
  UNIQUE KEY `uq_redflags_riskassess` (`risk_assessment_id`),
  CONSTRAINT `fk_redflags_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `religion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `religion` (
  `religion_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `religion_name` varchar(100) NOT NULL,
  PRIMARY KEY (`religion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resident_accounts` (
  `account_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `zone_purok` varchar(20) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `uq_residentacct_email` (`email`),
  UNIQUE KEY `uq_resident_accounts_resident_id` (`resident_id`),
  CONSTRAINT `fk_resident_accounts_resident_id` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resident_password_resets` (
  `reset_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `reset_token` varchar(255) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  KEY `fk_residentreset_account` (`account_id`),
  CONSTRAINT `fk_residentreset_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resident_statuses` (
  `resident_status_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `status` enum('Active','Deceased') NOT NULL DEFAULT 'Active',
  `death_record_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`resident_status_id`),
  UNIQUE KEY `uq_resstatus_resident` (`resident_id`),
  KEY `idx_resstatus_status` (`status`),
  KEY `fk_resstatus_death` (`death_record_id`),
  CONSTRAINT `fk_resstatus_death` FOREIGN KEY (`death_record_id`) REFERENCES `death_records` (`death_record_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_resstatus_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `residents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `residents` (
  `resident_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `household_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `relation_to_household_head` varchar(50) NOT NULL,
  `birthday` date NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Live-In') NOT NULL,
  `occupation_id` bigint(20) unsigned DEFAULT NULL,
  `occupation_other` varchar(255) DEFAULT NULL,
  `monthly_income` enum('None','Below 5,000','5,000-9,999','10,000-19,999','20,000-29,999','30,000-49,999','50,000 and above') DEFAULT NULL,
  `religion_id` bigint(20) unsigned DEFAULT NULL,
  `religion_other` varchar(255) DEFAULT NULL,
  `educational_attainment` enum('No Formal Education','Elementary Level','Elementary Graduate','High School Level','High School Graduate','Vocational','College Level','College Graduate','Post Graduate') DEFAULT NULL,
  `philhealth_number` varchar(12) DEFAULT NULL,
  `is_fp_user` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`resident_id`),
  KEY `fk_resident_household` (`household_id`),
  KEY `fk_resident_user` (`user_id`),
  KEY `fk_resident_occupation` (`occupation_id`),
  KEY `fk_resident_religion` (`religion_id`),
  CONSTRAINT `fk_resident_household` FOREIGN KEY (`household_id`) REFERENCES `households` (`household_id`),
  CONSTRAINT `fk_resident_occupation` FOREIGN KEY (`occupation_id`) REFERENCES `occupation` (`occupation_id`),
  CONSTRAINT `fk_resident_religion` FOREIGN KEY (`religion_id`) REFERENCES `religion` (`religion_id`),
  CONSTRAINT `fk_resident_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `risk_assessment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `risk_assessment` (
  `risk_assessment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `tobacco_vape_usage` text DEFAULT NULL,
  `alcohol_intake` text DEFAULT NULL,
  `dietary_habits` text DEFAULT NULL,
  `physical_activity` text DEFAULT NULL,
  `height_cm` text DEFAULT NULL,
  `weight_kg` text DEFAULT NULL,
  `waist_circum_cm` text DEFAULT NULL,
  `systolic_blood_pressure` text DEFAULT NULL,
  `diastolic_blood_pressure` text DEFAULT NULL,
  `blood_pressure_status` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`risk_assessment_id`),
  KEY `fk_riskassess_resident` (`resident_id`),
  KEY `fk_riskassess_user` (`user_id`),
  CONSTRAINT `fk_riskassess_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  CONSTRAINT `fk_riskassess_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rusf_supplementation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rusf_supplementation` (
  `rusf_supp_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_given` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`rusf_supp_id`),
  UNIQUE KEY `uq_rusfsupp_date` (`maternal_care_id`,`date_given`),
  CONSTRAINT `fk_rusfsupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `school_immunization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_immunization` (
  `school_immunization_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `grade_level` enum('Grade 1','Grade 7') NOT NULL,
  `td_date` date DEFAULT NULL,
  `mr_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`school_immunization_id`),
  UNIQUE KEY `uq_schoolimm_grade` (`resident_id`,`grade_level`),
  CONSTRAINT `fk_schoolimm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_password_resets` (
  `reset_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `reset_token` varchar(255) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  KEY `fk_staffreset_user` (`user_id`),
  CONSTRAINT `fk_staffreset_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `syphilis_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `syphilis_screening` (
  `syphilis_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`syphilis_screening_id`),
  UNIQUE KEY `uq_syphilis_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_syphilis_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `td_immunization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `td_immunization` (
  `td_immunization_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned NOT NULL,
  `dose_number` tinyint(3) unsigned DEFAULT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`td_immunization_id`),
  UNIQUE KEY `uq_tdimm_resident_dose` (`resident_id`,`dose_number`),
  KEY `fk_tdimm_resident` (`resident_id`),
  CONSTRAINT `fk_tdimm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `timbang_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timbang_records` (
  `timbang_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resident_id` bigint(20) unsigned DEFAULT NULL,
  `measurement_date` date NOT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `muac_cm` decimal(4,1) DEFAULT NULL,
  `muac_status` varchar(60) DEFAULT NULL,
  `weight_for_age` enum('Severely Underweight','Underweight','Normal','Overweight') DEFAULT NULL,
  `height_for_age` enum('Severely Stunted','Stunted','Normal','Tall') DEFAULT NULL,
  `weight_for_height` varchar(50) DEFAULT NULL,
  `bmi_value` decimal(4,1) DEFAULT NULL,
  `bmi_status` varchar(40) DEFAULT NULL,
  `overall_nutritional_status` varchar(40) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`timbang_id`),
  KEY `fk_timbang_resident` (`resident_id`),
  CONSTRAINT `fk_timbang_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ultrasound_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ultrasound_screening` (
  `ultrasound_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ultrasound_screening_id`),
  UNIQUE KEY `uq_ultrasound_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_ultrasound_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `urinalysis_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `urinalysis_screening` (
  `urinalysis_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `maternal_care_id` bigint(20) unsigned NOT NULL,
  `date_screened` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`urinalysis_screening_id`),
  UNIQUE KEY `uq_urinalysis_matcare` (`maternal_care_id`),
  CONSTRAINT `fk_urinalysis_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_management`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_management` (
  `user_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `photo_path` varchar(255) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `nationality` varchar(50) DEFAULT NULL,
  `mobile_number` varchar(20) NOT NULL,
  `email` varchar(150) NOT NULL,
  `house_no` varchar(20) DEFAULT NULL,
  `street` varchar(150) DEFAULT NULL,
  `purok_zone` varchar(20) DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `municipality_city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `zip_code` varchar(10) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('Active','Suspended') NOT NULL DEFAULT 'Active',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_user_email` (`email`),
  UNIQUE KEY `uq_user_username` (`username`),
  KEY `fk_user_created_by` (`created_by`),
  CONSTRAINT `fk_user_created_by` FOREIGN KEY (`created_by`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `visual_screening`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visual_screening` (
  `visual_screening_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `risk_assessment_id` bigint(20) unsigned NOT NULL,
  `no_screening_past_year` text DEFAULT NULL,
  `has_blurred_vision` text DEFAULT NULL,
  `blurred_vision_details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`visual_screening_id`),
  UNIQUE KEY `uq_visualscreen_riskassess` (`risk_assessment_id`),
  CONSTRAINT `fk_visualscreen_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `waste_management_practices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `waste_management_practices` (
  `waste_management_practices_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `env_assessment_id` bigint(20) unsigned NOT NULL,
  `waste_segregation` tinyint(1) NOT NULL DEFAULT 0,
  `backyard_composting` tinyint(1) NOT NULL DEFAULT 0,
  `recycling_reuse` tinyint(1) NOT NULL DEFAULT 0,
  `collected_by_municipality` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`waste_management_practices_id`),
  UNIQUE KEY `uq_waste_env` (`env_assessment_id`),
  CONSTRAINT `fk_waste_env` FOREIGN KEY (`env_assessment_id`) REFERENCES `environmental_sanitation` (`env_assessment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_appointment_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_appointment_zones` (
  `worker_appointment_zone_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `assigned_zone` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`worker_appointment_zone_id`),
  UNIQUE KEY `uq_worker_appt_zone` (`appointment_id`,`assigned_zone`),
  CONSTRAINT `fk_worker_appt_zones_appt` FOREIGN KEY (`appointment_id`) REFERENCES `worker_appointments` (`appointment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worker_appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worker_appointments` (
  `appointment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` enum('BHW','BNS','BSPO','Admin') NOT NULL,
  `assigned_barangay` varchar(100) DEFAULT NULL,
  `date_appointed` date DEFAULT NULL,
  `end_of_appointment` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`appointment_id`),
  KEY `fk_appt_user` (`user_id`),
  CONSTRAINT `fk_appt_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_08_17_100000_create_death_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_17_100100_create_resident_statuses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_17_100200_add_registry_no_to_death_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_21_100000_evolve_users_for_staff_identity',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_21_100100_create_worker_appointments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_21_140000_make_worker_appointment_employment_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_21_150000_backfill_registry_no_from_certificate_no_on_death_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_21_160000_create_households_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_21_160100_create_residents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_22_100000_add_resident_id_to_death_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_22_100100_add_resident_id_to_resident_statuses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_22_100200_backfill_resident_id_on_death_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_22_120000_create_child_birth_histories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_22_130000_create_deworming_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_23_100000_add_resident_status_unique_indexes_to_death_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_08_24_100000_create_child_immunizations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_24_100100_create_immunization_doses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_24_110000_create_school_immunizations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_24_110100_create_school_immunization_doses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_24_120000_create_child_nutritions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_24_120100_create_child_nutrition_sfp_outcomes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_25_100000_create_risk_assessments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_25_110000_create_family_planning_visits_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_25_140000_create_maternal_pregnancies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_26_100000_create_operation_timbang_measurements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_08_26_200000_create_household_environmental_profiles_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_08_28_120000_create_announcements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_09_05_100000_create_offline_sync_receipts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_09_12_011800_add_resident_id_to_resident_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_09_12_011810_create_record_request_otps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_09_12_011820_add_language_and_category_to_chatbot_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_09_12_011830_create_health_chunks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_09_12_100000_create_timbang_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_09_12_191500_fix_record_request_otp_expires_at_no_auto_update',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_09_12_220000_add_announcement_context_to_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_09_16_100000_add_nutrition_assessment_columns_to_timbang_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_09_16_110000_widen_timbang_records_weight_height_for_age_enums',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_09_18_134900_create_rusf_supplementation_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_09_18_150000_create_maternal_lab_screening_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_09_18_160000_add_delivery_outcome_newborn_sex_and_plurality',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_09_18_170000_create_postpartum_vitamin_a_supplementation_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_09_18_180000_create_worker_appointment_zones_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_09_18_190000_add_deleted_at_to_staff_identity_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_09_18_210000_update_risk_assessment_dietary_and_physical_activity_yes_no',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_09_19_100000_create_adult_immunization_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_09_24_100000_decrypt_family_planning_remarks_and_death_rejection_reason',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_09_24_110000_encrypt_health_record_columns_at_rest',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_09_28_100000_seed_super_admin_account',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_09_28_110000_create_resident_chatbot_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_09_28_120000_make_households_street_nullable',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_09_28_130000_make_resident_profile_columns_nullable',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_09_28_140000_drop_street_and_address_from_households',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_09_28_150000_create_maternal_trans_outs_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_09_28_160000_add_cpab_to_child_immunization_header',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_09_29_100000_normalize_child_immunization_header',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_09_29_110000_normalize_announcement_audience',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_09_29_120000_link_notifications_to_announcements',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_09_29_130000_add_record_request_otps_request_foreign_key',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_09_29_140000_drop_worker_appointments_assigned_zone',7);
