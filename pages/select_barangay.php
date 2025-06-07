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
            $selected_barangay_name = $barangay['name'];
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

    // Use the smart function to link the user's account to the correct person record.
    if (!updateUserBarangay($pdo, $_SESSION['user_id'], $selected_barangay_id)) {
        $_SESSION['error'] = "Failed to link your profile in the selected barangay. A matching record could not be found.";
        header("Location: select_barangay.php");
        exit;
    }

    // Set session variables for the selected barangay
    $_SESSION['barangay_id'] = $selected_barangay_id;
    $_SESSION['barangay_name'] = $selected_barangay_name;

    // Now that the link is correct, re-fetch person data to get full details.
    $personStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, religion, education_level FROM persons WHERE user_id = ?");
    $personStmt->execute([$_SESSION['user_id']]);
    $personData = $personStmt->fetch(PDO::FETCH_ASSOC);

    if ($personData) {
        $_SESSION['first_name'] = $personData['first_name'] ?? '';
        $_SESSION['middle_name'] = $personData['middle_name'] ?? '';
        $_SESSION['last_name'] = $personData['last_name'] ?? '';
        $_SESSION['religion'] = $personData['religion'] ?? '';
        $_SESSION['education_level'] = $personData['education_level'] ?? '';
    }

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
        "Selected barangay: " . $selected_barangay_name
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
        .barangay-selection-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin: 1rem 0;
        }

        .selection-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .selection-header h3 {
            color: #0a2240;
            margin: 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .barangay-count {
            background: #0a2240;
            color: white;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .barangay-list-scroll {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 0.5rem;
        }

        .barangay-card {
            background: #fafafa;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .barangay-card:hover:not(.archived) {
            border-color: #0a2240;
            box-shadow: 0 8px 25px rgba(10, 34, 64, 0.15);
            transform: translateY(-2px);
        }

        .barangay-card.selected {
            border-color: #0a2240;
            background: linear-gradient(135deg, #0a2240 0%, #1a3350 100%);
            color: white;
        }

        .barangay-card.archived {
            background: #f5f5f5;
            border-color: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .barangay-card-content {
            display: flex;
            align-items: center;
            padding: 1.25rem;
            gap: 1rem;
        }

        .barangay-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #0a2240 0%, #1a3350 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .barangay-card.selected .barangay-icon {
            background: rgba(255,255,255,0.2);
        }

        .barangay-card.archived .barangay-icon {
            background: #999;
        }

        .barangay-info {
            flex: 1;
        }

        .barangay-info h4 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: inherit;
        }

        .barangay-status {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .barangay-status.active {
            background: #d4edda;
            color: #155724;
        }

        .barangay-status.archived {
            background: #f8d7da;
            color: #721c24;
        }

        .barangay-card.selected .barangay-status {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .barangay-action {
            font-size: 1.2rem;
            color: #0a2240;
            flex-shrink: 0;
        }

        .barangay-card.selected .barangay-action {
            color: white;
        }

        .barangay-card.archived .barangay-action {
            color: #999;
        }

        .archived-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            font-size: 0.9rem;
        }

        .hover-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(10, 34, 64, 0.9);
            color: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            gap: 0.5rem;
        }

        .barangay-card:hover:not(.archived):not(.selected) .hover-overlay {
            opacity: 1;
        }

        .hover-overlay i {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ccc;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: #0a2240;
            margin-bottom: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #0a2240;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .back-link:hover {
            background: #f8f9fa;
            transform: translateX(-2px);
        }

        .form-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e0e0e0;
            text-align: center;
        }

        @media (max-width: 768px) {
            .barangay-card-content {
                padding: 1rem;
                gap: 0.75rem;
            }

            .barangay-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .selection-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .barangay-count {
                align-self: center;
            }
        }
    </style>
</head>

<body>
    <div class="login-wrapper">
        <div class="branding-side">
            <div class="branding-content">
                <img src="../photo/logo.png" alt="iBarangay Logo">
                <h1>iBarangay</h1>
                <p>Select your barangay to access your personalized dashboard and services tailored to your community.</p>
            </div>
        </div>

        <div class="form-side">
            <div class="login-container">
                <div class="header">
                    <h1>Select Barangay</h1>
                    <p>Choose your barangay to continue to your dashboard</p>
                </div>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($allArchived): ?>
                    <div class="warning-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Notice:</strong> All your barangay records are archived. You can still access the system with limited functionality.
                    </div>
                <?php endif; ?>

                <?php if (empty($_SESSION['accessible_barangays'])): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i>
                        No barangays found for your account. Please contact the barangay office for assistance.
                    </div>
                    <div class="empty-state">
                        <i class="fas fa-map-marker-alt"></i>
                        <h3>No Barangays Available</h3>
                        <p>Contact your administrator to get access to barangay services.</p>
                        <a href="logout.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Login
                        </a>
                    </div>
                <?php else: ?>
                    <form method="POST" id="barangayForm">
                        <div class="barangay-selection-container">
                            <div class="selection-header">
                                <h3><i class="fas fa-map-marker-alt"></i> Available Barangays</h3>
                                <span class="barangay-count"><?php echo count($_SESSION['accessible_barangays']); ?> barangay<?php echo count($_SESSION['accessible_barangays']) > 1 ? 's' : ''; ?></span>
                            </div>
                            
                            <div class="barangay-list-scroll">
                                <?php foreach ($_SESSION['accessible_barangays'] as $index => $barangay): ?>
                                <div class="barangay-card<?php echo ($barangay['status'] === 'archived') ? ' archived' : ''; ?>" 
                                     data-barangay-id="<?php echo $barangay['id']; ?>"
                                     data-barangay-name="<?php echo htmlspecialchars($barangay['name']); ?>"
                                     tabindex="<?php echo ($barangay['status'] === 'archived') ? '-1' : '0'; ?>"
                                     role="button"
                                     aria-label="<?php echo ($barangay['status'] === 'archived') ? 'Archived barangay: ' : 'Select '; ?><?php echo htmlspecialchars($barangay['name']); ?> barangay">
                                    
                                    <div class="barangay-card-content">
                                        <div class="barangay-icon">
                                            <i class="fas fa-building"></i>
                                        </div>
                                        <div class="barangay-info">
                                            <h4><?php echo htmlspecialchars($barangay['name']); ?></h4>
                                            <span class="barangay-status <?php echo $barangay['status']; ?>">
                                                <i class="fas fa-<?php echo ($barangay['status'] === 'archived') ? 'archive' : 'check-circle'; ?>"></i>
                                                <?php echo ucfirst($barangay['status']); ?>
                                            </span>
                                        </div>
                                        <div class="barangay-action">
                                            <?php if ($barangay['status'] === 'archived'): ?>
                                                <span class="archived-badge">
                                                    <i class="fas fa-lock"></i>
                                                </span>
                                            <?php else: ?>
                                                <i class="fas fa-arrow-right"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if ($barangay['status'] !== 'archived'): ?>
                                        <div class="hover-overlay">
                                            <i class="fas fa-mouse-pointer"></i>
                                            <span>Click to select</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <input type="hidden" name="barangay_id" id="selectedBarangayId">
                    </form>
                <?php endif; ?>

                <div class="form-footer">
                    <a href="logout.php" class="back-link">
                        <i class="fas fa-arrow-left"></i> Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const barangayCards = document.querySelectorAll('.barangay-card:not(.archived)');
            const form = document.getElementById('barangayForm');
            const selectedInput = document.getElementById('selectedBarangayId');

            barangayCards.forEach(card => {
                card.addEventListener('click', function() {
                    if (this.classList.contains('loading')) return;
                    
                    // Remove previous selection
                    barangayCards.forEach(c => {
                        c.classList.remove('selected');
                        c.setAttribute('aria-pressed', 'false');
                    });
                    
                    // Add selection to current card
                    this.classList.add('selected');
                    this.setAttribute('aria-pressed', 'true');
                    
                    // Set form data
                    selectedInput.value = this.dataset.barangayId;
                    
                    // Add loading state
                    this.classList.add('loading');
                    
                    // Submit form after brief delay for visual feedback
                    setTimeout(() => {
                        form.submit();
                    }, 500);
                });

                // Add keyboard support
                card.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.click();
                    }
                });

                // Set initial ARIA attributes
                card.setAttribute('aria-pressed', 'false');
            });

            // Add loading animation
            const style = document.createElement('style');
            style.textContent = `
                .barangay-card.loading .barangay-icon {
                    animation: pulse 1s infinite;
                }
                @keyframes pulse {
                    0%, 100% { opacity: 1; }
                    50% { opacity: 0.5; }
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>