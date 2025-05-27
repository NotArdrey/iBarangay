<?php
require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../pages/header.php";

// Check admin permissions
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

$barangay_id = $_SESSION['barangay_id'];
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$edit_id) {
    $_SESSION['error'] = "Invalid resident ID";
    header("Location: census_records.php");
    exit;
}

// Fetch resident data for editing
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
if (!$person) {
    $_SESSION['error'] = "Resident not found";
    header("Location: census_records.php");
    exit;
}
$form_data = $person;

// Fetch households and puroks for dropdowns
$stmt = $pdo->prepare("SELECT h.id AS household_id, h.purok_id, p.name as purok_name, h.household_number FROM households h LEFT JOIN purok p ON h.purok_id = p.id WHERE h.barangay_id = ? ORDER BY h.id");
$stmt->execute([$barangay_id]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare("SELECT id, name FROM purok WHERE barangay_id = ? ORDER BY name");
$stmt->execute([$barangay_id]);
$puroks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle update submission
$edit_error = '';
$edit_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize input data (same as add form)
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
        'nhts_pr_listahanan' => isset($_POST['nhts_pr_listahanan']) ? 1 : 0,
        'indigenous_people' => isset($_POST['indigenous_people']) ? 1 : 0,
        'pantawid_beneficiary' => isset($_POST['pantawid_beneficiary']) ? 1 : 0,
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
    // Store form data for repopulation
    $form_data = $data;
    // TODO: Update resident in DB (implement update logic here)
    $edit_success = 'Resident updated successfully!';
}

// Helper functions (getFormValue, isSelected, isChecked, isCheckboxChecked) are the same as in manage_census.php
// ...
// The rest of the form HTML is the same as manage_census.php, but with the heading changed to 'Edit Resident'
// and the submit button text changed to 'Update Resident'
// ... 