<?php
session_start();
require "../config/dbconn.php"; // Assumes this file creates a PDO instance as $pdo

/**
 * Returns the appropriate dashboard URL based on the user's role.
 */
function getDashboardUrl($role_id) {
    switch ($role_id) {
        case 1:
            return "../pages/programmer_admin.php";
        case 2:
            return "../pages/super_admin_dashboard.php";
        // Roles 3–7 are barangay admins
        case 3:
        case 4:
        case 5:
        case 6:
        case 7:
            return "../pages/barangay_admin_dashboard.php";
        // Role 8 (and any other) is a regular user/resident
        default:
            return "../pages/user_dashboard.php";
    }
}

// Only allow logged-in users; if not, redirect to login page.
if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
    header("Location: ../pages/index.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch current user's data from the Users table.
$query = "SELECT * FROM Users WHERE user_id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch current address data if it exists
$addressQuery = "SELECT * FROM Address WHERE user_id = ?";
$stmtAddress = $pdo->prepare($addressQuery);
$stmtAddress->execute([$user_id]);
$address = $stmtAddress->fetch(PDO::FETCH_ASSOC);

$error_message = '';
$formData = [];

// Process form submission on POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and trim form inputs
    $formData = [
        'first_name'               => trim($_POST['first_name'] ?? ''),
        'middle_name'              => trim($_POST['middle_name'] ?? ''),
        'last_name'                => trim($_POST['last_name'] ?? ''),
        'birth_date'               => trim($_POST['birth_date'] ?? ''),
        'gender'                   => trim($_POST['gender'] ?? ''),
        'contact_number'           => trim($_POST['contact_number'] ?? ''),
        'marital_status'           => trim($_POST['marital_status'] ?? ''),
        'emergency_contact_name'   => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_number' => trim($_POST['emergency_contact_number'] ?? ''),
        'emergency_contact_address'=> trim($_POST['emergency_contact_address'] ?? ''),
        'barangay_id'              => trim($_POST['barangay_id'] ?? ''),
        'residency_type'           => trim($_POST['residency_type'] ?? ''),
        'years_in_san_rafael'      => trim($_POST['years_in_san_rafael'] ?? ''),
        'block_lot'                => trim($_POST['block_lot'] ?? ''),
        'phase'                    => trim($_POST['phase'] ?? ''),
        'street'                   => trim($_POST['street'] ?? ''),
        'subdivision'              => trim($_POST['subdivision'] ?? ''),
    ];

    // Validate required fields
    $errors = [];
    if (empty($formData['first_name'])) {
        $errors[] = "First name is required.";
    }
    if (empty($formData['last_name'])) {
        $errors[] = "Last name is required.";
    }
    if (empty($formData['birth_date'])) {
        $errors[] = "Birth date is required.";
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $formData['birth_date']);
        if (!$d || $d->format('Y-m-d') !== $formData['birth_date']) {
            $errors[] = "Invalid birth date format.";
        } elseif ($d > new DateTime('today')) {
            $errors[] = "Birth date cannot be in the future.";
        }
    }
    if (empty($formData['gender'])) {
        $errors[] = "Gender is required.";
    }
    if (empty($formData['contact_number']) || !preg_match('/^\+?[0-9]{7,15}$/', $formData['contact_number'])) {
        $errors[] = "Valid contact number is required.";
    }
    if (empty($formData['barangay_id']) || !ctype_digit($formData['barangay_id'])) {
        $errors[] = "A valid Barangay selection is required.";
    }
    if (empty($formData['residency_type'])) {
        $errors[] = "Residency type is required.";
    }
    if ($formData['years_in_san_rafael'] === '' || !ctype_digit($formData['years_in_san_rafael'])) {
        $errors[] = "Years in San Rafael is required and must be a number.";
    }
    foreach (['block_lot','phase','street','subdivision'] as $fld) {
        if (empty($formData[$fld])) {
            $errors[] = ucfirst(str_replace('_',' ',$fld)) . " is required.";
        }
    }

    if (empty($errors)) {
        // Update Users
        $sql = "UPDATE Users SET
                    first_name=?, middle_name=?, last_name=?, birth_date=?,
                    gender=?, contact_number=?, marital_status=?,
                    emergency_contact_name=?, emergency_contact_number=?,
                    emergency_contact_address=?, barangay_id=?
                  WHERE user_id=?";
        $stmtUpd = $pdo->prepare($sql);
        $params = [
            $formData['first_name'],
            $formData['middle_name'],
            $formData['last_name'],
            $formData['birth_date'],
            $formData['gender'],
            $formData['contact_number'],
            $formData['marital_status'],
            $formData['emergency_contact_name'],
            $formData['emergency_contact_number'],
            $formData['emergency_contact_address'],
            $formData['barangay_id'],
            $user_id
        ];
        $okUser = $stmtUpd->execute($params);

        // Upsert Address
        $exists = $pdo->prepare("SELECT COUNT(*) FROM Address WHERE user_id=?");
        $exists->execute([$user_id]);
        if ($exists->fetchColumn()) {
            $sqlAddr = "UPDATE Address SET
                            residency_type=?, years_in_san_rafael=?,
                            block_lot=?, phase=?, street=?, subdivision=?, barangay_id=?
                        WHERE user_id=?";
            $stmtAddr = $pdo->prepare($sqlAddr);
            $addrParams = [
                $formData['residency_type'],
                $formData['years_in_san_rafael'],
                $formData['block_lot'],
                $formData['phase'],
                $formData['street'],
                $formData['subdivision'],
                $formData['barangay_id'],
                $user_id
            ];
            $okAddr = $stmtAddr->execute($addrParams);
        } else {
            $sqlAddr = "INSERT INTO Address (
                            user_id,residency_type,years_in_san_rafael,
                            block_lot,phase,street,subdivision,barangay_id
                        ) VALUES (?,?,?,?,?,?,?,?)";
            $stmtAddr = $pdo->prepare($sqlAddr);
            $addrParams = [
                $user_id,
                $formData['residency_type'],
                $formData['years_in_san_rafael'],
                $formData['block_lot'],
                $formData['phase'],
                $formData['street'],
                $formData['subdivision'],
                $formData['barangay_id'],
            ];
            $okAddr = $stmtAddr->execute($addrParams);
        }

        if ($okUser && $okAddr) {
          // 1) Update the session so we use the new barangay everywhere
          $_SESSION['barangay_id'] = (int)$formData['barangay_id'];
      
          // 2) (Optional) Update the session name if you want
          $_SESSION['user_name']   = $formData['first_name'].' '.$formData['last_name'];
      
          // 3) Now redirect to the correct dashboard
          header("Location: " . getDashboardUrl($_SESSION['role_id']));
          exit;
      }
        $error_message = "Failed to update profile. Please try again.";
    } else {
        $error_message = implode("<br>", $errors);
    }
} else {
    // Pre-fill form with existing data
    $formData = array_merge($user, $address ?: []);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Complete Your Profile</title>
  <link rel="stylesheet" href="../styles/edit_account.css">
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <!-- SweetAlert2 for notifications -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  
</head>
<body>

  <!-- Main Content -->
  <main class="edit-account-section">
    <div class="section-header ">
      <h2>Complete Your Profile</h2>
      <p>Please fill in the required details to continue.</p>
    </div>

    <?php if (!empty($error_message)): ?>
      <script>
        document.addEventListener('DOMContentLoaded', function() {
          Swal.fire({
            icon: "error",
            title: "Error",
            html: <?php echo json_encode($error_message); ?>
          });
        });
      </script>
    <?php endif; ?>

    <div class="account-form-container">
      <form class="account-form" action="" method="POST" id="profileForm">
        <!-- Personal Details Fields -->
        <div class="form-group">
          <label for="first_name">First Name *</label>
          <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="middle_name">Middle Name</label>
          <input type="text" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($formData['middle_name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="last_name">Last Name *</label>
          <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="birth_date">Birth Date *</label>
          <input type="date" id="birth_date" name="birth_date" value="<?php echo htmlspecialchars($formData['birth_date'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="gender">Gender *</label>
          <select name="gender" id="gender" required>
            <option value="">Select Gender</option>
            <option value="Male" <?php echo (isset($formData['gender']) && $formData['gender'] === "Male") ? 'selected' : ''; ?>>Male</option>
            <option value="Female" <?php echo (isset($formData['gender']) && $formData['gender'] === "Female") ? 'selected' : ''; ?>>Female</option>
            <option value="Others" <?php echo (isset($formData['gender']) && $formData['gender'] === "Others") ? 'selected' : ''; ?>>Others</option>
          </select>
        </div>
        <div class="form-group">
          <label for="contact_number">Contact Number *</label>
          <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($formData['contact_number'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="marital_status">Marital Status</label>
          <select name="marital_status" id="marital_status">
            <option value="">Select Status</option>
            <option value="Single" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] === "Single") ? 'selected' : ''; ?>>Single</option>
            <option value="Married" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] === "Married") ? 'selected' : ''; ?>>Married</option>
            <option value="Widowed" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] === "Widowed") ? 'selected' : ''; ?>>Widowed</option>
            <option value="Separated" <?php echo (isset($formData['marital_status']) && $formData['marital_status'] === "Separated") ? 'selected' : ''; ?>>Separated</option>
          </select>
        </div>
        <div class="form-group">
          <label for="emergency_contact_name">Emergency Contact Name</label>
          <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($formData['emergency_contact_name'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="emergency_contact_number">Emergency Contact Number</label>
          <input type="text" id="emergency_contact_number" name="emergency_contact_number" value="<?php echo htmlspecialchars($formData['emergency_contact_number'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="emergency_contact_address">Emergency Contact Address</label>
          <input type="text" id="emergency_contact_address" name="emergency_contact_address" value="<?php echo htmlspecialchars($formData['emergency_contact_address'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label for="barangay_id">Barangay</label>
          <select name="barangay_id" id="barangay_id" required>
            <option value="">Select Barangay</option>
            <?php
              $barangayQuery = "SELECT barangay_id, barangay_name FROM Barangay";
              $stmtBarangay = $pdo->query($barangayQuery);
              while ($barangay = $stmtBarangay->fetch(PDO::FETCH_ASSOC)) {
                  $selected = (isset($formData['barangay_id']) && $formData['barangay_id'] == $barangay['barangay_id']) ? 'selected' : '';
                  echo "<option value=\"{$barangay['barangay_id']}\" $selected>{$barangay['barangay_name']}</option>";
              }
            ?>
          </select>
        </div>

        <!-- Address Section -->
        <h3>Address Information</h3>
        <div class="form-group">
          <label for="residency_type">Residency Type *</label>
          <select name="residency_type" id="residency_type" required>
            <option value="">Select Residency Type</option>
            <option value="Home Owner" <?php echo (isset($formData['residency_type']) && $formData['residency_type'] === "Home Owner") ? 'selected' : ''; ?>>Home Owner</option>
            <option value="Renter" <?php echo (isset($formData['residency_type']) && $formData['residency_type'] === "Renter") ? 'selected' : ''; ?>>Renter</option>
            <option value="Boarder" <?php echo (isset($formData['residency_type']) && $formData['residency_type'] === "Boarder") ? 'selected' : ''; ?>>Boarder</option>
            <option value="Living-In" <?php echo (isset($formData['residency_type']) && $formData['residency_type'] === "Living-In") ? 'selected' : ''; ?>>Living-In</option>
          </select>
        </div>
        <div class="form-group">
          <label for="years_in_san_rafael">Years in San Rafael *</label>
          <input type="number" id="years_in_san_rafael" name="years_in_san_rafael" min="0" value="<?php echo htmlspecialchars($formData['years_in_san_rafael'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="block_lot">Block/Lot *</label>
          <input type="text" id="block_lot" name="block_lot" value="<?php echo htmlspecialchars($formData['block_lot'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="phase">Phase *</label>
          <input type="text" id="phase" name="phase" value="<?php echo htmlspecialchars($formData['phase'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="street">Street *</label>
          <input type="text" id="street" name="street" value="<?php echo htmlspecialchars($formData['street'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
          <label for="subdivision">Subdivision *</label>
          <input type="text" id="subdivision" name="subdivision" value="<?php echo htmlspecialchars($formData['subdivision'] ?? ''); ?>" required>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
          <button type="reset" class="btn secondary-btn">Reset</button>
          <button type="submit" class="btn cta-button">Save Profile</button>
        </div>
      </form>
    </div>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <p>&copy; 2025 Barangay Hub. All rights reserved.</p>
  </footer>
</body>
</html>