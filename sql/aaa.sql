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
ALTER TABLE events ADD COLUMN target_roles TEXT;
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
ADD COLUMN delivery_method SET('hardcopy', 'softcopy') DEFAULT 'hardcopy' AFTER business_type,
ADD COLUMN payment_method ENUM('cash', 'online') DEFAULT 'cash' AFTER delivery_method,
ADD COLUMN payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending' AFTER payment_method,
ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_status,
ADD COLUMN paymongo_checkout_id VARCHAR(100) NULL AFTER payment_reference,
ADD COLUMN payment_date DATETIME NULL AFTER paymongo_checkout_id;

ALTER TABLE temporary_records ADD COLUMN is_archived VARCHAR(50) DEFAULT FALSE AFTER days_residency;

ALTER TABLE case_hearings
ADD COLUMN created_by_user_id INT NULL AFTER resolution_details,
ADD COLUMN schedule_proposal_id INT NULL AFTER created_by_user_id,
ADD CONSTRAINT fk_case_hearings_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
ADD CONSTRAINT fk_case_hearings_schedule_proposal FOREIGN KEY (schedule_proposal_id) REFERENCES schedule_proposals(id) ON DELETE SET NULL;

ALTER TABLE blotter_cases
ADD COLUMN max_hearing_attempts INT DEFAULT 3 NULL AFTER hearing_count;

ALTER TABLE blotter_cases
ADD COLUMN cfa_reason VARCHAR(255) NULL DEFAULT NULL COMMENT 'Reason why the case became CFA eligible' AFTER is_cfa_eligible;

    ALTER TABLE blotter_cases
    MODIFY COLUMN scheduling_status ENUM(
        'none', 'pending_schedule', 'schedule_proposed', 
        'schedule_confirmed', 'scheduled', 'completed', 'cancelled', 
        'cfa_pending_issuance'  -- Added new value
    ) DEFAULT 'none';
    
    ALTER TABLE case_hearings MODIFY COLUMN hearing_outcome ENUM('scheduled', 'conducted', 'postponed', 'resolved', 'failed', 'cancelled') DEFAULT 'scheduled';
DESCRIBE case_hearings;


-- =====================================================
-- COMPREHENSIVE DUMMY DATA FOR TAMBUBONG AND CAINGIN
-- =====================================================

-- Clear existing sample data (optional - uncomment if needed)
-- DELETE FROM audit_trails WHERE id > 4;
-- DELETE FROM document_requests WHERE id > 6;
-- DELETE FROM event_participants WHERE event_id > 4;
-- DELETE FROM events WHERE id > 4;
-- DELETE FROM addresses WHERE id > 7;
-- DELETE FROM persons WHERE id > 17;
-- DELETE FROM users WHERE id > 10;

-- =====================================================
-- SECTION 1: PUROK DATA
-- =====================================================

-- Tambubong Puroks
INSERT INTO purok (barangay_id, name) VALUES
    (32, 'Purok 1 - Riverside'),
    (32, 'Purok 2 - Central'),
    (32, 'Purok 3 - Mountain View'),
    (32, 'Purok 4 - Garden'),
    (32, 'Purok 5 - Unity');

-- Caingin Puroks
INSERT INTO purok (barangay_id, name) VALUES
    (3, 'Purok A - Sunshine'),
    (3, 'Purok B - Harmony'),
    (3, 'Purok C - Progress'),
    (3, 'Purok D - Victory');

-- =====================================================
-- SECTION 2: ADDITIONAL USERS AND OFFICIALS
-- =====================================================

-- Tambubong Officials
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active, start_term_date, end_term_date) VALUES
    ('secretary.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 4, 32, 'Maria', 'Rodriguez', 'Female', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('treasurer.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 5, 32, 'Antonio', 'Lopez', 'Male', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('councilor1.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 6, 32, 'Carmen', 'Villanueva', 'Female', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('councilor2.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 6, 32, 'Roberto', 'Fernandez', 'Male', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('healthworker1.tambubong@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 9, 32, 'Dr. Elena', 'Cruz', 'Female', NOW(), TRUE, NULL, NULL);

-- Caingin Officials
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active, start_term_date, end_term_date) VALUES
    ('secretary.caingin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 4, 3, 'Luz', 'Mercado', 'Female', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('treasurer.caingin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 5, 3, 'Jose', 'Ramirez', 'Male', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('councilor1.caingin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 6, 3, 'Alma', 'Torres', 'Female', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('chairperson.caingin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 7, 3, 'Benjamin', 'Aguilar', 'Male', NOW(), TRUE, '2023-01-01', '2025-12-31'),
    ('healthworker1.caingin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 9, 3, 'Nurse Rosa', 'Delgado', 'Female', NOW(), TRUE, NULL, NULL);

-- Regular Residents (Tambubong)
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active, phone) VALUES
    ('juan.santos.tambubong@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Juan', 'Santos', 'Male', NOW(), TRUE, '09171234567'),
    ('maria.garcia.tambubong@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Maria', 'Garcia', 'Female', NOW(), TRUE, '09182345678'),
    ('pedro.dela.cruz@yahoo.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Pedro', 'Dela Cruz', 'Male', NOW(), TRUE, '09193456789'),
    ('ana.reyes.tambubong@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Ana', 'Reyes', 'Female', NOW(), TRUE, '09204567890'),
    ('carlos.mendoza@hotmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Carlos', 'Mendoza', 'Male', NOW(), TRUE, '09215678901');

-- Regular Residents (Caingin)
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active, phone) VALUES
    ('rosa.martinez.caingin@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 3, 'Rosa', 'Martinez', 'Female', NOW(), TRUE, '09226789012'),
    ('miguel.torres.caingin@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 3, 'Miguel', 'Torres', 'Male', NOW(), TRUE, '09237890123'),
    ('carmen.flores@yahoo.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 3, 'Carmen', 'Flores', 'Female', NOW(), TRUE, '09248901234'),
    ('ricardo.santos.caingin@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 3, 'Ricardo', 'Santos', 'Male', NOW(), TRUE, '09259012345'),
    ('elena.cruz.caingin@gmail.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 3, 'Elena', 'Cruz', 'Female', NOW(), TRUE, '09260123456');

-- =====================================================
-- SECTION 3: PERSON PROFILES (INTERCONNECTED)
-- =====================================================

-- Tambubong Officials Persons
INSERT INTO persons (user_id, first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, education_level, occupation, monthly_income, years_of_residency, contact_number, resident_type) VALUES
    (11, 'Maria', 'Santos', 'Rodriguez', '1982-03-15', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'COLLEGE GRADUATE', 'Barangay Secretary', 25000.00, 15, '09171234501', 'REGULAR'),
    (12, 'Antonio', 'Cruz', 'Lopez', '1978-08-22', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'COLLEGE GRADUATE', 'Barangay Treasurer', 28000.00, 18, '09171234502', 'REGULAR'),
    (13, 'Carmen', 'Dela Cruz', 'Villanueva', '1985-12-10', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'COLLEGE GRADUATE', 'Barangay Councilor', 22000.00, 10, '09171234503', 'REGULAR'),
    (14, 'Roberto', 'Mendoza', 'Fernandez', '1980-04-18', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'HIGH SCHOOL GRADUATE', 'Barangay Councilor', 22000.00, 12, '09171234504', 'REGULAR'),
    (15, 'Elena', 'Garcia', 'Cruz', '1975-09-05', 'Manila', 'FEMALE', 'MARRIED', 'POST GRADUATE', 'Health Worker', 35000.00, 8, '09171234505', 'REGULAR');

-- Caingin Officials Persons
INSERT INTO persons (user_id, first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, education_level, occupation, monthly_income, years_of_residency, contact_number, resident_type) VALUES
    (16, 'Luz', 'Santos', 'Mercado', '1979-06-12', 'San Rafael, Bulacan', 'FEMALE', 'WIDOW/WIDOWER', 'COLLEGE GRADUATE', 'Barangay Secretary', 24000.00, 20, '09171234506', 'REGULAR'),
    (17, 'Jose', 'Cruz', 'Ramirez', '1983-11-28', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'COLLEGE GRADUATE', 'Barangay Treasurer', 27000.00, 16, '09171234507', 'REGULAR'),
    (18, 'Alma', 'Reyes', 'Torres', '1987-02-14', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'COLLEGE GRADUATE', 'Barangay Councilor', 21000.00, 8, '09171234508', 'REGULAR'),
    (19, 'Benjamin', 'Lopez', 'Aguilar', '1975-07-30', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'COLLEGE GRADUATE', 'Barangay Chairperson', 30000.00, 22, '09171234509', 'REGULAR'),
    (20, 'Rosa', 'Martinez', 'Delgado', '1981-10-08', 'Quezon City', 'FEMALE', 'MARRIED', 'COLLEGE GRADUATE', 'Health Worker', 32000.00, 6, '09171234510', 'REGULAR');

-- Tambubong Residents Persons
INSERT INTO persons (user_id, first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, education_level, occupation, monthly_income, years_of_residency, contact_number, resident_type) VALUES
    (21, 'Juan', 'Cruz', 'Santos', '1990-01-15', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'HIGH SCHOOL GRADUATE', 'Tricycle Driver', 15000.00, 25, '09171234567', 'REGULAR'),
    (22, 'Maria', 'Reyes', 'Garcia', '1988-05-20', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'COLLEGE LEVEL', 'Sari-sari Store Owner', 12000.00, 20, '09182345678', 'REGULAR'),
    (23, 'Pedro', 'Santos', 'Dela Cruz', '1965-03-08', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'ELEMENTARY GRADUATE', 'Farmer', 8000.00, 45, '09193456789', 'SENIOR'),
    (24, 'Ana', 'Garcia', 'Reyes', '1992-07-12', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'COLLEGE GRADUATE', 'Teacher', 25000.00, 18, '09204567890', 'REGULAR'),
    (25, 'Carlos', 'Torres', 'Mendoza', '1985-11-25', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'VOCATIONAL', 'Mechanic', 18000.00, 15, '09215678901', 'REGULAR');

-- Caingin Residents Persons
INSERT INTO persons (user_id, first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, education_level, occupation, monthly_income, years_of_residency, contact_number, resident_type) VALUES
    (26, 'Rosa', 'Santos', 'Martinez', '1989-04-18', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'HIGH SCHOOL GRADUATE', 'Seamstress', 10000.00, 22, '09226789012', 'REGULAR'),
    (27, 'Miguel', 'Cruz', 'Torres', '1987-08-30', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'COLLEGE GRADUATE', 'Engineer', 35000.00, 12, '09237890123', 'REGULAR'),
    (28, 'Carmen', 'Dela Cruz', 'Flores', '1970-12-02', 'San Rafael, Bulacan', 'FEMALE', 'WIDOW/WIDOWER', 'HIGH SCHOOL GRADUATE', 'Vendor', 6000.00, 35, '09248901234', 'SENIOR'),
    (29, 'Ricardo', 'Lopez', 'Santos', '1991-06-14', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'COLLEGE LEVEL', 'Security Guard', 16000.00, 10, '09259012345', 'REGULAR'),
    (30, 'Elena', 'Martinez', 'Cruz', '1993-09-22', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'COLLEGE GRADUATE', 'Nurse', 28000.00, 8, '09260123456', 'REGULAR');

-- Additional Residents without user accounts
INSERT INTO persons (first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, education_level, occupation, monthly_income, years_of_residency, contact_number, resident_type, pantawid_beneficiary, nhts_pr_listahanan) VALUES
    -- Tambubong families
    ('Josefa', 'Santos', 'Rivera', '1955-02-14', 'San Rafael, Bulacan', 'FEMALE', 'WIDOW/WIDOWER', 'ELEMENTARY LEVEL', 'Retired', 3000.00, 50, '09301234567', 'SENIOR', FALSE, TRUE),
    ('Manuel', 'Garcia', 'Cruz', '1958-07-19', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'HIGH SCHOOL LEVEL', 'Carpenter', 12000.00, 40, '09302345678', 'SENIOR', FALSE, FALSE),
    ('Linda', 'Torres', 'Santos', '1962-11-03', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'ELEMENTARY GRADUATE', 'Housewife', 0.00, 38, '09303456789', 'SENIOR', TRUE, TRUE),
    ('Roberto', 'Cruz', 'Villanueva', '1995-03-28', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'HIGH SCHOOL GRADUATE', 'Construction Worker', 14000.00, 12, '09304567890', 'REGULAR', FALSE, FALSE),
    ('Gloria', 'Mendoza', 'Reyes', '1980-09-15', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'COLLEGE LEVEL', 'Barangay Health Worker', 8000.00, 25, '09305678901', 'REGULAR', FALSE, FALSE),
    
    -- Caingin families  
    ('Fernando', 'Santos', 'Lopez', '1960-01-22', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'HIGH SCHOOL GRADUATE', 'Jeepney Driver', 16000.00, 35, '09306789012', 'SENIOR', FALSE, FALSE),
    ('Esperanza', 'Garcia', 'Martinez', '1963-05-17', 'San Rafael, Bulacan', 'FEMALE', 'MARRIED', 'ELEMENTARY GRADUATE', 'Housewife', 0.00, 33, '09307890123', 'SENIOR', TRUE, TRUE),
    ('Antonio', 'Reyes', 'Torres', '1988-12-11', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'VOCATIONAL', 'Electrician', 20000.00, 18, '09308901234', 'REGULAR', FALSE, FALSE),
    ('Maricel', 'Cruz', 'Aguilar', '1994-04-06', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'COLLEGE GRADUATE', 'Bank Teller', 22000.00, 8, '09309012345', 'REGULAR', FALSE, FALSE),
    ('Domingo', 'Torres', 'Delgado', '1975-08-13', 'San Rafael, Bulacan', 'MALE', 'MARRIED', 'HIGH SCHOOL GRADUATE', 'Fisherman', 10000.00, 28, '09310123456', 'REGULAR', TRUE, TRUE);

-- Children (under 18)
INSERT INTO persons (first_name, middle_name, last_name, birth_date, birth_place, gender, civil_status, education_level, years_of_residency, contact_number, resident_type) VALUES
    -- Tambubong children
    ('Miguel Jr.', 'Santos', 'Garcia', '2010-03-15', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'ELEMENTARY LEVEL', 14, '', 'REGULAR'),
    ('Sofia', 'Cruz', 'Santos', '2012-07-20', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'ELEMENTARY LEVEL', 12, '', 'REGULAR'),
    ('Juan Carlo', 'Reyes', 'Dela Cruz', '2015-01-10', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'ELEMENTARY LEVEL', 9, '', 'REGULAR'),
    ('Isabella', 'Garcia', 'Mendoza', '2008-09-25', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'HIGH SCHOOL LEVEL', 16, '', 'REGULAR'),
    
    -- Caingin children
    ('Luis', 'Santos', 'Martinez', '2011-05-18', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'ELEMENTARY LEVEL', 13, '', 'REGULAR'),
    ('Maria Luisa', 'Cruz', 'Torres', '2013-11-30', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'ELEMENTARY LEVEL', 11, '', 'REGULAR'),
    ('Carlos Eduardo', 'Dela Cruz', 'Santos', '2009-02-14', 'San Rafael, Bulacan', 'MALE', 'SINGLE', 'HIGH SCHOOL LEVEL', 15, '', 'REGULAR'),
    ('Anna Marie', 'Lopez', 'Cruz', '2016-06-08', 'San Rafael, Bulacan', 'FEMALE', 'SINGLE', 'ELEMENTARY LEVEL', 8, '', 'REGULAR');

-- =====================================================
-- SECTION 4: USER ROLES ASSIGNMENT
-- =====================================================

INSERT INTO user_roles (user_id, role_id, barangay_id, is_active, start_term_date, end_term_date) VALUES
    -- Tambubong Officials
    (11, 4, 32, TRUE, '2023-01-01', '2025-12-31'),  -- Secretary
    (12, 5, 32, TRUE, '2023-01-01', '2025-12-31'),  -- Treasurer
    (13, 6, 32, TRUE, '2023-01-01', '2025-12-31'),  -- Councilor 1
    (14, 6, 32, TRUE, '2023-01-01', '2025-12-31'),  -- Councilor 2
    (15, 9, 32, TRUE, NULL, NULL),                  -- Health Worker
    
    -- Caingin Officials
    (16, 4, 3, TRUE, '2023-01-01', '2025-12-31'),   -- Secretary
    (17, 5, 3, TRUE, '2023-01-01', '2025-12-31'),   -- Treasurer
    (18, 6, 3, TRUE, '2023-01-01', '2025-12-31'),   -- Councilor
    (19, 7, 3, TRUE, '2023-01-01', '2025-12-31'),   -- Chairperson
    (20, 9, 3, TRUE, NULL, NULL),                   -- Health Worker
    
    -- Regular Residents (All role_id 8 = resident)
    (21, 8, 32, TRUE, NULL, NULL), (22, 8, 32, TRUE, NULL, NULL),
    (23, 8, 32, TRUE, NULL, NULL), (24, 8, 32, TRUE, NULL, NULL),
    (25, 8, 32, TRUE, NULL, NULL), (26, 8, 3, TRUE, NULL, NULL),
    (27, 8, 3, TRUE, NULL, NULL), (28, 8, 3, TRUE, NULL, NULL),
    (29, 8, 3, TRUE, NULL, NULL), (30, 8, 3, TRUE, NULL, NULL);

-- =====================================================
-- SECTION 5: HOUSEHOLDS AND ADDRESSES
-- =====================================================

-- Tambubong Households
INSERT INTO households (household_number, barangay_id, purok_id, household_head_person_id, household_size) VALUES
    ('TAM-2024-001', 32, 1, 21, 4),  -- Juan Santos family
    ('TAM-2024-002', 32, 1, 22, 3),  -- Maria Garcia family
    ('TAM-2024-003', 32, 2, 23, 2),  -- Pedro Dela Cruz family
    ('TAM-2024-004', 32, 2, 24, 1),  -- Ana Reyes (single)
    ('TAM-2024-005', 32, 3, 25, 5),  -- Carlos Mendoza family
    ('TAM-2024-006', 32, 3, 31, 1),  -- Josefa Rivera (widow)
    ('TAM-2024-007', 32, 4, 32, 3),  -- Manuel Cruz family
    ('TAM-2024-008', 32, 4, 33, 2),  -- Linda Santos family
    ('TAM-2024-009', 32, 5, 34, 1),  -- Roberto Villanueva (single)
    ('TAM-2024-010', 32, 5, 35, 4);  -- Gloria Reyes family

-- Caingin Households
INSERT INTO households (household_number, barangay_id, purok_id, household_head_person_id, household_size) VALUES
    ('CAI-2024-001', 3, 6, 26, 4),   -- Rosa Martinez family
    ('CAI-2024-002', 3, 6, 27, 3),   -- Miguel Torres family  
    ('CAI-2024-003', 3, 7, 28, 1),   -- Carmen Flores (widow)
    ('CAI-2024-004', 3, 7, 29, 1),   -- Ricardo Santos (single)
    ('CAI-2024-005', 3, 8, 30, 1),   -- Elena Cruz (single)
    ('CAI-2024-006', 3, 8, 36, 3),   -- Fernando Lopez family
    ('CAI-2024-007', 3, 9, 37, 2),   -- Esperanza Martinez family
    ('CAI-2024-008', 3, 9, 38, 4),   -- Antonio Torres family
    ('CAI-2024-009', 3, 9, 39, 1),   -- Maricel Aguilar (single)
    ('CAI-2024-010', 3, 9, 40, 5);   -- Domingo Delgado family

-- Household Members (connecting persons to households)
INSERT INTO household_members (household_id, person_id, relationship_type_id, is_household_head, relationship_to_head) VALUES
    -- Tambubong households
    (1, 21, 1, TRUE, 'HEAD'),      -- Juan Santos
    (1, 22, 2, FALSE, 'SPOUSE'),   -- Maria Garcia (spouse)
    (1, 41, 3, FALSE, 'CHILD'),    -- Miguel Jr.
    (1, 42, 3, FALSE, 'CHILD'),    -- Sofia
    
    (2, 22, 1, TRUE, 'HEAD'),      -- Maria Garcia
    (2, 43, 3, FALSE, 'CHILD'),    -- Juan Carlo
    (2, 44, 3, FALSE, 'CHILD'),    -- Isabella
    
    (3, 23, 1, TRUE, 'HEAD'),      -- Pedro Dela Cruz
    (3, 33, 2, FALSE, 'SPOUSE'),   -- Linda Santos
    
    (4, 24, 1, TRUE, 'HEAD'),      -- Ana Reyes (single)
    
    (5, 25, 1, TRUE, 'HEAD'),      -- Carlos Mendoza
    (5, 35, 2, FALSE, 'SPOUSE'),   -- Gloria Reyes
    (5, 32, 4, FALSE, 'PARENT'),   -- Manuel Cruz (father)
    
    -- Caingin households
    (11, 26, 1, TRUE, 'HEAD'),     -- Rosa Martinez
    (11, 45, 3, FALSE, 'CHILD'),   -- Luis
    (11, 46, 3, FALSE, 'CHILD'),   -- Maria Luisa
    (11, 36, 2, FALSE, 'SPOUSE'),  -- Fernando Lopez
    
    (12, 27, 1, TRUE, 'HEAD'),     -- Miguel Torres
    (12, 47, 3, FALSE, 'CHILD'),   -- Carlos Eduardo
    (12, 38, 2, FALSE, 'SPOUSE'),  -- Antonio Torres
    
    (13, 28, 1, TRUE, 'HEAD'),     -- Carmen Flores (widow)
    
    (14, 29, 1, TRUE, 'HEAD'),     -- Ricardo Santos (single)
    
    (15, 30, 1, TRUE, 'HEAD'),     -- Elena Cruz (single)
    
    (20, 40, 1, TRUE, 'HEAD'),     -- Domingo Delgado
    (20, 37, 2, FALSE, 'SPOUSE'),  -- Esperanza Martinez
    (20, 48, 3, FALSE, 'CHILD');   -- Anna Marie

-- Addresses for all persons
INSERT INTO addresses (person_id, user_id, barangay_id, barangay_name, house_no, street, phase, municipality, province, region, residency_type, years_in_san_rafael, is_primary) VALUES
    -- Tambubong Officials
    (18, 11, 32, 'Tambubong', '15', 'Mabini Street', 'Phase 1', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 15, TRUE),
    (19, 12, 32, 'Tambubong', '22', 'Rizal Avenue', 'Phase 2', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 18, TRUE),
    (20, 13, 32, 'Tambubong', '8', 'Bonifacio Road', 'Phase 1', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 10, TRUE),
    (21, 14, 32, 'Tambubong', '31', 'Luna Street', 'Phase 3', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 12, TRUE),
    (22, 15, 32, 'Tambubong', '45', 'Aguinaldo Street', 'Phase 2', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 8, TRUE),
    
    -- Caingin Officials
    (23, 16, 3, 'Caingin', '12', 'Bayanihan Street', 'Purok A', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 20, TRUE),
    (24, 17, 3, 'Caingin', '28', 'Kamatayan Road', 'Purok B', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 16, TRUE),
    (25, 18, 3, 'Caingin', '7', 'Sampaguita Street', 'Purok A', 'SAN RAFAEL', 'BULACAN', 'III', 'Boarder', 8, TRUE),
    (26, 19, 3, 'Caingin', '19', 'Maharlika Highway', 'Purok C', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 22, TRUE),
    (27, 20, 3, 'Caingin', '33', 'Narra Street', 'Purok B', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 6, TRUE),
    
    -- Tambubong Residents
    (28, 21, 32, 'Tambubong', '101', 'Mabini Extension', NULL, 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 25, TRUE),
    (29, 22, 32, 'Tambubong', '202', 'Rizal Street', NULL, 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 20, TRUE),
    (30, 23, 32, 'Tambubong', '55', 'Rivera Compound', 'Phase 4', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 45, TRUE),
    (31, 24, 32, 'Tambubong', '78', 'Santos Street', 'Phase 5', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 18, TRUE),
    (32, 25, 32, 'Tambubong', '92', 'Mendoza Avenue', 'Phase 3', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 15, TRUE),
    
    -- Caingin Residents
    (33, 26, 3, 'Caingin', '456', 'Maligaya Street', 'Purok C', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 22, TRUE),
    (34, 27, 3, 'Caingin', '67', 'Engineers Village', 'Purok D', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 12, TRUE),
    (35, 28, 3, 'Caingin', '23', 'Flores Compound', 'Purok A', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 35, TRUE),
    (36, 29, 3, 'Caingin', '89', 'Security Village', 'Purok B', 'SAN RAFAEL', 'BULACAN', 'III', 'Boarder', 10, TRUE),
    (37, 30, 3, 'Caingin', '134', 'Medical Center Road', 'Purok C', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 8, TRUE),
    
    -- Additional residents without user accounts
    (38, NULL, 32, 'Tambubong', '44', 'Senior Street', 'Phase 1', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 50, TRUE),
    (39, NULL, 32, 'Tambubong', '66', 'Carpenter Lane', 'Phase 2', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 40, TRUE),
    (40, NULL, 32, 'Tambubong', '88', 'Housewife Street', 'Phase 3', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 38, TRUE),
    (41, NULL, 32, 'Tambubong', '111', 'Construction Road', 'Phase 4', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 12, TRUE),
    (42, NULL, 32, 'Tambubong', '133', 'Health Worker Lane', 'Phase 5', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 25, TRUE),
    
    (43, NULL, 3, 'Caingin', '77', 'Driver Avenue', 'Purok A', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 35, TRUE),
    (44, NULL, 3, 'Caingin', '99', 'Esperanza Street', 'Purok B', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 33, TRUE),
    (45, NULL, 3, 'Caingin', '122', 'Electrician Road', 'Purok C', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 18, TRUE),
    (46, NULL, 3, 'Caingin', '155', 'Bank Street', 'Purok D', 'SAN RAFAEL', 'BULACAN', 'III', 'Renter', 8, TRUE),
    (47, NULL, 3, 'Caingin', '177', 'Fisherman Lane', 'Purok A', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 28, TRUE),
    
    -- Children addresses (same as parents)
    (48, NULL, 32, 'Tambubong', '101', 'Mabini Extension', NULL, 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 14, TRUE),
    (49, NULL, 32, 'Tambubong', '202', 'Rizal Street', NULL, 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 12, TRUE),
    (50, NULL, 32, 'Tambubong', '55', 'Rivera Compound', 'Phase 4', 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 9, TRUE),
    (51, NULL, 32, 'Tambubong', '78', 'Santos Street', 'Phase 5', 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 16, TRUE),
    
    (52, NULL, 3, 'Caingin', '456', 'Maligaya Street', 'Purok C', 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 13, TRUE),
    (53, NULL, 3, 'Caingin', '67', 'Engineers Village', 'Purok D', 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 11, TRUE),
    (54, NULL, 3, 'Caingin', '89', 'Security Village', 'Purok B', 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 15, TRUE),
    (55, NULL, 3, 'Caingin', '177', 'Fisherman Lane', 'Purok A', 'SAN RAFAEL', 'BULACAN', 'III', 'Living-In', 8, TRUE);

-- =====================================================
-- SECTION 6: PERSON IDENTIFICATION
-- =====================================================

INSERT INTO person_identification (person_id, osca_id, gsis_id, sss_id, tin_id, philhealth_id) VALUES
    -- Senior Citizens (OSCA IDs)
    (30, 'OSCA-TAM-2024-001', NULL, '12-3456789-0', '123-456-789-000', 'PH-123456789'),
    (38, 'OSCA-TAM-2024-002', NULL, '12-4567890-1', '234-567-890-000', 'PH-234567890'),
    (35, 'OSCA-CAI-2024-001', NULL, '12-5678901-2', '345-678-901-000', 'PH-345678901'),
    (43, 'OSCA-CAI-2024-002', NULL, '12-6789012-3', '456-789-012-000', 'PH-456789012'),
    (44, 'OSCA-CAI-2024-003', NULL, '12-7890123-4', '567-890-123-000', 'PH-567890123'),
    
    -- Government employees (GSIS)
    (22, NULL, 'GSIS-001-234567', '12-8901234-5', '678-901-234-000', 'PH-678901234'),
    (31, NULL, 'GSIS-002-345678', '12-9012345-6', '789-012-345-000', 'PH-789012345'),
    (27, NULL, 'GSIS-003-456789', '12-0123456-7', '890-123-456-000', 'PH-890123456'),
    
    -- Regular residents (SSS)
    (28, NULL, NULL, '12-1234567-8', '901-234-567-000', 'PH-901234567'),
    (29, NULL, NULL, '12-2345678-9', '012-345-678-000', 'PH-012345678'),
    (32, NULL, NULL, '12-3456789-0', '123-456-789-111', 'PH-123456789'),
    (33, NULL, NULL, '12-4567890-1', '234-567-890-111', 'PH-234567890'),
    (34, NULL, NULL, '12-5678901-2', '345-678-901-111', 'PH-345678901'),
    (36, NULL, NULL, '12-6789012-3', '456-789-012-111', 'PH-456789012'),
    (37, NULL, NULL, '12-7890123-4', '567-890-123-111', 'PH-567890123');

-- =====================================================
-- SECTION 7: EMERGENCY CONTACTS
-- =====================================================

INSERT INTO emergency_contacts (person_id, contact_name, contact_number, contact_address, relationship) VALUES
    -- Tambubong residents
    (28, 'Maria Reyes Garcia', '09182345678', '202 Rizal Street, Tambubong', 'Spouse'),
    (29, 'Juan Cruz Santos', '09171234567', '101 Mabini Extension, Tambubong', 'Spouse'),
    (30, 'Ana Garcia Reyes', '09204567890', '78 Santos Street, Tambubong', 'Daughter'),
    (31, 'Carlos Torres Mendoza', '09215678901', '92 Mendoza Avenue, Tambubong', 'Brother'),
    (32, 'Gloria Mendoza Reyes', '09305678901', '133 Health Worker Lane, Tambubong', 'Spouse'),
    
    -- Caingin residents
    (33, 'Fernando Santos Lopez', '09306789012', '77 Driver Avenue, Caingin', 'Spouse'),
    (34, 'Carlos Eduardo Torres', '09308901234', '67 Engineers Village, Caingin', 'Son'),
    (35, 'Luis Santos Martinez', '09226789012', '456 Maligaya Street, Caingin', 'Son'),
    (36, 'Elena Martinez Cruz', '09260123456', '134 Medical Center Road, Caingin', 'Sister'),
    (37, 'Domingo Torres Delgado', '09310123456', '177 Fisherman Lane, Caingin', 'Spouse'),
    
    -- Senior citizens
    (38, 'Roberto Cruz Villanueva', '09304567890', '111 Construction Road, Tambubong', 'Son'),
    (43, 'Rosa Santos Martinez', '09226789012', '456 Maligaya Street, Caingin', 'Daughter'),
    (44, 'Antonio Reyes Torres', '09308901234', '122 Electrician Road, Caingin', 'Spouse');

-- =====================================================
-- SECTION 8: HEALTH INFORMATION AND CENSUS DATA
-- =====================================================

-- Person Health Information
INSERT INTO person_health_info (person_id, health_condition, has_maintenance, maintenance_details, high_cost_medicines, lack_medical_professionals, lack_health_insurance, other_health_concerns) VALUES
    -- Senior citizens with health issues
    (30, 'Hypertension, Diabetes', TRUE, 'Amlodipine 5mg daily, Metformin 500mg twice daily', TRUE, FALSE, FALSE, 'Difficulty walking long distances'),
    (38, 'Arthritis, High Blood Pressure', TRUE, 'Ibuprofen 400mg as needed, Losartan 50mg daily', TRUE, TRUE, FALSE, 'Joint pain especially during rainy season'),
    (35, 'Osteoporosis, Cataract', TRUE, 'Calcium supplements, Eye drops', FALSE, TRUE, TRUE, 'Blurred vision, frequent falls'),
    (43, 'Heart Disease, Kidney Problems', TRUE, 'Multiple cardiac medications, Dialysis 3x/week', TRUE, FALSE, FALSE, 'Requires frequent hospital visits'),
    (44, 'Depression, Insomnia', TRUE, 'Antidepressants, Sleep aids', FALSE, TRUE, TRUE, 'Social isolation, memory problems'),
    
    -- Adults with common conditions
    (28, 'Allergic Rhinitis', FALSE, NULL, FALSE, FALSE, TRUE, 'Seasonal allergies'),
    (29, 'Lower Back Pain', FALSE, NULL, FALSE, TRUE, FALSE, 'Work-related injury'),
    (32, 'Migraine', FALSE, NULL, TRUE, FALSE, FALSE, 'Stress-related headaches'),
    (33, 'Asthma', TRUE, 'Salbutamol inhaler as needed', FALSE, FALSE, FALSE, 'Triggered by dust and smoke'),
    (36, 'High Cholesterol', TRUE, 'Atorvastatin 20mg daily', FALSE, FALSE, FALSE, 'Family history of heart disease');

-- Person Income Sources
INSERT INTO person_income_sources (person_id, source_type_id, amount, details) VALUES
    -- Own earnings/salaries
    (28, 1, 15000.00, 'Tricycle driving'),
    (29, 1, 12000.00, 'Sari-sari store business'),
    (31, 1, 25000.00, 'Public school teaching'),
    (32, 1, 18000.00, 'Auto repair shop'),
    (33, 1, 10000.00, 'Tailoring services'),
    (34, 1, 35000.00, 'Civil engineering'),
    (36, 1, 16000.00, 'Jeepney driving'),
    (45, 1, 20000.00, 'Electrical services'),
    (46, 1, 22000.00, 'Banking sector'),
    
    -- Pensions
    (30, 2, 8000.00, 'Senior citizen pension'),
    (38, 2, 12000.00, 'Retired carpenter pension'),
    (43, 2, 6000.00, 'Widow pension'),
    
    -- Dependent on children/relatives
    (35, 4, NULL, 'Monthly support from working children'),
    (44, 4, NULL, 'Support from son working abroad'),
    
    -- Spouse salary
    (29, 5, 15000.00, 'Husband tricycle driver'),
    (40, 5, 10000.00, 'Wife seamstress'),
    (37, 5, 10000.00, 'Husband fisherman'),
    
    -- Rentals/sharecrops
    (32, 8, 5000.00, 'Small apartment rental'),
    (34, 8, 8000.00, 'House rental income'),
    
    -- Others
    (42, 11, 8000.00, 'Barangay health worker allowance'),
    (47, 11, 10000.00, 'Fishpond operations');

-- Person Assets
INSERT INTO person_assets (person_id, asset_type_id, details) VALUES
    -- House and lot owners
    (28, 2, 'Own family home with small lot'),
    (29, 2, 'Inherited family property'),
    (32, 2, 'Self-built house with rental apartment'),
    (33, 2, 'Family home with small garden'),
    (34, 2, 'Modern house in subdivision'),
    (36, 2, 'Old family home, well-maintained'),
    (39, 2, 'Carpenter-built family home'),
    (43, 2, 'Inherited ancestral home'),
    (45, 2, 'Recently purchased house'),
    
    -- House only
    (30, 1, 'Senior citizen housing'),
    (31, 1, 'Teacher quarters'),
    (38, 1, 'Senior housing unit'),
    
    -- Farmland
    (30, 3, '0.5 hectare rice field'),
    (47, 3, '1 hectare mixed crops'),
    (39, 3, '0.25 hectare vegetable garden'),
    
    -- Commercial building
    (29, 4, 'Small sari-sari store'),
    (32, 4, 'Auto repair shop'),
    (34, 4, 'Engineering office space'),
    
    -- Fishpond/Resort
    (47, 6, 'Small fishpond operation'),
    
    -- Others
    (28, 7, 'Tricycle unit'),
    (36, 7, 'Jeepney unit'),
    (32, 7, 'Auto repair equipment'),
    (33, 7, 'Sewing machines and equipment');

-- Person Living Arrangements
INSERT INTO person_living_arrangements (person_id, arrangement_type_id, details) VALUES
    -- With spouse
    (28, 2, 'Living with spouse and children'),
    (29, 2, 'Living with spouse only'),
    (32, 2, 'Nuclear family setup'),
    (33, 2, 'With husband and extended family'),
    (36, 2, 'With wife and children'),
    (39, 2, 'Elderly couple'),
    (45, 2, 'Newly married couple'),
    
    -- With children
    (30, 4, 'Living with adult children'),
    (38, 4, 'Supported by children'),
    (43, 4, 'Dependent on children'),
    
    -- With grandchildren
    (35, 5, 'Multi-generational household'),
    (44, 5, 'Caring for grandchildren'),
    
    -- With relatives
    (31, 8, 'Living with cousin family'),
    (37, 8, 'Extended family arrangement'),
    (46, 8, 'Boarding with relatives'),
    
    -- Alone
    (35, 1, 'Widow living independently'),
    (40, 1, 'Single professional');

-- Person Skills
INSERT INTO person_skills (person_id, skill_type_id, details) VALUES
    -- Medical skills
    (22, 1, 'Health worker certification'),
    (27, 1, 'Nursing degree and experience'),
    (37, 1, 'First aid training'),
    
    -- Teaching skills
    (31, 2, 'Elementary education degree'),
    (20, 2, 'Health education and training'),
    
    -- Vocational skills
    (28, 10, 'Automotive driving and mechanics'),
    (32, 10, 'Auto repair and maintenance'),
    (33, 10, 'Tailoring and dressmaking'),
    (39, 10, 'Carpentry and woodworking'),
    (45, 10, 'Electrical installation and repair'),
    (47, 10, 'Fishpond management'),
    
    -- Farming
    (30, 7, 'Rice farming techniques'),
    (40, 7, 'Vegetable gardening'),
    
    -- Cooking
    (29, 9, 'Food preparation and catering'),
    (35, 9, 'Traditional Filipino cooking'),
    (44, 9, 'Baking and pastry making'),
    
    -- Arts
    (33, 11, 'Traditional embroidery'),
    (46, 11, 'Handicraft making'),
    
    -- Engineering
    (34, 12, 'Civil engineering and construction'),
    
    -- Others
    (36, 13, 'Public transportation operation'),
    (38, 13, 'Senior citizen peer counseling'),
    (42, 13, 'Community health education');

-- Person Community Involvements
INSERT INTO person_involvements (person_id, involvement_type_id, details) VALUES
    -- Medical involvement
    (22, 1, 'Barangay health worker'),
    (27, 1, 'Community health volunteer'),
    
    -- Resource volunteer
    (31, 2, 'Education program volunteer'),
    (34, 2, 'Skills training instructor'),
    
    -- Community beautification
    (28, 3, 'Monthly cleanup drive participant'),
    (29, 3, 'Garden maintenance volunteer'),
    (36, 3, 'Street sweeping coordinator'),
    
    -- Community/Organizational leader
    (32, 4, 'Homeowners association president'),
    (43, 4, 'Senior citizen group leader'),
    (45, 4, 'Youth organization advisor'),
    
    -- Friendly visits
    (35, 6, 'Senior citizen companion'),
    (38, 6, 'Elderly check-up volunteer'),
    
    -- Neighborhood support services
    (29, 7, 'Emergency response team'),
    (39, 7, 'Disaster preparedness volunteer'),
    
    -- Religious
    (30, 8, 'Church lector and volunteer'),
    (33, 8, 'Prayer group leader'),
    (44, 8, 'Religious education teacher'),
    (46, 8, 'Church choir member'),
    
    -- Sponsorship
    (34, 10, 'Education scholarship sponsor'),
    (37, 10, 'Community event sponsor'),
    
    -- Others
    (40, 12, 'Barangay watch volunteer'),
    (42, 12, 'Community garden coordinator'),
    (47, 12, 'Livelihood training facilitator');

-- =====================================================
-- SECTION 9: DOCUMENT REQUESTS (INTERCONNECTED)
-- =====================================================

INSERT INTO document_requests (person_id, user_id, document_type_id, barangay_id, requested_by_user_id, status, price, purpose, request_date, delivery_method, payment_method, payment_status) VALUES
    -- Tambubong requests
    (28, 21, 1, 32, 11, 'completed', 50.00, 'Job application requirements', '2024-01-15 09:30:00', 'hardcopy', 'cash', 'paid'),
    (29, 22, 3, 32, 11, 'pending', 30.00, 'Bank account opening', '2024-02-01 14:15:00', 'hardcopy', 'cash', 'pending'),
    (30, 23, 4, 32, 11, 'completed', 0.00, 'Medical assistance application', '2024-01-20 10:45:00', 'hardcopy', 'cash', 'paid'),
    (31, 24, 2, 32, 11, 'completed', 0.00, 'First job application', '2024-02-05 11:30:00', 'softcopy', 'online', 'paid'),
    (32, 25, 6, 32, 11, 'processing', 100.00, 'Auto repair shop permit', '2024-02-10 08:20:00', 'hardcopy', 'cash', 'pending'),
    (38, NULL, 1, 32, 11, 'pending', 50.00, 'Senior citizen ID application', '2024-02-12 13:45:00', 'hardcopy', 'cash', 'pending'),
    (42, NULL, 3, 32, 11, 'completed', 30.00, 'Employment verification', '2024-01-25 16:20:00', 'hardcopy', 'cash', 'paid'),
    
    -- Caingin requests
    (33, 26, 1, 3, 16, 'completed', 50.00, 'Loan application requirement', '2024-01-18 09:15:00', 'hardcopy', 'cash', 'paid'),
    (34, 27, 3, 3, 16, 'pending', 30.00, 'Professional license renewal', '2024-02-03 14:30:00', 'softcopy', 'online', 'pending'),
    (35, 28, 4, 3, 16, 'completed', 0.00, 'Social services application', '2024-01-22 11:10:00', 'hardcopy', 'cash', 'paid'),
    (36, 29, 1, 3, 16, 'processing', 50.00, 'Jeepney franchise renewal', '2024-02-08 15:45:00', 'hardcopy', 'cash', 'pending'),
    (37, 30, 4, 3, 16, 'completed', 0.00, 'Widow pension application', '2024-01-28 10:30:00', 'hardcopy', 'cash', 'paid'),
    (43, NULL, 1, 3, 16, 'pending', 50.00, 'Jeepney driver license', '2024-02-11 12:15:00', 'hardcopy', 'cash', 'pending'),
    (45, NULL, 6, 3, 16, 'processing', 100.00, 'Electrical contractor permit', '2024-02-09 09:40:00', 'hardcopy', 'cash', 'pending'),
    (46, NULL, 3, 3, 16, 'completed', 30.00, 'Bank employment verification', '2024-01-30 14:50:00', 'softcopy', 'online', 'paid');

-- =====================================================
-- SECTION 10: BLOTTER CASES (REALISTIC SCENARIOS)
-- =====================================================

-- External participants for blotter cases
INSERT INTO external_participants (first_name, last_name, contact_number, address, age, gender) VALUES
    ('Mark', 'Gonzales', '09888111222', 'Unknown address, Neighboring barangay', 32, 'Male'),
    ('Susan', 'Bautista', '09777333444', 'Pantubig, San Rafael', 28, 'Female'),
    ('David', 'Ramos', '09666555777', 'Diliman, San Rafael', 35, 'Male'),
    ('Jenny', 'Morales', '09555888999', 'Libis, San Rafael', 26, 'Female'),
    ('Robert', 'Silva', '09444222333', 'Unknown address, San Rafael', 45, 'Male');

-- Tambubong Blotter Cases
INSERT INTO blotter_cases (case_number, incident_date, location, description, status, barangay_id, reported_by_person_id, assigned_to_user_id, filing_date, requires_dual_signature) VALUES
    ('TAM-2024-003', '2024-02-01 20:30:00', 'Mabini Extension, Tambubong', 'Loud music complaint - neighbor playing karaoke past 10 PM during weekdays', 'open', 32, 28, 9, '2024-02-02 08:15:00', FALSE),
    ('TAM-2024-004', '2024-02-05 14:15:00', 'Rizal Street, Tambubong', 'Property boundary dispute - fence allegedly built on neighboring lot', 'pending', 32, 29, NULL, '2024-02-05 14:30:00', TRUE),
    ('TAM-2024-005', '2024-02-08 16:45:00', 'Santos Street, Tambubong', 'Domestic violence case - husband physically abusing wife', 'open', 32, 31, 9, '2024-02-08 17:00:00', TRUE),
    ('TAM-2024-006', '2024-02-10 09:20:00', 'Mendoza Avenue, Tambubong', 'Theft complaint - motorcycle parts stolen from repair shop', 'pending', 32, 32, NULL, '2024-02-10 09:45:00', FALSE),
    ('TAM-2024-007', '2024-02-12 18:30:00', 'Senior Street, Tambubong', 'Elder abuse case - son neglecting elderly mother, not providing proper care', 'open', 32, 38, 9, '2024-02-12 19:00:00', TRUE);

-- Caingin Blotter Cases
INSERT INTO blotter_cases (case_number, incident_date, location, description, status, barangay_id, reported_by_person_id, assigned_to_user_id, filing_date, requires_dual_signature) VALUES
    ('CAI-2024-002', '2024-02-03 21:00:00', 'Maligaya Street, Caingin', 'Noise disturbance - construction work during prohibited hours', 'completed', 3, 33, 19, '2024-02-04 08:30:00', FALSE),
    ('CAI-2024-003', '2024-02-06 13:45:00', 'Engineers Village, Caingin', 'Parking dispute - neighbor blocking driveway access', 'open', 3, 34, 19, '2024-02-06 14:00:00', FALSE),
    ('CAI-2024-004', '2024-02-09 19:20:00', 'Flores Compound, Caingin', 'Family dispute - inheritance conflict between siblings', 'pending', 3, 35, NULL, '2024-02-09 19:45:00', TRUE),
    ('CAI-2024-005', '2024-02-11 15:30:00', 'Security Village, Caingin', 'Assault case - physical altercation between neighbors', 'open', 3, 36, 19, '2024-02-11 16:00:00', TRUE),
    ('CAI-2024-006', '2024-02-13 11:15:00', 'Driver Avenue, Caingin', 'Animal complaint - rooster causing disturbance early morning', 'pending', 3, 43, NULL, '2024-02-13 11:30:00', FALSE);

-- Blotter Participants
INSERT INTO blotter_participants (blotter_case_id, person_id, role, statement) VALUES
    -- TAM-2024-003 (Karaoke noise)
    (5, 28, 'complainant', 'Neighbor plays loud karaoke until midnight even on weekdays. We have small children who need to sleep early.'),
    (5, 29, 'respondent', 'We only sing during weekends and special occasions. The volume is not that loud.'),
    
    -- TAM-2024-004 (Property boundary)
    (6, 29, 'complainant', 'The new fence they built encroached 2 meters into my property. I have the old survey documents.'),
    (6, 32, 'respondent', 'We built the fence based on our property title. We are willing to have it re-surveyed.'),
    
    -- TAM-2024-005 (Domestic violence)
    (7, 31, 'complainant', 'My husband has been physically abusing me for months. I fear for my safety and my children.'),
    (7, 32, 'witness', 'I heard screaming and saw bruises on her arms. This has happened multiple times.'),
    
    -- TAM-2024-006 (Theft)
    (8, 32, 'complainant', 'Several motorcycle parts worth 15,000 pesos were stolen from my shop. I suspect it was an inside job.'),
    
    -- TAM-2024-007 (Elder abuse)
    (9, 38, 'complainant', 'My son rarely visits, doesn\'t provide food or medicine. I am left alone most days without proper care.'),
    (9, 41, 'respondent', 'I work two jobs to support my family. I send money for her needs but cannot visit daily.'),
    
    -- CAI-2024-002 (Construction noise)
    (10, 33, 'complainant', 'Construction work starts at 5 AM and continues past 8 PM. This violates barangay ordinance.'),
    (10, 45, 'respondent', 'We need to finish the project on time. We will adjust our working hours as requested.'),
    
    -- CAI-2024-003 (Parking dispute)
    (11, 34, 'complainant', 'Neighbor parks his car blocking our gate. We cannot get our vehicle out during emergencies.'),
    (11, 36, 'respondent', 'There is limited parking space. I am willing to discuss alternative parking arrangements.'),
    
    -- CAI-2024-004 (Inheritance)
    (12, 35, 'complainant', 'My siblings are trying to sell our family home without proper division of inheritance.'),
    (12, 43, 'respondent', 'The house needs major repairs. Selling is the most practical solution for all of us.'),
    
    -- CAI-2024-005 (Assault)
    (13, 36, 'complainant', 'Neighbor punched me during an argument about his dog destroying my garden.'),
    (13, 47, 'respondent', 'He first threw stones at my dog. I only defended myself when he became aggressive.'),
    
    -- CAI-2024-006 (Animal complaint)
    (14, 43, 'complainant', 'Neighbor\'s rooster crows at 4 AM every day. It wakes up the entire neighborhood.'),
    (14, 44, 'respondent', 'Roosters naturally crow in the morning. This is normal farm animal behavior.');

-- External participants in blotter cases
INSERT INTO blotter_participants (blotter_case_id, external_participant_id, role, statement) VALUES
    (7, 3, 'witness', 'I saw the husband hitting his wife in their yard. This is not the first time.'),
    (8, 4, 'witness', 'I saw suspicious individuals near the shop around midnight before the theft was discovered.'),
    (13, 5, 'witness', 'I saw both men fighting. It started as an argument but became physical quickly.');

-- Blotter Case Categories
INSERT INTO blotter_case_categories (blotter_case_id, category_id) VALUES
    (5, 8),   -- TAM-2024-003: Other cases
    (6, 8),   -- TAM-2024-004: Other cases  
    (7, 1),   -- TAM-2024-005: RA 9262 (VAWC) - Physical
    (8, 8),   -- TAM-2024-006: Other cases
    (9, 8),   -- TAM-2024-007: Other cases
    (10, 8),  -- CAI-2024-002: Other cases
    (11, 8),  -- CAI-2024-003: Other cases
    (12, 8),  -- CAI-2024-004: Other cases
    (13, 8),  -- CAI-2024-005: Other cases
    (14, 8);  -- CAI-2024-006: Other cases

-- Case Hearings
INSERT INTO case_hearings (blotter_case_id, hearing_date, hearing_type, hearing_notes, hearing_outcome, presided_by_user_id, hearing_number, presiding_officer_name, presiding_officer_position) VALUES
    -- Completed case
    (10, '2024-02-15 14:00:00', 'mediation', 'Both parties agreed to construction time limits 7 AM to 6 PM weekdays, no work on Sundays', 'resolved', 19, 1, 'Benjamin Lopez Aguilar', 'Barangay Chairperson'),
    
    -- Ongoing cases with hearings scheduled
    (5, '2024-02-20 14:00:00', 'mediation', 'Initial hearing to establish facts and attempt mediation', 'scheduled', 9, 1, 'Ricardo Morales', 'Chief Officer'),
    (7, '2024-02-22 10:00:00', 'initial', 'VAWC case requires careful handling and counseling', 'scheduled', 9, 1, 'Ricardo Morales', 'Chief Officer'),
    (11, '2024-02-21 15:00:00', 'conciliation', 'Parking arrangement discussion between neighbors', 'scheduled', 19, 1, 'Benjamin Lopez Aguilar', 'Barangay Chairperson'),
    (13, '2024-02-23 16:00:00', 'mediation', 'Assault case mediation with witness statements', 'scheduled', 19, 1, 'Benjamin Lopez Aguilar', 'Barangay Chairperson');

-- =====================================================
-- SECTION 11: EVENTS AND ACTIVITIES
-- =====================================================

INSERT INTO events (title, description, start_datetime, end_datetime, location, organizer, barangay_id, created_by_user_id, status, max_participants, registration_required, event_type, contact_person, contact_number, requirements) VALUES
    -- Tambubong Events
    ('Tambubong Monthly Cleanup Drive', 'Community-wide cleanup and beautification activity', '2024-03-02 06:00:00', '2024-03-02 10:00:00', 'Various streets in Tambubong', 'Barangay Council', 32, 11, 'scheduled', 100, TRUE, 'activity', 'Maria Rodriguez', '09171234501', 'Bring own cleaning materials, wear comfortable clothes'),
    ('Senior Citizens Health Fair', 'Free medical checkup and consultation for senior citizens', '2024-03-05 08:00:00', '2024-03-05 16:00:00', 'Tambubong Covered Court', 'Barangay Health Center', 32, 15, 'scheduled', 50, TRUE, 'seminar', 'Dr. Elena Cruz', '09171234505', 'Bring valid ID and health records'),
    ('Barangay Assembly Meeting', 'Monthly barangay assembly and community updates', '2024-03-10 19:00:00', '2024-03-10 21:00:00', 'Tambubong Barangay Hall', 'Barangay Council', 32, 4, 'scheduled', 200, FALSE, 'meeting', 'Juan Dela Cruz', '09171234500', 'Open to all residents'),
    ('Youth Skills Training Program', 'Livelihood skills training for out-of-school youth', '2024-03-15 09:00:00', '2024-03-17 16:00:00', 'Tambubong Multi-Purpose Center', 'TESDA-Barangay Partnership', 32, 13, 'scheduled', 30, TRUE, 'seminar', 'Carmen Villanueva', '09171234503', 'Ages 16-25, bring birth certificate'),
    ('Disaster Preparedness Drill', 'Earthquake and fire safety drill for the community', '2024-03-20 14:00:00', '2024-03-20 17:00:00', 'Tambubong Elementary School', 'Barangay Disaster Risk Reduction Team', 32, 14, 'scheduled', 300, FALSE, 'activity', 'Roberto Fernandez', '09171234504', 'Participation encouraged for all ages'),
    
    -- Caingin Events
    ('Caingin Farmers Market', 'Monthly local farmers and vendors market', '2024-03-03 05:00:00', '2024-03-03 12:00:00', 'Caingin Basketball Court', 'Farmers Association', 3, 16, 'scheduled', 80, TRUE, 'activity', 'Luz Mercado', '09171234506', 'Local farmers and vendors only'),
    ('Community Feeding Program', 'Free feeding for children and senior citizens', '2024-03-07 11:00:00', '2024-03-07 13:00:00', 'Caingin Day Care Center', 'Barangay Nutrition Committee', 3, 20, 'scheduled', 100, FALSE, 'activity', 'Rosa Delgado', '09171234510', 'Children 5 years old and below, senior citizens'),
    ('Livelihood Training for Women', 'Sewing and handicraft skills training for women', '2024-03-12 13:00:00', '2024-03-14 17:00:00', 'Caingin Women\'s Center', 'Women\'s Organization', 3, 18, 'scheduled', 25, TRUE, 'seminar', 'Alma Torres', '09171234508', 'Women 18 years old and above'),
    ('Barangay Fiesta Preparation Meeting', 'Planning meeting for annual barangay fiesta', '2024-03-18 18:00:00', '2024-03-18 20:00:00', 'Caingin Barangay Hall', 'Fiesta Committee', 3, 8, 'scheduled', 50, FALSE, 'meeting', 'Roberto Reyes', '09171234509', 'Committee members and volunteers'),
    ('Environmental Awareness Seminar', 'Proper waste segregation and environmental protection', '2024-03-25 14:00:00', '2024-03-25 17:00:00', 'Caingin Covered Court', 'Environmental Committee', 3, 17, 'scheduled', 150, FALSE, 'seminar', 'Jose Ramirez', '09171234507', 'Open to all residents');

-- Event Participants
INSERT INTO event_participants (event_id, person_id, attendance_status) VALUES
    -- Tambubong Cleanup Drive
    (5, 28, 'registered'), (5, 29, 'registered'), (5, 30, 'confirmed'), (5, 31, 'registered'), 
    (5, 32, 'confirmed'), (5, 38, 'registered'), (5, 39, 'confirmed'), (5, 41, 'registered'),
    
    -- Senior Citizens Health Fair  
    (6, 30, 'confirmed'), (6, 38, 'confirmed'), (6, 39, 'registered'), (6, 40, 'confirmed'),
    
    -- Youth Skills Training
    (8, 48, 'confirmed'), (8, 49, 'registered'), (8, 50, 'confirmed'), (8, 51, 'registered'),
    
    -- Caingin Farmers Market
    (10, 33, 'confirmed'), (10, 36, 'confirmed'), (10, 43, 'registered'), (10, 47, 'confirmed'),
    
    -- Community Feeding Program
    (11, 35, 'confirmed'), (11, 44, 'confirmed'), (11, 52, 'registered'), (11, 53, 'registered'), (11, 55, 'registered'),
    
    -- Livelihood Training for Women
    (12, 33, 'confirmed'), (12, 35, 'confirmed'), (12, 37, 'registered'), (12, 44, 'confirmed'), (12, 46, 'registered');

-- =====================================================
-- SECTION 12: BARANGAY SERVICES AND SETTINGS
-- =====================================================

-- Service Categories
INSERT INTO service_categories (barangay_id, name, description, icon, display_order, is_active) VALUES
    (32, 'Document Services', 'All document request and certification services', 'fa-file-text', 1, TRUE),
    (32, 'Health Services', 'Health and medical related services', 'fa-heartbeat', 2, TRUE),
    (32, 'Legal Services', 'Blotter, mediation and legal assistance', 'fa-gavel', 3, TRUE),
    (32, 'Community Services', 'Events, programs and community activities', 'fa-users', 4, TRUE),
    (32, 'Business Services', 'Business permits and commercial services', 'fa-briefcase', 5, TRUE),
    
    (3, 'Document Services', 'All document request and certification services', 'fa-file-text', 1, TRUE),
    (3, 'Health Services', 'Health and medical related services', 'fa-heartbeat', 2, TRUE),
    (3, 'Legal Services', 'Blotter, mediation and legal assistance', 'fa-gavel', 3, TRUE),
    (3, 'Agricultural Services', 'Farming and livelihood support services', 'fa-leaf', 4, TRUE),
    (3, 'Social Services', 'Social welfare and assistance programs', 'fa-heart', 5, TRUE);

-- Custom Services
INSERT INTO custom_services (category_id, barangay_id, name, description, detailed_guide, requirements, processing_time, fees, icon, display_order, is_active, service_type, priority_level) VALUES
    -- Tambubong Services
    (1, 32, 'Online Document Request', 'Submit document requests online with digital delivery option', 'Fill out online form, upload requirements, choose delivery method', 'Valid ID, supporting documents', '1-3 business days', 'Varies by document type', 'fa-laptop', 1, TRUE, 'digital', 'high'),
    (2, 32, 'Mobile Health Clinic', 'Health services brought directly to your purok', 'Schedule appointment through barangay health worker', 'Valid ID, health records if available', 'By appointment', 'Free for basic services', 'fa-ambulance', 1, TRUE, 'mobile', 'high'),
    (3, 32, 'Conflict Mediation', 'Professional mediation services for neighbor disputes', 'File complaint, attend scheduled hearing, reach agreement', 'Incident report, witness statements', '5-10 business days', 'Free', 'fa-handshake', 1, TRUE, 'legal', 'normal'),
    (4, 32, 'Community Wi-Fi Access', 'Free internet access for students and workers', 'Register at barangay hall, bring valid student/work ID', 'Valid ID, proof of residence', 'Same day', 'Free', 'fa-wifi', 1, TRUE, 'digital', 'normal'),
    (5, 32, 'Business Consultation', 'Free consultation for starting small businesses', 'Schedule appointment with business development officer', 'Business plan draft, valid ID', '1 week', 'Free', 'fa-lightbulb', 1, TRUE, 'consultation', 'normal'),
    
    -- Caingin Services
    (6, 3, 'Agricultural Extension', 'Technical assistance for farmers and gardeners', 'Contact agricultural officer, schedule field visit', 'Farm/garden location details', '3-5 business days', 'Free', 'fa-seedling', 1, TRUE, 'agricultural', 'high'),
    (7, 3, 'Senior Citizen Support', 'Assistance program for elderly residents', 'Register at barangay office, submit requirements', 'OSCA ID, medical certificate if needed', '5 business days', 'Free', 'fa-user-plus', 1, TRUE, 'social', 'high'),
    (8, 3, 'Blotter Case Filing', 'File complaints and legal cases online', 'Submit complaint form, upload evidence', 'Incident details, witness information', '1-2 business days', 'Free', 'fa-clipboard-list', 1, TRUE, 'legal', 'urgent'),
    (9, 3, 'Livelihood Training', 'Skills development and livelihood programs', 'Apply for training slots, attend orientation', 'Valid ID, commitment letter', '2-4 weeks', 'Free with materials fee', 'fa-tools', 1, TRUE, 'educational', 'normal'),
    (10, 3, 'Emergency Response', '24/7 emergency assistance and coordination', 'Call emergency hotline or visit barangay hall', 'Emergency nature, contact information', 'Immediate', 'Free', 'fa-exclamation-triangle', 1, TRUE, 'emergency', 'urgent');

-- Update Barangay Settings with more details
UPDATE barangay_settings SET 
    barangay_captain_name = 'Juan Dela Cruz',
    local_barangay_contact = '0917-555-1234',
    pnp_contact = '0917-555-5678',
    bfp_contact = '0917-555-9012'
WHERE barangay_id = 32;

UPDATE barangay_settings SET 
    barangay_captain_name = 'Roberto Reyes',
    local_barangay_contact = '0917-555-1122',
    pnp_contact = '0917-555-3344',
    bfp_contact = '0917-555-5566'
WHERE barangay_id = 3;

-- =====================================================
-- SECTION 13: NOTIFICATIONS AND AUDIT TRAILS
-- =====================================================

-- System Notifications
INSERT INTO notifications (user_id, type, title, message, related_table, related_id, is_read, priority, created_at) VALUES
    -- Document request notifications
    (21, 'document_request', 'Document Request Approved', 'Your barangay clearance request has been approved and is ready for pickup.', 'document_requests', 7, FALSE, 'medium', '2024-02-14 09:30:00'),
    (22, 'document_request', 'Document Request Processing', 'Your proof of residency request is being processed.', 'document_requests', 8, TRUE, 'low', '2024-02-01 14:30:00'),
    (24, 'document_request', 'Document Request Completed', 'Your first time job seeker certificate is ready for download.', 'document_requests', 10, FALSE, 'medium', '2024-02-06 16:45:00'),
    
    -- Blotter case notifications
    (21, 'blotter_case', 'Hearing Scheduled', 'A hearing for your noise complaint case has been scheduled for February 20, 2024.', 'blotter_cases', 5, FALSE, 'high', '2024-02-15 11:00:00'),
    (24, 'blotter_case', 'Case Filed', 'Your domestic violence case has been filed and assigned for investigation.', 'blotter_cases', 7, TRUE, 'urgent', '2024-02-08 17:15:00'),
    (26, 'blotter_case', 'Case Resolved', 'Your noise disturbance case has been resolved through mediation.', 'blotter_cases', 10, FALSE, 'medium', '2024-02-15 15:30:00'),
    
    -- Event notifications
    (21, 'event', 'Event Registration Confirmed', 'You are registered for the Tambubong Monthly Cleanup Drive on March 2, 2024.', 'events', 5, TRUE, 'low', '2024-02-20 10:00:00'),
    (23, 'event', 'Health Fair Reminder', 'Senior Citizens Health Fair is tomorrow. Don\'t forget to bring your health records.', 'events', 6, FALSE, 'medium', '2024-03-04 18:00:00'),
    (26, 'event', 'Training Confirmation', 'You have been accepted for the Livelihood Training for Women program.', 'events', 12, FALSE, 'high', '2024-03-08 14:20:00'),
    
    -- Official notifications
    (4, 'system', 'Monthly Report Due', 'Monthly blotter case report for January 2024 is due for submission.', 'monthly_reports', NULL, TRUE, 'high', '2024-02-01 08:00:00'),
    (8, 'system', 'Case Assignment', 'New assault case has been assigned to you for investigation.', 'blotter_cases', 13, FALSE, 'urgent', '2024-02-11 16:15:00'),
    (11, 'document_request', 'Bulk Processing', '5 new document requests received today requiring your review.', 'document_requests', NULL, FALSE, 'medium', '2024-02-13 09:00:00');

-- Comprehensive Audit Trails
INSERT INTO audit_trails (user_id, action, table_name, record_id, description, ip_address, user_agent, action_timestamp) VALUES
    -- Document processing actions
    (11, 'APPROVE', 'document_requests', '7', 'Approved barangay clearance for Juan Santos', '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 09:15:00'),
    (11, 'CREATE', 'document_requests', '8', 'New proof of residency request from Maria Garcia', '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-01 14:15:00'),
    (16, 'COMPLETE', 'document_requests', '13', 'Completed indigency certificate for Carmen Flores', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-01-28 11:00:00'),
    
    -- Blotter case actions
    (9, 'CREATE', 'blotter_cases', '5', 'Filed new noise complaint case', '192.168.1.103', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-02 08:15:00'),
    (9, 'SCHEDULE', 'blotter_cases', '5', 'Scheduled hearing for karaoke noise case', '192.168.1.103', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-15 10:30:00'),
    (19, 'RESOLVE', 'blotter_cases', '10', 'Resolved construction noise case through mediation', '192.168.1.104', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-15 15:00:00'),
    (19, 'ASSIGN', 'blotter_cases', '13', 'Assigned assault case for investigation', '192.168.1.104', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-11 16:00:00'),
    
    -- User management actions
    (1, 'CREATE', 'users', '25', 'Created new resident account for Carlos Mendoza', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-10 14:30:00'),
    (2, 'UPDATE', 'user_roles', '15', 'Updated role assignment for health worker', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-12 16:45:00'),
    
    -- Event management actions
    (11, 'CREATE', 'events', '5', 'Created Monthly Cleanup Drive event', '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-25 13:20:00'),
    (15, 'CREATE', 'events', '6', 'Created Senior Citizens Health Fair', '192.168.1.105', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-26 09:15:00'),
    (16, 'UPDATE', 'events', '10', 'Updated Farmers Market event details', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-28 11:30:00'),
    
    -- Data export and reporting actions
    (4, 'EXPORT', 'document_requests', 'ALL', 'Exported monthly document requests report', '192.168.1.106', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-01 10:00:00'),
    (8, 'EXPORT', 'blotter_cases', 'ALL', 'Exported quarterly blotter cases report', '192.168.1.107', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-01 11:30:00'),
    (2, 'VIEW', 'audit_trails', 'ALL', 'Reviewed system audit logs', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-13 15:20:00');

-- =====================================================
-- SECTION 14: MONTHLY REPORTS AND STATISTICS
-- =====================================================

-- Monthly Reports
INSERT INTO monthly_reports (barangay_id, report_month, report_year, created_by_user_id, prepared_by_user_id, submitted_at) VALUES
    (32, 1, 2024, 4, 9, '2024-02-05 16:30:00'),  -- Tambubong January report
    (3, 1, 2024, 8, 19, '2024-02-03 14:45:00'),  -- Caingin January report
    (32, 2, 2024, 4, 9, NULL),                   -- Tambubong February report (in progress)
    (3, 2, 2024, 8, 19, NULL);                   -- Caingin February report (in progress)

-- Monthly Report Details
INSERT INTO monthly_report_details (monthly_report_id, category_id, total_cases, total_pnp, total_court, total_issued_bpo, total_medical) VALUES
    -- Tambubong January 2024
    (1, 1, 1, 0, 0, 0, 1),  -- RA 9262 (VAWC) - Physical: 1 case, 1 medical referral
    (1, 8, 6, 1, 0, 0, 0),  -- Other cases: 6 cases, 1 PNP referral
    
    -- Caingin January 2024  
    (2, 8, 4, 0, 0, 0, 0),  -- Other cases: 4 cases, all resolved locally
    
    -- Tambubong February 2024 (partial)
    (3, 1, 1, 0, 0, 1, 1),  -- RA 9262 (VAWC) - Physical: 1 case, 1 BPO issued, 1 medical
    (3, 8, 3, 0, 0, 0, 0),  -- Other cases: 3 cases
    
    -- Caingin February 2024 (partial)
    (4, 8, 2, 0, 0, 0, 0);  -- Other cases: 2 cases

-- =====================================================
-- SECTION 15: ADDITIONAL CENSUS AND HEALTH DATA
-- =====================================================

-- Child Information (for minors in the system)
INSERT INTO child_information (person_id, attending_school, school_name, grade_level, school_type, pantawid_beneficiary, immunization_complete, garantisadong_pambata, under_six_years, grade_school) VALUES
    (48, TRUE, 'Tambubong Elementary School', 'Grade 4', 'Public', TRUE, FALSE, TRUE, FALSE, TRUE),
    (49, TRUE, 'Tambubong Elementary School', 'Grade 2', 'Public', TRUE, TRUE, TRUE, FALSE, TRUE),
    (50, TRUE, 'Tambubong Elementary School', 'Kinder', 'Public', FALSE, FALSE, TRUE, TRUE, FALSE),
    (51, TRUE, 'San Rafael National High School', 'Grade 10', 'Public', TRUE, FALSE, FALSE, FALSE, FALSE),
    (52, TRUE, 'Caingin Elementary School', 'Grade 3', 'Public', TRUE, TRUE, TRUE, FALSE, TRUE),
    (53, TRUE, 'Caingin Elementary School', 'Grade 1', 'Public', TRUE, FALSE, TRUE, FALSE, TRUE),
    (54, TRUE, 'San Rafael National High School', 'Grade 9', 'Public', TRUE, FALSE, FALSE, FALSE, FALSE),
    (55, FALSE, NULL, NULL, 'Day Care', FALSE, TRUE, TRUE, TRUE, FALSE);

-- Person Economic Problems
INSERT INTO person_economic_problems (person_id, loss_income, unemployment, skills_training, skills_training_details, livelihood, livelihood_details, other_economic, other_economic_details) VALUES
    (30, TRUE, FALSE, TRUE, 'Interested in senior-friendly livelihood training', TRUE, 'Small-scale vegetable gardening', FALSE, NULL),
    (38, FALSE, TRUE, TRUE, 'Carpentry skills update and power tools training', FALSE, NULL, TRUE, 'Limited physical capacity due to age'),
    (35, TRUE, TRUE, TRUE, 'Sewing and tailoring skills enhancement', TRUE, 'Home-based tailoring business', FALSE, NULL),
    (40, FALSE, FALSE, TRUE, 'Food processing and preservation', TRUE, 'Food vending business', FALSE, NULL),
    (43, TRUE, FALSE, FALSE, NULL, TRUE, 'Jeepney driving - need vehicle financing', TRUE, 'High fuel costs affecting income'),
    (44, FALSE, FALSE, TRUE, 'Computer literacy and online selling', TRUE, 'Online business opportunities', FALSE, NULL),
    (29, FALSE, FALSE, FALSE, NULL, TRUE, 'Expand sari-sari store inventory', TRUE, 'Competition from other stores'),
    (33, FALSE, FALSE, TRUE, 'Modern sewing techniques and fashion design', FALSE, NULL, FALSE, NULL);

-- Person Social Problems  
INSERT INTO person_social_problems (person_id, loneliness, isolation, neglect, recreational, senior_friendly, other_social, other_social_details) VALUES
    (30, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, NULL),
    (38, TRUE, FALSE, TRUE, TRUE, TRUE, TRUE, 'Son works far away, limited family support'),
    (35, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE, NULL),
    (43, FALSE, FALSE, FALSE, TRUE, TRUE, FALSE, NULL),
    (44, TRUE, TRUE, FALSE, TRUE, TRUE, TRUE, 'Widow, children live in other provinces'),
    (40, FALSE, TRUE, FALSE, TRUE, FALSE, TRUE, 'Limited social interaction due to work schedule'),
    (42, FALSE, FALSE, FALSE, TRUE, FALSE, TRUE, 'Work stress, need for recreational activities');

-- Person Health Problems
INSERT INTO person_health_problems (person_id, condition_illness, condition_illness_details, high_cost_medicine, lack_medical_professionals, lack_sanitation, lack_health_insurance, inadequate_health_services, other_health, other_health_details) VALUES
    (30, TRUE, 'Hypertension, Diabetes Type 2', TRUE, FALSE, FALSE, FALSE, TRUE, FALSE, NULL),
    (38, TRUE, 'Arthritis, Hypertension', TRUE, TRUE, FALSE, FALSE, TRUE, TRUE, 'Need regular physical therapy'),
    (35, TRUE, 'Osteoporosis, Cataract', FALSE, TRUE, FALSE, TRUE, TRUE, FALSE, NULL),
    (43, TRUE, 'Heart Disease, Kidney problems', TRUE, FALSE, FALSE, FALSE, FALSE, TRUE, 'Requires dialysis 3x weekly'),
    (44, TRUE, 'Depression, Insomnia', FALSE, TRUE, FALSE, TRUE, TRUE, TRUE, 'Mental health services not readily available'),
    (28, FALSE, NULL, FALSE, FALSE, FALSE, TRUE, FALSE, TRUE, 'Work-related back pain'),
    (29, TRUE, 'Allergic Rhinitis', FALSE, FALSE, FALSE, FALSE, FALSE, FALSE, NULL),
    (33, TRUE, 'Asthma', FALSE, FALSE, FALSE, FALSE, TRUE, FALSE, NULL),
    (36, FALSE, NULL, FALSE, FALSE, FALSE, TRUE, FALSE, TRUE, 'High blood pressure, no regular checkup');

-- Person Housing Problems
INSERT INTO person_housing_problems (person_id, overcrowding, no_permanent_housing, independent_living, lost_privacy, squatters, other_housing, other_housing_details) VALUES
    (35, FALSE, FALSE, TRUE, FALSE, FALSE, TRUE, 'House needs major repairs, roof leaking'),
    (40, TRUE, FALSE, FALSE, TRUE, FALSE, FALSE, NULL),
    (41, FALSE, TRUE, FALSE, FALSE, FALSE, TRUE, 'Renting small room, wants own place'),
    (44, FALSE, FALSE, TRUE, FALSE, FALSE, TRUE, 'Living alone, house too big to maintain'),
    (46, FALSE, FALSE, TRUE, FALSE, FALSE, TRUE, 'Temporary boarding arrangement'),
    (29, FALSE, FALSE, FALSE, FALSE, FALSE, TRUE, 'Need house expansion for growing family'),
    (33, TRUE, FALSE, FALSE, TRUE, FALSE, FALSE, NULL);

-- Person Community Problems
INSERT INTO person_community_problems (person_id, desire_participate, skills_to_share, other_community, other_community_details) VALUES
    (30, TRUE, TRUE, FALSE, NULL),
    (38, TRUE, TRUE, TRUE, 'Wants to teach carpentry skills to youth'),
    (35, TRUE, TRUE, FALSE, NULL),
    (28, TRUE, TRUE, TRUE, 'Vehicle maintenance workshops'),
    (29, TRUE, TRUE, TRUE, 'Small business management training'),
    (32, TRUE, TRUE, TRUE, 'Auto repair training for unemployed'),
    (33, TRUE, TRUE, TRUE, 'Sewing classes for women'),
    (34, TRUE, TRUE, TRUE, 'Engineering and construction consultation'),
    (36, TRUE, TRUE, TRUE, 'Driver safety and traffic awareness'),
    (42, TRUE, TRUE, TRUE, 'Health education and first aid training'),
    (45, TRUE, TRUE, TRUE, 'Electrical safety and basic wiring'),
    (46, TRUE, TRUE, TRUE, 'Financial literacy and banking services'),
    (47, TRUE, TRUE, TRUE, 'Fish farming and aquaculture techniques');

-- Government Programs Participation
INSERT INTO government_programs (person_id, nhts_pr_listahanan, indigenous_people, pantawid_beneficiary) VALUES
    (30, FALSE, FALSE, FALSE),
    (35, TRUE, FALSE, FALSE),
    (38, TRUE, FALSE, FALSE),
    (40, TRUE, FALSE, TRUE),
    (43, FALSE, FALSE, FALSE),
    (44, TRUE, FALSE, TRUE),
    (49, TRUE, FALSE, TRUE),
    (52, TRUE, FALSE, TRUE),
    (55, TRUE, FALSE, TRUE);

-- Family Composition (detailed family structures)
INSERT INTO family_composition (household_id, person_id, name, relationship, age, civil_status, occupation, monthly_income) VALUES
    -- Household 1 (Juan Santos family)
    (1, 28, 'Juan Cruz Santos', 'HEAD', 34, 'MARRIED', 'Tricycle Driver', 15000.00),
    (1, 29, 'Maria Reyes Garcia', 'SPOUSE', 36, 'MARRIED', 'Sari-sari Store Owner', 12000.00),
    (1, 48, 'Miguel Jr. Santos Garcia', 'CHILD', 14, 'SINGLE', 'Student', 0.00),
    (1, 49, 'Sofia Cruz Santos', 'CHILD', 12, 'SINGLE', 'Student', 0.00),
    
    -- Household 3 (Pedro Dela Cruz family)
    (3, 30, 'Pedro Santos Dela Cruz', 'HEAD', 59, 'MARRIED', 'Farmer', 8000.00),
    (3, 40, 'Linda Torres Santos', 'SPOUSE', 54, 'MARRIED', 'Housewife', 0.00),
    
    -- Household 5 (Carlos Mendoza family)
    (5, 32, 'Carlos Torres Mendoza', 'HEAD', 39, 'MARRIED', 'Mechanic', 18000.00),
    (5, 42, 'Gloria Mendoza Reyes', 'SPOUSE', 44, 'MARRIED', 'Barangay Health Worker', 8000.00),
    (5, 39, 'Manuel Garcia Cruz', 'FATHER', 66, 'MARRIED', 'Carpenter', 12000.00),
    (5, 50, 'Juan Carlo Reyes Dela Cruz', 'CHILD', 9, 'SINGLE', 'Student', 0.00),
    (5, 51, 'Isabella Garcia Reyes', 'CHILD', 16, 'SINGLE', 'Student', 0.00),
    
    -- Household 11 (Rosa Martinez family)
    (11, 33, 'Rosa Santos Martinez', 'HEAD', 35, 'MARRIED', 'Seamstress', 10000.00),
    (11, 43, 'Fernando Santos Lopez', 'SPOUSE', 64, 'MARRIED', 'Jeepney Driver', 16000.00),
    (11, 52, 'Luis Santos Martinez', 'CHILD', 13, 'SINGLE', 'Student', 0.00),
    (11, 53, 'Maria Luisa Cruz Torres', 'CHILD', 11, 'SINGLE', 'Student', 0.00),
    
    -- Household 12 (Miguel Torres family)
    (12, 34, 'Miguel Cruz Torres', 'HEAD', 37, 'MARRIED', 'Engineer', 35000.00),
    (12, 45, 'Antonio Reyes Torres', 'BROTHER', 36, 'MARRIED', 'Electrician', 20000.00),
    (12, 54, 'Carlos Eduardo Torres', 'CHILD', 15, 'SINGLE', 'Student', 0.00),
    
    -- Household 20 (Domingo Delgado family)
    (20, 47, 'Domingo Torres Delgado', 'HEAD', 49, 'MARRIED', 'Fisherman', 10000.00),
    (20, 44, 'Esperanza Garcia Martinez', 'SPOUSE', 61, 'MARRIED', 'Housewife', 0.00),
    (20, 55, 'Anna Marie Lopez Cruz', 'CHILD', 8, 'SINGLE', 'Student', 0.00),
    (20, 46, 'Maricel Cruz Aguilar', 'DAUGHTER', 30, 'SINGLE', 'Bank Teller', 22000.00),
    (20, 37, 'Elena Martinez Cruz', 'DAUGHTER-IN-LAW', 31, 'SINGLE', 'Nurse', 28000.00);

-- =====================================================
-- SECTION 16: PAYMENT SYSTEM AND DOCUMENT TRACKING
-- =====================================================

-- Barangay PayMongo Settings
INSERT INTO barangay_paymongo_settings (barangay_id, is_enabled, public_key, test_mode) VALUES
    (32, TRUE, 'pk_test_tambubong_123456789', TRUE),
    (3, TRUE, 'pk_test_caingin_987654321', TRUE);

-- Document Request Restrictions (First Time Job Seeker tracking)
INSERT INTO document_request_restrictions (person_id, document_type_code, first_requested_at, request_count) VALUES
    (31, 'first_time_job_seeker', '2024-02-05 11:30:00', 1),
    (48, 'first_time_job_seeker', '2024-01-15 14:20:00', 1),
    (54, 'first_time_job_seeker', '2024-01-28 09:45:00', 1);

-- Schedule Proposals for ongoing cases
INSERT INTO schedule_proposals (blotter_case_id, proposed_by_user_id, proposed_by_role_id, proposed_date, proposed_time, hearing_location, presiding_officer, presiding_officer_position, status, complainant_confirmed, respondent_confirmed, created_at) VALUES
    (6, 11, 4, '2024-02-25', '14:00:00', 'Tambubong Barangay Hall Conference Room', 'Juan Dela Cruz', 'Barangay Captain', 'pending_user_confirmation', FALSE, FALSE, '2024-02-15 10:30:00'),
    (8, 11, 4, '2024-02-26', '15:30:00', 'Tambubong Barangay Hall Conference Room', 'Ricardo Morales', 'Chief Officer', 'proposed', FALSE, FALSE, '2024-02-13 14:15:00'),
    (12, 16, 4, '2024-02-24', '10:00:00', 'Caingin Barangay Hall', 'Benjamin Lopez Aguilar', 'Barangay Chairperson', 'pending_captain_approval', TRUE, FALSE, '2024-02-14 09:20:00'),
    (14, 16, 4, '2024-02-27', '16:00:00', 'Caingin Barangay Hall', 'Benjamin Lopez Aguilar', 'Barangay Chairperson', 'proposed', FALSE, FALSE, '2024-02-14 11:45:00');

-- Case Notifications
INSERT INTO case_notifications (blotter_case_id, notified_user_id, notification_type, is_read) VALUES
    (6, 4, 'signature_required', FALSE),
    (7, 4, 'signature_required', TRUE),
    (8, 4, 'case_filed', FALSE),
    (9, 4, 'signature_required', FALSE),
    (12, 8, 'signature_required', TRUE),
    (13, 8, 'case_accepted', FALSE),
    (14, 8, 'case_filed', FALSE);

-- Email Logs
INSERT INTO email_logs (to_email, subject, template_used, sent_at, status, blotter_case_id) VALUES
    ('juan.santos.tambubong@gmail.com', 'Hearing Schedule Notification', 'hearing_notice', '2024-02-15 14:30:00', 'sent', 5),
    ('ana.reyes.tambubong@gmail.com', 'Domestic Violence Case Support', 'vawc_support', '2024-02-08 18:00:00', 'sent', 7),
    ('rosa.martinez.caingin@gmail.com', 'Case Resolution Confirmation', 'case_resolved', '2024-02-15 16:00:00', 'sent', 10),
    ('miguel.torres.caingin@gmail.com', 'Mediation Schedule', 'mediation_notice', '2024-02-20 10:15:00', 'sent', 11),
    ('maria.garcia.tambubong@gmail.com', 'Document Ready for Pickup', 'document_ready', '2024-02-01 15:30:00', 'sent', NULL),
    ('elena.cruz.caingin@gmail.com', 'Event Registration Confirmed', 'event_confirmation', '2024-03-08 09:00:00', 'sent', NULL);

-- Participant Notifications (detailed)
INSERT INTO participant_notifications (blotter_case_id, participant_id, delivery_method, delivery_status, delivery_address, email_address, phone_number, notification_type, sent_at, confirmed, confirmation_token) VALUES
    (5, 1, 'email', 'delivered', NULL, 'juan.santos.tambubong@gmail.com', '09171234567', 'hearing_notice', '2024-02-15 14:30:00', TRUE, 'TOKEN_001'),
    (5, 2, 'sms', 'delivered', NULL, 'maria.garcia.tambubong@gmail.com', '09182345678', 'hearing_notice', '2024-02-15 14:35:00', FALSE, 'TOKEN_002'),
    (7, 3, 'personal', 'delivered', '78 Santos Street, Tambubong', 'ana.reyes.tambubong@gmail.com', '09204567890', 'summons', '2024-02-20 10:00:00', TRUE, 'TOKEN_003'),
    (11, 7, 'email', 'delivered', NULL, 'miguel.torres.caingin@gmail.com', '09237890123', 'hearing_notice', '2024-02-20 11:00:00', TRUE, 'TOKEN_004'),
    (11, 8, 'sms', 'pending', NULL, 'ricardo.santos.caingin@gmail.com', '09259012345', 'hearing_notice', '2024-02-20 11:05:00', FALSE, 'TOKEN_005');

-- Additional Audit Trails for recent activities
INSERT INTO audit_trails (user_id, action, table_name, record_id, description, ip_address, user_agent, action_timestamp) VALUES
    -- Recent document processing
    (11, 'UPDATE', 'document_requests', '15', 'Updated status to processing for business permit', '192.168.1.101', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 10:30:00'),
    (16, 'APPROVE', 'document_requests', '16', 'Approved widow pension certificate for Esperanza Martinez', '192.168.1.102', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 11:15:00'),
    
    -- Recent case management
    (9, 'UPDATE', 'blotter_cases', '7', 'Added dual signature requirement for VAWC case', '192.168.1.103', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 12:00:00'),
    (19, 'CREATE', 'schedule_proposals', '3', 'Proposed hearing schedule for inheritance dispute', '192.168.1.104', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 09:20:00'),
    
    -- System maintenance
    (1, 'BACKUP', 'database', 'ALL', 'Performed weekly database backup', '192.168.1.100', 'System/Automated', '2024-02-14 02:00:00'),
    (2, 'UPDATE', 'barangay_settings', '32', 'Updated Tambubong contact information', '192.168.1.100', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 13:45:00'),
    
    -- Event management
    (15, 'UPDATE', 'events', '6', 'Updated health fair participant limit to 60', '192.168.1.105', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 14:20:00'),
    (18, 'CREATE', 'event_participants', '25', 'Registered Maria Santos for livelihood training', '192.168.1.108', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', '2024-02-14 15:30:00');

-- Password History (for security tracking)
INSERT INTO password_history (user_id, password_hash, created_at) VALUES
    (4, '$2y$10$OldPasswordHash1', '2024-01-01 00:00:00'),
    (8, '$2y$10$OldPasswordHash2', '2024-01-15 00:00:00'),
    (11, '$2y$10$OldPasswordHash3', '2024-02-01 00:00:00'),
    (16, '$2y$10$OldPasswordHash4', '2024-02-01 00:00:00');

-- =====================================================
-- SECTION 17: FINAL STATISTICS AND SUMMARY DATA
-- =====================================================

-- Update case status and completion
UPDATE blotter_cases SET status = 'completed', resolved_at = '2024-02-15 15:30:00', resolution_details = 'Both parties agreed to construction time restrictions. Case resolved through successful mediation.' WHERE id = 10;

-- Update some document requests to completed status
UPDATE document_requests SET status = 'completed', completed_at = '2024-02-14 16:00:00', processed_by_user_id = 11 WHERE id IN (7, 10, 13, 16);
UPDATE document_requests SET status = 'processing', processed_by_user_id = 11 WHERE id IN (15, 17);

-- Update event participant attendance
UPDATE event_participants SET attendance_status = 'attended' WHERE event_id = 10 AND person_id IN (33, 36, 43, 47);
	