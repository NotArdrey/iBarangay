<?php
require "../config/dbconn.php";
require_once "../components/header.php";

if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No household ID specified.";
    echo "<script>window.location.href = 'manage_households.php';</script>";
    exit;
}

$household_id = $_GET['id'];
$barangay_id = $_SESSION['barangay_id'];

try {
    // Get household info
    $stmt = $pdo->prepare("
        SELECT h.*, 
               p.name as purok_name,
               b.name as barangay_name,
               hh.first_name as head_first_name,
               hh.last_name as head_last_name
        FROM households h 
        LEFT JOIN purok p ON h.purok_id = p.id
        LEFT JOIN barangay b ON h.barangay_id = b.id
        LEFT JOIN persons hh ON h.household_head_person_id = hh.id
        WHERE h.id = ? AND h.barangay_id = ?
    ");
    $stmt->execute([$household_id, $barangay_id]);
    $household = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$household) {
        throw new Exception("Household not found.");
    }

    // Get household members
    $stmt = $pdo->prepare("
        SELECT p.*, 
               rt.name as relationship,
               hm.is_household_head
        FROM household_members hm
        JOIN persons p ON hm.person_id = p.id
        JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        WHERE hm.household_id = ?
        ORDER BY hm.is_household_head DESC, rt.name
    ");
    $stmt->execute([$household_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    echo "<script>window.location.href = 'manage_households.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Household Members - <?= htmlspecialchars($household_id) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <!-- Navigation -->
        <div class="flex flex-wrap gap-4 mb-6 mt-6">
            <a href="manage_households.php" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Back to Households</a>
        </div>

        <!-- Household Information -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Household Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <p class="text-gray-600">Household ID: <span class="font-semibold"><?= htmlspecialchars($household_id) ?></span></p>
                    <p class="text-gray-600">Barangay: <span class="font-semibold"><?= htmlspecialchars($household['barangay_name']) ?></span></p>
                    <p class="text-gray-600">Purok: <span class="font-semibold"><?= htmlspecialchars($household['purok_name']) ?></span></p>
                </div>
                <div>
                    <p class="text-gray-600">Household Head: 
                        <span class="font-semibold">
                            <?= $household['head_first_name'] ? htmlspecialchars($household['head_first_name'] . ' ' . $household['head_last_name']) : 'Not assigned' ?>
                        </span>
                    </p>
                    <p class="text-gray-600">Total Members: <span class="font-semibold"><?= count($members) ?></span></p>
                    <p class="text-gray-600">Created: <span class="font-semibold"><?= date('M j, Y', strtotime($household['created_at'])) ?></span></p>
                </div>
            </div>
        </div>

        <!-- Household Members Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="p-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Household Members</h3>
                <?php if (count($members) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Relationship</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Age</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Civil Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Occupation</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($members as $member): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                                                        <?php if ($member['is_household_head']): ?>
                                                            <span class="ml-2 px-2 py-1 text-xs font-semibold text-blue-800 bg-blue-100 rounded-full">Head</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">ID: <?= $member['id'] ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($member['relationship']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= date_diff(date_create($member['birth_date']), date_create('today'))->y ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($member['civil_status']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($member['occupation'] ?? 'Not specified') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-gray-500 italic">No members found in this household.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (isset($_SESSION['error'])): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?= addslashes($_SESSION['error']) ?>',
                confirmButtonColor: '#3085d6'
            });
        </script>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
</body>
</html> 