<?php
header('Content-Type: application/json');
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
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_unread_count':
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['count' => $count]);
            break;
            
        case 'get_recent':
            $limit = intval($_GET['limit'] ?? 5);
            $notifications = getRecentNotifications($user_id, $limit);
            echo json_encode(['notifications' => $notifications]);
            break;
            
        case 'mark_read':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                $notification_id = $data['notification_id'] ?? 0;
                
                if (markNotificationAsRead($notification_id, $user_id)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to mark as read']);
                }
            }
            break;
            
        case 'mark_all_read':
            if ($method === 'POST') {
                if (markAllNotificationsAsRead($user_id)) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to mark all as read']);
                }
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
?>