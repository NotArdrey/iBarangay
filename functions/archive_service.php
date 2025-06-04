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
        // Verify service exists and belongs to the barangay
        $stmt = $pdo->prepare("
            SELECT name 
            FROM custom_services 
            WHERE id = ? AND barangay_id = ? AND is_archived = FALSE
        ");
        $stmt->execute([$service_id, $barangay_id]);
        $service = $stmt->fetch();

        if (!$service) {
            echo json_encode(['success' => false, 'message' => 'Service not found or already archived']);
            exit();
        }

        // Archive the service
        $stmt = $pdo->prepare("
            UPDATE custom_services 
            SET is_archived = TRUE, archived_at = NOW()
            WHERE id = ? AND barangay_id = ?
        ");
        $stmt->execute([$service_id, $barangay_id]);

        // Log the action
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
            VALUES (?, 'ARCHIVE', 'custom_services', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $service_id,
            "Archived service: {$service['name']}"
        ]);

        echo json_encode(['success' => true, 'message' => 'Service archived successfully']);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to archive service']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} 