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

// Get all document types (only the 6 supported)
$stmt = $pdo->query("
SELECT dt.id, dt.name, dt.code, dt.description
FROM document_types dt
WHERE dt.is_active = 1
  AND dt.code IN (
    'barangay_indigency',
    'barangay_clearance',
    'cedula',
    'proof_of_residency',
    'business_permit_clearance',
    'community_tax_certificate'
  )
ORDER BY dt.name
");
$documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            window.location.href = '../pages/user_dashboard.php';
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

    <!-- Navigation Bar (copied from user_dashboard.php) -->
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

    <main>
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" enctype="multipart/form-data" id="docRequestForm">
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
                            foreach ($documentTypes as $doc) {
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
                            <input type="number" id="ctcIncome" name="ctcIncome" placeholder="Enter annual income in PHP" min="0" step="0.01" required>
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

                    <button type="submit" class="btn cta-button" id="submitBtn">Submit Request</button>
                </form>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>

    <script>
    // Document fees for each code
    const documentFees = {
        'barangay_clearance': 30.00,
        'proof_of_residency': 0.00,
        'barangay_indigency': 0.00,
        'cedula': 55.00,
        'business_permit_clearance': 500.00,
        'community_tax_certificate': 6000.00
    };

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

        // Custom validation messages
        const validationMessages = {
            'required': 'This field is required.',
            'min': 'Value must be greater than 0.',
            'step': 'Please enter a valid amount with up to 2 decimal places.',
            'type': 'Please enter a valid number.'
        };

        // Add custom validation styling
        const style = document.createElement('style');
        style.textContent = `
            .form-row input:invalid {
                border-color: #ff4444;
            }
            .form-row input:invalid:focus {
                border-color: #ff4444;
                box-shadow: 0 0 0 2px rgba(255, 68, 68, 0.2);
            }
            .validation-message {
                color: #ff4444;
                font-size: 0.8em;
                margin-top: 4px;
                display: none;
            }
            .form-row input:invalid + .validation-message {
                display: block;
            }
            .document-fields:not([style*="display: none"]) input[required]:invalid {
                border-color: #ff4444;
            }
        `;
        document.head.appendChild(style);

        // Add validation messages to number inputs
        document.querySelectorAll('input[type="number"]').forEach(input => {
            // Add validation message element
            const validationMessage = document.createElement('div');
            validationMessage.className = 'validation-message';
            input.parentNode.insertBefore(validationMessage, input.nextSibling);

            // Add input event listener for live validation
            input.addEventListener('input', function(e) {
                const value = parseFloat(this.value);
                let message = '';

                if (this.value === '') {
                    if (this.required) message = validationMessages.required;
                } else if (isNaN(value)) {
                    message = validationMessages.type;
                } else if (value <= 0) {
                    message = validationMessages.min;
                } else if (value % 0.01 !== 0) {
                    message = validationMessages.step;
                }

                validationMessage.textContent = message;
                this.setCustomValidity(message);
            });
        });

        function hideAllDocumentFields() {
            const allFields = document.querySelectorAll('.document-fields');
            allFields.forEach(field => {
                field.style.display = 'none';
                // Remove required attribute and disable inputs when hiding
                const inputs = field.querySelectorAll('input[type="text"], input[type="number"]');
                inputs.forEach(input => {
                    input.required = false;
                    input.disabled = true;
                });
            });
        }

        function setFieldsRequired(container, documentCode) {
            if (!container) return;

            // Enable all inputs in the visible container
            const inputs = container.querySelectorAll('input[type="text"], input[type="number"]');
            inputs.forEach(input => {
                input.disabled = false;
            });

            // Then set required fields based on document type
            switch(documentCode) {
                case 'barangay_clearance':
                    const purposeClearance = document.getElementById('purposeClearance');
                    if (purposeClearance) {
                        purposeClearance.required = true;
                        purposeClearance.disabled = false;
                    }
                    break;

                case 'proof_of_residency':
                    const residencyDuration = document.getElementById('residencyDuration');
                    const residencyPurpose = document.getElementById('residencyPurpose');
                    if (residencyDuration) {
                        residencyDuration.required = true;
                        residencyDuration.disabled = false;
                    }
                    if (residencyPurpose) {
                        residencyPurpose.required = true;
                        residencyPurpose.disabled = false;
                    }
                    break;

                case 'barangay_indigency':
                    const indigencyReason = document.getElementById('indigencyReason');
                    if (indigencyReason) {
                        indigencyReason.required = true;
                        indigencyReason.disabled = false;
                    }
                    break;

                case 'cedula':
                    const cedulaOccupation = document.getElementById('cedulaOccupation');
                    const cedulaIncome = document.getElementById('cedulaIncome');
                    if (cedulaOccupation) {
                        cedulaOccupation.required = true;
                        cedulaOccupation.disabled = false;
                    }
                    if (cedulaIncome) {
                        cedulaIncome.required = true;
                        cedulaIncome.disabled = false;
                    }
                    break;

                case 'business_permit_clearance':
                    const businessName = document.getElementById('businessName');
                    const businessType = document.getElementById('businessType');
                    const businessAddress = document.getElementById('businessAddress');
                    const businessPurpose = document.getElementById('businessPurpose');
                    if (businessName) {
                        businessName.required = true;
                        businessName.disabled = false;
                    }
                    if (businessType) {
                        businessType.required = true;
                        businessType.disabled = false;
                    }
                    if (businessAddress) {
                        businessAddress.required = true;
                        businessAddress.disabled = false;
                    }
                    if (businessPurpose) {
                        businessPurpose.required = true;
                        businessPurpose.disabled = false;
                    }
                    break;

                case 'community_tax_certificate':
                    const ctcOccupation = document.getElementById('ctcOccupation');
                    const ctcIncome = document.getElementById('ctcIncome');
                    if (ctcOccupation) {
                        ctcOccupation.required = true;
                        ctcOccupation.disabled = false;
                    }
                    if (ctcIncome) {
                        ctcIncome.required = true;
                        ctcIncome.disabled = false;
                    }
                    break;
            }
        }

        if (documentTypeSelect) {
            documentTypeSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const documentCode = selectedOption.dataset.code || '';
                
                // Update fee label
                if (feeAmountElement) {
                    const fee = documentFees[documentCode] !== undefined ? documentFees[documentCode] : 0;
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
        }        // Update form submission handler
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                e.preventDefault(); // Prevent default submission first

                // Get selected document type
                const documentTypeSelect = document.getElementById('documentType');
                const selectedOption = documentTypeSelect.options[documentTypeSelect.selectedIndex];
                const documentCode = selectedOption.dataset.code;
                
                if (!documentCode) {
                    Swal.fire({
                        title: 'Error',
                        text: 'Please select a document type',
                        icon: 'error'
                    });
                    return;
                }

                // Get the relevant fields container based on document type
                let fieldsContainer;
                switch(documentCode) {
                    case 'barangay_clearance':
                        fieldsContainer = document.getElementById('clearanceFields');
                        break;
                    case 'proof_of_residency':
                        fieldsContainer = document.getElementById('residencyFields');
                        break;
                    case 'barangay_indigency':
                        fieldsContainer = document.getElementById('indigencyFields');
                        break;
                    case 'cedula':
                        fieldsContainer = document.getElementById('cedulaFields');
                        break;
                    case 'business_permit_clearance':
                        fieldsContainer = document.getElementById('businessPermitFields');
                        break;
                    case 'community_tax_certificate':
                        fieldsContainer = document.getElementById('communityTaxFields');
                        break;
                }

                // Validate only the fields for the selected document type
                const requiredFields = fieldsContainer.querySelectorAll('input[required]');
                let isValid = true;
                let firstInvalidField = null;

                requiredFields.forEach(field => {
                    // Clear previous validation state
                    field.setCustomValidity('');
                    
                    if (!field.value.trim()) {
                        isValid = false;
                        field.setCustomValidity('This field is required');
                        if (!firstInvalidField) firstInvalidField = field;
                    } else if (field.type === 'number') {
                        const value = parseFloat(field.value);
                        if (isNaN(value) || value < 0) {
                            isValid = false;
                            field.setCustomValidity('Please enter a valid positive number');
                            if (!firstInvalidField) firstInvalidField = field;
                        }
                    }
                });

                if (!isValid) {
                    // Focus the first invalid field
                    if (firstInvalidField) {
                        firstInvalidField.focus();
                    }
                    return;
                }
                    return;
                }

                e.preventDefault(); // Prevent immediate submission

                Swal.fire({
                    title: 'Confirm Submission',
                    text: 'Are you sure you want to submit this document request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, submit',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Update button state
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Submitting...';

                        form.submit();

                        // Optional: Add timeout to re-enable button if something goes wrong
                        setTimeout(function() {
                            if (submitBtn.disabled) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = 'Submit Request';
                            }
                        }, 15000); // Re-enable after 15 seconds
                    }
                });
            });
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
    });
    </script>
</body>
</html>