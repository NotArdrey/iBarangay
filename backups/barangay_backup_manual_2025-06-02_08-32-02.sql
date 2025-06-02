-- Barangay Database Backup
-- Database: barangay
-- Backup Type: manual
-- Created: 2025-06-02 08:32:02
-- Tables: 84

SET FOREIGN_KEY_CHECKS=0;

-- --------------------------------------------------------
-- Table structure for `active_document_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `active_document_requests`;
;

-- --------------------------------------------------------
-- Table structure for `addresses`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `addresses`;
CREATE TABLE `addresses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `barangay_id` int DEFAULT NULL,
  `barangay_name` varchar(60) DEFAULT NULL,
  `house_no` varchar(50) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `phase` varchar(50) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT 'SAN RAFAEL',
  `province` varchar(100) DEFAULT 'BULACAN',
  `region` varchar(50) DEFAULT 'III',
  `subdivision` varchar(100) DEFAULT NULL,
  `block_lot` varchar(50) DEFAULT NULL,
  `residency_type` enum('Home Owner','Renter','Boarder','Living-In') NOT NULL,
  `years_in_san_rafael` int DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT '1',
  `is_permanent` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_addresses_person_id` (`person_id`),
  KEY `idx_addresses_barangay_id` (`barangay_id`),
  CONSTRAINT `addresses_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `addresses_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `addresses_ibfk_3` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `asset_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `asset_types`;
CREATE TABLE `asset_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `assets_properties`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `assets_properties`;
CREATE TABLE `assets_properties` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `house` tinyint(1) DEFAULT '0',
  `house_lot` tinyint(1) DEFAULT '0',
  `farmland` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `assets_properties_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `audit_trails`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `audit_trails`;
CREATE TABLE `audit_trails` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `admin_user_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` varchar(100) DEFAULT NULL,
  `old_values` text,
  `new_values` text,
  `description` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `action_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_trails_user_action` (`user_id`,`action`,`action_timestamp`),
  CONSTRAINT `audit_trails_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `barangay`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `barangay`;
CREATE TABLE `barangay` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `barangay_document_prices`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `barangay_document_prices`;
CREATE TABLE `barangay_document_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barangay_id` int NOT NULL,
  `document_type_id` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_barangay_document` (`barangay_id`,`document_type_id`),
  KEY `document_type_id` (`document_type_id`),
  CONSTRAINT `barangay_document_prices_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE,
  CONSTRAINT `barangay_document_prices_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `barangay_settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `barangay_settings`;
CREATE TABLE `barangay_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barangay_id` int NOT NULL,
  `cutoff_time` time NOT NULL DEFAULT '15:00:00',
  `opening_time` time NOT NULL DEFAULT '08:00:00',
  `closing_time` time NOT NULL DEFAULT '17:00:00',
  `barangay_captain_name` varchar(100) DEFAULT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `local_barangay_contact` varchar(20) DEFAULT NULL,
  `pnp_contact` varchar(20) DEFAULT NULL,
  `bfp_contact` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `barangay_settings_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `blotter_case_categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `blotter_case_categories`;
CREATE TABLE `blotter_case_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `category_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_category` (`blotter_case_id`,`category_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `blotter_case_categories_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blotter_case_categories_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `case_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `blotter_case_interventions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `blotter_case_interventions`;
CREATE TABLE `blotter_case_interventions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `intervention_id` int NOT NULL,
  `intervened_at` datetime NOT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_intervention_date` (`blotter_case_id`,`intervention_id`,`intervened_at`),
  KEY `intervention_id` (`intervention_id`),
  CONSTRAINT `blotter_case_interventions_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blotter_case_interventions_ibfk_2` FOREIGN KEY (`intervention_id`) REFERENCES `case_interventions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `blotter_case_summary`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `blotter_case_summary`;
;

-- --------------------------------------------------------
-- Table structure for `blotter_cases`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `blotter_cases`;
CREATE TABLE `blotter_cases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `case_number` varchar(50) DEFAULT NULL,
  `incident_date` datetime DEFAULT NULL,
  `filing_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `location` varchar(200) DEFAULT NULL,
  `description` text,
  `status` enum('pending','open','closed','completed','transferred','solved','endorsed_to_court','cfa_eligible','dismissed','deleted') DEFAULT 'pending',
  `scheduling_status` enum('none','pending_schedule','schedule_proposed','schedule_confirmed','scheduled','completed','cancelled') DEFAULT 'none',
  `barangay_id` int DEFAULT NULL,
  `reported_by_person_id` int DEFAULT NULL,
  `assigned_to_user_id` int DEFAULT NULL,
  `accepted_by_user_id` int DEFAULT NULL,
  `accepted_by_role_id` int DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `scheduled_hearing` datetime DEFAULT NULL,
  `resolution_details` text,
  `resolved_at` datetime DEFAULT NULL,
  `dismissed_by_user_id` int DEFAULT NULL,
  `dismissal_reason` text,
  `dismissal_date` datetime DEFAULT NULL,
  `is_cfa_eligible` tinyint(1) DEFAULT '0',
  `cfa_issued_at` datetime DEFAULT NULL,
  `endorsed_to_court_at` datetime DEFAULT NULL,
  `hearing_count` int DEFAULT '0',
  `requires_dual_signature` tinyint(1) DEFAULT '0',
  `captain_signature_date` datetime DEFAULT NULL,
  `chief_signature_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `scheduling_deadline` datetime GENERATED ALWAYS AS ((`filing_date` + interval 5 day)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `case_number` (`case_number`),
  KEY `barangay_id` (`barangay_id`),
  KEY `reported_by_person_id` (`reported_by_person_id`),
  KEY `assigned_to_user_id` (`assigned_to_user_id`),
  KEY `idx_blotter_cases_status` (`status`,`barangay_id`),
  KEY `idx_blotter_cases_scheduling` (`scheduling_status`),
  KEY `idx_blotter_cases_dismissed` (`dismissed_by_user_id`,`dismissal_date`),
  KEY `accepted_by_user_id` (`accepted_by_user_id`),
  KEY `accepted_by_role_id` (`accepted_by_role_id`),
  CONSTRAINT `blotter_cases_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE SET NULL,
  CONSTRAINT `blotter_cases_ibfk_2` FOREIGN KEY (`reported_by_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `blotter_cases_ibfk_3` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `blotter_cases_ibfk_4` FOREIGN KEY (`dismissed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `blotter_cases_ibfk_5` FOREIGN KEY (`accepted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `blotter_cases_ibfk_6` FOREIGN KEY (`accepted_by_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `blotter_participants`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `blotter_participants`;
CREATE TABLE `blotter_participants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `person_id` int DEFAULT NULL,
  `external_participant_id` int DEFAULT NULL,
  `role` enum('complainant','respondent','witness') NOT NULL,
  `statement` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_person_role` (`blotter_case_id`,`person_id`,`role`),
  UNIQUE KEY `uk_case_external_role` (`blotter_case_id`,`external_participant_id`,`role`),
  KEY `person_id` (`person_id`),
  KEY `external_participant_id` (`external_participant_id`),
  CONSTRAINT `blotter_participants_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blotter_participants_ibfk_2` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blotter_participants_ibfk_3` FOREIGN KEY (`external_participant_id`) REFERENCES `external_participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `case_categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `case_categories`;
CREATE TABLE `case_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `case_hearings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `case_hearings`;
CREATE TABLE `case_hearings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `hearing_date` datetime NOT NULL,
  `hearing_type` enum('initial','mediation','conciliation','final') NOT NULL,
  `hearing_notes` text,
  `hearing_outcome` enum('scheduled','conducted','postponed','resolved','failed') DEFAULT 'scheduled',
  `presided_by_user_id` int DEFAULT NULL,
  `next_hearing_date` datetime DEFAULT NULL,
  `hearing_number` int DEFAULT '1',
  `presiding_officer_name` varchar(100) DEFAULT NULL,
  `presiding_officer_position` varchar(100) DEFAULT NULL,
  `is_mediation_successful` tinyint(1) DEFAULT '0',
  `resolution_details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `presided_by_user_id` (`presided_by_user_id`),
  CONSTRAINT `case_hearings_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `case_hearings_ibfk_2` FOREIGN KEY (`presided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `case_interventions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `case_interventions`;
CREATE TABLE `case_interventions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `case_notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `case_notifications`;
CREATE TABLE `case_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `notified_user_id` int NOT NULL,
  `notification_type` enum('case_filed','case_accepted','hearing_scheduled','signature_required','schedule_confirmation','schedule_approved','schedule_rejected') NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `notified_user_id` (`notified_user_id`),
  CONSTRAINT `case_notifications_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `case_notifications_ibfk_2` FOREIGN KEY (`notified_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `cfa_certificates`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `cfa_certificates`;
CREATE TABLE `cfa_certificates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `complainant_person_id` int DEFAULT NULL,
  `issued_by_user_id` int DEFAULT NULL,
  `certificate_number` varchar(50) NOT NULL,
  `issued_at` datetime NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `complainant_person_id` (`complainant_person_id`),
  KEY `issued_by_user_id` (`issued_by_user_id`),
  CONSTRAINT `cfa_certificates_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cfa_certificates_ibfk_2` FOREIGN KEY (`complainant_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cfa_certificates_ibfk_3` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `child_disabilities`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `child_disabilities`;
CREATE TABLE `child_disabilities` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `disability_type` enum('Blind/Visually Impaired','Hearing Impairment','Speech/Communication','Orthopedic/Physical','Intellectual/Learning','Psychosocial') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `child_disabilities_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `child_health_conditions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `child_health_conditions`;
CREATE TABLE `child_health_conditions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `condition_type` enum('Malaria','Dengue','Pneumonia','Tuberculosis','Diarrhea') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `child_health_conditions_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `child_information`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `child_information`;
CREATE TABLE `child_information` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `is_malnourished` tinyint(1) DEFAULT '0',
  `attending_school` tinyint(1) DEFAULT '0',
  `school_name` varchar(255) DEFAULT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `school_type` enum('Public','Private','ALS','Day Care','SNP','Not Attending') DEFAULT 'Not Attending',
  `immunization_complete` tinyint(1) DEFAULT '0',
  `is_pantawid_beneficiary` tinyint(1) DEFAULT '0',
  `has_timbang_operation` tinyint(1) DEFAULT '0',
  `has_feeding_program` tinyint(1) DEFAULT '0',
  `has_supplementary_feeding` tinyint(1) DEFAULT '0',
  `in_caring_institution` tinyint(1) DEFAULT '0',
  `is_under_foster_care` tinyint(1) DEFAULT '0',
  `is_directly_entrusted` tinyint(1) DEFAULT '0',
  `is_legally_adopted` tinyint(1) DEFAULT '0',
  `occupation` varchar(255) DEFAULT NULL,
  `garantisadong_pambata` tinyint(1) DEFAULT '0',
  `under_six_years` tinyint(1) DEFAULT '0',
  `grade_school` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `child_information_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `custom_services`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `custom_services`;
CREATE TABLE `custom_services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `barangay_id` int NOT NULL,
  `service_type` varchar(50) DEFAULT 'general',
  `name` varchar(100) NOT NULL,
  `description` text,
  `detailed_guide` text,
  `requirements` text,
  `processing_time` varchar(100) DEFAULT NULL,
  `fees` varchar(100) DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'fa-file',
  `url_path` varchar(255) DEFAULT NULL,
  `display_order` int DEFAULT '0',
  `priority_level` enum('normal','high','urgent') DEFAULT 'normal',
  `availability_type` enum('always','scheduled','limited') DEFAULT 'always',
  `additional_notes` text,
  `service_photo` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `custom_services_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_services_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `document_attribute_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `document_attribute_types`;
CREATE TABLE `document_attribute_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_type_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(40) NOT NULL,
  `description` varchar(200) DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_attr_code` (`document_type_id`,`code`),
  CONSTRAINT `document_attribute_types_ibfk_1` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `document_request_attributes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `document_request_attributes`;
CREATE TABLE `document_request_attributes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `attribute_type_id` int NOT NULL,
  `value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_attribute` (`request_id`,`attribute_type_id`),
  KEY `attribute_type_id` (`attribute_type_id`),
  CONSTRAINT `document_request_attributes_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_request_attributes_ibfk_2` FOREIGN KEY (`attribute_type_id`) REFERENCES `document_attribute_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `document_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `document_requests`;
CREATE TABLE `document_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `gender` enum('Male','Female','Others') DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `citizenship` varchar(50) DEFAULT 'Filipino',
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(100) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `education_level` varchar(100) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `monthly_income` decimal(10,2) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address_no` varchar(50) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `business_name` varchar(100) DEFAULT NULL,
  `business_location` varchar(200) DEFAULT NULL,
  `business_nature` varchar(200) DEFAULT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `purpose` text,
  `ctc_number` varchar(100) DEFAULT NULL,
  `or_number` varchar(100) DEFAULT NULL,
  `document_type_id` int NOT NULL,
  `barangay_id` int NOT NULL,
  `status` enum('pending','processing','for_payment','paid','for_pickup','completed','cancelled','rejected') DEFAULT 'pending',
  `price` decimal(10,2) DEFAULT '0.00',
  `remarks` text,
  `proof_image_path` varchar(255) DEFAULT NULL,
  `requested_by_user_id` int DEFAULT NULL,
  `processed_by_user_id` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `request_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `requested_by_user_id` (`requested_by_user_id`),
  KEY `processed_by_user_id` (`processed_by_user_id`),
  KEY `idx_doc_requests_status_barangay` (`status`,`barangay_id`,`request_date`),
  KEY `idx_doc_requests_person` (`person_id`),
  KEY `idx_doc_requests_doctype` (`document_type_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_2` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_3` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_requests_ibfk_4` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_requests_ibfk_5` FOREIGN KEY (`processed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_requests_ibfk_6` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `document_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `document_types`;
CREATE TABLE `document_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` text,
  `default_fee` decimal(10,2) DEFAULT '0.00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `email_logs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `email_logs`;
CREATE TABLE `email_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `template_used` varchar(100) DEFAULT NULL,
  `sent_at` datetime NOT NULL,
  `status` enum('sent','failed','pending') DEFAULT 'pending',
  `error_message` text,
  `blotter_case_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `emergency_contacts`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `emergency_contacts`;
CREATE TABLE `emergency_contacts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `contact_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `contact_address` varchar(200) DEFAULT NULL,
  `relationship` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `emergency_contacts_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `event_participants`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `event_participants`;
CREATE TABLE `event_participants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL,
  `person_id` int NOT NULL,
  `attendance_status` enum('registered','confirmed','attended','no_show') DEFAULT 'registered',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event_person` (`event_id`,`person_id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `event_participants_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_participants_ibfk_2` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `events`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `description` text,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `location` varchar(200) NOT NULL,
  `organizer` varchar(100) DEFAULT NULL,
  `barangay_id` int NOT NULL,
  `created_by_user_id` int NOT NULL,
  `status` enum('scheduled','ongoing','completed','postponed','cancelled') DEFAULT 'scheduled',
  `max_participants` int DEFAULT NULL,
  `registration_required` tinyint(1) DEFAULT '0',
  `registration_deadline` datetime DEFAULT NULL,
  `event_type` enum('meeting','seminar','activity','celebration','emergency','other') DEFAULT 'other',
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `requirements` text,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `idx_events_barangay_date` (`barangay_id`,`start_datetime`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE,
  CONSTRAINT `events_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `external_participants`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `external_participants`;
CREATE TABLE `external_participants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `age` int DEFAULT NULL,
  `gender` enum('Male','Female','Others') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `family_composition`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `family_composition`;
CREATE TABLE `family_composition` (
  `id` int NOT NULL AUTO_INCREMENT,
  `household_id` int NOT NULL,
  `person_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `relationship` varchar(50) NOT NULL,
  `age` int NOT NULL,
  `civil_status` enum('SINGLE','MARRIED','WIDOW/WIDOWER','SEPARATED') NOT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `monthly_income` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `household_id` (`household_id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `family_composition_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE,
  CONSTRAINT `family_composition_ibfk_2` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `government_programs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `government_programs`;
CREATE TABLE `government_programs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `nhts_pr_listahanan` tinyint(1) DEFAULT '0',
  `indigenous_people` tinyint(1) DEFAULT '0',
  `pantawid_beneficiary` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `government_programs_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `hearing_attendances`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `hearing_attendances`;
CREATE TABLE `hearing_attendances` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hearing_id` int NOT NULL,
  `participant_id` int NOT NULL,
  `is_present` tinyint(1) DEFAULT '0',
  `remarks` varchar(255) DEFAULT NULL,
  `participant_type` varchar(20) DEFAULT NULL,
  `attendance_remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hearing_participant` (`hearing_id`,`participant_id`),
  KEY `participant_id` (`participant_id`),
  CONSTRAINT `hearing_attendances_ibfk_1` FOREIGN KEY (`hearing_id`) REFERENCES `case_hearings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hearing_attendances_ibfk_2` FOREIGN KEY (`participant_id`) REFERENCES `blotter_participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `hearing_schedules`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `hearing_schedules`;
CREATE TABLE `hearing_schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hearing_date` date NOT NULL,
  `hearing_time` time NOT NULL,
  `location` varchar(255) DEFAULT 'Barangay Hall',
  `max_hearings_per_slot` int DEFAULT '5',
  `current_bookings` int DEFAULT '0',
  `is_available` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `household_info`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `household_info`;
;

-- --------------------------------------------------------
-- Table structure for `household_members`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `household_members`;
CREATE TABLE `household_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `household_id` int NOT NULL,
  `person_id` int NOT NULL,
  `relationship_type_id` int NOT NULL,
  `is_household_head` tinyint(1) DEFAULT '0',
  `relationship_to_head` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_household_person` (`household_id`,`person_id`),
  KEY `relationship_type_id` (`relationship_type_id`),
  KEY `idx_household_members_household` (`household_id`),
  KEY `idx_household_members_person` (`person_id`),
  CONSTRAINT `household_members_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE,
  CONSTRAINT `household_members_ibfk_2` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `household_members_ibfk_3` FOREIGN KEY (`relationship_type_id`) REFERENCES `relationship_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `households`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `households`;
CREATE TABLE `households` (
  `id` int NOT NULL AUTO_INCREMENT,
  `household_number` varchar(50) NOT NULL,
  `barangay_id` int NOT NULL,
  `purok_id` int DEFAULT NULL,
  `household_head_person_id` int DEFAULT NULL,
  `household_size` int DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_household_number` (`household_number`,`barangay_id`,`purok_id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `purok_id` (`purok_id`),
  KEY `household_head_person_id` (`household_head_person_id`),
  CONSTRAINT `households_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE,
  CONSTRAINT `households_ibfk_2` FOREIGN KEY (`purok_id`) REFERENCES `purok` (`id`) ON DELETE SET NULL,
  CONSTRAINT `households_ibfk_3` FOREIGN KEY (`household_head_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `income_source_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `income_source_types`;
CREATE TABLE `income_source_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `requires_amount` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `income_sources`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `income_sources`;
CREATE TABLE `income_sources` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `own_earnings` tinyint(1) DEFAULT '0',
  `own_pension` tinyint(1) DEFAULT '0',
  `own_pension_amount` decimal(10,2) DEFAULT NULL,
  `stocks_dividends` tinyint(1) DEFAULT '0',
  `dependent_on_children` tinyint(1) DEFAULT '0',
  `spouse_salary` tinyint(1) DEFAULT '0',
  `insurances` tinyint(1) DEFAULT '0',
  `spouse_pension` tinyint(1) DEFAULT '0',
  `spouse_pension_amount` decimal(10,2) DEFAULT NULL,
  `rentals_sharecrops` tinyint(1) DEFAULT '0',
  `savings` tinyint(1) DEFAULT '0',
  `livestock_orchards` tinyint(1) DEFAULT '0',
  `others` tinyint(1) DEFAULT '0',
  `others_specify` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `income_sources_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `involvement_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `involvement_types`;
CREATE TABLE `involvement_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `living_arrangement_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `living_arrangement_types`;
CREATE TABLE `living_arrangement_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `living_arrangements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `living_arrangements`;
CREATE TABLE `living_arrangements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `spouse` tinyint(1) DEFAULT '0',
  `care_institutions` tinyint(1) DEFAULT '0',
  `children` tinyint(1) DEFAULT '0',
  `grandchildren` tinyint(1) DEFAULT '0',
  `househelps` tinyint(1) DEFAULT '0',
  `relatives` tinyint(1) DEFAULT '0',
  `others` tinyint(1) DEFAULT '0',
  `others_specify` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `living_arrangements_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `monthly_report_details`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `monthly_report_details`;
CREATE TABLE `monthly_report_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `monthly_report_id` int NOT NULL,
  `category_id` int NOT NULL,
  `total_cases` int DEFAULT '0',
  `total_pnp` int DEFAULT '0',
  `total_court` int DEFAULT '0',
  `total_issued_bpo` int DEFAULT '0',
  `total_medical` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_report_category` (`monthly_report_id`,`category_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `monthly_report_details_ibfk_1` FOREIGN KEY (`monthly_report_id`) REFERENCES `monthly_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `monthly_report_details_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `case_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `monthly_reports`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `monthly_reports`;
CREATE TABLE `monthly_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barangay_id` int NOT NULL,
  `report_month` int NOT NULL,
  `report_year` int NOT NULL,
  `created_by_user_id` int NOT NULL,
  `prepared_by_user_id` int DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_barangay_month_year` (`barangay_id`,`report_month`,`report_year`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `prepared_by_user_id` (`prepared_by_user_id`),
  CONSTRAINT `monthly_reports_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE,
  CONSTRAINT `monthly_reports_ibfk_2` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `monthly_reports_ibfk_3` FOREIGN KEY (`prepared_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'general',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_table` varchar(100) DEFAULT NULL,
  `related_id` int DEFAULT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_type` (`type`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_notifications_user_read` (`user_id`,`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `other_need_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `other_need_types`;
CREATE TABLE `other_need_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` enum('social','economic','environmental','others') NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `participant_notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `participant_notifications`;
CREATE TABLE `participant_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `participant_id` int NOT NULL,
  `email_address` varchar(255) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `notification_type` enum('summons','hearing_notice','reminder') DEFAULT 'summons',
  `sent_at` datetime DEFAULT NULL,
  `confirmed` tinyint(1) DEFAULT '0',
  `confirmed_at` datetime DEFAULT NULL,
  `confirmation_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_case_participant_type` (`blotter_case_id`,`participant_id`,`notification_type`),
  KEY `participant_id` (`participant_id`),
  KEY `idx_confirmation_token` (`confirmation_token`),
  KEY `idx_sent_confirmed` (`sent_at`,`confirmed`),
  CONSTRAINT `participant_notifications_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `participant_notifications_ibfk_2` FOREIGN KEY (`participant_id`) REFERENCES `blotter_participants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `password_history`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `password_history`;
CREATE TABLE `password_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id_created` (`user_id`,`created_at`),
  CONSTRAINT `password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `password_reset_tokens`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`email`) REFERENCES `users` (`email`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_assets`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_assets`;
CREATE TABLE `person_assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `asset_type_id` int NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_asset` (`person_id`,`asset_type_id`),
  KEY `asset_type_id` (`asset_type_id`),
  CONSTRAINT `person_assets_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_assets_ibfk_2` FOREIGN KEY (`asset_type_id`) REFERENCES `asset_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_community_problems`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_community_problems`;
CREATE TABLE `person_community_problems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `desire_participate` tinyint(1) DEFAULT '0',
  `skills_to_share` tinyint(1) DEFAULT '0',
  `other_community` tinyint(1) DEFAULT '0',
  `other_community_details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `person_community_problems_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_economic_problems`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_economic_problems`;
CREATE TABLE `person_economic_problems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `loss_income` tinyint(1) DEFAULT '0',
  `unemployment` tinyint(1) DEFAULT '0',
  `skills_training` tinyint(1) DEFAULT '0',
  `skills_training_details` text,
  `livelihood` tinyint(1) DEFAULT '0',
  `livelihood_details` text,
  `other_economic` tinyint(1) DEFAULT '0',
  `other_economic_details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `person_economic_problems_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_health_info`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_health_info`;
CREATE TABLE `person_health_info` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `health_condition` text,
  `has_maintenance` tinyint(1) DEFAULT '0',
  `maintenance_details` text,
  `high_cost_medicines` tinyint(1) DEFAULT '0',
  `lack_medical_professionals` tinyint(1) DEFAULT '0',
  `lack_sanitation_access` tinyint(1) DEFAULT '0',
  `lack_health_insurance` tinyint(1) DEFAULT '0',
  `lack_medical_facilities` tinyint(1) DEFAULT '0',
  `other_health_concerns` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `person_health_info_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_health_problems`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_health_problems`;
CREATE TABLE `person_health_problems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `condition_illness` tinyint(1) DEFAULT '0',
  `condition_illness_details` text,
  `high_cost_medicine` tinyint(1) DEFAULT '0',
  `lack_medical_professionals` tinyint(1) DEFAULT '0',
  `lack_sanitation` tinyint(1) DEFAULT '0',
  `lack_health_insurance` tinyint(1) DEFAULT '0',
  `inadequate_health_services` tinyint(1) DEFAULT '0',
  `other_health` tinyint(1) DEFAULT '0',
  `other_health_details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `person_health_problems_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_housing_problems`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_housing_problems`;
CREATE TABLE `person_housing_problems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `overcrowding` tinyint(1) DEFAULT '0',
  `no_permanent_housing` tinyint(1) DEFAULT '0',
  `independent_living` tinyint(1) DEFAULT '0',
  `lost_privacy` tinyint(1) DEFAULT '0',
  `squatters` tinyint(1) DEFAULT '0',
  `other_housing` tinyint(1) DEFAULT '0',
  `other_housing_details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `person_housing_problems_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_identification`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_identification`;
CREATE TABLE `person_identification` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `osca_id` varchar(50) DEFAULT NULL,
  `gsis_id` varchar(50) DEFAULT NULL,
  `sss_id` varchar(50) DEFAULT NULL,
  `tin_id` varchar(50) DEFAULT NULL,
  `philhealth_id` varchar(50) DEFAULT NULL,
  `other_id_type` varchar(50) DEFAULT NULL,
  `other_id_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `person_identification_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_income_sources`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_income_sources`;
CREATE TABLE `person_income_sources` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `source_type_id` int NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_income_source` (`person_id`,`source_type_id`),
  KEY `source_type_id` (`source_type_id`),
  CONSTRAINT `person_income_sources_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_income_sources_ibfk_2` FOREIGN KEY (`source_type_id`) REFERENCES `income_source_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_involvements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_involvements`;
CREATE TABLE `person_involvements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `involvement_type_id` int NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_involvement` (`person_id`,`involvement_type_id`),
  KEY `involvement_type_id` (`involvement_type_id`),
  CONSTRAINT `person_involvements_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_involvements_ibfk_2` FOREIGN KEY (`involvement_type_id`) REFERENCES `involvement_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_living_arrangements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_living_arrangements`;
CREATE TABLE `person_living_arrangements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `arrangement_type_id` int NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_arrangement` (`person_id`,`arrangement_type_id`),
  KEY `arrangement_type_id` (`arrangement_type_id`),
  CONSTRAINT `person_living_arrangements_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_living_arrangements_ibfk_2` FOREIGN KEY (`arrangement_type_id`) REFERENCES `living_arrangement_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_other_needs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_other_needs`;
CREATE TABLE `person_other_needs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `need_type_id` int NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_other_need` (`person_id`,`need_type_id`),
  KEY `need_type_id` (`need_type_id`),
  CONSTRAINT `person_other_needs_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_other_needs_ibfk_2` FOREIGN KEY (`need_type_id`) REFERENCES `other_need_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_problems`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_problems`;
CREATE TABLE `person_problems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `problem_category_id` int NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_problem` (`person_id`,`problem_category_id`),
  KEY `problem_category_id` (`problem_category_id`),
  CONSTRAINT `person_problems_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_problems_ibfk_2` FOREIGN KEY (`problem_category_id`) REFERENCES `problem_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_skills`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_skills`;
CREATE TABLE `person_skills` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `skill_type_id` int NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_person_skill` (`person_id`,`skill_type_id`),
  KEY `skill_type_id` (`skill_type_id`),
  CONSTRAINT `person_skills_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `person_skills_ibfk_2` FOREIGN KEY (`skill_type_id`) REFERENCES `skill_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_social_problems`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_social_problems`;
CREATE TABLE `person_social_problems` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `loneliness` tinyint(1) DEFAULT '0',
  `isolation` tinyint(1) DEFAULT '0',
  `neglect` tinyint(1) DEFAULT '0',
  `recreational` tinyint(1) DEFAULT '0',
  `senior_friendly` tinyint(1) DEFAULT '0',
  `other_social` tinyint(1) DEFAULT '0',
  `other_social_details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  CONSTRAINT `person_social_problems_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `person_summary`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `person_summary`;
;

-- --------------------------------------------------------
-- Table structure for `personal_access_tokens`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE `personal_access_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_tokenable` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `persons`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `persons`;
CREATE TABLE `persons` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `birth_date` date NOT NULL,
  `birth_place` varchar(100) NOT NULL,
  `gender` enum('MALE','FEMALE') NOT NULL,
  `civil_status` enum('SINGLE','MARRIED','WIDOW/WIDOWER','SEPARATED') NOT NULL,
  `citizenship` varchar(50) DEFAULT 'Filipino',
  `religion` varchar(50) DEFAULT NULL,
  `education_level` enum('NOT ATTENDED ANY SCHOOL','ELEMENTARY LEVEL','ELEMENTARY GRADUATE','HIGH SCHOOL LEVEL','HIGH SCHOOL GRADUATE','VOCATIONAL','COLLEGE LEVEL','COLLEGE GRADUATE','POST GRADUATE') DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `monthly_income` decimal(10,2) DEFAULT NULL,
  `years_of_residency` int DEFAULT '0',
  `nhts_pr_listahanan` tinyint(1) DEFAULT '0',
  `indigenous_people` tinyint(1) DEFAULT '0',
  `pantawid_beneficiary` tinyint(1) DEFAULT '0',
  `resident_type` enum('REGULAR','SENIOR','PWD') DEFAULT 'REGULAR',
  `contact_number` varchar(20) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_persons_user_id` (`user_id`),
  CONSTRAINT `persons_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `problem_categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `problem_categories`;
CREATE TABLE `problem_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category_type` enum('health','economic','social','housing') NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `problems_needs`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `problems_needs`;
CREATE TABLE `problems_needs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `lack_income` tinyint(1) DEFAULT '0',
  `unemployment` tinyint(1) DEFAULT '0',
  `economic_others` tinyint(1) DEFAULT '0',
  `economic_others_specify` varchar(100) DEFAULT NULL,
  `loneliness` tinyint(1) DEFAULT '0',
  `isolation` tinyint(1) DEFAULT '0',
  `neglect` tinyint(1) DEFAULT '0',
  `lack_health_insurance` tinyint(1) DEFAULT '0',
  `inadequate_health_services` tinyint(1) DEFAULT '0',
  `lack_medical_facilities` tinyint(1) DEFAULT '0',
  `overcrowding` tinyint(1) DEFAULT '0',
  `no_permanent_housing` tinyint(1) DEFAULT '0',
  `independent_living` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `problems_needs_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `purok`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `purok`;
CREATE TABLE `purok` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barangay_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_barangay_purok` (`barangay_id`,`name`),
  CONSTRAINT `purok_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `relationship_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `relationship_types`;
CREATE TABLE `relationship_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `roles`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `schedule_notifications`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `schedule_notifications`;
CREATE TABLE `schedule_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `schedule_proposal_id` int NOT NULL,
  `notified_user_id` int NOT NULL,
  `notification_type` enum('proposal','confirmation','rejection') NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `schedule_proposal_id` (`schedule_proposal_id`),
  KEY `idx_schedule_notifications_user` (`notified_user_id`,`is_read`),
  CONSTRAINT `schedule_notifications_ibfk_1` FOREIGN KEY (`schedule_proposal_id`) REFERENCES `schedule_proposals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedule_notifications_ibfk_2` FOREIGN KEY (`notified_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `schedule_proposals`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `schedule_proposals`;
CREATE TABLE `schedule_proposals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int NOT NULL,
  `proposed_by_user_id` int NOT NULL,
  `proposed_by_role_id` int NOT NULL,
  `proposed_date` date NOT NULL,
  `proposed_time` time NOT NULL,
  `hearing_location` varchar(255) NOT NULL,
  `presiding_officer` varchar(100) NOT NULL,
  `presiding_officer_position` varchar(50) NOT NULL,
  `status` enum('proposed','user_confirmed','captain_confirmed','both_confirmed','conflict','pending_user_confirmation','pending_officer_confirmation','cancelled') NOT NULL DEFAULT 'proposed',
  `notification_sent` tinyint(1) DEFAULT '0',
  `notification_sent_at` datetime DEFAULT NULL,
  `user_confirmed` tinyint(1) DEFAULT '0',
  `user_confirmed_at` datetime DEFAULT NULL,
  `captain_confirmed` tinyint(1) DEFAULT '0',
  `captain_confirmed_at` datetime DEFAULT NULL,
  `confirmed_by_role` int DEFAULT NULL,
  `user_remarks` text,
  `captain_remarks` text,
  `conflict_reason` text,
  `complainant_confirmed` tinyint(1) DEFAULT '0',
  `respondent_confirmed` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `proposed_by_user_id` (`proposed_by_user_id`),
  KEY `confirmed_by_role` (`confirmed_by_role`),
  KEY `proposed_by_role_id` (`proposed_by_role_id`),
  KEY `idx_schedule_proposals_status` (`status`),
  CONSTRAINT `schedule_proposals_ibfk_1` FOREIGN KEY (`blotter_case_id`) REFERENCES `blotter_cases` (`id`),
  CONSTRAINT `schedule_proposals_ibfk_2` FOREIGN KEY (`proposed_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `schedule_proposals_ibfk_3` FOREIGN KEY (`confirmed_by_role`) REFERENCES `roles` (`id`),
  CONSTRAINT `schedule_proposals_ibfk_4` FOREIGN KEY (`proposed_by_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `service_categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `service_categories`;
CREATE TABLE `service_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barangay_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `icon` varchar(50) DEFAULT 'fa-cog',
  `display_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `service_categories_ibfk_1` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `service_request_attachments`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `service_request_attachments`;
CREATE TABLE `service_request_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `service_request_attachments_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `service_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `service_requests`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `service_requests`;
CREATE TABLE `service_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `user_id` int NOT NULL,
  `status` enum('pending','processing','completed','rejected','cancelled') DEFAULT 'pending',
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `service_requests_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `custom_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `service_requirements`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `service_requirements`;
CREATE TABLE `service_requirements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `is_required` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `service_requirements_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `custom_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `sessions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `sessions`;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sessions_user_id` (`user_id`),
  KEY `idx_sessions_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `skill_types`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `skill_types`;
CREATE TABLE `skill_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `skills`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `skills`;
CREATE TABLE `skills` (
  `id` int NOT NULL AUTO_INCREMENT,
  `person_id` int NOT NULL,
  `dental` tinyint(1) DEFAULT '0',
  `counseling` tinyint(1) DEFAULT '0',
  `evangelization` tinyint(1) DEFAULT '0',
  `farming` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  CONSTRAINT `skills_ibfk_1` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `temporary_records`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `temporary_records`;
CREATE TABLE `temporary_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `house_number` varchar(100) NOT NULL,
  `street` varchar(100) NOT NULL,
  `barangay_id` varchar(100) NOT NULL,
  `municipality` varchar(100) NOT NULL,
  `province` varchar(100) NOT NULL,
  `region` varchar(100) NOT NULL,
  `id_type` varchar(100) NOT NULL,
  `id_number` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `place_of_birth` varchar(255) NOT NULL,
  `months_residency` int NOT NULL,
  `days_residency` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `user_roles`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `role_id` int NOT NULL,
  `barangay_id` int NOT NULL,
  `start_term_date` date DEFAULT NULL,
  `end_term_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role_barangay` (`user_id`,`role_id`,`barangay_id`),
  KEY `role_id` (`role_id`),
  KEY `barangay_id` (`barangay_id`),
  CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_ibfk_3` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `role_id` int DEFAULT '8',
  `barangay_id` int DEFAULT '1',
  `id_expiration_date` date DEFAULT NULL,
  `id_type` varchar(50) DEFAULT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `verification_token` varchar(32) DEFAULT NULL,
  `verification_expiry` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `start_term_date` date DEFAULT NULL,
  `end_term_date` date DEFAULT NULL,
  `id_image_path` varchar(255) DEFAULT 'default.png',
  `signature_image_path` varchar(255) DEFAULT NULL,
  `esignature_path` varchar(255) DEFAULT NULL,
  `govt_id_image` longblob,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `chief_officer_esignature_path` longblob,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `phone` (`phone`),
  KEY `role_id` (`role_id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `idx_users_esignature` (`esignature_path`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `users_ibfk_2` FOREIGN KEY (`barangay_id`) REFERENCES `barangay` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;
-- End of backup
-- Total rows exported: 0
