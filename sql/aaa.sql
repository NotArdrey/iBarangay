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
    ('barangay_chairperson', 'Leads blottercases'), 
    ('resident', 'Regular barangay resident'),
	('health_worker', 'Health worker for census');
    

-- Document types
CREATE TABLE document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    default_fee DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO document_types (name, code, description, default_fee) VALUES
    ('Barangay Clearance', 'barangay_clearance', 'A clearance issued by the Barangay.', 50.00),
    ('First Time Job Seeker', 'first_time_job_seeker', 'Certification for first‐time job seekers.', 0.00),
    ('Proof of Residency', 'proof_of_residency', 'Official proof of residency certificate.', 30.00),
    ('Barangay Indigency', 'barangay_indigency', 'A document certifying indigency status.', 0.00),
    ('Cedula', 'cedula', 'Community Tax Certificate (Cedula)', 30.00),
    ('Business Permit Clearance', 'business_permit_clearance', 'Barangay clearance for business permit.', 100.00),
    ('No Income Certification', 'no_income_certification', 'Certification for individuals with no regular income.', 0.00);

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
    id_number VARCHAR(50),
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
    start_term_date DATE NULL,
    end_term_date DATE NULL,
    id_image_path VARCHAR(255) DEFAULT 'default.png',
    signature_image_path VARCHAR(255) NULL,
    esignature_path VARCHAR(255) NULL,
    govt_id_image LONGBLOB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id)
);

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
    is_archived BOOLEAN DEFAULT FALSE,
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
    barangay_id INT,
    barangay_name VARCHAR(60),
    house_no VARCHAR(50),
    street VARCHAR(100),
    phase VARCHAR(50),
    municipality VARCHAR(100) DEFAULT 'SAN RAFAEL',
    province VARCHAR(100) DEFAULT 'BULACAN',
    region VARCHAR(50) DEFAULT 'III',
    subdivision VARCHAR(100),
    block_lot VARCHAR(50),
    residency_type ENUM('Home Owner', 'Renter', 'Boarder', 'Living-In') NOT NULL,
    years_in_san_rafael INT,
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
    relationship_to_head VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_household_person (household_id, person_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (relationship_type_id) REFERENCES relationship_types(id) ON DELETE CASCADE
);

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

-- Economic Problems
CREATE TABLE person_economic_problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    loss_income BOOLEAN DEFAULT FALSE,
    unemployment BOOLEAN DEFAULT FALSE,
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

-- Legacy tables for backward compatibility (kept from second file structure)
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

-- Temporary records table
CREATE TABLE temporary_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(10),
    first_name VARCHAR(100) NOT NULL,
    house_number VARCHAR(100) NOT NULL,
    street VARCHAR(100) NOT NULL,
    barangay_id VARCHAR(100) NOT NULL,
    municipality VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    region VARCHAR(100) NOT NULL,
    id_type VARCHAR(100) NOT NULL,
    id_number VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100),
    date_of_birth DATE NOT NULL,
    place_of_birth VARCHAR(255) NOT NULL,
    months_residency INT NOT NULL,
    days_residency INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
    local_barangay_contact VARCHAR(20),
    pnp_contact VARCHAR(20),
    bfp_contact VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Document attribute types
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

CREATE TABLE document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Core relationships (normalized)
    person_id INT NOT NULL,
    user_id INT NULL,
    document_type_id INT NOT NULL,
    barangay_id INT NOT NULL,
    
    -- Request status and processing
    status ENUM('pending','completed','rejected') DEFAULT 'pending',
    price DECIMAL(10,2) DEFAULT 0.00,
    remarks TEXT,
    proof_image_path VARCHAR(255) NULL,
    requested_by_user_id INT,
    processed_by_user_id INT,
    completed_at DATETIME,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Document-specific information (only what's unique to this request)
    purpose TEXT NULL,
    ctc_number VARCHAR(100) NULL,
    or_number VARCHAR(100) NULL,
    
    -- Business-related information (for business permits only)
    business_name VARCHAR(100) NULL,
    business_location VARCHAR(200) NULL,
    business_nature VARCHAR(200) NULL,
    business_type VARCHAR(100) NULL,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Key Constraints
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    -- Indexes for performance
    INDEX idx_doc_requests_status_barangay (status, barangay_id, request_date),
    INDEX idx_doc_requests_person (person_id),
    INDEX idx_doc_requests_doctype (document_type_id),
    INDEX idx_doc_requests_user (user_id)
);

-- Fix 2: Add unique constraint for First Time Job Seeker (one per person)
CREATE TABLE document_request_restrictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    document_type_code VARCHAR(50) NOT NULL,
    first_requested_at DATETIME NOT NULL,
    request_count INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_person_document_restriction (person_id, document_type_code),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    INDEX idx_document_restrictions (document_type_code, person_id)
);

-- Document request attributes
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

-- Barangay document prices
CREATE TABLE barangay_document_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    document_type_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_barangay_document (barangay_id, document_type_id),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
);

-- Service categories
CREATE TABLE service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fa-cog',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Custom services
CREATE TABLE custom_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    barangay_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    detailed_guide TEXT,
    requirements TEXT,
    processing_time VARCHAR(100),
    fees VARCHAR(100),
    icon VARCHAR(50) DEFAULT 'fa-file',
    url_path VARCHAR(255),
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Service requirements
CREATE TABLE service_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_required BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES custom_services(id) ON DELETE CASCADE
);

-- Service requests
CREATE TABLE service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'rejected', 'cancelled') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (service_id) REFERENCES custom_services(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Service request attachments
CREATE TABLE service_request_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    file_size INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE
);

/*-------------------------------------------------------------
  SECTION 4: BLOTTER/CASE MANAGEMENT SYSTEM
  -------------------------------------------------------------*/

-- External participants (for people not in the persons table)
CREATE TABLE external_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    contact_number VARCHAR(20),
    address VARCHAR(255),
    age INT,
    gender ENUM('Male', 'Female', 'Others'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Main blotter case information
CREATE TABLE blotter_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_number VARCHAR(50) UNIQUE,
    incident_date DATETIME,
    location VARCHAR(200),
    description TEXT,
    status ENUM('pending', 'open', 'closed', 'completed', 'transferred', 'solved', 'endorsed_to_court', 'cfa_eligible', 'deleted') DEFAULT 'pending',
    scheduling_status ENUM('none', 'pending_schedule', 'schedule_proposed', 'schedule_confirmed', 'scheduled', 'completed', 'cancelled') DEFAULT 'none',
    barangay_id INT,
    reported_by_person_id INT,
    assigned_to_user_id INT,
    scheduled_hearing DATETIME,
    resolution_details TEXT,
    resolved_at DATETIME,
    is_cfa_eligible BOOLEAN DEFAULT FALSE,
    cfa_issued_at DATETIME NULL,
    endorsed_to_court_at DATETIME NULL,
    hearing_count INT DEFAULT 0,
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
    person_id INT,
    external_participant_id INT,
    role ENUM('complainant', 'respondent', 'witness') NOT NULL,
    statement TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_person_role (blotter_case_id, person_id, role),
    UNIQUE KEY uk_case_external_role (blotter_case_id, external_participant_id, role),
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (external_participant_id) REFERENCES external_participants(id) ON DELETE CASCADE
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
    hearing_number INT DEFAULT 1,
    presiding_officer_name VARCHAR(100) NULL,
    presiding_officer_position VARCHAR(100) NULL,
    is_mediation_successful BOOLEAN DEFAULT FALSE,
    resolution_details TEXT NULL,
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
    participant_type VARCHAR(20) NULL,
    attendance_remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hearing_participant (hearing_id, participant_id),
    FOREIGN KEY (hearing_id) REFERENCES case_hearings(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES blotter_participants(id) ON DELETE CASCADE
);

-- CFA certificates
CREATE TABLE cfa_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    complainant_person_id INT NULL,
    issued_by_user_id INT NULL,
    certificate_number VARCHAR(50) NOT NULL,
    issued_at DATETIME NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (complainant_person_id) REFERENCES persons(id) ON DELETE SET NULL,
    FOREIGN KEY (issued_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

/*-------------------------------------------------------------
  SECTION 5: EVENTS & REPORTING SYSTEM
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
    report_month INT NOT NULL,
    report_year INT NOT NULL,
    created_by_user_id INT NOT NULL,
    prepared_by_user_id INT NULL,
    submitted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_barangay_month_year (barangay_id, report_month, report_year),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (prepared_by_user_id) REFERENCES users(id) ON DELETE SET NULL
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
  SECTION 6: SYSTEM TABLES (AUDIT, TOKENS, SESSIONS)
  -------------------------------------------------------------*/



CREATE TABLE password_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id_created (user_id, created_at)
);

CREATE TABLE schedule_proposals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    blotter_case_id INT,
    proposed_by_user_id INT,
    proposed_date DATE NOT NULL,
    proposed_time TIME NOT NULL,
    hearing_location VARCHAR(255) NOT NULL,
    presiding_officer VARCHAR(100) NOT NULL,
    presiding_officer_position VARCHAR(50) NOT NULL,
    status ENUM('proposed', 'user_confirmed', 'captain_confirmed', 'both_confirmed', 'conflict', 'pending_user_confirmation', 'pending_officer_confirmation', 'cancelled') NOT NULL DEFAULT 'proposed',
    user_confirmed BOOLEAN DEFAULT FALSE,
    user_confirmed_at DATETIME,
    captain_confirmed BOOLEAN DEFAULT FALSE,
    captain_confirmed_at DATETIME,
    confirmed_by_role INT,
    user_remarks TEXT,
    captain_remarks TEXT,
    conflict_reason TEXT,
    complainant_confirmed  BOOLEAN DEFAULT FALSE,
    respondent_confirmed  BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id),
    FOREIGN KEY (proposed_by_user_id) REFERENCES users(id),
    FOREIGN KEY (confirmed_by_role) REFERENCES roles(id)
);

-- Add missing participant_notifications table
CREATE TABLE participant_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    participant_id INT NOT NULL,
    delivery_method VARCHAR(20), -- Added
    delivery_status VARCHAR(20) DEFAULT 'pending', -- Added
    delivery_address TEXT, -- Added
    email_address VARCHAR(255),
    phone_number VARCHAR(20),
    notification_type ENUM('summons', 'hearing_notice', 'reminder') DEFAULT 'summons',
    sent_at DATETIME,
    confirmed BOOLEAN DEFAULT FALSE,
    confirmed_at DATETIME NULL,
    confirmation_token VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES blotter_participants(id) ON DELETE CASCADE,
    UNIQUE KEY uk_case_participant_type (blotter_case_id, participant_id, notification_type),
    INDEX idx_confirmation_token (confirmation_token),
    INDEX idx_sent_confirmed (sent_at, confirmed)
);
-- System activity auditing
CREATE TABLE audit_trails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_user_id INT,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(100),
    record_id VARCHAR(100),
    old_values TEXT,
    new_values TEXT,
    description VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    action_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Email logs
CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template_used VARCHAR(100),
    sent_at DATETIME NOT NULL,
    status ENUM('sent', 'failed', 'pending') DEFAULT 'pending',
    error_message TEXT NULL,
    blotter_case_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE SET NULL
);

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    related_table VARCHAR(100) NULL,
    related_id INT NULL,
    action_url VARCHAR(255) NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_type (type),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Hearing schedules
CREATE TABLE hearing_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hearing_date DATE NOT NULL,
    hearing_time TIME NOT NULL,
    location VARCHAR(255) DEFAULT 'Barangay Hall',
    max_hearings_per_slot INT DEFAULT 5,
    current_bookings INT DEFAULT 0,
    is_available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Password reset tokens
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
    INDEX idx_sessions_last_activity (last_activity)
);

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
    ('chiefOfficer.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 7, 32, 'Ricardo', 'Morales', 'Male', NOW(), TRUE),
    ('healthworker.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Ricardo', 'Morales', 'Male', NOW(), TRUE);

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
    (9, 'Ricardo', 'Morales', '1968-11-10', 'Bulacan', 'MALE', 'MARRIED'),
    (10, 'tite', 'flores', '1968-11-10', 'Bulacan', 'MALE', 'MARRIED');

-- Insert additional residents without user accounts
INSERT INTO persons (first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, occupation, contact_number) VALUES
    ('Luis', 'Manalo', 'Santos', '1995-02-14', 'San Rafael', 'MALE', 'SINGLE', 'Engineer', '09112233445'),
    ('Sofia', 'Alcantara', 'Reyes', '1988-11-30', 'San Rafael', 'FEMALE', 'MARRIED', 'Business Owner', '09223344556'),
    ('Miguel', 'Tolentino', 'Cruz', '1972-07-22', 'San Rafael', 'MALE', 'WIDOW/WIDOWER', 'Fisherman', '09334455667'),
    ('Carlos', 'Santos', 'Dela Cruz', '1980-05-15', 'San Rafael', 'MALE', 'MARRIED', 'Farmer', '09123456789'),
    ('Elena', 'Garcia', 'Santos', '1985-08-20', 'San Rafael', 'FEMALE', 'MARRIED', 'Teacher', '09987654321'),
    ('Pedro', 'Ramos', 'Gonzales', '1975-12-10', 'San Rafael', 'MALE', 'SINGLE', 'Driver', '09111222333'),
    ('Ana', 'Flores', 'Reyes', '1990-03-25', 'San Rafael', 'FEMALE', 'SINGLE', 'Nurse', '09444555666'),
	('tite', 'Flores', 'Reyes', '1990-03-25', 'San Rafael', 'FEMALE', 'SINGLE', 'Nurse', '09344555666');


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
    
-- Insert Document Requests (corrected statuses)
INSERT INTO document_requests (person_id, user_id, document_type_id, barangay_id, requested_by_user_id, status) VALUES
    (10, NULL, 1, 32, 3, 'pending'),    -- Luis Santos (no user account)
    (11, NULL, 3, 32, 3, 'pending'),    -- Sofia Reyes (no user account)
    (12, NULL, 4, 32, 3, 'pending'),    -- Miguel Cruz (no user account)
    (13, NULL, 1, 32, 3, 'pending'),    -- Carlos Dela Cruz (no user account)
    (14, NULL, 3, 32, 3, 'pending'),    -- Elena Santos (no user account)
    (5, 5, 5, 32, 3, 'pending');        -- Test Resident (user_id=5)



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
ADD COLUMN service_type VARCHAR(50) DEFAULT 'general' AFTER barangay_id,
ADD COLUMN priority_level ENUM('normal', 'high', 'urgent') DEFAULT 'normal' AFTER display_order,
ADD COLUMN availability_type ENUM('always', 'scheduled', 'limited') DEFAULT 'always' AFTER priority_level,
ADD COLUMN additional_notes TEXT AFTER availability_type;

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
CREATE TABLE schedule_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_proposal_id INT NOT NULL,
    notified_user_id INT NOT NULL,
    notification_type ENUM('proposal', 'confirmation', 'rejection') NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_proposal_id) REFERENCES schedule_proposals(id) ON DELETE CASCADE,
    FOREIGN KEY (notified_user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE case_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    notified_user_id INT NOT NULL,
    notification_type ENUM('case_filed', 'case_accepted', 'hearing_scheduled', 'signature_required') NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (notified_user_id) REFERENCES users(id) ON DELETE CASCADE
);
-- Add indexes for better performance
CREATE INDEX idx_schedule_proposals_status ON schedule_proposals(status);
CREATE INDEX idx_schedule_notifications_user ON schedule_notifications(notified_user_id, is_read);
CREATE INDEX idx_blotter_cases_dismissed ON blotter_cases(dismissed_by_user_id, dismissal_date);
CREATE TABLE barangay_paymongo_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    is_enabled BOOLEAN DEFAULT FALSE,
    public_key VARCHAR(255),
    secret_key VARCHAR(255),
    webhook_secret VARCHAR(255),
    test_mode BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_barangay_id (barangay_id),
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

select * FROM barangay_paymongo_settings;

ALTER TABLE blotter_cases 
ADD COLUMN accepted_by_user_id INT NULL AFTER assigned_to_user_id,
ADD COLUMN accepted_by_role_id INT NULL AFTER accepted_by_user_id,
ADD COLUMN accepted_at DATETIME NULL AFTER accepted_by_role_id,
ADD COLUMN filing_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER incident_date,
ADD COLUMN scheduling_deadline DATETIME GENERATED ALWAYS AS (DATE_ADD(filing_date, INTERVAL 5 DAY)) STORED,
ADD COLUMN requires_dual_signature BOOLEAN DEFAULT FALSE AFTER hearing_count,
ADD COLUMN captain_signature_date DATETIME NULL AFTER requires_dual_signature,
ADD COLUMN chief_signature_date DATETIME NULL AFTER captain_signature_date,
ADD FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
ADD FOREIGN KEY (accepted_by_role_id) REFERENCES roles(id) ON DELETE SET NULL;

ALTER TABLE custom_services 
ADD COLUMN service_photo VARCHAR(255) AFTER additional_notes;

ALTER TABLE barangay.users ADD COLUMN chief_officer_esignature_path LONGBLOB NULL;
ALTER TABLE barangay.case_notifications
MODIFY COLUMN notification_type ENUM(
    'case_filed',
    'case_accepted',
    'hearing_scheduled',
    'signature_required',
    'schedule_confirmation',
    'schedule_approved',
    'schedule_rejected'
) NOT NULL;

CREATE INDEX idx_users_esignature ON users(esignature_path);

ALTER TABLE schedule_proposals 
ADD COLUMN witness_confirmed BOOLEAN DEFAULT FALSE AFTER respondent_confirmed;

ALTER TABLE schedule_proposals 
ADD COLUMN chief_confirmed BOOLEAN DEFAULT FALSE AFTER captain_confirmed,
ADD COLUMN chief_confirmed_at DATETIME NULL AFTER chief_confirmed,
ADD COLUMN status_updated_at DATETIME NULL AFTER updated_at;
ALTER TABLE schedule_proposals 
ADD COLUMN chief_remarks TEXT NULL AFTER captain_remarks;
ALTER TABLE schedule_proposals 
MODIFY COLUMN status ENUM(
    'proposed', 
    'user_confirmed',
    'captain_confirmed',
    'both_confirmed',
    'conflict',
    'pending_user_confirmation',
    'pending_captain_approval',  
    'pending_chief_approval',    
    'all_confirmed',                
    'cancelled', 
    'officer_conflict'
) NOT NULL DEFAULT 'proposed';


ALTER TABLE document_requests
ADD COLUMN is_archived BOOLEAN DEFAULT FALSE AFTER business_type;

ALTER TABLE document_requests
MODIFY COLUMN status ENUM('pending','completed','rejected', 'processing', 'for_payment', 'archived') DEFAULT 'pending';

ALTER TABLE custom_services
ADD COLUMN is_archived BOOLEAN DEFAULT FALSE AFTER additional_notes,
ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER is_archived;

ALTER TABLE document_requests 
ADD COLUMN delivery_method ENUM('hardcopy', 'softcopy') DEFAULT 'hardcopy' AFTER business_type,
ADD COLUMN payment_method ENUM('cash', 'online') DEFAULT 'cash' AFTER delivery_method,
ADD COLUMN payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending' AFTER payment_method,
ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_status,
ADD COLUMN paymongo_checkout_id VARCHAR(100) NULL AFTER payment_reference,
ADD COLUMN payment_date DATETIME NULL AFTER paymongo_checkout_id;

ALTER TABLE temporary_records ADD COLUMN is_archived VARCHAR(50) DEFAULT FALSE AFTER days_residency;
-- Insert a complete resident who has been living in Barangay Tambubong for 7 years
-- Starting with the core person record
INSERT INTO users (
    email, 
    phone, 
    role_id, 
    barangay_id, 
    id_expiration_date,
    id_type,
    id_number,
    first_name, 
    last_name, 
    gender, 
    password, 
    email_verified_at, 
    phone_verified_at,
    verification_token,
    verification_expiry,
    is_active,
    last_login,
    start_term_date,
    end_term_date,
    id_image_path,
    signature_image_path,
    esignature_path,
    govt_id_image
) VALUES (
    'maria.rodriguez@gmail.com',
    '09173456789',
    8, -- resident role
    32, -- Tambubong barangay
    '2029-03-15', -- ID expiration (5 years from birth month)
    'Voters ID',
    'TAMBUBONG-2024-001',
    'Maria Teresa',
    'Rodriguez',
    'Female',
    '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', -- Same password as other users
    NOW(), -- Email verified
    NOW(), -- Phone verified  
    NULL, -- No verification token needed since verified
    NULL, -- No verification expiry needed
    TRUE, -- Active account
    NULL, -- No last login yet
    NULL, -- No term dates for residents
    NULL,
    'default.png', -- Default profile image
    NULL, -- No signature image yet
    NULL, -- No e-signature yet
    NULL  -- No government ID image yet
);

INSERT INTO persons (
    first_name, 
    middle_name, 
    last_name, 
    suffix,
    birth_date, 
    birth_place, 
    gender, 
    civil_status,
    citizenship,
    religion,
    education_level,
    occupation,
    monthly_income,
    years_of_residency,
    nhts_pr_listahanan,
    indigenous_people,
    pantawid_beneficiary,
    resident_type,
    contact_number,
    user_id,
    is_archived
) VALUES (
    'Maria Teresa',
    'Santos', 
    'Rodriguez',
    NULL,
    '1985-03-15',
    'San Rafael, Bulacan',
    'FEMALE',
    'MARRIED',
    'Filipino',
    'Roman Catholic',
    'COLLEGE GRADUATE',
    'Elementary School Teacher',
    25000.00,
    7,
    FALSE,
    FALSE,
    FALSE,
    'REGULAR',
    '09173456789',
    NULL,
    FALSE
);

-- Get the person_id for subsequent inserts (assuming this is the next auto-increment ID)
SET @person_id = LAST_INSERT_ID();

-- Insert address information (7 years in Tambubong)
INSERT INTO addresses (
    person_id,
    user_id,
    barangay_id,
    barangay_name,
    house_no,
    street,
    phase,
    municipality,
    province,
    region,
    subdivision,
    block_lot,
    residency_type,
    years_in_san_rafael,
    is_primary,
    is_permanent
) VALUES (
    @person_id,
    NULL,
    32, -- Tambubong barangay_id
    'Tambubong',
    '125',
    'Maligaya Street',
    'Phase 2',
    'SAN RAFAEL',
    'BULACAN',
    'III',
    'Villa Teresa Subdivision',
    'Block 5 Lot 12',
    'Home Owner',
    7,
    TRUE,
    TRUE
);

-- Insert identification details
INSERT INTO person_identification (
    person_id,
    osca_id,
    gsis_id,
    sss_id,
    tin_id,
    philhealth_id,
    other_id_type,
    other_id_number
) VALUES (
    @person_id,
    NULL,
    '1234567890',
    '12-3456789-0',
    '123-456-789-000',
    '12-345678901-2',
    'Voters ID',
    'TAMBUBONG-2024-001'
);

-- Insert emergency contact
INSERT INTO emergency_contacts (
    person_id,
    contact_name,
    contact_number,
    contact_address,
    relationship
) VALUES (
    @person_id,
    'Juan Carlos Rodriguez',
    '09187654321',
    '125 Maligaya Street, Villa Teresa Subdivision, Tambubong, San Rafael, Bulacan',
    'Husband'
);

-- Insert government programs participation
INSERT INTO government_programs (
    person_id,
    nhts_pr_listahanan,
    indigenous_people,
    pantawid_beneficiary
) VALUES (
    @person_id,
    FALSE,
    FALSE,
    FALSE
);

-- Insert asset information (House & Lot owner)
INSERT INTO person_assets (
    person_id,
    asset_type_id,
    details
) VALUES 
(@person_id, 2, 'Two-story house with lot in Villa Teresa Subdivision'),
(@person_id, 7, 'Small vegetable garden at the back of the house');

-- Insert income sources
INSERT INTO person_income_sources (
    person_id,
    source_type_id,
    amount,
    details
) VALUES 
(@person_id, 1, NULL, 'Monthly salary as Elementary School Teacher'),
(@person_id, 10, NULL, 'Income from small vegetable garden and egg sales');

-- Insert living arrangements
INSERT INTO person_living_arrangements (
    person_id,
    arrangement_type_id,
    details
) VALUES 
(@person_id, 2, 'Lives with husband'),
(@person_id, 4, 'Two children living at home');

-- Insert skills
INSERT INTO person_skills (
    person_id,
    skill_type_id,
    details
) VALUES 
(@person_id, 2, 'Elementary Education Teaching - 8 years experience'),
(@person_id, 9, 'Excellent cooking skills, specializes in Filipino cuisine'),
(@person_id, 7, 'Vegetable gardening and small-scale farming');

-- Insert community involvement
INSERT INTO person_involvements (
    person_id,
    involvement_type_id,
    details
) VALUES 
(@person_id, 4, 'Member of Parent-Teacher Association and Barangay Education Committee'),
(@person_id, 8, 'Active member of local church choir and religious activities'),
(@person_id, 3, 'Volunteers for community beautification projects and tree planting');

-- Insert health information
INSERT INTO person_health_info (
    person_id,
    health_condition,
    has_maintenance,
    maintenance_details,
    high_cost_medicines,
    lack_medical_professionals,
    lack_sanitation_access,
    lack_health_insurance,
    lack_medical_facilities,
    other_health_concerns
) VALUES (
    @person_id,
    'Generally healthy, mild hypertension',
    TRUE,
    'Takes maintenance medication for hypertension - Amlodipine 5mg daily',
    FALSE,
    FALSE,
    FALSE,
    FALSE,
    FALSE,
    'Occasional stress-related headaches due to work'
);

-- Insert economic problems (if any)
INSERT INTO person_economic_problems (
    person_id,
    loss_income,
    unemployment,
    skills_training,
    skills_training_details,
    livelihood,
    livelihood_details,
    other_economic,
    other_economic_details
) VALUES (
    @person_id,
    FALSE,
    FALSE,
    TRUE,
    'Interested in digital literacy training and online teaching methods',
    TRUE,
    'Wants to expand vegetable garden into small business',
    FALSE,
    NULL
);

-- Insert housing problems (minimal since she owns her home)
INSERT INTO person_housing_problems (
    person_id,
    overcrowding,
    no_permanent_housing,
    independent_living,
    lost_privacy,
    squatters,
    other_housing,
    other_housing_details
) VALUES (
    @person_id,
    FALSE,
    FALSE,
    FALSE,
    FALSE,
    FALSE,
    TRUE,
    'Minor roof repairs needed during rainy season'
);

-- Insert other needs
INSERT INTO person_other_needs (
    person_id,
    need_type_id,
    details
) VALUES 
(@person_id, 3, 'Interested in advanced teaching methodology workshops'),
(@person_id, 6, 'Would like more recreational activities for families in the barangay'),
(@person_id, 12, 'Wants access to cultural activities and arts programs for children');

-- Create a household for this person
INSERT INTO households (
    household_number,
    barangay_id,
    purok_id,
    household_head_person_id,
    household_size
) VALUES (
    'TMB-2024-125',
    32, -- Tambubong
    NULL, -- Assuming no purok data yet
    @person_id,
    4 -- Including spouse and 2 children
);

SET @household_id = LAST_INSERT_ID();

-- Add person as household head
INSERT INTO household_members (
    household_id,
    person_id,
    relationship_type_id,
    is_household_head,
    relationship_to_head
) VALUES (
    @household_id,
    @person_id,
    1, -- HEAD
    TRUE,
    'HEAD'
);

-- Add family composition entry
INSERT INTO family_composition (
    household_id,
    person_id,
    name,
    relationship,
    age,
    civil_status,
    occupation,
    monthly_income
) VALUES (
    @household_id,
    @person_id,
    'Maria Teresa Santos Rodriguez',
    'HEAD',
    39,
    'MARRIED',
    'Elementary School Teacher',
    25000.00
);

-- Insert legacy format data for backward compatibility
INSERT INTO income_sources (
    person_id,
    own_earnings,
    own_pension,
    own_pension_amount,
    stocks_dividends,
    dependent_on_children,
    spouse_salary,
    insurances,
    spouse_pension,
    spouse_pension_amount,
    rentals_sharecrops,
    savings,
    livestock_orchards,
    others,
    others_specify
) VALUES (
    @person_id,
    TRUE,  -- own_earnings (teacher salary)
    FALSE, -- own_pension
    NULL,  -- own_pension_amount
    FALSE, -- stocks_dividends
    FALSE, -- dependent_on_children
    TRUE,  -- spouse_salary (husband works)
    TRUE,  -- insurances (GSIS, PhilHealth)
    FALSE, -- spouse_pension
    NULL,  -- spouse_pension_amount
    FALSE, -- rentals_sharecrops
    TRUE,  -- savings
    TRUE,  -- livestock_orchards (vegetable garden, chickens)
    FALSE, -- others
    NULL   -- others_specify
);

INSERT INTO assets_properties (
    person_id,
    house,
    house_lot,
    farmland
) VALUES (
    @person_id,
    FALSE, -- house (separate from lot)
    TRUE,  -- house_lot (owns both)
    FALSE  -- farmland (just small garden)
);

INSERT INTO living_arrangements (
    person_id,
    spouse,
    care_institutions,
    children,
    grandchildren,
    househelps,
    relatives,
    others,
    others_specify
) VALUES (
    @person_id,
    TRUE,  -- spouse
    FALSE, -- care_institutions
    TRUE,  -- children
    FALSE, -- grandchildren
    FALSE, -- househelps
    FALSE, -- relatives
    FALSE, -- others
    NULL   -- others_specify
);

INSERT INTO skills (
    person_id,
    dental,
    counseling,
    evangelization,
    farming
) VALUES (
    @person_id,
    FALSE, -- dental
    TRUE,  -- counseling (as a teacher)
    TRUE,  -- evangelization (church member)
    TRUE   -- farming (vegetable garden)
);

INSERT INTO problems_needs (
    person_id,
    lack_income,
    unemployment,
    economic_others,
    economic_others_specify,
    loneliness,
    isolation,
    neglect,
    lack_health_insurance,
    inadequate_health_services,
    lack_medical_facilities,
    overcrowding,
    no_permanent_housing,
    independent_living
) VALUES (
    @person_id,
    FALSE, -- lack_income
    FALSE, -- unemployment
    FALSE, -- economic_others
    NULL,  -- economic_others_specify
    FALSE, -- loneliness
    FALSE, -- isolation
    FALSE, -- neglect
    FALSE, -- lack_health_insurance (has GSIS/PhilHealth)
    FALSE, -- inadequate_health_services
    FALSE, -- lack_medical_facilities
    FALSE, -- overcrowding
    FALSE, -- no_permanent_housing (owns home)
    FALSE  -- independent_living
);UPDATE blotter_cases bc
SET hearing_count = (
    SELECT COUNT(*) 
    FROM schedule_proposals sp 
    WHERE sp.blotter_case_id = bc.id
)
WHERE bc.id > 0;
