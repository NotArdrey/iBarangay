<?php
session_start();
$error = isset($_SESSION['forget_error']) ? $_SESSION['forget_error'] : '';
unset($_SESSION['forget_error']);
$success = isset($_SESSION['forget_success']) ? $_SESSION['forget_success'] : '';
unset($_SESSION['forget_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - iBarangay</title>
    <link rel="stylesheet" href="../styles/login.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Define CSS variables for theming */
        :root {
            --primary-blue: #3b82f6;
            --secondary-blue: #2563eb;
            --border-radius: 8px;
            --transition-speed: 0.3s;
            --light-gray: #f3f4f6;
            --text-dark: #1f2937;
        }

        /* Global Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1rem;
            position: relative;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
        }

        body::before {
            content: "";
            background: url('../photo/bg1.jpg') no-repeat center center fixed;
            background-size: cover;
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            opacity: 0.8;
            z-index: -1;
        }

        /* Container */
        .forget-password-container {
            background: white;
            width: 100%;
            max-width: 440px;
            border-radius: var(--border-radius);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            padding: 2rem;
            text-align: center;
            color: white;
            margin-bottom: 1.5rem;
        }

        .header img {
            width: 80px;
            margin-bottom: 1rem;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
        }

        .header h1 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .header p {
            font-size: 0.875rem;
            opacity: 0.9;
        }

        /* Input Group */
        .input-group {
            padding: 0 1.5rem 1.5rem;
        }

        label {
            display: block;
            color: var(--text-dark);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        input[type="email"] {
            width: 100%;
            padding: 0.875rem;
            border: 2px solid #e2e8f0;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all var(--transition-speed) ease;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: var(--secondary-blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            margin-top: 1rem;
            padding: 1rem;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background var(--transition-speed) ease, transform 0.1s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .submit-btn:hover {
            background: var(--secondary-blue);
        }

        .submit-btn:active {
            transform: scale(0.98);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Back to Login */
        .back-to-login {
            padding: 1.5rem 2rem;
            background: #fafbfc;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: var(--text-dark);
            font-size: 0.875rem;
        }

        .back-to-login a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 600;
            transition: all var(--transition-speed) ease;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .back-to-login a:hover {
            color: var(--secondary-blue);
            background: rgba(59, 130, 246, 0.1);
            text-decoration: underline;
        }

        /* Security Info */
        .security-info {
            padding: 1.5rem 2rem;
            border-top: 1px solid #edf2f7;
            background: var(--light-gray);
            text-align: center;
        }

        .security-info p {
            margin-bottom: 0.5rem;
            font-size: 0.75rem;
            color: #718096;
            line-height: 1.4;
        }

        /* Messages */
        .message {
            margin: 1rem 1.5rem;
            padding: 1rem;
            border-radius: var(--border-radius);
            text-align: center;
            font-size: 0.875rem;
            display: none;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .forget-password-container {
                margin: 1rem;
            }

            .header {
                padding: 1.5rem;
            }

            .back-to-login {
                padding: 1rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="header">
            <img src="../photo/logo.png" alt="Government Logo">
            <h1>iBarangay</h1>
        </div>
        <form action="../functions/forget_pass.php" method="POST" id="forget-form">
            <div class="input-group">
                <label for="email">Enter your email address</label>
                <input type="email" id="email" name="email" required placeholder="e.g. user@email.com">
            </div>
            <button type="submit" class="login-btn"><span>Send Reset Link</span></button>
        </form>
        <div class="signup">
            <a href="../pages/login.php" class="alt-link">Back to Login</a>
        </div>
        <div class="footer">
            <div class="footer-info">
                <p>&copy; 2025 iBarangay. All Rights Reserved.</p>
            </div>
            <div class="security-note">
                <svg viewBox="0 0 24 24">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
                </svg>
                <span>Secure Government Portal</span>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if(!empty($error)): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Request Failed',
                    text: '<?php echo addslashes($error); ?>'
                });
            <?php endif; ?>
            <?php if(!empty($success)): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Request Sent',
                    text: '<?php echo addslashes($success); ?>'
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>