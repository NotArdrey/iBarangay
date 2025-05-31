<?php
// Start output buffering at the very beginning of the file
ob_start();

require "../config/dbconn.php";
require "../functions/manage_census.php";

// Check admin permissions
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    ob_end_clean(); // Clear the buffer before redirecting
    header("Location: ../pages/login.php");
    exit;
}

$person_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$person_id) {
    ob_end_clean(); // Clear the buffer before redirecting
    $_SESSION['error'] = "Invalid resident ID";
    header("Location: census_records.php");
    exit;
}

require_once "../components/header.php";

// Fetch comprehensive resident data
try {
    // Main person information with household and address
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            hm.household_id, 
            h.household_number,
            hm.relationship_type_id,
            hm.is_household_head,
            rt.name as relationship_name,
            b.name as barangay_name,
            pu.name as purok_name,
            TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
        FROM persons p
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        LEFT JOIN relationship_types rt ON hm.relationship_type_id = rt.id
        LEFT JOIN barangay b ON h.barangay_id = b.id
        LEFT JOIN purok pu ON h.purok_id = pu.id
        WHERE p.id = ?
    ");
    $stmt->execute([$person_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$person) {
        $_SESSION['error'] = "Resident not found";
        header("Location: census_records.php");
        exit;
    }

    // Fetch addresses with barangay information
    $stmt = $pdo->prepare("
        SELECT a.*, b.name as barangay_name
        FROM addresses a
        LEFT JOIN barangay b ON a.barangay_id = b.id
        WHERE a.person_id = ? 
        ORDER BY a.is_primary DESC, a.is_permanent DESC
    ");
    $stmt->execute([$person_id]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch government IDs
    $stmt = $pdo->prepare("
        SELECT * FROM person_identification 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $identification = $stmt->fetch(PDO::FETCH_ASSOC);

    // Emergency contacts section removed as not part of census forms

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

    // Fetch community involvements
    $stmt = $pdo->prepare("
        SELECT pi.*, it.name as involvement_name 
        FROM person_involvements pi
        JOIN involvement_types it ON pi.involvement_type_id = it.id
        WHERE pi.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $involvements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch health information
    $stmt = $pdo->prepare("
        SELECT * FROM person_health_info 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $health_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch economic problems
    $stmt = $pdo->prepare("
        SELECT * FROM person_economic_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $economic_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch social problems
    $stmt = $pdo->prepare("
        SELECT * FROM person_social_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $social_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch health problems
    $stmt = $pdo->prepare("
        SELECT * FROM person_health_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $health_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch housing problems
    $stmt = $pdo->prepare("
        SELECT * FROM person_housing_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $housing_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch community problems
    $stmt = $pdo->prepare("
        SELECT * FROM person_community_problems 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $community_problems = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch other needs
    $stmt = $pdo->prepare("
        SELECT pon.id, pon.person_id, pon.need_type_id, pon.details, 
               ont.name as need_name, ont.category as need_category
        FROM person_other_needs pon
        JOIN other_need_types ont ON pon.need_type_id = ont.id
        WHERE pon.person_id = ?
    ");
    $stmt->execute([$person_id]);
    $other_needs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch government programs
    $stmt = $pdo->prepare("
        SELECT * FROM government_programs 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $government_programs = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch child information if applicable
    $stmt = $pdo->prepare("
        SELECT * FROM child_information 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $child_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch child health conditions if applicable
    $stmt = $pdo->prepare("
        SELECT * FROM child_health_conditions 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $child_health_conditions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch child disabilities if applicable
    $stmt = $pdo->prepare("
        SELECT * FROM child_disabilities 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $child_disabilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch family composition data
    $stmt = $pdo->prepare("
        SELECT * FROM family_composition 
        WHERE person_id = ?
    ");
    $stmt->execute([$person_id]);
    $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $_SESSION['error'] = "Error fetching resident data: " . $e->getMessage();
    header("Location: census_records.php");
    exit;
}

// Helper function to display boolean values
function displayBoolean($value) {
    return $value ? 'Yes' : 'No';
}

// Helper function to display currency
function displayCurrency($amount) {
    return $amount ? '₱' . number_format($amount, 2) : 'Not specified';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Resident - <?= htmlspecialchars($person['first_name'] . ' ' . $person['last_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <link href="../assets/css/resident-profile.css" rel="stylesheet">
    <style>
        /* Enhanced styling for better visual appeal */
        :root {
            --primary-color: #3b82f6;
            --primary-dark: #2563eb;
            --primary-light: #93c5fd;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --neutral-color: #6b7280;
        }
        
        body {
            background-color: #f9fafb;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        
        .primary-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: 0.5rem 0.5rem 0 0;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .section-card {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
            border: 1px solid #e5e7eb;
            margin-bottom: 1.5rem;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .section-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .category-header {
            background-color: #f3f4f6;
            color: #1f2937;
            font-size: 1.2rem;
            font-weight: 600;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
        }
        
        .category-header::before {
            content: "";
            display: inline-block;
            width: 0.5rem;
            height: 1.5rem;
            background-color: var(--primary-color);
            margin-right: 0.75rem;
            border-radius: 1rem;
        }
        
        .field-row {
            display: flex;
            border-bottom: 1px solid #f3f4f6;
            padding: 0.75rem 1rem;
        }
        
        .field-row:hover {
            background-color: #f9fafb;
        }
        
        .field-label {
            flex: 0 0 40%;
            font-weight: 500;
            color: #4b5563;
        }
        
        .field-value {
            flex: 0 0 60%;
            color: #111827;
        }
        
        .empty-value {
            color: #9ca3af;
            font-style: italic;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
        }
        
        .badge-blue {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-primary {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-gray {
            background-color: #f3f4f6;
            color: #374151;
        }
        
        .data-card {
            background-color: #f9fafb;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            padding: 1rem;
            transition: all 0.2s ease;
        }
        
        .data-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transform: translateY(-2px);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #059669;
        }
        
        .btn-neutral {
            background-color: var(--neutral-color);
            color: white;
        }
        
        .btn-neutral:hover {
            background-color: #4b5563;
        }
        
        /* Print styles */
        @media print {
            body {
                font-size: 12px;
                background: white;
            }
            
            .container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
            
            button, a.btn {
                display: none !important;
            }
            
            .section-card {
                break-inside: avoid;
                margin-bottom: 1rem;
                page-break-inside: avoid;
                box-shadow: none;
                border: 1px solid #e5e7eb;
            }
            
            .primary-header {
                background: #3b82f6 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4 max-w-5xl w-full">
        <!-- Navigation buttons -->
        <div class="mb-4 flex flex-wrap gap-2 justify-end">
            <a href="census_records.php" class="btn btn-neutral">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Back to Records
            </a>
            <a href="manage_census.php?edit=<?= $person_id ?>" class="btn btn-success">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                Edit
            </a>
        </div>

        <!-- Main header with resident name -->
        <div class="primary-header">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold">
                        <?= htmlspecialchars(strtoupper($person['first_name'] . ' ' . 
                            ($person['middle_name'] ? strtoupper($person['middle_name']) . ' ' : '') . 
                            strtoupper($person['last_name']) . 
                            ($person['suffix'] ? ' ' . strtoupper($person['suffix']) : ''))) ?>
                    </h1>
                    <div class="text-sm text-blue-100 flex flex-wrap gap-4 mt-1">
                        <span>ID: <?= $person['id'] ?></span>
                        <span>Age: <?= $person['age'] ?></span>
                        <span>Sex: <?= $person['gender'] ?></span>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <span class="badge badge-primary">
                    <?= $person['resident_type'] ?>
                </span>
                <?php if ($person['is_household_head']): ?>
                    <span class="badge badge-primary">Head of Family</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main content -->
        <div class="bg-white rounded-b-lg shadow-md border border-gray-200 border-t-0 max-w-5xl w-full mx-auto">
            <!-- Personal Information Section -->
            <div class="p-6">
                <h2 class="section-header">Personal Information</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Left column -->
                    <div>
                        <div class="field-row">
                            <span class="field-label">First Name</span>
                            <span class="field-value"><?= htmlspecialchars($person['first_name']) ?></span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Last Name</span>
                            <span class="field-value"><?= htmlspecialchars($person['last_name']) ?></span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Middle Name</span>
                            <span class="field-value <?= empty($person['middle_name']) ? 'empty-value' : '' ?>">
                                <?= htmlspecialchars($person['middle_name'] ?? 'Not provided') ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Suffix</span>
                            <span class="field-value <?= empty($person['suffix']) ? 'empty-value' : '' ?>">
                                <?= htmlspecialchars($person['suffix'] ?? 'None') ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Sex</span>
                            <span class="field-value">
                                <span class="badge badge-primary">
                                    <?= htmlspecialchars($person['gender']) ?>
                                </span>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Birth Date</span>
                            <span class="field-value">
                                <?= date('F j, Y', strtotime($person['birth_date'])) ?> 
                                <span class="text-sm text-gray-500">(<?= $person['age'] ?> years)</span>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Birth Place</span>
                            <span class="field-value"><?= htmlspecialchars($person['birth_place']) ?></span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Civil Status</span>
                            <span class="field-value">
                                <span class="badge badge-primary"><?= htmlspecialchars($person['civil_status']) ?></span>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Right column -->
                    <div>
                        <div class="field-row">
                            <span class="field-label">Citizenship</span>
                            <span class="field-value"><?= htmlspecialchars($person['citizenship']) ?></span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Religion</span>
                            <span class="field-value <?= empty($person['religion']) ? 'empty-value' : '' ?>">
                                <?= htmlspecialchars($person['religion'] ?? 'Not specified') ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Education Level</span>
                            <span class="field-value <?= empty($person['education_level']) ? 'empty-value' : '' ?>">
                                <?= htmlspecialchars($person['education_level'] ?? 'Not specified') ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Contact Number</span>
                            <span class="field-value <?= empty($person['contact_number']) ? 'empty-value' : '' ?>">
                                <?= htmlspecialchars($person['contact_number'] ?? 'Not provided') ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Occupation</span>
                            <span class="field-value <?= empty($person['occupation']) ? 'empty-value' : '' ?>">
                                <?= htmlspecialchars($person['occupation'] ?? 'Not specified') ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Monthly Income</span>
                            <span class="field-value text-green-600 font-medium">
                                <?= displayCurrency($person['monthly_income']) ?>
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Years of Residency</span>
                            <span class="field-value">
                                <?= $person['years_of_residency'] ?> years
                            </span>
                        </div>
                        
                        <div class="field-row">
                            <span class="field-label">Resident Type</span>
                            <span class="field-value">
                                <span class="badge badge-primary">
                                    <?= htmlspecialchars($person['resident_type']) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Address Section -->
            <?php if (!empty($addresses)): ?>
            <div class="border-t border-gray-200 p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="section-header mb-0">Address Information</h2>
                    <?php if (!empty($addresses) && isset($addresses[0]['barangay_name'])): ?>
                    <div class="text-sm">
                        <span class="badge badge-blue">Barangay: <?= htmlspecialchars($addresses[0]['barangay_name']) ?></span>
                        <?php if (!empty($person['purok_name'])): ?>
                        <span class="badge badge-blue ml-2">Purok: <?= htmlspecialchars($person['purok_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($addresses as $address): ?>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <div class="flex justify-between items-start mb-3">
                                <h4 class="text-md font-semibold text-gray-800 flex items-center">
                                    <svg class="w-4 h-4 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                    </svg>
                                    <?= $address['is_primary'] ? 'Present Address' : 'Permanent Address' ?>
                                </h4>
                                <span class="badge <?= $address['is_primary'] ? 'badge-primary' : 'badge-blue' ?>">
                                    <?= $address['is_primary'] ? 'Primary' : 'Secondary' ?>
                                </span>
                            </div>
                            
                            <div class="space-y-2 text-sm">
                                <?php if (!empty($address['house_no']) || !empty($address['street'])): ?>
                                <div>
                                    <span class="text-gray-600 font-medium">House/Street:</span> 
                                    <?= htmlspecialchars($address['house_no'] ?? '') ?> <?= htmlspecialchars($address['street'] ?? '') ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($address['phase'])): ?>
                                <div>
                                    <span class="text-gray-600 font-medium">Phase:</span> 
                                    <?= htmlspecialchars($address['phase']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($address['barangay_name'])): ?>
                                <div>
                                    <span class="text-gray-600 font-medium">Barangay:</span> 
                                    <?= htmlspecialchars($address['barangay_name']) ?>
                                </div>
                                <?php endif; ?>
                                
                                <div>
                                    <span class="text-gray-600 font-medium">Municipality:</span> 
                                    <?= htmlspecialchars($address['municipality']) ?>
                                </div>
                                
                                <div>
                                    <span class="text-gray-600 font-medium">Province:</span> 
                                    <?= htmlspecialchars($address['province']) ?>
                                </div>
                                
                                <div>
                                    <span class="text-gray-600 font-medium">Region:</span> 
                                    <?= htmlspecialchars($address['region']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Household Section -->
            <?php if (!empty($person['household_number']) || !empty($person['relationship_name']) || $person['is_household_head']): ?>
            <div class="border-t border-gray-200 p-6">
                <h2 class="section-header">Household Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">Household Number</span>
                        <div class="mt-1">
                            <span class="badge badge-blue text-lg py-1 px-3">
                                <?= htmlspecialchars($person['household_number'] ?? 'Not assigned') ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">Relationship to Head</span>
                        <div class="mt-1 text-gray-900 font-medium">
                            <?= htmlspecialchars($person['relationship_name'] ?? 'Not specified') ?>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">Household Head</span>
                        <div class="mt-1">
                            <?php if ($person['is_household_head']): ?>
                                <span class="badge badge-primary">Yes</span>
                            <?php else: ?>
                                <span class="badge badge-gray">No</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Family Composition Section -->
            <?php if (!empty($family_members)): ?>
            <div class="border-t border-gray-200 p-6">
                <h2 class="section-header">Family Composition</h2>
                <div class="overflow-x-auto mb-2">
                    <table class="min-w-full bg-white border border-gray-200 text-sm">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border border-gray-200 px-4 py-2 text-left">Name</th>
                                <th class="border border-gray-200 px-4 py-2 text-left">Relationship</th>
                                <th class="border border-gray-200 px-4 py-2 text-center">Age</th>
                                <th class="border border-gray-200 px-4 py-2 text-left">Civil Status</th>
                                <th class="border border-gray-200 px-4 py-2 text-left">Occupation</th>
                                <th class="border border-gray-200 px-4 py-2 text-right">Income</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($family_members as $member): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="border border-gray-200 px-4 py-2">
                                        <?= htmlspecialchars($member['name']) ?>
                                    </td>
                                    <td class="border border-gray-200 px-4 py-2">
                                        <?= htmlspecialchars($member['relationship']) ?>
                                    </td>
                                    <td class="border border-gray-200 px-4 py-2 text-center">
                                        <?= htmlspecialchars($member['age']) ?>
                                    </td>
                                    <td class="border border-gray-200 px-4 py-2">
                                        <?= htmlspecialchars($member['civil_status']) ?>
                                    </td>
                                    <td class="border border-gray-200 px-4 py-2">
                                        <?= htmlspecialchars($member['occupation']) ?>
                                    </td>
                                    <td class="border border-gray-200 px-4 py-2 text-right">
                                        <?= !empty($member['monthly_income']) ? displayCurrency($member['monthly_income']) : 'Not specified' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Government ID Section -->
            <?php if ($identification && ( !empty($identification['osca_id']) || !empty($identification['gsis_id']) || !empty($identification['sss_id']) || !empty($identification['tin_id']) || !empty($identification['philhealth_id']) || (!empty($identification['other_id_type']) && !empty($identification['other_id_number'])) )): ?>
            <div class="border-t border-gray-200 p-6">
                <h2 class="section-header">Government Identification</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php if (!empty($identification['osca_id'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">OSCA ID</span>
                        <div class="mt-1 text-gray-900 font-medium"><?= htmlspecialchars($identification['osca_id']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($identification['gsis_id'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">GSIS ID</span>
                        <div class="mt-1 text-gray-900 font-medium"><?= htmlspecialchars($identification['gsis_id']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($identification['sss_id'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">SSS ID</span>
                        <div class="mt-1 text-gray-900 font-medium"><?= htmlspecialchars($identification['sss_id']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($identification['tin_id'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">TIN ID</span>
                        <div class="mt-1 text-gray-900 font-medium"><?= htmlspecialchars($identification['tin_id']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($identification['philhealth_id'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">PhilHealth ID</span>
                        <div class="mt-1 text-gray-900 font-medium"><?= htmlspecialchars($identification['philhealth_id']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($identification['other_id_type']) && !empty($identification['other_id_number'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium"><?= htmlspecialchars($identification['other_id_type']) ?></span>
                        <div class="mt-1 text-gray-900 font-medium"><?= htmlspecialchars($identification['other_id_number']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Government Programs Section -->
            <?php if ($government_programs && ( !empty($government_programs['nhts_pr_listahanan']) || !empty($government_programs['indigenous_people']) || !empty($government_programs['pantawid_beneficiary']) )): ?>
            <div class="border-t border-gray-200 p-6">
                <h2 class="section-header">Government Programs Participation</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php if (!empty($government_programs['nhts_pr_listahanan'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">NHTS-PR Listahanan</span>
                        <div class="mt-1">
                            <span class="badge badge-primary">Yes</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($government_programs['indigenous_people'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">Indigenous People</span>
                        <div class="mt-1">
                            <span class="badge badge-primary">Yes</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($government_programs['pantawid_beneficiary'])): ?>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <span class="text-sm text-gray-600 font-medium">Pantawid Beneficiary</span>
                        <div class="mt-1">
                            <span class="badge badge-primary">Yes</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Assets and Properties Section -->
        <?php if (!empty($assets)): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Assets and Properties</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
                <?php foreach ($assets as $asset): ?>
                    <div class="data-card hover:bg-white">
                        <div class="flex items-start">
                            <div class="mr-3 mt-1 text-indigo-500">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4zm3 1h6v4H7V5zm8 8v2h1v1H4v-1h1v-2H4v-1h16v1h-1z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($asset['asset_name']) ?></h4>
                                <?php if (!empty($asset['details'])): ?>
                                    <p class="text-sm text-gray-600"><?= htmlspecialchars($asset['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Income Sources Section -->
        <?php if (!empty($income_sources)): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Income Sources</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
                <?php foreach ($income_sources as $source): ?>
                    <div class="data-card hover:bg-white">
                        <div class="flex items-start">
                            <div class="mr-3 mt-1 text-yellow-500">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 002-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($source['source_name']) ?></h4>
                                <?php if ($source['amount']): ?>
                                    <p class="text-sm text-green-600 font-medium">Amount: <?= displayCurrency($source['amount']) ?></p>
                                <?php endif; ?>
                                <?php if ($source['details']): ?>
                                    <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($source['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Living Arrangements Section -->
        <?php if (!empty($living_arrangements)): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Living Arrangements</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
                <?php foreach ($living_arrangements as $arrangement): ?>
                    <div class="data-card hover:bg-white">
                        <div class="flex items-start">
                            <div class="mr-3 mt-1 text-blue-500">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($arrangement['arrangement_name']) ?></h4>
                                <?php if ($arrangement['details']): ?>
                                    <p class="text-sm text-gray-600"><?= htmlspecialchars($arrangement['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Skills and Capabilities Section -->
        <?php if (!empty($skills)): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Skills and Capabilities</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
                <?php foreach ($skills as $skill): ?>
                    <div class="data-card hover:bg-white">
                        <div class="flex items-start">
                            <div class="mr-3 mt-1 text-green-500">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($skill['skill_name']) ?></h4>
                                <?php if ($skill['details']): ?>
                                    <p class="text-sm text-gray-600"><?= htmlspecialchars($skill['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Community Involvements Section -->
        <?php if (!empty($involvements)): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Community Involvements</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
                <?php foreach ($involvements as $involvement): ?>
                    <div class="data-card hover:bg-white">
                        <div class="flex items-start">
                            <div class="mr-3 mt-1 text-purple-500">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($involvement['involvement_name']) ?></h4>
                                <?php if ($involvement['details']): ?>
                                    <p class="text-sm text-gray-600"><?= htmlspecialchars($involvement['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Health Information Section -->
        <?php 
        $has_health_info = false;
        if ($health_info) {
            foreach ($health_info as $v) {
                if (!empty($v) && $v !== '0') {
                    $has_health_info = true;
                    break;
                }
            }
        }
        ?>
        <?php /* Health Information section removed as it duplicates Health Problems */ ?>

        <!-- Problems and Concerns Section -->
        <?php 
        $has_problems = false;
        if ($economic_problems) {
            foreach($economic_problems as $v) { if (!empty($v)) { $has_problems = true; break; } }
        }
        if ($social_problems && !$has_problems) {
            foreach($social_problems as $v) { if (!empty($v)) { $has_problems = true; break; } }
        }
        if ($health_problems && !$has_problems) {
            foreach($health_problems as $v) { if (!empty($v)) { $has_problems = true; break; } }
        }
        if ($housing_problems && !$has_problems) {
            foreach($housing_problems as $v) { if (!empty($v)) { $has_problems = true; break; } }
        }
        if ($community_problems && !$has_problems) {
            foreach($community_problems as $v) { if (!empty($v)) { $has_problems = true; break; } }
        }
        ?>
        <?php if ($has_problems): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Problems and Concerns</h2>
            <div class="p-4">
                <!-- Economic Problems -->
                <div class="mb-6">
                        <h3 class="text-lg font-semibold text-blue-700 mb-4 pb-1 border-b border-blue-100 flex items-center">
                            <svg class="w-5 h-5 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                            </svg>
                            Economic Problems
                        </h3>
                        <?php 
                        $has_economic_data = false;
                        if (is_array($economic_problems)) {
                            foreach($economic_problems as $key => $value) {
                                if (!empty($value)) {
                                    $has_economic_data = true;
                                    break;
                                }
                            }
                        }
                        
                        if ($has_economic_data): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php if (!empty($economic_problems['loss_income'])): ?>
                            <div class="data-card hover:bg-white">
                                <div class="flex items-start">
                                    <div class="mr-3 mt-1 text-red-500">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M12 13a1 1 0 100 2h5a1 1 0 001-1V9a1 1 0 10-2 0v2.586l-4.293-4.293a1 1 0 00-1.414 0L8 9.586 3.707 5.293a1 1 0 00-1.414 1.414l5 5a1 1 0 001.414 0L11 9.414 14.586 13H12z" clip-rule="evenodd"></path>
                                        </svg>
                            </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-2">Loss of Income</h4>
                            </div>
                        </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($economic_problems['unemployment'])): ?>
                            <div class="data-card hover:bg-white">
                                <div class="flex items-start">
                                    <div class="mr-3 mt-1 text-red-500">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                            <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-2">Unemployment</h4>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($economic_problems['skills_training'])): ?>
                            <div class="data-card hover:bg-white">
                                <div class="flex items-start">
                                    <div class="mr-3 mt-1 text-yellow-500">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-2">Skills Training Needed</h4>
                                        <?php if (!empty($economic_problems['skills_training_details'])): ?>
                                            <p class="text-sm text-gray-600 mt-2"><?= htmlspecialchars($economic_problems['skills_training_details']) ?></p>
                                        <?php endif; ?>
                            </div>
                        </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($economic_problems['livelihood'])): ?>
                            <div class="data-card hover:bg-white">
                                <div class="flex items-start">
                                    <div class="mr-3 mt-1 text-yellow-500">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                            <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-2">Livelihood Opportunities</h4>
                                        <?php if (!empty($economic_problems['livelihood_details'])): ?>
                                            <p class="text-sm text-gray-600 mt-2"><?= htmlspecialchars($economic_problems['livelihood_details']) ?></p>
                                        <?php endif; ?>
                            </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($economic_problems['other_economic'])): ?>
                            <div class="data-card hover:bg-white">
                                <div class="flex items-start">
                                    <div class="mr-3 mt-1 text-yellow-500">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-2">Other Economic Problems</h4>
                        <?php if (!empty($economic_problems['other_economic_details'])): ?>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($economic_problems['other_economic_details']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <p class="text-gray-500 py-4 text-center">No economic problems information available.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Social Problems -->
            <?php if ($social_problems): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-blue-700 mb-4 pb-1 border-b border-blue-100 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                        </svg>
                        Social Problems
                    </h3>
                    <?php 
                    $has_social_data = false;
                    foreach($social_problems as $key => $value) {
                        if (!empty($value)) {
                            $has_social_data = true;
                            break;
                        }
                    }
                    
                    if ($has_social_data): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (!empty($social_problems['loneliness'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                    </svg>
                            </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Loneliness</h4>
                                </div>
                        </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($social_problems['isolation'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h10v7h-2l-1 2H8l-1-2H5V5z" clip-rule="evenodd"></path>
                                    </svg>
                        </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Helplessness & Worthlessness</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($social_problems['neglect'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 1.944A11.954 11.954 0 012.166 5C2.056 5.649 2 6.319 2 7c0 5.225 3.34 9.67 8 11.317C14.66 16.67 18 12.225 18 7c0-.682-.057-1.35-.166-2.001A11.954 11.954 0 0110 1.944zM11 14a1 1 0 11-2 0 1 1 0 012 0zm0-7a1 1 0 10-2 0v3a1 1 0 102 0V7z" clip-rule="evenodd"></path>
                                    </svg>
                            </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Neglect & Rejection</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                                                    <?php if (!empty($social_problems['recreational'])): ?>
                            <div class="data-card hover:bg-white">
                                <div class="flex items-start">
                                    <div class="mr-3 mt-1 text-yellow-500">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800 mb-2">Recreational Activities</h4>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        
                        <?php if (!empty($social_problems['senior_friendly'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-yellow-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Senior Friendly Environment</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($social_problems['other_social'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-yellow-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Other Social Problems</h4>
                        <?php if (!empty($social_problems['other_social_details'])): ?>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($social_problems['other_social_details']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <p class="text-gray-500 py-4 text-center">No social problems information available.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Health Problems -->
            <?php if ($health_problems): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-blue-700 mb-4 pb-1 border-b border-blue-100 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd"></path>
                        </svg>
                        Health Problems
                    </h3>
                    <?php 
                    $has_health_data = false;
                    foreach($health_problems as $key => $value) {
                        if (!empty($value)) {
                            $has_health_data = true;
                            break;
                        }
                    }
                    
                    if ($has_health_data): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (!empty($health_problems['condition_illness'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"></path>
                                    </svg>
                            </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Condition/Illness</h4>
                        <?php if (!empty($health_problems['condition_illness_details'])): ?>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($health_problems['condition_illness_details']) ?></p>
                        <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health_problems['high_cost_medicine'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">High Cost Medicine</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health_problems['lack_medical_professionals'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Lack Medical Professionals</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health_problems['lack_sanitation'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744L14.146 7.2 17.5 9.134a1 1 0 010 1.732l-3.354 1.935-1.18 4.455a1 1 0 01-1.933 0L9.854 12.8 6.5 10.866a1 1 0 010-1.732l3.354-1.935 1.18-4.455A1 1 0 0112 2z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Lack Sanitation</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health_problems['lack_health_insurance'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 2a4 4 0 00-4 4v1H5a1 1 0 00-.994.89l-1 9A1 1 0 004 18h12a1 1 0 00.994-1.11l-1-9A1 1 0 0015 7h-1V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4zm-6 3a1 1 0 112 0 1 1 0 01-2 0zm7-1a1 1 0 100 2 1 1 0 000-2z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Lack Health Insurance</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health_problems['inadequate_health_services'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Inadequate Health Services</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($health_problems['other_health'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-yellow-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Other Health Problems</h4>
                        <?php if (!empty($health_problems['other_health_details'])): ?>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($health_problems['other_health_details']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <p class="text-gray-500 py-4 text-center">No health problems information available.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Housing Problems -->
            <?php if ($housing_problems): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-blue-700 mb-4 pb-1 border-b border-blue-100 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                        </svg>
                        Housing Problems
                    </h3>
                    <?php 
                    $has_housing_data = false;
                    foreach($housing_problems as $key => $value) {
                        if (!empty($value)) {
                            $has_housing_data = true;
                            break;
                        }
                    }
                    
                    if ($has_housing_data): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (!empty($housing_problems['overcrowding'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                                    </svg>
                            </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Overcrowding</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($housing_problems['no_permanent_housing'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 1.944A11.954 11.954 0 012.166 5C2.056 5.649 2 6.319 2 7c0 5.225 3.34 9.67 8 11.317C14.66 16.67 18 12.225 18 7c0-.682-.057-1.35-.166-2.001A11.954 11.954 0 0110 1.944zM11 14a1 1 0 11-2 0 1 1 0 012 0zm0-7a1 1 0 10-2 0v3a1 1 0 102 0V7z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">No Permanent Housing</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($housing_problems['independent_living'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-yellow-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Independent Living</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($housing_problems['lost_privacy'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"></path>
                                        <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Lost Privacy</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($housing_problems['squatters'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-red-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Squatters</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($housing_problems['other_housing'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-yellow-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Other Housing Problems</h4>
                        <?php if (!empty($housing_problems['other_housing_details'])): ?>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($housing_problems['other_housing_details']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <p class="text-gray-500 py-4 text-center">No housing problems information available.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Community Service Problems -->
            <?php if ($community_problems): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-blue-700 mb-4 pb-1 border-b border-blue-100 flex items-center">
                        <svg class="w-5 h-5 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                        </svg>
                        Community Service
                    </h3>
                    <?php 
                    $has_community_data = false;
                    foreach($community_problems as $key => $value) {
                        if (!empty($value)) {
                            $has_community_data = true;
                            break;
                        }
                    }
                    
                    if ($has_community_data): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if (!empty($community_problems['desire_participate'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-green-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.43a2 2 0 001.106 1.79l.05.025A4 4 0 008.943 18h5.416a2 2 0 001.962-1.608l1.2-6A2 2 0 0015.56 8H12V4a2 2 0 00-2-2 1 1 0 00-1 1v.667a4 4 0 01-.8 2.4L6.8 7.933a4 4 0 00-.8 2.4z"></path>
                                    </svg>
                            </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Desire to Participate</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($community_problems['skills_to_share'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-green-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Skills to Share</h4>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($community_problems['other_community'])): ?>
                        <div class="data-card hover:bg-white">
                            <div class="flex items-start">
                                <div class="mr-3 mt-1 text-yellow-500">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800 mb-2">Other Community Concerns</h4>
                        <?php if (!empty($community_problems['other_community_details'])): ?>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($community_problems['other_community_details']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                        <p class="text-gray-500 py-4 text-center">No community service information available.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- Other Needs and Concerns Section -->
        <?php if (!empty($other_needs)): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header">Other Needs and Concerns</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-4">
                <?php foreach ($other_needs as $need): ?>
                    <div class="data-card hover:bg-white">
                        <div class="flex items-start">
                            <div class="mr-3 mt-1 text-red-500">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <h4 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($need['need_name']) ?></h4>
                                </div>
                                <p class="text-sm text-gray-600 mb-2">Category: <?= htmlspecialchars(ucfirst($need['need_category'])) ?></p>
                                <?php if (!empty($need['details'])): ?>
                                    <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($need['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Child Information (if applicable) -->
        <?php if ($child_info): ?>
        <div class="section-card max-w-5xl w-full mx-auto">
            <h2 class="category-header flex items-center gap-2">
                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 14l9-5-9-5-9 5 9 5z"/><path d="M12 14l6.16-3.422A12.083 12.083 0 0112 21.5a12.083 12.083 0 01-6.16-10.922L12 14z"/></svg>
                Child-Specific Information
            </h2>
            <div class="p-6 space-y-8">
                <!-- Educational Information -->
                <?php if (
                    $child_info['attending_school'] !== null ||
                    !empty($child_info['school_type']) ||
                    !empty($child_info['school_name']) ||
                    !empty($child_info['grade_level']) ||
                    !empty($child_info['occupation']) ||
                    !empty($person['relationship_name'])
                ): ?>
                <div class="bg-gray-50 rounded-lg p-4 mb-4 shadow">
                    <div class="flex items-center mb-4 gap-2">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 14l9-5-9-5-9 5 9 5z"/><path d="M12 14l6.16-3.422A12.083 12.083 0 0112 21.5a12.083 12.083 0 01-6.16-10.922L12 14z"/></svg>
                        <h3 class="text-lg font-semibold">Educational Information</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php if ($child_info['attending_school'] !== null): ?>
                        <div class="flex items-center">
                            <span class="font-medium">Attending School:</span>
                            <?php if ($child_info['attending_school']): ?>
                                <span class="ml-2 inline-flex items-center px-2 py-1 bg-green-100 text-green-800 rounded-full"><svg class="w-4 h-4 mr-1 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>YES</span>
                            <?php else: ?>
                                <span class="ml-2 inline-flex items-center px-2 py-1 bg-red-100 text-red-800 rounded-full"><svg class="w-4 h-4 mr-1 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>NO</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($child_info['school_type'])): ?>
                        <div><span class="font-medium">School Type:</span> <span class="ml-2 text-gray-900 font-semibold"><?= htmlspecialchars($child_info['school_type']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($child_info['school_name'])): ?>
                        <div><span class="font-medium">School Name:</span> <span class="ml-2 text-gray-900 font-semibold"><?= htmlspecialchars($child_info['school_name']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($child_info['grade_level'])): ?>
                        <div><span class="font-medium">Grade/Level:</span> <span class="ml-2 text-gray-900 font-semibold"><?= htmlspecialchars($child_info['grade_level']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($child_info['occupation'])): ?>
                        <div><span class="font-medium">Occupation:</span> <span class="ml-2 text-gray-900 font-semibold"><?= htmlspecialchars($child_info['occupation']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($person['relationship_name'])): ?>
                        <div><span class="font-medium">Relationship to Household Head:</span> <span class="ml-2 text-gray-900 font-semibold"><?= htmlspecialchars($person['relationship_name']) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Health/Nutrition -->
                <?php if (
                    isset($child_info['is_malnourished']) ||
                    isset($child_info['immunization_complete']) ||
                    isset($child_info['garantisadong_pambata']) ||
                    isset($child_info['has_timbang_operation']) ||
                    isset($child_info['has_supplementary_feeding']) ||
                    isset($child_info['under_six_years']) ||
                    isset($child_info['grade_school'])
                ): ?>
                <div class="bg-gray-50 rounded-lg p-4 mb-4 shadow">
                    <div class="flex items-center mb-4 gap-2">
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                        <h3 class="text-lg font-semibold">Health & Nutrition</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php
                        $health_fields = [
                            'is_malnourished' => 'Malnourished',
                            'immunization_complete' => 'Immunization Complete',
                            'garantisadong_pambata' => 'Garantizadong Pambata',
                            'has_timbang_operation' => 'Operation Timbang',
                            'has_supplementary_feeding' => 'Supplementary Feeding',
                            'under_six_years' => '0-71 mos / Under 6 Years',
                            'grade_school' => 'Grade School',
                        ];
                        foreach ($health_fields as $key => $label):
                        if (isset($child_info[$key])): ?>
                        <div class="flex items-center">
                            <span class="font-medium"><?= $label ?>:</span>
                            <?php if ($child_info[$key]): ?>
                                <span class="ml-2 inline-flex items-center px-2 py-1 bg-green-100 text-green-800 rounded-full"><svg class="w-4 h-4 mr-1 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>YES</span>
                            <?php else: ?>
                                <span class="ml-2 inline-flex items-center px-2 py-1 bg-red-100 text-red-800 rounded-full"><svg class="w-4 h-4 mr-1 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>NO</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Diseases -->
                <?php if (!empty($child_health_conditions)): ?>
                <div class="bg-gray-50 rounded-lg p-4 mb-4 shadow">
                    <div class="flex items-center mb-4 gap-2">
                        <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                        <h3 class="text-lg font-semibold">Diseases</h3>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($child_health_conditions as $condition): ?>
                            <span class="inline-block bg-red-100 text-red-800 px-3 py-1 rounded-full text-xs font-semibold">
                                <?= htmlspecialchars($condition['condition_type']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Child Welfare Status -->
                <?php if (
                    isset($child_info['in_caring_institution']) ||
                    isset($child_info['is_under_foster_care']) ||
                    isset($child_info['is_directly_entrusted']) ||
                    isset($child_info['is_legally_adopted'])
                ): ?>
                <div class="bg-gray-50 rounded-lg p-4 mb-4 shadow">
                    <div class="flex items-center mb-4 gap-2">
                        <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                        <h3 class="text-lg font-semibold">Child Welfare Status</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php
                        $welfare_fields = [
                            'in_caring_institution' => 'Caring Institution',
                            'is_under_foster_care' => 'Under Foster Care',
                            'is_directly_entrusted' => 'Directly Entrusted',
                            'is_legally_adopted' => 'Legally Adopted',
                        ];
                        foreach ($welfare_fields as $key => $label):
                        if (isset($child_info[$key])): ?>
                        <div class="flex items-center">
                            <span class="font-medium"><?= $label ?>:</span>
                            <?php if ($child_info[$key]): ?>
                                <span class="ml-2 inline-flex items-center px-2 py-1 bg-green-100 text-green-800 rounded-full"><svg class="w-4 h-4 mr-1 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>YES</span>
                            <?php else: ?>
                                <span class="ml-2 inline-flex items-center px-2 py-1 bg-red-100 text-red-800 rounded-full"><svg class="w-4 h-4 mr-1 text-red-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>NO</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Disabilities -->
                <?php if (!empty($child_disabilities)): ?>
                <div class="bg-gray-50 rounded-lg p-4 mb-4 shadow">
                    <div class="flex items-center mb-4 gap-2">
                        <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                        <h3 class="text-lg font-semibold">Disabilities</h3>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($child_disabilities as $disability): ?>
                            <span class="inline-block bg-orange-100 text-orange-800 px-3 py-1 rounded-full text-xs font-semibold">
                                <?= htmlspecialchars($disability['disability_type']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script>
        // Print styles
        window.addEventListener('beforeprint', function() {
            document.body.classList.add('print-mode');
        });

        window.addEventListener('afterprint', function() {
            document.body.classList.remove('print-mode');
        });
    </script>
</body>
</html>