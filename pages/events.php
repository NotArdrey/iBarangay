<?php
/* pages/events.php – fully working version with loading animation
   ─────────────────────────────────────────────────────────────── */
require __DIR__ . '/../vendor/autoload.php';
require_once "../functions/notification_helper.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) session_start();

/* dependencies */
require __DIR__ . '/../config/dbconn.php';


$user_id = $_SESSION['user_id']     ?? null;
$bid     = $_SESSION['barangay_id'] ?? null;
$role_id = $_SESSION['role_id'] ?? null;

if (!$user_id || !$bid) {
    header('Location: ../pages/login.php');
    exit;
}

// Check if user can manage events (admins only)
$canManageEvents = $role_id >= 2 && $role_id <= 7;

/* helpers */
function logAuditTrail(
    PDO $pdo,
    int $adminId,
    string $action,
    string $table,
    int $recordId,
    string $desc = ''
): void {
    // Fix: Always set user_id (required, NOT NULL) and admin_user_id
    $pdo->prepare(
        "INSERT INTO audit_trails
             (user_id, admin_user_id, action, table_name, record_id, description)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $adminId,
        $adminId,
        $action,
        $table,
        $recordId,
        $desc
    ]);
}

function sendEventEmails(PDO $pdo, array $event, int $barangayId, string $type): bool
{
    try {
        // Include the email template functions
        require_once __DIR__ . '/../functions/email_template.php';
        
        // Get target roles from event data
        $targetRoles = !empty($event['target_roles']) ? explode(',', $event['target_roles']) : [];
        
        // Clean up roles (remove any whitespace)
        $targetRoles = array_map('trim', $targetRoles);
        $targetRoles = array_filter($targetRoles); // Remove empty values
        
        if (empty($targetRoles)) {
            // Default to all roles if none specified
            $targetRoles = [
                'barangay_captain', 'barangay_secretary', 'barangay_treasurer', 
                'barangay_councilor', 'barangay_chairperson', 'resident', 'health_worker'
            ];
        }
        
        error_log('Target roles: ' . implode(', ', $targetRoles));
        
        // Build WHERE clause for roles
        $rolePlaceholders = str_repeat('?,', count($targetRoles) - 1) . '?';
        
        // Get users with specified roles in the barangay
        $query = "SELECT DISTINCT u.id, u.email, u.role 
                  FROM users u 
                  WHERE u.barangay_id = ? 
                  AND u.role IN ($rolePlaceholders)
                  AND u.email IS NOT NULL 
                  AND u.email != ''";
        
        $stmt = $pdo->prepare($query);
        
        // Create parameters array: barangay_id first, then all roles
        $params = array_merge([$barangayId], $targetRoles);
        
        error_log('Query: ' . $query);
        error_log('Parameters: ' . json_encode($params));
        
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log('Found ' . count($users) . ' users to notify');
        
        if (!$users) {
            error_log('No users found for email notification');
            return true; // Not a critical error
        }

        // Prepare event details for template
        $eventDetails = "
            <strong>Title:</strong> " . htmlspecialchars($event['title']) . "<br>
            <strong>Description:</strong> " . nl2br(htmlspecialchars($event['description'])) . "<br>
            <strong>Start:</strong> " . date('M d, Y h:i A', strtotime($event['start_datetime'])) . "<br>
            <strong>End:</strong> " . date('M d, Y h:i A', strtotime($event['end_datetime'])) . "<br>
            <strong>Location:</strong> " . htmlspecialchars($event['location']) . "<br>";
        
        if (!empty($event['organizer'])) {
            $eventDetails .= "<strong>Organizer:</strong> " . htmlspecialchars($event['organizer']) . "<br>";
        }

        // Send email notifications
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ibarangay.system@gmail.com';
        $mail->Password   = 'nxxn vxyb kxum cuvd';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('ibarangay.system@gmail.com', 'iBarangay System');
        $mail->isHTML(true);

        $emailsSent = 0;
        $failedEmails = [];
        
        foreach ($users as $user) {
            if (empty($user['email'])) continue;
            
            try {
                $mail->clearAllRecipients();
                $mail->addAddress($user['email']);
                
                // Use proper template function
                $isPostponed = ($type === 'postponed');
                $mail->Subject = $isPostponed ? "Event Postponed: {$event['title']}" : "New Event: {$event['title']}";
                $mail->Body = getEventNotificationTemplate($event['title'], $eventDetails, $isPostponed);
                
                // Create plain text version
                $plainText = ($isPostponed ? "Event Postponed: " : "New Event: ") . $event['title'] . "\n\n";
                $plainText .= ($isPostponed ? "The following event has been postponed:\n\n" : "A new event has been scheduled:\n\n");
                $plainText .= "Title: " . $event['title'] . "\n";
                $plainText .= "Description: " . $event['description'] . "\n";
                $plainText .= "Start: " . date('M d, Y h:i A', strtotime($event['start_datetime'])) . "\n";
                $plainText .= "End: " . date('M d, Y h:i A', strtotime($event['end_datetime'])) . "\n";
                $plainText .= "Location: " . $event['location'] . "\n";
                if (!empty($event['organizer'])) {
                    $plainText .= "Organizer: " . $event['organizer'] . "\n";
                }
                
                $mail->AltBody = $plainText;
                
                if ($mail->send()) {
                    $emailsSent++;
                    error_log("Email sent successfully to: {$user['email']} (Role: {$user['role']})");
                } else {
                    $failedEmails[] = $user['email'];
                    error_log("Failed to send email to: {$user['email']} (Role: {$user['role']})");
                }
                
            } catch (Exception $e) {
                $failedEmails[] = $user['email'];
                error_log('Failed to send email to ' . $user['email'] . ': ' . $e->getMessage());
            }
        }
        
        error_log("Email summary: Sent $emailsSent emails out of " . count($users) . " users");
        if (!empty($failedEmails)) {
            error_log("Failed emails: " . implode(', ', $failedEmails));
        }
        
        return $emailsSent > 0; // Return true if at least one email was sent
        
    } catch (Exception $e) {
        error_log('Email sending error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return false;
    }
}

/* POST handler */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = (int)($_POST['event_id'] ?? 0);

    /* postpone + delete */
    if (isset($_POST['delete']) && $event_id) {
        $stmt = $pdo->prepare(
            "SELECT * FROM events WHERE id = ? AND barangay_id = ?"
        );
        $stmt->execute([$event_id, $bid]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($event) {
            sendEventEmails($pdo, $event, $bid, 'postponed');

            $pdo->prepare("DELETE FROM events WHERE id = ? AND barangay_id = ?")
                ->execute([$event_id, $bid]);
            logAuditTrail($pdo, $user_id, 'DELETE', 'events', $event_id, 'Event postponed & deleted');
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Event Postponed',
                'message' => 'The event has been postponed and residents have been notified.'
            ];

        } else {
            $_SESSION['alert'] = [
                'type' => 'error',
                'title' => 'Error',
                'message' => 'Event not found.'
            ];
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    /* create / update */
    $title       = trim($_POST['title']        ?? '');
    $description = trim($_POST['description']  ?? '');
    $startRaw    =       $_POST['start_datetime'] ?? '';
    $endRaw      =       $_POST['end_datetime']   ?? '';
    $location    = trim($_POST['location']     ?? '');
    $organizer   = trim($_POST['organizer']    ?? '');
    $targetRoles = $_POST['target_roles'] ?? [];

    $errors = [];
    if ($title === '')    $errors[] = 'Title is required';
    if ($startRaw === '') $errors[] = 'Start date/time is required';
    if ($endRaw === '')   $errors[] = 'End date/time is required';
    if ($location === '') $errors[] = 'Location is required';
    if (empty($targetRoles)) $errors[] = 'At least one target role must be selected';
    if (strlen($title) > 100)                 $errors[] = 'Title max 100 chars';
    if (strlen($location) > 150)              $errors[] = 'Location max 150 chars';
    if ($organizer !== '' && strlen($organizer) > 100)
        $errors[] = 'Organizer max 100 chars';

    $startDT = DateTime::createFromFormat('Y-m-d\TH:i', $startRaw)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $startRaw);
    $endDT   = DateTime::createFromFormat('Y-m-d\TH:i', $endRaw)
        ?: DateTime::createFromFormat('Y-m-d H:i:s', $endRaw);

    if (!$startDT) $errors[] = 'Invalid start date/time';
    if (!$endDT)   $errors[] = 'Invalid end date/time';
    if ($startDT && $startDT < new DateTime)        $errors[] = 'Start must be in future';
    if ($startDT && $endDT && $endDT <= $startDT)   $errors[] = 'End must be after start';

    if ($errors) {
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Validation Error',
            'message' => implode('<br>', $errors)
        ];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $start_datetime = $startDT->format('Y-m-d H:i:s');
    $end_datetime   = $endDT->format('Y-m-d H:i:s');
    $targetRolesStr = implode(',', $targetRoles);

    try {
        // Check if target_roles column exists, add it if it doesn't
        $columnCheck = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_roles'");
        if ($columnCheck->rowCount() == 0) {
            $pdo->exec("ALTER TABLE events ADD COLUMN target_roles TEXT");
        }

        // Begin transaction for database operations
        $pdo->beginTransaction();

        if ($event_id) { /* update */
            $pdo->prepare(
                "UPDATE events
                    SET title = ?, description = ?, start_datetime = ?, end_datetime = ?,
                        location = ?, organizer = ?, target_roles = ?
                  WHERE id = ? AND barangay_id = ?"
            )->execute([
                $title, $description, $start_datetime, $end_datetime,
                $location, $organizer, $targetRolesStr, $event_id, $bid
            ]);
            
            // Commit database transaction first
            $pdo->commit();
            
            logAuditTrail($pdo, $user_id, 'UPDATE', 'events', $event_id, 'Event updated');
            
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Success',
                'message' => 'Event has been updated successfully.'
            ];
        } else {                  /* insert */
            $pdo->prepare(
                "INSERT INTO events
                        (title, description, start_datetime, end_datetime,
                        location, organizer, target_roles, barangay_id, created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                $title, $description, $start_datetime, $end_datetime,
                $location, $organizer, $targetRolesStr, $bid, $user_id
            ]);

            $newId = (int)$pdo->lastInsertId();
            
            // Commit database transaction first
            $pdo->commit();
            
            logAuditTrail($pdo, $user_id, 'INSERT', 'events', $newId, 'Event created');
            
            // Get the event data for email sending
            $evt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
            $evt->execute([$newId]);
            $eventData = $evt->fetch(PDO::FETCH_ASSOC);

            // Send emails after database operations are complete
            $emailResult = sendEventEmails($pdo, $eventData, $bid, 'new');
            
            if ($emailResult) {
                $_SESSION['alert'] = [
                    'type' => 'success',
                    'title' => 'Success',
                    'message' => 'Event has been created and residents have been notified.'
                ];
            } else {
                $_SESSION['alert'] = [
                    'type' => 'warning',
                    'title' => 'Event Created',
                    'message' => 'Event has been created but some email notifications may have failed.'
                ];
            }
        }
    } catch (PDOException $e) {
        // Rollback transaction on database error
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        
        error_log('DB error: ' . $e->getMessage());
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Database Error',
            'message' => 'Failed to save event: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        // Rollback transaction on any other error
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        
        error_log('General error: ' . $e->getMessage());
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Error',
            'message' => 'An unexpected error occurred: ' . $e->getMessage()
        ];
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* fetch events */
try {
    // Check if target_roles column exists, add it if it doesn't
    $columnCheck = $pdo->query("SHOW COLUMNS FROM events LIKE 'target_roles'");
    if ($columnCheck->rowCount() == 0) {
        $pdo->exec("ALTER TABLE events ADD COLUMN target_roles TEXT");
    }

    $stmt = $pdo->prepare("
        SELECT 
            e.*, 
            p.first_name AS creator_first_name, 
            p.last_name AS creator_last_name
        FROM events e
        LEFT JOIN users u ON e.created_by_user_id = u.id
        LEFT JOIN persons p ON u.id = p.user_id
        WHERE e.barangay_id = :bid 
        AND e.status != 'deleted'
        ORDER BY e.start_datetime DESC
    ");
    $stmt->execute([':bid' => $bid]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('DB error fetching events: ' . $e->getMessage());
    $events = [];
}

// Define available roles
$availableRoles = [
    'barangay_captain' => 'Barangay Captain',
    'barangay_secretary' => 'Barangay Secretary', 
    'barangay_treasurer' => 'Barangay Treasurer',
    'barangay_councilor' => 'Barangay Councilor',
    'barangay_chairperson' => 'Barangay Chairperson',
    'resident' => 'Resident',
    'health_worker' => 'Health Worker'
];

require __DIR__ . '/../components/header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.0/flowbite.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    /* Fix for SweetAlert2 button being invisible due to Tailwind/Flowbite */
    .swal2-confirm {
        background-color: #3085d6 !important;
        color: #fff !important;
        border: none !important;
        box-shadow: none !important;
        border-radius: 0.25rem !important;
        padding: 0.625em 2em !important;
        font-size: 1.0625em !important;
    }
    </style>
</head>

<body class="bg-gray-50">
    <main class="ml-0 lg:ml-64 p-4 md:p-8 space-y-6">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <h1 class="text-3xl font-bold text-blue-800">Event Management</h1>
            <?php if ($canManageEvents): ?>
            <button onclick="toggleModal()" class="w-full md:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5">+ Add New Event</button>
            <?php endif; ?>
        </div>

        <?php if (isset($_SESSION['alert'])): ?>
        <script>
            Swal.fire({
                icon: '<?= $_SESSION['alert']['type'] ?>',
                title: '<?= $_SESSION['alert']['title'] ?>',
                html: '<?= $_SESSION['alert']['message'] ?>',
                confirmButtonColor: '#3085d6'
            });
        </script>
        <?php unset($_SESSION['alert']); endif; ?>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">End</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organizer</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Target Roles</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if ($events): ?>
                            <?php foreach ($events as $event): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($event['title']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="font-medium"><?= date('M d, Y', strtotime($event['start_datetime'])) ?></span>
                                            <span class="text-gray-600"><?= date('h:i A', strtotime($event['start_datetime'])) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                                        <div class="flex flex-col">
                                            <span class="font-medium"><?= date('M d, Y', strtotime($event['end_datetime'])) ?></span>
                                            <span class="text-gray-600"><?= date('h:i A', strtotime($event['end_datetime'])) ?></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($event['location']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($event['organizer'] ?? 'N/A') ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?= htmlspecialchars(trim(($event['creator_first_name'] ?? '') . ' ' . ($event['creator_last_name'] ?? ''))) ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php 
                                        $eventRoles = !empty($event['target_roles']) ? explode(',', $event['target_roles']) : [];
                                        $roleNames = array_map(function($role) use ($availableRoles) {
                                            return $availableRoles[$role] ?? $role;
                                        }, $eventRoles);
                                        echo htmlspecialchars(implode(', ', $roleNames));
                                        ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= nl2br(htmlspecialchars($event['description'] ?? '')) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <div class="flex items-center space-x-3">
                                            <?php if ($canManageEvents): ?>
                                            <button onclick="editEvent(<?= $event['id'] ?>)" class="p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50">Edit</button>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                                                <input type="hidden" name="delete" value="1">
                                                <button type="button" onclick="confirmDelete(this.form)" class="p-2 text-red-600 hover:text-red-900 rounded-lg hover:bg-red-50">Delete</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="px-4 py-4 text-center text-gray-500">No events found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="eventModal" tabindex="-1" class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto h-[calc(100%-1rem)] max-h-full">
            <div class="relative w-full max-w-2xl max-h-full mx-auto">
                <div class="relative bg-white rounded-lg shadow">
                    <div class="flex items-start justify-between p-5 border-b rounded-t">
                        <h3 class="text-xl font-semibold text-gray-900" id="modalTitle">New Event</h3>
                        <button onclick="toggleModal()" class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center">X</button>
                    </div>
                    <form method="POST" class="p-6 space-y-4" onsubmit="showLoading()">
                        <input type="hidden" name="event_id" id="eventId">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Event Title <span class="text-red-500">*</span></label>
                                <input type="text" name="title" required class="w-full rounded border-gray-300" placeholder="Community Meeting">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Start <span class="text-red-500">*</span></label>
                                <input type="datetime-local" name="start_datetime" required class="w-full rounded border-gray-300">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">End <span class="text-red-500">*</span></label>
                                <input type="datetime-local" name="end_datetime" required class="w-full rounded border-gray-300">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Location <span class="text-red-500">*</span></label>
                                <input type="text" name="location" required class="w-full rounded border-gray-300" placeholder="Barangay Hall">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-700">Organizer</label>
                                <input type="text" name="organizer" class="w-full rounded border-gray-300" placeholder="Optional">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Target Recipients <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-2 gap-2 p-4 border border-gray-300 rounded-lg bg-gray-50">
                                <?php foreach ($availableRoles as $role => $label): ?>
                                    <label class="flex items-center space-x-2 text-sm">
                                        <input type="checkbox" name="target_roles[]" value="<?= $role ?>" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span><?= $label ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-sm text-gray-500">Select one or more roles to notify</p>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea name="description" rows="3" class="w-full rounded border-gray-300" placeholder="Enter event details..."></textarea>
                        </div>
                        <div class="flex items-center justify-end pt-6 space-x-3 border-t border-gray-200">
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Event</button>
                            <button type="button" onclick="toggleModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        function showLoading() {
            Swal.fire({
                title: 'Sending emails...',
                text: 'Please wait while notifications are being sent.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        function toggleModal(eventId = null) {
            const modal = document.getElementById('eventModal');
            modal.classList.toggle('hidden');
            if (eventId) {
                <?php foreach ($events as $e): ?>
                    if (<?= $e['id'] ?> === eventId) {
                        document.getElementById('eventId').value = <?= $e['id'] ?>;
                        document.querySelector('[name="title"]').value = '<?= addslashes($e['title']) ?>';
                        document.querySelector('[name="start_datetime"]').value = '<?= str_replace(' ', 'T', $e['start_datetime']) ?>';
                        document.querySelector('[name="end_datetime"]').value = '<?= str_replace(' ', 'T', $e['end_datetime']) ?>';
                        document.querySelector('[name="location"]').value = '<?= addslashes($e['location']) ?>';
                        document.querySelector('[name="organizer"]').value = '<?= addslashes($e['organizer']) ?>';
                        document.querySelector('[name="description"]').value = '<?= addslashes($e['description']) ?>';
                        
                        // Set selected roles for checkboxes
                        const targetRoles = '<?= $e['target_roles'] ?? '' ?>'.split(',').filter(role => role.trim());
                        const roleCheckboxes = document.querySelectorAll('[name="target_roles[]"]');
                        roleCheckboxes.forEach(checkbox => {
                            checkbox.checked = targetRoles.includes(checkbox.value);
                        });
                        
                        document.getElementById('modalTitle').textContent = 'Edit Event';
                    }
                <?php endforeach; ?>
            } else {
                document.getElementById('eventId').value = '';
                document.querySelector('form').reset();
                document.getElementById('modalTitle').textContent = 'New Event';
            }
        }

        function editEvent(id) {
            toggleModal(id);
        }

        function confirmDelete(form) {
            Swal.fire({
                title: 'Postpone Event?',
                text: "Residents will be notified and the event will be removed.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, Postpone',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading();
                    form.submit();
                }
            });
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.0/flowbite.min.js"></script>
</body>

</html>

