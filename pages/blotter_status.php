<?php
session_start();
require "../config/dbconn.php";

// Add this line to ensure $conn is set from $pdo
$conn = $pdo;
global $conn;

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$user_info = null;
$barangay_name = "Barangay";
$barangay_id = 32;

if ($user_id) {
    $sql = "SELECT u.first_name, u.last_name, u.barangay_id, b.name as barangay_name 
            FROM users u 
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

if (!$user_id) {
    header("Location: login.php");
    exit;
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

// Modified SQL query to remove references to "blotter_schedule_proposals"
$stmt = $pdo->prepare("
    SELECT bc.*, 
           GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories,
           bp.role
    FROM blotter_cases bc
    JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
    JOIN persons p ON bp.person_id = p.id
    LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id = cc.id
    WHERE p.user_id = ?
      AND bc.status != 'deleted'
    GROUP BY bc.id, bp.role
    ORDER BY bc.created_at DESC
");
$stmt->execute([$user_id]);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Uncomment to check what cases are fetched
// echo "<pre>"; print_r($cases); echo "</pre>";

// For each case, fetch the latest schedule proposal and its status for this user
foreach ($cases as &$case) {
    // Fetch latest schedule proposal for this case
    $stmt2 = $pdo->prepare("
        SELECT sp.*, 
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'System') as proposed_by_name
        FROM schedule_proposals sp
        LEFT JOIN users u ON sp.proposed_by_user_id = u.id
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            <a href="../pages/user_dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
            
            <h2>
                <i class="fas fa-gavel"></i>
                My Cases & Hearing Schedules
            </h2>

            <?php if ($action_message): ?>
            <div class="conflict-alert">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($action_message) ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($cases)): ?>
                <?php foreach ($cases as $case): ?>
                <div class="case-card">
                    <div class="case-header">
                        <div>
                            <div class="case-title">Case: <?= htmlspecialchars($case['case_number'] ?? 'N/A') ?></div>
                            <div class="case-number">Filed on <?= $case['incident_date'] ? date('M d, Y', strtotime($case['incident_date'])) : 'Unknown' ?></div>
                        </div>
                        <span class="status-badge status-<?= str_replace('_', '-', strtolower($case['status'])) ?>">
                            <i class="fas fa-circle"></i>
                            <?= ucfirst(str_replace('_', ' ', $case['status'])) ?>
                        </span>
                    </div>

                    <div class="case-meta">
                        <div class="meta-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span><?= htmlspecialchars($case['location']) ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-tags"></i>
                            <span><?= htmlspecialchars($case['categories'] ?: 'No categories') ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-user-tag"></i>
                            <span>You are the <?= ucfirst($case['role']) ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Status: <?= ucfirst(str_replace('_', ' ', $case['scheduling_status'] ?? 'no_schedule')) ?></span>
                        </div>
                    </div>

                    <!-- Enhanced Scheduling Section -->
                    <?php if (isset($case['current_proposal_id']) && $case['current_proposal_id']): ?>
                    <div class="scheduling-status">
                        <div class="scheduling-header">
                            <div class="scheduling-title">
                                <i class="fas fa-calendar-check"></i>
                                Hearing Schedule Status
                            </div>
                        </div>

                        <div class="schedule-info">
                            <div class="schedule-datetime">
                                <div class="datetime-item">
                                    <i class="fas fa-calendar"></i>
                                    <?= date('F j, Y', strtotime($case['proposed_date'])) ?>
                                </div>
                                <div class="datetime-item">
                                    <i class="fas fa-clock"></i>
                                    <?= date('g:i A', strtotime($case['proposed_time'])) ?>
                                </div>
                                <div class="datetime-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($case['hearing_location'] ?? 'Barangay Hall') ?>
                                </div>
                            </div>

                            <?php if ($case['presiding_officer']): ?>
                            <div class="meta-item">
                                <i class="fas fa-user-tie"></i>
                                <span>Presiding Officer: <?= htmlspecialchars($case['presiding_officer']) ?></span>
                            </div>
                            <?php endif; ?>

                            <!-- Proposal Status and Actions -->
                            <?php if ($case['proposal_status'] === 'proposed' && !$case['user_confirmed']): ?>
                            <div class="schedule-actions">
                                <form method="post" action="handle_schedule.php" style="display: inline;">
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="proposal_id" value="<?= $case['current_proposal_id'] ?>">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you can attend this schedule?')">
                                        <i class="fas fa-check"></i> Confirm Availability
                                    </button>
                                </form>
                                
                                <form method="post" action="handle_schedule.php" style="display: inline;">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="proposal_id" value="<?= $case['current_proposal_id'] ?>">
                                    <div style="margin-top: 10px;">
                                        <textarea name="remarks" placeholder="Please provide reason for conflict" required style="width: 100%; margin-bottom: 10px;"></textarea>
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you cannot attend this schedule?')">
                                            <i class="fas fa-times"></i> Can't Attend
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <?php elseif ($case['user_confirmed'] && !$case['captain_confirmed']): ?>
                            <div class="alert alert-info">You have confirmed. Waiting for Captain's confirmation.</div>
                            <?php elseif ($case['proposal_status'] === 'both_confirmed'): ?>
                            <div style="background: #ecfdf5; color: #059669; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
                                <i class="fas fa-check-circle"></i>
                                Hearing schedule confirmed by both parties. Please arrive 15 minutes early.
                            </div>
                            <?php elseif ($case['proposal_status'] === 'conflict'): ?>
                            <div class="conflict-alert">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Scheduling Conflict:</strong> <?= htmlspecialchars($case['conflict_reason'] ?? 'There was a conflict with the proposed schedule.') ?>
                                <br><small>The captain will propose alternative dates.</small>
                            </div>
                            <?php endif; ?>

                            <!-- Remarks Section -->
                            <?php if ($case['user_remarks'] || $case['captain_remarks']): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-light);">
                                <?php if ($case['user_remarks']): ?>
                                <div style="margin-bottom: 0.5rem;">
                                    <strong>Your remarks:</strong> <?= htmlspecialchars($case['user_remarks']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($case['captain_remarks']): ?>
                                <div>
                                    <strong>Captain's remarks:</strong> <?= htmlspecialchars($case['captain_remarks']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Schedule History -->
                    <?php if (!empty($case['all_proposals']) && count($case['all_proposals']) > 1): ?>
                    <div class="schedule-history">
                        <h4 style="margin-bottom: 1rem; color: var(--text-dark);">
                            <i class="fas fa-history"></i>
                            Schedule History
                        </h4>
                        <?php foreach (array_slice($case['all_proposals'], 1) as $proposal): ?>
                        <div class="history-item <?= $proposal['status'] ?>">
                            <div class="history-meta">
                                Proposed by <?= htmlspecialchars($proposal['proposed_by_name']) ?> on 
                                <?= date('M j, Y \a\t g:i A', strtotime($proposal['created_at'])) ?>
                            </div>
                            <div>
                                <strong>Date:</strong> <?= date('F j, Y \a\t g:i A', strtotime($proposal['proposed_date'] . ' ' . $proposal['proposed_time'])) ?>
                                <br>
                                <strong>Status:</strong> <?= ucfirst(str_replace('_', ' ', $proposal['status'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php
                    // Fetch schedule proposal for this case
                    $stmt = $pdo->prepare("
                        SELECT sp.*, 
                               sp.user_remarks,
                               sp.captain_remarks,
                               sp.conflict_reason
                        FROM schedule_proposals sp
                        JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
                        JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
                        JOIN persons p ON bp.person_id = p.id
                        WHERE bc.id = ? AND p.user_id = ?
                        ORDER BY sp.created_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$case['id'], $user_id]);
                    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($proposal):
                    ?>
                    <div class="scheduling-status">
                        <div>
                            <strong>Proposed:</strong> <?= date('M d, Y', strtotime($proposal['proposed_date'])) ?> <?= date('H:i', strtotime($proposal['proposed_time'])) ?><br>
                            <strong>Status:</strong> <?= ucfirst($proposal['status']) ?><br>
                            <strong>Captain:</strong> <?= $proposal['captain_confirmed'] ? 'Confirmed' : 'Pending' ?><br>
                            <?php if (isset($proposal['user_remarks']) && $proposal['user_remarks']): ?>
                                <div style="font-size:12px;color:#888;">Your remarks: <?= htmlspecialchars($proposal['user_remarks']) ?></div>
                            <?php endif; ?>
                            <?php if (isset($proposal['captain_remarks']) && $proposal['captain_remarks']): ?>
                                <div style="font-size:12px;color:#888;">Captain's remarks: <?= htmlspecialchars($proposal['captain_remarks']) ?></div>
                            <?php endif; ?>
                            <?php if (isset($proposal['conflict_reason']) && $proposal['conflict_reason']): ?>
                                <div style="font-size:12px;color:#888;">Conflict reason: <?= htmlspecialchars($proposal['conflict_reason']) ?></div>
                            <?php endif; ?>
                            <?php if ($proposal['status'] === 'proposed' && !$proposal['user_confirmed']): ?>
                                <form method="post" action="handle_schedule.php" style="display: inline;">
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you can attend this schedule?')">
                                        <i class="fas fa-check"></i> Confirm Availability
                                    </button>
                                </form>
                                
                                <form method="post" action="handle_schedule.php" style="display: inline;">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                                    <div style="margin-top: 10px;">
                                        <textarea name="remarks" placeholder="Please provide reason for conflict" required style="width: 100%; margin-bottom: 10px;"></textarea>
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you cannot attend this schedule?')">
                                            <i class="fas fa-times"></i> Can't Attend
                                        </button>
                                    </div>
                                </form>
                            <?php elseif ($proposal['user_confirmed'] && !$proposal['captain_confirmed']): ?>
                                <div class="alert alert-info">You have confirmed. Waiting for Captain's confirmation.</div>
                            <?php elseif ($proposal['status'] === 'both_confirmed'): ?>
                                <div class="alert alert-success">Schedule confirmed by both parties.</div>
                            <?php elseif ($proposal['status'] === 'conflict'): ?>
                                <div class="alert alert-error">Conflict: <?= htmlspecialchars($proposal['remarks']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-gavel"></i>
                    <h3>No Cases Found</h3>
                    <p>You haven't filed any blotter cases yet. When you do, they'll appear here with scheduling information.</p>
                </div>
            <?php endif; ?>

            <!-- Removed the global Proposed Schedule Section as actions should be per-case -->
            <!--
            <div id="proposedSchedule">
                <h3>Proposed Schedule</h3>
                <div id="scheduleDetails">
                </div>
                <div>
                    <button id="confirmScheduleBtn">Confirm Availability</button>
                    <button id="rejectScheduleBtn">Not Available</button>
                </div>
            </div>
            -->
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
                    Swal.fire({
                        icon: 'success',
                        title: 'Response Submitted',
                        text: data.message,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        closeModal();
                        location.reload();
                    });
                } else {
                    throw new Error(data.message || 'Failed to submit response');
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: error.message || 'Failed to submit response. Please try again.'
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
                    const confirmBtn = caseCard.querySelector('.btn-success');
                    const rejectBtn = caseCard.querySelector('.btn-danger');
                    
                    if (action === 'confirm' && confirmBtn) {
                        confirmBtn.click();
                    } else if (action === 'reject' && rejectBtn) {
                        rejectBtn.click();
                    }
                }
                
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
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
                            // Show update notification
                            const notification = document.createElement('div');
                            notification.innerHTML = `
                                <div style="position: fixed; top: 80px; right: 20px; background: #3498db; color: white; 
                                           padding: 1rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
                                           z-index: 1001; cursor: pointer;" onclick="location.reload()">
                                    <i class="fas fa-sync-alt"></i> Schedule updates available. Click to refresh.
                                </div>
                            `;
                            document.body.appendChild(notification);
                            
                            setTimeout(() => {
                                if (notification.parentNode) {
                                    notification.parentNode.removeChild(notification);
                                }
                            }, 10000);
                        }
                    }
                })
                .catch(error => console.log('Update check failed:', error));
        }, 120000); // 2 minutes

        // Add case-id attributes for easier targeting
        document.addEventListener('DOMContentLoaded', function() {
            const caseCards = document.querySelectorAll('.case-card');
            <?php foreach ($cases as $index => $case): ?>
            if (caseCards[<?= $index ?>]) {
                caseCards[<?= $index ?>].setAttribute('data-case-id', '<?= $case['id'] ?>');
            }
            <?php endforeach; ?>
        });
    </script>
</body>
</html>