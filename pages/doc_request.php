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
$bid              = 32; // Force to Tambubong for testing purposes
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

// Handle AJAX actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action   = $_GET['action'];
    $reqId    = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $response = ['success' => false, 'message' => ''];

    try {
        if ($action === 'view_doc_request') {
            $stmt = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id, 
                    dr.request_date,
                    dr.status,
                    dr.proof_image_path,
                    dr.remarks AS request_remarks,
                    dr.price,
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
                    -- ID image
                    pi.id_image_path,
                    -- User information (if linked)
                    u.email,
                    u.id AS user_id
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON p.user_id = u.id
                LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
                LEFT JOIN barangay b ON dr.barangay_id = b.id
                LEFT JOIN emergency_contacts ec ON p.id = ec.person_id
                LEFT JOIN person_identification pi ON p.id = pi.person_id
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
            // Get pending requests
            $stmtPending = $pdo->prepare("
                SELECT 
                    dr.id AS document_request_id,
                    dr.request_date,
                    dr.status,
                    dr.price,
                    dr.proof_image_path,
                    dt.name AS document_name,
                    dt.code AS document_code,
                    CONCAT(p.first_name, ' ', p.last_name) AS requester_name,
                    p.contact_number,
                    p.birth_date,
                    p.gender,
                    p.civil_status,
                    COALESCE(u.id, 0) AS user_id,
                    COALESCE(u.is_active, TRUE) AS is_active
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON p.user_id = u.id
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
                    CONCAT(p.first_name, ' ', p.last_name) AS requester_name
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
        
                // 1) Ban in DB
                $stmtBan = $pdo->prepare("
                    UPDATE users
                       SET is_active = FALSE
                     WHERE id = :id
                ");
                
                // First get the user ID from the person_id if needed
                $getUserStmt = $pdo->prepare("
                    SELECT u.id, u.email, CONCAT(p.first_name, ' ', p.last_name) AS name
                    FROM users u
                    JOIN persons p ON u.id = p.user_id
                    WHERE u.id = :id OR p.id = :id
                    LIMIT 1
                ");
                $getUserStmt->execute([':id'=>$reqId]);
                $userInfo = $getUserStmt->fetch();
                
                if ($userInfo && $stmtBan->execute([':id'=>$userInfo['id']])) {
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
                    $response['message'] = 'Unable to ban user or user not found.';
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
                    CONCAT(p.first_name, ' ', p.last_name) AS requester_name,
                    u.email
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON p.user_id = u.id
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
                    CONCAT(p.first_name, ' ', p.last_name) AS requester_name,
                    u.email
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON p.user_id = u.id
                WHERE dr.id = :id
                  AND dr.barangay_id = :bid
            ");
            $stmt->execute([':id'=>$reqId, ':bid'=>$bid]);
            $info = $stmt->fetch();
            
            if ($info && !empty($info['email'])) {
                // Generate PDF
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
                
                // Send email
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
                    $mail->Subject = 'Your Document Request: ' . $info['document_name'];
                    $mail->Body    = "Hello {$info['requester_name']},\n\nPlease find attached your requested document.";
                    $mail->addStringAttachment($pdfOutput, $pdfName, 'base64', 'application/pdf');
                    $mail->send();
                    
                    // Update status
                    $upd = $pdo->prepare("
                        UPDATE document_requests
                        SET status = 'completed', completed_at = NOW()
                        WHERE id = :id AND barangay_id = :bid
                    ");
                    $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                    
                    logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,'Sent PDF and marked complete.');
                    $response['success'] = true;
                    $response['message'] = 'Email sent & marked complete.';
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
require_once "../pages/header.php";

// 1) Fetch all "Pending" doc requests (FIFO => earliest date first)
$stmt = $pdo->prepare("
    SELECT 
        dr.id AS document_request_id,
        dr.request_date,
        dr.status,
        dr.proof_image_path,
        dr.price,
        dt.name AS document_name,
        dt.code AS document_code,
        CONCAT(p.first_name, ' ', p.last_name) AS requester_name,
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
    LEFT JOIN users u ON p.user_id = u.id
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
        CONCAT(p.first_name, ' ', p.last_name) AS requester_name
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
  </style>
</head>
<body class="bg-gray-100">
  <div class="container mx-auto p-4">
    <!-- Pending Requests Section -->
    <section id="docRequests" class="mb-10">
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
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-900 border-b">
                    <?= htmlspecialchars($req['document_name']) ?>
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
                      <button class="printDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-print"></i> Print
                      </button>
                      <button class="sendDocEmailBtn p-2 text-green-600 hover:text-green-900 rounded-lg hover:bg-green-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-envelope"></i> Email
                      </button>
                      <button class="completeDocRequestBtn p-2 text-blue-600 hover:text-blue-900 rounded-lg hover:bg-blue-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-check"></i> Complete
                      </button>
                      <button class="deleteDocRequestBtn p-2 text-red-600 hover:text-red-900 rounded-lg hover:bg-red-50" data-id="<?= $req['document_request_id'] ?>">
                        <i class="fas fa-trash"></i> Delete
                      </button>
                      <?php if ($req['user_id'] > 0 && $req['is_active']): ?>
                        <button
                          class="banUserBtn text-red-600 hover:text-red-900"
                          data-id="<?= $req['user_id'] ?>"
                        >
                          <i class="fas fa-ban"></i> Ban
                        </button>
                      <?php endif; ?>
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

    <!-- Completed (History) Section -->
    <section id="docRequestsHistory">
      <header class="mb-6">
        <h1 class="text-3xl font-bold text-green-800">Document Requests History (Completed)</h1>
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

  <!-- Include the JavaScript and modal code here -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
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
          window.open(`doc_request.php?action=print&id=${requestId}`, '_blank');
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
    });
  </script>
</body>
</html>