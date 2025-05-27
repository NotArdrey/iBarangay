<?php
session_start();
require_once '../config/dbconn.php';
require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id = $_SESSION['user_id'];
$old_password = $_POST['old_password'] ?? '';

try {
    // Verify old password
    $stmt = $pdo->prepare("SELECT email, password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($old_password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        exit;
    }

    // Generate verification code
    $verification_code = sprintf("%06d", mt_rand(100000, 999999));
    $_SESSION['password_reset_code'] = $verification_code;
    $_SESSION['password_reset_code_expiry'] = time() + (15 * 60); // 15 minutes expiry

    // Send email with verification code
    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'barangayhub2@gmail.com';
        $mail->Password   = 'eisy hpjz rdnt bwrp';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('barangayhub2@gmail.com', 'iBarangay System');
        $mail->addAddress($user['email']);

        $mail->isHTML(true);
        $mail->Subject = 'Password Change Verification Code';
        $mail->Body = "
            <h2>Password Change Request</h2>
            <p>You have requested to change your password. Please use the following verification code to continue:</p>
            <h1 style='font-size: 24px; color: #0a2240; background: #f5f5f5; padding: 10px; text-align: center;'>{$verification_code}</h1>
            <p>This code will expire in 15 minutes.</p>
            <p>If you did not request this change, please ignore this email and ensure your account is secure.</p>
        ";

        $mail->send();
        echo json_encode(['success' => true, 'message' => 'Verification code sent']);
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        echo json_encode(['success' => false, 'message' => 'Failed to send verification code']);
        exit;
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
    exit;
} 