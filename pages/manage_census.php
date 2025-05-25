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
        hm.relationship_type_id,
        rt.name as relationship_name,
        hm.is_household_head,
        CONCAT(a.house_no, ' ', a.street, ', ', b.name) as address,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
    FROM persons p
    JOIN household_members hm ON p.id = hm.person_id
    JOIN households h ON hm.household_id = h.id
    JOIN barangay b ON h.barangay_id = b.id
    LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
    LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
    WHERE h.barangay_id = ?
    ORDER BY p.last_name, p.first_name
");
$stmt->execute([$barangay_id]);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangay details
$stmt = $pdo->prepare("SELECT id, name FROM barangay WHERE id = ?");
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
            // Basic Information
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'suffix' => trim($_POST['suffix'] ?? ''),
            'birth_date' => trim($_POST['birth_date'] ?? ''),
            'birth_place' => trim($_POST['birth_place'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'civil_status' => trim($_POST['civil_status'] ?? ''),
            'nationality' => trim($_POST['nationality'] ?? ''),
            'religion' => trim($_POST['religion'] ?? ''),
            
            // Contact Information
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            
            // Address Information
            'current_address' => trim($_POST['current_address'] ?? ''),
            'current_city' => trim($_POST['current_city'] ?? 'BALIUAG'),
            'current_province' => trim($_POST['current_province'] ?? 'BULACAN'),
            'current_region' => trim($_POST['current_region'] ?? 'III'),
            'permanent_address' => trim($_POST['permanent_address'] ?? ''),
            'permanent_city' => trim($_POST['permanent_city'] ?? 'BALIUAG'),
            'permanent_province' => trim($_POST['permanent_province'] ?? 'BULACAN'),
            'permanent_region' => trim($_POST['permanent_region'] ?? 'III'),
            
            // Government IDs
            'osca_id' => trim($_POST['osca_id'] ?? ''),
            'gsis_id' => trim($_POST['gsis_id'] ?? ''),
            'sss_id' => trim($_POST['sss_id'] ?? ''),
            'tin_id' => trim($_POST['tin_id'] ?? ''),
            'philhealth_id' => trim($_POST['philhealth_id'] ?? ''),
            'other_id_type' => trim($_POST['other_id_type'] ?? ''),
            'other_id_number' => trim($_POST['other_id_number'] ?? ''),
            
            // Household Information
            'household_id' => trim($_POST['household_id'] ?? ''),
            'relationship' => trim($_POST['relationship'] ?? ''),
            'is_household_head' => isset($_POST['is_household_head']) ? 1 : 0,
            
            // Array fields
            'assets' => isset($_POST['assets']) ? (array)$_POST['assets'] : [],
            'income_sources' => isset($_POST['income_sources']) ? (array)$_POST['income_sources'] : [],
            'skills' => isset($_POST['skills']) ? (array)$_POST['skills'] : [],
            'health_concerns' => isset($_POST['health_concerns']) ? (array)$_POST['health_concerns'] : [],
            'living_arrangements' => isset($_POST['living_arrangements']) ? (array)$_POST['living_arrangements'] : [],
            'involvements' => isset($_POST['involvements']) ? (array)$_POST['involvements'] : [],
            'problems' => isset($_POST['problems']) ? (array)$_POST['problems'] : [],
            'service_needs' => isset($_POST['service_needs']) ? (array)$_POST['service_needs'] : [],
            'other_needs' => isset($_POST['other_needs']) ? (array)$_POST['other_needs'] : [],
            
            // Details for each array field
            'asset_details' => [],
            'income_details' => [],
            'skill_details' => [],
            'health_concern_details' => [],
            'living_arrangement_details' => [],
            'involvement_details' => [],
            'problem_details' => [],
            'service_need_details' => [],
            'other_need_details' => []
        ];
        
        // Process details for each array field
        foreach ($data['assets'] as $asset_id) {
            $data['asset_details'][$asset_id] = trim($_POST['asset_details'][$asset_id] ?? '');
        }
        
        foreach ($data['income_sources'] as $source_id) {
            $amount = trim($_POST['income_amounts'][$source_id] ?? '');
            $data['income_details'][$source_id] = [
                'amount' => $amount !== '' ? (float)str_replace(['₱', ','], '', $amount) : null,
                'details' => trim($_POST['income_details'][$source_id] ?? '')
            ];
        }
        
        foreach ($data['skills'] as $skill_id) {
            $data['skill_details'][$skill_id] = trim($_POST['skill_details'][$skill_id] ?? '');
        }
        
        foreach ($data['health_concerns'] as $concern_id) {
            $data['health_concern_details'][$concern_id] = trim($_POST['health_concern_details'][$concern_id] ?? '');
        }
        
        foreach ($data['living_arrangements'] as $arrangement_id) {
            $data['living_arrangement_details'][$arrangement_id] = trim($_POST['living_arrangement_details'][$arrangement_id] ?? '');
        }
        
        foreach ($data['involvements'] as $involvement_id) {
            $data['involvement_details'][$involvement_id] = trim($_POST['involvement_details'][$involvement_id] ?? '');
        }
        
        foreach ($data['problems'] as $problem_id) {
            $data['problem_details'][$problem_id] = trim($_POST['problem_details'][$problem_id] ?? '');
        }
        
        foreach ($data['service_needs'] as $need_id) {
            $data['service_need_details'][$need_id] = trim($_POST['service_need_details'][$need_id] ?? '');
        }
        
        foreach ($data['other_needs'] as $need_id) {
            $data['other_need_details'][$need_id] = trim($_POST['other_need_details'][$need_id] ?? '');
        }
        
        // Validate required fields
        $validation_errors = [];
        if (empty($data['first_name'])) $validation_errors[] = "First name is required";
        if (empty($data['last_name'])) $validation_errors[] = "Last name is required";
        if (empty($data['birth_date'])) $validation_errors[] = "Birth date is required";
        if (empty($data['gender'])) $validation_errors[] = "Gender is required";
        if (empty($data['civil_status'])) $validation_errors[] = "Civil status is required";
        
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
                        hm.relationship_type_id,
                        rt.name as relationship_name,
                        hm.is_household_head,
                        CONCAT(a.house_no, ' ', a.street, ', ', b.name) as address,
                        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
                    FROM persons p
                    JOIN household_members hm ON p.id = hm.person_id
                    JOIN households h ON hm.household_id = h.id
                    JOIN barangay b ON h.barangay_id = b.id
                    LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
                    LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
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
    if (strpos($key, '[') !== false) {
        // Handle array keys like 'asset_details[1]'
        preg_match('/^([^\[]+)\[([^\]]+)\]$/', $key, $matches);
        if (count($matches) === 3) {
            $array_key = $matches[1];
            $index = $matches[2];
            return isset($form_data[$array_key][$index]) ? htmlspecialchars($form_data[$array_key][$index]) : $default;
        }
    }
    return isset($form_data[$key]) ? htmlspecialchars($form_data[$key]) : $default;
}

// Helper function to check if option is selected
function isSelected($value, $form_data, $key)
{
    return (isset($form_data[$key]) && $form_data[$key] == $value) ? 'selected' : '';
}

// Helper function to check if radio is checked
function isChecked($value, $form_data, $key)
{
    return (isset($form_data[$key]) && $form_data[$key] == $value) ? 'checked' : '';
}

// Helper function to check if checkbox is checked
function isCheckboxChecked($form_data, $field, $value = null) {
    if ($value !== null) {
        // For array fields
        return isset($form_data[$field]) && is_array($form_data[$field]) && in_array($value, $form_data[$field]) ? 'checked' : '';
    }
    // For single checkbox fields
    return isset($form_data[$field]) && $form_data[$field] ? 'checked' : '';
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
    <script>
        // Function to calculate age
        function calculateAge() {
            const birthDateInput = document.getElementById('birth_date');
            const ageInput = document.getElementById('age');
            
            if (!birthDateInput || !ageInput) return;
            
            const birthDate = birthDateInput.value;
            if (birthDate) {
                const today = new Date();
                const birth = new Date(birthDate);
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                
                ageInput.value = age;
            } else {
                ageInput.value = '';
            }
        }

        // Initialize when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            const birthDateInput = document.getElementById('birth_date');
            if (birthDateInput) {
                birthDateInput.addEventListener('change', calculateAge);
                // Calculate initial age if birth date exists
                if (birthDateInput.value) {
                    calculateAge();
                }
            }
        });
    </script>
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
        <div class="bg-white rounded-lg shadow-sm p-6 mb-8">
            <h1 class="text-3xl font-bold text-blue-800 mb-6">Resident Census Management</h1>

            <!-- Navigation Buttons -->
            <div class="flex flex-wrap gap-4 mb-6">
                <a href="manage_census.php" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Add Resident
                </a>
                <a href="add_child.php" class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    Add Child
                </a>
                <a href="census_records.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Census Records
                </a>
                <a href="manage_households.php" class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg text-sm transition-colors duration-200">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Manage Households
                </a>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($add_error): ?>
            <div class="error-message transform transition-all duration-300">
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700"><?= $add_error ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($add_success): ?>
            <div class="success-message transform transition-all duration-300">
                <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-green-700"><?= $add_success ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Regular Resident Form -->
        <div id="add-resident" class="tab-content active bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">Add New Resident</h2>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700">Resident Type</label>
                <select name="resident_type" class="mt-1 block w-full border rounded p-2">
                    <option value="REGULAR" <?= isSelected('REGULAR', $form_data, 'resident_type') ?: 'selected' ?>>REGULAR</option>
                    <option value="SENIOR" <?= isSelected('SENIOR', $form_data, 'resident_type') ?>>SENIOR</option>
                    <option value="PWD" <?= isSelected('PWD', $form_data, 'resident_type') ?>>PWD</option>
                </select>
            </div>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="residentForm" autocomplete="off">
                <!-- Column 1: Basic Personal Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Basic Information</h3>

                    <!-- Name Fields -->
                    <div>
                        <label class="block text-sm font-medium">First Name *</label>
                        <input type="text" name="first_name" required value="<?= getFormValue('first_name', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Middle Name</label>
                        <input type="text" name="middle_name" value="<?= getFormValue('middle_name', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Last Name *</label>
                        <input type="text" name="last_name" required value="<?= getFormValue('last_name', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr, Sr, III, etc." value="<?= getFormValue('suffix', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <!-- Citizenship -->
                    <div>
                        <label class="block text-sm font-medium">Citizenship</label>
                        <input type="text" name="citizenship" value="<?= getFormValue('citizenship', $form_data) ?: 'FILIPINO' ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                    </div>

                    <!-- Contact Information -->
                    <div>
                        <label class="block text-sm font-medium">Contact Number</label>
                        <input type="text" name="contact_number" value="<?= getFormValue('contact_number', $form_data) ?>"
                            placeholder="e.g. 09123456789"
                            class="mt-1 block w-full border rounded p-2" 
                            pattern="[0-9]{11}"
                            title="Please enter a valid 11-digit phone number"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <p class="text-xs text-gray-500 mt-1">Format: 11-digit number (e.g. 09123456789)</p>
                    </div>
                </div>

                <!-- Column 2: Birth and Identity -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Birth & Identity</h3>

                    <!-- Birth Information -->
                    <div>
                        <label class="block text-sm font-medium">Date of Birth *</label>
                        <input type="date" name="birth_date" id="birth_date" required value="<?= getFormValue('birth_date', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2" onchange="calculateAge()">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Age</label>
                        <input type="number" name="age" id="age" readonly value="<?= getFormValue('age', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 bg-gray-100">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Place of Birth *</label>
                        <input type="text" name="birth_place" required value="<?= getFormValue('birth_place', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <!-- Gender -->
                    <div class="space-y-2">
                        <label class="block text-sm font-medium">Gender *</label>
                        <div class="flex gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="MALE" required <?= isChecked('MALE', $form_data, 'gender') ?>
                                    class="form-radio">
                                <span class="ml-2">MALE</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="FEMALE" <?= isChecked('FEMALE', $form_data, 'gender') ?>
                                    class="form-radio">
                                <span class="ml-2">FEMALE</span>
                            </label>
                        </div>
                    </div>

                    <!-- Civil Status -->
                    <div>
                        <label class="block text-sm font-medium">Civil Status *</label>
                        <select name="civil_status" required class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT CIVIL STATUS --</option>
                            <option value="SINGLE" <?= isSelected('SINGLE', $form_data, 'civil_status') ?>>SINGLE</option>
                            <option value="MARRIED" <?= isSelected('MARRIED', $form_data, 'civil_status') ?>>MARRIED</option>
                            <option value="WIDOW/WIDOWER" <?= isSelected('WIDOW/WIDOWER', $form_data, 'civil_status') ?>>WIDOW/WIDOWER</option>
                            <option value="SEPARATED" <?= isSelected('SEPARATED', $form_data, 'civil_status') ?>>SEPARATED</option>
                        </select>
                    </div>

                    <!-- Religion -->
                    <div>
                        <label class="block text-sm font-medium">Religion</label>
                        <select name="religion" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT RELIGION --</option>
                            <option value="ROMAN CATHOLIC" <?= isSelected('ROMAN CATHOLIC', $form_data, 'religion') ?>>ROMAN CATHOLIC</option>
                            <option value="PROTESTANT" <?= isSelected('PROTESTANT', $form_data, 'religion') ?>>PROTESTANT</option>
                            <option value="IGLESIA NI CRISTO" <?= isSelected('IGLESIA NI CRISTO', $form_data, 'religion') ?>>IGLESIA NI CRISTO</option>
                            <option value="ISLAM" <?= isSelected('ISLAM', $form_data, 'religion') ?>>ISLAM</option>
                            <option value="OTHERS" <?= isSelected('OTHERS', $form_data, 'religion') ?>>OTHERS</option>
                        </select>
                        <div id="other_religion_container" style="display: none;" class="mt-2">
                            <input type="text" name="other_religion" placeholder="Specify Religion" 
                                value="<?= getFormValue('other_religion', $form_data) ?>" 
                                class="w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                    </div>
                </div>

                <!-- Column 3: Socio-Economic Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Socio-Economic Profile</h3>

                    <!-- Educational Attainment -->
                    <div>
                        <label class="block text-sm font-medium">Educational Attainment</label>
                        <select name="education_level" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT EDUCATION LEVEL --</option>
                            <option value="NOT ATTENDED ANY SCHOOL" <?= isSelected('NOT ATTENDED ANY SCHOOL', $form_data, 'education_level') ?>>NOT ATTENDED ANY SCHOOL</option>
                            <option value="ELEMENTARY LEVEL" <?= isSelected('ELEMENTARY LEVEL', $form_data, 'education_level') ?>>ELEMENTARY LEVEL</option>
                            <option value="ELEMENTARY GRADUATE" <?= isSelected('ELEMENTARY GRADUATE', $form_data, 'education_level') ?>>ELEMENTARY GRADUATE</option>
                            <option value="HIGH SCHOOL LEVEL" <?= isSelected('HIGH SCHOOL LEVEL', $form_data, 'education_level') ?>>HIGH SCHOOL LEVEL</option>
                            <option value="HIGH SCHOOL GRADUATE" <?= isSelected('HIGH SCHOOL GRADUATE', $form_data, 'education_level') ?>>HIGH SCHOOL GRADUATE</option>
                            <option value="VOCATIONAL" <?= isSelected('VOCATIONAL', $form_data, 'education_level') ?>>VOCATIONAL</option>
                            <option value="COLLEGE LEVEL" <?= isSelected('COLLEGE LEVEL', $form_data, 'education_level') ?>>COLLEGE LEVEL</option>
                            <option value="COLLEGE GRADUATE" <?= isSelected('COLLEGE GRADUATE', $form_data, 'education_level') ?>>COLLEGE GRADUATE</option>
                            <option value="POST GRADUATE" <?= isSelected('POST GRADUATE', $form_data, 'education_level') ?>>POST GRADUATE</option>
                        </select>
                    </div>

                    <!-- Occupation and Income -->
                    <div>
                        <label class="block text-sm font-medium">Occupation</label>
                        <input type="text" name="occupation" value="<?= getFormValue('occupation', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Monthly Income</label>
                        <select name="monthly_income" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT INCOME RANGE --</option>
                            <option value="0" <?= isSelected('0', $form_data, 'monthly_income') ?>>NO INCOME</option>
                            <option value="999" <?= isSelected('999', $form_data, 'monthly_income') ?>>₱999 & BELOW</option>
                            <option value="1500" <?= isSelected('1500', $form_data, 'monthly_income') ?>>₱1,000-1,999</option>
                            <option value="2500" <?= isSelected('2500', $form_data, 'monthly_income') ?>>₱2,000-2,999</option>
                            <option value="3500" <?= isSelected('3500', $form_data, 'monthly_income') ?>>₱3,000-3,999</option>
                            <option value="4500" <?= isSelected('4500', $form_data, 'monthly_income') ?>>₱4,000-4,999</option>
                            <option value="5500" <?= isSelected('5500', $form_data, 'monthly_income') ?>>₱5,000-5,999</option>
                            <option value="6500" <?= isSelected('6500', $form_data, 'monthly_income') ?>>₱6,000-6,999</option>
                            <option value="7500" <?= isSelected('7500', $form_data, 'monthly_income') ?>>₱7,000-7,999</option>
                            <option value="8500" <?= isSelected('8500', $form_data, 'monthly_income') ?>>₱8,000-8,999</option>
                            <option value="9500" <?= isSelected('9500', $form_data, 'monthly_income') ?>>₱9,000-9,999</option>
                            <option value="10000" <?= isSelected('10000', $form_data, 'monthly_income') ?>>₱10,000 & ABOVE</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Years of Residency *</label>
                        <input type="number" name="years_of_residency" required min="0" max="100" 
                            value="<?= getFormValue('years_of_residency', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                </div>

                <!-- Address Information - Full Width Section -->
                <div class="space-y-4 md:col-span-3">
                    <h3 class="font-semibold text-lg border-t border-gray-200 pt-4 mt-4">Address Information</h3>

                    <!-- Present Address -->
                    <div class="border-b pb-4 mb-4">
                        <h4 class="font-semibold text-md mb-4">Present Address</h4>
                        <p class="text-sm text-gray-600 mb-4">Where you currently reside</p>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium">House No.</label>
                                <input type="text" name="present_house_no" value="<?= getFormValue('present_house_no', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            <div>
                                <label class="block text-sm font-medium">Street</label>
                                <input type="text" name="present_street" value="<?= getFormValue('present_street', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            

                            <div>
                                <label class="block text-sm font-medium">Barangay</label>
                                <input type="text" name="present_barangay" value="<?= htmlspecialchars($barangay['name']) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                                <input type="hidden" name="present_barangay_id" value="<?= htmlspecialchars($barangay['id']) ?>">
                            </div>

                            <div>
                                <label class="block text-sm font-medium">City/Municipality</label>
                                <input type="text" name="present_municipality" value="<?= getFormValue('present_municipality', $form_data) ?: 'SAN RAFAEL' ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            <div>
                                <label class="block text-sm font-medium">Province</label>
                                <input type="text" name="present_province" value="<?= getFormValue('present_province', $form_data) ?: 'BULACAN' ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium">Region</label>
                                <input type="text" name="present_region" value="<?= getFormValue('present_region', $form_data) ?: 'III' ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </div>

                    <!-- Permanent Address -->
                    <div class="pb-4 mb-4">
                        <h4 class="font-semibold text-md mb-4">Permanent Address</h4>
                        <p class="text-sm text-gray-600 mb-4">Your long-term or official residence</p>

                        <div class="mb-4">
                            <label class="inline-flex items-center">
                                <input type="checkbox" id="sameAsPresent" name="same_as_present" class="form-checkbox">
                                <span class="ml-2 text-sm">Same as Present Address</span>
                            </label>
                        </div>

                        <div id="permanentAddressFields" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium">House No.</label>
                                <input type="text" name="permanent_house_no" value="<?= getFormValue('permanent_house_no', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            <div>
                                <label class="block text-sm font-medium">Street</label>
                                <input type="text" name="permanent_street" value="<?= getFormValue('permanent_street', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            

                            <div>
                                <label class="block text-sm font-medium">Barangay</label>
                                <input type="text" name="permanent_barangay"
                                    value="<?= getFormValue('permanent_barangay', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            <div>
                                <label class="block text-sm font-medium">City/Municipality</label>
                                <input type="text" name="permanent_municipality" value="<?= getFormValue('permanent_municipality', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            <div>
                                <label class="block text-sm font-medium">Province</label>
                                <input type="text" name="permanent_province" value="<?= getFormValue('permanent_province', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium">Region</label>
                                <input type="text" name="permanent_region" value="<?= getFormValue('permanent_region', $form_data) ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Household Information - Full Width Section -->
                <div class="space-y-4 md:col-span-3 border-t border-gray-200 pt-4 mt-6">
                    <h3 class="font-semibold text-lg">Household Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Household ID *</label>
                            <select name="household_id" required class="mt-1 block w-full border rounded p-2">
                                <option value="">-- SELECT HOUSEHOLD --</option>
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
                                <option value="">-- SELECT RELATIONSHIP --</option>
                                <option value="1" <?= isSelected('1', $form_data, 'relationship') ?>>HEAD</option>
                                <option value="2" <?= isSelected('2', $form_data, 'relationship') ?>>SPOUSE</option>
                                <option value="3" <?= isSelected('3', $form_data, 'relationship') ?>>CHILD</option>
                                <option value="4" <?= isSelected('4', $form_data, 'relationship') ?>>PARENT</option>
                                <option value="5" <?= isSelected('5', $form_data, 'relationship') ?>>SIBLING</option>
                                <option value="6" <?= isSelected('6', $form_data, 'relationship') ?>>GRANDCHILD</option>
                                <option value="7" <?= isSelected('7', $form_data, 'relationship') ?>>OTHER RELATIVE</option>
                                <option value="8" <?= isSelected('8', $form_data, 'relationship') ?>>NON-RELATIVE</option>
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
                    
                    <!-- Government Program Participation -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Government Program Participation</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="nhts_pr_listahanan" value="1" <?= isCheckboxChecked($form_data, 'nhts_pr_listahanan') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">NHTS-PR (Listahanan)</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="indigenous_people" value="1" <?= isCheckboxChecked($form_data, 'indigenous_people') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Indigenous People</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="pantawid_beneficiary" value="1" <?= isCheckboxChecked($form_data, 'pantawid_beneficiary') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Pantawid Beneficiary</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ID Numbers -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">ID Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium">OSCA ID Number</label>
                                <input type="text" name="osca_id" value="<?= getFormValue('osca_id', $form_data) ?>" 
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium">GSIS ID Number</label>
                                <input type="text" name="gsis_id" value="<?= getFormValue('gsis_id', $form_data) ?>" 
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium">SSS ID Number</label>
                                <input type="text" name="sss_id" value="<?= getFormValue('sss_id', $form_data) ?>" 
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium">TIN ID Number</label>
                                <input type="text" name="tin_id" value="<?= getFormValue('tin_id', $form_data) ?>" 
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium">PhilHealth ID Number</label>
                                <input type="text" name="philhealth_id" value="<?= getFormValue('philhealth_id', $form_data) ?>" 
                                    class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-sm font-medium">Other ID Type</label>
                                    <input type="text" name="other_id_type" value="<?= getFormValue('other_id_type', $form_data) ?>" 
                                        class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Other ID Number</label>
                                    <input type="text" name="other_id_number" value="<?= getFormValue('other_id_number', $form_data) ?>" 
                                        class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Source of Income and Assistance -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Source of Income & Assistance (Check all applicable)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_sources[]" value="1" <?= isCheckboxChecked($form_data, 'income_sources', 1) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Own Earnings, Salaries/Wages</span>
                                </label>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="income_sources[]" value="2" <?= isCheckboxChecked($form_data, 'income_sources', 2) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Own Pension</span>
                                </label>
                                <input type="text" name="income_amount_2" placeholder="Amount" 
                                    value="<?= getFormValue('income_amount_2', $form_data) ?>" 
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_sources[]" value="3" <?= isCheckboxChecked($form_data, 'income_sources', 3) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Stocks/Dividends</span>
                                </label>
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_sources[]" value="4" <?= isCheckboxChecked($form_data, 'income_sources', 4) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Dependent on Children/Relatives</span>
                                </label>
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_sources[]" value="5" <?= isCheckboxChecked($form_data, 'income_sources', 5) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Spouse's Salary</span>
                                </label>
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_sources[]" value="6" <?= isCheckboxChecked($form_data, 'income_sources', 6) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Insurances</span>
                                </label>
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="income_spouse_pension" value="1" <?= isCheckboxChecked($form_data, 'income_spouse_pension') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Spouse's Pension</span>
                                </label>
                                <input type="text" name="income_spouse_pension_amount" placeholder="Amount" 
                                    value="<?= getFormValue('income_spouse_pension_amount', $form_data) ?>" 
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="income_others" value="1" <?= isCheckboxChecked($form_data, 'income_others') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="income_others_specify" placeholder="Specify" 
                                    value="<?= getFormValue('income_others_specify', $form_data) ?>" 
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_rentals_sharecrops" value="1" <?= isCheckboxChecked($form_data, 'income_rentals_sharecrops') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Rentals/Sharecrops</span>
                                </label>
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_savings" value="1" <?= isCheckboxChecked($form_data, 'income_savings') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Savings</span>
                                </label>
                            </div>
                            
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_livestock_orchards" value="1" <?= isCheckboxChecked($form_data, 'income_livestock_orchards') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Livestock/Orchards</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family Composition -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">II. Family Composition</h3>
                        
                        <div class="overflow-x-auto mb-4">
                            <table class="min-w-full bg-white border border-gray-200">
                                <thead>
                                    <tr>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Name</th>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Relationship</th>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Age</th>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Civil Status</th>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Occupation</th>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Income</th>
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Educational Attainment</th>
                                    </tr>
                                </thead>
                                <tbody id="familyMembersTable">
                                    <!-- Family member rows will be added here -->
                                    <tr class="family-member-row">
                                        <td class="border border-gray-200 px-2 py-2">
                                            <input type="text" name="family_member_name[]" class="w-full border-0 p-0 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <select name="family_member_relationship[]" class="w-full border-0 p-0 text-sm bg-transparent">
                                                <option value="">-- SELECT --</option>
                                                <option value="1">HEAD</option>
                                                <option value="2">SPOUSE</option>
                                                <option value="3">CHILD</option>
                                                <option value="4">PARENT</option>
                                                <option value="5">SIBLING</option>
                                                <option value="6">GRANDCHILD</option>
                                                <option value="7">OTHER RELATIVE</option>
                                                <option value="8">NON-RELATIVE</option>
                                            </select>
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <input type="number" name="family_member_age[]" class="w-full border-0 p-0 text-sm" min="0" max="120">
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <select name="family_member_civil_status[]" class="w-full border-0 p-0 text-sm bg-transparent">
                                                <option value="">-- SELECT --</option>
                                                <option value="SINGLE">SINGLE</option>
                                                <option value="MARRIED">MARRIED</option>
                                                <option value="WIDOW/WIDOWER">WIDOW/WIDOWER</option>
                                                <option value="SEPARATED">SEPARATED</option>
                                            </select>
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <input type="text" name="family_member_occupation[]" class="w-full border-0 p-0 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <input type="text" name="family_member_income[]" class="w-full border-0 p-0 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <select name="family_member_education[]" class="w-full border-0 p-0 text-sm bg-transparent">
                                                <option value="">-- SELECT --</option>
                                                <option value="NOT ATTENDED ANY SCHOOL">NOT ATTENDED ANY SCHOOL</option>
                                                <option value="ELEMENTARY LEVEL">ELEMENTARY LEVEL</option>
                                                <option value="ELEMENTARY GRADUATE">ELEMENTARY GRADUATE</option>
                                                <option value="HIGH SCHOOL LEVEL">HIGH SCHOOL LEVEL</option>
                                                <option value="HIGH SCHOOL GRADUATE">HIGH SCHOOL GRADUATE</option>
                                                <option value="VOCATIONAL">VOCATIONAL</option>
                                                <option value="COLLEGE LEVEL">COLLEGE LEVEL</option>
                                                <option value="COLLEGE GRADUATE">COLLEGE GRADUATE</option>
                                                <option value="POST GRADUATE">POST GRADUATE</option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <button type="button" id="addFamilyMember" class="mt-2 px-3 py-1 bg-blue-500 text-white rounded-md text-sm">
                            Add Family Member
                        </button>
                    </div>
                    
                    <!-- Assets & Properties -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Assets & Properties (Check all applicable)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="assets[]" value="1" <?= isCheckboxChecked($form_data, 'assets', 1) ?> class="form-checkbox">
                                    <span class="ml-2">House</span>
                                </label>
                                <input type="text" name="asset_details[1]" value="<?= getFormValue('asset_details[1]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="assets[]" value="2" <?= isCheckboxChecked($form_data, 'assets', 2) ?> class="form-checkbox">
                                    <span class="ml-2">House and Lot</span>
                                </label>
                                <input type="text" name="asset_details[2]" value="<?= getFormValue('asset_details[2]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="assets[]" value="3" <?= isCheckboxChecked($form_data, 'assets', 3) ?> class="form-checkbox">
                                    <span class="ml-2">Farmland</span>
                                </label>
                                <input type="text" name="asset_details[3]" value="<?= getFormValue('asset_details[3]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Living/Residing With -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Living/Residing With (Check all applicable)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_alone" value="1" <?= isCheckboxChecked($form_data, 'living_alone') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Alone</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_common_law_spouse" value="1" <?= isCheckboxChecked($form_data, 'living_common_law_spouse') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Common Law Spouse</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_in_laws" value="1" <?= isCheckboxChecked($form_data, 'living_in_laws') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">In-Laws</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_spouse" value="1" <?= isCheckboxChecked($form_data, 'living_spouse') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Spouse</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_care_institutions" value="1" <?= isCheckboxChecked($form_data, 'living_care_institutions') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Care Institutions</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_children" value="1" <?= isCheckboxChecked($form_data, 'living_children') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Children</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_grandchildren" value="1" <?= isCheckboxChecked($form_data, 'living_grandchildren') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Grandchildren</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_househelps" value="1" <?= isCheckboxChecked($form_data, 'living_househelps') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Househelps</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_relatives" value="1" <?= isCheckboxChecked($form_data, 'living_relatives') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Relatives</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="living_others" value="1" <?= isCheckboxChecked($form_data, 'living_others') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="living_others_specify" placeholder="Specify" 
                                    value="<?= getFormValue('living_others_specify', $form_data) ?>" 
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Areas of Specialization/Skills -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Areas of Specialization/Skills (Check all applicable)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skills[]" value="1" <?= isCheckboxChecked($form_data, 'skills', 1) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Technical Skills</span>
                                </label>
                                <input type="text" name="skill_details[1]" value="<?= getFormValue('skill_details[1]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skills[]" value="2" <?= isCheckboxChecked($form_data, 'skills', 2) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Vocational Skills</span>
                                </label>
                                <input type="text" name="skill_details[2]" value="<?= getFormValue('skill_details[2]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Involvement in Community Activities -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Involvement in Community Activities (Check all applicable)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvements[]" value="1" <?= isCheckboxChecked($form_data, 'involvements', 1) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Barangay Council</span>
                                </label>
                                <input type="text" name="involvement_details[1]" value="<?= getFormValue('involvement_details[1]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvements[]" value="2" <?= isCheckboxChecked($form_data, 'involvements', 2) ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Community Organizations</span>
                                </label>
                                <input type="text" name="involvement_details[2]" value="<?= getFormValue('involvement_details[2]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Problems/Needs Commonly Encountered -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Problems/Needs Commonly Encountered (Check all applicable)</h3>
                        
                        <!-- Economic -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-md mb-3">A. Economic</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problems[]" value="1" <?= isCheckboxChecked($form_data, 'problems', 1) ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Financial Problems</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problems[]" value="2" <?= isCheckboxChecked($form_data, 'problems', 2) ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Health Problems</span>
                                    </label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="problem_skills_training" value="1" <?= isCheckboxChecked($form_data, 'problem_skills_training') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Skills/Capability Training</span>
                                    </label>
                                    <input type="text" name="problem_skills_training_specify" placeholder="Specify" 
                                        value="<?= getFormValue('problem_skills_training_specify', $form_data) ?>" 
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="problem_livelihood" value="1" <?= isCheckboxChecked($form_data, 'problem_livelihood') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Livelihood Opportunities</span>
                                    </label>
                                    <input type="text" name="problem_livelihood_specify" placeholder="Specify" 
                                        value="<?= getFormValue('problem_livelihood_specify', $form_data) ?>" 
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="problem_economic_others" value="1" <?= isCheckboxChecked($form_data, 'problem_economic_others') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Others</span>
                                    </label>
                                    <input type="text" name="problem_economic_others_specify" placeholder="Specify" 
                                        value="<?= getFormValue('problem_economic_others_specify', $form_data) ?>" 
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Social/Emotional -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-md mb-3">B. Social/Emotional</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_neglect_rejection" value="1" <?= isCheckboxChecked($form_data, 'problem_neglect_rejection') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Feeling of Neglect & Rejection</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_helplessness" value="1" <?= isCheckboxChecked($form_data, 'problem_helplessness') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Feeling of Helplessness & Worthlessness</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_loneliness" value="1" <?= isCheckboxChecked($form_data, 'problem_loneliness') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Feeling of Loneliness & Isolation</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_recreational" value="1" <?= isCheckboxChecked($form_data, 'problem_recreational') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Inadequate Leisure/Recreational Activities</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_senior_friendly" value="1" <?= isCheckboxChecked($form_data, 'problem_senior_friendly') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Senior Citizen Friendly Environment</span>
                                    </label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="problem_social_others" value="1" <?= isCheckboxChecked($form_data, 'problem_social_others') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Others</span>
                                    </label>
                                    <input type="text" name="problem_social_others_specify" placeholder="Specify" 
                                        value="<?= getFormValue('problem_social_others_specify', $form_data) ?>" 
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Health -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-md mb-3">C. Health</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="health_concerns[]" value="1" <?= isCheckboxChecked($form_data, 'health_concerns', 1) ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">High Blood Pressure</span>
                                    </label>
                                    <input type="text" name="health_concern_details[1]" value="<?= getFormValue('health_concern_details[1]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                                </div>
                                
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="health_concerns[]" value="2" <?= isCheckboxChecked($form_data, 'health_concerns', 2) ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Diabetes</span>
                                    </label>
                                    <input type="text" name="health_concern_details[2]" value="<?= getFormValue('health_concern_details[2]', $form_data) ?>" placeholder="Details" class="mt-1 block w-full border rounded p-2">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label class="text-sm font-medium block mb-2">Concerns/Issues</label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="problem_high_cost_medicine" value="1" <?= isCheckboxChecked($form_data, 'problem_high_cost_medicine') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">High Cost Medicines</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="problem_lack_medical_professionals" value="1" <?= isCheckboxChecked($form_data, 'problem_lack_medical_professionals') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">Lack of Medical Professionals</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="problem_lack_sanitation" value="1" <?= isCheckboxChecked($form_data, 'problem_lack_sanitation') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">Lack/No Access to Sanitation</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="problem_lack_health_insurance" value="1" <?= isCheckboxChecked($form_data, 'problem_lack_health_insurance') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">Lack/No Health Insurance/s</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="problem_inadequate_health_services" value="1" <?= isCheckboxChecked($form_data, 'problem_inadequate_health_services') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">Inadequate Health Services</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="problem_lack_medical_facilities" value="1" <?= isCheckboxChecked($form_data, 'problem_lack_medical_facilities') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">Lack of Hospitals/Medical Facilities</span>
                                            </label>
                                        </div>
                                        <div class="flex items-center gap-2 md:col-span-2">
                                            <label class="inline-flex items-center whitespace-nowrap">
                                                <input type="checkbox" name="problem_health_others" value="1" <?= isCheckboxChecked($form_data, 'problem_health_others') ?> class="form-checkbox">
                                                <span class="ml-2 text-sm font-medium">Others</span>
                                            </label>
                                            <input type="text" name="problem_health_others_specify" placeholder="Specify" 
                                                value="<?= getFormValue('problem_health_others_specify', $form_data) ?>" 
                                                class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Housing -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-md mb-3">D. Housing</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_overcrowding" value="1" <?= isCheckboxChecked($form_data, 'problem_overcrowding') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Overcrowding in the Family Home</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_no_permanent_housing" value="1" <?= isCheckboxChecked($form_data, 'problem_no_permanent_housing') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">No Permanent Housing</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_independent_living" value="1" <?= isCheckboxChecked($form_data, 'problem_independent_living') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Longing for Independent Living/Quiet Atmosphere</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_lost_privacy" value="1" <?= isCheckboxChecked($form_data, 'problem_lost_privacy') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Lost Privacy</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_squatters" value="1" <?= isCheckboxChecked($form_data, 'problem_squatters') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Living in Squatter's Areas</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_high_rent" value="1" <?= isCheckboxChecked($form_data, 'problem_high_rent') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">High Cost Rent</span>
                                    </label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="problem_housing_others" value="1" <?= isCheckboxChecked($form_data, 'problem_housing_others') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Others</span>
                                    </label>
                                    <input type="text" name="problem_housing_others_specify" placeholder="Specify" 
                                        value="<?= getFormValue('problem_housing_others_specify', $form_data) ?>" 
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Community Service -->
                        <div class="mb-6">
                            <h4 class="font-semibold text-md mb-3">E. Community Service</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_desire_participate" value="1" <?= isCheckboxChecked($form_data, 'problem_desire_participate') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Desire to Participate</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_skills_to_share" value="1" <?= isCheckboxChecked($form_data, 'problem_skills_to_share') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Skills/Resources to Share</span>
                                    </label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <input type="checkbox" name="problem_community_others" value="1" <?= isCheckboxChecked($form_data, 'problem_community_others') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Others</span>
                                    </label>
                                    <input type="text" name="problem_community_others_specify" placeholder="Specify" 
                                        value="<?= getFormValue('problem_community_others_specify', $form_data) ?>" 
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Other Specific Needs -->
                        <div>
                            <h4 class="font-semibold text-md mb-3">F. Identify Other Specific Needs</h4>
                            <div class="pl-4">
                                <textarea name="other_specific_needs" rows="4" class="w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()"><?= getFormValue('other_specific_needs', $form_data) ?></textarea>
                            </div>
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

                // Handle "Same as Present Address" checkbox
                const sameAsPresent = document.getElementById('sameAsPresent');
                const permanentFields = document.getElementById('permanentAddressFields');

                function copyPresentToPermanent() {
                    if (sameAsPresent.checked) {
                        // Copy values from present to permanent address fields
                        const fieldMappings = [
                            ['house_no', 'permanent_house_no'],
                            ['street', 'permanent_street'],
                            ['barangay', 'permanent_barangay'],
                            ['municipality', 'permanent_municipality'],
                            ['province', 'permanent_province'],
                            ['region', 'permanent_region']
                        ];

                        fieldMappings.forEach(([presentField, permanentField]) => {
                            const presentValue = document.querySelector(`[name="present_${presentField}"]`).value;
                            const permanentInput = document.querySelector(`[name="${permanentField}"]`);
                            permanentInput.value = presentValue;
                            permanentInput.disabled = true;
                        });
                    } else {
                        // Enable permanent address fields
                        permanentFields.querySelectorAll('input').forEach(input => {
                            input.disabled = false;
                        });
                    }
                }

                sameAsPresent.addEventListener('change', copyPresentToPermanent);
                
                // Handle Family Members table
                const addFamilyMemberBtn = document.getElementById('addFamilyMember');
                const familyMembersTable = document.getElementById('familyMembersTable');
                
                addFamilyMemberBtn.addEventListener('click', function() {
                    const newRow = familyMembersTable.querySelector('.family-member-row').cloneNode(true);
                    
                    // Clear the values in the cloned row
                    newRow.querySelectorAll('input').forEach(input => {
                        input.value = '';
                    });
                    
                    // Reset dropdowns to first option
                    newRow.querySelectorAll('select').forEach(select => {
                        select.selectedIndex = 0;
                    });
                    
                    // Add a remove button if it doesn't exist
                    if (!newRow.querySelector('.remove-btn')) {
                        const lastCell = newRow.lastElementChild;
                        const removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'remove-btn text-red-600 hover:text-red-800 ml-2';
                        removeBtn.innerHTML = '&times;';
                        removeBtn.onclick = function() {
                            this.closest('tr').remove();
                        };
                        lastCell.appendChild(removeBtn);
                    }
                    
                    familyMembersTable.appendChild(newRow);
                });
                
                // Handle "Others" option for religion
                const religionSelect = document.querySelector('select[name="religion"]');
                const otherReligionContainer = document.getElementById('other_religion_container');
                
                function toggleOtherReligionField() {
                    if (religionSelect.value === 'OTHERS') {
                        otherReligionContainer.style.display = 'block';
                    } else {
                        otherReligionContainer.style.display = 'none';
                    }
                }
                
                // Initialize on page load
                toggleOtherReligionField();
                religionSelect.addEventListener('change', toggleOtherReligionField);

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
                
                // Auto-capitalize all inputs
                document.querySelectorAll('input[type="text"]').forEach(input => {
                    input.addEventListener('input', function() {
                        this.value = this.value.toUpperCase();
                    });
                });

                            // Initialize event handlers
            document.addEventListener('DOMContentLoaded', function() {
                // Initialize birth date handler
                const birthDateInput = document.getElementById('birth_date');
                if (birthDateInput) {
                    birthDateInput.addEventListener('change', calculateAge);
                    // Calculate initial age if birth date exists
                    if (birthDateInput.value) {
                        calculateAge();
                    }
                }

                // Function to calculate age
                function calculateAge() {
                    const birthDateInput = document.getElementById('birth_date');
                    const ageInput = document.getElementById('age');
                    
                    if (!birthDateInput || !ageInput) return;
                    
                    const birthDate = birthDateInput.value;
                    if (birthDate) {
                        const today = new Date();
                        const birth = new Date(birthDate);
                        let age = today.getFullYear() - birth.getFullYear();
                        const monthDiff = today.getMonth() - birth.getMonth();
                        
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                            age--;
                        }
                        
                        ageInput.value = age;
                    } else {
                        ageInput.value = '';
                    }
                }
            });
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
            const searchInput = document.getElementById('search-resident');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
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
            }

            // Category filter functionality
            const btnAll = document.getElementById('btn-all');
            const btnSeniors = document.getElementById('btn-seniors');
            const btnChildren = document.getElementById('btn-children');

            if (btnAll) {
                btnAll.addEventListener('click', function() {
                    filterByCategory('all');
                    updateActiveButton(this);
                });
            }

            if (btnSeniors) {
                btnSeniors.addEventListener('click', function() {
                    filterByCategory('Senior');
                    updateActiveButton(this);
                });
            }

            if (btnChildren) {
                btnChildren.addEventListener('click', function() {
                    filterByCategory('Child');
                    updateActiveButton(this);
                });
            }

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
                            $age = $resident['age'] ?? (function ($birth_date) {
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
                                    <?= $resident['relationship_name'] ?>
                                    <?= $resident['is_household_head'] ? ' (Head)' : '' ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?= $resident['address'] ?? 'No address provided' ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="<?= $category === 'Senior' ? 'bg-purple-100 text-purple-800' : ($category === 'Child' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?> 
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