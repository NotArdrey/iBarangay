<?php
session_start();
require "../config/dbconn.php"; // This file creates a PDO instance as $pdo

// Only allow logged-in users; if not, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Retrieve all columns from Users table for the logged-in user
$query = "SELECT * FROM users WHERE id = ?";  // Changed from user_id to id
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
        if ($old_password_hash !== $user['password']) {  // Changed from password_hash to password
            $errors[] = "Old password is incorrect.";
        }
    }

    // If there are no errors, update the personal details and possibly the password
    if (empty($errors)) {
        // Update personal details (note: email is omitted)
        $updateQuery = "UPDATE users SET 
                            first_name = ?, 
                            last_name = ?, 
                            gender = ?, 
                            phone = ?, 
                            barangay_id = ?
                        WHERE id = ?";  // Changed from user_id to id
        $updateStmt = $pdo->prepare($updateQuery);
        $params = [
            $first_name,
            $last_name,
            $gender,
            $contact_number,  // Maps to phone column
            $barangay_id,
            $user_id
        ];
        $updateStmt->execute($params);

        // Update password if requested
        if ($old_password !== '' && $new_password !== '' && $confirm_password !== '') {
            $new_password_hash = hash('sha256', $new_password);
            $updatePassQuery = "UPDATE users SET password = ? WHERE id = ?";  // Changed from password_hash to password and user_id to id
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

// Get barangay list for the dropdown
$barangayQuery = "SELECT id, name FROM barangay ORDER BY name";
$barangayStmt = $pdo->prepare($barangayQuery);
$barangayStmt->execute();
$barangays = $barangayStmt->fetchAll(PDO::FETCH_ASSOC);

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
 <!-- Navigation Bar -->
<header> 
  <nav class="navbar">
    <a href="#" class="logo">
      <img src="../photo/logo.png" alt="iBarangay Logo" />
      <h2>iBarangay</h2>
    </a>
    <button class="mobile-menu-btn" aria-label="Toggle navigation menu">
      <i class="fas fa-bars"></i>
    </button>
    <div class="nav-links">
      <a href="#home">Home</a>
      <a href="#about">About</a>
      <a href="#services">Services</a>
      <a href="#contact">Contact</a>
      
      <!-- User Info Section -->
      <?php if (!empty($userName)): ?>
      <div class="user-info" onclick="window.location.href='../pages/edit_account.php'" style="cursor: pointer;">
        <div class="user-avatar">
          <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-details">
          <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
          <div class="user-barangay"><?php echo htmlspecialchars($barangayName); ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </nav>
</header>

  <!-- Add CSS for User Info in Navbar -->
  <style>
  /* User Info Styles - Minimalist Version */
.user-info {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.5rem 1rem;
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    color: #333333;
    margin-left: 1rem;
    transition: all 0.2s ease;
}

.user-info:hover {
    background: #f8f8f8;
    border-color: #d0d0d0;
}

.user-avatar {
    font-size: 1.5rem;
    color: #666666;
    display: flex;
    align-items: center;
}

.user-details {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.user-name {
    font-size: 0.9rem;
    font-weight: 500;
    color: #0a2240; /* navy blue */
}

.user-barangay {
    font-size: 0.75rem;
    color: #0a2240; /* navy blue */
}
  </style>
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

    <form class="account-form" action="" method="POST">      <!-- Non-Editable Section -->
      <div class="form-section">
        <h3>Account Information (Read-Only)</h3>
        <div class="form-group">
          <label for="email">Email</label>
          <!-- Display email as read-only and provide a link to change email -->
          <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
          <a href="change_email.php" class="btn" style="margin-top: 0.5em; display: inline-block; color:black;">Change Email</a>
        </div>
        
        <!-- Government ID Display -->
        <div class="form-group" style="margin-top: 1.5em;">
          <label>Government ID</label>
          <div style="margin-top: 0.5em;">
            <?php if(!empty($user['govt_id_image'])): ?>
              <div class="govt-id-container" style="border: 1px solid #ddd; padding: 10px; border-radius: 5px; background-color: #f9f9f9;">
                <img src="data:image/jpeg;base64,<?php echo base64_encode($user['govt_id_image']); ?>" 
                     alt="Government ID" 
                     style="max-width: 100%; max-height: 300px; display: block; margin: 0 auto; cursor: pointer;" 
                     onclick="openImageModal(this.src)">
                <p style="text-align: center; margin-top: 8px; font-size: 0.9em; color: #666;">Click on image to enlarge</p>
              </div>
            <?php else: ?>
              <p>No government ID uploaded.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      
      <!-- Modal for Enlarged Image -->
      <div id="imageModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.9); overflow: auto;">
        <span style="position: absolute; top: 15px; right: 25px; color: #f1f1f1; font-size: 35px; font-weight: bold; cursor: pointer;" onclick="closeImageModal()">&times;</span>
        <img id="modalImage" style="display: block; margin: 60px auto; max-width: 90%; max-height: 90%;">
      </div>

      <!-- Editable Section: Personal Details -->
      <div class="form-section">
        <h3>Personal Details (Editable)</h3>
        <div class="form-group">
          <label for="first_name">First Name *</label>
          <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="last_name">Last Name *</label>
          <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="gender">Gender</label>
          <select name="gender" id="gender">
            <option value="">Select Gender</option>
            <option value="Male" <?php echo (isset($user['gender']) && $user['gender'] === "Male") ? 'selected' : ''; ?>>Male</option>
            <option value="Female" <?php echo (isset($user['gender']) && $user['gender'] === "Female") ? 'selected' : ''; ?>>Female</option>
            <option value="Others" <?php echo (isset($user['gender']) && $user['gender'] === "Others") ? 'selected' : ''; ?>>Others</option>
          </select>
        </div>
        <div class="form-group">
          <label for="contact_number">Contact Number</label>
          <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="barangay_id">Barangay</label>
          <select name="barangay_id" id="barangay_id">
            <option value="">Select Barangay</option>
            <?php foreach ($barangays as $barangay): ?>
            <option value="<?php echo $barangay['id']; ?>" <?php echo (isset($user['barangay_id']) && $user['barangay_id'] == $barangay['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($barangay['name']); ?>
            </option>
            <?php endforeach; ?>
          </select>
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
    <p>&copy; 2025 iBarangay. All rights reserved.</p>
  </footer>
  <script>
    // Mobile menu toggle functionality
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    mobileMenuBtn.addEventListener('click', () => {
      navLinks.classList.toggle('active');
    });
    
    // Image modal functionality
    function openImageModal(src) {
      document.getElementById('modalImage').src = src;
      document.getElementById('imageModal').style.display = 'block';
      document.body.style.overflow = 'hidden'; // Prevent scrolling when modal is open
    }
    
    function closeImageModal() {
      document.getElementById('imageModal').style.display = 'none';
      document.body.style.overflow = 'auto'; // Restore scrolling
    }
    
    // Close modal when clicking outside the image
    document.getElementById('imageModal').addEventListener('click', function(event) {
      if (event.target === this) {
        closeImageModal();
      }
    });
    
    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape' && document.getElementById('imageModal').style.display === 'block') {
        closeImageModal();
      }
    });
  </script>
</body>
</html>