-- Clean up existing records (in reverse order of dependencies)
DELETE FROM addresses WHERE user_id IN (10, 11, 12, 13, 14, 15, 16, 17, 18, 19);
DELETE FROM user_roles WHERE user_id IN (10, 11, 12, 13, 14, 15, 16, 17, 18, 19);
DELETE FROM persons WHERE user_id IN (10, 11, 12, 13, 14, 15, 16, 17, 18, 19);
DELETE FROM users WHERE id IN (10, 11, 12, 13, 14, 15, 16, 17, 18, 19);
DELETE FROM barangay_settings WHERE barangay_id IN (2, 4, 5, 8, 9);

-- Insert additional admin accounts for different barangays
-- Barangay Captains
INSERT INTO users (email, password, role_id, barangay_id, email_verified_at, is_active) VALUES
    ('captain.banca@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 3, 2, NOW(), TRUE),
    ('captain.capihan@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 3, 4, NOW(), TRUE),
    ('captain.coral@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 3, 5, NOW(), TRUE),
    ('captain.diliman1@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 3, 8, NOW(), TRUE),
    ('captain.diliman2@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 3, 9, NOW(), TRUE);

-- Barangay Secretaries
INSERT INTO users (email, password, role_id, barangay_id, email_verified_at, is_active) VALUES
    ('secretary.banca@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 4, 2, NOW(), TRUE),
    ('secretary.capihan@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 4, 4, NOW(), TRUE),
    ('secretary.coral@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 4, 5, NOW(), TRUE),
    ('secretary.diliman1@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 4, 8, NOW(), TRUE),
    ('secretary.diliman2@barangay.com', '$2y$10$aGiCCPg3BGBTV2cVVF2hNeLvvHD25/laK2w61P/RFv/yxPwmIuF3a', 4, 9, NOW(), TRUE);

-- Insert corresponding person records for the admins
INSERT INTO persons (user_id, first_name, last_name, birth_date, birth_place, gender, civil_status, citizenship, religion, education_level, occupation, contact_number) VALUES
    (10, 'ANTONIO', 'CRUZ', '1970-05-15', 'San Rafael', 'MALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Captain', '09123456789'),
    (11, 'JOSE', 'SANTOS', '1972-08-20', 'San Rafael', 'MALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Captain', '09234567890'),
    (12, 'MARIA', 'GARCIA', '1975-03-10', 'San Rafael', 'FEMALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Captain', '09345678901'),
    (13, 'PEDRO', 'REYES', '1968-11-25', 'San Rafael', 'MALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Captain', '09456789012'),
    (14, 'JUAN', 'DELA CRUZ', '1971-07-30', 'San Rafael', 'MALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Captain', '09567890123'),
    (15, 'ANA', 'CRUZ', '1980-04-12', 'San Rafael', 'FEMALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Secretary', '09678901234'),
    (16, 'LUCIA', 'SANTOS', '1982-09-18', 'San Rafael', 'FEMALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Secretary', '09789012345'),
    (17, 'ROSA', 'GARCIA', '1985-01-25', 'San Rafael', 'FEMALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Secretary', '09890123456'),
    (18, 'SUSAN', 'REYES', '1983-06-30', 'San Rafael', 'FEMALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Secretary', '09901234567'),
    (19, 'MARIA', 'DELA CRUZ', '1981-12-05', 'San Rafael', 'FEMALE', 'MARRIED', 'Filipino', 'Roman Catholic', 'COLLEGE GRADUATE', 'Barangay Secretary', '09111223344');

-- Insert user roles for the admins
INSERT INTO user_roles (user_id, role_id, barangay_id, is_active, start_term_date, end_term_date) VALUES
    (10, 3, 2, TRUE, '2023-01-01', '2025-12-31'),
    (11, 3, 4, TRUE, '2023-01-01', '2025-12-31'),
    (12, 3, 5, TRUE, '2023-01-01', '2025-12-31'),
    (13, 3, 8, TRUE, '2023-01-01', '2025-12-31'),
    (14, 3, 9, TRUE, '2023-01-01', '2025-12-31'),
    (15, 4, 2, TRUE, '2023-01-01', '2025-12-31'),
    (16, 4, 4, TRUE, '2023-01-01', '2025-12-31'),
    (17, 4, 5, TRUE, '2023-01-01', '2025-12-31'),
    (18, 4, 8, TRUE, '2023-01-01', '2025-12-31'),
    (19, 4, 9, TRUE, '2023-01-01', '2025-12-31');

-- Insert barangay settings for the new barangays
INSERT INTO barangay_settings (barangay_id, barangay_captain_name, local_barangay_contact, pnp_contact, bfp_contact) VALUES
    (2, 'ANTONIO CRUZ', '0917-555-1111', '0917-555-2222', '0917-555-3333'),
    (4, 'JOSE SANTOS', '0917-555-4444', '0917-555-5555', '0917-555-6666'),
    (5, 'MARIA GARCIA', '0917-555-7777', '0917-555-8888', '0917-555-9999'),
    (8, 'PEDRO REYES', '0917-555-0000', '0917-555-1112', '0917-555-2223'),
    (9, 'JUAN DELA CRUZ', '0917-555-3334', '0917-555-4445', '0917-555-5556');

-- Insert addresses for the admins
INSERT INTO addresses (person_id, user_id, barangay_id, house_no, street, municipality, province, region, residency_type, years_in_san_rafael, is_primary) VALUES
    (10, 10, 2, '101', 'Rizal Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 20, TRUE),
    (11, 11, 4, '202', 'Mabini Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 18, TRUE),
    (12, 12, 5, '303', 'Bonifacio Road', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 15, TRUE),
    (13, 13, 8, '404', 'Aguinaldo Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 22, TRUE),
    (14, 14, 9, '505', 'Luna Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 19, TRUE),
    (15, 15, 2, '606', 'Rizal Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 12, TRUE),
    (16, 16, 4, '707', 'Mabini Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 10, TRUE),
    (17, 17, 5, '808', 'Bonifacio Road', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 8, TRUE),
    (18, 18, 8, '909', 'Aguinaldo Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 14, TRUE),
    (19, 19, 9, '1010', 'Luna Street', 'SAN RAFAEL', 'BULACAN', 'III', 'Home Owner', 16, TRUE);