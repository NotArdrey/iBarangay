<?php
/* super_admin.php – modified to only allow adding Barangay Captains */
session_start();
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ────────────── CSRF seed & headers ────────────── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

require '../config/dbconn.php';

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
          AND id <> :uid
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

/* ────────────── Auth guard ─────────────────────── */
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header('Location: ../pages/login.php');
    exit;
}

// Fixed the query to use 'id' instead of 'user_id'
$stmt = $pdo->prepare('SELECT role_id, barangay_id FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userInfo || (int)$userInfo['role_id'] !== ROLE_SUPER_ADMIN) {
    if ($isAjax) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
    } else {
        header('Location: ../pages/login.php');
    }
    exit;
}
$bid = $userInfo['barangay_id']; // null for superadmin

/* ────────────── Toggle status for Barangay Captains only ────── */
if (isset($_GET['toggle_status'])) {
    $userId = (int)$_GET['user_id'];
    $action = $_GET['action'];

    if (!in_array($action, ['activate', 'deactivate'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }

    // Check if user is a Barangay Captain
    $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRole = $stmt->fetchColumn();
    
    if ($userRole != ROLE_CAPTAIN) {
        echo json_encode(['success' => false, 'message' => 'Can only toggle status of Barangay Captains']);
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

/* ────────────── Delete Barangay Captain only ────── */
if (isset($_GET['delete_id'])) {
    $userId = (int)$_GET['delete_id'];

    // Check if user is a Barangay Captain
    $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userRole = $stmt->fetchColumn();
    
    if ($userRole != ROLE_CAPTAIN) {
        echo json_encode(['success' => false, 'message' => 'Can only delete Barangay Captains']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        /* 1. child rows that must disappear */
        // document requests + their attributes
        $pdo->prepare("
            DELETE dr, dra
              FROM document_requests dr
         LEFT JOIN document_request_attributes dra
                ON dra.request_id = dr.id
             WHERE dr.requested_by_user_id = ?
        ")->execute([$userId]);

        // addresses
        $pdo->prepare("DELETE FROM addresses WHERE user_id = ?")
            ->execute([$userId]);

        // blotter participants – keep record, detach user
        $pdo->prepare("
            UPDATE blotter_participants bp
            JOIN persons p ON bp.person_id = p.id
               SET p.user_id = NULL
             WHERE p.user_id = ?
        ")->execute([$userId]);

        // monthly reports
        $pdo->prepare("
            UPDATE monthly_reports
               SET prepared_by_user_id = NULL
             WHERE prepared_by_user_id = ?
        ")->execute([$userId]);

        // audit-trail
        $pdo->prepare("
            DELETE FROM audit_trails
                  WHERE admin_user_id = ?
        ")->execute([$userId]);

        // events created by this user
        $pdo->prepare("
            DELETE FROM events
                  WHERE created_by_user_id = ?
        ")->execute([$userId]);

        /* 2. finally, remove the user */
        $stmt   = $pdo->prepare("DELETE FROM users WHERE id = ?");
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

/* ────────────── fetch list for page render - Only Barangay Captains ─────── */
$stmt = $pdo->prepare("
    SELECT 
        u.id as user_id, u.first_name, u.last_name, u.email, u.role_id, u.barangay_id, u.is_active,
        r.name as role_name, b.name as barangay_name,
        u.start_term_date, u.end_term_date,
        u.id_image_path, u.signature_image_path,
        CASE
            WHEN u.start_term_date <= CURDATE() AND
                 (u.end_term_date IS NULL OR u.end_term_date >= CURDATE()) THEN 'active'
            ELSE 'inactive'
        END AS term_status
    FROM users u
    JOIN roles r ON r.id = u.role_id
    JOIN barangay b ON b.id = u.barangay_id
    WHERE u.role_id = ?
    ORDER BY u.last_name, u.first_name
");
$stmt->execute([ROLE_CAPTAIN]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────── Fetch single user (AJAX) - Only Barangay Captains ──────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get'])) {
    $userId = (int)$_GET['get'];
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name, b.name as barangay_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        JOIN barangay b ON b.id = u.barangay_id
        WHERE u.id = ? AND u.role_id = ?
    ");
    $stmt->execute([$userId, ROLE_CAPTAIN]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Barangay Captain not found']);
    }
    exit;
}

/* ──────────────── Add / Edit Barangay Captains Only ──────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: super_admin.php');
        exit;
    }

    $action      = $_POST['action'] ?? '';
    $uid         = (int)($_POST['user_id'] ?? 0);
    $firstName   = htmlspecialchars(trim($_POST['first_name'] ?? ''));
    $lastName    = htmlspecialchars(trim($_POST['last_name'] ?? ''));
    $email       = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password    = $_POST['password'] ?? '';
    $roleId      = ROLE_CAPTAIN; // Force to Barangay Captain only
    $barangayId  = (int)($_POST['barangay_id'] ?? 0);
    $startTerm   = $_POST['start_term_date'] ?? '';
    $endTerm     = $_POST['end_term_date'] ?? '';
    $error       = null;

    /* Validate barangay */
    $chkBar = $pdo->prepare("SELECT COUNT(*) FROM barangay WHERE id = ?");
    $chkBar->execute([$barangayId]);
    if (!$chkBar->fetchColumn()) $error = 'Invalid barangay selected';

    /* Validate email */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format';
    } else {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$email, $uid]);
        if ($dup->fetchColumn() > 0) $error = 'Email already in use';
    }

    /* Auto-set term dates & overlap checks for Barangay Captain */
    if (!$error) {
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
            // Check for overlap - only one Barangay Captain per barangay at a time
            if (overlapCount($pdo, ROLE_CAPTAIN, $barangayId, $startTerm, $endTerm, $uid) > 0) {
                $error = 'Another Barangay Captain already serves in this barangay during the selected period';
            }
        }
    }

    /* ---------- Handle file uploads ---------- */
    $profilePic = null;
    if (!empty($_FILES['profile_pic']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $profilePic = uniqid('prof_') . ".$ext";
            $uploadDir = __DIR__ . "/../uploads/staff_pics/";
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $profilePic);
        } else {
            $error = 'Invalid profile picture format';
        }
    }
    
    $signaturePic = null;
    if (!empty($_FILES['signature_pic']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['signature_pic']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png'], true)) {
            $signaturePic = uniqid('sign_') . ".$ext";
            $uploadDir = __DIR__ . "/../uploads/signatures/";
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            move_uploaded_file($_FILES['signature_pic']['tmp_name'], $uploadDir . $signaturePic);
        } else {
            $error = 'Invalid signature format';
        }
    }

    /* ---------- Save to DB ---------- */
    if (!$error) {
        try {
            $pdo->beginTransaction();
            
            if ($action === 'add') {
                if (empty($password)) {
                    $error = 'Password is required for new users';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Explicitly set is_active to 1 for new admins/captains
                    $isActive = 1; // Always active for new users
                    
                    $sql = "INSERT INTO users (
                                email, password, first_name, last_name, 
                                role_id, barangay_id, is_active, email_verified_at,
                                start_term_date, end_term_date,
                                id_image_path, signature_image_path
                            ) VALUES (
                                ?, ?, ?, ?, 
                                ?, ?, ?, NOW(),
                                ?, ?,
                                ?, ?
                            )";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $email, $passwordHash, $firstName, $lastName,
                        $roleId, $barangayId, $isActive, // Explicitly pass the active status
                        $startTerm, $endTerm,
                        $profilePic ?: 'default.png',
                        $signaturePic
                    ]);
                    
                    // Log the creation for audit purposes
                    $newUserId = $pdo->lastInsertId();
                    error_log("New Barangay Captain created: ID $newUserId, Email: $email, Active: $isActive");
                }
            } else {
                // Updates for existing user - ensure it's still a Barangay Captain
                $checkRole = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
                $checkRole->execute([$uid]);
                $currentRole = $checkRole->fetchColumn();
                
                if ($currentRole != ROLE_CAPTAIN) {
                    $error = 'Can only edit Barangay Captains';
                } else {
                    $sqlParts = [
                        "email = ?",
                        "first_name = ?",
                        "last_name = ?",
                        "barangay_id = ?",
                        "start_term_date = ?",
                        "end_term_date = ?"
                    ];
                    
                    $params = [
                        $email, $firstName, $lastName,
                        $barangayId, $startTerm, $endTerm
                    ];
                    
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
                        $sqlParts[] = "password = ?";
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                    }
                    
                    // Add user_id to params for WHERE clause
                    $params[] = $uid;
                    
                    $sql = "UPDATE users SET " . implode(", ", $sqlParts) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
            }
            
            if (!$error) {
                $pdo->commit();
                $_SESSION['success'] = ($action === 'add')
                    ? 'Barangay Captain added successfully and is now active'
                    : 'Barangay Captain updated successfully';
                header('Location: super_admin.php');
                exit();
            } else {
                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Error saving user: ' . $e->getMessage();
        }
    }

    $_SESSION['error'] = $error;
    header('Location: super_admin.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Captain Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <main class="p-6 md:p-8 space-y-6">
        <?php if (!empty($_SESSION['success'])): ?>
            <script>
                Swal.fire({icon: 'success', title: 'Success!', text: '<?= addslashes($_SESSION['success']) ?>'});
            </script>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <script>
                Swal.fire({icon: 'error', title: 'Error!', text: '<?= addslashes($_SESSION['error']) ?>'});
            </script>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                <div class="text-center md:text-left mb-4 md:mb-0">
                    <h1 class="text-2xl font-bold text-gray-900">Barangay Captain Management</h1>
                    <p class="mt-1 text-sm text-gray-600">Manage Barangay Captains across all barangays</p>
                </div>
                <div class="flex space-x-4">
                    <button onclick="openModal('add')" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2.5 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Barangay Captain
                    </button>
                    <a href="../functions/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2.5 rounded-lg">
                        Logout
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b flex flex-col md:flex-row justify-between items-center gap-4">
                    <input type="text" id="searchInput" placeholder="Search captains..." 
                           class="w-full md:w-64 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                    <div class="flex items-center space-x-4">
                        <select id="barangayFilter" class="px-3 py-2 border rounded-lg">
                            <option value="">All Barangays</option>
                            <?php foreach ($pdo->query("SELECT id, name FROM barangay ORDER BY name") as $bar): ?>
                                <option value="<?= $bar['id'] ?>"><?= htmlspecialchars($bar['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="statusFilter" class="px-3 py-2 border rounded-lg">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profile</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Barangay</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Term Period</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="userTable">
                            <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50 transition-colors" 
                                data-id="<?= $user['user_id'] ?>" 
                                data-barangay="<?= $user['barangay_id'] ?>"
                                data-status="<?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <img src="../uploads/staff_pics/<?= htmlspecialchars($user['id_image_path'] ?: 'default.png') ?>" 
                                         class="w-10 h-10 rounded-full object-cover border-2 border-purple-200"
                                         alt="Profile picture"
                                         onerror="this.src='../uploads/staff_pics/default.png'">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars($user['email']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">
                                        <?= htmlspecialchars($user['barangay_name']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?php if($user['start_term_date']): ?>
                                        <?= date('M d, Y', strtotime($user['start_term_date'])) ?>
                                        - 
                                        <?= $user['end_term_date'] ? date('M d, Y', strtotime($user['end_term_date'])) : 'Present' ?>
                                    <?php else: ?>
                                        Not Set
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-medium <?= $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium flex gap-3">
                                    <button onclick="toggleStatus(<?= $user['user_id'] ?>, '<?= $user['is_active'] ? 'deactivate' : 'activate' ?>')"
                                        class="text-blue-600 hover:text-blue-900 text-xs font-medium px-2 py-1 rounded">
                                        <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                    <button onclick="openModal('edit', <?= $user['user_id'] ?>, <?= ROLE_CAPTAIN ?>)"
                                        class="text-purple-600 hover:text-purple-900">
                                        Edit
                                    </button>
                                    <button onclick="deleteUser(<?= $user['user_id'] ?>)"
                                        class="text-red-600 hover:text-red-900">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                    No Barangay Captains found. Click "Add Barangay Captain" to get started.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div id="userModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                <form id="userForm" method="POST" enctype="multipart/form-data" class="p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-bold text-gray-800" id="modalTitle">Add Barangay Captain</h2>
                        <button type="button" onclick="closeModal()" class="text-gray-500 hover:text-gray-700 text-xl">
                            ✕
                        </button>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="user_id" id="formUserId">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <!-- Term Dates - Always visible for Barangay Captains -->
                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Start Term Date *</label>
                                <input type="date" name="start_term_date" required class="w-full px-3 py-2 border rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">End Term Date *</label>
                                <input type="date" name="end_term_date" required class="w-full px-3 py-2 border rounded-lg">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Barangay *</label>
                            <select name="barangay_id" required class="w-full px-3 py-2 border rounded-lg">
                                <option value="">Select Barangay</option>
                                <?php foreach ($pdo->query("SELECT id, name FROM barangay ORDER BY name") as $bar): ?>
                                    <option value="<?= $bar['id'] ?>"><?= htmlspecialchars($bar['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                            <input type="text" value="Barangay Captain" disabled class="w-full px-3 py-2 border rounded-lg bg-gray-100">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Account Status</label>
                            <input type="text" value="Active (Default)" disabled class="w-full px-3 py-2 border rounded-lg bg-green-50 text-green-800">
                            <p class="text-xs text-green-600 mt-1">New captains are created as active by default</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">First Name *</label>
                            <input type="text" name="first_name" required class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Last Name *</label>
                            <input type="text" name="last_name" required class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                            <input type="email" name="email" required class="w-full px-3 py-2 border rounded-lg">
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                            <input type="password" name="password" class="w-full px-3 py-2 border rounded-lg">
                            <p class="text-xs text-gray-500 mt-1" id="passwordHelp">Password is required for new captains</p>
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Profile Picture</label>
                            <input type="file" name="profile_pic" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Upload JPG, JPEG, or PNG format</p>
                        </div>

                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Digital Signature</label>
                            <input type="file" name="signature_pic" accept="image/*" class="w-full px-3 py-2 border rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">Upload captain's digital signature (JPG, JPEG, or PNG)</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 mt-8">
                        <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-6 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg">
                            <span id="submitText">Add Captain</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openModal(action, id = null, roleId = null) {
                console.log('Opening modal:', action, id, roleId);
                
                const modal = document.getElementById('userModal');
                const form = document.getElementById('userForm');
                const title = document.getElementById('modalTitle');
                const passwordField = form.querySelector('[name="password"]');
                const passwordHelp = document.getElementById('passwordHelp');
                const submitText = document.getElementById('submitText');
                
                if (!modal || !form || !title) {
                    console.error('Modal elements not found');
                    return;
                }
                
                // Reset form
                form.reset();
                
                if(action === 'add') {
                    title.textContent = 'Add New Barangay Captain';
                    document.getElementById('formAction').value = 'add';
                    document.getElementById('formUserId').value = '';
                    passwordField.required = true;
                    passwordHelp.textContent = 'Password is required for new captains';
                    submitText.textContent = 'Add Captain';
                } else {
                    title.textContent = 'Edit Barangay Captain';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('formUserId').value = id;
                    passwordField.required = false;
                    passwordHelp.textContent = 'Leave blank to keep current password';
                    submitText.textContent = 'Update Captain';

                    // Load user data
                    if (id) {
                        fetch(`super_admin.php?get=${id}`)
                            .then(response => response.json())
                            .then(data => {
                                console.log('User data received:', data);
                                if (data.success) {
                                    const user = data.user;
                                    form.querySelector('[name="first_name"]').value = user.first_name || '';
                                    form.querySelector('[name="last_name"]').value = user.last_name || '';
                                    form.querySelector('[name="email"]').value = user.email || '';
                                    form.querySelector('[name="barangay_id"]').value = user.barangay_id || '';
                                    form.querySelector('[name="start_term_date"]').value = user.start_term_date || '';
                                    form.querySelector('[name="end_term_date"]').value = user.end_term_date || '';
                                } else {
                                    console.error('Failed to fetch user data:', data.message);
                                    Swal.fire('Error', 'Failed to load captain data: ' + data.message, 'error');
                                }
                            })
                            .catch(error => {
                                console.error('Error fetching user data:', error);
                                Swal.fire('Error', 'Failed to load captain data', 'error');
                            });
                    }
                }

                // Initialize date pickers
                flatpickr('[name="start_term_date"], [name="end_term_date"]', {
                    dateFormat: "Y-m-d",
                    allowInput: true
                });

                // Show modal
                modal.style.display = 'flex';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closeModal() {
                const modal = document.getElementById('userModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.style.display = 'none';
            }

            async function toggleStatus(userId, action) {
                try {
                    const response = await fetch(`super_admin.php?toggle_status=1&user_id=${userId}&action=${action}`);
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Failed to update status');

                    const row = document.querySelector(`tr[data-id="${userId}"]`);
                    if (row) {
                        const statusBadge = row.querySelector('td:nth-child(6) span');
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

                        // Update row data attribute for filtering
                        row.setAttribute('data-status', isActive ? 'active' : 'inactive');
                    }
                    Swal.fire('Success!', `Captain ${action}d successfully`, 'success');
                } catch (error) {
                    Swal.fire('Error', error.message || 'Could not update status', 'error');
                }
            }

            async function deleteUser(id) {
                const result = await Swal.fire({
                    title: 'Delete Barangay Captain?',
                    text: 'This action cannot be undone!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, delete!'
                });
                
                if (!result.isConfirmed) return;

                try {
                    const response = await fetch(`super_admin.php?delete_id=${id}`);
                    const data = await response.json();
                    if (!data.success) throw new Error(data.message || 'Delete failed');
                    
                    const row = document.querySelector(`tr[data-id="${id}"]`);
                    if (row) row.remove();
                    
                    Swal.fire('Deleted!', 'Barangay Captain has been deleted.', 'success');
                } catch (error) {
                    Swal.fire('Error', error.message, 'error');
                }
            }

            function filterTable() {
                const search = document.getElementById('searchInput').value.toLowerCase();
                const barangay = document.getElementById('barangayFilter').value;
                const status = document.getElementById('statusFilter').value;

                document.querySelectorAll('#userTable tr').forEach(row => {
                    // Skip the "no data" row
                    if (row.cells.length === 1) return;
                    
                    const matchesSearch = row.textContent.toLowerCase().includes(search);
                    const matchesBarangay = !barangay || row.dataset.barangay === barangay;
                    const matchesStatus = !status || row.dataset.status === status;
                    
                    row.style.display = (matchesSearch && matchesBarangay && matchesStatus) ? '' : 'none';
                });
            }

            // Event listeners
            document.addEventListener('DOMContentLoaded', function() {
                // Search and filter functionality
                document.getElementById('searchInput').addEventListener('input', filterTable);
                document.getElementById('barangayFilter').addEventListener('change', filterTable);
                document.getElementById('statusFilter').addEventListener('change', filterTable);
                
                // Modal click outside to close
                const modal = document.getElementById('userModal');
                modal.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        closeModal();
                    }
                });
                
                // Form submission handler
                document.getElementById('userForm').addEventListener('submit', function(e) {
                    const action = document.getElementById('formAction').value;
                    const password = this.querySelector('[name="password"]').value;
                    const startDate = this.querySelector('[name="start_term_date"]').value;
                    const endDate = this.querySelector('[name="end_term_date"]').value;
                    
                    // Validate password for new users
                    if (action === 'add' && !password.trim()) {
                        e.preventDefault();
                        Swal.fire('Error', 'Password is required for new captains', 'error');
                        return false;
                    }
                    
                    // Validate term dates
                    if (startDate && endDate && new Date(endDate) <= new Date(startDate)) {
                        e.preventDefault();
                        Swal.fire('Error', 'End term date must be after start term date', 'error');
                        return false;
                    }
                    
                    // Show loading
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const submitText = document.getElementById('submitText');
                    const originalText = submitText.textContent;
                    submitText.textContent = 'Saving...';
                    submitBtn.disabled = true;
                    
                    // Re-enable button after 10 seconds (in case of errors)
                    setTimeout(() => {
                        submitText.textContent = originalText;
                        submitBtn.disabled = false;
                    }, 10000);
                });
            });

            // ESC key to close modal
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });

            // Auto-set end date when start date changes (3 years term)
            document.addEventListener('change', function(event) {
                if (event.target.name === 'start_term_date') {
                    const startDate = new Date(event.target.value);
                    if (startDate) {
                        const endDate = new Date(startDate);
                        endDate.setFullYear(startDate.getFullYear() + 3);
                        const endDateField = document.querySelector('[name="end_term_date"]');
                        endDateField.value = endDate.toISOString().split('T')[0];
                    }
                }
            });
        </script>
    </main>
</body>
</html>