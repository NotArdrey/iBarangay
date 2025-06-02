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

// Validate password strength
function validatePasswordStrength($password) {
    $errors = [];
    
    // Minimum length
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    
    // Must contain uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    
    // Must contain lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    
    // Must contain number
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    
    // Must contain special character
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/', $password)) {
        $errors[] = 'Password must contain at least one special character (!@#$%^&*()_+-=[]{}|;:,.<>?)';
    }
    
    return $errors;
}

// Check password strength
$strength_errors = validatePasswordStrength($new_password);
if (!empty($strength_errors)) {
    echo json_encode(['success' => false, 'message' => implode('. ', $strength_errors)]);
    exit;
}

try {
    // Get current password and last 5 passwords from history
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Check if new password is same as current password
    if (password_verify($new_password, $current_user['password'])) {
        echo json_encode(['success' => false, 'message' => 'New password cannot be the same as your current password']);
        exit;
    }
    
    // Get last 5 passwords from history
    $history_stmt = $pdo->prepare("
        SELECT password_hash 
        FROM password_history 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $history_stmt->execute([$_SESSION['user_id']]);
    $password_history = $history_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Check against password history
    foreach ($password_history as $old_hash) {
        if (password_verify($new_password, $old_hash)) {
            echo json_encode(['success' => false, 'message' => 'New password cannot be one of your last passwords']);
            exit;
        }
    }
    
    $pdo->beginTransaction();
    
    // Store current password in history before updating
    $history_insert = $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
    $history_insert->execute([$_SESSION['user_id'], $current_user['password']]);
    
    // Update password in database
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$password_hash, $_SESSION['user_id']]);
    
    // Clean up old password history (keep only last 5)
    $cleanup_stmt = $pdo->prepare("
        DELETE FROM password_history 
        WHERE user_id = ? 
        AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM password_history 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ) AS recent_passwords
        )
    ");
    $cleanup_stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    
    $pdo->commit();
    
    // Clear verification session variables
    unset($_SESSION['password_reset_verified']);
    unset($_SESSION['password_reset_verified_expiry']);

    echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while updating password']);
    exit;
}