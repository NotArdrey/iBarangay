<?php
// Enhanced captain_page.php with Schedule Management Section
session_start();
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

require '../config/dbconn.php';
require __DIR__ . '/../vendor/autoload.php';

const ROLE_PROGRAMMER   = 1;
const ROLE_SUPER_ADMIN  = 2;
const ROLE_CAPTAIN      = 3;
const ROLE_SECRETARY    = 4;
const ROLE_TREASURER    = 5;
const ROLE_COUNCILOR    = 6;
const ROLE_CHIEF        = 7;
const ROLE_RESIDENT     = 8;

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: ../pages/login.php');
    exit;
}

$stmt = $pdo->prepare('SELECT role_id, barangay_id FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userInfo || !in_array((int)$userInfo['role_id'], [ROLE_CAPTAIN, ROLE_CHIEF])) {
    if ($isAjax) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
    } else {
        header('Location: ../pages/login.php');
    }
    exit;
}
$bid = $userInfo['barangay_id'];

// Handle Schedule Confirmation Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_action'])) {
    header('Content-Type: application/json');
    
    try {
        $proposalId = intval($_POST['proposal_id']);
        $action = $_POST['schedule_action']; // 'confirm' or 'reject'
        $remarks = $_POST['captain_remarks'] ?? '';
        
        if (!$proposalId || !in_array($action, ['confirm', 'reject'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        if ($action === 'confirm') {
            // Update schedule proposal status
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET captain_confirmed = TRUE, 
                    captain_confirmed_at = NOW(),
                    captain_remarks = ?,
                    status = 'captain_confirmed'
                WHERE id = ?
            ");
            $stmt->execute([$remarks, $proposalId]);
            
            // Create actual hearing record
            $stmt = $pdo->prepare("
                SELECT sp.*, bc.id as case_id
                FROM schedule_proposals sp
                JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
                WHERE sp.id = ?
            ");
            $stmt->execute([$proposalId]);
            $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($proposal) {
                // Get next hearing number
                $stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(hearing_number), 0) + 1 as next_hearing
                    FROM case_hearings 
                    WHERE blotter_case_id = ?
                ");
                $stmt->execute([$proposal['case_id']]);
                $nextHearing = $stmt->fetchColumn();
                
                // Create hearing record
                $stmt = $pdo->prepare("
                    INSERT INTO case_hearings 
                    (blotter_case_id, hearing_number, hearing_date, presiding_officer_name, presiding_officer_position, hearing_outcome)
                    VALUES (?, ?, ?, ?, ?, 'scheduled')
                ");
                $hearingDateTime = $proposal['proposed_date'] . ' ' . $proposal['proposed_time'];
                $stmt->execute([
                    $proposal['case_id'],
                    $nextHearing,
                    $hearingDateTime,
                    $proposal['presiding_officer'],
                    $proposal['presiding_officer_position']
                ]);
                
                // Send final confirmation emails
                sendFinalConfirmationEmails($proposal['case_id']);
                $message = 'Schedule confirmed and hearing created successfully';
            }
        } else { // reject
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET status = 'rejected',
                    captain_remarks = ?,
                    captain_confirmed = FALSE
                WHERE id = ?
            ");
            $stmt->execute([$remarks, $proposalId]);
            $message = 'Schedule proposal rejected';
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Error processing schedule: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Handle e-signature upload/removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['esignature_action'])) {
    if ($_POST['esignature_action'] === 'upload' && isset($_FILES['esignature_file'])) {
        $file = $_FILES['esignature_file'];
        $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
        if ($file['error'] === UPLOAD_ERR_OK && in_array($file['type'], $allowedTypes)) {
            $uploadDir = "../uploads/signatures/";
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $fileName = "signature_" . $user_id . "_" . time() . "." . pathinfo($file['name'], PATHINFO_EXTENSION);
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Remove old signature
                $old = $pdo->query("SELECT esignature_path FROM users WHERE id=$user_id")->fetchColumn();
                if ($old && file_exists("../$old")) @unlink("../$old");
                
                $relativePath = "uploads/signatures/" . $fileName;
                $pdo->prepare("UPDATE users SET esignature_path=? WHERE id=?")->execute([$relativePath, $user_id]);
                $_SESSION['success_message'] = "E-signature uploaded successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to upload e-signature.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid file type. Please upload PNG, JPEG, or JPG.";
        }
    } elseif ($_POST['esignature_action'] === 'remove') {
        $old = $pdo->query("SELECT esignature_path FROM users WHERE id=$user_id")->fetchColumn();
        if ($old && file_exists("../$old")) @unlink("../$old");
        $pdo->prepare("UPDATE users SET esignature_path=NULL WHERE id=?")->execute([$user_id]);
        $_SESSION['success_message'] = "E-signature removed successfully.";
    }
    header("Location: captain_page.php");
    exit;
}

// Get captain's e-signature path
$esignaturePath = $pdo->query("SELECT esignature_path FROM users WHERE id=$user_id")->fetchColumn();

// Get pending schedule proposals for captain review
$stmt = $pdo->prepare("
    SELECT sp.*, 
           bc.case_number, 
           bc.description, 
           bc.location,
           COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'System') as proposed_by_name,
           pn_stats.total_participants,
           pn_stats.confirmed_participants,
           pn_stats.confirmation_percentage
    FROM schedule_proposals sp
    JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
    LEFT JOIN users u ON sp.proposed_by_user_id = u.id
    LEFT JOIN (
        SELECT blotter_case_id,
               COUNT(*) as total_participants,
               SUM(CASE WHEN confirmed = TRUE THEN 1 ELSE 0 END) as confirmed_participants,
               ROUND((SUM(CASE WHEN confirmed = TRUE THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as confirmation_percentage
        FROM participant_notifications
        GROUP BY blotter_case_id
    ) pn_stats ON bc.id = pn_stats.blotter_case_id
    WHERE bc.barangay_id = ? 
      AND sp.captain_confirmed = FALSE
      AND sp.status NOT IN ('rejected', 'captain_confirmed')
    ORDER BY sp.created_at ASC
");
$stmt->execute([$bid]);
$pendingProposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently confirmed schedules
$stmt = $pdo->prepare("
    SELECT sp.*, 
           bc.case_number,
           bc.location,
           ch.hearing_outcome
    FROM schedule_proposals sp
    JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
    LEFT JOIN case_hearings ch ON bc.id = ch.blotter_case_id AND ch.hearing_number = 1
    WHERE bc.barangay_id = ? 
      AND sp.captain_confirmed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY sp.captain_confirmed_at DESC
    LIMIT 10
");
$stmt->execute([$bid]);
$recentConfirmedSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Import PHPMailer classes (must be at top-level scope)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to send final confirmation emails using PHPMailer
function sendFinalConfirmationEmails($caseId) {
    global $pdo;
    
    // Get all participants who confirmed
    $stmt = $pdo->prepare("
        SELECT pn.*, 
               COALESCE(CONCAT(p.first_name, ' ', p.last_name), CONCAT(ep.first_name, ' ', ep.last_name)) as full_name,
               COALESCE(u.email, pn.email) as participant_email
        FROM participant_notifications pn
        LEFT JOIN blotter_participants bp ON pn.participant_id = bp.id
        LEFT JOIN persons p ON bp.person_id = p.id
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
        WHERE pn.blotter_case_id = ? AND pn.confirmed = TRUE
    ");
    $stmt->execute([$caseId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get case details
    $stmt = $pdo->prepare("
        SELECT bc.*, sp.proposed_date, sp.proposed_time, sp.hearing_location
        FROM blotter_cases bc
        JOIN schedule_proposals sp ON bc.id = sp.blotter_case_id
        WHERE bc.id = ?
        ORDER BY sp.id DESC LIMIT 1
    ");
    $stmt->execute([$caseId]);
    $caseData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caseData) return;

    foreach ($participants as $participant) {
        if (empty($participant['participant_email'])) continue;
        
        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'barangayhub2@gmail.com';
            $mail->Password   = 'eisy hpjz rdnt bwrp';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom('noreply@barangayhub.com', 'iBarangay');
            $mail->addAddress($participant['participant_email'], $participant['full_name']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Hearing Schedule Confirmed - Case ' . $caseData['case_number'];
            $mail->Body    = "
                <h3>Hearing Schedule Confirmed</h3>
                <p>Dear {$participant['full_name']},</p>
                <p>Your hearing has been confirmed for:</p>
                <ul>
                    <li><strong>Date:</strong> " . date('F j, Y', strtotime($caseData['proposed_date'])) . "</li>
                    <li><strong>Time:</strong> " . date('g:i A', strtotime($caseData['proposed_time'])) . "</li>
                    <li><strong>Location:</strong> {$caseData['hearing_location']}</li>
                    <li><strong>Case:</strong> {$caseData['case_number']}</li>
                </ul>
                <p>Please be present at the scheduled time.</p>
                <p>Thank you,<br>iBarangay System</p>
            ";

            $mail->send();
        } catch (Exception $e) {
            error_log("Final confirmation email failed for " . $participant['participant_email'] . ": " . $mail->ErrorInfo);
        }
    }
}

// Rest of existing user management code...
require_once __DIR__ . "/../components/header.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Captain Dashboard - User & Schedule Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .schedule-card {
            transition: all 0.3s ease;
        }
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="bg-gray-50">
    <main class="container mx-auto p-6">
        <!-- Schedule Management Section -->
        <div class="bg-white rounded-lg shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-calendar-check text-blue-600 mr-2"></i>
                    Schedule Management
                </h2>
            </div>
            <div class="p-6">
                <!-- E-signature Section -->
                <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                    <h3 class="text-lg font-medium text-blue-800 mb-3">Digital Signature</h3>
                    <?php if ($esignaturePath): ?>
                        <div class="flex items-center gap-4">
                            <img src="../<?= htmlspecialchars($esignaturePath) ?>" alt="Current E-signature" 
                                 class="h-16 border border-gray-300 rounded">
                            <div>
                                <p class="text-sm text-green-600 font-medium">✓ E-signature uploaded</p>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="esignature_action" value="remove">
                                    <button type="submit" class="text-red-600 hover:text-red-800 text-sm">
                                        Remove signature
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST" enctype="multipart/form-data" class="flex items-center gap-4">
                            <input type="hidden" name="esignature_action" value="upload">
                            <input type="file" name="esignature_file" accept=".png,.jpg,.jpeg" required 
                                   class="flex-1 p-2 border border-gray-300 rounded">
                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                                Upload Signature
                            </button>
                        </form>
                        <p class="text-xs text-gray-500 mt-1">Upload PNG, JPEG, or JPG format</p>
                    <?php endif; ?>
                </div>

                <!-- Pending Schedule Proposals -->
                <div class="mb-8">
                    <h3 class="text-lg font-medium text-gray-800 mb-4">
                        Pending Schedule Confirmations (<?= count($pendingProposals) ?>)
                    </h3>
                    
                    <?php if (empty($pendingProposals)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-calendar-times text-4xl mb-3"></i>
                            <p>No pending schedule proposals</p>
                        </div>
                    <?php else: ?>
                        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <?php foreach ($pendingProposals as $proposal): ?>
                                <div class="schedule-card bg-white border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                            <?= htmlspecialchars($proposal['case_number']) ?>
                                        </span>
                                        <span class="text-xs text-gray-500">
                                            <?= date('M j, g:i A', strtotime($proposal['created_at'])) ?>
                                        </span>
                                    </div>
                                    
                                    <h4 class="font-medium text-gray-800 mb-2">
                                        <?= htmlspecialchars($proposal['location']) ?>
                                    </h4>
                                    
                                    <div class="space-y-1 text-sm text-gray-600 mb-3">
                                        <p><strong>Date:</strong> <?= date('F j, Y', strtotime($proposal['proposed_date'])) ?></p>
                                        <p><strong>Time:</strong> <?= date('g:i A', strtotime($proposal['proposed_time'])) ?></p>
                                        <p><strong>Proposed by:</strong> <?= htmlspecialchars($proposal['proposed_by_name']) ?></p>
                                        <?php if ($proposal['confirmation_percentage']): ?>
                                            <p><strong>Confirmations:</strong> 
                                                <?= $proposal['confirmed_participants'] ?>/<?= $proposal['total_participants'] ?> 
                                                (<?= $proposal['confirmation_percentage'] ?>%)
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex gap-2">
                                        <button onclick="confirmSchedule(<?= $proposal['id'] ?>, 'confirm')" 
                                                class="flex-1 bg-green-600 text-white text-sm py-2 px-3 rounded hover:bg-green-700">
                                            <i class="fas fa-check mr-1"></i> Confirm
                                        </button>
                                        <button onclick="confirmSchedule(<?= $proposal['id'] ?>, 'reject')" 
                                                class="flex-1 bg-red-600 text-white text-sm py-2 px-3 rounded hover:bg-red-700">
                                            <i class="fas fa-times mr-1"></i> Reject
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Confirmed Schedules -->
                <div>
                    <h3 class="text-lg font-medium text-gray-800 mb-4">
                        Recently Confirmed (Last 7 days)
                    </h3>
                    
                    <?php if (empty($recentConfirmedSchedules)): ?>
                        <div class="text-center py-6 text-gray-500">
                            <p>No recently confirmed schedules</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Case
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Location
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Hearing Date
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Confirmed At
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($recentConfirmedSchedules as $schedule): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($schedule['case_number']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= htmlspecialchars($schedule['location']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M j, Y g:i A', strtotime($schedule['proposed_date'] . ' ' . $schedule['proposed_time'])) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M j, g:i A', strtotime($schedule['captain_confirmed_at'])) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($schedule['hearing_outcome']): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        <?= ucfirst(str_replace('_', ' ', $schedule['hearing_outcome'])) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                        Scheduled
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        async function confirmSchedule(proposalId, action) {
            let title = action === 'confirm' ? 'Confirm Schedule?' : 'Reject Schedule?';
            let inputPlaceholder = action === 'confirm' ? 'Optional remarks...' : 'Reason for rejection...';
            
            const { value: remarks } = await Swal.fire({
                title: title,
                input: 'textarea',
                inputPlaceholder: inputPlaceholder,
                showCancelButton: true,
                confirmButtonText: action === 'confirm' ? 'Confirm' : 'Reject',
                confirmButtonColor: action === 'confirm' ? '#10b981' : '#ef4444',
                inputValidator: (value) => {
                    if (action === 'reject' && !value) {
                        return 'Please provide a reason for rejection';
                    }
                }
            });

            if (remarks !== undefined) {
                try {
                    const formData = new FormData();
                    formData.append('schedule_action', action);
                    formData.append('proposal_id', proposalId);
                    formData.append('captain_remarks', remarks || '');

                    const response = await fetch('captain_page.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: data.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                        location.reload();
                    } else {
                        await Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message
                        });
                    }
                } catch (error) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to process request'
                    });
                }
            }
        }

        // Show success/error messages
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?= addslashes($_SESSION['success_message']) ?>',
                timer: 3000,
                showConfirmButton: false
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?= addslashes($_SESSION['error_message']) ?>'
            });
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </script>

    <script>
        async function toggleStatus(userId, action) {
            try {
                const response = await fetch(`?toggle_status=1&user_id=${userId}&action=${action}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Success!', `User ${action}d successfully`, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to update user status', 'error');
            }
        }

        async function deleteUser(id) {
            const result = await Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            });
            
            if (!result.isConfirmed) return;

            try {
                const response = await fetch(`?delete_id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Deleted!', 'User has been deleted.', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to delete user', 'error');
            }
        }
    </script>
<?php

// Include the rest of the existing user management functionality
// (The original user management code from add_staff_official_barangaycaptian.php would continue here)

/* ────────────── Toggle status & Delete user AJAX … ────── */
if (isset($_GET['toggle_status'])) {
    $userId = (int)$_GET['user_id'];
    $action = $_GET['action'];

    if (!in_array($action, ['activate', 'deactivate'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    // Verify user belongs to captain's barangay
    $checkStmt = $pdo->prepare("SELECT barangay_id FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkResult || $checkResult['barangay_id'] != $bid) {
        echo json_encode(['success' => false, 'message' => 'User not found or access denied']);
        exit;
    }

    $newStatus = $action === 'activate' ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    if ($stmt->execute([$newStatus, $userId])) {
        echo json_encode(['success' => true, 'newStatus' => $newStatus ? 'yes' : 'no']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }
    exit;
}

/* ────────────── Delete user (FIXED) ────── */
if (isset($_GET['delete_id'])) {
    $userId = (int)$_GET['delete_id'];
    
    // Verify user belongs to captain's barangay
    $checkStmt = $pdo->prepare("SELECT barangay_id FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkResult || $checkResult['barangay_id'] != $bid) {
        echo json_encode(['success' => false, 'message' => 'User not found or access denied']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$userId])) {
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete user: ' . $e->getMessage()]);
    }
    exit;
}

// Get barangay name
$barangayStmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
$barangayStmt->execute([$bid]);
$barangayName = $barangayStmt->fetchColumn();

// Get allowed roles for dropdown
$allowedRoles = [ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR, ROLE_CHIEF];
$placeholders = str_repeat('?,', count($allowedRoles) - 1) . '?';
$roleStmt = $pdo->prepare("SELECT id as role_id, name as role_name FROM roles WHERE id IN ($placeholders)");
$roleStmt->execute($allowedRoles);
$roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch list for page render
$officialRoles = [ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR, ROLE_CHIEF];
$placeholders = str_repeat('?,', count($officialRoles) - 1) . '?';
$stmt = $pdo->prepare("
    SELECT u.*, r.name as role_name, b.name as barangay_name,
           CASE WHEN u.is_active = 1 THEN 'Active' ELSE 'Inactive' END as status_text
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN barangay b ON u.barangay_id = b.id
    WHERE (u.role_id IN ($placeholders) OR u.role_id IN ($placeholders)) AND u.barangay_id = ?
    ORDER BY u.role_id, u.last_name, u.first_name
");
$executeParams = array_merge($officialRoles, $officialRoles, [$bid]);
$stmt->execute($executeParams);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Add User Management Table HTML here if needed -->
<div class="mt-8 bg-white rounded-lg shadow-sm">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-xl font-semibold text-gray-800">
            <i class="fas fa-users text-blue-600 mr-2"></i>
            User Management
        </h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?= htmlspecialchars($user['email']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?= htmlspecialchars($user['role_name']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $user['status_text'] ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2">
                                <button onclick="toggleStatus(<?= $user['id'] ?>, '<?= $user['is_active'] ? 'deactivate' : 'activate' ?>')" 
                                        class="text-indigo-600 hover:text-indigo-900">
                                    <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                                <button onclick="deleteUser(<?= $user['id'] ?>)" 
                                        class="text-red-600 hover:text-red-900">
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Additional JavaScript for user management
function openModal(action, id = null, roleId = null) {
    // Implementation for user management modal
    console.log('Open modal:', action, id, roleId);
}
</script>