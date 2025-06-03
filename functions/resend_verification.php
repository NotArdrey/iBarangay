<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'email_template.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Include MySQLi connection
require "../config/dbconn.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_GET['email'])) {
    $email = $_GET['email'];

    // Retrieve user's verification details.
    $stmt = $conn->prepare("SELECT user_id, isverify, verification_token, verification_expiry FROM Users WHERE email = ?");
    if (!$stmt) {
        $_SESSION['alert'] = "<script>
            Swal.fire({icon:'error', title:'Error', text:'Database prepare failed: " . $conn->error . "'})
            .then(() => { window.location.href='../pages/register.php'; });
            </script>";
        header("Location: ../pages/register.php");
        exit();
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->bind_result($user_id, $isverify, $token, $expiry);
    
    if ($stmt->fetch()) {
        if ($isverify) {
            $_SESSION['alert'] = "<script>
                Swal.fire({icon:'info', title:'Already Verified', text:'This email is already verified.'})
                .then(() => { window.location.href='../pages/login.php'; });
                </script>";
            header("Location: ../pages/login.php");
            exit();
        }

        // Generate new verification token
        $newToken = bin2hex(random_bytes(16));
        $newExpiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Update the verification token
        $updateStmt = $conn->prepare("UPDATE Users SET verification_token = ?, verification_expiry = ? WHERE email = ?");
        $updateStmt->bind_param("sss", $newToken, $newExpiry, $email);
        
        if ($updateStmt->execute()) {
            $verificationLink = "https://localhost/iBarangay/functions/register.php?token=" . $newToken;
            
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'barangayhub2@gmail.com';
                $mail->Password   = 'eisy hpjz rdnt bwrp';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Resend: Email Verification';
                $mail->Body = getVerificationEmailTemplate($verificationLink);
                
                $mail->send();
                $_SESSION['alert'] = "<script>
                    Swal.fire({icon:'success', title:'Email Sent', text:'A new verification email has been sent. Please check your inbox.'})
                    .then(() => { window.location.href='../pages/index.php'; });
                    </script>";
            } catch (Exception $e) {
                $_SESSION['alert'] = "<script>
                    Swal.fire({icon:'error', title:'Error', text:'Email could not be sent. Mailer Error: {$mail->ErrorInfo}'})
                    .then(() => { window.location.href='../pages/register.php'; });
                    </script>";
            }
            header("Location: ../pages/index.php");
            exit();
        }
    }
    
    $_SESSION['alert'] = "<script>
        Swal.fire({icon:'error', title:'Error', text:'Email not found in our records.'})
        .then(() => { window.location.href='../pages/register.php'; });
        </script>";
    header("Location: ../pages/register.php");
    exit();
} else {
    header("Location: ../pages/register.php");
    exit();
}
?>
