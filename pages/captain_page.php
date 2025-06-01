<?php
// Simplified captain_page.php - User Management Only
session_start();

require '../config/dbconn.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../functions/captain_page.php';

const ROLE_PROGRAMMER   = 1;
const ROLE_SUPER_ADMIN  = 2;
const ROLE_CAPTAIN      = 3;
const ROLE_SECRETARY    = 4;
const ROLE_TREASURER    = 5;
const ROLE_COUNCILOR    = 6;
const ROLE_CHIEF        = 7;
const ROLE_RESIDENT     = 8;

// 1) session, headers, token
captain_start();

// 2) access check & get barangay_id
$bid = captain_checkAccess($pdo);

// 3) handle any AJAX/GET/POST actions
captain_handleActions($pdo, $bid);

// 4) load dropdown and table data
extract(captain_loadData($pdo, $bid));
?>

<?php require_once __DIR__ . "/../components/header.php"; ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Captain Dashboard - User Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <main class="container mx-auto p-6">
        <!-- User Management Section -->
        <div class="mt-8 bg-white rounded-lg shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-users text-blue-600 mr-2"></i>User Management
                </h2>
                <button onclick="openAddUserModal()" 
                        class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                    + Add User
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($user['email']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($user['role_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= $user['status_text'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <button onclick="openEditUserModal(<?= $user['id'] ?>)" 
                                                class="text-yellow-600 hover:text-yellow-900">Edit</button>
                                        <button onclick="toggleStatus(<?= $user['id'] ?>, '<?= $user['is_active'] ? 'deactivate' : 'activate' ?>')" 
                                                class="text-indigo-600 hover:text-indigo-900">
                                            <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                        <button onclick="deleteUser(<?= $user['id'] ?>)" 
                                                class="text-red-600 hover:text-red-900">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Add/Promote User Modal -->
    <div id="addUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden">
      <div class="container mx-auto p-6 flex items-center justify-center h-full">
        <div class="bg-white rounded-lg w-96 p-6">
          <h3 class="text-lg font-medium mb-4">Add or Promote User</h3>
          <form id="addUserForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="add_user" value="1">

            <div class="mb-2">
              <label class="block text-sm">Census Person</label>
              <select id="personSelect" name="person_id" class="w-full border p-2 rounded" required>
                <option value="">Select person</option>
                <?php foreach($persons as $p): ?>
                  <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['person_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="block text-sm">First Name</label>
              <input id="firstName" name="first_name" class="w-full border p-2 rounded" readonly>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Last Name</label>
              <input id="lastName" name="last_name" class="w-full border p-2 rounded" readonly>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Email</label>
              <input id="emailField" name="email" type="email" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Phone</label>
              <input id="phoneField" name="phone" type="tel" class="w-full border p-2 rounded" required
                     placeholder="09XXXXXXXXX or +639XXXXXXXXX">
            </div>
            <div class="mb-2">
              <label class="block text-sm">Password</label>
              <input id="passwordField" name="password" type="password" class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Confirm Password</label>
              <input id="confirmPasswordField" name="confirm_password" type="password" class="w-full border p-2 rounded" required>
            </div>


            <div class="mb-4">
              <label class="block text-sm">Role</label>
              <select name="role_id" class="w-full border p-2 rounded" required>
                <option value="">Select role</option>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= $r['role_id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="flex justify-end space-x-2">
              <button type="button" onclick="closeAddUserModal()" class="px-4 py-2 border rounded">Cancel</button>
              <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="fixed inset-0 bg-black bg-opacity-50 hidden">
      <div class="container mx-auto p-6 flex items-center justify-center h-full">
        <div class="bg-white rounded-lg w-96 p-6">
          <h3 class="text-lg font-medium mb-4">Edit User</h3>
          <form id="editUserForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="user_id" id="editUserId">

            <!-- reuse same fields as the Add form, add unique IDs: -->
            <div class="mb-2">
              <label class="block text-sm">First Name</label>
              <input id="firstNameEdit" name="first_name" 
                     class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Last Name</label>
              <input id="lastNameEdit" name="last_name" 
                     class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Email</label>
              <input id="emailEdit" name="email" type="email" 
                     class="w-full border p-2 rounded" required>
            </div>
            <div class="mb-2">
              <label class="block text-sm">Phone</label>
              <input id="phoneEdit" name="phone" type="tel" 
                     class="w-full border p-2 rounded" required
                     placeholder="09XXXXXXXXX or +639XXXXXXXXX">
            </div>
            <div class="mb-4">
              <label class="block text-sm">Role</label>
              <select id="roleEdit" name="role_id" 
                      class="w-full border p-2 rounded" required>
                <option value="">Select role</option>
                <?php foreach($roles as $r): ?>
                  <option value="<?= $r['role_id'] ?>">
                    <?= htmlspecialchars($r['role_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="flex justify-end space-x-2">
              <button type="button" onclick="closeEditUserModal()" 
                      class="px-4 py-2 border rounded">Cancel</button>
              <button type="submit" 
                      class="bg-blue-500 text-white px-4 py-2 rounded">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
        async function toggleStatus(userId, action) {
            try {
                const response = await fetch(`?toggle_status=1&user_id=${userId}&action=${action}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Success!', `User ${action}d successfully`, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to update user status', 'error');
            }
        }

        async function deleteUser(id) {
            const result = await Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            });
            
            if (!result.isConfirmed) return;

            try {
                const response = await fetch(`?delete_id=${id}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Deleted!', 'User has been deleted.', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'Failed to delete user', 'error');
            }
        }

        function openAddUserModal() {
          document.getElementById('addUserModal').classList.remove('hidden');
        }
        function closeAddUserModal() {
          document.getElementById('addUserModal').classList.add('hidden');
        }
        document.getElementById('addUserForm').addEventListener('submit', async e => {
          e.preventDefault();
          const form = e.target, data = new FormData(form);
          try {
            const res = await fetch('', { method:'POST', body:data });
            const json = await res.json();
            if (json.success) {
              Swal.fire('Success', json.message || 'Done', 'success').then(()=>location.reload());
            } else {
              Swal.fire('Error', json.message, 'error');
            }
          } catch {
            Swal.fire('Error','Server error','error');
          }
        });

        // open & populate edit modal
        async function openEditUserModal(id) {
          const res = await fetch(`?get_user=1&user_id=${id}`);
          const u   = await res.json();
          document.getElementById('editUserId').value   = u.id;
          document.getElementById('firstNameEdit').value= u.first_name;
          document.getElementById('lastNameEdit').value = u.last_name;
          document.getElementById('emailEdit').value    = u.email;
          document.getElementById('phoneEdit').value    = u.phone;
          document.getElementById('roleEdit').value     = u.role_id;
          document.getElementById('editUserModal').classList.remove('hidden');
        }
        function closeEditUserModal() {
          document.getElementById('editUserModal').classList.add('hidden');
        }

        // submit edit form
        document.getElementById('editUserForm')
          .addEventListener('submit', async e => {
            e.preventDefault();
            const form = e.target, data = new FormData(form);
            const res  = await fetch('', { method:'POST', body:data });
            const json = await res.json();
            if (json.success) {
              Swal.fire('Success', json.message, 'success')
                 .then(()=>location.reload());
            } else {
              Swal.fire('Error', json.message, 'error');
            }
          });

        // Auto-fill on person select
        document.getElementById('personSelect').addEventListener('change', async function() {
          ['firstName','lastName','emailField'].forEach(id=>document.getElementById(id).value='');
          if (!this.value) return;
          const res = await fetch(`?get_person=1&person_id=${this.value}`);
          const data = await res.json();
          document.getElementById('firstName').value = data.first_name;
          document.getElementById('lastName').value  = data.last_name;
          document.getElementById('emailField').value = data.email || '';
        });
    </script>
</body>
</html>