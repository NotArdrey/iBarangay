<?php
// Only start session if one hasn't been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/dbconn.php";
require_once "../functions/login.php";

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
  <div class="login-wrapper">
    <div class="branding-side">
      <div class="branding-content">
        <img src="../photo/logo.png" alt="iBarangay Logo">
        <h1>iBarangay</h1>
        <p>Your one-stop portal for barangay services. Access announcements, documents, and more with ease.</p>
      </div>
    </div>

    <div class="form-side">
      <div class="login-container">
        <div class="header">
          <h1>Welcome Back!</h1>
          <p>Sign in to continue to iBarangay.</p>
        </div>

        <!-- Login Form -->
        <form action="../functions/login.php" method="POST" id="login-form">
          <div class="input-group">
            <label for="email">Email</label>
            <input type="text" id="email" name="email" required placeholder="you@example.com">
          </div>
          <div class="input-group">
            <label for="password">Password</label>
            <div class="password-container">
              <input type="password" id="password" name="password" required placeholder="••••••••">
              <button type="button" class="toggle-password visible" aria-label="Toggle password visibility">
                <div class="eye-icon">
                  <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 5C5.64 5 1 12 1 12s4.64 7 11 7 11-7 11-7-4.64-7-11-7zm0 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" fill="currentColor"/>
                    <circle cx="12" cy="12" r="2.5" fill="currentColor"/>
                  </svg>
                  <div class="eye-slash"></div>
                </div>
              </button>
            </div>
          </div>
          
          <div class="login-options">
            <a href="../pages/forget_pass.php" class="alt-link">Forgot password?</a>
          </div>

          <button type="submit" class="login-btn"><span>Sign In</span></button>
        </form>

        <div class="signup">
          <span>Don't have an account?</span>
          <a href="../pages/register.php" class="alt-link">Sign up</a>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Toggle password visibility
    document.addEventListener('DOMContentLoaded', function() {
      const togglePassword = document.querySelector('.toggle-password');
      if (togglePassword) {
        const passwordInput = document.getElementById('password');
        
        togglePassword.addEventListener('click', function() {
          const currentType = passwordInput.getAttribute('type');
          passwordInput.setAttribute('type', currentType === 'password' ? 'text' : 'password');
          this.classList.toggle('visible');
        });
      }
    });

    // Display SweetAlert for login errors, if any
    document.addEventListener('DOMContentLoaded', function() {
      <?php if(!empty($error)): ?>
        Swal.fire({
          icon: 'error',
          title: 'Login Failed',
          text: '<?php echo addslashes($error); ?>',
          confirmButtonColor: '#4F46E5'
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
        confirmButtonColor: '#4F46E5'
      });
    <?php endif; ?>
  </script>
</body>
</html>
