<?php
ob_start(); // Start output buffering
require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../pages/header.php";

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: census_records.php");
    exit;
}

$person_id = $_GET['id'];

// Fetch barangay name for display
$barangay_name = '';
if (isset($_SESSION['barangay_id'])) {
    $stmt = $pdo->prepare('SELECT name FROM barangay WHERE id = ?');
    $stmt->execute([$_SESSION['barangay_id']]);
    $barangay_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($barangay_row) {
        $barangay_name = $barangay_row['name'];
    }
}

try {
    // Fetch person's basic information
    $stmt = $pdo->prepare("
        SELECT p.*, 
               a.house_no, a.street, a.phase as sitio,
               hm.household_id, hm.relationship_type_id,
               rt.name as relationship_name,
               hm.is_household_head,
               h.purok_id as purok_id,
               pi.osca_id, pi.gsis_id, pi.sss_id, pi.tin_id, pi.philhealth_id, pi.other_id_type, pi.other_id_number
        FROM persons p
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        LEFT JOIN person_identification pi ON p.id = pi.person_id
        WHERE p.id = ?
    ");
    $stmt->execute([$person_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        header("Location: census_records.php");
        exit;
    }

    // Fetch income sources
    $stmt = $pdo->prepare("
        SELECT pis.*, ist.name as source_name, ist.requires_amount
        FROM person_income_sources pis
        JOIN income_source_types ist ON pis.source_type_id = ist.id
        WHERE pis.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $income_sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert income sources to a more accessible format
    $income_data = [];
    foreach ($income_sources as $source) {
        $key = strtolower(str_replace([' ', '/'], '_', $source['source_name']));
        $income_data[$key] = [
            'checked' => true,
            'amount' => $source['amount'],
            'details' => $source['details']
        ];
    }

    // Fetch assets
    $stmt = $pdo->prepare("
        SELECT pa.*, at.name as asset_name
        FROM person_assets pa
        JOIN asset_types at ON pa.asset_type_id = at.id
        WHERE pa.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build an array of asset type IDs and details for 'Others'
    $asset_type_ids = [];
    $asset_details = [];
    foreach ($assets as $asset) {
        $asset_type_ids[] = (int)$asset['asset_type_id'];
        if (strtolower($asset['asset_name']) === 'others') {
            $asset_details['others'] = $asset['details'];
        }
    }

    // Fetch households for selection
    $stmt = $pdo->prepare("
        SELECT h.id AS household_id, h.household_number, h.purok_id, p.name as purok_name
        FROM households h
        LEFT JOIN purok p ON h.purok_id = p.id
        WHERE h.barangay_id = ? 
        ORDER BY h.household_number
    ");
    $stmt->execute([$_SESSION['barangay_id']]);
    $households = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch relationship types
    $stmt = $pdo->prepare("SELECT id, name FROM relationship_types ORDER BY name");
    $stmt->execute();
    $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch living arrangements (with details)
    $stmt = $pdo->prepare("
        SELECT arrangement_type_id, details
        FROM person_living_arrangements
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $living_arrangements_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $living_arrangements = [];
    $living_others_details = '';
    // Find the 'Others' type id
    $stmt = $pdo->prepare("SELECT id FROM living_arrangement_types WHERE LOWER(name) = 'others'");
    $stmt->execute();
    $others_type_id = $stmt->fetchColumn();
    foreach ($living_arrangements_raw as $arr) {
        $living_arrangements[] = (string)$arr['arrangement_type_id'];
        if ($arr['arrangement_type_id'] == $others_type_id) {
            $living_others_details = $arr['details'];
        }
    }

    // Fetch skills (with details)
    $stmt = $pdo->prepare("
        SELECT skill_type_id, details
        FROM person_skills
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $skills_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $skills = [];
    $skill_others_details = '';
    // Find the 'Others' type id
    $stmt = $pdo->prepare("SELECT id FROM skill_types WHERE LOWER(name) = 'others'");
    $stmt->execute();
    $skill_others_type_id = $stmt->fetchColumn();
    foreach ($skills_raw as $arr) {
        $skills[] = (string)$arr['skill_type_id'];
        if ($arr['skill_type_id'] == $skill_others_type_id) {
            $skill_others_details = $arr['details'];
        }
    }

    // Fetch medical conditions
    $stmt = $pdo->prepare("
        SELECT * 
        FROM person_health_info 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $health_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch problem categories
    $stmt = $pdo->prepare("
        SELECT * FROM person_economic_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $economic_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT * FROM person_social_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $social_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT * FROM person_health_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $health_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT * FROM person_housing_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $housing_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT * FROM person_community_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $community_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch puroks for selection
    $stmt = $pdo->prepare("SELECT id, name FROM purok WHERE barangay_id = ? ORDER BY name");
    $stmt->execute([$_SESSION['barangay_id']]);
    $puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch community involvements (with details)
    $stmt = $pdo->prepare("
        SELECT involvement_type_id, details
        FROM person_involvements
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $involvements_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $involvements = [];
    $involvement_others_details = '';
    // Find the 'Others' type id
    $stmt = $pdo->prepare("SELECT id FROM involvement_types WHERE LOWER(name) = 'others'");
    $stmt->execute();
    $involvement_others_type_id = $stmt->fetchColumn();
    foreach ($involvements_raw as $arr) {
        $involvements[] = (string)$arr['involvement_type_id'];
        if ($arr['involvement_type_id'] == $involvement_others_type_id) {
            $involvement_others_details = $arr['details'];
        }
    }

    // Fetch family composition for this resident
    $stmt = $pdo->prepare("SELECT * FROM family_composition WHERE person_id = ?");
    $stmt->execute([$person_id]);
    $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = "Error fetching data: " . $e->getMessage();
    header("Location: census_records.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'birth_date', 'birth_place', 'gender', 'civil_status'];
        $errors = [];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
            }
        }

        if (!empty($errors)) {
            throw new Exception(implode(", ", $errors));
        }

        // Validate birth date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['birth_date'])) {
            throw new Exception("Invalid birth date format");
        }

        // Validate years of residency
        if (isset($_POST['years_of_residency']) && !is_numeric($_POST['years_of_residency'])) {
            throw new Exception("Years of residency must be a number");
        }

        $pdo->beginTransaction();

        // Prepare data arrays for batch updates
        $person_data = [
            'first_name' => trim($_POST['first_name']),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'last_name' => trim($_POST['last_name']),
            'suffix' => trim($_POST['suffix'] ?? ''),
            'birth_date' => $_POST['birth_date'],
            'birth_place' => trim($_POST['birth_place']),
            'gender' => $_POST['gender'],
            'civil_status' => $_POST['civil_status'],
            'citizenship' => trim($_POST['citizenship'] ?? 'Filipino'),
            'religion' => trim($_POST['religion'] ?? ''),
            'education_level' => trim($_POST['education_level'] ?? ''),
            'occupation' => trim($_POST['occupation'] ?? ''),
            'monthly_income' => $_POST['monthly_income'] ?? null,
            'years_of_residency' => (int)($_POST['years_of_residency'] ?? 0),
            'resident_type' => $_POST['resident_type'] ?? 'regular',
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            'nhts_pr_listahanan' => isset($_POST['nhts_pr_listahanan']) ? 1 : 0,
            'indigenous_people' => isset($_POST['indigenous_people']) ? 1 : 0,
            'pantawid_beneficiary' => isset($_POST['pantawid_beneficiary']) ? 1 : 0
        ];

        // Update person information
        $person_sql = "UPDATE persons SET " . 
            implode(', ', array_map(function($key) { return "$key = :$key"; }, array_keys($person_data))) . 
            " WHERE id = :id";
        $person_data['id'] = $person_id;
        
        $stmt = $pdo->prepare($person_sql);
        $stmt->execute($person_data);

        // Update ID Numbers
        $id_data = [
            'osca_id' => trim($_POST['osca_id'] ?? ''),
            'gsis_id' => trim($_POST['gsis_id'] ?? ''),
            'sss_id' => trim($_POST['sss_id'] ?? ''),
            'tin_id' => trim($_POST['tin_id'] ?? ''),
            'philhealth_id' => trim($_POST['philhealth_id'] ?? ''),
            'other_id_type' => trim($_POST['other_id_type'] ?? ''),
            'other_id_number' => trim($_POST['other_id_number'] ?? '')
        ];

        $id_sql = "INSERT INTO person_identification (person_id, " . 
            implode(', ', array_keys($id_data)) . 
            ") VALUES (:person_id, :" . implode(', :', array_keys($id_data)) . 
            ") ON DUPLICATE KEY UPDATE " . 
            implode(', ', array_map(function($key) { return "$key = :$key"; }, array_keys($id_data)));
        
        $id_data['person_id'] = $person_id;
        $stmt = $pdo->prepare($id_sql);
        $stmt->execute($id_data);

        // Update address
        $address_data = [
            'house_no' => trim($_POST['present_house_no'] ?? ''),
            'street' => trim($_POST['present_street'] ?? ''),
            'phase' => trim($_POST['present_sitio'] ?? ''),
            'municipality' => 'SAN RAFAEL',
            'province' => 'BULACAN',
            'region' => 'III'
        ];

        $address_sql = "UPDATE addresses SET " . 
            implode(', ', array_map(function($key) { return "$key = :$key"; }, array_keys($address_data))) . 
            " WHERE person_id = :person_id AND is_primary = 1";
        
        $address_data['person_id'] = $person_id;
        $stmt = $pdo->prepare($address_sql);
        $stmt->execute($address_data);

        // Update household membership if provided
        if (!empty($_POST['household_id'])) {
            $household_data = [
                'household_id' => $_POST['household_id'],
                'relationship_type_id' => $_POST['relationship'],
                'is_household_head' => isset($_POST['is_household_head']) ? 1 : 0
            ];

            $household_sql = "UPDATE household_members SET " . 
                implode(', ', array_map(function($key) { return "$key = :$key"; }, array_keys($household_data))) . 
                " WHERE person_id = :person_id";
            
            $household_data['person_id'] = $person_id;
            $stmt = $pdo->prepare($household_sql);
            $stmt->execute($household_data);
        }

        // Update living arrangements
        $stmt = $pdo->prepare("DELETE FROM person_living_arrangements WHERE person_id = ?");
        $stmt->execute([$person_id]);

        $living_fields = [
            'living_alone' => 'alone',
            'living_spouse' => 'spouse',
            'living_children' => 'children',
            'living_grandchildren' => 'grandchildren',
            'living_in_laws' => 'in_laws',
            'living_relatives' => 'relatives',
            'living_househelps' => 'househelps',
            'living_care_institutions' => 'care_institutions',
            'living_common_law_spouse' => 'common_law_spouse'
        ];

        $living_sql = "INSERT INTO person_living_arrangements (person_id, arrangement_type_id) 
            VALUES (?, (SELECT id FROM living_arrangement_types WHERE name = ?))";
        $living_stmt = $pdo->prepare($living_sql);

        foreach ($living_fields as $field => $type) {
            if (isset($_POST[$field]) && $_POST[$field] == 1) {
                $living_stmt->execute([$person_id, $type]);
            }
        }

        // Update skills
        $stmt = $pdo->prepare("DELETE FROM person_skills WHERE person_id = ?");
        $stmt->execute([$person_id]);

        $skill_fields = [
            'skill_medical' => 'medical',
            'skill_teaching' => 'teaching',
            'skill_legal_services' => 'legal_services',
            'skill_dental' => 'dental',
            'skill_counseling' => 'counseling',
            'skill_evangelization' => 'evangelization',
            'skill_farming' => 'farming',
            'skill_fishing' => 'fishing',
            'skill_cooking' => 'cooking',
            'skill_vocational' => 'vocational',
            'skill_arts' => 'arts',
            'skill_engineering' => 'engineering'
        ];

        $skill_sql = "INSERT INTO person_skills (person_id, skill_type_id) 
            VALUES (?, (SELECT id FROM skill_types WHERE name = ?))";
        $skill_stmt = $pdo->prepare($skill_sql);

        foreach ($skill_fields as $field => $type) {
            if (isset($_POST[$field]) && $_POST[$field] == 1) {
                $skill_stmt->execute([$person_id, $type]);
            }
        }

        // Update medical conditions
        $stmt = $pdo->prepare("DELETE FROM person_health_info WHERE person_id = ?");
        $stmt->execute([$person_id]);

        $health_data = [
            'person_id' => $person_id,
            'health_condition' => trim($_POST['health_condition'] ?? ''),
            'has_maintenance' => isset($_POST['has_maintenance']) ? 1 : 0,
            'maintenance_details' => trim($_POST['maintenance_details'] ?? ''),
            'high_cost_medicines' => isset($_POST['high_cost_medicines']) ? 1 : 0,
            'lack_medical_professionals' => isset($_POST['lack_medical_professionals']) ? 1 : 0,
            'lack_sanitation_access' => isset($_POST['lack_sanitation_access']) ? 1 : 0,
            'lack_health_insurance' => isset($_POST['lack_health_insurance']) ? 1 : 0,
            'lack_medical_facilities' => isset($_POST['lack_medical_facilities']) ? 1 : 0,
            'other_health_concerns' => trim($_POST['other_health_concerns'] ?? '')
        ];

        $health_sql = "INSERT INTO person_health_info (" . 
            implode(', ', array_keys($health_data)) . 
            ") VALUES (:" . implode(', :', array_keys($health_data)) . ")";
        
        $stmt = $pdo->prepare($health_sql);
        $stmt->execute($health_data);

        // Update problem categories
        $problem_tables = [
            'person_economic_problems' => [
                'loss_income' => isset($_POST['problem_lack_income']) ? 1 : 0,
                'unemployment' => isset($_POST['problem_loss_income']) ? 1 : 0,
                'high_cost_living' => isset($_POST['problem_employment']) ? 1 : 0,
                'skills_training' => isset($_POST['problem_employment']) ? 1 : 0,
                'skills_training_details' => $_POST['problem_employment_details'] ?? null,
                'livelihood' => isset($_POST['problem_employment']) ? 1 : 0,
                'livelihood_details' => $_POST['problem_employment_details'] ?? null,
                'other_economic' => isset($_POST['problem_employment']) ? 1 : 0,
                'other_economic_details' => $_POST['problem_employment_details'] ?? null
            ],
            'person_social_problems' => [
                'loneliness' => isset($_POST['problem_social']) ? 1 : 0,
                'isolation' => isset($_POST['problem_social']) ? 1 : 0,
                'neglect' => isset($_POST['problem_social']) ? 1 : 0,
                'recreational' => isset($_POST['problem_social']) ? 1 : 0,
                'senior_friendly' => isset($_POST['problem_social']) ? 1 : 0,
                'other_social' => isset($_POST['problem_social']) ? 1 : 0,
                'other_social_details' => $_POST['problem_social_details'] ?? null
            ],
            'person_health_problems' => [
                'condition_illness' => isset($_POST['problem_health']) ? 1 : 0,
                'condition_illness_details' => $_POST['problem_health_details'] ?? null,
                'high_cost_medicine' => isset($_POST['problem_health']) ? 1 : 0,
                'lack_medical_professionals' => isset($_POST['problem_health']) ? 1 : 0,
                'lack_sanitation' => isset($_POST['problem_health']) ? 1 : 0,
                'lack_health_insurance' => isset($_POST['problem_health']) ? 1 : 0,
                'inadequate_health_services' => isset($_POST['problem_health']) ? 1 : 0,
                'other_health' => isset($_POST['problem_health']) ? 1 : 0,
                'other_health_details' => $_POST['problem_health_details'] ?? null
            ],
            'person_housing_problems' => [
                'overcrowding' => isset($_POST['problem_housing']) ? 1 : 0,
                'no_permanent_housing' => isset($_POST['problem_housing']) ? 1 : 0,
                'independent_living' => isset($_POST['problem_housing']) ? 1 : 0,
                'lost_privacy' => isset($_POST['problem_housing']) ? 1 : 0,
                'squatters' => isset($_POST['problem_housing']) ? 1 : 0,
                'other_housing' => isset($_POST['problem_housing']) ? 1 : 0,
                'other_housing_details' => $_POST['problem_housing_details'] ?? null
            ],
            'person_community_problems' => [
                'desire_participate' => isset($_POST['problem_other']) ? 1 : 0,
                'skills_to_share' => isset($_POST['problem_other']) ? 1 : 0,
                'other_community' => isset($_POST['problem_other']) ? 1 : 0,
                'other_community_details' => $_POST['problem_other_details'] ?? null
            ]
        ];

        foreach ($problem_tables as $table => $data) {
            // Delete existing records
            $stmt = $pdo->prepare("DELETE FROM $table WHERE person_id = ?");
            $stmt->execute([$person_id]);

            // Insert new records
            $data['person_id'] = $person_id;
            $sql = "INSERT INTO $table (" . 
                implode(', ', array_keys($data)) . 
                ") VALUES (:" . implode(', :', array_keys($data)) . ")";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
        }

        $pdo->commit();
        $success = "Resident record updated successfully!";
        
        // Refresh the data
        header("Location: edit_resident.php?id=" . $person_id . "&success=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error updating record: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Resident Record</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <style>
        input[type="text"],
        textarea,
        select {
            text-transform: uppercase;
        }

        /* Ensure placeholder text is also uppercase */
        ::placeholder {
            text-transform: uppercase;
            opacity: 0.7;
        }

        /* Fix for Firefox */
        ::-moz-placeholder {
            text-transform: uppercase;
            opacity: 0.7;
        }

        /* Make sure input values are more readable */
        input[type="text"],
        textarea,
        select {
            font-weight: 500;
            letter-spacing: 0.02em;
        }

        /* Keep labels in their original case */
        label,
        h1,
        h2,
        h3,
        h4,
        h5,
        h6,
        p,
        span:not(.ml-2) {
            text-transform: none;
        }
    </style>
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
            <a href="manage_puroks.php" class="w-full sm:w-auto text-white bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 font-medium rounded-lg text-sm px-5 py-2.5">Manage Puroks</a>
        </div>
        <section id="edit-resident" class="bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800 mb-6">EDIT RESIDENT RECORD</h2>
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?= $error ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    Record updated successfully!
                </div>
            <?php endif; ?>
            <div class="mb-6">
                <label class="block text-sm font-medium">Resident Type</label>
                <select name="resident_type" class="mt-1 block w-full border rounded p-2">
                    <option value="regular" <?= $person['resident_type'] === 'regular' ? 'selected' : '' ?>>Regular</option>
                    <option value="pwd" <?= $person['resident_type'] === 'pwd' ? 'selected' : '' ?>>Person With Disability (PWD)</option>
                    <option value="senior" <?= $person['resident_type'] === 'senior' ? 'selected' : '' ?>>Senior Citizen</option>
                </select>
            </div>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4" autocomplete="off">
                <!-- Column 1: Basic Personal Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Basic Information</h3>
                        <div>
                        <label class="block text-sm font-medium">First Name *</label>
                        <input type="text" name="first_name" required value="<?= htmlspecialchars($person['first_name']) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($person['middle_name'] ?? '') ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Last Name *</label>
                        <input type="text" name="last_name" required value="<?= htmlspecialchars($person['last_name']) ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Suffix</label>
                            <input type="text" name="suffix" value="<?= htmlspecialchars($person['suffix'] ?? '') ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()" maxlength="5">
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Citizenship</label>
                        <input type="text" name="citizenship" value="<?= htmlspecialchars($person['citizenship'] ?? 'Filipino') ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Contact Number</label>
                        <input type="text" name="contact_number" value="<?= htmlspecialchars($person['contact_number'] ?? '') ?>"
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
                        <div>
                        <label class="block text-sm font-medium">Birth Date *</label>
                        <input type="date" name="birth_date" id="birth_date" value="<?= htmlspecialchars($person['birth_date']) ?>" required
                            class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Age</label>
                        <input type="number" name="age" id="age" value="" readonly
                            class="mt-1 block w-full border rounded p-2 bg-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Place of Birth *</label>
                        <input type="text" name="birth_place" value="<?= htmlspecialchars($person['birth_place'] ?? '') ?>" required
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Sex *</label>
                        <select name="gender" required class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT GENDER --</option>
                            <option value="MALE" <?= ($person['gender'] ?? '') === 'MALE' ? 'selected' : '' ?>>MALE</option>
                            <option value="FEMALE" <?= ($person['gender'] ?? '') === 'FEMALE' ? 'selected' : '' ?>>FEMALE</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Civil Status *</label>
                        <select name="civil_status" required class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT CIVIL STATUS --</option>
                            <option value="SINGLE" <?= ($person['civil_status'] ?? '') === 'SINGLE' ? 'selected' : '' ?>>SINGLE</option>
                            <option value="MARRIED" <?= ($person['civil_status'] ?? '') === 'MARRIED' ? 'selected' : '' ?>>MARRIED</option>
                            <option value="WIDOWED" <?= ($person['civil_status'] ?? '') === 'WIDOWED' ? 'selected' : '' ?>>WIDOWED</option>
                            <option value="SEPARATED" <?= ($person['civil_status'] ?? '') === 'SEPARATED' ? 'selected' : '' ?>>SEPARATED</option>
                        </select>
                    </div>
                        <div>
                        <label class="block text-sm font-medium">Religion</label>
                        <select name="religion" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT RELIGION --</option>
                            <option value="ROMAN CATHOLIC" <?= ($person['religion'] ?? '') === 'ROMAN CATHOLIC' ? 'selected' : '' ?>>ROMAN CATHOLIC</option>
                            <option value="PROTESTANT" <?= ($person['religion'] ?? '') === 'PROTESTANT' ? 'selected' : '' ?>>PROTESTANT</option>
                            <option value="IGLESIA NI CRISTO" <?= ($person['religion'] ?? '') === 'IGLESIA NI CRISTO' ? 'selected' : '' ?>>IGLESIA NI CRISTO</option>
                            <option value="ISLAM" <?= ($person['religion'] ?? '') === 'ISLAM' ? 'selected' : '' ?>>ISLAM</option>
                            <option value="OTHERS" <?= ($person['religion'] ?? '') === 'OTHERS' ? 'selected' : '' ?>>OTHERS</option>
                        </select>
                        </div>
                        </div>
                <!-- Column 3: Socio-Economic Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Socio-Economic Profile</h3>
                        <div>
                        <label class="block text-sm font-medium">Education Attainment</label>
                        <select name="education_level" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT EDUCATION LEVEL --</option>
                            <option value="NOT ATTENDED ANY SCHOOL" <?= ($person['education_level'] ?? '') === 'NOT ATTENDED ANY SCHOOL' ? 'selected' : '' ?>>NOT ATTENDED ANY SCHOOL</option>
                            <option value="ELEMENTARY LEVEL" <?= ($person['education_level'] ?? '') === 'ELEMENTARY LEVEL' ? 'selected' : '' ?>>ELEMENTARY LEVEL</option>
                            <option value="ELEMENTARY GRADUATE" <?= ($person['education_level'] ?? '') === 'ELEMENTARY GRADUATE' ? 'selected' : '' ?>>ELEMENTARY GRADUATE</option>
                            <option value="HIGH SCHOOL LEVEL" <?= ($person['education_level'] ?? '') === 'HIGH SCHOOL LEVEL' ? 'selected' : '' ?>>HIGH SCHOOL LEVEL</option>
                            <option value="HIGH SCHOOL GRADUATE" <?= ($person['education_level'] ?? '') === 'HIGH SCHOOL GRADUATE' ? 'selected' : '' ?>>HIGH SCHOOL GRADUATE</option>
                            <option value="VOCATIONAL" <?= ($person['education_level'] ?? '') === 'VOCATIONAL' ? 'selected' : '' ?>>VOCATIONAL</option>
                            <option value="COLLEGE LEVEL" <?= ($person['education_level'] ?? '') === 'COLLEGE LEVEL' ? 'selected' : '' ?>>COLLEGE LEVEL</option>
                            <option value="COLLEGE GRADUATE" <?= ($person['education_level'] ?? '') === 'COLLEGE GRADUATE' ? 'selected' : '' ?>>COLLEGE GRADUATE</option>
                            <option value="POST GRADUATE" <?= ($person['education_level'] ?? '') === 'POST GRADUATE' ? 'selected' : '' ?>>POST GRADUATE</option>
                        </select>
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Occupation</label>
                            <input type="text" name="occupation" value="<?= htmlspecialchars($person['occupation'] ?? '') ?>"
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Monthly Income</label>
                        <select name="monthly_income" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT INCOME RANGE --</option>
                            <option value="0" <?= ($person['monthly_income'] ?? '') == '0' ? 'selected' : '' ?>>NO INCOME</option>
                            <option value="999" <?= ($person['monthly_income'] ?? '') == '999' ? 'selected' : '' ?>>₱999 & BELOW</option>
                            <option value="1500" <?= ($person['monthly_income'] ?? '') == '1500' ? 'selected' : '' ?>>₱1,000-1,999</option>
                            <option value="2500" <?= ($person['monthly_income'] ?? '') == '2500' ? 'selected' : '' ?>>₱2,000-2,999</option>
                            <option value="3500" <?= ($person['monthly_income'] ?? '') == '3500' ? 'selected' : '' ?>>₱3,000-3,999</option>
                            <option value="4500" <?= ($person['monthly_income'] ?? '') == '4500' ? 'selected' : '' ?>>₱4,000-4,999</option>
                            <option value="5500" <?= ($person['monthly_income'] ?? '') == '5500' ? 'selected' : '' ?>>₱5,000-5,999</option>
                            <option value="6500" <?= ($person['monthly_income'] ?? '') == '6500' ? 'selected' : '' ?>>₱6,000-6,999</option>
                            <option value="7500" <?= ($person['monthly_income'] ?? '') == '7500' ? 'selected' : '' ?>>₱7,000-7,999</option>
                            <option value="8500" <?= ($person['monthly_income'] ?? '') == '8500' ? 'selected' : '' ?>>₱8,000-8,999</option>
                            <option value="9500" <?= ($person['monthly_income'] ?? '') == '9500' ? 'selected' : '' ?>>₱9,000-9,999</option>
                            <option value="10000" <?= ($person['monthly_income'] ?? '') == '10000' ? 'selected' : '' ?>>₱10,000 & ABOVE</option>
                        </select>
                        </div>
                        <div>
                        <label class="block text-sm font-medium">Years of Residency *</label>
                        <input type="number" name="years_of_residency" id="years_of_residency" value="<?= htmlspecialchars($person['years_of_residency'] ?? '0') ?>"
                            class="mt-1 block w-full border rounded p-2" min="0" max="150">
                        <small class="text-red-600" id="residency_age_validation"></small>
                        </div>
                </div>
                <!-- Full width sections below -->
                <div class="md:col-span-3 space-y-8 mt-8">
                    <!-- Address Information -->
                    <div class="space-y-4 md:col-span-3">
                        <h3 class="font-semibold text-lg border-t border-gray-200 pt-4 mt-4">Address Information</h3>
                        <!-- Present Address -->
                        <div class="border-b pb-4 mb-4">
                            <h4 class="font-semibold text-md mb-4">Present Address</h4>
                            <p class="text-sm text-gray-600 mb-4">Where you currently reside</p>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                                    <label class="block text-sm font-medium">House No.</label>
                                    <input type="text" name="present_house_no" value="<?= htmlspecialchars($person['house_no'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                                    <label class="block text-sm font-medium">Street</label>
                                    <input type="text" name="present_street" value="<?= htmlspecialchars($person['street'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                                <div>
                                    <label class="block text-sm font-medium">Barangay</label>
                                    <input type="text" name="present_barangay" value="<?= htmlspecialchars($barangay_name) ?>" class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                                    <input type="hidden" name="present_barangay_id" value="<?= htmlspecialchars($_SESSION['barangay_id']) ?>">
                    </div>
                                <div>
                                    <label class="block text-sm font-medium">City/Municipality</label>
                                    <input type="text" name="present_municipality" value="SAN RAFAEL" class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                </div>
                        <div>
                                    <label class="block text-sm font-medium">Province</label>
                                    <input type="text" name="present_province" value="BULACAN" class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium">Region</label>
                                    <input type="text" name="present_region" value="III" class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
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
                                    <input type="text" name="permanent_house_no" value="" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                                <div>
                                    <label class="block text-sm font-medium">Street</label>
                                    <input type="text" name="permanent_street" value="" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                    </div>
                                <div>
                                    <label class="block text-sm font-medium">Barangay</label>
                                    <input type="text" name="permanent_barangay" value="" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                </div>
                                <div>
                                    <label class="block text-sm font-medium">City/Municipality</label>
                                    <input type="text" name="permanent_municipality" value="" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Province</label>
                                    <input type="text" name="permanent_province" value="" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium">Region</label>
                                    <input type="text" name="permanent_region" value="" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                    </div>
                <!-- Household Information -->
                    <div class="space-y-4 md:col-span-3 border-t border-gray-200 pt-4 mt-6">
                        <h3 class="font-semibold text-lg">Household Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                                <label class="block text-sm font-medium">Purok</label>
                                <select name="purok_id" id="purok_id_select" class="mt-1 block w-full border rounded p-2">
                                    <option value="">-- SELECT PUROK --</option>
                                    <?php foreach ($puroks as $purok): ?>
                                        <option value="<?= htmlspecialchars($purok['id']) ?>" <?= (isset($person['purok_id']) && $person['purok_id'] == $purok['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($purok['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium">Household</label>
                                <select name="household_id" id="household_id_select" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Household --</option>
                                <?php foreach ($households as $household): ?>
                                        <option value="<?= $household['household_id'] ?>" data-purok="<?= $household['purok_name'] ?>" <?= $household['household_id'] == ($person['household_id'] ?? '') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($household['household_number']) ?> (<?= htmlspecialchars($household['purok_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                                <label class="block text-sm font-medium">Relationship to Head</label>
                                <select name="relationship" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Relationship --</option>
                                <?php foreach ($relationships as $rel): ?>
                                        <option value="<?= $rel['id'] ?>" <?= $rel['id'] == $person['relationship_type_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($rel['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                            <div class="flex items-center mt-6">
                            <label class="inline-flex items-center">
                                    <input type="checkbox" name="is_household_head" value="1" <?= $person['is_household_head'] ? 'checked' : '' ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Is Household Head</span>
                            </label>
                        </div>
                    </div>
                </div>
                <!-- Government Program Participation -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Government Program Participation</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="nhts_pr_listahanan" value="1" class="form-checkbox" <?= (isset($person['nhts_pr_listahanan']) && $person['nhts_pr_listahanan'] == 1) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">NHTS-PR (Listahanan)</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="indigenous_people" value="1" class="form-checkbox" <?= (isset($person['indigenous_people']) && $person['indigenous_people'] == 1) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Indigenous People</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="pantawid_beneficiary" value="1" class="form-checkbox" <?= (isset($person['pantawid_beneficiary']) && $person['pantawid_beneficiary'] == 1) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Pantawid Beneficiary</span>
                            </label>
                        </div>
                    </div>
                </div>
                <!-- ID Numbers -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">ID Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium">OSCA ID Number</label>
                            <input type="text" name="osca_id" value="<?= htmlspecialchars($person['osca_id'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">GSIS ID Number</label>
                            <input type="text" name="gsis_id" value="<?= htmlspecialchars($person['gsis_id'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">SSS ID Number</label>
                            <input type="text" name="sss_id" value="<?= htmlspecialchars($person['sss_id'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">TIN ID Number</label>
                            <input type="text" name="tin_id" value="<?= htmlspecialchars($person['tin_id'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">PhilHealth ID Number</label>
                            <input type="text" name="philhealth_id" value="<?= htmlspecialchars($person['philhealth_id'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-sm font-medium">Other ID Type</label>
                                <input type="text" name="other_id_type" value="<?= htmlspecialchars($person['other_id_type'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div>
                                <label class="block text-sm font-medium">Other ID Number</label>
                                <input type="text" name="other_id_number" value="<?= htmlspecialchars($person['other_id_number'] ?? '') ?>" class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Source of Income & Assistance (Check all applicable) -->
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <h3 class="font-semibold text-lg mb-4">Source of Income & Assistance (Check all applicable)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_own_earnings" value="1" class="form-checkbox" <?= isset($income_data['own_earnings/salaries/wages']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Own Earnings, Salaries/Wages</span>
                            </label>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="inline-flex items-center whitespace-nowrap">
                                <input type="checkbox" name="income_own_pension" value="1" class="form-checkbox" <?= isset($income_data['own_pension']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Own Pension</span>
                            </label>
                            <input type="text" name="income_own_pension_amount" placeholder="Amount" value="<?= htmlspecialchars($income_data['own_pension']['amount'] ?? '') ?>"
                                class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_stocks_dividends" value="1" class="form-checkbox" <?= isset($income_data['stocks/dividends']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Stocks/Dividends</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_dependent_on_children" value="1" class="form-checkbox" <?= isset($income_data['dependent_on_children/relatives']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Dependent on Children/Relatives</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_spouse_salary" value="1" class="form-checkbox" <?= isset($income_data['spouse_salary']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Spouse's Salary</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_insurances" value="1" class="form-checkbox" <?= isset($income_data['insurances']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Insurances</span>
                            </label>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="inline-flex items-center whitespace-nowrap">
                                <input type="checkbox" name="income_spouse_pension" value="1" class="form-checkbox" <?= isset($income_data['spouse_pension']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Spouse's Pension</span>
                            </label>
                            <input type="text" name="income_spouse_pension_amount" placeholder="Amount" value="<?= htmlspecialchars($income_data['spouse_pension']['amount'] ?? '') ?>"
                                class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_rentals_sharecrops" value="1" class="form-checkbox" <?= isset($income_data['rentals/sharecrops']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Rentals/Sharecrops</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_savings" value="1" class="form-checkbox" <?= isset($income_data['savings']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Savings</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="income_livestock_orchards" value="1" class="form-checkbox" <?= isset($income_data['livestock/orchards']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Livestock/Orchards</span>
                            </label>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="inline-flex items-center whitespace-nowrap">
                                <input type="checkbox" name="income_others" value="1" class="form-checkbox" <?= isset($income_data['others']) ? 'checked' : '' ?>>
                                <span class="ml-2 text-sm font-medium">Others</span>
                            </label>
                            <input type="text" name="income_others_specify" placeholder="Specify" value="<?= htmlspecialchars($income_data['others']['details'] ?? '') ?>"
                                class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                        </div>
                    </div>
                </div>
                <!-- Family Composition -->
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <h3 class="font-semibold text-lg mb-4">II. Family Composition</h3>
                    <div class="mb-4">
                        <table class="min-w-full bg-white border border-gray-200">
                            <thead>
                                <tr>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Name</th>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Relationship</th>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Age</th>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Civil Status</th>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Occupation</th>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Income</th>
                                    <th class="border border-gray-200 px-4 py-2 text-sm">Action</th>
                                </tr>
                            </thead>
                            <tbody id="familyMembersTable">
                                <?php if (!empty($family_members)): ?>
                                    <?php foreach ($family_members as $i => $member): ?>
                                        <tr class="family-member-row">
                                            <td class="border border-gray-200 px-2 py-2">
                                                <input type="text" name="family_member_name[]" class="w-full border-0 p-0 text-sm uppercase"
                                                    value="<?= htmlspecialchars($member['name']) ?>" oninput="this.value = this.value.toUpperCase()">
                                            </td>
                                            <td class="border border-gray-200 px-2 py-2">
                                                <input type="text" name="family_member_relationship[]" class="w-full border-0 p-0 text-sm uppercase"
                                                    value="<?= htmlspecialchars($member['relationship']) ?>" oninput="this.value = this.value.toUpperCase()">
                                            </td>
                                            <td class="border border-gray-200 px-2 py-2">
                                                <input type="number" name="family_member_age[]" class="w-full border-0 p-0 text-sm"
                                                    value="<?= htmlspecialchars($member['age']) ?>" min="0" max="120">
                                            </td>
                                            <td class="border border-gray-200 px-2 py-2">
                                                <select name="family_member_civil_status[]" class="w-full border-0 p-0 text-sm bg-transparent">
                                                    <option value="">-- SELECT --</option>
                                                    <option value="SINGLE" <?= $member['civil_status'] == 'SINGLE' ? 'selected' : '' ?>>SINGLE</option>
                                                    <option value="MARRIED" <?= $member['civil_status'] == 'MARRIED' ? 'selected' : '' ?>>MARRIED</option>
                                                    <option value="WIDOW/WIDOWER" <?= $member['civil_status'] == 'WIDOW/WIDOWER' ? 'selected' : '' ?>>WIDOW/WIDOWER</option>
                                                    <option value="SEPARATED" <?= $member['civil_status'] == 'SEPARATED' ? 'selected' : '' ?>>SEPARATED</option>
                                                </select>
                                            </td>
                                            <td class="border border-gray-200 px-2 py-2">
                                                <input type="text" name="family_member_occupation[]" class="w-full border-0 p-0 text-sm uppercase"
                                                    value="<?= htmlspecialchars($member['occupation']) ?>" oninput="this.value = this.value.toUpperCase()">
                                            </td>
                                            <td class="border border-gray-200 px-2 py-2">
                                                <input type="text" name="family_member_income[]" class="w-full border-0 p-0 text-sm uppercase"
                                                    value="<?= htmlspecialchars($member['monthly_income']) ?>" oninput="this.value = this.value.toUpperCase()">
                                            </td>
                                            <td class="border border-gray-200 px-2 py-2 text-center">
                                                <button type="button" class="delete-family-member text-red-500 hover:text-red-700" title="Delete member">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr class="family-member-row">
                                        <td class="border border-gray-200 px-2 py-2">
                                            <input type="text" name="family_member_name[]" class="w-full border-0 p-0 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                        </td>
                                        <td class="border border-gray-200 px-2 py-2">
                                            <input type="text" name="family_member_relationship[]" class="w-full border-0 p-0 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
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
                                        <td class="border border-gray-200 px-2 py-2 text-center">
                                            <button type="button" class="delete-family-member text-red-500 hover:text-red-700" title="Delete member">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" id="addFamilyMember" class="mt-2 px-3 py-1 bg-blue-500 text-white rounded-md text-sm">
                            Add Family Member
                        </button>
                    </div>
                </div>
                    <!-- Assets & Properties (Check all applicable) -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold mb-4">Assets & Properties (Check all applicable)</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_house" value="1" class="form-checkbox" <?= in_array(1, $asset_type_ids) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">House</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_house_lot" value="1" class="form-checkbox" <?= in_array(2, $asset_type_ids) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">House & Lot</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_farmland" value="1" class="form-checkbox" <?= in_array(3, $asset_type_ids) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Farmland</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_commercial_building" value="1" class="form-checkbox" <?= in_array(4, $asset_type_ids) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Commercial Building</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_lot" value="1" class="form-checkbox" <?= in_array(5, $asset_type_ids) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Lot</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_fishponds_resorts" value="1" class="form-checkbox" <?= in_array(6, $asset_type_ids) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Fishponds/Resorts</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="asset_others" value="1" class="form-checkbox" <?= isset($asset_details['others']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="asset_others_specify" placeholder="Specify" value="<?= htmlspecialchars($asset_details['others'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </div>
                    <!-- Living/Residing With (Check all applicable) -->
                    <div class="bg-gray-50 p-4 rounded-lg mt-6">
                        <h2 class="text-lg font-semibold mb-4">Living/Residing With (Check all applicable)</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_alone" value="1" class="form-checkbox" <?= in_array('1', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Alone</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_common_law_spouse" value="1" class="form-checkbox" <?= in_array('9', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Common Law Spouse</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_in_laws" value="1" class="form-checkbox" <?= in_array('5', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">In-Laws</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_spouse" value="1" class="form-checkbox" <?= in_array('2', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Spouse</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_care_institutions" value="1" class="form-checkbox" <?= in_array('6', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Care Institutions</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_children" value="1" class="form-checkbox" <?= in_array('3', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Children</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_grandchildren" value="1" class="form-checkbox" <?= in_array('4', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Grandchildren</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_househelps" value="1" class="form-checkbox" <?= in_array('7', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Househelps</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="living_relatives" value="1" class="form-checkbox" <?= in_array('8', $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Relatives</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="living_others" value="1" class="form-checkbox" <?= in_array((string)$others_type_id, $living_arrangements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="living_others_specify" placeholder="Specify" value="<?= htmlspecialchars($living_others_details ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= in_array((string)$others_type_id, $living_arrangements ?? []) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                    <!-- Areas of Specialization/Skills (Check all applicable) -->
                    <div class="bg-gray-50 p-4 rounded-lg mt-6">
                        <h2 class="text-lg font-semibold mb-4">Areas of Specialization/Skills (Check all applicable)</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_medical" value="1" class="form-checkbox" <?= in_array('1', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Medical</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_teaching" value="1" class="form-checkbox" <?= in_array('2', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Teaching</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_legal_services" value="1" class="form-checkbox" <?= in_array('3', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Legal Services</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_dental" value="1" class="form-checkbox" <?= in_array('4', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Dental</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_counseling" value="1" class="form-checkbox" <?= in_array('5', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Counseling</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_evangelization" value="1" class="form-checkbox" <?= in_array('6', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Evangelization</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_farming" value="1" class="form-checkbox" <?= in_array('7', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Farming</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_fishing" value="1" class="form-checkbox" <?= in_array('8', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Fishing</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_cooking" value="1" class="form-checkbox" <?= in_array('9', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Cooking</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_vocational" value="1" class="form-checkbox" <?= in_array('10', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Vocational</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_arts" value="1" class="form-checkbox" <?= in_array('11', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Arts</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_engineering" value="1" class="form-checkbox" <?= in_array('12', $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Engineering</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="skill_others" value="1" class="form-checkbox" <?= in_array((string)$skill_others_type_id, $skills ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="skill_others_specify" placeholder="Specify" value="<?= htmlspecialchars($skill_others_details ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= in_array((string)$skill_others_type_id, $skills ?? []) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                    <!-- Involvement in Community Activities (Check all applicable) -->
                    <div class="bg-gray-50 p-4 rounded-lg mt-6">
                        <h2 class="text-lg font-semibold mb-4">Involvement in Community Activities (Check all applicable)</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_medical" value="1" class="form-checkbox" <?= in_array('1', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Medical</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_resource_volunteer" value="1" class="form-checkbox" <?= in_array('2', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Resource Volunteer</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_community_beautification" value="1" class="form-checkbox" <?= in_array('3', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Community Beautification</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_community_leader" value="1" class="form-checkbox" <?= in_array('4', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Community/Organizational Leader</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_dental" value="1" class="form-checkbox" <?= in_array('5', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Dental</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_friendly_visits" value="1" class="form-checkbox" <?= in_array('6', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Friendly Visits</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_neighborhood_support" value="1" class="form-checkbox" <?= in_array('7', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Neighborhood Support Services</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_religious" value="1" class="form-checkbox" <?= in_array('8', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Religious</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_counselling" value="1" class="form-checkbox" <?= in_array('9', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Counselling/Referral</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_sponsorship" value="1" class="form-checkbox" <?= in_array('10', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Sponsorship</span></label></div>
                            <div><label class="inline-flex items-center"><input type="checkbox" name="involvement_legal_services" value="1" class="form-checkbox" <?= in_array('11', $involvements ?? []) ? 'checked' : '' ?>><span class="ml-2 text-sm font-medium">Legal Services</span></label></div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="involvement_others" value="1" class="form-checkbox" <?= in_array((string)$involvement_others_type_id, $involvements ?? []) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="involvement_others_specify" placeholder="Specify" value="<?= htmlspecialchars($involvement_others_details ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= in_array((string)$involvement_others_type_id, $involvements ?? []) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                <!-- Problems/Needs Commonly Encountered (Check all applicable) -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Problems/Needs Commonly Encountered (Check all applicable)</h2>
                    <!-- Economic -->
                    <div class="mb-6">
                        <h4 class="font-semibold text-md mb-3">A. Economic</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_lack_income" value="1" class="form-checkbox" <?= !empty($economic_problems['unemployment']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Lack of Income/Resources</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_loss_income" value="1" class="form-checkbox" <?= !empty($economic_problems['loss_income']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Loss of Income/Resources</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_skills_training" value="1" class="form-checkbox" <?= !empty($economic_problems['skills_training']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Skills/Capability Training</span>
                                </label>
                                <input type="text" name="problem_skills_training_specify" placeholder="Specify" value="<?= htmlspecialchars($economic_problems['skills_training_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($economic_problems['skills_training']) ? '' : 'disabled' ?>>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_livelihood" value="1" class="form-checkbox" <?= !empty($economic_problems['livelihood']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Livelihood Opportunities</span>
                                </label>
                                <input type="text" name="problem_livelihood_specify" placeholder="Specify" value="<?= htmlspecialchars($economic_problems['livelihood_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($economic_problems['livelihood']) ? '' : 'disabled' ?>>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_economic_others" value="1" class="form-checkbox" <?= !empty($economic_problems['other_economic']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="problem_economic_others_specify" placeholder="Specify" value="<?= htmlspecialchars($economic_problems['other_economic_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($economic_problems['other_economic']) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                    <!-- Social/Emotional -->
                    <div class="mb-6">
                        <h4 class="font-semibold text-md mb-3">B. Social/Emotional</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_neglect_rejection" value="1" class="form-checkbox" <?= !empty($social_problems['neglect']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Feeling of Neglect & Rejection</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_helplessness" value="1" class="form-checkbox" <?= !empty($social_problems['isolation']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Feeling of Helplessness & Worthlessness</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_loneliness" value="1" class="form-checkbox" <?= !empty($social_problems['loneliness']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Feeling of Loneliness & Isolation</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_recreational" value="1" class="form-checkbox" <?= !empty($social_problems['recreational']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Inadequate Leisure/Recreational Activities</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_senior_friendly" value="1" class="form-checkbox" <?= !empty($social_problems['senior_friendly']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Senior Citizen Friendly Environment</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_social_others" value="1" class="form-checkbox" <?= !empty($social_problems['other_social']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="problem_social_others_specify" placeholder="Specify" value="<?= htmlspecialchars($social_problems['other_social_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($social_problems['other_social']) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                    <!-- Health -->
                    <div class="mb-6">
                        <h4 class="font-semibold text-md mb-3">C. Health</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_condition_illness" value="1" class="form-checkbox" <?= !empty($health_problems['condition_illness']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Condition/Illness</span>
                                </label>
                                <input type="text" name="problem_condition_illness_specify" placeholder="Specify" value="<?= htmlspecialchars($health_problems['condition_illness_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($health_problems['condition_illness']) ? '' : 'disabled' ?>>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <span class="text-sm font-medium">With Maintenance</span>
                                </label>
                                <label class="inline-flex items-center ml-2">
                                    <input type="radio" name="problem_with_maintenance" value="YES" class="form-radio" <?= isset($health_problems['has_maintenance']) && $health_problems['has_maintenance'] == 1 ? 'checked' : '' ?>>
                                    <span class="ml-1 text-sm">YES</span>
                                </label>
                                <input type="text" name="problem_with_maintenance_specify" placeholder="Specify" value="<?= htmlspecialchars($health_problems['maintenance_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= isset($health_problems['has_maintenance']) && $health_problems['has_maintenance'] == 1 ? '' : 'disabled' ?>>
                                <label class="inline-flex items-center ml-2">
                                    <input type="radio" name="problem_with_maintenance" value="NO" class="form-radio" <?= isset($health_problems['has_maintenance']) && $health_problems['has_maintenance'] == 0 ? 'checked' : '' ?>>
                                    <span class="ml-1 text-sm">NO</span>
                                </label>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-sm font-medium block mb-2">Concerns/Issues</label>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="problem_high_cost_medicine" value="1" class="form-checkbox" <?= !empty($health_problems['high_cost_medicine']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">High Cost Medicines</span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="problem_lack_medical_professionals" value="1" class="form-checkbox" <?= !empty($health_problems['lack_medical_professionals']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">Lack of Medical Professionals</span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="problem_lack_sanitation" value="1" class="form-checkbox" <?= !empty($health_problems['lack_sanitation']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">Lack/No Access to Sanitation</span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="problem_lack_health_insurance" value="1" class="form-checkbox" <?= !empty($health_problems['lack_health_insurance']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">Lack/No Health Insurance/s</span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="problem_inadequate_health_services" value="1" class="form-checkbox" <?= !empty($health_problems['inadequate_health_services']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">Inadequate Health Services</span>
                                        </label>
                                    </div>
                                    <div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="problem_lack_medical_facilities" value="1" class="form-checkbox" <?= !empty($health_problems['lack_medical_facilities']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">Lack of Hospitals/Medical Facilities</span>
                                        </label>
                                    </div>
                                    <div class="flex items-center gap-2 md:col-span-2">
                                        <label class="inline-flex items-center whitespace-nowrap">
                                            <input type="checkbox" name="problem_health_others" value="1" class="form-checkbox" <?= !empty($health_problems['other_health']) ? 'checked' : '' ?>>
                                            <span class="ml-2 text-sm font-medium">Others</span>
                                        </label>
                                        <input type="text" name="problem_health_others_specify" placeholder="Specify" value="<?= htmlspecialchars($health_problems['other_health_details'] ?? '') ?>"
                                            class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($health_problems['other_health']) ? '' : 'disabled' ?>>
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
                                    <input type="checkbox" name="problem_overcrowding" value="1" class="form-checkbox" <?= !empty($housing_problems['overcrowding']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Overcrowding in the Family Home</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_no_permanent_housing" value="1" class="form-checkbox" <?= !empty($housing_problems['no_permanent_housing']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">No Permanent Housing</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_independent_living" value="1" class="form-checkbox" <?= !empty($housing_problems['independent_living']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Longing for Independent Living/Quiet Atmosphere</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_lost_privacy" value="1" class="form-checkbox" <?= !empty($housing_problems['lost_privacy']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Lost Privacy</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_squatters" value="1" class="form-checkbox" <?= !empty($housing_problems['squatters']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Living in Squatter's Areas</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_high_rent" value="1" class="form-checkbox" <?= !empty($housing_problems['high_rent']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">High Cost Rent</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_housing_others" value="1" class="form-checkbox" <?= !empty($housing_problems['other_housing']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="problem_housing_others_specify" placeholder="Specify" value="<?= htmlspecialchars($housing_problems['other_housing_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($housing_problems['other_housing']) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                    <!-- Community Service -->
                    <div class="mb-6">
                        <h4 class="font-semibold text-md mb-3">E. Community Service</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pl-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_desire_participate" value="1" class="form-checkbox" <?= !empty($community_problems['desire_participate']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Desire to Participate</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="problem_skills_to_share" value="1" class="form-checkbox" <?= !empty($community_problems['skills_to_share']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Skills/Resources to Share</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="problem_community_others" value="1" class="form-checkbox" <?= !empty($community_problems['other_community']) ? 'checked' : '' ?>>
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="problem_community_others_specify" placeholder="Specify" value="<?= htmlspecialchars($community_problems['other_community_details'] ?? '') ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()" <?= !empty($community_problems['other_community']) ? '' : 'disabled' ?>>
                            </div>
                        </div>
                    </div>
                    <!-- Other Specific Needs -->
                    <div class="mb-6">
                        <h4 class="font-semibold text-md mb-3">F. Other Specific Needs</h4>
                        <div class="pl-4">
                            <textarea name="other_specific_needs" rows="3" class="w-full border rounded p-2 uppercase"
                                oninput="this.value = this.value.toUpperCase()"></textarea>
                        </div>
                    </div>
                </div>
                </div>
                <div class="md:col-span-3 flex justify-end space-x-4 mt-8">
                    <a href="census_records.php" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">Cancel</a>
                    <button type="submit" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-lg transition-colors">
                        Save Changes
                    </button>
                </div>
            </form>
        </section>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to setup checkbox-text field pairs
            function setupCheckboxTextFieldPair(checkboxName, textFieldName) {
                const checkbox = document.querySelector(`input[name='${checkboxName}']`);
                const textField = document.querySelector(`input[name='${textFieldName}']`);
                if (checkbox && textField) {
                    // Set initial state on page load
                    textField.disabled = !checkbox.checked;
                    // Add event listener
                    checkbox.addEventListener('change', function() {
                        textField.disabled = !this.checked;
                        if (!this.checked) {
                            textField.value = '';
                        }
                    });
                }
            }
            // Setup all checkbox-text field pairs
            setupCheckboxTextFieldPair('income_own_pension', 'income_own_pension_amount');
            setupCheckboxTextFieldPair('income_spouse_pension', 'income_spouse_pension_amount');
            setupCheckboxTextFieldPair('income_others', 'income_others_specify');
            setupCheckboxTextFieldPair('asset_others', 'asset_others_specify');
            setupCheckboxTextFieldPair('living_others', 'living_others_specify');
            setupCheckboxTextFieldPair('skill_others', 'skill_others_specify');
            setupCheckboxTextFieldPair('problem_skills_training', 'problem_skills_training_specify');
            setupCheckboxTextFieldPair('problem_livelihood', 'problem_livelihood_specify');
            setupCheckboxTextFieldPair('problem_economic_others', 'problem_economic_others_specify');
            setupCheckboxTextFieldPair('problem_social_others', 'problem_social_others_specify');
            setupCheckboxTextFieldPair('problem_condition_illness', 'problem_condition_illness_specify');
            setupCheckboxTextFieldPair('problem_with_maintenance', 'problem_with_maintenance_specify');
            setupCheckboxTextFieldPair('problem_health_others', 'problem_health_others_specify');
            setupCheckboxTextFieldPair('problem_housing_others', 'problem_housing_others_specify');
            setupCheckboxTextFieldPair('problem_community_others', 'problem_community_others_specify');

            // Same as present address functionality
            const sameAsPresentCheckbox = document.getElementById('sameAsPresent');
            if (sameAsPresentCheckbox) {
                sameAsPresentCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        document.querySelector('input[name="permanent_house_no"]').value = document.querySelector('input[name="present_house_no"]').value;
                        document.querySelector('input[name="permanent_street"]').value = document.querySelector('input[name="present_street"]').value;
                        document.querySelector('input[name="permanent_barangay"]').value = document.querySelector('input[name="present_barangay"]').value;
                        document.querySelector('input[name="permanent_municipality"]').value = document.querySelector('input[name="present_municipality"]').value;
                        document.querySelector('input[name="permanent_province"]').value = document.querySelector('input[name="present_province"]').value;
                        document.querySelector('input[name="permanent_region"]').value = document.querySelector('input[name="present_region"]').value;
                    } else {
                        document.querySelector('input[name="permanent_house_no"]').value = '';
                        document.querySelector('input[name="permanent_street"]').value = '';
                        document.querySelector('input[name="permanent_barangay"]').value = '';
                        document.querySelector('input[name="permanent_municipality"]').value = '';
                        document.querySelector('input[name="permanent_province"]').value = '';
                        document.querySelector('input[name="permanent_region"]').value = '';
                    }
                });
            }

            // Age calculation based on birth date
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
            const birthDateInput = document.getElementById('birth_date');
            if (birthDateInput) {
                birthDateInput.addEventListener('change', calculateAge);
                if (birthDateInput.value) calculateAge();
            }

            // Residency cannot exceed age
            const residencyInput = document.getElementById('years_of_residency');
            const ageInput = document.getElementById('age');
            const residencyValidationMsg = document.getElementById('residency_age_validation');

            function updateResidencyMaximum() {
                if (residencyInput && ageInput) {
                    const age = parseInt(ageInput.value) || 0;
                    residencyInput.max = age;
                    if (parseInt(residencyInput.value) > age) {
                        residencyInput.value = age;
                        residencyValidationMsg.textContent = `Years of residency cannot exceed age (${age})`;
                    } else {
                        residencyValidationMsg.textContent = '';
                    }
                }
            }
            if (residencyInput && ageInput) {
                residencyInput.addEventListener('input', updateResidencyMaximum);
                ageInput.addEventListener('input', updateResidencyMaximum);
                // Also update on page load
                updateResidencyMaximum();
            }

            // --- DYNAMIC HOUSEHOLD SELECT BASED ON PUROK ---
            const householdsByPurok = {};
            <?php foreach ($households as $household): ?>
                if (!householdsByPurok['<?= $household['purok_id'] ?>']) householdsByPurok['<?= $household['purok_id'] ?>'] = [];
                householdsByPurok['<?= $household['purok_id'] ?>'].push({
                    id: '<?= htmlspecialchars($household['household_id']) ?>',
                    number: '<?= htmlspecialchars($household['household_number']) ?>',
                    purok_name: '<?= htmlspecialchars($household['purok_name']) ?>'
                });
            <?php endforeach; ?>
            const purokSelect = document.getElementById('purok_id_select');
            const householdSelect = document.getElementById('household_id_select');
            const originalOption = householdSelect.querySelector('option[value=""]');

            function updateHouseholdOptions() {
                const purokId = purokSelect.value;
                // Remove all except the first option
                householdSelect.innerHTML = '';
                householdSelect.appendChild(originalOption.cloneNode(true));
                if (householdsByPurok[purokId]) {
                    householdsByPurok[purokId].forEach(hh => {
                        const opt = document.createElement('option');
                        opt.value = hh.id;
                        opt.textContent = hh.number + (hh.purok_name ? ' - ' + hh.purok_name : '');
                        householdSelect.appendChild(opt);
                    });
                }
            }
            if (purokSelect && householdSelect) {
                purokSelect.addEventListener('change', updateHouseholdOptions);
                // Optionally, update on page load if a purok is pre-selected
                if (purokSelect.value) updateHouseholdOptions();
            }

            // Handle adding family members
            const addFamilyMemberBtn = document.getElementById('addFamilyMember');
            const familyMembersTable = document.getElementById('familyMembersTable');

            // Handle deleting family members
            function setupFamilyMemberDeleteButtons() {
                document.querySelectorAll('.delete-family-member').forEach(button => {
                    button.addEventListener('click', function() {
                        const row = this.closest('tr');
                        // Check if this is the only row in the table
                        if (familyMembersTable.querySelectorAll('tr').length > 1) {
                            row.remove();
                        } else {
                            // If it's the last row, just clear the inputs instead of removing
                            row.querySelectorAll('input').forEach(input => {
                                input.value = '';
                            });
                            row.querySelectorAll('select').forEach(select => {
                                select.selectedIndex = 0;
                            });
                            // Show SweetAlert2 info alert
                            if (window.Swal) {
                                Swal.fire({
                                    icon: 'info',
                                    title: 'Cannot Delete',
                                    text: 'At least one family member row must remain. Values have been cleared instead.',
                                    confirmButtonColor: '#3085d6'
                                });
                            } else {
                                alert('At least one family member row must remain. Values have been cleared instead.');
                            }
                        }
                    });
                });
            }
            // Setup delete buttons on page load
            setupFamilyMemberDeleteButtons();
            // Add family member with delete button functionality
            if (addFamilyMemberBtn && familyMembersTable) {
                addFamilyMemberBtn.addEventListener('click', function() {
                    // Clone the first row as a template
                    const firstRow = familyMembersTable.querySelector('.family-member-row');
                    const newRow = firstRow.cloneNode(true);
                    // Clear input values in the new row
                    newRow.querySelectorAll('input').forEach(input => {
                        input.value = '';
                    });
                    // Reset select elements
                    newRow.querySelectorAll('select').forEach(select => {
                        select.selectedIndex = 0;
                    });
                    // Add the new row to the table
                    familyMembersTable.appendChild(newRow);
                    // Setup delete button for the new row
                    setupFamilyMemberDeleteButtons();
                });
            }
        });
    </script>
</body>

</html> 