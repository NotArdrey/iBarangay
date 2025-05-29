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

    switch ($action) {
        case 'confirm':
            // Update proposal as user confirmed
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET user_confirmed = TRUE, 
                    user_confirmed_at = NOW(),
                    status = CASE 
                        WHEN captain_confirmed = TRUE THEN 'both_confirmed' 
                        ELSE 'user_confirmed' 
                    END
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
                $_SESSION['success'] = "Schedule confirmed successfully";
            } else {
                $_SESSION['error'] = "Failed to confirm schedule";
            }
            break;

        case 'reject':
            $remarks = trim($_POST['remarks'] ?? '');
            
            if (empty($remarks)) {
                $_SESSION['error'] = "Please provide a reason for rejection";
                header("Location: blotter_status.php");
                exit;
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
                $_SESSION['success'] = "Schedule rejected successfully";
            } else {
                $_SESSION['error'] = "Failed to reject schedule";
            }
            break;

        default:
            $_SESSION['error'] = "Invalid action";
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = "Error: " . $e->getMessage();
}

header("Location: blotter_status.php");
exit; 