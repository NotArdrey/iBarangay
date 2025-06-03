<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require "../config/dbconn.php";  // This file should define a valid $pdo instance.
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'email_template.php';

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
 * Sends a password reset email to the user.
 *
 * @param string $email The user's email address.
 * @param PDO    $pdo   The PDO database connection.
 * @return string       A message indicating success or the error that occurred.
 */
function sendPasswordReset($email, $pdo) {
    // Validate the email.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Invalid email address.";
    }
    
    // Look up the user in the database.
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        return "No account is associated with this email.";
    }
    
    // Generate a secure reset token.
    $token = bin2hex(random_bytes(16));
    
    // Update the user's record with the reset token and expiry time (1 hour from now)
    $stmt = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?");
    $stmt->execute([$token, $email]);
    
    // Create the password reset link.
    $resetLink = "https://localhost/iBarangay/pages/change_pass.php?email=" . urlencode($email) . "&token=" . $token;
    
    // Set up PHPMailer to send the password reset email.
    $mail = new PHPMailer(true);
    try {
        // SMTP configuration.
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'barangayhub2@gmail.com';  // Your SMTP username.
        $mail->Password   = 'eisy hpjz rdnt bwrp';         // Your SMTP password.
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('barangayhub2@gmail.com', 'iBarangay System');
        $mail->addAddress($email);
        
        // Email content.
        $mail->isHTML(true);
        $mail->Subject = "Password Reset Request";
        $mail->Body = getPasswordResetTemplate($resetLink);
        
        $mail->send();
        
        // Log the password reset request.
        logAuditTrail($pdo, $user['id'], "PASSWORD RESET REQUEST", "users", $user['id'], "Password reset email sent to $email");
        
        return "A password reset link has been sent to your email.";
    } catch (Exception $e) {
        error_log("Failed to send password reset email: " . $mail->ErrorInfo);
        return "Failed to send the password reset email. Please try again later.";
    }
}

// Process the form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $result = sendPasswordReset($email, $pdo);
    echo $result;
}
?>
