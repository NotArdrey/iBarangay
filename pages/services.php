<?php
session_start();
require_once "../config/dbconn.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/index.php");
    exit;
}
$stmt = $pdo->query("
SELECT dt.document_type_id, dt.document_name, df.fee
FROM DocumentType dt
LEFT JOIN DocumentFee df
  ON df.document_type = LOWER(REPLACE(dt.document_name,' ',''))
");
$documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$documentFees = [
    'barangayClearance' => 50.00,
    'firstTimeJobSeeker' => 0.00,
    'proofOfResidency' => 30.00,
    'barangayIndigency' => 20.00,
    'goodMoralCertificate' => 30.00,
    'noIncomeCertification' => 0.00
];

$userId = $_SESSION['user_id'];
$userBarangayId = null;

try {
    $stmt = $pdo->prepare("SELECT barangay_id FROM Users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userBarangay = $stmt->fetch(PDO::FETCH_ASSOC);
    $userBarangayId = $userBarangay['barangay_id'];
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
}

$gcash_number = 'Not available';
if ($userBarangayId) {
    try {
        $stmt = $pdo->prepare("SELECT account_details FROM BarangayPaymentMethod 
            WHERE barangay_id = ? AND method = 'GCash' AND is_active = 'yes'");
        $stmt->execute([$userBarangayId]);
        $gcashData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($gcashData) $gcash_number = $gcashData['account_details'];
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
                      <?php foreach ($documentTypes as $doc): ?>
                        <option
                          value="<?= $doc['document_type_id'] ?>"
                          data-fee="<?= $doc['fee'] ?? 0 ?>"
                        >
                          <?= htmlspecialchars($doc['document_name']) ?>
                          (<?= $doc['fee'] > 0 ? '₱'.number_format($doc['fee'],2) : 'Free' ?>)
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

                    <div id="payment-section" style="display: none;">
                        <div class="payment-info">
                            <h3>Payment Details</h3>
                            <div class="fee-display">
                                <span>Document Fee:</span>
                                <strong id="feeAmount">₱0.00</strong>
                            </div>
                            <p>Send payment to: <strong><?= $gcash_number ?></strong></p>
                        </div>

                        <div class="form-row">
                            <label for="uploadProof">Upload Payment Receipt</label>
                            <input type="file" name="uploadProof" id="uploadProof" 
                                   accept="image/jpeg,image/png,application/pdf">
                            <small>Accepted formats: JPG, PNG, PDF (Max 2MB)</small>
                        </div>
                    </div>

                    <input type="hidden" id="paymentAmount" name="paymentAmount" value="0">

                    <button type="submit" class="btn cta-button">Submit Request</button>
                </form>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p>&copy; 2025 Barangay Hub. All rights reserved.</p>
    </footer>

    <script>
        document.getElementById('documentType').addEventListener('change', function() {
            const fee = this.options[this.selectedIndex].dataset.fee;
            document.getElementById('feeAmount').textContent = 
                fee > 0 ? `₱${parseFloat(fee).toFixed(2)}` : 'Free';
            document.getElementById('paymentAmount').value = fee;
            togglePaymentSection();
        });

        document.getElementById('deliveryMethod').addEventListener('change', togglePaymentSection);

        function togglePaymentSection() {
            const delivery = document.getElementById('deliveryMethod').value;
            const fee = parseFloat(document.getElementById('paymentAmount').value);
            document.getElementById('payment-section').style.display = 
                (delivery === 'Softcopy' && fee > 0) ? 'block' : 'none';
        }
    </script>
</body>
</html>