/*-------------------------------------------------------------
  SECTION 4: BLOTTER/CASE MANAGEMENT SYSTEM (IMPROVED)
  -------------------------------------------------------------*/

-- Add hearing reschedule tracking
CREATE TABLE IF NOT EXISTS hearing_reschedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hearing_id INT NOT NULL,
    requested_by_user_id INT NOT NULL,
    requested_by_role_id INT NOT NULL,
    old_hearing_date DATETIME NOT NULL,
    new_hearing_date DATETIME NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    approved_by_user_id INT NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hearing_id) REFERENCES case_hearings(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by_role_id) REFERENCES roles(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Add audit trail for blotter actions
CREATE TABLE IF NOT EXISTS blotter_case_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    action ENUM('filed', 'accepted', 'scheduled', 'rescheduled', 'hearing_conducted', 'resolved', 'dismissed', 'endorsed', 'cfa_issued', 'notification_sent', 'notification_delivered') NOT NULL,
    performed_by_user_id INT,
    performed_by_role_id INT,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (performed_by_role_id) REFERENCES roles(id) ON DELETE SET NULL
);

-- Add explicit status history for participant notifications
CREATE TABLE IF NOT EXISTS participant_notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    status ENUM('pending', 'sent', 'delivered', 'failed') NOT NULL,
    changed_by_user_id INT NULL,
    remarks TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (notification_id) REFERENCES participant_notifications(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Add index for hearing_reschedules
CREATE INDEX idx_hearing_reschedules_hearing ON hearing_reschedules(hearing_id);

-- Add index for blotter_case_audits
CREATE INDEX idx_blotter_case_audits_case ON blotter_case_audits(blotter_case_id);

/*-------------------------------------------------------------
  SECTION 7: SAMPLE DATA INSERTION (IMPROVED)
  -------------------------------------------------------------*/

-- Sample: Propose a hearing schedule for a blotter case
INSERT INTO schedule_proposals (
    blotter_case_id, proposed_by_user_id, proposed_by_role_id, proposed_date, proposed_time,
    hearing_location, presiding_officer, presiding_officer_position, status, user_confirmed, captain_confirmed
) VALUES
    (1, 4, 3, '2024-03-10', '09:00:00', 'Barangay Hall', 'Juan Dela Cruz', 'Barangay Captain', 'proposed', FALSE, FALSE);

-- Sample: Accept a blotter case
UPDATE blotter_cases SET
    accepted_by_user_id = 4,
    accepted_by_role_id = 3,
    accepted_at = NOW(),
    status = 'open'
WHERE id = 1;

-- Sample: Schedule a hearing for the case
INSERT INTO case_hearings (
    blotter_case_id, hearing_date, hearing_type, hearing_notes, presided_by_user_id, hearing_number
) VALUES
    (1, '2024-03-10 09:00:00', 'initial', 'Initial mediation scheduled.', 4, 1);

-- Sample: Log audit trail for actions
INSERT INTO blotter_case_audits (blotter_case_id, action, performed_by_user_id, performed_by_role_id, remarks)
VALUES
    (1, 'filed', 10, 8, 'Case filed by resident.'),
    (1, 'accepted', 4, 3, 'Accepted by Barangay Captain.'),
    (1, 'scheduled', 4, 3, 'Initial hearing scheduled.');

-- Sample: Participant notification log
INSERT INTO participant_notification_logs (notification_id, status, changed_by_user_id, remarks)
SELECT id, 'sent', 4, 'Summons sent via email.' FROM participant_notifications WHERE blotter_case_id = 1;

-- Sample: Reschedule a hearing
INSERT INTO hearing_reschedules (
    hearing_id, requested_by_user_id, requested_by_role_id, old_hearing_date, new_hearing_date, reason
) VALUES
    (1, 10, 8, '2024-03-10 09:00:00', '2024-03-12 09:00:00', 'Complainant unavailable on original date.');