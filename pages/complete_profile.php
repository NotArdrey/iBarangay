<?php
session_start();
require "../config/dbconn.php"; // Assumes this file creates a PDO instance as $pdo

/**
 * Returns the appropriate dashboard URL based on the user's role.
 */
function getDashboardUrl($role_id)
{
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
    'emergency_contact_address' => trim($_POST['emergency_contact_address'] ?? ''),
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
  foreach (['block_lot', 'phase', 'street', 'subdivision'] as $fld) {
    if (empty($formData[$fld])) {
      $errors[] = ucfirst(str_replace('_', ' ', $fld)) . " is required.";
    }
  }

  // 3. File‐upload handling (store directly into the existing columns)
  $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
  $maxSize     = 2 * 1024 * 1024; // 2MB

  // Initialize with whatever’s already in the DB
  $idImageBlob     = $user['id_image_path']    ?? null;
  $selfieImageBlob = $user['selfie_image_path'] ?? null;

  // Gov’t ID → id_image_path
  if (
    !empty($_FILES['govt_id']) &&
    $_FILES['govt_id']['error'] !== UPLOAD_ERR_NO_FILE
  ) {
    $f = $_FILES['govt_id'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Error uploading Government ID.";
    } elseif (!in_array(mime_content_type($f['tmp_name']), $allowedTypes, true)) {
      $errors[] = "Government ID must be JPG, PNG, or GIF.";
    } elseif ($f['size'] > $maxSize) {
      $errors[] = "Government ID exceeds 2 MB.";
    } else {
      // read the binary straight in
      $idImageBlob = file_get_contents($f['tmp_name']);
    }
  }

  // Selfie → selfie_image_path
  if (
    !empty($_FILES['personal_photo']) &&
    $_FILES['personal_photo']['error'] !== UPLOAD_ERR_NO_FILE
  ) {
    $f = $_FILES['personal_photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Error uploading Personal Photo.";
    } elseif (!in_array(mime_content_type($f['tmp_name']), $allowedTypes, true)) {
      $errors[] = "Personal Photo must be JPG, PNG, or GIF.";
    } elseif ($f['size'] > $maxSize) {
      $errors[] = "Personal Photo exceeds 2 MB.";
    } else {
      $selfieImageBlob = file_get_contents($f['tmp_name']);
    }
  }

  if (empty($errors)) {
    // Update Users
    $sql = "UPDATE Users SET
              first_name=?, middle_name=?, last_name=?, birth_date=?,
              gender=?, contact_number=?, marital_status=?,
              emergency_contact_name=?, emergency_contact_number=?,
              emergency_contact_address=?, barangay_id=?,
              id_image_path=?, selfie_image_path=?
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
      $idImageBlob,
      $selfieImageBlob,
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
      $_SESSION['user_name']   = $formData['first_name'] . ' ' . $formData['last_name'];

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
      <form class="account-form" action="" method="POST" id="profileForm" enctype="multipart/form-data">
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

        <!-- Upload Govt ID -->
        <div class="form-group">
          <label>Government Issued ID *</label>
          <div class="drop-zone" id="govt_id_zone">
            <div class="drop-content">
              <i class="fas fa-cloud-upload-alt"></i>
              <p>Drag and drop file here<br>or click to browse</p>
              <input type="file" id="govt_id" name="govt_id" accept="image/*" hidden <?= empty($user['id_image_path']) ? 'required' : '' ?>>
            </div>
            <div class="preview-container">
              <?php if (!empty($user['id_image_path'])): ?>
                <div class="file-info">
                  <span>Uploaded: <?= basename($user['id_image_path']) ?></span>
                </div>
              <?php endif; ?>
              <img id="govt_id_preview" src="<?= !empty($user['id_image_path']) ? '/uploads/id/' . basename($user['id_image_path']) : '' ?>">
            </div>
          </div>
          <small>Accepted formats: JPG, PNG, GIF. Max size: 2MB</small>
        </div>

        <!-- Take personal photo -->
        <div class="form-group">
          <label>Personal Photo *</label>
          <div class="camera-section">
            <video id="cameraPreview" autoplay playsinline style="display: none;"></video>
            <button type="button" id="startCamera" class="btn camera-btn">Open Camera</button>
            <button type="button" id="capturePhoto" class="btn camera-btn" style="display: none;">Capture Photo</button>
            <canvas id="cameraCanvas" style="display: none;"></canvas>
          </div>
          <input type="file" id="personal_photo" name="personal_photo" accept="image/*" hidden <?= empty($user['selfie_image_path']) ? 'required' : '' ?>>
          <div class="preview-container">
            <?php if (!empty($user['selfie_image_path'])): ?>
            <?php endif; ?>
            <img id="personal_photo_preview" src="<?= !empty($user['selfie_image_path']) ? '/uploads/selfie/' . basename($user['selfie_image_path']) : '' ?>">
          </div>
          <small>Click "Open Camera" to take a live photo. Accepted formats: JPG, PNG, GIF. Max size: 2MB</small>
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

  <script>
    const video = document.getElementById('cameraPreview');
    const startCamera = document.getElementById('startCamera');
    const capturePhoto = document.getElementById('capturePhoto');
    const canvas = document.getElementById('cameraCanvas');
    const personalPhotoInput = document.getElementById('personal_photo');
    const photoPreview = document.getElementById('personal_photo_preview');
    let stream = null;

    // Start camera preview
    startCamera.addEventListener('click', async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: {
            facingMode: 'user',
            width: {
              ideal: 1280
            },
            height: {
              ideal: 720
            }
          }
        });
        video.style.display = 'block';
        video.srcObject = stream;
        startCamera.style.display = 'none';
        capturePhoto.style.display = 'inline-block';
      } catch (err) {
        console.error('Camera error:', err);
        Swal.fire({
          icon: 'error',
          title: 'Camera Access Required',
          text: 'Please enable camera access in your browser settings to continue',
          footer: 'Error: ' + err.message
        });
        startCamera.style.display = 'block';
        capturePhoto.style.display = 'none';
      }
    });

    // Capture photo from video stream
    capturePhoto.addEventListener('click', () => {
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      canvas.getContext('2d').drawImage(video, 0, 0);

      // Stop camera stream
      stream.getTracks().forEach(track => track.stop());
      video.style.display = 'none';
      capturePhoto.style.display = 'none';
      startCamera.style.display = 'inline-block';

      // Convert to JPEG with 80% quality
      canvas.toBlob(blob => {
        const file = new File([blob], 'selfie_' + Date.now() + '.jpg', {
          type: 'image/jpeg',
          lastModified: Date.now()
        });

        // Update file input
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(file);
        personalPhotoInput.files = dataTransfer.files;

        // Show preview
        photoPreview.src = URL.createObjectURL(file);
        photoPreview.style.display = 'block';
      }, 'image/jpeg', 0.8);
    });

    // Handle existing file selection
    personalPhotoInput.addEventListener('change', function() {
      if (this.files[0]) {
        photoPreview.src = URL.createObjectURL(this.files[0]);
        photoPreview.style.display = 'block';
      }
    });


    function setupDropZone(zoneId, inputId, previewId) {
      const dropZone = document.getElementById(zoneId);
      const fileInput = document.getElementById(inputId);
      const preview = document.getElementById(previewId);

      // Click handler
      dropZone.addEventListener('click', () => fileInput.click());

      // Drag handlers
      dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
      });

      dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
      });

      dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length) {
          fileInput.files = files;
          previewImage(fileInput, previewId);
        }
      });

      // File input change handler
      fileInput.addEventListener('change', () => previewImage(fileInput, previewId));
    }

    function previewImage(input, previewId) {
      const preview = document.getElementById(previewId);
      const fileInput = input;
      const file = fileInput.files[0];
      const dropZone = fileInput.closest('.drop-zone');

      if (file) {
        const reader = new FileReader();
        reader.onload = (e) => {
          preview.src = e.target.result;
          preview.style.display = 'block';

          // Toggle has-file on the drop-zone
          if (dropZone) dropZone.classList.add('has-file');

          // Update file-info content
          const fileInfo = dropZone.querySelector('.file-info');
          if (fileInfo) {
            fileInfo.innerHTML = `<span>${file.name} (${(file.size/1024/1024).toFixed(2)} MB)</span>`;
          }
        };
        reader.readAsDataURL(file);
      } else {
        // no file → remove preview and class
        preview.style.display = 'none';
        if (dropZone) dropZone.classList.remove('has-file');
      }
    }

    // Initialize both drop zones
    setupDropZone('govt_id_zone', 'govt_id', 'govt_id_preview');
  </script>


</body>

</html>