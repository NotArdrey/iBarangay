<?php
session_start();
require "../config/dbconn.php";
require_once "../pages/header.php";
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
  <title>Edit Document Prices</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    <header class="mb-6">
      <h1 class="text-3xl font-bold text-blue-800">Edit Document Prices</h1>
    </header>
    <?php if (!empty($success)) echo '<div class="mb-4 p-2 bg-green-100 text-green-800 rounded">'.$success.'</div>'; ?>
    <form method="post">
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Default Price</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Your Barangay Price</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($docs as $doc): ?>
              <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 text-sm text-gray-900 border-b"><?= htmlspecialchars($doc['name']) ?></td>
                <td class="px-4 py-3 text-sm text-gray-900 border-b">₱<?= number_format($doc['default_fee'], 2) ?></td>
                <td class="px-4 py-3 text-sm text-gray-900 border-b">
                  <input type="number" step="0.01" min="0" name="prices[<?= $doc['id'] ?>]" value="<?= isset($prices[$doc['id']]) ? $prices[$doc['id']] : $doc['default_fee'] ?>" class="border rounded px-2 py-1 w-32">
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <button type="submit" class="mt-4 bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition">Save Prices</button>
    </form>
  </div>
</body>
</html>
