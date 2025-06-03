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