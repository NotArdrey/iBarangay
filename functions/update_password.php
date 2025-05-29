<?php
session_start();
require_once '../config/dbconn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Verify that the user has completed email verification
if (!isset($_SESSION['password_reset_verified']) || !isset($_SESSION['password_reset_verified_expiry'])) {
    echo json_encode(['success' => false, 'message' => 'Email verification required']);
    exit;
}

if (time() > $_SESSION['password_reset_verified_expiry']) {
    // Clear verification
    unset($_SESSION['password_reset_verified']);
    unset($_SESSION['password_reset_verified_expiry']);
    echo json_encode(['success' => false, 'message' => 'Verification has expired, please start over']);
    exit;
}

$new_password = $_POST['new_password'] ?? '';

if (empty($new_password)) {
    echo json_encode(['success' => false, 'message' => 'New password is required']);
    exit;
}

try {
    // Update password in database
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$password_hash, $_SESSION['user_id']]);

    // Clear verification session variables
    unset($_SESSION['password_reset_verified']);
    unset($_SESSION['password_reset_verified_expiry']);

    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
    exit;
} 