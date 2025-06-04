<?php
session_start();
require "../config/dbconn.php";

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

$current_admin_id = $_SESSION['user_id'];
$barangay_id = $_SESSION['barangay_id'] ?? 1;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'save_settings') {
                $secret_key = trim($_POST['secret_key'] ?? '');
                $public_key = trim($_POST['public_key'] ?? '');
                $webhook_secret = trim($_POST['webhook_secret'] ?? '');
                $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
                $test_mode = isset($_POST['test_mode']) ? 1 : 0;
                
                // Validate keys if provided
                if ($is_enabled && (empty($secret_key) || empty($public_key))) {
                    throw new Exception("Both Secret Key and Public Key are required when activating PayMongo");
                }
                
                // Validate key format
                if ($secret_key && !preg_match('/^sk_(test|live)_/', $secret_key)) {
                    throw new Exception("Invalid Secret Key format. Must start with 'sk_test_' or 'sk_live_'");
                }
                
                if ($public_key && !preg_match('/^pk_(test|live)_/', $public_key)) {
                    throw new Exception("Invalid Public Key format. Must start with 'pk_test_' or 'pk_live_'");
                }
                
                // Check if settings exist
                $stmt = $pdo->prepare("
                    SELECT id FROM barangay_paymongo_settings 
                    WHERE barangay_id = ?
                ");
                $stmt->execute([$barangay_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    // Update existing
                    $stmt = $pdo->prepare("
                        UPDATE barangay_paymongo_settings 
                        SET secret_key = ?, public_key = ?, webhook_secret = ?, is_enabled = ?, test_mode = ?, updated_at = NOW()
                        WHERE barangay_id = ?
                    ");
                    $stmt->execute([$secret_key, $public_key, $webhook_secret, $is_enabled, $test_mode, $barangay_id]);
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("
                        INSERT INTO barangay_paymongo_settings 
                        (barangay_id, secret_key, public_key, webhook_secret, is_enabled, test_mode, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$barangay_id, $secret_key, $public_key, $webhook_secret, $is_enabled, $test_mode]);
                }
                
                // Log the action
                $stmt = $pdo->prepare("
                    INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
                    VALUES (?, 'UPDATE', 'barangay_paymongo_settings', ?, ?)
                ");
                $stmt->execute([
                    $current_admin_id,
                    $barangay_id,
                    $is_enabled ? 'Activated PayMongo settings' : 'Deactivated PayMongo settings'
                ]);
                
                $_SESSION['success'] = "PayMongo settings saved successfully!";
                
            } elseif ($_POST['action'] === 'test_connection') {
                $secret_key = trim($_POST['secret_key'] ?? '');
                
                if (empty($secret_key)) {
                    throw new Exception("Secret Key is required for testing");
                }
                
                // Test the connection by trying to create a test payment intent
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/payment_intents');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'data' => [
                        'attributes' => [
                            'amount' => 10000, // 100 PHP in centavos
                            'currency' => 'PHP',
                            'description' => 'Test connection'
                        ]
                    ]
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . base64_encode($secret_key . ':')
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $_SESSION['success'] = "PayMongo connection test successful!";
                } else {
                    $errorData = json_decode($response, true);
                    $errorMessage = $errorData['errors'][0]['detail'] ?? 'Unknown error';
                    throw new Exception("PayMongo connection failed: " . $errorMessage);
                }
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Get current settings
$stmt = $pdo->prepare("
    SELECT * FROM barangay_paymongo_settings 
    WHERE barangay_id = ?
");
$stmt->execute([$barangay_id]);
$settings = $stmt->fetch();

// Get barangay name
$stmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
$stmt->execute([$barangay_id]);
$barangay = $stmt->fetch();
$barangayName = $barangay ? $barangay['name'] : 'Unknown';
require_once "../components/header.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PayMongo Settings - <?= htmlspecialchars($barangayName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-6 max-w-4xl">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-credit-card mr-2"></i>
                    PayMongo Payment Settings
                </h1>
                <p class="text-gray-600">Configure online payments for <?= htmlspecialchars($barangayName) ?></p>
            </div>
            <div class="flex items-center gap-4">
                <a href="doc_request.php" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Document Requests
                </a>
                <?php if ($settings && $settings['is_enabled']): ?>
                    <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                        <i class="fas fa-check-circle mr-1"></i>
                        Active
                    </span>
                <?php else: ?>
                    <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm font-medium">
                        <i class="fas fa-times-circle mr-1"></i>
                        Inactive
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-lg p-6">
            <!-- Information Panel -->
            <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-blue-800">About PayMongo Integration</h3>
                        <div class="mt-2 text-sm text-blue-700">
                            <p>Configure your barangay's PayMongo account to enable online payments for document requests.</p>
                            <ul class="list-disc list-inside mt-2">
                                <li>Users will be able to pay online via GCash, GrabPay, PayMaya, and Credit Cards</li>
                                <li>If PayMongo is not configured, only cash payment at the office will be available</li>
                                <li>You can get your API keys from your PayMongo Dashboard</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" id="paymongoForm">
                <input type="hidden" name="action" value="save_settings">
                
                <div class="grid grid-cols-1 gap-6">
                    <!-- Enable/Disable PayMongo -->
                    <div class="flex items-center">
                        <input type="checkbox" id="is_enabled" name="is_enabled" 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                               <?= ($settings && $settings['is_enabled']) ? 'checked' : '' ?>>
                        <label for="is_enabled" class="ml-2 block text-sm text-gray-900">
                            Enable PayMongo Online Payments
                        </label>
                    </div>

                    <!-- Test Mode Toggle -->
                    <div class="flex items-center">
                        <input type="checkbox" id="test_mode" name="test_mode" 
                               class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-gray-300 rounded"
                               <?= ($settings && $settings['test_mode']) ? 'checked' : '' ?>>
                        <label for="test_mode" class="ml-2 block text-sm text-gray-900">
                            Enable Test Mode 
                            <span class="text-xs text-yellow-600 font-medium">(No real payments will be processed)</span>
                        </label>
                    </div>

                    <!-- Secret Key -->
                    <div>
                        <label for="secret_key" class="block text-sm font-medium text-gray-700 mb-2">
                            Secret Key <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <input type="password" id="secret_key" name="secret_key"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="sk_test_... or sk_live_..."
                                   value="<?= htmlspecialchars($settings['secret_key'] ?? '') ?>">
                            <button type="button" onclick="togglePassword('secret_key')" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="secret_key_icon"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            Your PayMongo Secret Key (starts with sk_test_ for test mode or sk_live_ for live mode)
                        </p>
                    </div>

                    <!-- Public Key -->
                    <div>
                        <label for="public_key" class="block text-sm font-medium text-gray-700 mb-2">
                            Public Key <span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="public_key" name="public_key"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="pk_test_... or pk_live_..."
                               value="<?= htmlspecialchars($settings['public_key'] ?? '') ?>">
                        <p class="mt-1 text-sm text-gray-500">
                            Your PayMongo Public Key (starts with pk_test_ for test mode or pk_live_ for live mode)
                        </p>
                    </div>

                    <!-- Webhook Secret -->
                    <div>
                        <label for="webhook_secret" class="block text-sm font-medium text-gray-700 mb-2">
                            Webhook Secret
                        </label>
                        <div class="relative">
                            <input type="password" id="webhook_secret" name="webhook_secret"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="whsec_..."
                                   value="<?= htmlspecialchars($settings['webhook_secret'] ?? '') ?>">
                            <button type="button" onclick="togglePassword('webhook_secret')" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                <i class="fas fa-eye text-gray-400 hover:text-gray-600" id="webhook_secret_icon"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            Optional: Your PayMongo Webhook Secret for receiving payment notifications
                        </p>
                    </div>

                    <!-- Buttons -->
                    <div class="flex space-x-4">
                        <button type="submit" 
                                class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <i class="fas fa-save mr-2"></i>
                            Save Settings
                        </button>
                        
                        <button type="button" onclick="testConnection()"
                                class="bg-green-600 text-white px-6 py-2 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500">
                            <i class="fas fa-plug mr-2"></i>
                            Test Connection
                        </button>
                    </div>
                </div>
            </form>

            <!-- Help Section -->
            <div class="mt-8 border-t pt-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-question-circle mr-2"></i>
                    How to get your PayMongo API Keys
                </h3>
                <div class="bg-gray-50 p-4 rounded-md">
                    <ol class="list-decimal list-inside space-y-2 text-sm text-gray-700">
                        <li>Go to <a href="https://dashboard.paymongo.com" target="_blank" class="text-blue-600 hover:underline">PayMongo Dashboard</a></li>
                        <li>Sign up for a PayMongo account if you don't have one</li>
                        <li>Navigate to "Developers" → "API Keys"</li>
                        <li>Copy your Secret Key and Public Key</li>
                        <li>Use Test keys for testing, Live keys for production</li>
                        <li>For webhook secret, go to "Developers" → "Webhooks" and create a webhook endpoint</li>
                        <li>Paste the keys in the form above and click "Save Settings"</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <script>
        <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({
            title: 'Success!',
            text: '<?= $_SESSION['success'] ?>',
            icon: 'success'
        });
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({
            title: 'Error!',
            text: '<?= $_SESSION['error'] ?>',
            icon: 'error'
        });
        <?php unset($_SESSION['error']); endif; ?>

        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function testConnection() {
            const secretKey = document.getElementById('secret_key').value;
            
            // Validate secret key is not empty
            if (!secretKey) {
                Swal.fire('Error', 'Please enter your Secret Key first', 'error');
                return;
            }
            
            // Validate secret key format
            if (!secretKey.match(/^sk_(test|live)_/)) {
                Swal.fire('Error', 'Invalid Secret Key format. Must start with sk_test_ or sk_live_', 'error');
                return;
            }
            
            // Save current form values to restore them if needed
            const formData = new FormData(document.getElementById('paymongoForm'));
            
            Swal.fire({
                title: 'Testing Connection...',
                text: 'Please wait while we test your PayMongo connection.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Create a new FormData just for the test
            const testData = new FormData();
            testData.append('action', 'test_connection');
            testData.append('secret_key', secretKey);
            
            fetch(window.location.href, {
                method: 'POST',
                body: testData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(() => {
                // Instead of reloading the page, show success message
                Swal.fire({
                    title: 'Success!',
                    text: 'Connection test successful! Your PayMongo API key is valid.',
                    icon: 'success'
                });
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    title: 'Connection Failed',
                    text: 'Could not connect to PayMongo. Please check your Secret Key and try again.',
                    icon: 'error'
                });
                
                // No need to restore form data as we're not reloading the page
            });
        }

        // Form validation
        document.getElementById('paymongoForm').addEventListener('submit', function(e) {
            const isEnabled = document.getElementById('is_enabled').checked;
            const secretKey = document.getElementById('secret_key').value;
            const publicKey = document.getElementById('public_key').value;
            
            if (isEnabled && (!secretKey || !publicKey)) {
                e.preventDefault();
                Swal.fire('Error', 'Both Secret Key and Public Key are required when enabling PayMongo', 'error');
                return;
            }
            
            if (secretKey && !secretKey.match(/^sk_(test|live)_/)) {
                e.preventDefault();
                Swal.fire('Error', 'Invalid Secret Key format. Must start with sk_test_ or sk_live_', 'error');
                return;
            }
            
            if (publicKey && !publicKey.match(/^pk_(test|live)_/)) {
                e.preventDefault();
                Swal.fire('Error', 'Invalid Public Key format. Must start with pk_test_ or pk_live_', 'error');
                return;
            }
        });
    </script>
</body>
</html>
