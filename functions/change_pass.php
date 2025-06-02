<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require "../config/dbconn.php";

/**
 * Audit Trail logging function.
 */
function logAuditTrail($pdo, $user_id, $action, $table_name = null, $record_id = null, $description = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_trails (user_id, action, table_name, record_id, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id ?? 1, // Use 1 (system user) if no user_id is provided
            $action,
            $table_name,
            $record_id,
            $description
        ]);
    } catch (PDOException $e) {
        error_log("Error logging audit trail: " . $e->getMessage());
        // Don't throw the error - just log it and continue
    }
}

/**
 * Validate password strength
 */
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

// Ensure this script is only accessed via POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/change_pass.php");
    exit;
}

// If the user clicks "Resend Reset Email"
if (isset($_POST['resend']) && $_POST['resend'] == 1) {
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    
    // Include the forget_pass.php file to use sendPasswordReset function
    require_once "forget_pass.php";
    $message = sendPasswordReset($email, $pdo);  
    $_SESSION['success'] = $message;
    header("Location: ../pages/change_pass.php?email=" . urlencode($email));
    exit;
}

// Process Change Password request.
$email            = isset($_POST['email']) ? $_POST['email'] : '';
$token            = isset($_POST['token']) ? $_POST['token'] : '';
$new_password     = isset($_POST['new_password']) ? $_POST['new_password'] : '';
$confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

// Basic validations.
if (empty($email) || empty($token)) {
    $_SESSION['error'] = "Invalid request. Missing email or token.";
    header("Location: ../pages/change_pass.php");
    exit;
}

if (empty($new_password) || empty($confirm_password)) {
    $_SESSION['error'] = "Please fill in all fields.";
    header("Location: ../pages/change_pass.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
}

if ($new_password !== $confirm_password) {
    $_SESSION['error'] = "Passwords do not match.";
    header("Location: ../pages/change_pass.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
}

// Validate password strength
$strength_errors = validatePasswordStrength($new_password);
if (!empty($strength_errors)) {
    $_SESSION['error'] = implode('. ', $strength_errors);
    header("Location: ../pages/change_pass.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
}

// --- Token Verification ---
$stmt = $pdo->prepare("SELECT id, verification_token, verification_expiry, password FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "No account found for that email.";
    header("Location: ../pages/change_pass.php");
    exit;
}

// Check if the token matches and that it hasn't expired.
if ($token !== $user['verification_token'] || strtotime($user['verification_expiry']) < time()) {
    $_SESSION['error'] = "Invalid or expired token.";
    header("Location: ../pages/change_pass.php?email=" . urlencode($email));
    exit;
}

// Check if new password is same as current password
if (password_verify($new_password, $user['password'])) {
    $_SESSION['error'] = "New password cannot be the same as your current password.";
    header("Location: ../pages/change_pass.php?email=" . urlencode($email) . "&token=" . urlencode($token));
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
$history_stmt->execute([$user['id']]);
$password_history = $history_stmt->fetchAll(PDO::FETCH_COLUMN);

// Check against password history
foreach ($password_history as $old_hash) {
    if (password_verify($new_password, $old_hash)) {
        $_SESSION['error'] = "New password cannot be one of your last passwords.";
        header("Location: ../pages/change_pass.php?email=" . urlencode($email) . "&token=" . urlencode($token));
        exit;
    }
}

try {
    $pdo->beginTransaction();
    
    // Store current password in history before updating (if not already there)
    if (!empty($user['password'])) {
        $history_insert = $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
        $history_insert->execute([$user['id'], $user['password']]);
    }
    
    // --- Update the Password ---
    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ?, verification_token = NULL, verification_expiry = NULL WHERE email = ?");
    $stmt->execute([$password_hash, $email]);
    
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
    $cleanup_stmt->execute([$user['id'], $user['id']]);
    
    $pdo->commit();
    
    // Log the password change event.
    logAuditTrail($pdo, $user['id'], "CHANGE PASSWORD", "users", $user['id'], "User changed password successfully via forgot password.");
    
    $_SESSION['success'] = "Password successfully changed. You may now log in.";
    header("Location: ../pages/login.php");
    exit;
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = "An error occurred while updating your password. Please try again.";
    header("Location: ../pages/change_pass.php?email=" . urlencode($email) . "&token=" . urlencode($token));
    exit;
}
?>