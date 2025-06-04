<?php

require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../components/header.php"; // header.php should handle session_start()

// Define User Roles
if (!defined('ROLE_CAPTAIN')) define('ROLE_CAPTAIN', 3);
if (!defined('ROLE_SECRETARY')) define('ROLE_SECRETARY', 4);
if (!defined('ROLE_TREASURER')) define('ROLE_TREASURER', 5);
if (!defined('ROLE_COUNCILOR')) define('ROLE_COUNCILOR', 6);
if (!defined('ROLE_CHIEF')) define('ROLE_CHIEF', 7);
if (!defined('ROLE_HEALTH_WORKER')) define('ROLE_HEALTH_WORKER', 9);


// Check admin permissions - Updated RBAC
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php");
    exit;
}

$current_role_id = $_SESSION['role_id'] ?? 0;

// Roles with full management access for census-related data
$canManageRoles = [ROLE_CAPTAIN, ROLE_CHIEF, ROLE_HEALTH_WORKER];
// Roles with view access
$canViewRoles = [ROLE_CAPTAIN, ROLE_CHIEF, ROLE_HEALTH_WORKER, ROLE_SECRETARY, ROLE_TREASURER, ROLE_COUNCILOR];

$hasFullAccess = in_array($current_role_id, $canManageRoles);
$canViewPage = in_array($current_role_id, $canViewRoles);

if (!$canViewPage) {
    // Instead of redirecting to login, show access denied or redirect to a dashboard
    echo "<div class='container mx-auto p-4'><div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative' role='alert'>
          <strong class='font-bold'>Access Denied!</strong>
          <span class='block sm:inline'>You do not have permission to view this page.</span>
          </div></div>";
    // Optionally include footer or a link back
    exit;
}


$current_admin_id = $_SESSION['user_id'];
$barangay_id = $_SESSION['barangay_id'];

// Fetch households for selection
$stmt = $pdo->prepare("
    SELECT h.id AS household_id, h.household_number, h.purok_id, p.name as purok_name
    FROM households h
    LEFT JOIN purok p ON h.purok_id = p.id
    WHERE h.barangay_id = ? 
    ORDER BY h.purok_id, h.household_number
");
$stmt->execute([$barangay_id]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group households by purok for JavaScript
$households_by_purok = [];
foreach ($households as $household) {
    $purok_id = $household['purok_id'];
    if (!isset($households_by_purok[$purok_id])) {
        $households_by_purok[$purok_id] = [];
    }
    $households_by_purok[$purok_id][] = $household;
}

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

if ($hasFullAccess && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
            'permanent_barangay' => trim($_POST['permanent_barangay'] ?? ''),
            'permanent_municipality' => trim($_POST['permanent_municipality'] ?? ''),
            'permanent_province' => trim($_POST['permanent_province'] ?? ''),
            'permanent_region' => trim($_POST['permanent_region'] ?? ''),
            'household_id' => isset($_POST['household_id']) && $_POST['household_id'] !== '' ? (int)$_POST['household_id'] : null,
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
            'problem_high_rent' => isset($_POST['problem_high_rent']) ? 1 : 0,
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
            const ageValidationMsg = document.getElementById('age_validation');
            const yearsOfResidencyInput = document.getElementById('years_of_residency');
            const residencyValidationMsg = document.getElementById('residency_age_validation');

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
                    ageValidationMsg.textContent = errorMessage;
                    ageValidationMsg.style.color = 'red';
                    // Clear the age input if invalid
                    ageInput.value = '';
                } else {
                    ageValidationMsg.textContent = '';
                }

                // Validate years of residency
                if (yearsOfResidencyInput) {
                    const yearsOfResidency = parseInt(yearsOfResidencyInput.value);
                    if (!isNaN(yearsOfResidency) && yearsOfResidency > age) {
                        yearsOfResidencyInput.value = age;
                        residencyValidationMsg.textContent = `Years of residency cannot exceed age (${age} years)`;
                        residencyValidationMsg.style.color = 'red';
                    } else {
                        residencyValidationMsg.textContent = '';
                    }
                }
            } else {
                ageInput.value = '';
                ageValidationMsg.textContent = '';
                residencyValidationMsg.textContent = '';
            }
        }

        // Initialize when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            const birthDateInput = document.getElementById('birth_date');
            const residentTypeSelect = document.getElementById('residentTypeSelect');
            const yearsOfResidencyInput = document.getElementById('years_of_residency');
            const purokSelect = document.querySelector('select[name="purok_id"]');
            const householdSelect = document.getElementById('household_id_select');
            const relationshipSelect = document.querySelector('select[name="relationship"]');
            const isHouseholdHeadCheckbox = document.querySelector('input[name="is_household_head"]');
            
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

            // Add validation for household-related fields
            function validateHouseholdFields() {
                let isValid = true;
                let errorMessage = '';

                // Check if purok is selected
                if (!purokSelect.value) {
                    isValid = false;
                    errorMessage = 'Please select a purok';
                    purokSelect.classList.add('border-red-500');
                } else {
                    purokSelect.classList.remove('border-red-500');
                }

                // Check if household is selected
                if (!householdSelect.value) {
                    isValid = false;
                    errorMessage = 'Please select a household number';
                    householdSelect.classList.add('border-red-500');
                } else {
                    householdSelect.classList.remove('border-red-500');
                }

                // Check if relationship is selected
                if (!relationshipSelect.value) {
                    isValid = false;
                    errorMessage = 'Please select relationship to household head';
                    relationshipSelect.classList.add('border-red-500');
                } else {
                    relationshipSelect.classList.remove('border-red-500');
                }

                // If not valid, show error message
                if (!isValid) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Missing Information',
                        text: errorMessage,
                        confirmButtonColor: '#3085d6'
                    });
                }

                return isValid;
            }

            // Add event listeners for household-related fields
            if (purokSelect) {
                purokSelect.addEventListener('change', function() {
                    this.classList.remove('border-red-500');
                });
            }

            if (householdSelect) {
                householdSelect.addEventListener('change', function() {
                    this.classList.remove('border-red-500');
                });
            }

            if (relationshipSelect) {
                relationshipSelect.addEventListener('change', function() {
                    this.classList.remove('border-red-500');
                });
            }

            // Add form submission validation
            const form = document.getElementById('residentForm');
            if (form) {
                <?php if ($hasFullAccess): ?>
                form.addEventListener('submit', function(e) {
                    if (!validateHouseholdFields()) {
                        e.preventDefault();
                    }
                });
                <?php else: ?>
                // Disable form submission if not full access
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Access Denied',
                        text: 'You do not have permission to save resident data.',
                        confirmButtonColor: '#d33'
                    });
                });
                // Optionally disable all form fields
                form.querySelectorAll('input, select, textarea, button').forEach(el => el.disabled = true);
                <?php endif; ?>
            }
        });
    </script>
</body>

</html>