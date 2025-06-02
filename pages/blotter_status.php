<?php
session_start();
require "../config/dbconn.php";

// Add this line to ensure $conn is set from $pdo
$conn = $pdo;
global $conn;

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 4;
$user_info = null;
$barangay_name = "Barangay";
$barangay_id = 32;

if ($user_id) {
    $sql = "SELECT p.first_name, p.last_name, u.barangay_id, b.name as barangay_name 
            FROM users u
            LEFT JOIN persons p ON p.user_id = u.id
            LEFT JOIN barangay b ON u.barangay_id = b.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $user_info = $row;
        $barangay_name = $row['barangay_name'];
        $barangay_id = $row['barangay_id'];
    }
    $stmt = null;
}

// Handle scheduling actions from URL parameters
$action_message = '';
$action_type = '';
if (isset($_GET['action']) && isset($_GET['case_id'])) {
    $action = $_GET['action'];
    $case_id = intval($_GET['case_id']);
    
    if ($action === 'confirm' || $action === 'reject') {
        $action_message = "Please " . ($action === 'confirm' ? 'confirm your availability' : 'provide alternative dates') . " for the proposed hearing schedule.";
        $action_type = $action;
    }
}

// NOW INCLUDE NAVBAR AFTER VARIABLES ARE SET
require "../components/navbar.php";

// Check if the new columns exist before using them
$columnCheck = $pdo->query("SHOW COLUMNS FROM blotter_cases LIKE 'hearing_attempts'");
$hasNewColumns = $columnCheck->rowCount() > 0;

// Modified SQL query to fix GROUP BY issues and get user cases properly
$sql = "
    SELECT bc.*, 
           GROUP_CONCAT(DISTINCT cc.name SEPARATOR ', ') AS categories,
           bp.role,
           u.email as user_email,
           CONCAT(p.first_name, ' ', p.last_name) as proposed_by_name";

// Add conditional columns if they exist
if ($hasNewColumns) {
    $sql .= ",
           bc.hearing_attempts,
           bc.max_hearing_attempts,
           bc.is_cfa_eligible,
           bc.cfa_reason";
} else {
    $sql .= ",
           0 as hearing_attempts,
           3 as max_hearing_attempts,
           FALSE as is_cfa_eligible,
           NULL as cfa_reason";
}

$sql .= "
    FROM blotter_cases bc
    JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
    JOIN persons p ON bp.person_id = p.id
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id = cc.id
    WHERE p.user_id = ?
      AND bc.status != 'deleted'
    GROUP BY bc.id, bp.role, u.email, p.first_name, p.last_name
    ORDER BY bc.created_at DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // table not found → proceed with empty list and show a notice
    if (strpos($e->getMessage(), '1146') !== false) {
        $cases = [];
        $_SESSION['error_message'] = 'Scheduling data unavailable. Please run the latest migrations.';
    } else {
        throw $e;
    }
}

// For each case, fetch hearing information and summons details
foreach ($cases as &$case) {
    // Fetch latest schedule proposal for this case
    $stmt2 = $pdo->prepare("
        SELECT sp.*, 
            COALESCE(CONCAT(p.first_name, ' ', p.last_name), 'System') as proposed_by_name,
            sp.proposed_by_role_id as confirmed_by_role
        FROM schedule_proposals sp
        LEFT JOIN users u ON sp.proposed_by_user_id = u.id
        LEFT JOIN persons p ON u.id = p.user_id
        WHERE sp.blotter_case_id = ?
        ORDER BY sp.created_at DESC
        LIMIT 1
    ");
    $stmt2->execute([$case['id']]);
    $proposal = $stmt2->fetch(PDO::FETCH_ASSOC);

    if ($proposal) {
        $case['current_proposal_id'] = $proposal['id'];
        $case['proposed_date'] = $proposal['proposed_date'];
        $case['proposed_time'] = $proposal['proposed_time'];
        $case['hearing_location'] = $proposal['hearing_location'];
        $case['presiding_officer'] = $proposal['presiding_officer'];
        $case['proposal_status'] = $proposal['status'];
        $case['user_confirmed'] = $proposal['user_confirmed'];
        $case['captain_confirmed'] = $proposal['captain_confirmed'];
        $case['user_remarks'] = $proposal['user_remarks'];
        $case['captain_remarks'] = $proposal['captain_remarks'];
        $case['conflict_reason'] = $proposal['conflict_reason'];
        $case['confirmed_by_role'] = $proposal['confirmed_by_role'] ?? null;
    }
    
    // Check if user has email for summons delivery method
    $case['has_email'] = !empty($case['user_email']);
    
    // Fetch participant notifications/summons for users without email
    if (!$case['has_email'] && isset($case['current_proposal_id'])) {
        $stmt3 = $pdo->prepare("
            SELECT pn.*, bp.role 
            FROM participant_notifications pn
            JOIN blotter_participants bp ON pn.participant_id = bp.id
            WHERE pn.blotter_case_id = ? AND bp.person_id = (
                SELECT person_id FROM blotter_participants 
                WHERE blotter_case_id = ? AND person_id IN (
                    SELECT id FROM persons WHERE user_id = ?
                )
            )
            ORDER BY pn.created_at DESC
            LIMIT 1
        ");
        $stmt3->execute([$case['id'], $case['id'], $user_id]);
        $case['summons_info'] = $stmt3->fetch(PDO::FETCH_ASSOC);
    }
}
unset($case);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cases & Schedules - iBarangay</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.1/sweetalert2.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --primary-color: #0056b3;
            --primary-dark: #003366;
            --secondary-color: #3498db;
            --success-color: #059669;
            --warning-color: #d97706;
            --error-color: #dc2626;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
            --bg-light: #f8f9fa;
            --white: #ffffff;
            --sidebar-bg: #2c3e50;
            --border-light: #e0e0e0;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.1);
            --shadow-md: 0 5px 15px rgba(0,0,0,0.08);
            --shadow-lg: 0 15px 30px rgba(0,0,0,0.1);
        }

        body {
            background: var(--bg-light);
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        html::-webkit-scrollbar, body::-webkit-scrollbar {
            display: none;
        }

        .page-wrapper {
            flex: 1;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        .container {
            max-width: 1440px;
            margin: 0 auto;
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            padding: 2rem;
            min-height: auto;
        }

        h2 {
            color: var(--primary-color);
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        h2 i {
            color: var(--secondary-color);
        }

        .back-button {
            background: var(--secondary-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .back-button:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Enhanced Case Cards */
        .case-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            position: relative;
        }

        .case-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .case-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .case-title {
            color: var(--text-dark);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .case-number {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .case-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .meta-item i {
            color: var(--secondary-color);
            width: 16px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            gap: 0.25rem;
        }

        .status-pending { background: #fffbeb; color: #d97706; }
        .status-open { background: #ecfdf5; color: #059669; }
        .status-solved { background: #f0fdf4; color: #15803d; }
        .status-cfa-eligible { background: #fef2f2; color: #b91c1c; }
        .status-endorsed-to-court { background: #f5f3ff; color: #6d28d9; }
        .status-closed { background: #f8fafc; color: #475569; }

        .scheduling-status {
            background: var(--bg-light);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 4px solid var(--secondary-color);
        }

        .scheduling-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .scheduling-title {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .schedule-info {
            background: var(--white);
            border-radius: 6px;
            padding: 1rem;
            margin-top: 0.75rem;
            border: 1px solid var(--border-light);
        }

        .schedule-datetime {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.75rem;
            flex-wrap: wrap;
        }

        .datetime-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .datetime-item i {
            color: var(--primary-color);
        }

        .schedule-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background: var(--error-color);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .schedule-history {
            margin-top: 1rem;
            border-top: 1px solid var(--border-light);
            padding-top: 1rem;
        }

        .history-item {
            padding: 0.75rem;
            border-left: 3px solid var(--border-light);
            margin-bottom: 0.75rem;
            background: #fafafa;
            border-radius: 0 6px 6px 0;
        }

        .history-item.proposed {
            border-left-color: var(--warning-color);
        }

        .history-item.confirmed {
            border-left-color: var(--success-color);
        }

        .history-item.conflict {
            border-left-color: var(--error-color);
        }

        .history-meta {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-bottom: 0.25rem;
        }

        .conflict-alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 1rem;
            margin-top: 1rem;
            color: var(--error-color);
        }

        .conflict-alert i {
            margin-right: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 2.5rem;
            color: var(--border-light);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .footer {
            background: var(--sidebar-bg);
            color: white;
            text-align: center;
            padding: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 5vw, 5%);
            border-top: 2px solid #e0e0e0; 
        }

        .footer p {
            font-size: clamp(0.85rem, 1.5vw, 1rem);
        }

        /* Action Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-light);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* New Styles for Proposed Schedule Section */
        #proposedSchedule {
            background: var(--white);
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
            border: 1px solid var(--border-light);
        }

        #proposedSchedule h3 {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }

        #scheduleDetails {
            font-size: 0.9rem;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }

        #confirmScheduleBtn, #rejectScheduleBtn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        #confirmScheduleBtn {
            background: var(--success-color);
            color: white;
        }

        #confirmScheduleBtn:hover {
            background: #047857;
        }

        #rejectScheduleBtn {
            background: var(--error-color);
            color: white;
        }

        #rejectScheduleBtn:hover {
            background: #c62828;
        }

        /* Custom SweetAlert2 Styling */
        .swal2-popup {
            font-family: 'Poppins', sans-serif !important;
            border-radius: 12px !important;
        }

        .swal2-title {
            color: var(--text-dark) !important;
            font-weight: 600 !important;
        }

        .swal2-confirm {
            background-color: var(--success-color) !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 0.75rem 1.5rem !important;
            font-weight: 500 !important;
        }

        .swal2-cancel {
            background-color: var(--error-color) !important;
            border: none !important;
            border-radius: 6px !important;
            padding: 0.75rem 1.5rem !important;
            font-weight: 500 !important;
        }

        .swal2-textarea {
            border: 1px solid var(--border-light) !important;
            border-radius: 6px !important;
            font-family: 'Poppins', sans-serif !important;
        }

        .swal2-textarea:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1) !important;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .page-wrapper {
                padding: 1rem;
            }

            .container {
                padding: 1.5rem;
                border-radius: 8px;
            }

            .case-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .case-meta {
                grid-template-columns: 1fr;
            }

            .schedule-datetime {
                flex-direction: column;
                align-items: flex-start;
            }

            .schedule-actions {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }

            #proposedSchedule {
                padding: 1rem;
            }

            #proposedSchedule h3 {
                font-size: 1.1rem;
            }

            #scheduleDetails {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="container">
            <?php if ($action_message): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <?= htmlspecialchars($action_message) ?>
                </div>
            <?php endif; ?>

            <div class="case-header">
                <h2><i class="fas fa-gavel"></i> My Cases & Schedules</h2>
                <a href="../pages/user_dashboard.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if (empty($cases)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Cases Found</h3>
                    <p>You don't have any blotter cases at this time.</p>
                </div>
            <?php else: ?>
                <?php foreach ($cases as $case): ?>
                    <div class="case-card">
                        <div class="case-header">
                            <div class="case-title">
                                <h3>Case #<?= htmlspecialchars($case['case_number']) ?></h3>
                                <span class="case-number"><?= ucfirst($case['role']) ?></span>
                            </div>
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $case['status'])) ?>">
                                <?= ucfirst(str_replace('_', ' ', $case['status'])) ?>
                            </span>
                        </div>

                        <div class="case-meta">
                            <div class="meta-item">
                                <i class="fas fa-calendar-alt"></i>
                                Filed: <?= date('M d, Y', strtotime($case['created_at'])) ?>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                Location: <?= htmlspecialchars($case['location']) ?>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-tags"></i>
                                Categories: <?= htmlspecialchars($case['categories'] ?: 'None specified') ?>
                            </div>
                        </div>

                        <div class="case-description">
                            <p><?= nl2br(htmlspecialchars($case['description'])) ?></p>
                        </div>

                        <?php if (isset($case['current_proposal_id'])): ?>
                            <div class="scheduling-status">
                                <div class="scheduling-header">
                                    <h4 class="scheduling-title">
                                        <i class="fas fa-clock"></i>
                                        Hearing Schedule
                                    </h4>
                                    <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $case['proposal_status'])) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $case['proposal_status'])) ?>
                                    </span>
                                </div>

                                <div class="schedule-info">
                                    <div class="schedule-datetime">
                                        <div class="datetime-item">
                                            <i class="fas fa-calendar"></i>
                                            <span><?= date('F d, Y', strtotime($case['proposed_date'])) ?></span>
                                        </div>
                                        <div class="datetime-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?= date('g:i A', strtotime($case['proposed_time'])) ?></span>
                                        </div>
                                        <div class="datetime-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?= htmlspecialchars($case['hearing_location'] ?? 'Barangay Hall') ?></span>
                                        </div>
                                        <div class="datetime-item">
                                            <i class="fas fa-user-tie"></i>
                                            <span><?= htmlspecialchars($case['presiding_officer'] ?? 'Barangay Captain') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <?php 
                                $needsResponse = false;
                                $showApprovalButtons = false;
                                $confirmButtonText = '';
                                $rejectButtonText = '';
                                
                                // Determine if user needs to respond and what buttons to show
                                if ($case['proposal_status'] === 'proposed' || $case['proposal_status'] === 'pending') {
                                    if (!$case['user_confirmed'] && !$case['captain_confirmed']) {
                                        // Neither confirmed yet
                                        $needsResponse = true;
                                        $showApprovalButtons = true;
                                        $confirmButtonText = 'Confirm Availability';
                                        $rejectButtonText = 'Request Different Time';
                                    }
                                } elseif ($case['proposal_status'] === 'conflict') {
                                    // Show conflict reason
                                    if (!empty($case['conflict_reason'])) {
                                        echo '<div class="conflict-alert">';
                                        echo '<i class="fas fa-exclamation-triangle"></i>';
                                        echo '<strong>Schedule Conflict:</strong> ' . htmlspecialchars($case['conflict_reason']);
                                        echo '</div>';
                                    }
                                } elseif ($case['proposal_status'] === 'confirmed' || $case['proposal_status'] === 'both_confirmed') {
                                    // Show confirmation details
                                    echo '<div class="schedule-confirmed">';
                                    echo '<i class="fas fa-check-circle"></i>';
                                    echo '<span>Schedule confirmed. Please attend on the scheduled date and time.</span>';
                                    echo '</div>';
                                }
                                
                                if ($showApprovalButtons): ?>
                                    <div class="schedule-actions">
                                        <button onclick="respondToProposal(<?= $case['current_proposal_id'] ?>, 'confirm')" 
                                                class="btn btn-success">
                                            <i class="fas fa-check"></i> <?= $confirmButtonText ?>
                                        </button>
                                        <button onclick="respondToProposal(<?= $case['current_proposal_id'] ?>, 'reject')" 
                                                class="btn btn-warning">
                                            <i class="fas fa-times"></i> <?= $rejectButtonText ?>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <?php 
                                // Add debugging info only for development - remove in production
                                if (isset($case['confirmed_by_role']) && $case['confirmed_by_role']): ?>
                                    <div class="schedule-history">
                                        <div class="history-item confirmed">
                                            <strong>Confirmed by:</strong> 
                                            <?php
                                            $roleNames = [
                                                3 => 'Barangay Captain',
                                                7 => 'Chief Officer',
                                                8 => 'Resident'
                                            ];
                                            echo $roleNames[$case['confirmed_by_role']] ?? 'Unknown Role';
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="scheduling-status">
                                <div class="scheduling-header">
                                    <h4 class="scheduling-title">
                                        <i class="fas fa-clock"></i>
                                        Hearing Schedule
                                    </h4>
                                    <span class="status-badge status-pending">
                                        Pending Schedule
                                    </span>
                                </div>
                                <p class="text-muted">No hearing schedule has been proposed yet. Please wait for the barangay officials to schedule your hearing.</p>
                            </div>
                        <?php endif; ?>

                        <?php if (!$case['has_email'] && isset($case['summons_info'])): ?>
                            <div class="summons-info">
                                <h4><i class="fas fa-envelope"></i> Summons Delivery</h4>
                                <p>Since you don't have an email address on file, summons will be delivered physically.</p>
                                <?php if ($case['summons_info']): ?>
                                    <div class="summons-status">
                                        <strong>Status:</strong> <?= ucfirst($case['summons_info']['delivery_status']) ?><br>
                                        <strong>Method:</strong> <?= ucfirst($case['summons_info']['delivery_method']) ?><br>
                                        <?php if ($case['summons_info']['delivered_at']): ?>
                                            <strong>Delivered:</strong> <?= date('M d, Y g:i A', strtotime($case['summons_info']['delivered_at'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Response Modal -->
    <div class="modal" id="responseModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Respond to Schedule Proposal</h3>
            </div>
            <form id="responseForm">
                <input type="hidden" id="proposalId" name="proposal_id">
                <input type="hidden" id="responseType" name="response">

                <div class="form-group">
                    <label class="form-label">Your Response</label>
                    <div id="responseMessage" style="padding: 1rem; background: var(--bg-light); border-radius: 6px; margin-bottom: 1rem;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="remarks">Additional Remarks</label>
                    <textarea class="form-control" id="remarks" name="remarks" 
                              placeholder="Any additional information or concerns..."></textarea>
                </div>

                <div class="form-group" id="alternativeDatesGroup" style="display: none;">
                    <label class="form-label" for="alternativeDates">Suggest Alternative Dates/Times</label>
                    <textarea class="form-control" id="alternativeDates" name="alternative_dates" 
                              placeholder="Please suggest dates and times when you're available..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Submit Response</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>

    <!-- SweetAlert2 JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.1/sweetalert2.all.min.js"></script>

    <script>
        // Handle schedule proposal responses
        function respondToProposal(proposalId, responseType) {
            document.getElementById('proposalId').value = proposalId;
            document.getElementById('responseType').value = responseType;
            
            const modal = document.getElementById('responseModal');
            const responseMessage = document.getElementById('responseMessage');
            const alternativeDatesGroup = document.getElementById('alternativeDatesGroup');
            const modalTitle = document.getElementById('modalTitle');
            const submitBtn = document.getElementById('submitBtn');
            
            if (responseType === 'confirm') {
                modalTitle.textContent = 'Confirm Your Availability';
                responseMessage.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success-color);"></i> You are confirming that you can attend the proposed hearing schedule.';
                responseMessage.style.background = '#ecfdf5';
                responseMessage.style.color = '#059669';
                alternativeDatesGroup.style.display = 'none';
                submitBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Availability';
                submitBtn.className = 'btn btn-success';
            } else {
                modalTitle.textContent = 'Report Scheduling Conflict';
                responseMessage.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: var(--warning-color);"></i> You cannot attend the proposed hearing schedule. Please provide alternative dates.';
                responseMessage.style.background = '#fffbeb';
                responseMessage.style.color = '#d97706';
                alternativeDatesGroup.style.display = 'block';
                submitBtn.innerHTML = '<i class="fas fa-calendar-alt"></i> Submit Conflict';
                submitBtn.className = 'btn btn-warning';
            }
            
            modal.classList.add('show');
        }

        function closeModal() {
            document.getElementById('responseModal').classList.remove('show');
            document.getElementById('responseForm').reset();
        }

        // Handle form submission
        document.getElementById('responseForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            try {
                // Show loading state
                Swal.fire({
                    title: 'Processing...',
                    text: 'Submitting your response...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                const response = await fetch('../api/scheduling_api.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'respond_to_proposal',
                        proposal_id: formData.get('proposal_id'),
                        response: formData.get('response'),
                        remarks: formData.get('remarks'),
                        alternative_dates: formData.get('alternative_dates')
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Success SweetAlert
                    Swal.fire({
                        title: 'Success!',
                        text: 'Your response has been submitted successfully.',
                        icon: 'success',
                        confirmButtonColor: '#059669',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        closeModal();
                        location.reload();
                    });
                } else {
                    throw new Error(data.message || 'Failed to submit response');
                }
            } catch (error) {
                // Error SweetAlert
                Swal.fire({
                    title: 'Error!',
                    text: error.message || 'Failed to submit response. Please try again.',
                    icon: 'error',
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'OK'
                });
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });

        // Close modal when clicking outside
        document.getElementById('responseModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Handle URL action parameters for direct response
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get('action');
            const caseId = urlParams.get('case_id');
            
            if (action && caseId && (action === 'confirm' || action === 'reject')) {
                // Find the current proposal for this case
                const caseCard = document.querySelector(`[data-case-id="${caseId}"]`);
                if (caseCard) {
                    const confirmBtn = caseCard.querySelector('.confirm-availability-btn');
                    const rejectBtn = caseCard.querySelector('.cant-attend-btn');
                    
                    if (action === 'confirm' && confirmBtn) {
                        confirmBtn.click();
                    } else if (action === 'reject' && rejectBtn) {
                        rejectBtn.click();
                    }
                }
                
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            // Enhanced Confirm Availability and Can't Attend handlers using SweetAlert2
            document.querySelectorAll('.confirm-availability-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const proposalId = btn.dataset.proposalId;
                    const caseId = btn.dataset.caseId;
                    
                    Swal.fire({
                        title: 'Confirm Your Availability',
                        html: `
                            <div style="text-align: left; margin: 1rem 0;">
                                <p style="margin-bottom: 1rem;">Are you available for the scheduled hearing?</p>
                                <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 1rem; color: #047857;">
                                    <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                                    <strong>Note:</strong> By confirming, you agree to attend the hearing at the scheduled time and date.
                                </div>
                            </div>
                        `,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#059669',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: '<i class="fas fa-check"></i> Yes, I can attend',
                        cancelButtonText: '<i class="fas fa-times"></i> Cancel',
                        customClass: {
                            popup: 'swal2-popup',
                            title: 'swal2-title',
                            htmlContainer: 'swal2-html-container'
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Show loading
                            Swal.fire({
                                title: 'Processing...',
                                text: 'Confirming your availability...',
                                allowOutsideClick: false,
                                allowEscapeKey: false,
                                showConfirmButton: false,
                                didOpen: () => {
                                    Swal.showLoading();
                                }
                            });

                            // Submit form
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'handle_schedule.php';
                            
                            const actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'action';
                            actionInput.value = 'confirm';
                            
                            const proposalInput = document.createElement('input');
                            proposalInput.type = 'hidden';
                            proposalInput.name = 'proposal_id';
                            proposalInput.value = proposalId;
                            
                            form.appendChild(actionInput);
                            form.appendChild(proposalInput);
                            document.body.appendChild(form);
                            form.submit();
                        }
                    });
                });
            });

            // Can't Attend handlers
            document.querySelectorAll('.cant-attend-btn').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const proposalId = btn.dataset.proposalId;
                    const caseId = btn.dataset.caseId;
                    
                    Swal.fire({
                        title: "Can't Attend Hearing",
                        html: `
                            <div style="text-align: left; margin: 1rem 0;">
                                <p style="margin-bottom: 1rem;">Please provide a reason why you cannot attend and suggest alternative dates/times when you are available.</p>
                                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 1rem; color: #dc2626; margin-bottom: 1rem;">
                                    <i class="fas fa-exclamation-triangle" style="margin-right: 0.5rem;"></i>
                                    <strong>Important:</strong> A valid reason is required for rescheduling.
                                </div>
                            </div>
                        `,
                        input: 'textarea',
                        inputLabel: 'Reason and Alternative Dates/Times',
                        inputPlaceholder: 'Please explain why you cannot attend and suggest alternative dates and times when you are available...',
                        inputAttributes: {
                            'aria-label': 'Reason for not attending',
                            'style': 'min-height: 120px; font-family: Poppins, sans-serif;'
                        },
                        showCancelButton: true,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        confirmButtonText: '<i class="fas fa-paper-plane"></i> Submit Request',
                        cancelButtonText: '<i class="fas fa-times"></i> Cancel',
                        inputValidator: (value) => {
                            if (!value || value.trim().length < 10) {
                                return 'Please provide a detailed reason (at least 10 characters) and suggest alternative dates.';
                            }
                        },
                        customClass: {
                            popup: 'swal2-popup',
                            title: 'swal2-title',
                            input: 'swal2-textarea'
                        }
                    }).then((result) => {
                        if (result.isConfirmed && result.value) {
                            // Show loading
                            Swal.fire({
                                title: 'Processing...',
                                text: 'Submitting your request...',
                                allowOutsideClick: false,
                                allowEscapeKey: false,
                                showConfirmButton: false,
                                didOpen: () => {
                                    Swal.showLoading();
                                }
                            });

                            // Submit form
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'handle_schedule.php';
                            
                            const actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'action';
                            actionInput.value = 'reject';
                            
                            const proposalInput = document.createElement('input');
                            proposalInput.type = 'hidden';
                            proposalInput.name = 'proposal_id';
                            proposalInput.value = proposalId;
                            
                            const remarksInput = document.createElement('input');
                            remarksInput.type = 'hidden';
                            remarksInput.name = 'remarks';
                            remarksInput.value = result.value;
                            
                            form.appendChild(actionInput);
                            form.appendChild(proposalInput);
                            form.appendChild(remarksInput);
                            document.body.appendChild(form);
                            form.submit();
                        }
                    });
                });
            });
        });

        // Auto-refresh every 2 minutes to check for updates
        setInterval(function() {
            // Check for updates without reloading
            fetch('../api/scheduling_api.php?action=get_user_cases_with_schedules')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Simple check for changes
                        const currentCases = <?= json_encode($cases) ?>;
                        if (JSON.stringify(data.cases) !== JSON.stringify(currentCases)) {
                            // Show update notification using SweetAlert2
                            Swal.fire({
                                title: 'Updates Available',
                                text: 'There are new schedule updates. Would you like to refresh the page?',
                                icon: 'info',
                                showCancelButton: true,
                                confirmButtonColor: '#3498db',
                                cancelButtonColor: '#6b7280',
                                confirmButtonText: '<i class="fas fa-sync-alt"></i> Refresh Now',
                                cancelButtonText: 'Later',
                                toast: true,
                                position: 'top-end',
                                timer: 10000,
                                timerProgressBar: true,
                                showCloseButton: true
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    location.reload();
                                }
                            });
                        }
                    }
                })
                .catch(error => console.log('Update check failed:', error));
        }, 120000); // 2 minutes

        // Success/Error messages from server-side operations
        <?php if (isset($_GET['success'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const success = '<?= htmlspecialchars($_GET['success']) ?>';
            let title = 'Success!';
            let text = '';
            
            switch(success) {
                case 'confirmed':
                    text = 'You have successfully confirmed your availability for the hearing.';
                    break;
                case 'rejected':
                    text = 'Your scheduling conflict has been reported. The captain will propose alternative dates.';
                    break;
                default:
                    text = 'Operation completed successfully.';
            }
            
            Swal.fire({
                title: title,
                text: text,
                icon: 'success',
                confirmButtonColor: '#059669',
                confirmButtonText: 'OK'
            });
        });
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const error = '<?= htmlspecialchars($_GET['error']) ?>';
            let text = '';
            
            switch(error) {
                case 'invalid_proposal':
                    text = 'The schedule proposal could not be found or is no longer valid.';
                    break;
                case 'already_responded':
                    text = 'You have already responded to this schedule proposal.';
                    break;
                case 'database_error':
                    text = 'A database error occurred. Please try again later.';
                    break;
                default:
                    text = 'An error occurred while processing your request.';
            }
            
            Swal.fire({
                title: 'Error!',
                text: text,
                icon: 'error',
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'OK'
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>