<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$verification_code = $_POST['verification_code'] ?? '';

// Check if verification code exists and hasn't expired
if (!isset($_SESSION['password_reset_code']) || !isset($_SESSION['password_reset_code_expiry'])) {
    echo json_encode(['success' => false, 'message' => 'No verification code found']);
    exit;
}

if (time() > $_SESSION['password_reset_code_expiry']) {
    // Clear expired code
    unset($_SESSION['password_reset_code']);
    unset($_SESSION['password_reset_code_expiry']);
    echo json_encode(['success' => false, 'message' => 'Verification code has expired']);
    exit;
}

// Convert both codes to strings for comparison
$submitted_code = (string)$verification_code;
$stored_code = (string)$_SESSION['password_reset_code'];

if ($submitted_code !== $stored_code) {
    echo json_encode(['success' => false, 'message' => 'Invalid verification code']);
    exit;
}

// Code is valid - clear it and set verification flag
unset($_SESSION['password_reset_code']);
unset($_SESSION['password_reset_code_expiry']);
$_SESSION['password_reset_verified'] = true;
$_SESSION['password_reset_verified_expiry'] = time() + (5 * 60); // 5 minutes to complete password change

echo json_encode(['success' => true, 'message' => 'Code verified successfully']); 