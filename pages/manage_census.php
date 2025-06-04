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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize input
    $first_name   = trim($_POST['first_name'] ?? '');
    $middle_name  = trim($_POST['middle_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $suffix       = trim($_POST['suffix'] ?? '');
    $birth_date   = $_POST['birth_date'] ?? '';
    $birth_place  = trim($_POST['birth_place'] ?? '');
    $gender       = $_POST['gender'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $citizenship  = trim($_POST['citizenship'] ?? 'Filipino');
    $religion     = trim($_POST['religion'] ?? '');
    $education    = trim($_POST['education_level'] ?? '');
    $occupation   = trim($_POST['occupation'] ?? '');
    $monthly_income = $_POST['monthly_income'] ?? '';
    $contact_number = trim($_POST['contact_number'] ?? '');
    $house_no     = trim($_POST['house_no'] ?? '');
    $street       = trim($_POST['street'] ?? '');
    $subdivision  = trim($_POST['subdivision'] ?? '');
    $block_lot    = trim($_POST['block_lot'] ?? '');
    $phase        = trim($_POST['phase'] ?? '');
    $municipality = trim($_POST['municipality'] ?? 'SAN RAFAEL');
    $province     = trim($_POST['province'] ?? 'BULACAN');
    $residency_type = $_POST['residency_type'] ?? '';
    $years_in_san_rafael = $_POST['years_in_san_rafael'] ?? '';
    $household_id = $_POST['household_id'] ?? '';
    $relationship = $_POST['relationship'] ?? '';
    $is_household_head = isset($_POST['is_household_head']) ? 1 : 0;
    $resident_type = $_POST['resident_type'] ?? 'regular';

    // Validate required fields
    $required = [
        'First Name' => $first_name,
        'Last Name' => $last_name,
        'Birth Date' => $birth_date,
        'Gender' => $gender,
        'Civil Status' => $civil_status,
        'Household ID' => $household_id,
    ];
    foreach ($required as $label => $val) {
        if (!$val) $add_error .= "$label is required.<br>";
    }
    // Validate household exists
    $household_exists = false;
    foreach ($households as $h) {
        if ($h['household_id'] == $household_id) $household_exists = true;
    }
    if (!$household_exists) $add_error .= "Selected household does not exist.<br>";

    if (!$add_error) {
        try {
            $pdo->beginTransaction();

            // Insert into persons
            $stmt = $pdo->prepare("INSERT INTO persons 
                (first_name, middle_name, last_name, suffix, birth_date, birth_place, gender, civil_status, citizenship, religion, education_level, occupation, monthly_income, contact_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $first_name, $middle_name, $last_name, $suffix, $birth_date, $birth_place, $gender, $civil_status, $citizenship, $religion, $education, $occupation, $monthly_income, $contact_number
            ]);
            $person_id = $pdo->lastInsertId();

            // Insert into addresses
            $stmt = $pdo->prepare("INSERT INTO addresses 
                (person_id, barangay_id, house_no, street, subdivision, block_lot, phase, residency_type, years_in_san_rafael, is_primary)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([
                $person_id, $barangay_id, $house_no, $street, $subdivision, $block_lot, $phase, $residency_type, $years_in_san_rafael
            ]);

            // Insert into household_members
            $stmt = $pdo->prepare("INSERT INTO household_members 
                (household_id, person_id, relationship_to_head, is_household_head)
                VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $household_id, $person_id, $relationship, $is_household_head
            ]);

            // If Senior or PWD, insert into senior_health or other tables as needed
            if ($resident_type === 'senior') {
                $stmt = $pdo->prepare("INSERT INTO senior_health (person_id) VALUES (?)");
                $stmt->execute([$person_id]);
            }
            if ($resident_type === 'pwd') {
                // You may want to insert into a PWD-specific table if you have one
                // Example: $stmt = $pdo->prepare("INSERT INTO pwd_information (person_id) VALUES (?)");
                // $stmt->execute([$person_id]);
            }

            $pdo->commit();
            $add_success = "Resident added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $add_error = "Error adding resident: " . htmlspecialchars($e->getMessage());
        }
    }
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

        <?php if ($add_error): ?>
            <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4"><?= $add_error ?></div>
        <?php elseif ($add_success): ?>
            <div class="bg-green-100 text-green-700 px-4 py-2 rounded mb-4"><?= $add_success ?></div>
        <?php endif; ?>

        <!-- Regular Resident Form -->
        <div id="add-resident" class="tab-content active bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">Add New Resident</h2>
            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">Resident Type</label>
                <select id="residentTypeSelect" name="resident_type" class="border rounded p-2 w-full md:w-1/3" form="residentForm">
                    <option value="regular" selected>Regular</option>
                    <option value="senior">Senior Citizen</option>
                    <option value="pwd">Person with Disability (PWD)</option>
                </select>
            </div>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="residentForm" autocomplete="off">
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Personal Information</h3>
                    <div>
                        <label class="block text-sm font-medium">First Name *</label>
                        <input type="text" name="first_name" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Middle Name</label>
                        <input type="text" name="middle_name" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Last Name *</label>
                        <input type="text" name="last_name" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr, Sr, III, etc."
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Date of Birth *</label>
                        <input type="date" name="birth_date" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Place of Birth *</label>
                        <input type="text" name="birth_place" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-medium">Gender *</label>
                        <div class="flex gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Male" required 
                                       class="form-radio">
                                <span class="ml-2">Male</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Female" 
                                       class="form-radio">
                                <span class="ml-2">Female</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Others" 
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
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Widowed">Widowed</option>
                            <option value="Separated">Separated</option>
                            <option value="Widow/Widower">Widow/Widower</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Citizenship</label>
                        <input type="text" name="citizenship" value="Filipino" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Religion</label>
                        <select name="religion" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Religion --</option>
                            <option value="Roman Catholic">Roman Catholic</option>
                            <option value="Protestant">Protestant</option>
                            <option value="Iglesia Ni Cristo">Iglesia Ni Cristo</option>
                            <option value="Islam">Islam</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Educational Attainment</label>
                        <select name="education_level" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Education Level --</option>
                            <option value="Not Attended Any School">Not Attended Any School</option>
                            <option value="Elementary Level">Elementary Level</option>
                            <option value="Elementary Graduate">Elementary Graduate</option>
                            <option value="High School Level">High School Level</option>
                            <option value="High School Graduate">High School Graduate</option>
                            <option value="Vocational">Vocational</option>
                            <option value="College Level">College Level</option>
                            <option value="College Graduate">College Graduate</option>
                            <option value="Post Graduate">Post Graduate</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Occupation</label>
                        <input type="text" name="occupation" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Monthly Income</label>
                        <select name="monthly_income" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Income Range --</option>
                            <option value="0">No Income</option>
                            <option value="999">999 & below</option>
                            <option value="1500">1,000-1,999</option>
                            <option value="2500">2,000-2,999</option>
                            <option value="3500">3,000-3,999</option>
                            <option value="4500">4,000-4,999</option>
                            <option value="5500">5,000-5,999</option>
                            <option value="6500">6,000-6,999</option>
                            <option value="7500">7,000-7,999</option>
                            <option value="8500">8,000-8,999</option>
                            <option value="9500">9,000-9,999</option>
                            <option value="10000">10,000 & above</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Contact Number</label>
                        <input type="text" name="contact_number" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Address Information</h3>
                    <div>
                        <label class="block text-sm font-medium">House No.</label>
                        <input type="text" name="house_no" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Street</label>
                        <input type="text" name="street" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Subdivision/Purok/Zone/Sitio</label>
                        <input type="text" name="subdivision" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Block/Lot</label>
                        <input type="text" name="block_lot" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Phase</label>
                        <input type="text" name="phase" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">City/Municipality</label>
                        <input type="text" name="municipality" value="SAN RAFAEL" 
                               class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Province</label>
                        <input type="text" name="province" value="BULACAN" 
                               class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Residency Type</label>
                        <select name="residency_type" class="mt-1 block w-full border rounded p-2">
                            <option value="Home Owner">Home Owner</option>
                            <option value="Renter">Renter</option>
                            <option value="Sharer">Sharer</option>
                            <option value="Care Taker">Care Taker</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Years in San Rafael</label>
                        <input type="number" name="years_in_san_rafael" min="0" max="100" 
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
                                    <option value="<?= htmlspecialchars($household['household_id']) ?>">
                                        <?= htmlspecialchars($household['household_id']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">If household is not listed, create it in the Manage Households tab</div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium">Relationship to Head</label>
                            <select name="relationship" class="mt-1 block w-full border rounded p-2">
                                <option value="Head">Head</option>
                                <option value="Spouse">Spouse</option>
                                <option value="Child">Child</option>
                                <option value="Parent">Parent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Grandchild">Grandchild</option>
                                <option value="Other Relative">Other Relative</option>
                                <option value="Non-relative">Non-relative</option>
                            </select>
                        </div>
                        
                        <div class="flex items-center">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_household_head" class="form-checkbox">
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
            select.addEventListener('change', function() {
                let existing = form.querySelector('input[name="resident_type"]');
                if (!existing) {
                    existing = document.createElement('input');
                    existing.type = 'hidden';
                    existing.name = 'resident_type';
                    form.appendChild(existing);
                }
                existing.value = select.value;
            });
            let existing = form.querySelector('input[name="resident_type"]');
            if (!existing) {
                existing = document.createElement('input');
                existing.type = 'hidden';
                existing.name = 'resident_type';
                form.appendChild(existing);
            }
            existing.value = select.value;
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
                            $age = $resident['age'] ?? calculateAge($resident['birth_date']);
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
                                <a href="view_resident.php?id=<?= $resident['person_id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                <a href="edit_resident.php?id=<?= $resident['person_id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <a href="javascript:void(0)" onclick="confirmDelete(<?= $resident['person_id'] ?>)" class="text-red-600 hover:text-red-900">Delete</a>
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
