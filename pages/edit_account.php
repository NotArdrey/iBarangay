<?php
session_start();
require "../config/dbconn.php"; // This file creates a PDO instance as $pdo

// Only allow logged-in users; if not, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Retrieve all columns from Users table for the logged-in user
$query = "SELECT * FROM Users WHERE user_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$error_message = '';
$success_message = '';

// Process form submission (for editable fields and password change)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Retrieve and trim values for the editable fields (email is not editable here)
    $first_name                = trim($_POST['first_name']);
    $middle_name               = trim($_POST['middle_name']);
    $last_name                 = trim($_POST['last_name']);
    $birth_date                = $_POST['birth_date'];
    $gender                    = $_POST['gender'];
    $contact_number            = trim($_POST['contact_number']);
    $marital_status            = $_POST['marital_status'];
    $emergency_contact_name    = trim($_POST['emergency_contact_name']);
    $emergency_contact_number  = trim($_POST['emergency_contact_number']);
    $emergency_contact_address = trim($_POST['emergency_contact_address']);
    $barangay_id               = $_POST['barangay_id'];
    
    // Retrieve password change fields (if any)
    $old_password      = trim($_POST['old_password'] ?? '');
    $new_password      = trim($_POST['new_password'] ?? '');
    $confirm_password  = trim($_POST['confirm_password'] ?? '');

    // Validate required fields for personal details
    $errors = [];
    if (empty($first_name)) { $errors[] = "First name is required."; }
    if (empty($last_name)) { $errors[] = "Last name is required."; }
    if (empty($birth_date)) { $errors[] = "Birth date is required."; }
    if (empty($gender)) { $errors[] = "Gender is required."; }
    if (empty($contact_number)) { $errors[] = "Contact number is required."; }

    // If any of the password change fields are provided, process password update
    if ($old_password !== '' || $new_password !== '' || $confirm_password !== '') {
        // Ensure all password fields are filled
        if (empty($old_password)) { $errors[] = "Old password is required for password change."; }
        if (empty($new_password)) { $errors[] = "New password is required."; }
        if (empty($confirm_password)) { $errors[] = "Confirm password is required."; }
        
        // Check new password and confirmation match
        if ($new_password !== $confirm_password) {
            $errors[] = "New password and confirm password do not match.";
        }
        
        // Verify old password (using SHA-256 as in your existing logic)
        $old_password_hash = hash('sha256', $old_password);
        if ($old_password_hash !== $user['password_hash']) {
            $errors[] = "Old password is incorrect.";
        }
    }

    // If there are no errors, update the personal details and possibly the password
    if (empty($errors)) {
        // Update personal details (note: email is omitted)
        $updateQuery = "UPDATE Users SET 
                            first_name = ?, 
                            middle_name = ?, 
                            last_name = ?, 
                            birth_date = ?, 
                            gender = ?, 
                            contact_number = ?, 
                            marital_status = ?, 
                            emergency_contact_name = ?, 
                            emergency_contact_number = ?, 
                            emergency_contact_address = ?, 
                            barangay_id = ?
                        WHERE user_id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $params = [
            $first_name,
            $middle_name,
            $last_name,
            $birth_date,
            $gender,
            $contact_number,
            $marital_status,
            $emergency_contact_name,
            $emergency_contact_number,
            $emergency_contact_address,
            $barangay_id,
            $user_id
        ];
        $updateStmt->execute($params);

        // Update password if requested
        if ($old_password !== '' && $new_password !== '' && $confirm_password !== '') {
            $new_password_hash = hash('sha256', $new_password);
            $updatePassQuery = "UPDATE Users SET password_hash = ? WHERE user_id = ?";
            $stmt_pass = $pdo->prepare($updatePassQuery);
            $stmt_pass->execute([$new_password_hash, $user_id]);
            $success_message .= " Your password has been changed successfully.";
        }
        $success_message = "Your account has been updated successfully." . $success_message;
        
        // Refresh the user data
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $error_message = implode("<br>", $errors);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Account</title>
  <link rel="stylesheet" href="../styles/edit_account.css">
  <!-- Font Awesome for icons -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Optional styling for grouping fields */
    .form-section { border: 1px solid #ccc; padding: 1em; margin-bottom: 1em; }
    .form-section h3 { margin-top: 0; }
    .btn { padding: 0.5em 1em; text-decoration: none; border: 1px solid #ccc; background: #f5f5f5; }
  </style>
</head>
<body>
  <!-- Navigation Header -->
  <header>
    <nav class="navbar">
      <a href="#" class="logo">
        <img src="../photo/logo.png" alt="Barangay Hub Logo" />
        <h2>Barangay Hub</h2>
      </a>
      <button class="mobile-menu-btn" aria-label="Toggle navigation menu">
        <i class="fas fa-bars"></i>
      </button>
      <div class="nav-links">
        <a href="../pages/user_dashboard.php#home">Home</a>
        <a href="../pages/user_dashboard.php#about">About</a>
        <a href="../pages/user_dashboard.php#services">Services</a>
        <a href="../pages/user_dashboard.php#contact">Contact</a>
        <a href="edit_account.php">Account</a>
        <a href="../functions/logout.php" style="color: red;"><i class="fas fa-sign-out-alt"></i> Logout</a>
      </div>
    </nav>
  </header>

  <!-- Main Content -->
  <main class="edit-account-section">

    <?php if (!empty($error_message)): ?>
      <script>
          Swal.fire({
            icon: "error",
            title: "Error",
            html: <?php echo json_encode($error_message); ?>
          });
      </script>
    <?php endif; ?>
    
    <?php if (!empty($success_message)): ?>
      <script>
          Swal.fire({
            icon: "success",
            title: "Success",
            text: <?php echo json_encode($success_message); ?>
          });
      </script>
    <?php endif; ?>

    <form class="account-form" action="" method="POST">
      <!-- Non-Editable Section -->
      <div class="form-section">
        <h3>Account Information (Read-Only)</h3>
        <div class="form-group">
          <label for="email">Email</label>
          <!-- Display email as read-only and provide a link to change email -->
          <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
          <a href="change_email.php" class="btn" style="margin-top: 0.5em; display: inline-block; color:black;">Change Email</a>
        </div>
      </div>

      <!-- Editable Section: Personal Details -->
      <div class="form-section">
        <h3>Personal Details (Editable)</h3>
        <div class="form-group">
          <label for="first_name">First Name *</label>
          <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="middle_name">Middle Name</label>
          <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="last_name">Last Name *</label>
          <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="birth_date">Birth Date *</label>
          <input type="date" id="birth_date" name="birth_date" value="<?php echo htmlspecialchars($user['birth_date'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="gender">Gender *</label>
          <select name="gender" id="gender" required>
            <option value="">Select Gender</option>
            <option value="Male" <?php echo (isset($user['gender']) && $user['gender'] === "Male") ? 'selected' : ''; ?>>Male</option>
            <option value="Female" <?php echo (isset($user['gender']) && $user['gender'] === "Female") ? 'selected' : ''; ?>>Female</option>
            <option value="Others" <?php echo (isset($user['gender']) && $user['gender'] === "Others") ? 'selected' : ''; ?>>Others</option>
          </select>
        </div>
        <div class="form-group">
          <label for="contact_number">Contact Number *</label>
          <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="marital_status">Marital Status</label>
          <select name="marital_status" id="marital_status">
            <option value="">Select Status</option>
            <option value="Single" <?php echo (isset($user['marital_status']) && $user['marital_status'] === "Single") ? 'selected' : ''; ?>>Single</option>
            <option value="Married" <?php echo (isset($user['marital_status']) && $user['marital_status'] === "Married") ? 'selected' : ''; ?>>Married</option>
            <option value="Widowed" <?php echo (isset($user['marital_status']) && $user['marital_status'] === "Widowed") ? 'selected' : ''; ?>>Widowed</option>
            <option value="Separated" <?php echo (isset($user['marital_status']) && $user['marital_status'] === "Separated") ? 'selected' : ''; ?>>Separated</option>
          </select>
        </div>
        <!-- Removed Senior/PWD and Solo Parent fields -->
        <div class="form-group">
          <label for="emergency_contact_name">Emergency Contact Name</label>
          <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="emergency_contact_number">Emergency Contact Number</label>
          <input type="text" id="emergency_contact_number" name="emergency_contact_number" value="<?php echo htmlspecialchars($user['emergency_contact_number'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="emergency_contact_address">Emergency Contact Address</label>
          <input type="text" id="emergency_contact_address" name="emergency_contact_address" value="<?php echo htmlspecialchars($user['emergency_contact_address'] ?? ''); ?>">
        </div>
      </div>

      <!-- Editable Section: Change Password -->
      <div class="form-section">
        <h3>Change Password</h3>
        <p>If you want to change your password, fill in the fields below.</p>
        <div class="form-group">
          <label for="old_password">Old Password</label>
          <input type="password" id="old_password" name="old_password">
        </div>
        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password">
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password">
        </div>
      </div>

      <!-- Form Actions -->
      <div class="form-actions">
        <button type="reset" class="btn secondary-btn">Reset</button>
        <button type="submit" class="btn cta-button">Update Account</button>
      </div>
    </form>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <p>&copy; 2025 Barangay Hub. All rights reserved.</p>
  </footer>

  <script>
    // Mobile menu toggle functionality
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    mobileMenuBtn.addEventListener('click', () => {
      navLinks.classList.toggle('active');
    });
  </script>
</body>
</html>
<?php
// functions.php

/**
 * Returns the dashboard URL based on the user's role.
 *
 * @param int $role_id The user's role ID.
 * @return string The appropriate dashboard URL.
 */
function getDashboardUrl($role_id) {
    if ($role_id == 1) {
        return "../pages/super_admin_dashboard.php";
    } elseif ($role_id == 2) {
        return "../pages/barangay_admin_dashboard.php";
    } else {
        return "../pages/user_dashboard.php";
    }
}

/**
 * Loads the barangay name for a given user based on email.
 *
 * @param PDO|mysqli $pdo The database connection object.
 * @param string $email The user's email.
 * @return string|null The barangay name if found, or null otherwise.
 */
function loadBarangayInfo($pdo, $email) {
    // Retrieve the barangay_id from the Users table
    $stmt = $pdo->prepare("SELECT barangay_id FROM Users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userRecord && !empty($userRecord['barangay_id'])) {
        $stmt2 = $pdo->prepare("SELECT barangay_name FROM Barangay WHERE barangay_id = :barangay_id LIMIT 1");
        $stmt2->execute([':barangay_id' => $userRecord['barangay_id']]);
        $barangayRecord = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($barangayRecord) {
            return $barangayRecord['barangay_name'];
        }
    }
    return null;
}
?>
