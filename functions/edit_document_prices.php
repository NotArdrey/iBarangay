<?php
session_start();
require "../config/dbconn.php";
require_once "../components/header.php";
// Only allow logged-in users with official roles (customize as needed)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_id'], [3,4,5,6,7])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$barangay_id = $_SESSION['barangay_id']; // Make sure this is set in your session

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['prices'])) {
    foreach ($_POST['prices'] as $doc_type_id => $price) {
        // Check if entry exists
        $stmt = $pdo->prepare("SELECT id FROM barangay_document_prices WHERE barangay_id = ? AND document_type_id = ?");
        $stmt->execute([$barangay_id, $doc_type_id]);
        if ($stmt->fetch()) {
            // Update
            $update = $pdo->prepare("UPDATE barangay_document_prices SET price = ? WHERE barangay_id = ? AND document_type_id = ?");
            $update->execute([$price, $barangay_id, $doc_type_id]);
        } else {
            // Insert
            $insert = $pdo->prepare("INSERT INTO barangay_document_prices (barangay_id, document_type_id, price) VALUES (?, ?, ?)");
            $insert->execute([$barangay_id, $doc_type_id, $price]);
        }
    }
    $success = "Prices updated successfully!";
}

// Get all document types
$docs = $pdo->query("SELECT id, name, default_fee FROM document_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

// Get current prices for this barangay
$prices = [];
$stmt = $pdo->prepare("SELECT document_type_id, price FROM barangay_document_prices WHERE barangay_id = ?");
$stmt->execute([$barangay_id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $prices[$row['document_type_id']] = $row['price'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Document Prices</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
  <style>
    .price-input {
      transition: all 0.3s ease;
    }
    .price-input:focus {
      transform: scale(1.02);
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }
    .table-row {
      transition: all 0.2s ease;
    }
    .table-row:hover {
      transform: translateX(5px);
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }
  </style>
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    <header class="mb-6">
      <h1 class="text-3xl font-bold text-gray-800">Edit Document Prices</h1>
      <p class="text-gray-600">Customize document fees for your barangay</p>
    </header>

    <?php if (!empty($success)): ?>
    <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg shadow-sm fade-in flex items-center">
      <i class="fas fa-check-circle mr-2"></i>
      <?php echo $success; ?>
    </div>
    <?php endif; ?>

    <form method="post" class="fade-in">
      <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Default Price</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Custom Price</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($docs as $doc): ?>
              <tr class="table-row">
                <td class="px-6 py-4 text-sm text-gray-900"><?= htmlspecialchars($doc['name']) ?></td>
                <td class="px-6 py-4 text-sm text-gray-600">
                  <span class="bg-gray-100 px-3 py-1 rounded-full">
                    ₱<?= number_format($doc['default_fee'], 2) ?>
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">
                  <div class="flex items-center space-x-2">
                    <span class="text-gray-500">₱</span>
                    <input 
                      type="number" 
                      step="0.01" 
                      min="0" 
                      name="prices[<?= $doc['id'] ?>]" 
                      value="<?= isset($prices[$doc['id']]) ? $prices[$doc['id']] : $doc['default_fee'] ?>" 
                      class="price-input border border-gray-300 rounded px-3 py-2 w-32 focus:border-blue-500 focus:outline-none"
                    >
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="mt-4">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition-colors flex items-center">
          <i class="fas fa-save mr-2"></i>
          <span>Save Changes</span>
        </button>
      </div>
    </form>
  </div>

  <script>
    // Add smooth transitions when changing input values
    document.querySelectorAll('.price-input').forEach(input => {
      input.addEventListener('change', function() {
        this.classList.add('scale-110');
        setTimeout(() => this.classList.remove('scale-110'), 200);
      });
    });

    // Show confirmation on form submit
    document.querySelector('form').addEventListener('submit', function(e) {
      e.preventDefault();
      Swal.fire({
        title: 'Save Changes?',
        text: 'Are you sure you want to update the document prices?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3B82F6',
        cancelButtonColor: '#6B7280',
        confirmButtonText: 'Yes, save changes',
        cancelButtonText: 'Cancel'
      }).then((result) => {
        if (result.isConfirmed) {
          this.submit();
        }
      });
    });
  </script>
</body>
</html>
