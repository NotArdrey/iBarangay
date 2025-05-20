<?php
// functions/services.php – full rewrite with PayMongo integration and SMS capability
session_start();
require __DIR__ . '/../config/dbconn.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// SMS Sender Helper Class
class SMSSender {
    private $apiKey;
    private $senderID;
    private $baseUrl;
    private $provider;
    
    /**
     * Initialize the SMS sender with provider configuration
     * 
     * @param array|string $config Configuration array or JSON string
     */
    public function __construct($config) {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        
        $this->provider = $config['provider'] ?? 'semaphore';
        
        switch ($this->provider) {
            case 'semaphore':
                $this->apiKey = $config['api_key'] ?? '';
                $this->senderID = $config['sender_id'] ?? '';
                $this->baseUrl = 'https://api.semaphore.co/api/v4/messages';
                break;
            case 'twilio':
                $this->apiKey = $config['api_key'] ?? '';
                $this->senderID = $config['sender_id'] ?? '';
                $this->baseUrl = 'https://api.twilio.com/2010-04-01/Accounts/' . $config['account_sid'] . '/Messages.json';
                break;
            default:
                throw new Exception('Unsupported SMS provider: ' . $this->provider);
        }
    }
    
    /**
     * Send SMS message
     * 
     * @param string $to Recipient phone number
     * @param string $message SMS content
     * @return array Result with success status and details
     */
    public function send($to, $message) {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'SMS configuration is incomplete'
            ];
        }
        
        // Format phone number (ensure it has country code)
        $to = $this->formatPhoneNumber($to);
        
        switch ($this->provider) {
            case 'semaphore':
                return $this->sendSemaphore($to, $message);
            case 'twilio':
                return $this->sendTwilio($to, $message);
            default:
                return [
                    'success' => false,
                    'message' => 'Unsupported SMS provider'
                ];
        }
    }
    
    /**
     * Format phone number to ensure it has country code (+63 for Philippines)
     */
    private function formatPhoneNumber($phoneNumber) {
        // Remove any non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If number starts with 0, replace with country code
        if (substr($phoneNumber, 0, 1) === '0') {
            $phoneNumber = '63' . substr($phoneNumber, 1);
        }
        
        // If no country code, add Philippines by default (+63)
        if (strlen($phoneNumber) === 10) {
            $phoneNumber = '63' . $phoneNumber;
        }
        
        return $phoneNumber;
    }
    
    /**
     * Send SMS via Semaphore API
     */
    private function sendSemaphore($to, $message) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $data = [
            'apikey' => $this->apiKey,
            'number' => $to,
            'message' => $message
        ];
        
        if (!empty($this->senderID)) {
            $data['sendername'] = $this->senderID;
        }
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'message_id' => $result['message_id'] ?? '',
                'provider' => 'semaphore'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Semaphore API Error: ' . ($result['message'] ?? 'Unknown error'),
                'http_code' => $httpCode
            ];
        }
    }
    
    /**
     * Send SMS via Twilio API
     */
    private function sendTwilio($to, $message) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERPWD, $this->apiKey); // Auth with API key as username
        
        $data = [
            'To' => '+' . $to,
            'Body' => $message,
            'From' => $this->senderID
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error
            ];
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'message_id' => $result['sid'] ?? '',
                'provider' => 'twilio'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Twilio API Error: ' . ($result['message'] ?? 'Unknown error'),
                'http_code' => $httpCode
            ];
        }
    }
}

/**
 * Send notification to user via SMS - This function will never fail silently
 * Logs directly to the AuditTrail table without using environment variables
 */
function sendSMS($phoneNumber, $message, $barangayId, $pdo) {
    try {
        // Skip empty phone numbers
        if (empty($phoneNumber)) {
            error_log('SMS not sent: Empty phone number provided');
            return [
                'success' => false,
                'message' => 'Phone number is required'
            ];
        }
        
        // Get SMS configuration for barangay from BarangayPaymentMethod table
        $stmt = $pdo->prepare("
            SELECT account_details 
            FROM BarangayPaymentMethod 
            WHERE barangay_id = ? AND method = 'SMS' AND is_active = 'yes'
        ");
        $stmt->execute([$barangayId]);
        $smsConfig = $stmt->fetchColumn();
        
        // If no configuration, use mock provider
        if (!$smsConfig) {
            // Use mock provider for testing
            $smsConfig = json_encode([
                'provider' => 'mock',
                'sender_id' => 'BRGY_' . $barangayId
            ]);
            
            // Log warning about missing configuration
            error_log('No SMS configuration found for barangay ' . $barangayId . '. Using mock provider.');
        }
        
        // Always send SMS using available configuration
        $sender = new SMSSender($smsConfig);
        $result = $sender->send($phoneNumber, $message);
        
        // Add to audit trail
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO AuditTrail (
                admin_user_id, action, table_name, record_id, description
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            'SMS',
            'Users', // Using Users table as reference point
            $userId,
            ($result['success'] ? 'SMS sent to ' : 'Failed sending SMS to ') . 
            $phoneNumber . ' via ' . ($result['provider'] ?? 'unknown provider') .
            ': ' . substr($message, 0, 50) . (strlen($message) > 50 ? '...' : '') .
            ' - Barangay: ' . $barangayId
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        // Log the error
        error_log('SMS Error: ' . $e->getMessage());
        
        // Try to log the error in the audit trail
        try {
            // Add to audit trail
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO AuditTrail (
                    admin_user_id, action, table_name, record_id, description
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                'ERROR',
                'Users',
                $userId,
                'SMS Error: Failed to send to ' . $phoneNumber . ' - ' . $e->getMessage() . ' - Barangay: ' . $barangayId
            ]);
        } catch (Exception $logEx) {
            // If we can't even log the error, just write to error log
            error_log('Failed to log SMS error in audit trail: ' . $logEx->getMessage());
        }
        
        return [
            'success' => false,
            'message' => 'SMS Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Send email notification using PHPMailer
 * Logs directly to the AuditTrail table without using environment variables
 */
function sendEmail($to, $subject, $body, $barangayId, $pdo) {
    try {
        // Skip empty email addresses
        if (empty($to)) {
            error_log('Email not sent: Empty recipient email address');
            return [
                'success' => false,
                'message' => 'Recipient email is required'
            ];
        }
        
        // Initialize PHPMailer
        $mail = new PHPMailer(true);
        
        // Configure server settings - hardcoded for simplicity
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'barangayhub2@gmail.com';
        $mail->Password   = 'eisy hpjz rdnt bwrp';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Additional SMTP settings for reliability
        $mail->Timeout = 60; // seconds
        $mail->SMTPKeepAlive = true; // Maintains connection for multiple emails
        
        // Get barangay name for the from address
        $stmt = $pdo->prepare("SELECT barangay_name FROM Barangay WHERE barangay_id = ?");
        $stmt->execute([$barangayId]);
        $barangayName = $stmt->fetchColumn() ?: 'Barangay Hub';
        
        // Set sender and recipient
        $mail->setFrom('noreply@barangayhub.com', $barangayName);
        $mail->addAddress($to);
        
        // Set content format
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
        // Set email content
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // Create plain text version
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        
        // Try to send the email
        $emailSent = false;
        try {
            $emailSent = $mail->send();
        } catch (Exception $mailEx) {
            // Catch sending errors but continue to log the attempt
            error_log('PHPMailer Send Error: ' . $mailEx->getMessage());
            $emailSent = false;
        }
        
        // Add to audit trail
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
        
        $stmt = $pdo->prepare("
            INSERT INTO AuditTrail (
                admin_user_id, action, table_name, record_id, description
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            'EMAIL',
            'Users', // Using Users table as reference point
            $userId,
            ($emailSent ? 'Email sent to ' : 'Failed sending email to ') . $to . 
            ' - Subject: ' . $subject . 
            ' - Barangay: ' . $barangayId
        ]);
        
        // Return appropriate result
        if ($emailSent) {
            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to send email'
            ];
        }
        
    } catch (Exception $e) {
        // Log the error
        error_log('PHPMailer Error: ' . $e->getMessage());
        
        // Try to log the failed attempt in audit trail
        try {
            // Add to audit trail
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO AuditTrail (
                    admin_user_id, action, table_name, record_id, description
                ) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                'ERROR',
                'Users',
                $userId,
                'Email Error: Failed to send to ' . $to . ' - ' . $e->getMessage()
            ]);
        } catch (Exception $logEx) {
            // If we can't even log the error, just write to error log
            error_log('Failed to log email error in audit trail: ' . $logEx->getMessage());
        }
        
        return [
            'success' => false,
            'message' => 'Email Error: ' . $e->getMessage()
        ];
    }
}

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
            // Get payment details
            $stmt = $pdo->prepare("
                SELECT ps.*, u.phone_number, u.email, u.name, u.barangay_id, dt.document_name
                FROM PaymentSessions ps
                JOIN Users u ON ps.user_id = u.user_id
                JOIN DocumentType dt ON ps.document_id = dt.document_type_id
                WHERE ps.session_id = ?
            ");
            $stmt->execute([$sessionId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payment) {
                // Update payment status
                $status = $success ? 'completed' : 'cancelled';
                $stmt = $pdo->prepare("
                    UPDATE PaymentSessions 
                    SET status = ? 
                    WHERE session_id = ?
                ");
                $stmt->execute([$status, $sessionId]);
                
                // Send notifications if payment was successful
                if ($success && isset($payment['phone_number']) && !empty($payment['phone_number'])) {
                    // Send SMS notification
                    $smsMessage = "Your payment of PHP " . number_format($payment['amount'], 2) . 
                                  " for " . $payment['document_name'] . " has been received. Reference: " . 
                                  $payment['reference'];
                    
                    sendSMS($payment['phone_number'], $smsMessage, $payment['barangay_id'], $pdo);
                }
                
                if ($success && isset($payment['email']) && !empty($payment['email'])) {
                    // Send email notification
                    $emailSubject = "Payment Confirmation: " . $payment['document_name'];
                    $emailBody = "
                        <html>
                        <head>
                            <title>Payment Confirmation</title>
                        </head>
                        <body>
                            <h2>Payment Confirmation</h2>
                            <p>Dear " . htmlspecialchars($payment['name']) . ",</p>
                            <p>We have received your payment of PHP " . number_format($payment['amount'], 2) . 
                            " for " . $payment['document_name'] . ".</p>
                            <p><strong>Payment Reference:</strong> " . $payment['reference'] . "</p>
                            <p>Your document request is now being processed. We will notify you once it's ready.</p>
                            <br>
                            <p>Thank you for using our service.</p>
                        </body>
                        </html>
                    ";
                    
                    sendEmail($payment['email'], $emailSubject, $emailBody, $payment['barangay_id'], $pdo);
                }
            }
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
    header('Location: ../pages/login.php');
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName  = $_SESSION['user_name']  ?? 'User';

try {
    // Get user details including phone number
    $stmt = $pdo->prepare("SELECT phone_number, barangay_id FROM Users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    $userPhone = $userDetails['phone_number'] ?? '';
    $userBarangayId = $userDetails['barangay_id'] ?? null;
    
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

    // Get document name for notifications
    $stmt = $pdo->prepare("SELECT document_name FROM DocumentType WHERE document_type_id = ?");
    $stmt->execute([$docTypeId]);
    $documentName = $stmt->fetchColumn() ?: 'Document';

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

    /* ─────── notifications ─────── */
    // Send SMS notification if phone number is available
    if (!empty($userPhone)) {
        $smsMessage = "Thank you for submitting a request for $documentName. Your request ID is #$requestId. We will process it shortly.";
        sendSMS($userPhone, $smsMessage, $barangayId, $pdo);
    }
    
    // Send email notification if email is available
    if (!empty($userEmail)) {
        $emailSubject = "Document Request Confirmation: #$requestId";
        $emailBody = "
            <html>
            <head>
                <title>Document Request Confirmation</title>
            </head>
            <body>
                <h2>Document Request Confirmation</h2>
                <p>Dear " . htmlspecialchars($userName) . ",</p>
                <p>Thank you for submitting a request for <strong>" . htmlspecialchars($documentName) . "</strong>.</p>
                <p><strong>Request ID:</strong> #$requestId</p>
                <p><strong>Delivery Method:</strong> $delivery</p>";
        
        if ($paymentAmount > 0) {
            $emailBody .= "<p><strong>Payment Amount:</strong> PHP " . number_format($paymentAmount, 2) . "</p>";
            $emailBody .= "<p><strong>Payment Method:</strong> $paymentMethod</p>";
            
            if ($paymentMethod === 'PayMongo') {
                $emailBody .= "<p><strong>Payment Reference:</strong> " . substr($proofPath, 9) . "</p>";
            }
        }
        
        $emailBody .= "
                <p>We will process your request shortly and notify you once it's ready.</p>
                <br>
                <p>Thank you for using our service.</p>
            </body>
            </html>
        ";
        
        sendEmail($userEmail, $emailSubject, $emailBody, $barangayId, $pdo);
    }

    $pdo->commit();

    $_SESSION['success'] = [
        'title'      => 'Success!',
        'message'    => 'Your document request was submitted.',
        'processing' => 'We will process it shortly and email/SMS you updates on your request.'
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