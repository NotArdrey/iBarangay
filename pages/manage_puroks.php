<?php
require "../config/dbconn.php";
require_once "../components/header.php"; // header.php should handle session_start()

// Define User Roles
if (!defined('ROLE_CAPTAIN')) define('ROLE_CAPTAIN', 3);
if (!defined('ROLE_SECRETARY')) define('ROLE_SECRETARY', 4);
if (!defined('ROLE_TREASURER')) define('ROLE_TREASURER', 5);
if (!defined('ROLE_COUNCILOR')) define('ROLE_COUNCILOR', 6);
if (!defined('ROLE_CHAIRPERSON')) define('ROLE_CHAIRPERSON', 7); // Changed from ROLE_CHIEF
if (!defined('ROLE_HEALTH_WORKER')) define('ROLE_HEALTH_WORKER', 9);

// Check if user is logged in (assuming header.php or dbconn.php handles session_start)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$current_role_id = $_SESSION['role_id'] ?? 0;
$barangay_id = $_SESSION['barangay_id']; // Ensure this is set

// Roles with full management access for census-related data (including puroks)
$canManageRoles = [ROLE_CAPTAIN, ROLE_CHAIRPERSON, ROLE_HEALTH_WORKER]; // Changed from ROLE_CHIEF
// Roles with view access
$canViewRoles = [ROLE_CAPTAIN, ROLE_CHAIRPERSON, ROLE_HEALTH_WORKER, ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR]; // Changed from ROLE_CHIEF

$hasFullAccess = in_array($current_role_id, $canManageRoles);
$canViewPage = in_array($current_role_id, $canViewRoles);

if (!$canViewPage) {
    echo "Access Denied. You do not have permission to view this page.";
    // Optionally include footer or redirect
    exit;
}

// Add purok logic
$add_error = '';
$add_success = '';
if ($hasFullAccess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Ensure $barangay_id is available, it's used inside this block.
    // $barangay_id = $_SESSION['barangay_id']; // Already defined above

    if ($_POST['action'] === 'add') {
        $purok_name = trim($_POST['purok_name']);

        try {
            // Check if purok already exists in this barangay
            $stmt = $pdo->prepare("SELECT id FROM purok WHERE barangay_id = ? AND name = ?");
            $stmt->execute([$barangay_id, $purok_name]);
            if ($stmt->fetch()) {
                $add_error = "A purok with this name already exists in this barangay.";
            } else {
                // Insert new purok
                $stmt = $pdo->prepare("INSERT INTO purok (barangay_id, name) VALUES (?, ?)");
                $stmt->execute([$barangay_id, $purok_name]);

                // Get the new purok ID
                $purok_id = $pdo->lastInsertId();

                // Log to audit trail
                $stmt = $pdo->prepare("
                    INSERT INTO audit_trails (
                        user_id, action, table_name, record_id, description
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    'INSERT',
                    'purok',
                    $purok_id,
                    "Added new purok: {$purok_name}"
                ]);

                $add_success = "Purok added successfully!";
            }
        } catch (Exception $e) {
            $add_error = "Error adding purok: " . htmlspecialchars($e->getMessage());
        }
    }
    // Delete purok logic
    else if ($_POST['action'] === 'delete' && isset($_POST['purok_id'])) {
        try {
            // Get purok name before deletion for audit trail
            $stmt = $pdo->prepare("SELECT name FROM purok WHERE id = ? AND barangay_id = ?");
            $stmt->execute([$_POST['purok_id'], $barangay_id]);
            $purok = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$purok) {
                $add_error = "Purok not found.";
            } else {
                // Check if purok has any households
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM households WHERE purok_id = ?");
                $stmt->execute([$_POST['purok_id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result['count'] > 0) {
                    $add_error = "Cannot delete purok because it has households assigned to it.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM purok WHERE id = ? AND barangay_id = ?");
                    $stmt->execute([$_POST['purok_id'], $barangay_id]);

                    // Log to audit trail
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_trails (
                            user_id, action, table_name, record_id, description
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'DELETE',
                        'purok',
                        $_POST['purok_id'],
                        "Deleted purok: {$purok['name']}"
                    ]);

                    $add_success = "Purok deleted successfully!";
                }
            }
        } catch (Exception $e) {
            $add_error = "Error deleting purok: " . htmlspecialchars($e->getMessage());
        }
    }
    // Edit purok logic
    else if ($_POST['action'] === 'edit' && isset($_POST['purok_id'])) {
        $purok_name = trim($_POST['purok_name']);

        try {
            // Get old purok name for audit trail
            $stmt = $pdo->prepare("SELECT name FROM purok WHERE id = ? AND barangay_id = ?");
            $stmt->execute([$_POST['purok_id'], $barangay_id]);
            $old_purok = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$old_purok) {
                $add_error = "Purok not found.";
            } else {
                // Check if new name already exists in this barangay
                $stmt = $pdo->prepare("SELECT id FROM purok WHERE barangay_id = ? AND name = ? AND id != ?");
                $stmt->execute([$barangay_id, $purok_name, $_POST['purok_id']]);
                if ($stmt->fetch()) {
                    $add_error = "A purok with this name already exists in this barangay.";
                } else {
                    $stmt = $pdo->prepare("UPDATE purok SET name = ? WHERE id = ? AND barangay_id = ?");
                    $stmt->execute([$purok_name, $_POST['purok_id'], $barangay_id]);

                    // Log to audit trail
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_trails (
                            user_id, action, table_name, record_id, description
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $_SESSION['user_id'],
                        'UPDATE',
                        'purok',
                        $_POST['purok_id'],
                        "Updated purok name from '{$old_purok['name']}' to '{$purok_name}'"
                    ]);

                    $add_success = "Purok updated successfully!";
                }
            }
        } catch (Exception $e) {
            $add_error = "Error updating purok: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Get puroks for current barangay
$stmt = $pdo->prepare("
    SELECT p.id, p.name, p.created_at, 
           COUNT(h.id) as household_count 
    FROM purok p 
    LEFT JOIN households h ON p.id = h.purok_id 
    WHERE p.barangay_id = ? 
    GROUP BY p.id, p.name, p.created_at 
    ORDER BY p.name
");
$stmt->execute([$_SESSION['barangay_id']]);
$puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current barangay info for display
$stmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
$stmt->execute([$_SESSION['barangay_id']]);
$current_barangay = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Puroks</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <!-- Add SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
</head>

<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <!-- Navigation Buttons -->
        <div class="flex flex-wrap gap-4 mb-6">
            <?php if ($hasFullAccess): ?>
            <a href="manage_census.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                Add Resident
            </a>
            <a href="add_child.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                Add Child
            </a>
            <?php endif; ?>
            <a href="census_records.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                Census Records
            </a>
            <?php if ($hasFullAccess): ?>
            <a href="manage_households.php" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                Manage Households
            </a>
            <a href="manage_puroks.php" class="w-full sm:w-auto text-white bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Manage Puroks</a>
            <a href="temporary_record.php" class="inline-flex items-center px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                Temporary Records
            </a>
            <?php endif; ?>
        </div>

        <section class="bg-white rounded-lg shadow p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between mb-6 gap-6">
                <h2 class="text-2xl font-bold text-blue-800">Manage Puroks</h2>

                <!-- Add New Purok Form -->
                <?php if ($hasFullAccess): ?>
                <div class="bg-gray-50 p-4 rounded-lg lg:w-96">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Add New Purok</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Purok Name</label>
                            <input type="text" name="purok_name" required
                                class="w-full border rounded px-3 py-2"
                                placeholder="Enter purok name">
                        </div>
                        <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">
                            Add Purok
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Puroks Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Purok Name</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Households</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Created</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <?php if (count($puroks) > 0): ?>
                            <?php foreach ($puroks as $purok): ?>
                                <tr class="hover:bg-blue-50 transition">
                                    <td class="px-4 py-3">
                                        <span class="font-medium"><?= htmlspecialchars($purok['name']) ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-gray-600"><?= $purok['household_count'] ?> households</span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600">
                                        <?= date('M j, Y', strtotime($purok['created_at'])) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($hasFullAccess): ?>
                                        <button onclick="editPurok(<?= $purok['id'] ?>, '<?= htmlspecialchars($purok['name']) ?>')"
                                            class="text-blue-600 hover:text-blue-800 font-medium mr-3">Edit</button>
                                        <form method="POST" class="inline" onsubmit="return confirmDelete(event, '<?= htmlspecialchars($purok['name']) ?>');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="purok_id" value="<?= $purok['id'] ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-gray-400">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-gray-400 italic">
                                    No puroks defined for <?= htmlspecialchars($current_barangay['name'] ?? 'this barangay') ?>.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Purok</h3>
                    <?php if ($hasFullAccess): // Only render form if user has access to submit it ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="purok_id" id="edit_purok_id">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Purok Name</label>
                            <input type="text" name="purok_name" id="edit_purok_name" required
                                class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" onclick="closeEditModal()"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Cancel</button>
                            <button type="submit"
                                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Changes</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p class="text-red-500">You do not have permission to edit puroks.</p>
                     <div class="flex justify-end gap-3 mt-4">
                            <button type="button" onclick="closeEditModal()"
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded hover:bg-gray-400">Close</button>
                     </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Edit Purok Modal -->


    <!-- Add SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function editPurok(id, name) {
            document.getElementById('edit_purok_id').value = id;
            document.getElementById('edit_purok_name').value = name;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function confirmDelete(event, purokName) {
            event.preventDefault();
            const form = event.target;

            Swal.fire({
                title: 'Are you sure?',
                text: `Do you want to delete purok "${purokName}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!',
                buttonsStyling: true,
                customClass: {
                    confirmButton: 'swal2-confirm-button',
                    cancelButton: 'swal2-cancel-button'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });

            return false;
        }

        // Show success/error messages using SweetAlert2
        <?php if ($add_error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?= addslashes($add_error) ?>',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK',
                buttonsStyling: true,
                customClass: {
                    confirmButton: 'swal2-confirm-button'
                }
            });
        <?php elseif ($add_success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?= addslashes($add_success) ?>',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK',
                buttonsStyling: true,
                customClass: {
                    confirmButton: 'swal2-confirm-button'
                }
            });
        <?php endif; ?>
    </script>
    <style>
        .swal2-confirm-button {
            background-color: #3085d6 !important;
            color: white !important;
            border: none !important;
            padding: 12px 30px !important;
            border-radius: 5px !important;
            font-size: 1.1em !important;
            font-weight: 500 !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }

        .swal2-confirm-button:hover {
            background-color: #2b7ac9 !important;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2) !important;
        }

        .swal2-cancel-button {
            background-color: #d33 !important;
            color: white !important;
            border: none !important;
            padding: 12px 30px !important;
            border-radius: 5px !important;
            font-size: 1.1em !important;
            font-weight: 500 !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }

        .swal2-cancel-button:hover {
            background-color: #c22 !important;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2) !important;
        }
    </style>
</body>

</html>