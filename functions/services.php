<?php
// functions/services.php – full rewrite with SMS capability
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
            INSERT INTO audit_trails (
                user_id, admin_user_id, action, table_name, record_id, description
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $userId,
            'SMS',
            'users', // Using users table as reference point
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
                INSERT INTO audit_trails (
                    user_id, admin_user_id, action, table_name, record_id, description
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $userId,
                'ERROR',
                'users',
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
        $stmt = $pdo->prepare("SELECT name FROM barangays WHERE id = ?");
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
            INSERT INTO audit_trails (
                user_id, admin_user_id, action, table_name, record_id, description
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $userId,
            'EMAIL',
            'users', // Using users table as reference point
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
                INSERT INTO audit_trails (
                    user_id, admin_user_id, action, table_name, record_id, description
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $userId,
                'ERROR',
                'users',
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
    // Get user details including phone and barangay
    $stmt = $pdo->prepare("SELECT phone, barangay_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    $userPhone = $userDetails['phone'] ?? '';
    $userBarangayId = $userDetails['barangay_id'] ?? null;
    
    $pdo->beginTransaction();

    /* ─────── payment / proof ─────── */
    $delivery      = $_POST['deliveryMethod'] ?? 'Hardcopy';
    $proofPath     = null;

    // No payment logic, so skip paymentAmount, paymentMethod, proof upload, PayMongo, etc.

    /* ─────── validate doc ─────── */
    $docTypeId  = filter_input(INPUT_POST, 'document_type_id', FILTER_VALIDATE_INT);
    if (!$docTypeId || !$userBarangayId) {
        throw new Exception('Please select document type.');
    }

    $chk = $pdo->prepare("SELECT COUNT(*) FROM document_types WHERE id = ?");
    $chk->execute([$docTypeId]);
    if (!$chk->fetchColumn()) {
        throw new Exception('Document type not found.');
    }

    // Get document name for notifications
    $stmt = $pdo->prepare("SELECT name FROM document_types WHERE id = ?");
    $stmt->execute([$docTypeId]);
    $documentName = $stmt->fetchColumn() ?: 'Document';

    /* ─────── insert request ─────── */
    // Get person_id for this user
    $stmt = $pdo->prepare("SELECT id FROM persons WHERE user_id = ?");
    $stmt->execute([$userId]);
    $personId = $stmt->fetchColumn();
    if (!$personId) {
        throw new Exception('User profile not found.');
    }

    /* NEW: Check for any pending blotter cases for this person (in any barangay) */
    $stmtBlotter = $pdo->prepare("SELECT COUNT(*) FROM blotter_cases WHERE reported_by_person_id = ? AND status IN ('pending','open')");
    $stmtBlotter->execute([$personId]);
    if ($stmtBlotter->fetchColumn() > 0) {
        throw new Exception('You have a pending blotter case. Please resolve it before requesting a certificate.');
    }

    $pdo->prepare("
        INSERT INTO document_requests
            (person_id, document_type_id, barangay_id, delivery_method, proof_image_path, requested_by_user_id)
        VALUES (?,?,?,?,?,?)
    ")->execute([$personId, $docTypeId, $userBarangayId, $delivery, $proofPath, $userId]);
    $requestId = $pdo->lastInsertId();

    /* ─────── extra attributes ─────── */
    // Map attribute keys to attribute_type_id
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
        INSERT INTO document_request_attributes (request_id, attribute_type_id, value)
        VALUES (?,?,?)
    ");
    foreach ($attrs as $k => $v) {
        if ($v !== null && trim($v) !== '') {
            // Get attribute_type_id
            $stmt = $pdo->prepare("SELECT id FROM document_attribute_types WHERE code = ? AND document_type_id = ?");
            $stmt->execute([$k, $docTypeId]);
            $attrTypeId = $stmt->fetchColumn();
            if ($attrTypeId) {
                $ins->execute([$requestId, $attrTypeId, trim($v)]);
            }
        }
    }

    /* ─────── audit trail ─────── */
    $pdo->prepare("
        INSERT INTO audit_trails
            (user_id, admin_user_id, action, table_name, record_id, description)
        VALUES (?,?,?,?,?,?)
    ")->execute([
        $userId,
        $userId,
        'INSERT',
        'document_requests',
        $requestId,
        'Submitted document request'
    ]);

    /* ─────── notifications ─────── */
    // Send SMS notification if phone is available
    if (!empty($userPhone)) {
        $smsMessage = "Thank you for submitting a request for $documentName. Your request ID is #$requestId. We will process it shortly.";
        sendSMS($userPhone, $smsMessage, $userBarangayId, $pdo);
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
                <p><strong>Delivery Method:</strong> $delivery</p>
                <p>We will process your request shortly and notify you once it's ready.</p>
                <br>
                <p>Thank you for using our service.</p>
            </body>
            </html>
        ";
        sendEmail($userEmail, $emailSubject, $emailBody, $userBarangayId, $pdo);
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