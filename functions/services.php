<?php
// functions/services.php – full rewrite with SMS capability
session_start();
require_once "../config/dbconn.php";

/**
 * Check if PayMongo integration is available for a barangay
 * @param int $barangay_id The barangay ID to check
 * @return bool True if PayMongo is available, false otherwise
 */
function isPayMongoAvailable($barangay_id) {
    global $pdo;
    
    try {
        // Check if the barangay has PayMongo settings configured
        $stmt = $pdo->prepare("
            SELECT is_enabled, public_key, secret_key 
            FROM barangay_paymongo_settings 
            WHERE barangay_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$barangay_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // PayMongo is available if enabled and keys are provided
        if ($settings && 
            isset($settings['is_enabled']) && 
            $settings['is_enabled'] == 1 && 
            !empty($settings['public_key']) && 
            !empty($settings['secret_key'])) {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        // Log error or handle exception
        error_log("Error checking PayMongo availability: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a PayMongo checkout session
 * @param array $lineItems Array of items to be purchased
 * @param string $successUrl URL to redirect on successful payment
 * @param string $cancelUrl URL to redirect on cancelled payment
 * @param int $barangay_id Barangay ID to get PayMongo credentials
 * @return array|bool Checkout data on success, false on failure
 */
function createPayMongoCheckout($lineItems, $successUrl, $cancelUrl, $barangay_id) {
    global $pdo;
    
    try {
        // Get PayMongo credentials for the barangay
        $stmt = $pdo->prepare("
            SELECT public_key, secret_key 
            FROM barangay_paymongo_settings 
            WHERE barangay_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$barangay_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings || empty($settings['public_key']) || empty($settings['secret_key'])) {
            throw new Exception("PayMongo not configured for this barangay");
        }
        
        $secretKey = $settings['secret_key'];
        
        // Create a unique reference for this checkout
        $reference = 'DOC-' . time() . '-' . rand(1000, 9999);
        
        // Prepare the checkout session data
        $data = [
            'data' => [
                'attributes' => [
                    'line_items' => $lineItems,
                    'payment_method_types' => ['card', 'gcash', 'grab_pay'],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'reference_number' => $reference,
                    'description' => 'Document Request Payment'
                ]
            ]
        ];
        
        // Initialize cURL session to PayMongo API
        $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($secretKey . ':')
        ]);
        
        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Parse the response
        $responseData = json_decode($response, true);
        
        // Check if the request was successful
        if ($httpCode == 200 && isset($responseData['data'])) {
            // Return the checkout URL and ID
            return [
                'id' => $responseData['data']['id'],
                'checkout_url' => $responseData['data']['attributes']['checkout_url']
            ];
        } else {
            // Log error information
            $errorMsg = isset($responseData['errors']) ? json_encode($responseData['errors']) : 'Unknown error';
            error_log("PayMongo API Error: " . $errorMsg);
            return false;
        }
    } catch (Exception $e) {
        error_log("Error creating PayMongo checkout: " . $e->getMessage());
        return false;
    }
}

// Handle form submission for document requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if user has pending blotter cases
        // ... (existing blotter check logic) ...
        // $hasPendingBlotter = ...;
        // if ($hasPendingBlotter) {
        //     throw new Exception("You have pending blotter case(s)...");
        // }

        $documentTypeId = $_POST['document_type_id'] ?? '';
        if (empty($documentTypeId)) {
            throw new Exception("Please select a document type");
        }

        if (!isset($_SESSION['user_id'])) {
            throw new Exception("Please log in to submit a request");
        }
        $user_id = $_SESSION['user_id'];
        $barangay_id = $_SESSION['barangay_id'] ?? 1; // Assuming barangay_id is in session

        // Get person_id
        $person_id = null;
        if (isset($_SESSION['person_id'])) {
            $person_id = $_SESSION['person_id'];
        } else {
            $stmtPerson = $pdo->prepare("SELECT id FROM persons WHERE user_id = ? AND is_archived = FALSE LIMIT 1");
            $stmtPerson->execute([$user_id]);
            $personData = $stmtPerson->fetch();
            if ($personData) {
                $person_id = $personData['id'];
                $_SESSION['person_id'] = $person_id;
            } else {
                 // If no person record exists, create one (simplified for example)
                $stmtUserForPerson = $pdo->prepare("SELECT first_name, last_name, gender FROM users WHERE id = ?");
                $stmtUserForPerson->execute([$user_id]);
                $userInfoForPerson = $stmtUserForPerson->fetch();

                if ($userInfoForPerson) {
                    $stmtCreatePerson = $pdo->prepare("
                        INSERT INTO persons (user_id, first_name, last_name, birth_date, birth_place, gender, civil_status, citizenship)
                        VALUES (?, ?, ?, '1900-01-01', 'Unknown', ?, 'SINGLE', 'Filipino') 
                    "); // Default values
                    $stmtCreatePerson->execute([
                        $user_id,
                        $userInfoForPerson['first_name'],
                        $userInfoForPerson['last_name'],
                        strtoupper($userInfoForPerson['gender'] ?? 'UNKNOWN')
                    ]);
                    $person_id = $pdo->lastInsertId();
                    $_SESSION['person_id'] = $person_id;
                } else {
                    throw new Exception("User details not found to create person record.");
                }
            }
        }
        if (!$person_id) {
            throw new Exception("Associated person record not found. Please update your profile.");
        }


        // Get document type info
        $stmtDocType = $pdo->prepare("
            SELECT dt.*, COALESCE(bdp.price, dt.default_fee) as final_price
            FROM document_types dt
            LEFT JOIN barangay_document_prices bdp ON bdp.document_type_id = dt.id AND bdp.barangay_id = ?
            WHERE dt.id = ?
        ");
        $stmtDocType->execute([$barangay_id, $documentTypeId]);
        $documentType = $stmtDocType->fetch();

        if (!$documentType) {
            throw new Exception("Invalid document type selected");
        }

        // FTJS server-side eligibility check
        $canAvailFtjsServerCheck = true;
        if ($person_id) {
            $stmtFtjsCheck = $pdo->prepare("SELECT 1 FROM document_request_restrictions WHERE person_id = ? AND document_type_code = 'first_time_job_seeker' LIMIT 1");
            $stmtFtjsCheck->execute([$person_id]);
            if ($stmtFtjsCheck->fetch()) {
                $canAvailFtjsServerCheck = false;
            }
        }

        $isFtjsAvailedForClearance = (isset($_POST['ftjs_availed']) && $documentType['code'] === 'barangay_clearance');

        if ($isFtjsAvailedForClearance && !$canAvailFtjsServerCheck) {
            throw new Exception("You are not eligible to avail the First Time Job Seeker benefit, or it has already been used.");
        }
        
        // Handle Cedula requirement for Barangay Clearance
        if ($documentType['code'] === 'barangay_clearance') {
            $hasCompletedCedulaThisYearServerCheck = false;
            if ($person_id) {
                $stmtCedulaCheck = $pdo->prepare("
                    SELECT 1 FROM document_requests dr
                    JOIN document_types dt ON dr.document_type_id = dt.id
                    WHERE dr.person_id = ? AND dt.code = 'cedula' AND YEAR(dr.request_date) = YEAR(CURDATE()) AND dr.status = 'completed'
                    LIMIT 1
                ");
                $stmtCedulaCheck->execute([$person_id]);
                if ($stmtCedulaCheck->fetch()) {
                    $hasCompletedCedulaThisYearServerCheck = true;
                }
            }
            
            // Only block if no bypass and no cedula (make it a warning instead of hard block)
            if (!$hasCompletedCedulaThisYearServerCheck && !isset($_POST['bypass_cedula_check'])) {
                // Changed from throw Exception to just a warning log
                error_log("Warning: Barangay Clearance requested without completed Cedula for person_id: $person_id");
                // Allow submission to continue instead of blocking
            }
        }


        $pdo->beginTransaction();
        
        try {
            $imagePath = null;
            // ... (existing file upload logic for indigency) ...
            if ($documentType['code'] === 'barangay_indigency' && isset($_FILES['userPhoto'])) {
                if ($_FILES['userPhoto']['error'] === UPLOAD_ERR_OK) {
                    // ... (full file upload handling code as in existing file) ...
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

                } else if ($_FILES['userPhoto']['error'] !== UPLOAD_ERR_NO_FILE) {
                     throw new Exception("Error uploading photo: " . $_FILES['userPhoto']['error']);
                } else { // No file uploaded, but it's required for indigency
                    throw new Exception("Photo is required for Barangay Indigency Certificate.");
                }
            }


            $purpose = '';
            $businessName = null;
            $businessLocation = null;
            $businessNature = null;
            $businessType = null;
            $currentPrice = $documentType['final_price']; // Default price

            switch($documentType['code']) {
                case 'barangay_clearance':
                    if ($isFtjsAvailedForClearance && $canAvailFtjsServerCheck) {
                        $purpose = trim($_POST['job_seeker_purpose_ftjs'] ?? '');
                        if (empty($purpose)) {
                            throw new Exception("Purpose for First Time Job Seeker is required.");
                        }
                        $currentPrice = 0.00; // Override price for FTJS
                    } else {
                        $purpose = trim($_POST['purposeClearance'] ?? '');
                        if (empty($purpose)) {
                            throw new Exception("Purpose for Barangay Clearance is required.");
                        }
                    }
                    break;
                case 'proof_of_residency':
                    $purpose = 'Duration: ' . ($_POST['residencyDuration'] ?? '') . 
                               '; Purpose: ' . ($_POST['residencyPurpose'] ?? '');
                    if (empty(trim($_POST['residencyDuration'] ?? '')) || empty(trim($_POST['residencyPurpose'] ?? ''))) {
                        throw new Exception("Duration and Purpose are required for Proof of Residency.");
                    }
                    break;
                case 'barangay_indigency':
                    $purpose = $_POST['indigencyReason'] ?? '';
                     if (empty(trim($purpose))) {
                        throw new Exception("Reason for Indigency is required.");
                    }
                    if (empty($imagePath)) { // Double check image path if required
                        throw new Exception("Photo is required for Barangay Indigency Certificate.");
                    }
                    break;
                case 'business_permit_clearance':
                    $businessName = $_POST['businessName'] ?? '';
                    $businessLocation = $_POST['businessAddress'] ?? '';
                    $businessNature = $_POST['businessPurpose'] ?? '';
                    $businessType = $_POST['businessType'] ?? '';
                    $purpose = 'Business Permit Application';
                    if (empty($businessName) || empty($businessLocation) || empty($businessNature) || empty($businessType)) {
                        throw new Exception("All business information fields are required for Business Permit Clearance.");
                    }
                    break;
                case 'cedula':
                    $purpose = 'Community Tax Certificate';
                    break;
                default:
                    $purpose = 'General purposes'; // Should not happen if validation is correct
                    break;
            }

            $stmtInsert = $pdo->prepare("
                INSERT INTO document_requests (
                    person_id, user_id, document_type_id, barangay_id, 
                    status, request_date, price, purpose, proof_image_path,
                    business_name, business_location, business_nature, business_type,
                    delivery_method, payment_method, requested_by_user_id
                ) VALUES (
                    ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            // Handle delivery_method as comma-separated string if array
            $delivery_method = $_POST['delivery_method'] ?? 'hardcopy';
            if (is_array($delivery_method)) {
                $delivery_method = implode(',', array_filter($delivery_method, function($v) {
                    return in_array($v, ['hardcopy', 'softcopy']);
                }));
            }
            $stmtInsert->execute([
                $currentPrice, $purpose, $imagePath,
                $businessName, $businessLocation, $businessNature, $businessType,
                $delivery_method,
                ($currentPrice > 0 ? ($_POST['payment_method'] ?? 'cash') : 'cash'), // Default to cash if free
                $user_id // requested_by_user_id
            ]);
            $requestId = $pdo->lastInsertId();

            // Record FTJS usage if availed
            if ($isFtjsAvailedForClearance && $canAvailFtjsServerCheck && $person_id) {
                // Check again to be absolutely sure before inserting restriction
                $stmtFtjsCheckAgain = $pdo->prepare("SELECT 1 FROM document_request_restrictions WHERE person_id = ? AND document_type_code = 'first_time_job_seeker' LIMIT 1");
                $stmtFtjsCheckAgain->execute([$person_id]);
                if (!$stmtFtjsCheckAgain->fetch()) {
                    $stmtRestrict = $pdo->prepare("
                        INSERT INTO document_request_restrictions (person_id, document_type_code, restriction_reason, created_at, updated_at)
                        VALUES (?, 'first_time_job_seeker', ?, NOW(), NOW())
                    ");
                    // Ensure restriction_reason column exists and is varchar
                    $restrictionReason = "Availed with Barangay Clearance Request ID: " . $requestId . " on " . date('Y-m-d');
                    $stmtRestrict->execute([$person_id, $restrictionReason]);
                }
            }

            $pdo->commit();

            $_SESSION['success'] = [
                'title' => 'Document Request Submitted',
                'message' => 'Your document request has been submitted successfully.',
                'processing' => 'Please wait for the processing of your request. You will be notified once it is ready.'
            ];
            $_SESSION['show_pending'] = true;
            header('Location: ../pages/services.php?show_pending=1'); // Redirect to services page
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            if ($imagePath && file_exists('../' . $imagePath)) {
                unlink('../' . $imagePath);
            }
            $_SESSION['error'] = "Submission Error: " . $e->getMessage();
            header('Location: ../pages/services.php'); // Redirect back to services page
            exit;
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
        header('Location: ../pages/services.php'); // Redirect back to services page
        exit;
    }
} else {
    // If not a POST request, or some other scenario, redirect or show error
    // For example, redirect to the services page if accessed directly without POST
    // header('Location: ../pages/services.php');
    // exit;
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

// Get all document types from database
$stmt = $pdo->query("
SELECT dt.id, dt.name, dt.code, dt.description, dt.default_fee
FROM document_types dt
WHERE dt.is_active = 1
ORDER BY dt.name
");
$dbDocumentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create a map for quick lookup
$docMap = [];
foreach ($dbDocumentTypes as $doc) {
    $docMap[$doc['code']] = $doc;
}

// Define the correct order with Barangay Clearance first
$requiredDocs = [
    'barangay_clearance',           // Most common - should be first
    'barangay_indigency', 
    'proof_of_residency',
    'cedula',
    'business_permit_clearance',
    'community_tax_certificate'
];

// Build ordered document types array
$documentTypes = [];
foreach ($requiredDocs as $code) {
    if (isset($docMap[$code])) {
        $documentTypes[] = $docMap[$code];
    }
}

$selectedDocumentType = $_GET['documentType'] ?? '';
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
</head>
<body>
    <?php if (isset($_SESSION['success'])): ?>
    <script>
        Swal.fire({
            title: '<?= $_SESSION['success']['title'] ?>',
            html: `<b><?= $_SESSION['success']['message'] ?></b><br><br><?= $_SESSION['success']['processing'] ?>`,
            icon: 'success'
        }).then(() => {
            window.location.href = 'services.php';
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

    /* Document Info Styles */
    .document-info {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        font-size: 0.9rem;
    }

    .document-info h4 {
        margin: 0 0 0.5rem 0;
        color: #0a2240;
        font-size: 1rem;
    }

    .document-info p {
        margin: 0;
        color: #6c757d;
        line-height: 1.4;
    }

    .fee-highlight {
        background: #e8f5e8;
        border: 1px solid #d4edda;
        border-radius: 4px;
        padding: 0.5rem;
        margin-top: 0.5rem;
        color: #155724;
        font-weight: 500;
    }

    .fee-highlight.paid {
        background: #fff3cd;
        border-color: #ffeaa7;
        color: #856404;
    }
    </style>

    <main>
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                
                <!-- Document Information Display -->
                <div id="documentInfo" class="document-info" style="display: none;">
                    <h4 id="docInfoTitle">Document Information</h4>
                    <p id="docInfoDescription">Select a document to see details</p>
                    <div id="docInfoFee" class="fee-highlight">Fee: ₱0.00</div>
                </div>

                <form method="POST" action="../functions/services.php" enctype="multipart/form-data" id="docRequestForm">
                    <div class="form-row">
                        <label for="documentType">Document Type *</label>
                        <select id="documentType" name="document_type_id" required>
                            <option value="">Select Document Type</option>
                            <?php foreach ($documentTypes as $doc): ?>
                                <option value="<?= $doc['id'] ?>" 
                                        data-code="<?= $doc['code'] ?>"
                                        data-fee="<?= $doc['default_fee'] ?>"
                                        data-description="<?= htmlspecialchars($doc['description'] ?? '') ?>">
                                    <?= htmlspecialchars($doc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="deliveryMethod">Delivery Method *</label>
                        <select id="deliveryMethod" name="delivery_method" required>
                            <option value="">Select Delivery Method</option>
                            <option value="Softcopy">Softcopy (Digital)</option>
                            <option value="Hardcopy">Hardcopy (Physical)</option>
                        </select>
                    </div>

                    <!-- Document-specific fields -->
                    <div id="clearanceFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="purposeClearance">Purpose of Clearance *</label>
                            <input type="text" id="purposeClearance" name="purposeClearance" 
                                   placeholder="Enter purpose (e.g., Employment, Business Permit, etc.)">
                        </div>
                    </div>

                    <div id="residencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="residencyDuration">Duration of Residency *</label>
                            <input type="text" id="residencyDuration" name="residencyDuration" 
                                   placeholder="e.g., 5 years" required>
                        </div>
                        <div class="form-row">
                            <label for="residencyPurpose">Purpose *</label>
                            <input type="text" id="residencyPurpose" name="residencyPurpose" 
                                   placeholder="Enter purpose (e.g., School enrollment, Scholarship, etc.)" required>
                        </div>
                    </div>

                    <div id="indigencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="indigencyReason">Reason for Requesting *</label>
                            <input type="text" id="indigencyReason" name="indigencyReason" 
                                   placeholder="Enter reason (e.g., Medical assistance, Educational assistance, etc.)" required>
                        </div>
                    </div>

                    <div id="cedulaFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="cedulaOccupation">Occupation *</label>
                            <input type="text" id="cedulaOccupation" name="cedulaOccupation" 
                                   placeholder="Enter your occupation" required>
                        </div>
                        <div class="form-row">
                            <label for="cedulaIncome">Annual Income *</label>
                            <input type="number" id="cedulaIncome" name="cedulaIncome" 
                                   placeholder="Enter annual income in PHP" min="0" step="0.01" required>
                        </div>
                        <div class="form-row">
                            <label for="cedulaBirthplace">Place of Birth</label>
                            <input type="text" id="cedulaBirthplace" name="cedulaBirthplace" 
                                   placeholder="Enter place of birth">
                        </div>
                    </div>

                    <div id="businessPermitFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="businessName">Business Name *</label>
                            <input type="text" id="businessName" name="businessName" 
                                   placeholder="Enter business name" required>
                        </div>
                        <div class="form-row">
                            <label for="businessType">Business Type *</label>
                            <input type="text" id="businessType" name="businessType" 
                                   placeholder="Enter type/nature of business (e.g., Retail, Restaurant, etc.)" required>
                        </div>
                        <div class="form-row">
                            <label for="businessAddress">Business Address *</label>
                            <input type="text" id="businessAddress" name="businessAddress" 
                                   placeholder="Enter complete business address" required>
                        </div>
                        <div class="form-row">
                            <label for="businessPurpose">Purpose *</label>
                            <input type="text" id="businessPurpose" name="businessPurpose" 
                                   placeholder="Purpose for business clearance (e.g., New business permit, Renewal, etc.)" required>
                        </div>
                    </div>

                    <div id="communityTaxFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="ctcOccupation">Occupation *</label>
                            <input type="text" id="ctcOccupation" name="ctcOccupation" 
                                   placeholder="Enter your occupation" required>
                        </div>
                        <div class="form-row">
                            <label for="ctcIncome">Annual Income *</label>
                            <input type="number" id="ctcIncome" name="ctcIncome" 
                                   placeholder="Enter annual income in PHP" min="0" step="0.01" required>
                        </div>
                        <div class="form-row">
                            <label for="ctcPropertyValue">Real Property Value</label>
                            <input type="number" id="ctcPropertyValue" name="ctcPropertyValue" 
                                   placeholder="Enter total value of real property owned" min="0" step="0.01">
                        </div>
                        <div class="form-row">
                            <label for="ctcBirthplace">Place of Birth</label>
                            <input type="text" id="ctcBirthplace" name="ctcBirthplace" 
                                   placeholder="Enter place of birth">
                        </div>
                    </div>

                    <button type="submit" class="btn cta-button" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </form>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>

    <script>
    // Document information
    const documentInfo = {
        'barangay_clearance': {
            title: 'Barangay Clearance',
            description: 'Required for employment, business permits, and various transactions. This document certifies that you are a resident of good standing in the barangay.',
            fee: 30.00
        },
        'barangay_indigency': {
            title: 'Certificate of Indigency',
            description: 'For accessing social welfare programs and financial assistance. This document certifies your financial status for assistance programs.',
            fee: 0.00
        },
        'proof_of_residency': {
            title: 'Certificate of Residency',
            description: 'Official proof of residence in the barangay. Required for school enrollment, scholarship applications, and other official purposes.',
            fee: 0.00
        },
        'cedula': {
            title: 'Community Tax Certificate (Sedula)',
            description: 'Annual tax certificate required for government transactions. Valid for one calendar year from date of issuance.',
            fee: 55.00
        },
        'business_permit_clearance': {
            title: 'Business Permit Clearance',
            description: 'Barangay clearance required for business license applications. Certifies compliance with local regulations.',
            fee: 500.00
        },
        'community_tax_certificate': {
            title: 'Community Tax Certificate',
            description: 'Annual tax certificate for residents and corporations. Required for various legal and business transactions.',
            fee: 6000.00
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        const documentTypeSelect = document.getElementById('documentType');
        const documentInfoDiv = document.getElementById('documentInfo');
        const docInfoTitle = document.getElementById('docInfoTitle');
        const docInfoDescription = document.getElementById('docInfoDescription');
        const docInfoFee = document.getElementById('docInfoFee');
        const form = document.getElementById('docRequestForm');
        const submitBtn = document.getElementById('submitBtn');

        if (documentTypeSelect) {
            documentTypeSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const documentCode = selectedOption.dataset.code || '';
                
                // Update document information display
                if (documentCode && documentInfo[documentCode]) {
                    const info = documentInfo[documentCode];
                    docInfoTitle.textContent = info.title;
                    docInfoDescription.textContent = info.description;
                    
                    if (info.fee > 0) {
                        docInfoFee.textContent = `Fee: ₱${parseFloat(info.fee).toFixed(2)}`;
                        docInfoFee.className = 'fee-highlight paid';
                    } else {
                        docInfoFee.textContent = 'Fee: Free';
                        docInfoFee.className = 'fee-highlight';
                    }
                    
                    documentInfoDiv.style.display = 'block';
                } else {
                    documentInfoDiv.style.display = 'none';
                }

                // Show/hide document-specific fields
                hideAllDocumentFields();
                updateRequiredFields(documentCode, false); // Remove required first
                
                switch(documentCode) {
                    case 'barangay_clearance':
                        document.getElementById('clearanceFields').style.display = 'block';
                        updateRequiredFields('clearance', true);
                        break;
                    case 'proof_of_residency':
                        document.getElementById('residencyFields').style.display = 'block';
                        updateRequiredFields('residency', true);
                        break;
                    case 'barangay_indigency':
                        document.getElementById('indigencyFields').style.display = 'block';
                        updateRequiredFields('indigency', true);
                        break;
                    case 'cedula':
                        document.getElementById('cedulaFields').style.display = 'block';
                        updateRequiredFields('cedula', true);
                        break;
                    case 'business_permit_clearance':
                        document.getElementById('businessPermitFields').style.display = 'block';
                        updateRequiredFields('business', true);
                        break;
                    case 'community_tax_certificate':
                        document.getElementById('communityTaxFields').style.display = 'block';
                        updateRequiredFields('ctc', true);
                        break;
                }
            });
        }

        function hideAllDocumentFields() {
            const allFields = document.querySelectorAll('.document-fields');
            allFields.forEach(field => {
                field.style.display = 'none';
            });
        }

        function updateRequiredFields(type, required) {
            const fieldMap = {
                'clearance': ['purposeClearance'],
                'residency': ['residencyDuration', 'residencyPurpose'],
                'indigency': ['indigencyReason'],
                'cedula': ['cedulaOccupation', 'cedulaIncome'],
                'business': ['businessName', 'businessType', 'businessAddress', 'businessPurpose'],
                'ctc': ['ctcOccupation', 'ctcIncome']
            };

            // Always remove required from all fields first
            Object.values(fieldMap).flat().forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.required = false;
                }
            });

            // Then set required only for the current type
            if (type && fieldMap[type]) {
                fieldMap[type].forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        field.required = required;
                    }
                });
            }
        }

        // Form submission handling
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                if (!form.checkValidity()) {
                    return;
                }

                e.preventDefault();

                Swal.fire({
                    title: 'Confirm Submission',
                    text: 'Are you sure you want to submit this document request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, submit',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                        form.submit();

                        setTimeout(function() {
                            if (submitBtn.disabled) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
                            }
                        }, 15000);
                    }
                });
            });
        }

        // Auto-select document type from URL parameter
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

        // Handle payment method change
        const paymentMethodSelect = document.getElementById('paymentMethod');
        const paymentInfo = document.getElementById('paymentInfo');
        const paymentInfoText = document.getElementById('paymentInfoText');

        if (paymentMethodSelect) {
            // Update payment method options based on PayMongo availability
            function updatePaymentMethodOptions() {
                paymentMethodSelect.innerHTML = `
                    <option value="">Select Payment Method</option>
                    <option value="cash">Cash (Pay at Barangay Office)</option>
                `;
                
                if (paymongoAvailable) {
                    paymentMethodSelect.innerHTML += `<option value="online">Online Payment (PayMongo)</option>`;
                }
            }
            
            updatePaymentMethodOptions();
            
            paymentMethodSelect.addEventListener('change', function() {
                const selectedMethod = this.value;
                const selectedDoc = documentTypeSelect.options[documentTypeSelect.selectedIndex];
                const fee = barangayPrices[selectedDoc.dataset.code] || 0;
                
                if (selectedMethod && fee > 0) {
                    paymentInfo.style.display = 'block';
                    
                    if (selectedMethod === 'cash') {
                        paymentInfoText.innerHTML = `
                            <strong>Cash Payment:</strong><br>
                            • Pay ₱${fee.toFixed(2)} at the Barangay Office<br>
                            • Document will be processed after payment confirmation<br>
                            • Bring valid ID when paying
                        `;
                    } else if (selectedMethod === 'online') {
                        if (paymongoAvailable) {
                            paymentInfoText.innerHTML = `
                                <strong>Online Payment:</strong><br>
                                • Pay ₱${fee.toFixed(2)} via PayMongo<br>
                                • You will be redirected to secure payment page<br>
                                • Document processing starts after successful payment<br>
                                • Payment confirmation will be sent via email
                            `;
                        } else {
                            paymentInfoText.innerHTML = `
                                <strong>Online Payment Not Available:</strong><br>
                                • Online payment is not configured for this barangay<br>
                                • Please select cash payment instead
                            `;
                        }
                    }
                } else {
                    paymentInfo.style.display = 'none';
                }
            });
        }

        // Validate form before submission
        form.addEventListener('submit', function(e) {
            const selectedDoc = documentTypeSelect.options[documentTypeSelect.selectedIndex];
            const fee = barangayPrices[selectedDoc.dataset.code] || 0;
            const paymentMethod = paymentMethodSelect.value;

            // Validate payment method for paid documents
            if (fee > 0 && !paymentMethod) {
                Swal.fire('Error', 'Please select a payment method.', 'error');
                return;
            }
            
            // Check if online payment is available
            if (paymentMethod === 'online' && !paymongoAvailable) {
                Swal.fire('Error', 'Online payment is not available for this barangay. Please select cash payment.', 'error');
                return;
            }
        });

    });
    </script>

</body>
</html>

    <?php
    // This file would typically contain functions for handling service and document requests.

    /**
     * Example of where to put logic for First Time Job Seeker restriction.
     * 
     * When a "First Time Job Seeker" document request is successfully processed and completed,
     * you need to add an entry to the `document_request_restrictions` table to mark
     * that this person has availed the certificate.
     *
     * This function would be called after a First Time Job Seeker request is marked as 'completed'.
     */
   
    function recordFirstTimeJobSeekerAvailment($pdo, $person_id) {
        if (!$person_id) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO document_request_restrictions 
                    (person_id, document_type_code, first_requested_at, request_count) 
                VALUES 
                    (?, 'first_time_job_seeker', NOW(), 1)
                ON DUPLICATE KEY UPDATE 
                    request_count = request_count + 1, 
                    updated_at = NOW()
            ");
            $stmt->execute([$person_id]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Log error or handle it as needed
            // error_log("Error recording FTJS availment: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the user has a completed Cedula for the current year (any barangay).
     * Returns true if found, false otherwise.
     */
    function hasCompletedCedulaThisYear($pdo, $person_id) {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM document_requests dr
            JOIN document_types dt ON dr.document_type_id = dt.id
            WHERE dr.person_id = ? 
              AND dt.code = 'cedula' 
              AND YEAR(dr.request_date) = YEAR(CURDATE()) 
              AND dr.status = 'completed'
            LIMIT 1
        ");
        $stmt->execute([$person_id]);
        return (bool)$stmt->fetch();
    }


?>