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
const ROLE_CHAIRPERSON  = 7; // Changed from ROLE_CHIEF
const ROLE_RESIDENT     = 8;
const ROLE_HEALTH_WORKER = 9;


captain_start();


$bid = captain_checkAccess($pdo);


captain_handleActions($pdo, $bid);


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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.7/dist/sweetalert2.all.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <main class="container mx-auto p-6">
        <!-- Signature Management Section -->
        <div class="bg-white rounded-lg shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-signature text-blue-600 mr-2"></i>Digital Signature Management
                </h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Current Signature Display -->
                    <div>
                        <h3 class="text-lg font-medium mb-4">Current Signature</h3>
                        <div id="currentSignatureDisplay" class="border-2 border-dashed border-gray-300 rounded-lg p-4 text-center">
                            <p class="text-gray-500">Loading signature...</p>
                        </div>
                    </div>
                    
                    <!-- Upload New Signature -->
                    <div>
                        <h3 class="text-lg font-medium mb-4">Upload New Signature</h3>
                        <form id="signatureUploadForm" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="upload_signature" value="1">
                            <div class="mb-4">
                                <input type="file" name="signature_file" id="signatureFile" 
                                       accept="image/jpeg,image/png,image/gif" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                                <p class="text-sm text-gray-500 mt-1">Supported formats: JPEG, PNG, GIF. Max size: 2MB</p>
                            </div>
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                                <i class="fas fa-upload mr-2"></i>Upload Signature
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Requests Management Section -->
        <div class="bg-white rounded-lg shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-file-alt text-blue-600 mr-2"></i>Document Requests Reports
                </h2>
                <button onclick="openFinancialReportModal()" 
                        class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                    <i class="fas fa-chart-line mr-2"></i>Generate Financial Report
                </button>
            </div>
        </div>

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
                                    <?= htmlspecialchars(
                                        $user['person_name'] ??
                                        (trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: 'N/A')
                                    ) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($user['email']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($user['role_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= isset($user['status_text']) ? $user['status_text'] : ($user['is_active'] ? 'Active' : 'Inactive') ?>
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

    <!-- Financial Report Modal -->
    <div id="financialReportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden">
        <div class="container mx-auto p-6 flex items-center justify-center h-full">
            <div class="bg-white rounded-lg w-96 p-6">
                <h3 class="text-lg font-medium mb-4">Generate Financial Report</h3>
                <form id="financialReportForm">
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-2">Report Type</label>
                        <select name="report_type" class="w-full border p-2 rounded" required>
                            <option value="monthly">Monthly Report</option>
                            <option value="yearly">Yearly Report</option>
                            <option value="custom">Custom Date Range</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-2">Year</label>
                        <select name="year" class="w-full border p-2 rounded" required>
                            <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4" id="monthField">
                        <label class="block text-sm font-medium mb-2">Month</label>
                        <select name="month" class="w-full border p-2 rounded">
                            <?php 
                            $months = [
                                1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
                            ];
                            foreach($months as $num => $name): ?>
                                <option value="<?= $num ?>" <?= $num == date('n') ? 'selected' : '' ?>><?= $name ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4 hidden" id="customDateFields">
                        <label class="block text-sm font-medium mb-2">Start Date</label>
                        <input type="date" name="start_date" class="w-full border p-2 rounded mb-2">
                        <label class="block text-sm font-medium mb-2">End Date</label>
                        <input type="date" name="end_date" class="w-full border p-2 rounded">
                    </div>
                    
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeFinancialReportModal()" class="px-4 py-2 border rounded">Cancel</button>
                        <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded">Generate Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
        // Load current signature on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadCurrentSignature();
            
            // Financial report form handling
            document.querySelector('select[name="report_type"]').addEventListener('change', function() {
                const monthField = document.getElementById('monthField');
                const customFields = document.getElementById('customDateFields');
                
                if (this.value === 'yearly') {
                    monthField.classList.add('hidden');
                    customFields.classList.add('hidden');
                } else if (this.value === 'custom') {
                    monthField.classList.add('hidden');
                    customFields.classList.remove('hidden');
                } else {
                    monthField.classList.remove('hidden');
                    customFields.classList.add('hidden');
                }
            });
        });

        // Load current signature
        async function loadCurrentSignature() {
            try {
                const response = await fetch('?get_current_signature=1');
                const data = await response.json();
                const display = document.getElementById('currentSignatureDisplay');
                
                if (data.success && data.signature_path) {
                    display.innerHTML = `
                        <img src="../${data.signature_path}" alt="Current Signature" 
                             class="max-w-full max-h-32 mx-auto border rounded">
                        <p class="text-sm text-gray-600 mt-2">Uploaded: ${data.uploaded_at || 'Unknown'}</p>
                    `;
                } else {
                    display.innerHTML = `
                        <i class="fas fa-signature text-4xl text-gray-300 mb-2"></i>
                        <p class="text-gray-500">No signature uploaded</p>
                    `;
                }
            } catch (error) {
                console.error('Error loading signature:', error);
                document.getElementById('currentSignatureDisplay').innerHTML = `
                    <p class="text-red-500">Error loading signature</p>
                `;
            }
        }

        // Handle signature upload
        document.getElementById('signatureUploadForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const fileInput = document.getElementById('signatureFile');
            
            if (!fileInput.files[0]) {
                Swal.fire('Error', 'Please select a file to upload', 'error');
                return;
            }
            
            // Validate file size (2MB)
            if (fileInput.files[0].size > 2 * 1024 * 1024) {
                Swal.fire('Error', 'File size must be less than 2MB', 'error');
                return;
            }
            
            try {
                Swal.fire({
                    title: 'Uploading...',
                    text: 'Please wait while we upload your signature.',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    Swal.fire('Success!', 'Signature uploaded successfully', 'success').then(() => {
                        loadCurrentSignature();
                        this.reset();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to upload signature', 'error');
                }
            } catch (error) {
                Swal.fire('Error', 'An error occurred while uploading', 'error');
            }
        });

        // Financial Report Modal Functions
        function openFinancialReportModal() {
            document.getElementById('financialReportModal').classList.remove('hidden');
        }

        function closeFinancialReportModal() {
            document.getElementById('financialReportModal').classList.add('hidden');
        }

        // Handle financial report generation
        document.getElementById('financialReportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const params = new URLSearchParams();
            
            for (let [key, value] of formData.entries()) {
                if (value) params.append(key, value);
            }
            
            // Open report in new window
            const url = `doc_request.php?action=generate_financial_report&${params.toString()}`;
            window.open(url, '_blank');
            
            closeFinancialReportModal();
        });

        async function toggleStatus(userId, action) {
            try {
                const response = await fetch(`?toggle_status=1&user_id=${userId}&action=${action}`);
                const data = await response.json();
                if (data.success) {
                    Swal.fire('Success!', `User ${action}d successfully`, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to update user status', 'error');
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
                // Send CSRF token as GET param
                const csrf = <?= json_encode($_SESSION['csrf_token']) ?>;
                const response = await fetch(`?delete_id=${id}&csrf_token=${encodeURIComponent(csrf)}`);
                const data = await response.json();
                if (data.success) {
                    Swal.fire('Deleted!', 'User has been deleted.', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete user', 'error');
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
              Swal.fire('Error', json.message || 'Failed to add user', 'error');
            }
          } catch {
            Swal.fire('Error','Server error','error');
          }
        });

        // open & populate edit modal
        async function openEditUserModal(id) {
          try {
            const res = await fetch(`?get_user=1&user_id=${id}`);
            const data = await res.json();
            if (!data.success) {
              Swal.fire('Error', data.message || 'Failed to load user', 'error');
              return;
            }
            const u = data.data;
            document.getElementById('editUserId').value   = u.id;
            document.getElementById('firstNameEdit').value= u.first_name || '';
            document.getElementById('lastNameEdit').value = u.last_name || '';
            document.getElementById('emailEdit').value    = u.email || '';
            document.getElementById('phoneEdit').value    = u.phone || '';
            document.getElementById('roleEdit').value     = u.role_id || '';
            document.getElementById('editUserModal').classList.remove('hidden');
          } catch (e) {
            Swal.fire('Error', 'Failed to load user', 'error');
          }
        }
        function closeEditUserModal() {
          document.getElementById('editUserModal').classList.add('hidden');
        }

        // submit edit form
        document.getElementById('editUserForm')
          .addEventListener('submit', async e => {
            e.preventDefault();
            const form = e.target, data = new FormData(form);
            try {
              const res  = await fetch('', { method:'POST', body:data });
              const json = await res.json();
              if (json.success) {
                Swal.fire('Success', json.message || 'User updated', 'success')
                   .then(()=>location.reload());
              } else {
                Swal.fire('Error', json.message || 'Update failed', 'error');
              }
            } catch {
              Swal.fire('Error', 'Server error', 'error');
            }
          });

        // Auto-fill on person select
        document.getElementById('personSelect').addEventListener('change', async function() {
          ['firstName','lastName','emailField'].forEach(id=>document.getElementById(id).value='');
          if (!this.value) return;
          try {
            const res = await fetch(`?get_person=1&person_id=${this.value}`);
            const data = await res.json();
            if (!data.success) {
              Swal.fire('Error', data.message || 'Failed to load person', 'error');
              return;
            }
            const p = data.data;
            document.getElementById('firstName').value = p.first_name || '';
            document.getElementById('lastName').value  = p.last_name || '';
            document.getElementById('emailField').value = p.email || '';
          } catch {
            Swal.fire('Error', 'Failed to load person', 'error');
          }
        });
    </script>
</body>
</html>