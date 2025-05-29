<?php
session_start();
require_once '../config/dbconn.php';

// Ensure user is logged in and has barangay_id
if (!isset($_SESSION['barangay_id']) || !is_numeric($_SESSION['barangay_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Barangay not set in session.']);
    exit();
}

// Check if user has appropriate role
if (!in_array($_SESSION['role_id'], [3,4,5,6,7])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service_id = $_POST['service_id'] ?? '';
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $icon = $_POST['icon'] ?? '';
    $requirements = $_POST['requirements'] ?? '';
    $detailed_guide = $_POST['detailed_guide'] ?? '';
    $processing_time = $_POST['processing_time'] ?? '';
    $fees = $_POST['fees'] ?? '';
    $barangay_id = $_SESSION['barangay_id'];

    if (empty($service_id) || empty($name) || empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Required fields are missing']);
        exit();
    }

    try {
        // Verify service exists and belongs to the barangay
        $stmt = $pdo->prepare("
            SELECT id 
            FROM custom_services 
            WHERE id = ? AND barangay_id = ?
        ");
        $stmt->execute([$service_id, $barangay_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Service not found']);
            exit();
        }

        // Update the service
        $stmt = $pdo->prepare("
            UPDATE custom_services 
            SET name = ?, 
                description = ?, 
                icon = ?,
                requirements = ?,
                detailed_guide = ?,
                processing_time = ?,
                fees = ?
            WHERE id = ? AND barangay_id = ?
        ");
        
        $stmt->execute([
            $name,
            $description,
            $icon,
            $requirements,
            $detailed_guide,
            $processing_time,
            $fees,
            $service_id,
            $barangay_id
        ]);

        // Log the action
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
            VALUES (?, 'UPDATE', 'custom_services', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $service_id,
            "Updated service: $name"
        ]);

        echo json_encode(['success' => true, 'message' => 'Service updated successfully']);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update service']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} 