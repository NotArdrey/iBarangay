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
    SELECT h.id AS household_id, h.purok_id, p.name as purok_name
    FROM households h
    LEFT JOIN purok p ON h.purok_id = p.id
    WHERE h.barangay_id = ? 
    ORDER BY h.id
");
$stmt->execute([$barangay_id]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch puroks for selection
$stmt = $pdo->prepare("SELECT id, name FROM purok WHERE barangay_id = ? ORDER BY name");
$stmt->execute([$_SESSION['barangay_id']]);
$puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
require_once "../components/header.php";

// --- ADD RESIDENT LOGIC ---
$add_error = '';
$add_success = '';
$form_data = []; // Store form data for repopulation on error

// Pre-fill form for editing
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    // Fetch main person and household info
    $stmt = $pdo->prepare("
        SELECT p.*, hm.household_id, hm.relationship_type_id, hm.is_household_head, rt.name as relationship_name, h.purok_id
        FROM persons p
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        WHERE p.id = ?
    ");
    $stmt->execute([$edit_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($person) {
        $form_data = $person;
        // Optionally fetch and merge more data (e.g., addresses, IDs, etc.)
    }
}

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
            'present_house_no' => trim($_POST['present_house_no'] ?? ''),
            'present_street' => trim($_POST['present_street'] ?? ''),
            'present_municipality' => trim($_POST['present_municipality'] ?? 'SAN RAFAEL'),
            'present_province' => trim($_POST['present_province'] ?? 'BULACAN'),
            'present_region' => trim($_POST['present_region'] ?? 'III'),
            'permanent_house_no' => trim($_POST['permanent_house_no'] ?? ''),
            'permanent_street' => trim($_POST['permanent_street'] ?? ''),
            'permanent_municipality' => trim($_POST['permanent_municipality'] ?? ''),
            'permanent_province' => trim($_POST['permanent_province'] ?? ''),
            'permanent_region' => trim($_POST['permanent_region'] ?? ''),
            'household_id' => $_POST['household_id'] ?? '',
            'relationship' => $_POST['relationship'] ?? '',
            'is_household_head' => isset($_POST['is_household_head']) ? 1 : 0,
            'resident_type' => $_POST['resident_type'] ?? 'regular',
            'contact_number' => trim($_POST['contact_number'] ?? ''),
            // Add ID fields
            'osca_id' => trim($_POST['osca_id'] ?? ''),
            'gsis_id' => trim($_POST['gsis_id'] ?? ''),
            'sss_id' => trim($_POST['sss_id'] ?? ''),
            'tin_id' => trim($_POST['tin_id'] ?? ''),
            'philhealth_id' => trim($_POST['philhealth_id'] ?? ''),
            'other_id_type' => trim($_POST['other_id_type'] ?? ''),
            'other_id_number' => trim($_POST['other_id_number'] ?? ''),
            'years_of_residency' => isset($_POST['years_of_residency']) ? (int)trim($_POST['years_of_residency']) : 0,
            // Living arrangements
            'living_alone' => isset($_POST['living_alone']) ? 1 : 0,
            'living_spouse' => isset($_POST['living_spouse']) ? 1 : 0,
            'living_children' => isset($_POST['living_children']) ? 1 : 0,
            'living_grandchildren' => isset($_POST['living_grandchildren']) ? 1 : 0,
            'living_in_laws' => isset($_POST['living_in_laws']) ? 1 : 0,
            'living_relatives' => isset($_POST['living_relatives']) ? 1 : 0,
            'living_househelps' => isset($_POST['living_househelps']) ? 1 : 0,
            'living_care_institutions' => isset($_POST['living_care_institutions']) ? 1 : 0,
            'living_common_law_spouse' => isset($_POST['living_common_law_spouse']) ? 1 : 0,
            'living_others' => isset($_POST['living_others']) ? 1 : 0,
            'living_others_specify' => trim($_POST['living_others_specify'] ?? ''),

            // Skills
            'skill_medical' => isset($_POST['skill_medical']) ? 1 : 0,
            'skill_teaching' => isset($_POST['skill_teaching']) ? 1 : 0,
            'skill_legal_services' => isset($_POST['skill_legal_services']) ? 1 : 0,
            'skill_dental' => isset($_POST['skill_dental']) ? 1 : 0,
            'skill_counseling' => isset($_POST['skill_counseling']) ? 1 : 0,
            'skill_evangelization' => isset($_POST['skill_evangelization']) ? 1 : 0,
            'skill_farming' => isset($_POST['skill_farming']) ? 1 : 0,
            'skill_fishing' => isset($_POST['skill_fishing']) ? 1 : 0,
            'skill_cooking' => isset($_POST['skill_cooking']) ? 1 : 0,
            'skill_vocational' => isset($_POST['skill_vocational']) ? 1 : 0,
            'skill_arts' => isset($_POST['skill_arts']) ? 1 : 0,
            'skill_engineering' => isset($_POST['skill_engineering']) ? 1 : 0,
            'skill_others' => isset($_POST['skill_others']) ? 1 : 0,
            'skill_others_specify' => trim($_POST['skill_others_specify'] ?? ''),

            // Community involvements
            'involvement_medical' => isset($_POST['involvement_medical']) ? 1 : 0,
            'involvement_resource_volunteer' => isset($_POST['involvement_resource_volunteer']) ? 1 : 0,
            'involvement_community_beautification' => isset($_POST['involvement_community_beautification']) ? 1 : 0,
            'involvement_community_leader' => isset($_POST['involvement_community_leader']) ? 1 : 0,
            'involvement_dental' => isset($_POST['involvement_dental']) ? 1 : 0,
            'involvement_friendly_visits' => isset($_POST['involvement_friendly_visits']) ? 1 : 0,
            'involvement_neighborhood_support' => isset($_POST['involvement_neighborhood_support']) ? 1 : 0,
            'involvement_religious' => isset($_POST['involvement_religious']) ? 1 : 0,
            'involvement_counselling' => isset($_POST['involvement_counselling']) ? 1 : 0,
            'involvement_sponsorship' => isset($_POST['involvement_sponsorship']) ? 1 : 0,
            'involvement_legal_services' => isset($_POST['involvement_legal_services']) ? 1 : 0,
            'involvement_others' => isset($_POST['involvement_others']) ? 1 : 0,
            'involvement_others_specify' => trim($_POST['involvement_others_specify'] ?? ''),

            // Government Programs
            'nhts_pr_listahanan' => isset($_POST['nhts_pr_listahanan']) && $_POST['nhts_pr_listahanan'] == 1 ? 1 : 0,
            'indigenous_people' => isset($_POST['indigenous_people']) && $_POST['indigenous_people'] == 1 ? 1 : 0,
            'pantawid_beneficiary' => isset($_POST['pantawid_beneficiary']) && $_POST['pantawid_beneficiary'] == 1 ? 1 : 0,

            // Assets
            'asset_house' => isset($_POST['asset_house']) ? 1 : 0,
            'asset_house_lot' => isset($_POST['asset_house_lot']) ? 1 : 0,
            'asset_farmland' => isset($_POST['asset_farmland']) ? 1 : 0,
            'asset_commercial' => isset($_POST['asset_commercial']) ? 1 : 0,
            'asset_lot' => isset($_POST['asset_lot']) ? 1 : 0,
            'asset_fishpond' => isset($_POST['asset_fishpond']) ? 1 : 0,
            'asset_others' => isset($_POST['asset_others']) ? 1 : 0,
            'asset_others_specify' => trim($_POST['asset_others_specify'] ?? ''),

            // Income Sources
            'income_own_earnings' => isset($_POST['income_own_earnings']) ? 1 : 0,
            'income_own_pension' => isset($_POST['income_own_pension']) ? 1 : 0,
            'income_own_pension_amount' => trim($_POST['income_own_pension_amount'] ?? ''),
            'income_stocks' => isset($_POST['income_stocks']) ? 1 : 0,
            'income_dependent_children' => isset($_POST['income_dependent_children']) ? 1 : 0,
            'income_spouse_salary' => isset($_POST['income_spouse_salary']) ? 1 : 0,
            'income_insurance' => isset($_POST['income_insurance']) ? 1 : 0,
            'income_spouse_pension' => isset($_POST['income_spouse_pension']) ? 1 : 0,
            'income_spouse_pension_amount' => trim($_POST['income_spouse_pension_amount'] ?? ''),
            'income_rentals' => isset($_POST['income_rentals']) ? 1 : 0,
            'income_savings' => isset($_POST['income_savings']) ? 1 : 0,
            'income_livestock' => isset($_POST['income_livestock']) ? 1 : 0,
            'income_others' => isset($_POST['income_others']) ? 1 : 0,
            'income_others_specify' => trim($_POST['income_others_specify'] ?? ''),

            // Problems - Economic
            'problem_loss_income' => isset($_POST['problem_loss_income']) ? 1 : 0,
            'problem_lack_income' => isset($_POST['problem_lack_income']) ? 1 : 0,
            'problem_high_cost_living' => isset($_POST['problem_high_cost_living']) ? 1 : 0,
            'problem_skills_training' => isset($_POST['problem_skills_training']) ? 1 : 0,
            'problem_skills_training_specify' => trim($_POST['problem_skills_training_specify'] ?? ''),
            'problem_livelihood' => isset($_POST['problem_livelihood']) ? 1 : 0,
            'problem_livelihood_specify' => trim($_POST['problem_livelihood_specify'] ?? ''),
            'problem_economic_others' => isset($_POST['problem_economic_others']) ? 1 : 0,
            'problem_economic_others_specify' => trim($_POST['problem_economic_others_specify'] ?? ''),

            // Problems - Social
            'problem_loneliness' => isset($_POST['problem_loneliness']) ? 1 : 0,
            'problem_helplessness' => isset($_POST['problem_helplessness']) ? 1 : 0,
            'problem_neglect_rejection' => isset($_POST['problem_neglect_rejection']) ? 1 : 0,
            'problem_recreational' => isset($_POST['problem_recreational']) ? 1 : 0,
            'problem_senior_friendly' => isset($_POST['problem_senior_friendly']) ? 1 : 0,
            'problem_social_others' => isset($_POST['problem_social_others']) ? 1 : 0,
            'problem_social_others_specify' => trim($_POST['problem_social_others_specify'] ?? ''),

            // Problems - Health
            'problem_condition_illness' => isset($_POST['problem_condition_illness']) ? 1 : 0,
            'problem_condition_illness_specify' => trim($_POST['problem_condition_illness_specify'] ?? ''),
            'problem_high_cost_medicine' => isset($_POST['problem_high_cost_medicine']) ? 1 : 0,
            'problem_lack_medical_professionals' => isset($_POST['problem_lack_medical_professionals']) ? 1 : 0,
            'problem_lack_sanitation' => isset($_POST['problem_lack_sanitation']) ? 1 : 0,
            'problem_lack_health_insurance' => isset($_POST['problem_lack_health_insurance']) ? 1 : 0,
            'problem_inadequate_health_services' => isset($_POST['problem_inadequate_health_services']) ? 1 : 0,
            'problem_health_others' => isset($_POST['problem_health_others']) ? 1 : 0,
            'problem_health_others_specify' => trim($_POST['problem_health_others_specify'] ?? ''),

            // Problems - Housing
            'problem_overcrowding' => isset($_POST['problem_overcrowding']) ? 1 : 0,
            'problem_no_permanent_housing' => isset($_POST['problem_no_permanent_housing']) ? 1 : 0,
            'problem_independent_living' => isset($_POST['problem_independent_living']) ? 1 : 0,
            'problem_lost_privacy' => isset($_POST['problem_lost_privacy']) ? 1 : 0,
            'problem_squatters' => isset($_POST['problem_squatters']) ? 1 : 0,
            'problem_housing_others' => isset($_POST['problem_housing_others']) ? 1 : 0,
            'problem_housing_others_specify' => trim($_POST['problem_housing_others_specify'] ?? ''),

            // Problems - Community Service
            'problem_desire_participate' => isset($_POST['problem_desire_participate']) ? 1 : 0,
            'problem_skills_to_share' => isset($_POST['problem_skills_to_share']) ? 1 : 0,
            'problem_community_others' => isset($_POST['problem_community_others']) ? 1 : 0,
            'problem_community_others_specify' => trim($_POST['problem_community_others_specify'] ?? ''),

            // F. Other Specific Needs
            'other_specific_needs' => trim($_POST['other_specific_needs'] ?? ''),

            // Health Conditions and Maintenance
            'health_condition' => trim($_POST['problem_condition_illness_specify'] ?? ''),
            'has_maintenance' => isset($_POST['problem_with_maintenance']) && $_POST['problem_with_maintenance'] === 'YES' ? 1 : 0,
            'maintenance_details' => trim($_POST['problem_with_maintenance_specify'] ?? ''),
            'high_cost_medicines' => isset($_POST['problem_high_cost_medicine']) && $_POST['problem_high_cost_medicine'] == 1 ? 1 : 0,
            'lack_medical_professionals' => isset($_POST['problem_lack_medical_professionals']) && $_POST['problem_lack_medical_professionals'] == 1 ? 1 : 0,
            'lack_sanitation_access' => isset($_POST['problem_lack_sanitation']) && $_POST['problem_lack_sanitation'] == 1 ? 1 : 0,
            'lack_health_insurance' => isset($_POST['problem_lack_health_insurance']) && $_POST['problem_lack_health_insurance'] == 1 ? 1 : 0,
            'lack_medical_facilities' => isset($_POST['problem_lack_medical_facilities']) && $_POST['problem_lack_medical_facilities'] == 1 ? 1 : 0,
            'other_health_concerns' => trim($_POST['problem_health_others_specify'] ?? ''),

            // Health Concerns
            'health_high_blood' => isset($_POST['health_high_blood']) ? 1 : 0,
            'health_diabetes' => isset($_POST['health_diabetes']) ? 1 : 0,
            'health_heart' => isset($_POST['health_heart']) ? 1 : 0,
            'health_arthritis' => isset($_POST['health_arthritis']) ? 1 : 0,
            'health_respiratory' => isset($_POST['health_respiratory']) ? 1 : 0,
            'health_vision' => isset($_POST['health_vision']) ? 1 : 0,
            'health_hearing' => isset($_POST['health_hearing']) ? 1 : 0,
            'health_dental' => isset($_POST['health_dental']) ? 1 : 0,
            'health_mental' => isset($_POST['health_mental']) ? 1 : 0,
            'health_mobility' => isset($_POST['health_mobility']) ? 1 : 0,
            'health_chronic_pain' => isset($_POST['health_chronic_pain']) ? 1 : 0,
            'health_medication' => isset($_POST['health_medication']) ? 1 : 0,
            'health_nutrition' => isset($_POST['health_nutrition']) ? 1 : 0,
            'health_sleep' => isset($_POST['health_sleep']) ? 1 : 0,
            'health_others' => isset($_POST['health_others']) ? 1 : 0,
            'health_others_specify' => trim($_POST['health_others_specify'] ?? ''),
            'purok_id' => $_POST['purok_id'] ?? '',
        ];

        // Add family member data as arrays
        if (isset($_POST['family_member_name']) && is_array($_POST['family_member_name'])) {
            $data['family_member_name'] = $_POST['family_member_name'];
            $data['family_member_relationship'] = $_POST['family_member_relationship'] ?? [];
            $data['family_member_age'] = $_POST['family_member_age'] ?? [];
            $data['family_member_civil_status'] = $_POST['family_member_civil_status'] ?? [];
            $data['family_member_occupation'] = $_POST['family_member_occupation'] ?? [];
            $data['family_member_income'] = $_POST['family_member_income'] ?? [];
        }

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

        // Household ID is optional, but if specified, relationship must be provided
        if (!empty($data['household_id']) && empty($data['relationship'])) {
            $validation_errors[] = "Relationship to household head is required when household is specified.";
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
function getFormValue($key, $form_data, $default = '')
{
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
function isCheckboxChecked($form_data, $key)
{
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
    <script>
        // Function to calculate age
        function calculateAge() {
            const birthDateInput = document.getElementById('birth_date');
            const ageInput = document.getElementById('age');
            const residentTypeSelect = document.getElementById('residentTypeSelect');
            const validationMsg = document.getElementById('residency_age_validation');
            const yearsOfResidencyInput = document.getElementById('years_of_residency');

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

                // Validate age based on resident type
                const residentType = residentTypeSelect.value;
                let isValid = true;
                let errorMessage = '';

                if (residentType === 'REGULAR' || residentType === 'PWD') {
                    if (age < 18 || age > 59) {
                        isValid = false;
                        errorMessage = 'Age must be between 18 and 59 for Regular/PWD residents';
                    }
                } else if (residentType === 'SENIOR') {
                    if (age < 60) {
                        isValid = false;
                        errorMessage = 'Age must be 60 or above for Senior residents';
                    }
                }

                if (!isValid) {
                    validationMsg.textContent = errorMessage;
                    validationMsg.style.color = 'red';
                    // Clear the age input if invalid
                    ageInput.value = '';
                } else {
                    validationMsg.textContent = '';
                }

                // Validate years of residency
                if (yearsOfResidencyInput) {
                    const yearsOfResidency = parseInt(yearsOfResidencyInput.value);
                    if (!isNaN(yearsOfResidency) && yearsOfResidency > age) {
                        yearsOfResidencyInput.value = age;
                        validationMsg.textContent = `Years of residency cannot exceed age (${age} years)`;
                        validationMsg.style.color = 'red';
                    }
                }
            } else {
                ageInput.value = '';
                validationMsg.textContent = '';
            }
        }

        // Initialize when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            const birthDateInput = document.getElementById('birth_date');
            const residentTypeSelect = document.getElementById('residentTypeSelect');
            const yearsOfResidencyInput = document.getElementById('years_of_residency');
            
            if (birthDateInput) {
                // Add event listener for input change
                birthDateInput.addEventListener('input', calculateAge);
                // Add event listener for change event
                birthDateInput.addEventListener('change', calculateAge);
                // Calculate initial age if birth date exists
                if (birthDateInput.value) {
                    calculateAge();
                }
            }

            // Add event listener for resident type changes
            if (residentTypeSelect) {
                residentTypeSelect.addEventListener('change', calculateAge);
            }

            // Add event listener for years of residency changes
            if (yearsOfResidencyInput) {
                yearsOfResidencyInput.addEventListener('input', calculateAge);
                yearsOfResidencyInput.addEventListener('change', calculateAge);
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
        </div>

        <!-- Regular Resident Form -->
        <div id="add-resident" class="tab-content active bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-3xl font-bold text-blue-800">Add New Resident</h2>
            <div class="mb-6">
                <label class="block text-sm font-medium mb-2">Resident Type</label>
                <select id="residentTypeSelect" name="resident_type" class="border rounded p-2 w-full md:w-1/3 uppercase" form="residentForm">
                    <option value="REGULAR" <?= isSelected('REGULAR', $form_data, 'resident_type') ?: 'selected' ?>>REGULAR</option>
                    <option value="SENIOR" <?= isSelected('SENIOR', $form_data, 'resident_type') ?>>SENIOR CITIZEN</option>
                    <option value="PWD" <?= isSelected('PWD', $form_data, 'resident_type') ?>>PERSON WITH DISABILITY (PWD)</option>
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
                            class="mt-1 block w-full border rounded p-2 uppercase" oninput="this.value = this.value.toUpperCase()" maxlength="5">
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
                        <label class="block text-sm font-medium">Sex *</label>
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
                        <input type="number" name="years_of_residency" id="years_of_residency" required min="0" max="150"
                            value="<?= getFormValue('years_of_residency', $form_data) ?>"
                            class="mt-1 block w-full border rounded p-2"
                            placeholder="Enter number of years">
                        <small class="text-gray-500" id="residency_age_validation"></small>
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
                                    class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                            </div>

                            <div>
                                <label class="block text-sm font-medium">Province</label>
                                <input type="text" name="present_province" value="<?= getFormValue('present_province', $form_data) ?: 'BULACAN' ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
                            </div>

                            <div>
                                <label class="block text-sm font-medium">Region</label>
                                <input type="text" name="present_region" value="<?= getFormValue('present_region', $form_data) ?: 'III' ?>"
                                    class="mt-1 block w-full border rounded p-2 uppercase bg-gray-100" readonly>
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
                            <label class="block text-sm font-medium">Purok</label>
                            <select name="purok_id" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- SELECT PUROK --</option>
                                <?php foreach ($puroks as $purok): ?>
                                    <option value="<?= htmlspecialchars($purok['id']) ?>"
                                        <?= isSelected($purok['id'], $form_data, 'purok_id') ?>>
                                        <?= htmlspecialchars($purok['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium">Household Number (Optional)</label>
                            <select name="household_id" id="household_id_select" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- SELECT HOUSEHOLD --</option>
                                <?php foreach ($households as $household): ?>
                                    <option value="<?= htmlspecialchars($household['household_id']) ?>"
                                        data-purok="<?= htmlspecialchars($household['purok_id']) ?>"
                                        <?= isSelected($household['household_id'], $form_data, 'household_id') ?>>
                                        <?= htmlspecialchars($household['household_number'] ?? $household['household_id']) ?>
                                        <?= $household['purok_name'] ? ' - ' . htmlspecialchars($household['purok_name']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">If household is not listed, create it in the Manage Households tab</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium">Relationship to Head</label>
                            <select name="relationship" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- SELECT RELATIONSHIP --</option>
                                <option value="HEAD" <?= isSelected('HEAD', $form_data, 'relationship') ?>>HEAD</option>
                                <option value="SPOUSE" <?= isSelected('SPOUSE', $form_data, 'relationship') ?>>SPOUSE</option>
                                <option value="CHILD" <?= isSelected('CHILD', $form_data, 'relationship') ?>>CHILD</option>
                                <option value="PARENT" <?= isSelected('PARENT', $form_data, 'relationship') ?>>PARENT</option>
                                <option value="SIBLING" <?= isSelected('SIBLING', $form_data, 'relationship') ?>>SIBLING</option>
                                <option value="GRANDCHILD" <?= isSelected('GRANDCHILD', $form_data, 'relationship') ?>>GRANDCHILD</option>
                                <option value="OTHER RELATIVE" <?= isSelected('OTHER RELATIVE', $form_data, 'relationship') ?>>OTHER RELATIVE</option>
                                <option value="NON-RELATIVE" <?= isSelected('NON-RELATIVE', $form_data, 'relationship') ?>>NON-RELATIVE</option>
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
                                    <input type="checkbox" name="income_own_earnings" value="1" <?= isCheckboxChecked($form_data, 'income_own_earnings') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Own Earnings, Salaries/Wages</span>
                                </label>
                            </div>

                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="income_own_pension" value="1" <?= isCheckboxChecked($form_data, 'income_own_pension') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Own Pension</span>
                                </label>
                                <input type="text" name="income_own_pension_amount" placeholder="Amount"
                                    value="<?= getFormValue('income_own_pension_amount', $form_data) ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>

                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_stocks_dividends" value="1" <?= isCheckboxChecked($form_data, 'income_stocks_dividends') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Stocks/Dividends</span>
                                </label>
                            </div>

                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_dependent_on_children" value="1" <?= isCheckboxChecked($form_data, 'income_dependent_on_children') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Dependent on Children/Relatives</span>
                                </label>
                            </div>

                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_spouse_salary" value="1" <?= isCheckboxChecked($form_data, 'income_spouse_salary') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Spouse's Salary</span>
                                </label>
                            </div>

                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="income_insurances" value="1" <?= isCheckboxChecked($form_data, 'income_insurances') ?> class="form-checkbox">
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
                                        <th class="border border-gray-200 px-4 py-2 text-sm">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="familyMembersTable">
                                    <!-- Family member rows will be added here -->
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
                                    <input type="checkbox" name="asset_house" value="1" <?= isCheckboxChecked($form_data, 'asset_house') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">House</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_house_lot" value="1" <?= isCheckboxChecked($form_data, 'asset_house_lot') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">House & Lot</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_farmland" value="1" <?= isCheckboxChecked($form_data, 'asset_farmland') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Farmland</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_fishponds_resorts" value="1" <?= isCheckboxChecked($form_data, 'asset_fishponds_resorts') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Fishponds/Resorts</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_commercial_building" value="1" <?= isCheckboxChecked($form_data, 'asset_commercial_building') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Commercial Building</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="asset_lot" value="1" <?= isCheckboxChecked($form_data, 'asset_lot') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Lot</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="asset_others" value="1" <?= isCheckboxChecked($form_data, 'asset_others') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="asset_others_specify" placeholder="Specify"
                                    value="<?= getFormValue('asset_others_specify', $form_data) ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
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
                                    <input type="checkbox" name="skill_medical" value="1" <?= isCheckboxChecked($form_data, 'skill_medical') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Medical</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_teaching" value="1" <?= isCheckboxChecked($form_data, 'skill_teaching') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Teaching</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_legal_services" value="1" <?= isCheckboxChecked($form_data, 'skill_legal_services') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Legal Services</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_dental" value="1" <?= isCheckboxChecked($form_data, 'skill_dental') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Dental</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_counseling" value="1" <?= isCheckboxChecked($form_data, 'skill_counseling') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Counseling</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_evangelization" value="1" <?= isCheckboxChecked($form_data, 'skill_evangelization') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Evangelization</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_farming" value="1" <?= isCheckboxChecked($form_data, 'skill_farming') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Farming</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_fishing" value="1" <?= isCheckboxChecked($form_data, 'skill_fishing') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Fishing</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_cooking" value="1" <?= isCheckboxChecked($form_data, 'skill_cooking') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Cooking</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_vocational" value="1" <?= isCheckboxChecked($form_data, 'skill_vocational') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Vocational</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_arts" value="1" <?= isCheckboxChecked($form_data, 'skill_arts') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Arts</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="skill_engineering" value="1" <?= isCheckboxChecked($form_data, 'skill_engineering') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Engineering</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="skill_others" value="1" <?= isCheckboxChecked($form_data, 'skill_others') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="skill_others_specify" placeholder="Specify"
                                    value="<?= getFormValue('skill_others_specify', $form_data) ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                            </div>
                        </div>
                    </div>

                    <!-- Involvement in Community Activities -->
                    <div class="mt-6 border-t border-gray-200 pt-4">
                        <h3 class="font-semibold text-lg mb-4">Involvement in Community Activities (Check all applicable)</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_medical" value="1" <?= isCheckboxChecked($form_data, 'involvement_medical') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Medical</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_resource_volunteer" value="1" <?= isCheckboxChecked($form_data, 'involvement_resource_volunteer') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Resource Volunteer</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_community_beautification" value="1" <?= isCheckboxChecked($form_data, 'involvement_community_beautification') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Community Beautification</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_community_leader" value="1" <?= isCheckboxChecked($form_data, 'involvement_community_leader') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Community/Organizational Leader</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_dental" value="1" <?= isCheckboxChecked($form_data, 'involvement_dental') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Dental</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_friendly_visits" value="1" <?= isCheckboxChecked($form_data, 'involvement_friendly_visits') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Friendly Visits</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_neighborhood_support" value="1" <?= isCheckboxChecked($form_data, 'involvement_neighborhood_support') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Neighborhood Support Services</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_religious" value="1" <?= isCheckboxChecked($form_data, 'involvement_religious') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Religious</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_counselling" value="1" <?= isCheckboxChecked($form_data, 'involvement_counselling') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Counselling/Referral</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_sponsorship" value="1" <?= isCheckboxChecked($form_data, 'involvement_sponsorship') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Sponsorship</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="involvement_legal_services" value="1" <?= isCheckboxChecked($form_data, 'involvement_legal_services') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Legal Services</span>
                                </label>
                            </div>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center whitespace-nowrap">
                                    <input type="checkbox" name="involvement_others" value="1" <?= isCheckboxChecked($form_data, 'involvement_others') ?> class="form-checkbox">
                                    <span class="ml-2 text-sm font-medium">Others</span>
                                </label>
                                <input type="text" name="involvement_others_specify" placeholder="Specify"
                                    value="<?= getFormValue('involvement_others_specify', $form_data) ?>"
                                    class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
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
                                        <input type="checkbox" name="problem_lack_income" value="1" <?= isCheckboxChecked($form_data, 'problem_lack_income') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Lack of Income/Resources</span>
                                    </label>
                                </div>
                                <div>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="problem_loss_income" value="1" <?= isCheckboxChecked($form_data, 'problem_loss_income') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Loss of Income/Resources</span>
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
                                        <input type="checkbox" name="problem_condition_illness" value="1" <?= isCheckboxChecked($form_data, 'problem_condition_illness') ?> class="form-checkbox">
                                        <span class="ml-2 text-sm font-medium">Condition/Illness</span>
                                    </label>
                                    <input type="text" name="problem_condition_illness_specify" placeholder="Specify"
                                        value="<?= getFormValue('problem_condition_illness_specify', $form_data) ?>"
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                </div>

                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center whitespace-nowrap">
                                        <span class="text-sm font-medium">With Maintenance</span>
                                    </label>
                                    <label class="inline-flex items-center ml-2">
                                        <input type="radio" name="problem_with_maintenance" value="YES" <?= isChecked('YES', $form_data, 'problem_with_maintenance') ?> class="form-radio">
                                        <span class="ml-1 text-sm">YES</span>
                                    </label>
                                    <input type="text" name="problem_with_maintenance_specify" placeholder="Specify"
                                        value="<?= getFormValue('problem_with_maintenance_specify', $form_data) ?>"
                                        class="flex-1 border rounded p-1 text-sm uppercase" oninput="this.value = this.value.toUpperCase()">
                                    <label class="inline-flex items-center ml-2">
                                        <input type="radio" name="problem_with_maintenance" value="NO" <?= isChecked('NO', $form_data, 'problem_with_maintenance') ?> class="form-radio">
                                        <span class="ml-1 text-sm">NO</span>
                                    </label>
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
                        <div class="mb-6">
                            <h4 class="font-semibold text-md mb-3">F. Other Specific Needs</h4>
                            <div class="pl-4">
                                <textarea name="other_specific_needs" rows="3" class="w-full border rounded p-2 uppercase"
                                    oninput="this.value = this.value.toUpperCase()"><?= getFormValue('other_specific_needs', $form_data) ?></textarea>
                            </div>
                        </div>

                        <!-- Health Concerns and Service Needs sections removed -->

                        <!-- Submit Button -->
                        <div class="mt-8 flex justify-center">
                            <button type="submit" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg shadow-lg transition-colors">
                                Save Resident
                            </button>
                        </div>
                    </div>
            </form>
        </div>
    </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Display SweetAlert for success and error messages
            <?php if ($add_success): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: '<?= addslashes($add_success) ?>',
                    confirmButtonColor: '#3085d6'
                });
            <?php endif; ?>

            <?php if ($add_error): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '<?= addslashes($add_error) ?>',
                    confirmButtonColor: '#3085d6'
                });
            <?php endif; ?>

            <?php if (isset($person_id)): ?>
                // Code for person_id related functionality
            <?php endif; ?>

            // Auto-capitalize all inputs
            document.querySelectorAll('input[type="text"]').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });

            // Years of residency cannot exceed age constraint
            const birthDateInput = document.getElementById('birth_date');
            const residencyInput = document.getElementById('years_of_residency');
            const validationMsg = document.getElementById('residency_age_validation');

            function updateResidencyMaximum() {
                if (birthDateInput && birthDateInput.value && residencyInput) {
                    // Calculate age based on birth date
                    const birthDate = new Date(birthDateInput.value);
                    const today = new Date();
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const monthDiff = today.getMonth() - birthDate.getMonth();

                    // Adjust age if birthday hasn't occurred yet this year
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }

                    // Update max attribute of years_of_residency
                    residencyInput.setAttribute('max', age);

                    // Check if current value exceeds age
                    if (parseInt(residencyInput.value) > age) {
                        residencyInput.value = age;
                        validationMsg.textContent = `Maximum years of residency is ${age} (cannot exceed age)`;
                    } else {
                        validationMsg.textContent = ``;
                    }
                }
            }

            // Add event listeners
            if (birthDateInput && residencyInput) {
                birthDateInput.addEventListener('change', updateResidencyMaximum);
                residencyInput.addEventListener('input', function() {
                    updateResidencyMaximum();
                });

                // Run on page load to initialize
                updateResidencyMaximum();
            }

            // Toggle text fields next to checkboxes
            function setupCheckboxTextFieldPair(checkboxName, textFieldName) {
                const checkbox = document.querySelector(`input[name="${checkboxName}"]`);
                const textField = document.querySelector(`input[name="${textFieldName}"]`);

                if (checkbox && textField) {
                    // Set initial state
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

            // Toggle text fields based on radio button selection
            function setupRadioTextFieldPair(radioGroupName, radioValue, textFieldName) {
                const radioButtons = document.querySelectorAll(`input[name="${radioGroupName}"]`);
                const textField = document.querySelector(`input[name="${textFieldName}"]`);

                if (radioButtons.length && textField) {
                    // Find the specific radio button that should enable the text field
                    const targetRadio = document.querySelector(`input[name="${radioGroupName}"][value="${radioValue}"]`);

                    // Set initial state
                    const isEnabled = targetRadio && targetRadio.checked;
                    textField.disabled = !isEnabled;

                    // Add event listeners to all radio buttons in the group
                    radioButtons.forEach(radio => {
                        radio.addEventListener('change', function() {
                            // Enable text field only when the specific radio value is selected
                            const shouldEnable = this.value === radioValue;
                            textField.disabled = !shouldEnable;

                            // Clear the text field if it's being disabled
                            if (!shouldEnable) {
                                textField.value = '';
                            }
                        });
                    });
                }
            }

            // Setup all checkbox-text field pairs
            // Income sources section
            setupCheckboxTextFieldPair('income_own_pension', 'income_own_pension_amount');
            setupCheckboxTextFieldPair('income_spouse_pension', 'income_spouse_pension_amount');
            setupCheckboxTextFieldPair('income_others', 'income_others_specify');

            // Assets & Properties section
            setupCheckboxTextFieldPair('asset_others', 'asset_others_specify');

            // Living/Residing With section
            setupCheckboxTextFieldPair('living_others', 'living_others_specify');

            // Areas of Specialization/Skills section
            setupCheckboxTextFieldPair('skill_others', 'skill_others_specify');

            // Involvement in Community Activities section
            setupCheckboxTextFieldPair('involvement_others', 'involvement_others_specify');

            // Problem Categories sections
            // Economic problems
            setupCheckboxTextFieldPair('problem_skills_training', 'problem_skills_training_specify');
            setupCheckboxTextFieldPair('problem_livelihood', 'problem_livelihood_specify');
            setupCheckboxTextFieldPair('problem_economic_others', 'problem_economic_others_specify');

            // Other problem categories
            setupCheckboxTextFieldPair('problem_social_others', 'problem_social_others_specify');
            setupCheckboxTextFieldPair('problem_health_others', 'problem_health_others_specify');
            setupCheckboxTextFieldPair('problem_housing_others', 'problem_housing_others_specify');
            setupCheckboxTextFieldPair('problem_community_others', 'problem_community_others_specify');

            // Health Condition section
            setupCheckboxTextFieldPair('problem_condition_illness', 'problem_condition_illness_specify');
            setupRadioTextFieldPair('problem_with_maintenance', 'YES', 'problem_with_maintenance_specify');

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
                            // Delete without confirmation
                            row.remove();
                        } else {
                            // If it's the last row, just clear the inputs instead of removing
                            row.querySelectorAll('input').forEach(input => {
                                input.value = '';
                            });
                            row.querySelectorAll('select').forEach(select => {
                                select.selectedIndex = 0;
                            });
                            // Replace standard alert with SweetAlert
                            Swal.fire({
                                icon: 'info',
                                title: 'Cannot Delete',
                                text: 'At least one family member row must remain. Values have been cleared instead.',
                                confirmButtonColor: '#3085d6'
                            });
                        }
                    });
                });
            }

            // Setup delete buttons on page load
            setupFamilyMemberDeleteButtons();

            // Function to populate family member rows with existing data
            function populateFamilyMemberRows(familyMembers) {
                if (!familyMembers || !familyMembers.length || !familyMembersTable) return;

                // Clear existing rows except the first one (template)
                const rows = familyMembersTable.querySelectorAll('tr');
                if (rows.length > 1) {
                    for (let i = rows.length - 1; i > 0; i--) {
                        rows[i].remove();
                    }
                }

                // Clear the first row (template)
                const firstRow = familyMembersTable.querySelector('.family-member-row');
                if (firstRow) {
                    firstRow.querySelectorAll('input').forEach(input => {
                        input.value = '';
                    });
                    firstRow.querySelectorAll('select').forEach(select => {
                        select.selectedIndex = 0;
                    });
                }

                // Add data to the first row
                if (familyMembers.length > 0 && firstRow) {
                    const member = familyMembers[0];

                    const nameInput = firstRow.querySelector('input[name="family_member_name[]"]');
                    const relationshipInput = firstRow.querySelector('input[name="family_member_relationship[]"]');
                    const ageInput = firstRow.querySelector('input[name="family_member_age[]"]');
                    const civilStatusSelect = firstRow.querySelector('select[name="family_member_civil_status[]"]');
                    const occupationInput = firstRow.querySelector('input[name="family_member_occupation[]"]');
                    const incomeInput = firstRow.querySelector('input[name="family_member_income[]"]');

                    if (nameInput) nameInput.value = member.name || '';
                    if (relationshipInput) relationshipInput.value = member.relationship || '';
                    if (ageInput) ageInput.value = member.age || '';
                    if (civilStatusSelect) {
                        Array.from(civilStatusSelect.options).forEach((option, index) => {
                            if (option.value === member.civil_status) {
                                civilStatusSelect.selectedIndex = index;
                            }
                        });
                    }
                    if (occupationInput) occupationInput.value = member.occupation || '';
                    if (incomeInput) incomeInput.value = member.monthly_income || '';
                }

                // Add additional rows for remaining family members
                for (let i = 1; i < familyMembers.length; i++) {
                    const member = familyMembers[i];

                    // Clone the first row as a template
                    const newRow = firstRow.cloneNode(true);

                    const nameInput = newRow.querySelector('input[name="family_member_name[]"]');
                    const relationshipInput = newRow.querySelector('input[name="family_member_relationship[]"]');
                    const ageInput = newRow.querySelector('input[name="family_member_age[]"]');
                    const civilStatusSelect = newRow.querySelector('select[name="family_member_civil_status[]"]');
                    const occupationInput = newRow.querySelector('input[name="family_member_occupation[]"]');
                    const incomeInput = newRow.querySelector('input[name="family_member_income[]"]');

                    if (nameInput) nameInput.value = member.name || '';
                    if (relationshipInput) relationshipInput.value = member.relationship || '';
                    if (ageInput) ageInput.value = member.age || '';
                    if (civilStatusSelect) {
                        Array.from(civilStatusSelect.options).forEach((option, index) => {
                            if (option.value === member.civil_status) {
                                civilStatusSelect.selectedIndex = index;
                            }
                        });
                    }
                    if (occupationInput) occupationInput.value = member.occupation || '';
                    if (incomeInput) incomeInput.value = member.monthly_income || '';

                    // Add the new row to the table
                    familyMembersTable.appendChild(newRow);
                }

                // Setup delete buttons for all rows
                setupFamilyMemberDeleteButtons();
            }

            // If family members data is available in PHP, populate the rows
            <?php if (isset($form_data['family_members']) && is_array($form_data['family_members'])): ?>
                populateFamilyMemberRows(<?= json_encode($form_data['family_members']) ?>);
            <?php endif; ?>

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

                    // Add an event listener for the uppercase conversion
                    newRow.querySelectorAll('input[type="text"]').forEach(input => {
                        input.addEventListener('input', function() {
                            this.value = this.value.toUpperCase();
                        });
                    });

                    // Add the new row to the table
                    familyMembersTable.appendChild(newRow);

                    // Setup delete button for the new row
                    setupFamilyMemberDeleteButtons();
                });
            }

            // Same as Present Address checkbox functionality
            const sameAsPresentCheckbox = document.getElementById('sameAsPresent');
            if (sameAsPresentCheckbox) {
                sameAsPresentCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        // Copy present address fields to permanent address fields
                        document.querySelector('input[name="permanent_house_no"]').value = document.querySelector('input[name="present_house_no"]').value;
                        document.querySelector('input[name="permanent_street"]').value = document.querySelector('input[name="present_street"]').value;
                        document.querySelector('input[name="permanent_barangay"]').value = document.querySelector('input[name="present_barangay"]').value;
                        document.querySelector('input[name="permanent_municipality"]').value = document.querySelector('input[name="present_municipality"]').value;
                        document.querySelector('input[name="permanent_province"]').value = document.querySelector('input[name="present_province"]').value;
                        document.querySelector('input[name="permanent_region"]').value = document.querySelector('input[name="present_region"]').value;
                    } else {
                        // Clear permanent address fields
                        document.querySelector('input[name="permanent_house_no"]').value = '';
                        document.querySelector('input[name="permanent_street"]').value = '';
                        document.querySelector('input[name="permanent_barangay"]').value = '';
                        document.querySelector('input[name="permanent_municipality"]').value = '';
                        document.querySelector('input[name="permanent_province"]').value = '';
                        document.querySelector('input[name="permanent_region"]').value = '';
                    }
                });
            }

            // --- DYNAMIC HOUSEHOLD SELECT BASED ON PUROK ---
            (function() {
                // Build a mapping of purok_id to households
                const householdsByPurok = {};
                <?php foreach ($households as $household): ?>
                    if (!householdsByPurok['<?= $household['purok_id'] ?>']) householdsByPurok['<?= $household['purok_id'] ?>'] = [];
                    householdsByPurok['<?= $household['purok_id'] ?>'].push({
                        id: '<?= htmlspecialchars($household['household_id']) ?>',
                        number: '<?= htmlspecialchars($household['household_number'] ?? $household['household_id']) ?>',
                        purok_name: '<?= htmlspecialchars($household['purok_name']) ?>'
                    });
                <?php endforeach; ?>

                const purokSelect = document.querySelector('select[name="purok_id"]');
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
            })();
        });
    </script>
</body>

</html>