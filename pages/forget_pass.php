<?php
if(session_status() === PHP_SESSION_NONE) session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="../styles/forget_pass.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="password-reset-wrapper">
        <div class="branding-side">
            <div class="branding-content">
                <img src="../photo/logo.png" alt="iBarangay Logo">
                <h1>iBarangay</h1>
                <p>Forgot your password? No worries, we'll help you get back on track.</p>
            </div>
        </div>
        <div class="form-side">
            <div class="form-container">
                <div class="header">
                    <h1>Reset Password</h1>
                    <p>Enter your email address and we'll send you a link to reset your password.</p>
                </div>

                <form action="../functions/forget_pass.php" method="post" id="forgot-password-form">
                    <div class="input-group">
                        <label for="email">Email Address</label>
                        <input type="email" name="email" id="email" required placeholder="you@example.com">
                    </div>
                    <button type="submit" class="action-btn">Send Reset Link</button>
                </form>

                <div class="back-link">
                    <a href="login.php" class="alt-link">Back to Login</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_SESSION['forget_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: <?php echo json_encode($_SESSION['forget_success']); ?>,
                confirmButtonColor: '#4F46E5'
            });
            <?php unset($_SESSION['forget_success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['forget_error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?php echo json_encode($_SESSION['forget_error']); ?>,
                confirmButtonColor: '#4F46E5'
            });
            <?php unset($_SESSION['forget_error']); ?>
        <?php endif; ?>
    });
    </script>
</body>
</html>