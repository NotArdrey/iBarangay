<?php
require_once __DIR__ . '/../config/dbconn.php';
require_once __DIR__ . '/email_template.php'; // Added require for email templates

/**
 * Create a new notification
 * 
 * @param int $user_id The user to notify
 * @param string $type The type of notification (e.g., 'blotter', 'service', 'event')
 * @param string $title The notification title
 * @param string $message The notification message
 * @param string $priority The priority level ('low', 'medium', 'high', 'urgent')
 * @param string|null $related_table The related table name (e.g., 'blotter_cases', 'service_requests')
 * @param int|null $related_id The ID of the related record
 * @param string|null $action_url The URL to redirect to when clicking the notification
 * @return bool Whether the notification was created successfully
 */
function createNotification($user_id, $type, $title, $message, $priority = 'medium', $related_table = null, $related_id = null, $action_url = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, priority, 
                related_table, related_id, action_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $user_id, $type, $title, $message, $priority,
            $related_table, $related_id, $action_url
        ]);
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notifications for multiple users
 * 
 * @param array $user_ids Array of user IDs to notify
 * @param string $type The type of notification
 * @param string $title The notification title
 * @param string $message The notification message
 * @param string $priority The priority level
 * @param string|null $related_table The related table name
 * @param int|null $related_id The ID of the related record
 * @param string|null $action_url The URL to redirect to
 * @return int Number of notifications created
 */
function createNotificationsForUsers($user_ids, $type, $title, $message, $priority = 'medium', $related_table = null, $related_id = null, $action_url = null) {
    $success_count = 0;
    foreach ($user_ids as $user_id) {
        if (createNotification($user_id, $type, $title, $message, $priority, $related_table, $related_id, $action_url)) {
            $success_count++;
        }
    }
    return $success_count;
}

/**
 * Create notifications for users with specific roles
 * 
 * @param array $roles Array of role IDs
 * @param string $type The type of notification
 * @param string $title The notification title
 * @param string $message The notification message
 * @param string $priority The priority level
 * @param string|null $related_table The related table name
 * @param int|null $related_id The ID of the related record
 * @param string|null $action_url The URL to redirect to
 * @return int Number of notifications created
 */
function createNotificationsForRoles($roles, $type, $title, $message, $priority = 'medium', $related_table = null, $related_id = null, $action_url = null) {
    global $pdo;
    
    try {
        // Get users with specified roles
        $placeholders = str_repeat('?,', count($roles) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id 
            FROM users u 
            JOIN user_roles ur ON u.id = ur.user_id 
            WHERE ur.role_id IN ($placeholders)
        ");
        $stmt->execute($roles);
        $user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return createNotificationsForUsers($user_ids, $type, $title, $message, $priority, $related_table, $related_id, $action_url);
    } catch (PDOException $e) {
        error_log("Error creating role-based notifications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark a notification as read
 * 
 * @param int $notification_id The notification ID
 * @param int $user_id The user ID
 * @return bool Whether the notification was marked as read
 */
function markNotificationAsRead($notification_id, $user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        return $stmt->execute([$notification_id, $user_id]);
    } catch (PDOException $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for a user
 * 
 * @param int $user_id The user ID
 * @return bool Whether the notifications were marked as read
 */
function markAllNotificationsAsRead($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0
        ");
        return $stmt->execute([$user_id]);
    } catch (PDOException $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notification count for a user
 * 
 * @param int $user_id The user ID
 * @return int Number of unread notifications
 */
function getUnreadNotificationCount($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error getting unread notification count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get recent notifications for a user
 * 
 * @param int $user_id The user ID
 * @param int $limit Maximum number of notifications to return
 * @return array Array of notifications
 */
function getRecentNotifications($user_id, $limit = 5) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * 
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting recent notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Notify user about a blotter case update.
 *
 * @param int $user_id The user to notify.
 * @param int $case_id The ID of the blotter case.
 * @param string $case_number The case number.
 * @param string $update_details Specific details about the update.
 * @param string|null $action_url Optional URL for the notification.
 * @param string|null $action_text Optional text for the action button in the email.
 * @return bool Whether the notification was created successfully.
 */
function notifyBlotterUpdate($user_id, $case_id, $case_number, $update_details, $action_url = null, $action_text = null) {
    $system_title = "Blotter Case Update: " . htmlspecialchars($case_number);
    $email_html_content = getBlotterUpdateNotificationTemplate($case_number, $update_details, $action_url, $action_text);
    
    return createNotification(
        $user_id,
        'blotter',
        $system_title,
        $email_html_content, // For systems that might send this as email
        'medium',
        'blotter_cases',
        $case_id,
        $action_url
    );
}

/**
 * Notify user about a scheduled hearing for a blotter case.
 *
 * @param int $user_id The user to notify.
 * @param int $case_id The ID of the blotter case.
 * @param string $case_number The case number.
 * @param string $hearing_date The date of the hearing.
 * @param string $hearing_time The time of the hearing.
 * @param string $location The location of the hearing.
 * @param string $additional_info Optional additional information for the email.
 * @param string|null $action_url Optional URL for the notification.
 * @param string|null $action_text Optional text for the action button in the email.
 * @return bool Whether the notification was created successfully.
 */
function notifyHearingScheduled($user_id, $case_id, $case_number, $hearing_date, $hearing_time, $location, $additional_info = '', $action_url = null, $action_text = null) {
    $system_title = "Hearing Scheduled: Case " . htmlspecialchars($case_number);
    $email_html_content = getHearingScheduleNotificationTemplate($case_number, $hearing_date, $hearing_time, $location, $additional_info, $action_url, $action_text);

    return createNotification(
        $user_id,
        'blotter_hearing',
        $system_title,
        $email_html_content,
        'high',
        'blotter_cases',
        $case_id,
        $action_url
    );
}

/**
 * Notify user about an issued summons for a blotter case.
 *
 * @param int $user_id The user to notify (typically the respondent).
 * @param int $case_id The ID of the blotter case.
 * @param string $case_number The case number.
 * @param string $respondent_name The name of the respondent.
 * @param string $hearing_date The date of the hearing.
 * @param string $hearing_time The time of the hearing.
 * @param string $additional_info Optional additional information for the email.
 * @param string|null $action_url Optional URL for the notification (e.g., to view summons).
 * @param string|null $action_text Optional text for the action button in the email.
 * @return bool Whether the notification was created successfully.
 */
function notifySummonsIssued($user_id, $case_id, $case_number, $respondent_name, $hearing_date, $hearing_time, $additional_info = '', $action_url = null, $action_text = null) {
    $system_title = "Summons Issued: Case " . htmlspecialchars($case_number);
    $email_html_content = getSummonsNotificationTemplate($case_number, $respondent_name, $hearing_date, $hearing_time, $additional_info, $action_url, $action_text);

    return createNotification(
        $user_id,
        'blotter_summons',
        $system_title,
        $email_html_content,
        'urgent',
        'blotter_cases',
        $case_id,
        $action_url
    );
}

/**
 * Document Request Notifications
 */
function notifyDocumentRequestSubmitted($requester_id, $request_id, $document_type) {
    return createNotification(
        $requester_id,
        'Document Request Submitted',
        "Your request for {$document_type} has been submitted and is pending review.",
        'medium',
        $request_id,
        'document_request'
    );
}

function notifyDocumentRequestStatusChange($requester_id, $request_id, $document_type, $new_status, $admin_name = '') {
    $status_messages = [
        'approved' => "Your request for {$document_type} has been approved" . ($admin_name ? " by {$admin_name}" : '') . ".",
        'rejected' => "Your request for {$document_type} has been rejected" . ($admin_name ? " by {$admin_name}" : '') . ". Please contact the office for details.",
        'ready' => "Your {$document_type} is ready for pickup.",
        'completed' => "Your {$document_type} request has been completed."
    ];
    
    $message = $status_messages[$new_status] ?? "Your request for {$document_type} status has been updated to {$new_status}.";
    $type = ($new_status === 'approved' || $new_status === 'ready') ? 'high' : 'medium';
    
    return createNotification(
        $requester_id,
        'Document Request Update',
        $message,
        $type,
        $request_id,
        'document_request'
    );
}

function notifyAdminsNewDocumentRequest($barangay_id, $request_id, $document_type, $requester_name) {
    global $pdo;
    
    try {
        // Get all admins (role_id 2-7) in the barangay
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND role_id BETWEEN 2 AND 7 AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $success = true;
        foreach ($admins as $admin_id) {
            $result = createNotification(
                $admin_id,
                'New Document Request',
                "New {$document_type} request from {$requester_name} requires review.",
                'high',
                $request_id,
                'document_request'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying admins of new document request: " . $e->getMessage());
        return false;
    }
}

/**
 * Event Notifications
 */
function notifyEventCreated($barangay_id, $event_id, $event_title, $event_date) {
    global $pdo;
    
    try {
        // Get all active users in the barangay
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $success = true;
        foreach ($users as $user_id) {
            $result = createNotification(
                $user_id,
                'New Event Scheduled',
                "A new event '{$event_title}' has been scheduled for {$event_date}.",
                'medium',
                $event_id,
                'event'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying users of new event: " . $e->getMessage());
        return false;
    }
}

function notifyEventUpdated($barangay_id, $event_id, $event_title, $changes_description) {
    global $pdo;
    
    try {
        // Get all active users in the barangay
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $success = true;
        foreach ($users as $user_id) {
            $result = createNotification(
                $user_id,
                'Event Updated',
                "The event '{$event_title}' has been updated. {$changes_description}",
                'medium',
                $event_id,
                'event'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying users of event update: " . $e->getMessage());
        return false;
    }
}

function notifyEventReminder($barangay_id, $event_id, $event_title, $event_date) {
    global $pdo;
    
    try {
        // Get all active users in the barangay
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $success = true;
        foreach ($users as $user_id) {
            $result = createNotification(
                $user_id,
                'Event Reminder',
                "Reminder: '{$event_title}' is scheduled for {$event_date}.",
                'high',
                $event_id,
                'event'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error sending event reminders: " . $e->getMessage());
        return false;
    }
}

function notifyEventCancelled($barangay_id, $event_id, $event_title, $reason = '') {
    global $pdo;
    
    try {
        // Get all active users in the barangay
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $message = "The event '{$event_title}' has been cancelled.";
        if ($reason) {
            $message .= " Reason: {$reason}";
        }
        
        $success = true;
        foreach ($users as $user_id) {
            $result = createNotification(
                $user_id,
                'Event Cancelled',
                $message,
                'urgent',
                $event_id,
                'event'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying users of event cancellation: " . $e->getMessage());
        return false;
    }
}

/**
 * Blotter Case Notifications
 */
function notifyBlotterCaseCreated($barangay_id, $case_id, $case_number, $complainant_name) {
    global $pdo;
    
    try {
        // Get all admins (role_id 2-7) in the barangay
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND role_id BETWEEN 2 AND 7 AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $success = true;
        foreach ($admins as $admin_id) {
            $result = createNotification(
                $admin_id,
                'New Blotter Case Filed',
                "New case {$case_number} filed by {$complainant_name} requires attention.",
                'high',
                $case_id,
                'blotter_case'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying admins of new blotter case: " . $e->getMessage());
        return false;
    }
}

function notifyBlotterStatusUpdate($barangay_id, $case_id, $case_number, $new_status, $participants = []) {
    global $pdo;
    
    try {
        $status_messages = [
            'open' => "Case {$case_number} has been opened for investigation.",
            'closed' => "Case {$case_number} has been resolved and closed.",
            'dismissed' => "Case {$case_number} has been dismissed.",
            'solved' => "Case {$case_number} has been marked as solved.",
            'endorsed_to_court' => "Case {$case_number} has been endorsed to court.",
            'cfa_eligible' => "Case {$case_number} is now eligible for Certificate to File Action."
        ];
        
        $message = $status_messages[$new_status] ?? "Case {$case_number} status has been updated to {$new_status}.";
        $type = ($new_status === 'dismissed' || $new_status === 'endorsed_to_court') ? 'urgent' : 'medium';
        
        $success = true;
        
        // Notify participants
        foreach ($participants as $participant) {
            if (!empty($participant['user_id'])) {
                $result = createNotification(
                    $participant['user_id'],
                    'Case Status Update',
                    $message,
                    $type,
                    $case_id,
                    'blotter_case'
                );
                if (!$result) $success = false;
            }
        }
        
        // Also notify admins
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE barangay_id = ? AND role_id BETWEEN 2 AND 7 AND is_active = 1
        ");
        $stmt->execute([$barangay_id]);
        $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($admins as $admin_id) {
            $result = createNotification(
                $admin_id,
                'Case Status Update',
                $message,
                'medium',
                $case_id,
                'blotter_case'
            );
            if (!$result) $success = false;
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying of blotter status update: " . $e->getMessage());
        return false;
    }
}

function notifyHearingReminder($case_id, $case_number, $hearing_date, $hearing_time, $participants = []) {
    global $pdo;
    
    try {
        $formatted_date = date('F j, Y', strtotime($hearing_date));
        $formatted_time = date('g:i A', strtotime($hearing_time));
        
        $message = "Reminder: Your hearing for case {$case_number} is scheduled for today ({$formatted_date}) at {$formatted_time}.";
        
        $success = true;
        foreach ($participants as $participant) {
            if (!empty($participant['user_id'])) {
                $result = createNotification(
                    $participant['user_id'],
                    'Hearing Reminder',
                    $message,
                    'urgent',
                    $case_id,
                    'blotter_case'
                );
                if (!$result) $success = false;
            }
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error sending hearing reminder: " . $e->getMessage());
        return false;
    }
}

function notifyHearingOutcome($case_id, $case_number, $outcome, $participants = [], $next_hearing_date = null) {
    global $pdo;
    
    try {
        $outcome_messages = [
            'resolved' => "Case {$case_number} has been resolved during the hearing.",
            'failed' => "Mediation for case {$case_number} was unsuccessful.",
            'postponed' => "The hearing for case {$case_number} has been postponed."
        ];
        
        $message = $outcome_messages[$outcome] ?? "Hearing outcome for case {$case_number}: {$outcome}";
        
        if ($outcome === 'postponed' && $next_hearing_date) {
            $formatted_date = date('F j, Y', strtotime($next_hearing_date));
            $message .= " Next hearing scheduled for {$formatted_date}.";
        }
        
        $type = ($outcome === 'resolved') ? 'high' : 'medium';
        
        $success = true;
        foreach ($participants as $participant) {
            if (!empty($participant['user_id'])) {
                $result = createNotification(
                    $participant['user_id'],
                    'Hearing Outcome',
                    $message,
                    $type,
                    $case_id,
                    'blotter_case'
                );
                if (!$result) $success = false;
            }
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying of hearing outcome: " . $e->getMessage());
        return false;
    }
}

function notifyCFAIssued($case_id, $case_number, $complainant_user_id, $certificate_number) {
    try {
        return createNotification(
            $complainant_user_id,
            'Certificate to File Action Issued',
            "A Certificate to File Action (#{$certificate_number}) has been issued for case {$case_number}. You may now proceed to file your case in court.",
            'urgent',
            $case_id,
            'blotter_case'
        );
    } catch (Exception $e) {
        error_log("Error notifying of CFA issuance: " . $e->getMessage());
        return false;
    }
}

function notifyInterventionAdded($case_id, $case_number, $intervention_name, $participants = []) {
    global $pdo;
    
    try {
        $message = "An intervention ({$intervention_name}) has been added to case {$case_number}.";
        
        $success = true;
        foreach ($participants as $participant) {
            if (!empty($participant['user_id'])) {
                $result = createNotification(
                    $participant['user_id'],
                    'Case Intervention Added',
                    $message,
                    'medium',
                    $case_id,
                    'blotter_case'
                );
                if (!$result) $success = false;
            }
        }
        
        return $success;
    } catch (PDOException $e) {
        error_log("Error notifying of intervention: " . $e->getMessage());
        return false;
    }
}