USE barangay;

-- Add missing columns to blotter_cases table
ALTER TABLE blotter_cases 
ADD COLUMN hearing_attempts INT DEFAULT 0 AFTER hearing_count,
ADD COLUMN max_hearing_attempts INT DEFAULT 3 AFTER hearing_attempts,
ADD COLUMN is_cfa_eligible BOOLEAN DEFAULT FALSE AFTER max_hearing_attempts,
ADD COLUMN cfa_reason TEXT NULL AFTER is_cfa_eligible;

-- Create participant_notifications table for summons delivery
CREATE TABLE IF NOT EXISTS participant_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blotter_case_id INT NOT NULL,
    participant_id INT NOT NULL,
    delivery_method ENUM('email', 'physical', 'phone') DEFAULT 'physical',
    delivery_status ENUM('pending', 'sent', 'delivered', 'failed') DEFAULT 'pending',
    delivered_at DATETIME NULL,
    delivery_address VARCHAR(255),
    delivery_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blotter_case_id) REFERENCES blotter_cases(id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES blotter_participants(id) ON DELETE CASCADE
);

-- Add indexes for better performance
CREATE INDEX idx_participant_notifications_case ON participant_notifications(blotter_case_id);
CREATE INDEX idx_participant_notifications_participant ON participant_notifications(participant_id);
CREATE INDEX idx_participant_notifications_status ON participant_notifications(delivery_status);

-- Update existing schedule_proposals table to ensure all required columns exist
ALTER TABLE schedule_proposals 
ADD COLUMN IF NOT EXISTS complainant_confirmed BOOLEAN DEFAULT FALSE AFTER captain_remarks,
ADD COLUMN IF NOT EXISTS respondent_confirmed BOOLEAN DEFAULT FALSE AFTER complainant_confirmed;

-- Insert some sample participant notifications for testing
INSERT INTO participant_notifications (blotter_case_id, participant_id, delivery_method, delivery_status, delivery_address)
SELECT 
    bc.id,
    bp.id,
    CASE 
        WHEN u.email IS NOT NULL THEN 'email'
        ELSE 'physical'
    END,
    'pending',
    COALESCE(
        CONCAT(a.house_no, ' ', a.street, ', ', b.name),
        'Address not provided'
    )
FROM blotter_cases bc
JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
LEFT JOIN persons p ON bp.person_id = p.id
LEFT JOIN users u ON p.user_id = u.id
LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
LEFT JOIN barangay b ON a.barangay_id = b.id
WHERE NOT EXISTS (
    SELECT 1 FROM participant_notifications pn 
    WHERE pn.blotter_case_id = bc.id AND pn.participant_id = bp.id
);
