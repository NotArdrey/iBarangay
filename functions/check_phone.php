<?php
require_once '../config/dbconn.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if phone number is provided
if (!isset($_POST['phone'])) {
    echo json_encode(['error' => 'Phone number is required']);
    exit;
}

$phone = $_POST['phone'];

// Validate phone number format
if (!preg_match('/^09\d{9}$/', $phone)) {
    echo json_encode(['error' => 'Invalid phone number format']);
    exit;
}

try {
    // Check if phone number exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $exists = $stmt->fetchColumn() > 0;
    
    echo json_encode(['exists' => $exists]);
} catch (PDOException $e) {
    error_log("Error checking phone number: " . $e->getMessage());
    echo json_encode(['error' => 'Database error occurred']);
} 