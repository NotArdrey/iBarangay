<?php
session_start();
require_once "../config/dbconn.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit;
}
//../pages/services.php
// Get user ID
$userId = $_SESSION['user_id'];

// Get all document types
$stmt = $pdo->query("
SELECT dt.document_type_id, dt.document_name, df.fee
FROM DocumentType dt
LEFT JOIN DocumentFee df
  ON df.document_type = LOWER(REPLACE(dt.document_name,' ',''))
");
$documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if user has already requested a First Time Job Seeker document
$firstTimeJobSeekerCheck = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM DocumentRequest dr
    JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
    WHERE dr.user_id = ? 
    AND dt.document_name = 'First Time Job Seeker'
");
$firstTimeJobSeekerCheck->execute([$userId]);
$hasRequestedFirstTimeJobSeeker = $firstTimeJobSeekerCheck->fetch(PDO::FETCH_ASSOC)['count'] > 0;

$documentFees = [
    'barangayClearance' => 50.00,
    'firstTimeJobSeeker' => 0.00,
    'proofOfResidency' => 30.00,
    'barangayIndigency' => 20.00,
    'goodMoralCertificate' => 30.00,
    'noIncomeCertification' => 0.00
];

$userBarangayId = null;

try {
    $stmt = $pdo->prepare("SELECT barangay_id FROM Users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userBarangay = $stmt->fetch(PDO::FETCH_ASSOC);
    $userBarangayId = $userBarangay['barangay_id'];
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
}

// Initialize payment method variables
$paymentMethod = null;
$methodName = null;
$accountDetails = 'Not available';

if ($userBarangayId) {
    try {
        // Get the active payment method for the barangay
        $stmt = $pdo->prepare("
            SELECT method, account_details
            FROM BarangayPaymentMethod
            WHERE barangay_id = ?
            AND is_active = 'yes'
            LIMIT 1
        ");
        $stmt->execute([$userBarangayId]);
        $paymentData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($paymentData) {
            $paymentMethod = $paymentData['method'];
            $accountDetails = $paymentData['account_details'];
            $methodName = ($paymentMethod == 'GCash') ? 'GCash' : 'PayMongo';
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
}

try {
    $stmt = $pdo->prepare("SELECT barangay_id, barangay_name FROM Barangay ORDER BY barangay_name");
    $stmt->execute();
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
}

$selectedDocumentType = $_GET['documentType'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Hub - Document Request</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../styles/services.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Payment Section Styles */
        .payment-section {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        
        .fee-display {
            display: flex;
            align-items: center;
            background-color: #eef2ff;
            padding: 0.75rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }
        
        .fee-display span {
            margin-right: 0.5rem;
        }
        
        .fee-display strong {
            font-size: 1.125rem;
            color: #1e40af;
        }
        
        .gcash-payment-method, .paymongo-payment-method {
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .gcash-header, .paymongo-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .gcash-header h4, .paymongo-header h4 {
            margin: 0 0 0 0.75rem;
            font-size: 1.125rem;
        }
        
        .gcash-number {
            background-color: #f3f4f6;
            padding: 0.75rem;
            border-radius: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 0.75rem 0;
        }
        
        .copy-btn {
            background-color: #e5e7eb;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
        }
        
        .copy-btn:hover {
            background-color: #d1d5db;
        }
        
        .copy-btn i {
            margin-right: 0.25rem;
        }
        
        .payment-options {
            display: flex;
            gap: 1rem;
            margin: 0.75rem 0;
        }
        
        .payment-options span {
            display: flex;
            align-items: center;
            background-color: #f3f4f6;
            padding: 0.5rem 0.75rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        
        .payment-options span i {
            margin-right: 0.5rem;
        }
        
        .payment-button {
            width: 100%;
            background-color: #2563eb;
            color: white;
            padding: 0.75rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1rem 0;
        }
        
        .payment-button:hover {
            background-color: #1d4ed8;
        }
        
        .payment-button i {
            margin-right: 0.5rem;
        }
        
        .payment-status {
            text-align: center;
            font-size: 0.875rem;
            margin: 0.5rem 0;
            min-height: 1.5rem;
        }
        
        .payment-note {
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
            margin-top: 0.75rem;
        }
        
        .no-payment-method {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: 0.375rem;
            padding: 1rem;
        }
        
        .payment-warning {
            color: #b91c1c;
            display: flex;
            align-items: center;
        }
        
        .payment-warning i {
            margin-right: 0.5rem;
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
                <img src="../photo/logo.png" alt="Barangay Hub Logo">
                <h2>Barangay Hub</h2>
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
                <form method="POST" action="../functions/services.php" enctype="multipart/form-data">
                    <div class="form-row upload-id">
                        <label for="uploadId">Upload Valid ID</label>
                        <input type="file" name="uploadId" id="uploadId" 
                               accept="image/jpeg,image/png,application/pdf" required>
                        <small>Accepted formats: JPG, PNG, PDF (Max 2MB)</small>
                    </div>

                    <div class="form-row">
                        <label for="barangaySelect">Select Barangay</label>
                        <select id="barangaySelect" name="barangay_id" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $b): ?>
                            <option value="<?= $b['barangay_id'] ?>" 
                                <?= $b['barangay_id'] === $userBarangayId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['barangay_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                    <label for="documentType">Document Type</label>
                    <select id="documentType"
                            name="document_type_id"
                            required>
                      <option value="">Select Document</option>
                      <?php foreach ($documentTypes as $doc): 
                        // Skip First Time Job Seeker if user has already requested it
                        if ($doc['document_name'] == 'First Time Job Seeker' && $hasRequestedFirstTimeJobSeeker) {
                            continue;
                        }
                      ?>
                        <option
                          value="<?= $doc['document_type_id'] ?>"
                          data-fee="<?= $doc['fee'] ?? 0 ?>"
                        >
                          <?= htmlspecialchars($doc['document_name']) ?>
                          (<?= $doc['fee'] > 0 ? '₱'.number_format($doc['fee'],2) : 'Free' ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($hasRequestedFirstTimeJobSeeker): ?>
                      <small class="text-info">Note: First Time Job Seeker certificate can only be requested once.</small>
                    <?php endif; ?>
                  </div>

                    <div class="form-row">
                        <label for="deliveryMethod">Delivery Method</label>
                        <select id="deliveryMethod" name="deliveryMethod" required>
                            <option value="">Select Delivery Method</option>
                            <option value="Softcopy">Softcopy (Digital)</option>
                            <option value="Hardcopy">Hardcopy (Physical)</option>
                        </select>
                    </div>

                    <!-- Document-specific fields will be shown/hidden based on selection -->
                    <div id="clearanceFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="purposeClearance">Purpose of Clearance</label>
                            <input type="text" id="purposeClearance" name="purposeClearance" placeholder="Enter purpose">
                        </div>
                    </div>

                    <div id="residencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="residencyDuration">Duration of Residency</label>
                            <input type="text" id="residencyDuration" name="residencyDuration" placeholder="e.g., 5 years">
                        </div>
                        <div class="form-row">
                            <label for="residencyPurpose">Purpose</label>
                            <input type="text" id="residencyPurpose" name="residencyPurpose" placeholder="Enter purpose">
                        </div>
                    </div>

                    <div id="gmcFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="gmcPurpose">Purpose</label>
                            <input type="text" id="gmcPurpose" name="gmcPurpose" placeholder="Enter purpose">
                        </div>
                    </div>

                    <div id="nicFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="nicReason">Reason for No Income</label>
                            <input type="text" id="nicReason" name="nicReason" placeholder="Enter reason">
                        </div>
                    </div>

                    <div id="indigencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="indigencyIncome">Monthly Income (if any)</label>
                            <input type="number" id="indigencyIncome" name="indigencyIncome" placeholder="Enter amount">
                        </div>
                        <div class="form-row">
                            <label for="indigencyReason">Reason for Requesting</label>
                            <input type="text" id="indigencyReason" name="indigencyReason" placeholder="Enter reason">
                        </div>
                    </div>

                    <!-- Enhanced Payment Section -->
                    <div id="payment-section" style="display: none;" class="payment-section">
                        <div class="payment-info">
                            <h3>Payment Details</h3>
                            <div class="fee-display">
                                <span>Document Fee:</span>
                                <strong id="feeAmount">₱0.00</strong>
                            </div>
                            
                            <?php if ($paymentMethod): ?>
                                <?php if ($paymentMethod === 'GCash'): ?>
                                    <!-- GCash Payment Method -->
                                    <div class="gcash-payment-method">
                                        <div class="gcash-info">
                                            <div class="gcash-header">
                                                <img src="../assets/icons/gcash-logo.png" alt="GCash" width="60" onerror="this.src='../photo/gcash.png'; this.onerror=null;">
                                                <h4>GCash Payment</h4>
                                            </div>
                                            <div class="gcash-instructions">
                                                <p>Please send the exact amount to this GCash number:</p>
                                                <div class="gcash-number">
                                                    <strong><?= htmlspecialchars($accountDetails) ?></strong>
                                                    <button type="button" 
                                                            onclick="navigator.clipboard.writeText('<?= htmlspecialchars($accountDetails) ?>'); alert('GCash number copied!');"
                                                            class="copy-btn">
                                                        <i class="fas fa-copy"></i> Copy
                                                    </button>
                                                </div>
                                                <p class="payment-note">Upload the screenshot/proof of payment below before submitting your request.</p>
                                            </div>
                                        </div>
                                        
                                        <!-- GCash Proof Upload Field -->
                                        <div class="form-row">
                                            <label for="uploadProof">Upload Payment Receipt</label>
                                            <input type="file" name="uploadProof" id="uploadProof" 
                                                   accept="image/jpeg,image/png,application/pdf" required>
                                            <small>Accepted formats: JPG, PNG, PDF (Max 2MB)</small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- PayMongo Payment Method -->
                                    <div class="paymongo-payment-method">
                                        <div class="paymongo-header">
                                            <img src="../assets/icons/paymongo-logo.png" alt="PayMongo" width="80" onerror="this.src='../photo/credit-card.png'; this.onerror=null;">
                                            <h4>Secure Online Payment</h4>
                                        </div>
                                        <div class="paymongo-info">
                                            <p>Complete your payment securely via credit card, GCash, or GrabPay:</p>
                                            <div class="payment-options">
                                                <span><i class="fas fa-credit-card"></i> Credit Card</span>
                                                <span><i class="fas fa-wallet"></i> GCash</span>
                                                <span><i class="fas fa-money-bill-wave"></i> GrabPay</span>
                                            </div>
                                            <button type="button" id="paymongoBtn" class="btn payment-button">
                                                <i class="fas fa-credit-card"></i> Pay Now
                                            </button>
                                            <input type="hidden" name="paymongoReference" id="paymongoReference" value="">
                                            <p id="paymongoStatus" class="payment-status"></p>
                                            <p class="payment-note">After completing payment, return to this page to submit your request.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="no-payment-method">
                                    <p class="payment-warning">
                                        <i class="fas fa-exclamation-triangle"></i> 
                                        No payment methods are currently available for this barangay. 
                                        Please contact your Barangay office for assistance.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <input type="hidden" id="paymentAmount" name="paymentAmount" value="0">
                        <input type="hidden" id="paymentMethod" name="paymentMethod" value="<?= $paymentMethod ?? '' ?>">
                    </div>

                    <button type="submit" class="btn cta-button">Submit Request</button>
                </form>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 Barangay Hub. All rights reserved.</p>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const documentTypeSelect = document.getElementById('documentType');
            const deliveryMethodSelect = document.getElementById('deliveryMethod');
            const paymentSection = document.getElementById('payment-section');
            const feeAmountElement = document.getElementById('feeAmount');
            const paymentAmountInput = document.getElementById('paymentAmount');
            const uploadProofInput = document.getElementById('uploadProof');
            const paymongoBtn = document.getElementById('paymongoBtn');
            const paymongoReference = document.getElementById('paymongoReference');
            const paymongoStatus = document.getElementById('paymongoStatus');
            
            // Document-specific fields
            const clearanceFields = document.getElementById('clearanceFields');
            const residencyFields = document.getElementById('residencyFields');
            const gmcFields = document.getElementById('gmcFields');
            const nicFields = document.getElementById('nicFields');
            const indigencyFields = document.getElementById('indigencyFields');
            
            // Set initial state
            updatePaymentSection();
            
            // Add event listeners
            if (documentTypeSelect) {
                documentTypeSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const fee = selectedOption.dataset.fee || 0;
                    const documentName = selectedOption.textContent.trim().split('(')[0].trim().toLowerCase();
                    
                    if (feeAmountElement) {
                        feeAmountElement.textContent = fee > 0 ? `₱${parseFloat(fee).toFixed(2)}` : 'Free';
                    }
                    
                    if (paymentAmountInput) {
                        paymentAmountInput.value = fee;
                    }
                    
                    // Show/hide document-specific fields
                    hideAllDocumentFields();
                    if (documentName.includes('clearance')) {
                        clearanceFields.style.display = 'block';
                    } else if (documentName.includes('residency')) {
                        residencyFields.style.display = 'block';
                    } else if (documentName.includes('moral')) {
                        gmcFields.style.display = 'block';
                    } else if (documentName.includes('income')) {
                        nicFields.style.display = 'block';
                    } else if (documentName.includes('indigency')) {
                        indigencyFields.style.display = 'block';
                    }
                    
                    updatePaymentSection();
                });
            }
            
            if (deliveryMethodSelect) {
                deliveryMethodSelect.addEventListener('change', updatePaymentSection);
            }
            
            // Helper function to hide all document-specific fields
            function hideAllDocumentFields() {
                const allFields = document.querySelectorAll('.document-fields');
                allFields.forEach(field => {
                    field.style.display = 'none';
                });
            }
            
            // PayMongo button click handler
            if (paymongoBtn) {
                paymongoBtn.addEventListener('click', function() {
                    const amount = parseFloat(paymentAmountInput.value);
                    if (!amount || amount <= 0) {
                        Swal.fire({
                            title: 'Error',
                            text: 'Please select a document that requires payment',
                            icon: 'error'
                        });
                        return;
                    }
                    
                    createPayMongoCheckout(amount);
                });
            }
            
            /**
             * Updates the visibility of payment-related elements based on selected options
             */
            function updatePaymentSection() {
                const fee = parseFloat(paymentAmountInput.value) || 0;
                const deliveryMethod = deliveryMethodSelect ? deliveryMethodSelect.value : '';
                const shouldShowPayment = fee > 0 && deliveryMethod;
                
                if (paymentSection) {
                    paymentSection.style.display = shouldShowPayment ? 'block' : 'none';
                }
                
                // Show/hide proof upload field based on payment method
                if (uploadProofInput) {
                    const paymentMethod = document.getElementById('paymentMethod').value;
                    const proofRow = uploadProofInput.closest('.form-row');
                    
                    if (proofRow) {
                        if (paymentMethod === 'GCash' && shouldShowPayment) {
                            proofRow.style.display = 'block';
                            uploadProofInput.required = true;
                        } else {
                            proofRow.style.display = 'none';
                            uploadProofInput.required = false;
                        }
                    }
                }
            }
            
            /**
             * Creates a PayMongo checkout session
             */
            function createPayMongoCheckout(amount) {
                // Show loading state
                if (paymongoBtn) {
                    paymongoBtn.disabled = true;
                    paymongoBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                }
                
                if (paymongoStatus) {
                    paymongoStatus.innerHTML = '<span class="text-blue-600">Connecting to payment gateway...</span>';
                }
                
                // Get document info
                const documentId = documentTypeSelect ? documentTypeSelect.value : null;
                const documentName = documentTypeSelect && documentTypeSelect.selectedOptions[0] ? 
                                    documentTypeSelect.selectedOptions[0].textContent.trim().split('(')[0].trim() : 'Document';
                
                // Prepare request data
                const requestData = {
                    amount: amount,
                    document_id: documentId,
                    description: `Payment for ${documentName}`
                };
                
                // Make AJAX request to create payment
                fetch('../functions/services.php?action=create_payment', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Store session ID
                        if (paymongoReference) {
                            paymongoReference.value = data.session_id;
                        }
                        
                        // Update UI to show success
                        if (paymongoStatus) {
                            paymongoStatus.innerHTML = '<span style="color: green;"><i class="fas fa-check-circle"></i> Payment link created successfully!</span>';
                        }
                        
                        if (paymongoBtn) {
                            paymongoBtn.innerHTML = '<i class="fas fa-external-link-alt"></i> Complete Payment';
                            paymongoBtn.disabled = false;
                            
                            // Open checkout URL in new tab
                            window.open(data.checkout_url, '_blank');
                            
                            // Also update button to allow reopening the payment window
                            paymongoBtn.onclick = function() {
                                window.open(data.checkout_url, '_blank');
                            };
                        }
                        
                        // Show success message
                        Swal.fire({
                            title: 'Payment Link Created',
                            text: 'A new tab has opened with your payment page. After completing payment, return to this page to submit your request.',
                            icon: 'info'
                        });
                    } else {
                        // Handle error
                        if (paymongoStatus) {
                            paymongoStatus.innerHTML = `<span style="color: red;"><i class="fas fa-exclamation-circle"></i> Error: ${data.message}</span>`;
                        }
                        
                        if (paymongoBtn) {
                            paymongoBtn.innerHTML = '<i class="fas fa-credit-card"></i> Try Again';
                            paymongoBtn.disabled = false;
                        }
                        
                        Swal.fire({
                            title: 'Payment Error',
                            text: data.message || 'Failed to create payment link. Please try again.',
                            icon: 'error'
                        });
                    }
                })
                .catch(error => {
                    console.error('Payment error:', error);
                    
                    if (paymongoStatus) {
                        paymongoStatus.innerHTML = '<span style="color: red;"><i class="fas fa-exclamation-circle"></i> Connection error. Please try again.</span>';
                    }
                    
                    if (paymongoBtn) {
                        paymongoBtn.innerHTML = '<i class="fas fa-credit-card"></i> Try Again';
                        paymongoBtn.disabled = false;
                    }
                    
                    Swal.fire({
                        title: 'Connection Error',
                        text: 'Could not connect to payment service. Please check your internet connection and try again.',
                        icon: 'error'
                    });
                });
            }
            
            // Check for payment success/failure messages on page load (for redirect handling)
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('payment_success')) {
                Swal.fire({
                    title: 'Payment Successful',
                    text: 'Your payment has been processed successfully. You can now submit your document request.',
                    icon: 'success'
                });
            } else if (urlParams.has('payment_canceled')) {
                Swal.fire({
                    title: 'Payment Canceled',
                    text: 'Your payment was not completed. You can try again or choose a different payment method.',
                    icon: 'warning'
                });
            }
            
            // Barangay selection updates payment methods
            document.getElementById('barangaySelect').addEventListener('change', function() {
                const barangayId = this.value;
                if (barangayId) {
                    // In a real implementation, fetch the payment methods for the selected barangay via AJAX
                    // For now, we'll just refresh the page with the new barangay selected
                    window.location.href = `?barangay_id=${barangayId}`;
                }
            });
        });
    </script>
</body>
</html>