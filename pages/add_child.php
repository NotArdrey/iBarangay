<?php
require "../config/dbconn.php";
require_once "../pages/header.php";

// Fetch households for selection
$stmt = $pdo->prepare("SELECT id AS household_id FROM households WHERE barangay_id = ? ORDER BY id");
$stmt->execute([$_SESSION['barangay_id']]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $school_type = $_POST['school_type'] ?? '';
    $school_name = trim($_POST['school_name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');

    // Health Information
    $is_malnourished = $_POST['is_malnourished'] ?? '0';
    $is_immunized = $_POST['is_immunized'] ?? '0';
    $garantisadong_pambata = $_POST['garantisadong_pambata'] ?? '0';
    $operation_timbang = $_POST['operation_timbang'] ?? '0';
    $supplementary_feeding = $_POST['supplementary_feeding'] ?? '0';
    $under_six_years = $_POST['under_six_years'] ?? '0';
    $grade_school = $_POST['grade_school'] ?? '0';

    // Diseases
    $has_malaria = $_POST['has_malaria'] ?? '0';
    $has_dengue = $_POST['has_dengue'] ?? '0';
    $has_pneumonia = $_POST['has_pneumonia'] ?? '0';
    $has_tuberculosis = $_POST['has_tuberculosis'] ?? '0';
    $has_diarrhea = $_POST['has_diarrhea'] ?? '0';

    // Child Welfare Status
    $caring_institution = $_POST['caring_institution'] ?? '0';
    $foster_care = $_POST['foster_care'] ?? '0';
    $directly_entrusted = $_POST['directly_entrusted'] ?? '0';
    $legally_adopted = $_POST['legally_adopted'] ?? '0';
    // Disability
    $visually_impaired = $_POST['visually_impaired'] ?? '0';
    $hearing_impaired = $_POST['hearing_impaired'] ?? '0';
    $speech_impaired = $_POST['speech_impaired'] ?? '0';
    $orthopedic_disability = $_POST['orthopedic_disability'] ?? '0';
    $intellectual_disability = $_POST['intellectual_disability'] ?? '0';
    $psychosocial_disability = $_POST['psychosocial_disability'] ?? '0';

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
                (first_name, middle_name, last_name, suffix, birth_date, gender, civil_status, citizenship)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $first_name,
                $middle_name,
                $last_name,
                $suffix,
                $birth_date,
                $gender,
                $civil_status,
                $citizenship
            ]);
            $person_id = $pdo->lastInsertId();

            // Insert into household_members (if household_id is provided)
            if (!empty($household_id)) {
                $stmt = $pdo->prepare("INSERT INTO household_members 
                    (household_id, person_id, relationship_to_head, is_household_head)
                    VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $household_id,
                    $person_id,
                    $relationship,
                    $is_household_head
                ]);
            }

            // Insert into child_information
            $stmt = $pdo->prepare("INSERT INTO child_information (person_id) VALUES (?)");
            $stmt->execute([$person_id]);

            $pdo->commit();
            $add_success = "Child added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $add_error = "Error adding child: " . htmlspecialchars($e->getMessage());
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
        <section id="add-child" class="bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">CHILDREN 0-17 YEARS OLD</h2>
            <form method="POST" class="space-y-8" autocomplete="off">
                <!-- Household Information Section -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 p-4 border border-gray-300 rounded-lg">
                    <div class="md:col-span-2">
                        <label class="block text-md font-semibold">REGION</label>
                        <input type="text" name="region" value="III" class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-md font-semibold text-right">HOUSEHOLD NUMBER (HN) (Optional)</label>
                        <select name="household_id" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- SELECT HOUSEHOLD --</option>
                            <?php foreach ($households as $household): ?>
                                <option value="<?= htmlspecialchars($household['household_id']) ?>">
                                    <?= htmlspecialchars($household['household_id']) ?>
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
                            <input type="text" name="suffix" class="mt-1 block w-full border rounded p-2">
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
                        </div>                        <div>
                            <label class="block text-md font-semibold">Date of Birth (mm-dd-yyyy)</label>
                            <input type="date" name="birth_date" id="birth_date" required class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Age</label>
                            <input type="text" name="age" id="age" class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Citizenship</label>
                            <input type="text" name="citizenship" value="FILIPINO" class="mt-1 block w-full border rounded p-2">
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
                                    <input type="radio" name="civil_status" value="Single" checked class="form-radio">
                                    <span class="ml-2">SINGLE</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Married" class="form-radio">
                                    <span class="ml-2">MARRIED</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Widow/er" class="form-radio">
                                    <span class="ml-2">WIDOW/ER</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Separated" class="form-radio">
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
                            </div>
                        </div>                    </div>                    
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
        document.addEventListener('DOMContentLoaded', function() {            const attendingYes = document.getElementById('attending-yes');
            const attendingNo = document.getElementById('attending-no');
            const schoolTypeOptions = document.getElementById('school-type-options');
            const schoolDetails = document.getElementById('school-details');

            // Show/hide school type options and school details based on attending school selection
            attendingYes.addEventListener('change', function() {
                if (this.checked) {
                    schoolTypeOptions.classList.remove('hidden');
                    schoolDetails.classList.remove('hidden');
                }
            });            attendingNo.addEventListener('change', function() {
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
        });        document.addEventListener('DOMContentLoaded', function() {
            // Set today's date as default for date input
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
            
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
            
            // Calculate initial age if birth date is already set
            if (birthDateInput.value) {
                ageInput.value = calculateAge(birthDateInput.value);
            }
            
            // Update age when birth date changes
            birthDateInput.addEventListener('change', function() {
                if (this.value) {
                    ageInput.value = calculateAge(this.value);
                } else {
                    ageInput.value = '';
                }
            });

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