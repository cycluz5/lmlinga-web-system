-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 23, 2026 at 07:36 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hi`
--

-- --------------------------------------------------------

--
-- Table structure for table `adult_immunization`
--

CREATE TABLE `adult_immunization` (
  `adult_immunization_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `vaccine_type` varchar(64) NOT NULL,
  `date_given` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `adult_immunization`
--


-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(500) NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `place` varchar(120) DEFAULT NULL,
  `target_group` varchar(32) NOT NULL,
  `age_presets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`age_presets`)),
  `age_min_months` smallint(5) UNSIGNED DEFAULT NULL,
  `age_max_months` smallint(5) UNSIGNED DEFAULT NULL,
  `zone_mode` varchar(16) NOT NULL,
  `zones` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`zones`)),
  `audience_label` varchar(255) NOT NULL,
  `estimated_reach` int(10) UNSIGNED DEFAULT NULL,
  `posted_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `posted_by_name` varchar(120) NOT NULL,
  `posted_by_role` varchar(16) NOT NULL,
  `posted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `announcements`
--


-- --------------------------------------------------------

--
-- Table structure for table `cbc_hgb_hct_screening`
--

CREATE TABLE `cbc_hgb_hct_screening` (
  `cbc_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('With Anemia','Without Anemia') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cbc_hgb_hct_screening`
--


-- --------------------------------------------------------

--
-- Table structure for table `cc_supplementation`
--

CREATE TABLE `cc_supplementation` (
  `cc_supp_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `visit_number` tinyint(3) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cc_supplementation`
--


-- --------------------------------------------------------

--
-- Table structure for table `chatbot_conversations`
--

CREATE TABLE `chatbot_conversations` (
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(150) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chatbot_conversations`
--


-- --------------------------------------------------------

--
-- Table structure for table `chatbot_messages`
--

CREATE TABLE `chatbot_messages` (
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` bigint(20) UNSIGNED NOT NULL,
  `sender` enum('Resident','Chatbot') NOT NULL,
  `message_text` text NOT NULL,
  `language` varchar(10) NOT NULL DEFAULT 'bcl',
  `category` varchar(50) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chatbot_messages`
--


-- --------------------------------------------------------

--
-- Table structure for table `child_immunizations`
--

CREATE TABLE `child_immunizations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `selected_vaccine_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selected_vaccine_types`)),
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `child_immunizations`
--


-- --------------------------------------------------------

--
-- Table structure for table `child_nutrition`
--

CREATE TABLE `child_nutrition` (
  `child_nutrition_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `length_at_birth_cm` decimal(5,2) DEFAULT NULL,
  `weight_at_birth_kg` decimal(5,2) DEFAULT NULL,
  `initiated_breastfeeding_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `child_nutrition`
--


-- --------------------------------------------------------

--
-- Table structure for table `cvc_screening`
--

CREATE TABLE `cvc_screening` (
  `cvc_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `value` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `death_records`
--

CREATE TABLE `death_records` (
  `death_record_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `cause_of_death` text NOT NULL,
  `date_of_death` date NOT NULL,
  `death_certificate_no` varchar(50) NOT NULL,
  `death_certificate_file_path` varchar(255) NOT NULL,
  `verification_status` enum('Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Pending Verification',
  `rejection_reason` text DEFAULT NULL,
  `submitted_by` bigint(20) UNSIGNED NOT NULL,
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `death_records`
--


-- --------------------------------------------------------

--
-- Table structure for table `delivery_outcomes`
--

CREATE TABLE `delivery_outcomes` (
  `delivery_outcome_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
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
  `plurality_number` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `delivery_outcomes`
--


-- --------------------------------------------------------

--
-- Table structure for table `deworming_records`
--

CREATE TABLE `deworming_records` (
  `deworming_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `year` year(4) NOT NULL,
  `deworming_round` enum('1','2') NOT NULL,
  `date_given` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deworming_records`
--


-- --------------------------------------------------------

--
-- Table structure for table `deworming_supplementation`
--

CREATE TABLE `deworming_supplementation` (
  `deworming_supp_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deworming_supplementation`
--


-- --------------------------------------------------------

--
-- Table structure for table `disability_type`
--

CREATE TABLE `disability_type` (
  `disability_type_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `no_disability` tinyint(1) NOT NULL DEFAULT 0,
  `intellectual_disability` tinyint(1) NOT NULL DEFAULT 0,
  `mental_disability` tinyint(1) NOT NULL DEFAULT 0,
  `physical_disability` tinyint(1) NOT NULL DEFAULT 0,
  `other_disability` tinyint(1) NOT NULL DEFAULT 0,
  `other_disability_specify` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disability_type`
--


-- --------------------------------------------------------

--
-- Table structure for table `environmental_sanitation`
--

CREATE TABLE `environmental_sanitation` (
  `env_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `environmental_sanitation`
--


-- --------------------------------------------------------

--
-- Table structure for table `family_history`
--

CREATE TABLE `family_history` (
  `fam_history_id` bigint(20) UNSIGNED NOT NULL,
  `risk_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `hypertension` tinyint(1) NOT NULL DEFAULT 0,
  `stroke` tinyint(1) NOT NULL DEFAULT 0,
  `heart_disease` tinyint(1) NOT NULL DEFAULT 0,
  `diabetes_mellitus` tinyint(1) NOT NULL DEFAULT 0,
  `asthma` tinyint(1) NOT NULL DEFAULT 0,
  `cancer` tinyint(1) NOT NULL DEFAULT 0,
  `kidney_disease` tinyint(1) NOT NULL DEFAULT 0,
  `first_degree_cardio` tinyint(1) NOT NULL DEFAULT 0,
  `tb` tinyint(1) NOT NULL DEFAULT 0,
  `mental_problem` tinyint(1) NOT NULL DEFAULT 0,
  `copd` tinyint(1) NOT NULL DEFAULT 0,
  `none_family_history` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `family_history`
--


-- --------------------------------------------------------

--
-- Table structure for table `family_planning`
--

CREATE TABLE `family_planning` (
  `fp_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `visitation_date` date NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `family_planning`
--


-- --------------------------------------------------------

--
-- Table structure for table `fic_cic_status`
--

CREATE TABLE `fic_cic_status` (
  `fic_cic_id` bigint(20) UNSIGNED NOT NULL,
  `child_immunization_id` bigint(20) UNSIGNED NOT NULL,
  `fic_completed` tinyint(1) NOT NULL DEFAULT 0,
  `cic_completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fic_cic_status`
--


-- --------------------------------------------------------

--
-- Table structure for table `fp_commodities_given`
--

CREATE TABLE `fp_commodities_given` (
  `commodity_given_id` bigint(20) UNSIGNED NOT NULL,
  `fp_id` bigint(20) UNSIGNED NOT NULL,
  `commodity_name` enum('Pills','Pills-Combined','Condoms','DMPA','IUD','Implant') NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fp_commodities_given`
--


-- --------------------------------------------------------

--
-- Table structure for table `gdm_screening`
--

CREATE TABLE `gdm_screening` (
  `gdm_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('Positive','Negative') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gdm_screening`
--


-- --------------------------------------------------------

--
-- Table structure for table `gestational_screening`
--

CREATE TABLE `gestational_screening` (
  `gestational_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `value` decimal(6,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_chunks`
--

CREATE TABLE `health_chunks` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `language` varchar(10) NOT NULL,
  `category` varchar(50) NOT NULL,
  `source_file` varchar(255) NOT NULL,
  `chunk_index` int(10) UNSIGNED NOT NULL,
  `content` text NOT NULL,
  `embedding` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `health_chunks`
--


-- --------------------------------------------------------

--
-- Table structure for table `hepatitis_b_screening`
--

CREATE TABLE `hepatitis_b_screening` (
  `hep_b_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('Reactive','Negative') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `hepatitis_b_screening`
--


-- --------------------------------------------------------

--
-- Table structure for table `hiv_screening`
--

CREATE TABLE `hiv_screening` (
  `hiv_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('REACTIVE','NON REACTIVE') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `households`
--

CREATE TABLE `households` (
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `household_no` varchar(50) NOT NULL,
  `purok` varchar(20) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `household_type` enum('NHTS','Non-NHTS') DEFAULT NULL,
  `date_registered` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `households`
--


-- --------------------------------------------------------

--
-- Table structure for table `hpv_immunization`
--

CREATE TABLE `hpv_immunization` (
  `hpv_immunization_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `dose_number` enum('1st Dose','2nd Dose') NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ifa_supplementation`
--

CREATE TABLE `ifa_supplementation` (
  `ifa_supp_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `visit_number` tinyint(3) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `immunization_doses`
--

CREATE TABLE `immunization_doses` (
  `dose_id` bigint(20) UNSIGNED NOT NULL,
  `child_immunization_id` bigint(20) UNSIGNED NOT NULL,
  `vaccine_type` enum('BCG','Hepa B','DPT-HIB-HepB','OPV','IPV','PCV','MMR') NOT NULL,
  `dose_number` tinyint(3) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `immunization_doses`
--


-- --------------------------------------------------------

--
-- Table structure for table `iron_supplementation`
--

CREATE TABLE `iron_supplementation` (
  `iron_supp_id` bigint(20) UNSIGNED NOT NULL,
  `child_nutrition_id` bigint(20) UNSIGNED NOT NULL,
  `month_number` enum('1','2','3') NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `malnutrition_management`
--

CREATE TABLE `malnutrition_management` (
  `malnutrition_mgmt_id` bigint(20) UNSIGNED NOT NULL,
  `child_nutrition_id` bigint(20) UNSIGNED NOT NULL,
  `malnutrition_type` enum('MAM','SAM') NOT NULL,
  `status_type` enum('Identified','Enrolled','Cured','Non-Cured','Default','Died') NOT NULL,
  `status_date` date DEFAULT NULL,
  `action` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maternal_care`
--

CREATE TABLE `maternal_care` (
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `lmp_date` date DEFAULT NULL,
  `gravida` tinyint(3) UNSIGNED DEFAULT NULL,
  `edd` date DEFAULT NULL,
  `parity` tinyint(3) UNSIGNED DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) GENERATED ALWAYS AS (`weight_kg` / pow(`height_cm` / 100,2)) STORED,
  `bp_systolic` smallint(5) UNSIGNED DEFAULT NULL,
  `bp_diastolic` smallint(5) UNSIGNED DEFAULT NULL,
  `pregnancy_status` enum('Active','Completed','Trans-Out') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maternal_care`
--


-- --------------------------------------------------------

--
-- Table structure for table `medical_history`
--

CREATE TABLE `medical_history` (
  `medical_history_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `no_medical_history` tinyint(1) NOT NULL DEFAULT 0,
  `diabetes_mellitus` tinyint(1) NOT NULL DEFAULT 0,
  `heart_disease` tinyint(1) NOT NULL DEFAULT 0,
  `hypertension` tinyint(1) NOT NULL DEFAULT 0,
  `kidney_disease` tinyint(1) NOT NULL DEFAULT 0,
  `tuberculosis` tinyint(1) NOT NULL DEFAULT 0,
  `other_medical_history` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_history`
--


-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--


-- --------------------------------------------------------

--
-- Table structure for table `mms_supplementation`
--

CREATE TABLE `mms_supplementation` (
  `mms_supp_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `visit_number` tinyint(3) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `notification_type` enum('Record Request Update','Chatbot Message','System') NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text DEFAULT NULL,
  `recipient_context` text DEFAULT NULL,
  `place` varchar(120) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `related_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `related_conversation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--


-- --------------------------------------------------------

--
-- Table structure for table `nutrition_supplementation`
--

CREATE TABLE `nutrition_supplementation` (
  `supplementation_id` bigint(20) UNSIGNED NOT NULL,
  `child_nutrition_id` bigint(20) UNSIGNED NOT NULL,
  `supplement_type` enum('Vitamin A','MNP','LNS-SQ') NOT NULL,
  `age_group` enum('6-11 Months','12-23 Months','12-59 Months') NOT NULL,
  `dose_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `occupation`
--

CREATE TABLE `occupation` (
  `occupation_id` bigint(20) UNSIGNED NOT NULL,
  `occupation_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `offline_sync_receipts`
--

CREATE TABLE `offline_sync_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `operation_id` varchar(36) NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED NOT NULL,
  `operation_type` varchar(64) NOT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL,
  `household_pk` bigint(20) UNSIGNED DEFAULT NULL,
  `resident_pk` bigint(20) UNSIGNED DEFAULT NULL,
  `household_no` varchar(16) DEFAULT NULL,
  `member_no` varchar(16) DEFAULT NULL,
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `offline_sync_receipts`
--


-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `past_medical_history`
--

CREATE TABLE `past_medical_history` (
  `past_med_id` bigint(20) UNSIGNED NOT NULL,
  `risk_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `hypertension` tinyint(1) NOT NULL DEFAULT 0,
  `heart_diseases` tinyint(1) NOT NULL DEFAULT 0,
  `diabetes` tinyint(1) NOT NULL DEFAULT 0,
  `cancer` tinyint(1) NOT NULL DEFAULT 0,
  `copd` tinyint(1) NOT NULL DEFAULT 0,
  `asthma` tinyint(1) NOT NULL DEFAULT 0,
  `mental_disorders` tinyint(1) NOT NULL DEFAULT 0,
  `vision_problems` tinyint(1) NOT NULL DEFAULT 0,
  `surgical_history` tinyint(1) NOT NULL DEFAULT 0,
  `thyroid_disorders` tinyint(1) NOT NULL DEFAULT 0,
  `allergies` tinyint(1) NOT NULL DEFAULT 0,
  `none_past_medical` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `past_medical_history`
--


-- --------------------------------------------------------

--
-- Table structure for table `postnatal_care_visits`
--

CREATE TABLE `postnatal_care_visits` (
  `pnc_visit_id` bigint(20) UNSIGNED NOT NULL,
  `delivery_outcome_id` bigint(20) UNSIGNED NOT NULL,
  `contact_number` tinyint(3) UNSIGNED NOT NULL,
  `contact_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `postpartum_ifa_supplementation`
--

CREATE TABLE `postpartum_ifa_supplementation` (
  `postpartum_ifa_id` bigint(20) UNSIGNED NOT NULL,
  `delivery_outcome_id` bigint(20) UNSIGNED NOT NULL,
  `visit_number` tinyint(3) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `tablets_given` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `postpartum_vitamin_a_supplementation`
--

CREATE TABLE `postpartum_vitamin_a_supplementation` (
  `postpartum_vitamin_a_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prenatal_visits`
--

CREATE TABLE `prenatal_visits` (
  `prenatal_visit_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `trimester` enum('1st','2nd','3rd') NOT NULL,
  `visit_number` tinyint(3) UNSIGNED DEFAULT NULL,
  `visit_date` date DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `bmi` decimal(4,1) GENERATED ALWAYS AS (`weight_kg` / pow(`height_cm` / 100,2)) STORED,
  `bp_systolic` smallint(5) UNSIGNED DEFAULT NULL,
  `bp_diastolic` smallint(5) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `prenatal_visits`
--


-- --------------------------------------------------------

--
-- Table structure for table `record_requests`
--

CREATE TABLE `record_requests` (
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `household_no_submitted` varchar(50) NOT NULL,
  `zone_submitted` varchar(20) NOT NULL,
  `relationship_submitted` varchar(50) NOT NULL,
  `first_name_submitted` varchar(100) NOT NULL,
  `middle_name_submitted` varchar(100) NOT NULL,
  `last_name_submitted` varchar(100) NOT NULL,
  `mobile_number_submitted` varchar(20) NOT NULL,
  `email_submitted` varchar(150) NOT NULL,
  `submitter_ip` varchar(45) DEFAULT NULL,
  `matched_resident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('Pending','No Match','Awaiting OTP','Approved','Denied') NOT NULL DEFAULT 'Pending',
  `decision_reason` text DEFAULT NULL,
  `evaluated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `record_requests`
--


-- --------------------------------------------------------

--
-- Table structure for table `record_request_otps`
--

CREATE TABLE `record_request_otps` (
  `otp_id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `destination_fingerprint` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempt_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `resend_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `invalidated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `record_request_otps`
--


-- --------------------------------------------------------

--
-- Table structure for table `red_flags_assessment`
--

CREATE TABLE `red_flags_assessment` (
  `red_flag_id` bigint(20) UNSIGNED NOT NULL,
  `risk_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `chest_pain` tinyint(1) NOT NULL DEFAULT 0,
  `diff_breathing` tinyint(1) NOT NULL DEFAULT 0,
  `loss_consciousness` tinyint(1) NOT NULL DEFAULT 0,
  `slurred_speech` tinyint(1) NOT NULL DEFAULT 0,
  `facial_assym` tinyint(1) NOT NULL DEFAULT 0,
  `disorientation` tinyint(1) NOT NULL DEFAULT 0,
  `chest_retract` tinyint(1) NOT NULL DEFAULT 0,
  `seizure` tinyint(1) NOT NULL DEFAULT 0,
  `self_harm` tinyint(1) NOT NULL DEFAULT 0,
  `is_agitated` tinyint(1) NOT NULL DEFAULT 0,
  `eye_injury` tinyint(1) NOT NULL DEFAULT 0,
  `weakness_body` tinyint(1) NOT NULL DEFAULT 0,
  `no_red_flags` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `red_flags_assessment`
--


-- --------------------------------------------------------

--
-- Table structure for table `religion`
--

CREATE TABLE `religion` (
  `religion_id` bigint(20) UNSIGNED NOT NULL,
  `religion_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `household_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `relation_to_household_head` varchar(50) NOT NULL,
  `birthday` date NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Live-In') NOT NULL,
  `occupation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `occupation_other` varchar(255) DEFAULT NULL,
  `monthly_income` enum('None','Below 5,000','5,000-9,999','10,000-19,999','20,000-29,999','30,000-49,999','50,000 and above') DEFAULT NULL,
  `religion_id` bigint(20) UNSIGNED DEFAULT NULL,
  `religion_other` varchar(255) DEFAULT NULL,
  `educational_attainment` enum('No Formal Education','Elementary Level','Elementary Graduate','High School Level','High School Graduate','Vocational','College Level','College Graduate','Post Graduate') DEFAULT NULL,
  `philhealth_number` varchar(12) DEFAULT NULL,
  `is_fp_user` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--


-- --------------------------------------------------------

--
-- Table structure for table `resident_accounts`
--

CREATE TABLE `resident_accounts` (
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `zone_purok` varchar(20) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `resident_accounts`
--


-- --------------------------------------------------------

--
-- Table structure for table `resident_password_resets`
--

CREATE TABLE `resident_password_resets` (
  `reset_id` bigint(20) UNSIGNED NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `reset_token` varchar(255) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resident_statuses`
--

CREATE TABLE `resident_statuses` (
  `resident_status_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('Active','Deceased') NOT NULL DEFAULT 'Active',
  `death_record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `risk_assessment`
--

CREATE TABLE `risk_assessment` (
  `risk_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `tobacco_vape_usage` enum('Never','Current User','Stopped < 1 year') DEFAULT NULL,
  `alcohol_intake` enum('Never','Light (Occasional)','Excessive') DEFAULT NULL,
  `dietary_habits` enum('Yes','No') DEFAULT NULL,
  `physical_activity` enum('Yes','No') DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `waist_circum_cm` decimal(5,2) DEFAULT NULL,
  `systolic_blood_pressure` smallint(5) UNSIGNED DEFAULT NULL,
  `diastolic_blood_pressure` smallint(5) UNSIGNED DEFAULT NULL,
  `blood_pressure_status` varchar(30) GENERATED ALWAYS AS (case when `systolic_blood_pressure` is null or `diastolic_blood_pressure` is null then NULL when `systolic_blood_pressure` > 180 or `diastolic_blood_pressure` > 120 then 'Hypertensive Crisis' when `systolic_blood_pressure` >= 140 or `diastolic_blood_pressure` >= 90 then 'Hypertension Stage 2' when `systolic_blood_pressure` between 130 and 139 or `diastolic_blood_pressure` between 80 and 89 then 'Hypertension Stage 1' when `systolic_blood_pressure` between 120 and 129 and `diastolic_blood_pressure` < 80 then 'Elevated' when `systolic_blood_pressure` < 120 and `diastolic_blood_pressure` < 80 then 'Normal' else NULL end) STORED,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `risk_assessment`
--


-- --------------------------------------------------------

--
-- Table structure for table `rusf_supplementation`
--

CREATE TABLE `rusf_supplementation` (
  `rusf_supp_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_given` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rusf_supplementation`
--


-- --------------------------------------------------------

--
-- Table structure for table `school_immunization`
--

CREATE TABLE `school_immunization` (
  `school_immunization_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `grade_level` enum('Grade 1','Grade 7') NOT NULL,
  `td_date` date DEFAULT NULL,
  `mr_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_password_resets`
--

CREATE TABLE `staff_password_resets` (
  `reset_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `reset_token` varchar(255) NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `syphilis_screening`
--

CREATE TABLE `syphilis_screening` (
  `syphilis_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `result` enum('REACTIVE','NON REACTIVE') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `td_immunization`
--

CREATE TABLE `td_immunization` (
  `td_immunization_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `dose_number` tinyint(3) UNSIGNED DEFAULT NULL,
  `date_given` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timbang_records`
--

CREATE TABLE `timbang_records` (
  `timbang_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED DEFAULT NULL,
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
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `timbang_records`
--


-- --------------------------------------------------------

--
-- Table structure for table `ultrasound_screening`
--

CREATE TABLE `ultrasound_screening` (
  `ultrasound_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `urinalysis_screening`
--

CREATE TABLE `urinalysis_screening` (
  `urinalysis_screening_id` bigint(20) UNSIGNED NOT NULL,
  `maternal_care_id` bigint(20) UNSIGNED NOT NULL,
  `date_screened` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_management`
--

CREATE TABLE `user_management` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
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
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_management`
--


-- --------------------------------------------------------

--
-- Table structure for table `visual_screening`
--

CREATE TABLE `visual_screening` (
  `visual_screening_id` bigint(20) UNSIGNED NOT NULL,
  `risk_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `no_screening_past_year` tinyint(1) NOT NULL DEFAULT 0,
  `has_blurred_vision` tinyint(1) NOT NULL DEFAULT 0,
  `blurred_vision_details` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `waste_management_practices`
--

CREATE TABLE `waste_management_practices` (
  `waste_management_practices_id` bigint(20) UNSIGNED NOT NULL,
  `env_assessment_id` bigint(20) UNSIGNED NOT NULL,
  `waste_segregation` tinyint(1) NOT NULL DEFAULT 0,
  `backyard_composting` tinyint(1) NOT NULL DEFAULT 0,
  `recycling_reuse` tinyint(1) NOT NULL DEFAULT 0,
  `collected_by_municipality` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `waste_management_practices`
--


-- --------------------------------------------------------

--
-- Table structure for table `worker_appointments`
--

CREATE TABLE `worker_appointments` (
  `appointment_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('BHW','BNS','BSPO','Admin') NOT NULL,
  `assigned_barangay` varchar(100) DEFAULT NULL,
  `assigned_zone` varchar(20) DEFAULT NULL,
  `date_appointed` date DEFAULT NULL,
  `end_of_appointment` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `worker_appointments`
--


-- --------------------------------------------------------

--
-- Table structure for table `worker_appointment_zones`
--

CREATE TABLE `worker_appointment_zones` (
  `worker_appointment_zone_id` bigint(20) UNSIGNED NOT NULL,
  `appointment_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_zone` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `worker_appointment_zones`
--


--
-- Indexes for dumped tables
--

--
-- Indexes for table `adult_immunization`
--
ALTER TABLE `adult_immunization`
  ADD PRIMARY KEY (`adult_immunization_id`),
  ADD UNIQUE KEY `uq_adult_imm_resident_vaccine` (`resident_id`,`vaccine_type`),
  ADD KEY `idx_adult_imm_resident` (`resident_id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `announcements_event_date_index` (`event_date`),
  ADD KEY `announcements_posted_at_index` (`posted_at`),
  ADD KEY `announcements_target_group_index` (`target_group`),
  ADD KEY `fk_announce_user` (`posted_by_user_id`);

--
-- Indexes for table `cbc_hgb_hct_screening`
--
ALTER TABLE `cbc_hgb_hct_screening`
  ADD PRIMARY KEY (`cbc_screening_id`),
  ADD UNIQUE KEY `uq_cbc_matcare` (`maternal_care_id`);

--
-- Indexes for table `cc_supplementation`
--
ALTER TABLE `cc_supplementation`
  ADD PRIMARY KEY (`cc_supp_id`),
  ADD UNIQUE KEY `uq_ccsupp_visit` (`maternal_care_id`,`visit_number`);

--
-- Indexes for table `chatbot_conversations`
--
ALTER TABLE `chatbot_conversations`
  ADD PRIMARY KEY (`conversation_id`),
  ADD KEY `fk_chatconv_account` (`account_id`);

--
-- Indexes for table `chatbot_messages`
--
ALTER TABLE `chatbot_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `fk_chatmsg_conv` (`conversation_id`);

--
-- Indexes for table `child_immunizations`
--
ALTER TABLE `child_immunizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_child_immunizations_resident` (`resident_id`);

--
-- Indexes for table `child_nutrition`
--
ALTER TABLE `child_nutrition`
  ADD PRIMARY KEY (`child_nutrition_id`),
  ADD KEY `fk_childnutr_resident` (`resident_id`);

--
-- Indexes for table `cvc_screening`
--
ALTER TABLE `cvc_screening`
  ADD PRIMARY KEY (`cvc_screening_id`),
  ADD UNIQUE KEY `uq_cvc_matcare` (`maternal_care_id`);

--
-- Indexes for table `death_records`
--
ALTER TABLE `death_records`
  ADD PRIMARY KEY (`death_record_id`),
  ADD UNIQUE KEY `uq_death_resident` (`resident_id`),
  ADD KEY `fk_death_submitted_by` (`submitted_by`),
  ADD KEY `fk_death_verified_by` (`verified_by`);

--
-- Indexes for table `delivery_outcomes`
--
ALTER TABLE `delivery_outcomes`
  ADD PRIMARY KEY (`delivery_outcome_id`),
  ADD UNIQUE KEY `uq_delivery_matcare` (`maternal_care_id`);

--
-- Indexes for table `deworming_records`
--
ALTER TABLE `deworming_records`
  ADD PRIMARY KEY (`deworming_id`),
  ADD UNIQUE KEY `uq_deworm_round` (`resident_id`,`year`,`deworming_round`);

--
-- Indexes for table `deworming_supplementation`
--
ALTER TABLE `deworming_supplementation`
  ADD PRIMARY KEY (`deworming_supp_id`),
  ADD KEY `fk_dewormsupp_matcare` (`maternal_care_id`);

--
-- Indexes for table `disability_type`
--
ALTER TABLE `disability_type`
  ADD PRIMARY KEY (`disability_type_id`),
  ADD UNIQUE KEY `uq_disability_resident` (`resident_id`);

--
-- Indexes for table `environmental_sanitation`
--
ALTER TABLE `environmental_sanitation`
  ADD PRIMARY KEY (`env_assessment_id`),
  ADD KEY `fk_env_household` (`household_id`),
  ADD KEY `fk_env_user` (`user_id`);

--
-- Indexes for table `family_history`
--
ALTER TABLE `family_history`
  ADD PRIMARY KEY (`fam_history_id`),
  ADD UNIQUE KEY `uq_famhistory_riskassess` (`risk_assessment_id`);

--
-- Indexes for table `family_planning`
--
ALTER TABLE `family_planning`
  ADD PRIMARY KEY (`fp_id`),
  ADD KEY `fk_fp_resident` (`resident_id`);

--
-- Indexes for table `fic_cic_status`
--
ALTER TABLE `fic_cic_status`
  ADD PRIMARY KEY (`fic_cic_id`),
  ADD UNIQUE KEY `uq_ficcic_childimm` (`child_immunization_id`);

--
-- Indexes for table `fp_commodities_given`
--
ALTER TABLE `fp_commodities_given`
  ADD PRIMARY KEY (`commodity_given_id`),
  ADD KEY `fk_fpcommodity_fp` (`fp_id`);

--
-- Indexes for table `gdm_screening`
--
ALTER TABLE `gdm_screening`
  ADD PRIMARY KEY (`gdm_screening_id`),
  ADD UNIQUE KEY `uq_gdm_matcare` (`maternal_care_id`);

--
-- Indexes for table `gestational_screening`
--
ALTER TABLE `gestational_screening`
  ADD PRIMARY KEY (`gestational_screening_id`),
  ADD UNIQUE KEY `uq_gestational_matcare` (`maternal_care_id`);

--
-- Indexes for table `health_chunks`
--
ALTER TABLE `health_chunks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `health_chunks_language_index` (`language`),
  ADD KEY `health_chunks_category_index` (`category`),
  ADD KEY `health_chunks_lang_cat_source_index` (`language`,`category`,`source_file`);

--
-- Indexes for table `hepatitis_b_screening`
--
ALTER TABLE `hepatitis_b_screening`
  ADD PRIMARY KEY (`hep_b_screening_id`),
  ADD UNIQUE KEY `uq_hepb_matcare` (`maternal_care_id`);

--
-- Indexes for table `hiv_screening`
--
ALTER TABLE `hiv_screening`
  ADD PRIMARY KEY (`hiv_screening_id`),
  ADD UNIQUE KEY `uq_hiv_matcare` (`maternal_care_id`);

--
-- Indexes for table `households`
--
ALTER TABLE `households`
  ADD PRIMARY KEY (`household_id`),
  ADD UNIQUE KEY `uq_household_no` (`household_no`);

--
-- Indexes for table `hpv_immunization`
--
ALTER TABLE `hpv_immunization`
  ADD PRIMARY KEY (`hpv_immunization_id`),
  ADD UNIQUE KEY `uq_hpvimm_dose` (`resident_id`,`dose_number`);

--
-- Indexes for table `ifa_supplementation`
--
ALTER TABLE `ifa_supplementation`
  ADD PRIMARY KEY (`ifa_supp_id`),
  ADD UNIQUE KEY `uq_ifasupp_visit` (`maternal_care_id`,`visit_number`);

--
-- Indexes for table `immunization_doses`
--
ALTER TABLE `immunization_doses`
  ADD PRIMARY KEY (`dose_id`),
  ADD UNIQUE KEY `uq_immdose` (`child_immunization_id`,`vaccine_type`,`dose_number`);

--
-- Indexes for table `iron_supplementation`
--
ALTER TABLE `iron_supplementation`
  ADD PRIMARY KEY (`iron_supp_id`),
  ADD UNIQUE KEY `uq_ironsupp_month` (`child_nutrition_id`,`month_number`);

--
-- Indexes for table `malnutrition_management`
--
ALTER TABLE `malnutrition_management`
  ADD PRIMARY KEY (`malnutrition_mgmt_id`),
  ADD UNIQUE KEY `uq_malnutrmgmt` (`child_nutrition_id`,`malnutrition_type`,`status_type`);

--
-- Indexes for table `maternal_care`
--
ALTER TABLE `maternal_care`
  ADD PRIMARY KEY (`maternal_care_id`),
  ADD KEY `fk_matcare_resident` (`resident_id`);

--
-- Indexes for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD PRIMARY KEY (`medical_history_id`),
  ADD UNIQUE KEY `uq_medhistory_resident` (`resident_id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `mms_supplementation`
--
ALTER TABLE `mms_supplementation`
  ADD PRIMARY KEY (`mms_supp_id`),
  ADD UNIQUE KEY `uq_mmssupp_visit` (`maternal_care_id`,`visit_number`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `fk_notif_account` (`account_id`),
  ADD KEY `fk_notif_request` (`related_request_id`),
  ADD KEY `fk_notif_conv` (`related_conversation_id`);

--
-- Indexes for table `nutrition_supplementation`
--
ALTER TABLE `nutrition_supplementation`
  ADD PRIMARY KEY (`supplementation_id`),
  ADD UNIQUE KEY `uq_nutrsupp` (`child_nutrition_id`,`supplement_type`,`age_group`,`dose_number`);

--
-- Indexes for table `occupation`
--
ALTER TABLE `occupation`
  ADD PRIMARY KEY (`occupation_id`);

--
-- Indexes for table `offline_sync_receipts`
--
ALTER TABLE `offline_sync_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `offline_sync_receipts_operation_id_unique` (`operation_id`),
  ADD KEY `offline_sync_receipts_actor_user_id_index` (`actor_user_id`),
  ADD KEY `fk_syncrcpt_household` (`household_pk`),
  ADD KEY `fk_syncrcpt_resident` (`resident_pk`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `past_medical_history`
--
ALTER TABLE `past_medical_history`
  ADD PRIMARY KEY (`past_med_id`),
  ADD UNIQUE KEY `uq_pastmed_riskassess` (`risk_assessment_id`);

--
-- Indexes for table `postnatal_care_visits`
--
ALTER TABLE `postnatal_care_visits`
  ADD PRIMARY KEY (`pnc_visit_id`),
  ADD UNIQUE KEY `uq_pnc_contact` (`delivery_outcome_id`,`contact_number`);

--
-- Indexes for table `postpartum_ifa_supplementation`
--
ALTER TABLE `postpartum_ifa_supplementation`
  ADD PRIMARY KEY (`postpartum_ifa_id`),
  ADD UNIQUE KEY `uq_postpartumifa_visit` (`delivery_outcome_id`,`visit_number`);

--
-- Indexes for table `postpartum_vitamin_a_supplementation`
--
ALTER TABLE `postpartum_vitamin_a_supplementation`
  ADD PRIMARY KEY (`postpartum_vitamin_a_id`),
  ADD UNIQUE KEY `uq_ppvita_matcare` (`maternal_care_id`);

--
-- Indexes for table `prenatal_visits`
--
ALTER TABLE `prenatal_visits`
  ADD PRIMARY KEY (`prenatal_visit_id`),
  ADD KEY `fk_prenatal_matcare` (`maternal_care_id`);

--
-- Indexes for table `record_requests`
--
ALTER TABLE `record_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `fk_recreq_account` (`account_id`),
  ADD KEY `fk_recreq_resident` (`matched_resident_id`);

--
-- Indexes for table `record_request_otps`
--
ALTER TABLE `record_request_otps`
  ADD PRIMARY KEY (`otp_id`),
  ADD KEY `fk_record_request_otps_request_id` (`request_id`);

--
-- Indexes for table `red_flags_assessment`
--
ALTER TABLE `red_flags_assessment`
  ADD PRIMARY KEY (`red_flag_id`),
  ADD UNIQUE KEY `uq_redflags_riskassess` (`risk_assessment_id`);

--
-- Indexes for table `religion`
--
ALTER TABLE `religion`
  ADD PRIMARY KEY (`religion_id`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`resident_id`),
  ADD KEY `fk_resident_household` (`household_id`),
  ADD KEY `fk_resident_user` (`user_id`),
  ADD KEY `fk_resident_occupation` (`occupation_id`),
  ADD KEY `fk_resident_religion` (`religion_id`);

--
-- Indexes for table `resident_accounts`
--
ALTER TABLE `resident_accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `uq_residentacct_email` (`email`),
  ADD UNIQUE KEY `uq_resident_accounts_resident_id` (`resident_id`);

--
-- Indexes for table `resident_password_resets`
--
ALTER TABLE `resident_password_resets`
  ADD PRIMARY KEY (`reset_id`),
  ADD KEY `fk_residentreset_account` (`account_id`);

--
-- Indexes for table `resident_statuses`
--
ALTER TABLE `resident_statuses`
  ADD PRIMARY KEY (`resident_status_id`),
  ADD UNIQUE KEY `uq_resstatus_resident` (`resident_id`),
  ADD KEY `idx_resstatus_status` (`status`),
  ADD KEY `fk_resstatus_death` (`death_record_id`);

--
-- Indexes for table `risk_assessment`
--
ALTER TABLE `risk_assessment`
  ADD PRIMARY KEY (`risk_assessment_id`),
  ADD KEY `fk_riskassess_resident` (`resident_id`),
  ADD KEY `fk_riskassess_user` (`user_id`);

--
-- Indexes for table `rusf_supplementation`
--
ALTER TABLE `rusf_supplementation`
  ADD PRIMARY KEY (`rusf_supp_id`),
  ADD UNIQUE KEY `uq_rusfsupp_date` (`maternal_care_id`,`date_given`);

--
-- Indexes for table `school_immunization`
--
ALTER TABLE `school_immunization`
  ADD PRIMARY KEY (`school_immunization_id`),
  ADD UNIQUE KEY `uq_schoolimm_grade` (`resident_id`,`grade_level`);

--
-- Indexes for table `staff_password_resets`
--
ALTER TABLE `staff_password_resets`
  ADD PRIMARY KEY (`reset_id`),
  ADD KEY `fk_staffreset_user` (`user_id`);

--
-- Indexes for table `syphilis_screening`
--
ALTER TABLE `syphilis_screening`
  ADD PRIMARY KEY (`syphilis_screening_id`),
  ADD UNIQUE KEY `uq_syphilis_matcare` (`maternal_care_id`);

--
-- Indexes for table `td_immunization`
--
ALTER TABLE `td_immunization`
  ADD PRIMARY KEY (`td_immunization_id`),
  ADD KEY `fk_tdimm_resident` (`resident_id`);

--
-- Indexes for table `timbang_records`
--
ALTER TABLE `timbang_records`
  ADD PRIMARY KEY (`timbang_id`),
  ADD KEY `fk_timbang_resident` (`resident_id`);

--
-- Indexes for table `ultrasound_screening`
--
ALTER TABLE `ultrasound_screening`
  ADD PRIMARY KEY (`ultrasound_screening_id`),
  ADD UNIQUE KEY `uq_ultrasound_matcare` (`maternal_care_id`);

--
-- Indexes for table `urinalysis_screening`
--
ALTER TABLE `urinalysis_screening`
  ADD PRIMARY KEY (`urinalysis_screening_id`),
  ADD UNIQUE KEY `uq_urinalysis_matcare` (`maternal_care_id`);

--
-- Indexes for table `user_management`
--
ALTER TABLE `user_management`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_user_email` (`email`),
  ADD UNIQUE KEY `uq_user_username` (`username`),
  ADD KEY `fk_user_created_by` (`created_by`);

--
-- Indexes for table `visual_screening`
--
ALTER TABLE `visual_screening`
  ADD PRIMARY KEY (`visual_screening_id`),
  ADD UNIQUE KEY `uq_visualscreen_riskassess` (`risk_assessment_id`);

--
-- Indexes for table `waste_management_practices`
--
ALTER TABLE `waste_management_practices`
  ADD PRIMARY KEY (`waste_management_practices_id`),
  ADD UNIQUE KEY `uq_waste_env` (`env_assessment_id`);

--
-- Indexes for table `worker_appointments`
--
ALTER TABLE `worker_appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `fk_appt_user` (`user_id`);

--
-- Indexes for table `worker_appointment_zones`
--
ALTER TABLE `worker_appointment_zones`
  ADD PRIMARY KEY (`worker_appointment_zone_id`),
  ADD UNIQUE KEY `uq_worker_appt_zone` (`appointment_id`,`assigned_zone`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adult_immunization`
--
ALTER TABLE `adult_immunization`
  MODIFY `adult_immunization_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cbc_hgb_hct_screening`
--
ALTER TABLE `cbc_hgb_hct_screening`
  MODIFY `cbc_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cc_supplementation`
--
ALTER TABLE `cc_supplementation`
  MODIFY `cc_supp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chatbot_conversations`
--
ALTER TABLE `chatbot_conversations`
  MODIFY `conversation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chatbot_messages`
--
ALTER TABLE `chatbot_messages`
  MODIFY `message_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `child_immunizations`
--
ALTER TABLE `child_immunizations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `child_nutrition`
--
ALTER TABLE `child_nutrition`
  MODIFY `child_nutrition_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cvc_screening`
--
ALTER TABLE `cvc_screening`
  MODIFY `cvc_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `death_records`
--
ALTER TABLE `death_records`
  MODIFY `death_record_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_outcomes`
--
ALTER TABLE `delivery_outcomes`
  MODIFY `delivery_outcome_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deworming_records`
--
ALTER TABLE `deworming_records`
  MODIFY `deworming_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deworming_supplementation`
--
ALTER TABLE `deworming_supplementation`
  MODIFY `deworming_supp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `disability_type`
--
ALTER TABLE `disability_type`
  MODIFY `disability_type_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `environmental_sanitation`
--
ALTER TABLE `environmental_sanitation`
  MODIFY `env_assessment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `family_history`
--
ALTER TABLE `family_history`
  MODIFY `fam_history_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `family_planning`
--
ALTER TABLE `family_planning`
  MODIFY `fp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fic_cic_status`
--
ALTER TABLE `fic_cic_status`
  MODIFY `fic_cic_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fp_commodities_given`
--
ALTER TABLE `fp_commodities_given`
  MODIFY `commodity_given_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gdm_screening`
--
ALTER TABLE `gdm_screening`
  MODIFY `gdm_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gestational_screening`
--
ALTER TABLE `gestational_screening`
  MODIFY `gestational_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `health_chunks`
--
ALTER TABLE `health_chunks`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hepatitis_b_screening`
--
ALTER TABLE `hepatitis_b_screening`
  MODIFY `hep_b_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hiv_screening`
--
ALTER TABLE `hiv_screening`
  MODIFY `hiv_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `households`
--
ALTER TABLE `households`
  MODIFY `household_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hpv_immunization`
--
ALTER TABLE `hpv_immunization`
  MODIFY `hpv_immunization_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ifa_supplementation`
--
ALTER TABLE `ifa_supplementation`
  MODIFY `ifa_supp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `immunization_doses`
--
ALTER TABLE `immunization_doses`
  MODIFY `dose_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `iron_supplementation`
--
ALTER TABLE `iron_supplementation`
  MODIFY `iron_supp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `malnutrition_management`
--
ALTER TABLE `malnutrition_management`
  MODIFY `malnutrition_mgmt_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maternal_care`
--
ALTER TABLE `maternal_care`
  MODIFY `maternal_care_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medical_history`
--
ALTER TABLE `medical_history`
  MODIFY `medical_history_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mms_supplementation`
--
ALTER TABLE `mms_supplementation`
  MODIFY `mms_supp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nutrition_supplementation`
--
ALTER TABLE `nutrition_supplementation`
  MODIFY `supplementation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `occupation`
--
ALTER TABLE `occupation`
  MODIFY `occupation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `offline_sync_receipts`
--
ALTER TABLE `offline_sync_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `past_medical_history`
--
ALTER TABLE `past_medical_history`
  MODIFY `past_med_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `postnatal_care_visits`
--
ALTER TABLE `postnatal_care_visits`
  MODIFY `pnc_visit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `postpartum_ifa_supplementation`
--
ALTER TABLE `postpartum_ifa_supplementation`
  MODIFY `postpartum_ifa_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `postpartum_vitamin_a_supplementation`
--
ALTER TABLE `postpartum_vitamin_a_supplementation`
  MODIFY `postpartum_vitamin_a_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prenatal_visits`
--
ALTER TABLE `prenatal_visits`
  MODIFY `prenatal_visit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `record_requests`
--
ALTER TABLE `record_requests`
  MODIFY `request_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `record_request_otps`
--
ALTER TABLE `record_request_otps`
  MODIFY `otp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `red_flags_assessment`
--
ALTER TABLE `red_flags_assessment`
  MODIFY `red_flag_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `religion`
--
ALTER TABLE `religion`
  MODIFY `religion_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `resident_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resident_accounts`
--
ALTER TABLE `resident_accounts`
  MODIFY `account_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resident_password_resets`
--
ALTER TABLE `resident_password_resets`
  MODIFY `reset_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resident_statuses`
--
ALTER TABLE `resident_statuses`
  MODIFY `resident_status_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `risk_assessment`
--
ALTER TABLE `risk_assessment`
  MODIFY `risk_assessment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rusf_supplementation`
--
ALTER TABLE `rusf_supplementation`
  MODIFY `rusf_supp_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_immunization`
--
ALTER TABLE `school_immunization`
  MODIFY `school_immunization_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_password_resets`
--
ALTER TABLE `staff_password_resets`
  MODIFY `reset_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `syphilis_screening`
--
ALTER TABLE `syphilis_screening`
  MODIFY `syphilis_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `td_immunization`
--
ALTER TABLE `td_immunization`
  MODIFY `td_immunization_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timbang_records`
--
ALTER TABLE `timbang_records`
  MODIFY `timbang_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ultrasound_screening`
--
ALTER TABLE `ultrasound_screening`
  MODIFY `ultrasound_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `urinalysis_screening`
--
ALTER TABLE `urinalysis_screening`
  MODIFY `urinalysis_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_management`
--
ALTER TABLE `user_management`
  MODIFY `user_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `visual_screening`
--
ALTER TABLE `visual_screening`
  MODIFY `visual_screening_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `waste_management_practices`
--
ALTER TABLE `waste_management_practices`
  MODIFY `waste_management_practices_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `worker_appointments`
--
ALTER TABLE `worker_appointments`
  MODIFY `appointment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `worker_appointment_zones`
--
ALTER TABLE `worker_appointment_zones`
  MODIFY `worker_appointment_zone_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `adult_immunization`
--
ALTER TABLE `adult_immunization`
  ADD CONSTRAINT `fk_adult_imm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `announcements`
--
ALTER TABLE `announcements`
  ADD CONSTRAINT `fk_announce_user` FOREIGN KEY (`posted_by_user_id`) REFERENCES `user_management` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `cbc_hgb_hct_screening`
--
ALTER TABLE `cbc_hgb_hct_screening`
  ADD CONSTRAINT `fk_cbc_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `cc_supplementation`
--
ALTER TABLE `cc_supplementation`
  ADD CONSTRAINT `fk_ccsupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `chatbot_conversations`
--
ALTER TABLE `chatbot_conversations`
  ADD CONSTRAINT `fk_chatconv_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`);

--
-- Constraints for table `chatbot_messages`
--
ALTER TABLE `chatbot_messages`
  ADD CONSTRAINT `fk_chatmsg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `chatbot_conversations` (`conversation_id`);

--
-- Constraints for table `child_immunizations`
--
ALTER TABLE `child_immunizations`
  ADD CONSTRAINT `fk_child_immunizations_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`) ON UPDATE CASCADE;

--
-- Constraints for table `child_nutrition`
--
ALTER TABLE `child_nutrition`
  ADD CONSTRAINT `fk_childnutr_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `cvc_screening`
--
ALTER TABLE `cvc_screening`
  ADD CONSTRAINT `fk_cvc_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `death_records`
--
ALTER TABLE `death_records`
  ADD CONSTRAINT `fk_death_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  ADD CONSTRAINT `fk_death_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `user_management` (`user_id`),
  ADD CONSTRAINT `fk_death_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `delivery_outcomes`
--
ALTER TABLE `delivery_outcomes`
  ADD CONSTRAINT `fk_delivery_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `deworming_records`
--
ALTER TABLE `deworming_records`
  ADD CONSTRAINT `fk_deworm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `deworming_supplementation`
--
ALTER TABLE `deworming_supplementation`
  ADD CONSTRAINT `fk_dewormsupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `disability_type`
--
ALTER TABLE `disability_type`
  ADD CONSTRAINT `fk_disability_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `environmental_sanitation`
--
ALTER TABLE `environmental_sanitation`
  ADD CONSTRAINT `fk_env_household` FOREIGN KEY (`household_id`) REFERENCES `households` (`household_id`),
  ADD CONSTRAINT `fk_env_user` FOREIGN KEY (`user_id`) REFERENCES `user_management` (`user_id`);

--
-- Constraints for table `family_history`
--
ALTER TABLE `family_history`
  ADD CONSTRAINT `fk_famhistory_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`);

--
-- Constraints for table `family_planning`
--
ALTER TABLE `family_planning`
  ADD CONSTRAINT `fk_fp_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `fic_cic_status`
--
ALTER TABLE `fic_cic_status`
  ADD CONSTRAINT `fk_fic_cic_status_child` FOREIGN KEY (`child_immunization_id`) REFERENCES `child_immunizations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fp_commodities_given`
--
ALTER TABLE `fp_commodities_given`
  ADD CONSTRAINT `fk_fpcommodity_fp` FOREIGN KEY (`fp_id`) REFERENCES `family_planning` (`fp_id`);

--
-- Constraints for table `gdm_screening`
--
ALTER TABLE `gdm_screening`
  ADD CONSTRAINT `fk_gdm_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `gestational_screening`
--
ALTER TABLE `gestational_screening`
  ADD CONSTRAINT `fk_gestational_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `hepatitis_b_screening`
--
ALTER TABLE `hepatitis_b_screening`
  ADD CONSTRAINT `fk_hepb_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `hiv_screening`
--
ALTER TABLE `hiv_screening`
  ADD CONSTRAINT `fk_hiv_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `hpv_immunization`
--
ALTER TABLE `hpv_immunization`
  ADD CONSTRAINT `fk_hpvimm_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `ifa_supplementation`
--
ALTER TABLE `ifa_supplementation`
  ADD CONSTRAINT `fk_ifasupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `immunization_doses`
--
ALTER TABLE `immunization_doses`
  ADD CONSTRAINT `fk_immunization_doses_child` FOREIGN KEY (`child_immunization_id`) REFERENCES `child_immunizations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `iron_supplementation`
--
ALTER TABLE `iron_supplementation`
  ADD CONSTRAINT `fk_ironsupp_childnutr` FOREIGN KEY (`child_nutrition_id`) REFERENCES `child_nutrition` (`child_nutrition_id`);

--
-- Constraints for table `malnutrition_management`
--
ALTER TABLE `malnutrition_management`
  ADD CONSTRAINT `fk_malnutrmgmt_childnutr` FOREIGN KEY (`child_nutrition_id`) REFERENCES `child_nutrition` (`child_nutrition_id`);

--
-- Constraints for table `maternal_care`
--
ALTER TABLE `maternal_care`
  ADD CONSTRAINT `fk_matcare_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD CONSTRAINT `fk_medhistory_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`);

--
-- Constraints for table `mms_supplementation`
--
ALTER TABLE `mms_supplementation`
  ADD CONSTRAINT `fk_mmssupp_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_account` FOREIGN KEY (`account_id`) REFERENCES `resident_accounts` (`account_id`),
  ADD CONSTRAINT `fk_notif_conv` FOREIGN KEY (`related_conversation_id`) REFERENCES `chatbot_conversations` (`conversation_id`),
  ADD CONSTRAINT `fk_notif_request` FOREIGN KEY (`related_request_id`) REFERENCES `record_requests` (`request_id`);

--
-- Constraints for table `nutrition_supplementation`
--
ALTER TABLE `nutrition_supplementation`
  ADD CONSTRAINT `fk_nutrsupp_childnutr` FOREIGN KEY (`child_nutrition_id`) REFERENCES `child_nutrition` (`child_nutrition_id`);

--
-- Constraints for table `offline_sync_receipts`
--
ALTER TABLE `offline_sync_receipts`
  ADD CONSTRAINT `fk_syncrcpt_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `user_management` (`user_id`),
  ADD CONSTRAINT `fk_syncrcpt_household` FOREIGN KEY (`household_pk`) REFERENCES `households` (`household_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_syncrcpt_resident` FOREIGN KEY (`resident_pk`) REFERENCES `residents` (`resident_id`) ON DELETE SET NULL;

--
-- Constraints for table `past_medical_history`
--
ALTER TABLE `past_medical_history`
  ADD CONSTRAINT `fk_pastmed_riskassess` FOREIGN KEY (`risk_assessment_id`) REFERENCES `risk_assessment` (`risk_assessment_id`);

--
-- Constraints for table `postnatal_care_visits`
--
ALTER TABLE `postnatal_care_visits`
  ADD CONSTRAINT `fk_pnc_delivery` FOREIGN KEY (`delivery_outcome_id`) REFERENCES `delivery_outcomes` (`delivery_outcome_id`);

--
-- Constraints for table `postpartum_ifa_supplementation`
--
ALTER TABLE `postpartum_ifa_supplementation`
  ADD CONSTRAINT `fk_postpartumifa_delivery` FOREIGN KEY (`delivery_outcome_id`) REFERENCES `delivery_outcomes` (`delivery_outcome_id`);

--
-- Constraints for table `postpartum_vitamin_a_supplementation`
--
ALTER TABLE `postpartum_vitamin_a_supplementation`
  ADD CONSTRAINT `fk_ppvita_matcare` FOREIGN KEY (`maternal_care_id`) REFERENCES `maternal_care` (`maternal_care_id`);

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

--
-- Constraints for table `resident_statuses`
--
ALTER TABLE `resident_statuses`
  ADD CONSTRAINT `fk_resstatus_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  ADD CONSTRAINT `fk_resstatus_death` FOREIGN KEY (`death_record_id`) REFERENCES `death_records` (`death_record_id`) ON DELETE SET NULL;

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

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

--
-- Migrations already represented by this schema (legacy table builders are skipped on MySQL).
--
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_cache_table', 1),
('0001_01_01_000002_create_jobs_table', 1),
('2026_08_17_100000_create_death_requests_table', 1),
('2026_08_17_100100_create_resident_statuses_table', 1),
('2026_08_17_100200_add_registry_no_to_death_requests_table', 1),
('2026_08_21_100000_evolve_users_for_staff_identity', 1),
('2026_08_21_100100_create_worker_appointments_table', 1),
('2026_08_21_140000_make_worker_appointment_employment_nullable', 1),
('2026_08_21_150000_backfill_registry_no_from_certificate_no_on_death_requests', 1),
('2026_08_21_160000_create_households_table', 1),
('2026_08_21_160100_create_residents_table', 1),
('2026_08_22_100000_add_resident_id_to_death_requests_table', 1),
('2026_08_22_100100_add_resident_id_to_resident_statuses_table', 1),
('2026_08_22_100200_backfill_resident_id_on_death_tables', 1),
('2026_08_22_120000_create_child_birth_histories_table', 1),
('2026_08_22_130000_create_deworming_records_table', 1),
('2026_08_23_100000_add_resident_status_unique_indexes_to_death_requests', 1),
('2026_08_24_100000_create_child_immunizations_table', 1),
('2026_08_24_100100_create_immunization_doses_table', 1),
('2026_08_24_110000_create_school_immunizations_table', 1),
('2026_08_24_110100_create_school_immunization_doses_table', 1),
('2026_08_24_120000_create_child_nutritions_table', 1),
('2026_08_24_120100_create_child_nutrition_sfp_outcomes_table', 1),
('2026_08_25_100000_create_risk_assessments_table', 1),
('2026_08_25_110000_create_family_planning_visits_table', 1),
('2026_08_25_140000_create_maternal_pregnancies_table', 1),
('2026_08_26_100000_create_operation_timbang_measurements_table', 1),
('2026_08_26_200000_create_household_environmental_profiles_tables', 1),
('2026_08_28_120000_create_announcements_table', 1),
('2026_09_05_100000_create_offline_sync_receipts_table', 1),
('2026_09_12_011800_add_resident_id_to_resident_accounts_table', 1),
('2026_09_12_011810_create_record_request_otps_table', 1),
('2026_09_12_011820_add_language_and_category_to_chatbot_messages_table', 1),
('2026_09_12_011830_create_health_chunks_table', 1),
('2026_09_12_100000_create_timbang_records_table', 1),
('2026_09_12_191500_fix_record_request_otp_expires_at_no_auto_update', 1),
('2026_09_12_220000_add_announcement_context_to_notifications_table', 1),
('2026_09_16_100000_add_nutrition_assessment_columns_to_timbang_records_table', 1),
('2026_09_16_110000_widen_timbang_records_weight_height_for_age_enums', 1),
('2026_09_18_134900_create_rusf_supplementation_table', 1),
('2026_09_18_150000_create_maternal_lab_screening_tables', 1),
('2026_09_18_160000_add_delivery_outcome_newborn_sex_and_plurality', 1),
('2026_09_18_170000_create_postpartum_vitamin_a_supplementation_table', 1),
('2026_09_18_180000_create_worker_appointment_zones_table', 1),
('2026_09_18_190000_add_deleted_at_to_staff_identity_tables', 1),
('2026_09_18_210000_update_risk_assessment_dietary_and_physical_activity_yes_no', 1),
('2026_09_19_100000_create_adult_immunization_table', 1)
;
