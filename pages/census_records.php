<?php
require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../pages/header.php";

// Use the real household ID from the household_members table (not just the household's auto-increment id)
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
        p.resident_type
    FROM persons p
    LEFT JOIN household_members hm ON p.id = hm.person_id
    LEFT JOIN households h ON hm.household_id = h.id
    LEFT JOIN barangay b ON h.barangay_id = b.id
    LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
    LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
    WHERE (h.barangay_id = ? OR h.barangay_id IS NULL)
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
    </div>

    <section id="censusRecords" class="bg-white rounded-lg shadow-sm p-6">
      <h2 class="text-3xl font-bold text-blue-800">Census Records</h2>
      <div class="mb-4 flex justify-between items-center">
        <div class="flex gap-2">
          <button id="btn-all"
            class="filter-btn px-4 py-2 bg-blue-600 text-white rounded transition-colors duration-200"
            data-filter="all">
            All
          </button>
          <button id="btn-regular"
            class="filter-btn px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition-colors duration-200"
            data-filter="regular">
            Regular
          </button>
          <button id="btn-pwd"
            class="filter-btn px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition-colors duration-200"
            data-filter="pwd">
            PWD
          </button>
          <button id="btn-seniors"
            class="filter-btn px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition-colors duration-200"
            data-filter="seniors">
            Seniors
          </button>
          <button id="btn-children"
            class="filter-btn px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300 transition-colors duration-200"
            data-filter="children">
            Children
          </button>
        </div>
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
              
              // Check if this is a child record
              $is_child = false;
              $child_info = null;
              if ($age < 18) {
                $stmt = $pdo->prepare("SELECT * FROM child_information WHERE person_id = ?");
                $stmt->execute([$resident['id']]);
                $child_info = $stmt->fetch(PDO::FETCH_ASSOC);
                $is_child = $child_info !== false;
              }
              
              // For children, we'll use age-based categorization
              $category = ($age < 18) ? 'CHILD' : $residentType;
            ?>
              <tr class="resident-row" 
                  data-category="<?= $category ?>" 
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
                  <a href="view_resident.php?id=<?= $resident['id'] ?>" class="text-green-600 hover:text-green-900 mr-3">View</a>
                  <button onclick="deleteResident(<?= $resident['id'] ?>)" class="text-red-600 hover:text-red-900">Delete</button>
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
      const filterButtons = document.querySelectorAll('.filter-btn');
      const residentRows = document.querySelectorAll('.resident-row');
      const searchInput = document.getElementById('search-resident');

      // Active and inactive button styles
      const activeClasses = ['bg-blue-600', 'text-white'];
      const inactiveClasses = ['bg-gray-200', 'text-gray-700', 'hover:bg-gray-300'];

      // Function to update button styles
      function updateButtonStyles(activeButton) {
        filterButtons.forEach(btn => {
          // Remove all style classes
          btn.classList.remove(...activeClasses, ...inactiveClasses);

          if (btn === activeButton) {
            // Apply active styles
            btn.classList.add(...activeClasses);
          } else {
            // Apply inactive styles
            btn.classList.add(...inactiveClasses);
          }
        });
      }

      // Function to filter residents
      function filterResidents(filterType) {
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach(row => {
          const category = row.getAttribute('data-category');
          let shouldShow = false;

          switch (filterType) {
            case 'all':
              shouldShow = true;
              break;
            case 'regular':
              shouldShow = category === 'REGULAR';
              break;
            case 'pwd':
              shouldShow = category === 'PWD';
              break;
            case 'seniors':
              shouldShow = category === 'SENIOR';
              break;
            case 'children':
              shouldShow = category === 'CHILD';
              break;
          }

          row.style.display = shouldShow ? '' : 'none';
        });
      }

      // Function to search residents by name
      function searchResidents(searchTerm) {
        const lowercaseSearch = searchTerm.toLowerCase();

        residentRows.forEach(row => {
          const name = row.getAttribute('data-name').toLowerCase();
          const isVisible = row.style.display !== 'none';

          if (name.includes(lowercaseSearch)) {
            // Only show if it passes the current filter
            if (isVisible || searchTerm === '') {
              row.style.display = '';
            }
          } else {
            row.style.display = 'none';
          }
        });
      }

      // Add click event listeners to filter buttons
      filterButtons.forEach(button => {
        button.addEventListener('click', function() {
          const filterType = this.getAttribute('data-filter');

          // Update button styles
          updateButtonStyles(this);

          // Filter residents
          filterResidents(filterType);

          // Re-apply search if there's a search term
          const searchTerm = searchInput.value.trim();
          if (searchTerm) {
            searchResidents(searchTerm);
          }
        });
      });

      // Add search functionality
      searchInput.addEventListener('input', function() {
        const searchTerm = this.value.trim();

        if (searchTerm === '') {
          // If search is empty, just apply the current filter
          const activeButton = document.querySelector('.filter-btn.bg-blue-600');
          const filterType = activeButton.getAttribute('data-filter');
          filterResidents(filterType);
        } else {
          // First apply the current filter, then search
          const activeButton = document.querySelector('.filter-btn.bg-blue-600');
          const filterType = activeButton.getAttribute('data-filter');
          filterResidents(filterType);
          searchResidents(searchTerm);
        }
      });

      // Initialize with "All" filter active
      const allButton = document.getElementById('btn-all');
      updateButtonStyles(allButton);
      filterResidents('all');
    });

    function deleteResident(id) {
      Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
      }).then((result) => {
        if (result.isConfirmed) {
          // Send delete request
          fetch(`../functions/delete_resident.php?id=${id}`, {
            method: 'DELETE'
          })
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              Swal.fire(
                'Deleted!',
                'Resident has been deleted.',
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