<?php
//change_pass.php
if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require "../config/dbconn.php";

$token = $_GET['token'] ?? null;
$user_id = null;
$error = '';
$message = '';

if ($token) {
    $sql = "SELECT user_id, expires FROM password_resets WHERE token = ? AND expires > NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$token]);
    $reset_request = $stmt->fetch();

    if ($reset_request) {
        $user_id = $reset_request['user_id'];
    } else {
        $error = "This password reset link is invalid or has expired.";
    }
} else {
    $error = "No reset token provided.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password === $confirm_password) {
        if (preg_match('/^(?=.*[A-Z])(?=.*[0-9!@#$%^&*()]).{8,}$/', $password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$hashed_password, $user_id])) {
                $sql = "DELETE FROM password_resets WHERE user_id = ?";
                $pdo->prepare($sql)->execute([$user_id]);
                $_SESSION['message'] = "Your password has been successfully updated. You can now log in.";
                $_SESSION['success'] = true;
                header("Location: login.php");
                exit();
            } else {
                $error = "Failed to update your password. Please try again.";
            }
        } else {
            $error = "Password must be at least 8 characters long and contain at least one capital letter and one number or special character.";
        }
    } else {
        $error = "Passwords do not match. Please try again.";
    }
}

// Retrieve any messages set in the session
$error = isset($_SESSION['error']) ? $_SESSION['error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
// Clear messages after reading
unset($_SESSION['error'], $_SESSION['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link rel="stylesheet" href="../styles/change_pass.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="password-reset-wrapper">
        <div class="branding-side">
            <div class="branding-content">
                <img src="../photo/logo.png" alt="iBarangay Logo">
                <h1>iBarangay</h1>
                <p>Create a new, secure password for your account.</p>
            </div>
        </div>
        <div class="form-side">
            <div class="form-container">
                <div class="header">
                    <h1>Set New Password</h1>
                    <p>Your new password must be different from previous passwords.</p>
                </div>

                <?php if (!$error && $user_id): ?>
                <form action="change_pass.php?token=<?php echo htmlspecialchars($token); ?>" method="post">
                    <div class="input-group">
                        <label for="password">New Password</label>
                        <div class="password-container">
                           <input type="password" name="password" id="password" required>
                           <button type="button" class="toggle-password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="input-group">
                        <label for="confirm_password">Confirm New Password</label>
                         <div class="password-container">
                            <input type="password" name="confirm_password" id="confirm_password" required>
                            <button type="button" class="toggle-password"><i class="fas fa-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="action-btn">Reset Password</button>
                </form>
                <?php endif; ?>
                
                <div class="back-link">
                    <a href="login.php" class="alt-link">Back to Login</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if(!empty($error)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo addslashes($error); ?>',
                confirmButtonColor: '#4F46E5',
                didClose: () => {
                    <?php if ($error !== 'Passwords do not match. Please try again.' && $error !== 'Password must be at least 8 characters long and contain at least one capital letter and one number or special character.'): ?>
                        window.location.href = 'login.php';
                    <?php endif; ?>
                }
            });
        <?php endif; ?>

        const toggleButtons = document.querySelectorAll('.toggle-password');
        toggleButtons.forEach(button => {
            button.addEventListener('click', function() {
                const passwordInput = this.previousElementSibling;
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });
        });
    });
    </script>
</body>
</html>