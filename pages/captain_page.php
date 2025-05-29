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
require_once '../config/email_config.php'; // Include email service

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
if (!$userInfo || (int)$userInfo['role_id'] !== ROLE_CAPTAIN) {
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
            throw new Exception('Invalid request parameters');
        }
        
        $pdo->beginTransaction();
        
        if ($action === 'confirm') {
            // Update proposal as captain confirmed
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET captain_confirmed = TRUE, 
                    captain_confirmed_at = NOW(), 
                    captain_remarks = ?,
                    status = CASE 
                        WHEN user_confirmed = TRUE THEN 'both_confirmed' 
                        ELSE 'captain_confirmed' 
                    END
                WHERE id = ? AND blotter_case_id IN (
                    SELECT id FROM blotter_cases WHERE barangay_id = ?
                )
            ");
            $stmt->execute([$remarks, $proposalId, $bid]);
            
            // Check if both parties confirmed to schedule hearing
            $stmt = $pdo->prepare("
                SELECT sp.*, bc.id as case_id, bc.case_number
                FROM schedule_proposals sp
                JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
                WHERE sp.id = ? AND sp.user_confirmed = TRUE AND sp.captain_confirmed = TRUE
            ");
            $stmt->execute([$proposalId]);
            $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($proposal) {
                // Schedule the first hearing
                $stmt = $pdo->prepare("
                    INSERT INTO case_hearings 
                    (blotter_case_id, hearing_date, hearing_type, hearing_number, 
                     presiding_officer_name, presiding_officer_position, hearing_notes, hearing_outcome)
                    VALUES (?, ?, 'first', 1, ?, ?, 'First hearing - confirmed by all parties', 'scheduled')
                ");
                
                $hearingDateTime = $proposal['proposed_date'] . ' ' . $proposal['proposed_time'];
                $stmt->execute([
                    $proposal['blotter_case_id'],
                    $hearingDateTime,
                    $proposal['presiding_officer'],
                    $proposal['presiding_officer_position']
                ]);
                
                // Update blotter case
                $stmt = $pdo->prepare("
                    UPDATE blotter_cases 
                    SET hearing_count = 1, status = 'open', scheduled_hearing = ?
                    WHERE id = ?
                ");
                $stmt->execute([$hearingDateTime, $proposal['blotter_case_id']]);
                
                // Send final confirmation emails using PHPMailer
                sendFinalConfirmationEmails($proposal['blotter_case_id']);
                
                $message = 'Schedule confirmed! First hearing has been officially scheduled and participants have been notified.';
            } else {
                $message = 'Schedule confirmed. Waiting for all participant confirmations.';
            }
            
        } else { // reject
            $stmt = $pdo->prepare("
                UPDATE schedule_proposals 
                SET status = 'conflict', 
                    captain_remarks = ?, 
                    conflict_reason = ?
                WHERE id = ? AND blotter_case_id IN (
                    SELECT id FROM blotter_cases WHERE barangay_id = ?
                )
            ");
            $stmt->execute([$remarks, $remarks, $proposalId, $bid]);
            
            $message = 'Schedule rejected. Participants will be notified to propose alternative dates.';
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
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $targetDir = "../uploads/esignatures/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $filename = "captain_{$user_id}_" . time() . ".$ext";
            $targetPath = $targetDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Remove old signature if exists
                $old = $pdo->query("SELECT esignature_path FROM users WHERE id=$user_id")->fetchColumn();
                if ($old && file_exists("../$old")) @unlink("../$old");
                $pdo->prepare("UPDATE users SET esignature_path=? WHERE id=?")->execute(["uploads/esignatures/$filename", $user_id]);
                $_SESSION['success_message'] = "E-signature uploaded successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to upload signature.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid file type. Only PNG/JPG allowed.";
        }
    } elseif ($_POST['esignature_action'] === 'remove') {
        $old = $pdo->query("SELECT esignature_path FROM users WHERE id=$user_id")->fetchColumn();
        if ($old && file_exists("../$old")) @unlink("../$old");
        $pdo->prepare("UPDATE users SET esignature_path=NULL WHERE id=?")->execute([$user_id]);
        $_SESSION['success_message'] = "E-signature removed.";
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
        WHERE notification_type = 'summons'
        GROUP BY blotter_case_id
    ) pn_stats ON bc.id = pn_stats.blotter_case_id
    WHERE bc.barangay_id = ? 
      AND sp.status IN ('proposed', 'user_confirmed')
      AND sp.captain_confirmed = FALSE
    ORDER BY 
        CASE WHEN sp.status = 'user_confirmed' THEN 0 ELSE 1 END,
        sp.created_at ASC
");
$stmt->execute([$bid]);
$pendingProposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently confirmed schedules
$stmt = $pdo->prepare("
    SELECT sp.*, 
           bc.case_number, 
           bc.description,
           ch.hearing_date as scheduled_hearing,
           ch.hearing_outcome
    FROM schedule_proposals sp
    JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
    LEFT JOIN case_hearings ch ON bc.id = ch.blotter_case_id AND ch.hearing_number = 1
    WHERE bc.barangay_id = ? 
      AND sp.status = 'both_confirmed'
      AND sp.captain_confirmed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY sp.captain_confirmed_at DESC
    LIMIT 10
");
$stmt->execute([$bid]);
$recentConfirmedSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to send final confirmation emails using PHPMailer
function sendFinalConfirmationEmails($caseId) {
    global $pdo;
    // PHPMailer classes
    require_once '../vendor/autoload.php';
    
    // Get all participants who confirmed
    $stmt = $pdo->prepare("
        SELECT DISTINCT pn.email_address, bp.role,
               COALESCE(CONCAT(p.first_name, ' ', p.last_name), CONCAT(ep.first_name, ' ', ep.last_name)) AS full_name
        FROM participant_notifications pn
        JOIN blotter_participants bp ON pn.participant_id = bp.id
        LEFT JOIN persons p ON bp.person_id = p.id
        LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
        WHERE pn.blotter_case_id = ? AND pn.confirmed = TRUE
    ");
    $stmt->execute([$caseId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get case details
    $stmt = $pdo->prepare("
        SELECT bc.*, sp.proposed_date, sp.proposed_time, sp.hearing_location,
               b.name as barangay_name
        FROM blotter_cases bc
        JOIN schedule_proposals sp ON bc.id = sp.blotter_case_id
        JOIN barangay b ON bc.barangay_id = b.id
        WHERE bc.id = ? AND sp.status = 'both_confirmed'
        ORDER BY sp.id DESC LIMIT 1
    ");
    $stmt->execute([$caseId]);
    $caseData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caseData) return;

    foreach ($participants as $participant) {
        if (empty($participant['email_address'])) continue;
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'barangayhub2@gmail.com';
            $mail->Password   = 'eisy hpjz rdnt bwrp';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->setFrom('noreply@barangayhub.com', 'Barangay Hub');
            $mail->addAddress($participant['email_address'], $participant['full_name']);
            $mail->Subject = 'Hearing Schedule Confirmed - Case ' . $caseData['case_number'];
            $mail->Body    = "Dear {$participant['full_name']},\n\n"
                . "The hearing for case {$caseData['case_number']} has been officially scheduled.\n"
                . "Date: {$caseData['proposed_date']} {$caseData['proposed_time']}\n"
                . "Location: {$caseData['hearing_location']}\n"
                . "Barangay: {$caseData['barangay_name']}\n\n"
                . "Please be present at the scheduled date and time.\n\n"
                . "Thank you,\nBarangay Hub";
            $mail->send();
        } catch (\Exception $e) {
            // Optionally log error: error_log('Mailer Error: ' . $mail->ErrorInfo);
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
            border-left: 4px solid #e5e7eb;
        }
        .schedule-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .schedule-card.urgent {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        .schedule-card.ready {
            border-left-color: #10b981;
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
        }
        .schedule-card.pending {
            border-left-color: #3b82f6;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }
        .confirmation-progress {
            height: 8px;
            background-color: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
            border-radius: 4px;
        }
        .progress-low { background-color: #ef4444; }
        .progress-medium { background-color: #f59e0b; }
        .progress-high { background-color: #10b981; }
        .action-button {
            transition: all 0.2s ease;
        }
        .action-button:hover {
            transform: translateY(-1px);
        }
        .notification-badge {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <main class="container mx-auto p-6">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Captain Dashboard</h1>
                <p class="text-gray-600">Manage staff, officials, and hearing schedules</p>
            </div>
            <div class="flex items-center space-x-4 mt-4 md:mt-0">
                <!-- Notification Bell -->
                <div class="relative">
                    <button class="relative p-2 text-gray-600 hover:text-gray-800 transition-colors">
                        <i class="fas fa-bell text-xl"></i>
                        <?php if (count($pendingProposals) > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center notification-badge">
                            <?= count($pendingProposals) ?>
                        </span>
                        <?php endif; ?>
                    </button>
                </div>
                <button onclick="openModal('add')" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors">
                    <i class="fas fa-plus mr-2"></i>Add Official
                </button>
            </div>
        </div>

        <!-- E-signature Management UI (top of main container, before schedule management) -->
        <div class="bg-white rounded-lg shadow-sm mb-8 p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-2">
                <i class="fas fa-pen-nib text-blue-600 mr-2"></i>
                E-signature for Reports & Summons
            </h2>
            <form method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row items-center gap-4">
                <?php if ($esignaturePath): ?>
                    <div>
                        <img src="../<?= htmlspecialchars($esignaturePath) ?>" alt="E-signature" style="height:60px;max-width:200px;border:1px solid #ddd;background:#fff;padding:4px;">
                    </div>
                    <button type="submit" name="esignature_action" value="remove"
                        class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded transition-all">
                        Remove Signature
                    </button>
                <?php else: ?>
                    <input type="file" name="esignature_file" accept="image/png,image/jpeg" required>
                    <button type="submit" name="esignature_action" value="upload"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded transition-all">
                        Upload Signature
                    </button>
                <?php endif; ?>
                <span class="text-xs text-gray-500 ml-2">PNG/JPG only. Transparent background recommended. Max height: 60px.</span>
            </form>
        </div>

        <!-- Schedule Management Section -->
        <?php if (!empty($pendingProposals)): ?>
        <div class="mb-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-2xl font-semibold text-gray-800">
                    <i class="fas fa-calendar-check text-blue-600 mr-2"></i>
                    Pending Schedule Confirmations
                </h2>
                <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium">
                    <?= count($pendingProposals) ?> pending
                </span>
            </div>
            
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($pendingProposals as $proposal): ?>
                <div class="schedule-card <?= $proposal['status'] === 'user_confirmed' ? 'ready' : 'pending' ?> bg-white rounded-lg p-6 shadow-sm">
                    <!-- Header -->
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($proposal['case_number']) ?></h3>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($proposal['location']) ?></p>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium rounded-full 
                            <?= $proposal['status'] === 'user_confirmed' 
                                ? 'bg-green-100 text-green-800' 
                                : 'bg-yellow-100 text-yellow-800' ?>">
                            <?= $proposal['status'] === 'user_confirmed' ? 'Ready to Confirm' : 'Awaiting Participants' ?>
                        </span>
                    </div>

                    <!-- Schedule Details -->
                    <div class="space-y-2 mb-4">
                        <div class="flex items-center text-sm text-gray-700">
                            <i class="fas fa-calendar text-blue-500 w-4 mr-2"></i>
                            <?= date('F j, Y', strtotime($proposal['proposed_date'])) ?>
                        </div>
                        <div class="flex items-center text-sm text-gray-700">
                            <i class="fas fa-clock text-blue-500 w-4 mr-2"></i>
                            <?= date('g:i A', strtotime($proposal['proposed_time'])) ?>
                        </div>
                        <div class="flex items-center text-sm text-gray-700">
                            <i class="fas fa-user-tie text-blue-500 w-4 mr-2"></i>
                            <?= htmlspecialchars($proposal['presiding_officer']) ?>
                        </div>
                        <div class="flex items-center text-sm text-gray-700">
                            <i class="fas fa-user text-blue-500 w-4 mr-2"></i>
                            Proposed by <?= htmlspecialchars($proposal['proposed_by_name']) ?>
                        </div>
                    </div>

                    <!-- Participant Confirmation Progress -->
                    <?php if ($proposal['total_participants'] > 0): ?>
                    <div class="mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-medium text-gray-700">Participant Confirmations</span>
                            <span class="text-sm text-gray-600">
                                <?= $proposal['confirmed_participants'] ?>/<?= $proposal['total_participants'] ?>
                            </span>
                        </div>
                        <div class="confirmation-progress">
                            <div class="progress-bar <?= $proposal['confirmation_percentage'] >= 80 ? 'progress-high' : ($proposal['confirmation_percentage'] >= 50 ? 'progress-medium' : 'progress-low') ?>" 
                                 style="width: <?= $proposal['confirmation_percentage'] ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1"><?= $proposal['confirmation_percentage'] ?>% confirmed</p>
                    </div>
                    <?php endif; ?>

                    <!-- Case Description -->
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 line-clamp-2"><?= htmlspecialchars($proposal['description']) ?></p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex space-x-2">
                        <button onclick="confirmSchedule(<?= $proposal['id'] ?>, '<?= htmlspecialchars($proposal['case_number']) ?>')" 
                                class="action-button flex-1 bg-green-600 hover:bg-green-700 text-white py-2 px-3 rounded text-sm font-medium transition-all
                                       <?= $proposal['status'] !== 'user_confirmed' ? 'opacity-50 cursor-not-allowed' : '' ?>"
                                <?= $proposal['status'] !== 'user_confirmed' ? 'disabled' : '' ?>>
                            <i class="fas fa-check mr-1"></i>Confirm
                        </button>
                        <button onclick="rejectSchedule(<?= $proposal['id'] ?>, '<?= htmlspecialchars($proposal['case_number']) ?>')" 
                                class="action-button flex-1 bg-red-600 hover:bg-red-700 text-white py-2 px-3 rounded text-sm font-medium transition-all">
                            <i class="fas fa-times mr-1"></i>Reject
                        </button>
                        <button onclick="viewScheduleDetails(<?= $proposal['id'] ?>)" 
                                class="action-button bg-blue-600 hover:bg-blue-700 text-white py-2 px-3 rounded text-sm transition-all">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recently Confirmed Schedules -->
        <?php if (!empty($recentConfirmedSchedules)): ?>
        <div class="mb-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>
                Recently Confirmed Hearings
            </h2>
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Case</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hearing Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Confirmed</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recentConfirmedSchedules as $schedule): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($schedule['case_number']) ?></div>
                                    <div class="text-sm text-gray-500"><?= htmlspecialchars(substr($schedule['description'], 0, 50)) ?>...</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= date('F j, Y g:i A', strtotime($schedule['proposed_date'] . ' ' . $schedule['proposed_time'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?= $schedule['hearing_outcome'] === 'scheduled' 
                                            ? 'bg-blue-100 text-blue-800' 
                                            : 'bg-green-100 text-green-800' ?>">
                                        <?= $schedule['hearing_outcome'] ? ucfirst(str_replace('_', ' ', $schedule['hearing_outcome'])) : 'Scheduled' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('M j, Y', strtotime($schedule['captain_confirmed_at'])) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- User Management Section (existing code) -->
        <div class="bg-white rounded-lg shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800">
                    <i class="fas fa-users text-blue-600 mr-2"></i>
                    User Management
                </h2>
            </div>
            <!-- Add existing user management table here -->
        </div>
    </main>

    <script>
        // Schedule confirmation functions
        async function confirmSchedule(proposalId, caseNumber) {
            const { value: remarks } = await Swal.fire({
                title: `Confirm Hearing Schedule`,
                html: `
                    <div class="text-left mb-4">
                        <p class="text-gray-700 mb-3">You are about to confirm the hearing schedule for <strong>${caseNumber}</strong>.</p>
                        <p class="text-sm text-gray-600 mb-4">This will officially schedule the first hearing and notify all participants.</p>
                    </div>
                    <textarea id="captain-remarks" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" rows="3" placeholder="Add any instructions or remarks for participants (optional)..."></textarea>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Confirm Schedule',
                confirmButtonColor: '#10b981',
                cancelButtonText: 'Cancel',
                focusConfirm: false,
                preConfirm: () => {
                    return document.getElementById('captain-remarks').value;
                }
            });

            if (remarks !== undefined) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            schedule_action: 'confirm',
                            proposal_id: proposalId,
                            captain_remarks: remarks
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Schedule Confirmed!',
                            text: data.message,
                            confirmButtonText: 'OK'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        throw new Error(data.message);
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'Failed to confirm schedule'
                    });
                }
            }
        }

        async function rejectSchedule(proposalId, caseNumber) {
            const { value: formValues } = await Swal.fire({
                title: `Reject Hearing Schedule`,
                html: `
                    <div class="text-left mb-4">
                        <p class="text-gray-700 mb-3">You are rejecting the proposed hearing schedule for <strong>${caseNumber}</strong>.</p>
                        <p class="text-sm text-gray-600 mb-4">Please provide a reason and suggest alternative arrangements.</p>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Reason for rejection <span class="text-red-500">*</span></label>
                        <textarea id="rejection-reason" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" rows="3" placeholder="Explain why this schedule cannot be confirmed..." required></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Suggested alternatives</label>
                        <textarea id="alternative-suggestions" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" rows="3" placeholder="Suggest alternative dates, times, or arrangements..."></textarea>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Reject Schedule',
                confirmButtonColor: '#ef4444',
                cancelButtonText: 'Cancel',
                focusConfirm: false,
                preConfirm: () => {
                    const reason = document.getElementById('rejection-reason').value.trim();
                    if (!reason) {
                        Swal.showValidationMessage('Please provide a reason for rejection');
                        return false;
                    }
                    return {
                        reason: reason,
                        alternatives: document.getElementById('alternative-suggestions').value
                    };
                }
            });

            if (formValues) {
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            schedule_action: 'reject',
                            proposal_id: proposalId,
                            captain_remarks: `${formValues.reason}\n\nSuggested alternatives:\n${formValues.alternatives}`
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Schedule Rejected',
                            text: data.message,
                            confirmButtonText: 'OK'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        throw new Error(data.message);
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'Failed to reject schedule'
                    });
                }
            }
        }

        function viewScheduleDetails(proposalId) {
            // Implementation for viewing detailed schedule information
            Swal.fire({
                title: 'Schedule Details',
                text: 'Detailed view implementation coming soon...',
                icon: 'info'
            });
        }

        // Auto-refresh every 2 minutes to check for new proposals
        setInterval(function() {
            const currentProposalCount = <?= count($pendingProposals) ?>;
            
            fetch('../api/scheduling_api.php?action=get_admin_notifications')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.count !== currentProposalCount) {
                        // Show notification of new proposals
                        const notification = document.createElement('div');
                        notification.innerHTML = `
                            <div style="position: fixed; top: 20px; right: 20px; background: #3b82f6; color: white; 
                                       padding: 1rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); 
                                       z-index: 1001; cursor: pointer;" onclick="location.reload()">
                                <i class="fas fa-calendar-plus"></i> New schedule proposals available. Click to refresh.
                            </div>
                        `;
                        document.body.appendChild(notification);
                        
                        setTimeout(() => {
                            if (notification.parentNode) {
                                notification.parentNode.removeChild(notification);
                            }
                        }, 10000);
                    }
                })
                .catch(error => console.log('Notification check failed:', error));
        }, 120000); // 2 minutes

        // Display success/error messages
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire('Success!', '<?= addslashes($_SESSION['success_message']) ?>', 'success');
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            Swal.fire('Error!', '<?= addslashes($_SESSION['error_message']) ?>', 'error');
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
    </script>
</body>
</html>
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
        echo json_encode(['success' => false, 'message' => 'Unauthorized to modify this user']);
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
        echo json_encode(['success' => false, 'message' => 'Unauthorized to delete this user']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Delete related records
        $pdo->prepare("
            DELETE FROM document_request_attributes 
            WHERE request_id IN (
                SELECT dr.id FROM document_requests dr 
                JOIN persons p ON dr.person_id = p.id 
                WHERE p.user_id = ?
            )
        ")->execute([$userId]);

        $pdo->prepare("
            DELETE FROM document_requests 
            WHERE person_id IN (
                SELECT id FROM persons WHERE user_id = ?
            )
        ")->execute([$userId]);

        $pdo->prepare("
            DELETE FROM addresses 
            WHERE person_id IN (
                SELECT id FROM persons WHERE user_id = ?
            )
        ")->execute([$userId]);

        $pdo->prepare("
            UPDATE blotter_participants
            SET person_id = NULL
            WHERE person_id IN (
                SELECT id FROM persons WHERE user_id = ?
            )
        ")->execute([$userId]);

        $pdo->prepare("
            UPDATE monthly_reports
            SET prepared_by_user_id = NULL
            WHERE prepared_by_user_id = ?
        ")->execute([$userId]);

        $pdo->prepare("
            DELETE FROM audit_trails
            WHERE user_id = ?
        ")->execute([$userId]);

        $pdo->prepare("
            DELETE FROM events
            WHERE created_by_user_id = ?
        ")->execute([$userId]);

        $pdo->prepare("DELETE FROM persons WHERE user_id = ?")
            ->execute([$userId]);

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $result = $stmt->execute([$userId]);

        if ($result) {
            $pdo->commit();
            echo json_encode(['success' => true]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
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
           CASE
             WHEN u.role_id IN ($placeholders) THEN
                  IF(u.start_term_date <= CURDATE() AND
                     (u.end_term_date IS NULL OR u.end_term_date >= CURDATE()),
                     'active','inactive')
             ELSE 'N/A'
           END AS term_status
      FROM users u
      JOIN roles r      ON r.id     = u.role_id
      JOIN barangay b  ON b.id = u.barangay_id
     WHERE u.role_id IN ($placeholders)
       AND u.barangay_id = ?
     ORDER BY u.role_id, u.last_name, u.first_name
");
$executeParams = array_merge($officialRoles, $officialRoles, [$bid]);
$stmt->execute($executeParams);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Add User Management Table HTML here if needed -->
<div class="mt-8 bg-white rounded-lg shadow-sm">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-800">Barangay Officials</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profile</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Term Period</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200" id="userTable">
                <?php foreach ($users as $user): ?>
                <tr data-id="<?= htmlspecialchars($user['id']) ?>">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <img src="../uploads/staff_pics/<?= htmlspecialchars($user['id_image_path'] ?? 'default.png') ?>" 
                             class="w-10 h-10 rounded-full object-cover" alt="Profile">
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?= htmlspecialchars($user['role_name']) ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?= $user['start_term_date'] 
                            ? htmlspecialchars(date('M j, Y', strtotime($user['start_term_date']))) . ' - ' . 
                                ($user['end_term_date'] 
                                    ? htmlspecialchars(date('M j, Y', strtotime($user['end_term_date']))) 
                                    : 'Present')
                            : 'N/A' ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($user['term_status'] === 'active'): ?>
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                        <?php elseif ($user['term_status'] === 'inactive'): ?>
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">Inactive</span>
                        <?php else: ?>
                            <span class="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap space-x-2">
                        <button onclick="openModal('edit', <?= $user['id'] ?>, <?= $user['role_id'] ?>)" 
                                class="text-purple-600 hover:text-purple-900">Edit</button>
                        <button onclick="toggleStatus(<?= $user['id'] ?>, '<?= $user['is_active'] ? 'deactivate' : 'activate' ?>')" 
                                class="text-blue-600 hover:text-blue-900">
                            <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                        <button onclick="deleteUser(<?= $user['id'] ?>)" 
                                class="text-red-600 hover:text-red-900">Delete</button>
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

async function toggleStatus(userId, action) {
    try {
        const response = await fetch(`?toggle_status=1&user_id=${userId}&action=${action}`);
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Failed to update status');

        const row = document.querySelector(`tr[data-id="${userId}"]`);
        if (row) {
            const statusBadge = row.querySelector('td:nth-child(5) span');
            const button = row.querySelector('button.text-blue-600');
            const isActive = data.newStatus === 'yes';

            if (statusBadge) {
                statusBadge.className = isActive ? 
                    'px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800' : 
                    'px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800';
                statusBadge.textContent = isActive ? 'Active' : 'Inactive';
            }

            if (button) {
                button.textContent = isActive ? 'Deactivate' : 'Activate';
                button.onclick = () => toggleStatus(userId, isActive ? 'deactivate' : 'activate');
            }
        }
        Swal.fire('Success!', `User ${action}d successfully`, 'success');
    } catch (error) {
        Swal.fire('Error', error.message || 'Could not update status', 'error');
    }
}

async function deleteUser(id) {
    const result = await Swal.fire({
        title: 'Delete user?',
        text: 'This cannot be undone!',
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
        if (!data.success) throw new Error(data.message || 'Delete failed');
        
        document.querySelector(`tr[data-id="${id}"]`)?.remove();
        Swal.fire('Deleted!', 'User has been deleted.', 'success');
    } catch (error) {
        Swal.fire('Error', error.message, 'error');
    }
}
</script>