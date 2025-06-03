<?php

session_start();
$error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
$success = isset($_SESSION['success']) ? $_SESSION['success'] : '';
unset($_SESSION['login_error'], $_SESSION['success']);

// Add session management code
if (isset($_SESSION['user_id'])) {
    // If user is already logged in, check if they're trying to log in as a different user
    if (isset($_POST['email'])) {
        // Get the email being used to log in
        $email = trim($_POST['email']);
        
        // If the email is different from the current session's email, show warning
        if ($email !== $_SESSION['email']) {
            $_SESSION['login_error'] = "You are already logged in as " . $_SESSION['email'] . ". Please log out first before logging in as a different user.";
            header("Location: login.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>iBarangay Login</title>
  <link rel="stylesheet" href="../styles/login.css">
  <!-- Include SweetAlert2 CSS and JS from CDN -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <div class="login-container">
    <div class="header">
      <img src="../photo/logo.png" alt="Government Logo">
      <h1>iBarangay</h1>
    </div>

    <!-- Login Form -->
    <form action="../functions/login.php" method="POST" id="login-form">
      <div class="input-group">
        <label for="email">Email</label>
        <input type="text" id="email" name="email" required>
      </div>
      <div class="input-group">
        <label for="password">Password</label>
        <div class="password-container">
          <input type="password" id="password" name="password" required>
          <button type="button" class="toggle-password visible" aria-label="Toggle password visibility">
            <div class="eye-icon">
              <svg viewBox="0 0 24 24">
                <path d="M12 5C5.64 5 1 12 1 12s4.64 7 11 7 11-7 11-7-4.64-7-11-7zm0 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/>
                <circle cx="12" cy="12" r="2.5"/>
              </svg>
              <div class="eye-slash"></div>
            </div>
          </button>
        </div>
        <div class="forget-pass">
            <a href="../pages/forget_pass.php" class="alt-link">Forgot password?</a>
        </div>
      </div>
      <button type="submit" class="login-btn"><span>Sign In</span></button>
    </form>

    <div class="signup">
      <span>Don't have an account?</span>
      <a href="../pages/register.php" class="alt-link">Sign up</a>
    </div>

    <!-- Footer -->
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
    // Toggle password visibility
    document.addEventListener('DOMContentLoaded', function() {
      const togglePassword = document.querySelector('.toggle-password');
      const passwordInput = document.getElementById('password');
      
      togglePassword.addEventListener('click', function() {
        const currentType = passwordInput.getAttribute('type');
        passwordInput.setAttribute('type', currentType === 'password' ? 'text' : 'password');
        this.classList.toggle('visible');
      });
    });

    // Display SweetAlert for login errors, if any
    document.addEventListener('DOMContentLoaded', function() {
      <?php if(!empty($error)): ?>
        Swal.fire({
          icon: 'error',
          title: 'Login Failed',
          text: '<?php echo addslashes($error); ?>'
        });
      <?php endif; ?>
    });

    // Display SweetAlert for success messages, if any
    <?php if(!empty($success)): ?>
      Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: '<?php echo addslashes($success); ?>',
        timer: 3000,
        timerProgressBar: true,
        showConfirmButton: true,
        confirmButtonText: 'OK',
        confirmButtonColor: '#3b82f6'
      });
    <?php endif; ?>
  </script>
</body>
</html>
