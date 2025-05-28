<?php
require_once 'header.php';
require_once '../config/dbconn.php';

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    try {
        if (!isset($_POST['record_id']) || empty($_POST['record_id'])) {
            echo json_encode(['success' => false, 'message' => 'Record ID is required']);
            exit;
        }

        $record_id = $_POST['record_id'];
        error_log("Attempting to delete record ID: " . $record_id);

        // First check if the record exists
        $checkStmt = $pdo->prepare("SELECT id FROM temporary_records WHERE id = ?");
        $checkStmt->execute([$record_id]);
        
        if (!$checkStmt->fetch()) {
            error_log("Record not found: " . $record_id);
            echo json_encode(['success' => false, 'message' => 'Record not found']);
            exit;
        }

        // If record exists, proceed with deletion
        $stmt = $pdo->prepare("DELETE FROM temporary_records WHERE id = ?");
        $result = $stmt->execute([$record_id]);
        
        if ($result) {
            error_log("Successfully deleted record ID: " . $record_id);
            echo json_encode(['success' => true, 'message' => 'Record deleted successfully']);
        } else {
            error_log("Failed to delete record ID: " . $record_id);
            echo json_encode(['success' => false, 'message' => 'Failed to delete record']);
        }
        exit;
    } catch (PDOException $e) {
        error_log("Delete Error for record ID " . ($_POST['record_id'] ?? 'unknown') . ": " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['record_id']) && !empty($_POST['record_id'])) {
            // Update existing record
            $stmt = $pdo->prepare("
                UPDATE temporary_records SET
                    last_name = ?, suffix = ?, first_name = ?, middle_name = ?, 
                    date_of_birth = ?, place_of_birth = ?, 
                    months_residency = ?, days_residency = ?,
                    house_number = ?, street = ?, barangay = ?, municipality = ?, province = ?, region = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $_POST['last_name'],
                $_POST['suffix'],
                $_POST['first_name'],
                $_POST['middle_name'],
                $_POST['date_of_birth'],
                $_POST['place_of_birth'],
                $_POST['months_residency'],
                $_POST['days_residency'],
                $_POST['house_number'],
                $_POST['street'],
                $_POST['barangay'],
                $_POST['municipality'],
                $_POST['province'],
                $_POST['region'],
                $_POST['record_id']
            ]);

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Temporary record has been updated successfully.',
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK',
                    customClass: {
                        confirmButton: 'swal2-confirm-button'
                    }
                });
            </script>";
        } else {
            // Insert new record
            $stmt = $pdo->prepare("
                INSERT INTO temporary_records (
                    last_name, suffix, first_name, middle_name, 
                    date_of_birth, place_of_birth, 
                    months_residency, days_residency,
                    house_number, street, barangay, municipality, province, region,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $_POST['last_name'],
                $_POST['suffix'],
                $_POST['first_name'],
                $_POST['middle_name'],
                $_POST['date_of_birth'],
                $_POST['place_of_birth'],
                $_POST['months_residency'],
                $_POST['days_residency'],
                $_POST['house_number'],
                $_POST['street'],
                $_POST['barangay'],
                $_POST['municipality'],
                $_POST['province'],
                $_POST['region']
            ]);

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Temporary record has been added successfully.',
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK',
                    customClass: {
                        confirmButton: 'swal2-confirm-button'
                    }
                });
            </script>";
        }
    } catch (PDOException $e) {
        echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: 'Failed to process temporary record. Please try again.',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK',
                customClass: {
                    confirmButton: 'swal2-confirm-button'
                }
            });
        </script>";
    }
}

// Fetch existing records
$stmt = $pdo->query("
    SELECT * FROM temporary_records 
    ORDER BY created_at DESC
");
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto px-4 py-8">
    <!-- Tabs -->
    <div class="mb-6 flex space-x-2">
        <button id="tabForm" class="tab-btn px-4 py-2 rounded-t bg-blue-600 text-white font-semibold focus:outline-none">Add Temporary Record</button>
        <button id="tabList" class="tab-btn px-4 py-2 rounded-t bg-gray-200 text-gray-700 font-semibold focus:outline-none">Temporary Records List</button>
    </div>
    <!-- Form Tab Content -->
    <div id="formTabContent">
        <div class="border border-gray-200 rounded-b-lg p-6 mb-6 bg-white">
            <h2 class="text-3xl font-bold text-blue-800 mb-6">Temporary Record Form</h2>
            <form id="temporaryRecordForm" method="POST" class="space-y-4">
                <input type="hidden" name="record_id" id="record_id">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <!-- Last Name -->
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name *</label>
                        <input type="text" name="last_name" id="last_name" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Suffix -->
                    <div>
                        <label for="suffix" class="block text-sm font-medium text-gray-700">Suffix</label>
                        <input type="text" name="suffix" id="suffix" maxlength="5"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            placeholder="e.g. Jr, Sr, II">
                    </div>
                    <!-- First Name -->
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700">First Name *</label>
                        <input type="text" name="first_name" id="first_name" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Middle Name -->
                    <div>
                        <label for="middle_name" class="block text-sm font-medium text-gray-700">Middle Name</label>
                        <input type="text" name="middle_name" id="middle_name"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Date of Birth -->
                    <div>
                        <label for="date_of_birth" class="block text-sm font-medium text-gray-700">Date of Birth *</label>
                        <input type="date" name="date_of_birth" id="date_of_birth" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Age (Auto-calculated) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Age</label>
                        <input type="text" id="age" readonly
                            class="mt-1 block w-full rounded-md border-gray-300 bg-gray-50 shadow-sm">
                    </div>
                    <!-- Place of Birth -->
                    <div>
                        <label for="place_of_birth" class="block text-sm font-medium text-gray-700">Place of Birth *</label>
                        <input type="text" name="place_of_birth" id="place_of_birth" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- House Number -->
                    <div>
                        <label for="house_number" class="block text-sm font-medium text-gray-700">House Number *</label>
                        <input type="text" name="house_number" id="house_number" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Street -->
                    <div>
                        <label for="street" class="block text-sm font-medium text-gray-700">Street *</label>
                        <input type="text" name="street" id="street" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Barangay -->
                    <div>
                        <label for="barangay" class="block text-sm font-medium text-gray-700">Barangay *</label>
                        <input type="text" name="barangay" id="barangay" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Municipality -->
                    <div>
                        <label for="municipality" class="block text-sm font-medium text-gray-700">Municipality *</label>
                        <input type="text" name="municipality" id="municipality" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Province -->
                    <div>
                        <label for="province" class="block text-sm font-medium text-gray-700">Province *</label>
                        <input type="text" name="province" id="province" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Region -->
                    <div>
                        <label for="region" class="block text-sm font-medium text-gray-700">Region *</label>
                        <input type="text" name="region" id="region" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <!-- Residency Duration -->
                    <div class="col-span-1 md:col-span-2 lg:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Length of Residency *</label>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="months_residency" class="block text-sm text-gray-600">Months (0-11)</label>
                                <input type="number" name="months_residency" id="months_residency" min="0" max="11"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    oninput="validateMonths(this)">
                            </div>
                            <div>
                                <label for="days_residency" class="block text-sm text-gray-600">Days (0-30)</label>
                                <input type="number" name="days_residency" id="days_residency" min="0" max="30"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-6">
                    <button type="submit" id="submitBtn"
                        class="w-full px-4 py-3 bg-blue-600 text-white rounded-md text-lg font-semibold hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Submit Record
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- List Tab Content -->
    <div id="listTabContent" class="hidden">
        <section class="mb-6">
            <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                <div class="flex items-center space-x-3">
                    <h3 class="text-3xl font-bold text-blue-800">Temporary Records</h3>
                </div>
                <input id="searchTemporaryInput" type="text" placeholder="Search temporary records..." class="p-2 border rounded w-1/3">
            </div>
        </section>
        <div class="bg-white rounded-lg shadow border border-gray-200 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Address</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Length of Residency</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Added</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="temporaryRecordsTableBody">
                    <?php foreach ($records as $record): ?>
                        <tr
                            data-id="<?= htmlspecialchars($record['id']) ?>"
                            data-lastname="<?= htmlspecialchars($record['last_name']) ?>"
                            data-firstname="<?= htmlspecialchars($record['first_name']) ?>"
                            data-middlename="<?= htmlspecialchars($record['middle_name']) ?>"
                            data-suffix="<?= htmlspecialchars($record['suffix']) ?>"
                            data-dob="<?= htmlspecialchars($record['date_of_birth']) ?>"
                            data-pob="<?= htmlspecialchars($record['place_of_birth']) ?>"
                            data-housenumber="<?= htmlspecialchars($record['house_number']) ?>"
                            data-street="<?= htmlspecialchars($record['street']) ?>"
                            data-barangay="<?= htmlspecialchars($record['barangay']) ?>"
                            data-municipality="<?= htmlspecialchars($record['municipality']) ?>"
                            data-province="<?= htmlspecialchars($record['province']) ?>"
                            data-region="<?= htmlspecialchars($record['region']) ?>"
                            data-monthsresidency="<?= htmlspecialchars($record['months_residency']) ?>"
                            data-daysresidency="<?= htmlspecialchars($record['days_residency']) ?>"
                            data-createdat="<?= htmlspecialchars(date('M d, Y', strtotime($record['created_at']))) ?>">
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?= htmlspecialchars($record['last_name'] . ', ' . $record['first_name'] . ' ' .
                                    ($record['middle_name'] ? $record['middle_name'][0] . '.' : '') .
                                    ($record['suffix'] ? ' ' . $record['suffix'] : '')) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?= htmlspecialchars($record['house_number'] . ', ' . $record['street'] . ', ' . $record['barangay'] . ', ' . $record['municipality'] . ', ' . $record['province'] . ', ' . $record['region']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?= htmlspecialchars($record['months_residency'] . 'm ' . $record['days_residency'] . 'd') ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?= date('M d, Y', strtotime($record['created_at'])) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <div class="flex items-center space-x-4">
                                    <button class="viewBtn text-blue-600 hover:text-blue-900 focus:underline">View</button>
                                    <button class="editBtn text-green-600 hover:text-green-800 focus:underline">Edit</button>
                                    <button class="deleteBtn text-red-600 hover:text-red-800 focus:underline">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal for viewing temporary record details -->
    <div id="viewModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-gray-900 bg-opacity-60"></div>
        <div class="relative z-10 flex items-center justify-center min-h-screen">
            <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-10 relative">
                <button id="closeViewModal" class="absolute top-4 right-4 text-gray-400 hover:text-blue-700 text-3xl transition-colors duration-150 focus:outline-none">&times;</button>
                <h2 class="text-3xl font-extrabold text-blue-800 mb-6 border-b pb-3">Temporary Record Details</h2>
                <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                    <div class="font-semibold text-gray-700">Name:</div>
                    <div id="viewFullName" class="text-gray-900 whitespace-normal"></div>
                    <div class="font-semibold text-gray-700">Date of Birth:</div>
                    <div id="viewDOB" class="text-gray-900"></div>
                    <div class="font-semibold text-gray-700">Place of Birth:</div>
                    <div id="viewPOB" class="text-gray-900 whitespace-normal"></div>
                    <div class="font-semibold text-gray-700">Address:</div>
                    <div id="viewAddress" class="text-gray-900 whitespace-normal break-words"></div>
                    <div class="font-semibold text-gray-700">Length of Residency:</div>
                    <div id="viewResidency" class="text-gray-900"></div>
                    <div class="font-semibold text-gray-700">Date Added:</div>
                    <div id="viewDateAdded" class="text-gray-900"></div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add custom styles for SweetAlert2 buttons
        const style = document.createElement('style');
        style.textContent = `
            .swal2-confirm-button {
                background-color: #3085d6 !important;
                color: white !important;
                font-weight: 600 !important;
                padding: 12px 30px !important;
                border-radius: 6px !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
                transition: all 0.3s ease !important;
            }
            .swal2-confirm-button:hover {
                background-color: #2b78c4 !important;
                transform: translateY(-1px) !important;
                box-shadow: 0 4px 6px rgba(0,0,0,0.15) !important;
            }
            .swal2-cancel-button {
                background-color: #dc3545 !important;
                color: white !important;
                font-weight: 600 !important;
                padding: 12px 30px !important;
                border-radius: 6px !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
                transition: all 0.3s ease !important;
            }
            .swal2-cancel-button:hover {
                background-color: #c82333 !important;
                transform: translateY(-1px) !important;
                box-shadow: 0 4px 6px rgba(0,0,0,0.15) !important;
            }
        `;
        document.head.appendChild(style);

        // Delete functionality
        document.querySelectorAll('.deleteBtn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const row = btn.closest('tr');
                const recordId = row.getAttribute('data-id');
                const recordName = row.querySelector('td:first-child').textContent;

                console.log('Attempting to delete record:', recordId); // Debug log

                Swal.fire({
                    title: 'Are you sure?',
                    text: `Do you want to delete the record for "${recordName}"?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#dc3545',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'No, cancel',
                    customClass: {
                        confirmButton: 'swal2-confirm-button',
                        cancelButton: 'swal2-cancel-button'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Send delete request
                        const formData = new FormData();
                        formData.append('action', 'delete');
                        formData.append('record_id', recordId);

                        console.log('Sending delete request for record:', recordId); // Debug log

                        fetch(window.location.href, {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(response => {
                            console.log('Response status:', response.status); // Debug log
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log('Response data:', data); // Debug log
                            if (data.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Deleted!',
                                    text: data.message,
                                    confirmButtonColor: '#3085d6',
                                    customClass: {
                                        confirmButton: 'swal2-confirm-button'
                                    }
                                }).then(() => {
                                    // Remove the row from the table
                                    row.remove();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error!',
                                    text: data.message || 'Failed to delete record',
                                    confirmButtonColor: '#3085d6',
                                    customClass: {
                                        confirmButton: 'swal2-confirm-button'
                                    }
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Delete error:', error);
                            window.location.reload();
                        });
                    }
                });
            });
        });

        // Search functionality
        const searchInput = document.getElementById('searchTemporaryInput');
        const tableBody = document.getElementById('temporaryRecordsTableBody');
        const rows = tableBody.getElementsByTagName('tr');

        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const searchTerm = this.value.toLowerCase().trim();

                Array.from(rows).forEach(row => {
                    const name = row.querySelector('td:first-child').textContent.toLowerCase();
                    const address = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                    const residency = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                    const dateAdded = row.querySelector('td:nth-child(4)').textContent.toLowerCase();

                    const matches = name === searchTerm || 
                                  address === searchTerm || 
                                  residency === searchTerm || 
                                  dateAdded === searchTerm;

                    row.style.display = matches ? '' : 'none';
                });

                // Show "no results" message if no matches found
                const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
                const noResultsRow = document.getElementById('noResultsRow');
                
                if (visibleRows.length === 0 && searchTerm !== '') {
                    if (!noResultsRow) {
                        const newRow = document.createElement('tr');
                        newRow.id = 'noResultsRow';
                        newRow.innerHTML = `
                            <td colspan="5" class="px-4 py-3 text-center text-gray-500">
                                No records found matching "${searchTerm}"
                            </td>
                        `;
                        tableBody.appendChild(newRow);
                    }
                } else {
                    if (noResultsRow) {
                        noResultsRow.remove();
                    }
                }
            }
        });

        // Clear search when input is cleared
        searchInput.addEventListener('input', function() {
            if (this.value === '') {
                Array.from(rows).forEach(row => {
                    row.style.display = '';
                });
                const noResultsRow = document.getElementById('noResultsRow');
                if (noResultsRow) {
                    noResultsRow.remove();
                }
            }
        });

        // Auto-uppercase for relevant fields
        const upperFields = [
            'last_name', 'suffix', 'first_name', 'middle_name', 'place_of_birth',
            'house_number', 'street', 'barangay', 'municipality', 'province', 'region'
        ];
        upperFields.forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
        });

        // Calculate age when date of birth changes
        const dobInput = document.getElementById('date_of_birth');
        const ageInput = document.getElementById('age');

        dobInput.addEventListener('change', function() {
            const dob = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();

            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                age--;
            }

            ageInput.value = age;
        });

        // Form validation
        const form = document.getElementById('temporaryRecordForm');
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Basic validation
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('border-red-500');
                } else {
                    field.classList.remove('border-red-500');
                }
            });

            // Validate months
            const monthsInput = document.getElementById('months_residency');
            if (parseInt(monthsInput.value) > 11) {
                isValid = false;
                monthsInput.classList.add('border-red-500');
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Months cannot exceed 11.',
                    confirmButtonColor: '#3085d6',
                    customClass: {
                        confirmButton: 'swal2-confirm-button'
                    }
                });
            }

            if (isValid) {
                form.submit();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill in all required fields correctly.',
                    confirmButtonColor: '#3085d6',
                    customClass: {
                        confirmButton: 'swal2-confirm-button'
                    }
                });
            }
        });

        // Function to validate months input
        window.validateMonths = function(input) {
            const value = parseInt(input.value);
            if (value > 11) {
                input.value = 11;
                Swal.fire({
                    icon: 'warning',
                    title: 'Invalid Input',
                    text: 'Months cannot exceed 11.',
                    confirmButtonColor: '#3085d6',
                    customClass: {
                        confirmButton: 'swal2-confirm-button'
                    }
                });
            }
        };

        // Edit button click handler
        document.querySelectorAll('.editBtn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const row = btn.closest('tr');
                const recordId = row.getAttribute('data-id');
                
                // Populate form fields
                document.getElementById('record_id').value = recordId;
                document.getElementById('last_name').value = row.getAttribute('data-lastname');
                document.getElementById('suffix').value = row.getAttribute('data-suffix') || '';
                document.getElementById('first_name').value = row.getAttribute('data-firstname');
                document.getElementById('middle_name').value = row.getAttribute('data-middlename') || '';
                document.getElementById('date_of_birth').value = row.getAttribute('data-dob');
                document.getElementById('place_of_birth').value = row.getAttribute('data-pob');
                document.getElementById('house_number').value = row.getAttribute('data-housenumber');
                document.getElementById('street').value = row.getAttribute('data-street');
                document.getElementById('barangay').value = row.getAttribute('data-barangay');
                document.getElementById('municipality').value = row.getAttribute('data-municipality');
                document.getElementById('province').value = row.getAttribute('data-province');
                document.getElementById('region').value = row.getAttribute('data-region');
                document.getElementById('months_residency').value = row.getAttribute('data-monthsresidency');
                document.getElementById('days_residency').value = row.getAttribute('data-daysresidency');

                // Update button text
                submitBtn.textContent = 'Update Record';

                // Switch to form tab
                document.getElementById('tabForm').click();

                // Calculate and set age
                const dob = new Date(row.getAttribute('data-dob'));
                const today = new Date();
                let age = today.getFullYear() - dob.getFullYear();
                const monthDiff = today.getMonth() - dob.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                    age--;
                }
                document.getElementById('age').value = age;
            });
        });

        // Reset form when switching to form tab
        document.getElementById('tabForm').addEventListener('click', function() {
            if (!document.getElementById('record_id').value) {
                form.reset();
                submitBtn.textContent = 'Submit Record';
            }
        });

        // View modal logic
        const viewModal = document.getElementById('viewModal');
        const closeViewModal = document.getElementById('closeViewModal');
        document.querySelectorAll('.viewBtn').forEach(function(btn, idx) {
            btn.addEventListener('click', function() {
                const row = btn.closest('tr');
                // Compose full name
                const lastName = row.querySelector('td').textContent.split(',')[0].trim();
                const rest = row.querySelector('td').textContent.split(',')[1].trim();
                const [firstName, middleInitialAndSuffix] = rest.split(' ', 2);
                const middleName = row.getAttribute('data-middlename') || '';
                const suffix = row.getAttribute('data-suffix') || '';
                let fullName = lastName + ', ' + firstName;
                if (middleName) fullName += ' ' + middleName;
                if (suffix) fullName += ' ' + suffix;
                document.getElementById('viewFullName').textContent = fullName;
                // Format DOB
                let dob = row.getAttribute('data-dob') || '';
                if (dob) {
                    const d = new Date(dob);
                    document.getElementById('viewDOB').textContent = d.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });
                } else {
                    document.getElementById('viewDOB').textContent = '';
                }
                document.getElementById('viewPOB').textContent = row.getAttribute('data-pob') || '';
                // Compose address
                const address = [
                    row.getAttribute('data-housenumber'),
                    row.getAttribute('data-street'),
                    row.getAttribute('data-barangay'),
                    row.getAttribute('data-municipality'),
                    row.getAttribute('data-province'),
                    row.getAttribute('data-region')
                ].filter(Boolean).join(', ');
                document.getElementById('viewAddress').textContent = address;
                // Residency
                document.getElementById('viewResidency').textContent = (row.getAttribute('data-monthsresidency') || '0') + 'm ' + (row.getAttribute('data-daysresidency') || '0') + 'd';
                // Date added
                document.getElementById('viewDateAdded').textContent = row.getAttribute('data-createdat') || '';
                viewModal.classList.remove('hidden');
            });
        });
        closeViewModal.addEventListener('click', function() {
            viewModal.classList.add('hidden');
        });
        viewModal.addEventListener('click', function(e) {
            if (e.target === viewModal) viewModal.classList.add('hidden');
        });

        // Tab switching logic
        const tabForm = document.getElementById('tabForm');
        const tabList = document.getElementById('tabList');
        const formTabContent = document.getElementById('formTabContent');
        const listTabContent = document.getElementById('listTabContent');
        tabForm.addEventListener('click', function() {
            tabForm.classList.add('bg-blue-600', 'text-white');
            tabForm.classList.remove('bg-gray-200', 'text-gray-700');
            tabList.classList.remove('bg-blue-600', 'text-white');
            tabList.classList.add('bg-gray-200', 'text-gray-700');
            formTabContent.classList.remove('hidden');
            listTabContent.classList.add('hidden');
        });
        tabList.addEventListener('click', function() {
            tabList.classList.add('bg-blue-600', 'text-white');
            tabList.classList.remove('bg-gray-200', 'text-gray-700');
            tabForm.classList.remove('bg-blue-600', 'text-white');
            tabForm.classList.add('bg-gray-200', 'text-gray-700');
            formTabContent.classList.add('hidden');
            listTabContent.classList.remove('hidden');
        });
    });
</script>