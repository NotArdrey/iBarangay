<?php
require "../config/dbconn.php";
require "../functions/manage_census.php";
require_once "../pages/header.php";

// Check if ID is provided
if (!isset($_GET['id'])) {
    header("Location: census_records.php");
    exit;
}

$person_id = (int)$_GET['id'];

// Fetch resident data with all related information
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        hm.household_id AS household_id,
        hm.relationship_type_id,
        rt.name as relationship_name,
        hm.is_household_head,
        CONCAT(a.house_no, ' ', a.street, ', ', b.name) as address,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age,
        p.years_of_residency,
        gp.nhts_pr_listahanan,
        gp.indigenous_people,
        gp.pantawid_beneficiary,
        i.own_earnings as income_own_earnings,
        i.own_pension as income_own_pension,
        i.own_pension_amount as income_own_pension_amount,
        i.stocks_dividends as income_stocks_dividends,
        i.dependent_on_children as income_dependent_on_children,
        i.spouse_salary as income_spouse_salary,
        i.insurances as income_insurances,
        i.spouse_pension as income_spouse_pension,
        i.spouse_pension_amount as income_spouse_pension_amount,
        i.rentals_sharecrops as income_rentals_sharecrops,
        i.savings as income_savings,
        i.livestock_orchards as income_livestock_orchards,
        i.others as income_others,
        i.others_specify as income_others_specify,
        ap.house as asset_house,
        ap.house_lot as asset_house_lot,
        ap.farmland as asset_farmland,
        pi.osca_id,
        pi.gsis_id,
        pi.sss_id,
        pi.tin_id,
        pi.philhealth_id,
        pi.other_id_type,
        pi.other_id_number,
        la.spouse as living_spouse,
        la.care_institutions as living_care_institutions,
        la.children as living_children,
        la.grandchildren as living_grandchildren,
        la.househelps as living_househelps,
        la.relatives as living_relatives,
        la.others as living_others,
        la.others_specify as living_others_specify,
        s.dental as skill_dental,
        s.counseling as skill_counseling,
        s.evangelization as skill_evangelization,
        s.farming as skill_farming,
        pn.lack_income as problem_lack_income,
        pn.unemployment as problem_unemployment,
        pn.economic_others as problem_economic_others,
        pn.economic_others_specify as problem_economic_others_specify,
        pn.loneliness as problem_loneliness,
        pn.isolation as problem_isolation,
        pn.neglect as problem_neglect,
        pn.lack_health_insurance as problem_lack_health_insurance,
        pn.inadequate_health_services as problem_inadequate_health_services,
        pn.lack_medical_facilities as problem_lack_medical_facilities,
        pn.overcrowding as problem_overcrowding,
        pn.no_permanent_housing as problem_no_permanent_housing,
        pn.independent_living as problem_independent_living
    FROM persons p
    JOIN household_members hm ON p.id = hm.person_id
    JOIN households h ON hm.household_id = h.id
    JOIN barangay b ON h.barangay_id = b.id
    LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
    LEFT JOIN government_programs gp ON p.id = gp.person_id
    LEFT JOIN income_sources i ON p.id = i.person_id
    LEFT JOIN assets_properties ap ON p.id = ap.person_id
    LEFT JOIN person_identification pi ON p.id = pi.person_id
    LEFT JOIN living_arrangements la ON p.id = la.person_id
    LEFT JOIN skills s ON p.id = s.person_id
    LEFT JOIN problems_needs pn ON p.id = pn.person_id
    LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
    WHERE p.id = ?
");

$stmt->execute([$person_id]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    header("Location: census_records.php");
    exit;
}

// Fetch family composition data
$stmt = $pdo->prepare("
    SELECT fc.*
    FROM family_composition fc
    WHERE fc.household_id = ?
    ORDER BY 
        CASE 
            WHEN fc.relationship = 'HEAD' THEN 1
            WHEN fc.relationship = 'SPOUSE' THEN 2
            WHEN fc.relationship = 'CHILD' THEN 3
            ELSE 4
        END,
        fc.name
");
$stmt->execute([$resident['household_id']]);
$family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to display boolean values as indicators
function displayIndicator($value, $label) {
    $bgColor = $value ? 'bg-green-500' : 'bg-gray-300';
    return "
        <div class='flex items-center'>
            <span class='w-4 h-4 {$bgColor} rounded-full mr-2'></span>
            <span>{$label}</span>
        </div>
    ";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Resident - <?= htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <!-- Back button and title -->
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-blue-800">Resident Information</h1>
            <a href="census_records.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                Back to Census Records
            </a>
        </div>

        <!-- Main content -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Basic Information -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Basic Information</h2>
                <div class="space-y-3">
                    <p><span class="font-medium">Name:</span> <?= htmlspecialchars($resident['last_name'] . ', ' . $resident['first_name'] . ' ' . ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . ($resident['suffix'] ?? '')) ?></p>
                    <p><span class="font-medium">Birth Date:</span> <?= htmlspecialchars($resident['birth_date']) ?></p>
                    <p><span class="font-medium">Age:</span> <?= $resident['age'] ?></p>
                    <p><span class="font-medium">Birth Place:</span> <?= htmlspecialchars($resident['birth_place']) ?></p>
                    <p><span class="font-medium">Gender:</span> <?= htmlspecialchars($resident['gender']) ?></p>
                    <p><span class="font-medium">Civil Status:</span> <?= htmlspecialchars($resident['civil_status']) ?></p>
                    <p><span class="font-medium">Years of Residency:</span> <?= htmlspecialchars($resident['years_of_residency']) ?> years</p>
                    <p><span class="font-medium">Citizenship:</span> <?= htmlspecialchars($resident['citizenship']) ?></p>
                    <p><span class="font-medium">Religion:</span> <?= htmlspecialchars($resident['religion']) ?></p>
                </div>
            </div>

            <!-- Address Information -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Address Information</h2>
                <div class="space-y-3">
                    <p><span class="font-medium">Current Address:</span> <?= htmlspecialchars($resident['address']) ?></p>
                    <p><span class="font-medium">House No:</span> <?= htmlspecialchars($resident['house_no'] ?? 'N/A') ?></p>
                    <p><span class="font-medium">Street:</span> <?= htmlspecialchars($resident['street'] ?? 'N/A') ?></p>
                    <p><span class="font-medium">Municipality:</span> <?= htmlspecialchars($resident['municipality'] ?? 'SAN RAFAEL') ?></p>
                    <p><span class="font-medium">Province:</span> <?= htmlspecialchars($resident['province'] ?? 'BULACAN') ?></p>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Additional Information</h2>
                <div class="space-y-3">
                    <p><span class="font-medium">Education Level:</span> <?= htmlspecialchars($resident['education_level'] ?? 'N/A') ?></p>
                    <p><span class="font-medium">Occupation:</span> <?= htmlspecialchars($resident['occupation'] ?? 'N/A') ?></p>
                    <p><span class="font-medium">Monthly Income:</span> ₱<?= number_format($resident['monthly_income'] ?? 0, 2) ?></p>
                    <p><span class="font-medium">Household ID:</span> <?= htmlspecialchars($resident['household_id']) ?></p>
                    <p><span class="font-medium">Relationship to Head:</span> <?= htmlspecialchars($resident['relationship_name']) ?> <?= $resident['is_household_head'] ? '(Head)' : '' ?></p>
                </div>
            </div>

            <!-- Government Programs -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Government Programs</h2>
                <div class="space-y-2">
                    <?= displayIndicator($resident['nhts_pr_listahanan'], 'NHTS-PR (Listahanan)') ?>
                    <?= displayIndicator($resident['indigenous_people'], 'Indigenous People') ?>
                    <?= displayIndicator($resident['pantawid_beneficiary'], 'Pantawid Beneficiary') ?>
                </div>
            </div>

            <!-- Source of Income -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Source of Income & Assistance</h2>
                <div class="space-y-2">
                    <?= displayIndicator($resident['income_own_earnings'], 'Own Earnings, Salaries/Wages') ?>
                    <?= displayIndicator($resident['income_own_pension'], 'Own Pension' . ($resident['income_own_pension_amount'] ? ' (₱' . number_format($resident['income_own_pension_amount'], 2) . ')' : '')) ?>
                    <?= displayIndicator($resident['income_stocks_dividends'], 'Stocks/Dividends') ?>
                    <?= displayIndicator($resident['income_dependent_on_children'], 'Dependent on Children/Relatives') ?>
                    <?= displayIndicator($resident['income_spouse_salary'], 'Spouse\'s Salary') ?>
                    <?= displayIndicator($resident['income_insurances'], 'Insurances') ?>
                    <?= displayIndicator($resident['income_spouse_pension'], 'Spouse\'s Pension' . ($resident['income_spouse_pension_amount'] ? ' (₱' . number_format($resident['income_spouse_pension_amount'], 2) . ')' : '')) ?>
                    <?= displayIndicator($resident['income_rentals_sharecrops'], 'Rentals/Sharecrops') ?>
                    <?= displayIndicator($resident['income_savings'], 'Savings') ?>
                    <?= displayIndicator($resident['income_livestock_orchards'], 'Livestock/Orchards') ?>
                    <?php if ($resident['income_others']): ?>
                        <?= displayIndicator(true, 'Others: ' . htmlspecialchars($resident['income_others_specify'] ?? 'Not specified')) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Assets & Properties -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Assets & Properties</h2>
                <div class="space-y-2">
                    <?= displayIndicator($resident['asset_house'], 'House') ?>
                    <?= displayIndicator($resident['asset_house_lot'], 'House & Lot') ?>
                    <?= displayIndicator($resident['asset_farmland'], 'Farmland') ?>
                </div>
            </div>

            <!-- Living Arrangements -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Living Arrangements</h2>
                <div class="space-y-2">
                    <?= displayIndicator($resident['living_spouse'], 'Spouse') ?>
                    <?= displayIndicator($resident['living_care_institutions'], 'Care Institutions') ?>
                    <?= displayIndicator($resident['living_children'], 'Children') ?>
                    <?= displayIndicator($resident['living_grandchildren'], 'Grandchildren') ?>
                    <?= displayIndicator($resident['living_househelps'], 'Househelps') ?>
                    <?= displayIndicator($resident['living_relatives'], 'Relatives') ?>
                    <?php if ($resident['living_others']): ?>
                        <?= displayIndicator(true, 'Others: ' . htmlspecialchars($resident['living_others_specify'] ?? 'Not specified')) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Skills -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Skills & Specializations</h2>
                <div class="space-y-2">
                    <?= displayIndicator($resident['skill_dental'], 'Dental') ?>
                    <?= displayIndicator($resident['skill_counseling'], 'Counseling') ?>
                    <?= displayIndicator($resident['skill_evangelization'], 'Evangelization') ?>
                    <?= displayIndicator($resident['skill_farming'], 'Farming') ?>
                </div>
            </div>

            <!-- Problems & Needs -->
            <div class="bg-white rounded-lg shadow-sm p-6 md:col-span-3">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Problems & Needs</h2>
                
                <!-- Economic Problems -->
                <div class="mb-6">
                    <h3 class="font-medium mb-2">Economic</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <?= displayIndicator($resident['problem_lack_income'], 'Lack of Income') ?>
                        <?= displayIndicator($resident['problem_unemployment'], 'Unemployment') ?>
                        <?php if ($resident['problem_economic_others']): ?>
                            <?= displayIndicator(true, 'Others: ' . htmlspecialchars($resident['problem_economic_others_specify'] ?? 'Not specified')) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Social/Emotional Problems -->
                <div class="mb-6">
                    <h3 class="font-medium mb-2">Social/Emotional</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <?= displayIndicator($resident['problem_loneliness'], 'Loneliness') ?>
                        <?= displayIndicator($resident['problem_isolation'], 'Isolation') ?>
                        <?= displayIndicator($resident['problem_neglect'], 'Neglect') ?>
                    </div>
                </div>

                <!-- Health Problems -->
                <div class="mb-6">
                    <h3 class="font-medium mb-2">Health</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <?= displayIndicator($resident['problem_lack_health_insurance'], 'Lack/No Health Insurance/s') ?>
                        <?= displayIndicator($resident['problem_inadequate_health_services'], 'Inadequate Health Services') ?>
                        <?= displayIndicator($resident['problem_lack_medical_facilities'], 'Lack of Hospitals/Medical Facilities') ?>
                    </div>
                </div>

                <!-- Housing Problems -->
                <div>
                    <h3 class="font-medium mb-2">Housing</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <?= displayIndicator($resident['problem_overcrowding'], 'Overcrowding in the Family Home') ?>
                        <?= displayIndicator($resident['problem_no_permanent_housing'], 'No Permanent Housing') ?>
                        <?= displayIndicator($resident['problem_independent_living'], 'Longing for Independent Living/Quiet Atmosphere') ?>
                    </div>
                </div>
            </div>

            <!-- Family Composition -->
            <div class="bg-white rounded-lg shadow-sm p-6 md:col-span-3">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Family Composition</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white border border-gray-200">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Name</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Relationship</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Age</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Civil Status</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Occupation</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Monthly Income</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Working Status</th>
                                <th class="border border-gray-200 px-4 py-2 text-left text-sm font-medium text-gray-600">Educational Attainment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($family_members)): ?>
                            <tr>
                                <td colspan="8" class="border border-gray-200 px-4 py-2 text-center text-gray-500">No family members found</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($family_members as $member): ?>
                                <tr>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['name']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['relationship']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['age']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['civil_status']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['occupation'] ?? 'N/A') ?></td>
                                    <td class="border border-gray-200 px-4 py-2">₱<?= number_format($member['monthly_income'] ?? 0, 2) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['working_status']) ?></td>
                                    <td class="border border-gray-200 px-4 py-2"><?= htmlspecialchars($member['educational_attainment'] ?? 'N/A') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="mt-6 flex justify-end space-x-4">
            <a href="edit_resident.php?id=<?= $resident['id'] ?>" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                Edit Resident
            </a>
            <button onclick="confirmDelete(<?= $resident['id'] ?>)" class="bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700">
                Delete Resident
            </button>
        </div>
    </div>

    <script>
        function confirmDelete(residentId) {
            if (confirm('Are you sure you want to delete this resident? This action cannot be undone.')) {
                window.location.href = `delete_resident.php?id=${residentId}`;
            }
        }
    </script>
</body>
</html> 