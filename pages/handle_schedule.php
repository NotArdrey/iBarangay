<?php
session_start();
require "../config/dbconn.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$proposal_id = intval($_POST['proposal_id'] ?? 0);

if (!$proposal_id) {
    $_SESSION['error'] = "Invalid proposal ID";
    header("Location: blotter_status.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // First check if this proposal exists and get its current status
    $stmt = $pdo->prepare("
        SELECT sp.*, bc.id as case_id 
        FROM schedule_proposals sp
        JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
        JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
        JOIN persons p ON bp.person_id = p.id
        WHERE sp.id = ? AND p.user_id = ?
    ");
    $stmt->execute([$proposal_id, $user_id]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposal) {
        throw new Exception("Schedule proposal not found or unauthorized");
    }

    switch ($action) {
        case 'confirm':
            // Only allow confirmation if captain has already confirmed
            if ($proposal['status'] !== 'captain_confirmed') {
                throw new Exception("Cannot confirm schedule - waiting for Captain's confirmation");
            }

            // Update proposal as user confirmed
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET user_confirmed = TRUE, 
                    user_confirmed_at = NOW(),
                    status = 'both_confirmed'
                WHERE id = ? AND blotter_case_id IN (
                    SELECT bc.id 
                    FROM blotter_cases bc
                    JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
                    JOIN persons p ON bp.person_id = p.id
                    WHERE p.user_id = ?
                )
            ");
            $stmt->execute([$proposal_id, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                // Update blotter case status
                $stmt = $pdo->prepare("
                    UPDATE blotter_cases 
                    SET status = 'open',
                        scheduled_hearing = ?
                    WHERE id = ?
                ");
                $hearingDateTime = $proposal['proposed_date'] . ' ' . $proposal['proposed_time'];
                $stmt->execute([$hearingDateTime, $proposal['case_id']]);

                $_SESSION['success'] = "confirmed";
            } else {
                throw new Exception("Failed to confirm schedule");
            }
            break;

        case 'reject':
            $remarks = trim($_POST['remarks'] ?? '');
            
            if (empty($remarks)) {
                throw new Exception("Please provide a reason for rejection");
            }
            
            // Update proposal as rejected
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET status = 'conflict',
                    user_remarks = ?,
                    conflict_reason = ?
                WHERE id = ? AND blotter_case_id IN (
                    SELECT bc.id 
                    FROM blotter_cases bc
                    JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
                    JOIN persons p ON bp.person_id = p.id
                    WHERE p.user_id = ?
                )
            ");
            $stmt->execute([$remarks, $remarks, $proposal_id, $user_id]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['success'] = "rejected";
            } else {
                throw new Exception("Failed to reject schedule");
            }
            break;

        default:
            throw new Exception("Invalid action");
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

header("Location: blotter_status.php");
exit; 