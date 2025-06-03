<?php

// Ensure session is started before using $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../vendor/autoload.php";
require __DIR__ . "/../config/dbconn.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Cast session values to integers for strict comparison
$current_admin_id = isset($_SESSION['user_id'])       ? (int) $_SESSION['user_id']       : null;
$role             = isset($_SESSION['role_id'])       ? (int) $_SESSION['role_id']       : null;
$bid              = isset($_SESSION['barangay_id'])   ? (int) $_SESSION['barangay_id']   : null;

define('ROLE_RESIDENT', 8);
$filter = $_GET['filter'] ?? 'active';
// Access control: only Super Admin (2) and Barangay-specific Admins (3–7) can view
if ($current_admin_id === null || !in_array($role, [2, 3, 4, 5, 6, 7], true)) {
    header("Location: ../pages/login.php");
    exit;
}

/**
 * Audit trail logger
 */
function logAuditTrail(PDO $pdo, int $admin, string $action, string $table, int $id, string $desc): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_trails (admin_user_id, action, table_name, record_id, old_values)
         VALUES (:admin, :act, :tbl, :rid, :desc)"
    );
    $stmt->execute([
        ':admin' => $admin,
        ':act'   => $action,
        ':tbl'   => $table,
        ':rid'   => $id,
        ':desc'  => $desc,
    ]);
}

// Build query to fetch residents (role_id = ROLE_RESIDENT)
$sql = <<<SQL
SELECT 
    u.id,
    u.email,
    u.phone,
    u.role_id,
    u.barangay_id,
    u.is_active,
    u.last_login,
    u.govt_id_image,
    u.id_type,
    u.id_expiration_date,
    p.id AS person_id,
    p.first_name AS person_first_name,
    p.middle_name AS person_middle_name,
    p.last_name AS person_last_name,
    p.suffix,
    p.birth_date,
    p.birth_place,
    p.gender AS person_gender,
    p.civil_status,
    p.citizenship,
    p.religion,
    p.education_level,
    p.occupation,
    p.monthly_income,
    p.contact_number,
    p.resident_type,
    a.house_no,
    a.street,
    a.phase,
    a.municipality,
    a.province,
    a.region,
    a.is_primary,
    a.is_permanent,
    ec.contact_name AS emergency_contact_name,
    ec.contact_number AS emergency_contact_number,
    ec.contact_address AS emergency_contact_address,
    ec.relationship AS emergency_contact_relationship,
    pi.osca_id,
    pi.gsis_id,
    pi.sss_id,
    pi.tin_id,
    pi.philhealth_id,
    pi.other_id_type,
    pi.other_id_number,
    h.household_number,
    pu.name AS purok_name,
    b.name AS barangay_name
FROM users u
LEFT JOIN persons p ON u.id = p.user_id
LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
LEFT JOIN emergency_contacts ec ON p.id = ec.person_id
LEFT JOIN person_identification pi ON p.id = pi.person_id
LEFT JOIN household_members hm ON p.id = hm.person_id
LEFT JOIN households h ON hm.household_id = h.id
LEFT JOIN purok pu ON h.purok_id = pu.id
LEFT JOIN barangay b ON u.barangay_id = b.id
WHERE u.role_id = :role
SQL;
$params = [':role' => ROLE_RESIDENT];

// ─── Barangay scope (unchanged) ──────────────────────
if ($role >= 3 && $role <= 7) {
    $sql           .= " AND u.barangay_id = :bid";
    $params[':bid'] = $bid;
}
if (isset($_GET['action'], $_GET['id'])) {
    header('Content-Type: application/json');
    $resId   = (int) $_GET['id'];
    $act     = $_GET['action'];  // 'ban' or 'unban'

    // Fetch user email and name
    $stmtUser = $pdo->prepare("
        SELECT email, CONCAT(first_name,' ', last_name) AS name
          FROM users
         WHERE id = :id
    ");
    $stmtUser->execute([':id' => $resId]);
    $userInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if ($act === 'ban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Grab the ban reason
        $remarks = $_POST['remarks'] ?? '';

        // Deactivate the user
        $stmt = $pdo->prepare("
            UPDATE users
               SET is_active = FALSE
             WHERE id     = :id
               AND barangay_id = :bid
        ");
        $success = $stmt->execute([':id' => $resId, ':bid' => $bid]);
        if ($success) {
            // Send ban email with reason
            if (!empty($userInfo['email'])) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'barangayhub2@gmail.com';
                    $mail->Password   = 'eisy hpjz rdnt bwrp';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->setFrom('barangayhub2@gmail.com', 'iBarangay System');
                    $mail->addAddress($userInfo['email'], $userInfo['name']);
                    $mail->Subject = 'Your account has been suspended';
                    $mail->Body = getAccountSuspendedTemplate($userInfo['name'], $remarks);
                    $mail->send();
                } catch (Exception $e) {
                    error_log('Mailer Error: ' . $mail->ErrorInfo);
                }
            }
            // Log audit with remarks
            logAuditTrail(
                $pdo,
                $current_admin_id,
                'UPDATE',
                'users',
                $resId,
                'Banned resident: ' . $remarks
            );
            echo json_encode(['success' => true, 'message' => 'Resident banned and notified.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Unable to ban resident.']);
        }
    } elseif ($act === 'unban') {
        // Reactivate the user
        $stmt = $pdo->prepare("
            UPDATE users
               SET is_active = TRUE
             WHERE id     = :id
               AND barangay_id = :bid
        ");
        $success = $stmt->execute([':id' => $resId, ':bid' => $bid]);
        if ($success) {
            // Send unban email
            if (!empty($userInfo['email'])) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'barangayhub2@gmail.com';
                    $mail->Password   = 'eisy hpjz rdnt bwrp';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->setFrom('noreply@barangayhub.com', 'iBarangay');
                    $mail->addAddress($userInfo['email'], $userInfo['name']);
                    $mail->Subject = 'Your account has been reactivated';
                    $mail->Body    = "Hello {$userInfo['name']},\n\n"
                        . "Your account has been reactivated.\n"
                        . "You can now log in and continue using the system.";
                    $mail->send();
                } catch (Exception $e) {
                    error_log('Mailer Error: ' . $mail->ErrorInfo);
                }
            }
            logAuditTrail(
                $pdo,
                $current_admin_id,
                'UPDATE',
                'users',
                $resId,
                'Unbanned resident.'
            );
            echo json_encode(['success' => true, 'message' => 'Resident unbanned and notified.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Unable to unban resident.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action or method.']);
    }
    exit;
}

// ─── Active/banned filter ────────────────────────────
if ($filter === 'active') {
    $sql .= " AND u.is_active = TRUE";
} elseif ($filter === 'banned') {
    $sql .= " AND u.is_active = FALSE";
}

try {
    $stmt      = $pdo->prepare($sql);
    $stmt->execute($params);
    $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching residents: " . $e->getMessage());
}

// Handle edit form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_resident_submit'])) {
    $user_id = (int) ($_POST['edit_person_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // Update Users table
        $userFields = ['first_name', 'last_name', 'email', 'phone', 'gender'];
        $userUpdateParts = [];
        $userParams = [':user_id' => $user_id];

        foreach ($userFields as $field) {
            if (isset($_POST["edit_{$field}"])) {
                $userUpdateParts[] = "{$field} = :{$field}";
                $userParams[":{$field}"] = trim($_POST["edit_{$field}"]);
            }
        }

        if (!empty($userUpdateParts)) {
            $updateUserSql = "UPDATE users SET " . implode(', ', $userUpdateParts) . " WHERE id = :user_id";
            $stmt = $pdo->prepare($updateUserSql);
            $stmt->execute($userParams);
        }

        // Update persons table
        $personFields = [
            'first_name',
            'middle_name',
            'last_name',
            'suffix',
            'birth_date',
            'birth_place',
            'gender',
            'civil_status',
            'citizenship',
            'religion',
            'education_level',
            'occupation',
            'monthly_income',
            'contact_number',
            'resident_type'
        ];
        $personUpdateParts = [];
        $personParams = [':user_id' => $user_id];

        foreach ($personFields as $field) {
            if (isset($_POST["edit_{$field}"])) {
                $personUpdateParts[] = "{$field} = :{$field}";
                $personParams[":{$field}"] = trim($_POST["edit_{$field}"]);
            }
        }

        if (!empty($personUpdateParts)) {
            // Check if person record exists
            $checkPersonStmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = :user_id");
            $checkPersonStmt->execute([':user_id' => $user_id]);
            $personId = $checkPersonStmt->fetchColumn();

            if ($personId) {
                $updatePersonSql = "UPDATE persons SET " . implode(', ', $personUpdateParts) . " WHERE user_id = :user_id";
                $stmt = $pdo->prepare($updatePersonSql);
                $stmt->execute($personParams);
            } else {
                // Insert new person record
                $personParams[':citizenship'] = 'Filipino';
                $insertPersonSql = "INSERT INTO persons (user_id, " . implode(', ', array_keys($personParams)) . ") 
                                  VALUES (:user_id, :" . implode(', :', array_keys($personParams)) . ")";
                $stmt = $pdo->prepare($insertPersonSql);
                $stmt->execute($personParams);
                $personId = $pdo->lastInsertId();
            }

            // Update address if provided
            if ($personId && (isset($_POST['edit_house_no']) || isset($_POST['edit_street']))) {
                $addressFields = ['house_no', 'street', 'phase'];
                $addressParams = [
                    ':person_id' => $personId,
                    ':municipality' => 'SAN RAFAEL',
                    ':province' => 'BULACAN',
                    ':region' => 'III'
                ];
                $addressUpdateParts = [];

                foreach ($addressFields as $field) {
                    if (isset($_POST["edit_{$field}"])) {
                        $addressUpdateParts[] = "{$field} = :{$field}";
                        $addressParams[":{$field}"] = trim($_POST["edit_{$field}"]);
                    }
                }

                // Check if address exists
                $checkAddressStmt = $pdo->prepare("SELECT id FROM addresses WHERE person_id = :person_id AND is_primary = TRUE");
                $checkAddressStmt->execute([':person_id' => $personId]);
                $addressId = $checkAddressStmt->fetchColumn();

                if ($addressId && !empty($addressUpdateParts)) {
                    $updateAddressSql = "UPDATE addresses SET " . implode(', ', $addressUpdateParts) . " WHERE id = :address_id";
                    $addressParams[':address_id'] = $addressId;
                    $stmt = $pdo->prepare($updateAddressSql);
                    $stmt->execute($addressParams);
                } elseif (!empty($addressUpdateParts)) {
                    $addressParams[':is_primary'] = true;
                    $insertAddressSql = "INSERT INTO addresses (person_id, " . implode(', ', array_keys($addressParams)) . ", is_primary) 
                                       VALUES (:person_id, :" . implode(', :', array_keys($addressParams)) . ", :is_primary)";
                    $stmt = $pdo->prepare($insertAddressSql);
                    $stmt->execute($addressParams);
                }
            }

            // Update emergency contact if provided
            if ($personId && (isset($_POST['edit_emergency_contact_name']) || isset($_POST['edit_emergency_contact_number']))) {
                $emergencyParams = [
                    ':person_id' => $personId,
                    ':contact_name' => $_POST['edit_emergency_contact_name'] ?? '',
                    ':contact_number' => $_POST['edit_emergency_contact_number'] ?? '',
                    ':contact_address' => $_POST['edit_emergency_contact_address'] ?? '',
                    ':relationship' => $_POST['edit_emergency_contact_relationship'] ?? ''
                ];

                // Check if emergency contact exists
                $checkEmergencyStmt = $pdo->prepare("SELECT id FROM emergency_contacts WHERE person_id = :person_id");
                $checkEmergencyStmt->execute([':person_id' => $personId]);
                $emergencyId = $checkEmergencyStmt->fetchColumn();

                if ($emergencyId) {
                    $updateEmergencySql = "UPDATE emergency_contacts 
                                         SET contact_name = :contact_name,
                                             contact_number = :contact_number,
                                             contact_address = :contact_address,
                                             relationship = :relationship
                                         WHERE id = :emergency_id";
                    $emergencyParams[':emergency_id'] = $emergencyId;
                    $stmt = $pdo->prepare($updateEmergencySql);
                    $stmt->execute($emergencyParams);
                } else {
                    $insertEmergencySql = "INSERT INTO emergency_contacts (person_id, contact_name, contact_number, contact_address, relationship)
                                         VALUES (:person_id, :contact_name, :contact_number, :contact_address, :relationship)";
                    $stmt = $pdo->prepare($insertEmergencySql);
                    $stmt->execute($emergencyParams);
                }
            }
        }

        $pdo->commit();
        $_SESSION['success_message'] = "Resident information updated successfully.";
        header("Location: residents.php");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error updating resident: " . $e->getMessage();
        header("Location: residents.php");
        exit;
    }
}

require_once __DIR__ . "/../components/header.php";
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Accounts Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>

<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="mb-4 p-4 bg-green-100 text-green-800 rounded"><?= $_SESSION['success_message'];
                                                                        unset($_SESSION['success_message']); ?></div>
        <?php elseif (!empty($_SESSION['error_message'])): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-800 rounded"><?= $_SESSION['error_message'];
                                                                    unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>

        <section class="mb-6">
            <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                <div class="flex items-center space-x-3">
                    <h1 class="text-3xl font-bold text-blue-800">Resident Accounts Management</h1>
                    <!-- Filter dropdown -->
                    <select id="filterStatus" class="border p-2 rounded">
                        <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="banned" <?= $filter === 'banned' ? 'selected' : '' ?>>Banned</option>
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                    </select>
                </div>
                <input id="searchInput" type="text" placeholder="Search residents..." class="p-2 border rounded w-1/3">
            </div>
        </section>

        <div class="bg-white rounded-lg shadow border border-gray-200 overflow-x-auto">
            <table id="residentsTable" class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Age</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Address</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($residents as $r): ?>
                        <?php
                        // Prefer person data over user data
                        $firstName = ($r['person_first_name'] ?? '') ?: ($r['first_name'] ?? '');
                        $middleName = ($r['person_middle_name'] ?? '') ?: ($r['middle_name'] ?? '');
                        $lastName = ($r['person_last_name'] ?? '') ?: ($r['last_name'] ?? '');
                        $fullName = trim("{$firstName} {$middleName} {$lastName}");
                        $birthDate = $r['person_birth_date'] ?? $r['birth_date'] ?? '';
                        $age = !empty($birthDate) ? (new DateTime())->diff(new DateTime($birthDate))->y : '';
                        $contact = $r['contact_number'] ?? $r['phone'] ?? '—';
                        $address = trim(implode(', ', array_filter([
                            $r['household_number'] ?? '',
                            $r['purok_name'] ?? '',
                            $r['phase'] ?? '',
                            $r['barangay_name'] ?? '',
                            $r['municipality'] ?? '',
                            $r['province'] ?? ''
                        ]))) ?: '—';
                        ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($fullName) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($age) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($contact) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($address) ?></td>
                            <td class="px-4 py-3 text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $r['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= $r['is_active'] ? 'Active' : 'Banned' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <div class="flex items-center space-x-2">
                                    <button class="viewBtn text-blue-600 hover:text-blue-900"
                                        data-res='<?= htmlspecialchars(json_encode(array_merge($r, ['govt_id_image' => base64_encode($r['govt_id_image'] ?? '')])), ENT_QUOTES, 'UTF-8') ?>'>
                                        View
                                    </button>
                                    <?php if ($role === 2): // Only show edit button for super admin ?>
                                        <button class="editBtn bg-green-600 text-white px-2 py-1 rounded hover:bg-green-700"
                                            data-res='<?= htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8') ?>'>
                                            Edit
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($role >= 3 && $role <= 7): ?>
                                        <button
                                            class="deactivateBtn bg-yellow-500 text-white px-2 py-1 rounded hover:bg-yellow-600"
                                            data-id="<?= $r['id'] ?>">
                                            <?= $r['is_active'] ? 'Ban' : 'Unban' ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($residents)): ?>
                        <tr>
                            <td colspan="3" class="px-4 py-4 text-center text-gray-500">No residents found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- View Modal -->
        <div id="viewResidentModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                    <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="flex justify-between items-center mb-6 border-b pb-4">
                            <h3 class="text-2xl font-bold text-gray-900">Resident Account Details</h3>
                            <button type="button" data-close-modal="viewResidentModal" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="space-y-6">
                            <!-- Basic Information Section -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Basic Information</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">First Name</p>
                                        <p id="viewFirstName" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Last Name</p>
                                        <p id="viewLastName" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Email</p>
                                        <p id="viewEmail" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Phone</p>
                                        <p id="viewPhone" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Gender</p>
                                        <p id="viewGender" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Address Section -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Address Information</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Household Number</p>
                                        <p id="viewHouseholdNumber" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Purok</p>
                                        <p id="viewPurok" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Phase/Subdivision</p>
                                        <p id="viewPhase" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Barangay</p>
                                        <p id="viewBarangay" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Municipality</p>
                                        <p id="viewMunicipality" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Province</p>
                                        <p id="viewProvince" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Region</p>
                                        <p id="viewRegion" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Government IDs Section -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Government IDs</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">OSCA ID</p>
                                        <p id="viewOscaId" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">GSIS ID</p>
                                        <p id="viewGsisId" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">SSS ID</p>
                                        <p id="viewSssId" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">TIN ID</p>
                                        <p id="viewTinId" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">PhilHealth ID</p>
                                        <p id="viewPhilhealthId" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Other ID Type</p>
                                        <p id="viewOtherIdType" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">Other ID Number</p>
                                        <p id="viewOtherIdNumber" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                    <div class="space-y-2">
                                        <p class="text-sm text-gray-500">ID Expiration</p>
                                        <p id="viewIdExpiration" class="text-base font-medium text-gray-900">—</p>
                                    </div>
                                </div>
                            </div>

                            <!-- ID Verification Section -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">ID Verification</h4>
                                <div id="viewIdImage" class="mt-2 flex flex-col items-center justify-center">
                                    <img id="idImage" src="" alt="ID Image" class="max-w-[300px] h-auto border rounded-lg shadow-sm hidden">
                                    <p id="noIdImage" class="text-gray-500 italic">No ID image available</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="button" data-close-modal="viewResidentModal" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="hidden fixed inset-0 z-50 p-4 bg-black bg-opacity-50">
            <div class="relative mx-auto mt-20 max-w-2xl bg-white rounded-lg shadow">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-semibold">Edit Resident</h3>
                        <button type="button" data-close-modal="editModal" class="text-gray-500 hover:text-gray-700">✕</button>
                    </div>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="edit_person_id" id="edit_person_id">
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-sm font-medium">First Name</label><input type="text" name="edit_first_name" id="edit_first_name" required class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Middle Name</label><input type="text" name="edit_middle_name" id="edit_middle_name" class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Last Name</label><input type="text" name="edit_last_name" id="edit_last_name" required class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Email</label><input type="email" name="edit_email" id="edit_email" required class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Birth Date</label><input type="date" name="edit_birth_date" id="edit_birth_date" class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Gender</label>
                                <select name="edit_gender" id="edit_gender" class="w-full p-2 border rounded">
                                    <option value="">Select</option>
                                    <option>Male</option>
                                    <option>Female</option>
                                    <option>Others</option>
                                </select>
                            </div>
                            <div><label class="block text-sm font-medium">Contact Number</label><input type="text" name="edit_contact_number" id="edit_contact_number" class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Marital Status</label>
                                <select name="edit_marital_status" id="edit_marital_status" class="w-full p-2 border rounded">
                                    <option value="">Select</option>
                                    <option>Single</option>
                                    <option>Married</option>
                                    <option>Widowed</option>
                                    <option>Separated</option>
                                    <option>Widow/Widower</option>
                                </select>
                            </div>
                            <div><label class="block text-sm font-medium">Emergency Contact Name</label><input type="text" name="edit_emergency_contact_name" id="edit_emergency_contact_name" class="w-full p-2 border rounded"></div>
                            <div><label class="block text-sm font-medium">Emergency Contact Number</label><input type="text" name="edit_emergency_contact_number" id="edit_emergency_contact_number" class="w-full p-2 border rounded"></div>
                            <div class="col-span-2"><label class="block text-sm font-medium">Emergency Contact Address</label><textarea name="edit_emergency_contact_address" id="edit_emergency_contact_address" class="w-full p-2 border rounded"></textarea></div>
                            <div class="col-span-2"><label class="block text-sm font-medium">Address</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <div><input type="text" name="edit_house_no" id="edit_house_no" placeholder="House No." class="w-full p-2 border rounded"></div>
                                    <div><input type="text" name="edit_street" id="edit_street" placeholder="Street" class="w-full p-2 border rounded"></div>
                                    <div><input type="text" name="edit_phase" id="edit_phase" placeholder="Phase/Subdivision" class="w-full p-2 border rounded"></div>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" data-close-modal="editModal" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">Cancel</button>
                            <button type="submit" name="edit_resident_submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal handling
            function toggleModal(modalId) {
                document.getElementById(modalId).classList.toggle('hidden');
            }

            // Close modals
            document.querySelectorAll('[data-close-modal]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.getElementById(btn.dataset.closeModal).classList.add('hidden');
                });
            });

            // View resident
            document.querySelectorAll('.viewBtn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const resident = JSON.parse(btn.dataset.res);
                    document.getElementById('viewFirstName').textContent = resident.person_first_name || resident.first_name || '—';
                    document.getElementById('viewLastName').textContent = resident.person_last_name || resident.last_name || '—';
                    document.getElementById('viewEmail').textContent = resident.email || '—';
                    document.getElementById('viewPhone').textContent = resident.phone || '—';
                    document.getElementById('viewGender').textContent = resident.person_gender || resident.gender || '—';
                    document.getElementById('viewHouseholdNumber').textContent = resident.household_number || '—';
                    document.getElementById('viewPurok').textContent = resident.purok_name || '—';
                    document.getElementById('viewPhase').textContent = resident.phase || '—';
                    document.getElementById('viewBarangay').textContent = resident.barangay_name || '—';
                    document.getElementById('viewMunicipality').textContent = resident.municipality || '—';
                    document.getElementById('viewProvince').textContent = resident.province || '—';
                    document.getElementById('viewRegion').textContent = resident.region || '—';
                    document.getElementById('viewOscaId').textContent = resident.osca_id || '—';
                    document.getElementById('viewGsisId').textContent = resident.gsis_id || '—';
                    document.getElementById('viewSssId').textContent = resident.sss_id || '—';
                    document.getElementById('viewTinId').textContent = resident.tin_id || '—';
                    document.getElementById('viewPhilhealthId').textContent = resident.philhealth_id || '—';
                    document.getElementById('viewOtherIdType').textContent = resident.other_id_type || '—';
                    document.getElementById('viewOtherIdNumber').textContent = resident.other_id_number || '—';
                    document.getElementById('viewIdExpiration').textContent = resident.id_expiration_date ? new Date(resident.id_expiration_date).toLocaleDateString() : '—';

                    // Handle ID image display
                    const idImage = document.getElementById('idImage');
                    const noIdImage = document.getElementById('noIdImage');
                    if (resident.govt_id_image && resident.govt_id_image !== '') {
                        idImage.src = 'data:image/jpeg;base64,' + resident.govt_id_image;
                        idImage.classList.remove('hidden');
                        noIdImage.classList.add('hidden');
                    } else {
                        idImage.classList.add('hidden');
                        noIdImage.classList.remove('hidden');
                    }
                    toggleModal('viewResidentModal');
                });
            });
            document.getElementById('filterStatus').addEventListener('change', function() {
                const f = this.value;
                const url = new URL(window.location);
                url.searchParams.set('filter', f);
                window.location = url;
            });

            // Edit resident
            document.querySelectorAll('.editBtn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const resident = JSON.parse(btn.dataset.res);
                    document.getElementById('edit_person_id').value = resident.id;
                    document.getElementById('edit_first_name').value = resident.person_first_name || resident.first_name || '';
                    document.getElementById('edit_middle_name').value = resident.person_middle_name || resident.middle_name || '';
                    document.getElementById('edit_last_name').value = resident.person_last_name || resident.last_name || '';
                    document.getElementById('edit_email').value = resident.email || '';
                    document.getElementById('edit_birth_date').value = resident.person_birth_date || resident.birth_date || '';
                    document.getElementById('edit_gender').value = resident.person_gender || resident.gender || '';
                    document.getElementById('edit_contact_number').value = resident.person_contact_number || resident.contact_number || '';
                    document.getElementById('edit_marital_status').value = resident.marital_status || '';
                    document.getElementById('edit_emergency_contact_name').value = resident.emergency_contact_name || '';
                    document.getElementById('edit_emergency_contact_number').value = resident.emergency_contact_number || '';
                    document.getElementById('edit_emergency_contact_address').value = resident.emergency_contact_address || '';
                    document.getElementById('edit_house_no').value = resident.house_no || '';
                    document.getElementById('edit_street').value = resident.street || '';
                    document.getElementById('edit_phase').value = resident.phase || '';
                    toggleModal('editModal');
                });
            });

            // Search functionality
            document.getElementById('searchInput').addEventListener('input', function() {
                const term = this.value.toLowerCase();
                document.querySelectorAll('#residentsTable tbody tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
            document.querySelectorAll('.deactivateBtn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.dataset.id;
                    const act = btn.textContent.trim().toLowerCase(); // 'ban' or 'unban'

                    if (act === 'ban') {
                        Swal.fire({
                            title: 'Ban Resident?',
                            input: 'textarea',
                            inputPlaceholder: 'Reason for ban...',
                            showCancelButton: true,
                            confirmButtonText: 'Ban',
                            preConfirm: reason => {
                                if (!reason) Swal.showValidationMessage('A reason is required');
                                return reason;
                            }
                        }).then(result => {
                            if (!result.isConfirmed) return;
                            Swal.showLoading();
                            fetch(`residents.php?id=${id}&action=ban`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded'
                                    },
                                    body: `remarks=${encodeURIComponent(result.value)}`
                                })
                                .then(r => r.json())
                                .then(data => {
                                    Swal.close();
                                    if (data.success) {
                                        Swal.fire('Banned!', data.message, 'success').then(() => location.reload());
                                    } else {
                                        Swal.fire('Error', data.message, 'error');
                                    }
                                })
                                .catch(() => {
                                    Swal.close();
                                    Swal.fire('Error', 'Network error occurred', 'error');
                                });
                        });

                    } else {
                        Swal.fire({
                            title: 'Unban Resident?',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Unban'
                        }).then(res => {
                            if (!res.isConfirmed) return;
                            Swal.showLoading();
                            fetch(`residents.php?id=${id}&action=unban`)
                                .then(r => r.json())
                                .then(data => {
                                    Swal.close();
                                    if (data.success) {
                                        Swal.fire('Unbanned!', data.message, 'success').then(() => location.reload());
                                    } else {
                                        Swal.fire('Error', data.message, 'error');
                                    }
                                })
                                .catch(() => {
                                    Swal.close();
                                    Swal.fire('Error', 'Network error occurred', 'error');
                                });
                        });
                    }
                });
            });
            // Delete handling
            document.querySelectorAll('.deleteBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const userId = this.dataset.id;
                    Swal.fire({
                        title: 'Delete Resident?',
                        text: `Confirm deletion of resident ID ${userId}`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#3085d6',


                        confirmButtonText: 'Delete',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            fetch(`delete_resident.php?id=${userId}`, {
                                    method: 'DELETE'
                                })
                                .then(response => {
                                    if (!response.ok) throw new Error('Deletion failed');
                                    return response.json();
                                })
                                .then(data => {
                                    if (data.success) {
                                        this.closest('tr').remove();
                                        Swal.fire('Deleted!', data.message, 'success');
                                    }
                                })
                                .catch(error => {
                                    Swal.fire('Error', error.message, 'error');
                                });
                        }
                    });
                });
            });
        });
    </script>
</body>

</html>