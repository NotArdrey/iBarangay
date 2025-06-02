<?php
require_once '../config/dbconn.php';
require_once '../functions/notification_helper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle different actions
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_notifications':
        // Get notifications for dropdown
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
        $notifications = getRecentNotifications($user_id, $limit);
        $unread_count = getUnreadNotificationCount($user_id);

        echo json_encode([
            'notifications' => $notifications,
            'unread_count' => $unread_count
        ]);
        break;

    case 'mark_read':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $notification_id = $_POST['notification_id'] ?? null;
            if ($notification_id) {
                $success = markNotificationAsRead($notification_id, $user_id);
                echo json_encode(['success' => $success]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Notification ID required']);
            }
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;

    case 'mark_all_read':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $success = markAllNotificationsAsRead($user_id);
            echo json_encode(['success' => $success]);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
} 