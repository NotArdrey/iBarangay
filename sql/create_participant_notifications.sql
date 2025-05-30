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