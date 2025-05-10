<?php
// functions/services.php  – full rewrite with PayMongo integration
session_start();
require __DIR__ . '/../config/dbconn.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// PayMongo integration helper function
function createPaymongoPaymentLink($amount, $description, $paymongoConfig, $userId, $docTypeId, $successUrl = '', $cancelUrl = '') {
    // Decode stored config if needed
    if (is_string($paymongoConfig)) {
        $config = json_decode($paymongoConfig, true);
    } else {
        $config = $paymongoConfig;
    }
    
    if (!isset($config['secret_key']) || empty($config['secret_key'])) {
        return [
            'success' => false,
            'message' => 'Invalid PayMongo configuration: Missing secret key'
        ];
    }
    
    $secretKey = $config['secret_key'];
    
    // Set default URLs if not provided
    $host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $successUrl = $successUrl ?: "$host/pages/services.php?payment_success=true&doc_id=$docTypeId";
    $cancelUrl = $cancelUrl ?: "$host/pages/services.php?payment_canceled=true";
    
    // Create unique reference
    $reference = 'DOC_' . $docTypeId . '_' . time();
    
    // Create a PayMongo checkout session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Format amount correctly - PayMongo requires amount in cents
    $amountInCents = round($amount * 100);
    
    $data = [
        'data' => [
            'attributes' => [
                'line_items' => [
                    [
                        'name' => $description,
                        'amount' => $amountInCents,
                        'quantity' => 1
                    ]
                ],
                'payment_method_types' => ['card', 'gcash', 'grab_pay'],
                'success_url' => $successUrl . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'reference_number' => $reference,
                'description' => $description
            ]
        ]
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secretKey . ':')
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Handle errors
    if ($error) {
        return [
            'success' => false,
            'message' => 'cURL Error: ' . $error
        ];
    }
    
    $result = json_decode($response, true);
    
    // Check if request was successful
    if ($httpCode >= 200 && $httpCode < 300 && isset($result['data']['attributes']['checkout_url'])) {
        return [
            'success' => true,
            'checkout_url' => $result['data']['attributes']['checkout_url'],
            'session_id' => $result['data']['id'],
            'reference' => $reference
        ];
    } else {
        $errorMessage = isset($result['errors'][0]['detail']) 
            ? $result['errors'][0]['detail'] 
            : 'Unknown error occurred';
        
        return [
            'success' => false,
            'message' => 'PayMongo API Error: ' . $errorMessage,
            'http_code' => $httpCode
        ];
    }
}

// Handle payment creation ajax request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'create_payment') {
    // This is the API endpoint for AJAX payment creation
    header('Content-Type: application/json');
    
    // Make sure user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'User not authenticated']);
        exit;
    }
    
    // Get request data from POST or JSON body
    $requestData = [];
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $requestData = json_decode(file_get_contents('php://input'), true);
    } else {
        $requestData = $_POST;
    }
    
    // Validate required fields
    if (!isset($requestData['amount'], $requestData['document_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    $amount = floatval($requestData['amount']);
    $docTypeId = intval($requestData['document_id']);
    $userId = $_SESSION['user_id'];
    $description = $requestData['description'] ?? 'Document Request Payment';
    $successUrl = $requestData['success_url'] ?? '';
    $cancelUrl = $requestData['cancel_url'] ?? '';
    
    try {
        // Get user's barangay
        $stmt = $pdo->prepare("SELECT barangay_id FROM Users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $barangayId = $stmt->fetchColumn();
        
        if (!$barangayId) {
            echo json_encode(['success' => false, 'message' => 'User has no associated barangay']);
            exit;
        }
        
        // Get PayMongo credentials
        $stmt = $pdo->prepare("
            SELECT account_details 
            FROM BarangayPaymentMethod 
            WHERE barangay_id = ? AND method = 'PayMongo' AND is_active = 'yes'
        ");
        $stmt->execute([$barangayId]);
        $paymongoConfig = $stmt->fetchColumn();
        
        if (!$paymongoConfig) {
            echo json_encode(['success' => false, 'message' => 'PayMongo not available for your barangay']);
            exit;
        }
        
        // Create payment link
        $result = createPaymongoPaymentLink(
            $amount, 
            $description, 
            $paymongoConfig, 
            $userId, 
            $docTypeId, 
            $successUrl, 
            $cancelUrl
        );
        
        if ($result['success']) {
            // Check if we have the PaymentSessions table, if not, create it
            $tableExists = $pdo->query("SHOW TABLES LIKE 'PaymentSessions'")->rowCount() > 0;
            if (!$tableExists) {
                $pdo->exec("
                    CREATE TABLE PaymentSessions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        document_id INT NOT NULL,
                        session_id VARCHAR(100) NOT NULL,
                        amount DECIMAL(10,2) NOT NULL,
                        reference VARCHAR(100) NOT NULL,
                        status ENUM('pending', 'completed', 'cancelled', 'failed') DEFAULT 'pending',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES Users(user_id) ON DELETE CASCADE
                    )
                ");
            }
            
            // Store payment session in database
            $stmt = $pdo->prepare("
                INSERT INTO PaymentSessions (
                    user_id, document_id, session_id, amount, reference, status
                ) VALUES (?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $userId,
                $docTypeId,
                $result['session_id'],
                $amount,
                $result['reference']
            ]);
            
            echo json_encode([
                'success' => true,
                'checkout_url' => $result['checkout_url'],
                'session_id' => $result['session_id']
            ]);
        } else {
            echo json_encode($result); // Return error message
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle payment callback
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['payment_success'], $_GET['session_id'])) {
    // This handles the callback from PayMongo after payment
    $sessionId = $_GET['session_id'];
    $success = $_GET['payment_success'] === 'true';
    
    try {
        // Check if PaymentSessions table exists
        $tableExists = $pdo->query("SHOW TABLES LIKE 'PaymentSessions'")->rowCount() > 0;
        if ($tableExists) {
            // Update payment status
            $status = $success ? 'completed' : 'cancelled';
            $stmt = $pdo->prepare("
                UPDATE PaymentSessions 
                SET status = ? 
                WHERE session_id = ?
            ");
            $stmt->execute([$status, $sessionId]);
        }
        
        if ($success) {
            $_SESSION['payment_reference'] = $sessionId;
            $_SESSION['payment_success'] = true;
        } else {
            $_SESSION['payment_cancelled'] = true;
        }
        
        // Redirect to services page
        header('Location: ../pages/services.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error processing payment: ' . $e->getMessage();
        header('Location: ../pages/services.php');
        exit;
    }
}

/* ─────── regular form submission handling ─────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_GET['action'])) {
    $_SESSION['error'] = 'Use the form to submit a request.';
    header('Location: ../pages/services.php');
    exit;
}

if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in first.';
    header('Location: ../pages/index.php');
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName  = $_SESSION['user_name']  ?? 'User';

try {
    $pdo->beginTransaction();

    /* ──────── ID upload ──────── */
    if (!isset($_FILES['uploadId']) || $_FILES['uploadId']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Valid ID is required.');
    }
    $allowedExt = ['jpg','jpeg','png','pdf'];
    $ext = strtolower(pathinfo($_FILES['uploadId']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        throw new Exception('Invalid ID format; only JPG, PNG, PDF allowed.');
    }
    if ($_FILES['uploadId']['size'] > 2 * 1024 * 1024) {
        throw new Exception('ID file too large; max 2 MB.');
    }
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $idFilename = sprintf('id_user_%d_%d.%s', $userId, time(), $ext);
    $idTarget   = $uploadDir . $idFilename;
    if (!move_uploaded_file($_FILES['uploadId']['tmp_name'], $idTarget)) {
        throw new Exception('Failed to save uploaded ID.');
    }
    $idPath = 'uploads/' . $idFilename;
    $pdo->prepare("UPDATE Users SET id_image_path = ? WHERE user_id = ?")
        ->execute([$idPath, $userId]);

    /* ─────── payment / proof ─────── */
    $delivery      = $_POST['deliveryMethod'] ?? 'Hardcopy';
    $paymentAmount = (float) ($_POST['paymentAmount'] ?? 0);
    $paymentMethod = $_POST['paymentMethod'] ?? '';
    $proofPath     = null;

    if ($paymentAmount > 0) {
        if ($paymentMethod === 'GCash') {
            // For GCash, process payment proof upload
            if (!isset($_FILES['uploadProof']) || $_FILES['uploadProof']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Payment receipt is required for documents with a fee.');
            }
            $ext2 = strtolower(pathinfo($_FILES['uploadProof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext2, $allowedExt)) {
                throw new Exception('Invalid receipt format; only JPG, PNG, PDF allowed.');
            }
            if ($_FILES['uploadProof']['size'] > 2 * 1024 * 1024) {
                throw new Exception('Receipt file too large; max 2 MB.');
            }
            $proofFilename = sprintf('proof_user_%d_%d.%s', $userId, time(), $ext2);
            $proofTarget   = $uploadDir . $proofFilename;
            if (!move_uploaded_file($_FILES['uploadProof']['tmp_name'], $proofTarget)) {
                throw new Exception('Failed to save payment receipt.');
            }
            $proofPath = 'uploads/' . $proofFilename;
        } elseif ($paymentMethod === 'PayMongo') {
            // For PayMongo, verify payment reference
            $paymongoReference = $_POST['paymongoReference'] ?? '';
            if (empty($paymongoReference)) {
                throw new Exception('Payment reference is required. Please complete payment first.');
            }
            
            // Check if PaymentSessions table exists
            $tableExists = $pdo->query("SHOW TABLES LIKE 'PaymentSessions'")->rowCount() > 0;
            
            if ($tableExists) {
                // Verify the payment session exists
                $stmt = $pdo->prepare("
                    SELECT id, status FROM PaymentSessions 
                    WHERE session_id = ? AND user_id = ? 
                    LIMIT 1
                ");
                $stmt->execute([$paymongoReference, $userId]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$payment) {
                    throw new Exception('Invalid payment reference. Please complete payment first.');
                }
                
                // Update payment status to completed if it's not already
                if ($payment['status'] !== 'completed') {
                    $stmt = $pdo->prepare("
                        UPDATE PaymentSessions 
                        SET status = 'completed' 
                        WHERE id = ?
                    ");
                    $stmt->execute([$payment['id']]);
                }
            }
            
            // Store payment reference in proof_image_path
            $proofPath = 'paymongo:' . $paymongoReference;
        }
    }

    /* ─────── validate doc & barangay ─────── */
    $docTypeId  = filter_input(INPUT_POST, 'document_type_id', FILTER_VALIDATE_INT);
    $barangayId = filter_input(INPUT_POST, 'barangay_id',     FILTER_VALIDATE_INT);
    if (!$docTypeId || !$barangayId) {
        throw new Exception('Please select document type and barangay.');
    }

    $chk = $pdo->prepare("SELECT COUNT(*) FROM DocumentType WHERE document_type_id = ?");
    $chk->execute([$docTypeId]);
    if (!$chk->fetchColumn()) {
        throw new Exception('Document type not found.');
    }

    $chk = $pdo->prepare("SELECT COUNT(*) FROM Barangay WHERE barangay_id = ?");
    $chk->execute([$barangayId]);
    if (!$chk->fetchColumn()) {
        throw new Exception('Barangay not found.');
    }

    /* ─────── insert request ─────── */
    $pdo->prepare("
        INSERT INTO DocumentRequest
            (user_id, document_type_id, barangay_id, delivery_method, proof_image_path)
        VALUES (?,?,?,?,?)
    ")->execute([$userId, $docTypeId, $barangayId, $delivery, $proofPath]);
    $requestId = $pdo->lastInsertId();

    /* ─────── extra attributes ─────── */
    $attrs = [
        'clearance_purpose'  => $_POST['purposeClearance']  ?? null,
        'residency_duration' => $_POST['residencyDuration'] ?? null,
        'residency_purpose'  => $_POST['residencyPurpose']  ?? null,
        'gmc_purpose'        => $_POST['gmcPurpose']        ?? null,
        'nic_reason'         => $_POST['nicReason']         ?? null,
        'indigency_income'   => $_POST['indigencyIncome']   ?? null,
        'indigency_reason'   => $_POST['indigencyReason']   ?? null,
    ];
    $ins = $pdo->prepare("
        INSERT INTO DocumentRequestAttribute (request_id, attr_key, attr_value)
        VALUES (?,?,?)
    ");
    foreach ($attrs as $k => $v) {
        if ($v !== null && trim($v) !== '') {
            $ins->execute([$requestId, $k, trim($v)]);
        }
    }

    /* ─────── audit trail ─────── */
    $paymentInfo = '';
    if ($paymentAmount > 0) {
        $paymentInfo = ' with ' . $paymentMethod . ' payment';
        if ($paymentMethod === 'PayMongo') {
            $paymentInfo .= ' (ref: ' . substr($proofPath, 9) . ')';  // Extract ref from 'paymongo:ref'
        }
    }
    
    $pdo->prepare("
        INSERT INTO AuditTrail
            (admin_user_id, action, table_name, record_id, description)
        VALUES (?,?,?,?,?)
    ")->execute([
        $userId,
        'INSERT',
        'DocumentRequest',
        $requestId,
        'Submitted document request' . $paymentInfo
    ]);

    $pdo->commit();

    $_SESSION['success'] = [
        'title'      => 'Success!',
        'message'    => 'Your document request was submitted.',
        'processing' => 'We will process it shortly and email you updates on your request.'
    ];
    header('Location: ../pages/services.php');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
    header('Location: ../pages/services.php');
    exit;
}