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
$query = "SELECT u.*, p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date, 
          p.birth_place, p.gender, p.civil_status, p.citizenship, p.religion, 
          p.education_level, p.occupation, p.monthly_income, p.contact_number, 
          p.resident_type, p.years_of_residency, p.nhts_pr_listahanan, 
          p.indigenous_people, p.pantawid_beneficiary
          FROM users u
          INNER JOIN persons p ON u.id = p.user_id
          WHERE u.id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$error_message = '';
$success_message = '';

// Add this after the user data is fetched
$id_expiration_warning = '';
if (!empty($user['id_expiration_date'])) {
    $expiry_date = new DateTime($user['id_expiration_date']);
    $today = new DateTime();
    $diff = $today->diff($expiry_date);
    
    // Check if ID expires in 3 months or less
    if ($diff->days <= 90 && $expiry_date > $today) {
        $id_expiration_warning = "Your ID will expire in " . $diff->days . " days. Please renew it soon.";
    } elseif ($expiry_date <= $today) {
        $id_expiration_warning = "Your ID has expired. Please upload a new valid ID.";
    }
}

// Process form submission (for editable fields and password change)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_account'])) {
    // Retrieve and trim values for the editable fields
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $gender = $_POST['gender'];
    $contact_number = trim($_POST['contact_number']);
    $barangay_id = $_POST['barangay_id'];
    
    // Present Address
    $present_house_no = trim($_POST['present_house_no']);
    $present_street = trim($_POST['present_street']);
    $present_municipality = trim($_POST['present_municipality']);
    $present_province = trim($_POST['present_province']);

    // Permanent Address
    $permanent_house_no = trim($_POST['permanent_house_no']);
    $permanent_street = trim($_POST['permanent_street']);
    $permanent_municipality = trim($_POST['permanent_municipality']);
    $permanent_province = trim($_POST['permanent_province']);

    // ID Details
    $osca_id = trim($_POST['osca_id'] ?? '');
    $gsis_id = trim($_POST['gsis_id'] ?? '');
    $sss_id = trim($_POST['sss_id'] ?? '');
    $tin_id = trim($_POST['tin_id'] ?? '');
    $philhealth_id = trim($_POST['philhealth_id'] ?? '');
    $other_id_type = trim($_POST['other_id_type'] ?? '');
    $other_id_number = trim($_POST['other_id_number'] ?? '');
    
    // New ID Details from Document AI
    $id_type = trim($_POST['id_type'] ?? '');
    $id_number = trim($_POST['id_number'] ?? '');
    $id_expiration_date = trim($_POST['id_expiration_date'] ?? '');
    
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

    // Handle government ID upload
    if (isset($_FILES['govt_id']) && $_FILES['govt_id']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($_FILES['govt_id']['type'], $allowed_types)) {
            $errors[] = "Invalid file type. Please upload a JPEG or PNG image.";
        } elseif ($_FILES['govt_id']['size'] > $max_size) {
            $errors[] = "File size too large. Maximum size is 5MB.";
        } else {
            $govt_id_data = file_get_contents($_FILES['govt_id']['tmp_name']);
            
            // Update government ID and ID details in database
            $updateGovtIdQuery = "UPDATE users SET 
                                govt_id_image = ?,
                                id_type = ?,
                                id_number = ?,
                                id_expiration_date = ?
                                WHERE id = ?";
            $updateGovtIdStmt = $pdo->prepare($updateGovtIdQuery);
            $updateGovtIdStmt->execute([
                $govt_id_data,
                $id_type,
                $id_number,
                $id_expiration_date,
                $user_id
            ]);
            
            $success_message .= " Government ID and details have been updated successfully.";
        }
    }

    // If there are no errors, update the personal details and possibly the password
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

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

            // Update present address
            $updateAddressQuery = "UPDATE addresses SET 
                                    house_no = ?,
                                    street = ?,
                                    municipality = ?,
                                    province = ?
                                WHERE person_id = (SELECT id FROM persons WHERE user_id = ?) AND is_primary = 1";
            $updateAddressStmt = $pdo->prepare($updateAddressQuery);
            $updateAddressStmt->execute([
                $present_house_no,
                $present_street,
                $present_municipality,
                $present_province,
                $user_id
            ]);

            // Update permanent address
            $updatePermAddressQuery = "UPDATE addresses SET 
                                        house_no = ?,
                                        street = ?,
                                        municipality = ?,
                                        province = ?
                                    WHERE person_id = (SELECT id FROM persons WHERE user_id = ?) AND is_permanent = 1";
            $updatePermAddressStmt = $pdo->prepare($updatePermAddressQuery);
            $updatePermAddressStmt->execute([
                $permanent_house_no,
                $permanent_street,
                $permanent_municipality,
                $permanent_province,
                $user_id
            ]);

            // Update ID details
            $updateIdQuery = "UPDATE person_identification SET 
                                osca_id = ?,
                                gsis_id = ?,
                                sss_id = ?,
                                tin_id = ?,
                                philhealth_id = ?,
                                other_id_type = ?,
                                other_id_number = ?
                            WHERE person_id = (SELECT id FROM persons WHERE user_id = ?)";
            $updateIdStmt = $pdo->prepare($updateIdQuery);
            $updateIdStmt->execute([
                $osca_id,
                $gsis_id,
                $sss_id,
                $tin_id,
                $philhealth_id,
                $other_id_type,
                $other_id_number,
                $user_id
            ]);

            // Update password if requested
            if (!empty($old_password) && !empty($new_password) && !empty($confirm_password)) {
                $new_password_hash = hash('sha256', $new_password);
                $updatePassQuery = "UPDATE users SET password = ? WHERE id = ?";
                $stmt_pass = $pdo->prepare($updatePassQuery);
                $stmt_pass->execute([$new_password_hash, $user_id]);
                $success_message .= " Your password has been changed successfully.";
            }

            $pdo->commit();
            $success_message = "Your account has been updated successfully." . $success_message;
            
            // Refresh the user data
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "An error occurred while updating your account: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Get barangay list for the dropdown
$barangayQuery = "SELECT id, name FROM barangay ORDER BY name";
$barangayStmt = $pdo->prepare($barangayQuery);
$barangayStmt->execute();
$barangays = $barangayStmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's complete information including addresses and IDs
$userQuery = "SELECT u.*, 
                    p.first_name, p.middle_name, p.last_name, p.suffix, p.birth_date, p.birth_place, p.gender, p.civil_status, p.citizenship, p.religion, p.education_level, p.occupation, p.monthly_income, p.contact_number, p.resident_type, p.years_of_residency, p.nhts_pr_listahanan, p.indigenous_people, p.pantawid_beneficiary,
                    a.house_no, a.street, a.municipality, a.province,
                    pa.house_no as permanent_house_no, pa.street as permanent_street,
                    pa.municipality as permanent_municipality, pa.province as permanent_province,
                    pi.osca_id, pi.gsis_id, pi.sss_id, pi.tin_id, pi.philhealth_id,
                    pi.other_id_type, pi.other_id_number,
                    h.household_number, pu.name as purok_name,
                    u.id_type, u.id_number, u.id_expiration_date
             FROM users u
             INNER JOIN persons p ON u.id = p.user_id
             LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
             LEFT JOIN addresses pa ON p.id = pa.person_id AND pa.is_permanent = 1
             LEFT JOIN person_identification pi ON p.id = pi.person_id
             LEFT JOIN household_members hm ON p.id = hm.person_id
             LEFT JOIN households h ON hm.household_id = h.id
             LEFT JOIN purok pu ON h.purok_id = pu.id
             WHERE u.id = ?";
$userStmt = $pdo->prepare($userQuery);
$userStmt->execute([$user_id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

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

    /* Add these styles to your existing CSS */
    #idPreviewModal .modal-content {
      max-width: 600px;
    }

    #idPreviewModal .form-group {
      margin-bottom: 1rem;
    }

    #idPreviewModal .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      font-weight: 500;
    }

    #idPreviewModal .form-group input {
      width: 100%;
      padding: 0.5rem;
      border: 1px solid #ddd;
      border-radius: 4px;
    }

    #idPreviewModal .preview-container {
      text-align: center;
      margin-bottom: 1.5rem;
    }

    #idPreviewModal .preview-container img {
      max-width: 100%;
      max-height: 300px;
      border-radius: 4px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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

    <?php if (!empty($id_expiration_warning)): ?>
      <div class="alert alert-warning" style="margin-bottom: 20px; padding: 15px; border-radius: 5px; background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <i class="fas fa-exclamation-triangle" style="font-size: 1.2em;"></i>
          <span><?php echo htmlspecialchars($id_expiration_warning); ?></span>
        </div>
      </div>
      <script>
        // Also show a modal alert for better visibility
        Swal.fire({
          icon: 'warning',
          title: 'ID Expiration Notice',
          text: <?php echo json_encode($id_expiration_warning); ?>,
          confirmButtonText: 'I Understand'
        });
      </script>
    <?php endif; ?>

    <form class="account-form" action="" method="POST" enctype="multipart/form-data">
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
                
                <!-- ID Details Display -->
                <?php if (!empty($user['id_type']) || !empty($user['id_number']) || !empty($user['id_expiration_date'])): ?>
                  <div class="id-details" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <?php if (!empty($user['id_type'])): ?>
                      <p><strong>ID Type:</strong> <?php echo htmlspecialchars($user['id_type']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($user['id_number'])): ?>
                      <p><strong>ID Number:</strong> <?php echo htmlspecialchars($user['id_number']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($user['id_expiration_date'])): ?>
                      <p><strong>Expiry Date:</strong> <?php echo date('F d, Y', strtotime($user['id_expiration_date'])); ?></p>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <p>No government ID uploaded.</p>
            <?php endif; ?>
            
            <!-- Add file input for uploading new ID -->
            <div class="upload-new-id" style="margin-top: 1rem;">
              <label for="govt_id" class="upload-label">
                <i class="fas fa-upload"></i> Upload New Government ID
              </label>
              <input type="file" id="govt_id" name="govt_id" accept="image/jpeg,image/png,image/jpg" style="display: none;" onchange="processNewId(this)">
            </div>
          </div>
        </div>
      </div>

      <!-- ID Preview Modal -->
      <div id="idPreviewModal" class="modal">
        <div class="modal-content">
          <div class="modal-header">
            <h2>Verify ID Information</h2>
            <button type="button" class="close" onclick="closeIdPreviewModal()">&times;</button>
          </div>
          <div class="modal-body">
            <div class="preview-container">
              <img id="previewImage" src="" alt="ID Preview" style="max-width: 100%; max-height: 300px; margin-bottom: 20px;">
            </div>
            <form id="idInfoForm">
              <div class="form-group">
                <label for="extracted_id_type">ID Type</label>
                <input type="text" id="extracted_id_type" name="extracted_id_type" required>
              </div>
              <div class="form-group">
                <label for="extracted_id_number">ID Number</label>
                <input type="text" id="extracted_id_number" name="extracted_id_number" required>
              </div>
              <div class="form-group">
                <label for="extracted_expiry">Expiry Date</label>
                <input type="date" id="extracted_expiry" name="extracted_expiry" required>
              </div>
              <div class="form-actions">
                <button type="button" class="btn secondary-btn" onclick="closeIdPreviewModal()">Cancel</button>
                <button type="button" class="btn cta-button" onclick="confirmIdUpdate()">Confirm Update</button>
              </div>
            </form>
          </div>
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
          <label for="last_name">Last Name *</label>
          <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="gender">Gender</label>
          <input type="text" id="gender" name="gender" value="<?php echo htmlspecialchars(ucfirst(strtolower($user['gender'] ?? ''))); ?>" readonly>
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

      <!-- Present Address Section -->
      <div class="form-section">
        <h3>Present Address</h3>
        <div class="form-group">
          <label for="household_number">Household Number</label>
          <input type="text" id="household_number" name="household_number" value="<?php echo htmlspecialchars($user['household_number'] ?? ''); ?>" readonly>
        </div>
        <div class="form-group">
          <label for="purok">Purok</label>
          <input type="text" id="purok" name="purok" value="<?php echo htmlspecialchars($user['purok_name'] ?? ''); ?>" readonly>
        </div>
        <div class="form-group">
          <label for="present_house_no">House Number</label>
          <input type="text" id="present_house_no" name="present_house_no" value="<?php echo htmlspecialchars($user['house_no'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="present_street">Street</label>
          <input type="text" id="present_street" name="present_street" value="<?php echo htmlspecialchars($user['street'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="present_municipality">City/Municipality</label>
          <input type="text" id="present_municipality" name="present_municipality" value="<?php echo htmlspecialchars($user['municipality'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="present_province">Province</label>
          <input type="text" id="present_province" name="present_province" value="<?php echo htmlspecialchars($user['province'] ?? ''); ?>">
        </div>
      </div>

      <!-- Permanent Address Section -->
      <div class="form-section">
        <h3>Permanent Address</h3>
        <div id="permanent_address_fields">
          <div class="form-group">
            <label for="permanent_house_no">House Number</label>
            <input type="text" id="permanent_house_no" name="permanent_house_no" value="<?php echo htmlspecialchars($user['permanent_house_no'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label for="permanent_street">Street</label>
            <input type="text" id="permanent_street" name="permanent_street" value="<?php echo htmlspecialchars($user['permanent_street'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label for="permanent_municipality">City/Municipality</label>
            <input type="text" id="permanent_municipality" name="permanent_municipality" value="<?php echo htmlspecialchars($user['permanent_municipality'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label for="permanent_province">Province</label>
            <input type="text" id="permanent_province" name="permanent_province" value="<?php echo htmlspecialchars($user['permanent_province'] ?? ''); ?>">
          </div>
        </div>
      </div>

      <!-- ID Details Section -->
      <div class="form-section">
        <h3>ID Details</h3>
        <div class="form-group">
          <label for="osca_id">OSCA ID</label>
          <input type="text" id="osca_id" name="osca_id" value="<?php echo htmlspecialchars($user['osca_id'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="gsis_id">GSIS ID</label>
          <input type="text" id="gsis_id" name="gsis_id" value="<?php echo htmlspecialchars($user['gsis_id'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="sss_id">SSS ID</label>
          <input type="text" id="sss_id" name="sss_id" value="<?php echo htmlspecialchars($user['sss_id'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="tin_id">TIN ID</label>
          <input type="text" id="tin_id" name="tin_id" value="<?php echo htmlspecialchars($user['tin_id'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="philhealth_id">PhilHealth ID</label>
          <input type="text" id="philhealth_id" name="philhealth_id" value="<?php echo htmlspecialchars($user['philhealth_id'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="other_id_type">Other ID Type</label>
          <input type="text" id="other_id_type" name="other_id_type" value="<?php echo htmlspecialchars($user['other_id_type'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="other_id_number">Other ID Number</label>
          <input type="text" id="other_id_number" name="other_id_number" value="<?php echo htmlspecialchars($user['other_id_number'] ?? ''); ?>">
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

    // Image Modal Functions
    function openImageModal(src) {
      const modal = document.getElementById('imageModal');
      const modalImg = document.getElementById('modalImage');
      modal.style.display = "block";
      modalImg.src = src;
    }

    function closeImageModal() {
      const modal = document.getElementById('imageModal');
      modal.style.display = "none";
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const documentModal = document.getElementById('documentModal');
      const imageModal = document.getElementById('imageModal');
      
      if (event.target == documentModal) {
        closeDocumentModal();
      }
      if (event.target == imageModal) {
        closeImageModal();
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
  
  // Client-side password strength validation
  function validatePasswordStrength(password) {
    const errors = [];
    
    // Minimum length
    if (password.length < 8) {
      errors.push('Password must be at least 8 characters long');
    }
    
    // Must contain uppercase letter
    if (!/[A-Z]/.test(password)) {
      errors.push('Password must contain at least one uppercase letter');
    }
    
    // Must contain lowercase letter
    if (!/[a-z]/.test(password)) {
      errors.push('Password must contain at least one lowercase letter');
    }
    
    // Must contain number
    if (!/\d/.test(password)) {
      errors.push('Password must contain at least one number');
    }
    
    // Must contain special character
    if (!/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/.test(password)) {
      errors.push('Password must contain at least one special character (!@#$%^&*()_+-=[]{}|;:,.<>?)');
    }
    
    return errors;
  }
  
  // Validate password strength
  const strengthErrors = validatePasswordStrength(newPassword);
  if (strengthErrors.length > 0) {
    Swal.fire({
      icon: 'error',
      title: 'Weak Password',
      html: strengthErrors.join('<br>')
    });
    return;
  }
  
  // Check if passwords match
  if (newPassword !== confirmPassword) {
    Swal.fire({
      icon: 'error',
      title: 'Error',
      text: 'Passwords do not match'
    });
    return;
  }
  
  // Show loading
  Swal.fire({
    title: 'Updating Password...',
    text: 'Please wait while we update your password',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });
  
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
        text: data.message
      }).then(() => {
        closePasswordChangeModal();
        // Clear the form
        document.getElementById('modal_old_password').value = '';
        document.getElementById('modal_new_password').value = '';
        document.getElementById('modal_confirm_password').value = '';
        document.getElementById('verification_code').value = '';
      });
    } else {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: data.message
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

    let currentFile = null;

    function processNewId(input) {
      if (input.files && input.files[0]) {
        currentFile = input.files[0];
        const formData = new FormData();
        formData.append('govt_id', currentFile);
        formData.append('debug', 'true'); // Enable debug mode

        // Show loading state
        Swal.fire({
          title: 'Processing ID...',
          text: 'Please wait while we extract information from your ID',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });

        // Send to Document AI processor
        fetch('../scripts/process_id.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Display preview modal with extracted data
            const modal = document.getElementById('idPreviewModal');
            const previewImage = document.getElementById('previewImage');
            const reader = new FileReader();
            
            reader.onload = function(e) {
              previewImage.src = e.target.result;
              
              // Fill in extracted data
              document.getElementById('extracted_id_type').value = data.data.type_of_id || '';
              document.getElementById('extracted_id_number').value = data.data.id_number || '';
              
              // Format and set expiry date if available
              if (data.data.expiration_date) {
                const expiryDate = new Date(data.data.expiration_date);
                document.getElementById('extracted_expiry').value = expiryDate.toISOString().split('T')[0];
              }
              
              modal.style.display = 'block';
              modal.offsetHeight; // Trigger reflow
              modal.classList.add('show');
            };
            
            reader.readAsDataURL(currentFile);
            Swal.close();
          } else {
            throw new Error(data.error || 'Failed to process ID');
          }
        })
        .catch(error => {
          console.error('Error:', error);
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: error.message || 'Failed to process ID. Please try again.'
          });
        });
      }
    }

    function closeIdPreviewModal() {
      const modal = document.getElementById('idPreviewModal');
      modal.classList.remove('show');
      setTimeout(() => {
        modal.style.display = 'none';
      }, 200);
      
      // Reset file input
      document.getElementById('govt_id').value = '';
      currentFile = null;
    }

    function confirmIdUpdate() {
      const formData = new FormData();
      formData.append('govt_id', currentFile);
      formData.append('id_type', document.getElementById('extracted_id_type').value);
      formData.append('id_number', document.getElementById('extracted_id_number').value);
      formData.append('id_expiration_date', document.getElementById('extracted_expiry').value);
      formData.append('update_account', '1');

      // Show loading state
      Swal.fire({
        title: 'Updating ID...',
        text: 'Please wait while we update your ID information',
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        }
      });

      // Submit the form with the new data
      fetch(window.location.href, {
        method: 'POST',
        body: formData
      })
      .then(response => response.text())
      .then(() => {
        Swal.fire({
          icon: 'success',
          title: 'Success',
          text: 'Your ID information has been updated successfully'
        }).then(() => {
          window.location.reload();
        });
      })
      .catch(error => {
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to update ID information. Please try again.'
        });
      });
    }
  </script>
</body>

</html>