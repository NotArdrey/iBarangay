<?php
session_start();
require_once "../config/dbconn.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit;
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

    <header>
        <nav class="navbar">
            <a href="#" class="logo">
                <img src="../photo/logo.png" alt="iBarangay Logo">
                <h2>iBarangay</h2>
            </a>
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

    <main>
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                <form method="POST" action="../functions/services.php" enctype="multipart/form-data" id="docRequestForm">
                    <div class="form-row">
                        <label for="documentType">Document Type</label>
                        <select id="documentType" name="document_type_id" required>
                            <option value="">Select Document</option>
                            <?php foreach ($documentTypes as $doc): ?>
                            <option value="<?= $doc['id'] ?>" data-code="<?= $doc['code'] ?>">
                                <?= htmlspecialchars($doc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label for="deliveryMethod">Delivery Method</label>
                        <select id="deliveryMethod" name="deliveryMethod" required>
                            <option value="">Select Delivery Method</option>
                            <option value="Softcopy">Softcopy (Digital)</option>
                            <option value="Hardcopy">Hardcopy (Physical)</option>
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

            // Fix for submit button: attach click event instead of form submit event
            if (submitBtn) {
                submitBtn.addEventListener('click', function() {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Submitting...';
                });
            }
        });
    </script>
</body>
</html>