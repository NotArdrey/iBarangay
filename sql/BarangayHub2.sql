-- Drop the database if it exists and create a new one
DROP DATABASE IF EXISTS barangay;
CREATE DATABASE barangay;
USE barangay;

/*-------------------------------------------------------------
  SECTION 1: BARANGAY AND REFERENCE TABLES (CORE LOOKUPS)
  -------------------------------------------------------------*/

-- Barangay lookup table
CREATE TABLE barangay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

-- Insert barangays of San Rafael
INSERT INTO barangay (name) VALUES
    ('BMA-Balagtas'), ('Banca‐Banca'), ('Caingin'), ('Capihan'),
    ('Coral na Bato'), ('Cruz na Daan'), ('Dagat‐Dagatan'), ('Diliman I'),
    ('Diliman II'), ('Libis'), ('Lico'), ('Maasim'), ('Mabalas‐Balas'),
    ('Maguinao'), ('Maronquillo'), ('Paco'), ('Pansumaloc'), ('Pantubig'),
    ('Pasong Bangkal'), ('Pasong Callos'), ('Pasong Intsik'), ('Pinacpinacan'),
    ('Poblacion'), ('Pulo'), ('Pulong Bayabas'), ('Salapungan'),
    ('Sampaloc'), ('San Agustin'), ('San Roque'), ('Sapang Pahalang'),
    ('Talacsan'), ('Tambubong'), ('Tukod'), ('Ulingao');

-- Role definitions
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO roles (name, description) VALUES 
    ('programmer', 'System developer with full access'),
    ('super_admin', 'Administrative account with system-wide access'),
    ('barangay_captain', 'Lead barangay official'),
    ('barangay_secretary', 'Administrative official for barangay operations'),
    ('barangay_treasurer', 'Financial official for barangay funds'),
    ('barangay_councilor', 'Elected barangay council member'),
    ('chief_officer', 'Leads specific barangay services'),
    ('resident', 'Regular barangay resident');

-- Document types
CREATE TABLE document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type_id INT, -- Retained for compatibility if legacy system used it
    name VARCHAR(100) NOT NULL,
    document_name VARCHAR(100),
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    default_fee DECIMAL(10,2) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO document_types (name, document_name, code, description, default_fee) VALUES
    ('Barangay Clearance', 'Barangay Clearance', 'barangay_clearance', 'A clearance issued by the Barangay.', 50.00),
    ('First Time Job Seeker', 'First Time Job Seeker', 'first_time_job_seeker', 'Certification for first‐time job seekers.', 0.00),
    ('Proof of Residency', 'Proof of Residency', 'proof_of_residency', 'Official proof of residency certificate.', 30.00),
    ('Barangay Indigency', 'Barangay Indigency', 'barangay_indigency', 'A document certifying indigency status.', 20.00),
    ('Good Moral Certificate', 'Good Moral Certificate', 'good_moral_certificate', 'Certification of good moral character.', 30.00),
    ('No Income Certification', 'No Income Certification', 'no_income_certification', 'Certification for individuals with no regular income.', 20.00);

-- Case categories for blotter management
CREATE TABLE case_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO case_categories (name) VALUES
    ('RA 9262 (VAWC) ‐ Physical'),
    ('RA 9262 (VAWC) ‐ Sexual'),
    ('RA 9262 (VAWC) ‐ Psychosocial'),
    ('RA 9262 (VAWC) ‐ Economic'),
    ('RA 7877 (Sexual Harassment)'),
    ('RA 9208 (Anti‐trafficking)'),
    ('Psychological'),
    ('Other cases / Bullying Emotional'),
    ('Programs/Activities/Projects Implemented');

-- Case intervention types
CREATE TABLE case_interventions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO case_interventions (name) VALUES
    ('M/CSWD'), ('PNP'), ('Court'), ('Issued BPO'), ('Medical');

/*-------------------------------------------------------------
  SECTION 1.B: CENSUS LOOKUP TABLES
  -------------------------------------------------------------*/

-- Asset types
CREATE TABLE asset_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE income_source_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    requires_amount BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE living_arrangement_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE skill_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE involvement_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE problem_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    category_type ENUM('health', 'economic', 'social', 'housing') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

-- Health concern types
-- REMOVED: health_concern_types table

-- Community service types
-- REMOVED: community_service_types table

-- Other needs and concerns types
CREATE TABLE other_need_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    category ENUM('social', 'economic', 'environmental', 'others') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE relationship_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15) UNIQUE,
    role_id INT DEFAULT 8, -- Default to resident
    barangay_id INT DEFAULT 1, -- Default to a generic/first barangay
    id_expiration_date DATE,
    id_type VARCHAR(50),
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    gender ENUM('Male', 'Female', 'Others'),
    password VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP NULL,
    phone_verified_at TIMESTAMP NULL,
    verification_token VARCHAR(32),
    verification_expiry DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id)
);

ALTER TABLE users ADD COLUMN govt_id_image LONGBLOB;

-- Person information
CREATE TABLE persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    suffix VARCHAR(10),
    birth_date DATE NOT NULL,
    birth_place VARCHAR(100) NOT NULL,
    gender ENUM('MALE', 'FEMALE') NOT NULL,
    civil_status ENUM('SINGLE', 'MARRIED', 'WIDOW/WIDOWER', 'SEPARATED') NOT NULL,
    citizenship VARCHAR(50) DEFAULT 'Filipino',
    religion VARCHAR(50),
    education_level ENUM('NOT ATTENDED ANY SCHOOL', 'ELEMENTARY LEVEL', 'ELEMENTARY GRADUATE', 'HIGH SCHOOL LEVEL', 'HIGH SCHOOL GRADUATE', 'VOCATIONAL', 'COLLEGE LEVEL', 'COLLEGE GRADUATE', 'POST GRADUATE'),
    occupation VARCHAR(100),
    monthly_income DECIMAL(10,2),
    years_of_residency INT DEFAULT 0,
    nhts_pr_listahanan BOOLEAN DEFAULT FALSE,
    indigenous_people BOOLEAN DEFAULT FALSE,
    pantawid_beneficiary BOOLEAN DEFAULT FALSE,
    resident_type ENUM('REGULAR', 'SENIOR', 'PWD') DEFAULT 'REGULAR',
    contact_number VARCHAR(20),
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- User-Role assignment
CREATE TABLE user_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    barangay_id INT NOT NULL,
    start_term_date DATE NULL,
    end_term_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_role_barangay (user_id, role_id, barangay_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Official ID
CREATE TABLE person_identification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    osca_id VARCHAR(50),
    gsis_id VARCHAR(50),
    sss_id VARCHAR(50),
    tin_id VARCHAR(50),
    philhealth_id VARCHAR(50),
    other_id_type VARCHAR(50),
    other_id_number VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Address information
CREATE TABLE addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    user_id INT,
    barangay_id INT NOT NULL,
    house_no VARCHAR(50),
    street VARCHAR(100),
    phase VARCHAR(50),
    municipality VARCHAR(100) DEFAULT 'SAN RAFAEL',
    province VARCHAR(100) DEFAULT 'BULACAN',
    region VARCHAR(50) DEFAULT 'III',
    is_primary BOOLEAN DEFAULT TRUE,
    is_permanent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id)
);

-- Emergency contact information
CREATE TABLE emergency_contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    contact_name VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    contact_address VARCHAR(200),
    relationship VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Purok table
CREATE TABLE purok (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    UNIQUE KEY uk_barangay_purok (barangay_id, name)
);


-- Household information
CREATE TABLE households (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_number VARCHAR(50) NOT NULL,
    barangay_id INT NOT NULL,
    purok_id INT,
    household_head_person_id INT,
    household_size INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (purok_id) REFERENCES purok(id) ON DELETE SET NULL,
    FOREIGN KEY (household_head_person_id) REFERENCES persons(id) ON DELETE SET NULL,
    UNIQUE KEY uk_household_number (household_number, barangay_id, purok_id)
);

-- Person-Household relationship
CREATE TABLE household_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_id INT NOT NULL,
    person_id INT NOT NULL,
    relationship_type_id INT NOT NULL,
    is_household_head BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_household_person (household_id, person_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (relationship_type_id) REFERENCES relationship_types(id) ON DELETE CASCADE
);

CREATE TABLE temporary_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(10),
    first_name VARCHAR(100) NOT NULL,
    house_number VARCHAR(100) NOT NULL,
    street VARCHAR(100) NOT NULL,
    barangay VARCHAR(100) NOT NULL,
    municipality VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    region VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    date_of_birth DATE NOT NULL,
    place_of_birth VARCHAR(255) NOT NULL,
    months_residency INT NOT NULL,
    days_residency INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert sample users
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active) VALUES
    ('programmer@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 1, 1, 'System', 'Programmer', 'Male', NOW(), TRUE),
    ('superadmin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 2, 1, 'Super', 'Administrator', 'Female', NOW(), TRUE),
    ('barangayadmin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 4, 32, 'Barangay', 'Administrator', 'Male', NOW(), TRUE),
    ('resident1@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Test', 'Resident', 'Male', NOW(), TRUE);

-- Insert sample persons for the users
INSERT INTO persons (user_id, first_name, last_name, birth_date, birth_place, gender, civil_status, religion, education_level, monthly_income, years_of_residency, resident_type, contact_number) 
VALUES 
    (1, 'System', 'Programmer', '1990-01-01', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'ROMAN CATHOLIC', 'COLLEGE GRADUATE', 50000.00, 15, 'REGULAR', '09123456789'),
    (2, 'Super', 'Administrator', '1985-01-01', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'ROMAN CATHOLIC', 'COLLEGE GRADUATE', 45000.00, 20, 'REGULAR', '09234567890'),
    (3, 'Barangay', 'Administrator', '1980-01-01', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'ROMAN CATHOLIC', 'COLLEGE LEVEL', 35000.00, 25, 'REGULAR', '09345678901'),
    (4, 'Test', 'Resident', '1990-01-01', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'ROMAN CATHOLIC', 'VOCATIONAL', 25000.00, 10, 'REGULAR', '09456789012');

-- Insert user roles
INSERT INTO user_roles (user_id, role_id, barangay_id, is_active) VALUES
    (1, 1, 1, TRUE),    -- Programmer role
    (2, 2, 1, TRUE),    -- Super admin role
    (3, 4, 32, TRUE),   -- Barangay secretary role in Tambubong
    (4, 8, 32, TRUE);   -- Resident role

-- Insert more sample persons (general residents)
INSERT INTO persons (first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, religion, education_level, occupation, monthly_income, years_of_residency, resident_type, contact_number) 
VALUES 
    ('Juan', 'Santos', 'Dela Cruz', '1980-05-15', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'ROMAN CATHOLIC', 'HIGH SCHOOL GRADUATE', 'Farmer', 15000.00, 30, 'REGULAR', '09567890123'),
    ('Maria', 'Garcia', 'Santos', '1985-08-20', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'ROMAN CATHOLIC', 'COLLEGE GRADUATE', 'Teacher', 25000.00, 18, 'REGULAR', '09678901234'),
    ('Pedro', 'Ramos', 'Gonzales', '1975-12-10', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'PROTESTANT', 'HIGH SCHOOL LEVEL', 'Driver', 12000.00, 12, 'REGULAR', '09789012345'),
    ('Ana', 'Flores', 'Reyes', '1990-03-25', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'ROMAN CATHOLIC', 'COLLEGE GRADUATE', 'Nurse', 35000.00, 8, 'REGULAR', '09890123456'),
    ('Jose', 'Miguel', 'Torres', '1970-07-08', 'San Rafael, Bulacan', 'MALE', 'WIDOW/WIDOWER', 'ROMAN CATHOLIC', 'VOCATIONAL', 'Retired', 15000.00, 35, 'REGULAR', '09901234567');

-- Insert sample addresses for residents
INSERT INTO addresses (person_id, user_id, barangay_id, house_no, street, is_primary, is_permanent) VALUES
    (5, NULL, 32, '123', 'Mabini Street', TRUE, FALSE),
    (6, NULL, 18, '456', 'Rizal Avenue', TRUE, FALSE),
    (7, NULL, 3, '789', 'Luna Street', TRUE, FALSE),
    (8, NULL, 32, '321', 'Bonifacio Road', TRUE, FALSE),
    (9, NULL, 18, '654', 'Aguinaldo Street', TRUE, FALSE);

-- Insert sample puroks
INSERT INTO purok (id, barangay_id, name) VALUES
    (1, 32, 'Purok 1'),
    (2, 32, 'Purok 2'),
    (3, 32, 'Purok 3');

-- Insert sample households
INSERT INTO households (id, household_number, barangay_id, purok_id, household_head_person_id) VALUES
    (1, '0001', 32, 1, 5);  -- Juan's household in Tambubong

-- Insert sample household members
INSERT INTO household_members (household_id, person_id, relationship_type_id, is_household_head) VALUES
    ('0001', 5, 1, TRUE),   -- Juan as HEAD
    ('0001', 6, 2, FALSE),  -- Maria as SPOUSE
    ('0001', 7, 3, FALSE),  -- Pedro as CHILD
    ('0001', 8, 3, FALSE),  -- Ana as CHILD
    ('0001', 9, 3, FALSE);  -- Jose as CHILD

/*-------------------------------------------------------------
  SECTION 2.B: NORMALIZED PERSON DETAIL TABLES (CENSUS)
  -------------------------------------------------------------*/

-- Person assets (Normalized version)
CREATE TABLE person_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    asset_type_id INT NOT NULL,
    details TEXT, -- For specifics if 'Others' or additional info
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (asset_type_id) REFERENCES asset_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_asset (person_id, asset_type_id)
);

-- Person income sources (Normalized version)
CREATE TABLE person_income_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    source_type_id INT NOT NULL,
    amount DECIMAL(10,2), -- If requires_amount is true in income_source_types
    details TEXT, -- For specifics if 'Others' or additional info
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (source_type_id) REFERENCES income_source_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_income_source (person_id, source_type_id)
);

-- Person living arrangements (Normalized version)
CREATE TABLE person_living_arrangements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    arrangement_type_id INT NOT NULL,
    details TEXT, -- For specifics if 'Others' or additional info
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (arrangement_type_id) REFERENCES living_arrangement_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_arrangement (person_id, arrangement_type_id)
);

-- Person skills (Normalized version)
CREATE TABLE person_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    skill_type_id INT NOT NULL,
    details TEXT, -- For specifics if 'Others' or additional info
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_type_id) REFERENCES skill_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_skill (person_id, skill_type_id)
);

-- Person community involvements (Normalized version)
CREATE TABLE person_involvements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    involvement_type_id INT NOT NULL,
    details TEXT, -- For specifics if 'Others' or additional info
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (involvement_type_id) REFERENCES involvement_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_involvement (person_id, involvement_type_id)
);

-- Person problems/concerns (linking to problem_categories)
CREATE TABLE person_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    problem_category_id INT NOT NULL,
    details TEXT, -- For specific details of the problem
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Added for consistency
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (problem_category_id) REFERENCES problem_categories(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_problem (person_id, problem_category_id)
);

-- Person health information (replaces senior_health, more generic)
CREATE TABLE person_health_info (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    health_condition TEXT, -- General description
    has_maintenance BOOLEAN DEFAULT FALSE,
    maintenance_details TEXT,
    high_cost_medicines BOOLEAN DEFAULT FALSE,
    lack_medical_professionals BOOLEAN DEFAULT FALSE,
    lack_sanitation_access BOOLEAN DEFAULT FALSE,
    lack_health_insurance BOOLEAN DEFAULT FALSE,
    lack_medical_facilities BOOLEAN DEFAULT FALSE,
    other_health_concerns TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Person health concerns (linking to health_concern_types)
-- REMOVED: person_health_concerns table

-- Person community service needs (linking to community_service_types)
-- REMOVED: person_service_needs table

-- Person other needs (linking to other_need_types)
CREATE TABLE person_other_needs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    need_type_id INT NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (need_type_id) REFERENCES other_need_types(id) ON DELETE CASCADE,
    UNIQUE KEY uk_person_other_need (person_id, need_type_id)
);

/*-------------------------------------------------------------
  SECTION 2.C: CHILD-SPECIFIC INFORMATION
  -------------------------------------------------------------*/

-- Child-specific information
CREATE TABLE child_information (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    is_malnourished BOOLEAN DEFAULT FALSE,
    attending_school BOOLEAN DEFAULT FALSE,
    school_name VARCHAR(255),
    grade_level VARCHAR(50),
    school_type ENUM('Public', 'Private', 'ALS', 'Day Care', 'SNP', 'Not Attending') DEFAULT 'Not Attending',
    immunization_complete BOOLEAN DEFAULT FALSE,
    is_pantawid_beneficiary BOOLEAN DEFAULT FALSE, -- Note: also in persons table, check usage
    has_timbang_operation BOOLEAN DEFAULT FALSE,
    has_feeding_program BOOLEAN DEFAULT FALSE,
    has_supplementary_feeding BOOLEAN DEFAULT FALSE,
    in_caring_institution BOOLEAN DEFAULT FALSE,
    is_under_foster_care BOOLEAN DEFAULT FALSE,
    is_directly_entrusted BOOLEAN DEFAULT FALSE,
    is_legally_adopted BOOLEAN DEFAULT FALSE,
    occupation VARCHAR(255),
    garantisadong_pambata BOOLEAN DEFAULT FALSE,
    under_six_years BOOLEAN DEFAULT FALSE,
    grade_school BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Child health conditions (specific ENUM based, kept separate from generic health concerns)
CREATE TABLE child_health_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    condition_type ENUM('Malaria', 'Dengue', 'Pneumonia', 'Tuberculosis', 'Diarrhea') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
    -- Consider adding UNIQUE KEY (person_id, condition_type) if a child can't have the same condition listed twice.
);

-- Child disabilities (specific ENUM based)
CREATE TABLE child_disabilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    disability_type ENUM('Blind/Visually Impaired', 'Hearing Impairment', 'Speech/Communication', 'Orthopedic/Physical', 'Intellectual/Learning', 'Psychosocial') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
    -- Consider adding UNIQUE KEY (person_id, disability_type)
);

/*-------------------------------------------------------------
  SECTION 2.D: RESIDENT DETAILS AND GOVERNMENT PROGRAMS
  -------------------------------------------------------------*/

-- Government programs participation
CREATE TABLE government_programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    nhts_pr_listahanan BOOLEAN DEFAULT FALSE,
    indigenous_people BOOLEAN DEFAULT FALSE,
    pantawid_beneficiary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Income sources details
CREATE TABLE income_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    own_earnings BOOLEAN DEFAULT FALSE,
    own_pension BOOLEAN DEFAULT FALSE,
    own_pension_amount DECIMAL(10,2),
    stocks_dividends BOOLEAN DEFAULT FALSE,
    dependent_on_children BOOLEAN DEFAULT FALSE,
    spouse_salary BOOLEAN DEFAULT FALSE,
    insurances BOOLEAN DEFAULT FALSE,
    spouse_pension BOOLEAN DEFAULT FALSE,
    spouse_pension_amount DECIMAL(10,2),
    rentals_sharecrops BOOLEAN DEFAULT FALSE,
    savings BOOLEAN DEFAULT FALSE,
    livestock_orchards BOOLEAN DEFAULT FALSE,
    others BOOLEAN DEFAULT FALSE,
    others_specify VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Assets and properties
CREATE TABLE assets_properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    house BOOLEAN DEFAULT FALSE,
    house_lot BOOLEAN DEFAULT FALSE,
    farmland BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Living arrangements
CREATE TABLE living_arrangements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    spouse BOOLEAN DEFAULT FALSE,
    care_institutions BOOLEAN DEFAULT FALSE,
    children BOOLEAN DEFAULT FALSE,
    grandchildren BOOLEAN DEFAULT FALSE,
    househelps BOOLEAN DEFAULT FALSE,
    relatives BOOLEAN DEFAULT FALSE,
    others BOOLEAN DEFAULT FALSE,
    others_specify VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Skills
CREATE TABLE skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    dental BOOLEAN DEFAULT FALSE,
    counseling BOOLEAN DEFAULT FALSE,
    evangelization BOOLEAN DEFAULT FALSE,
    farming BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Problems and needs
CREATE TABLE problems_needs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    lack_income BOOLEAN DEFAULT FALSE,
    unemployment BOOLEAN DEFAULT FALSE,
    economic_others BOOLEAN DEFAULT FALSE,
    economic_others_specify VARCHAR(100),
    loneliness BOOLEAN DEFAULT FALSE,
    isolation BOOLEAN DEFAULT FALSE,
    neglect BOOLEAN DEFAULT FALSE,
    lack_health_insurance BOOLEAN DEFAULT FALSE,
    inadequate_health_services BOOLEAN DEFAULT FALSE,
    lack_medical_facilities BOOLEAN DEFAULT FALSE,
    overcrowding BOOLEAN DEFAULT FALSE,
    no_permanent_housing BOOLEAN DEFAULT FALSE,
    independent_living BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Insert sample data for existing persons
INSERT INTO government_programs (person_id, nhts_pr_listahanan, indigenous_people, pantawid_beneficiary)
SELECT id, FALSE, FALSE, FALSE FROM persons;

INSERT INTO income_sources (person_id, own_earnings, own_pension, stocks_dividends, dependent_on_children)
SELECT id, TRUE, FALSE, FALSE, FALSE FROM persons;

INSERT INTO assets_properties (person_id, house, house_lot, farmland)
SELECT id, FALSE, FALSE, FALSE FROM persons;

INSERT INTO living_arrangements (person_id, spouse, children, relatives)
SELECT id, FALSE, FALSE, FALSE FROM persons;

INSERT INTO skills (person_id, dental, counseling, evangelization, farming)
SELECT id, FALSE, FALSE, FALSE, FALSE FROM persons;

INSERT INTO problems_needs (person_id, lack_income, unemployment, loneliness)
SELECT id, FALSE, FALSE, FALSE FROM persons;

/*-------------------------------------------------------------
  SECTION 3: BARANGAY OPERATIONS & DOCUMENT REQUEST SYSTEM
  -------------------------------------------------------------*/

-- Barangay settings and operation information
CREATE TABLE barangay_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL UNIQUE,
    cutoff_time TIME NOT NULL DEFAULT '15:00:00',
    opening_time TIME NOT NULL DEFAULT '08:00:00',
    closing_time TIME NOT NULL DEFAULT '17:00:00',
    barangay_captain_name VARCHAR(100),
    contact_number VARCHAR(15),
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Document purpose/attribute types (normalized)
CREATE TABLE document_attribute_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(40) NOT NULL,
    description VARCHAR(200),
    is_required BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doc_attr_code (document_type_id, code),
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

INSERT INTO document_attribute_types (document_type_id, name, code, description, is_required) VALUES
    (1, 'Purpose for Clearance', 'clearance_purpose', 'Purpose for barangay clearance', TRUE),
    (3, 'Duration of Residency', 'residency_duration', 'How long the requester has resided', TRUE),
    (3, 'Purpose for Certificate', 'residency_purpose', 'Purpose for certificate of residency', TRUE),
    (5, 'Purpose for Certificate', 'gmc_purpose', 'Purpose for good‐moral certificate', TRUE),
    (6, 'Reason for Certificate', 'nic_reason', 'Reason for no‐income certificate', TRUE),
    (4, 'Stated Income', 'indigency_income', 'Stated income for indigency cert.', TRUE),
    (4, 'Reason for Request', 'indigency_reason', 'Reason for requesting indigency cert.', TRUE);

CREATE TABLE document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    user_id INT, -- User who is the subject of the request, or system user if different model
    document_type_id INT NOT NULL,
    barangay_id INT NOT NULL,
    status ENUM('pending', 'processing', 'for_payment', 'paid', 'for_pickup', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    remarks TEXT,
    proof_image_path VARCHAR(255) NULL,
    requested_by_user_id INT, -- System user who made the request
    processed_by_user_id INT, -- System user who processed the request
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, -- This FK should be ON DELETE SET NULL if user can be deleted but request remains
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Document request attributes (normalized)
CREATE TABLE document_request_attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    attribute_type_id INT NOT NULL,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_request_attribute (request_id, attribute_type_id),
    FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (attribute_type_id) REFERENCES document_attribute_types(id) ON DELETE CASCADE
);

/*-------------------------------------------------------------
  SECTION 4: PAYMENT SYSTEM (Placeholder if more tables needed)
  -------------------------------------------------------------*/
-- (No tables currently defined in this section in original)

/*-------------------------------------------------------------
  SECTION 5: BLOTTER/CASE MANAGEMENT
  -------------------------------------------------------------*/

-- Main blotter case information
CREATE TABLE blotter_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_number VARCHAR(50) UNIQUE,
    incident_date DATETIME,
    location VARCHAR(200),
    description TEXT,
    status ENUM('pending', 'open', 'closed', 'completed', 'transferred') DEFAULT 'pending',
    barangay_id INT,
    reported_by_person_id INT,
    assigned_to_user_id INT,
    scheduled_hearing DATETIME,
    resolution_details TEXT,
    resolved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by_person_id) REFERENCES persons(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- People involved in blotter cases
CREATE TABLE blotter_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    person_id INT, -- Can be NULL if participant is not in persons table
    first_name VARCHAR(50), -- If person_id is NULL
    last_name VARCHAR(50),  -- If person_id is NULL
    contact_number VARCHAR(20), -- If person_id is NULL
    address VARCHAR(255), -- If person_id is NULL
    age INT, -- If person_id is NULL
    gender VARCHAR(50), -- If person_id is NULL
    role ENUM('complainant', 'respondent', 'witness') NOT NULL,
    statement TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_person_role (blotter_case_id, person_id, role), -- This might fail if person_id is NULL and multiple NULLs are not unique depending on SQL version/config.
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL
);

-- Case-category relationship
CREATE TABLE blotter_case_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    category_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_category (blotter_case_id, category_id),
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES case_categories(id) ON DELETE CASCADE
);

-- Case-intervention relationship
CREATE TABLE blotter_case_interventions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    intervention_id INT NOT NULL,
    intervened_at DATETIME NOT NULL,
    performed_by VARCHAR(100),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_intervention_date (blotter_case_id, intervention_id, intervened_at),
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (intervention_id) REFERENCES case_interventions(id) ON DELETE CASCADE
);

-- Case hearings and mediation
CREATE TABLE case_hearings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    hearing_date DATETIME NOT NULL,
    hearing_type ENUM('initial', 'mediation', 'conciliation', 'final') NOT NULL,
    hearing_notes TEXT,
    hearing_outcome ENUM('scheduled', 'conducted', 'postponed', 'resolved', 'failed') DEFAULT 'scheduled',
    presided_by_user_id INT,
    next_hearing_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (presided_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Case hearing attendees
CREATE TABLE hearing_attendances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT NOT NULL,
    participant_id INT NOT NULL,
    is_present BOOLEAN DEFAULT FALSE,
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hearing_participant (hearing_id, participant_id),
    FOREIGN KEY (hearing_id) REFERENCES case_hearings(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES blotter_participants(id) ON DELETE CASCADE
);

/*-------------------------------------------------------------
  SECTION 6: EVENTS & REPORTING SYSTEM
  -------------------------------------------------------------*/

-- Event management
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    location VARCHAR(200) NOT NULL,
    organizer VARCHAR(100),
    barangay_id INT NOT NULL,
    created_by_user_id INT NOT NULL,
    status ENUM('scheduled', 'ongoing', 'completed', 'postponed', 'cancelled') DEFAULT 'scheduled',
    max_participants INT DEFAULT NULL,
    registration_required BOOLEAN DEFAULT FALSE,
    registration_deadline DATETIME DEFAULT NULL,
    event_type ENUM('meeting', 'seminar', 'activity', 'celebration', 'emergency', 'other') DEFAULT 'other',
    contact_person VARCHAR(100),
    contact_number VARCHAR(20),
    requirements TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Event participants
CREATE TABLE event_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    person_id INT NOT NULL,
    attendance_status ENUM('registered', 'confirmed', 'attended', 'no_show') DEFAULT 'registered',
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_event_person (event_id, person_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Monthly report tracking
CREATE TABLE monthly_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    report_year YEAR(4) NOT NULL,
    report_month TINYINT NOT NULL,
    prepared_by_user_id INT,
    approved_by_user_id INT,
    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
    remarks TEXT,
    submitted_at DATETIME,
    approved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_brgy_year_month (barangay_id, report_year, report_month),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (prepared_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Monthly report details by category
CREATE TABLE monthly_report_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    monthly_report_id INT NOT NULL,
    category_id INT NOT NULL,
    total_cases INT DEFAULT 0,
    total_pnp INT DEFAULT 0,
    total_court INT DEFAULT 0,
    total_issued_bpo INT DEFAULT 0,
    total_medical INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_report_category (monthly_report_id, category_id),
    FOREIGN KEY (monthly_report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES case_categories(id) ON DELETE CASCADE
);

/*-------------------------------------------------------------
  SECTION 7: SYSTEM TABLES (AUDIT, TOKENS, SESSIONS)
  -------------------------------------------------------------*/

-- System activity auditing
CREATE TABLE audit_trails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT, -- Changed to allow NULL if system action or pre-user creation
    admin_user_id INT, -- Clarify purpose, perhaps for actions done *on behalf of* a user by an admin
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(100),
    record_id VARCHAR(100),
    old_values TEXT,
    new_values TEXT,
    description VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    action_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL -- SET NULL if user is deleted
);

CREATE TABLE password_reset_tokens (
    email VARCHAR(100) PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (email) REFERENCES users(email) ON DELETE CASCADE
);

-- Personal access tokens (for API authentication)
CREATE TABLE personal_access_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tokenable (tokenable_type, tokenable_id)
);

-- Sessions table (for web sessions)
CREATE TABLE sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id INT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    INDEX idx_sessions_user_id (user_id),
    INDEX idx_sessions_last_activity (last_activity),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE -- Or SET NULL depending on desired behavior
);

/*-------------------------------------------------------------
  SECTION 8: SAMPLE DATA INSERTION
  -------------------------------------------------------------*/

-- Insert sample settings for a few barangays
INSERT INTO barangay_settings (barangay_id, barangay_captain_name) VALUES
    (32, 'Juan Dela Cruz'),  -- Tambubong 
    (18, 'Maria Santos'),    -- Pantubig
    (3, 'Roberto Reyes');    -- Caingin

-- Insert sample document requests (assuming person_id 5-9 and user_id 3 for barangay admin)
INSERT INTO document_requests (person_id, user_id, document_type_id, barangay_id, requested_by_user_id, status) VALUES
    (5, NULL, 1, 32, 3, 'pending'),
    (6, NULL, 3, 18, 3, 'processing'),
    (7, NULL, 4, 3, 3, 'completed'),
    (8, NULL, 2, 32, 3, 'for_payment'),
    (9, NULL, 5, 18, 3, 'cancelled');
    
-- Insert sample blotter cases (assuming person_id 5-8 and user_id 3)
INSERT INTO blotter_cases (case_number, incident_date, location, description, status, barangay_id, reported_by_person_id, assigned_to_user_id) VALUES
    ('TAM-2024-0001', '2024-01-15 14:30:00', 'Mabini Street, Tambubong', 'Noise complaint against neighbor', 'open', 32, 5, 3),
    ('PAN-2024-0001', '2024-01-20 09:15:00', 'Rizal Avenue, Pantubig', 'Property boundary dispute', 'pending', 18, 6, 3),
    ('CAI-2024-0001', '2024-01-25 16:45:00', 'Luna Street, Caingin', 'Family dispute mediation', 'closed', 3, 7, 3);

-- Insert sample blotter participants (assuming blotter_case_id 1,2,3 and person_id 5,6,7,8)
INSERT INTO blotter_participants (blotter_case_id, person_id, role, statement) VALUES
    (1, 5, 'complainant', 'Neighbor is playing loud music past 10 PM'),
    (1, 8, 'respondent', 'We were just celebrating a birthday'), -- Pedro Gonzales is person_id 7. Ana Flores is 8.
    (2, 6, 'complainant', 'Neighbor built fence on my property'),
    (3, 7, 'complainant', 'Need help resolving family issues');

-- Insert sample case categories for blotter cases
INSERT INTO blotter_case_categories (blotter_case_id, category_id) VALUES
    (1, 8), -- Other cases / Bullying Emotional
    (2, 8), -- Other cases / Bullying Emotional 
    (3, 8); -- Other cases / Bullying Emotional

-- Insert sample events (assuming user_id 3)
INSERT INTO events (title, description, start_datetime, end_datetime, location, organizer, barangay_id, created_by_user_id) VALUES
    ('Barangay Assembly', 'Monthly barangay assembly meeting', '2024-02-15 19:00:00', '2024-02-15 21:00:00', 'Barangay Hall', 'Barangay Council', 32, 3),
    ('Health Fair', 'Free medical checkup and consultation', '2024-02-20 08:00:00', '2024-02-20 17:00:00', 'Covered Court', 'Health Committee', 18, 3),
    ('Clean-up Drive', 'Community clean-up activity', '2024-02-25 06:00:00', '2024-02-25 10:00:00', 'Various Streets', 'Environment Committee', 3, 3);

-- Now insert into audit_trails AFTER users are created
INSERT INTO audit_trails (user_id, action, table_name, record_id, description, action_timestamp) VALUES
    (3, 'LOGIN', 'users', '3', 'User logged into the system', NOW()),
    (3, 'VIEW', 'document_requests', '1', 'Viewed document request details', NOW()),
    (3, 'EXPORT', 'audit_trails', 'ALL', 'Exported audit trail report', NOW()),
    (3, 'FILTER', 'audit_trails', 'ALL', 'Applied filters to audit trail view', NOW());

/*-------------------------------------------------------------
  SECTION 9: INDEXES FOR PERFORMANCE
  -------------------------------------------------------------*/

-- User-related indexes
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_phone ON users(phone);
CREATE INDEX idx_users_active ON users(is_active);
CREATE INDEX idx_users_last_login ON users(last_login);
CREATE INDEX idx_users_role_id ON users(role_id);
CREATE INDEX idx_users_barangay_id ON users(barangay_id);

-- Person-related indexes
CREATE INDEX idx_persons_user_id ON persons(user_id);
CREATE INDEX idx_persons_name ON persons(last_name, first_name);
CREATE INDEX idx_persons_birth_date ON persons(birth_date);

-- Address indexes
CREATE INDEX idx_addresses_person_id ON addresses(person_id);
CREATE INDEX idx_addresses_user_id ON addresses(user_id);
CREATE INDEX idx_addresses_barangay_id ON addresses(barangay_id);
CREATE INDEX idx_addresses_primary ON addresses(is_primary);
CREATE INDEX idx_addresses_permanent ON addresses(is_permanent);

-- Document request indexes
CREATE INDEX idx_document_requests_person_id ON document_requests(person_id);
CREATE INDEX idx_document_requests_user_id ON document_requests(user_id);
CREATE INDEX idx_document_requests_barangay_id ON document_requests(barangay_id);
CREATE INDEX idx_document_requests_status ON document_requests(status);
CREATE INDEX idx_document_requests_created_at ON document_requests(created_at);

-- Blotter case indexes
CREATE INDEX idx_blotter_cases_barangay_id ON blotter_cases(barangay_id);
CREATE INDEX idx_blotter_cases_status ON blotter_cases(status);
CREATE INDEX idx_blotter_cases_created_at ON blotter_cases(created_at);
CREATE INDEX idx_blotter_cases_case_number ON blotter_cases(case_number);

-- Event indexes
CREATE INDEX idx_events_barangay_id ON events(barangay_id);
CREATE INDEX idx_events_start_datetime ON events(start_datetime);
CREATE INDEX idx_events_status ON events(status);

-- Audit trail indexes
CREATE INDEX idx_audit_trails_user_id ON audit_trails(user_id);
CREATE INDEX idx_audit_trails_table_name ON audit_trails(table_name);
CREATE INDEX idx_audit_trails_action_timestamp ON audit_trails(action_timestamp);

/*-------------------------------------------------------------
  SECTION 10: TRIGGERS
  -------------------------------------------------------------*/

DROP TRIGGER IF EXISTS users_audit_insert;
DROP TRIGGER IF EXISTS users_audit_update;
DROP TRIGGER IF EXISTS document_requests_audit_insert;
DROP TRIGGER IF EXISTS document_requests_audit_update;
DROP TRIGGER IF EXISTS blotter_cases_audit_insert;
DROP TRIGGER IF EXISTS blotter_cases_audit_update;
DROP TRIGGER IF EXISTS persons_audit_insert;
DROP TRIGGER IF EXISTS persons_audit_update;

DELIMITER //

-- Audit trigger for users table inserts
CREATE TRIGGER users_audit_insert AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_trails (user_id, action, table_name, record_id, new_values, description, ip_address, user_agent)
    VALUES (NEW.id, 'INSERT', 'users', NEW.id, 
            CONCAT('email:', NEW.email, ', role_id:', NEW.role_id, ', barangay_id:', NEW.barangay_id), 
            CONCAT('New user created: ', COALESCE(NEW.first_name, ''), ' ', COALESCE(NEW.last_name, ''), ' (', NEW.email, ')'),
            NULL, NULL);
END //

-- Audit trigger for users table updates
CREATE TRIGGER users_audit_update AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    DECLARE changes TEXT DEFAULT '';
    
    IF OLD.email != NEW.email THEN
        SET desc_text = CONCAT(desc_text, 'Email changed from ', OLD.email, ' to ', NEW.email, '; ');
        SET changes = CONCAT(changes, 'email:', OLD.email, '->', NEW.email, ',');
    END IF;
    IF OLD.is_active != NEW.is_active THEN
        SET desc_text = CONCAT(desc_text, 'Account status changed to ', 
                              CASE WHEN NEW.is_active = 1 THEN 'Active' ELSE 'Inactive' END, '; ');
        SET changes = CONCAT(changes, 'is_active:', OLD.is_active, '->', NEW.is_active, ',');
    END IF;
    
    SET changes = TRIM(TRAILING ',' FROM changes);
    
    IF desc_text = '' THEN
        SET desc_text = CONCAT('User profile updated for: ', NEW.first_name, ' ', NEW.last_name);
    ELSE
        SET desc_text = CONCAT('User profile updated for: ', NEW.first_name, ' ', NEW.last_name, ' - ', TRIM(TRAILING '; ' FROM desc_text));
    END IF;

    INSERT INTO audit_trails (user_id, action, table_name, record_id, old_values, new_values, description, ip_address, user_agent)
    VALUES (NEW.id, 'UPDATE', 'users', NEW.id, 
            changes, -- Simplified old values
            CONCAT('email:', NEW.email, ',is_active:', NEW.is_active),
            desc_text, NULL, NULL);
END //

-- Audit trigger for document requests inserts
CREATE TRIGGER document_requests_audit_insert AFTER INSERT ON document_requests
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    DECLARE doc_type_name VARCHAR(100) DEFAULT '';
    DECLARE person_name VARCHAR(100) DEFAULT '';
    
    SELECT name INTO doc_type_name FROM document_types WHERE id = NEW.document_type_id LIMIT 1;
    SELECT CONCAT(first_name, ' ', last_name) INTO person_name 
    FROM persons WHERE id = NEW.person_id LIMIT 1;
    
    SET desc_text = CONCAT('New document request created: ', 
                          COALESCE(doc_type_name, 'Unknown Document'),
                          ' for ', COALESCE(person_name, 'Unknown Person'),
                          ' (Status: ', NEW.status, ')');

    INSERT INTO audit_trails (user_id, action, table_name, record_id, new_values, description, ip_address, user_agent)
    VALUES (COALESCE(NEW.requested_by_user_id, 1), 'INSERT', 'document_requests', NEW.id,
            CONCAT('document_type_id:', NEW.document_type_id, ',person_id:', NEW.person_id, ',status:', NEW.status),
            desc_text, NULL, NULL);
END //

-- Audit trigger for document requests updates
CREATE TRIGGER document_requests_audit_update AFTER UPDATE ON document_requests
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    DECLARE doc_type_name VARCHAR(100) DEFAULT '';
    
    SELECT name INTO doc_type_name FROM document_types WHERE id = NEW.document_type_id LIMIT 1;
    
    IF OLD.status != NEW.status THEN
        SET desc_text = CONCAT('Document request status changed from "', 
                              UPPER(REPLACE(OLD.status, '_', ' ')), '" to "', 
                              UPPER(REPLACE(NEW.status, '_', ' ')), '" for ', 
                              COALESCE(doc_type_name, 'document'), ' (ID: ', NEW.id, ')');
        
        CASE NEW.status
            WHEN 'processing' THEN SET desc_text = CONCAT(desc_text, ' - Request is now being processed.');
            WHEN 'for_payment' THEN SET desc_text = CONCAT(desc_text, ' - Request is awaiting payment.');
            WHEN 'paid' THEN SET desc_text = CONCAT(desc_text, ' - Payment received.');
            WHEN 'for_pickup' THEN SET desc_text = CONCAT(desc_text, ' - Document is ready for pickup.');
            WHEN 'completed' THEN SET desc_text = CONCAT(desc_text, ' - Request completed.');
            WHEN 'cancelled' THEN SET desc_text = CONCAT(desc_text, ' - Request has been cancelled.');
            WHEN 'rejected' THEN SET desc_text = CONCAT(desc_text, ' - Request was rejected.');
            ELSE BEGIN END; 
        END CASE;
        
        INSERT INTO audit_trails (user_id, action, table_name, record_id, old_values, new_values, description, ip_address, user_agent)
        VALUES (COALESCE(NEW.processed_by_user_id, NEW.requested_by_user_id, OLD.requested_by_user_id, 1), 
                'STATUS_CHANGE', 'document_requests', NEW.id,
                CONCAT('status:', OLD.status),
                CONCAT('status:', NEW.status),
                desc_text, NULL, NULL);
    END IF;
END //

DELIMITER ;

/*-------------------------------------------------------------
  SECTION 11: FINAL COMMANDS
  -------------------------------------------------------------*/
FLUSH PRIVILEGES;

-- Family composition table
CREATE TABLE family_composition (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_id INT NOT NULL,
    person_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    age INT NOT NULL,
    civil_status ENUM('SINGLE', 'MARRIED', 'WIDOW/WIDOWER', 'SEPARATED') NOT NULL,
    occupation VARCHAR(100),
    monthly_income DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Insert sample family composition data for existing household members
INSERT INTO family_composition (
    household_id, 
    person_id, 
    name, 
    relationship,
    age, 
    civil_status, 
    occupation, 
    monthly_income
)
SELECT 
    hm.household_id,
    p.id,
    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name, ' ', COALESCE(p.suffix, '')),
    rt.name,
    TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()),
    p.civil_status,
    p.occupation,
    p.monthly_income
FROM household_members hm
JOIN persons p ON hm.person_id = p.id
JOIN relationship_types rt ON hm.relationship_type_id = rt.id;

-- Insert sample person identification data
INSERT INTO person_identification (person_id, osca_id, gsis_id, sss_id, tin_id, philhealth_id, other_id_type, other_id_number) VALUES
    (1, NULL, '1234567890', '11-2222222-3', '123-456-789-000', 'PH-12345678901', 'Driver\'s License', 'N01-12-345678'),
    (2, NULL, '2345678901', '22-3333333-4', '234-567-890-000', 'PH-23456789012', 'Voter\'s ID', 'VID-123456789'),
    (3, NULL, '3456789012', '33-4444444-5', '345-678-901-000', 'PH-34567890123', 'UMID', 'CRN-123456789012'),
    (4, NULL, NULL, '44-5555555-6', '456-789-012-000', 'PH-45678901234', 'Postal ID', 'P-12345678'),
    (5, '1234-5678-9012', NULL, '55-6666666-7', '567-890-123-000', 'PH-56789012345', NULL, NULL),
    (6, NULL, '4567890123', '66-7777777-8', '678-901-234-000', 'PH-67890123456', 'PRC ID', 'PRC-123456'),
    (7, NULL, NULL, '77-8888888-9', '789-012-345-000', 'PH-78901234567', NULL, NULL),
    (8, NULL, NULL, '88-9999999-0', '890-123-456-000', 'PH-89012345678', 'Passport', 'P1234567A'),
    (9, '2345-6789-0123', NULL, '99-0000000-1', '901-234-567-000', 'PH-90123456789', NULL, NULL);

-- Insert sample person assets data
INSERT INTO person_assets (person_id, asset_type_id, details) VALUES
    (1, 1, 'Two-story house'),
    (1, 2, '150 sqm residential lot'),
    (2, 2, '200 sqm with house'),
    (3, 1, 'Bungalow house'),
    (3, 3, '1 hectare rice field'),
    (4, 4, 'Small store building'),
    (5, 2, '300 sqm with house'),
    (5, 6, 'Small fish pond'),
    (6, 1, 'Three-story house'),
    (6, 5, '500 sqm vacant lot'),
    (7, 3, '2 hectare farmland'),
    (8, 2, '250 sqm with house'),
    (9, 1, 'Two-story house');

-- Insert sample person income sources data
INSERT INTO person_income_sources (person_id, source_type_id, amount, details) VALUES
    (1, 1, NULL, 'Software Developer Salary'),
    (1, 3, NULL, 'Stock investments'),
    (2, 1, NULL, 'Administrative work'),
    (3, 1, NULL, 'Government Employee'),
    (3, 7, 15000.00, 'Monthly pension'),
    (4, 1, NULL, 'Private sector employee'),
    (5, 1, NULL, 'Farming income'),
    (5, 8, NULL, 'Land rental'),
    (6, 2, 25000.00, 'Teacher\'s pension'),
    (7, 1, NULL, 'Driver income'),
    (8, 1, NULL, 'Nurse salary'),
    (9, 2, 20000.00, 'Retirement pension');
    
 -- Insert sample person living arrangements data
INSERT INTO person_living_arrangements (person_id, arrangement_type_id, details) VALUES
    (1, 2, 'Living with spouse'),
    (2, 4, 'Living with children'),
    (3, 2, 'Living with spouse'),
    (4, 1, 'Living alone'),
    (5, 2, 'Living with spouse and children'),
    (6, 4, 'Living with children'),
    (7, 1, 'Living alone'),
    (8, 8, 'Living with relatives'),
    (9, 3, 'Senior care facility');

-- Insert sample person skills data
INSERT INTO person_skills (person_id, skill_type_id, details) VALUES
    (1, 12, 'Software Engineering'),
    (2, 2, 'Elementary Education'),
    (3, 3, 'Paralegal'),
    (4, 10, 'Automotive'),
    (5, 7, 'Rice Farming'),
    (6, 2, 'High School Teaching'),
    (7, 8, 'Deep sea fishing'),
    (8, 1, 'Nursing'),
    (9, 11, 'Traditional Crafts');

-- Insert sample person community involvements data
INSERT INTO person_involvements (person_id, involvement_type_id, details) VALUES
    (1, 2, 'IT Training Volunteer'),
    (2, 4, 'Parent-Teacher Association Head'),
    (3, 8, 'Church Organization Leader'),
    (4, 3, 'Street Cleaning Drive Organizer'),
    (5, 7, 'Neighborhood Watch Member'),
    (6, 1, 'Medical Mission Volunteer'),
    (7, 6, 'Senior Citizens Group Member'),
    (8, 9, 'Youth Counseling'),
    (9, 4, 'Senior Citizens Association Officer');

-- Insert sample person problems data
INSERT INTO person_problems (person_id, problem_category_id, details) VALUES
    (1, 15, 'High cost of living'),
    (2, 3, 'Limited access to medical services'),
    (3, 7, 'Mobility issues'),
    (4, 14, 'Housing loan concerns'),
    (5, 2, 'Healthcare costs'),
    (6, 20, 'Transportation difficulties'),
    (7, 12, 'Home maintenance issues'),
    (8, 4, 'Work-related stress'),
    (9, 19, 'Social isolation');

-- Insert sample person health information
INSERT INTO person_health_info (person_id, health_condition, has_maintenance, maintenance_details, high_cost_medicines) VALUES
    (1, 'Hypertension', TRUE, 'Maintenance for blood pressure', TRUE),
    (2, 'Diabetes', TRUE, 'Insulin maintenance', TRUE),
    (3, 'Arthritis', TRUE, 'Pain management medication', TRUE),
    (4, 'None', FALSE, NULL, FALSE),
    (5, 'High Cholesterol', TRUE, 'Cholesterol maintenance', FALSE),
    (6, 'Asthma', TRUE, 'Inhaler maintenance', TRUE),
    (7, 'Heart Disease', TRUE, 'Heart medication', TRUE),
    (8, 'None', FALSE, NULL, FALSE),
    (9, 'Osteoporosis', TRUE, 'Calcium supplements', FALSE);

-- Insert sample person health concerns
-- REMOVED: person_health_concerns table

-- Insert sample person service needs
-- REMOVED: person_service_needs table

-- Insert sample person other needs
INSERT INTO person_other_needs (person_id, need_type_id, details) VALUES
    (1, 1, 'Medicine subsidy'),
    (2, 2, 'Part-time work'),
    (3, 3, 'Skills training'),
    (5, 4, 'Social activities'),
    (6, 5, 'Family counseling'),
    (7, 6, 'Home safety improvements'),
    (9, 7, 'Environmental concerns');

-- Economic Problems
CREATE TABLE person_economic_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    loss_income BOOLEAN DEFAULT FALSE,
    unemployment BOOLEAN DEFAULT FALSE,
    high_cost_living BOOLEAN DEFAULT FALSE,
    skills_training BOOLEAN DEFAULT FALSE,
    skills_training_details TEXT,
    livelihood BOOLEAN DEFAULT FALSE,
    livelihood_details TEXT,
    other_economic BOOLEAN DEFAULT FALSE,
    other_economic_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Social Problems
CREATE TABLE person_social_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    loneliness BOOLEAN DEFAULT FALSE,
    isolation BOOLEAN DEFAULT FALSE,
    neglect BOOLEAN DEFAULT FALSE,
    recreational BOOLEAN DEFAULT FALSE,
    senior_friendly BOOLEAN DEFAULT FALSE,
    other_social BOOLEAN DEFAULT FALSE,
    other_social_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Health Problems
CREATE TABLE person_health_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    condition_illness BOOLEAN DEFAULT FALSE,
    condition_illness_details TEXT,
    high_cost_medicine BOOLEAN DEFAULT FALSE,
    lack_medical_professionals BOOLEAN DEFAULT FALSE,
    lack_sanitation BOOLEAN DEFAULT FALSE,
    lack_health_insurance BOOLEAN DEFAULT FALSE,
    inadequate_health_services BOOLEAN DEFAULT FALSE,
    other_health BOOLEAN DEFAULT FALSE,
    other_health_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Housing Problems
CREATE TABLE person_housing_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    overcrowding BOOLEAN DEFAULT FALSE,
    no_permanent_housing BOOLEAN DEFAULT FALSE,
    independent_living BOOLEAN DEFAULT FALSE,
    lost_privacy BOOLEAN DEFAULT FALSE,
    squatters BOOLEAN DEFAULT FALSE,
    other_housing BOOLEAN DEFAULT FALSE,
    other_housing_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Community Service Problems
CREATE TABLE person_community_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    desire_participate BOOLEAN DEFAULT FALSE,
    skills_to_share BOOLEAN DEFAULT FALSE,
    other_community BOOLEAN DEFAULT FALSE,
    other_community_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

