<?php
session_start();
require "../vendor/autoload.php";
require "../config/dbconn.php";

use Dompdf\Dompdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception; 

// Make sure the user is actually logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

// Safe to read these now
$current_admin_id = $_SESSION['user_id'];
$bid              = $_SESSION['barangay_id'] ?? 1; // Use admin's actual barangay
$role             = $_SESSION['role_id'];

/**
 * Helper function to insert into AuditTrail
 */
function logAuditTrail($pdo, $adminId, $action, $tableName, $recordId, $description) {
    $stmtAudit = $pdo->prepare("
        INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
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

// Handle form submission for price updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prices'])) {
    try {
        foreach ($_POST['prices'] as $doc_type_id => $price) {
            // Check if entry exists
            $stmt = $pdo->prepare("SELECT id FROM barangay_document_prices WHERE barangay_id = ? AND document_type_id = ?");
            $stmt->execute([$bid, $doc_type_id]);
            if ($stmt->fetch()) {
                // Update
                $update = $pdo->prepare("UPDATE barangay_document_prices SET price = ? WHERE barangay_id = ? AND document_type_id = ?");
                $update->execute([$price, $bid, $doc_type_id]);
            } else {
                // Insert
                $insert = $pdo->prepare("INSERT INTO barangay_document_prices (barangay_id, document_type_id, price) VALUES (?, ?, ?)");
                $insert->execute([$bid, $doc_type_id, $price]);
            }
        }
        
        logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'barangay_document_prices', $bid, 'Updated document prices');
        
        echo json_encode(['success' => true, 'message' => 'Prices updated successfully!']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating prices: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action   = $_GET['action'];
    $reqId    = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $response = ['success' => false, 'message' => ''];

    try {
        if ($action === 'get_document_prices') {
            // Get all document types
            $docs = $pdo->query("SELECT id, name, default_fee FROM document_types WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);

            // Get current prices for this barangay
            $prices = [];
            $stmt = $pdo->prepare("SELECT document_type_id, price FROM barangay_document_prices WHERE barangay_id = ?");
            $stmt->execute([$bid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $prices[$row['document_type_id']] = $row['price'];
            }

            echo json_encode(['success' => true, 'documents' => $docs, 'prices' => $prices]);
            exit;

        } elseif ($action === 'view_doc_request') {
            // Updated query to work with new structure
            $stmt = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id, 
                    dr.request_date,
                    dr.status,
                    dr.proof_image_path,
                    dr.remarks AS request_remarks,
                    dr.price,
                    dr.purpose,
                    dr.business_name,
                    dr.business_location,
                    dr.business_nature,
                    dr.business_type,
                    dt.name AS document_name,
                    dt.code AS document_code,
                    -- Person information
                    p.id AS person_id,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS full_name,
                    p.contact_number,
                    p.birth_date,
                    p.gender,
                    p.civil_status,
                    -- Address information
                    a.house_no,
                    a.street,
                    a.subdivision,
                    b.name AS barangay_name,
                    -- Emergency contact
                    ec.contact_name AS emergency_contact_name,
                    ec.contact_number AS emergency_contact_number,
                    ec.contact_address AS emergency_contact_address,
                    -- User information (if linked)
                    u.email,
                    u.id AS user_id
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON dr.user_id = u.id
                LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
                LEFT JOIN barangay b ON dr.barangay_id = b.id
                LEFT JOIN emergency_contacts ec ON p.id = ec.person_id
                WHERE dr.barangay_id = :bid
                  AND dr.id = :id
                  AND LOWER(dr.status) = 'pending'
            ");
            $stmt->execute([':bid'=>$bid, ':id'=>$reqId]);
            $result = $stmt->fetch();
            
            if ($result) {
                $response['success'] = true;
                $response['request'] = $result;
                logAuditTrail($pdo, $current_admin_id, 'VIEW', 'document_requests', $reqId, 'Viewed document request details.');
            } else {
                $response['message'] = 'Record not found.';
            }

        } elseif ($action === 'get_requests') {
            // Get pending requests - Updated query
            $stmtPending = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id,
                    dr.request_date,
                    dr.status,
                    dr.price,
                    dr.proof_image_path,
                    dr.purpose,
                    dr.business_name,
                    dt.name AS document_name,
                    dt.code AS document_code,
                    CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name,
                    p.contact_number,
                    p.birth_date,
                    p.gender,
                    p.civil_status,
                    COALESCE(u.id, 0) AS user_id,
                    COALESCE(u.is_active, TRUE) AS is_active
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON dr.user_id = u.id
                WHERE dr.barangay_id = :bid
                  AND LOWER(dr.status) = 'pending'
                  AND (u.is_active IS NULL OR u.is_active = TRUE)
                ORDER BY dr.request_date ASC
            ");
            $stmtPending->execute([':bid'=>$bid]);
            $pending = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

            // Get completed requests
            $stmtCompleted = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id,
                    dr.request_date,
                    dr.status,
                    dr.completed_at,
                    dt.name AS document_name,
                    CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                WHERE dr.barangay_id = :bid
                  AND LOWER(dr.status) IN ('completed', 'complete')
                ORDER BY dr.request_date DESC
            ");
            $stmtCompleted->execute([':bid'=>$bid]);
            $completed = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['pending'=>$pending,'completed'=>$completed]);
            exit;

        } elseif ($action === 'ban_user') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $remarks = $_POST['remarks'] ?? '';
        
                // Get the user ID from the document request
                $getUserStmt = $pdo->prepare("
                    SELECT u.id, u.email, CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS name
                    FROM document_requests dr
                    JOIN users u ON dr.user_id = u.id
                    JOIN persons p ON dr.person_id = p.id
                    WHERE dr.id = :id
                    LIMIT 1
                ");
                $getUserStmt->execute([':id'=>$reqId]);
                $userInfo = $getUserStmt->fetch();
                
                if ($userInfo) {
                    $stmtBan = $pdo->prepare("UPDATE users SET is_active = FALSE WHERE id = :id");
                    
                    if ($stmtBan->execute([':id'=>$userInfo['id']])) {
                        // Send notification email
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
                                $mail->Subject = 'Your account has been suspended';
                                $mail->Body    = "Hello {$userInfo['name']},\n\nYour account has been suspended for the following reason: {$remarks}";
                                $mail->send();
                            } catch (Exception $e) {
                                error_log('Mailer Error: ' . $mail->ErrorInfo);
                            }
                        }
             
                        logAuditTrail(
                          $pdo, $current_admin_id,
                          'UPDATE','users',$userInfo['id'],
                          'Banned user: '.$remarks
                        );
                        $response['success'] = true;
                        $response['message'] = 'User banned and notified.';
                    } else {
                        $response['message'] = 'Unable to ban user.';
                    }
                } else {
                    $response['message'] = 'User not found.';
                }
            } else {
                $response['message'] = 'Invalid request method.';
            }

        } elseif ($action === 'complete') {
            $stmt = $pdo->prepare("
                UPDATE document_requests
                SET status = 'completed', completed_at = NOW()
                WHERE id = :id AND barangay_id = :bid
            ");
            if ($stmt->execute([':id'=>$reqId,':bid'=>$bid])) {
                logAuditTrail($pdo,$current_admin_id,'UPDATE','document_requests',$reqId,'Marked complete manually.');
                $response['success'] = true;
                $response['message'] = 'Marked complete.';
            } else {
                $response['message'] = 'Unable to mark complete.';
            }

        } elseif ($action === 'delete') {
            $remarks = $_POST['remarks'] ?? '';
            
            // Get request info first
            $stmt = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id,
                    dt.name AS document_name,
                    CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name,
                    u.email
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON dr.user_id = u.id
                WHERE dr.id = :id
                  AND dr.barangay_id = :bid
            ");
            $stmt->execute([':id'=>$reqId,':bid'=>$bid]);
            $requestInfo = $stmt->fetch();
            
            if (!$requestInfo) {
                $response['message'] = 'Request not found; cannot delete.';
                echo json_encode($response);
                exit;
            }
            
            // Send notification email if user has email
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
                    $mail->setFrom('noreply@barangayhub.com','iBarangay');
                    $mail->addAddress($requestInfo['email'], $requestInfo['requester_name']);
                    $mail->Subject = 'Document Request Not Processed';
                    $mail->Body    = "Hello {$requestInfo['requester_name']},\n\nYour request for '{$requestInfo['document_name']}' has been declined.\n\nReason: {$remarks}";
                    $mail->send();
                } catch (Exception $e) {
                    error_log('Email send failed: ' . $e->getMessage());
                }
            }
            
            // Delete the request
            $stmtDel = $pdo->prepare("
                DELETE FROM document_requests
                WHERE id = :id AND barangay_id = :bid
            ");
            if ($stmtDel->execute([':id'=>$reqId,':bid'=>$bid])) {
                logAuditTrail($pdo,$current_admin_id,'DELETE','document_requests',$reqId,'Deleted request with remarks: '.$remarks);
                $response['success'] = true;
                $response['message'] = 'Request deleted.';
            } else {
                $response['message'] = 'Unable to delete request.';
            }

        } elseif ($action === 'print') {
            // Check if document is a cedula
            $stmt = $pdo->prepare("
                SELECT dt.code as document_code
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                WHERE dr.id = :id AND dr.barangay_id = :bid
            ");
            $stmt->execute([':id' => $reqId, ':bid' => $bid]);
            $docType = $stmt->fetch();
            
            if ($docType && in_array($docType['document_code'], ['cedula', 'community_tax_certificate'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Community Tax Certificate (Cedula) must be obtained in person at the Barangay Hall.'
                ]);
                exit;
            }
            
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

        } elseif ($action === 'send_email') {
            // Get request info
            $stmt = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id,
                    dt.name AS document_name,
                    dt.code AS document_code,
                    CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name,
                    u.email
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON dr.user_id = u.id
                WHERE dr.id = :id
                  AND dr.barangay_id = :bid
            ");
            $stmt->execute([':id'=>$reqId, ':bid'=>$bid]);
            $info = $stmt->fetch();
            
            if ($info && !empty($info['email'])) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'barangayhub2@gmail.com';
                    $mail->Password   = 'eisy hpjz rdnt bwrp';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->setFrom('noreply@barangayhub.com','iBarangay');
                    $mail->addAddress($info['email'], $info['requester_name']);

                    // Check if document is a cedula
                    if (in_array($info['document_code'], ['cedula', 'community_tax_certificate'])) {
                        $mail->Subject = 'Community Tax Certificate (Cedula) Ready for Pickup';
                        $mail->Body    = "Hello {$info['requester_name']},\n\n"
                                     . "Your Community Tax Certificate (Cedula) request has been processed and is ready for pickup at the Barangay Hall.\n\n"
                                     . "Please note that Cedula must be obtained in person. Bring a valid ID when claiming your document.\n\n"
                                     . "Office Hours: Monday to Friday, 8:00 AM to 5:00 PM\n\n"
                                     . "Thank you for your understanding.";
                    } else {
                        // Generate PDF for non-cedula documents
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
                        
                        $mail->Subject = 'Your Document Request: ' . $info['document_name'];
                        $mail->Body    = "Hello {$info['requester_name']},\n\nPlease find attached your requested document.";
                        $mail->addStringAttachment($pdfOutput, $pdfName, 'base64', 'application/pdf');
                    }
                    
                    $mail->send();
                    
                    // Update status
                    $upd = $pdo->prepare("
                        UPDATE document_requests
                        SET status = 'completed', completed_at = NOW()
                        WHERE id = :id AND barangay_id = :bid
                    ");
                    $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                    
                    logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,
                        in_array($info['document_code'], ['cedula', 'community_tax_certificate']) 
                            ? 'Sent pickup notification email for cedula.'
                            : 'Sent PDF and marked complete.'
                    );
                    
                    $response['success'] = true;
                    $response['message'] = in_array($info['document_code'], ['cedula', 'community_tax_certificate'])
                        ? 'Pickup notification email sent & marked complete.'
                        : 'Email sent & marked complete.';
                } catch (Exception $e) {
                    $response['message'] = 'Mailer error: '.$mail->ErrorInfo;
                }
            } else {
                $response['message'] = 'Request/email info not found.';
            }
        }
        
    } catch (Exception $ex) {
        $response['message'] = 'Server Error: '.$ex->getMessage();
    }

    echo json_encode($response);
    exit;
}

// Only include header + HTML if no specific action
require_once "../components/header.php";

// 1) Fetch all "Pending" doc requests (FIFO => earliest date first) - Updated query
$stmt = $pdo->prepare("
    SELECT 
        dr.id AS document_request_id,
        dr.request_date,
        dr.status,
        dr.proof_image_path,
        dr.price,
        dr.purpose,
        dr.business_name,
        dt.name AS document_name,
        dt.code AS document_code,
        CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name,
        p.contact_number,
        p.birth_date,
        p.gender,
        p.civil_status,
        b.name AS barangay_name,
        COALESCE(u.id, 0) AS user_id,
        COALESCE(u.is_active, TRUE) AS is_active
    FROM document_requests dr
    JOIN document_types dt ON dr.document_type_id = dt.id
    JOIN persons p ON dr.person_id = p.id
    LEFT JOIN users u ON dr.user_id = u.id
    JOIN barangay b ON dr.barangay_id = b.id
    WHERE dr.barangay_id = :bid
      AND LOWER(dr.status) = 'pending'
      AND (u.is_active IS NULL OR u.is_active = TRUE)
    ORDER BY dr.request_date ASC
");
$stmt->execute([':bid' => $bid]);
$docRequests = $stmt->fetchAll();

// 2) Fetch all "Complete" doc requests (History)
$stmtHist = $pdo->prepare("
    SELECT 
        dr.id AS document_request_id,
        dr.request_date,
        dr.status,
        dr.completed_at,
        dt.name AS document_name,
        CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name
    FROM document_requests dr
    JOIN document_types dt ON dr.document_type_id = dt.id
    JOIN persons p ON dr.person_id = p.id
    WHERE dr.barangay_id = :bid
      AND LOWER(dr.status) IN ('completed', 'complete')
    ORDER BY dr.request_date DESC
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
    .downloadPhotoBtn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem;
        border-radius: 0.375rem;
        transition: all 0.2s;
    }

    .downloadPhotoBtn:hover {
        background-color: #f0fdf4;
    }

    .downloadPhotoBtn i {
        font-size: 0.875rem;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem;
        border-radius: 0.375rem;
        transition: all 0.2s;
    }

    .action-btn:hover {
        background-color: #f3f4f6;
    }

    .action-btn i {
        font-size: 0.875rem;
    }

    /* Tabs Navigation Styles */
    .tabs-navigation {
      display: flex;
      gap: 1rem;
      border-bottom: 2px solid #e5e7eb;
      margin-bottom: 2rem;
    }

    .tab-button {
      padding: 0.75rem 1.5rem;
      font-weight: 500;
      color: #6b7280;
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .tab-button:hover {
      color: #1e40af;
    }

    .tab-button.active {
      color: #1e40af;
      border-bottom-color: #1e40af;
    }

    /* Tab Content Styles */
    .tab-content {
      display: none;
    }

    .tab-content.active {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

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
    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }

    .restriction-warning {
      background: #fef3c7;
      border: 1px solid #f59e0b;
      color: #92400e;
      padding: 0.75rem;
      border-radius: 0.375rem;
      margin: 0.5rem 0;
      font-size: 0.875rem;
    }
  </style>
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    <!-- Header with Manage Prices Button -->
    <div class="flex justify-between items-center mb-6">
      <div>
        <h1 class="text-3xl font-bold text-blue-800">Document Request Management</h1>
        <p class="text-gray-600 mt-2">Manage document requests and pricing</p>
      </div>
      <button id="managePricesBtn" class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 transition-colors flex items-center">
        <i class="fas fa-dollar-sign mr-2"></i>
        Manage Document Prices
      </button>
    </div>

    <!-- Tabs Navigation -->
    <div class="tabs-navigation mb-4">
      <button class="tab-button active" data-tab="pending">Pending Requests</button>
      <button class="tab-button" data-tab="completed">Completed Requests</button>
    </div>

    <!-- Pending Requests Tab -->
    <section id="pending" class="tab-content active">
      <header class="mb-6">
        <h1 class="text-3xl font-bold text-blue-800">Pending Document Requests</h1>
        <p class="text-gray-600 mt-2">Total pending requests: <span class="font-semibold"><?= count($docRequests) ?></span></p>
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
                  Requester Name
                </th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
                  Document Type
                </th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
                  Contact Number
                </th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
                  Request Date
                </th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">
                  Price
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
                    <?php if ($req['document_code'] === 'first_time_job_seeker'): ?>
                      <div class="restriction-warning">
                        <i class="fas fa-info-circle"></i> First Time Job Seeker - One time only
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    <?= htmlspecialchars($req['document_name']) ?>
                    <?php if (!empty($req['purpose'])): ?>
                      <br><small class="text-gray-600">Purpose: <?= htmlspecialchars($req['purpose']) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($req['business_name'])): ?>
                      <br><small class="text-blue-600">Business: <?= htmlspecialchars($req['business_name']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    <?= htmlspecialchars($req['contact_number'] ?? 'N/A') ?>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    <?= date('M d, Y h:i A', strtotime($req['request_date'])) ?>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    ₱<?= number_format($req['price'] ?? 0, 2) ?>
                  </td>
                  <td class="px-4 py-3 text-sm border-b">
                    <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded">
                      <?= ucfirst(htmlspecialchars($req['status'])) ?>
                    </span>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    <div class="flex items-center space-x-2">
                      <button class="viewDocRequestBtn text-blue-600 hover:text-blue-900" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-eye"></i> View
                      </button>
                      <?php if ($req['document_code'] === 'barangay_indigency' && $req['proof_image_path']): ?>
                      <a href="../<?= $req['proof_image_path'] ?>" download class="downloadPhotoBtn text-green-600 hover:text-green-900">
                        <i class="fas fa-download"></i> Photo
                      </a>
                      <?php endif; ?>
                      <?php if (!in_array($req['document_code'], ['cedula', 'community_tax_certificate'])): ?>
                      <button class="printDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-print"></i> Print
                      </button>
                      <?php endif; ?>
                      <button class="sendDocEmailBtn p-2 text-green-600 hover:text-green-900 rounded-lg hover:bg-green-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-envelope"></i> Email
                      </button>
                      <button class="completeDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-check"></i> Complete
                      </button>
                      <button class="deleteDocRequestBtn p-2 text-red-600 hover:text-red-900 rounded-lg hover:bg-red-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="7" class="px-4 py-4 text-center text-gray-500">No pending document requests found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Completed Requests Tab -->
    <section id="completed" class="tab-content">
      <header class="mb-6">
        <h1 class="text-3xl font-bold text-green-800">Document Requests History</h1>
        <p class="text-gray-600 mt-2">Total completed requests: <span class="font-semibold"><?= count($completedRequests) ?></span></p>
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
                  Completed Date
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
                    <?= date('M d, Y h:i A', strtotime($req['request_date'])) ?>
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    <?= $req['completed_at'] ? date('M d, Y h:i A', strtotime($req['completed_at'])) : 'N/A' ?>
                  </td>
                  <td class="px-4 py-3 text-sm border-b">
                    <span class="px-3 py-1 bg-green-100 text-green-800 rounded">
                      <?= ucfirst(htmlspecialchars($req['status'])) ?>
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

  <!-- Document Prices Modal -->
  <div id="pricesModal" class="hidden" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background-color: rgba(0, 0, 0, 0.5); overflow-y: auto;">
    <div class="flex items-center justify-center min-h-screen px-4 py-8">
      <div class="relative w-full max-w-4xl bg-white rounded-lg shadow-xl">
        <div class="p-6">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Manage Document Prices</h3>
            <button id="closePricesModal" class="text-gray-400 hover:text-gray-600">
              <i class="fas fa-times text-xl"></i>
            </button>
          </div>
          
          <form id="pricesForm">
            <div class="max-h-96 overflow-y-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0">
                  <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Default Price</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Custom Price</th>
                  </tr>
                </thead>
                <tbody id="pricesTableBody" class="bg-white divide-y divide-gray-200">
                  <!-- Table content will be populated by JavaScript -->
                </tbody>
              </table>
            </div>
            
            <div class="flex justify-end mt-6 space-x-3">
              <button type="button" id="cancelPricesBtn" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400 transition-colors">
                Cancel
              </button>
              <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition-colors flex items-center">
                <i class="fas fa-save mr-2"></i>
                Save Changes
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Tabs functionality
      const tabButtons = document.querySelectorAll('.tab-button');
      const tabContents = document.querySelectorAll('.tab-content');

      function switchTab(tabId) {
        tabButtons.forEach(button => {
          button.classList.toggle('active', button.dataset.tab === tabId);
        });

        tabContents.forEach(content => {
          if (content.id === tabId) {
            content.classList.add('active');
          } else {
            content.classList.remove('active');
          }
        });
      }

      tabButtons.forEach(button => {
        button.addEventListener('click', () => {
          switchTab(button.dataset.tab);
        });
      });

      // Search functionality
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
        const currentHeader = table.querySelectorAll('th')[columnIndex];
        const isAsc = currentHeader.getAttribute('data-sort-dir') === 'asc';
        currentHeader.setAttribute('data-sort-dir', isAsc ? 'desc' : 'asc');

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

      // Initialize search and sort
      const pendingSearch = document.getElementById('pendingSearch');
      const docRequestsTable = document.getElementById('docRequestsTable');
      tableSearch(pendingSearch, docRequestsTable);

      const completedSearch = document.getElementById('completedSearch');
      const docRequestsHistoryTable = document.getElementById('docRequestsHistoryTable');
      tableSearch(completedSearch, docRequestsHistoryTable);

      // Add sorting to tables
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

      // Document Prices Modal functionality
      const pricesModal = document.getElementById('pricesModal');
      const managePricesBtn = document.getElementById('managePricesBtn');
      const closePricesModal = document.getElementById('closePricesModal');
      const cancelPricesBtn = document.getElementById('cancelPricesBtn');
      const pricesForm = document.getElementById('pricesForm');
      const pricesTableBody = document.getElementById('pricesTableBody');

      // Open prices modal
      managePricesBtn.addEventListener('click', function() {
        fetch('?action=get_document_prices')
          .then(response => response.json())
          .then(data => {
            if (data.success) {
              populatePricesTable(data.documents, data.prices);
              pricesModal.classList.remove('hidden');
            } else {
              Swal.fire('Error', 'Failed to load document prices', 'error');
            }
          })
          .catch(error => {
            Swal.fire('Error', 'An error occurred: ' + error.message, 'error');
          });
      });

      // Close prices modal
      function closePricesModalFunc() {
        pricesModal.classList.add('hidden');
      }

      closePricesModal.addEventListener('click', closePricesModalFunc);
      cancelPricesBtn.addEventListener('click', closePricesModalFunc);

      // Close modal when clicking outside
      pricesModal.addEventListener('click', function(e) {
        if (e.target === pricesModal) {
          closePricesModalFunc();
        }
      });

      // Populate prices table
      function populatePricesTable(documents, prices) {
        pricesTableBody.innerHTML = '';
        documents.forEach(doc => {
          const currentPrice = prices[doc.id] || doc.default_fee;
          const row = document.createElement('tr');
          row.className = 'table-row';
          row.innerHTML = `
            <td class="px-6 py-4 text-sm text-gray-900">${doc.name}</td>
            <td class="px-6 py-4 text-sm text-gray-600">
              <span class="bg-gray-100 px-3 py-1 rounded-full">
                ₱${parseFloat(doc.default_fee).toFixed(2)}
              </span>
            </td>
            <td class="px-6 py-4 text-sm text-gray-900">
              <div class="flex items-center space-x-2">
                <span class="text-gray-500">₱</span>
                <input 
                  type="number" 
                  step="0.01" 
                  min="0" 
                  name="prices[${doc.id}]" 
                  value="${currentPrice}" 
                  class="price-input border border-gray-300 rounded px-3 py-2 w-32 focus:border-blue-500 focus:outline-none"
                >
              </div>
            </td>
          `;
          pricesTableBody.appendChild(row);
        });

        // Add input animation effects
        document.querySelectorAll('.price-input').forEach(input => {
          input.addEventListener('change', function() {
            this.classList.add('scale-110');
            setTimeout(() => this.classList.remove('scale-110'), 200);
          });
        });
      }

      // Handle prices form submission
      pricesForm.addEventListener('submit', function(e) {
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
            const formData = new FormData(pricesForm);
            formData.append('update_prices', '1');

            fetch('', {
              method: 'POST',
              body: formData
            })
            .then(response => response.json())
            .then(data => {
              if (data.success) {
                Swal.fire('Success', data.message, 'success').then(() => {
                  closePricesModalFunc();
                  location.reload(); // Refresh to show updated prices
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

      // Helper functions
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

      function hideLoading() {
        Swal.close();
      }

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

      // Event handlers for buttons
      document.querySelectorAll('.printDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          const requestId = this.getAttribute('data-id');
          fetch(`doc_request.php?action=print&id=${requestId}`)
            .then(response => response.json())
            .then(data => {
              if (!data.success) {
                Swal.fire('Not Available', data.message, 'warning');
              } else {
                window.open(`doc_request.php?action=print&id=${requestId}`, '_blank');
              }
            })
            .catch(() => {
              // If response is not JSON, it means it's the PDF
              window.open(`doc_request.php?action=print&id=${requestId}`, '_blank');
            });
        });
      });

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

      document.querySelectorAll('.deleteDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          let requestId = this.getAttribute('data-id');
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
              const userRemarks = result.value;
              showLoading();
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

      document.querySelectorAll('.viewDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          const requestId = this.dataset.id;
          fetch(`doc_request.php?action=view_doc_request&id=${requestId}`)
            .then(response => response.json())
            .then(data => {
              if (data.success) {
                const r = data.request;
                let additionalInfo = '';
                
                // Add business information if available
                if (r.business_name) {
                  additionalInfo += `<p><strong>Business:</strong> ${r.business_name}</p>`;
                  additionalInfo += `<p><strong>Business Location:</strong> ${r.business_location || 'N/A'}</p>`;
                  additionalInfo += `<p><strong>Business Type:</strong> ${r.business_type || 'N/A'}</p>`;
                  additionalInfo += `<p><strong>Business Nature:</strong> ${r.business_nature || 'N/A'}</p>`;
                }
                
                // Add purpose if available
                if (r.purpose) {
                  additionalInfo += `<p><strong>Purpose:</strong> ${r.purpose}</p>`;
                }

                // Show modal with request details
                Swal.fire({
                  title: 'Document Request Details',
                  html: `
                    <div class="text-left">
                      <p><strong>Name:</strong> ${r.full_name}</p>
                      <p><strong>Document:</strong> ${r.document_name}</p>
                      <p><strong>Contact:</strong> ${r.contact_number || 'N/A'}</p>
                      <p><strong>Email:</strong> ${r.email || 'N/A'}</p>
                      <p><strong>Request Date:</strong> ${r.request_date}</p>
                      <p><strong>Status:</strong> ${r.status}</p>
                      <p><strong>Price:</strong> ₱${parseFloat(r.price || 0).toFixed(2)}</p>
                      ${additionalInfo}
                    </div>
                  `,
                  showCloseButton: true,
                  showConfirmButton: false,
                  width: '600px'
                });
              } else {
                Swal.fire('Error', data.message || 'Unable to load details.', 'error');
              }
            });
        });
      });
    });
  </script>
</body>
</html>