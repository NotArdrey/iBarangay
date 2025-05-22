<?php
require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../pages/header.php";

// Use the real household ID from the household_members table (not just the household's auto-increment id)
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        hm.household_id AS household_id, 
        hm.relationship_to_head, 
        hm.is_household_head,
        CONCAT(a.house_no, ' ', a.street, ', ', b.name) as address,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
    FROM persons p
    JOIN household_members hm ON p.id = hm.person_id
    JOIN households h ON hm.household_id = h.id
    JOIN barangay b ON h.barangay_id = b.id
    LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
    WHERE h.barangay_id = ?
    ORDER BY p.last_name, p.first_name
");
$stmt->execute([$_SESSION['barangay_id']]);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Census Records</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    <?php include "../pages/header.php"; ?>
    <!-- Navigation Buttons for Census Pages -->
    <div class="flex flex-wrap gap-4 mb-6 mt-6">
        <a href="manage_census.php" class="px-4 py-2 bg-blue-700 text-white rounded hover:bg-blue-800 transition">Add Resident</a>
        <a href="add_child.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">Add Child</a>
        <a href="census_records.php" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">Census Records</a>
        <a href="manage_households.php" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700 transition">Manage Households</a>
    </div>
    <section id="censusRecords" class="bg-white rounded-lg shadow-sm p-6">
      <h2 class="text-2xl font-bold mb-4">Census Records</h2>
      <div class="mb-4 flex justify-between items-center">
        <div class="flex gap-2">
          <button id="btn-all" class="px-4 py-2 bg-blue-600 text-white rounded">All</button>
          <button id="btn-seniors" class="px-4 py-2 bg-gray-200 rounded">Seniors</button>
          <button id="btn-children" class="px-4 py-2 bg-gray-200 rounded">Children</button>
        </div>
        <div>
          <input type="text" id="search-resident" placeholder="Search by name..." class="px-4 py-2 border rounded w-64">
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Age</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Gender</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Civil Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Household ID</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Relationship</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach($residents as $resident): 
                  $age = $resident['age'] ?? calculateAge($resident['birth_date']);
                  $category = ($age >= 60) ? 'Senior' : (($age < 18) ? 'Child' : 'Adult');
            ?>
            <tr data-category="<?= $category ?>">
              <td class="px-6 py-4 whitespace-nowrap">
                <?= htmlspecialchars("{$resident['last_name']}, {$resident['first_name']} " . 
                    ($resident['middle_name'] ? substr($resident['middle_name'], 0, 1) . '.' : '') . 
                    ($resident['suffix'] ? " {$resident['suffix']}" : '')) ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap"><?= $age ?></td>
              <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['gender']) ?></td>
              <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['civil_status']) ?></td>
              <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['household_id']) ?></td>
              <td class="px-6 py-4 whitespace-nowrap">
                <?= htmlspecialchars($resident['relationship_to_head']) ?> 
                <?= $resident['is_household_head'] ? ' (Head)' : '' ?>
              </td>
              <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['address'] ?? 'No address provided') ?></td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="<?= $category === 'Senior' ? 'bg-purple-100 text-purple-800' : ($category === 'Child' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?> px-2 py-1 rounded text-xs">
                  <?= $category ?>
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <a href="view_resident.php?id=<?= $resident['person_id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                <a href="edit_resident.php?id=<?= $resident['person_id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                <a href="javascript:void(0)" onclick="confirmDelete(<?= $resident['person_id'] ?>)" class="text-red-600 hover:text-red-900">Delete</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
  <script>
    // JavaScript for filtering and sorting can be added here
  </script>
</body>
</html>