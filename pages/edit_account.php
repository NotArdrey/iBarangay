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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    // Retrieve and trim values for the editable fields
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $gender = $_POST['gender'];
    $contact_number = trim($_POST['contact_number']);
    $barangay_id = $_POST['barangay_id'];
    
    // Retrieve password change fields (if any)
    $old_password = trim($_POST['old_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validate required fields for personal details
    $errors = [];
    if (empty($first_name)) { $errors[] = "First name is required."; }
    if (empty($last_name)) { $errors[] = "Last name is required."; }
    
    // Only validate password if any password field is filled
    if (!empty($old_password) || !empty($new_password) || !empty($confirm_password)) {
        if (empty($old_password)) { $errors[] = "Old password is required for password change."; }
        if (empty($new_password)) { $errors[] = "New password is required."; }
        if (empty($confirm_password)) { $errors[] = "Confirm password is required."; }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "New password and confirm password do not match.";
        }
        
        if (!empty($old_password)) {
            $old_password_hash = hash('sha256', $old_password);
            if ($old_password_hash !== $user['password']) {
                $errors[] = "Old password is incorrect.";
            }
        }
    }

    // If there are no errors, update the personal details and possibly the password
    if (empty($errors)) {
        // Update personal details
        $updateQuery = "UPDATE users SET 
                            first_name = ?, 
                            last_name = ?, 
                            gender = ?, 
                            phone = ?, 
                            barangay_id = ?
                        WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $params = [
            $first_name,
            $last_name,
            $gender,
            $contact_number,
            $barangay_id,
            $user_id
        ];
        $updateStmt->execute($params);

        // Update password if requested
        if (!empty($old_password) && !empty($new_password) && !empty($confirm_password)) {
            $new_password_hash = hash('sha256', $new_password);
            $updatePassQuery = "UPDATE users SET password = ? WHERE id = ?";
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
    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      opacity: 0;
      transition: opacity 0.2s ease;
    }

    .modal.show {
      opacity: 1;
    }

    .modal-content {
      background-color: #fff;
      margin: 5% auto;
      width: 90%;
      max-width: 600px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      transform: translateY(-20px);
      opacity: 0;
      transition: all 0.2s ease;
    }

    .modal.show .modal-content {
      transform: translateY(0);
      opacity: 1;
    }

    .modal-header {
      padding: 1rem;
      border-bottom: 1px solid #eee;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h2 {
      margin: 0;
      font-size: 1.2rem;
      color: #333;
    }

    .close {
      color: #666;
      font-size: 24px;
      cursor: pointer;
      border: none;
      background: none;
      padding: 0;
    }

    .close:hover {
      color: #333;
    }

    .modal-body {
      padding: 1rem;
      max-height: 60vh;
      overflow-y: auto;
    }

    /* Simplified Tab Styles */
    .tabs {
      display: flex;
      border-bottom: 1px solid #eee;
      margin-bottom: 1rem;
    }

    .tab-btn {
      padding: 0.75rem 1rem;
      border: none;
      background: none;
      color: #666;
      cursor: pointer;
      font-size: 0.9rem;
      position: relative;
      transition: color 0.2s;
    }

    .tab-btn:hover {
      color: #333;
    }

    .tab-btn.active {
      color: #0a2240;
      font-weight: 500;
    }

    .tab-btn.active::after {
      content: '';
      position: absolute;
      bottom: -1px;
      left: 0;
      width: 100%;
      height: 2px;
      background: #0a2240;
    }

    .tab-content {
      display: none;
      padding: 0.5rem 0;
    }

    .tab-content.active {
      display: block;
    }

    /* Simplified Document List Styles */
    .document-list {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .document-item {
      padding: 0.75rem;
      border: 1px solid #eee;
      border-radius: 4px;
      background: #fafafa;
    }

    .document-info h4 {
      margin: 0 0 0.5rem 0;
      font-size: 0.95rem;
      color: #333;
    }

    .document-info p {
      margin: 0.25rem 0;
      font-size: 0.85rem;
      color: #666;
    }

    /* Status Colors */
    .status-pending { color: #f0ad4e; }
    .status-processing { color: #5bc0de; }
    .status-for_payment { color: #5cb85c; }
    .status-completed { color: #28a745; }
    .status-cancelled { color: #d9534f; }
    .status-rejected { color: #d9534f; }
    .status-for_pickup { color: #17a2b8; }

    /* View Documents Button */
    .view-docs-btn {
      padding: 0.5rem 1rem;
      background: #f8f9fa;
      color: #333;
      border: 1px solid #ddd;
      border-radius: 4px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      transition: all 0.2s;
    }

    .view-docs-btn:hover {
      background: #e9ecef;
      border-color: #ccc;
    }

    @media (max-width: 768px) {
      .modal-content {
        width: 95%;
        margin: 10% auto;
      }
      
      .tabs {
        flex-wrap: wrap;
      }
      
      .tab-btn {
        flex: 1;
        text-align: center;
        padding: 0.5rem;
      }
    }

    /* Password Change Modal Specific Styles */
    .password-step {
      padding: 1rem;
    }
    
    .password-step h3 {
      margin-bottom: 1rem;
      color: #0a2240;
      font-size: 1.1rem;
    }
    
    .password-step .form-group {
      margin-bottom: 1rem;
    }
    
    .password-step button {
      margin-right: 0.5rem;
    }
    
    .password-step p {
      margin-bottom: 1rem;
      color: #666;
    }
  </style>
</head>

<body>
  <!-- Navigation Header -->
  <header> 
  <nav class="navbar">
    <a href="../index.php" class="logo">
      <img src="../photo/logo.png" alt="iBarangay Logo" />
      <h2>iBarangay</h2>
    </a>
    <button class="mobile-menu-btn" aria-label="Toggle navigation menu">
      <i class="fas fa-bars"></i>
    </button>
    <div class="nav-links">
          <a href="../pages/user_dashboard.php#home">Home</a>
          <a href="../pages/user_dashboard.php#about">About</a>
          <a href="../pages/user_dashboard.php#services">Services</a>
          <a href="../pages/user_dashboard.php#contact">Contact</a>
      <a href="../functions/logout.php" class="logout-btn" onclick="return confirmLogout()">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
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

    <form class="account-form" action="" method="POST">
        <!-- Add a hidden input to identify form submission -->
        <input type="hidden" name="update_account" value="1">

      <!-- View Documents History Section -->
      <div class="form-section">
        <h3>View Documents History</h3>
        <div class="form-group">
          <button type="button" class="view-docs-btn" onclick="openDocumentModal()">
            <i class="fas fa-file-alt"></i> View Document History
          </button>
        </div>
      </div>

      <!-- Document History Modal -->
      <div id="documentModal" class="modal">
        <div class="modal-content">
          <div class="modal-header">
            <h2>Document History</h2>
            <button type="button" class="close" onclick="closeDocumentModal()">&times;</button>
          </div>
          <div class="modal-body">
            <div class="tabs">
              <button class="tab-btn active" onclick="openTab(event, 'document-history')">Recent</button>
              <button class="tab-btn" onclick="openTab(event, 'pending-requests')">Pending</button>
              <button class="tab-btn" onclick="openTab(event, 'completed-history')">Completed</button>
            </div>

            <div id="document-history" class="tab-content active">
              <?php
              $docHistoryQuery = "SELECT dr.*, dt.name as document_name 
                                 FROM document_requests dr 
                                 JOIN document_types dt ON dr.document_type_id = dt.id 
                                 WHERE dr.user_id = ? 
                                 ORDER BY dr.created_at DESC 
                                 LIMIT 5";
              $docHistoryStmt = $pdo->prepare($docHistoryQuery);
              $docHistoryStmt->execute([$user_id]);
              $documentHistory = $docHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
              
              if (count($documentHistory) > 0): ?>
                <div class="document-list">
                  <?php foreach ($documentHistory as $doc): ?>
                    <div class="document-item">
                      <div class="document-info">
                        <h4><?php echo htmlspecialchars($doc['document_name']); ?></h4>
                        <p>Status: <span class="status-<?php echo $doc['status']; ?>"><?php echo ucfirst($doc['status']); ?></span></p>
                        <p>Requested: <?php echo date('M d, Y', strtotime($doc['created_at'])); ?></p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p>No document history found.</p>
              <?php endif; ?>
            </div>

            <div id="pending-requests" class="tab-content">
              <?php
              $pendingQuery = "SELECT dr.*, dt.name as document_name 
                              FROM document_requests dr 
                              JOIN document_types dt ON dr.document_type_id = dt.id 
                              WHERE dr.user_id = ? AND dr.status IN ('pending', 'processing', 'for_payment') 
                              ORDER BY dr.created_at DESC";
              $pendingStmt = $pdo->prepare($pendingQuery);
              $pendingStmt->execute([$user_id]);
              $pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
              
              if (count($pendingRequests) > 0): ?>
                <div class="document-list">
                  <?php foreach ($pendingRequests as $request): ?>
                    <div class="document-item">
                      <div class="document-info">
                        <h4><?php echo htmlspecialchars($request['document_name']); ?></h4>
                        <p>Status: <span class="status-<?php echo $request['status']; ?>"><?php echo ucfirst($request['status']); ?></span></p>
                        <p>Requested: <?php echo date('M d, Y', strtotime($request['created_at'])); ?></p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p>No pending requests found.</p>
              <?php endif; ?>
            </div>

            <div id="completed-history" class="tab-content">
              <?php
              $completedQuery = "SELECT dr.*, dt.name as document_name 
                                FROM document_requests dr 
                                JOIN document_types dt ON dr.document_type_id = dt.id 
                                WHERE dr.user_id = ? AND dr.status = 'completed' 
                                ORDER BY dr.completed_at DESC 
                                LIMIT 5";
              $completedStmt = $pdo->prepare($completedQuery);
              $completedStmt->execute([$user_id]);
              $completedRequests = $completedStmt->fetchAll(PDO::FETCH_ASSOC);
              
              if (count($completedRequests) > 0): ?>
                <div class="document-list">
                  <?php foreach ($completedRequests as $request): ?>
                    <div class="document-item">
                      <div class="document-info">
                        <h4><?php echo htmlspecialchars($request['document_name']); ?></h4>
                        <p>Status: <span class="status-<?php echo $request['status']; ?>"><?php echo ucfirst($request['status']); ?></span></p>
                        <p>Completed: <?php echo date('M d, Y', strtotime($request['completed_at'])); ?></p>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p>No completed requests found.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Non-Editable Section -->
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
            <?php if (!empty($user['govt_id_image'])): ?>
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

      <!-- Change Password Button -->
      <div class="form-section">
        <h3>Change Password</h3>
        <div class="form-group">
          <button type="button" class="btn cta-button" onclick="openPasswordChangeModal()">
            <i class="fas fa-key"></i> Change Password
          </button>
        </div>
      </div>

      <!-- Form Actions -->
      <div class="form-actions">
        <button type="reset" class="btn secondary-btn">Reset</button>
        <button type="submit" class="btn cta-button">Update Account</button>
      </div>
    </form>
  </main>

  <!-- Password Change Modal -->
  <div id="passwordChangeModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h2>Change Password</h2>
        <button type="button" class="close" onclick="closePasswordChangeModal()">&times;</button>
      </div>
      <div class="modal-body">
        <!-- Step 1: Enter Old Password -->
        <div id="step1" class="password-step">
          <h3>Step 1: Verify Current Password</h3>
          <div class="form-group">
            <label for="modal_old_password">Current Password</label>
            <input type="password" id="modal_old_password" name="modal_old_password">
          </div>
          <button type="button" class="btn cta-button" onclick="verifyOldPassword()">Continue</button>
        </div>

        <!-- Step 2: Email Verification -->
        <div id="step2" class="password-step" style="display: none;">
          <h3>Step 2: Email Verification</h3>
          <p>A verification code has been sent to your email address.</p>
          <div class="form-group">
            <label for="verification_code">Enter Verification Code</label>
            <input type="text" id="verification_code" name="verification_code">
          </div>
          <button type="button" class="btn cta-button" onclick="verifyCode()">Verify Code</button>
          <button type="button" class="btn secondary-btn" onclick="resendCode()">Resend Code</button>
        </div>

        <!-- Step 3: New Password -->
        <div id="step3" class="password-step" style="display: none;">
          <h3>Step 3: Set New Password</h3>
          <div class="form-group">
            <label for="modal_new_password">New Password</label>
            <input type="password" id="modal_new_password" name="modal_new_password">
          </div>
          <div class="form-group">
            <label for="modal_confirm_password">Confirm New Password</label>
            <input type="password" id="modal_confirm_password" name="modal_confirm_password">
          </div>
          <button type="button" class="btn cta-button" onclick="updatePassword()">Update Password</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <footer class="footer">
    <p>&copy; 2025 iBarangay. All rights reserved.</p>
  </footer>

  <script>
    // Modal Functions
    function openDocumentModal() {
      const modal = document.getElementById('documentModal');
      modal.style.display = 'block';
      modal.offsetHeight; // Trigger reflow
      modal.classList.add('show');
    }

    function closeDocumentModal() {
      const modal = document.getElementById('documentModal');
      modal.classList.remove('show');
      setTimeout(() => {
        modal.style.display = 'none';
      }, 200);
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('documentModal');
      if (event.target == modal) {
        closeDocumentModal();
      }
    }

    // Tab Functions
    function openTab(evt, tabName) {
      evt.preventDefault(); // Prevent form submission
      const tabcontent = document.getElementsByClassName("tab-content");
      for (let i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("active");
      }

      const tablinks = document.getElementsByClassName("tab-btn");
      for (let i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
      }

      document.getElementById(tabName).classList.add("active");
      evt.currentTarget.classList.add("active");
    }

    // Logout Confirmation
    function confirmLogout() {
      Swal.fire({
        title: 'Are you sure?',
        text: "You will be logged out of your account.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, logout!'
      }).then((result) => {
        if (result.isConfirmed) {
          // If confirmed, redirect to logout script
          window.location.href = '../functions/logout.php';
        }
      });
      // Prevent default link behavior, as SweetAlert handles the navigation
      return false;
    }

    // Mobile menu toggle
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    mobileMenuBtn.addEventListener('click', () => {
      navLinks.classList.toggle('active');
    });

    // Password Change Modal Functions
    function openPasswordChangeModal() {
      const modal = document.getElementById('passwordChangeModal');
      modal.style.display = 'block';
      modal.offsetHeight; // Trigger reflow
      modal.classList.add('show');
      
      // Reset steps
      document.querySelectorAll('.password-step').forEach(step => step.style.display = 'none');
      document.getElementById('step1').style.display = 'block';
    }

    function closePasswordChangeModal() {
      const modal = document.getElementById('passwordChangeModal');
      modal.classList.remove('show');
      setTimeout(() => {
        modal.style.display = 'none';
      }, 200);
    }

    function verifyOldPassword() {
      const oldPassword = document.getElementById('modal_old_password').value;
      
      fetch('../functions/verify_password.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `old_password=${encodeURIComponent(oldPassword)}`
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Show step 2 and hide step 1
          document.getElementById('step1').style.display = 'none';
          document.getElementById('step2').style.display = 'block';
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Current password is incorrect'
          });
        }
      })
      .catch(error => {
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An error occurred. Please try again.'
        });
      });
    }

    function verifyCode() {
      const code = document.getElementById('verification_code').value;
      
      fetch('../functions/verify_code.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `verification_code=${encodeURIComponent(code)}`
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          // Show step 3 and hide step 2
          document.getElementById('step2').style.display = 'none';
          document.getElementById('step3').style.display = 'block';
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Invalid verification code'
          });
        }
      })
      .catch(error => {
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An error occurred. Please try again.'
        });
      });
    }

    function resendCode() {
      fetch('../functions/resend_code.php', {
        method: 'POST'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Verification code has been resent to your email'
          });
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to resend verification code'
          });
        }
      })
      .catch(error => {
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An error occurred. Please try again.'
        });
      });
    }

    function updatePassword() {
      const newPassword = document.getElementById('modal_new_password').value;
      const confirmPassword = document.getElementById('modal_confirm_password').value;
      
      if (newPassword !== confirmPassword) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Passwords do not match'
        });
        return;
      }
      
      fetch('../functions/update_password.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `new_password=${encodeURIComponent(newPassword)}`
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Password has been updated successfully'
          }).then(() => {
            closePasswordChangeModal();
          });
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to update password'
          });
        }
      })
      .catch(error => {
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An error occurred. Please try again.'
        });
      });
    }
  </script>
</body>

</html>