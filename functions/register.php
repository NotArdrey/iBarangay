<?php
session_start();
require '../includes/db_connect.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hn = $_POST['household_id'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $birth_date = $_POST['birth_date'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirmPassword'];
        $role_id = $_POST['role_id'] ?? 3;

        // Validate password match
        if ($password !== $confirmPassword) {
            throw new Exception("Passwords do not match");
        }

        // Check household existence
        $stmt = $conn->prepare("SELECT * FROM Household WHERE household_id = ?");
        $stmt->bind_param("s", $hn);
        $stmt->execute();
        $household = $stmt->get_result()->fetch_assoc();
        if (!$household) {
            throw new Exception("Invalid Household Number");
        }

        // Verify census record
        $stmt = $conn->prepare("
            SELECT p.person_id 
            FROM Person p
            JOIN HouseholdMember hm ON p.person_id = hm.person_id
            WHERE hm.household_id = ?
            AND LOWER(p.first_name) = LOWER(?)
            AND LOWER(p.last_name) = LOWER(?)
            AND p.birth_date = ?
        ");
        $stmt->bind_param("ssss", $hn, $first_name, $last_name, $birth_date);
        $stmt->execute();
        $person = $stmt->get_result()->fetch_assoc();
        
        if (!$person) {
            throw new Exception("No matching resident found in census records");
        }

        // Check existing account
        $stmt = $conn->prepare("SELECT user_account_id FROM Person WHERE person_id = ?");
        $stmt->bind_param("i", $person['person_id']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            throw new Exception("This resident already has an account");
        }

        // Generate verification token
        $verification_token = bin2hex(random_bytes(16));
        $verification_expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

        // Create user account
        $conn->begin_transaction();
        
        $stmt = $conn->prepare("
            INSERT INTO UserAccount 
            (email, phone, password_hash, verification_token, verification_expiry)
            VALUES (?, ?, ?, ?, ?)
        ");
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bind_param("sssss", $email, $phone, $hashed_password, $verification_token, $verification_expiry);
        $stmt->execute();
        $user_id = $conn->insert_id;

        // Link person to account
        $stmt = $conn->prepare("
            UPDATE Person 
            SET user_account_id = ?
            WHERE person_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $person['person_id']);
        $stmt->execute();

        // Assign role
        $stmt = $conn->prepare("
            INSERT INTO UserRole 
            (user_account_id, role_id, barangay_id)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iii", $user_id, $role_id, $household['barangay_id']);
        $stmt->execute();

        // Send verification email
        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.yourdomain.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'noreply@barangayhub.com';
            $mail->Password   = 'your_email_password';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            // Recipients
            $mail->setFrom('noreply@barangayhub.com', 'Barangay Hub');
            $mail->addAddress($email);

            // Content
            $verification_link = "https://yourdomain.com/verify.php?token=$verification_token";
            $mail->isHTML(true);
            $mail->Subject = 'Verify Your Barangay Hub Account';
            $mail->Body    = "
                <h2>Account Verification</h2>
                <p>Please click the link below to verify your account:</p>
                <p><a href='$verification_link'>$verification_link</a></p>
                <p>This link will expire in 1 hour.</p>
            ";

            $mail->send();
        } catch (Exception $e) {
            throw new Exception("Verification email could not be sent. Error: {$mail->ErrorInfo}");
        }

        $conn->commit();
        
        $_SESSION['alert'] = "<script>
            Swal.fire({
                icon: 'success',
                title: 'Registration Successful!',
                text: 'Please check your email to verify your account'
            });
        </script>";
        header("Location: ../pages/login.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['alert'] = "<script>
            Swal.fire({
                icon: 'error',
                title: 'Registration Failed',
                text: '".addslashes($e->getMessage())."'
            });
        </script>";
        header("Location: register.php");
        exit();
    }
}
?>