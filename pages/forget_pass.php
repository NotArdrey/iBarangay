<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - iBarangay</title>
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
    <div class="forget-password-container">
        <div class="header">
            <img src="../photo/logo.png" alt="iBarangay Logo">
            <h1>iBarangay</h1>
            <p>Forgot your password? No worries!</p>
        </div>

        <div class="input-group">
            <label for="email">Email Address</label>
            <form id="forgotPasswordForm" action="../functions/forget_pass.php" method="post">
                <input type="email" name="email" id="email" placeholder="Enter your email address" required>
                <button type="submit" class="submit-btn" id="submitBtn">
                    Send Reset Link
                </button>
            </form>
        </div>

        <div class="message" id="messageDiv">
            <?php
                if (isset($_GET['message'])) {
                    echo htmlspecialchars($_GET['message']);
                }
            ?>
        </div>

        <div class="security-info">
            <p>For your security, the reset link will expire in 1 hour.</p>
            <p>If you don't receive the email, please check your spam folder.</p>
        </div>

        <div class="back-to-login">
            <a href="../pages/login.php">← Back to Login</a>
        </div>
    </div>

    <script>
        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const email = document.getElementById('email').value;
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Email',
                    text: 'Please enter a valid email address.'
                });
                return;
            }

            // Show loading state
            submitBtn.textContent = 'Sending...';
            submitBtn.disabled = true;
        });

        // Show message if exists
        window.addEventListener('load', function() {
            const messageDiv = document.getElementById('messageDiv');
            const message = messageDiv.textContent.trim();
            
            if (message) {
                const isSuccess = message.toLowerCase().includes('sent') || message.toLowerCase().includes('success');
                messageDiv.className = `message ${isSuccess ? 'success' : 'error'}`;
                messageDiv.style.display = 'block';
            }
        });
    </script>
</body>
</html>