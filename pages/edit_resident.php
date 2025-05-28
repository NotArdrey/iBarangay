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
$stmt->execute([$barangay_id]);
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
require_once "../pages/header.php";

// --- ADD RESIDENT LOGIC ---
$add_error = '';
$add_success = '';
$form_data = []; // Store form data for repopulation on error

// Pre-fill form for editing
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    // Fetch main person and household info
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            hm.household_id, 
            hm.relationship_type_id, 
            hm.is_household_head, 
            rt.name as relationship_name, 
            h.purok_id,
            a_present.id as present_address_id,
            a_present.house_no as present_house_no,
            a_present.street as present_street,
            a_present.municipality as present_municipality,
            a_present.province as present_province,
            a_present.region as present_region,
            a_permanent.id as permanent_address_id,
            a_permanent.house_no as permanent_house_no,
            a_permanent.street as permanent_street,
            a_permanent.municipality as permanent_municipality,
            a_permanent.province as permanent_province,
            a_permanent.region as permanent_region,
            pi.osca_id, pi.gsis_id, pi.sss_id, pi.tin_id, pi.philhealth_id,
            pi.other_id_type, pi.other_id_number
        FROM persons p
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        LEFT JOIN addresses a_present ON p.id = a_present.person_id AND a_present.address_type = 'present'
        LEFT JOIN addresses a_permanent ON p.id = a_permanent.person_id AND a_permanent.address_type = 'permanent'
        LEFT JOIN person_identification pi ON p.id = pi.person_id
        WHERE p.id = ?
    ");
    $stmt->execute([$edit_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($person) {
        $form_data = $person;
        
        // Fetch family composition
        $stmt = $pdo->prepare("
            SELECT * FROM family_composition 
            WHERE person_id = ?
            ORDER BY id
        ");
        $stmt->execute([$edit_id]);
        $form_data['family_members'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $edit_error = "Resident not found.";
    }
}

// Check for session messages
if (isset($_SESSION['error'])) {
    $edit_error = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isset($_SESSION['success'])) {
    $edit_success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['edit'])) {
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
            'problem_unemployment' => isset($_POST['problem_unemployment']) ? 1 : 0,
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
    <title>Edit Resident - <?php echo htmlspecialchars($barangay['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>

<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-lg shadow-md p-6">
                <h1 class="text-2xl font-bold mb-6">Edit Resident Information</h1>
                
                <?php if ($edit_error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <?php echo $edit_error; ?>
            </div>
                <?php endif; ?>
                
                <?php if ($edit_success): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        <?php echo $edit_success; ?>
            </div>
                <?php endif; ?>

                <?php if (isset($form_data) && !empty($form_data)): ?>
                    <form method="POST" action="?edit=<?php echo $edit_id; ?>" class="space-y-6">
                        <!-- Personal Information -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h2 class="text-lg font-semibold mb-4">Personal Information</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">First Name *</label>
                                    <input type="text" name="first_name" value="<?php echo getFormValue('first_name', $form_data); ?>" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Middle Name</label>
                                    <input type="text" name="middle_name" value="<?php echo getFormValue('middle_name', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Last Name *</label>
                                    <input type="text" name="last_name" value="<?php echo getFormValue('last_name', $form_data); ?>" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Suffix</label>
                                    <input type="text" name="suffix" value="<?php echo getFormValue('suffix', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Birth Date *</label>
                                    <input type="date" name="birth_date" value="<?php echo getFormValue('birth_date', $form_data); ?>" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Birth Place</label>
                                    <input type="text" name="birth_place" value="<?php echo getFormValue('birth_place', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Gender *</label>
                                    <select name="gender" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">Select Gender</option>
                                        <option value="M" <?php echo isSelected('M', $form_data, 'gender'); ?>>Male</option>
                                        <option value="F" <?php echo isSelected('F', $form_data, 'gender'); ?>>Female</option>
                        </select>
                    </div>
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Civil Status *</label>
                                    <select name="civil_status" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">Select Civil Status</option>
                                        <option value="Single" <?php echo isSelected('Single', $form_data, 'civil_status'); ?>>Single</option>
                                        <option value="Married" <?php echo isSelected('Married', $form_data, 'civil_status'); ?>>Married</option>
                                        <option value="Widowed" <?php echo isSelected('Widowed', $form_data, 'civil_status'); ?>>Widowed</option>
                                        <option value="Separated" <?php echo isSelected('Separated', $form_data, 'civil_status'); ?>>Separated</option>
                                        <option value="Divorced" <?php echo isSelected('Divorced', $form_data, 'civil_status'); ?>>Divorced</option>
                        </select>
                        </div>
                    </div>
                </div>

                        <!-- Contact Information -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h2 class="text-lg font-semibold mb-4">Contact Information</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                                    <label class="block text-sm font-medium text-gray-700">Contact Number</label>
                                    <input type="tel" name="contact_number" value="<?php echo getFormValue('contact_number', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    </div>
                    </div>

                    <!-- Present Address -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h2 class="text-lg font-semibold mb-4">Present Address</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">House No.</label>
                                    <input type="text" name="present_house_no" value="<?php echo getFormValue('present_house_no', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Street</label>
                                    <input type="text" name="present_street" value="<?php echo getFormValue('present_street', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Municipality</label>
                                    <input type="text" name="present_municipality" value="<?php echo getFormValue('present_municipality', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Province</label>
                                    <input type="text" name="present_province" value="<?php echo getFormValue('present_province', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Region</label>
                                    <input type="text" name="present_region" value="<?php echo getFormValue('present_region', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Permanent Address -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h2 class="text-lg font-semibold mb-4">Permanent Address</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">House No.</label>
                                    <input type="text" name="permanent_house_no" value="<?php echo getFormValue('permanent_house_no', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Street</label>
                                    <input type="text" name="permanent_street" value="<?php echo getFormValue('permanent_street', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Municipality</label>
                                    <input type="text" name="permanent_municipality" value="<?php echo getFormValue('permanent_municipality', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Province</label>
                                    <input type="text" name="permanent_province" value="<?php echo getFormValue('permanent_province', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">Region</label>
                                    <input type="text" name="permanent_region" value="<?php echo getFormValue('permanent_region', $form_data); ?>"
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
                                    <select name="household_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">Select Household</option>
                                <?php foreach ($households as $household): ?>
                                            <option value="<?php echo $household['household_id']; ?>" 
                                                <?php echo isSelected($household['household_id'], $form_data, 'household_id'); ?>>
                                                Household #<?php echo $household['household_id']; ?> 
                                                (<?php echo htmlspecialchars($household['purok_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                                    <label class="block text-sm font-medium text-gray-700">Relationship to Household Head</label>
                                    <input type="text" name="relationship" value="<?php echo getFormValue('relationship_name', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Is Household Head</label>
                                    <div class="mt-2">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_household_head" value="1"
                                                <?php echo isCheckboxChecked($form_data, 'is_household_head'); ?>
                                                class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            <span class="ml-2">Yes</span>
                            </label>
                        </div>
                    </div>
                        </div>
                    </div>

                        <!-- Government IDs -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h2 class="text-lg font-semibold mb-4">Government IDs</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">OSCA ID</label>
                                    <input type="text" name="osca_id" value="<?php echo getFormValue('osca_id', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">GSIS ID</label>
                                    <input type="text" name="gsis_id" value="<?php echo getFormValue('gsis_id', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">SSS ID</label>
                                    <input type="text" name="sss_id" value="<?php echo getFormValue('sss_id', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">TIN ID</label>
                                    <input type="text" name="tin_id" value="<?php echo getFormValue('tin_id', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                    <label class="block text-sm font-medium text-gray-700">PhilHealth ID</label>
                                    <input type="text" name="philhealth_id" value="<?php echo getFormValue('philhealth_id', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Other ID Type</label>
                                    <input type="text" name="other_id_type" value="<?php echo getFormValue('other_id_type', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Other ID Number</label>
                                    <input type="text" name="other_id_number" value="<?php echo getFormValue('other_id_number', $form_data); ?>"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                        <!-- Family Composition -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h2 class="text-lg font-semibold mb-4">Family Composition</h2>
                            <div id="family-members">
                                <?php if (isset($form_data['family_members']) && !empty($form_data['family_members'])): ?>
                                    <?php foreach ($form_data['family_members'] as $index => $member): ?>
                                        <div class="family-member grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Name</label>
                                                <input type="text" name="family_member_name[]" value="<?php echo htmlspecialchars($member['name']); ?>"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Relationship</label>
                                                <input type="text" name="family_member_relationship[]" value="<?php echo htmlspecialchars($member['relationship']); ?>"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Age</label>
                                                <input type="number" name="family_member_age[]" value="<?php echo htmlspecialchars($member['age']); ?>"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Civil Status</label>
                                                <input type="text" name="family_member_civil_status[]" value="<?php echo htmlspecialchars($member['civil_status']); ?>"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Occupation</label>
                                                <input type="text" name="family_member_occupation[]" value="<?php echo htmlspecialchars($member['occupation']); ?>"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Monthly Income</label>
                                                <input type="text" name="family_member_income[]" value="<?php echo htmlspecialchars($member['monthly_income']); ?>"
                                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" onclick="addFamilyMember()" class="mt-4 bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                                Add Family Member
                                            </button>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end space-x-4">
                            <a href="residents.php" class="bg-gray-500 text-white px-6 py-2 rounded hover:bg-gray-600">Cancel</a>
                            <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">
                                Update Resident
                        </button>
                    </div>
                    </form>
                <?php else: ?>
                    <div class="text-center py-8">
                        <p class="text-gray-600">No resident data found.</p>
                        <a href="residents.php" class="mt-4 inline-block bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">
                            Back to Residents
                        </a>
                            </div>
                <?php endif; ?>
                            </div>
                        </div>
                    </div>

    <script>
        function addFamilyMember() {
            const familyMembers = document.getElementById('family-members');
            const newMember = document.createElement('div');
            newMember.className = 'family-member grid grid-cols-1 md:grid-cols-2 gap-4 mb-4';
            newMember.innerHTML = `
                            <div>
                    <label class="block text-sm font-medium text-gray-700">Name</label>
                    <input type="text" name="family_member_name[]"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                    <label class="block text-sm font-medium text-gray-700">Relationship</label>
                    <input type="text" name="family_member_relationship[]"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                    <label class="block text-sm font-medium text-gray-700">Age</label>
                    <input type="number" name="family_member_age[]"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                    <label class="block text-sm font-medium text-gray-700">Civil Status</label>
                    <input type="text" name="family_member_civil_status[]"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                    <label class="block text-sm font-medium text-gray-700">Occupation</label>
                    <input type="text" name="family_member_occupation[]"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                    <label class="block text-sm font-medium text-gray-700">Monthly Income</label>
                    <input type="text" name="family_member_income[]"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
            `;
            familyMembers.appendChild(newMember);
        }
    </script>
</body>

</html>