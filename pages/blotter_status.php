<?php
session_start();
require "../config/dbconn.php";

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if it's a JSON request
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Check for JSON decode errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
                exit;
            }
            
            if (empty($input['action']) || empty($input['proposal_id'])) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                exit;
            }
            
            $proposalId = intval($input['proposal_id']);
            
            // Use the user_id that was already set up above
            if (!$user_id) {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                exit;
            }
            
            $userId = $user_id; // Use the already established user_id
        
            // Get the person_id for the current user
            $personStmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = ?");
            $personStmt->execute([$userId]);
            $personId = $personStmt->fetchColumn();
            
            if (!$personId) {
                echo json_encode(['success' => false, 'message' => 'User person record not found']); 
                exit;
            }
            
            // Simplified query without the missing columns
            $stmt = $pdo->prepare("
                SELECT sp.*, 
                       bc.id as case_id
                FROM schedule_proposals sp 
                JOIN blotter_cases bc ON sp.blotter_case_id = bc.id 
                WHERE sp.id = ?
            ");
            $stmt->execute([$proposalId]);
            $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$proposal) {
                echo json_encode(['success' => false, 'message' => 'Proposal not found']); 
                exit;
            }

            // Check if the proposal is in a state that allows user confirmation
            if ($proposal['status'] !== 'pending_user_confirmation' && 
                $proposal['status'] !== 'all_confirmed' && 
                $proposal['status'] !== 'both_confirmed') {
                // If officers haven't acted yet (e.g. status is pending_captain_approval or pending_chief_approval)
                if (strpos($proposal['status'], '_approval') !== false) {
                     echo json_encode(['success' => false, 'message' => 'Schedule is awaiting officer review before participant confirmation. Current status: ' . htmlspecialchars($proposal['status'])]);
                } else {
                     echo json_encode(['success' => false, 'message' => 'Schedule is not currently open for participant confirmation. Current status: ' . htmlspecialchars($proposal['status'])]);
                }
                exit;
            }
            
            // Use the correct case_id field
            $caseId = $proposal['case_id'];
            
            // Find if user is complainant or respondent
            $pstmt = $pdo->prepare("
                SELECT bp.role 
                FROM blotter_participants bp 
                WHERE bp.blotter_case_id = ? AND bp.person_id = ?
            ");
            $pstmt->execute([$caseId, $personId]);
            $role = $pstmt->fetchColumn();
            
            if (!in_array($role, ['complainant', 'respondent', 'witness'])) {
                echo json_encode(['success' => false, 'message' => 'Only complainant, respondent, or witness can confirm/reject availability.']); 
                exit;
            }
        
            if ($input['action'] === 'confirm_availability') {
                // Update the correct column based on role
                $col = '';
                if ($role === 'complainant') $col = 'complainant_confirmed';
                elseif ($role === 'respondent') $col = 'respondent_confirmed';
                elseif ($role === 'witness') $col = 'witness_confirmed';
                
                if (empty($col)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user role']);
                    exit;
                }
                
                $updateStmt = $pdo->prepare("UPDATE schedule_proposals SET $col = 1 WHERE id = ?");
                $updateStmt->execute([$proposalId]);
                
                // Check if all parties confirmed
                $sp2 = $pdo->prepare("SELECT complainant_confirmed, respondent_confirmed, witness_confirmed, captain_confirmed FROM schedule_proposals WHERE id=?");
                $sp2->execute([$proposalId]);
                $flags = $sp2->fetch(PDO::FETCH_ASSOC);
                
                // Get all participants to check if witness exists
                $partStmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT bp.role) as role_count,
                           SUM(CASE WHEN bp.role = 'witness' THEN 1 ELSE 0 END) as witness_count
                    FROM blotter_participants bp
                    WHERE bp.blotter_case_id = ?
                ");
                $partStmt->execute([$caseId]);
                $partInfo = $partStmt->fetch(PDO::FETCH_ASSOC);
                $hasWitness = $partInfo['witness_count'] > 0;

                $officerCheck = $pdo->prepare("
                    SELECT captain_confirmed, chief_confirmed 
                    FROM schedule_proposals 
                    WHERE id = ?
                ");
                $officerCheck->execute([$proposalId]);
                $officerFlags = $officerCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($flags['complainant_confirmed'] && $flags['respondent_confirmed'] && 
                    (!$hasWitness || $flags['witness_confirmed'])) {
                    // Create the hearing record
                    $pdo->beginTransaction();
                    
                    try {
                        // Get hearing number
                        $hStmt = $pdo->prepare("SELECT COUNT(*) + 1 as hearing_num FROM case_hearings WHERE blotter_case_id = ?");
                        $hStmt->execute([$caseId]);
                        $hearingNum = $hStmt->fetchColumn();
                        
                        // Insert hearing
                        $insertHearingStmt = $pdo->prepare("
                            INSERT INTO case_hearings 
                            (blotter_case_id, hearing_date, hearing_type, hearing_outcome,
                            presiding_officer_name, presiding_officer_position, hearing_number)
                            VALUES(?, TIMESTAMP(?, ?), 'mediation', 'scheduled', ?, ?, ?)
                        ");
                        $insertHearingStmt->execute([
                            $caseId,
                            $proposal['proposed_date'],
                            $proposal['proposed_time'],
                            $proposal['presiding_officer'],
                            $proposal['presiding_officer_position'],
                            $hearingNum
                        ]);
                        
                        // Update case and proposal status
                        $updateCaseStmt = $pdo->prepare("
                            UPDATE blotter_cases
                            SET scheduling_status = 'scheduled', 
                                status = 'open',
                                scheduled_hearing = TIMESTAMP(?, ?)
                            WHERE id = ?
                        ");
                        $updateCaseStmt->execute([$proposal['proposed_date'], $proposal['proposed_time'], $caseId]);
                        
                        $updateProposalStmt = $pdo->prepare("
                            UPDATE schedule_proposals
                            SET status = 'all_confirmed'
                            WHERE id = ?
                        ");
                        $updateProposalStmt->execute([$proposalId]);
                        
                        $pdo->commit();
                        
                        echo json_encode(['success' => true, 'message' => 'Hearing officially scheduled. All required participants have confirmed attendance. Officer availability has been noted.']);
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log("Error scheduling hearing: " . $e->getMessage());
                        echo json_encode(['success' => false, 'message' => 'Error scheduling hearing: ' . $e->getMessage()]);
                    }
                } else {
                    // Build message about who still needs to confirm
                    $pending = [];
                    if (!$flags['complainant_confirmed']) $pending[] = 'complainant';
                    if (!$flags['respondent_confirmed']) $pending[] = 'respondent';
                    if ($hasWitness && !$flags['witness_confirmed']) $pending[] = 'witness';
                    
                    echo json_encode(['success' => true, 'message' => 'Your confirmation is recorded. Waiting for: ' . implode(', ', $pending)]);
                }
                exit;
                
            } elseif ($input['action'] === 'reject_availability') {
                // Mark as conflict
                $pdo->beginTransaction();
                
                try {
                    $conflictStmt = $pdo->prepare("UPDATE schedule_proposals SET status='conflict', conflict_reason=? WHERE id=?");
                    $conflictStmt->execute([$input['reason'] ?? 'Participant declined', $proposalId]);
                    
                    // Check if hearing_attempts column exists before using it
                    $columnCheck = $pdo->query("SHOW COLUMNS FROM blotter_cases LIKE 'hearing_attempts'");
                    if ($columnCheck->rowCount() > 0) {
                        $caseUpdateStmt = $pdo->prepare("UPDATE blotter_cases SET scheduling_status='pending_schedule', hearing_attempts=COALESCE(hearing_attempts,0)+1 WHERE id=?");
                    } else {
                        $caseUpdateStmt = $pdo->prepare("UPDATE blotter_cases SET scheduling_status='pending_schedule' WHERE id=?");
                    }
                    $caseUpdateStmt->execute([$caseId]);
                    
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'Your unavailability was recorded. A new schedule will need to be proposed.']);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Error recording conflict: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Error recording conflict: ' . $e->getMessage()]);
                }
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit;
            }
        } catch (Exception $e) {
            error_log("Error in POST handler: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
            exit;
        }
    }
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

// Fetch all cases where the user is a participant (either as person or external)
$sql = "
    SELECT bc.*, 
           GROUP_CONCAT(DISTINCT cc.name SEPARATOR ', ') AS categories,
           bp.role,
           p.id AS person_id,
           ep.id AS external_id,
           u.email as user_email
    FROM blotter_cases bc
    JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
    LEFT JOIN persons p ON bp.person_id = p.id
    LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id = cc.id
    WHERE (p.user_id = ? OR ep.id IS NOT NULL)
      AND bc.status != 'deleted'
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
    } elseif (!empty($case['external_id'])) {
        $stmt_pid = $pdo->prepare("SELECT id FROM blotter_participants WHERE blotter_case_id = ? AND external_participant_id = ?");
        $stmt_pid->execute([$case['id'], $case['external_id']]);
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
                                // AND the proposal status is 'pending_user_confirmation'
                                // AND this specific user has not yet confirmed.
                                $userRoleInCase = $case['role']; // 'complainant', 'respondent', 'witness'
                                $canConfirmThisUser = false;
                                
                                if ($case['proposal_status'] === 'pending_user_confirmation') {
                                    if ($userRoleInCase === 'complainant' && empty($case['complainant_confirmed'])) $canConfirmThisUser = true;
                                    if ($userRoleInCase === 'respondent' && empty($case['respondent_confirmed'])) $canConfirmThisUser = true;
                                    // Assuming 'witness_confirmed' is a general flag for all witnesses for now.
                                    // If individual witness tracking is needed, this logic would be more complex.
                                    if ($userRoleInCase === 'witness' && empty($case['witness_confirmed'])) $canConfirmThisUser = true; 
                                }
                                
                                if ($canConfirmThisUser): ?>
                                    <div class="schedule-actions">
                                        <button class="confirm-btn btn-success"
                                            data-proposal="<?= $case['current_proposal_id'] ?>">
                                            Confirm Availability
                                        </button>
                                        <button class="reject-btn btn-danger"
                                            data-proposal="<?= $case['current_proposal_id'] ?>">
                                            Not Available
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
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        action: 'confirm_availability', 
                        proposal_id: parseInt(proposalId)
                    })
                });
                
                // Check if response is ok
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response');
                }
                
                const data = await res.json();
                
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
        const { value: reason } = await Swal.fire({
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
        
        if (reason) {
            try {
                const res = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ 
                        action: 'reject_availability', 
                        proposal_id: parseInt(proposalId), 
                        reason: reason 
                    })
                });
                
                // Check if response is ok
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const contentType = res.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await res.text();
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response');
                }
                
                const data = await res.json();
                
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
