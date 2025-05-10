<?php
session_start();
// doc_request.php
require "../vendor/autoload.php";
require "../config/dbconn.php";
use Dompdf\Dompdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception; 


//..pages/doc_request.php

// 3) Make sure the user is actually logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

// 4) Safe to read these now
$current_admin_id = $_SESSION['user_id'];
$bid              = $_SESSION['barangay_id'];
$role             = $_SESSION['role_id'];


/**
 * Helper function to insert into AuditTrail
 * Adjust or refine as desired.
 */

function createPaymongoPaymentLink($amount, $description, $paymongoConfig, $successCallback = null, $cancelCallback = null) {
  // Decode stored config
  $config = json_decode($paymongoConfig, true);
  if (!isset($config['secret_key']) || empty($config['secret_key'])) {
    return [
      'success' => false,
      'message' => 'Invalid PayMongo configuration: Missing secret key'
    ];
  }
  
  $secretKey = $config['secret_key'];
  
  // Default callback URLs if not provided
  $successUrl = $successCallback ?? 'https://yourwebsite.com/payment-success';
  $cancelUrl = $cancelCallback ?? 'https://yourwebsite.com/payment-cancel';
  
  // Create a PayMongo checkout session
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST, 1);
  
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
              'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
              'cancel_url' => $cancelUrl
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
      'session_id' => $result['data']['id']
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

function logAuditTrail($pdo, $adminId, $action, $tableName, $recordId, $description) {
    $stmtAudit = $pdo->prepare("
        INSERT INTO AuditTrail (admin_user_id, action, table_name, record_id, description)
        VALUES (:admin_id, :action, :tbl, :rid, :desc)
    ");
    $stmtAudit->execute([
        ':admin_id' => $adminId,
        ':action'   => $action,
        ':tbl'      => $tableName,
        ':rid'      => $recordId,
        ':desc'     => $description
    ]);
}

// Handle GCash insert/update
if (isset($_POST['barangay_id'], $_POST['payment_method'])) {
  $bid = intval($_POST['barangay_id']);
  $method = $_POST['payment_method'];
  
  // First deactivate all payment methods for this barangay
  $deactivateStmt = $pdo->prepare("
      UPDATE BarangayPaymentMethod 
      SET is_active = 'no' 
      WHERE barangay_id = ?
  ");
  $deactivateStmt->execute([$bid]);
  
  // Process differently based on payment method
  if ($method === 'GCash') {
    // For GCash, we just need the account number
    $acct = trim($_POST['account_details']);
    
    if ($bid && $acct) {
      $stmt = $pdo->prepare("
          INSERT INTO BarangayPaymentMethod (barangay_id, method, account_details, is_active)
          VALUES (?, ?, ?, 'yes')
          ON DUPLICATE KEY UPDATE
              account_details = VALUES(account_details),
              is_active = 'yes'
      ");
      $stmt->execute([$bid, $method, $acct]);
      
      // Grab the ID (either new or updated)
      $pmid = $pdo->lastInsertId() ?: 
             $pdo->query("SELECT payment_method_id FROM BarangayPaymentMethod WHERE barangay_id = $bid AND method = '$method'")->fetchColumn();
      
      logAuditTrail(
        $pdo,
        $current_admin_id,
        'UPDATE',
        'BarangayPaymentMethod',
        $pmid,
        "Set GCash to \"{$acct}\" and activated (deactivated other methods)"
      );
    }
  } else if ($method === 'PayMongo') {
    // For PayMongo, we need to handle both public and secret keys
    $publicKey = trim($_POST['paymongo_public_key']);
    $secretKey = trim($_POST['paymongo_secret_key']);
    
    // Validate keys format
    $isValidPublicKey = preg_match('/^pk_(test|live)_\w+$/', $publicKey);
    $isValidSecretKey = preg_match('/^sk_(test|live)_\w+$/', $secretKey);
    
    if (!$isValidPublicKey || !$isValidSecretKey) {
      // Set error message in session and redirect back
      $_SESSION['payment_error'] = "Invalid PayMongo API keys format. Public key should start with 'pk_' and Secret key with 'sk_'";
      header("Location: doc_request.php");
      exit;
    }
    
    // Check if test/live environment matches
    $publicEnv = strpos($publicKey, 'pk_test_') === 0 ? 'test' : 'live';
    $secretEnv = strpos($secretKey, 'sk_test_') === 0 ? 'test' : 'live';
    
    if ($publicEnv !== $secretEnv) {
      $_SESSION['payment_error'] = "API keys mismatch: You're mixing test and live keys. Both keys must be from the same environment.";
      header("Location: doc_request.php");
      exit;
    }
    
    // Combine keys for storage - better to encrypt these in production
    // But for demonstration, we'll use JSON format
    $paymongoConfig = json_encode([
      'public_key' => $publicKey,
      'secret_key' => $secretKey,
      'environment' => $publicEnv,
      'configured_at' => date('Y-m-d H:i:s')
    ]);
    
    if ($bid && ($publicKey || $secretKey)) {
      $stmt = $pdo->prepare("
          INSERT INTO BarangayPaymentMethod (barangay_id, method, account_details, is_active)
          VALUES (?, ?, ?, 'yes')
          ON DUPLICATE KEY UPDATE
              account_details = VALUES(account_details),
              is_active = 'yes'
      ");
      $stmt->execute([$bid, $method, $paymongoConfig]);
      
      // Grab the ID (either new or updated)
      $pmid = $pdo->lastInsertId() ?: 
             $pdo->query("SELECT payment_method_id FROM BarangayPaymentMethod WHERE barangay_id = $bid AND method = '$method'")->fetchColumn();
      
      // Verify API keys by making a test call
      $testResult = createPaymongoPaymentLink(1, 'API Key Verification', $paymongoConfig);
      
      if ($testResult['success']) {
        $_SESSION['payment_success'] = "PayMongo API keys verified successfully! Payment integration is ready.";
      } else {
        $_SESSION['payment_warning'] = "PayMongo keys saved, but verification failed: " . $testResult['message'];
      }
      
      logAuditTrail(
        $pdo,
        $current_admin_id,
        'UPDATE',
        'BarangayPaymentMethod',
        $pmid,
        "Set PayMongo API keys and activated (deactivated other methods)"
      );
    }
  }
}

// 2) Deactivate
if (isset($_POST['deactivate_payment_method_id'])) {
  $pmid = intval($_POST['deactivate_payment_method_id']);
  
  // Get the method type before deactivating
  $stmtGet = $pdo->prepare("SELECT method FROM BarangayPaymentMethod WHERE payment_method_id = ?");
  $stmtGet->execute([$pmid]);
  $methodType = $stmtGet->fetchColumn();
  $methodName = ($methodType == 'GCash') ? 'GCash' : 'PayMongo';
  
  $pdo->prepare("UPDATE BarangayPaymentMethod SET is_active = 'no' WHERE payment_method_id = ?")
      ->execute([$pmid]);
  logAuditTrail(
    $pdo,
    $current_admin_id,
    'UPDATE',
    'BarangayPaymentMethod',
    $pmid,
    "Deactivated $methodName account"
  );
}

// 3) Activate (and deactivate others)
if (isset($_POST['activate_payment_method_id'])) {
  $pmid = intval($_POST['activate_payment_method_id']);
  
  // Get the barangay_id and method type before activating
  $stmtGet = $pdo->prepare("SELECT barangay_id, method FROM BarangayPaymentMethod WHERE payment_method_id = ?");
  $stmtGet->execute([$pmid]);
  $result = $stmtGet->fetch();
  $barangayId = $result['barangay_id'];
  $methodType = $result['method'];
  $methodName = ($methodType == 'GCash') ? 'GCash' : 'PayMongo';
  
  // First deactivate all payment methods for this barangay
  $deactivateStmt = $pdo->prepare("
      UPDATE BarangayPaymentMethod 
      SET is_active = 'no' 
      WHERE barangay_id = ?
  ");
  $deactivateStmt->execute([$barangayId]);
  
  // Then activate the selected payment method
  $pdo->prepare("UPDATE BarangayPaymentMethod SET is_active = 'yes' WHERE payment_method_id = ?")
      ->execute([$pmid]);
  
  logAuditTrail(
    $pdo,
    $current_admin_id,
    'UPDATE',
    'BarangayPaymentMethod',
    $pmid,
    "Activated $methodName account (deactivated other methods)"
  );
}


// Fetch for listing
$stmt = $pdo->prepare("
  SELECT pm.payment_method_id,
         b.barangay_name,
         pm.method,
         pm.account_details,
         pm.is_active
    FROM BarangayPaymentMethod pm
    JOIN Barangay b
      ON pm.barangay_id = b.barangay_id
   WHERE pm.barangay_id = ?
");
$stmt->execute([$bid]);
$payments = $stmt->fetchAll();

// Also fetch the barangay name to display
$barangayName = $pdo->prepare("SELECT barangay_name FROM Barangay WHERE barangay_id = ?");
$barangayName->execute([$bid]);
$barangayName = $barangayName->fetchColumn();

// ------------------------------------------------------
// 2. Check if we have an AJAX or direct action; if so, return JSON or process
// ------------------------------------------------------
if (isset($_GET['action'])) {
  header('Content-Type: application/json');
  $action   = $_GET['action'];
  $reqId    = isset($_GET['id']) ? intval($_GET['id']) : 0;
  $response = ['success' => false, 'message' => ''];

  try {
      if ($action === 'view_doc_request') {
          $stmt = $pdo->prepare("
              SELECT dr.document_request_id,
                     dr.request_date,
                     dr.status,
                     dr.delivery_method,
                     dr.proof_image_path,
                     dr.remarks AS request_remarks,
                     dt.document_name,
                     u.user_id,
                     u.email,
                     u.contact_number,
                     u.birth_date,
                     u.gender,
                     u.marital_status,
                     u.emergency_contact_name,
                     u.emergency_contact_number,
                     u.emergency_contact_address,
                     u.id_image_path,
                     CONCAT(u.first_name,' ',COALESCE(u.middle_name,''),' ',u.last_name) AS full_name,
                     MAX(CASE WHEN a.attr_key='clearance_purpose'  THEN a.attr_value END) AS clearance_purpose,
                     MAX(CASE WHEN a.attr_key='residency_duration' THEN a.attr_value END) AS residency_duration,
                     MAX(CASE WHEN a.attr_key='residency_purpose'  THEN a.attr_value END) AS residency_purpose,
                     MAX(CASE WHEN a.attr_key='gmc_purpose'        THEN a.attr_value END) AS gmc_purpose,
                     MAX(CASE WHEN a.attr_key='nic_reason'         THEN a.attr_value END) AS nic_reason,
                     MAX(CASE WHEN a.attr_key='indigency_income'   THEN a.attr_value END) AS indigency_income,
                     MAX(CASE WHEN a.attr_key='indigency_reason'   THEN a.attr_value END) AS indigency_reason
              FROM DocumentRequest dr
              JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
              JOIN Users u ON dr.user_id = u.user_id
              LEFT JOIN DocumentRequestAttribute a ON dr.document_request_id = a.request_id
              WHERE dr.barangay_id = :bid
                AND dr.document_request_id = :id
                AND LOWER(dr.status) = 'pending'
              GROUP BY dr.document_request_id
          ");
          $stmt->execute([':bid'=>$bid, ':id'=>$reqId]);
          $result = $stmt->fetch();
          if ($result) {
              $response['success'] = true;
              $response['request'] = $result;
              logAuditTrail($pdo, $current_admin_id, 'VIEW', 'DocumentRequest', $reqId, 'Viewed document request details.');
          } else {
              $response['message'] = 'Record not found.';
          }

      }elseif ($action === 'ban_user') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $remarks = $_POST['remarks'] ?? '';
    
            // 1) Ban in DB
            $stmtBan = $pdo->prepare("
                UPDATE Users
                   SET is_active = 'no'
                 WHERE user_id     = :id
                   AND barangay_id = :bid
            ");
            if ($stmtBan->execute([':id'=>$reqId, ':bid'=>$bid])) {
    
                // 2) Fetch user email & name
                $u = $pdo->prepare("
                    SELECT email, CONCAT(first_name,' ',last_name) AS name
                      FROM Users
                     WHERE user_id = :id
                ");
                $u->execute([':id'=>$reqId]);
                $userInfo = $u->fetch();
    
                // 3) Send notification email
                if (!empty($userInfo['email'])) {
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'barangayhub2@gmail.com';
                        $mail->Password   = 'eisy hpjz rdnt bwrp';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        $mail->setFrom('noreply@barangayhub.com','Barangay Hub');
                        $mail->addAddress($userInfo['email'], $userInfo['name']);
                        $mail->Subject = 'Your account has been banned';
                        // properly terminate the string and remove the stray backslash
                        $mail->Body    = "Hello {$userInfo['name']},\n\nYour account has been suspended for the following reason: {$remarks}";
                        $mail->send();
                    
                      } catch (Exception $e) {
                        // optionally log the error:
                        error_log('Mailer Error: ' . $mail->ErrorInfo);
                    }
                }
    
         
                logAuditTrail(
                  $pdo, $current_admin_id,
                  'UPDATE','Users',$reqId,
                  'Banned user: '.$remarks
                );
                $response['success'] = true;
                $response['message'] = 'User banned and notified.';
            } else {
                $response['message'] = 'Unable to ban user.';
            }
        } else {
            $response['message'] = 'Invalid request method.';
        }
    }elseif ($action === 'send_email') {
          $stmt = $pdo->prepare("
              SELECT dr.document_request_id,
                     dt.document_name,
                     u.email,
                     CONCAT(u.first_name,' ',u.last_name) AS requester_name
              FROM DocumentRequest dr
              JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
              JOIN Users u ON dr.user_id = u.user_id
              WHERE dr.document_request_id = :id
                AND dr.barangay_id = :bid
          ");
          $stmt->execute([':id'=>$reqId, ':bid'=>$bid]);
          $info = $stmt->fetch();
          if ($info && !empty($info['email'])) {
              ob_start();
              $docRequestId = $reqId;
              require __DIR__ . '/../functions/document_template.php';
              $html = ob_get_clean();
              $dompdf = new Dompdf();
              $dompdf->loadHtml($html);
              $dompdf->setPaper('A4','portrait');
              $dompdf->render();
              $pdfOutput = $dompdf->output();
              $pdfName   = "document_request_{$reqId}.pdf";
              $mail = new PHPMailer(true);
              try {
                  $mail->isSMTP();
                  $mail->Host       = 'smtp.gmail.com';
                  $mail->SMTPAuth   = true;
                  $mail->Username   = 'barangayhub2@gmail.com';
                  $mail->Password   = 'eisy hpjz rdnt bwrp';
                  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                  $mail->Port       = 587;
                  $mail->setFrom('noreply@barangayhub.com','Barangay Hub');
                  $mail->addAddress($info['email'], $info['requester_name']);
                  $mail->Subject = 'Your Document Request: ' . $info['document_name'];
                  $mail->Body    = "Hello {$info['requester_name']},\n\nPlease find attached your requested document.";
                  $mail->addStringAttachment($pdfOutput, $pdfName, 'base64', 'application/pdf');
                  $mail->send();
                  $upd = $pdo->prepare("
                      UPDATE DocumentRequest
                      SET status = 'Complete'
                      WHERE document_request_id = :id AND barangay_id = :bid
                  ");
                  $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                  logAuditTrail($pdo, $current_admin_id, 'UPDATE','DocumentRequest',$reqId,'Sent PDF and marked complete.');
                  $response['success'] = true;
                  $response['message'] = 'Email sent & marked complete.';
              } catch (Exception $e) {
                  $response['message'] = 'Mailer error: '.$mail->ErrorInfo;
              }
          } else {
              $response['message'] = 'Request/email info not found.';
          }

      } elseif ($action === 'print') {
          ob_start();
          $docRequestId = $reqId;
          require __DIR__ . '/../functions/document_template.php';
          $html = ob_get_clean();
          $dompdf = new Dompdf();
          $dompdf->loadHtml($html);
          $dompdf->setPaper('A4','portrait');
          $dompdf->render();
          header('Content-Type: application/pdf');
          header("Content-Disposition: inline; filename=\"document_request_{$reqId}.pdf\"");
          echo $dompdf->output();
          exit;

      } elseif ($action === 'complete') {
          $stmt = $pdo->prepare("
              UPDATE DocumentRequest
              SET status = 'Complete'
              WHERE document_request_id = :id AND barangay_id = :bid
          ");
          if ($stmt->execute([':id'=>$reqId,':bid'=>$bid])) {
              logAuditTrail($pdo,$current_admin_id,'UPDATE','DocumentRequest',$reqId,'Marked complete manually.');
              $response['success'] = true;
              $response['message'] = 'Marked complete.';
          } else {
              $response['message'] = 'Unable to mark complete.';
          }

      } elseif ($action === 'delete') {
          $remarks = $_POST['remarks'] ?? '';
          $stmt = $pdo->prepare("
              SELECT dr.document_request_id,
                     dt.document_name,
                     u.email,
                     CONCAT(u.first_name,' ',u.last_name) AS requester_name
              FROM DocumentRequest dr
              JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
              JOIN Users u ON dr.user_id = u.user_id
              WHERE dr.document_request_id = :id
                AND dr.barangay_id = :bid
          ");
          $stmt->execute([':id'=>$reqId,':bid'=>$bid]);
          $requestInfo = $stmt->fetch();
          if (!$requestInfo) {
              $response['message'] = 'Request not found; cannot delete.';
              echo json_encode($response);
              exit;
          }
          if (!empty($requestInfo['email'])) {
              $mail = new PHPMailer(true);
              try {
                  $mail->isSMTP();
                  $mail->Host       = 'smtp.gmail.com';
                  $mail->SMTPAuth   = true;
                  $mail->Username   = 'barangayhub2@gmail.com';
                  $mail->Password   = 'eisy hpjz rdnt bwrp';
                  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                  $mail->Port       = 587;
                  $mail->setFrom('noreply@barangayhub.com','Barangay Hub');
                  $mail->addAddress($requestInfo['email'], $requestInfo['requester_name']);
                  $mail->Subject = 'Document Request Not Processed';
                  $mail->Body    = "Hello {$requestInfo['requester_name']},\n\nYour request for '{$requestInfo['document_name']}' has been declined.\n\nReason: {$remarks}";
                  $mail->send();
              } catch (Exception $e) {}
          }
          $stmtDel = $pdo->prepare("
              DELETE FROM DocumentRequest
              WHERE document_request_id = :id AND barangay_id = :bid
          ");
          if ($stmtDel->execute([':id'=>$reqId,':bid'=>$bid])) {
              logAuditTrail($pdo,$current_admin_id,'DELETE','DocumentRequest',$reqId,'Deleted request with remarks: '.$remarks);
              $response['success'] = true;
              $response['message'] = 'Request deleted.';
          } else {
              $response['message'] = 'Unable to delete request.';
          }

      } elseif ($action === 'get_requests') {
        $stmtPending = $pdo->prepare("
        SELECT dr.document_request_id,
               dr.request_date,
               dr.status,
               dr.delivery_method,
               dt.document_name,
               CONCAT(u.first_name,' ',u.last_name) AS requester_name
        FROM DocumentRequest dr
        JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
        JOIN Users u ON dr.user_id = u.user_id
        WHERE dr.barangay_id = :bid
          AND LOWER(dr.status) = 'pending'
        ORDER BY dr.request_date ASC
    ");
    $stmtPending->execute([':bid'=>$bid]);
    $pending = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

    $stmtCompleted = $pdo->prepare("
        SELECT dr.document_request_id,
               dr.request_date,
               dr.status,
               dr.delivery_method,
               dt.document_name,
               CONCAT(u.first_name,' ',u.last_name) AS requester_name
        FROM DocumentRequest dr
        JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
        JOIN Users u ON dr.user_id = u.user_id
        WHERE dr.barangay_id = :bid
          AND LOWER(dr.status) = 'complete'
        ORDER BY dr.request_date ASC
    ");
    $stmtCompleted->execute([':bid'=>$bid]);
    $completed = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['pending'=>$pending,'completed'=>$completed]);
    exit;
      } elseif ($action === 'create_payment') {
        // Get document type and fee
        $docTypeId = isset($_GET['document_type_id']) ? intval($_GET['document_type_id']) : 0;
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        
        if (!$docTypeId || !$userId) {
          $response['message'] = 'Missing required parameters';
          echo json_encode($response);
          exit;
        }
        
        // Get document fee
        $stmtFee = $pdo->prepare("
          SELECT dt.document_name, df.fee 
          FROM DocumentType dt
          JOIN DocumentFee df ON dt.document_name = df.document_type
          WHERE dt.document_type_id = ?
        ");
        $stmtFee->execute([$docTypeId]);
        $docInfo = $stmtFee->fetch();
        
        if (!$docInfo) {
          $response['message'] = 'Document type not found or fee not configured';
          echo json_encode($response);
          exit;
        }
        
        // Get active payment method
        $stmtMethod = $pdo->prepare("
          SELECT method, account_details 
          FROM BarangayPaymentMethod 
          WHERE barangay_id = ? AND is_active = 'yes'
        ");
        $stmtMethod->execute([$bid]);
        $paymentMethod = $stmtMethod->fetch();
        
        if (!$paymentMethod) {
          $response['message'] = 'No active payment method found';
          echo json_encode($response);
          exit;
        }
        
        if ($paymentMethod['method'] === 'PayMongo') {
          // Get site URL for success/cancel callbacks
          $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
          $successUrl = $siteUrl . "/payment_success.php?user_id={$userId}&doc_type={$docTypeId}";
          $cancelUrl = $siteUrl . "/payment_cancel.php";
          
          $result = createPaymongoPaymentLink(
            $docInfo['fee'], 
            "Payment for {$docInfo['document_name']}", 
            $paymentMethod['account_details'],
            $successUrl,
            $cancelUrl
          );
          
          if ($result['success']) {
            $response['success'] = true;
            $response['checkout_url'] = $result['checkout_url'];
            $response['message'] = 'Payment link created successfully';
          } else {
            $response['message'] = $result['message'];
          }
        } else {
          // For GCash, return the account number
          $response['success'] = true;
          $response['payment_method'] = 'GCash';
          $response['account_number'] = $paymentMethod['account_details'];
          $response['fee'] = $docInfo['fee'];
          $response['message'] = 'Please send payment to this GCash number';
        }
      }
  } catch (Exception $ex) {
      $response['message'] = 'Server Error: '.$ex->getMessage();
  }

  echo json_encode($response);
  exit;
}


// ----------------------------------------------------
// 3. Only include header + HTML if no specific action
// ----------------------------------------------------
require_once "../pages/header.php";

// EXAMPLE: Hard-coded barangay_id=1 to filter requests


// 1) Fetch all "Pending" doc requests (FIFO => earliest date first)
$stmt = $pdo->prepare("
  SELECT 
        dr.document_request_id,
        dr.request_date,
        dr.status,
        dr.delivery_method,
        dt.document_name,
        u.user_id,
        u.is_active,
        CONCAT(u.first_name, ' ', u.last_name) AS requester_name
    FROM DocumentRequest dr
    JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
    JOIN Users u          ON dr.user_id           = u.user_id
    WHERE dr.barangay_id   = :bid
      AND u.is_active     = 'yes'
      AND LOWER(dr.status)= 'pending'
    ORDER BY dr.request_date ASC
");
$stmt->execute([':bid' =>$bid]);
$docRequests = $stmt->fetchAll();

// 2) Fetch all "Complete" doc requests (History), also FIFO => earliest date first
$stmtHist = $pdo->prepare("
     SELECT 
        dr.document_request_id,
        dr.request_date,
        dr.status,
        dr.delivery_method,
        dt.document_name,
        CONCAT(u.first_name, ' ', u.last_name) AS requester_name
    FROM DocumentRequest dr
    JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
    JOIN Users u ON dr.user_id = u.user_id
    WHERE dr.barangay_id = :bid
      AND LOWER(dr.status) = 'complete'
    ORDER BY dr.request_date ASC
");
$stmtHist->execute([':bid'=>$bid]);
$completedRequests = $stmtHist->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Document Requests</title>
  <!-- Tailwind CSS -->
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
  <!-- SweetAlert2 -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    .payment-section {
      background-color: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 0.5rem;
      padding: 1.5rem;
      margin: 1.5rem 0;
    }
    
    .payment-method-card {
      background-color: white;
      border: 1px solid #e5e7eb;
      border-radius: 0.375rem;
      padding: 1rem;
      margin-bottom: 1rem;
      transition: all 0.2s;
    }
    
    .payment-method-card:hover {
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    
    .method-header {
      display: flex;
      align-items: center;
      margin-bottom: 0.75rem;
    }
    
    .method-header h4 {
      margin: 0 0 0.5rem 0;
      font-size: 1.125rem;
    }
    
    .method-badges {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 0.75rem;
    }
    
    .method-badge {
      padding: 0.25rem 0.5rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    
    .badge-active {
      background-color: #dcfce7;
      color: #166534;
    }
    
    .badge-inactive {
      background-color: #f3f4f6;
      color: #6b7280;
    }
    
    .badge-test {
      background-color: #dbeafe;
      color: #1e40af;
    }
    
    .badge-live {
      background-color: #fef9c3;
      color: #854d0e;
    }
  </style>
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
  <button id="showPaymentModalBtn" class="mb-6 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded">
    <i class="fas fa-credit-card mr-2"></i> Manage Payment Methods
  </button>
    <!-- Pending Requests Section -->
    <section id="docRequests" class="mb-10">
  <header class="mb-6">
    <h1 class="text-3xl font-bold text-blue-800">Pending Document Requests</h1>
  </header>

  <input type="text" id="pendingSearch" 
         class="p-2 border rounded mb-4" 
         placeholder="Search pending requests...">

  <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200" id="docRequestsTable">
        <thead class="bg-gray-50">
          <tr>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Requested By
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Document Type
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Request Date
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Delivery Method
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Status
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              Actions
            </th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <?php if (!empty($docRequests)): ?>
            <?php foreach ($docRequests as $req): ?>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['requester_name']) ?>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['document_name']) ?>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['request_date']) ?>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['delivery_method']) ?>
              </td>
              <td class="px-4 py-3 text-sm border-b">
                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded">
                  <?= htmlspecialchars($req['status']) ?>
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <div class="flex items-center space-x-2">
                  <button class="viewDocRequestBtn text-blue-600 hover:text-blue-900" data-id="<?= $req['document_request_id'] ?>">
                    View
                  </button>
                  <?php if (strtolower($req['delivery_method'])==='softcopy'): ?>
                  <button class="sendDocEmailBtn bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700" data-id="<?= $req['document_request_id'] ?>">
                    Send Email
                  </button>
                  <?php else: ?>
                  <button class="printDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                    Print
                  </button>
                  <button class="completeDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                    Complete
                  </button>
                  <?php endif; ?>
                  <button class="deleteDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                    Delete
                  </button>
                  <?php if ($req['is_active'] === 'yes'): ?>
                    <button
                      class="banUserBtn text-red-600 hover:text-red-900"
                      data-id="<?= $req['user_id'] ?>"
                    >
                      Ban User
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="px-4 py-4 text-center text-gray-500">No pending document requests found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

    <!-- Completed (History) Section -->
    <section id="docRequestsHistory">
  <header class="mb-6">
    <h1 class="text-3xl font-bold text-green-800">Document Requests History (Completed)</h1>
  </header>

  <input type="text" id="completedSearch" 
         class="p-2 border rounded mb-4" 
         placeholder="Search completed requests...">

  <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200" id="docRequestsHistoryTable">
        <thead class="bg-gray-50">
          <tr>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Requested By
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Document Type
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Request Date
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Delivery Method
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
              Status
            </th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <?php if (!empty($completedRequests)): ?>
            <?php foreach ($completedRequests as $req): ?>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['requester_name']) ?>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['document_name']) ?>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['request_date']) ?>
              </td>
              <td class="px-4 py-3 text-sm text-gray-900 border-b">
                <?= htmlspecialchars($req['delivery_method']) ?>
              </td>
              <td class="px-4 py-3 text-sm border-b">
                <span class="px-3 py-1 bg-green-100 text-green-800 rounded">
                  <?= htmlspecialchars($req['status']) ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="px-4 py-4 text-center text-gray-500">No completed document requests found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
  </div>

  <!-- Inline JavaScript to handle doc request actions -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Display any payment method messages
      <?php if (isset($_SESSION['payment_success'])): ?>
        Swal.fire({
          title: 'Success!',
          text: '<?= $_SESSION['payment_success'] ?>',
          icon: 'success'
        });
        <?php unset($_SESSION['payment_success']); ?>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['payment_error'])): ?>
        Swal.fire({
          title: 'Error',
          text: '<?= $_SESSION['payment_error'] ?>',
          icon: 'error'
        });
        <?php unset($_SESSION['payment_error']); ?>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['payment_warning'])): ?>
        Swal.fire({
          title: 'Warning',
          text: '<?= $_SESSION['payment_warning'] ?>',
          icon: 'warning'
        });
        <?php unset($_SESSION['payment_warning']); ?>
      <?php endif; ?>

      // ======================================
      // Searching & Sorting: common function
      // ======================================
      function tableSearch(inputElem, tableElem) {
        inputElem.addEventListener('keyup', function() {
          const term = this.value.toLowerCase();
          tableElem.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
          });
        });
      }

      // Sorting function
      function sortTableByColumn(table, columnIndex) {
        const tBody = table.querySelector('tbody');
        const rows = Array.from(tBody.querySelectorAll('tr'));
        // Check if we have a current sort direction
        const currentHeader = table.querySelectorAll('th')[columnIndex];
        const isAsc = currentHeader.getAttribute('data-sort-dir') === 'asc';
        // Toggle
        currentHeader.setAttribute('data-sort-dir', isAsc ? 'desc' : 'asc');

        // Remove from other THs
        table.querySelectorAll('th').forEach((th, idx) => {
          if (idx !== columnIndex) {
            th.removeAttribute('data-sort-dir');
          }
        });

        const sortedRows = rows.sort((a, b) => {
          const aVal = a.children[columnIndex].innerText.toLowerCase();
          const bVal = b.children[columnIndex].innerText.toLowerCase();

          if (aVal < bVal) return isAsc ? -1 : 1;
          if (aVal > bVal) return isAsc ? 1 : -1;
          return 0;
        });

        sortedRows.forEach(row => tBody.appendChild(row));
      }

      // Attach searching
      const pendingSearch = document.getElementById('pendingSearch');
      const docRequestsTable = document.getElementById('docRequestsTable');
      tableSearch(pendingSearch, docRequestsTable);

      const completedSearch = document.getElementById('completedSearch');
      const docRequestsHistoryTable = document.getElementById('docRequestsHistoryTable');
      tableSearch(completedSearch, docRequestsHistoryTable);

      // Attach sorting
      docRequestsTable.querySelectorAll('thead th.sortable').forEach((th, idx) => {
        th.addEventListener('click', () => {
          sortTableByColumn(docRequestsTable, idx);
        });
      });
      docRequestsHistoryTable.querySelectorAll('thead th.sortable').forEach((th, idx) => {
        th.addEventListener('click', () => {
          sortTableByColumn(docRequestsHistoryTable, idx);
        });
      });

      // Helper: show loading spinner (SweetAlert2)
      function showLoading() {
        Swal.fire({
          title: 'Please wait...',
          text: 'Processing your request.',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });
      }
      // Helper: hide loading spinner
      function hideLoading() {
        Swal.close();
      }

      // Helper function to do a GET and parse JSON
      function fetchJSON(url) {
        showLoading();
        return fetch(url)
          .then(resp => {
            if (!resp.ok) {
              hideLoading();
              throw new Error('Network response was not OK');
            }
            return resp.json();
          })
          .finally(() => hideLoading());
      }

      // (1) VIEW Document Request (show all user details + ID image)
     
      document.querySelectorAll('.printDocRequestBtn').forEach(btn => {
  btn.addEventListener('click', function() {
    const requestId = this.getAttribute('data-id');

    // open generated PDF inline in new tab
    window.open(`doc_request.php?action=print&id=${requestId}`, '_blank');
  });
});

      // (3) SEND EMAIL (Softcopy) => Confirm => Send => Auto-complete
      document.querySelectorAll('.sendDocEmailBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          let requestId = this.getAttribute('data-id');

          Swal.fire({
            title: 'Send Email?',
            text: 'Are you sure you want to send the requested document via email? This will automatically mark it as Complete.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, send it',
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            cancelButtonText: 'Cancel'
          }).then((result) => {
            if (result.isConfirmed) {
              // Proceed with sending
              fetchJSON(`doc_request.php?action=send_email&id=${requestId}`)
                .then(data => {
                  if (data.success) {
                    Swal.fire('Success', data.message, 'success').then(() => {
                      location.reload();
                    });
                  } else {
                    Swal.fire('Error', data.message, 'error');
                  }
                })
                .catch(error => {
                  Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
                });
            }
          });
        });
      });

      // (4) COMPLETE Document Request (Hardcopy)
      document.querySelectorAll('.completeDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          let requestId = this.getAttribute('data-id');
          Swal.fire({
            title: 'Mark as Complete?',
            text: 'This will mark the request as ready for pickup.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, complete it',
            cancelButtonText: 'Cancel'
          }).then((result) => {
            if (result.isConfirmed) {
              fetchJSON(`doc_request.php?action=complete&id=${requestId}`)
                .then(data => {
                  if (data.success) {
                    Swal.fire('Completed', data.message, 'success')
                      .then(() => location.reload());
                  } else {
                    Swal.fire('Error', data.message, 'error');
                  }
                })
                .catch(error => {
                  Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
                });
            }
          });
        });
      });

      // (5) DELETE Document Request => Ask for remarks => Email => Delete
      document.querySelectorAll('.deleteDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          let requestId = this.getAttribute('data-id');

          // Ask user for remarks
          Swal.fire({
            title: 'Delete Document Request?',
            text: 'Please provide remarks/reason for not processing this request. It will be emailed to the user.',
            icon: 'warning',
            input: 'textarea',
            inputPlaceholder: 'Enter your remarks here...',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Delete',
            cancelButtonText: 'Cancel',
            preConfirm: (remarks) => {
              if (!remarks) {
                Swal.showValidationMessage('Remarks are required to proceed.');
              }
              return remarks;
            }
          }).then((result) => {
            if (result.isConfirmed && result.value) {
              // Remarks from the input
              const userRemarks = result.value;
              showLoading();
              // We'll do a normal fetch with POST to pass remarks
              const formData = new FormData();
              formData.append('remarks', userRemarks);

              fetch(`doc_request.php?action=delete&id=${requestId}`, {
                method: 'POST',
                body: formData
              })
              .then(resp => {
                if (!resp.ok) {
                  hideLoading();
                  throw new Error('Network response was not OK');
                }
                return resp.json();
              })
              .then(data => {
                hideLoading();
                if (data.success) {
                  Swal.fire('Deleted', data.message, 'success')
                    .then(() => location.reload());
                } else {
                  Swal.fire('Error', data.message, 'error');
                }
              })
              .catch(error => {
                hideLoading();
                Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
              });
            }
          });
        });
      });
      
      // Function to create a payment link for a document
      window.createPaymentLink = function(docTypeId, userId) {
        showLoading();
        fetch(`doc_request.php?action=create_payment&document_type_id=${docTypeId}&user_id=${userId}`)
          .then(response => response.json())
          .then(data => {
            hideLoading();
            if (data.success) {
              if (data.checkout_url) {
                // PayMongo payment link
                window.open(data.checkout_url, '_blank');
              } else if (data.payment_method === 'GCash') {
                // GCash payment info
                Swal.fire({
                  title: 'GCash Payment',
                  html: `
                    <div class="text-left">
                      <p><strong>Send payment of ₱${data.fee} to:</strong></p>
                      <p class="text-lg font-bold text-blue-600 my-2">${data.account_number}</p>
                      <p>After sending payment, please upload a screenshot of the transaction.</p>
                    </div>
                  `,
                  icon: 'info'
                });
              }
            } else {
              Swal.fire('Error', data.message, 'error');
            }
          })
          .catch(error => {
            hideLoading();
            Swal.fire('Error', 'Failed to create payment link: ' + error.message, 'error');
          });
      };
    });
 // Toggle View Modal


    function toggleViewDocModal() {
  document.getElementById('viewDocModal').classList.toggle('hidden');
}document.addEventListener('DOMContentLoaded', () => {
  const paymentModal = document.getElementById('paymentModal');
  const showBtn   = document.getElementById('showPaymentModalBtn');
  const closeBtn  = document.getElementById('closePaymentModal');

  showBtn.addEventListener('click', () => {
    paymentModal.classList.remove('hidden');
  });
  closeBtn.addEventListener('click', () => {
    paymentModal.classList.add('hidden');
  });
  paymentModal.addEventListener('click', e => {
    if (e.target === paymentModal) {
      paymentModal.classList.add('hidden');
    }
  });
});

// Setup method selector to show right fields
document.getElementById('payment_method').addEventListener('change', function() {
  const method = this.value;
  const gcashFields = document.getElementById('gcashFields');
  const paymongoFields = document.getElementById('paymongoFields');
  const accountDetailsContainer = document.getElementById('accountDetailsContainer');
  
  if (method === 'GCash') {
    gcashFields.classList.remove('hidden');
    paymongoFields.classList.add('hidden');
    accountDetailsContainer.classList.remove('hidden');
    document.getElementById('account_details_label').textContent = 'GCash Number';
    document.getElementById('account_details').placeholder = 'Enter GCash number';
  } else {
    gcashFields.classList.add('hidden');
    paymongoFields.classList.remove('hidden');
    accountDetailsContainer.classList.add('hidden'); // Hide the original account_details field
  }
});

// Prefill the payment form when clicking Edit
function populatePaymentForm(account, method) {
  document.getElementById('payment_method').value = method;
  
  // Trigger the change event to update fields
  const event = new Event('change');
  document.getElementById('payment_method').dispatchEvent(event);
  
  if (method === 'GCash') {
    document.getElementById('account_details').value = account;
  } else if (method === 'PayMongo') {
    try {
      // Parse the JSON stored in account_details
      const paymongoConfig = JSON.parse(account);
      document.getElementById('paymongo_public_key').value = paymongoConfig.public_key || '';
      document.getElementById('paymongo_secret_key').value = paymongoConfig.secret_key || '';
    } catch (e) {
      // Handle legacy data or parsing errors
      console.error('Error parsing PayMongo config:', e);
      // Default to empty values if parsing fails
      document.getElementById('paymongo_public_key').value = '';
      document.getElementById('paymongo_secret_key').value = '';
    }
  }
  
  document.getElementById('paymentModal').classList.remove('hidden');
}

// Test PayMongo connection
function testPayMongoConnection() {
  const publicKey = document.getElementById('paymongo_public_key').value.trim();
  const secretKey = document.getElementById('paymongo_secret_key').value.trim();
  
  if (!publicKey || !secretKey) {
    Swal.fire({
      title: 'Missing Keys',
      text: 'Please enter both Public and Secret API keys to test the connection.',
      icon: 'warning'
    });
    return;
  }
  
  Swal.fire({
    title: 'Testing Connection',
    text: 'Connecting to PayMongo...',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });
  
  // Simulate a connection test - in production you would make an actual API call
  // For security reasons, we'll just validate the format here in the frontend
  const isValidPublicKey = /^pk_(test|live)_\w+$/.test(publicKey);
  const isValidSecretKey = /^sk_(test|live)_\w+$/.test(secretKey);
  
  // Check if environment matches (both test or both live)
  const publicEnv = publicKey.startsWith('pk_test_') ? 'test' : 'live';
  const secretEnv = secretKey.startsWith('sk_test_') ? 'test' : 'live';
  
  setTimeout(() => {
    if (!isValidPublicKey || !isValidSecretKey) {
      Swal.fire({
        title: 'Invalid Key Format',
        html: `
          <div class="text-left">
            ${!isValidPublicKey ? '<p>⚠️ Public key format is invalid.</p>' : ''}
            ${!isValidSecretKey ? '<p>⚠️ Secret key format is invalid.</p>' : ''}
            <p class="mt-2">Public keys should start with <code>pk_test_</code> or <code>pk_live_</code></p>
            <p>Secret keys should start with <code>sk_test_</code> or <code>sk_live_</code></p>
          </div>
        `,
        icon: 'error'
      });
    } else if (publicEnv !== secretEnv) {
      Swal.fire({
        title: 'Environment Mismatch',
        html: `
          <div class="text-left">
            <p>⚠️ You're mixing test and live environments.</p>
            <p class="mt-2">Your public key is for the <strong>${publicEnv}</strong> environment.</p>
            <p>Your secret key is for the <strong>${secretEnv}</strong> environment.</p>
            <p class="mt-2">Both keys must be from the same environment.</p>
          </div>
        `,
        icon: 'warning'
      });
    } else {
      Swal.fire({
        title: 'Keys Look Valid',
        html: `
          <div class="text-left">
            <p>✅ Your API keys are in the correct format!</p>
            <p class="mt-2">Environment: <strong>${publicEnv}</strong></p>
            <p class="mt-2"><strong>Note:</strong> Submit the form to save your keys and perform a full validation.</p>
          </div>
        `,
        icon: 'success'
      });
    }
  }, 1500);
}

document.querySelectorAll('.viewDocRequestBtn').forEach(btn => {
  btn.addEventListener('click', function() {
    const requestId = this.dataset.id;
    fetch(`doc_request.php?action=view_doc_request&id=${requestId}`)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const r = data.request;
          // Populate modal fields
          document.getElementById('viewReqName').textContent = r.full_name;
          document.getElementById('viewDocType').textContent = r.document_name;
          document.getElementById('viewReqDate').textContent = r.request_date;
          document.getElementById('viewStatus').textContent = r.status;
          document.getElementById('viewDelivery').textContent = r.delivery_method;
          document.getElementById('viewRemarks').textContent = r.request_remarks || 'N/A';
          document.getElementById('viewReqEmail').textContent = r.email || 'N/A';
          document.getElementById('viewReqContact').textContent = r.contact_number || 'N/A';
          document.getElementById('viewReqBirth').textContent = r.birth_date || 'N/A';
          document.getElementById('viewReqGender').textContent = r.gender || 'N/A';
          document.getElementById('viewReqMarital').textContent = r.marital_status || 'N/A';
          document.getElementById('viewEmergencyName').textContent = r.emergency_contact_name || 'N/A';
          document.getElementById('viewEmergencyContact').textContent = r.emergency_contact_number || 'N/A';
          document.getElementById('viewEmergencyAddress').textContent = r.emergency_contact_address || 'N/A';
          
          // Handle ID Image
          const idImageContainer = document.getElementById('viewIdImage');
        idImageContainer.innerHTML = '';                    // clear it first
        if (r.id_image_path) {
          const img = document.createElement('img');
          img.src = `../${r.id_image_path}`;                // <-- prepend "../"
          img.alt = 'ID Image';
          img.className = 'max-w-[300px] h-auto border rounded cursor-zoom-in';
          img.addEventListener('click', () => {
            Swal.fire({
              imageUrl: `../${r.id_image_path}`,
              imageAlt: 'ID Image',
              showConfirmButton: false,
              showCloseButton: true,
              background: 'transparent',
              backdrop: 'rgba(0,0,0,0.8)'
            });
          });
          idImageContainer.appendChild(img);
        } else {
          idImageContainer.textContent = 'No ID image available';
        }

        // 2) Proof of Payment
        const proofContainer = document.getElementById('viewProofImage');
        proofContainer.innerHTML = '';
        if (r.proof_image_path) {
          // Check if this is a PayMongo reference
          if (r.proof_image_path.startsWith('paymongo:')) {
            const paymongoRef = r.proof_image_path.substr(9); // Remove 'paymongo:' prefix
            proofContainer.innerHTML = `
              <div class="bg-blue-50 p-3 rounded-md">
                <p class="text-blue-700"><i class="fas fa-check-circle text-blue-500"></i> Payment completed via PayMongo</p>
                <p class="text-sm text-blue-600 mt-1">Reference: ${paymongoRef}</p>
              </div>
            `;
          } else {
            const img2 = document.createElement('img');
            img2.src = `../${r.proof_image_path}`;
            img2.alt = 'Proof of Payment';
            img2.className = 'max-w-[300px] h-auto border rounded cursor-zoom-in';
            img2.addEventListener('click', () => {
              Swal.fire({
                imageUrl: `../${r.proof_image_path}`,
                imageAlt: 'Proof of Payment',
                showConfirmButton: false,
                showCloseButton: true,
                background: 'transparent',
                backdrop: 'rgba(0,0,0,0.8)'
              });
            });
            proofContainer.appendChild(img2);
          }
        } else {
          proofContainer.textContent = 'No proof of payment available';
        }

          toggleViewDocModal();
        } else {
          Swal.fire('Error', data.message || 'Unable to load details.', 'error');
        }
      });
  });
});


document.querySelectorAll('.banUserBtn').forEach(btn => {
  btn.addEventListener('click', () => {
    const userId = btn.dataset.id;
    Swal.fire({
      title: 'Ban this user?',
      input: 'textarea',
      inputPlaceholder: 'Reason for ban...',
      showCancelButton: true,
      confirmButtonText: 'Ban',
      preConfirm: reason => {
        if (!reason) Swal.showValidationMessage('You need to provide a reason');
        return reason;
      }
    }).then(result => {
      if (result.isConfirmed) {
        Swal.showLoading();
        fetch(`doc_request.php?action=ban_user&id=${userId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `remarks=${encodeURIComponent(result.value)}`
        })
        .then(r => r.json())
        .then(data => {
          Swal.close();
          Swal.fire(
            data.success ? 'Banned!' : 'Error',
            data.message,
            data.success ? 'success' : 'error'
          ).then(() => data.success && location.reload());
        });
      }
    });
  });
});
  </script>
<!-- Payment Methods Modal -->
<div id="paymentModal"
     class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
  <div class="bg-white rounded-lg shadow-lg p-6 w-full max-w-md">
    <!-- Header -->
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-xl font-semibold">Manage Payment Methods</h2>
      <button id="closePaymentModal" class="text-gray-400 hover:text-gray-600">&times;</button>
    </div>

    <!-- Form -->
    <form action="doc_request.php" method="POST" class="space-y-4 mb-6">
      <!-- Read-only Barangay -->
      <div>
        <label class="block text-sm font-medium text-gray-700">Barangay</label>
        <p class="mt-1 text-gray-900"><?= htmlspecialchars($barangayName) ?></p>
        <input type="hidden" name="barangay_id" value="<?= $bid ?>">
      </div>

      <!-- Payment Method Selector -->
      <div>
        <label for="payment_method" class="block text-sm font-medium text-gray-700">Payment Method</label>
        <select 
          name="payment_method" 
          id="payment_method"
          class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500"
        >
          <option value="GCash">GCash</option>
          <option value="PayMongo">PayMongo</option>
        </select>
      </div>

      <!-- GCash Number / PayMongo API Key -->
      <div id="accountDetailsContainer">
        <label for="account_details" id="account_details_label" class="block text-sm font-medium text-gray-700">GCash Number</label>
        <input
          type="text"
          name="account_details"
          id="account_details"
          required
          placeholder="Enter GCash number"
          class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500"
        >
      </div>

      <!-- PayMongo Fields - Will show/hide based on selection -->
      <div id="paymongoFields" class="hidden space-y-3">
        <div>
          <label for="paymongo_public_key" class="block text-sm font-medium text-gray-700">Public Key</label>
          <input
            type="text"
            name="paymongo_public_key"
            id="paymongo_public_key"
            placeholder="pk_test_xxxxxxxxxxxx"
            class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500"
          >
        </div>
        <div>
          <label for="paymongo_secret_key" class="block text-sm font-medium text-gray-700">Secret Key</label>
          <input
            type="password"
            name="paymongo_secret_key"
            id="paymongo_secret_key"
            placeholder="sk_test_xxxxxxxxxxxx"
            class="mt-1 block w-full border border-gray-300 rounded-md p-2 focus:ring-blue-500 focus:border-blue-500"
          >
        </div>
        
        <button 
          type="button" 
          onclick="testPayMongoConnection()"
          class="inline-block px-4 py-2 bg-blue-100 text-blue-700 rounded-md text-sm hover:bg-blue-200 transition-colors"
        >
          Test Connection
        </button>
        
        <div class="bg-blue-50 p-3 rounded-md">
          <h4 class="text-sm font-medium text-blue-800">How to set up PayMongo:</h4>
          <ol class="mt-2 text-xs text-blue-700 list-decimal list-inside space-y-1">
            <li>Sign up for a <a href="https://dashboard.paymongo.com/signup" target="_blank" class="underline">PayMongo account</a></li>
            <li>Go to Developers > API Keys in your PayMongo dashboard</li>
            <li>Copy your Public and Secret keys</li>
            <li>Paste them in the fields above</li>
          </ol>
          <p class="mt-2 text-xs text-blue-600">You can start with test keys (prefixed with pk_test_ and sk_test_) for testing. Switch to live keys when ready for real transactions.</p>
        </div>
      </div>

      <!-- GCash Fields -->
      <div id="gcashFields">
        <p class="text-sm text-gray-600">Enter the GCash number where users should send payments.</p>
      </div>

      <button type="submit"
              class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-md">
        Save Payment Method
      </button>
    </form>

    <hr class="my-4">

    <!-- Existing Payment Methods -->
    <h3 class="font-medium text-gray-800 mb-2">Active Payment Methods</h3>
    <?php if (!empty($payments)): ?>
      <?php foreach ($payments as $pm): ?>
      <div class="flex items-center justify-between mb-2 last:mb-0">
        <div>
          <span class="font-medium"><?= htmlspecialchars($pm['method']) ?>:</span>
          <?php if ($pm['method'] === 'PayMongo'): ?>
            <?php 
              $configDetails = json_decode($pm['account_details'], true);
              $environment = $configDetails['environment'] ?? '';
              $envLabel = $environment ? " ({$environment} mode)" : '';
            ?>
            <span class="ml-2">API Keys Configured<?= $envLabel ?></span>
          <?php else: ?>
            <span class="ml-2"><?= htmlspecialchars($pm['account_details']) ?></span>
          <?php endif; ?>
          <span class="ml-2 inline-block px-2 py-0.5 text-sm <?= $pm['is_active']==='yes' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?> rounded">
            <?= ucfirst($pm['is_active']) ?>
          </span>
        </div>
        <div class="flex gap-2">
          <button
            type="button"
            onclick="populatePaymentForm('<?= htmlspecialchars(str_replace("'", "\\'", $pm['account_details'])) ?>', '<?= htmlspecialchars($pm['method']) ?>')"
            class="bg-gray-500 hover:bg-gray-600 text-white py-1 px-3 rounded-md"
          >
            Edit
          </button>
          <form action="doc_request.php" method="POST" class="inline">
            <?php if ($pm['is_active'] === 'yes'): ?>
              <input type="hidden" name="deactivate_payment_method_id" value="<?= $pm['payment_method_id'] ?>">
              <button type="submit" class="bg-red-500 hover:bg-red-600 text-white py-1 px-3 rounded-md">
                Deactivate
              </button>
            <?php else: ?>
              <input type="hidden" name="activate_payment_method_id" value="<?= $pm['payment_method_id'] ?>">
              <button type="submit" class="bg-green-500 hover:bg-green-600 text-white py-1 px-3 rounded-md">
                Activate
              </button>
            <?php endif; ?>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="text-gray-500 italic">No payment methods configured yet.</p>
    <?php endif; ?>
  </div>
</div>


  <!-- View Document Request Modal -->
<div id="viewDocModal" tabindex="-1" 
     class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
  <div class="relative w-full max-w-2xl max-h-full mx-auto">
    <div class="relative bg-white rounded-lg shadow">
      <!-- Header -->
      <div class="flex items-start justify-between p-5 border-b rounded-t">
        <h3 class="text-xl font-semibold text-gray-900">Document Request Details</h3>
        <button type="button" onclick="toggleViewDocModal()"
                class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center">
          <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
          </svg>
        </button>
      </div>
      <!-- Body -->
      <div class="p-6 space-y-4 overflow-y-auto max-h-[calc(100%-6rem)] text-sm text-gray-800">
        <div class="grid grid-cols-2 gap-4">
          <div><strong>Requested By:</strong> <span id="viewReqName">—</span></div>
          <div><strong>Document Type:</strong> <span id="viewDocType">—</span></div>
          <div><strong>Request Date:</strong> <span id="viewReqDate">—</span></div>
          <div><strong>Status:</strong> <span id="viewStatus">—</span></div>
          <div><strong>Delivery Method:</strong> <span id="viewDelivery">—</span></div>
          <div><strong>Remarks:</strong> <span id="viewRemarks">—</span></div>
        </div>
        
        <h4 class="text-lg font-medium pt-4 border-t">Requester Information</h4>
        <div class="grid grid-cols-2 gap-4">
          <div><strong>Email:</strong> <span id="viewReqEmail">—</span></div>
          <div><strong>Contact #:</strong> <span id="viewReqContact">—</span></div>
          <div><strong>Birth Date:</strong> <span id="viewReqBirth">—</span></div>
          <div><strong>Gender:</strong> <span id="viewReqGender">—</span></div>
          <div><strong>Marital Status:</strong> <span id="viewReqMarital">—</span></div>
        </div>

        <h4 class="text-lg font-medium pt-4 border-t">Emergency Contact</h4>
        <div class="grid grid-cols-2 gap-4">
          <div><strong>Name:</strong> <span id="viewEmergencyName">—</span></div>
          <div><strong>Contact #:</strong> <span id="viewEmergencyContact">—</span></div>
          <div><strong>Address:</strong> <span id="viewEmergencyAddress">—</span></div>
        </div>

        <h4 class="text-lg font-medium pt-4 border-t">ID Image</h4>
        <div id="viewIdImage" class="mt-2"></div>

        <h4 class="text-lg font-medium pt-4 border-t">Proof of Payment</h4>
        <div id="viewProofImage" class="mt-2">—</div>
      </div>
      <!-- Footer -->
      <div class="flex items-center justify-end p-5 border-t border-gray-200">
        <button type="button" onclick="toggleViewDocModal()"
                class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 border border-gray-200">
          Close
        </button>
      </div>
    </div>
  </div>
</div>
</body>
</html>