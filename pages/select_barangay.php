<?php
// Only start session if one hasn't been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/dbconn.php";
require_once "../functions/login.php";

// Debug logging
error_log("Session contents: " . print_r($_SESSION, true));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("No user_id in session, redirecting to login");
    header("Location: login.php");
    exit;
}

// Check if accessible barangays are set
if (!isset($_SESSION['accessible_barangays'])) {
    error_log("No accessible_barangays in session, getting them now");
    $_SESSION['accessible_barangays'] = getUserBarangays($pdo, $_SESSION['user_id']);
    error_log("Retrieved barangays: " . print_r($_SESSION['accessible_barangays'], true));
}

// Handle barangay selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barangay_id'])) {
    $selected_barangay_id = intval($_POST['barangay_id']);

    // Validate that the selected barangay is in the user's accessible list
    $is_valid = false;
    foreach ($_SESSION['accessible_barangays'] as $barangay) {
        if ($barangay['id'] == $selected_barangay_id) {
            $is_valid = true;
            $selected_status = $barangay['status'];
            break;
        }
    }

    if (!$is_valid) {
        $_SESSION['error'] = "Invalid barangay selection";
        header("Location: select_barangay.php");
        exit;
    }

    // Check if the selected barangay is archived
    if ($selected_status === 'archived') {
        $_SESSION['error'] = "Cannot select an archived barangay";
        header("Location: select_barangay.php");
        exit;
    }

    // Update the user's barangay_id
    if (!updateUserBarangay($pdo, $_SESSION['user_id'], $selected_barangay_id)) {
        $_SESSION['error'] = "Failed to update barangay selection";
        header("Location: select_barangay.php");
        exit;
    }

    // Set session variables
    $_SESSION['barangay_id'] = $selected_barangay_id;
    $_SESSION['barangay_name'] = $barangay['name'];

    // Update last login
    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $updateStmt->execute([$_SESSION['user_id']]);

    // Log the action
    logAuditTrail(
        $pdo,
        $_SESSION['user_id'],
        "LOGIN",
        "users",
        $_SESSION['user_id'],
        "Selected barangay: " . $barangay['name']
    );

    // Redirect to appropriate dashboard
    header("Location: " . getDashboardUrl($_SESSION['role_id']));
    exit;
}

// Check if user has any active records
$hasActiveRecords = hasActiveRecords($pdo, $_SESSION['user_id']);

// Check if all barangays are archived
$allArchived = true;
foreach ($_SESSION['accessible_barangays'] as $barangay) {
    if ($barangay['status'] !== 'archived') {
        $allArchived = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Barangay - iBarangay</title>
    <link rel="stylesheet" href="../styles/login.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
      .barangay-card {
        background: var(--light-gray);
        border-radius: var(--border-radius);
        width: 100%;
        max-width: 100%;
        min-height: 54px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-dark);
        border: 2px solid #e2e8f0;
        transition: all var(--transition-speed) ease;
        cursor: pointer;
        position: relative;
        box-shadow: 0 2px 8px rgba(59,130,246,0.06);
        margin-bottom: 0;
        padding: 1.1rem 0.5rem 1.1rem 0.5rem;
      }
      .barangay-card:hover:not(.archived) {
        border: 2px solid var(--primary-blue);
        background: #eaf2ff;
        box-shadow: 0 4px 16px rgba(59,130,246,0.13);
      }
      .barangay-card.archived {
        opacity: 0.7;
        cursor: not-allowed;
      }
      .archived-badge {
        position: absolute;
        top: 8px;
        right: 16px;
        background: #ffe066;
        color: #222;
        font-size: 0.93rem;
        font-weight: 600;
        border-radius: 999px;
        padding: 4px 16px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        letter-spacing: 0.5px;
        border: 1.5px solid #ffd600;
        z-index: 2;
        opacity: 1;
      }
      .barangay-list-scroll {
        max-height: 320px;
        overflow-y: auto;
        width: 100%;
        margin-bottom: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.1rem;
        align-items: center;
      }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="header">
            <img src="../photo/logo.png" alt="Government Logo">
            <h1>iBarangay</h1>
        </div>
        <div class="select-content">
            <div style="font-size:1.25rem;font-weight:600;text-align:center;margin-bottom:0.25rem;color:var(--text-dark);">Select Barangay</div>
            <div style="color:#6c757d;text-align:center;margin-bottom:1.5rem;font-size:1rem;">Choose a barangay to continue</div>
            <?php if ($allArchived): ?>
                <div class="notice-banner" style="margin-bottom:1.5rem;">
                    <i class="fas fa-exclamation-triangle me-2" style="margin-right:8px;"></i>
                    <strong>Notice:</strong> All your records are currently archived. You can still access the system but with limited functionality.
                </div>
            <?php endif; ?>
            <?php if (empty($_SESSION['accessible_barangays'])): ?>
                <div class="notice-banner" style="background:#ffeaea; color:#a94442; border:1px solid #f5c6cb;">
                    <i class="fas fa-exclamation-circle me-2" style="margin-right:8px;"></i>
                    No barangays found for your account. Please contact the barangay office for assistance.
                </div>
            <?php else: ?>
                <form method="POST" id="barangayForm" style="width:100%;">
                    <div class="barangay-list-scroll">
                        <?php foreach ($_SESSION['accessible_barangays'] as $barangay): ?>
                        <div class="input-group" style="padding:0;width:100%;margin-bottom:0;">
                            <div class="barangay-card<?php echo ($barangay['status'] === 'archived') ? ' archived' : ''; ?>" data-barangay-id="<?php echo $barangay['id']; ?>">
                                <?php if ($barangay['status'] === 'archived'): ?>
                                <span class="archived-badge">Archived</span>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($barangay['name']); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="barangay_id" id="selectedBarangayId">
                </form>
            <?php endif; ?>
            <a href="login.php" class="alt-link" style="display:block;text-align:center;margin:1.5rem 0 0.5rem 0;">Back to Login</a>
        </div>
        <!-- Footer -->
        <div class="footer">
            <div class="footer-info">
                <p>&copy; 2025 iBarangay. All Rights Reserved.</p>
            </div>
            <div class="security-note">
                <svg viewBox="0 0 24 24">
                    <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" />
                </svg>
                <span>Secure Government Portal</span>
            </div>
        </div>
    </div>
    <script>
        document.querySelectorAll('.barangay-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.barangay-card').forEach(c => c.style.border = '2px solid #e2e8f0');
                this.style.border = '2px solid var(--primary-blue)';
                document.getElementById('selectedBarangayId').value = this.dataset.barangayId;
                document.getElementById('barangayForm').submit();
            });
        });
    </script>
</body>

</html>