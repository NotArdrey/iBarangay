/*-------------------------------------------------------------
  SECTION 8: CUSTOM BARANGAY SERVICES
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

-- Table for custom service categories
CREATE TABLE service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fa-file',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE
);

-- Table for custom services
CREATE TABLE custom_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barangay_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon VARCHAR(50) DEFAULT 'fa-file',
    requirements TEXT,
    detailed_guide TEXT,
    processing_time VARCHAR(100),
    fees VARCHAR(255),
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barangay_id) REFERENCES barangay(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE CASCADE
);

-- Table for service requirements
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

-- Table for service requests
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

-- Table for service request attachments
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

-- Create indexes for better performance
CREATE INDEX idx_service_categories_barangay ON service_categories(barangay_id);
CREATE INDEX idx_service_categories_active ON service_categories(is_active);
CREATE INDEX idx_custom_services_barangay ON custom_services(barangay_id);
CREATE INDEX idx_custom_services_category ON custom_services(category_id);
CREATE INDEX idx_custom_services_active ON custom_services(is_active);
CREATE INDEX idx_service_requests_status ON service_requests(status);
CREATE INDEX idx_service_requests_created ON service_requests(created_at);

-- Insert some default categories
INSERT INTO service_categories (barangay_id, name, description, icon) VALUES
(32, 'Health Services', 'Medical and healthcare related services', 'fa-heart'),
(32, 'Social Services', 'Community welfare and social assistance', 'fa-hands-helping'),
(32, 'Education', 'Educational support and programs', 'fa-graduation-cap'),
(32, 'Senior Citizen Services', 'Services for elderly residents', 'fa-users');

-- Insert sample custom services
INSERT INTO custom_services (barangay_id, category_id, name, description, icon, requirements, detailed_guide, processing_time, fees) VALUES
(32, 1, 'Medical Mission', 'Free medical check-up and consultation', 'fa-stethoscope', 
'1. Valid ID\n2. Barangay Health Card\n3. Proof of Residency', 
'1. Register at the barangay hall\n2. Get a queue number\n3. Wait for your turn\n4. Consult with the doctor\n5. Get prescribed medicine if applicable',
'30-45 minutes', 'Free'),

(32, 2, 'Food Bank Program', 'Weekly food assistance for qualified residents', 'fa-box-open',
'1. Barangay ID\n2. Proof of Income\n3. Family Assessment Form',
'1. Submit requirements to social welfare office\n2. Undergo assessment\n3. Receive food assistance schedule\n4. Claim weekly food package',
'1-2 days for processing', 'Free');

/*-------------------------------------------------------------
  SECTION 8: SERVICE MANAGEMENT SYSTEM
  -------------------------------------------------------------*/

-- Insert sample service categories
INSERT INTO service_categories (barangay_id, name, description, icon) VALUES
(32, 'Health Services', 'Medical and health-related services', 'fa-heart'),
(32, 'Education Support', 'Educational assistance programs', 'fa-graduation-cap'),
(32, 'Social Welfare', 'Community welfare services', 'fa-hands-helping');

-- Insert sample custom services
INSERT INTO custom_services (barangay_id, category_id, name, description, icon, url_path) VALUES
(32, 1, 'Medical Mission Registration', 'Register for upcoming medical missions', 'fa-hospital', '/pages/medical_mission.php'),
(32, 2, 'Tutorial Program', 'Free tutoring for elementary students', 'fa-book', '/pages/tutorial_program.php'),
(32, 3, 'Food Bank', 'Emergency food assistance for families', 'fa-utensils', '/pages/food_bank.php'); 



-- Add missing columns to custom_services table
ALTER TABLE custom_services 
ADD COLUMN service_type VARCHAR(50) DEFAULT 'general' AFTER barangay_id,
ADD COLUMN priority_level ENUM('normal', 'high', 'urgent') DEFAULT 'normal' AFTER display_order,
ADD COLUMN availability_type ENUM('always', 'scheduled', 'limited') DEFAULT 'always' AFTER priority_level,
ADD COLUMN additional_notes TEXT AFTER availability_type;



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

-- Participant Notifications
CREATE TABLE participant_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    participant_id INT NOT NULL,
    notification_type ENUM('summons', 'schedule_confirmation', 'schedule_rejection', 'hearing_reminder', 'case_update') NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME NULL,
    confirmed BOOLEAN DEFAULT FALSE,
    confirmed_at DATETIME NULL,
    email_address VARCHAR(255) NULL,
    email_sent BOOLEAN DEFAULT FALSE,
    email_sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_blotter_case (blotter_case_id),
    INDEX idx_participant (participant_id),
    INDEX idx_notification_type (notification_type),
    INDEX idx_is_read (is_read),
    INDEX idx_confirmed (confirmed),
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES blotter_participants(id) ON DELETE CASCADE
);

/*-------------------------------------------------------------
  SECTION 7: SAMPLE DATA INSERTION
  -------------------------------------------------------------*/

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

-- Add indexes for better performance
CREATE INDEX idx_schedule_proposals_status ON schedule_proposals(status);
CREATE INDEX idx_schedule_notifications_user ON schedule_notifications(notified_user_id, is_read);
CREATE INDEX idx_blotter_cases_dismissed ON blotter_cases(dismissed_by_user_id, dismissal_date);

-- Add view for schedule proposals with notifications
CREATE VIEW schedule_proposal_summary AS
SELECT 
    sp.id,
    sp.blotter_case_id,
    bc.case_number,
    sp.proposed_date,
    sp.proposed_time,
    sp.hearing_location,
    sp.status,
    sp.user_confirmed,
    sp.captain_confirmed,
    u1.email as proposed_by_email,
    u2.email as notified_officer_email,
    sn.is_read as notification_read
FROM schedule_proposals sp
JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
JOIN users u1 ON sp.proposed_by_user_id = u1.id
LEFT JOIN users u2 ON u2.id = (
    SELECT user_id 
    FROM user_roles 
    WHERE barangay_id = bc.barangay_id 
    AND role_id = CASE 
        WHEN sp.proposed_by_role_id = 3 THEN 7 
        ELSE 3 
    END 
    AND is_active = 1 
    LIMIT 1
)
LEFT JOIN schedule_notifications sn ON sp.id = sn.schedule_proposal_id
WHERE sp.status IN ('proposed', 'pending_user_confirmation', 'pending_officer_confirmation');

-- Add view for dismissed cases
CREATE VIEW dismissed_cases_summary AS
SELECT 
    bc.id,
    bc.case_number,
    bc.incident_date,
    bc.dismissal_date,
    bc.dismissal_reason,
    u.email as dismissed_by_email,
    b.name as barangay_name
FROM blotter_cases bc
JOIN users u ON bc.dismissed_by_user_id = u.id
JOIN barangay b ON bc.barangay_id = b.id
WHERE bc.status = 'dismissed';