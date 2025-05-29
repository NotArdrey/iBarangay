<?php
session_start();
require_once '../config/dbconn.php';

// Ensure user is logged in and has barangay_id
if (!isset($_SESSION['barangay_id']) || !is_numeric($_SESSION['barangay_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. Barangay not set in session.']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Service ID is required']);
    exit;
}

$service_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
$barangay_id = (int)$_SESSION['barangay_id'];

if ($service_id === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid service ID']);
    exit;
}

try {
    // Only get services that belong to the user's barangay
    $stmt = $pdo->prepare("SELECT * FROM custom_services WHERE id = ? AND barangay_id = ? AND is_active = 1");
    $stmt->execute([$service_id, $barangay_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        http_response_code(404);
        echo json_encode(['error' => 'Service not found']);
        exit;
    }

    echo json_encode($service);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?> 