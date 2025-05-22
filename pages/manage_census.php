<?php

use \PDO;
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
    SELECT household_id, household_head_person_id 
    FROM Household 
    WHERE barangay_id = ? 
    ORDER BY household_id
");
$stmt->execute([$barangay_id]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing census data with detailed information
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        h.household_id, 
        hm.relationship_to_head, 
        hm.is_household_head,
        CONCAT(a.house_no, ' ', a.street, ', ', b.barangay_name) as address,
        TIMESTAMPDIFF(YEAR, p.birth_date, CURDATE()) as age
    FROM Person p
    JOIN HouseholdMember hm ON p.person_id = hm.person_id
    JOIN Household h ON hm.household_id = h.household_id
    JOIN Barangay b ON h.barangay_id = b.barangay_id
    LEFT JOIN Address a ON p.person_id = a.person_id AND a.is_primary = 1
    WHERE h.barangay_id = ?
    ORDER BY p.last_name, p.first_name
");
$stmt->execute([$barangay_id]);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangay details for header
$stmt = $pdo->prepare("SELECT barangay_name FROM Barangay WHERE barangay_id = ?");
$stmt->execute([$barangay_id]);
$barangay = $stmt->fetch(PDO::FETCH_ASSOC);
require_once "../pages/header.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Census Data</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <?php include "../pages/header.php"; ?>

        <!-- Tab Navigation -->
        <div class="mb-6 mt-6">
            <div class="border-b border-gray-200">
                <ul class="flex flex-wrap -mb-px">
                    <li class="mr-2">
                        <a href="#" class="tab-link inline-block p-4 border-b-2 rounded-t-lg border-blue-600 text-blue-600" data-tab="add-resident">Add New Resident</a>
                    </li>
                    <li class="mr-2">
                        <a href="#" class="tab-link inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-600 hover:border-gray-300" data-tab="add-senior">Add Senior Citizen</a>
                    </li>
                    <li class="mr-2">
                        <a href="#" class="tab-link inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-600 hover:border-gray-300" data-tab="add-child">Add Child (0-17)</a>
                    </li>
                    <li class="mr-2">
                        <a href="#" class="tab-link inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-600 hover:border-gray-300" data-tab="census-list">Census Records</a>
                    </li>
                    <li>
                        <a href="#" class="tab-link inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-600 hover:border-gray-300" data-tab="add-household">Manage Households</a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Regular Resident Form -->
        <div id="add-resident" class="tab-content active bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-2xl font-bold mb-4">Add New Resident</h2>
            
            <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Personal Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Personal Information</h3>
                    <div>
                        <label class="block text-sm font-medium">First Name *</label>
                        <input type="text" name="first_name" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Middle Name</label>
                        <input type="text" name="middle_name" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Last Name *</label>
                        <input type="text" name="last_name" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Suffix</label>
                        <input type="text" name="suffix" placeholder="Jr, Sr, III, etc."
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Date of Birth *</label>
                        <input type="date" name="birth_date" required 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Place of Birth</label>
                        <input type="text" name="birth_place" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-medium">Gender *</label>
                        <div class="flex gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Male" required 
                                       class="form-radio">
                                <span class="ml-2">Male</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Female" 
                                       class="form-radio">
                                <span class="ml-2">Female</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="gender" value="Others" 
                                       class="form-radio">
                                <span class="ml-2">Others</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Additional Information</h3>
                    <div>
                        <label class="block text-sm font-medium">Civil Status *</label>
                        <select name="civil_status" required class="mt-1 block w-full border rounded p-2">
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Widowed">Widowed</option>
                            <option value="Separated">Separated</option>
                            <option value="Widow/Widower">Widow/Widower</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Citizenship</label>
                        <input type="text" name="citizenship" value="Filipino" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Religion</label>
                        <select name="religion" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Religion --</option>
                            <option value="Roman Catholic">Roman Catholic</option>
                            <option value="Protestant">Protestant</option>
                            <option value="Iglesia Ni Cristo">Iglesia Ni Cristo</option>
                            <option value="Islam">Islam</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Educational Attainment</label>
                        <select name="education_level" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Education Level --</option>
                            <option value="Not Attended Any School">Not Attended Any School</option>
                            <option value="Elementary Level">Elementary Level</option>
                            <option value="Elementary Graduate">Elementary Graduate</option>
                            <option value="High School Level">High School Level</option>
                            <option value="High School Graduate">High School Graduate</option>
                            <option value="Vocational">Vocational</option>
                            <option value="College Level">College Level</option>
                            <option value="College Graduate">College Graduate</option>
                            <option value="Post Graduate">Post Graduate</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Occupation</label>
                        <input type="text" name="occupation" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Monthly Income</label>
                        <select name="monthly_income" class="mt-1 block w-full border rounded p-2">
                            <option value="">-- Select Income Range --</option>
                            <option value="0">No Income</option>
                            <option value="999">999 & below</option>
                            <option value="1500">1,000-1,999</option>
                            <option value="2500">2,000-2,999</option>
                            <option value="3500">3,000-3,999</option>
                            <option value="4500">4,000-4,999</option>
                            <option value="5500">5,000-5,999</option>
                            <option value="6500">6,000-6,999</option>
                            <option value="7500">7,000-7,999</option>
                            <option value="8500">8,000-8,999</option>
                            <option value="9500">9,000-9,999</option>
                            <option value="10000">10,000 & above</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Contact Number</label>
                        <input type="text" name="contact_number" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                </div>

                <!-- Address Information -->
                <div class="space-y-4">
                    <h3 class="font-semibold text-lg">Address Information</h3>
                    <div>
                        <label class="block text-sm font-medium">House No.</label>
                        <input type="text" name="house_no" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Street</label>
                        <input type="text" name="street" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Subdivision/Purok/Zone/Sitio</label>
                        <input type="text" name="subdivision" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Block/Lot</label>
                        <input type="text" name="block_lot" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Phase</label>
                        <input type="text" name="phase" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">City/Municipality</label>
                        <input type="text" name="municipality" value="SAN RAFAEL" 
                               class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Province</label>
                        <input type="text" name="province" value="BULACAN" 
                               class="mt-1 block w-full border rounded p-2" readonly>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Residency Type</label>
                        <select name="residency_type" class="mt-1 block w-full border rounded p-2">
                            <option value="Home Owner">Home Owner</option>
                            <option value="Renter">Renter</option>
                            <option value="Sharer">Sharer</option>
                            <option value="Care Taker">Care Taker</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium">Years in San Rafael</label>
                        <input type="number" name="years_in_san_rafael" min="0" max="100" 
                               class="mt-1 block w-full border rounded p-2">
                    </div>
                </div>

                <!-- Household Information -->
                <div class="space-y-4 md:col-span-3 border-t border-gray-200 pt-4 mt-6">
                    <h3 class="font-semibold text-lg">Household Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Household ID *</label>
                            <select name="household_id" required class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Household --</option>
                                <?php foreach ($households as $household): ?>
                                    <option value="<?= htmlspecialchars($household['household_id']) ?>">
                                        <?= htmlspecialchars($household['household_id']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">If household is not listed, create it in the Manage Households tab</div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium">Relationship to Head</label>
                            <select name="relationship" class="mt-1 block w-full border rounded p-2">
                                <option value="Head">Head</option>
                                <option value="Spouse">Spouse</option>
                                <option value="Child">Child</option>
                                <option value="Parent">Parent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Grandchild">Grandchild</option>
                                <option value="Other Relative">Other Relative</option>
                                <option value="Non-relative">Non-relative</option>
                            </select>
                        </div>
                        
                        <div class="flex items-center">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="is_household_head" class="form-checkbox">
                                <span class="ml-2 text-sm font-medium">Is Household Head</span>
                            </label>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                            Save Resident Data
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Senior Citizen Form -->
        <div id="add-senior" class="tab-content bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-2xl font-bold mb-4">Add Senior Citizen (60 Years Old & Above)</h2>
            
            <form method="POST" class="space-y-8">
                <!-- Personal Information -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-semibold mb-4">I. Personal Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Last Name *</label>
                            <input type="text" name="last_name" required 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">First Name *</label>
                            <input type="text" name="first_name" required 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Middle Name</label>
                            <input type="text" name="middle_name" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Suffix</label>
                            <input type="text" name="suffix" placeholder="Jr, Sr, III, etc."
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">House No.</label>
                            <input type="text" name="house_no" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Street</label>
                            <input type="text" name="street" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Name of Subdivision/Zone/Sitio/Purok</label>
                            <input type="text" name="subdivision" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">City/Municipality</label>
                            <input type="text" name="municipality" value="SAN RAFAEL" 
                                class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Province</label>
                            <input type="text" name="province" value="BULACAN" 
                                class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">Date of Birth *</label>
                            <input type="date" name="birth_date" required 
                                class="mt-1 block w-full border rounded p-2">
                            <div class="text-xs text-gray-500 mt-1">Format: mm-dd-yyyy</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Citizenship</label>
                            <input type="text" name="citizenship" value="Filipino" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">Place of Birth</label>
                            <input type="text" name="birth_place" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Sex *</label>
                            <div class="flex mt-2 space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="gender" value="Male" required class="form-radio">
                                    <span class="ml-2">Male</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="gender" value="Female" class="form-radio">
                                    <span class="ml-2">Female</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">Civil Status *</label>
                            <div class="flex mt-2 space-x-4">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Single" class="form-radio">
                                    <span class="ml-2">Single</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Married" class="form-radio">
                                    <span class="ml-2">Married</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Widowed" class="form-radio">
                                    <span class="ml-2">Widow/er</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="civil_status" value="Separated" class="form-radio">
                                    <span class="ml-2">Separated</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Contact Number</label>
                            <input type="text" name="contact_number" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">Educational Attainment</label>
                            <select name="education_level" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Education Level --</option>
                                <option value="Not Attended Any School">Not Attended Any School</option>
                                <option value="Elementary Level">Elementary Level</option>
                                <option value="Elementary Graduate">Elementary Graduate</option>
                                <option value="High School Level">High School Level</option>
                                <option value="High School Graduate">High School Graduate</option>
                                <option value="Vocational">Vocational</option>
                                <option value="College Level">College Level</option>
                                <option value="College Graduate">College Graduate</option>
                                <option value="Post Graduate">Post Graduate</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Religion</label>
                            <select name="religion" class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Religion --</option>
                                <option value="Roman Catholic">Roman Catholic</option>
                                <option value="Protestant">Protestant</option>
                                <option value="Iglesia Ni Cristo">Iglesia Ni Cristo</option>
                                <option value="Islam">Islam</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Government IDs Section -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-semibold mb-4">Government IDs</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">OSCA ID</label>
                            <input type="text" name="osca_id" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">TIN</label>
                            <input type="text" name="tin" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">PhilHealth</label>
                            <input type="text" name="philhealth" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">GSIS</label>
                            <input type="text" name="gsis" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">SSS</label>
                            <input type="text" name="sss" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Other ID (specify)</label>
                            <input type="text" name="other_id" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                </div>

                <!-- Areas of Specialization/Skills -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-semibold mb-4">Areas of Specialization/Skills (Check all applicable)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_medical" value="1" class="form-checkbox">
                                <span class="ml-2">Medical</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_farming" value="1" class="form-checkbox">
                                <span class="ml-2">Farming</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_teaching" value="1" class="form-checkbox">
                                <span class="ml-2">Teaching</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_fishing" value="1" class="form-checkbox">
                                <span class="ml-2">Fishing</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_legal" value="1" class="form-checkbox">
                                <span class="ml-2">Legal Services</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_cooking" value="1" class="form-checkbox">
                                <span class="ml-2">Cooking</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_dental" value="1" class="form-checkbox">
                                <span class="ml-2">Dental</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_vocational" value="1" class="form-checkbox">
                                <span class="ml-2">Vocational</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_counseling" value="1" class="form-checkbox">
                                <span class="ml-2">Counseling</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_arts" value="1" class="form-checkbox">
                                <span class="ml-2">Arts</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_evangelization" value="1" class="form-checkbox">
                                <span class="ml-2">Evangelization</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_engineering" value="1" class="form-checkbox">
                                <span class="ml-2">Engineering</span>
                            </label>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="skill_others" value="1" class="form-checkbox">
                            <span class="ml-2">Others, please specify:</span>
                            <input type="text" name="skill_others_details" 
                                class="ml-2 w-48 border rounded p-1">
                        </label>
                    </div>
                </div>
                
                <!-- Involvement in Community Activities -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-semibold mb-4">Involvement in Community Activities (Check all applicable)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_medical" value="1" class="form-checkbox">
                                <span class="ml-2">Medical</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_neighborhood" value="1" class="form-checkbox">
                                <span class="ml-2">Neighborhood Support Services</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_resource" value="1" class="form-checkbox">
                                <span class="ml-2">Resource Volunteer</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_religious" value="1" class="form-checkbox">
                                <span class="ml-2">Religious</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_beautification" value="1" class="form-checkbox">
                                <span class="ml-2">Community Beautification</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_counseling" value="1" class="form-checkbox">
                                <span class="ml-2">Counseling/referral</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_leader" value="1" class="form-checkbox">
                                <span class="ml-2">Community/Organizational Leader</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_sponsorship" value="1" class="form-checkbox">
                                <span class="ml-2">Sponsorship</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_dental" value="1" class="form-checkbox">
                                <span class="ml-2">Dental</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_legal" value="1" class="form-checkbox">
                                <span class="ml-2">Legal Services</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="involvement_visits" value="1" class="form-checkbox">
                                <span class="ml-2">Friendly Visits</span>
                            </label>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="involvement_others" value="1" class="form-checkbox">
                            <span class="ml-2">Others, specify:</span>
                            <input type="text" name="involvement_others_details" 
                                class="ml-2 w-48 border rounded p-1">
                        </label>
                    </div>
                </div>
                
                <!-- Problems/Needs Commonly Encountered -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-semibold mb-4">Problems/Needs Commonly Encountered (Check all applicable)</h3>
                    
                    <!-- Economic -->
                    <div class="mb-4">
                        <h4 class="font-medium">a. Economic</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="econ_lack" value="1" class="form-checkbox">
                                    <span class="ml-2">Lack of income/resources</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="econ_skills" value="1" class="form-checkbox">
                                    <span class="ml-2">Skills/Capability Training</span>
                                    <input type="text" name="econ_skills_details" placeholder="specify" 
                                        class="ml-2 w-32 border rounded p-1">
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="econ_loss" value="1" class="form-checkbox">
                                    <span class="ml-2">Loss of income/resources</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="econ_livelihood" value="1" class="form-checkbox">
                                    <span class="ml-2">Livelihood opportunities</span>
                                    <input type="text" name="econ_livelihood_details" placeholder="specify" 
                                        class="ml-2 w-32 border rounded p-1">
                                </label>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="econ_others" value="1" class="form-checkbox">
                                <span class="ml-2">Others, specify:</span>
                                <input type="text" name="econ_others_details" 
                                    class="ml-2 w-48 border rounded p-1">
                            </label>
                        </div>
                    </div>
                    
                    <!-- Social/Emotional -->
                    <div class="mb-4">
                        <h4 class="font-medium">b. Social/Emotional</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="emotional_neglect" value="1" class="form-checkbox">
                                    <span class="ml-2">Feeling of neglect & rejection</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="emotional_leisure" value="1" class="form-checkbox">
                                    <span class="ml-2">Inadequate leisure/recreational activities</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="emotional_helpless" value="1" class="form-checkbox">
                                    <span class="ml-2">Feeling of helplessness & worthlessness</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="emotional_environment" value="1" class="form-checkbox">
                                    <span class="ml-2">Senior Citizen Friendly Environment</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="emotional_lonely" value="1" class="form-checkbox">
                                    <span class="ml-2">Feeling of loneliness & isolation</span>
                                </label>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="emotional_others" value="1" class="form-checkbox">
                                <span class="ml-2">Others, specify:</span>
                                <input type="text" name="emotional_others_details" 
                                    class="ml-2 w-48 border rounded p-1">
                            </label>
                        </div>
                    </div>
                    
                    <!-- Health -->
                    <div class="mb-4">
                        <h4 class="font-medium">c. Health</h4>
                        <div class="mt-2">
                            <label class="block text-sm">Condition/Illnesses</label>
                            <textarea name="health_condition" rows="2" 
                                class="mt-1 block w-full border rounded p-2"></textarea>
                        </div>
                        
                        <div class="mt-2">
                            <label class="inline-flex items-center">
                                <span class="mr-4">With Maintenance:</span>
                                <input type="radio" name="has_maintenance" value="Yes" class="form-radio">
                                <span class="ml-2 mr-4">Yes</span>
                                <input type="radio" name="has_maintenance" value="No" class="form-radio">
                                <span class="ml-2">No</span>
                            </label>
                            <div class="mt-1">
                                <label class="block text-sm">If yes, please specify</label>
                                <input type="text" name="maintenance_details" 
                                    class="mt-1 block w-full border rounded p-2">
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-sm font-medium">Concerns/Issues:</label>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-1">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="high_cost_medicines" value="1" class="form-checkbox">
                                    <span class="ml-2">High cost medicines</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="lack_medical_professionals" value="1" class="form-checkbox">
                                    <span class="ml-2">Lack of medical professionals</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="lack_sanitation_access" value="1" class="form-checkbox">
                                    <span class="ml-2">Lack/No access to sanitation</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="lack_health_insurance" value="1" class="form-checkbox">
                                    <span class="ml-2">Lack/No health insurance/Inadequate health services</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="lack_medical_facilities" value="1" class="form-checkbox">
                                    <span class="ml-2">Lack of hospitals/medical facilities</span>
                                </label>
                            </div>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="other_health_concerns" value="1" class="form-checkbox">
                                    <span class="ml-2">Others, specify:</span>
                                    <input type="text" name="other_health_concerns_details" 
                                        class="ml-2 w-48 border rounded p-1">
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Housing -->
                    <div class="mb-4">
                        <h4 class="font-medium">d. Housing</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_overcrowding" value="1" class="form-checkbox">
                                <span class="ml-2">Overcrowding in the family home</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_privacy" value="1" class="form-checkbox">
                                <span class="ml-2">Lost privacy</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_no_permanent" value="1" class="form-checkbox">
                                <span class="ml-2">No permanent housing</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_squatter" value="1" class="form-checkbox">
                                <span class="ml-2">Living in squatter's area</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_independent" value="1" class="form-checkbox">
                                <span class="ml-2">Longing for independent living/quiet atmosphere</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_high_rent" value="1" class="form-checkbox">
                                <span class="ml-2">High cost rent</span>
                            </label>
                        </div>
                        <div class="mt-2">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="housing_others" value="1" class="form-checkbox">
                                <span class="ml-2">Others, specify:</span>
                                <input type="text" name="housing_others_details" 
                                    class="ml-2 w-48 border rounded p-1">
                            </label>
                        </div>
                    </div>
                    
                    <!-- Community Service -->
                    <div class="mb-4">
                        <h4 class="font-medium">e. Community Service</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2 mt-2">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="community_desire" value="1" class="form-checkbox">
                                <span class="ml-2">Desire to participate</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="community_skills" value="1" class="form-checkbox">
                                <span class="ml-2">Skills/resources to share</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="community_others" value="1" class="form-checkbox">
                                <span class="ml-2">Others, specify:</span>
                                <input type="text" name="community_others_details" 
                                    class="ml-2 w-32 border rounded p-1">
                            </label>
                        </div>
                    </div>
                    
                    <!-- Other Specific Needs -->
                    <div>
                        <h4 class="font-medium">f. Identify Others Specific Needs</h4>
                        <div class="mt-2">
                            <textarea name="other_specific_needs" rows="4" 
                                class="mt-1 block w-full border rounded p-2"></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Household Information and Submit -->
                <div class="mt-6">
                    <h3 class="text-xl font-semibold mb-4">Household Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Household ID *</label>
                            <select name="household_id" required class="mt-1 block w-full border rounded p-2">
                                <option value="">-- Select Household --</option>
                                <?php foreach ($households as $household): ?>
                                    <option value="<?= htmlspecialchars($household['household_id']) ?>">
                                        <?= htmlspecialchars($household['household_id']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-xs text-gray-500 mt-1">If household is not listed, create it in the Manage Households tab</div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium">Relationship to Head</label>
                            <select name="relationship" class="mt-1 block w-full border rounded p-2">
                                <option value="Head">Head</option>
                                <option value="Spouse">Spouse</option>
                                <option value="Child">Child</option>
                                <option value="Parent">Parent</option>
                                <option value="Sibling">Sibling</option>
                                <option value="Grandchild">Grandchild</option>
                                <option value="Other Relative">Other Relative</option>
                                <option value="Non-relative">Non-relative</option>
                            </select>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" name="is_household_head" id="senior_is_household_head" class="mr-2">
                            <label for="senior_is_household_head" class="text-sm font-medium">Is Household Head</label>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-between">
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                            Save Senior Citizen Data
                        </button>
                        <div class="text-gray-500 text-sm">
                            This form follows the DSWD Senior Citizen Intake Sheet format
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Child Form -->
        <div id="add-child" class="tab-content bg-white rounded-lg shadow-sm p-6 mb-8">
            <h2 class="text-2xl font-bold mb-4">Add Child (0-17 Years Old)</h2>
            
            <form method="POST" class="space-y-8">
                <!-- Personal Information -->
                <div class="border-b pb-6">
                    <h3 class="text-xl font-semibold mb-4">Personal Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium">Last Name *</label>
                            <input type="text" name="last_name" required 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">First Name *</label>
                            <input type="text" name="first_name" required 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Middle Name</label>
                            <input type="text" name="middle_name" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Suffix</label>
                            <input type="text" name="suffix" placeholder="Jr, Sr, III, etc."
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">House No.</label>
                            <input type="text" name="house_no" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Street</label>
                            <input type="text" name="street" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Name of Subdivision/Zone/Sitio/Purok</label>
                            <input type="text" name="subdivision" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">City/Municipality</label>
                            <input type="text" name="municipality" value="SAN RAFAEL" 
                                class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Province</label>
                            <input type="text" name="province" value="BULACAN" 
                                class="mt-1 block w-full border rounded p-2" readonly>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium">Date of Birth *</label>
                            <input type="date" name="birth_date" required 
                                class="mt-1 block w-full border rounded p-2">
                            <div class="text-xs text-gray-500 mt-1">Format: mm-dd-yyyy</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium">Citizenship</label>
                            <input type="text" name="citizenship" value="Filipino" 
                                class="mt-1 block w-full border rounded p-2">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_medical" value="1" class="form-checkbox">
                                <span class="ml-2">Medical</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_farming" value="1" class="form-checkbox">
                                <span class="ml-2">Farming</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_teaching" value="1" class="form-checkbox">
                                <span class="ml-2">Teaching</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_fishing" value="1" class="form-checkbox">
                                <span class="ml-2">Fishing</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_legal" value="1" class="form-checkbox">
                                <span class="ml-2">Legal Services</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_cooking" value="1" class="form-checkbox">
                                <span class="ml-2">Cooking</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_dental" value="1" class="form-checkbox">
                                <span class="ml-2">Dental</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_vocational" value="1" class="form-checkbox">
                                <span class="ml-2">Vocational</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_counseling" value="1" class="form-checkbox">
                                <span class="ml-2">Counseling</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_arts" value="1" class="form-checkbox">
                                <span class="ml-2">Arts</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_evangelization" value="1" class="form-checkbox">
                                <span class="ml-2">Evangelization</span>
                            </label>
                        </div>
                        <div>
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="skill_engineering" value="1" class="form-checkbox">
                                <span class="ml-2">Engineering</span>
                            </label>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="skill_others" value="1" class="form-checkbox">
                            <span class="ml-2">Others, please specify:</span>
                            <input type="text" name="skill_others_details" 
                                class="ml-2 w-48 border rounded p-1">
                        </label>
                    </div>
                </div>
                <!-- End Living/Residing With -->
                <!-- End of Child Form sections -->
            </form>
        </div>
        <!-- End Child Form -->

        <!-- Census List -->
        <div id="census-list" class="tab-content bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-2xl font-bold mb-4">Census Records</h2>
            <div class="mb-4 flex justify-between items-center">
                <div class="flex gap-2">
                    <button id="btn-all" class="px-4 py-2 bg-blue-600 text-white rounded">All</button>
                    <button id="btn-seniors" class="px-4 py-2 bg-gray-200 rounded">Seniors</button>
                    <button id="btn-children" class="px-4 py-2 bg-gray-200 rounded">Children</button>
                </div>
                <div>
                    <input type="text" id="search-resident" placeholder="Search by name..." 
                           class="px-4 py-2 border rounded w-64">
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Age</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Gender</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Civil Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Household ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Relationship</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($residents as $resident): 
                            $age = $resident['age'] ?? calculateAge($resident['birth_date']);
                            $category = '';
                            if ($age >= 60) {
                                $category = 'Senior';
                            } elseif ($age < 18) {
                                $category = 'Child';
                            } else {
                                $category = 'Adult';
                            }
                        ?>
                        <tr data-category="<?= $category ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?= htmlspecialchars("{$resident['last_name']}, {$resident['first_name']} " . 
                                    ($resident['middle_name'] ? substr($resident['middle_name'], 0, 1) . '.' : '') . 
                                    ($resident['suffix'] ? " {$resident['suffix']}" : '')) 
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $age ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $resident['gender'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $resident['civil_status'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= $resident['household_id'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?= $resident['relationship_to_head'] ?>
                                <?= $resident['is_household_head'] ? ' (Head)' : '' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?= $resident['address'] ?? 'No address provided' ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="<?= $category === 'Senior' ? 'bg-purple-100 text-purple-800' : 
                                    ($category === 'Child' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?> 
                                    px-2 py-1 rounded text-xs">
                                    <?= $category ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="view_resident.php?id=<?= $resident['person_id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                <a href="edit_resident.php?id=<?= $resident['person_id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <a href="javascript:void(0)" onclick="confirmDelete(<?= $resident['person_id'] ?>)" class="text-red-600 hover:text-red-900">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
