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

try {
    // Fetch person's basic information
    $stmt = $pdo->prepare("
        SELECT p.*, 
               a.house_no, a.street, a.phase as sitio,
               hm.household_id, hm.relationship_type_id,
               rt.name as relationship_name,
               hm.is_household_head
        FROM persons p
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        WHERE p.id = ?
    ");
    $stmt->execute([$person_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        header("Location: census_records.php");
        exit;
    }

    // Fetch households for selection
    $stmt = $pdo->prepare("
        SELECT h.id AS household_id, h.household_number, p.name as purok_name
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

    // Fetch living arrangements
    $stmt = $pdo->prepare("
        SELECT arrangement_type_id 
        FROM person_living_arrangements 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $living_arrangements = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch skills
    $stmt = $pdo->prepare("
        SELECT skill_type_id 
        FROM person_skills 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $skills = $stmt->fetchAll(PDO::FETCH_COLUMN);

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

} catch (Exception $e) {
    $_SESSION['error'] = "Error fetching data: " . $e->getMessage();
    header("Location: census_records.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        // Update basic person information
        $stmt = $pdo->prepare("
            UPDATE persons SET 
                first_name = ?,
                middle_name = ?,
                last_name = ?,
                suffix = ?,
                birth_date = ?,
                birth_place = ?,
                gender = ?,
                civil_status = ?,
                citizenship = ?,
                religion = ?,
                education_level = ?,
                occupation = ?,
                monthly_income = ?,
                years_of_residency = ?,
                resident_type = ?,
                contact_number = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $_POST['first_name'],
            $_POST['middle_name'],
            $_POST['last_name'],
            $_POST['suffix'],
            $_POST['birth_date'],
            $_POST['birth_place'],
            $_POST['gender'],
            $_POST['civil_status'],
            $_POST['citizenship'],
            $_POST['religion'],
            $_POST['education_level'],
            $_POST['occupation'],
            $_POST['monthly_income'],
            $_POST['years_of_residency'],
            $_POST['resident_type'],
            $_POST['contact_number'],
            $person_id
        ]);

        // Update address
        $stmt = $pdo->prepare("
            UPDATE addresses SET 
                house_no = ?,
                street = ?,
                phase = ?,
                municipality = 'SAN RAFAEL',
                province = 'BULACAN',
                region = 'III'
            WHERE person_id = ? AND is_primary = 1
        ");
        $stmt->execute([
            $_POST['address_number'],
            $_POST['address_street'],
            $_POST['address_sitio'],
            $person_id
        ]);

        // Update household membership
        if (!empty($_POST['household_id'])) {
            $stmt = $pdo->prepare("
                UPDATE household_members SET 
                    household_id = ?,
                    relationship_type_id = ?,
                    is_household_head = ?
                WHERE person_id = ?
            ");
            $stmt->execute([
                $_POST['household_id'],
                $_POST['relationship'],
                isset($_POST['is_household_head']) ? 1 : 0,
                $person_id
            ]);
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

        $stmt = $pdo->prepare("
            INSERT INTO person_living_arrangements (person_id, arrangement_type_id) 
            VALUES (?, (SELECT id FROM living_arrangement_types WHERE name = ?))
        ");

        foreach ($living_fields as $field => $type) {
            if (isset($_POST[$field]) && $_POST[$field] == 1) {
                $stmt->execute([$person_id, $type]);
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

        $stmt = $pdo->prepare("
            INSERT INTO person_skills (person_id, skill_type_id) 
            VALUES (?, (SELECT id FROM skill_types WHERE name = ?))
        ");

        foreach ($skill_fields as $field => $type) {
            if (isset($_POST[$field]) && $_POST[$field] == 1) {
                $stmt->execute([$person_id, $type]);
            }
        }

        // Update medical conditions
        $stmt = $pdo->prepare("DELETE FROM person_health_info WHERE person_id = ?");
        $stmt->execute([$person_id]);

        $stmt = $pdo->prepare("
            INSERT INTO person_health_info (person_id, health_condition, has_maintenance, maintenance_details, high_cost_medicines, lack_medical_professionals, lack_sanitation_access, lack_health_insurance, lack_medical_facilities, other_health_concerns)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $person_id,
            $_POST['health_condition'],
            isset($_POST['has_maintenance']) ? 1 : 0,
            $_POST['maintenance_details'],
            isset($_POST['high_cost_medicines']) ? 1 : 0,
            isset($_POST['lack_medical_professionals']) ? 1 : 0,
            isset($_POST['lack_sanitation_access']) ? 1 : 0,
            isset($_POST['lack_health_insurance']) ? 1 : 0,
            isset($_POST['lack_medical_facilities']) ? 1 : 0,
            $_POST['other_health_concerns']
        ]);

        // Update problem categories
        // Delete existing problem records
        $stmt = $pdo->prepare("DELETE FROM person_economic_problems WHERE person_id = ?");
        $stmt->execute([$person_id]);
        $stmt = $pdo->prepare("DELETE FROM person_social_problems WHERE person_id = ?");
        $stmt->execute([$person_id]);
        $stmt = $pdo->prepare("DELETE FROM person_health_problems WHERE person_id = ?");
        $stmt->execute([$person_id]);
        $stmt = $pdo->prepare("DELETE FROM person_housing_problems WHERE person_id = ?");
        $stmt->execute([$person_id]);
        $stmt = $pdo->prepare("DELETE FROM person_community_problems WHERE person_id = ?");
        $stmt->execute([$person_id]);

        // Insert economic problems
        $stmt = $pdo->prepare("
            INSERT INTO person_economic_problems (
                person_id, loss_income, unemployment, high_cost_living,
                skills_training, skills_training_details, livelihood,
                livelihood_details, other_economic, other_economic_details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $person_id,
            isset($_POST['problem_employment']) ? 1 : 0,
            isset($_POST['problem_employment']) ? 1 : 0,
            isset($_POST['problem_employment']) ? 1 : 0,
            isset($_POST['problem_employment']) ? 1 : 0,
            $_POST['problem_employment_details'] ?? null,
            isset($_POST['problem_employment']) ? 1 : 0,
            $_POST['problem_employment_details'] ?? null,
            isset($_POST['problem_employment']) ? 1 : 0,
            $_POST['problem_employment_details'] ?? null
        ]);

        // Insert social problems
        $stmt = $pdo->prepare("
            INSERT INTO person_social_problems (
                person_id, loneliness, isolation, neglect,
                recreational, senior_friendly, other_social,
                other_social_details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $person_id,
            isset($_POST['problem_social']) ? 1 : 0,
            isset($_POST['problem_social']) ? 1 : 0,
            isset($_POST['problem_social']) ? 1 : 0,
            isset($_POST['problem_social']) ? 1 : 0,
            isset($_POST['problem_social']) ? 1 : 0,
            isset($_POST['problem_social']) ? 1 : 0,
            $_POST['problem_social_details'] ?? null
        ]);

        // Insert health problems
        $stmt = $pdo->prepare("
            INSERT INTO person_health_problems (
                person_id, condition_illness, condition_illness_details,
                high_cost_medicine, lack_medical_professionals, lack_sanitation,
                lack_health_insurance, inadequate_health_services,
                other_health, other_health_details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $person_id,
            isset($_POST['problem_health']) ? 1 : 0,
            $_POST['problem_health_details'] ?? null,
            isset($_POST['problem_health']) ? 1 : 0,
            isset($_POST['problem_health']) ? 1 : 0,
            isset($_POST['problem_health']) ? 1 : 0,
            isset($_POST['problem_health']) ? 1 : 0,
            isset($_POST['problem_health']) ? 1 : 0,
            isset($_POST['problem_health']) ? 1 : 0,
            $_POST['problem_health_details'] ?? null
        ]);

        // Insert housing problems
        $stmt = $pdo->prepare("
            INSERT INTO person_housing_problems (
                person_id, overcrowding, no_permanent_housing,
                independent_living, lost_privacy, squatters,
                other_housing, other_housing_details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $person_id,
            isset($_POST['problem_housing']) ? 1 : 0,
            isset($_POST['problem_housing']) ? 1 : 0,
            isset($_POST['problem_housing']) ? 1 : 0,
            isset($_POST['problem_housing']) ? 1 : 0,
            isset($_POST['problem_housing']) ? 1 : 0,
            isset($_POST['problem_housing']) ? 1 : 0,
            $_POST['problem_housing_details'] ?? null
        ]);

        // Insert community problems
        $stmt = $pdo->prepare("
            INSERT INTO person_community_problems (
                person_id, desire_participate, skills_to_share,
                other_community, other_community_details
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $person_id,
            isset($_POST['problem_other']) ? 1 : 0,
            isset($_POST['problem_other']) ? 1 : 0,
            isset($_POST['problem_other']) ? 1 : 0,
            $_POST['problem_other_details'] ?? null
        ]);

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
        <section id="edit-resident" class="bg-white rounded-lg shadow-sm p-6 mb-8 max-w-5xl mx-auto">
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
            <form method="POST" class="space-y-8" autocomplete="off">
                <!-- Personal Information -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Personal Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($person['first_name']) ?>" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($person['middle_name'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($person['last_name']) ?>" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Suffix</label>
                            <input type="text" name="suffix" value="<?= htmlspecialchars($person['suffix'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Birth Date</label>
                            <input type="date" name="birth_date" value="<?= htmlspecialchars($person['birth_date']) ?>" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Place of Birth</label>
                            <input type="text" name="birth_place" value="<?= htmlspecialchars($person['birth_place'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Gender</label>
                            <select name="gender" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="Male" <?= $person['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $person['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Civil Status</label>
                            <select name="civil_status" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="Single" <?= $person['civil_status'] === 'Single' ? 'selected' : '' ?>>Single</option>
                                <option value="Married" <?= $person['civil_status'] === 'Married' ? 'selected' : '' ?>>Married</option>
                                <option value="Widowed" <?= $person['civil_status'] === 'Widowed' ? 'selected' : '' ?>>Widowed</option>
                                <option value="Separated" <?= $person['civil_status'] === 'Separated' ? 'selected' : '' ?>>Separated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Citizenship</label>
                            <input type="text" name="citizenship" value="<?= htmlspecialchars($person['citizenship'] ?? 'Filipino') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Religion</label>
                            <input type="text" name="religion" value="<?= htmlspecialchars($person['religion'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Education Level</label>
                            <input type="text" name="education_level" value="<?= htmlspecialchars($person['education_level'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Occupation</label>
                            <input type="text" name="occupation" value="<?= htmlspecialchars($person['occupation'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Monthly Income</label>
                            <input type="number" name="monthly_income" value="<?= htmlspecialchars($person['monthly_income'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Years of Residency</label>
                            <input type="number" name="years_of_residency" value="<?= htmlspecialchars($person['years_of_residency'] ?? '0') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Resident Type</label>
                            <select name="resident_type"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="regular" <?= $person['resident_type'] === 'regular' ? 'selected' : '' ?>>Regular</option>
                                <option value="pwd" <?= $person['resident_type'] === 'pwd' ? 'selected' : '' ?>>PWD</option>
                                <option value="senior" <?= $person['resident_type'] === 'senior' ? 'selected' : '' ?>>Senior Citizen</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Contact Number</label>
                            <input type="text" name="contact_number" value="<?= htmlspecialchars($person['contact_number'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <!-- Address Information -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Address Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">House Number</label>
                            <input type="text" name="address_number" value="<?= htmlspecialchars($person['house_no'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Street</label>
                            <input type="text" name="address_street" value="<?= htmlspecialchars($person['street'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Sitio</label>
                            <input type="text" name="address_sitio" value="<?= htmlspecialchars($person['sitio'] ?? '') ?>"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                </div>

                <!-- Household Information -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Household Information</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Household</label>
                            <select name="household_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">-- Select Household --</option>
                                <?php foreach ($households as $household): ?>
                                    <option value="<?= $household['household_id'] ?>" 
                                        <?= $household['household_id'] == $person['household_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($household['household_number']) ?> 
                                        (<?= htmlspecialchars($household['purok_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Relationship to Head</label>
                            <select name="relationship"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">-- Select Relationship --</option>
                                <?php foreach ($relationships as $rel): ?>
                                    <option value="<?= $rel['id'] ?>" 
                                        <?= $rel['id'] == $person['relationship_type_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($rel['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_household_head" value="1"
                                    <?= $person['is_household_head'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Is Household Head</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Living Arrangements -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Living Arrangements</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_alone" value="1"
                                    <?= in_array('alone', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living Alone</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_spouse" value="1"
                                    <?= in_array('spouse', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with Spouse</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_children" value="1"
                                    <?= in_array('children', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with Children</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_grandchildren" value="1"
                                    <?= in_array('grandchildren', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with Grandchildren</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_in_laws" value="1"
                                    <?= in_array('in_laws', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with In-laws</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_relatives" value="1"
                                    <?= in_array('relatives', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with Relatives</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_househelps" value="1"
                                    <?= in_array('househelps', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with Househelps</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_care_institutions" value="1"
                                    <?= in_array('care_institutions', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living in Care Institution</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="living_common_law_spouse" value="1"
                                    <?= in_array('common_law_spouse', $living_arrangements) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Living with Common-law Spouse</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Skills -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Skills</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_medical" value="1"
                                    <?= in_array('medical', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Medical</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_teaching" value="1"
                                    <?= in_array('teaching', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Teaching</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_legal_services" value="1"
                                    <?= in_array('legal_services', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Legal Services</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_dental" value="1"
                                    <?= in_array('dental', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Dental</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_counseling" value="1"
                                    <?= in_array('counseling', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Counseling</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_evangelization" value="1"
                                    <?= in_array('evangelization', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Evangelization</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_farming" value="1"
                                    <?= in_array('farming', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Farming</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_fishing" value="1"
                                    <?= in_array('fishing', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Fishing</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_cooking" value="1"
                                    <?= in_array('cooking', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Cooking</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_vocational" value="1"
                                    <?= in_array('vocational', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Vocational</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_arts" value="1"
                                    <?= in_array('arts', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Arts</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_engineering" value="1"
                                    <?= in_array('engineering', $skills) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Engineering</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Medical Conditions -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Medical Conditions</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_hypertension" value="1"
                                    <?= isset($health_info['has_hypertension']) && $health_info['has_hypertension'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Hypertension</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_diabetes" value="1"
                                    <?= isset($health_info['has_diabetes']) && $health_info['has_diabetes'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Diabetes</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_asthma" value="1"
                                    <?= isset($health_info['has_asthma']) && $health_info['has_asthma'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Asthma</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_heart_disease" value="1"
                                    <?= isset($health_info['has_heart_disease']) && $health_info['has_heart_disease'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Heart Disease</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_arthritis" value="1"
                                    <?= isset($health_info['has_arthritis']) && $health_info['has_arthritis'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Arthritis</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_cancer" value="1"
                                    <?= isset($health_info['has_cancer']) && $health_info['has_cancer'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Cancer</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_tuberculosis" value="1"
                                    <?= isset($health_info['has_tuberculosis']) && $health_info['has_tuberculosis'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Tuberculosis</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_stroke" value="1"
                                    <?= isset($health_info['has_stroke']) && $health_info['has_stroke'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Stroke</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_kidney_disease" value="1"
                                    <?= isset($health_info['has_kidney_disease']) && $health_info['has_kidney_disease'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Kidney Disease</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="has_liver_disease" value="1"
                                    <?= isset($health_info['has_liver_disease']) && $health_info['has_liver_disease'] ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Liver Disease</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Problem Categories -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h2 class="text-lg font-semibold mb-4">Problem Categories</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="problem_health" value="1"
                                    <?= isset($economic_problems['loss_income']) || isset($social_problems['loneliness']) || isset($health_problems['condition_illness']) || isset($housing_problems['overcrowding']) || isset($community_problems['desire_participate']) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Health Problems</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="problem_education" value="1"
                                    <?= isset($economic_problems['skills_training']) || isset($social_problems['isolation']) || isset($health_problems['condition_illness']) || isset($housing_problems['no_permanent_housing']) || isset($community_problems['skills_to_share']) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Education Problems</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="problem_employment" value="1"
                                    <?= isset($economic_problems['loss_income']) || isset($social_problems['isolation']) || isset($health_problems['condition_illness']) || isset($housing_problems['no_permanent_housing']) || isset($community_problems['skills_to_share']) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Employment Problems</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="problem_housing" value="1"
                                    <?= isset($housing_problems['overcrowding']) || isset($housing_problems['no_permanent_housing']) || isset($housing_problems['independent_living']) || isset($housing_problems['lost_privacy']) || isset($housing_problems['squatters']) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Housing Problems</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="problem_social" value="1"
                                    <?= isset($social_problems['loneliness']) || isset($social_problems['isolation']) || isset($social_problems['neglect']) || isset($social_problems['recreational']) || isset($social_problems['senior_friendly']) || isset($social_problems['other_social']) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Social Problems</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="problem_other" value="1"
                                    <?= isset($community_problems['desire_participate']) || isset($community_problems['skills_to_share']) || isset($community_problems['other_community']) ? 'checked' : '' ?>
                                    class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Other Problems</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-4">
                    <a href="census_records.php" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">Cancel</a>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save Changes</button>
                </div>
            </form>
        </section>
    </div>
</body>
</html> 