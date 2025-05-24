<?php
/* add_staff_official_barangaycaptian.php – full page */
session_start();
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ────────────── CSRF seed & headers ────────────── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

require '../config/dbconn.php';
require __DIR__ . '/../vendor/autoload.php';

/* ────────────── Role constants  (match DB!) ─────── */
const ROLE_PROGRAMMER   = 1;
const ROLE_SUPER_ADMIN  = 2;
const ROLE_CAPTAIN      = 3;
const ROLE_SECRETARY    = 4;
const ROLE_TREASURER    = 5;
const ROLE_COUNCILOR    = 6;
const ROLE_CHIEF        = 7;
const ROLE_RESIDENT     = 8;

/* ───────── Helper: Count overlapping terms ───────── */
function overlapCount(PDO $pdo, int $roleId, int $barangayId, string $start, string $end, int $excludeUser = 0): int {
    $sql = "
        SELECT COUNT(*) FROM users
        WHERE role_id = :role
          AND barangay_id = :bid
          AND user_id <> :uid
          AND (
               (start_term_date <= :end AND (end_term_date IS NULL OR end_term_date >= :start))
          )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':role'  => $roleId,
        ':bid'   => $barangayId,
        ':start' => $start,
        ':end'   => $end,
        ':uid'   => $excludeUser
    ]);
    return (int)$stmt->fetchColumn();
}

/* ─────── Helper: Check councilor limit ─────── */
function maxCouncilorsReached(PDO $pdo, int $barangayId, string $start, string $end, int $excludeUser = 0): bool {
    $sql = "
        SELECT COUNT(*) FROM users
        WHERE role_id = :councilor
          AND barangay_id = :bid
          AND user_id <> :uid
          AND (
               (start_term_date <= :end AND (end_term_date IS NULL OR end_term_date >= :start))
          )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':councilor' => ROLE_COUNCILOR,
        ':bid'       => $barangayId,
        ':start'     => $start,
        ':end'       => $end,
        ':uid'       => $excludeUser
    ]);
    return ((int)$stmt->fetchColumn() >= 7);
}

/* ────────────── Auth guard ─────────────────────── */
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: ../pages/login.php');
    exit;
}
$stmt = $pdo->prepare('SELECT role_id, barangay_id FROM users WHERE user_id = ?');
$stmt->execute([$user_id]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$userInfo || (int)$userInfo['role_id'] !== ROLE_CAPTAIN) { // Only Barangay Captain allowed
    if ($isAjax) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
    } else {
        header('Location: ../pages/login.php');
    }
    exit;
}
$bid = $userInfo['barangay_id']; // Barangay ID for the Barangay Captain

/* ────────────── Toggle status & Delete user AJAX … ────── */
if (isset($_GET['toggle_status'])) {
    $userId = (int)$_GET['user_id'];
    $action = $_GET['action'];

    if (!in_array($action, ['activate', 'deactivate'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    // Verify user belongs to captain's barangay
    $checkStmt = $pdo->prepare("SELECT barangay_id FROM users WHERE user_id = ?");
    $checkStmt->execute([$userId]);
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkResult || $checkResult['barangay_id'] != $bid) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized to modify this user']);
        exit;
    }

    $newStatus = $action === 'activate' ? 'yes' : 'no';

    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    if ($stmt->execute([$newStatus, $userId])) {
        echo json_encode(['success' => true, 'newStatus' => $newStatus]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update status']);
    }
    exit;
}

/* ────────────── Delete user ────── */
if (isset($_GET['delete_id'])) {
    $userId = (int)$_GET['delete_id'];
    
    // Verify user belongs to captain's barangay
    $checkStmt = $pdo->prepare("SELECT barangay_id FROM users WHERE user_id = ?");
    $checkStmt->execute([$userId]);
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$checkResult || $checkResult['barangay_id'] != $bid) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized to delete this user']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        /* 1. child rows that must disappear */
        // document requests + their attributes
        $pdo->prepare("
            DELETE dr, dra
              FROM DocumentRequest dr
         LEFT JOIN DocumentRequestAttribute dra
                ON dra.request_id = dr.document_request_id
             WHERE dr.user_id = ?
        ")->execute([$userId]);

        // addresses
        $pdo->prepare("DELETE FROM Address WHERE user_id = ?")
            ->execute([$userId]);

        // blotter participants – keep record, detach user
        $pdo->prepare("
            UPDATE BlotterParticipant
               SET user_id = NULL
             WHERE user_id = ?
        ")->execute([$userId]);

        // monthly reports
        $pdo->prepare("
            UPDATE MonthlyReport
               SET prepared_by = NULL
             WHERE prepared_by = ?
        ")->execute([$userId]);

        // audit-trail
        $pdo->prepare("
            DELETE FROM AuditTrail
                  WHERE admin_user_id = ?
        ")->execute([$userId]);

        // events created by this user
        $pdo->prepare("
            DELETE FROM events
                  WHERE created_by = ?
        ")->execute([$userId]);

        /* 2. finally, remove the user */
        $stmt   = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
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

/* ────────────── fetch list for page render ─────── */
$officialRoles = [ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR, ROLE_CHIEF];
$rolePlaceholders = implode(',', $officialRoles);
$stmt = $pdo->prepare("
    SELECT u.*, r.role_name, b.barangay_name,
           CASE
             WHEN u.role_id IN ($rolePlaceholders) THEN
                  IF(u.start_term_date <= CURDATE() AND
                     (u.end_term_date IS NULL OR u.end_term_date >= CURDATE()),
                     'active','inactive')
             ELSE 'N/A'
           END AS term_status
      FROM users u
      JOIN Role r      ON r.role_id     = u.role_id
      JOIN barangay b  ON b.barangay_id = u.barangay_id
     WHERE u.role_id IN ($rolePlaceholders)
       AND u.barangay_id = ?
     ORDER BY u.role_id, u.last_name, u.first_name
");
$stmt->execute([$bid]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────── Fetch single user (AJAX) ──────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get'])) {
    $userId = (int)$_GET['get'];
    $stmt = $pdo->prepare("
        SELECT u.*, r.role_name, b.barangay_name
        FROM users u
        JOIN Role r ON r.role_id = u.role_id
        JOIN barangay b ON b.barangay_id = u.barangay_id
        WHERE u.user_id = ?
        AND u.barangay_id = ?
    ");
    $stmt->execute([$userId, $bid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    exit;
}

/* ──────────────── Add / Edit ──────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_event_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: add_staff_official_barangaycaptian.php');
        exit;
    }

    $action      = $_POST['action'] ?? '';
    $uid         = (int)($_POST['user_id'] ?? 0);
    $firstName   = htmlspecialchars(trim($_POST['first_name'] ?? ''));
    $lastName    = htmlspecialchars(trim($_POST['last_name'] ?? ''));
    $email       = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password    = $_POST['password'] ?? '';
    $roleId      = (int)($_POST['role_id'] ?? 0);
    $barangayId  = $bid; // Always use captain's barangay_id
    $startTerm   = $_POST['start_term_date'] ?? '';
    $endTerm     = $_POST['end_term_date'] ?? '';
    $isOfficial  = in_array($roleId, [ROLE_SECRETARY, ROLE_TREASURER, ROLE_CHIEF, ROLE_COUNCILOR], true);
    $error       = null;

    /* Validate role */
    $allowedRoles = [ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR, ROLE_CHIEF];
    if (!in_array($roleId, $allowedRoles)) {
        $error = 'Invalid role selected';
    }

    /* Validate email */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id <> ?");
        $dup->execute([$email, $uid]);
        if ($dup->fetchColumn() > 0) $error = 'Email already in use';
    }

    /* Auto-set term dates & overlap checks */
    if ($isOfficial && !$error) {
        if (empty($startTerm)) {
            $startTerm = date('Y-m-d');
        }
        if (empty($endTerm)) {
            $endTerm = date('Y-m-d', strtotime('+36 months', strtotime($startTerm)));
        }
        if ($endTerm < $startTerm) {
            $error = 'End term cannot be before start term';
        }
        if (!$error) {
            // Overlap for single officers
            if (in_array($roleId, [ROLE_SECRETARY, ROLE_TREASURER, ROLE_CHIEF], true)) {
                if (overlapCount($pdo, $roleId, $barangayId, $startTerm, $endTerm, $uid) > 0) {
                    $error = 'Another officer with this role already serves during the selected period';
                }
            }
            // Councilor limit
            if ($roleId === ROLE_COUNCILOR && maxCouncilorsReached($pdo, $barangayId, $startTerm, $endTerm, $uid)) {
                $error = 'Councilor limit (7) reached for the selected period';
            }
        }
    }

    /* ---------- Handle file uploads ---------- */
    $profilePic = null;
    if (!empty($_FILES['profile_pic']['tmp_name'])) {
        $uploadDir = __DIR__ . "/../uploads/staff_pics/";
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $profilePic = uniqid('prof_') . ".$ext";
            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $profilePic)) {
                $error = 'Failed to upload profile picture';
            }
        } else {
            $error = 'Invalid profile picture format';
        }
    }
    
    $signaturePic = null;
    if ($isOfficial && !empty($_FILES['signature_pic']['tmp_name'])) {
        $uploadDir = __DIR__ . "/../uploads/signatures/";
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['signature_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png'], true)) {
            $signaturePic = uniqid('sign_') . ".$ext";
            if (!move_uploaded_file($_FILES['signature_pic']['tmp_name'], $uploadDir . $signaturePic)) {
                $error = 'Failed to upload signature';
            }
        } else {
            $error = 'Invalid signature format';
        }
    }

    /* ---------- Save to DB ---------- */
    if (!$error) {
        try {
            $pdo->beginTransaction();
            
            if ($action === 'add') {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (
                            email, password_hash, first_name, last_name, 
                            role_id, barangay_id, is_active, isverify,
                            start_term_date, end_term_date,
                            id_image_path, signature_image_path
                        ) VALUES (
                            ?, ?, ?, ?, 
                            ?, ?, 'yes', 'yes',
                            ?, ?,
                            ?, ?
                        )";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $email, $passwordHash, $firstName, $lastName,
                    $roleId, $barangayId,
                    $isOfficial ? $startTerm : null,
                    $isOfficial ? $endTerm : null,
                    $profilePic ?: 'default.png',
                    $signaturePic
                ]);
            } else {
                // Verify user belongs to captain's barangay
                $checkStmt = $pdo->prepare("SELECT barangay_id FROM Users WHERE user_id = ?");
                $checkStmt->execute([$uid]);
                $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$checkResult || $checkResult['barangay_id'] != $bid) {
                    $error = 'Unauthorized to modify this user';
                    throw new Exception($error);
                }
                
                // Updates for existing user
                $sqlParts = [
                    "email = ?",
                    "first_name = ?",
                    "last_name = ?",
                    "role_id = ?",
                    "start_term_date = " . ($isOfficial ? "?" : "NULL"),
                    "end_term_date = " . ($isOfficial ? "?" : "NULL")
                ];
                
                $params = [
                    $email, $firstName, $lastName, $roleId
                ];
                
                if ($isOfficial) {
                    $params[] = $startTerm;
                    $params[] = $endTerm;
                }
                
                // Add profile picture update if provided
                if ($profilePic) {
                    $sqlParts[] = "id_image_path = ?";
                    $params[] = $profilePic;
                }
                
                // Add signature picture update if provided
                if ($signaturePic) {
                    $sqlParts[] = "signature_image_path = ?";
                    $params[] = $signaturePic;
                }
                
                // Add password update if provided
                if (!empty($password)) {
                    $sqlParts[] = "password_hash = ?";
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                
                // Add user_id to params for WHERE clause
                $params[] = $uid;
                
                $sql = "UPDATE Users SET " . implode(", ", $sqlParts) . " WHERE user_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
            
            $pdo->commit();
            $_SESSION['success'] = ($action === 'add')
                ? 'Official added successfully'
                : 'User updated successfully';
            header('Location: add_staff_official_barangaycaptian.php');
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error saving user: ' . $e->getMessage();
        }
    }

    $_SESSION['error'] = $error;
    header('Location: add_staff_official_barangaycaptian.php');
    exit();
}

// Get barangay name
$barangayStmt = $pdo->prepare("SELECT barangay_name FROM barangay WHERE barangay_id = ?");
$barangayStmt->execute([$bid]);
$barangayName = $barangayStmt->fetchColumn();

// Get allowed roles for dropdown
$allowedRoles = [ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR, ROLE_CHIEF];
$roleStmt = $pdo->prepare("SELECT role_id, role_name FROM Role WHERE role_id IN (" . implode(',', $allowedRoles) . ")");
$roleStmt->execute();
$roles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . "/../pages/header.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>
<body class="bg-gray-50">
    <main class="">
        <div class="container mx-auto p-4">
            <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                <div class="text-center md:text-left mb-4 md:mb-0">
                    <h1 class="text-3xl font-bold text-blue-800">User Management</h1>
                    <p class="mt-1 text-sm text-gray-600">Manage existing users</p>
                </div>
                <div class="flex space-x-4">
                    <button onclick="openModal('add')" class="mb-6 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded">
                        Add Barangay Official
                    </button>
                </div>
            </div>

            <div class="pb-4">
                <input type="text" id="searchInput" placeholder="Search users..." 
                    class="w-full md:w-64 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                
                
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
                            <tr data-id="<?= htmlspecialchars($user['user_id']) ?>">
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
                                    <button onclick="openModal('edit', <?= $user['user_id'] ?>, <?= $user['role_id'] ?>)" 
                                            class="text-purple-600 hover:text-purple-900">Edit</button>
                                    <button onclick="toggleStatus(<?= $user['user_id'] ?>, '<?= $user['is_active'] === 'yes' ? 'deactivate' : 'activate' ?>')" 
                                            class="text-blue-600 hover:text-blue-900">
                                        <?= $user['is_active'] === 'yes' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                    <button onclick="deleteUser(<?= $user['user_id'] ?>)" 
                                            class="text-red-600 hover:text-red-900">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl">
                <form id="userForm" method="POST" enctype="multipart/form-data" class="p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800" id="modalTitle">Edit User</h2>
                        <button type="button" onclick="closeModal()" class="text-gray-500 hover:text-gray-700">
                            ✕
                        </button>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" id="formAction" value="edit">
                    <input type="hidden" name="user_id" id="formUserId">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div id="termDates" class="hidden md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Start Term Date</label>
                                <input type="date" name="start_term_date" class="w-full px-3 py-2 border rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">End Term Date</label>
                                <input type="date" name="end_term_date" class="w-full px-3 py-2 border rounded-lg">
                            </div>
                        </div>

                        <div id="roleField">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                            <select name="role_id" required class="w-full px-3 py-2 border rounded-lg">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= htmlspecialchars($role['role_id']) ?>">
                                        <?= htmlspecialchars($role['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                            <input type="text" name="first_name" required class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                            <input type="text" name="last_name" required class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                            <input type="email" name="email" required class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                            <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Leave blank to keep current password</p>
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Profile Picture</label>
                            <input type="file" name="profile_pic" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div id="signatureUpload" class="col-span-2 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Signature</label>
                            <input type="file" name="signature_pic" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-8">
                        <button type="button" onclick="closeModal()" class="py-2 px-4 border rounded-lg bg-white hover:bg-gray-100 text-gray-800">Cancel</button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                <?php if (isset($_SESSION['error'])): ?>
                    Swal.fire('Error', '<?= $_SESSION['error'] ?>', 'error');
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    Swal.fire('Success', '<?= $_SESSION['success'] ?>', 'success');
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
            });
            
            function openModal(action, id = null, roleId = null) {
                const modal = document.getElementById('userModal');
                const form = document.getElementById('userForm');
                const title = document.getElementById('modalTitle');
                const roleField = document.getElementById('roleField');
                form.reset();

                const existingHiddenRole = form.querySelector('input[name="role_id"][type="hidden"]');
                if (existingHiddenRole) existingHiddenRole.remove();

                const isOfficial = [<?= implode(',', $allowedRoles) ?>].includes(parseInt(roleId));
                
                if(action === 'add') {
                    title.textContent = 'Add New Barangay Official';
                    document.getElementById('formAction').value = 'add';
                    document.getElementById('formUserId').value = '';
                    document.getElementById('termDates').classList.remove('hidden');
                    document.getElementById('signatureUpload').classList.remove('hidden');
                    form.querySelector('[name="role_id"]').value = <?= ROLE_SECRETARY ?>;
                    roleField.style.display = 'block';
                } else {
                    title.textContent = 'Edit User';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('formUserId').value = id;
                    document.getElementById('termDates').classList.toggle('hidden', !isOfficial);
                    document.getElementById('signatureUpload').classList.toggle('hidden', !isOfficial);

                    if (parseInt(roleId) === <?= ROLE_RESIDENT ?>) {
                        roleField.style.display = 'none';
                        const hiddenRole = document.createElement('input');
                        hiddenRole.type = 'hidden';
                        hiddenRole.name = 'role_id';
                        hiddenRole.value = '<?= ROLE_RESIDENT ?>';
                        form.appendChild(hiddenRole);
                    } else {
                        roleField.style.display = 'block';
                    }

                    fetch(`add_staff_official_barangaycaptian.php?get=${id}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                const user = data.user;
                                form.querySelector('[name="first_name"]').value = user.first_name;
                                form.querySelector('[name="last_name"]').value = user.last_name;
                                form.querySelector('[name="email"]').value = user.email;
                                form.querySelector('[name="role_id"]').value = user.role_id;
                                
                                if (isOfficial) {
                                    form.querySelector('[name="start_term_date"]').value = user.start_term_date || '';
                                    form.querySelector('[name="end_term_date"]').value = user.end_term_date || '';
                                }
                            }
                        });
                }

                const roleSelect = form.querySelector('[name="role_id"]');
                const handleRoleChange = () => {
                    const selectedRole = parseInt(roleSelect.value);
                    const showOfficialFields = [<?= implode(',', $allowedRoles) ?>].includes(selectedRole);
                    document.getElementById('termDates').classList.toggle('hidden', !showOfficialFields);
                    document.getElementById('signatureUpload').classList.toggle('hidden', !showOfficialFields);
                };
                roleSelect.addEventListener('change', handleRoleChange);

                flatpickr('[name="start_term_date"], [name="end_term_date"]', {
                    dateFormat: "Y-m-d",
                    allowInput: true
                });

                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closeModal() {
                document.getElementById('userModal').classList.add('hidden');
                document.getElementById('userModal').classList.remove('flex');
            }

            async function toggleStatus(userId, action) {
                try {
                    const response = await fetch(`add_staff_official_barangaycaptian.php?toggle_status=1&user_id=${userId}&action=${action}`);
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
                    const response = await fetch(`add_staff_official_barangaycaptian.php?delete_id=${id}`);
                    const data = await response.json();
                    if (!data.success) throw new Error(data.message || 'Delete failed');
                    
                    document.querySelector(`tr[data-id="${id}"]`)?.remove();
                    Swal.fire('Deleted!', 'User has been deleted.', 'success');
                } catch (error) {
                    Swal.fire('Error', error.message, 'error');
                }
            }
            
            function filterTable() {
                const search = document.getElementById('searchInput').value.toLowerCase();

                document.querySelectorAll('#userTable tr').forEach(row => {
                    const matchesSearch = row.textContent.toLowerCase().includes(search);
                    row.style.display = matchesSearch ? '' : 'none';
                });
            }

            document.getElementById('searchInput').addEventListener('input', filterTable);
            
            window.onclick = function(event) {
                if (event.target === document.getElementById('userModal')) {
                    closeModal();
                }
            };
        </script>
    </main>
</body>
</html>