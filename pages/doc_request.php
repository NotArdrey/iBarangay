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
$bid              = $_SESSION['barangay_id'] ?? 1;
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
        if ($action === 'generate_financial_report') {
            // Generate financial report for document requests
            $reportType = $_GET['report_type'] ?? 'monthly';
            $year = intval($_GET['year'] ?? date('Y'));
            $month = intval($_GET['month'] ?? date('n'));
            $startDate = $_GET['start_date'] ?? '';
            $endDate = $_GET['end_date'] ?? '';
            
            // Build date conditions based on report type
            $dateCondition = '';
            $params = ['bid' => $bid];
            $reportTitle = '';
            
            if ($reportType === 'monthly') {
                $dateCondition = "AND YEAR(dr.request_date) = :year AND MONTH(dr.request_date) = :month";
                $params['year'] = $year;
                $params['month'] = $month;
                $reportTitle = date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ' Financial Report';
            } elseif ($reportType === 'yearly') {
                $dateCondition = "AND YEAR(dr.request_date) = :year";
                $params['year'] = $year;
                $reportTitle = $year . ' Annual Financial Report';
            } elseif ($reportType === 'custom' && $startDate && $endDate) {
                $dateCondition = "AND DATE(dr.request_date) BETWEEN :start_date AND :end_date";
                $params['start_date'] = $startDate;
                $params['end_date'] = $endDate;
                $reportTitle = 'Financial Report (' . date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate)) . ')';
            }
            
            // Get financial data
            $stmt = $pdo->prepare("
                SELECT 
                    dt.name as document_type,
                    COUNT(dr.id) as total_requests,
                    SUM(dr.price) as total_revenue,
                    AVG(dr.price) as average_fee,
                    DATE_FORMAT(dr.request_date, '%Y-%m') as period
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                WHERE dr.barangay_id = :bid
                  AND dr.status IN ('completed', 'complete')
                  AND dr.price > 0
                  $dateCondition
                GROUP BY dt.id, dt.name
                ORDER BY total_revenue DESC
            ");
            $stmt->execute($params);
            $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get overall totals
            $totalStmt = $pdo->prepare("
                SELECT 
                    COUNT(dr.id) as grand_total_requests,
                    SUM(dr.price) as grand_total_revenue
                FROM document_requests dr
                WHERE dr.barangay_id = :bid
                  AND dr.status IN ('completed', 'complete')
                  AND dr.price > 0
                  $dateCondition
            ");
            $totalStmt->execute($params);
            $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
            
            // Get barangay info and signatures
            $barangayStmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
            $barangayStmt->execute([$bid]);
            $barangayName = $barangayStmt->fetchColumn();
            
            // Get captain and chief signatures
            $sigStmt = $pdo->prepare("
                SELECT 
                    u.first_name, u.last_name, u.role_id,
                    u.esignature_path, u.chief_esignature_path
                FROM users u 
                WHERE u.barangay_id = ? AND u.role_id IN (?, ?) AND u.is_active = 1
            ");
            $sigStmt->execute([$bid, ROLE_CAPTAIN, ROLE_CHAIRPERSON]);
            $officials = $sigStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $captainInfo = null;
            $chiefInfo = null;
            foreach ($officials as $official) {
                if ($official['role_id'] == ROLE_CAPTAIN) {
                    $captainInfo = $official;
                } elseif ($official['role_id'] == ROLE_CHAIRPERSON) {
                    $chiefInfo = $official;
                }
            }
            
            // Generate PDF HTML
            ob_start();
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .title { font-size: 18px; font-weight: bold; margin: 10px 0; }
                    .subtitle { font-size: 14px; color: #666; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f5f5f5; font-weight: bold; }
                    .text-right { text-align: right; }
                    .total-row { background-color: #f9f9f9; font-weight: bold; }
                    .signatures { margin-top: 50px; }
                    .signature-box { display: inline-block; width: 300px; margin: 20px; text-align: center; }
                    .signature-img { max-width: 150px; max-height: 50px; }
                    .signature-line { border-top: 1px solid #000; margin-top: 50px; }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="title">BARANGAY <?= strtoupper(htmlspecialchars($barangayName)) ?></div>
                    <div class="title"><?= htmlspecialchars($reportTitle) ?></div>
                    <div class="subtitle">Generated on <?= date('F d, Y') ?></div>
                </div>
                
                <table>
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th class="text-right">Total Requests</th>
                            <th class="text-right">Total Revenue</th>
                            <th class="text-right">Average Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['document_type']) ?></td>
                            <td class="text-right"><?= number_format($row['total_requests']) ?></td>
                            <td class="text-right">₱<?= number_format($row['total_revenue'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($row['average_fee'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><strong><?= number_format($totals['grand_total_requests']) ?></strong></td>
                            <td class="text-right"><strong>₱<?= number_format($totals['grand_total_revenue'], 2) ?></strong></td>
                            <td class="text-right">-</td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="signatures">
                    <div class="signature-box">
                        <?php if ($captainInfo && !empty($captainInfo['esignature_path'])): ?>
                            <img src="<?= '../' . htmlspecialchars($captainInfo['esignature_path']) ?>" 
                                 alt="Captain Signature" class="signature-img">
                        <?php endif; ?>
                        <div class="signature-line"></div>
                        <div>
                            <strong><?= $captainInfo ? htmlspecialchars($captainInfo['first_name'] . ' ' . $captainInfo['last_name']) : 'Barangay Captain' ?></strong><br>
                            Barangay Captain
                        </div>
                    </div>
                    
                    <div class="signature-box">
                        <?php if ($chiefInfo && !empty($chiefInfo['chief_esignature_path'])): ?>
                            <img src="<?= '../' . htmlspecialchars($chiefInfo['chief_esignature_path']) ?>" 
                                 alt="Chief Signature" class="signature-img">
                        <?php endif; ?>
                        <div class="signature-line"></div>
                        <div>
                            <strong><?= $chiefInfo ? htmlspecialchars($chiefInfo['first_name'] . ' ' . $chiefInfo['last_name']) : 'Barangay Chairperson' ?></strong><br>
                            Barangay Chairperson
                        </div>
                    </div>
                </div>
            </body>
            </html>
            <?php
            $html = ob_get_clean();
            
            $pdf = new Dompdf();
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'portrait');
            $pdf->render();
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="Financial-Report-' . date('Y-m-d') . '.pdf"');
            echo $pdf->output();
            exit;

        } elseif ($action === 'get_document_prices') {
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
                    dr.delivery_method,
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
                    dr.delivery_method,
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
                    // Ban the user
                    $stmtBan = $pdo->prepare("UPDATE users SET is_active = FALSE WHERE id = :id");
                    
                    if ($stmtBan->execute([':id'=>$userInfo['id']])) {
                        // Send notification email
                        if (!empty($userInfo['email'])) {
                            try {
                                $mail = new PHPMailer(true);
                                $mail->isSMTP();
                                $mail->Host = 'smtp.gmail.com';
                                $mail->SMTPAuth = true;
                                $mail->Username = 'ibarangay.system@gmail.com';
                                $mail->Password = 'nxxn vxyb kxum cuvd';
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                $mail->Port = 587;
                                $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                                $mail->addAddress($userInfo['email'], $userInfo['name']);
                                $mail->isHTML(true);
                                $mail->Subject = 'Account Suspended';
                                $mail->Body = "Dear {$userInfo['name']},<br><br>Your account has been suspended. Reason: {$remarks}<br><br>Please contact the barangay office for more information.";
                                $mail->send();
                            } catch (Exception $e) {
                                error_log('Email send failed: ' . $e->getMessage());
                            }
                        }
                        
                        logAuditTrail(
                            $pdo, 
                            $current_admin_id, 
                            'SUSPEND', 
                            'users', 
                            $userInfo['id'], 
                            'Banned user with remarks: ' . $remarks
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
                    $mail->Username   = 'ibarangay.system@gmail.com';  
                    $mail->Password   = 'nxxn vxyb kxum cuvd';   
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                    $mail->addAddress($requestInfo['email'], $requestInfo['requester_name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Document Request Not Processed';
                    $mail->Body = getDocumentReadyTemplate($requestInfo['document_name'], false);
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
                    COALESCE(dr.delivery_method, 'hardcopy') as delivery_method,
                    dt.name AS document_name,
                    dt.code AS document_code,
                    CONCAT(p.first_name, ' ', COALESCE(p.last_name, '')) AS requester_name,
                    u.email,
                    u.id as user_id,
                    p.contact_number
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                JOIN persons p ON dr.person_id = p.id
                LEFT JOIN users u ON dr.user_id = u.id
                WHERE dr.id = :id
                  AND dr.barangay_id = :bid
            ");
            $stmt->execute([':id'=>$reqId, ':bid'=>$bid]);
            $info = $stmt->fetch();
            
            if (!$info) {
                $response['message'] = 'Document request not found.';
            } else {
                // Check if document requires pickup notification (only business permit clearance)
                $requiresPickupNotification = in_array($info['document_code'], ['business_permit_clearance']);
                
                // Check if document is cedula (requires in-person pickup)
                $isCedula = in_array($info['document_code'], ['cedula', 'community_tax_certificate']);
                
                if ($isCedula) {
                    
                    $upd = $pdo->prepare("
                        UPDATE document_requests
                        SET status = 'completed', completed_at = NOW()
                        WHERE id = :id AND barangay_id = :bid
                    ");
                    $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                    
                    logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,
                        'Marked cedula/community tax certificate as complete - requires in-person pickup.'
                    );
                    
                    $response['success'] = true;
                    $response['message'] = 'Cedula/Community Tax Certificate marked as complete. User must visit barangay office for in-person issuance.';
                    
                } elseif ($requiresPickupNotification && !empty($info['email'])) {
                    // Business permit - send pickup notification email
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'ibarangay.system@gmail.com';  
                        $mail->Password   = 'nxxn vxyb kxum cuvd';   
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                        $mail->addAddress($info['email'], $info['requester_name']);
                        $mail->isHTML(true);
                        
                        $mail->Subject = 'Business Permit Clearance Ready for Pickup';
                        $mail->Body = "Hello {$info['requester_name']},<br><br>"
                            . "Your Business Permit Clearance is now ready for pickup at the barangay office.<br><br>"
                            . "Please bring a valid ID when picking up your document.<br><br>"
                            . "Office Hours: Monday to Friday, 8:00 AM - 5:00 PM<br><br>"
                            . "Thank you.";
                        
                        $mail->send();
                        
                        // Update status
                        $upd = $pdo->prepare("
                            UPDATE document_requests
                            SET status = 'completed', completed_at = NOW()
                            WHERE id = :id AND barangay_id = :bid
                        ");
                        $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                        
                        logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,
                            'Business permit marked complete and pickup notification sent via email.'
                        );
                        
                        $response['success'] = true;
                        $response['message'] = 'Pickup notification sent via email & marked complete.';
                        
                    } catch (Exception $e) {
                        // Email failed - still mark as complete but note the issue
                        $upd = $pdo->prepare("
                            UPDATE document_requests
                            SET status = 'completed', completed_at = NOW()
                            WHERE id = :id AND barangay_id = :bid
                        ");
                        $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                        
                        logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,
                            'Business permit marked complete but email notification failed: ' . $e->getMessage()
                        );
                        
                        $response['success'] = true;
                        $response['message'] = 'Business permit marked complete but email notification failed.';
                    }
                    
                } elseif ($requiresPickupNotification && empty($info['email'])) {
                    // Business permit but no email - still mark as complete
                    $upd = $pdo->prepare("
                        UPDATE document_requests
                        SET status = 'completed', completed_at = NOW()
                        WHERE id = :id AND barangay_id = :bid
                    ");
                    $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                    
                    logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,
                        'Business permit marked complete - no email available for notification.'
                    );
                    
                    $response['success'] = true;
                    $response['message'] = 'Business permit marked complete. No email sent - user has no email address on file.';
                    
                    if (!empty($info['contact_number'])) {
                        $response['message'] .= ' Contact number: ' . $info['contact_number'];
                    }
                    
                } else {
                    // For softcopy delivery, actually generate and email the document
                    if (!empty($info['email'])) {
                        try {
                            // Generate PDF document
                            $docRequestId = $reqId;
                            ob_start();
                            require __DIR__ . '/../functions/document_template.php';
                            $html = ob_get_clean();
                            $dompdf = new Dompdf();
                            $dompdf->loadHtml($html);
                            $dompdf->setPaper('A4','portrait');
                            $dompdf->render();
                            $pdfContent = $dompdf->output();
                            
                            // Create temp file for attachment
                            $tempFile = tempnam(sys_get_temp_dir(), 'doc_');
                            file_put_contents($tempFile, $pdfContent);
                            
                            // Send email with attachment
                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com';
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'ibarangay.system@gmail.com';  
                            $mail->Password   = 'nxxn vxyb kxum cuvd';   
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = 587;
                            $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                            $mail->addAddress($info['email'], $info['requester_name']);
                            $mail->isHTML(true);
                            
                            $mail->Subject = 'Your Requested Document: ' . $info['document_name'];
                            $mail->Body = "Hello {$info['requester_name']},<br><br>"
                                . "Your requested document ({$info['document_name']}) is attached to this email.<br><br>"
                                . "This is an electronically generated document. If you need a physical copy with an official seal, "
                                . "please visit the barangay office.<br><br>"
                                . "Thank you for using iBarangay!<br><br>"
                                . "Best regards,<br>"
                                . "The iBarangay Team";
                            
                            // Add PDF attachment
                            $mail->addAttachment($tempFile, 'Document_' . $info['document_code'] . '.pdf');
                            
                            $mail->send();
                            
                            // Delete temp file
                            @unlink($tempFile);
                            
                            // Update status
                            $upd = $pdo->prepare("
                                UPDATE document_requests
                                SET status = 'completed', completed_at = NOW()
                                WHERE id = :id AND barangay_id = :bid
                            ");
                            $upd->execute([':id'=>$reqId,':bid'=>$bid]);
                            
                            logAuditTrail($pdo, $current_admin_id, 'UPDATE','document_requests',$reqId,
                                'Document sent via email and marked complete.'
                            );
                            
                            $response['success'] = true;
                            $response['message'] = 'Document successfully sent to ' . $info['email'] . ' and marked complete.';
                            
                        } catch (Exception $e) {
                            // Email failed
                            logAuditTrail($pdo, $current_admin_id, 'ERROR','document_requests',$reqId,
                                'Failed to send document via email: ' . $e->getMessage()
                            );
                            
                            $response['success'] = false;
                            $response['message'] = 'Failed to send email: ' . $e->getMessage();
                        }
                    } else {
                        // No email available
                        $response['success'] = false;
                        $response['message'] = 'Cannot send email. User has no email address on file.';
                    }
                }
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
        dr.delivery_method,
        dr.payment_method,
        dr.payment_status,
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
      AND LOWER(dr.status) IN ('pending', 'for_payment')
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
      <div class="flex gap-4">
        <button id="managePricesBtn" class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 transition-colors flex items-center">
          <i class="fas fa-dollar-sign mr-2"></i>
          Manage Document Prices
        </button>
        <a href="paymongo_settings.php" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center">
          <i class="fas fa-credit-card mr-2"></i>
          PayMongo Settings
        </a>
      </div>
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
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Document</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Requester</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($docRequests as $req): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?= date('M d, Y', strtotime($req['request_date'])) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($req['document_name']) ?></div>
                  <?php if ($req['price'] > 0): ?>
                    <div class="text-sm text-gray-500">Fee: ₱<?= number_format($req['price'], 2) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?= htmlspecialchars($req['requester_name']) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                    <?= ucfirst($req['status']) ?>
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <div class="flex space-x-2">
                    <button class="viewDocRequestBtn text-blue-600 hover:text-blue-900" data-id="<?= $req['document_request_id'] ?>">
                      View
                    </button>
                    <?php if (strtolower($req['delivery_method']) === 'hardcopy'): ?>
                    <button class="printDocRequestBtn text-green-600 hover:text-green-900" data-id="<?= $req['document_request_id'] ?>">
                      Print
                    </button>
                    <?php endif; ?>
                    <?php if (strtolower($req['delivery_method']) === 'softcopy'): ?>
                    <button class="sendDocEmailBtn text-purple-600 hover:text-purple-900" data-id="<?= $req['document_request_id'] ?>">
                      Send Email
                    </button>
                    <?php endif; ?>
                    <button class="deleteDocRequestBtn text-red-600 hover:text-red-900" data-id="<?= $req['document_request_id'] ?>">
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
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
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Document</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Requester</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sortable">Status</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <?php foreach ($completedRequests as $req): ?>
              <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?= date('M d, Y', strtotime($req['request_date'])) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  <?= htmlspecialchars($req['document_name']) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <?= htmlspecialchars($req['requester_name']) ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                    Completed
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
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
            <h3 class="text-lg font-medium text-gray-900">
              <i class="fas fa-dollar-sign mr-2"></i>
              Manage Document Prices
            </h3>
            <button type="button" id="closePricesModal" class="text-gray-400 hover:text-gray-600">
              <i class="fas fa-times text-xl"></i>
            </button>
          </div>
          
          <form id="pricesForm">
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Document Type
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Default Fee
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Your Price
                    </th>
                  </tr>
                </thead>
                <tbody id="pricesTableBody" class="bg-white divide-y divide-gray-200">
                  <!-- Populated by JavaScript -->
                </tbody>
              </table>
            </div>
            
            <div class="flex justify-end gap-4 mt-6">
              <button type="button" id="cancelPricesBtn" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-lg hover:bg-gray-300">
                Cancel
              </button>
              <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
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

        // Show/hide tab content
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
            <td class="p-4 font-medium text-gray-900">${doc.name}</td>
            <td class="p-4 text-gray-600">₱${parseFloat(doc.default_fee).toFixed(2)}</td>
            <td class="p-4">
              <input type="number" 
                     name="prices[${doc.id}]" 
                     value="${currentPrice}" 
                     min="0" 
                     step="0.01" 
                     class="price-input w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                     required>
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
            showLoading();
            
            const formData = new FormData(pricesForm);
            formData.append('update_prices', '1');
            
            fetch('', {
              method: 'POST',
              body: formData
            })
            .then(response => response.json())
            .then(data => {
              hideLoading();
              if (data.success) {
                Swal.fire('Success!', data.message, 'success').then(() => {
                  closePricesModalFunc();
                  location.reload();
                });
              } else {
                Swal.fire('Error!', data.message, 'error');
              }
            })
            .catch(error => {
              hideLoading();
              Swal.fire('Error!', 'An error occurred: ' + error.message, 'error');
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
              throw new Error('Network response was not ok');
            }
            return resp.json();
          })
          .finally(() => hideLoading());
      }

      // Event handlers for buttons
      document.querySelectorAll('.printDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          const requestId = this.getAttribute('data-id');
          
          // Open print in new tab/window
          window.open(`doc_request.php?action=print&id=${requestId}`, '_blank');
          
          // After printing, ask if they want to mark as complete
          setTimeout(() => {
            Swal.fire({
              title: 'Mark as Complete?',
              text: 'Did you successfully print the document? This will mark it as complete.',
              icon: 'question',
              showCancelButton: true,
              confirmButtonColor: '#10B981',
              cancelButtonColor: '#6B7280',
              confirmButtonText: 'Yes, mark complete',
              cancelButtonText: 'Not yet'
            }).then((result) => {
              if (result.isConfirmed) {
                fetch(`?action=complete&id=${requestId}`)
                .then(response => response.json())
                .then(data => {
                  if (data.success) {
                    Swal.fire('Completed!', data.message, 'success').then(() => {
                      location.reload();
                    });
                  } else {
                    Swal.fire('Error!', data.message, 'error');
                  }
                });
              }
            });
          }, 1000);
        });
      });

      document.querySelectorAll('.sendDocEmailBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          let requestId = this.getAttribute('data-id');
          
          Swal.fire({
            title: 'Send Document via Email?',
            text: 'This will generate the document as a PDF and send it to the requester via email.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10B981',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Yes, send email',
            cancelButtonText: 'Cancel'
          }).then((result) => {
            if (result.isConfirmed) {
              Swal.fire({
                title: 'Sending Email...',
                text: 'Please wait while we generate and send the document.',
                allowOutsideClick: false,
                didOpen: () => {
                  Swal.showLoading();
                }
              });
              
              fetch(`?action=send_email&id=${requestId}`)
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  Swal.fire('Email Sent!', data.message, 'success').then(() => {
                    location.reload();
                  });
                } else {
                  Swal.fire('Error!', data.message, 'error');
                }
              })
              .catch(error => {
                Swal.fire('Error!', 'An error occurred while sending the email.', 'error');
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
            text: 'Are you sure you want to mark this document as complete?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10B981',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Yes, mark complete',
            cancelButtonText: 'Cancel'
          }).then((result) => {
            if (result.isConfirmed) {
              fetch(`?action=complete&id=${requestId}`)
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  Swal.fire('Completed!', data.message, 'success').then(() => {
                    location.reload();
                  });
                } else {
                  Swal.fire('Error!', data.message, 'error');
                }
              });
            }
          });
        });
      });

      document.querySelectorAll('.deleteDocRequestBtn').forEach(btn => {
        btn.addEventListener('click', function() {
          let requestId = this.getAttribute('data-id');
          Swal.fire({
            title: 'Delete Request',
            input: 'textarea',
            inputLabel: 'Reason for deletion (optional)',
            inputPlaceholder: 'Enter reason for deleting this request...',
            showCancelButton: true,
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'Yes, delete',
            cancelButtonText: 'Cancel',
            inputValidator: (value) => {
              // Optional validation - you can make this required if needed
            }
          }).then((result) => {
            if (result.isConfirmed) {
              const formData = new FormData();
              formData.append('remarks', result.value || '');
              
              fetch(`?action=delete&id=${requestId}`, {
                method: 'POST',
                body: formData
              })
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  Swal.fire('Deleted!', data.message, 'success').then(() => {
                    location.reload();
                  });
                } else {
                  Swal.fire('Error!', data.message, 'error');
                }
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
                const req = data.request;
                Swal.fire({
                  title: 'Document Request Details',
                  html: `
                    <div style="text-align: left;">
                      <p><strong>Document:</strong> ${req.document_name}</p>
                      <p><strong>Requester:</strong> ${req.full_name}</p>
                      <p><strong>Contact:</strong> ${req.contact_number || 'N/A'}</p>
                      <p><strong>Request Date:</strong> ${new Date(req.request_date).toLocaleDateString()}</p>
                      <p><strong>Purpose:</strong> ${req.purpose || 'N/A'}</p>
                      ${req.price > 0 ? `<p><strong>Fee:</strong> ₱${parseFloat(req.price).toFixed(2)}</p>` : ''}
                    </div>
                  `,
                  width: '500px',
                  confirmButtonText: 'Close'
                });
              } else {
                Swal.fire('Error!', data.message, 'error');
              }
            });
        });
      });

      // Modified function to handle document requests display with delivery method
      function updateDocRequests(data) {
        const pendingTable = document.getElementById('docRequestsTable').querySelector('tbody');
        const completedTable = document.getElementById('docRequestsHistoryTable').querySelector('tbody');
        
        // Clear existing rows
        pendingTable.innerHTML = '';
        completedTable.innerHTML = '';
        
        // Add pending requests
        data.pending.forEach(req => {
          const row = document.createElement('tr');
          row.className = 'hover:bg-gray-50';
          
          const dateCell = document.createElement('td');
          dateCell.className = 'px-6 py-4 whitespace-nowrap text-sm text-gray-900';
          dateCell.textContent = new Date(req.request_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          
          const documentCell = document.createElement('td');
          documentCell.className = 'px-6 py-4 whitespace-nowrap';
          documentCell.innerHTML = `
            <div class="text-sm font-medium text-gray-900">${req.document_name}</div>
            ${req.price > 0 ? `<div class="text-sm text-gray-500">Fee: ₱${parseFloat(req.price).toFixed(2)}</div>` : ''}
          `;
          
          const requesterCell = document.createElement('td');
          requesterCell.className = 'px-6 py-4 whitespace-nowrap text-sm text-gray-900';
          requesterCell.textContent = req.requester_name;
          
          const statusCell = document.createElement('td');
          statusCell.className = 'px-6 py-4 whitespace-nowrap';
          statusCell.innerHTML = `
            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
              ${req.status.charAt(0).toUpperCase() + req.status.slice(1)}
            </span>
          `;
          
          const actionsCell = document.createElement('td');
          actionsCell.className = 'px-6 py-4 whitespace-nowrap text-sm font-medium';
          
          // Use delivery_method to determine which buttons to display
          const deliveryMethod = (req.delivery_method || '').toLowerCase();
          
          actionsCell.innerHTML = `
            <div class="flex space-x-2">
              <button class="viewDocRequestBtn text-blue-600 hover:text-blue-900" data-id="${req.document_request_id}">
                View
              </button>
              ${deliveryMethod === 'hardcopy' ? `
              <button class="printDocRequestBtn text-green-600 hover:text-green-900" data-id="${req.document_request_id}">
                Print
              </button>
              ` : ''}
              ${deliveryMethod === 'softcopy' ? `
              <button class="sendDocEmailBtn text-purple-600 hover:text-purple-900" data-id="${req.document_request_id}">
                Send Email
              </button>
              ` : ''}
              <button class="deleteDocRequestBtn text-red-600 hover:text-red-900" data-id="${req.document_request_id}">
                Delete
              </button>
            </div>
          `;
          
          row.appendChild(dateCell);
          row.appendChild(documentCell);
          row.appendChild(requesterCell);
          row.appendChild(statusCell);
          row.appendChild(actionsCell);
          
          pendingTable.appendChild(row);
        });
        
        // Add completed requests
        data.completed.forEach(req => {
          const row = document.createElement('tr');
          row.className = 'hover:bg-gray-50';
          
          row.innerHTML = `
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
              ${new Date(req.request_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
              ${req.document_name}
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
              ${req.requester_name}
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                Completed
              </span>
            </td>
          `;
          
          completedTable.appendChild(row);
        });
        
        // Re-attach event listeners for action buttons
        attachActionButtonListeners();
      }
      
      // Function to attach event listeners to action buttons
      function attachActionButtonListeners() {
        document.querySelectorAll('.viewDocRequestBtn').forEach(btn => {
          btn.addEventListener('click', viewDocRequest);
        });
        
        document.querySelectorAll('.printDocRequestBtn').forEach(btn => {
          btn.addEventListener('click', printDocRequest);
        });
        
        document.querySelectorAll('.sendDocEmailBtn').forEach(btn => {
          btn.addEventListener('click', sendDocEmail);
        });
        
        document.querySelectorAll('.deleteDocRequestBtn').forEach(btn => {
          btn.addEventListener('click', deleteDocRequest);
        });
      }
      
      // Define button click handlers
      function viewDocRequest() {
        const requestId = this.getAttribute('data-id');
        // ... existing view request code ...
      }
      
      function printDocRequest() {
        const requestId = this.getAttribute('data-id');
        // ... existing print request code ...
      }
      
      function sendDocEmail() {
        const requestId = this.getAttribute('data-id');
        // ... existing email request code ...
      }
      
      function deleteDocRequest() {
        const requestId = this.getAttribute('data-id');
        // ... existing delete request code ...
      }

      // Function to load requests via AJAX
      function loadRequests() {
        fetch('?action=get_requests')
          .then(response => response.json())
          .then(data => {
            updateDocRequests(data);
          })
          .catch(error => {
            console.error('Error loading requests:', error);
          });
      }
    });
  </script>
</body>
</html>