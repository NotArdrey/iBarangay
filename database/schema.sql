-- Drop the database if it exists and create a new one
DROP DATABASE IF EXISTS barangay;
CREATE DATABASE barangay;
USE barangay;

/*-------------------------------------------------------------
  SECTION 1: BARANGAY AND REFERENCE TABLES (CORE LOOKUPS)
  -------------------------------------------------------------*/

-- Barangay lookup table
CREATE TABLE IF NOT EXISTS `barangay` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `municipality` varchar(255) NOT NULL,
  `province` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert barangays of San Rafael
INSERT INTO barangay (name, municipality, province) VALUES
    ('BMA-Balagtas', 'San Rafael', 'Bulacan'), ('Banca‐Banca', 'San Rafael', 'Bulacan'), ('Caingin', 'San Rafael', 'Bulacan'), ('Capihan', 'San Rafael', 'Bulacan'),
    ('Coral na Bato', 'San Rafael', 'Bulacan'), ('Cruz na Daan', 'San Rafael', 'Bulacan'), ('Dagat‐Dagatan', 'San Rafael', 'Bulacan'), ('Diliman I', 'San Rafael', 'Bulacan'),
    ('Diliman II', 'San Rafael', 'Bulacan'), ('Libis', 'San Rafael', 'Bulacan'), ('Lico', 'San Rafael', 'Bulacan'), ('Maasim', 'San Rafael', 'Bulacan'), ('Mabalas‐Balas', 'San Rafael', 'Bulacan'),
    ('Maguinao', 'San Rafael', 'Bulacan'), ('Maronquillo', 'San Rafael', 'Bulacan'), ('Paco', 'San Rafael', 'Bulacan'), ('Pansumaloc', 'San Rafael', 'Bulacan'), ('Pantubig', 'San Rafael', 'Bulacan'),
    ('Pasong Bangkal', 'San Rafael', 'Bulacan'), ('Pasong Callos', 'San Rafael', 'Bulacan'), ('Pasong Intsik', 'San Rafael', 'Bulacan'), ('Pinacpinacan', 'San Rafael', 'Bulacan'),
    ('Poblacion', 'San Rafael', 'Bulacan'), ('Pulo', 'San Rafael', 'Bulacan'), ('Pulong Bayabas', 'San Rafael', 'Bulacan'), ('Salapungan', 'San Rafael', 'Bulacan'),
    ('Sampaloc', 'San Rafael', 'Bulacan'), ('San Agustin', 'San Rafael', 'Bulacan'), ('San Roque', 'San Rafael', 'Bulacan'), ('Sapang Pahalang', 'San Rafael', 'Bulacan'),
    ('Talacsan', 'San Rafael', 'Bulacan'), ('Tambubong', 'San Rafael', 'Bulacan'), ('Tukod', 'San Rafael', 'Bulacan'), ('Ulingao', 'San Rafael', 'Bulacan');

-- Role definitions
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Programmer', 'System programmer'),
(2, 'Super Admin', 'System administrator'),
(3, 'Captain', 'Barangay Captain'),
(4, 'Secretary', 'Barangay Secretary'),
(5, 'Treasurer', 'Barangay Treasurer'),
(6, 'Councilor', 'Barangay Councilor'),
(7, 'Chief', 'Chief Officer'),
(8, 'Resident', 'Barangay Resident');

-- Document types
CREATE TABLE IF NOT EXISTS `document_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(50) UNIQUE NOT NULL,
    `description` TEXT,
    `default_fee` DECIMAL(10,2) DEFAULT 0.00,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO document_types (name, code, description, default_fee) VALUES
    ('Barangay Clearance', 'barangay_clearance', 'A clearance issued by the Barangay.', 50.00),
    ('First Time Job Seeker', 'first_time_job_seeker', 'Certification for first‐time job seekers.', 0.00),
    ('Proof of Residency', 'proof_of_residency', 'Official proof of residency certificate.', 30.00),
    ('Barangay Indigency', 'barangay_indigency', 'A document certifying indigency status.', 0.00),
    ('Cedula', 'cedula', 'Community Tax Certificate (Cedula)', 30.00),
    ('Business Permit Clearance', 'business_permit_clearance', 'Barangay clearance for business permit.', 100.00),
    ('Community Tax Certificate (Sedula)', 'community_tax_certificate', 'Community Tax Certificate (Sedula)', 30.00),
    ('Good Moral Certificate', 'good_moral_certificate', 'Certification of good moral character.', 25.00),
    ('No Income Certification', 'no_income_certification', 'Certification for individuals with no regular income.', 0.00);

-- Case categories for blotter management
CREATE TABLE IF NOT EXISTS `case_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `case_categories` (`name`, `description`) VALUES
('Physical Injury', 'Cases involving physical harm'),
('Verbal Dispute', 'Arguments and verbal conflicts'),
('Property Dispute', 'Conflicts over property ownership or boundaries'),
('Noise Complaint', 'Complaints about excessive noise'),
('Theft', 'Cases involving stealing'),
('Family Dispute', 'Family-related conflicts'),
('Neighbor Dispute', 'Conflicts between neighbors'),
('Other', 'Other types of cases');

-- Case intervention types
CREATE TABLE IF NOT EXISTS `case_interventions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `case_interventions` (`name`, `description`) VALUES
('M/CSWD', 'Municipal/City Social Welfare and Development'),
('PNP', 'Philippine National Police'),
('Court', 'Court intervention'),
('Issued BPO', 'Barangay Protection Order'),
('Medical', 'Medical assistance');

/*-------------------------------------------------------------
  SECTION 1.B: CENSUS LOOKUP TABLES
  -------------------------------------------------------------*/

-- Asset types
CREATE TABLE IF NOT EXISTS `asset_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO asset_types (name) VALUES
    ('House'),
    ('House & Lot'),
    ('Farmland'),
    ('Commercial Building'),
    ('Lot'),
    ('Fishpond/Resort'),
    ('Others');

-- Income source types
CREATE TABLE IF NOT EXISTS `income_source_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `requires_amount` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO income_source_types (name, requires_amount) VALUES
    ('Own Earnings/Salaries/Wages', FALSE),
    ('Own Pension', TRUE),
    ('Stocks/Dividends', FALSE),
    ('Dependent on Children/Relatives', FALSE),
    ('Spouse Salary', FALSE),
    ('Insurances', FALSE),
    ('Spouse Pension', TRUE),
    ('Rentals/Sharecrops', FALSE),
    ('Savings', FALSE),
    ('Livestock/Orchards', FALSE),
    ('Others', TRUE);

-- Living arrangement types
CREATE TABLE IF NOT EXISTS `living_arrangement_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO living_arrangement_types (name) VALUES
    ('Alone'),
    ('Spouse'),
    ('Care Institutions'),
    ('Children'),
    ('Grandchildren'),
    ('Common Law Spouse'),
    ('In laws'),
    ('Relatives'),
    ('Househelp'),
    ('Others');

-- Skill types
CREATE TABLE IF NOT EXISTS `skill_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO skill_types (name) VALUES
    ('Medical'),
    ('Teaching'),
    ('Legal Services'),
    ('Dental'),
    ('Counseling'),
    ('Evangelization'),
    ('Farming'),
    ('Fishing'),
    ('Cooking'),
    ('Vocational'),
    ('Arts'),
    ('Engineering'),
    ('Others');

-- Community involvement types
CREATE TABLE IF NOT EXISTS `involvement_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO involvement_types (name) VALUES
    ('Medical'),
    ('Resource Volunteer'),
    ('Community Beautification'),
    ('Community/Organizational Leader'),
    ('Dental'),
    ('Friendly Visits'),
    ('Neighborhood Support Services'),
    ('Religious'),
    ('Counselling/Referral'),
    ('Sponsorship'),
    ('Legal Services'),
    ('Others');

-- Problem categories
CREATE TABLE IF NOT EXISTS `problem_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `category_type` ENUM('health', 'economic', 'social', 'housing') NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO problem_categories (name, category_type) VALUES
    -- Health
    ('Lack/No Health Insurance/s', 'health'),
    ('Inadequate Health Services', 'health'),
    ('Lack of Hospitals/Medical Facilities', 'health'),
    ('High Cost of Medicines', 'health'),
    ('Lack of Medical Professionals', 'health'),
    ('Lack of Sanitation Access', 'health'),
    -- Housing
    ('Overcrowding in the Family Home', 'housing'),
    ('No Permanent Housing', 'housing'),
    ('Longing for Independent Living/Quiet Atmosphere', 'housing'),
    ('Lost Privacy', 'housing'),
    ('Living in Squatter Area', 'housing'),
    ('High Cost Rent', 'housing'),
    -- Economic
    ('Insufficient Income', 'economic'),
    ('Unemployment', 'economic'),
    ('High Cost of Living', 'economic'),
    ('Skills/Capability Training', 'economic'),
    ('Livelihood opportunities', 'economic'),
    -- Social/Emotional
    ('Lack of Support from Family', 'social'),
    ('Limited Social Interaction', 'social'),
    ('Difficulty in Accessing Services', 'social'),
    ('Feeling of neglect & rejection', 'social'),
    ('Feeling of helplessness & worthlessness', 'social'),
    ('Feeling of loneliness & isolation', 'social'),
    ('Inadequate leisure/recreational activities', 'social'),
    ('Senior Citizen Friendly Environment', 'social');

-- Other needs and concerns types
CREATE TABLE IF NOT EXISTS `other_need_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `category` ENUM('social', 'economic', 'environmental', 'others') NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO other_need_types (name, category) VALUES
    ('Financial Assistance', 'economic'),
    ('Job Placement', 'economic'),
    ('Skills Training', 'economic'),
    ('Social Integration', 'social'),
    ('Family Support', 'social'),
    ('Recreational Activities', 'social'),
    ('Environmental Safety', 'environmental'),
    ('Waste Management', 'environmental'),
    ('Disaster Preparedness', 'environmental'),
    ('Technology Access', 'others'),
    ('Information Access', 'others'),
    ('Cultural Activities', 'others');

-- Relationship types
CREATE TABLE IF NOT EXISTS `relationship_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO relationship_types (name) VALUES
    ('HEAD'),
    ('SPOUSE'),
    ('CHILD'),
    ('PARENT'),
    ('SIBLING'),
    ('GRANDCHILD'),
    ('OTHER RELATIVE'),
    ('NON-RELATIVE');

/*-------------------------------------------------------------
  SECTION 2: USER, PERSON, AND CORE PROFILE TABLES
  -------------------------------------------------------------*/

-- Main users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `email` varchar(255) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(20),
  `role_id` int(11) NOT NULL,
  `barangay_id` int(11) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `esignature_path` varchar(500),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `role_id` (`role_id`),
  KEY `barangay_id` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person information
CREATE TABLE IF NOT EXISTS `persons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100),
  `birth_date` date,
  `gender` enum('Male','Female','Other'),
  `contact_number` varchar(20),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User-Role assignment
CREATE TABLE IF NOT EXISTS `user_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `role_id` INT NOT NULL,
    `barangay_id` INT NOT NULL,
    `start_term_date` DATE NULL,
    `end_term_date` DATE NULL,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_role_barangay (user_id, role_id, barangay_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Official ID
CREATE TABLE IF NOT EXISTS `person_identification` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `osca_id` VARCHAR(50),
    `gsis_id` VARCHAR(50),
    `sss_id` VARCHAR(50),
    `tin_id` VARCHAR(50),
    `philhealth_id` VARCHAR(50),
    `other_id_type` VARCHAR(50),
    `other_id_number` VARCHAR(50),
    `id_image_path` VARCHAR(255),
    `selfie_image_path` VARCHAR(255),
    `signature_image_path` VARCHAR(255),
    `signature_date` DATE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Address information
CREATE TABLE IF NOT EXISTS `addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `person_id` int(11) NOT NULL,
  `house_no` varchar(50),
  `street` varchar(255),
  `barangay_id` int(11) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  KEY `barangay_id` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Emergency contact information
CREATE TABLE IF NOT EXISTS `emergency_contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `contact_name` VARCHAR(100) NOT NULL,
    `contact_number` VARCHAR(20) NOT NULL,
    `contact_address` VARCHAR(200),
    `relationship` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Purok table
CREATE TABLE IF NOT EXISTS `purok` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `barangay_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    UNIQUE KEY uk_barangay_purok (barangay_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Household information
CREATE TABLE IF NOT EXISTS `households` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `household_number` VARCHAR(50) NOT NULL,
    `barangay_id` INT NOT NULL,
    `purok_id` INT,
    `household_head_person_id` INT,
    `household_size` INT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (purok_id) REFERENCES purok(id) ON DELETE SET NULL,
    FOREIGN KEY (household_head_person_id) REFERENCES persons(id) ON DELETE SET NULL,
    UNIQUE KEY uk_household_number (household_number, barangay_id, purok_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person-Household relationship
CREATE TABLE IF NOT EXISTS `household_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `household_id` INT NOT NULL,
    `person_id` INT NOT NULL,
    `relationship_type_id` INT NOT NULL,
    `is_household_head` BOOLEAN DEFAULT FALSE,
    `relationship_to_head` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_household_person (household_id, person_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (relationship_type_id) REFERENCES relationship_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*-------------------------------------------------------------
  SECTION 2.B: NORMALIZED PERSON DETAIL TABLES (CENSUS)
  -------------------------------------------------------------*/

-- Person assets (Normalized version)
CREATE TABLE IF NOT EXISTS `person_assets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `asset_type_id` INT NOT NULL,
    `details` TEXT, -- For specifics if 'Others' or additional info
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_type_id) REFERENCES asset_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_asset (person_id, asset_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person income sources (Normalized version)
CREATE TABLE IF NOT EXISTS `person_income_sources` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `source_type_id` INT NOT NULL,
    `amount` DECIMAL(10,2), -- If requires_amount is true in income_source_types
    `details` TEXT, -- For specifics if 'Others' or additional info
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (source_type_id) REFERENCES income_source_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_income_source (person_id, source_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person living arrangements (Normalized version)
CREATE TABLE IF NOT EXISTS `person_living_arrangements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `arrangement_type_id` INT NOT NULL,
    `details` TEXT, -- For specifics if 'Others' or additional info
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (arrangement_type_id) REFERENCES living_arrangement_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_arrangement (person_id, arrangement_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person skills (Normalized version)
CREATE TABLE IF NOT EXISTS `person_skills` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `skill_type_id` INT NOT NULL,
    `details` TEXT, -- For specifics if 'Others' or additional info
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_type_id) REFERENCES skill_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_skill (person_id, skill_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person community involvements (Normalized version)
CREATE TABLE IF NOT EXISTS `person_involvements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `involvement_type_id` INT NOT NULL,
    `details` TEXT, -- For specifics if 'Others' or additional info
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (involvement_type_id) REFERENCES involvement_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_involvement (person_id, involvement_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person problems/concerns (linking to problem_categories)
CREATE TABLE IF NOT EXISTS `person_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `problem_category_id` INT NOT NULL,
    `details` TEXT, -- For specific details of the problem
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (problem_category_id) REFERENCES problem_categories(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_problem (person_id, problem_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Economic Problems
CREATE TABLE IF NOT EXISTS `person_economic_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `loss_income` BOOLEAN DEFAULT FALSE,
    `unemployment` BOOLEAN DEFAULT FALSE,
    `high_cost_living` BOOLEAN DEFAULT FALSE,
    `skills_training` BOOLEAN DEFAULT FALSE,
    `skills_training_details` TEXT,
    `livelihood` BOOLEAN DEFAULT FALSE,
    `livelihood_details` TEXT,
    `other_economic` BOOLEAN DEFAULT FALSE,
    `other_economic_details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Social Problems
CREATE TABLE IF NOT EXISTS `person_social_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `loneliness` BOOLEAN DEFAULT FALSE,
    `isolation` BOOLEAN DEFAULT FALSE,
    `neglect` BOOLEAN DEFAULT FALSE,
    `recreational` BOOLEAN DEFAULT FALSE,
    `senior_friendly` BOOLEAN DEFAULT FALSE,
    `other_social` BOOLEAN DEFAULT FALSE,
    `other_social_details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Health Problems
CREATE TABLE IF NOT EXISTS `person_health_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `condition_illness` BOOLEAN DEFAULT FALSE,
    `condition_illness_details` TEXT,
    `high_cost_medicine` BOOLEAN DEFAULT FALSE,
    `lack_medical_professionals` BOOLEAN DEFAULT FALSE,
    `lack_sanitation` BOOLEAN DEFAULT FALSE,
    `lack_health_insurance` BOOLEAN DEFAULT FALSE,
    `inadequate_health_services` BOOLEAN DEFAULT FALSE,
    `other_health` BOOLEAN DEFAULT FALSE,
    `other_health_details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Housing Problems
CREATE TABLE IF NOT EXISTS `person_housing_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `overcrowding` BOOLEAN DEFAULT FALSE,
    `no_permanent_housing` BOOLEAN DEFAULT FALSE,
    `independent_living` BOOLEAN DEFAULT FALSE,
    `lost_privacy` BOOLEAN DEFAULT FALSE,
    `squatters` BOOLEAN DEFAULT FALSE,
    `other_housing` BOOLEAN DEFAULT FALSE,
    `other_housing_details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Community Service Problems
CREATE TABLE IF NOT EXISTS `person_community_problems` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `desire_participate` BOOLEAN DEFAULT FALSE,
    `skills_to_share` BOOLEAN DEFAULT FALSE,
    `other_community` BOOLEAN DEFAULT FALSE,
    `other_community_details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person health information (replaces senior_health, more generic)
CREATE TABLE IF NOT EXISTS `person_health_info` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `health_condition` TEXT, -- General description
    `has_maintenance` BOOLEAN DEFAULT FALSE,
    `maintenance_details` TEXT,
    `high_cost_medicines` BOOLEAN DEFAULT FALSE,
    `lack_medical_professionals` BOOLEAN DEFAULT FALSE,
    `lack_sanitation_access` BOOLEAN DEFAULT FALSE,
    `lack_health_insurance` BOOLEAN DEFAULT FALSE,
    `lack_medical_facilities` BOOLEAN DEFAULT FALSE,
    `other_health_concerns` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Person other needs (linking to other_need_types)
CREATE TABLE IF NOT EXISTS `person_other_needs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `need_type_id` INT NOT NULL,
    `details` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (need_type_id) REFERENCES other_need_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_other_need (person_id, need_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Family composition table
CREATE TABLE IF NOT EXISTS `family_composition` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `household_id` INT NOT NULL,
    `person_id` INT NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `relationship` VARCHAR(50) NOT NULL,
    `age` INT NOT NULL,
    `civil_status` ENUM('SINGLE', 'MARRIED', 'WIDOW/WIDOWER', 'SEPARATED') NOT NULL,
    `occupation` VARCHAR(100),
    `monthly_income` DECIMAL(10,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Legacy tables for backward compatibility (kept from second file structure)
-- Income sources details
CREATE TABLE IF NOT EXISTS `income_sources` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `own_earnings` BOOLEAN DEFAULT FALSE,
    `own_pension` BOOLEAN DEFAULT FALSE,
    `own_pension_amount` DECIMAL(10,2),
    `stocks_dividends` BOOLEAN DEFAULT FALSE,
    `dependent_on_children` BOOLEAN DEFAULT FALSE,
    `spouse_salary` BOOLEAN DEFAULT FALSE,
    `insurances` BOOLEAN DEFAULT FALSE,
    `spouse_pension` BOOLEAN DEFAULT FALSE,
    `spouse_pension_amount` DECIMAL(10,2),
    `rentals_sharecrops` BOOLEAN DEFAULT FALSE,
    `savings` BOOLEAN DEFAULT FALSE,
    `livestock_orchards` BOOLEAN DEFAULT FALSE,
    `others` BOOLEAN DEFAULT FALSE,
    `others_specify` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Assets and properties
CREATE TABLE IF NOT EXISTS `assets_properties` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `house` BOOLEAN DEFAULT FALSE,
    `house_lot` BOOLEAN DEFAULT FALSE,
    `farmland` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Living arrangements
CREATE TABLE IF NOT EXISTS `living_arrangements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `spouse` BOOLEAN DEFAULT FALSE,
    `care_institutions` BOOLEAN DEFAULT FALSE,
    `children` BOOLEAN DEFAULT FALSE,
    `grandchildren` BOOLEAN DEFAULT FALSE,
    `househelps` BOOLEAN DEFAULT FALSE,
    `relatives` BOOLEAN DEFAULT FALSE,
    `others` BOOLEAN DEFAULT FALSE,
    `others_specify` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Skills
CREATE TABLE IF NOT EXISTS `skills` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `dental` BOOLEAN DEFAULT FALSE,
    `counseling` BOOLEAN DEFAULT FALSE,
    `evangelization` BOOLEAN DEFAULT FALSE,
    `farming` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Problems and needs
CREATE TABLE IF NOT EXISTS `problems_needs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `lack_income` BOOLEAN DEFAULT FALSE,
    `unemployment` BOOLEAN DEFAULT FALSE,
    `economic_others` BOOLEAN DEFAULT FALSE,
    `economic_others_specify` VARCHAR(100),
    `loneliness` BOOLEAN DEFAULT FALSE,
    `isolation` BOOLEAN DEFAULT FALSE,
    `neglect` BOOLEAN DEFAULT FALSE,
    `lack_health_insurance` BOOLEAN DEFAULT FALSE,
    `inadequate_health_services` BOOLEAN DEFAULT FALSE,
    `lack_medical_facilities` BOOLEAN DEFAULT FALSE,
    `overcrowding` BOOLEAN DEFAULT FALSE,
    `no_permanent_housing` BOOLEAN DEFAULT FALSE,
    `independent_living` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*-------------------------------------------------------------
  SECTION 2.C: CHILD-SPECIFIC INFORMATION
  -------------------------------------------------------------*/

-- Child-specific information
CREATE TABLE IF NOT EXISTS `child_information` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `is_malnourished` BOOLEAN DEFAULT FALSE,
    `attending_school` BOOLEAN DEFAULT FALSE,
    `school_name` VARCHAR(255),
    `grade_level` VARCHAR(50),
    `school_type` ENUM('Public', 'Private', 'ALS', 'Day Care', 'SNP', 'Not Attending') DEFAULT 'Not Attending',
    `immunization_complete` BOOLEAN DEFAULT FALSE,
    `is_pantawid_beneficiary` BOOLEAN DEFAULT FALSE, -- Note: also in persons table, check usage
    `has_timbang_operation` BOOLEAN DEFAULT FALSE,
    `has_feeding_program` BOOLEAN DEFAULT FALSE,
    `has_supplementary_feeding` BOOLEAN DEFAULT FALSE,
    `in_caring_institution` BOOLEAN DEFAULT FALSE,
    `is_under_foster_care` BOOLEAN DEFAULT FALSE,
    `is_directly_entrusted` BOOLEAN DEFAULT FALSE,
    `is_legally_adopted` BOOLEAN DEFAULT FALSE,
    `occupation` VARCHAR(255),
    `garantisadong_pambata` BOOLEAN DEFAULT FALSE,
    `under_six_years` BOOLEAN DEFAULT FALSE,
    `grade_school` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Child health conditions (specific ENUM based, kept separate from generic health concerns)
CREATE TABLE IF NOT EXISTS `child_health_conditions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `condition_type` ENUM('Malaria', 'Dengue', 'Pneumonia', 'Tuberculosis', 'Diarrhea') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
    -- Consider adding UNIQUE KEY (person_id, condition_type) if a child can't have the same condition listed twice.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Child disabilities (specific ENUM based)
CREATE TABLE IF NOT EXISTS `child_disabilities` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `disability_type` ENUM('Blind/Visually Impaired', 'Hearing Impairment', 'Speech/Communication', 'Orthopedic/Physical', 'Intellectual/Learning', 'Psychosocial') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
    -- Consider adding UNIQUE KEY (person_id, disability_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*-------------------------------------------------------------
  SECTION 2.D: RESIDENT DETAILS AND GOVERNMENT PROGRAMS
  -------------------------------------------------------------*/

-- Government programs participation
CREATE TABLE IF NOT EXISTS `government_programs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL UNIQUE,
    `nhts_pr_listahanan` BOOLEAN DEFAULT FALSE,
    `indigenous_people` BOOLEAN DEFAULT FALSE,
    `pantawid_beneficiary` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Temporary records table
CREATE TABLE IF NOT EXISTS `temporary_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `last_name` VARCHAR(100) NOT NULL,
    `suffix` VARCHAR(10),
    `first_name` VARCHAR(100) NOT NULL,
    `house_number` VARCHAR(100) NOT NULL,
    `street` VARCHAR(100) NOT NULL,
    `barangay_id` VARCHAR(100) NOT NULL,
    `municipality` VARCHAR(100) NOT NULL,
    `province` VARCHAR(100) NOT NULL,
    `region` VARCHAR(100) NOT NULL,
    `id_type` VARCHAR(100) NOT NULL,
    `id_number` VARCHAR(100) NOT NULL,
    `middle_name` VARCHAR(100),
    `date_of_birth` DATE NOT NULL,
    `place_of_birth` VARCHAR(255) NOT NULL,
    `months_residency` INT NOT NULL,
    `days_residency` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*-------------------------------------------------------------
  SECTION 3: BARANGAY OPERATIONS & DOCUMENT REQUEST SYSTEM
  -------------------------------------------------------------*/

-- Barangay settings and operation information
CREATE TABLE IF NOT EXISTS `barangay_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `barangay_id` INT NOT NULL UNIQUE,
    `cutoff_time` TIME NOT NULL DEFAULT '15:00:00',
    `opening_time` TIME NOT NULL DEFAULT '08:00:00',
    `closing_time` TIME NOT NULL DEFAULT '17:00:00',
    `barangay_captain_name` VARCHAR(100),
    `contact_number` VARCHAR(15),
    `email` VARCHAR(100),
    `local_barangay_contact` VARCHAR(20),
    `pnp_contact` VARCHAR(20),
    `bfp_contact` VARCHAR(20),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Document attribute types
CREATE TABLE IF NOT EXISTS `document_attribute_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_type_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(40) NOT NULL,
    `description` VARCHAR(200),
    `is_required` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doc_attr_code (document_type_id, code),
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Document requests
CREATE TABLE IF NOT EXISTS `document_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `person_id` INT NOT NULL,
    `document_type_id` INT NOT NULL,
    `barangay_id` INT NOT NULL,
    `status` ENUM('pending', 'processing', 'for_payment', 'paid', 'for_pickup', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    `price` DECIMAL(10,2) DEFAULT 0.00,
    `remarks` TEXT,
    `proof_image_path` VARCHAR(255) NULL,
    `requested_by_user_id` INT,
    `processed_by_user_id` INT,
    `completed_at` DATETIME,
    `request_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Document request attributes
CREATE TABLE IF NOT EXISTS `document_request_attributes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `attribute_type_id` INT NOT NULL,
    `value` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_request_attribute (request_id, attribute_type_id),
    FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (attribute_type_id) REFERENCES document_attribute_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Barangay document prices
CREATE TABLE IF NOT EXISTS `barangay_document_prices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `barangay_id` INT NOT NULL,
    `document_type_id` INT NOT NULL,
    `price` DECIMAL(10,2) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_barangay_document (barangay_id, document_type_id),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service categories
CREATE TABLE IF NOT EXISTS `service_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `barangay_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `icon` VARCHAR(50) DEFAULT 'fa-cog',
    `display_order` INT DEFAULT 0,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom services
CREATE TABLE IF NOT EXISTS `custom_services` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT NOT NULL,
    `barangay_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `detailed_guide` TEXT,
    `requirements` TEXT,
    `processing_time` VARCHAR(100),
    `fees` VARCHAR(100),
    `icon` VARCHAR(50) DEFAULT 'fa-file',
    `url_path` VARCHAR(255),
    `display_order` INT DEFAULT 0,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service requirements
CREATE TABLE IF NOT EXISTS `service_requirements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `is_required` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES custom_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service requests
CREATE TABLE IF NOT EXISTS `service_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'rejected', 'cancelled') DEFAULT 'pending',
    `remarks` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES custom_services(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service request attachments
CREATE TABLE IF NOT EXISTS `service_request_attachments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_type` VARCHAR(50),
    `file_size` INT,
    `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*-------------------------------------------------------------
  SECTION 4: BLOTTER/CASE MANAGEMENT SYSTEM
  -------------------------------------------------------------*/

-- External participants (for people not in the persons table)
CREATE TABLE IF NOT EXISTS `external_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(20),
  `address` varchar(500),
  `age` int(3),
  `gender` enum('Male','Female','Other'),
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Main blotter case information
CREATE TABLE IF NOT EXISTS `blotter_cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_number` varchar(50) NOT NULL UNIQUE,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` enum('pending','open','closed','completed','solved','endorsed_to_court','cfa_eligible','dismissed') DEFAULT 'pending',
  `barangay_id` int(11) NOT NULL,
  `incident_date` datetime,
  `filing_date` datetime,
  `scheduling_deadline` datetime GENERATED ALWAYS AS (DATE_ADD(filing_date, INTERVAL 5 DAY)) STORED,
  `accepted_by_user_id` int(11) DEFAULT NULL,
  `accepted_by_role_id` int(11) DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `cfa_issued_at` datetime DEFAULT NULL,
  `endorsed_to_court_at` datetime DEFAULT NULL,
  `captain_signature_date` datetime DEFAULT NULL,
  `chief_signature_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `accepted_by_user_id` (`accepted_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `blotter_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `person_id` int(11) DEFAULT NULL,
  `external_participant_id` int(11) DEFAULT NULL,
  `role` enum('complainant','respondent','witness') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `person_id` (`person_id`),
  KEY `external_participant_id` (`external_participant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `blotter_case_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `blotter_case_interventions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `intervention_id` int(11) NOT NULL,
  `intervened_at` datetime NOT NULL,
  `remarks` text,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `intervention_id` (`intervention_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Schedule management tables
CREATE TABLE IF NOT EXISTS `schedule_proposals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `proposed_by_user_id` int(11) NOT NULL,
  `proposed_date` date NOT NULL,
  `proposed_time` time NOT NULL,
  `hearing_location` varchar(255) DEFAULT 'Barangay Hall',
  `presiding_officer` varchar(255) NOT NULL,
  `presiding_officer_position` enum('barangay_captain','chief_officer') NOT NULL,
  `status` enum('proposed','user_confirmed','captain_confirmed','rejected','pending_user_confirmation') DEFAULT 'proposed',
  `captain_confirmed` tinyint(1) DEFAULT 0,
  `captain_confirmed_at` datetime DEFAULT NULL,
  `captain_remarks` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `proposed_by_user_id` (`proposed_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `participant_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `participant_id` int(11) NOT NULL,
  `participant_type` enum('registered','external') NOT NULL,
  `email` varchar(255),
  `contact_number` varchar(20),
  `notification_sent_at` datetime DEFAULT NULL,
  `confirmed` tinyint(1) DEFAULT 0,
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hearing management tables
CREATE TABLE IF NOT EXISTS `case_hearings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `hearing_number` int(2) NOT NULL DEFAULT 1,
  `hearing_date` datetime NOT NULL,
  `presiding_officer_name` varchar(255) NOT NULL,
  `presiding_officer_position` enum('barangay_captain','chief_officer') NOT NULL,
  `hearing_outcome` enum('scheduled','completed','mediation_successful','mediation_failed','no_show','postponed') DEFAULT 'scheduled',
  `resolution_details` text,
  `hearing_notes` text,
  `is_mediation_successful` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `hearing_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hearing_id` int(11) NOT NULL,
  `participant_id` int(11) NOT NULL,
  `attended` tinyint(1) DEFAULT 0,
  `attendance_remarks` text,
  PRIMARY KEY (`id`),
  KEY `hearing_id` (`hearing_id`),
  KEY `participant_id` (`participant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CFA Certificate management
CREATE TABLE IF NOT EXISTS `cfa_certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `complainant_person_id` int(11) NOT NULL,
  `issued_by_user_id` int(11) NOT NULL,
  `certificate_number` varchar(50) NOT NULL UNIQUE,
  `issued_at` datetime NOT NULL,
  `reason` text,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `complainant_person_id` (`complainant_person_id`),
  KEY `issued_by_user_id` (`issued_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification system
CREATE TABLE IF NOT EXISTS `case_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blotter_case_id` int(11) NOT NULL,
  `notified_user_id` int(11) NOT NULL,
  `notification_type` enum('case_filed','case_accepted','schedule_proposed','hearing_scheduled','case_closed') NOT NULL,
  `message` text,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `blotter_case_id` (`blotter_case_id`),
  KEY `notified_user_id` (`notified_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail
CREATE TABLE IF NOT EXISTS `audit_trails` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(11) NOT NULL,
  `description` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monthly reports
CREATE TABLE IF NOT EXISTS `monthly_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay_id` int(11) NOT NULL,
  `report_year` int(4) NOT NULL,
  `report_month` int(2) NOT NULL,
  `prepared_by_user_id` int(11) NOT NULL,
  `submitted_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `prepared_by_user_id` (`prepared_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Hearing schedules
CREATE TABLE IF NOT EXISTS `hearing_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hearing_date` date NOT NULL,
  `hearing_time` time NOT NULL,
  `max_hearings_per_slot` int(2) DEFAULT 5,
  `current_bookings` int(2) DEFAULT 0,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*-------------------------------------------------------------
  SECTION 7: SAMPLE DATA INSERTION
  -------------------------------------------------------------*/

-- Insert sample users
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active) VALUES
    ('programmer@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 1, 1, 'System', 'Programmer', 'Male', NOW(), TRUE),
    ('superadmin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 2, 1, 'Super', 'Administrator', 'Female', NOW(), TRUE),
    ('barangayadmin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 4, 32, 'Barangay', 'Administrator', 'Male', NOW(), TRUE),
    ('captain.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 3, 32, 'Juan', 'Dela Cruz', 'Male', NOW(), TRUE),
    ('resident1@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Test', 'Resident', 'Male', NOW(), TRUE),
    ('neilardrey14@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Neil', 'Ardrey', 'Male', NOW(), TRUE),
    ('captain.pantubig@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 3, 18, 'Maria', 'Santos', 'Female', NOW(), TRUE),
    ('captain.caingin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 3, 3, 'Roberto', 'Reyes', 'Male', NOW(), TRUE),
    ('chiefOfficer.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 7, 32, 'Ricardo', 'Morales', 'Male', NOW(), TRUE);

-- Insert sample persons
INSERT INTO persons (user_id, first_name, last_name, birth_date, birth_place, gender, civil_status) VALUES
    (1, 'System', 'Programmer', '1990-01-01', 'Manila', 'MALE', 'SINGLE'),
    (2, 'Super', 'Administrator', '1985-01-01', 'Quezon City', 'FEMALE', 'MARRIED'),
    (3, 'Barangay', 'Administrator', '1980-01-01', 'San Rafael', 'MALE', 'MARRIED'),
    (4, 'Juan', 'Dela Cruz', '1970-01-01', 'San Rafael', 'MALE', 'MARRIED'),
    (5, 'Test', 'Resident', '1990-01-01', 'Manila', 'MALE', 'SINGLE'),
    (6, 'Neil', 'Ardrey', '1992-05-15', 'San Rafael', 'MALE', 'SINGLE'),
    (7, 'Maria', 'Santos', '1975-03-15', 'Bulacan', 'FEMALE', 'MARRIED'),
    (8, 'Roberto', 'Reyes', '1970-08-22', 'Bulacan', 'MALE', 'MARRIED'),
    (9, 'Ricardo', 'Morales', '1968-11-10', 'Bulacan', 'MALE', 'MARRIED');

-- Insert additional residents without user accounts
INSERT INTO persons (first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, occupation, contact_number) VALUES
    ('Luis', 'Manalo', 'Santos', '1995-02-14', 'San Rafael', 'MALE', 'SINGLE', 'Engineer', '09112233445'),
    ('Sofia', 'Alcantara', 'Reyes', '1988-11-30', 'San Rafael', 'FEMALE', 'MARRIED', 'Business Owner', '09223344556'),
    ('Miguel', 'Tolentino', 'Cruz', '1972-07-22', 'San Rafael', 'MALE', 'WIDOW/WIDOWER', 'Fisherman', '09334455667'),
    ('Carlos', 'Santos', 'Dela Cruz', '1980-05-15', 'San Rafael', 'MALE', 'MARRIED', 'Farmer', '09123456789'),
    ('Elena', 'Garcia', 'Santos', '1985-08-20', 'San Rafael', 'FEMALE', 'MARRIED', 'Teacher', '09987654321'),
    ('Pedro', 'Ramos', 'Gonzales', '1975-12-10', 'San Rafael', 'MALE', 'SINGLE', 'Driver', '09111222333'),
    ('Ana', 'Flores', 'Reyes', '1990-03-25', 'San Rafael', 'FEMALE', 'SINGLE', 'Nurse', '09444555666');

-- Insert User Roles
INSERT INTO user_roles (user_id, role_id, barangay_id, is_active, start_term_date, end_term_date) VALUES
    (1, 1, 1, TRUE, NULL, NULL),
    (2, 2, 1, TRUE, NULL, NULL),
    (3, 4, 32, TRUE, NULL, NULL),
    (4, 3, 32, TRUE, '2023-01-01', '2025-12-31'),
    (5, 8, 32, TRUE, NULL, NULL),
    (6, 8, 32, TRUE, NULL, NULL),
    (7, 3, 18, TRUE, '2023-01-01', '2025-12-31'),
    (8, 3, 3, TRUE, '2023-01-01', '2025-12-31'),
    (9, 3, 1, TRUE, '2023-01-01', '2025-12-31');

-- Insert Addresses
INSERT INTO addresses (person_id, barangay_id, house_no, street, residency_type, years_in_san_rafael, is_primary) VALUES
    (10, 32, '101', 'Mabini Extension', 'Home Owner', 8, TRUE),
    (11, 32, '202', 'Rizal Street', 'Home Owner', 12, TRUE),
    (12, 32, '303', 'Rivera Compound', 'Home Owner', 25, TRUE),
    (13, 18, '456', 'Rizal Avenue', 'Renter', 8, TRUE),
    (14, 3, '789', 'Luna Street', 'Home Owner', 20, TRUE),
    (15, 32, '321', 'Bonifacio Road', 'Boarder', 3, TRUE),
    (16, 18, '654', 'Aguinaldo Street', 'Home Owner', 12, TRUE);

-- Insert Barangay Settings
INSERT INTO barangay_settings (barangay_id, barangay_captain_name, local_barangay_contact, pnp_contact, bfp_contact) VALUES
    (32, 'Juan Dela Cruz', '0917-555-1234', '0917-555-5678', '0917-555-9012'),
    (18, 'Maria Santos', '0917-555-4321', '0917-555-8765', '0917-555-2109'),
    (3, 'Roberto Reyes', '0917-555-1122', '0917-555-3344', '0917-555-5566'),
    (1, 'Ricardo Morales', '0917-555-7890', '0917-555-1357', '0917-555-2468');

-- Insert Document Attribute Types
INSERT INTO document_attribute_types (document_type_id, name, code, description, is_required) VALUES
    (1, 'Purpose for Clearance', 'clearance_purpose', 'Purpose for barangay clearance', TRUE),
    (3, 'Duration of Residency', 'residency_duration', 'How long the requester has resided', TRUE),
    (3, 'Purpose for Certificate', 'residency_purpose', 'Purpose for certificate of residency', TRUE),
    (4, 'Purpose for Certificate', 'indigency_purpose', 'Purpose for indigency certificate', TRUE),
    (4, 'Stated Income', 'indigency_income', 'Stated income for indigency cert.', TRUE),
    (4, 'Reason for Request', 'indigency_reason', 'Reason for requesting indigency cert.', TRUE),
    (5, 'Tax Amount', 'cedula_amount', 'Tax amount for cedula', TRUE);

-- Insert External Participants
INSERT INTO external_participants (first_name, last_name, contact_number, address, age, gender) VALUES
    ('Carlos', 'Rivera', '09888777666', 'Unknown Street, Tambubong', 35, 'Male'),
    ('Elena', 'Cruz', '09555444333', 'Somewhere in Pantubig', 28, 'Female');

-- Insert Blotter Cases
INSERT INTO blotter_cases (case_number, incident_date, location, description, status, barangay_id, reported_by_person_id) VALUES
    ('TAM-2024-0001', '2024-01-15 14:30:00', 'Mabini Street, Tambubong', 'Noise complaint against neighbor', 'open', 32, 10),
    ('PAN-2024-0001', '2024-01-20 09:15:00', 'Rizal Avenue, Pantubig', 'Property boundary dispute', 'pending', 18, 11),
    ('CAI-2024-0001', '2024-01-25 16:45:00', 'Luna Street, Caingin', 'Family dispute mediation', 'closed', 3, 12),
    ('TAM-2024-0002', '2024-02-10 20:00:00', 'Rizal Street, Tambubong', 'Property damage complaint', 'open', 32, 11);

-- Insert Blotter Participants
INSERT INTO blotter_participants (blotter_case_id, person_id, role, statement) VALUES
    (1, 10, 'complainant', 'Neighbor is playing loud music past 10 PM'),
    (2, 11, 'complainant', 'Neighbor built fence on my property'),
    (3, 12, 'complainant', 'Need help resolving family issues'),
    (4, 11, 'complainant', 'Neighbor destroyed my fence during argument'),
    (4, 12, 'respondent', 'Accident occurred while trimming trees');

INSERT INTO blotter_participants (blotter_case_id, external_participant_id, role, statement) VALUES
    (1, 1, 'respondent', 'We were just celebrating a birthday');

-- Insert Blotter Case Categories
INSERT INTO blotter_case_categories (blotter_case_id, category_id) VALUES
    (1, 8), (2, 8), (3, 8), (4, 8);

-- Insert Document Requests
INSERT INTO document_requests (person_id, document_type_id, barangay_id, requested_by_user_id, status) VALUES
    (10, 1, 32, 3, 'pending'),
    (11, 3, 32, 3, 'processing'),
    (12, 4, 32, 3, 'for_payment'),
    (13, 1, 32, 3, 'pending'),
    (14, 3, 32, 3, 'processing'),
    (15, 5, 32, 3, 'for_payment');

-- Insert Events
INSERT INTO events (title, description, start_datetime, end_datetime, location, barangay_id, created_by_user_id) VALUES
    ('Tambubong Cleanup Day', 'Monthly community cleanup', '2024-03-05 07:00:00', '2024-03-05 11:00:00', 'Tambubong Covered Court', 32, 3),
    ('Barangay Assembly', 'Monthly barangay assembly meeting', '2024-02-15 19:00:00', '2024-02-15 21:00:00', 'Barangay Hall', 32, 3),
    ('Health Fair', 'Free medical checkup and consultation', '2024-02-20 08:00:00', '2024-02-20 17:00:00', 'Covered Court', 18, 3),
    ('Clean-up Drive', 'Community clean-up activity', '2024-02-25 06:00:00', '2024-02-25 10:00:00', 'Various Streets', 3, 3);

-- Insert Event Participants
INSERT INTO event_participants (event_id, person_id) VALUES
    (1, 5), (1, 10), (1, 11), (1, 12),
    (2, 5), (2, 10), (2, 11);

-- Insert Audit Trails
INSERT INTO audit_trails (user_id, action, table_name, record_id, description) VALUES
    (3, 'LOGIN', 'users', '3', 'User logged into the system'),
    (3, 'VIEW', 'document_requests', '1', 'Viewed document request details'),
    (3, 'EXPORT', 'audit_trails', 'ALL', 'Exported audit trail report'),
    (3, 'FILTER', 'audit_trails', 'ALL', 'Applied filters to audit trail view');


-- Insert CFA Certificate
INSERT INTO cfa_certificates (blotter_case_id, complainant_person_id, issued_by_user_id, certificate_number, issued_at, reason) VALUES
    (1, 10, 4, 'TAM-CFA-2024-001', NOW(), 'Successful mediation between parties');

-- Update scheduling status for existing blotter cases
UPDATE blotter_cases 
SET scheduling_status = 'scheduled'
WHERE id > 0 AND scheduled_hearing IS NOT NULL AND scheduled_hearing > NOW();

UPDATE blotter_cases 
SET scheduling_status = 'completed'
WHERE id > 0 AND scheduled_hearing IS NOT NULL AND scheduled_hearing <= NOW();

UPDATE blotter_cases 
SET scheduling_status = 'pending_schedule'
WHERE id > 0 AND status IN ('open', 'pending') AND (scheduled_hearing IS NULL OR scheduling_status = 'none');

/*-------------------------------------------------------------
  SECTION 8: INDEXES FOR PERFORMANCE
  -------------------------------------------------------------*/

-- Add composite indexes for better performance
CREATE INDEX idx_doc_requests_status_barangay ON document_requests(status, barangay_id, request_date);
CREATE INDEX idx_doc_requests_person ON document_requests(person_id);
CREATE INDEX idx_doc_requests_doctype ON document_requests(document_type_id);
CREATE INDEX idx_blotter_cases_status ON blotter_cases(status, barangay_id);
CREATE INDEX idx_blotter_cases_scheduling ON blotter_cases(scheduling_status);
CREATE INDEX idx_persons_user_id ON persons(user_id);
CREATE INDEX idx_persons_census_id ON persons(census_id);
CREATE INDEX idx_addresses_person_id ON addresses(person_id);
CREATE INDEX idx_addresses_barangay_id ON addresses(barangay_id);
CREATE INDEX idx_household_members_household ON household_members(household_id);
CREATE INDEX idx_household_members_person ON household_members(person_id);
CREATE INDEX idx_events_barangay_date ON events(barangay_id, start_datetime);
CREATE INDEX idx_audit_trails_user_action ON audit_trails(user_id, action, action_timestamp);
CREATE INDEX idx_notifications_user_read ON notifications(user_id, is_read);

/*-------------------------------------------------------------
  SECTION 9: ADDITIONAL VIEWS FOR COMMON QUERIES
  -------------------------------------------------------------*/

-- View for person summary with address
CREATE VIEW person_summary AS
SELECT 
    p.id,
    p.first_name,
    p.middle_name,
    p.last_name,
    p.suffix,
    p.birth_date,
    p.gender,
    p.civil_status,
    p.contact_number,
    a.house_no,
    a.street,
    a.subdivision,
    b.name as barangay_name,
    a.residency_type,
    p.occupation,
    p.monthly_income
FROM persons p
LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
LEFT JOIN barangay b ON a.barangay_id = b.id;

-- View for active document requests with person and document info
CREATE VIEW active_document_requests AS
SELECT 
    dr.id,
    dr.request_date,
    dr.status,
    dr.price,
    p.first_name,
    p.last_name,
    dt.name as document_type,
    b.name as barangay_name,
    dr.remarks
FROM document_requests dr
JOIN persons p ON dr.person_id = p.id
JOIN document_types dt ON dr.document_type_id = dt.id
JOIN barangay b ON dr.barangay_id = b.id
WHERE dr.status NOT IN ('completed', 'cancelled');

-- View for household information
CREATE VIEW household_info AS
SELECT 
    h.id as household_id,
    h.household_number,
    b.name as barangay_name,
    p.first_name as head_first_name,
    p.last_name as head_last_name,
    h.household_size,
    COUNT(hm.person_id) as actual_members
FROM households h
LEFT JOIN barangay b ON h.barangay_id = b.id
LEFT JOIN persons p ON h.household_head_person_id = p.id
LEFT JOIN household_members hm ON h.id = hm.household_id
GROUP BY h.id, h.household_number, b.name, p.first_name, p.last_name, h.household_size;

-- View for blotter case summary
CREATE VIEW blotter_case_summary AS
SELECT 
    bc.id,
    bc.case_number,
    bc.incident_date,
    bc.status,
    bc.scheduling_status,
    b.name as barangay_name,
    GROUP_CONCAT(cc.name SEPARATOR ', ') as categories,
    COUNT(DISTINCT bp.id) as participant_count
FROM blotter_cases bc
LEFT JOIN barangay b ON bc.barangay_id = b.id
LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
LEFT JOIN case_categories cc ON bcc.category_id = cc.id
LEFT JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
GROUP BY bc.id, bc.case_number, bc.incident_date, bc.status, bc.scheduling_status, b.name;


-- Add missing participant_notifications table
CREATE TABLE IF NOT EXISTS `participant_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `blotter_case_id` INT NOT NULL,
    `participant_id` INT NOT NULL,
    `email_address` VARCHAR(255),
    `phone_number` VARCHAR(20),
    `notification_type` ENUM('summons', 'hearing_notice', 'reminder') DEFAULT 'summons',
    `sent_at` DATETIME,
    `confirmed` BOOLEAN DEFAULT FALSE,
    `confirmed_at` DATETIME NULL,
    `confirmation_token` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES blotter_participants(id) ON DELETE CASCADE,
    UNIQUE KEY uk_case_participant_type (blotter_case_id, participant_id, notification_type),
    INDEX idx_confirmation_token (confirmation_token),
    INDEX idx_sent_confirmed (sent_at, confirmed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample data for existing blotter cases to prevent errors
-- This assumes you have blotter cases with IDs 1-4 and corresponding participants
INSERT INTO participant_notifications (blotter_case_id, participant_id, email_address, notification_type, confirmed)
SELECT 
    bp.blotter_case_id,
    bp.id as participant_id,
    CASE 
        WHEN p.user_id IS NOT NULL THEN u.email
        ELSE CONCAT('external_', ep.id, '@placeholder.com')
    END as email_address,
    'summons' as notification_type,
    CASE 
        WHEN RAND() > 0.3 THEN TRUE 
        ELSE FALSE 
    END as confirmed
FROM blotter_participants bp
LEFT JOIN persons p ON bp.person_id = p.id
LEFT JOIN users u ON p.user_id = u.id
LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
WHERE bp.blotter_case_id IN (
    SELECT id FROM blotter_cases 
    WHERE status IN ('pending', 'open') 
    AND scheduling_status IN ('none', 'pending_schedule', 'schedule_proposed')
)
ON DUPLICATE KEY UPDATE confirmed = VALUES(confirmed);


-- Add missing columns to custom_services table
ALTER TABLE custom_services 
ADD COLUMN service_photo VARCHAR(255) AFTER additional_notes;

-- Add new status for blotter cases
ALTER TABLE blotter_cases 
MODIFY COLUMN status ENUM('pending', 'open', 'closed', 'completed', 'transferred', 'solved', 'endorsed_to_court', 'cfa_eligible', 'dismissed', 'deleted') DEFAULT 'pending';

-- Add new columns for case dismissal
ALTER TABLE blotter_cases
ADD COLUMN dismissed_by_user_id INT NULL AFTER resolved_at,
ADD COLUMN dismissal_reason TEXT NULL AFTER dismissed_by_user_id,
ADD COLUMN dismissal_date DATETIME NULL AFTER dismissal_reason,
ADD FOREIGN KEY (dismissed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add new columns for schedule proposals
ALTER TABLE schedule_proposals
ADD COLUMN proposed_by_role_id INT NOT NULL AFTER proposed_by_user_id,
ADD COLUMN notification_sent BOOLEAN DEFAULT FALSE AFTER status,
ADD COLUMN notification_sent_at DATETIME NULL AFTER notification_sent,
ADD FOREIGN KEY (proposed_by_role_id) REFERENCES roles(id) ON DELETE CASCADE;

-- Add new table for schedule notifications
CREATE TABLE IF NOT EXISTS `schedule_notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `schedule_proposal_id` INT NOT NULL,
    `notified_user_id` INT NOT NULL,
    `notification_type` ENUM('proposal', 'confirmation', 'rejection') NOT NULL,
    `is_read` BOOLEAN DEFAULT FALSE,
    `read_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_proposal_id) REFERENCES schedule_proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (notified_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add indexes for better performance
CREATE INDEX idx_schedule_proposals_status ON schedule_proposals(status);
CREATE INDEX idx_schedule_notifications_user ON schedule_notifications(notified_user_id, is_read);
CREATE INDEX idx_blotter_cases_dismissed ON blotter_cases(dismissed_by_user_id, dismissal_date);


-- Add missing columns to document_requests table
ALTER TABLE document_requests 
ADD COLUMN user_id INT AFTER person_id,
ADD COLUMN first_name VARCHAR(50) AFTER user_id,
ADD COLUMN middle_name VARCHAR(50) AFTER first_name,
ADD COLUMN last_name VARCHAR(50) AFTER middle_name,
ADD COLUMN suffix VARCHAR(10) AFTER last_name,
ADD COLUMN gender ENUM('Male', 'Female', 'Others') AFTER suffix,
ADD COLUMN civil_status VARCHAR(50) AFTER gender,
ADD COLUMN citizenship VARCHAR(50) DEFAULT 'Filipino' AFTER civil_status,
ADD COLUMN birth_date DATE AFTER citizenship,
ADD COLUMN birth_place VARCHAR(100) AFTER birth_date,
ADD COLUMN religion VARCHAR(50) AFTER birth_place,
ADD COLUMN education_level VARCHAR(100) AFTER religion,
ADD COLUMN occupation VARCHAR(100) AFTER education_level,
ADD COLUMN monthly_income DECIMAL(10,2) AFTER occupation,
ADD COLUMN contact_number VARCHAR(20) AFTER monthly_income,
ADD COLUMN address_no VARCHAR(50) AFTER contact_number,
ADD COLUMN street VARCHAR(100) AFTER address_no,
ADD COLUMN business_name VARCHAR(100) AFTER street,
ADD COLUMN business_location VARCHAR(200) AFTER business_name,
ADD COLUMN business_nature VARCHAR(200) AFTER business_location,
ADD COLUMN business_type VARCHAR(100) AFTER business_nature,
ADD COLUMN purpose TEXT AFTER business_type,
ADD COLUMN ctc_number VARCHAR(100) AFTER purpose,
ADD COLUMN or_number VARCHAR(100) AFTER ctc_number,
ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;