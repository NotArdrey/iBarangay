<?php

require "../config/dbconn.php";
require "../functions/manage_census.php";

// Check admin permissions
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

$current_admin_id = $_SESSION['user_id'];
$barangay_id = $_SESSION['barangay_id'];

// Fetch households for selection
$stmt = $pdo->prepare("
    SELECT id AS household_id, household_head_person_id 
    FROM households 
    WHERE barangay_id = ? 
    ORDER BY id
");
$stmt->execute([$barangay_id]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing census data with detailed information
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        h.id AS household_id, 
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
$stmt->execute([$barangay_id]);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangay details for header
$stmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
$stmt->execute([$barangay_id]);
$barangay = $stmt->fetch(PDO::FETCH_ASSOC);
require_once "../pages/header.php";

// --- ADD RESIDENT LOGIC ---
$add_error = '';
$add_success = '';
$form_data = []; // Store form data for repopulation on error

// Check for session messages (from redirects like delete operations)
if (isset($_SESSION['error'])) {
    $add_error = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    $add_success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Collect and sanitize input data
        $data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'suffix' => trim($_POST['suffix'] ?? ''),
            'birth_date' => $_POST['birth_date'] ?? '',
            'birth_place' => trim($_POST['birth_place'] ?? ''),
            'gender' => $_POST['gender'] ?? '',
            'civil_status' => $_POST['civil_status'] ?? '',
            'citizenship' => trim($_POST['citizenship'] ?? 'Filipino'),
            'religion' => trim($_POST['religion'] ?? ''),
            'education_level' => trim($_POST['education_level'] ?? ''),
            'occupation' => trim($_POST['occupation'] ?? ''),
            'monthly_income' => $_POST['monthly_income'] ?? '',
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'house_no' => trim($_POST['house_no'] ?? ''),
            'street' => trim($_POST['street'] ?? ''),
            'subdivision' => trim($_POST['subdivision'] ?? ''),
            'block_lot' => trim($_POST['block_lot'] ?? ''),
            'phase' => trim($_POST['phase'] ?? ''),
            'municipality' => trim($_POST['municipality'] ?? 'SAN RAFAEL'),
            'province' => trim($_POST['province'] ?? 'BULACAN'),
            'residency_type' => $_POST['residency_type'] ?? '',
            'years_in_san_rafael' => $_POST['years_in_san_rafael'] ?? '',
            'household_id' => $_POST['household_id'] ?? '',
            'relationship' => $_POST['relationship'] ?? '',
            'is_household_head' => isset($_POST['is_household_head']) ? 1 : 0,
            'resident_type' => $_POST['resident_type'] ?? 'regular',
        ];
        
        // Store form data for repopulation
        $form_data = $data;
        
        // Basic validation
        $validation_errors = [];
        
        if (empty($data['first_name'])) {
            $validation_errors[] = "First name is required.";
        }
        
        if (empty($data['last_name'])) {
            $validation_errors[] = "Last name is required.";
        }
        
        if (empty($data['birth_date'])) {
            $validation_errors[] = "Birth date is required.";
        } elseif (!strtotime($data['birth_date'])) {
            $validation_errors[] = "Invalid birth date format.";
        }
        
        if (empty($data['birth_place'])) {
            $validation_errors[] = "Birth place is required.";
        }
        
        if (empty($data['gender'])) {
            $validation_errors[] = "Gender is required.";
        }
        
        if (empty($data['civil_status'])) {
            $validation_errors[] = "Civil status is required.";
        }
        
        if (empty($data['household_id'])) {
            $validation_errors[] = "Household ID is required.";
        }
        
        if (!empty($validation_errors)) {
            $add_error = implode("<br>", $validation_errors);
        } else {
            // Use the saveResident function to add the resident
            $result = saveResident($pdo, $data, $barangay_id);
            
            if ($result['success']) {
                $add_success = $result['message'];
                // Clear form data on success
                $form_data = [];
                
                // Refresh the residents list
                $stmt = $pdo->prepare("
                    SELECT 
                        p.*, 
                        h.id AS household_id, 
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
                $stmt->execute([$barangay_id]);
                $residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $add_error = $result['message'];
            }
        }
    } catch (Exception $e) {
        $add_error = "An error occurred while saving the resident data: " . $e->getMessage();
        error_log("Error saving resident: " . $e->getMessage());
    }
}

// Helper function to get form value
function getFormValue($key, $form_data, $default = '') {
    return isset($form_data[$key]) ? htmlspecialchars($form_data[$key]) : $default;
}

// Helper function to check if option is selected
function isSelected($value, $form_data, $key) {
    return (isset($form_data[$key]) && $form_data[$key] == $value) ? 'selected' : '';
}

// Helper function to check if radio is checked
function isChecked($value, $form_data, $key) {
    return (isset($form_data[$key]) && $form_data[$key] == $value) ? 'checked' : '';
}

// Helper function to check if checkbox is checked
function isCheckboxChecked($form_data, $key) {
    return (isset($form_data[$key]) && $form_data[$key]) ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Census Data</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .error-message {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .success-message {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <!-- Navigation Buttons for Census Pages -->
        <div class="flex flex-wrap gap-4 mb-6 mt-6">
            <a href="manage_census.php" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Add Resident</a>
            <a href="add_child.php" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Add Child</a>
            <a href="census_records.php" class="w-full sm:w-auto text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Census Records</a>
            <a href="manage_households.php" class="pw-full sm:w-auto text-white bg-purple-600 hover:bg-purple-700 focus:ring-4 focus:ring-purple-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Manage Households</a>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($add_error): ?>
            <div class="error-message">
                <strong>Error:</strong> <?= $add_error ?>
            </div>
        <?php elseif ($add_success): ?>
            <div class="success-message">
                <strong>Success:</strong> <?= $add_success ?>
            </div>
        <?php endif; ?>

        <!-- Regular Resident Form -->
        <div id="add-resident" class="tab-content active bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">Add New Resident</h2>
            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">Resident Type</label>
                <select id="residentTypeSelect" name="resident_type" class="border rounded p-2 w-full md:w-1/3" form="residentForm">
                    <option value="regular" <?= isSelected('regular', $form_data, 'resident_type') ?: 'selected' ?>>Regular</option>
                    <option value="senior" <?= isSelected('senior', $form_data, 'resident_type') ?>>Senior Citizen</option>
                    <option value="pwd" <?= isSelected('pwd', $form_data, 'resident_type') ?>>Person with Disability (PWD)</option>
                </select>
            </div>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="residentForm" autocomplete="off">
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Personal Information</h3>
                    <div>
                        <label class="block text-sm font-medium">First Name *</label>
                        <input type="text" name="first_name" required value="<?= getFormValue('first_name', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Middle Name</label>
                        <input type="text" name="middle_name" value="<?= getFormValue('middle_name', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Last Name *</label>
                        <input type="text" name="last_name" required value="<?= getFormValue('last_name', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr, Sr, III, etc." value="<?= getFormValue('suffix', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Date of Birth *</label>
                        <input type="date" name="birth_date" required value="<?= getFormValue('birth_date', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Place of Birth *</label>
                        <input type="text" name="birth_place" required value="<?= getFormValue('birth_place', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-medium">Gender *</label>
                        <div class="flex gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Male" required <?= isChecked('Male', $form_data, 'gender') ?>
                                       class="form-radio">
                                <span class="ml-2">Male</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Female" <?= isChecked('Female', $form_data, 'gender') ?>
                                       class="form-radio">
                                <span class="ml-2">Female</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Others" <?= isChecked('Others', $form_data, 'gender') ?>
                                       class="form-radio">
                                <span class="ml-2">Others</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Additional Information</h3>
                    <div>
                        <label class="block text-sm font-medium">Civil Status *</label>
                        <select name="civil_status" required class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Civil Status --</option>
                            <option value="Single" <?= isSelected('Single', $form_data, 'civil_status') ?>>Single</option>
                            <option value="Married" <?= isSelected('Married', $form_data, 'civil_status') ?>>Married</option>
                            <option value="Widowed" <?= isSelected('Widowed', $form_data, 'civil_status') ?>>Widowed</option>
                            <option value="Separated" <?= isSelected('Separated', $form_data, 'civil_status') ?>>Separated</option>
                            <option value="Widow/Widower" <?= isSelected('Widow/Widower', $form_data, 'civil_status') ?>>Widow/Widower</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Citizenship</label>
                        <input type="text" name="citizenship" value="<?= getFormValue('citizenship', $form_data) ?: 'Filipino' ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Religion</label>
                        <select name="religion" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Religion --</option>
                            <option value="Roman Catholic" <?= isSelected('Roman Catholic', $form_data, 'religion') ?>>Roman Catholic</option>
                            <option value="Protestant" <?= isSelected('Protestant', $form_data, 'religion') ?>>Protestant</option>
                            <option value="Iglesia Ni Cristo" <?= isSelected('Iglesia Ni Cristo', $form_data, 'religion') ?>>Iglesia Ni Cristo</option>
                            <option value="Islam" <?= isSelected('Islam', $form_data, 'religion') ?>>Islam</option>
                            <option value="Other" <?= isSelected('Other', $form_data, 'religion') ?>>Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Educational Attainment</label>
                        <select name="education_level" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Education Level --</option>
                            <option value="Not Attended Any School" <?= isSelected('Not Attended Any School', $form_data, 'education_level') ?>>Not Attended Any School</option>
                            <option value="Elementary Level" <?= isSelected('Elementary Level', $form_data, 'education_level') ?>>Elementary Level</option>
                            <option value="Elementary Graduate" <?= isSelected('Elementary Graduate', $form_data, 'education_level') ?>>Elementary Graduate</option>
                            <option value="High School Level" <?= isSelected('High School Level', $form_data, 'education_level') ?>>High School Level</option>
                            <option value="High School Graduate" <?= isSelected('High School Graduate', $form_data, 'education_level') ?>>High School Graduate</option>
                            <option value="Vocational" <?= isSelected('Vocational', $form_data, 'education_level') ?>>Vocational</option>
                            <option value="College Level" <?= isSelected('College Level', $form_data, 'education_level') ?>>College Level</option>
                            <option value="College Graduate" <?= isSelected('College Graduate', $form_data, 'education_level') ?>>College Graduate</option>
                            <option value="Post Graduate" <?= isSelected('Post Graduate', $form_data, 'education_level') ?>>Post Graduate</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Occupation</label>
                        <input type="text" name="occupation" value="<?= getFormValue('occupation', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Monthly Income</label>
                        <select name="monthly_income" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Income Range --</option>
                            <option value="0" <?= isSelected('0', $form_data, 'monthly_income') ?>>No Income</option>
                            <option value="999" <?= isSelected('999', $form_data, 'monthly_income') ?>>999 & below</option>
                            <option value="1500" <?= isSelected('1500', $form_data, 'monthly_income') ?>>1,000-1,999</option>
                            <option value="2500" <?= isSelected('2500', $form_data, 'monthly_income') ?>>2,000-2,999</option>
                            <option value="3500" <?= isSelected('3500', $form_data, 'monthly_income') ?>>3,000-3,999</option>
                            <option value="4500" <?= isSelected('4500', $form_data, 'monthly_income') ?>>4,000-4,999</option>
                            <option value="5500" <?= isSelected('5500', $form_data, 'monthly_income') ?>>5,000-5,999</option>
                            <option value="6500" <?= isSelected('6500', $form_data, 'monthly_income') ?>>6,000-6,999</option>
                            <option value="7500" <?= isSelected('7500', $form_data, 'monthly_income') ?>>7,000-7,999</option>
                            <option value="8500" <?= isSelected('8500', $form_data, 'monthly_income') ?>>8,000-8,999</option>
                            <option value="9500" <?= isSelected('9500', $form_data, 'monthly_income') ?>>9,000-9,999</option>
                            <option value="10000" <?= isSelected('10000', $form_data, 'monthly_income') ?>>10,000 & above</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Contact Number</label>
                        <input type="text" name="contact_number" value="<?= getFormValue('contact_number', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Address Information</h3>
                    <div>
                        <label class="block text-sm font-medium">House No.</label>
                        <input type="text" name="house_no" value="<?= getFormValue('house_no', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Street</label>
                        <input type="text" name="street" value="<?= getFormValue('street', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Subdivision/Purok/Zone/Sitio</label>
                        <input type="text" name="subdivision" value="<?= getFormValue('subdivision', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Block/Lot</label>
                        <input type="text" name="block_lot" value="<?= getFormValue('block_lot', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Phase</label>
                        <input type="text" name="phase" value="<?= getFormValue('phase', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">City/Municipality</label>
                        <input type="text" name="municipality" value="<?= getFormValue('municipality', $form_data) ?: 'SAN RAFAEL' ?>"
                               class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Province</label>
                        <input type="text" name="province" value="<?= getFormValue('province', $form_data) ?: 'BULACAN' ?>"
                               class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Residency Type</label>
                        <select name="residency_type" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Residency Type --</option>                            <option value="Homeowner" <?= isSelected('Homeowner', $form_data, 'residency_type') ?>>Homeowner</option>
                            <option value="Renter" <?= isSelected('Renter', $form_data, 'residency_type') ?>>Renter</option>
                            <option value="Sharer" <?= isSelected('Sharer', $form_data, 'residency_type') ?>>Sharer</option>
                            <option value="Caretaker" <?= isSelected('Caretaker', $form_data, 'residency_type') ?>>Caretaker</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Years in San Rafael</label>
                        <input type="number" name="years_in_san_rafael" min="0" max="100" value="<?= getFormValue('years_in_san_rafael', $form_data) ?>"
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                </div>

                <div class="space-y-4 md:col-span-3 border-t border-gray-200 pt-4 mt-6">
                    <h3 class="font-semibold text-lg">Household Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Household ID *</label>
                            <select name="household_id" required class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Household --</option>
                                <?php foreach ($households as $household): ?>
                                    <option value="<?= htmlspecialchars($household['household_id']) ?>" 
                                        <?= isSelected($household['household_id'], $form_data, 'household_id') ?>>
                                        <?= htmlspecialchars($household['household_id']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">If household is not listed, create it in the Manage Households tab</div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium">Relationship to Head</label>
                            <select name="relationship" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Relationship --</option>
                                <option value="Head" <?= isSelected('Head', $form_data, 'relationship') ?>>Head</option>
                                <option value="Spouse" <?= isSelected('Spouse', $form_data, 'relationship') ?>>Spouse</option>
                                <option value="Child" <?= isSelected('Child', $form_data, 'relationship') ?>>Child</option>
                                <option value="Parent" <?= isSelected('Parent', $form_data, 'relationship') ?>>Parent</option>
                                <option value="Sibling" <?= isSelected('Sibling', $form_data, 'relationship') ?>>Sibling</option>
                                <option value="Grandchild" <?= isSelected('Grandchild', $form_data, 'relationship') ?>>Grandchild</option>
                                <option value="Other Relative" <?= isSelected('Other Relative', $form_data, 'relationship') ?>>Other Relative</option>
                                <option value="Non-relative" <?= isSelected('Non-relative', $form_data, 'relationship') ?>>Non-relative</option>
                            </select>
                        </div>
                        
                        <div class="flex items-center">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_household_head" value="1" 
                                    <?= isCheckboxChecked($form_data, 'is_household_head') ?> class="form-checkbox">
                                <span class="ml-2 text-sm font-medium">Is Household Head</span>
                            </label>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button type="submit" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">
                            Save Resident Data
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <script>
        // Show/hide extra fields for Senior/PWD
        document.addEventListener('DOMContentLoaded', function() {
            const select = document.getElementById('residentTypeSelect');
            const form = document.getElementById('residentForm');
            
            function updateResidentType() {
                let existing = form.querySelector('input[name="resident_type"]');
                if (!existing) {
                    existing = document.createElement('input');
                    existing.type = 'hidden';
                    existing.name = 'resident_type';
                    form.appendChild(existing);
                }
                existing.value = select.value;
            }
            
            select.addEventListener('change', updateResidentType);
            updateResidentType(); // Initialize on page load
            
            // Show SweetAlert for success/error messages
            <?php if ($add_error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                html: '<?= addslashes($add_error) ?>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#dc2626'
            });
            <?php elseif ($add_success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?= addslashes($add_success) ?>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#16a34a',
                timer: 5000,
                timerProgressBar: true
            });
            <?php endif; ?>
            
            // Auto-hide success/error messages after 5 seconds (fallback)
            const errorMsg = document.querySelector('.error-message');
            const successMsg = document.querySelector('.success-message');
            
            if (errorMsg) {
                setTimeout(() => {
                    errorMsg.style.transition = 'opacity 0.5s';
                    errorMsg.style.opacity = '0';
                    setTimeout(() => errorMsg.remove(), 500);
                }, 5000);
            }
            
            if (successMsg) {
                setTimeout(() => {
                    successMsg.style.transition = 'opacity 0.5s';
                    successMsg.style.opacity = '0';
                    setTimeout(() => successMsg.remove(), 500);
                }, 5000);
            }
        });
        
        // Form submission with loading state
        document.getElementById('residentForm').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Saving...';
            
            // Reset button after a delay (in case form doesn't redirect)
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }, 10000);
        });
        
        // Confirm delete function
        function confirmDelete(personId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "This action cannot be undone. All related records will also be deleted.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'Deleting...',
                        text: 'Please wait while we delete the resident record.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Redirect to delete script
                    window.location.href = `delete_resident.php?id=${personId}`;
                }
            });
        }
        
        // Search functionality
        document.getElementById('search-resident').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#census-list tbody tr');
            
            rows.forEach(row => {
                const name = row.querySelector('td:first-child').textContent.toLowerCase();
                if (name.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Category filter functionality
        document.getElementById('btn-all').addEventListener('click', function() {
            filterByCategory('all');
            updateActiveButton(this);
        });
        
        document.getElementById('btn-seniors').addEventListener('click', function() {
            filterByCategory('Senior');
            updateActiveButton(this);
        });
        
        document.getElementById('btn-children').addEventListener('click', function() {
            filterByCategory('Child');
            updateActiveButton(this);
        });
        
        function filterByCategory(category) {
            const rows = document.querySelectorAll('#census-list tbody tr');
            
            rows.forEach(row => {
                if (category === 'all' || row.dataset.category === category) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function updateActiveButton(activeBtn) {
            // Remove active class from all buttons
            document.querySelectorAll('#census-list .flex button').forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white');
                btn.classList.add('bg-gray-200');
            });
            
            // Add active class to clicked button
            activeBtn.classList.remove('bg-gray-200');
            activeBtn.classList.add('bg-blue-600', 'text-white');
        }
        
        // Form validation
        function validateForm() {
            const form = document.getElementById('residentForm');
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('border-red-500');
                    isValid = false;
                } else {
                    field.classList.remove('border-red-500');
                }
            });
            
            return isValid;
        }
        
        // Add validation on form submission
        document.getElementById('residentForm').addEventListener('submit', function(e) {
            if (!validateForm()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Validation Error',
                    text: 'Please fill in all required fields.',
                    confirmButtonColor: '#dc2626'
                });
            }
        });
        
        // Real-time validation
        document.querySelectorAll('#residentForm [required]').forEach(field => {
            field.addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.classList.add('border-red-500');
                } else {
                    this.classList.remove('border-red-500');
                }
            });
        });
        </script>

        <!-- Census List -->
        <div id="census-list" class="tab-content bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-2xl font-bold mb-4">Census Records</h2>
            <div class="mb-4 flex justify-between items-center">
                <div class="flex gap-2">
                    <button id="btn-all" class="px-4 py-2 bg-blue-600 text-white rounded">All</button>
                    <button id="btn-seniors" class="px-4 py-2 bg-gray-200 rounded">Seniors</button>
                    <button id="btn-children" class="px-4 py-2 bg-gray-200 rounded">Children</button>
                </div>
                <div>
                    <input type="text" id="search-resident" placeholder="Search by name..." 
                           class="px-4 py-2 border rounded w-64">
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
                        <?php foreach ($residents as $resident): 
                            $age = $resident['age'] ?? (function($birth_date) {
                                return floor((time() - strtotime($birth_date)) / 31556926);
                            })($resident['birth_date']);
                            $category = '';
                            if ($age >= 60) {
                                $category = 'Senior';
                            } elseif ($age < 18) {
                                $category = 'Child';
                            } else {
                                $category = 'Adult';
                            }
                        ?>
                        <tr data-category="<?= $category ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?= htmlspecialchars("{$resident['last_name']}, {$resident['first_name']} " . 
                                    ($resident['middle_name'] ? substr($resident['middle_name'], 0, 1) . '.' : '') . 
                                    ($resident['suffix'] ? " {$resident['suffix']}" : '')) 
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $age ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $resident['gender'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $resident['civil_status'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $resident['household_id'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?= $resident['relationship_to_head'] ?>
                                <?= $resident['is_household_head'] ? ' (Head)' : '' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?= $resident['address'] ?? 'No address provided' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="<?= $category === 'Senior' ? 'bg-purple-100 text-purple-800' : 
                                    ($category === 'Child' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?> 
                                    px-2 py-1 rounded text-xs">
                                    <?= $category ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="view_resident.php?id=<?= $resident['id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                <a href="edit_resident.php?id=<?= $resident['id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <a href="javascript:void(0)" onclick="confirmDelete(<?= $resident['id'] ?>)" class="text-red-600 hover:text-red-900">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>