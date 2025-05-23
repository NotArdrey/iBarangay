<?php
require "../config/dbconn.php";
require_once "../pages/header.php";

// Fetch households for selection
$stmt = $pdo->prepare("SELECT id AS household_id FROM households WHERE barangay_id = ? ORDER BY id");
$stmt->execute([$_SESSION['barangay_id']]);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

$add_error = '';
$add_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name   = trim($_POST['first_name'] ?? '');
    $middle_name  = trim($_POST['middle_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $suffix       = trim($_POST['suffix'] ?? '');
    $birth_date   = $_POST['birth_date'] ?? '';
    $gender       = $_POST['gender'] ?? '';
    $civil_status = $_POST['civil_status'] ?? 'Single';
    $citizenship  = trim($_POST['citizenship'] ?? 'Filipino');
    $household_id = $_POST['household_id'] ?? '';
    $relationship = $_POST['relationship'] ?? '';
    $is_household_head = isset($_POST['is_household_head']) ? 1 : 0;

    // Validate required fields
    $required = [
        'First Name' => $first_name,
        'Last Name' => $last_name,
        'Birth Date' => $birth_date,
        'Gender' => $gender,
        'Household ID' => $household_id,
    ];
    foreach ($required as $label => $val) {
        if (!$val) $add_error .= "$label is required.<br>";
    }
    $household_exists = false;
    foreach ($households as $h) {
        if ($h['household_id'] == $household_id) $household_exists = true;
    }
    if (!$household_exists) $add_error .= "Selected household does not exist.<br>";

    if (!$add_error) {
        try {
            $pdo->beginTransaction();

            // Insert into persons
            $stmt = $pdo->prepare("INSERT INTO persons 
                (first_name, middle_name, last_name, suffix, birth_date, gender, civil_status, citizenship)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $first_name, $middle_name, $last_name, $suffix, $birth_date, $gender, $civil_status, $citizenship
            ]);
            $person_id = $pdo->lastInsertId();

            // Insert into household_members
            $stmt = $pdo->prepare("INSERT INTO household_members 
                (household_id, person_id, relationship_to_head, is_household_head)
                VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $household_id, $person_id, $relationship, $is_household_head
            ]);

            // Insert into child_information
            $stmt = $pdo->prepare("INSERT INTO child_information (person_id) VALUES (?)");
            $stmt->execute([$person_id]);

            $pdo->commit();
            $add_success = "Child added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $add_error = "Error adding child: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Add Child</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
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
    <section id="add-child" class="bg-white rounded-lg shadow-sm p-6 mb-8">
      <h2 class="text-3xl font-bold text-blue-800">Add Child (0-17 Years Old)</h2>
      <form method="POST" class="space-y-8" autocomplete="off">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium">First Name *</label>
                <input type="text" name="first_name" required class="mt-1 block w-full border rounded p-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Middle Name</label>
                <input type="text" name="middle_name" class="mt-1 block w-full border rounded p-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Last Name *</label>
                <input type="text" name="last_name" required class="mt-1 block w-full border rounded p-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Suffix</label>
                <input type="text" name="suffix" class="mt-1 block w-full border rounded p-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Date of Birth *</label>
                <input type="date" name="birth_date" required class="mt-1 block w-full border rounded p-2">
            </div>
            <div>
                <label class="block text-sm font-medium">Gender *</label>
                <select name="gender" required class="mt-1 block w-full border rounded p-2">
                    <option value="">-- Select Gender --</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                    <option value="Others">Others</option>
                </select>
            </div>
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
            </div>
            <div>
                <label class="block text-sm font-medium">Relationship to Head</label>
                <select name="relationship" class="mt-1 block w-full border rounded p-2">
                    <option value="Child">Child</option>
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
        <div>
            <button type="submit" class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5">
                Save Child Data
            </button>
        </div>
      </form>
    </section>
  </div>
</body>
</html>
