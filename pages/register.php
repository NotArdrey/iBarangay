<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Barangay Hub Register</title>
  <link rel="stylesheet" href="../styles/register.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
  <div class="register-container">
    <div class="header">
      <img src="../photo/logo.png" alt="Government Logo">
      <h1>Create Account</h1>
    </div>
    <form action="../functions/register.php" method="POST">
      <div class="input-group">
        <input type="hidden" name="role_id" value="3">
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
      </div>
      <div class="input-group">
        <label for="confirmPassword">Confirm Password</label>
        <div class="password-container">
          <input type="password" id="confirmPassword" name="confirmPassword" required>
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
      </div>
      <button type="submit" class="register-btn"><span>Register</span></button>
    </form>
    <div class="footer-links">
        <a href="../pages/index.php" class="help-link">Back to Login</a>
    </div>
    <div class="footer">
      <div class="footer-info">
        <p>&copy; 2025 Barangay Hub. All Rights Reserved.</p>
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
    // Toggle password visibility for both password fields.
    const toggleButtons = document.querySelectorAll('.toggle-password');
    toggleButtons.forEach(function(toggle) {
      toggle.addEventListener('click', function() {
        const passwordInput = this.parentElement.querySelector('input');
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        this.classList.toggle('visible');
      });
    });
  </script>
</body>
</html>
<?php
if(isset($_SESSION['alert'])) {
    echo $_SESSION['alert'];
    unset($_SESSION['alert']);
}
?>
