<?php

session_start();
$error = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
unset($_SESSION['login_error']);


session_regenerate_id(true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Barangay Hub Login</title>
  <link rel="stylesheet" href="../styles/index.css">
  <!-- Include SweetAlert2 CSS and JS from CDN -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body>
  <div class="login-container">
    <div class="header">
      <img src="../photo/logo.png" alt="Government Logo">
      <h1>Barangay Hub</h1>
    </div>

    <!-- Login Form -->
    <form action="../functions/index.php" method="POST" id="login-form">
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

    <!-- Divider between standard and social login -->
    <div class="divider">
      <span>or sign in with</span>
    </div>

    <!-- Social Login Buttons -->
    <div class="social-login">
      <button type="button" class="social-btn google-btn" id="google-signin-button">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
          <path fill="#EA4335" d="M24 9.5c3.54 0 6.76 1.22 9.25 3.22l6.87-6.87C35.29 3.12 30.06 1 24 1 14.43 1 6.27 5.73 2.54 12.2l7.75 6.02C12.15 12.35 17.61 9.5 24 9.5z"/>
          <path fill="#4285F4" d="M46.19 24.5c0-1.64-.15-3.21-.43-4.73H24v9h12.53c-.54 2.87-2.15 5.29-4.58 6.93l7.25 5.64c4.26-3.92 6.76-9.68 6.76-16.84z"/>
          <path fill="#FBBC05" d="M9.29 28.9a14.18 14.18 0 0 1 0-9.8l-7.75-6.02A23.984 23.984 0 0 0 0 24c0 3.78.9 7.35 2.54 10.2l7.75-6.3z"/>
          <path fill="#34A853" d="M24 47c6.48 0 11.91-2.14 15.88-5.81l-7.25-5.64c-2.01 1.35-4.57 2.15-8.63 2.15-6.39 0-11.85-3.85-13.76-9.21l-7.75 6.02C6.27 42.27 14.43 47 24 47z"/>
          <path fill="none" d="M0 0h48v48H0z"/>
        </svg>
        <span>Google</span>
      </button>
      <div class="signup">
        <span>Don’t have an account?</span>
        <a href="../pages/register.php" class="alt-link">Sign up</a>
      </div>
    </div>

    <!-- Footer -->
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
    // Google Sign-In handler
    function handleCredentialResponse(response) {
      console.log("Encoded JWT ID token: " + response.credential);
      fetch('../functions/index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: response.credential })
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          window.location.href = data.redirect;
        } else {
          console.error('Google Sign-In error:', data.error);
        }
      })
      .catch(error => console.error('Fetch error:', error));
    }
    
    window.onload = function() {
      google.accounts.id.initialize({
        client_id: "1070456838675-ol86nondnkulmh8s9c5ceapm42tsampq.apps.googleusercontent.com",
        callback: handleCredentialResponse,
        auto_select: false
      });
      google.accounts.id.renderButton(
        document.getElementById("google-signin-button"),
        {
          type: "standard",
          theme: "outline",
          size: "large",
          text: "signin_with",
          shape: "rectangular"
        }
      );
    };

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
  </script>
</body>
</html>
