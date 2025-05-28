<?php
require "../config/dbconn.php";
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

    // Fetch child information
    $stmt = $pdo->prepare("SELECT * FROM child_information WHERE person_id = ?");
    $stmt->execute([$person_id]);
    $child_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch child health conditions
    $stmt = $pdo->prepare("SELECT condition_type FROM child_health_conditions WHERE person_id = ?");
    $stmt->execute([$person_id]);
    $health_conditions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch child disabilities
    $stmt = $pdo->prepare("SELECT disability_type FROM child_disabilities WHERE person_id = ?");
    $stmt->execute([$person_id]);
    $disabilities = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch households for selection
    $stmt = $pdo->prepare("
        SELECT h.id AS household_id, h.household_number, p.name as purok_name, h.purok_id
        FROM households h
        LEFT JOIN purok p ON h.purok_id = p.id
        WHERE h.barangay_id = ? 
        ORDER BY h.household_number
    ");
    $stmt->execute([$_SESSION['barangay_id']]);
    $households = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch puroks for selection
    $stmt = $pdo->prepare("SELECT id, name FROM purok WHERE barangay_id = ? ORDER BY name");
    $stmt->execute([$_SESSION['barangay_id']]);
    $puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch relationship types
    $stmt = $pdo->prepare("SELECT id, name FROM relationship_types ORDER BY name");
    $stmt->execute();
    $relationships = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                citizenship = ?
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

        // Update child information
        $stmt = $pdo->prepare("
            UPDATE child_information SET 
                attending_school = ?,
                school_name = ?,
                grade_level = ?,
                school_type = ?,
                is_malnourished = ?,
                immunization_complete = ?,
                garantisadong_pambata = ?,
                has_timbang_operation = ?,
                has_supplementary_feeding = ?,
                in_caring_institution = ?,
                is_under_foster_care = ?,
                is_directly_entrusted = ?,
                is_legally_adopted = ?,
                occupation = ?,
                under_six_years = ?,
                grade_school = ?
            WHERE person_id = ?
        ");
        $stmt->execute([
            $_POST['attending_school'] == '1' ? 1 : 0,
            $_POST['school_name'],
            $_POST['grade_level'],
            $_POST['school_type'],
            $_POST['is_malnourished'] == '1' ? 1 : 0,
            $_POST['is_immunized'] == '1' ? 1 : 0,
            $_POST['garantisadong_pambata'] == '1' ? 1 : 0,
            $_POST['operation_timbang'] == '1' ? 1 : 0,
            $_POST['supplementary_feeding'] == '1' ? 1 : 0,
            $_POST['caring_institution'] == '1' ? 1 : 0,
            $_POST['foster_care'] == '1' ? 1 : 0,
            $_POST['directly_entrusted'] == '1' ? 1 : 0,
            $_POST['legally_adopted'] == '1' ? 1 : 0,
            $_POST['occupation'],
            $_POST['under_six_years'] == '1' ? 1 : 0,
            $_POST['grade_school'] == '1' ? 1 : 0,
            $person_id
        ]);

        // Update health conditions
        $stmt = $pdo->prepare("DELETE FROM child_health_conditions WHERE person_id = ?");
        $stmt->execute([$person_id]);

        $health_conditions = [
            'Malaria' => 'has_malaria',
            'Dengue' => 'has_dengue',
            'Pneumonia' => 'has_pneumonia',
            'Tuberculosis' => 'has_tuberculosis',
            'Diarrhea' => 'has_diarrhea'
        ];

        $stmt = $pdo->prepare("INSERT INTO child_health_conditions (person_id, condition_type) VALUES (?, ?)");
        foreach ($health_conditions as $condition => $field) {
            if (isset($_POST[$field]) && $_POST[$field] == 1) {
                $stmt->execute([$person_id, $condition]);
            }
        }

        // Update disabilities
        $stmt = $pdo->prepare("DELETE FROM child_disabilities WHERE person_id = ?");
        $stmt->execute([$person_id]);

        $disabilities = [
            'Blind/Visually Impaired' => 'visually_impaired',
            'Hearing Impairment' => 'hearing_impaired',
            'Speech/Communication' => 'speech_impaired',
            'Orthopedic/Physical' => 'orthopedic_disability',
            'Intellectual/Learning' => 'intellectual_disability',
            'Psychosocial' => 'psychosocial_disability'
        ];

        $stmt = $pdo->prepare("INSERT INTO child_disabilities (person_id, disability_type) VALUES (?, ?)");
        foreach ($disabilities as $disability => $field) {
            if (isset($_POST[$field]) && $_POST[$field] == 1) {
                $stmt->execute([$person_id, $disability]);
            }
        }

        $pdo->commit();
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@10'></script>\n";
        echo "<script>\nSwal.fire({\n  icon: 'success',\n  title: 'Success!',\n  text: 'Child record updated successfully!',\n  confirmButtonColor: '#3085d6'\n}).then(() => {\n  window.location.href = 'edit_child.php?id=$person_id';\n});\n</script>";
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
    <title>Edit Child Record</title>
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
        <section id="edit-child" class="bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">EDIT CHILD RECORD (0-17 YEARS OLD)</h2>

            <form method="POST" class="space-y-8" autocomplete="off">
                <!-- Household Information Section -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 p-4 border border-gray-300 rounded-lg">
                    <div class="md:col-span-1">
                        <label class="block text-md font-semibold">REGION</label>
                        <input type="text" value="III" class="mt-1 block w-full border rounded p-2" readonly>
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
                                        data-purok="<?= htmlspecialchars($household['purok_id']) ?>"
                                        <?= $household['household_id'] == $person['household_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($household['household_number'] ?? $household['household_id']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-md font-semibold">BARANGAY</label>
                        <input type="text" value="TAMBUBONG" class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                </div>

                <!-- Personal Information Section -->
                <div class="p-4 border border-gray-300 rounded-lg mb-8">
                    <h3 class="text-xl font-semibold mb-4 bg-gray-200 p-2">Personal Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-md font-semibold">Last Name</label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($person['last_name']) ?>" required
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold text-right">Suffix</label>
                            <input type="text" name="suffix" value="<?= htmlspecialchars($person['suffix'] ?? '') ?>" maxlength="5"
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-md font-semibold">First Name</label>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($person['first_name']) ?>" required
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-md font-semibold">Middle Name</label>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($person['middle_name'] ?? '') ?>"
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-md font-semibold">Address</label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-sm text-gray-600">No.</label>
                                    <input type="text" name="address_number" value="<?= htmlspecialchars($person['house_no'] ?? '') ?>"
                                        class="mt-1 block w-full border rounded p-2">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600">Street</label>
                                    <input type="text" name="address_street" value="<?= htmlspecialchars($person['street'] ?? '') ?>"
                                        class="mt-1 block w-full border rounded p-2">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600">Name of Subd./Zone/Sitio/Purok</label>
                                    <input type="text" name="address_sitio" value="<?= htmlspecialchars($person['sitio'] ?? '') ?>"
                                        class="mt-1 block w-full border rounded p-2">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mt-2">
                                <div class="md:col-span-2">
                                    <label class="block text-sm text-gray-600">City/Municipality</label>
                                    <input type="text" value="SAN RAFAEL" class="mt-1 block w-full border rounded p-2" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-600">Province</label>
                                    <input type="text" value="BULACAN" class="mt-1 block w-full border rounded p-2" readonly>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Date of Birth (mm-dd-yyyy)</label>
                            <input type="date" name="birth_date" value="<?= htmlspecialchars($person['birth_date']) ?>" required
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Age</label>
                            <input type="text" id="age" class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Citizenship</label>
                            <input type="text" name="citizenship" value="<?= htmlspecialchars($person['citizenship'] ?? 'FILIPINO') ?>"
                                class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Place of Birth</label>
                            <input type="text" name="birth_place" value="<?= htmlspecialchars($person['birth_place'] ?? '') ?>"
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Sex</label>
                            <div class="flex space-x-4 mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="gender" value="Male" <?= $person['gender'] === 'Male' ? 'checked' : '' ?> required class="form-radio">
                                    <span class="ml-2">MALE</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="gender" value="Female" <?= $person['gender'] === 'Female' ? 'checked' : '' ?> required class="form-radio">
                                    <span class="ml-2">FEMALE</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-md font-semibold">Civil Status</label>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Single" <?= $person['civil_status'] === 'Single' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">SINGLE</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Married" <?= $person['civil_status'] === 'Married' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">MARRIED</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Widowed" <?= $person['civil_status'] === 'Widowed' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">WIDOW/ER</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Separated" <?= $person['civil_status'] === 'Separated' ? 'checked' : '' ?> class="form-radio">
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
                                    <input type="radio" name="attending_school" value="1" id="attending-yes" <?= $child_info['attending_school'] ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">YES</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="attending_school" value="0" id="attending-no" <?= !$child_info['attending_school'] ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">OSY</span>
                                </label>
                            </div>

                            <div id="school-type-options" class="flex flex-wrap space-x-4 mt-2 <?= $child_info['attending_school'] ? '' : 'hidden' ?>">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Private" <?= $child_info['school_type'] === 'Private' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">Private</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Public" <?= $child_info['school_type'] === 'Public' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">Public</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="ALS" <?= $child_info['school_type'] === 'ALS' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">ALS</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Day Care" <?= $child_info['school_type'] === 'Day Care' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">Day Care</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="SNP" <?= $child_info['school_type'] === 'SNP' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">SNP</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="school_type" value="Not Attending" <?= $child_info['school_type'] === 'Not Attending' ? 'checked' : '' ?> class="form-radio">
                                    <span class="ml-2">Not Attending</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div id="school-details" class="md:col-span-3 <?= $child_info['attending_school'] ? '' : 'hidden' ?>">
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <label class="block text-md font-semibold">Name of School</label>
                                <input type="text" name="school_name" value="<?= htmlspecialchars($child_info['school_name'] ?? '') ?>"
                                    class="mt-1 block w-full border rounded p-2">
                            </div>
                            <div class="col-span-1">
                                <label class="block text-md font-semibold">Grade/Level</label>
                                <input type="text" name="grade_level" value="<?= htmlspecialchars($child_info['grade_level'] ?? '') ?>"
                                    class="mt-1 block w-full border rounded p-2">
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-md font-semibold">Occupation</label>
                        <input type="text" name="occupation" value="<?= htmlspecialchars($child_info['occupation'] ?? '') ?>"
                            class="mt-1 block w-full border rounded p-2">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-md font-semibold">Relationship to Household Head</label>
                        <select name="relationship" class="mt-1 block w-full border rounded p-2">
                            <?php foreach ($relationships as $rel): ?>
                                <option value="<?= $rel['id'] ?>" <?= $rel['id'] == $person['relationship_type_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rel['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Health Information Section -->
                <div class="p-4 border border-gray-300 rounded-lg mb-8">
                    <h3 class="text-xl font-semibold mb-4 bg-gray-200 p-2">Completion Rate</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-3">
                            <div class="grid grid-cols-3 border-b pb-2">
                                <div class="col-span-2 font-medium">Malnourished</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="is_malnourished" value="1" <?= $child_info['is_malnourished'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="is_malnourished" value="0" <?= !$child_info['is_malnourished'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 border-b py-2">
                                <div class="col-span-2 font-medium">Immunization Complete</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="is_immunized" value="1" <?= $child_info['immunization_complete'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="is_immunized" value="0" <?= !$child_info['immunization_complete'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 border-b py-2">
                                <div class="col-span-2 font-medium">Garantisadong Pambata (0-17 years old)</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="garantisadong_pambata" value="1" <?= $child_info['garantisadong_pambata'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="garantisadong_pambata" value="0" <?= !$child_info['garantisadong_pambata'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 border-b py-2">
                                <div class="col-span-2 font-medium">Operation Timbang (0-7 yrs old)</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="operation_timbang" value="1" <?= $child_info['has_timbang_operation'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="operation_timbang" value="0" <?= !$child_info['has_timbang_operation'] ? 'checked' : '' ?> class="form-radio">
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
                                        <input type="radio" name="supplementary_feeding" value="1" <?= $child_info['has_supplementary_feeding'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="supplementary_feeding" value="0" <?= !$child_info['has_supplementary_feeding'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 border-b py-2">
                                <div class="col-span-2 font-medium">0-71 mos / under 6yrs old</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="under_six_years" value="1" <?= $child_info['under_six_years'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="under_six_years" value="0" <?= !$child_info['under_six_years'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 border-b py-2">
                                <div class="col-span-2 font-medium">Grade School</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="grade_school" value="1" <?= $child_info['grade_school'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="grade_school" value="0" <?= !$child_info['grade_school'] ? 'checked' : '' ?> class="form-radio">
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
                                        <input type="radio" name="has_malaria" value="1" <?= in_array('Malaria', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_malaria" value="0" <?= !in_array('Malaria', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Dengue</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_dengue" value="1" <?= in_array('Dengue', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_dengue" value="0" <?= !in_array('Dengue', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Pneumonia</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_pneumonia" value="1" <?= in_array('Pneumonia', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_pneumonia" value="0" <?= !in_array('Pneumonia', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Tuberculosis</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_tuberculosis" value="1" <?= in_array('Tuberculosis', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_tuberculosis" value="0" <?= !in_array('Tuberculosis', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Diarrhea</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_diarrhea" value="1" <?= in_array('Diarrhea', $health_conditions) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="has_diarrhea" value="0" <?= !in_array('Diarrhea', $health_conditions) ? 'checked' : '' ?> class="form-radio">
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
                                        <input type="radio" name="caring_institution" value="1" <?= $child_info['in_caring_institution'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="caring_institution" value="0" <?= !$child_info['in_caring_institution'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Under Foster Care</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="foster_care" value="1" <?= $child_info['is_under_foster_care'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="foster_care" value="0" <?= !$child_info['is_under_foster_care'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Directly Entrusted</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="directly_entrusted" value="1" <?= $child_info['is_directly_entrusted'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="directly_entrusted" value="0" <?= !$child_info['is_directly_entrusted'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Legally Adopted</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="legally_adopted" value="1" <?= $child_info['is_legally_adopted'] ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="legally_adopted" value="0" <?= !$child_info['is_legally_adopted'] ? 'checked' : '' ?> class="form-radio">
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
                                        <input type="radio" name="visually_impaired" value="1" <?= in_array('Blind/Visually Impaired', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="visually_impaired" value="0" <?= !in_array('Blind/Visually Impaired', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Hearing Impairment</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="hearing_impaired" value="1" <?= in_array('Hearing Impairment', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="hearing_impaired" value="0" <?= !in_array('Hearing Impairment', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Speech/Communication</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="speech_impaired" value="1" <?= in_array('Speech/Communication', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="speech_impaired" value="0" <?= !in_array('Speech/Communication', $disabilities) ? 'checked' : '' ?> class="form-radio">
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
                                        <input type="radio" name="orthopedic_disability" value="1" <?= in_array('Orthopedic/Physical', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="orthopedic_disability" value="0" <?= !in_array('Orthopedic/Physical', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Intellectual/Learning</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="intellectual_disability" value="1" <?= in_array('Intellectual/Learning', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="intellectual_disability" value="0" <?= !in_array('Intellectual/Learning', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">NO</span>
                                    </label>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 border-b py-2">
                                <div class="font-medium">Psychosocial</div>
                                <div class="flex justify-end space-x-8">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="psychosocial_disability" value="1" <?= in_array('Psychosocial', $disabilities) ? 'checked' : '' ?> class="form-radio">
                                        <span class="ml-2">YES</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="psychosocial_disability" value="0" <?= !in_array('Psychosocial', $disabilities) ? 'checked' : '' ?> class="form-radio">
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
                        Save Changes
                    </button>
                </div>
            </form>
        </section>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Calculate age based on birth date
            const birthDateInput = document.querySelector('input[name="birth_date"]');
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

            // Update age when birth date changes
            birthDateInput.addEventListener('change', function() {
                if (this.value) {
                    ageInput.value = calculateAge(this.value);
                } else {
                    ageInput.value = '';
                }
            });

            // Calculate initial age if birth date is already set
            if (birthDateInput.value) {
                ageInput.value = calculateAge(birthDateInput.value);
            }

            // --- Dependent Household Dropdown Logic ---
            const purokSelect = document.getElementById('purok_select');
            const householdSelect = document.getElementById('household_select');
            const originalHouseholdOption = householdSelect.querySelector('option[value=""]');
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
                // Try to keep the previously selected household if still available
                const currentValue = householdSelect.getAttribute('data-current');
                if (currentValue) {
                    householdSelect.value = currentValue;
                }
            }

            // Set data-current to the selected value for pre-selection
            householdSelect.setAttribute('data-current', householdSelect.value);

            // Update household options when purok selection changes
            purokSelect.addEventListener('change', updateHouseholdOptions);

            // Initial update if a purok is pre-selected
            if (purokSelect.value) {
                updateHouseholdOptions();
            }
        });
    </script>
    <?php if (isset($_GET['success'])): ?>
    <script>
    Swal.fire({
      icon: 'success',
      title: 'Success!',
      text: 'Child record updated successfully!',
      confirmButtonColor: '#3085d6'
    });
    </script>
    <?php endif; ?>

    <?php if ($error): ?>
    <script>
    Swal.fire({
      icon: 'error',
      title: 'Error!',
      text: '<?= addslashes($error) ?>',
      confirmButtonColor: '#d33'
    });
    </script>
    <?php endif; ?>
</body>
</html> 