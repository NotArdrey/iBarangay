<?php
ob_start(); // Start output buffering
require "../config/dbconn.php";
require_once "../pages/header.php";

// Validate household ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "No household ID specified.";
    header("Location: manage_households.php");
    exit;
}

$household_id = (int)$_GET['id'];
$barangay_id = $_SESSION['barangay_id'];

// Fetch household info
$stmt = $pdo->prepare("
    SELECT h.*, pu.name as purok_name
    FROM households h
    LEFT JOIN purok pu ON h.purok_id = pu.id
    WHERE h.id = ? AND h.barangay_id = ?
");
$stmt->execute([$household_id, $barangay_id]);
$household = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$household) {
    $_SESSION['error'] = "Household not found.";
    header("Location: manage_households.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purok_id = $_POST['purok_id'] ?? null;
    $household_number = trim($_POST['household_number'] ?? '');
    $household_head_person_id = $_POST['household_head_person_id'] ?? null;
    $remove_members = $_POST['remove_member'] ?? [];
    $errors = [];

    // Validate
    if (!$purok_id) $errors[] = "Purok is required.";
    if (!$household_number) $errors[] = "Household number is required.";

    // Check if household number already exists in the same purok (excluding current household)
    if ($household_number !== $household['household_number']) {
        $stmt = $pdo->prepare("
            SELECT id FROM households 
            WHERE household_number = ? 
            AND purok_id = ? 
            AND id != ? 
            AND barangay_id = ?
        ");
        $stmt->execute([$household_number, $purok_id, $household_id, $barangay_id]);
        if ($stmt->fetch()) {
            $errors[] = "Household number already exists in this purok.";
        }
    }

    if (empty($errors)) {
        try {
            // Start transaction
            $pdo->beginTransaction();

            // Update household info
            $stmt = $pdo->prepare("
                UPDATE households 
                SET purok_id = ?, 
                    household_number = ?, 
                    household_head_person_id = ? 
                WHERE id = ? AND barangay_id = ?
            ");
            $stmt->execute([
                $purok_id, 
                $household_number, 
                $household_head_person_id, 
                $household_id, 
                $barangay_id
            ]);

            // Remove selected members
            if (!empty($remove_members)) {
                $in = str_repeat('?,', count($remove_members) - 1) . '?';
                $params = array_merge([$household_id], $remove_members);
                $pdo->prepare("DELETE FROM household_members WHERE household_id = ? AND person_id IN ($in)")->execute($params);
            }

            // Log to audit trail
            $stmt = $pdo->prepare("
                INSERT INTO audit_trails (
                    user_id, action, table_name, record_id, description
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                'UPDATE',
                'households',
                $household_id,
                "Updated household number: {$household_number} in Purok ID: {$purok_id}"
            ]);

            // Commit transaction
            $pdo->commit();

            $_SESSION['success'] = "Household updated successfully.";
            header("Location: manage_households.php");
            exit;
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $errors[] = "Error updating household: " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        header("Location: edit_household.php?id=$household_id");
        exit;
    }
}

// Fetch puroks
$stmt = $pdo->prepare("SELECT id, name FROM purok WHERE barangay_id = ? ORDER BY name");
$stmt->execute([$_SESSION['barangay_id']]);
$puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available persons for household head
$stmt = $pdo->prepare("
    SELECT p.id, p.first_name, p.last_name
    FROM persons p
    LEFT JOIN addresses a ON p.id = a.person_id
    WHERE a.barangay_id = ? OR a.barangay_id IS NULL
    ORDER BY p.last_name, p.first_name
");
$stmt->execute([$barangay_id]);
$available_persons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch household members
$stmt = $pdo->prepare("
    SELECT p.id, p.first_name, p.last_name, hm.is_household_head
    FROM household_members hm
    JOIN persons p ON hm.person_id = p.id
    WHERE hm.household_id = ?
    ORDER BY hm.is_household_head DESC, p.last_name, p.first_name
");
$stmt->execute([$household_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Household</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
<div class="container mx-auto p-4">
    <div class="bg-white rounded-lg shadow p-6 mb-8">
        <h2 class="text-2xl font-bold text-blue-800 mb-4">Edit Household</h2>
        <form method="POST" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block text-sm font-medium">Purok</label>
                    <select name="purok_id" class="mt-1 block w-full border rounded p-2" required>
                        <?php foreach ($puroks as $purok): ?>
                            <option value="<?= $purok['id'] ?>" <?= $household['purok_id'] == $purok['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($purok['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium">Household Number</label>
                    <input type="text" name="household_number" class="mt-1 block w-full border rounded p-2" value="<?= htmlspecialchars($household['household_number']) ?>" required>
                </div>
                <div>
                    <label class="block text-sm font-medium">Household Head</label>
                    <select name="household_head_person_id" class="mt-1 block w-full border rounded p-2">
                        <option value="">-- None --</option>
                        <?php foreach ($available_persons as $person): ?>
                            <option value="<?= $person['id'] ?>" <?= $household['household_head_person_id'] == $person['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($person['first_name'] . ' ' . $person['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-2">Household Members</label>
                <div class="bg-gray-50 border rounded p-2 overflow-x-auto">
                    <?php if (count($members) > 0): ?>
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-4 py-2 text-left">Name</th>
                                    <th class="px-4 py-2 text-left">Role</th>
                                    <th class="px-4 py-2 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $member): ?>
                                    <tr class="border-b">
                                        <td class="px-4 py-2">
                                            <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                                        </td>
                                        <td class="px-4 py-2">
                                            <?= $member['is_household_head'] ? '<span class=\'text-blue-700 font-semibold\'>Head</span>' : 'Member' ?>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <?php if (!$member['is_household_head']): ?>
                                                <button type="submit" name="remove_member[]" value="<?= $member['id'] ?>" class="text-red-600 hover:text-red-800" title="Remove">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <span class="text-gray-400 italic">No members in this household.</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <a href="manage_households.php" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">Cancel</a>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>
</body>
</html> 