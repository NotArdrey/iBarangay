<?php
session_start();
require_once '../config/dbconn.php';

// Check if user has appropriate role
if (!in_array($_SESSION['role_id'], [3,4,5,6,7])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $service_id = $_GET['id'] ?? '';
    $barangay_id = $_SESSION['barangay_id'];

    if (empty($service_id)) {
        echo json_encode(['success' => false, 'message' => 'Service ID is required']);
        exit();
    }

    try {
        // Get service details and verify ownership
        $stmt = $pdo->prepare("
            SELECT * FROM custom_services
            WHERE id = ? AND barangay_id = ?
        ");
        $stmt->execute([$service_id, $barangay_id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            echo json_encode(['success' => false, 'message' => 'Service not found']);
            exit();
        }

        // Return service details
        echo json_encode([
            'success' => true,
            'data' => $service
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch service details']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} 