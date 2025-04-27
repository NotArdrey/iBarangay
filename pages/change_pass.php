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
    <title>Reset Password</title>
    <link rel="stylesheet" href="../styles/change_pass.css">
</head>
<body>
    <div class="reset-password-container">
        <h2>Reset Password</h2>
        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Only display the forms if no success message is set -->
        <?php if (empty($success)): ?>
            <!-- Password Reset Form -->
            <form action="../functions/change_pass.php" method="post">
                <!-- Pass email and token in hidden fields -->
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="password" name="new_password" placeholder="Enter new password" required>
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                <input type="submit" value="Change Password">
            </form>
            
            <!-- Resend Email Form -->
            <form action="../functions/change_pass.php" method="post" class="resend-form">
                <!-- Indicate that this POST is for resending the email -->
                <input type="hidden" name="resend" value="1">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <button type="submit">Resend Reset Email</button>
            </form>
        <?php endif; ?>
    </div>
    
    <?php
    // Optionally display other alerts stored in session.
    if(isset($_SESSION['alert'])) {
        echo $_SESSION['alert'];
        unset($_SESSION['alert']);
    }
    ?>
</body>
</html>
