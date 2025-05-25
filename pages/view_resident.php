<?php
// Get person ID from URL parameter
$person_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

require "../config/dbconn.php";
require "../functions/manage_census.php";

if (!$person_id) {
    header("Location: census_records.php");
    exit;
}

require_once "../pages/header.php";

try {
    // Fetch basic person information with all related data
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            hm.household_id,
            hm.relationship_type_id,
            rt.name as relationship_name,
            hm.is_household_head,
            -- Present Address
            CONCAT(a_present.house_no, ' ', a_present.street, ', ', b_present.name) as present_address,
            a_present.municipality as present_municipality,
            a_present.province as present_province,
            a_present.region as present_region,
            -- Permanent Address
            CONCAT(a_perm.house_no, ' ', a_perm.street, ', ', b_perm.name) as permanent_address,
            a_perm.municipality as permanent_municipality,
            a_perm.province as permanent_province,
            a_perm.region as permanent_region,
            -- Government IDs
            pi.osca_id,
            pi.gsis_id,
            pi.sss_id,
            pi.tin_id,
            pi.philhealth_id,
            pi.other_id_type,
            pi.other_id_number,
            -- Emergency Contact
            ec.contact_name as emergency_contact_name,
            ec.contact_number as emergency_contact_number,
            ec.contact_address as emergency_contact_address,
            ec.relationship as emergency_contact_relationship,
            -- Assets
            GROUP_CONCAT(DISTINCT CONCAT(pa.asset_type_id, ':', pat.name, ':', pa.details) SEPARATOR '|') as assets,
            -- Income Sources
            GROUP_CONCAT(DISTINCT CONCAT(pis.source_type_id, ':', ist.name, ':', pis.amount, ':', pis.details) SEPARATOR '|') as income_sources,
            -- Living Arrangements
            GROUP_CONCAT(DISTINCT CONCAT(pla.arrangement_type_id, ':', lat.name, ':', pla.details) SEPARATOR '|') as living_arrangements,
            -- Skills
            GROUP_CONCAT(DISTINCT CONCAT(ps.skill_type_id, ':', st.name, ':', ps.details) SEPARATOR '|') as skills,
            -- Community Involvements
            GROUP_CONCAT(DISTINCT CONCAT(pi.involvement_type_id, ':', it.name, ':', pi.details) SEPARATOR '|') as involvements,
            -- Health Information
            phi.health_condition,
            phi.has_maintenance,
            phi.maintenance_details,
            phi.high_cost_medicines,
            phi.lack_medical_professionals,
            phi.lack_sanitation_access,
            phi.lack_health_insurance,
            phi.lack_medical_facilities,
            phi.other_health_concerns,
            -- Health Concerns
            GROUP_CONCAT(DISTINCT CONCAT(phc.concern_type_id, ':', hct.name, ':', phc.details) SEPARATOR '|') as health_concerns,
            -- Service Needs
            GROUP_CONCAT(DISTINCT CONCAT(psn.service_type_id, ':', cst.name, ':', psn.details, ':', psn.is_urgent, ':', psn.status) SEPARATOR '|') as service_needs,
            -- Other Needs
            GROUP_CONCAT(DISTINCT CONCAT(pon.need_type_id, ':', ont.name, ':', pon.details, ':', pon.priority_level, ':', pon.status) SEPARATOR '|') as other_needs,
            -- Problems
            GROUP_CONCAT(DISTINCT CONCAT(pp.problem_category_id, ':', pc.name, ':', pc.category_type, ':', pp.details) SEPARATOR '|') as problems,
            -- Child Information (if applicable)
            ci.is_malnourished,
            ci.school_name,
            ci.grade_level,
            ci.school_type,
            ci.immunization_complete,
            ci.is_pantawid_beneficiary,
            ci.has_timbang_operation,
            ci.has_feeding_program,
            ci.has_supplementary_feeding,
            ci.in_caring_institution,
            ci.is_under_foster_care,
            ci.is_directly_entrusted,
            ci.is_legally_adopted,
            -- Child Health Conditions
            GROUP_CONCAT(DISTINCT chc.condition_type SEPARATOR '|') as child_health_conditions,
            -- Child Disabilities
            GROUP_CONCAT(DISTINCT cd.disability_type SEPARATOR '|') as child_disabilities,
            -- Family Composition
            GROUP_CONCAT(DISTINCT CONCAT(fc.name, ':', fc.relationship, ':', fc.age, ':', fc.civil_status, ':', fc.occupation, ':', fc.monthly_income, ':', fc.working_status, ':', fc.educational_attainment) SEPARATOR '|') as family_members,
            -- Age calculation
            TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
        FROM persons p
        -- Household and Relationship
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        -- Addresses
        LEFT JOIN addresses a_present ON p.id = a_present.person_id AND a_present.is_primary = 1
        LEFT JOIN addresses a_perm ON p.id = a_perm.person_id AND a_perm.is_permanent = 1
        LEFT JOIN barangay b_present ON a_present.barangay_id = b_present.id
        LEFT JOIN barangay b_perm ON a_perm.barangay_id = b_perm.id
        -- Government IDs
        LEFT JOIN person_identification pi ON p.id = pi.person_id
        -- Emergency Contact
        LEFT JOIN emergency_contacts ec ON p.id = ec.person_id
        -- Assets
        LEFT JOIN person_assets pa ON p.id = pa.person_id
        LEFT JOIN asset_types pat ON pa.asset_type_id = pat.id
        -- Income Sources
        LEFT JOIN person_income_sources pis ON p.id = pis.person_id
        LEFT JOIN income_source_types ist ON pis.source_type_id = ist.id
        -- Living Arrangements
        LEFT JOIN person_living_arrangements pla ON p.id = pla.person_id
        LEFT JOIN living_arrangement_types lat ON pla.arrangement_type_id = lat.id
        -- Skills
        LEFT JOIN person_skills ps ON p.id = ps.person_id
        LEFT JOIN skill_types st ON ps.skill_type_id = st.id
        -- Community Involvements
        LEFT JOIN person_involvements pi ON p.id = pi.person_id
        LEFT JOIN involvement_types it ON pi.involvement_type_id = it.id
        -- Health Information
        LEFT JOIN person_health_info phi ON p.id = phi.person_id
        -- Health Concerns
        LEFT JOIN person_health_concerns phc ON p.id = phc.person_id
        LEFT JOIN health_concern_types hct ON phc.concern_type_id = hct.id
        -- Service Needs
        LEFT JOIN person_service_needs psn ON p.id = psn.person_id
        LEFT JOIN community_service_types cst ON psn.service_type_id = cst.id
        -- Other Needs
        LEFT JOIN person_other_needs pon ON p.id = pon.person_id
        LEFT JOIN other_need_types ont ON pon.need_type_id = ont.id
        -- Problems
        LEFT JOIN person_problems pp ON p.id = pp.person_id
        LEFT JOIN problem_categories pc ON pp.problem_category_id = pc.id
        -- Child Information
        LEFT JOIN child_information ci ON p.id = ci.person_id
        LEFT JOIN child_health_conditions chc ON p.id = chc.person_id
        LEFT JOIN child_disabilities cd ON p.id = cd.person_id
        -- Family Composition
        LEFT JOIN family_composition fc ON hm.household_id = fc.household_id
        WHERE p.id = :person_id
        GROUP BY p.id
    ");
    $stmt->execute([':person_id' => $person_id]);
    $resident = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resident) {
        throw new Exception("Resident not found");
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

    // Fetch income sources
    $stmt = $pdo->prepare("
        SELECT pis.*, ist.name as source_name
        FROM person_income_sources pis
        JOIN income_source_types ist ON pis.source_type_id = ist.id
        WHERE pis.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $income_sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch living arrangements
    $stmt = $pdo->prepare("
        SELECT pla.*, lat.name as arrangement_name
        FROM person_living_arrangements pla
        JOIN living_arrangement_types lat ON pla.arrangement_type_id = lat.id
        WHERE pla.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $living_arrangements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch skills
    $stmt = $pdo->prepare("
        SELECT ps.*, st.name as skill_name
        FROM person_skills ps
        JOIN skill_types st ON ps.skill_type_id = st.id
        WHERE ps.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch health concerns
    $stmt = $pdo->prepare("
        SELECT phc.*, hct.name as concern_name
        FROM person_health_concerns phc
        JOIN health_concern_types hct ON phc.concern_type_id = hct.id
        WHERE phc.person_id = ? AND phc.is_active = 1
    ");
    $stmt->execute([$person_id]);
    $health_concerns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch family composition (excluding self)
    $stmt = $pdo->prepare("
        SELECT fc.*
        FROM family_composition fc
        JOIN household_members hm ON fc.household_id = hm.household_id
        WHERE hm.person_id = ? 
        AND fc.name != CONCAT(?, ' ', ?)  -- Exclude self by matching full name
        ORDER BY fc.id
    ");
    $stmt->execute([$person_id, $resident['first_name'], $resident['last_name']]);
    $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $_SESSION['error'] = "Error: " . $e->getMessage();
    header("Location: census_records.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Resident Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <!-- Back Button -->
        <div class="mb-4">
            <a href="census_records.php" class="text-blue-600 hover:text-blue-800">
                ← Back to Census Records
            </a>
        </div>

        <!-- Basic Information -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Basic Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <p class="text-gray-600">Full Name</p>
                    <p class="font-semibold">
                        <?= htmlspecialchars($resident['last_name'] . ', ' . $resident['first_name'] . 
                            ($resident['middle_name'] ? ' ' . $resident['middle_name'] : '') . 
                            ($resident['suffix'] ? ' ' . $resident['suffix'] : '')) ?>
                    </p>
                </div>
                <div>
                    <p class="text-gray-600">Age</p>
                    <p class="font-semibold"><?= $resident['age'] ?> years old</p>
                </div>
                <div>
                    <p class="text-gray-600">Gender</p>
                    <p class="font-semibold"><?= htmlspecialchars($resident['gender']) ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Civil Status</p>
                    <p class="font-semibold"><?= htmlspecialchars($resident['civil_status']) ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Birth Date</p>
                    <p class="font-semibold"><?= date('F j, Y', strtotime($resident['birth_date'])) ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Birth Place</p>
                    <p class="font-semibold"><?= htmlspecialchars($resident['birth_place']) ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Contact Number</p>
                    <p class="font-semibold"><?= htmlspecialchars($resident['contact_number'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Years of Residency</p>
                    <p class="font-semibold"><?= htmlspecialchars($resident['years_of_residency']) ?> years</p>
                </div>
            </div>
        </div>

        <!-- Address Information -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="text-lg font-medium text-gray-900">Address Information</h3>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Present Address -->
                <div>
                    <h4 class="font-medium text-gray-700">Present Address</h4>
                    <p class="mt-2 text-gray-600">
                        <?= !empty($resident['present_address']) ? htmlspecialchars($resident['present_address']) : 'N/A' ?><br>
                        <?= !empty($resident['present_municipality']) ? htmlspecialchars($resident['present_municipality']) : 'N/A' ?>,
                        <?= !empty($resident['present_province']) ? htmlspecialchars($resident['present_province']) : 'N/A' ?><br>
                        Region <?= !empty($resident['present_region']) ? htmlspecialchars($resident['present_region']) : 'N/A' ?>
                    </p>
                </div>
                <!-- Permanent Address -->
                <div>
                    <h4 class="font-medium text-gray-700">Permanent Address</h4>
                    <p class="mt-2 text-gray-600">
                        <?= !empty($resident['permanent_address']) ? htmlspecialchars($resident['permanent_address']) : 'N/A' ?><br>
                        <?= !empty($resident['permanent_municipality']) ? htmlspecialchars($resident['permanent_municipality']) : 'N/A' ?>,
                        <?= !empty($resident['permanent_province']) ? htmlspecialchars($resident['permanent_province']) : 'N/A' ?><br>
                        Region <?= !empty($resident['permanent_region']) ? htmlspecialchars($resident['permanent_region']) : 'N/A' ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Government IDs -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="text-lg font-medium text-gray-900">Government IDs</h3>
            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500">OSCA ID</p>
                    <p class="mt-1"><?= !empty($resident['osca_id']) ? htmlspecialchars($resident['osca_id']) : 'N/A' ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">GSIS ID</p>
                    <p class="mt-1"><?= !empty($resident['gsis_id']) ? htmlspecialchars($resident['gsis_id']) : 'N/A' ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">SSS ID</p>
                    <p class="mt-1"><?= !empty($resident['sss_id']) ? htmlspecialchars($resident['sss_id']) : 'N/A' ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">TIN ID</p>
                    <p class="mt-1"><?= !empty($resident['tin_id']) ? htmlspecialchars($resident['tin_id']) : 'N/A' ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-500">PhilHealth ID</p>
                    <p class="mt-1"><?= !empty($resident['philhealth_id']) ? htmlspecialchars($resident['philhealth_id']) : 'N/A' ?></p>
                </div>
                <?php if (!empty($resident['other_id_type']) || !empty($resident['other_id_number'])): ?>
                <div>
                    <p class="text-sm font-medium text-gray-500"><?= htmlspecialchars($resident['other_id_type']) ?></p>
                    <p class="mt-1"><?= htmlspecialchars($resident['other_id_number']) ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assets -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="text-lg font-medium text-gray-900">Assets & Properties</h3>
            <?php if (!empty($resident['assets'])): ?>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $assets = array_filter(explode('|', $resident['assets']));
                    foreach ($assets as $asset) {
                        list($type_id, $name, $details) = array_pad(explode(':', $asset), 3, '');
                    ?>
                        <div>
                            <p class="text-sm font-medium text-gray-500"><?= htmlspecialchars($name) ?></p>
                            <?php if (!empty($details)): ?>
                                <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars($details) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php } ?>
                </div>
            <?php else: ?>
                <p class="mt-2 text-gray-600">No assets recorded</p>
            <?php endif; ?>
        </div>

        <!-- Income Sources -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="text-lg font-medium text-gray-900">Sources of Income</h3>
            <?php if (!empty($resident['income_sources'])): ?>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $sources = array_filter(explode('|', $resident['income_sources']));
                    foreach ($sources as $source) {
                        list($type_id, $name, $amount, $details) = array_pad(explode(':', $source), 4, '');
                    ?>
                        <div>
                            <p class="text-sm font-medium text-gray-500"><?= htmlspecialchars($name) ?></p>
                            <?php if (!empty($amount)): ?>
                                <p class="mt-1 text-sm text-gray-600">Amount: ₱<?= number_format($amount, 2) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($details)): ?>
                                <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars($details) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php } ?>
                </div>
            <?php else: ?>
                <p class="mt-2 text-gray-600">No income sources recorded</p>
            <?php endif; ?>
        </div>

        <!-- Skills -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="text-lg font-medium text-gray-900">Skills</h3>
            <?php if (!empty($resident['skills'])): ?>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php
                    $skills = array_filter(explode('|', $resident['skills']));
                    foreach ($skills as $skill) {
                        list($type_id, $name, $details) = array_pad(explode(':', $skill), 3, '');
                    ?>
                        <div>
                            <p class="text-sm font-medium text-gray-500"><?= htmlspecialchars($name) ?></p>
                            <?php if (!empty($details)): ?>
                                <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars($details) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php } ?>
                </div>
            <?php else: ?>
                <p class="mt-2 text-gray-600">No skills recorded</p>
            <?php endif; ?>
        </div>

        <!-- Health Concerns -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="text-lg font-medium text-gray-900">Health Concerns</h3>
            <?php if (!empty($resident['health_concerns'])): ?>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $concerns = array_filter(explode('|', $resident['health_concerns']));
                    foreach ($concerns as $concern) {
                        list($type_id, $name, $details) = array_pad(explode(':', $concern), 3, '');
                    ?>
                        <div>
                            <p class="text-sm font-medium text-gray-500"><?= htmlspecialchars($name) ?></p>
                            <?php if (!empty($details)): ?>
                                <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars($details) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php } ?>
                </div>
            <?php else: ?>
                <p class="mt-2 text-gray-600">No health concerns recorded</p>
            <?php endif; ?>
        </div>

        <!-- Family Composition -->
        <?php if (!empty($family_members)): ?>
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Family Composition</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full table-auto">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">Relationship</th>
                            <th class="px-4 py-2 text-left">Age</th>
                            <th class="px-4 py-2 text-left">Civil Status</th>
                            <th class="px-4 py-2 text-left">Occupation</th>
                            <th class="px-4 py-2 text-left">Monthly Income</th>
                            <th class="px-4 py-2 text-left">Education</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($family_members as $member): ?>
                        <tr class="border-b">
                            <td class="px-4 py-2"><?= htmlspecialchars($member['name']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($member['relationship']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($member['age']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($member['civil_status']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($member['occupation'] ?? 'N/A') ?></td>
                            <td class="px-4 py-2"><?= $member['monthly_income'] ? '₱' . number_format($member['monthly_income'], 2) : 'N/A' ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($member['educational_attainment'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="flex justify-end space-x-4 mt-6">
            <a href="edit_resident.php?id=<?= $person_id ?>" 
               class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                Edit Resident
            </a>
            <button onclick="confirmDelete(<?= $person_id ?>)" 
                    class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                Delete Resident
            </button>
        </div>
    </div>

    <script>
    function confirmDelete(personId) {
        if (confirm('Are you sure you want to delete this resident? This action cannot be undone.')) {
            window.location.href = `delete_resident.php?id=${personId}`;
        }
    }
    </script>
</body>
</html> 