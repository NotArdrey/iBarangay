<?php
session_start();
require_once "../config/dbconn.php";

// Fetch user info for navbar (copy from user_dashboard.php)
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
                <form method="POST" action="../functions/services.php" enctype="multipart/form-data" id="docRequestForm">
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

                    <div class="form-row">
                        <label for="deliveryMethod">Receiving Option</label>
                        <select id="deliveryMethod" name="deliveryMethod" required>
                            <option value="">Select Receiving Option</option>
                            <option value="Softcopy">Digital Copy (PDF/Email)</option>
                            <option value="Hardcopy">Printed Copy (Pick-up)</option>
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
                            <input type="text" id="businessName" name="businessName" placeholder="Enter business name" required>
                        </div>
                        <div class="form-row">
                            <label for="businessType">Business Type</label>
                            <input type="text" id="businessType" name="businessType" placeholder="Enter type/nature of business (e.g., Retail, Restaurant, etc.)" required>
                        </div>
                        <div class="form-row">
                            <label for="businessAddress">Business Address</label>
                            <input type="text" id="businessAddress" name="businessAddress" placeholder="Enter complete business address" required>
                        </div>
                        <div class="form-row">
                            <label for="businessPurpose">Purpose</label>
                            <input type="text" id="businessPurpose" name="businessPurpose" placeholder="Purpose for business clearance (e.g., New business permit, Renewal, etc.)" required>
                        </div>
                    </div>

                    <div id="communityTaxFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="ctcOccupation">Occupation</label>
                            <input type="text" id="ctcOccupation" name="ctcOccupation" placeholder="Enter your occupation" required>
                        </div>
                        <div class="form-row">
                            <label for="ctcIncome">Annual Income</label>
                            <input type="number" id="ctcIncome" name="ctcIncome" placeholder="Enter annual income in PHP" min="0" step="0.01" required>
                        </div>
                        <div class="form-row">
                            <label for="ctcPropertyValue">Real Property Value (Optional)</label>
                            <input type="number" id="ctcPropertyValue" name="ctcPropertyValue" placeholder="Enter total value of real property owned" min="0" step="0.01">
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
                switch(documentCode) {
                    case 'barangay_clearance':
                        clearanceFields.style.display = 'block';
                        break;
                    case 'proof_of_residency':
                        residencyFields.style.display = 'block';
                        break;
                    case 'barangay_indigency':
                        indigencyFields.style.display = 'block';
                        break;
                    case 'cedula':
                        cedulaFields.style.display = 'block';
                        break;
                    case 'business_permit_clearance':
                        businessPermitFields.style.display = 'block';
                        break;
                    case 'community_tax_certificate':
                        communityTaxFields.style.display = 'block';
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

        // FIXED: Use form submit event to handle submission properly
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                // Validate form before submission
                if (!form.checkValidity()) {
                    return; // Let browser handle validation errors
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