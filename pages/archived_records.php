<?php
require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../components/header.php";

// Query to get only archived records
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        hm.household_id AS household_id,
        h.household_number,
        hm.relationship_type_id,
        rt.name as relationship_name,
        hm.is_household_head,
        CONCAT(a.house_no, ' ', a.street, ', ', b.name) as address,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age,
        p.years_of_residency,
        p.resident_type,
        CASE WHEN ci.id IS NOT NULL THEN 1 ELSE 0 END as is_child
    FROM persons p
    LEFT JOIN household_members hm ON p.id = hm.person_id
    LEFT JOIN households h ON hm.household_id = h.id
    LEFT JOIN barangay b ON h.barangay_id = b.id
    LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
    LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
    LEFT JOIN child_information ci ON p.id = ci.person_id
    WHERE h.barangay_id = ?
    AND p.is_archived = 1
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
  <title>Archived Records</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>

<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    <!-- Navigation Buttons -->
    <div class="flex flex-wrap gap-4 mb-6">
      <a href="manage_census.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
        Add Resident
      </a>
      <a href="add_child.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
        Add Child
      </a>
      <a href="census_records.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
        Census Records
      </a>
      <a href="manage_households.php" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
        Manage Households
      </a>
      <a href="manage_puroks.php" class="w-full sm:w-auto text-white bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Manage Puroks</a>
      <a href="temporary_record.php" class="inline-flex items-center px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
        Temporary Records
      </a>
      <a href="archived_records.php" class="inline-flex items-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
        Archived Records
      </a>
    </div>

    <section id="archivedRecords" class="bg-white rounded-lg shadow-sm p-6">
      <h2 class="text-3xl font-bold text-red-800">Archived Records</h2>
      <div class="mb-4 flex justify-between items-center">
        <div>
          <input type="text" id="search-resident" placeholder="Search by name..." class="px-4 py-2 border rounded w-64">
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Record Type</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Age</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Gender</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Civil Status</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Household Number</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Relationship</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Years of Residency</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($residents as $resident):
              $age = $resident['age'] ?? calculateAge($resident['birth_date']);
              $residentType = strtoupper($resident['resident_type'] ?? 'REGULAR');
              $is_child = $resident['is_child'] && $age < 18;
              $category = $is_child ? 'CHILD' : $residentType;
            ?>
              <tr class="resident-row"
                data-name="<?= htmlspecialchars("{$resident['last_name']}, {$resident['first_name']} " .
                              ($resident['middle_name'] ? substr($resident['middle_name'], 0, 1) . '.' : '') .
                              ($resident['suffix'] ? " {$resident['suffix']}" : '')) ?>">
                <td class="px-6 py-4 whitespace-nowrap">
                  <?php if ($is_child): ?>
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                      Child Record
                    </span>
                  <?php else: ?>
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                      Regular Record
                    </span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <?= htmlspecialchars("{$resident['last_name']}, {$resident['first_name']} " .
                    ($resident['middle_name'] ? substr($resident['middle_name'], 0, 1) . '.' : '') .
                    ($resident['suffix'] ? " {$resident['suffix']}" : '')) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap"><?= $age ?></td>
                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['gender']) ?></td>
                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['civil_status']) ?></td>
                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['household_number'] ?? 'Not assigned') ?></td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <?= htmlspecialchars($resident['relationship_name']) ?>
                  <?= $resident['is_household_head'] ? ' (Head)' : '' ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($resident['years_of_residency']) ?> years</td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <?php if ($is_child): ?>
                    <a href="edit_child.php?id=<?= $resident['id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">Edit</a>
                  <?php else: ?>
                    <a href="edit_resident.php?id=<?= $resident['id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">Edit</a>
                  <?php endif; ?>
                  <a href="view_resident.php?id=<?= $resident['id'] ?>"
                    onclick="event.preventDefault(); viewResident(<?= $resident['id'] ?>);"
                    class="text-green-600 hover:text-green-900 mr-3">View</a>
                  <button onclick="restoreResident(<?= $resident['id'] ?>)" class="text-green-600 hover:text-green-900">Restore</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('search-resident');
      const residentRows = document.querySelectorAll('.resident-row');

      // Function to search residents by name
      function searchResidents(searchTerm) {
        const lowercaseSearch = searchTerm.toLowerCase();

        residentRows.forEach(row => {
          const name = row.getAttribute('data-name').toLowerCase();
          row.style.display = name.includes(lowercaseSearch) ? '' : 'none';
        });
      }

      // Add search input event listener
      searchInput.addEventListener('input', function() {
        searchResidents(this.value);
      });
    });

    function viewResident(id) {
      window.location.href = 'view_resident.php?id=' + id;
    }

    function restoreResident(id) {
      Swal.fire({
        title: 'Are you sure?',
        text: "This will restore the resident record and their user account.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, restore it!'
      }).then((result) => {
        if (result.isConfirmed) {
          // Send restore request
          fetch(`../functions/restore_resident.php?id=${id}`, {
            method: 'POST'
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              Swal.fire(
                'Restored!',
                'Resident has been restored successfully.',
                'success'
              ).then(() => {
                window.location.reload();
              });
            } else {
              Swal.fire(
                'Error!',
                data.message || 'Something went wrong.',
                'error'
              );
            }
          })
          .catch(error => {
            Swal.fire(
              'Error!',
              'Something went wrong.',
              'error'
            );
          });
        }
      });
    }
  </script>
</body>
</html> 