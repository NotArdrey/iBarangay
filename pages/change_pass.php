<?php
//change_pass.php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
require "../config/dbconn.php";

// If coming via email link, get the token and email from GET parameters.
// Also allow POST values for when redirecting back after an error.
$email = isset($_GET['email']) ? $_GET['email'] : (isset($_POST['email']) ? $_POST['email'] : '');
$token = isset($_GET['token']) ? $_GET['token'] : (isset($_POST['token']) ? $_POST['token'] : '');

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
    <title>Reset Password - iBarangay</title>
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
        .reset-password-container {
            background: white;
            width: 100%;
            max-width: 500px;
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

        /* Messages */
        .error-message, .success-message {
            margin: 1.5rem;
            padding: 1rem;
            border-radius: var(--border-radius);
            font-size: 0.875rem;
            text-align: center;
        }

        .error-message {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .success-message {
            background: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        /* Content Area */
        .content {
            padding: 2rem;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            color: var(--text-dark);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .password-input-container {
            position: relative;
        }

        input[type="password"], input[type="text"] {
            width: 100%;
            padding: 0.875rem 3rem 0.875rem 0.875rem;
            border: 2px solid #e5e7eb;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all var(--transition-speed) ease;
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: white;
        }

        input[type="password"]:focus, input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.75rem;
            color: #6b7280;
            padding: 0.25rem;
            font-weight: 500;
        }

        .toggle-password:hover {
            color: var(--primary-blue);
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 0.75rem;
            padding: 1rem;
            background: #f9fafb;
            border-radius: var(--border-radius);
            border: 1px solid #e5e7eb;
            display: none;
        }

        .password-strength.show {
            display: block;
        }

        .password-strength h4 {
            color: var(--text-dark);
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
            color: #dc2626;
        }

        .requirement.valid {
            color: #16a34a;
        }

        .requirement-icon {
            font-size: 0.75rem;
            font-weight: bold;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            margin-bottom: 1rem;
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-primary:hover {
            background: var(--secondary-blue);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: white;
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }

        .btn-secondary:hover {
            background: var(--primary-blue);
            color: white;
        }

        /* Security Info */
        .security-info {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-top: 1.5rem;
        }

        .security-info h4 {
            color: var(--text-dark);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .security-info ul {
            list-style: none;
            padding: 0;
        }

        .security-info li {
            font-size: 0.8rem;
            color: #374151;
            margin-bottom: 0.25rem;
            padding-left: 1rem;
            position: relative;
        }

        .security-info li:before {
            content: '•';
            color: var(--primary-blue);
            position: absolute;
            left: 0;
        }

        /* Footer */
        .footer {
            padding: 1.5rem 2rem;
            background: #fafbfc;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }

        .footer a {
            color: var(--primary-blue);
            text-decoration: none;
            font-weight: 500;
            transition: color var(--transition-speed) ease;
        }

        .footer a:hover {
            color: var(--secondary-blue);
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .reset-password-container {
                margin: 0.5rem;
            }

            .header {
                padding: 1.5rem;
            }

            .content {
                padding: 1.5rem;
            }

            .footer {
                padding: 1rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="reset-password-container">
        <div class="header">
            <img src="../photo/logo.png" alt="iBarangay Logo">
            <h1>iBarangay</h1>
            <p>Reset Password</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Only display the forms if no success message is set -->
        <?php if (empty($success)): ?>
            <div class="content">
                <form id="resetPasswordForm" action="../functions/change_pass.php" method="post">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <div class="password-input-container">
                            <input type="password" id="new_password" name="new_password" placeholder="Enter new password" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('new_password', this)">
                                Show
                            </button>
                        </div>
                        
                        <div class="password-strength" id="passwordStrength">
                            <h4>Password Requirements</h4>
                            <div class="requirement" id="length">
                                <span class="requirement-icon">✗</span>
                                <span>At least 8 characters</span>
                            </div>
                            <div class="requirement" id="uppercase">
                                <span class="requirement-icon">✗</span>
                                <span>One uppercase letter</span>
                            </div>
                            <div class="requirement" id="lowercase">
                                <span class="requirement-icon">✗</span>
                                <span>One lowercase letter</span>
                            </div>
                            <div class="requirement" id="number">
                                <span class="requirement-icon">✗</span>
                                <span>One number</span>
                            </div>
                            <div class="requirement" id="special">
                                <span class="requirement-icon">✗</span>
                                <span>One special character</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="password-input-container">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)">
                                Show
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        Change Password
                    </button>
                </form>
                
                <form action="../functions/change_pass.php" method="post">
                    <input type="hidden" name="resend" value="1">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <button type="submit" class="btn btn-secondary">
                        Resend Reset Email
                    </button>
                </form>

                <div class="security-info">
                    <h4>Security Tips</h4>
                    <ul>
                        <li>Your new password cannot be the same as your current password</li>
                        <li>You cannot reuse any of your last 5 passwords</li>
                        <li>Choose a strong, unique password you haven't used elsewhere</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="footer">
            <a href="../pages/login.php">← Back to Login</a>
        </div>
    </div>

    <script>
        function togglePassword(fieldId, button) {
            const field = document.getElementById(fieldId);
            const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
            field.setAttribute('type', type);
            button.textContent = type === 'password' ? 'Show' : 'Hide';
        }

        function validatePasswordStrength(password) {
            const requirements = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /\d/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/.test(password)
            };

            const strengthDiv = document.getElementById('passwordStrength');
            if (password.length > 0) {
                strengthDiv.classList.add('show');
            } else {
                strengthDiv.classList.remove('show');
            }

            Object.keys(requirements).forEach(req => {
                const element = document.getElementById(req);
                const icon = element.querySelector('.requirement-icon');
                
                if (requirements[req]) {
                    element.classList.add('valid');
                    icon.textContent = '✓';
                } else {
                    element.classList.remove('valid');
                    icon.textContent = '✗';
                }
            });

            return Object.values(requirements).every(req => req);
        }

        function validatePasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (confirmPassword.length > 0) {
                if (password === confirmPassword) {
                    document.getElementById('confirm_password').style.borderColor = '#16a34a';
                } else {
                    document.getElementById('confirm_password').style.borderColor = '#dc2626';
                }
            } else {
                document.getElementById('confirm_password').style.borderColor = '#e5e7eb';
            }
        }

        document.getElementById('new_password').addEventListener('input', function() {
            validatePasswordStrength(this.value);
            validatePasswordMatch();
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            validatePasswordMatch();
        });

        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (!validatePasswordStrength(password)) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Weak Password',
                    text: 'Please ensure your password meets all the requirements.'
                });
                return;
            }

            if (password !== confirmPassword) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Password Mismatch',
                    text: 'Passwords do not match. Please try again.'
                });
                return;
            }

            const submitBtn = document.getElementById('submitBtn');
            submitBtn.textContent = 'Changing Password...';
            submitBtn.disabled = true;
        });

        <?php if (!empty($error)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: <?php echo json_encode($error); ?>
            });
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: <?php echo json_encode($success); ?>,
                timer: 3000,
                timerProgressBar: true
            }).then(() => {
                window.location.href = '../pages/login.php';
            });
        <?php endif; ?>
    </script>
</body>
</html>