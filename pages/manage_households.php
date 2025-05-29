<?php
require "../config/dbconn.php";
require_once "../components/header.php";

// Function to generate Philippine HSN (Household Serial Number) based on actual PSA system
function generateHouseholdId($pdo, $barangay_id) {
    try {
        // Get barangay information
        $stmt = $pdo->prepare("SELECT id, name FROM barangay WHERE id = ?");
        $stmt->execute([$barangay_id]);
        $barangay = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$barangay) {
            throw new Exception("Invalid barangay");
        }
        
        // Real Philippine HSN system: Sequential 4-digit numbers per enumeration area
        // Each barangay is an enumeration area (or divided into EAs for large barangays)
        // Format: HSN 0001, 0002, 0003... per enumeration area
        
        // Get current household count for this barangay/enumeration area
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as household_count 
            FROM households 
            WHERE barangay_id = ?
        ");
        $stmt->execute([$barangay_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_hsn = $result['household_count'] + 1;
        
        // Philippine HSN format: 4-digit sequential number
        // Example: 0001, 0002, 0003... (actual PSA format)
        $household_id = sprintf('%04d', $next_hsn);
        
        // Check for special HSN ranges (avoid conflicts with special codes)
        if ($next_hsn >= 7777) {
            // If we reach special HSN ranges, use extended format
            $household_id = sprintf('H%04d', $next_hsn);
        }
        
        // Ensure uniqueness within this barangay
        $stmt = $pdo->prepare("SELECT id FROM households WHERE id = ? AND barangay_id = ?");
        $stmt->execute([$household_id, $barangay_id]);
        if ($stmt->fetch()) {
            // Fallback with timestamp if collision
            $household_id = sprintf('%04d-%s', $next_hsn, date('His'));
        }
        
        return $household_id;
        
    } catch (Exception $e) {
        // Fallback to simple sequential
        return sprintf('%04d', time() % 10000);
    }
}

// Function to get PSGC-style barangay code (if available)
function getBarangayPSGC($barangay_name) {
    // This would connect to PSGC database in a real implementation
    // For now, return a mock PSGC-style code
    // Real PSGC for San Rafael barangays would look like: 034901001, 034901002, etc.
    return null; // Implement when PSGC data is available
}

// Add household logic
$add_error = '';
$add_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $barangay_id = $_SESSION['barangay_id'];
    $household_head_person_id = $_POST['household_head_person_id'] ?? null;
    $manual_id = trim($_POST['manual_household_id'] ?? '');
    $use_manual_id = isset($_POST['use_manual_id']) && $_POST['use_manual_id'] === '1';

    try {
        // Generate or use manual household ID
        if ($use_manual_id && !empty($manual_id)) {
            // Check if manual ID already exists
            $stmt = $pdo->prepare("SELECT id FROM households WHERE id = ?");
            $stmt->execute([$manual_id]);
            if ($stmt->fetch()) {
                $add_error = "Household ID already exists.";
            } else {
                $household_id = $manual_id;
            }
        } else {
            // Auto-generate household ID using Philippine enumeration style
            $household_id = generateHouseholdId($pdo, $barangay_id);
        }

        // --- Validate household_head_person_id ---
        if ($household_head_person_id !== null && $household_head_person_id !== '') {
            // Check if person exists
            $stmt = $pdo->prepare("SELECT id FROM persons WHERE id = ?");
            $stmt->execute([$household_head_person_id]);
            if (!$stmt->fetch()) {
                $add_error = "Selected Household Head Person ID does not exist.";
            }
        } else {
            $household_head_person_id = null;
        }

        if (!$add_error) {
            // Insert new household
            $stmt = $pdo->prepare("INSERT INTO households (id, barangay_id, household_head_person_id) VALUES (?, ?, ?)");
            $stmt->execute([
                $household_id,
                $barangay_id,
                $household_head_person_id
            ]);
            $add_success = "Household added successfully! Household ID: " . $household_id;
        }
    } catch (Exception $e) {
        $add_error = "Error adding household: " . htmlspecialchars($e->getMessage());
    }
}

// Get households for current barangay
$stmt = $pdo->prepare("
    SELECT h.*, 
           p.first_name, 
           p.last_name,
           b.name as barangay_name
    FROM households h 
    LEFT JOIN persons p ON h.household_head_person_id = p.id
    LEFT JOIN barangay b ON h.barangay_id = b.id
    WHERE h.barangay_id = ? 
    ORDER BY h.created_at DESC
");
$stmt->execute([$_SESSION['barangay_id']]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available persons for household head selection
$stmt = $pdo->prepare("
    SELECT p.id, p.first_name, p.last_name, p.contact_number
    FROM persons p
    LEFT JOIN addresses a ON p.id = a.person_id
    WHERE a.barangay_id = ? OR a.barangay_id IS NULL
    ORDER BY p.last_name, p.first_name
");
$stmt->execute([$_SESSION['barangay_id']]);
$available_persons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current barangay info for display
$stmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
$stmt->execute([$_SESSION['barangay_id']]);
$current_barangay = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Manage Households</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet" />
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    
        <!-- Navigation Buttons for Census Pages -->
        <div class="flex flex-wrap gap-4 mb-6 mt-6">
            <a href="manage_census.php" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Add Resident</a>
            <a href="add_child.php" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Add Child</a>
            <a href="census_records.php" class="w-full sm:w-auto text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:ring-green-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Census Records</a>
            <a href="manage_households.php" class="pw-full sm:w-auto text-white bg-purple-600 hover:bg-purple-700 focus:ring-4 focus:ring-purple-300 
               font-medium rounded-lg text-sm px-5 py-2.5">Manage Households</a>
        </div>
    
    <?php if ($add_error): ?>
        <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4"><?= $add_error ?></div>
    <?php elseif ($add_success): ?>
        <div class="bg-green-100 text-green-700 px-4 py-2 rounded mb-4"><?= $add_success ?></div>
    <?php endif; ?>
    
    <!-- Philippine HSN (Household Serial Number) System Information -->
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">Philippine HSN (Household Serial Number) System</h3>
                <div class="mt-2 text-sm text-blue-700">
                    <p>Following actual PSA (Philippine Statistics Authority) HSN assignment:</p>
                    <p><strong>Format: 4-digit Sequential Number per Enumeration Area</strong></p>
                    <ul class="list-disc ml-5 mt-1">
                        <li><strong>HSN 0001</strong>: First household enumerated</li>
                        <li><strong>HSN 0002</strong>: Second household enumerated</li>
                        <li><strong>HSN 0003</strong>: Third household enumerated, and so on...</li>
                    </ul>
                    <p class="mt-2">Next HSN for <?= htmlspecialchars($current_barangay['name'] ?? 'Current Barangay') ?>: 
                        <span class="font-mono bg-white px-2 py-1 rounded">
                            <?php 
                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM households WHERE barangay_id = ?");
                            $stmt->execute([$_SESSION['barangay_id']]);
                            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                            echo sprintf('%04d', $count + 1);
                            ?>
                        </span>
                    </p>
                    <div class="mt-2 p-2 bg-blue-100 rounded text-xs">
                        <strong>PSA Standard:</strong> Each barangay is an Enumeration Area (EA). HSNs 7777, 8888, 8889 are reserved for special cases (temporary residents, excluded persons, occasional use housing).
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <section id="manageHouseholds" class="bg-white rounded-lg shadow p-6 mb-8">
      <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between mb-6 gap-6">
        <h2 class="text-2xl font-bold text-blue-800">Manage Households</h2>
        
        <!-- Add New Household Form -->
        <div class="bg-gray-50 p-4 rounded-lg lg:w-96">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Add New Household</h3>
            <form method="POST" class="space-y-4">
                <!-- Household Head Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Household Head (Optional)</label>
                    <select name="household_head_person_id" class="w-full border rounded px-3 py-2 bg-white">
                        <option value="">Select a person...</option>
                        <?php foreach($available_persons as $person): ?>
                            <option value="<?= $person['id'] ?>">
                                <?= htmlspecialchars($person['first_name'] . ' ' . $person['last_name']) ?>
                                <?php if($person['contact_number']): ?>
                                    (<?= htmlspecialchars($person['contact_number']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- ID Generation Options -->
                <div class="border-t pt-4">
                    <div class="space-y-3">
                        <label class="flex items-center">
                            <input type="radio" name="id_type" value="auto" checked class="mr-2" onchange="toggleManualId(false)">
                            <span class="text-sm font-medium">Auto-generate Household ID</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="id_type" value="manual" class="mr-2" onchange="toggleManualId(true)">
                            <span class="text-sm font-medium">Enter custom Household ID</span>
                        </label>
                    </div>
                    
                    <div id="manual-id-field" class="mt-3 hidden">
                        <input type="text" 
                               name="manual_household_id" 
                               placeholder="Enter custom household ID" 
                               class="w-full border rounded px-3 py-2" />
                        <input type="hidden" name="use_manual_id" value="0" id="use_manual_id_flag">
                        <p class="text-xs text-gray-500 mt-1">Use this only for special cases or data migration</p>
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 transition">
                    Add Household
                </button>
            </form>
        </div>
      </div>
      
      <!-- Households Table -->
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">HSN</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Household Head</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Enumeration Date</th>
              <th class="px-4 py-3 text-left font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-100">
            <?php if (count($households) > 0): ?>
              <?php foreach($households as $household): ?>
              <tr class="hover:bg-blue-50 transition">
                <td class="px-4 py-3">
                    <span class="font-mono text-blue-900 bg-blue-100 px-3 py-2 rounded text-lg font-bold">
                        HSN <?= htmlspecialchars($household['id']) ?>
                    </span>
                    <?php if (preg_match('/^\d{4}$/', $household['id'])): ?>
                        <div class="text-xs text-green-600 mt-1">✓ PSA HSN Format</div>
                    <?php else: ?>
                        <div class="text-xs text-orange-600 mt-1">⚠ Non-standard format</div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <?php if($household['household_head_person_id']): ?>
                        <span class="font-medium">
                            <?= htmlspecialchars($household['first_name'] . ' ' . $household['last_name']) ?>
                        </span>
                        <span class="text-gray-500 text-xs block">ID: <?= $household['household_head_person_id'] ?></span>
                    <?php else: ?>
                        <span class="text-gray-400 italic">No head assigned</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600">
                    <?= date('M j, Y', strtotime($household['created_at'])) ?>
                    <span class="text-xs text-gray-400 block">
                        <?= date('g:i A', strtotime($household['created_at'])) ?>
                    </span>
                </td>
                <td class="px-4 py-3">
                  <a href="edit_household.php?id=<?= urlencode($household['id']) ?>" 
                     class="inline-block text-blue-600 hover:text-blue-800 font-medium mr-3">Edit</a>
                  <a href="delete_household.php?id=<?= urlencode($household['id']) ?>" 
                     class="inline-block text-red-600 hover:text-red-800 font-medium"
                     onclick="return confirm('Are you sure you want to delete household <?= htmlspecialchars($household['id']) ?>?');">Delete</a>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="4" class="px-4 py-6 text-center text-gray-400 italic">
                    No households enumerated for <?= htmlspecialchars($current_barangay['name'] ?? 'this barangay') ?>.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      
      <!-- Summary Statistics -->
      <div class="mt-6 bg-gray-50 p-4 rounded-lg">
        <h3 class="text-lg font-semibold text-gray-800 mb-2">Enumeration Summary</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div>
            <span class="text-gray-600">Total Households Enumerated:</span>
            <span class="font-bold text-blue-800 ml-2"><?= count($households) ?></span>
          </div>
          <div>
            <span class="text-gray-600">With Household Head:</span>
            <span class="font-bold text-green-600 ml-2">
              <?= count(array_filter($households, function($h) { return !empty($h['household_head_person_id']); })) ?>
            </span>
          </div>
          <div>
            <span class="text-gray-600">Pending Head Assignment:</span>
            <span class="font-bold text-orange-600 ml-2">
              <?= count(array_filter($households, function($h) { return empty($h['household_head_person_id']); })) ?>
            </span>
          </div>
        </div>
        <div class="mt-3 text-xs text-gray-600">
          <strong>PSA Standard:</strong> HSN (Household Serial Numbers) are assigned sequentially per enumeration area as per Philippine Statistics Authority census procedures.
        </div>
      </div>
    </section>
  </div>
  
  <script>
    function toggleManualId(show) {
        const manualField = document.getElementById('manual-id-field');
        const flag = document.getElementById('use_manual_id_flag');
        
        if (show) {
            manualField.classList.remove('hidden');
            flag.value = '1';
        } else {
            manualField.classList.add('hidden');
            flag.value = '0';
        }
    }
  </script>
</body>
</html>