
<?php
session_start();
require "../config/dbconn.php";

// Handle AJAX requests BEFORE any HTML output or navbar include
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    // Check content type for JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJsonRequest = strpos($contentType, 'application/json') !== false;
    
    if ($isJsonRequest || $isAjax) {
        // Prevent any HTML output
        ob_clean();
        
        // Set JSON response headers
        header('Content-Type: application/json');
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
                exit;
            }
            
            if (!isset($data['action'])) {
                echo json_encode(['success' => false, 'message' => 'No action specified']);
                exit;
            }
            
            if ($data['action'] === 'confirm_availability' && isset($data['proposal_id'])) {
                try {
                    $proposalId = intval($data['proposal_id']);
                    $userId = $_SESSION['user_id'] ?? null;
                    
                    if (!$userId) {
                        echo json_encode(['success' => false, 'message' => 'User not authenticated']);
                        exit;
                    }
                    
                    // Get person_id from user_id
                    $personStmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = ?");
                    $personStmt->execute([$userId]);
                    $personId = $personStmt->fetchColumn();
                    
                    if (!$personId) {
                        echo json_encode(['success' => false, 'message' => 'Person record not found']);
                        exit;
                    }
                    
                    // Get proposal and case details
                    $proposalStmt = $pdo->prepare("
                        SELECT sp.*, bp.role, sp.blotter_case_id 
                        FROM schedule_proposals sp
                        JOIN blotter_participants bp ON sp.blotter_case_id = bp.blotter_case_id AND bp.person_id = ?
                        WHERE sp.id = ?
                    ");
                    $proposalStmt->execute([$personId, $proposalId]);
                    $proposal = $proposalStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$proposal) {
                        echo json_encode(['success' => false, 'message' => 'Proposal not found or you are not a participant']);
                        exit;
                    }
                    
                    $pdo->beginTransaction();
                    
                    // Update based on participant role
                    if ($proposal['role'] === 'complainant') {
                        $stmt = $pdo->prepare("UPDATE schedule_proposals SET complainant_confirmed = 1 WHERE id = ?");
                        $stmt->execute([$proposalId]);
                    } elseif ($proposal['role'] === 'respondent') {
                        $stmt = $pdo->prepare("UPDATE schedule_proposals SET respondent_confirmed = 1 WHERE id = ?");
                        $stmt->execute([$proposalId]);
                    } elseif ($proposal['role'] === 'witness') {
                        $stmt = $pdo->prepare("UPDATE schedule_proposals SET witness_confirmed = 1 WHERE id = ?");
                        $stmt->execute([$proposalId]);
                    }
                    
                    // Check if all required parties have confirmed
                    $confirmStmt = $pdo->prepare("
                        SELECT complainant_confirmed, respondent_confirmed
                        FROM schedule_proposals 
                        WHERE id = ?
                    ");
                    $confirmStmt->execute([$proposalId]);
                    $confirmations = $confirmStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($confirmations['complainant_confirmed'] && $confirmations['respondent_confirmed']) {
                        // All have confirmed - finalize the schedule proposal status
                        $pdo->prepare("UPDATE schedule_proposals SET status = 'all_confirmed' WHERE id = ?")->execute([$proposalId]);
                        
                        $message = 'Availability confirmed by all parties for the proposed schedule. The hearing will proceed as planned by the admin.';
                    } else {
                        $message = 'Your confirmation has been recorded. Waiting for other participants.';
                    }
                    
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                    
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                    exit;
                }
                
            } elseif ($data['action'] === 'reject_availability' && isset($data['proposal_id'])) {
                try {
                    $proposalId = intval($data['proposal_id']);
                    $conflictReason = $data['conflict_reason'] ?? 'No reason provided';
                    
                    // Get proposal and case ID
                    $stmt = $pdo->prepare("SELECT blotter_case_id FROM schedule_proposals WHERE id = ?");
                    $stmt->execute([$proposalId]);
                    $caseId = $stmt->fetchColumn();
                    
                    if (!$caseId) {
                        echo json_encode(['success' => false, 'message' => 'Proposal not found']);
                        exit;
                    }
                    
                    $pdo->beginTransaction();
                    
                    // Update proposal status to conflict
                    $pdo->prepare("
                        UPDATE schedule_proposals 
                        SET status = 'conflict', conflict_reason = ? 
                        WHERE id = ?
                    ")->execute([$conflictReason, $proposalId]);
                    
                    // NEW LOGIC: If any participant is unavailable, the case immediately becomes CFA eligible.
                    $cfaReasonForUnavailable = 'Participant marked as unavailable for scheduled hearing.';
                    $pdo->prepare("
                        UPDATE blotter_cases 
                        SET is_cfa_eligible = TRUE, 
                            status = 'pending', 
                            cfa_reason = ?, 
                            scheduling_status = 'pending_schedule'
                        WHERE id = ?
                    ")->execute([$cfaReasonForUnavailable, $caseId]);
                    
                    // Mark the original 'scheduled' hearing entry as 'cancelled' due to this conflict
                    $updateHearingStmt = $pdo->prepare("
                        UPDATE case_hearings
                        SET hearing_outcome = 'cancelled',
                            resolution_details = CONCAT(COALESCE(resolution_details, ''), 'Participant unavailable: ', ?),
                            updated_at = NOW()
                        WHERE schedule_proposal_id = ? AND hearing_outcome = 'scheduled'
                    ");

                    if ($updateHearingStmt->execute([$conflictReason, $proposalId])) {
                        if ($updateHearingStmt->rowCount() > 0) {
                            $message = 'Your unavailability has been recorded. The hearing is cancelled, and the case is now eligible for CFA. The case administrator will be notified.';
                        } else {
                             $message = 'Your unavailability has been recorded (the specific hearing instance was not found or already cancelled). The case is now eligible for CFA. The case administrator will be notified.';
                        }
                    } else {
                         $message = 'Your unavailability has been recorded, but there was an issue updating the specific hearing details. The case is now eligible for CFA. The case administrator will be notified.';
                    }
                    
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                    
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                    exit;
                }
                
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
                exit;
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            exit;
        }
    }
}

// Include navbar and other components AFTER AJAX handling
require "../components/navbar.php";

// Add this line to ensure $conn is set from $pdo
$conn = $pdo;
global $conn;

// Add error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set up user session variables BEFORE handling POST requests
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 4;
$user_info = null;
$barangay_name = "Barangay";
$barangay_id = isset($_SESSION['barangay_id']) ? $_SESSION['barangay_id'] : null;

// Initialize action message variable to prevent undefined variable warning
$action_message = '';

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
        $barangay_id = $row['barangay_id']; // Get barangay_id from the database
    }
    $stmt = null;
}

$columnCheck = $pdo->query("SHOW COLUMNS FROM blotter_cases LIKE 'hearing_attempts'");
$hasNewColumns = $columnCheck->rowCount() > 0;

// Fetch all cases where the user is a participant (either as person or external)
$sql = "
    SELECT bc.*, 
           GROUP_CONCAT(DISTINCT cc.name SEPARATOR ', ') AS categories,
           bp.role,
           p.id AS person_id,
           u.email as user_email
    FROM blotter_cases bc
    JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
    JOIN persons p ON bp.person_id = p.id AND p.user_id = ?
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id = cc.id
    WHERE bc.status != 'deleted'
    GROUP BY bc.id, bp.id
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
        $case['complainant_confirmed'] = $proposal['complainant_confirmed'];
        $case['respondent_confirmed']  = $proposal['respondent_confirmed'];
    }
    $case['has_email'] = !empty($case['user_email']);

    // Fetch participant notification for this participant (person or external)
    $participant_id = null;
    if (!empty($case['person_id'])) {
        $stmt_pid = $pdo->prepare("SELECT id FROM blotter_participants WHERE blotter_case_id = ? AND person_id = ?");
        $stmt_pid->execute([$case['id'], $case['person_id']]);
        $participant_id = $stmt_pid->fetchColumn();
    }
    if ($participant_id) {
        $stmt3 = $pdo->prepare("
            SELECT pn.*, bp.role 
            FROM participant_notifications pn
            JOIN blotter_participants bp ON pn.participant_id = bp.id
            WHERE pn.blotter_case_id = ? AND pn.participant_id = ?
            ORDER BY pn.created_at DESC
            LIMIT 1
        ");
        $stmt3->execute([$case['id'], $participant_id]);
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.1/sweetalert2.all.min.js"></script>


    
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
        
        /* Hearing attempts indicator */
        .hearing-attempts-indicator {
            background: var(--bg-light);
            border-radius: 8px;
            padding: 0.75rem;
            margin: 1rem 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-left: 4px solid var(--primary-color);
        }
        
        .attempts-remaining {
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .attempts-progress {
            display: flex;
            gap: 0.5rem;
        }
        
        .attempt-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--border-light);
        }
        
        .attempt-dot.used {
            background-color: var(--warning-color);
        }
        
        .attempt-dot.available {
            background-color: var(--success-color);
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

        /* Updated styles for Confirm/Reject buttons on blotter_status.php */
        .confirm-btn,
        .reject-btn {
            padding: 0.6rem 1.2rem; /* Increased padding */
            border-radius: 8px;    /* Slightly more rounded corners */
            font-weight: 500;
            font-size: 0.9rem;     /* Slightly larger font */
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;         /* Increased gap for icon */
            text-decoration: none;
            border: none;          /* Ensure no default border interferes */
            box-shadow: var(--shadow-sm); /* Add a subtle shadow */
        }

        .confirm-btn:hover,
        .reject-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .confirm-btn {
            background-color: var(--success-color);
            color: white;
        }
        .confirm-btn:hover {
            background-color: #047857; /* Darker shade of success */
        }

        .reject-btn {
            background-color: var(--error-color);
            color: white;
        }
        .reject-btn:hover {
            background-color: #b91c1c; /* Darker shade of error */
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
                                    <div class="scheduling-title">
                                        <i class="fas fa-calendar-alt"></i> Hearing Schedule Proposal
                                    </div>
                                </div>
                                <div class="schedule-info">
                                    <div class="schedule-datetime">
                                        <div class="datetime-item">
                                            <i class="fas fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($case['proposed_date'])) ?>
                                        </div>
                                        <div class="datetime-item">
                                            <i class="fas fa-clock"></i>
                                            <?= date('g:i A', strtotime($case['proposed_time'])) ?>
                                        </div>
                                        <div class="datetime-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?= htmlspecialchars($case['hearing_location']) ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong>Presiding Officer:</strong> <?= htmlspecialchars($case['presiding_officer']) ?>
                                    </div>
                                    <div>
                                        <strong>Status:</strong>
                                        <?php
                                        $status = $case['proposal_status'];
                                        if ($status === 'conflict') {
                                            echo '<span class="status-badge status-cfa-eligible">Conflict: ' . htmlspecialchars($case['conflict_reason']) . '</span>';
                                        } elseif ($status === 'all_confirmed' || $status === 'both_confirmed') { // both_confirmed is legacy
                                            echo '<span class="status-badge status-open">Confirmed by all participants</span>';
                                        } elseif ($status === 'pending_user_confirmation') {
                                            echo '<span class="status-badge status-pending">Pending Participant Confirmation</span>';
                                        } elseif ($status === 'pending_captain_approval') {
                                            echo '<span class="status-badge status-pending">Pending Captain Review</span>';
                                        } elseif ($status === 'pending_chief_approval') {
                                            echo '<span class="status-badge status-pending">Pending Chief Officer Review</span>';
                                        } else {
                                            echo '<span class="status-badge">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $status))) . '</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php
                                // Show confirm/reject buttons if user is complainant/respondent/witness 
                                // AND the proposal status allows confirmation
                                // AND this specific user has not yet confirmed.
                                $userRoleInCase = $case['role']; // 'complainant', 'respondent', 'witness'
                                $canConfirmThisUser = false;
                                
                                if ($case['status'] === 'open' && 
                                    ($case['proposal_status'] === 'pending_user_confirmation' ||
                                     $case['proposal_status'] === 'both_confirmed')) { // 'both_confirmed' might mean one party confirmed
                                    if ($userRoleInCase === 'complainant' && empty($case['complainant_confirmed'])) $canConfirmThisUser = true;
                                    if ($userRoleInCase === 'respondent' && empty($case['respondent_confirmed'])) $canConfirmThisUser = true;
                                    // Assuming 'witness_confirmed' is a general flag for all witnesses for now.
                                    // If individual witness tracking is needed, this logic would be more complex.
                                    if ($userRoleInCase === 'witness' && empty($case['witness_confirmed'])) $canConfirmThisUser = true; 
                                }
                                
                                // Explicitly ensure buttons do not show if all_confirmed, regardless of individual flags
                                if ($case['proposal_status'] === 'all_confirmed') {
                                    $canConfirmThisUser = false;
                                }
                                
                                if ($canConfirmThisUser): ?>
                                    <div class="schedule-actions">
                                        <button class="confirm-btn btn-success"
                                            data-proposal="<?= $case['current_proposal_id'] ?>">
                                            <i class="fas fa-check"></i> Confirm Availability
                                        </button>
                                        <button class="reject-btn btn-danger"
                                            data-proposal="<?= $case['current_proposal_id'] ?>">
                                            <i class="fas fa-times"></i> Not Available
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div> <!-- end scheduling-status -->

                            <div class="schedule-history">
                                <div class="history-item <?= htmlspecialchars($case['proposal_status']) ?>">
                                    <div class="history-meta">
                                        <i class="fas fa-clock"></i>
                                        <?= date('M d, Y g:i A', strtotime($case['updated_at'])) ?>
                                    </div>
                                    <div>
                                        <?php
                                        $status = $case['proposal_status'] ?? '';
                                        if ($status === 'conflict') {
                                            echo 'Schedule proposed but a participant is unavailable or a conflict arose.';
                                        } elseif ($status === 'all_confirmed' || $status === 'both_confirmed') {
                                            echo 'Hearing schedule confirmed by all required participants.';
                                        } elseif ($status === 'pending_user_confirmation') {
                                            echo 'Schedule awaiting confirmation from participants.';
                                        } elseif ($status === 'pending_captain_approval') {
                                            echo 'Schedule proposed, awaiting review from Barangay Captain.';
                                        } elseif ($status === 'pending_chief_approval') {
                                            echo 'Schedule proposed, awaiting review from Chief Officer.';
                                        } elseif ($status === 'proposed') { // Legacy or initial state before officer review
                                            echo 'New hearing schedule proposed, pending officer review.';
                                        } else {
                                            echo 'Hearing schedule status: ' . htmlspecialchars(ucfirst(str_replace('_', ' ', $status)));
                                        }
                                        ?>
                                    </div>
                                </div>

                                <?php if (!empty($case['user_remarks']) || !empty($case['captain_remarks'])): ?>
                                    <div class="history-item remarks-item">
                                        <div class="history-meta">
                                            <i class="fas fa-comments"></i> Remarks
                                        </div>
                                        <div>
                                            <?php if (!empty($case['user_remarks'])): ?>
                                                <div><strong>You:</strong> <?= nl2br(htmlspecialchars($case['user_remarks'])) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($case['captain_remarks'])): ?>
                                                <div><strong>Captain:</strong> <?= nl2br(htmlspecialchars($case['captain_remarks'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($case['conflict_reason'])): ?>
                                    <div class="history-item conflict-item">
                                        <div class="history-meta">
                                            <i class="fas fa-exclamation-triangle"></i> Conflict Reason
                                        </div>
                                        <div>
                                            <?= nl2br(htmlspecialchars($case['conflict_reason'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?> 

                    </div> <!-- end case-card -->
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Hidden modals for schedule actions -->
            <div class="modal" id="confirmModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title">Confirm Schedule</div>
                        <button class="close-modal" onclick="document.getElementById('confirmModal').classList.remove('show')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to confirm this schedule?</p>
                        <div id="confirmScheduleDetails" class="schedule-info">
                            <!-- Schedule details will be populated by JavaScript -->
                        </div>
                        <div class="form-group">
                            <label for="userRemarks" class="form-label">Your Remarks (optional)</label>
                            <textarea id="userRemarks" class="form-control" placeholder="Enter any remarks here..."></textarea>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-primary" id="btnConfirmSchedule">Confirm Schedule</button>
                        <button class="btn btn-outline" onclick="document.getElementById('confirmModal').classList.remove('show')">Cancel</button>
                    </div>
                </div>
            </div>

            <div class="modal" id="rejectModal">
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title">Provide Conflict Details</div>
                        <button class="close-modal" onclick="document.getElementById('rejectModal').classList.remove('show')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>Please let us know why you cannot attend the proposed schedule:</p>
                        <div class="form-group">
                            <label for="conflictReason" class="form-label">Conflict Reason</label>
                            <textarea id="conflictReason" class="form-control" placeholder="Enter the reason for conflict..."></textarea>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-danger" id="btnRejectSchedule">Submit</button>
                        <button class="btn btn-outline" onclick="document.getElementById('rejectModal').classList.remove('show')">Cancel</button>
                    </div>
                </div>
            </div>
        </div> <!-- end container -->
    </div> <!-- end page-wrapper -->

    <!-- JavaScript to handle schedule confirmation/rejection -->
    <script>
        document.querySelectorAll('.confirm-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const proposalId = btn.dataset.proposal;
                
                // Use SweetAlert2 for confirmation
                const result = await Swal.fire({
                    title: 'Confirm Availability',
                    text: 'Are you available for this hearing schedule?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#059669',
                    cancelButtonColor: '#dc2626',
                    confirmButtonText: 'Yes, I am available',
                    cancelButtonText: 'Cancel'
                });
                
                if (result.isConfirmed) {
                    try {
                        const res = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({ 
                                action: 'confirm_availability', 
                                proposal_id: parseInt(proposalId)
                            })
                        });
                        
                        // Log response for debugging
                        const responseText = await res.text();
                        console.log('Response:', responseText);
                        
                        // Check if response is ok
                        if (!res.ok) {
                            throw new Error(`HTTP error! status: ${res.status}`);
                        }
                        
                        let data;
                        try {
                            data = JSON.parse(responseText);
                        } catch (parseError) {
                            console.error('Failed to parse JSON:', responseText);
                            throw new Error('Server returned invalid JSON response');
                        }
                        
                        if (data.success) {
                            await Swal.fire('Success', data.message, 'success');
                            location.reload();
                        } else {
                            Swal.fire('Error', data.message || 'An error occurred', 'error');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        Swal.fire('Error', 'Failed to process your request. Please try again. Error: ' + error.message, 'error');
                    }
                }
            });
        });

        document.querySelectorAll('.reject-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const proposalId = btn.dataset.proposal;
                
                // Use SweetAlert2 for input
                const { value: conflictReason } = await Swal.fire({
                    title: 'Not Available',
                    input: 'textarea',
                    inputLabel: 'Please provide a reason for your unavailability:',
                    inputPlaceholder: 'Enter your reason here...',
                    inputAttributes: {
                        'aria-label': 'Enter your reason'
                    },
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Submit',
                    inputValidator: (value) => {
                        if (!value) {
                            return 'You need to provide a reason!'
                        }
                    }
                });
                
                if (conflictReason) {
                    try {
                        const res = await fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({ 
                                action: 'reject_availability', 
                                proposal_id: parseInt(proposalId), 
                                conflict_reason: conflictReason
                            })
                        });
                        
                        // Log response for debugging
                        const responseText = await res.text();
                        console.log('Response:', responseText);
                        
                        // Check if response is ok
                        if (!res.ok) {
                            throw new Error(`HTTP error! status: ${res.status}`);
                        }
                        
                        let data;
                        try {
                            data = JSON.parse(responseText);
                        } catch (parseError) {
                            console.error('Failed to parse JSON:', responseText);
                            throw new Error('Server returned invalid JSON response');
                        }
                        
                        if (data.success) {
                            await Swal.fire('Success', data.message, 'success');
                            location.reload();
                        } else {
                            Swal.fire('Error', data.message || 'An error occurred', 'error');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        Swal.fire('Error', 'Failed to process your request. Please try again. Error: ' + error.message, 'error');
                    }
                }
            });
        });
    </script>
</body>
</html>
