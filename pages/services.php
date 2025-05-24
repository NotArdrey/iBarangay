<?php
session_start();
require_once "../config/dbconn.php";

// Check for pending requests
$hasPendingRequest = false;
$pendingRequests = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT dr.*, dt.name as document_name, dt.code as document_code
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.user_id = ? 
        AND dr.status IN ('pending', 'processing', 'for_payment')
        ORDER BY dr.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasPendingRequest = count($pendingRequests) > 0;
}

// Handle form submission for document requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Time-gated validation
        $currentTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $startTime = new DateTime('08:00:00', new DateTimeZone('Asia/Manila'));
        $endTime = new DateTime('17:00:00', new DateTimeZone('Asia/Manila'));

        if ($currentTime < $startTime || $currentTime > $endTime) {
            throw new Exception("Document requests can only be submitted between 8:00 AM and 5:00 PM.");
        }

        // Check if user has pending requests
        if ($hasPendingRequest && !isset($_POST['override_pending'])) {
            throw new Exception("You have pending document requests. Please wait for them to be processed before submitting new requests.");
        }

        // Validate required fields
        $documentTypeId = $_POST['document_type_id'] ?? '';
        if (empty($documentTypeId)) {
            throw new Exception("Please select a document type");
        }

        // Get user info
        if (!isset($_SESSION['user_id'])) {
            throw new Exception("Please log in to submit a request");
        }
        $user_id = $_SESSION['user_id'];

        // Get user's person_id from the persons table
        $stmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $person = $stmt->fetch();
        
        if (!$person) {
            throw new Exception("User profile not found");
        }

        // Begin transaction for the main request
        $pdo->beginTransaction();
        
        try {
            // Handle file upload for indigency certificate
            $imagePath = null;
            if ($_POST['document_type_id'] && isset($_FILES['userPhoto'])) {
                $stmt = $pdo->prepare("SELECT code FROM document_types WHERE id = ?");
                $stmt->execute([$documentTypeId]);
                $docType = $stmt->fetch();
                
                if ($docType && $docType['code'] === 'barangay_indigency' && $_FILES['userPhoto']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/indigency_photos/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                    $fileType = $_FILES['userPhoto']['type'];
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        throw new Exception("Invalid file type. Please upload JPG or PNG images only.");
                    }
                    
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['userPhoto']['size'] > $maxSize) {
                        throw new Exception("File size too large. Maximum size is 5MB.");
                    }
                    
                    $fileName = uniqid() . '_' . time() . '.' . pathinfo($_FILES['userPhoto']['name'], PATHINFO_EXTENSION);
                    $targetPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['userPhoto']['tmp_name'], $targetPath)) {
                        $imagePath = 'uploads/indigency_photos/' . $fileName;
                    } else {
                        throw new Exception("Failed to upload photo. Please try again.");
                    }
                }
            }

            // Insert into document_requests table
            $stmt = $pdo->prepare("
                INSERT INTO document_requests 
                (document_type_id, person_id, user_id, barangay_id, requested_by_user_id, status, request_date, proof_image_path, price) 
                SELECT ?, ?, ?, ?, ?, 'pending', NOW(), ?, COALESCE(bdp.price, dt.default_fee)
                FROM document_types dt
                LEFT JOIN barangay_document_prices bdp ON bdp.document_type_id = dt.id 
                    AND bdp.barangay_id = ?
                WHERE dt.id = ?
            ");
            $stmt->execute([
                $documentTypeId,
                $person['id'],
                $user_id,
                $_SESSION['barangay_id'],
                $user_id,
                $imagePath,
                $_SESSION['barangay_id'],
                $documentTypeId
            ]);
            $requestId = $pdo->lastInsertId();

            // Get document type info 
            $stmt = $pdo->prepare("SELECT id, code FROM document_types WHERE id = ?");
            $stmt->execute([$documentTypeId]);
            $documentType = $stmt->fetch();

            // Get attribute type IDs
            $stmt = $pdo->prepare("
                SELECT id, code 
                FROM document_attribute_types 
                WHERE document_type_id = ?
            ");
            $stmt->execute([$documentTypeId]);
            $attributeTypes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Prepare attributes array based on document type
            $attributes = [];
            switch($documentType['code']) {
                case 'barangay_clearance':
                    if (!empty($_POST['purposeClearance'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['clearance_purpose'] ?? null,
                            'value' => $_POST['purposeClearance']
                        ];
                    }
                    break;

                case 'proof_of_residency':
                    if (!empty($_POST['residencyDuration'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['residency_duration'] ?? null,
                            'value' => $_POST['residencyDuration']
                        ];
                    }
                    if (!empty($_POST['residencyPurpose'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['residency_purpose'] ?? null,
                            'value' => $_POST['residencyPurpose']
                        ];
                    }
                    break;

                case 'barangay_indigency':
                    if (!empty($_POST['indigencyReason'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['indigency_reason'] ?? null,
                            'value' => $_POST['indigencyReason']
                        ];
                    }
                    break;

                case 'cedula':
                case 'community_tax_certificate':
                    // Handle occupation
                    $occupation = !empty($_POST['cedulaOccupation']) ? $_POST['cedulaOccupation'] : $_POST['ctcOccupation'] ?? '';
                    if (!empty($occupation)) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['occupation'] ?? null,
                            'value' => $occupation
                        ];
                    }
                    
                    // Handle income fields
                    $income = !empty($_POST['cedulaIncome']) ? $_POST['cedulaIncome'] : $_POST['ctcIncome'] ?? '';
                    if (!empty($income)) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['income'] ?? null,
                            'value' => number_format((float)$income, 2, '.', '')
                        ];
                    }

                    // Handle optional fields specific to CTC
                    if ($documentType['code'] === 'community_tax_certificate') {
                        if (!empty($_POST['ctcPropertyValue'])) {
                            $attributes[] = [
                                'type_id' => $attributeTypes['property_value'] ?? null,
                                'value' => number_format((float)$_POST['ctcPropertyValue'], 2, '.', '')
                            ];
                        }
                        if (!empty($_POST['ctcBirthplace'])) {
                            $attributes[] = [
                                'type_id' => $attributeTypes['birthplace'] ?? null,
                                'value' => $_POST['ctcBirthplace']
                            ];
                        }
                    }
                    break;

                case 'business_permit_clearance':
                    if (!empty($_POST['businessName'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['business_name'] ?? null,
                            'value' => $_POST['businessName']
                        ];
                    }
                    if (!empty($_POST['businessType'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['business_type'] ?? null,
                            'value' => $_POST['businessType']
                        ];
                    }
                    if (!empty($_POST['businessAddress'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['business_address'] ?? null,
                            'value' => $_POST['businessAddress']
                        ];
                    }
                    if (!empty($_POST['businessPurpose'])) {
                        $attributes[] = [
                            'type_id' => $attributeTypes['business_purpose'] ?? null,
                            'value' => $_POST['businessPurpose']
                        ];
                    }
                    break;
            }

            // Insert attributes if any exist
            if (!empty($attributes)) {
                $stmt = $pdo->prepare("
                    INSERT INTO document_request_attributes 
                    (request_id, attribute_type_id, value) 
                    VALUES (?, ?, ?)
                ");
                foreach ($attributes as $attr) {
                    if ($attr['type_id']) {
                        $stmt->execute([$requestId, $attr['type_id'], $attr['value']]);
                    }
                }
            }

            // If we got here, commit the transaction
            $pdo->commit();

            // Set success notification
            $_SESSION['success'] = [
                'title' => 'Document Request Submitted',
                'message' => 'Your document request has been submitted successfully.',
                'processing' => 'Please wait for the processing of your request. You will be notified once it is ready.'
            ];
            $_SESSION['show_pending'] = true;

            // Redirect back to the same page to show pending requests
            header('Location: ' . $_SERVER['PHP_SELF'] . '?show_pending=1');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            // Delete uploaded file if transaction failed
            if ($imagePath && file_exists('../' . $imagePath)) {
                unlink('../' . $imagePath);
            }
            throw $e;
        }

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get user info for the page header
$userName = '';
$barangayName = '';
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT u.first_name, u.last_name, b.name as barangay_name
            FROM users u
            LEFT JOIN barangay b ON u.barangay_id = b.id
            WHERE u.id = ?";
    $stmtUser = $pdo->prepare($sql);
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch();
    if ($user) {
        $userName = trim($user['first_name'] . ' ' . $user['last_name']);
        $barangayName = $user['barangay_name'] ?? '';
    }
}

// Get all document types (only the 6 supported)
$barangay_id = $_SESSION['barangay_id'];

$stmt = $pdo->prepare("
    SELECT 
        dt.id,
        dt.name,
        dt.code,
        dt.default_fee,
        COALESCE(bdp.price, dt.default_fee) AS price
    FROM document_types dt
    LEFT JOIN barangay_document_prices bdp
        ON bdp.document_type_id = dt.id AND bdp.barangay_id = ?
    WHERE dt.is_active = 1
");
$stmt->execute([$barangay_id]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a PHP array for JS
$barangayPrices = [];
foreach ($docs as $doc) {
    $barangayPrices[$doc['code']] = $doc['price'];
}

$selectedDocumentType = $_GET['documentType'] ?? '';
$showPending = isset($_GET['show_pending']) || isset($_SESSION['show_pending']);
unset($_SESSION['show_pending']);

// Time-gated notice
$currentTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
$startTime = new DateTime('08:00:00', new DateTimeZone('Asia/Manila'));
$endTime = new DateTime('17:00:00', new DateTimeZone('Asia/Manila'));
$isWithinTimeGate = ($currentTime >= $startTime && $currentTime <= $endTime);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iBarangay - Document Request</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../styles/services.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    /* User Info Styles */
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
        color: #0a2240;
    }

    .user-barangay {
        font-size: 0.75rem;
        color: #0a2240;
    }

    /* Footer fix */
    body {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        margin: 0;
    }

    main {
        flex: 1;
    }
    
    .footer {
        position: relative;
        width: 100%;
        padding: 1rem 0;
        text-align: center;  
        z-index: 1;
    }

    /* Override SweetAlert z-index to ensure it appears above footer */
    .swal2-container {
        z-index: 9999 !important;
    }

    /* Ensure main content has proper spacing */
    .wizard-section {
        padding-bottom: 2rem;
    }

    /* Photo upload styles */
    .photo-upload-container {
        margin: 1rem 0;
        padding: 1rem;
        border: 2px dashed #ddd;
        border-radius: 8px;
        text-align: center;
        background: #f9f9f9;
    }

    .photo-upload-container.active {
        border-color: #0a2240;
        background: #e8f0ff;
    }

    .upload-options {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin: 1rem 0;
    }

    .upload-btn {
        padding: 0.5rem 1rem;
        background: #0a2240;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background 0.3s;
    }

    .upload-btn:hover {
        background: #1a3350;
    }

    .upload-btn i {
        margin-right: 0.5rem;
    }

    .photo-preview {
        margin: 1rem auto;
        max-width: 200px;
        max-height: 200px;
        display: none;
    }

    .photo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 8px;
        border: 2px solid #0a2240;
    }

    .remove-photo {
        margin-top: 0.5rem;
        padding: 0.3rem 0.8rem;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.85rem;
    }

    .remove-photo:hover {
        background: #c82333;
    }

    /* Pending requests section */
    .pending-requests-section {
        background: #f8f9fa;
        padding: 2rem;
        margin: 2rem 0;
        border-radius: 10px;
    }

    .pending-requests-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .pending-requests-header h3 {
        color: #0a2240;
        margin: 0;
    }

    .request-card {
        background: white;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-left: 4px solid #0a2240;
    }

    .request-card:last-child {
        margin-bottom: 0;
    }

    .request-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .request-title {
        font-weight: 600;
        color: #0a2240;
    }

    .request-status {
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .status-pending {
        background: #ffc107;
        color: #000;
    }

    .status-processing {
        background: #17a2b8;
        color: white;
    }

    .status-for_payment {
        background: #fd7e14;
        color: white;
    }

    .request-details {
        color: #666;
        font-size: 0.9rem;
    }

    .request-date {
        color: #999;
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }

    .no-pending {
        text-align: center;
        color: #666;
        padding: 2rem;
    }

    .new-request-btn {
        background: #0a2240;
        color: white;
        padding: 0.5rem 1.5rem;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .new-request-btn:hover {
        background: #1a3350;
    }

    /* Warning message for pending requests */
    .pending-warning {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .pending-warning i {
        font-size: 1.2rem;
    }

    .time-gate-notice {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        text-align: center;
    }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['success'])): ?>
    <script>
        Swal.fire({
            title: '<?= $_SESSION['success']['title'] ?>',
            html: `<b><?= $_SESSION['success']['message'] ?></b><br><br><?= $_SESSION['success']['processing'] ?>`,
            icon: 'success'
        });
    </script>
    <?php unset($_SESSION['success']); endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <script>
        Swal.fire({
            title: 'Error',
            text: '<?= $_SESSION['error'] ?>',
            icon: 'error'
        });
    </script>
    <?php unset($_SESSION['error']); endif; ?>

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
          <a href="../pages/user_dashboard.php#home">Home</a>
          <a href="../pages/user_dashboard.php#about">About</a>
          <a href="../pages/user_dashboard.php#services">Services</a>
          <a href="../pages/user_dashboard.php#contact">Contact</a>
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

    <main>
        <?php if ($showPending && count($pendingRequests) > 0): ?>
        <section class="pending-requests-section">
            <div class="wizard-container">
                <div class="pending-requests-header">
                    <h3><i class="fas fa-clock"></i> Your Pending Document Requests</h3>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="new-request-btn">
                        <i class="fas fa-plus"></i> New Request
                    </a>
                </div>
                
                <?php foreach ($pendingRequests as $request): ?>
                <div class="request-card">
                    <div class="request-header">
                        <div class="request-title"><?= htmlspecialchars($request['document_name']) ?></div>
                        <span class="request-status status-<?= $request['status'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                        </span>
                    </div>
                    <div class="request-details">
                        Request ID: #<?= str_pad($request['id'], 6, '0', STR_PAD_LEFT) ?>
                    </div>
                    <div class="request-date">
                        Submitted on: <?= date('F d, Y - h:i A', strtotime($request['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php else: ?>
        
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                
                <?php if (!$isWithinTimeGate): ?>
                <div class="time-gate-notice">
                    <p><i class="fas fa-clock"></i> Document requests can only be submitted between 8:00 AM and 5:00 PM.</p>
                    <p>Please come back during operating hours.</p>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" enctype="multipart/form-data" id="docRequestForm">
                    <div class="form-row">
                        <label for="documentType">Document Type</label>
                        <select id="documentType" name="document_type_id" required>
                            <option value="">Select Document</option>
                            <?php
                            // Always show all 6 supported documents in the correct order
                            $requiredDocs = [
                                'barangay_clearance',
                                'barangay_indigency',
                                'business_permit_clearance',
                                'cedula',
                                'proof_of_residency',
                                'community_tax_certificate'
                            ];
                            // Build a map for quick lookup
                            $docMap = [];
                            foreach ($docs as $doc) {
                                $docMap[$doc['code']] = $doc;
                            }
                            foreach ($requiredDocs as $code) {
                                if (isset($docMap[$code])) {
                                    $doc = $docMap[$code];
                                    ?>
                                    <option value="<?= $doc['id'] ?>" data-code="<?= $doc['code'] ?>">
                                        <?= htmlspecialchars($doc['name']) ?>
                                    </option>
                                    <?php
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Document price/fee label -->
                    <div class="form-row">
                        <label>Document Fee:</label>
                        <span id="feeAmount" style="font-weight:bold;">₱0.00</span>
                    </div>

                    <!-- Document-specific fields for the 6 document types -->
                    <div id="clearanceFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="purposeClearance">Purpose of Clearance</label>
                            <input type="text" id="purposeClearance" name="purposeClearance" placeholder="Enter purpose (e.g., Employment, Business Permit, etc.)">
                        </div>
                    </div>

                    <div id="residencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="residencyDuration">Duration of Residency</label>
                            <input type="text" id="residencyDuration" name="residencyDuration" placeholder="e.g., 5 years">
                        </div>
                        <div class="form-row">
                            <label for="residencyPurpose">Purpose</label>
                            <input type="text" id="residencyPurpose" name="residencyPurpose" placeholder="Enter purpose (e.g., School enrollment, Scholarship, etc.)">
                        </div>
                    </div>

                    <div id="indigencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="indigencyReason">Reason for Requesting</label>
                            <input type="text" id="indigencyReason" name="indigencyReason" placeholder="Enter reason (e.g., Medical assistance, Educational assistance, etc.)">
                        </div>
                        <div class="form-row">
                            <label>Your Photo <span style="color: red;">*</span></label>
                            <div class="photo-upload-container" id="photoUploadContainer">
                                <input type="file" id="userPhoto" name="userPhoto" accept="image/*" style="display: none;" required>
                                <div class="upload-options">
                                    <button type="button" class="upload-btn" onclick="document.getElementById('userPhoto').click();">
                                        <i class="fas fa-upload"></i> Choose Photo
                                    </button>
                                    <button type="button" class="upload-btn" onclick="openCamera();">
                                        <i class="fas fa-camera"></i> Take Photo
                                    </button>
                                </div>
                                <p class="upload-hint" style="color: #666; font-size: 0.9rem; margin: 0.5rem 0;">
                                    Upload a recent photo of yourself (JPG or PNG, max 5MB)
                                </p>
                                <div class="photo-preview" id="photoPreview">
                                    <img id="previewImage" src="" alt="Photo preview">
                                    <button type="button" class="remove-photo" onclick="removePhoto();">Remove Photo</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="cedulaFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="cedulaOccupation">Occupation</label>
                            <input type="text" id="cedulaOccupation" name="cedulaOccupation" placeholder="Enter your occupation">
                        </div>
                        <div class="form-row">
                            <label for="cedulaIncome">Annual Income</label>
                            <input type="number" id="cedulaIncome" name="cedulaIncome" placeholder="Enter annual income in PHP" min="0" step="0.01">
                        </div>
                        <div class="form-row">
                            <label for="cedulaBirthplace">Place of Birth (Optional)</label>
                            <input type="text" id="cedulaBirthplace" name="cedulaBirthplace" placeholder="Enter place of birth">
                        </div>
                    </div>

                    <div id="businessPermitFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="businessName">Business Name</label>
                            <input type="text" id="businessName" name="businessName" placeholder="Enter business name">
                        </div>
                        <div class="form-row">
                            <label for="businessType">Business Type</label>
                            <input type="text" id="businessType" name="businessType" placeholder="Enter type/nature of business (e.g., Retail, Restaurant, etc.)">
                        </div>
                        <div class="form-row">
                            <label for="businessAddress">Business Address</label>
                            <input type="text" id="businessAddress" name="businessAddress" placeholder="Enter complete business address">
                        </div>
                        <div class="form-row">
                            <label for="businessPurpose">Purpose</label>
                            <input type="text" id="businessPurpose" name="businessPurpose" placeholder="Purpose for business clearance (e.g., New business permit, Renewal, etc.)">
                        </div>
                    </div>

                    <div id="communityTaxFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="ctcOccupation">Occupation</label>
                            <input type="text" id="ctcOccupation" name="ctcOccupation" placeholder="Enter your occupation">
                        </div>
                        <div class="form-row">
                            <label for="ctcIncome">Annual Income</label>
                            <input type="number" id="ctcIncome" name="ctcIncome" placeholder="Enter annual income in PHP" min="0" step="0.01">
                            <small class="input-help">This field is required for tax computation</small>
                        </div>
                        <div class="form-row">
                            <label for="ctcPropertyValue">Real Property Value (Optional)</label>
                            <input type="number" id="ctcPropertyValue" name="ctcPropertyValue" placeholder="Enter total value of real property owned" min="0" step="0.01">
                            <small class="input-help">Enter if you own real property (land, house, etc.)</small>
                        </div>
                        <div class="form-row">
                            <label for="ctcBirthplace">Place of Birth (Optional)</label>
                            <input type="text" id="ctcBirthplace" name="ctcBirthplace" placeholder="Enter place of birth">
                        </div>
                    </div>

                    <?php if ($hasPendingRequest): ?>
                    <input type="hidden" name="override_pending" value="1">
                    <?php endif; ?>

                    <button type="submit" class="btn cta-button" id="submitBtn" <?= !$isWithinTimeGate ? 'disabled' : '' ?>>
                        <?= !$isWithinTimeGate ? 'Unavailable (8AM-5PM)' : 'Submit Request' ?>
                    </button>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Hidden canvas for camera capture -->
    <canvas id="cameraCanvas" style="display: none;"></canvas>

    <script>
    // Use barangayPrices in JS
    var barangayPrices = <?= json_encode($barangayPrices) ?>;
    var isWithinTimeGateJS = <?= json_encode($isWithinTimeGate) ?>;

    // Photo upload functions
    function openCamera() {
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    Swal.fire({
                        title: 'Take Photo',
                        html: `
                            <video id="cameraVideo" style="width: 100%; max-width: 400px;" autoplay></video>
                            <canvas id="captureCanvas" style="display: none;"></canvas>
                        `,
                        showCancelButton: true,
                        confirmButtonText: 'Capture',
                        cancelButtonText: 'Cancel',
                        didOpen: () => {
                            const video = document.getElementById('cameraVideo');
                            video.srcObject = stream;
                        },
                        preConfirm: () => {
                            const video = document.getElementById('cameraVideo');
                            const canvas = document.getElementById('captureCanvas');
                            canvas.width = video.videoWidth;
                            canvas.height = video.videoHeight;
                            const ctx = canvas.getContext('2d');
                            ctx.drawImage(video, 0, 0);
                            return canvas.toDataURL('image/jpeg');
                        },
                        willClose: () => {
                            stream.getTracks().forEach(track => track.stop());
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            displayCapturedPhoto(result.value);
                        }
                    });
                })
                .catch(function(err) {
                    Swal.fire('Error', 'Unable to access camera. Please choose a file instead.', 'error');
                });
        } else {
            Swal.fire('Error', 'Camera is not supported on this device. Please choose a file instead.', 'error');
        }
    }

    function displayCapturedPhoto(dataUrl) {
        // Convert data URL to blob
        fetch(dataUrl)
            .then(res => res.blob())
            .then(blob => {
                // Create a file from blob
                const file = new File([blob], "camera_photo.jpg", { type: "image/jpeg" });
                
                // Create a DataTransfer object to set file input
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('userPhoto').files = dataTransfer.files;
                
                // Display preview
                document.getElementById('previewImage').src = dataUrl;
                document.getElementById('photoPreview').style.display = 'block';
                document.getElementById('photoUploadContainer').classList.add('active');
            });
    }

    function removePhoto() {
        document.getElementById('userPhoto').value = '';
        document.getElementById('photoPreview').style.display = 'none';
        document.getElementById('photoUploadContainer').classList.remove('active');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const documentTypeSelect = document.getElementById('documentType');
        const feeAmountElement = document.getElementById('feeAmount');
        const clearanceFields = document.getElementById('clearanceFields');
        const residencyFields = document.getElementById('residencyFields');
        const indigencyFields = document.getElementById('indigencyFields');
        const cedulaFields = document.getElementById('cedulaFields');
        const businessPermitFields = document.getElementById('businessPermitFields');
        const communityTaxFields = document.getElementById('communityTaxFields');
        const form = document.getElementById('docRequestForm');
        const submitBtn = document.getElementById('submitBtn');
        const userPhotoInput = document.getElementById('userPhoto');

        // Handle file selection
        if (userPhotoInput) {
            userPhotoInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Validate file size
                    if (file.size > 5 * 1024 * 1024) {
                        Swal.fire('Error', 'File size too large. Maximum size is 5MB.', 'error');
                        this.value = '';
                        return;
                    }
                    
                    // Validate file type
                    if (!file.type.match('image.*')) {
                        Swal.fire('Error', 'Please select an image file.', 'error');
                        this.value = '';
                        return;
                    }
                    
                    // Display preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('previewImage').src = e.target.result;
                        document.getElementById('photoPreview').style.display = 'block';
                        document.getElementById('photoUploadContainer').classList.add('active');
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        function hideAllDocumentFields() {
            const allFields = document.querySelectorAll('.document-fields');
            allFields.forEach(field => {
                field.style.display = 'none';
            });
            
            // Remove required attribute from ALL form inputs
            const allInputs = document.querySelectorAll('#docRequestForm input[type="text"], #docRequestForm input[type="number"], #docRequestForm input[type="file"]');
            allInputs.forEach(input => {
                input.required = false;
            });
            
            // Reset photo upload
            removePhoto();
        }

        function setFieldsRequired(container, documentCode) {
            if (!container) return;

            // Set required fields based on document type
            switch(documentCode) {
                case 'barangay_clearance':
                    const purposeClearance = document.getElementById('purposeClearance');
                    if (purposeClearance) purposeClearance.required = true;
                    break;

                case 'proof_of_residency':
                    const residencyDuration = document.getElementById('residencyDuration');
                    const residencyPurpose = document.getElementById('residencyPurpose');
                    if (residencyDuration) residencyDuration.required = true;
                    if (residencyPurpose) residencyPurpose.required = true;
                    break;

                case 'barangay_indigency':
                    const indigencyReason = document.getElementById('indigencyReason');
                    const userPhoto = document.getElementById('userPhoto');
                    if (indigencyReason) indigencyReason.required = true;
                    if (userPhoto) userPhoto.required = true;
                    break;

                case 'cedula':
                    const cedulaOccupation = document.getElementById('cedulaOccupation');
                    const cedulaIncome = document.getElementById('cedulaIncome');
                    if (cedulaOccupation) cedulaOccupation.required = true;
                    if (cedulaIncome) cedulaIncome.required = true;
                    break;

                case 'business_permit_clearance':
                    const businessName = document.getElementById('businessName');
                    const businessType = document.getElementById('businessType');
                    const businessAddress = document.getElementById('businessAddress');
                    const businessPurpose = document.getElementById('businessPurpose');
                    if (businessName) businessName.required = true;
                    if (businessType) businessType.required = true;
                    if (businessAddress) businessAddress.required = true;
                    if (businessPurpose) businessPurpose.required = true;
                    break;

                case 'community_tax_certificate':
                    const ctcOccupation = document.getElementById('ctcOccupation');
                    const ctcIncome = document.getElementById('ctcIncome');
                    if (ctcOccupation) ctcOccupation.required = true;
                    if (ctcIncome) ctcIncome.required = true;
                    break;
            }
        }

        if (documentTypeSelect) {
            documentTypeSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const documentCode = selectedOption.dataset.code || '';
                
                // Update fee label using barangayPrices
                if (feeAmountElement) {
                    const fee = barangayPrices[documentCode] !== undefined ? barangayPrices[documentCode] : 0;
                    feeAmountElement.textContent = fee > 0 ? `₱${parseFloat(fee).toFixed(2)}` : 'Free';
                }
                
                hideAllDocumentFields();
                
                // Show appropriate fields and set required fields based on document type
                switch(documentCode) {
                    case 'barangay_clearance':
                        clearanceFields.style.display = 'block';
                        setFieldsRequired(clearanceFields, documentCode);
                        break;
                    case 'proof_of_residency':
                        residencyFields.style.display = 'block';
                        setFieldsRequired(residencyFields, documentCode);
                        break;
                    case 'barangay_indigency':
                        indigencyFields.style.display = 'block';
                        setFieldsRequired(indigencyFields, documentCode);
                        break;
                    case 'cedula':
                        cedulaFields.style.display = 'block';
                        setFieldsRequired(cedulaFields, documentCode);
                        break;
                    case 'business_permit_clearance':
                        businessPermitFields.style.display = 'block';
                        setFieldsRequired(businessPermitFields, documentCode);
                        break;
                    case 'community_tax_certificate':
                        communityTaxFields.style.display = 'block';
                        setFieldsRequired(communityTaxFields, documentCode);
                        break;
                }
            });
        }

        // Form submission handler
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                // Special validation for indigency photo
                const selectedOption = documentTypeSelect.options[documentTypeSelect.selectedIndex];
                const documentCode = selectedOption.dataset.code;
                
                if (documentCode === 'barangay_indigency') {
                    const photoInput = document.getElementById('userPhoto');
                    if (!photoInput.files || photoInput.files.length === 0) {
                        e.preventDefault();
                        Swal.fire('Error', 'Please upload or take a photo for the Certificate of Indigency.', 'error');
                        return;
                    }
                }
                
                // Validate form before submission
                if (!form.checkValidity()) {
                    return; // Let browser handle validation errors
                }

                e.preventDefault(); // Prevent immediate submission

                if (!isWithinTimeGateJS) {
                    Swal.fire({
                        title: 'Outside Operating Hours',
                        text: 'Document requests can only be submitted between 8:00 AM and 5:00 PM.',
                        icon: 'error'
                    });
                    return;
                }

                <?php if ($hasPendingRequest): ?>
                Swal.fire({
                    title: 'Pending Request Warning',
                    text: 'You have pending document requests. Are you sure you want to submit a new request?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, submit anyway',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitForm();
                    }
                });
                <?php else: ?>
                Swal.fire({
                    title: 'Confirm Submission',
                    text: 'Are you sure you want to submit this document request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, submit',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitForm();
                    }
                });
                <?php endif; ?>
            });
        }

        function submitForm() {
            // Update button state
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            form.submit();

            // Re-enable button after timeout if something goes wrong
            setTimeout(function() {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Request';
                }
            }, 15000);
        }

        // Auto-select document type if provided in URL (?documentType=code)
        const urlParams = new URLSearchParams(window.location.search);
        const docTypeParam = urlParams.get('documentType');
        if (docTypeParam && documentTypeSelect) {
            for (let i = 0; i < documentTypeSelect.options.length; i++) {
                if (documentTypeSelect.options[i].dataset.code === docTypeParam) {
                    documentTypeSelect.selectedIndex = i;
                    documentTypeSelect.dispatchEvent(new Event('change'));
                    break;
                }
            }
        }

        // Initial check for submit button state based on time gate
        if (!isWithinTimeGateJS && submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Unavailable (8AM-5PM)';
        }
    });
    </script>
    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>
</body>
</html>