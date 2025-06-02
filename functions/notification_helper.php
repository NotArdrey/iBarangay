<?php
require_once __DIR__ . '/../config/dbconn.php';

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