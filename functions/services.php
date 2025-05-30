<?php
// functions/services.php – full rewrite with SMS capability
session_start();
require_once "../config/dbconn.php";

// Handle form submission for document requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
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
            // Insert into document_requests table
            $stmt = $pdo->prepare("
                INSERT INTO document_requests 
                (document_type_id, person_id, user_id, barangay_id, requested_by_user_id, status, request_date) 
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $documentTypeId,
                $person['id'],
                $user_id,
                $_SESSION['barangay_id'],
                $user_id
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

            // Redirect back to the user dashboard
            header('Location: ../pages/user_dashboard.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

    } catch (Exception $e) {        $_SESSION['error'] = $e->getMessage();
        $redirectTo = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../pages/services.php';
        header('Location: ' . $redirectTo);
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
            window.location.href = 'user_dashboard.php';
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
                        <select id="deliveryMethod" name="deliveryMethod" required>
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
                                   placeholder="Enter purpose (e.g., Employment, Business Permit, etc.)" required>
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

            if (type === false) {
                // Remove required from all fields
                Object.values(fieldMap).flat().forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        field.required = false;
                    }
                });
            } else if (fieldMap[type]) {
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
    });
    </script>
</body>
</html>