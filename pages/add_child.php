<?php
require "../config/dbconn.php";
require_once "../components/header.php";

// Fetch households for selection
$stmt = $pdo->prepare("
    SELECT h.id AS household_id, h.purok_id, p.name as purok_name, h.household_number
    FROM households h
    LEFT JOIN purok p ON h.purok_id = p.id
    WHERE h.barangay_id = ? 
    ORDER BY h.purok_id, h.household_number
");
$stmt->execute([$_SESSION['barangay_id']]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch puroks for selection
$stmt = $pdo->prepare("SELECT id, name FROM purok WHERE barangay_id = ? ORDER BY name");
$stmt->execute([$_SESSION['barangay_id']]);
$puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$add_error = '';
$add_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name   = trim($_POST['first_name'] ?? '');
    $middle_name  = trim($_POST['middle_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $suffix       = trim($_POST['suffix'] ?? '');
    $birth_date   = $_POST['birth_date'] ?? '';
    $gender       = $_POST['gender'] ?? '';
    $civil_status = $_POST['civil_status'] ?? 'Single';
    $citizenship  = trim($_POST['citizenship'] ?? 'Filipino');
    $household_id = $_POST['household_id'] ?? '';
    $relationship = $_POST['relationship'] ?? '';
    $is_household_head = isset($_POST['is_household_head']) ? 1 : 0;
    // Household Information
    $region = trim($_POST['region'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');

    // Personal Information
    $place_of_birth = trim($_POST['place_of_birth'] ?? '');
    $address_number = trim($_POST['address_number'] ?? '');
    $address_street = trim($_POST['address_street'] ?? '');
    $address_sitio = trim($_POST['address_sitio'] ?? '');
    $address_city = trim($_POST['address_city'] ?? '');
    $address_province = trim($_POST['address_province'] ?? '');

    // Educational Information
    $attending_school = $_POST['attending_school'] ?? '0';
    $school_type = $_POST['school_type'] ?? 'Not Attending';
    $school_name = trim($_POST['school_name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');

    // Health Information
    $is_malnourished = isset($_POST['is_malnourished']) ? ($_POST['is_malnourished'] === '1') : false;
    $is_immunized = isset($_POST['is_immunized']) ? ($_POST['is_immunized'] === '1') : false;
    $garantisadong_pambata = isset($_POST['garantisadong_pambata']) ? ($_POST['garantisadong_pambata'] === '1') : false;
    $operation_timbang = isset($_POST['operation_timbang']) ? ($_POST['operation_timbang'] === '1') : false;
    $supplementary_feeding = isset($_POST['supplementary_feeding']) ? ($_POST['supplementary_feeding'] === '1') : false;
    $under_six_years = isset($_POST['under_six_years']) ? ($_POST['under_six_years'] === '1') : false;
    $grade_school = isset($_POST['grade_school']) ? ($_POST['grade_school'] === '1') : false;

    // Diseases
    $has_malaria = isset($_POST['has_malaria']) ? ($_POST['has_malaria'] === '1') : false;
    $has_dengue = isset($_POST['has_dengue']) ? ($_POST['has_dengue'] === '1') : false;
    $has_pneumonia = isset($_POST['has_pneumonia']) ? ($_POST['has_pneumonia'] === '1') : false;
    $has_tuberculosis = isset($_POST['has_tuberculosis']) ? ($_POST['has_tuberculosis'] === '1') : false;
    $has_diarrhea = isset($_POST['has_diarrhea']) ? ($_POST['has_diarrhea'] === '1') : false;

    // Child Welfare Status
    $caring_institution = isset($_POST['caring_institution']) ? ($_POST['caring_institution'] === '1') : false;
    $foster_care = isset($_POST['foster_care']) ? ($_POST['foster_care'] === '1') : false;
    $directly_entrusted = isset($_POST['directly_entrusted']) ? ($_POST['directly_entrusted'] === '1') : false;
    $legally_adopted = isset($_POST['legally_adopted']) ? ($_POST['legally_adopted'] === '1') : false;
    // Disability
    $visually_impaired = isset($_POST['visually_impaired']) ? ($_POST['visually_impaired'] === '1') : false;
    $hearing_impaired = isset($_POST['hearing_impaired']) ? ($_POST['hearing_impaired'] === '1') : false;
    $speech_impaired = isset($_POST['speech_impaired']) ? ($_POST['speech_impaired'] === '1') : false;
    $orthopedic_disability = isset($_POST['orthopedic_disability']) ? ($_POST['orthopedic_disability'] === '1') : false;
    $intellectual_disability = isset($_POST['intellectual_disability']) ? ($_POST['intellectual_disability'] === '1') : false;
    $psychosocial_disability = isset($_POST['psychosocial_disability']) ? ($_POST['psychosocial_disability'] === '1') : false;

    // Certification Fields
    $form_accomplisher = trim($_POST['form_accomplisher'] ?? '');
    $date_accomplished = $_POST['date_accomplished'] ?? date('Y-m-d');
    $attested_by = trim($_POST['attested_by'] ?? '');

    // Validate required fields
    $required = [
        'First Name' => $first_name,
        'Last Name' => $last_name,
        'Birth Date' => $birth_date,
        'Gender' => $gender,
        // Household ID is optional
    ];
    foreach ($required as $label => $val) {
        if (!$val) $add_error .= "$label is required.<br>";
    }

    // Validate age (0-17 years)
    if ($birth_date) {
        $birth_date_obj = new DateTime($birth_date);
        $today = new DateTime();
        $age = $today->diff($birth_date_obj)->y;
        
        if ($age < 0 || $age > 17) {
            $add_error .= "Only children aged 0-17 years old are allowed.<br>";
        }
    }

    // Check if household exists only if a household ID was provided
    if (!empty($household_id)) {
        $household_exists = false;
        foreach ($households as $h) {
            if ($h['household_id'] == $household_id) $household_exists = true;
        }
        if (!$household_exists) $add_error .= "Selected household does not exist.<br>";
    }

    if (!$add_error) {
        try {
            $pdo->beginTransaction();

            // Insert into persons
            $stmt = $pdo->prepare("INSERT INTO persons 
                (first_name, middle_name, last_name, suffix, birth_date, birth_place, gender, civil_status, citizenship)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $first_name,
                $middle_name,
                $last_name,
                $suffix,
                $birth_date,
                $place_of_birth,
                $gender,
                $civil_status,
                $citizenship
            ]);
            $person_id = $pdo->lastInsertId();

            // Insert address information
            $stmt = $pdo->prepare("INSERT INTO addresses 
                (person_id, barangay_id, house_no, street, phase, municipality, province, region, is_primary)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $person_id,
                $_SESSION['barangay_id'],
                $address_number,
                $address_street,
                $address_sitio,
                'SAN RAFAEL',
                'BULACAN',
                'III',
                true
            ]);

            // Insert into household_members (if household_id is provided)
            if (!empty($household_id)) {
                // First, get the relationship_type_id based on the relationship name
                $stmt = $pdo->prepare("SELECT id FROM relationship_types WHERE name = ?");
                $stmt->execute([$relationship]);
                $relationship_type = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($relationship_type) {
                    $stmt = $pdo->prepare("INSERT INTO household_members 
                        (household_id, person_id, relationship_type_id, is_household_head)
                        VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $household_id,
                        $person_id,
                        $relationship_type['id'],
                        $is_household_head
                    ]);
                }
            }

            // Insert into child_information
            $stmt = $pdo->prepare("INSERT INTO child_information 
                (person_id, attending_school, is_malnourished, school_name, grade_level, school_type, 
                immunization_complete, is_pantawid_beneficiary, has_timbang_operation, has_feeding_program,
                has_supplementary_feeding, in_caring_institution, is_under_foster_care, is_directly_entrusted,
                is_legally_adopted, occupation, garantisadong_pambata, under_six_years, grade_school)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $person_id,
                $attending_school,
                $is_malnourished ? 1 : 0,
                $school_name,
                $grade_level,
                $school_type,
                $is_immunized ? 1 : 0,
                0, // is_pantawid_beneficiary - not in form
                $operation_timbang ? 1 : 0,
                0, // has_feeding_program - not in form
                $supplementary_feeding ? 1 : 0,
                $caring_institution ? 1 : 0,
                $foster_care ? 1 : 0,
                $directly_entrusted ? 1 : 0,
                $legally_adopted ? 1 : 0,
                $occupation,
                $garantisadong_pambata ? 1 : 0,
                $under_six_years ? 1 : 0,
                $grade_school ? 1 : 0
            ]);

            // Insert health conditions
            $health_conditions = [
                'Malaria' => $has_malaria,
                'Dengue' => $has_dengue,
                'Pneumonia' => $has_pneumonia,
                'Tuberculosis' => $has_tuberculosis,
                'Diarrhea' => $has_diarrhea
            ];

            $stmt = $pdo->prepare("INSERT INTO child_health_conditions (person_id, condition_type) VALUES (?, ?)");
            foreach ($health_conditions as $condition => $has_condition) {
                if ($has_condition && $has_condition !== '0' && $has_condition !== 0) {
                    $stmt->execute([$person_id, $condition]);
                }
            }

            // Insert disabilities
            $disabilities = [
                'Blind/Visually Impaired' => $visually_impaired,
                'Hearing Impairment' => $hearing_impaired,
                'Speech/Communication' => $speech_impaired,
                'Orthopedic/Physical' => $orthopedic_disability,
                'Intellectual/Learning' => $intellectual_disability,
                'Psychosocial' => $psychosocial_disability
            ];

            $stmt = $pdo->prepare("INSERT INTO child_disabilities (person_id, disability_type) VALUES (?, ?)");
            foreach ($disabilities as $disability => $has_disability) {
                if ($has_disability && $has_disability !== '0' && $has_disability !== 0) {
                    $stmt->execute([$person_id, $disability]);
                }
            }

            $pdo->commit();
            $add_success = "Child added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $add_error = "Error adding child: " . htmlspecialchars($e->getMessage());
            // Log the error for debugging
            error_log("Error in add_child.php: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Add Child</title>
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
            <a href="manage_puroks.php" class="w-full sm:w-auto text-white bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Manage Puroks</a>
        </div>
        <section id="add-child" class="bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">CHILDREN 0-17 YEARS OLD</h2>
            <form method="POST" class="space-y-8" autocomplete="off">
                <!-- Household Information Section -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 p-4 border border-gray-300 rounded-lg">
                    <div class="md:col-span-1">
                        <label class="block text-md font-semibold">REGION</label>
                        <input type="text" name="region" value="III" class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-md font-semibold">PUROK</label>
                        <select name="purok_id" id="purok_select" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT PUROK --</option>
                            <?php foreach ($puroks as $purok): ?>
                                <option value="<?= htmlspecialchars($purok['id']) ?>">
                                    <?= htmlspecialchars($purok['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-md font-semibold text-right">HOUSEHOLD NUMBER (HN) (Optional)</label>
                        <select name="household_id" id="household_select" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT HOUSEHOLD --</option>
                            <?php foreach ($households as $household): ?>
                                <option value="<?= htmlspecialchars($household['household_id']) ?>" 
                                        data-purok="<?= htmlspecialchars($household['purok_id']) ?>">
                                    <?= htmlspecialchars($household['household_number'] ?? $household['household_id']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-md font-semibold">BARANGAY</label>
                        <input type="text" name="barangay" value="TAMBUBONG" class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                </div>

                <!-- Personal Information Section -->
                <div class="p-4 border border-gray-300 rounded-lg mb-8">
                    <h3 class="text-xl font-semibold mb-4 bg-gray-200 p-2">Personal Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-md font-semibold">Last Name</label>
                            <input type="text" name="last_name" required class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold text-right">Suffix</label>
                            <input type="text" name="suffix" maxlength="5" class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-md font-semibold">First Name</label>
                            <input type="text" name="first_name" required class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-md font-semibold">Middle Name</label>
                            <input type="text" name="middle_name" class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-md font-semibold">Address</label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-sm text-gray-600">No.</label>
                                    <input type="text" name="address_number" class="mt-1 block w-full border rounded p-2">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600">Street</label>
                                    <input type="text" name="address_street" class="mt-1 block w-full border rounded p-2">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600">Name of Subd./Zone/Sitio/Purok</label>
                                    <input type="text" name="address_sitio" class="mt-1 block w-full border rounded p-2">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mt-2">
                                <div class="md:col-span-2">
                                    <label class="block text-sm text-gray-600">City/Municipality</label>
                                    <input type="text" name="address_city" value="SAN RAFAEL" class="mt-1 block w-full border rounded p-2" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600">Province</label>
                                    <input type="text" name="address_province" value="BULACAN" class="mt-1 block w-full border rounded p-2" readonly>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Date of Birth (mm-dd-yyyy)</label>
                            <input type="date" name="birth_date" id="birth_date" required class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Age</label>
                            <input type="text" name="age" id="age" class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Citizenship</label>
                            <input type="text" name="citizenship" value="FILIPINO" class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Place of Birth</label>
                            <input type="text" name="place_of_birth" class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Sex</label>
                            <div class="flex space-x-4 mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="gender" value="Male" required class="form-radio">
                                    <span class="ml-2">MALE</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="gender" value="Female" required class="form-radio">
                                    <span class="ml-2">FEMALE</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Civil Status</label>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="SINGLE" checked class="form-radio">
                                    <span class="ml-2">SINGLE</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="MARRIED" class="form-radio">
                                    <span class="ml-2">MARRIED</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="WIDOW/WIDOWER" class="form-radio">
                                    <span class="ml-2">WIDOW/ER</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="SEPARATED" class="form-radio">
                                    <span class="ml-2">SEPARATED</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Educational Information Section -->
                <div class="p-4 border border-gray-300 rounded-lg mb-8">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-3">
                            <label class="block text-md font-semibold">Attending School</label>
                            <div class="flex space-x-4 mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="attending_school" value="1" id="attending-yes" class="form-radio">
                                    <span class="ml-2">YES</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="attending_school" value="0" id="attending-no" class="form-radio">
                                    <span class="ml-2">OSY</span>
                                </label>
                            </div>

                            <div id="school-type-options" class="flex flex-wrap space-x-4 mt-2 hidden">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Private" class="form-radio">
                                    <span class="ml-2">Private</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Public" class="form-radio">
                                    <span class="ml-2">Public</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="ALS" class="form-radio">
                                    <span class="ml-2">ALS</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Day Care" class="form-radio">
                                    <span class="ml-2">Day Care</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="SNP" class="form-radio">
                                    <span class="ml-2">SNP</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Not Attending" class="form-radio">
                                    <span class="ml-2">Not Attending</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div id="school-details" class="md:col-span-3 hidden">
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <label class="block text-md font-semibold">Name of School</label>
                                <input type="text" name="school_name" class="mt-1 block w-full border rounded p-2">
                            </div>
                            <div class="col-span-1">
                                <label class="block text-md font-semibold">Grade/Level</label>
                                <input type="text" name="grade_level" class="mt-1 block w-full border rounded p-2">
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-md font-semibold">Occupation</label>
                        <input type="text" name="occupation" class="mt-1 block w-full border rounded p-2">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-md font-semibold">Relationship to Household Head</label> <select name="relationship" class="mt-1 block w-full border rounded p-2">
                            <option value="Child">CHILD</option>
                            <option value="Grandchild">GRANDCHILD</option>
                            <option value="Other Relative">OTHER RELATIVE</option>
                            <option value="Non-relative">NON-RELATIVE</option>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <div class="flex space-x-4 my-2">
                            <label class="inline-flex items-center">
                                <span class="mr-2">Malnourished</span>
                                <input type="radio" name="is_malnourished" value="1" class="form-radio">
                                <span class="ml-2">Yes</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="is_malnourished" value="0" class="form-radio">
                                <span class="ml-2">No</span>
                            </label>
                        </div>
                    </div>
                </div>
    </div>

    <!-- Health Information Section -->
    <div class="p-4 border border-gray-300 rounded-lg mb-8">
        <h3 class="text-xl font-semibold mb-4 bg-gray-200 p-2">Completion Rate</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-3">
                <div class="grid grid-cols-3 border-b pb-2">
                    <div class="col-span-2 font-medium">Expanded Immunization Program (0-11 months)</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="is_immunized" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="is_immunized" value="0" class="form-radio">
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-3 border-b py-2">
                    <div class="col-span-2 font-medium">Garantisadong Pambata (0-17 years old)</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="garantisadong_pambata" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="garantisadong_pambata" value="0" class="form-radio">
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-3 border-b py-2">
                    <div class="col-span-2 font-medium">Operation Timbang (0-7 yrs old)</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="operation_timbang" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="operation_timbang" value="0" class="form-radio">
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <h4 class="text-lg font-semibold mt-4">Feeding Program</h4>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-3">
                <div class="grid grid-cols-3 border-b py-2">
                    <div class="col-span-2 font-medium">Supplementary Feeding Program</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="supplementary_feeding" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="supplementary_feeding" value="0" class="form-radio">
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-3 border-b py-2">
                    <div class="col-span-2 font-medium">0-71 mos / under 6yrs old</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="under_six_years" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="under_six_years" value="0" class="form-radio">
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-3 border-b py-2">
                    <div class="col-span-2 font-medium">Grade School</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="grade_school" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="grade_school" value="0" class="form-radio">
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Disease Section -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
            <div class="col-span-2">
                <h4 class="text-lg font-semibold">With cases of</h4>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Malaria</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_malaria" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_malaria" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Dengue</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_dengue" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_dengue" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Pneumonia</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_pneumonia" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_pneumonia" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Tuberculosis</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_tuberculosis" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_tuberculosis" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Diarrhea</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_diarrhea" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="has_diarrhea" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Child Welfare Status Section -->
            <div class="col-span-2">
                <h4 class="text-lg font-semibold">Children under</h4>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Caring Institution</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="caring_institution" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="caring_institution" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Under Foster Care</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="foster_care" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="foster_care" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Directly Entrusted</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="directly_entrusted" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="directly_entrusted" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Legally Adopted</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="legally_adopted" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="legally_adopted" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Disability Section -->
    <div class="p-4 border border-gray-300 rounded-lg mb-8">
        <h3 class="text-xl font-semibold mb-4">Children with Disability</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="col-span-2">
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Blind / Visually Impaired</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="visually_impaired" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="visually_impaired" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Hearing Impairment</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="hearing_impaired" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="hearing_impaired" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Speech/Communication</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="speech_impaired" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="speech_impaired" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="col-span-2">
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Orthopedic/Physical</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="orthopedic_disability" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="orthopedic_disability" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Intellectual/Learning</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="intellectual_disability" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="intellectual_disability" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 border-b py-2">
                    <div class="font-medium">Psychosocial</div>
                    <div class="flex justify-end space-x-8">
                        <label class="inline-flex items-center">
                            <input type="radio" name="psychosocial_disability" value="1" class="form-radio">
                            <span class="ml-2">YES</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="psychosocial_disability" value="0" class="form-radio" checked>
                            <span class="ml-2">NO</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="flex justify-center mt-6">
        <button type="submit" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-lg px-8 py-3 transition duration-200">
            Save Child Data
        </button>
    </div>
    </form>
    </section>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const attendingYes = document.getElementById('attending-yes');
            const attendingNo = document.getElementById('attending-no');
            const schoolTypeOptions = document.getElementById('school-type-options');
            const schoolDetails = document.getElementById('school-details');

            // Show/hide school type options and school details based on attending school selection
            attendingYes.addEventListener('change', function() {
                if (this.checked) {
                    schoolTypeOptions.classList.remove('hidden');
                    schoolDetails.classList.remove('hidden');
                }
            });
            attendingNo.addEventListener('change', function() {
                if (this.checked) {
                    schoolTypeOptions.classList.add('hidden');
                    schoolDetails.classList.add('hidden');

                    // Clear any selected school type when OSY is selected
                    const schoolTypeRadios = document.getElementsByName('school_type');
                    schoolTypeRadios.forEach(radio => {
                        radio.checked = false;
                    });

                    // Clear school name and grade level inputs
                    document.querySelector('input[name="school_name"]').value = '';
                    document.querySelector('input[name="grade_level"]').value = '';
                }
            });

            // Check initial state when page loads
            if (attendingYes.checked) {
                schoolTypeOptions.classList.remove('hidden');
                schoolDetails.classList.remove('hidden');
            }

            // Purok and Household Selection Logic
            const purokSelect = document.getElementById('purok_select');
            const householdSelect = document.getElementById('household_select');
            const originalHouseholdOption = householdSelect.querySelector('option[value=""]').cloneNode(true);

            // Store all household options
            const allHouseholdOptions = Array.from(householdSelect.querySelectorAll('option[data-purok]'));

            function updateHouseholdOptions() {
                const selectedPurokId = purokSelect.value;
                
                // Clear current options except the first one
                householdSelect.innerHTML = '';
                householdSelect.appendChild(originalHouseholdOption.cloneNode(true));

                // Add households that match the selected purok
                allHouseholdOptions.forEach(option => {
                    if (option.dataset.purok === selectedPurokId) {
                        householdSelect.appendChild(option.cloneNode(true));
                    }
                });
            }

            // Update household options when purok selection changes
            purokSelect.addEventListener('change', updateHouseholdOptions);

            // Initial update if a purok is pre-selected
            if (purokSelect.value) {
                updateHouseholdOptions();
            }

            // Calculate age based on birth date
            const birthDateInput = document.getElementById('birth_date');
            const ageInput = document.getElementById('age');

            // Function to calculate age
            function calculateAge(birthDate) {
                const today = new Date();
                const birthDateObj = new Date(birthDate);
                let age = today.getFullYear() - birthDateObj.getFullYear();
                const monthDiff = today.getMonth() - birthDateObj.getMonth();

                // Adjust age if birthday hasn't occurred yet this year
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDateObj.getDate())) {
                    age--;
                }

                return age;
            }

            // Function to validate age (0-17 years)
            function validateAge(birthDate) {
                const age = calculateAge(birthDate);
                if (age < 0 || age > 17) {
                    return false;
                }
                return true;
            }

            // Function to get minimum and maximum allowed birth dates
            function getDateRange() {
                const today = new Date();
                const minDate = new Date(today.getFullYear() - 17, today.getMonth(), today.getDate());
                const maxDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
                return { minDate, maxDate };
            }

            // Set date input constraints
            const { minDate, maxDate } = getDateRange();
            birthDateInput.min = minDate.toISOString().split('T')[0];
            birthDateInput.max = maxDate.toISOString().split('T')[0];

            // Update age when birth date changes
            birthDateInput.addEventListener('change', function() {
                if (this.value) {
                    if (validateAge(this.value)) {
                        ageInput.value = calculateAge(this.value);
                    } else {
                        this.value = '';
                        ageInput.value = '';
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid Age',
                            text: 'Only children aged 0-17 years old are allowed.',
                            confirmButtonColor: '#3085d6'
                        });
                    }
                } else {
                    ageInput.value = '';
                }
            });

            // Add form validation
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                if (birthDateInput.value && !validateAge(birthDateInput.value)) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Invalid Age',
                        text: 'Only children aged 0-17 years old are allowed.',
                        confirmButtonColor: '#3085d6'
                    });
                    birthDateInput.focus();
                } else {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Saving Child Data',
                        text: 'Please wait while we save the child information...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Submit the form after showing the loading message
                    setTimeout(() => {
                        form.submit();
                    }, 500);
                }
            });

            // Show success message if PHP indicates success
            <?php if ($add_success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $add_success; ?>',
                confirmButtonColor: '#3085d6'
            });
            <?php endif; ?>

            // Show error message if PHP indicates error
            <?php if ($add_error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?php echo $add_error; ?>',
                confirmButtonColor: '#3085d6'
            });
            <?php endif; ?>

            // Make all text inputs uppercase while typing
            const textInputs = document.querySelectorAll('input[type="text"]:not([readonly]), textarea');
            textInputs.forEach(input => {
                // Skip inputs that should remain as is (like hidden fields)
                if (input.classList.contains('no-transform')) {
                    return;
                }

                // Store original input value
                if (input.value) {
                    input.dataset.originalValue = input.value;
                    input.value = input.value.toUpperCase();
                }

                // Convert text to uppercase during typing
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });

            // Handle select dropdowns - transform only user input
            const selectElements = document.querySelectorAll('select');
            selectElements.forEach(select => {
                if (!select.multiple) {
                    select.addEventListener('input', function() {
                        // If it's a free-text select with an input field
                        const inputField = select.querySelector('input');
                        if (inputField) {
                            inputField.value = inputField.value.toUpperCase();
                        }
                    });
                }
            });
        });
    </script>
</body>

</html>