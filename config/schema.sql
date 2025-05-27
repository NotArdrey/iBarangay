-- Drop the database if it exists and create a new one
DROP DATABASE IF EXISTS barangay;
CREATE DATABASE barangay;
USE barangay;

/*-------------------------------------------------------------
  SECTION 1: BARANGAY AND REFERENCE TABLES
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

/*-------------------------------------------------------------
  SECTION 2: USER AND ROLE MANAGEMENT
  -------------------------------------------------------------*/

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

-- Main users table (authentication and basic info) - MODIFIED FOR PHP COMPATIBILITY
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15) UNIQUE,
    role_id INT DEFAULT 8,
    barangay_id INT DEFAULT 1,
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

-- Person information (personal data separate from users)
CREATE TABLE persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE, -- NULL for non-system users
    census_id VARCHAR(50) UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    middle_name VARCHAR(50),
    last_name VARCHAR(50) NOT NULL,
    suffix VARCHAR(20),
    birth_date DATE NOT NULL,
    birth_place VARCHAR(255),
    gender ENUM('Male', 'Female', 'Others') NOT NULL,
    civil_status ENUM('Single', 'Married', 'Widowed', 'Separated', 'Widow/Widower') NOT NULL,
    citizenship VARCHAR(100) DEFAULT 'Filipino',
    religion VARCHAR(100),
    education_level VARCHAR(100),
    occupation VARCHAR(255),
    monthly_income DECIMAL(10,2),
    contact_number VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE persons 
CHANGE COLUMN census_id id_number VARCHAR(50) UNIQUE;

INSERT INTO persons (
    user_id,
    id_number,
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
    contact_number
) VALUES (
    NULL,
    '4503-1961-7095-4067',
    'Lance Jefferson',
    'Ramos',
    'Uy',
    NULL,
    '2005-02-18',
    'Calumpit, Bulacan',
    'Male',
    'Single',
    'Filipino',
    'Roman Catholic',
    'College Student',
    'Student',
    5000.00,
    '+639171234568'
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

-- Official ID and image data
CREATE TABLE person_identification (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    id_image_path VARCHAR(255),
    selfie_image_path VARCHAR(255),
    signature_image_path VARCHAR(255),
    signature_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Address information (normalized) - MODIFIED FOR PHP COMPATIBILITY
CREATE TABLE addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    user_id INT, -- Added for PHP compatibility
    barangay_id INT NOT NULL,
    house_no VARCHAR(50),
    street VARCHAR(100),
    subdivision VARCHAR(100),
    block_lot VARCHAR(50),
    phase VARCHAR(50),
    residency_type ENUM('Home Owner', 'Renter', 'Boarder', 'Living-In') NOT NULL,
    years_in_san_rafael INT,
    is_primary BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
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

-- Household information
CREATE TABLE households (
    id VARCHAR(50) PRIMARY KEY,
    barangay_id INT NOT NULL,
    household_head_person_id INT,
    household_size INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (household_head_person_id) REFERENCES persons(id) ON DELETE SET NULL
);

-- Person-Household relationship
CREATE TABLE household_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_id VARCHAR(50) NOT NULL,
    person_id INT NOT NULL,
    relationship_to_head VARCHAR(50),
    is_household_head BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_household_person (household_id, person_id),
    FOREIGN KEY (household_id) REFERENCES households(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Assets and properties
CREATE TABLE person_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    asset_type ENUM('House', 'House & Lot', 'Farmland', 'Commercial Building', 'Lot', 'Fishpond/Resort', 'Others') NOT NULL,
    asset_description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Income sources
CREATE TABLE income_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    source_type ENUM('Own earnings/salary/wages', 'Own pension', 'Stocks/Dividends', 'Dependent on children/relatives', 'Spouse salary', 'Insurance', 'Spouse pension', 'Rentals/Sharecrops', 'Savings', 'Livestock/Orchards', 'Others') NOT NULL,
    source_details VARCHAR(255),
    amount DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Living arrangements
CREATE TABLE living_arrangements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    arrangement_type ENUM('Alone', 'Spouse', 'Care Institution', 'Children', 'Common Law Spouse', 'Grandchildren', 'Househelp', 'In laws', 'Relatives', 'Others') NOT NULL,
    details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Senior specific health information
CREATE TABLE senior_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    condition_description TEXT,
    has_maintenance BOOLEAN DEFAULT FALSE,
    maintenance_details VARCHAR(255),
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

-- Skills and specializations
CREATE TABLE person_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    skill_type ENUM('Medical', 'Teaching', 'Legal Services', 'Dental', 'Counseling', 'Evangelization', 'Farming', 'Fishing', 'Cooking', 'Vocational', 'Arts', 'Engineering', 'Others') NOT NULL,
    skill_details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Community involvement
CREATE TABLE community_involvements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    involvement_type ENUM('Medical', 'Resource Volunteer', 'Community Beautification', 'Community/Organizational Leader', 'Dental', 'Friendly Visits', 'Neighborhood Support Services', 'Religious', 'Counseling/referral', 'Sponsorship', 'Legal Services', 'Others') NOT NULL,
    involvement_details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Housing concerns
CREATE TABLE housing_concerns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    concern_type ENUM('Overcrowding', 'No permanent housing', 'Longing for independent living', 'Lost privacy', 'Living in squatter area', 'High cost rent', 'Others') NOT NULL,
    concern_details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Economic concerns
CREATE TABLE economic_concerns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    concern_type ENUM('Lack of income/resources', 'Loss of income/resources', 'Skills/Capability Training', 'Livelihood opportunities', 'Others') NOT NULL,
    concern_details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Emotional concerns
CREATE TABLE emotional_concerns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    concern_type ENUM('Feeling of neglect & rejection', 'Feeling of helplessness & worthlessness', 'Feeling of loneliness & isolation', 'Inadequate leisure/recreational activities', 'Senior Citizen Friendly Environment', 'Others') NOT NULL,
    concern_details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Child-specific information
CREATE TABLE child_information (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL UNIQUE,
    is_malnourished BOOLEAN DEFAULT FALSE,
    school_name VARCHAR(255),
    grade_level VARCHAR(50),
    school_type ENUM('Public', 'Private', 'ALS', 'Day Care', 'SNP', 'Not Attending') DEFAULT 'Not Attending',
    immunization_complete BOOLEAN DEFAULT FALSE,
    is_pantawid_beneficiary BOOLEAN DEFAULT FALSE,
    has_timbang_operation BOOLEAN DEFAULT FALSE,
    has_feeding_program BOOLEAN DEFAULT FALSE,
    has_supplementary_feeding BOOLEAN DEFAULT FALSE,
    in_caring_institution BOOLEAN DEFAULT FALSE,
    is_under_foster_care BOOLEAN DEFAULT FALSE,
    is_directly_entrusted BOOLEAN DEFAULT FALSE,
    is_legally_adopted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Child health conditions
CREATE TABLE child_health_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    condition_type ENUM('Malaria', 'Dengue', 'Pneumonia', 'Tuberculosis', 'Diarrhea') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

-- Child disabilities
CREATE TABLE child_disabilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    disability_type ENUM('Blind/Visually Impaired', 'Hearing Impairment', 'Speech/Communication', 'Orthopedic/Physical', 'Intellectual/Learning', 'Psychosocial') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);

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

-- Insert sample settings for a few barangays
INSERT INTO barangay_settings (barangay_id, barangay_captain_name) VALUES
    (32, 'Juan Dela Cruz'),  -- Tambubong 
    (18, 'Maria Santos'),    -- Pantubig
    (3, 'Roberto Reyes');    -- Caingin

/*-------------------------------------------------------------
  SECTION 3: DOCUMENT REQUEST SYSTEM
  -------------------------------------------------------------*/

-- Document types - SIMPLIFIED FOR COMPATIBILITY
CREATE TABLE document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type_id INT,
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
    ('Barangay Clearance', 'Barangay Clearance', 'barangay_clearance', 'Required for employment, business permits, and various transactions.', 50.00),
    ('Certificate of Indigency', 'Certificate of Indigency', 'barangay_indigency', 'For accessing social welfare programs and financial assistance.', 20.00),
    ('Certificate of Residency', 'Certificate of Residency', 'proof_of_residency', 'Official proof of residence in the barangay.', 30.00),
    ('First Time Job Seeker', 'First Time Job Seeker', 'first_time_job_seeker', 'Certification for first-time job seekers.', 0.00),
    ('Community Tax Certificate (Cedula)', 'Community Tax Certificate', 'cedula', 'Annual tax certificate required for government transactions.', 55.00),
    ('Business Permit Clearance', 'Business Permit Clearance', 'business_permit_clearance', 'Barangay clearance required for business license applications.', 500.00),
    ('Community Tax Certificate', 'Community Tax Certificate', 'community_tax_certificate', 'Annual tax certificate for residents and corporations.', 6000.00);



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
    document_request_id INT,
    person_id INT NOT NULL,
    user_id INT, -- Added for PHP compatibility
    document_type_id INT NOT NULL,
    barangay_id INT NOT NULL,
    status ENUM('pending', 'processing', 'for_payment', 'paid', 'for_pickup', 'completed', 'cancelled', 'rejected') DEFAULT 'pending',
    remarks TEXT,
    proof_image_path VARCHAR(255) NULL,
    requested_by_user_id INT, -- who made the request in the system
    processed_by_user_id INT, -- who processed the request
    completed_at DATETIME,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
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
  SECTION 4: PAYMENT SYSTEM
  -------------------------------------------------------------*/


/*-------------------------------------------------------------
  SECTION 5: BLOTTER/CASE MANAGEMENT
  -------------------------------------------------------------*/

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
    person_id INT,
    first_name VARCHAR(50),
    last_name VARCHAR(50),
    contact_number VARCHAR(20),
    address VARCHAR(255),
    age INT,
    gender VARCHAR(50),
    role ENUM('complainant', 'respondent', 'witness') NOT NULL,
    statement TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_person_role (blotter_case_id, person_id, role),
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL
);

-- Case-category relationship (normalized)
CREATE TABLE blotter_case_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    category_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_case_category (blotter_case_id, category_id),
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES case_categories(id) ON DELETE CASCADE
);

-- Case-intervention relationship (normalized)
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

-- Event management - SIMPLIFIED FOR COMPATIBILITY
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT,
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

-- Monthly report details by category (normalized)
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

-- System activity auditing - SIMPLIFIED FOR COMPATIBILITY
CREATE TABLE audit_trails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audit_id INT,
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
  SECTION 7: INDEXES FOR PERFORMANCE
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
CREATE INDEX idx_persons_id_number ON persons(id_number);
CREATE INDEX idx_persons_name ON persons(last_name, first_name);
CREATE INDEX idx_persons_birth_date ON persons(birth_date);

-- Address indexes
CREATE INDEX idx_addresses_person_id ON addresses(person_id);
CREATE INDEX idx_addresses_user_id ON addresses(user_id);
CREATE INDEX idx_addresses_barangay_id ON addresses(barangay_id);
CREATE INDEX idx_addresses_primary ON addresses(is_primary);

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
CREATE INDEX idx_audit_trails_created_at ON audit_trails(created_at);



DROP TRIGGER IF EXISTS users_audit_insert;
DROP TRIGGER IF EXISTS users_audit_update;
DROP TRIGGER IF EXISTS document_requests_audit_update;
DROP TRIGGER IF EXISTS blotter_cases_audit_insert;
DROP TRIGGER IF EXISTS blotter_cases_audit_update;

DELIMITER //

-- Updated audit trigger for users table with descriptions
CREATE TRIGGER users_audit_insert AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO audit_trails (user_id, action, table_name, record_id, new_values, description)
    VALUES (NEW.id, 'INSERT', 'users', NEW.id, 
            CONCAT('email:', NEW.email), 
            CONCAT('New user created: ', NEW.first_name, ' ', NEW.last_name, ' (', NEW.email, ')'));
END //

CREATE TRIGGER users_audit_update AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    
    -- Build description based on what changed
    IF OLD.email != NEW.email THEN
        SET desc_text = CONCAT(desc_text, 'Email changed from ', OLD.email, ' to ', NEW.email, '; ');
    END IF;
    
    IF OLD.is_active != NEW.is_active THEN
        SET desc_text = CONCAT(desc_text, 'Account status changed to ', 
                              CASE WHEN NEW.is_active = 1 THEN 'Active' ELSE 'Inactive' END, '; ');
    END IF;
    
    IF OLD.role_id != NEW.role_id THEN
        SET desc_text = CONCAT(desc_text, 'Role changed; ');
    END IF;
    
    -- Remove trailing semicolon and space
    SET desc_text = TRIM(TRAILING '; ' FROM desc_text);
    
    IF desc_text = '' THEN
        SET desc_text = CONCAT('User profile updated: ', NEW.first_name, ' ', NEW.last_name);
    END IF;

    INSERT INTO audit_trails (user_id, action, table_name, record_id, old_values, new_values, description)
    VALUES (NEW.id, 'UPDATE', 'users', NEW.id, 
            CONCAT('email:', OLD.email, ',is_active:', OLD.is_active),
            CONCAT('email:', NEW.email, ',is_active:', NEW.is_active),
            desc_text);
END //

-- Updated audit trigger for document requests with descriptions
CREATE TRIGGER document_requests_audit_update AFTER UPDATE ON document_requests
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    DECLARE doc_type_name VARCHAR(100) DEFAULT '';
    
    -- Get document type name
    SELECT name INTO doc_type_name FROM document_types WHERE id = NEW.document_type_id LIMIT 1;
    
    IF OLD.status != NEW.status THEN
        SET desc_text = CONCAT('Document request status changed from "', 
                              UPPER(REPLACE(OLD.status, '_', ' ')), '" to "', 
                              UPPER(REPLACE(NEW.status, '_', ' ')), '" for ', 
                              COALESCE(doc_type_name, 'document'));
        
        -- Add specific context for certain status changes
        CASE NEW.status
            WHEN 'processing' THEN SET desc_text = CONCAT(desc_text, ' - Request is now being processed');
            WHEN 'completed' THEN SET desc_text = CONCAT(desc_text, ' - Document is ready for pickup');
            WHEN 'cancelled' THEN SET desc_text = CONCAT(desc_text, ' - Request has been cancelled');
            WHEN 'rejected' THEN SET desc_text = CONCAT(desc_text, ' - Request was rejected');
            ELSE BEGIN END;
        END CASE;
        
        INSERT INTO audit_trails (user_id, action, table_name, record_id, old_values, new_values, description)
        VALUES (COALESCE(NEW.processed_by_user_id, NEW.requested_by_user_id), 
                'STATUS_CHANGE', 'document_requests', NEW.id,
                CONCAT('status:', OLD.status),
                CONCAT('status:', NEW.status),
                desc_text);
    END IF;
END //

-- Updated audit trigger for blotter cases with descriptions
CREATE TRIGGER blotter_cases_audit_insert AFTER INSERT ON blotter_cases
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    
    SET desc_text = CONCAT('New blotter case created: ', 
                          COALESCE(NEW.case_number, 'Pending Case Number'),
                          ' - ', COALESCE(SUBSTRING(NEW.description, 1, 100), 'No description'));
    
    IF LENGTH(NEW.description) > 100 THEN
        SET desc_text = CONCAT(desc_text, '...');
    END IF;

    INSERT INTO audit_trails (user_id, action, table_name, record_id, new_values, description)
    VALUES (COALESCE(NEW.assigned_to_user_id, 1), 'INSERT', 'blotter_cases', NEW.id, 
            CONCAT('case_number:', COALESCE(NEW.case_number, 'NULL'), ',status:', NEW.status),
            desc_text);
END //

CREATE TRIGGER blotter_cases_audit_update AFTER UPDATE ON blotter_cases
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    
    -- Build description based on what changed
    IF OLD.status != NEW.status THEN
        SET desc_text = CONCAT('Case status updated from "', 
                              UPPER(OLD.status), '" to "', 
                              UPPER(NEW.status), '" for case ', 
                              COALESCE(NEW.case_number, 'Pending'));
    END IF;
    
    IF OLD.resolution_details != NEW.resolution_details THEN
        IF desc_text != '' THEN
            SET desc_text = CONCAT(desc_text, '; ');
        END IF;
        SET desc_text = CONCAT(desc_text, 'Resolution details updated');
    END IF;
    
    IF OLD.assigned_to_user_id != NEW.assigned_to_user_id THEN
        IF desc_text != '' THEN
            SET desc_text = CONCAT(desc_text, '; ');
        END IF;
        SET desc_text = CONCAT(desc_text, 'Case reassigned to different officer');
    END IF;
    
    IF desc_text = '' THEN
        SET desc_text = CONCAT('Case updated: ', COALESCE(NEW.case_number, 'Pending'));
    END IF;

    INSERT INTO audit_trails (user_id, action, table_name, record_id, old_values, new_values, description)
    VALUES (COALESCE(NEW.assigned_to_user_id, OLD.assigned_to_user_id, 1), 
            'UPDATE', 'blotter_cases', NEW.id,
            CONCAT('status:', OLD.status, ',resolution:', COALESCE(OLD.resolution_details, 'NULL')),
            CONCAT('status:', NEW.status, ',resolution:', COALESCE(NEW.resolution_details, 'NULL')),
            desc_text);
END //

-- Additional triggers for other important tables

-- Document requests insert trigger
CREATE TRIGGER document_requests_audit_insert AFTER INSERT ON document_requests
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    DECLARE doc_type_name VARCHAR(100) DEFAULT '';
    DECLARE person_name VARCHAR(100) DEFAULT '';
    
    -- Get document type name
    SELECT name INTO doc_type_name FROM document_types WHERE id = NEW.document_type_id LIMIT 1;
    
    -- Get person name
    SELECT CONCAT(first_name, ' ', last_name) INTO person_name 
    FROM persons WHERE id = NEW.person_id LIMIT 1;
    
    SET desc_text = CONCAT('New document request created: ', 
                          COALESCE(doc_type_name, 'Unknown Document'),
                          ' for ', COALESCE(person_name, 'Unknown Person'));

    INSERT INTO audit_trails (user_id, action, table_name, record_id, new_values, description)
    VALUES (COALESCE(NEW.requested_by_user_id, 1), 'INSERT', 'document_requests', NEW.id,
            CONCAT('document_type_id:', NEW.document_type_id, ',status:', NEW.status),
            desc_text);
END //

-- Persons audit trigger
CREATE TRIGGER persons_audit_insert AFTER INSERT ON persons
FOR EACH ROW
BEGIN
    INSERT INTO audit_trails (user_id, action, table_name, record_id, new_values, description)
    VALUES (COALESCE(NEW.user_id, 1), 'INSERT', 'persons', NEW.id,
            CONCAT('name:', NEW.first_name, ' ', NEW.last_name),
            CONCAT('New person record created: ', NEW.first_name, ' ', NEW.last_name));
END //

CREATE TRIGGER persons_audit_update AFTER UPDATE ON persons
FOR EACH ROW
BEGIN
    DECLARE desc_text TEXT DEFAULT '';
    
    SET desc_text = CONCAT('Person record updated: ', NEW.first_name, ' ', NEW.last_name);
    
    -- Add specific changes
    IF OLD.first_name != NEW.first_name OR OLD.last_name != NEW.last_name THEN
        SET desc_text = CONCAT(desc_text, ' - Name changed');
    END IF;
    
    IF OLD.contact_number != NEW.contact_number THEN
        SET desc_text = CONCAT(desc_text, ' - Contact number updated');
    END IF;

    INSERT INTO audit_trails (user_id, action, table_name, record_id, old_values, new_values, description)
    VALUES (COALESCE(NEW.user_id, 1), 'UPDATE', 'persons', NEW.id,
            CONCAT('name:', OLD.first_name, ' ', OLD.last_name),
            CONCAT('name:', NEW.first_name, ' ', NEW.last_name),
            desc_text);
END //

DELIMITER ;


-- ... previous schema setup ...

-- Insert sample users
INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active) VALUES
    ('programmer@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 1, 1, 'System', 'Programmer', 'Male', NOW(), TRUE),
    ('superadmin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 2, 1, 'Super', 'Administrator', 'Female', NOW(), TRUE),
    ('barangayadmin@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 4, 32, 'Barangay', 'Administrator', 'Male', NOW(), TRUE),
    ('resident1@barangay.com', '$2y$10$YavXAnllLC3VCF8R0eVxXeWu/.mawVifHel6BYiU2H5oxCz8nfMIm', 8, 32, 'Test', 'Resident', 'Male', NOW(), TRUE);

-- Insert sample persons for the users
INSERT INTO persons (user_id, first_name, last_name, birth_date, gender, civil_status) VALUES
    (1, 'System', 'Programmer', '1990-01-01', 'Male', 'Single'),
    (2, 'Super', 'Administrator', '1985-01-01', 'Female', 'Married'),
    (3, 'Barangay', 'Administrator', '1980-01-01', 'Male', 'Married'),
    (4, 'Test', 'Resident', '1990-01-01', 'Male', 'Single');

-- Insert user roles after users and persons are created
INSERT INTO user_roles (user_id, role_id, barangay_id, is_active) VALUES
    (1, 1, 1, TRUE),    -- Programmer role
    (2, 2, 1, TRUE),    -- Super admin role
    (3, 4, 32, TRUE),   -- Barangay secretary role in Tambubong
    (4, 8, 32, TRUE);   -- Resident role

-- Now insert into audit_trails AFTER users are created
INSERT INTO audit_trails (user_id, action, table_name, record_id, description) VALUES
    (3, 'LOGIN', 'users', '3', 'User logged into the system'),
    (3, 'VIEW', 'document_requests', '1', 'Viewed document request details'),
    (3, 'EXPORT', 'audit_trails', 'ALL', 'Exported audit trail report'),
    (3, 'FILTER', 'audit_trails', 'ALL', 'Applied filters to audit trail view');

INSERT INTO persons (first_name, middle_name, last_name, birth_date, gender, civil_status, occupation, contact_number) VALUES
    ('Juan', 'Santos', 'Dela Cruz', '1980-05-15', 'Male', 'Married', 'Farmer', '09123456789'),
    ('Maria', 'Garcia', 'Santos', '1985-08-20', 'Female', 'Married', 'Teacher', '09987654321'),
    ('Pedro', 'Ramos', 'Gonzales', '1975-12-10', 'Male', 'Single', 'Driver', '09111222333'),
    ('Ana', 'Flores', 'Reyes', '1990-03-25', 'Female', 'Single', 'Nurse', '09444555666'),
    ('Jose', 'Miguel', 'Torres', '1970-07-08', 'Male', 'Widowed', 'Retired', '09777888999');

-- Insert sample addresses for residents
INSERT INTO addresses (person_id, user_id, barangay_id, house_no, street, residency_type, years_in_san_rafael, is_primary) VALUES
    (5, NULL, 32, '123', 'Mabini Street', 'Home Owner', 15, TRUE),
    (6, NULL, 18, '456', 'Rizal Avenue', 'Renter', 8, TRUE),
    (7, NULL, 3, '789', 'Luna Street', 'Home Owner', 20, TRUE),
    (8, NULL, 32, '321', 'Bonifacio Road', 'Boarder', 3, TRUE),
    (9, NULL, 18, '654', 'Aguinaldo Street', 'Home Owner', 12, TRUE);

-- Insert sample document requests
INSERT INTO document_requests (person_id, user_id, document_type_id, barangay_id, requested_by_user_id, status) VALUES
    (5, NULL, 1, 32, 3, 'pending'),
    (6, NULL, 3, 18, 3, 'processing'),
    (7, NULL, 4, 3, 3, 'completed'),
    (8, NULL, 2, 32, 3, 'for_payment'),
    (9, NULL, 5, 18, 3, 'cancelled');
    
-- Insert sample blotter cases
INSERT INTO blotter_cases (case_number, incident_date, location, description, status, barangay_id, reported_by_person_id, assigned_to_user_id) VALUES
    ('TAM-2024-0001', '2024-01-15 14:30:00', 'Mabini Street, Tambubong', 'Noise complaint against neighbor', 'open', 32, 5, 3),
    ('PAN-2024-0001', '2024-01-20 09:15:00', 'Rizal Avenue, Pantubig', 'Property boundary dispute', 'pending', 18, 6, 3),
    ('CAI-2024-0001', '2024-01-25 16:45:00', 'Luna Street, Caingin', 'Family dispute mediation', 'closed', 3, 7, 3);

-- Insert sample blotter participants
INSERT INTO blotter_participants (blotter_case_id, person_id, role, statement) VALUES
    (1, 5, 'complainant', 'Neighbor is playing loud music past 10 PM'),
    (1, 8, 'respondent', 'We were just celebrating a birthday'),
    (2, 6, 'complainant', 'Neighbor built fence on my property'),
    (3, 7, 'complainant', 'Need help resolving family issues');

-- Insert sample case categories
INSERT INTO blotter_case_categories (blotter_case_id, category_id) VALUES
    (1, 8), -- Other cases
    (2, 8), -- Other cases  
    (3, 8); -- Other cases

-- Insert sample events
INSERT INTO events (title, description, start_datetime, end_datetime, location, organizer, barangay_id, created_by_user_id) VALUES
    ('Barangay Assembly', 'Monthly barangay assembly meeting', '2024-02-15 19:00:00', '2024-02-15 21:00:00', 'Barangay Hall', 'Barangay Council', 32, 3),
    ('Health Fair', 'Free medical checkup and consultation', '2024-02-20 08:00:00', '2024-02-20 17:00:00', 'Covered Court', 'Health Committee', 18, 3),
    ('Clean-up Drive', 'Community clean-up activity', '2024-02-25 06:00:00', '2024-02-25 10:00:00', 'Various Streets', 'Environment Committee', 3, 3);


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

ALTER TABLE document_requests 
ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00 AFTER document_type_id;
-- Flush privileges

ALTER TABLE document_requests 
-- Personal Information (some may duplicate person table but needed for form completeness)
ADD COLUMN first_name VARCHAR(50) AFTER barangay_id,
ADD COLUMN middle_name VARCHAR(50) AFTER first_name,
ADD COLUMN last_name VARCHAR(50) AFTER middle_name,
ADD COLUMN qualifier VARCHAR(20) AFTER last_name,
ADD COLUMN sex ENUM('Male', 'Female', 'Others') AFTER qualifier,
ADD COLUMN civil_status ENUM('Single', 'Married', 'Widowed', 'Separated', 'Widow/Widower') AFTER sex,
ADD COLUMN citizenship VARCHAR(100) AFTER civil_status,
ADD COLUMN date_of_birth DATE AFTER citizenship,
ADD COLUMN place_of_birth VARCHAR(255) AFTER date_of_birth,
ADD COLUMN address_no VARCHAR(50) AFTER place_of_birth,
ADD COLUMN street VARCHAR(100) AFTER address_no,

-- Business Clearance specific fields
ADD COLUMN business_name VARCHAR(255) AFTER street,
ADD COLUMN business_location VARCHAR(255) AFTER business_name,
ADD COLUMN business_nature VARCHAR(255) AFTER business_location,
ADD COLUMN plate_number VARCHAR(50) AFTER business_nature,

-- Indigency Certification specific fields
ADD COLUMN relations VARCHAR(100) AFTER plate_number,
ADD COLUMN beneficiary_name VARCHAR(100) AFTER relations,
ADD COLUMN beneficiary_address_no VARCHAR(50) AFTER beneficiary_name,
ADD COLUMN beneficiary_street VARCHAR(100) AFTER beneficiary_address_no,

-- Residence Certification specific fields
ADD COLUMN years_of_residence INT AFTER beneficiary_street,
ADD COLUMN purpose TEXT AFTER years_of_residence,

-- Building/Fencing Clearance specific fields
ADD COLUMN construction_location VARCHAR(255) AFTER purpose,
ADD COLUMN title_number VARCHAR(100) AFTER construction_location,

-- Common document fields
ADD COLUMN ctc_number VARCHAR(100) AFTER title_number,
ADD COLUMN date_issued DATE AFTER ctc_number,
ADD COLUMN place_issued VARCHAR(255) AFTER date_issued,
ADD COLUMN or_number VARCHAR(100) AFTER place_issued,
ADD COLUMN cp_number VARCHAR(100) AFTER or_number,
ADD COLUMN amount DECIMAL(10,2) AFTER cp_number;
FLUSH PRIVILEGES;
SELECT * FROM document_requests;

