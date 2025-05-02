<?php
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
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
        $stmt->close();
        if ($isverify === "yes") {
            $_SESSION['alert'] = "<script>
            Swal.fire({icon:'success', title:'Already Verified', text:'Your email is already verified.'})
            .then(() => { window.location.href='../pages/index.php'; });
            </script>";
            header("Location: ../pages/index.php");
            exit();
        } else {
            // If token has expired, generate a new one.
            if (strtotime($expiry) < time()) {
                $token = bin2hex(random_bytes(16));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 day'));
                $stmt2 = $conn->prepare("UPDATE Users SET verification_token = ?, verification_expiry = ? WHERE user_id = ?");
                if (!$stmt2) {
                    $_SESSION['alert'] = "<script>
                        Swal.fire({icon:'error', title:'Error', text:'Database update failed: " . $conn->error . "'})
                        .then(() => { window.location.href='../pages/register.php'; });
                        </script>";
                    header("Location: ../pages/register.php");
                    exit();
                }
                $stmt2->bind_param("ssi", $token, $expiry, $user_id);
                $stmt2->execute();
                $stmt2->close();
            }
            
            // Create new verification link.
            $verificationLink = "https://localhost/barangayhub/pages/verify.php?token=" . $token;
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'barangayhub2@gmail.com'; // Your SMTP username
                $mail->Password   = 'eisy hpjz rdnt bwrp'; // Your SMTP password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('noreply@barangayhub.com', 'Barangay Hub');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Resend: Email Verification';
                $mail->Body    = "Please verify your email by clicking the following link: <a href='$verificationLink'>$verificationLink</a><br>This link will expire in 24 hours.";
                $mail->AltBody = "Please verify your email by visiting: $verificationLink";
                
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
    } else {
        $stmt->close();
        $_SESSION['alert'] = "<script>
            Swal.fire({icon:'error', title:'Error', text:'Email not found.'})
            .then(() => { window.location.href='../pages/register.php'; });
            </script>";
        header("Location: ../pages/register.php");
        exit();
    }
} else {
    header("Location: ../pages/register.php");
    exit();
}
?>
