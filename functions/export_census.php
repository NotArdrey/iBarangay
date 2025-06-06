<?php
require "../config/dbconn.php";

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has permission
$current_admin_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$current_role_id = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : null;
$barangay_id = isset($_SESSION['barangay_id']) ? (int)$_SESSION['barangay_id'] : null;

$census_full_access_roles = [1, 2, 3, 9]; // Programmer, Super Admin, Captain, Health Worker
$census_view_only_roles = [4, 5, 6, 7];   // Secretary, Treasurer, Councilor, Chairperson

$can_view_census = in_array($current_role_id, $census_full_access_roles) || in_array($current_role_id, $census_view_only_roles);

if ($current_admin_id === null || !$can_view_census) {
    header("Location: ../pages/login.php");
    exit;
}

// Query to get all resident data
$stmt = $pdo->prepare("
    WITH present_address AS (
        SELECT person_id, house_no AS present_house_no, street AS present_street, barangay_id AS present_barangay_id
        FROM addresses
        WHERE is_primary = 1
    ),
    permanent_address AS (
        SELECT person_id, house_no AS permanent_house_no, street AS permanent_street, barangay_id AS permanent_barangay_id
        FROM addresses
        WHERE is_permanent = 1
    )
    SELECT 
        p.id,
        p.first_name,
        p.middle_name,
        p.last_name,
        p.suffix,
        p.birth_date,
        p.birth_place,
        p.gender,
        p.civil_status,
        p.citizenship,
        p.religion,
        p.education_level,
        p.occupation,
        p.monthly_income,
        p.years_of_residency,
        p.resident_type,
        p.nhts_pr_listahanan,
        p.indigenous_people,
        p.pantawid_beneficiary,
        p.contact_number,
        h.household_number,
        rt.name as relationship_name,
        hm.is_household_head,
        pa.present_house_no,
        pa.present_street,
        pb.name as present_barangay,
        perm.permanent_house_no,
        perm.permanent_street,
        pb2.name as permanent_barangay,
        purok.name as purok_name,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age,
        CASE WHEN ci.id IS NOT NULL THEN 1 ELSE 0 END as is_child,
        pi.tin_id,
        pi.philhealth_id,
        pi.other_id_type,
        pi.other_id_number,
        pi.osca_id,
        pi.gsis_id,
        pi.sss_id,
        ci.attending_school,
        ci.school_type,
        ci.school_name,
        ci.grade_level,
        ci.is_malnourished,
        ci.immunization_complete,
        ci.is_pantawid_beneficiary,
        ci.has_timbang_operation,
        ci.has_feeding_program,
        ci.has_supplementary_feeding,
        ci.in_caring_institution,
        ci.is_under_foster_care,
        ci.is_directly_entrusted,
        ci.is_legally_adopted,
        ci.garantisadong_pambata,
        ci.under_six_years,
        ci.grade_school,
        (
            SELECT GROUP_CONCAT(DISTINCT condition_type)
            FROM child_health_conditions
            WHERE person_id = p.id
        ) as health_conditions,
        (
            SELECT GROUP_CONCAT(DISTINCT disability_type)
            FROM child_disabilities
            WHERE person_id = p.id
        ) as disabilities,
        (
            SELECT GROUP_CONCAT(DISTINCT asset_type_id)
            FROM person_assets
            WHERE person_id = p.id
        ) as asset_types,
        (
            SELECT GROUP_CONCAT(DISTINCT source_type_id)
            FROM person_income_sources
            WHERE person_id = p.id
        ) as income_sources,
        (
            SELECT GROUP_CONCAT(DISTINCT arrangement_type_id)
            FROM person_living_arrangements
            WHERE person_id = p.id
        ) as living_arrangements,
        (
            SELECT GROUP_CONCAT(DISTINCT skill_type_id)
            FROM person_skills
            WHERE person_id = p.id
        ) as skills,
        (
            SELECT GROUP_CONCAT(DISTINCT involvement_type_id)
            FROM person_involvements
            WHERE person_id = p.id
        ) as involvements,
        (
            SELECT GROUP_CONCAT(DISTINCT problem_category_id)
            FROM person_problems
            WHERE person_id = p.id
        ) as problems,
        (
            SELECT CONCAT_WS(',',
                IF(loss_income = 1, 'Loss of Income', NULL),
                IF(unemployment = 1, 'Unemployment', NULL),
                IF(skills_training = 1, 'Skills Training', NULL),
                IF(livelihood = 1, 'Livelihood', NULL),
                IF(other_economic = 1, CONCAT('Other: ', other_economic_details), NULL)
            )
            FROM person_economic_problems
            WHERE person_id = p.id
        ) as economic_problems,
        (
            SELECT skills_training_details FROM person_economic_problems WHERE person_id = p.id
        ) as skills_training_details,
        (
            SELECT livelihood_details FROM person_economic_problems WHERE person_id = p.id
        ) as livelihood_details,
        (
            SELECT other_economic_details FROM person_economic_problems WHERE person_id = p.id
        ) as other_economic_details,
        (
            SELECT CONCAT_WS(',',
                IF(loneliness = 1, 'Loneliness', NULL),
                IF(isolation = 1, 'Isolation', NULL),
                IF(neglect = 1, 'Neglect', NULL),
                IF(recreational = 1, 'Inadequate leisure/recreational activities', NULL),
                IF(senior_friendly = 1, 'Senior Citizen Friendly Environment', NULL),
                IF(other_social = 1, CONCAT('Other: ', other_social_details), NULL)
            )
            FROM person_social_problems
            WHERE person_id = p.id
        ) as social_problems,
        (
            SELECT other_social_details FROM person_social_problems WHERE person_id = p.id
        ) as other_social_details,
        (
            SELECT CONCAT_WS(',',
                IF(condition_illness = 1, 'Condition/Illness', NULL),
                IF(high_cost_medicine = 1, 'High Cost Medicine', NULL),
                IF(lack_medical_professionals = 1, 'Lack Medical Professionals', NULL),
                IF(lack_sanitation = 1, 'Lack Sanitation', NULL),
                IF(lack_health_insurance = 1, 'Lack Health Insurance', NULL),
                IF(inadequate_health_services = 1, 'Inadequate Health Services', NULL),
                IF(other_health = 1, CONCAT('Other: ', other_health_details), NULL)
            )
            FROM person_health_problems
            WHERE person_id = p.id
        ) as health_problems,
        (
            SELECT condition_illness_details FROM person_health_problems WHERE person_id = p.id
        ) as condition_illness_details,
        (
            SELECT other_health_details FROM person_health_problems WHERE person_id = p.id
        ) as other_health_details,
        (
            SELECT CONCAT_WS(',',
                IF(overcrowding = 1, 'Overcrowding', NULL),
                IF(no_permanent_housing = 1, 'No Permanent Housing', NULL),
                IF(independent_living = 1, 'Independent Living', NULL),
                IF(lost_privacy = 1, 'Lost Privacy', NULL),
                IF(squatters = 1, 'Squatters', NULL),
                IF(other_housing = 1, CONCAT('Other: ', other_housing_details), NULL)
            )
            FROM person_housing_problems
            WHERE person_id = p.id
        ) as housing_problems,
        (
            SELECT other_housing_details FROM person_housing_problems WHERE person_id = p.id
        ) as other_housing_details,
        (
            SELECT JSON_ARRAYAGG(JSON_OBJECT(
                'name', fc.name,
                'relationship', fc.relationship,
                'age', fc.age,
                'civil_status', fc.civil_status,
                'occupation', fc.occupation,
                'monthly_income', fc.monthly_income
            ))
            FROM family_composition fc
            WHERE fc.person_id = p.id
        ) as family_composition
    FROM persons p
    LEFT JOIN household_members hm ON p.id = hm.person_id
    LEFT JOIN households h ON hm.household_id = h.id
    LEFT JOIN purok ON h.purok_id = purok.id
    LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
    LEFT JOIN present_address pa ON p.id = pa.person_id
    LEFT JOIN barangay pb ON pa.present_barangay_id = pb.id
    LEFT JOIN permanent_address perm ON p.id = perm.person_id
    LEFT JOIN barangay pb2 ON perm.permanent_barangay_id = pb2.id
    LEFT JOIN child_information ci ON p.id = ci.person_id
    LEFT JOIN person_identification pi ON p.id = pi.person_id
    WHERE h.barangay_id = ?
    AND p.is_archived = 0
");

$stmt->execute([$barangay_id]);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename="census_records_export_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel encoding
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
$headers = [
    'Record Type',
    'First Name',
    'Middle Name',
    'Last Name',
    'Suffix',
    'Date of Birth',
    'Age',
    'Place of Birth',
    'Sex',
    'Civil Status',
    'Citizenship',
    'Religion',
    'Household Number',
    'Relationship to Head',
    'Is Household Head',
    'House Number',
    'Purok',
    'Present Address',
    'Permanent Address',
    'Years of Residency',
    'Resident Type',
    'Educational Attainment',
    'Occupation',
    'Monthly Income',
    'TIN ID',
    'PhilHealth ID',
    'OSCA ID',
    'GSIS ID',
    'SSS ID',
    'Other ID Type',
    'Other ID Number',
    'NHTS/PR Listahanan',
    'Indigenous People',
    'Pantawid Beneficiary',
    'Contact Number',
    'Assets',
    'Income Sources',
    'Living Arrangements',
    'Skills',
    'Community Involvement',
    'Problems',
    'Economic Problems',
    'Skills Training Details',
    'Livelihood Details',
    'Other Economic Details',
    'Social Problems',
    'Other Social Details',
    'Health Problems',
    'Condition Illness Details',
    'Other Health Details',
    'Housing Problems',
    'Other Housing Details',
    'Family Composition',
    'Attending School',
    'School Type',
    'School Name',
    'Grade Level',
    'Is Malnourished',
    'Immunization Complete',
    'Is Pantawid Beneficiary',
    'Has Timbang Operation',
    'Has Feeding Program',
    'Has Supplementary Feeding',
    'In Caring Institution',
    'Under Foster Care',
    'Directly Entrusted',
    'Legally Adopted',
    'Garantisadong Pambata',
    'Under Six Years',
    'Grade School',
    'Health Conditions',
    'Disabilities'
];
fputcsv($output, $headers);

// Add data rows
foreach ($residents as $resident) {
    $age = $resident['age'] ?? calculateAge($resident['birth_date']);
    $is_child = $resident['is_child'] && $age < 18;
    $recordType = $is_child ? 'Child Record' : 'Regular Record';

    $present_address = trim(($resident['present_house_no'] ? $resident['present_house_no'] . ' ' : '') . ($resident['present_street'] ? $resident['present_street'] . ', ' : '') . ($resident['present_barangay'] ?? ''));
    $permanent_address = trim(($resident['permanent_house_no'] ? $resident['permanent_house_no'] . ' ' : '') . ($resident['permanent_street'] ? $resident['permanent_street'] . ', ' : '') . ($resident['permanent_barangay'] ?? ''));

    $row = [
        $recordType,
        $resident['first_name'],
        $resident['middle_name'],
        $resident['last_name'],
        $resident['suffix'],
        $resident['birth_date'],
        $age,
        $resident['birth_place'],
        $resident['gender'],
        $resident['civil_status'],
        $resident['citizenship'],
        $resident['religion'],
        $resident['household_number'],
        $resident['relationship_name'],
        $resident['is_household_head'] ? 'Yes' : 'No',
        $resident['present_house_no'],
        $resident['purok_name'],
        $present_address,
        $permanent_address,
        $resident['years_of_residency'],
        $resident['resident_type'],
        $resident['education_level'],
        $resident['occupation'],
        $resident['monthly_income'],
        $resident['tin_id'],
        $resident['philhealth_id'],
        $resident['osca_id'],
        $resident['gsis_id'],
        $resident['sss_id'],
        $resident['other_id_type'],
        $resident['other_id_number'],
        $resident['nhts_pr_listahanan'] ? 'Yes' : 'No',
        $resident['indigenous_people'] ? 'Yes' : 'No',
        $resident['pantawid_beneficiary'] ? 'Yes' : 'No',
        $resident['contact_number'],
        $resident['asset_types'],
        $resident['income_sources'],
        $resident['living_arrangements'],
        $resident['skills'],
        $resident['involvements'],
        $resident['problems'],
        $resident['economic_problems'],
        $resident['skills_training_details'],
        $resident['livelihood_details'],
        $resident['other_economic_details'],
        $resident['social_problems'],
        $resident['other_social_details'],
        $resident['health_problems'],
        $resident['condition_illness_details'],
        $resident['other_health_details'],
        $resident['housing_problems'],
        $resident['other_housing_details'],
        $resident['family_composition'],
        $resident['attending_school'] ? 'Yes' : 'No',
        $resident['school_type'],
        $resident['school_name'],
        $resident['grade_level'],
        $resident['is_malnourished'] ? 'Yes' : 'No',
        $resident['immunization_complete'] ? 'Yes' : 'No',
        $resident['is_pantawid_beneficiary'] ? 'Yes' : 'No',
        $resident['has_timbang_operation'] ? 'Yes' : 'No',
        $resident['has_feeding_program'] ? 'Yes' : 'No',
        $resident['has_supplementary_feeding'] ? 'Yes' : 'No',
        $resident['in_caring_institution'] ? 'Yes' : 'No',
        $resident['is_under_foster_care'] ? 'Yes' : 'No',
        $resident['is_directly_entrusted'] ? 'Yes' : 'No',
        $resident['is_legally_adopted'] ? 'Yes' : 'No',
        $resident['garantisadong_pambata'] ? 'Yes' : 'No',
        $resident['under_six_years'] ? 'Yes' : 'No',
        $resident['grade_school'] ? 'Yes' : 'No',
        $resident['health_conditions'],
        $resident['disabilities']
    ];
    
    fputcsv($output, $row);
}

fclose($output);
exit; 