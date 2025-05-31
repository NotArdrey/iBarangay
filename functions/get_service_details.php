<?php
session_start();
require_once '../config/dbconn.php';

// Check if user has appropriate role or is a resident
if (!in_array($_SESSION['role_id'], [3,4,5,6,7,8])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $service_id = $_GET['id'] ?? '';
    
    if (empty($service_id)) {
        echo json_encode(['success' => false, 'message' => 'Service ID is required']);
        exit();
    }

    try {
        // For residents, only show active services from their barangay
        // For officials, show all services from their barangay
        $barangay_condition = '';
        $active_condition = '';
        
        if ($_SESSION['role_id'] == 8) { // Resident
            $barangay_condition = ' AND barangay_id = ?';
            $active_condition = ' AND is_active = 1';
        } else { // Officials
            $barangay_condition = ' AND barangay_id = ?';
        }

        $stmt = $pdo->prepare("
            SELECT * FROM custom_services 
            WHERE id = ? $barangay_condition $active_condition
        ");
        
        if ($_SESSION['role_id'] == 8) { // Resident
            $stmt->execute([$service_id, $_SESSION['barangay_id']]);
        } else { // Officials
            $stmt->execute([$service_id, $_SESSION['barangay_id']]);
        }
        
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            echo json_encode(['success' => false, 'message' => 'Service not found']);
            exit();
        }

        echo json_encode([
            'success' => true, 
            'data' => $service
        ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to retrieve service details']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}