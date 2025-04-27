<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require "../config/dbconn.php";

/**
 * Audit Trail logging function.
 */
function logAuditTrail($pdo, $user_id, $action, $table_name = null, $record_id = null, $description = '') {
    $stmt = $pdo->prepare("INSERT INTO AuditTrail (admin_user_id, action, table_name, record_id, description) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $table_name, $record_id, $description]);
}

// Ensure this script is only accessed via POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../pages/change_pass.php");
    exit;
}

// If the user clicks "Resend Reset Email"
if (isset($_POST['resend']) && $_POST['resend'] == 1) {
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    
    // Ensure you have the sendPasswordReset() function defined/required before using it.
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

// --- Token Verification ---
$stmt = $pdo->prepare("SELECT user_id, verification_token, verification_expiry FROM Users WHERE email = ?");
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

// --- Update the Password ---
$password_hash = password_hash($new_password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE Users SET password_hash = ?, verification_token = NULL, verification_expiry = NULL WHERE email = ?");
$stmt->execute([$password_hash, $email]);

// Log the password change event.
logAuditTrail($pdo, $user['user_id'], "CHANGE PASSWORD", "Users", $user['user_id'], "User changed password successfully.");

$_SESSION['success'] = "Password successfully changed. You may now log in.";
header("Location: ../pages/index.php");
exit;
?>
