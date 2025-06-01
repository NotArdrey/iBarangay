<?php
session_start();
require_once '../config/dbconn.php';

// Check if user has appropriate role
if (!in_array($_SESSION['role_id'], [3,4,5,6,7])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON data
    $data = json_decode(file_get_contents('php://input'), true);
    $service_id = $data['service_id'] ?? '';
    $barangay_id = $_SESSION['barangay_id'];

    if (empty($service_id)) {
        echo json_encode(['success' => false, 'message' => 'Service ID is required']);
        exit();
    }

    try {
        // Get current status and verify ownership
        $stmt = $pdo->prepare("
            SELECT is_active, name
            FROM custom_services 
            WHERE id = ? AND barangay_id = ?
        ");
        $stmt->execute([$service_id, $barangay_id]);
        $service = $stmt->fetch();

        if (!$service) {
            echo json_encode(['success' => false, 'message' => 'Service not found or you do not have permission to modify this service']);
            exit();
        }

        // Toggle status
        $new_status = !$service['is_active'];
        $stmt = $pdo->prepare("
            UPDATE custom_services 
            SET is_active = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ? AND barangay_id = ?
        ");
        $result = $stmt->execute([$new_status, $service_id, $barangay_id]);

        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Failed to update service status']);
            exit();
        }

        // Log the action
        $action_desc = $new_status ? "Activated" : "Deactivated";
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
            VALUES (?, 'UPDATE', 'custom_services', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $service_id,
            "$action_desc service: {$service['name']}"
        ]);

        echo json_encode([
            'success' => true, 
            'message' => 'Service status updated successfully',
            'new_status' => $new_status ? 'active' : 'inactive'
        ]);

    } catch (PDOException $e) {
        error_log("Toggle service status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred while updating service status']);
    } catch (Exception $e) {
        error_log("Toggle service status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}