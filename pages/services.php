<?php
session_start();
require_once "../config/dbconn.php";

// Check for pending requests - UPDATED to use new table structure
$hasPendingRequest = false;
$pendingRequests = [];
$hasPendingBlotter = false;
$pendingBlotterCases = [];
$hasInsufficientResidency = false;
$residencyDetails = [];

if (isset($_SESSION['user_id'])) {
    // First check for pending document requests
    $stmt = $pdo->prepare("
        SELECT 
            dr.id,
            dr.status,
            dr.created_at,
            dr.request_date,
            dr.price,
            p.first_name,
            p.last_name,
            dt.name as document_name, 
            dt.code as document_code
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        JOIN persons p ON dr.person_id = p.id
        WHERE dr.user_id = ? 
        AND dr.status IN ('pending', 'processing', 'for_payment')
        ORDER BY dr.created_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasPendingRequest = count($pendingRequests) > 0;

    // Check for pending blotter cases in ANY barangay where the user is involved
    $stmt = $pdo->prepare("
        SELECT bc.id, bc.case_number, bc.status, b.name as barangay_name, bc.description,
               bc.incident_date, bp.role
        FROM blotter_cases bc
        JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
        JOIN persons p ON bp.person_id = p.id
        JOIN barangay b ON bc.barangay_id = b.id
        WHERE p.user_id = ? 
        AND bc.status IN ('pending', 'open', 'processing')
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pendingBlotterCases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasPendingBlotter = count($pendingBlotterCases) > 0;

    // NEW: Check residency requirement (6 months minimum)
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.first_name,
            p.last_name,
            p.years_of_residency,
            a.years_in_san_rafael,
            a.residency_type,
            a.created_at as address_record_created,
            CASE 
                WHEN p.years_of_residency >= 1 THEN 'sufficient_years'
                WHEN a.years_in_san_rafael >= 1 THEN 'sufficient_address_years'
                WHEN TIMESTAMPDIFF(MONTH, a.created_at, NOW()) >= 6 THEN 'sufficient_record_age'
                ELSE 'insufficient'
            END as residency_status,
            CASE 
                WHEN p.years_of_residency >= 1 THEN p.years_of_residency
                WHEN a.years_in_san_rafael >= 1 THEN a.years_in_san_rafael
                ELSE TIMESTAMPDIFF(MONTH, a.created_at, NOW())
            END as computed_months
        FROM persons p
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
        WHERE p.user_id = ? AND a.barangay_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['barangay_id']]);
    $residencyDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($residencyDetails) {
        $hasInsufficientResidency = ($residencyDetails['residency_status'] === 'insufficient');
    } else {
        // No person/address record found - this is also insufficient
        $hasInsufficientResidency = true;
        $residencyDetails = [
            'residency_status' => 'no_record',
            'computed_months' => 0
        ];
    }
}

// Handle form submission for document requests - UPDATED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Time-gated validation - ENABLED
        $currentTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
        $startTime = new DateTime('08:00:00', new DateTimeZone('Asia/Manila'));
        $endTime = new DateTime('17:00:00', new DateTimeZone('Asia/Manila'));

        if ($currentTime < $startTime || $currentTime > $endTime) {
            throw new Exception("Document requests can only be submitted between 8:00 AM and 5:00 PM.");
        }

        // Check if user has pending blotter cases in ANY barangay
        if ($hasPendingBlotter) {
            $caseDetails = [];
            foreach ($pendingBlotterCases as $case) {
                $caseDetails[] = "Case #" . $case['case_number'] . " in " . $case['barangay_name'];
            }
            throw new Exception("You have pending blotter case(s): " . implode(", ", $caseDetails) . ". Document requests are not allowed until your case(s) are resolved.");
        }

        // NEW: Check residency requirement
        if ($hasInsufficientResidency) {
            if ($residencyDetails['residency_status'] === 'no_record') {
                throw new Exception("You need to be a resident for at least 6 months to request documents. Please contact the barangay office to update your residency information.");
            } else {
                $monthsLived = $residencyDetails['computed_months'];
                $monthsNeeded = 6 - $monthsLived;
                throw new Exception("Insufficient residency period. You need to be a resident for at least 6 months to request documents. You currently have {$monthsLived} month(s) of recorded residency. Please wait {$monthsNeeded} more month(s) or contact the barangay office if this is incorrect.");
            }
        }

        // Check if user has pending requests
        if ($hasPendingRequest && !isset($_POST['override_pending'])) {
            throw new Exception("You have pending document requests. Please wait for them to be processed before submitting new requests.");
        }

        // Validate required fields
        $documentTypeId = $_POST['document_type_id'] ?? '';
        if (empty($documentTypeId)) {
            throw new Exception("Please select a document type");
        }

        // Get user info
        if (!isset($_SESSION['user_id'])) {
            throw new Exception("Please log in to submit a request");
        }
        $user_id = $_SESSION['user_id'];

        // Get user information (no census requirement)
        $stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.gender, u.id
            FROM users u 
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();
        
        if (!$user_info) {
            throw new Exception("User not found. Please log in again.");
        }

        // Try to get person from census (optional)
        $stmt = $pdo->prepare("
            SELECT p.* FROM persons p WHERE p.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $person = $stmt->fetch();

        // Use user info if no person record exists
        if (!$person) {
            $person = [
                'id' => null,
                'first_name' => $user_info['first_name'],
                'last_name' => $user_info['last_name'],
                'middle_name' => '',
                'suffix' => '',
                'gender' => $user_info['gender'],
                'civil_status' => '',
                'citizenship' => 'Filipino',
                'birth_date' => null,
                'birth_place' => '',
                'religion' => '',
                'education_level' => '',
                'occupation' => '',
                'monthly_income' => null,
                'contact_number' => ''
            ];
        }

        // Set default address (no census requirement)
        $address = [
            'house_no' => '',
            'street' => 'Default Street',
            'subdivision' => '',
            'block_lot' => '',
            'phase' => ''
        ];

        // Get document type info for determining price
        $stmt = $pdo->prepare("
            SELECT dt.*, COALESCE(bdp.price, dt.default_fee) as final_price
            FROM document_types dt
            LEFT JOIN barangay_document_prices bdp ON bdp.document_type_id = dt.id 
                AND bdp.barangay_id = ?
            WHERE dt.id = ?
        ");
        $stmt->execute([$_SESSION['barangay_id'], $documentTypeId]);
        $documentType = $stmt->fetch();

        if (!$documentType) {
            throw new Exception("Invalid document type selected");
        }

      

        // Begin transaction for the main request
        $pdo->beginTransaction();
        
        try {
            // Handle file upload for indigency certificate
            $imagePath = null;
            if ($documentType['code'] === 'barangay_indigency' && isset($_FILES['userPhoto'])) {
                if ($_FILES['userPhoto']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/indigency_photos/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                    $fileType = $_FILES['userPhoto']['type'];
                    
                    if (!in_array($fileType, $allowedTypes)) {
                        throw new Exception("Invalid file type. Please upload JPG or PNG images only.");
                    }
                    
                    $maxSize = 5 * 1024 * 1024; // 5MB
                    if ($_FILES['userPhoto']['size'] > $maxSize) {
                        throw new Exception("File size too large. Maximum size is 5MB.");
                    }
                    
                    $fileName = uniqid() . '_' . time() . '.' . pathinfo($_FILES['userPhoto']['name'], PATHINFO_EXTENSION);
                    $targetPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['userPhoto']['tmp_name'], $targetPath)) {
                        $imagePath = 'uploads/indigency_photos/' . $fileName;
                    } else {
                        throw new Exception("Failed to upload photo. Please try again.");
                    }
                } else {
                    throw new Exception("Photo is required for Barangay Indigency Certificate.");
                }
            }

            // Prepare the main insert statement with all the new columns
            $stmt = $pdo->prepare("
                INSERT INTO document_requests (
                    document_type_id, person_id, user_id, barangay_id, requested_by_user_id, 
                    status, request_date, price,
                    
                    -- Personal Information
                    first_name, middle_name, last_name, suffix, gender, civil_status, 
                    citizenship, birth_date, birth_place, religion, education_level, 
                    occupation, monthly_income, contact_number,
                    
                    -- Address Information
                    address_no, street,
                    
                    -- Business Information (for business permits)
                    business_name, business_location, business_nature, business_type,
                    
                    -- Purpose and specific fields
                    purpose,
                    
                    -- Image path for indigency
                    proof_image_path
                ) VALUES (
                    ?, ?, ?, ?, ?, 'pending', NOW(), ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            // Prepare values based on document type
            $values = [
                $documentTypeId,
                $person['id'], // Can be null if no census record
                $user_id,
                $_SESSION['barangay_id'],
                $user_id,
                $documentType['final_price'],
                
                // Personal Information (from user or person table)
                $person['first_name'] ?? '',
                $person['middle_name'] ?? '',
                $person['last_name'] ?? '',
                $person['suffix'] ?? '',
                $person['gender'] ?? '',
                $person['civil_status'] ?? '',
                $person['citizenship'] ?? 'Filipino',
                $person['birth_date'] ?? null,
                $person['birth_place'] ?? '',
                $person['religion'] ?? '',
                $person['education_level'] ?? '',
                $person['occupation'] ?? '',
                $person['monthly_income'] ?? null,
                $person['contact_number'] ?? '',
                
                // Address Information (defaults)
                $address['house_no'] ?? '',
                $address['street'] ?? ''
            ];

            // Add document-specific values
            switch($documentType['code']) {
                case 'barangay_clearance':
                    $values = array_merge($values, [
                        '', '', '', '', // business fields - empty for clearance
                        $_POST['purposeClearance'] ?? '', // purpose
                        $imagePath // proof_image_path
                    ]);
                    break;

                case 'proof_of_residency':
                    $purpose = 'Duration: ' . ($_POST['residencyDuration'] ?? '') . 
                               '; Purpose: ' . ($_POST['residencyPurpose'] ?? '');
                    $values = array_merge($values, [
                        '', '', '', '', // business fields - empty
                        $purpose, // purpose
                        $imagePath // proof_image_path
                    ]);
                    break;

                case 'barangay_indigency':
                    $values = array_merge($values, [
                        '', '', '', '', // business fields - empty
                        $_POST['indigencyReason'] ?? '', // purpose
                        $imagePath // proof_image_path (required for indigency)
                    ]);
                    break;

                case 'business_permit_clearance':
                    $values = array_merge($values, [
                        $_POST['businessName'] ?? '', // business_name
                        $_POST['businessAddress'] ?? '', // business_location
                        $_POST['businessPurpose'] ?? '', // business_nature
                        $_POST['businessType'] ?? '', // business_type
                        'Business Permit Application', // purpose
                        $imagePath // proof_image_path
                    ]);
                    break;

                case 'first_time_job_seeker':
                    $values = array_merge($values, [
                        '', '', '', '', // business fields - empty
                        $_POST['jobSeekerPurpose'] ?? '', // purpose
                        $imagePath // proof_image_path
                    ]);
                    break;

                default:
                    $values = array_merge($values, [
                        '', '', '', '', // business fields - empty
                        '', // purpose
                        $imagePath // proof_image_path
                    ]);
                    break;
            }

            // Execute the insert
            $stmt->execute($values);
            $requestId = $pdo->lastInsertId();

            // If we got here, commit the transaction
            $pdo->commit();

            // Set success notification
            $_SESSION['success'] = [
                'title' => 'Document Request Submitted',
                'message' => 'Your document request has been submitted successfully.',
                'processing' => 'Please wait for the processing of your request. You will be notified once it is ready.'
            ];
            $_SESSION['show_pending'] = true;

            // Redirect back to the same page to show pending requests
            header('Location: ' . $_SERVER['PHP_SELF'] . '?show_pending=1');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            // Delete uploaded file if transaction failed
            if ($imagePath && file_exists('../' . $imagePath)) {
                unlink('../' . $imagePath);
            }
            throw $e;
        }

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get user info for the page header
$userName = '';
$barangayName = '';
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT p.first_name, p.last_name, b.name as barangay_name
            FROM users u
            LEFT JOIN persons p ON u.id = p.user_id
            LEFT JOIN barangay b ON u.barangay_id = b.id
            WHERE u.id = ?";
    $stmtUser = $pdo->prepare($sql);
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch();
    if ($user) {
        $userName = trim($user['first_name'] . ' ' . $user['last_name']);
        $barangayName = $user['barangay_name'] ?? '';
    }
}

// Get all document types (only the 6 supported)
$barangay_id = $_SESSION['barangay_id'];

$stmt = $pdo->prepare("
    SELECT 
        dt.id,
        dt.name,
        dt.code,
        dt.default_fee,
        COALESCE(bdp.price, dt.default_fee) AS price
    FROM document_types dt
    LEFT JOIN barangay_document_prices bdp
        ON bdp.document_type_id = dt.id AND bdp.barangay_id = ?
    WHERE dt.is_active = 1
");
$stmt->execute([$barangay_id]);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a PHP array for JS
$barangayPrices = [];
foreach ($docs as $doc) {
    $barangayPrices[$doc['code']] = $doc['price'];
}

$selectedDocumentType = $_GET['documentType'] ?? '';
$showPending = isset($_GET['show_pending']) || isset($_SESSION['show_pending']);
unset($_SESSION['show_pending']);

// Time-gated notice - ENABLED
$currentTime = new DateTime('now', new DateTimeZone('Asia/Manila'));
$startTime = new DateTime('08:00:00', new DateTimeZone('Asia/Manila'));
$endTime = new DateTime('17:00:00', new DateTimeZone('Asia/Manila'));
$isWithinTimeGate = ($currentTime >= $startTime && $currentTime <= $endTime);

require_once '../components/navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iBarangay - Document Request</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../styles/services.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    /* Footer fix */
    body {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        margin: 0;
    }

    main {
        flex: 1;
    }
    
    .footer {
        position: relative;
        width: 100%;
        padding: 1rem 0;
        text-align: center;  
        z-index: 1;
    }

    /* Override SweetAlert z-index to ensure it appears above footer */
    .swal2-container {
        z-index: 9999 !important;
    }

    /* Ensure main content has proper spacing */
    .wizard-section {
        padding-bottom: 2rem;
    }

    /* Enhanced photo upload styles */
    .photo-upload-container {
        margin: 1rem 0;
        padding: 1.5rem;
        border: 2px dashed #ddd;
        border-radius: 8px;
        text-align: center;
        background: #f9f9f9;
        transition: all 0.3s ease;
        min-height: 120px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }

    .photo-upload-container.active {
        border-color: #0a2240;
        background: #e8f0ff;
    }

    .upload-options {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin: 1rem 0;
        flex-wrap: wrap;
    }

    .upload-btn {
        padding: 0.7rem 1.2rem;
        background: #0a2240;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .upload-btn:hover:not(:disabled) {
        background: #1a3350;
        transform: translateY(-1px);
    }

    .upload-btn:disabled {
        background: #ccc;
        cursor: not-allowed;
    }

    .upload-btn i {
        font-size: 1rem;
    }

    .photo-preview {
        margin: 1rem auto;
        text-align: center;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .photo-preview img {
        max-width: 200px;
        max-height: 200px;
        width: auto;
        height: auto;
        object-fit: cover;
        border-radius: 8px;
        border: 2px solid #0a2240;
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .remove-photo {
        margin-top: 0.5rem;
        padding: 0.4rem 0.8rem;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.85rem;
        transition: background 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.3rem;
        justify-content: center;
    }

    .remove-photo:hover {
        background: #c82333;
    }

    .upload-hint {
        color: #666;
        font-size: 0.9rem;
        margin: 0.5rem 0;
        text-align: center;
    }

    /* Pending requests section */
    .pending-requests-section {
        background: #f8f9fa;
        padding: 2rem;
        margin: 2rem 0;
        border-radius: 10px;
    }

    .pending-requests-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .pending-requests-header h3 {
        color: #0a2240;
        margin: 0;
    }

    .request-card {
        background: white;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-left: 4px solid #0a2240;
    }

    .request-card:last-child {
        margin-bottom: 0;
    }

    .request-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .request-title {
        font-weight: 600;
        color: #0a2240;
    }

    .request-status {
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .status-pending {
        background: #ffc107;
        color: #000;
    }

    .status-processing {
        background: #17a2b8;
        color: white;
    }

    .status-for_payment {
        background: #fd7e14;
        color: white;
    }

    .request-details {
        color: #666;
        font-size: 0.9rem;
    }

    .request-date {
        color: #999;
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }

    .no-pending {
        text-align: center;
        color: #666;
        padding: 2rem;
    }

    .new-request-btn {
        background: #0a2240;
        color: white;
        padding: 0.5rem 1.5rem;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .new-request-btn:hover {
        background: #1a3350;
    }

    /* Warning message for pending requests */
    .pending-warning {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .pending-warning i {
        font-size: 1.2rem;
    }

    .time-gate-notice {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
        text-align: center;
    }

    /* NEW: Residency warning styles */
    .residency-warning {
        background: #dc3545;
        color: white;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
    }

    .residency-warning h4 {
        margin: 0 0 0.5rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .residency-warning p {
        margin: 0.5rem 0;
    }

    .residency-warning ul {
        margin: 0.5rem 0;
        padding-left: 2rem;
    }

    .residency-info {
        background: #e3f2fd;
        border: 1px solid #bbdefb;
        color: #1976d2;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
    }

    .residency-info h4 {
        margin: 0 0 0.5rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .blotter-warning {
        background: #dc3545;
        color: white;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
    }

    .blotter-warning i {
        margin-right: 0.5rem;
    }

    .blotter-warning p {
        margin: 0.5rem 0;
    }

    .blotter-warning ul {
        margin: 0.5rem 0;
        padding-left: 2rem;
    }

    .blotter-warning li {
        margin-bottom: 0.3rem;
    }

    .blotter-warning small {
        opacity: 0.9;
    }

    .cedula-note {
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 4px;
        font-size: 0.9rem;
        color: #666;
    }

    /* Responsive design */
    @media (max-width: 768px) {
        .upload-options {
            flex-direction: column;
            align-items: center;
        }
        
        .upload-btn {
            width: 100%;
            max-width: 200px;
            justify-content: center;
        }
        
        .photo-preview img {
            max-width: 150px;
            max-height: 150px;
        }

        .pending-requests-header {
            flex-direction: column;
            gap: 1rem;
            align-items: stretch;
        }

        .new-request-btn {
            text-align: center;
        }
    }

    /* Camera popup styling */
    .camera-popup {
        border-radius: 15px !important;
    }

    .camera-popup .swal2-html-container {
        margin: 1rem 0 !important;
    }

    /* Form styling improvements */
    .form-row {
        margin-bottom: 1rem;
    }

    .form-row label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #333;
    }

    .form-row input[type="text"],
    .form-row input[type="number"],
    .form-row select {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 1rem;
        transition: border-color 0.3s ease;
    }

    .form-row input:focus,
    .form-row select:focus {
        outline: none;
        border-color: #0a2240;
        box-shadow: 0 0 0 2px rgba(10, 34, 64, 0.1);
    }

    .form-row input:disabled,
    .form-row select:disabled {
        background-color: #f5f5f5;
        color: #999;
        cursor: not-allowed;
    }

    .input-help {
        font-size: 0.85rem;
        color: #666;
        margin-top: 0.3rem;
        display: block;
    }

    .document-fields {
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 1.5rem;
        margin: 1rem 0;
        background: #fafafa;
    }

    .cta-button {
        background: #0a2240;
        color: white;
        padding: 1rem 2rem;
        border: none;
        border-radius: 5px;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%;
        margin-top: 1rem;
    }

    .cta-button:hover:not(:disabled) {
        background: #1a3350;
        transform: translateY(-1px);
    }

    .cta-button:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
    }

    .wizard-container {
        max-width: 800px;
        margin: 0 auto;
        padding: 2rem;
    }

    .form-header {
        text-align: center;
        color: #0a2240;
        margin-bottom: 2rem;
        font-size: 2rem;
        font-weight: 600;
    }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['success'])): ?>
    <script>
        Swal.fire({
            title: '<?= $_SESSION['success']['title'] ?>',
            html: `<b><?= $_SESSION['success']['message'] ?></b><br><br><?= $_SESSION['success']['processing'] ?>`,
            icon: 'success'
        });
    </script>
    <?php unset($_SESSION['success']); endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <script>
        Swal.fire({
            title: 'Error',
            text: '<?= $_SESSION['error'] ?>',
            icon: 'error'
        });
    </script>
    <?php unset($_SESSION['error']); endif; ?>

    <main>
        <?php if ($showPending && count($pendingRequests) > 0): ?>
        <section class="pending-requests-section">
            <div class="wizard-container">
                <div class="pending-requests-header">
                    <h3><i class="fas fa-clock"></i> Your Pending Document Requests</h3>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="new-request-btn">
                        <i class="fas fa-plus"></i> New Request
                    </a>
                </div>
                
                <?php foreach ($pendingRequests as $request): ?>
                <div class="request-card">
                    <div class="request-header">
                        <div class="request-title"><?= htmlspecialchars($request['document_name']) ?></div>
                        <span class="request-status status-<?= $request['status'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $request['status'])) ?>
                        </span>
                    </div>
                    <div class="request-details">
                        Request ID: #<?= str_pad($request['id'], 6, '0', STR_PAD_LEFT) ?>
                        <?php if (!empty($request['business_name'])): ?>
                            <br>Business: <?= htmlspecialchars($request['business_name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($request['purpose'])): ?>
                            <br>Purpose: <?= htmlspecialchars($request['purpose']) ?>
                        <?php endif; ?>
                        <?php if ($request['price'] > 0): ?>
                            <br>Fee: ₱<?= number_format($request['price'], 2) ?>
                        <?php endif; ?>
                    </div>
                    <div class="request-date">
                        Submitted on: <?= date('F d, Y - h:i A', strtotime($request['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php else: ?>
        
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                
                <!-- Time gate notice - ENABLED -->
                <?php if (!$isWithinTimeGate): ?>
                <div class="time-gate-notice">
                    <p><i class="fas fa-clock"></i> Document requests can only be submitted between 8:00 AM and 5:00 PM.</p>
                    <p>Please come back during operating hours.</p>
                </div>
                <?php endif; ?>

                <?php if ($hasPendingBlotter): ?>
                <div class="blotter-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p><strong>Document Request Restricted</strong></p>
                    <p>You currently have pending blotter case(s):</p>
                    <ul>
                        <?php foreach ($pendingBlotterCases as $case): ?>
                        <li>Case #<?= htmlspecialchars($case['case_number']) ?> in <?= htmlspecialchars($case['barangay_name']) ?>
                            <br>
                            <small>Status: <?= ucfirst($case['status']) ?> | Role: <?= ucfirst($case['role']) ?></small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>Document requests are not allowed until your case(s) are resolved.</p>
                </div>
                <?php endif; ?>

                <?php if ($hasInsufficientResidency): ?>
                <div class="residency-warning">
                    <h4><i class="fas fa-home"></i> Insufficient Residency Period</h4>
                    <?php if ($residencyDetails['residency_status'] === 'no_record'): ?>
                        <p><strong>6-month residency requirement not met.</strong></p>
                        <p>You need to be a resident for at least 6 months to request documents.</p>
                        <p>Please contact the barangay office to update your residency information if you have been a resident for 6+ months.</p>
                    <?php else: ?>
                        <p><strong>You need to be a resident for at least 6 months to request documents.</strong></p>
                        <p>Current residency period: <strong><?= $residencyDetails['computed_months'] ?> month(s)</strong></p>
                        <p>You need <strong><?= (6 - $residencyDetails['computed_months']) ?> more month(s)</strong> before you can request documents.</p>
                        <p>If you believe this information is incorrect, please contact the barangay office to update your residency records.</p>
                    <?php endif; ?>
                    <p><em>This requirement ensures that documents are only issued to established residents of the barangay.</em></p>
                </div>
                <?php elseif ($residencyDetails && $residencyDetails['residency_status'] !== 'no_record'): ?>
                <div class="residency-info">
                    <h4><i class="fas fa-check-circle"></i> Residency Verified</h4>
                    <p>Residency period: <strong><?= $residencyDetails['computed_months'] >= 12 ? floor($residencyDetails['computed_months'] / 12) . ' year(s) ' . ($residencyDetails['computed_months'] % 12) . ' month(s)' : $residencyDetails['computed_months'] . ' month(s)' ?></strong></p>
                    <p>You meet the minimum 6-month residency requirement for document requests.</p>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" enctype="multipart/form-data" id="docRequestForm">
                    <div class="form-row">
                        <label for="documentType">Document Type</label>
                        <select id="documentType" name="document_type_id" required <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                            <option value="">Select Document</option>
                            <?php
                            // Always show all 6 supported documents in the correct order
                            $requiredDocs = [
                                'barangay_clearance',
                                'barangay_indigency',
                                'business_permit_clearance',
                                'cedula',
                                'proof_of_residency',
                                'first_time_job_seeker'
                            ];
                            // Build a map for quick lookup
                            $docMap = [];
                            foreach ($docs as $doc) {
                                $docMap[$doc['code']] = $doc;
                            }
                            foreach ($requiredDocs as $code) {
                                if (isset($docMap[$code])) {
                                    $doc = $docMap[$code];
                                    ?>
                                    <option value="<?= $doc['id'] ?>" data-code="<?= $doc['code'] ?>">
                                        <?= htmlspecialchars($doc['name']) ?>
                                    </option>
                                    <?php
                                }
                            }
                            ?>
                        </select>
                        
                    
                    </div>

                    <!-- Document price/fee label -->
                    <div class="form-row">
                        <label>Document Fee:</label>
                        <span id="feeAmount" style="font-weight:bold;">₱0.00</span>
                    </div>

                    <!-- Document-specific fields for the 6 document types -->
                    <div id="clearanceFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="purposeClearance">Purpose of Clearance</label>
                            <input type="text" id="purposeClearance" name="purposeClearance" placeholder="Enter purpose (e.g., Employment, Business Permit, etc.)" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div id="residencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="residencyDuration">Duration of Residency</label>
                            <input type="text" id="residencyDuration" name="residencyDuration" placeholder="e.g., 5 years" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="residencyPurpose">Purpose</label>
                            <input type="text" id="residencyPurpose" name="residencyPurpose" placeholder="Enter purpose (e.g., School enrollment, Scholarship, etc.)" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div id="indigencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="indigencyReason">Reason for Requesting</label>
                            <input type="text" id="indigencyReason" name="indigencyReason" placeholder="Enter reason (e.g., Medical assistance, Educational assistance, etc.)" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label>Your Photo <span style="color: red;">*</span></label>
                            <div class="photo-upload-container" id="photoUploadContainer">
                                <input type="file" id="userPhoto" name="userPhoto" accept="image/jpeg,image/jpg,image/png" style="display: none;" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                
                                <!-- Upload options -->
                                <div class="upload-options">
                                    <button type="button" class="upload-btn" onclick="document.getElementById('userPhoto').click();" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                        <i class="fas fa-upload"></i> Choose Photo
                                    </button>
                                    <button type="button" class="upload-btn" onclick="openCamera();" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                        <i class="fas fa-camera"></i> Take Photo
                                    </button>
                                </div>
                                
                                <!-- Upload hint -->
                                <p class="upload-hint">
                                    Upload a recent photo of yourself (JPG or PNG, max 5MB)
                                </p>
                                
                                <!-- Photo preview -->
                                <div class="photo-preview" id="photoPreview" style="display: none;">
                                    <img id="previewImage" src="" alt="Photo preview">
                                    <br>
                                    <button type="button" class="remove-photo" onclick="removePhoto();">
                                        <i class="fas fa-trash"></i> Remove Photo
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="cedulaFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="cedulaOccupation">Occupation</label>
                            <input type="text" id="cedulaOccupation" name="cedulaOccupation" placeholder="Enter your occupation" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="cedulaIncome">Annual Income</label>
                            <input type="number" id="cedulaIncome" name="cedulaIncome" placeholder="Enter annual income in PHP" min="0" step="0.01" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="cedulaBirthplace">Place of Birth (Optional)</label>
                            <input type="text" id="cedulaBirthplace" name="cedulaBirthplace" placeholder="Enter place of birth" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div id="businessPermitFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="businessName">Business Name <span style="color: red;">*</span></label>
                            <input type="text" id="businessName" name="businessName" placeholder="Enter business name" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="businessType">Type of Business <span style="color: red;">*</span></label>
                            <input type="text" id="businessType" name="businessType" placeholder="Enter type of business (e.g., Retail, Restaurant, etc.)" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="businessAddress">Business Location/Address <span style="color: red;">*</span></label>
                            <input type="text" id="businessAddress" name="businessAddress" placeholder="Enter complete business address" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="businessPurpose">Nature of Business <span style="color: red;">*</span></label>
                            <input type="text" id="businessPurpose" name="businessPurpose" placeholder="Describe the nature of business operations" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                            <small class="input-help">Describe what your business does (e.g., Food Service, General Merchandise, etc.)</small>
                        </div>
                    </div>

                    <div id="firstTimeJobSeekerFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="jobSeekerPurpose">Purpose/Institution</label>
                            <input type="text" id="jobSeekerPurpose" name="jobSeekerPurpose" placeholder="Enter where you will use this certificate (e.g., Company name, Job application, etc.)" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <?php if ($hasPendingRequest): ?>
                    <input type="hidden" name="override_pending" value="1">
                    <?php endif; ?>

                    <button type="submit" class="btn cta-button" id="submitBtn" <?= ($hasInsufficientResidency || $hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        <?php if (!$isWithinTimeGate): ?>
                            Outside Operating Hours (8AM-5PM)
                        <?php elseif ($hasInsufficientResidency): ?>
                            Insufficient Residency Period
                        <?php elseif ($hasPendingBlotter): ?>
                            Restricted - Pending Blotter Case
                        <?php else: ?>
                            Submit Request
                        <?php endif; ?>
                    </button>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Hidden canvas for camera capture -->
    <canvas id="cameraCanvas" style="display: none;"></canvas>

    <script>
    // Use barangayPrices in JS
    var barangayPrices = <?= json_encode($barangayPrices) ?>;
    // Time gate enabled
    var isWithinTimeGateJS = <?= json_encode($isWithinTimeGate) ?>;
    var hasPendingBlotterJS = <?= json_encode($hasPendingBlotter) ?>;
    var hasInsufficientResidencyJS = <?= json_encode($hasInsufficientResidency) ?>;

    // Enhanced photo upload functions
    function openCamera() {
        // Check all validation restrictions
        if (hasInsufficientResidencyJS || hasPendingBlotterJS || !isWithinTimeGateJS) {
            Swal.fire('Error', 'Camera function is disabled due to validation restrictions.', 'error');
            return;
        }
        
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    width: { ideal: 640 }, 
                    height: { ideal: 480 },
                    facingMode: 'user' // Front camera for selfies
                } 
            })
            .then(function(stream) {
                Swal.fire({
                    title: 'Take Your Photo',
                    html: `
                        <div style="text-align: center;">
                            <video id="cameraVideo" style="width: 100%; max-width: 400px; border-radius: 8px;" autoplay playsinline></video>
                            <canvas id="captureCanvas" style="display: none;"></canvas>
                            <p style="margin: 1rem 0; color: #666; font-size: 0.9rem;">
                                Position yourself in the frame and click "Capture Photo"
                            </p>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-camera"></i> Capture Photo',
                    cancelButtonText: '<i class="fas fa-times"></i> Cancel',
                    customClass: {
                        popup: 'camera-popup'
                    },
                    didOpen: () => {
                        const video = document.getElementById('cameraVideo');
                        video.srcObject = stream;
                    },
                    preConfirm: () => {
                        const video = document.getElementById('cameraVideo');
                        const canvas = document.getElementById('captureCanvas');
                        
                        if (video.videoWidth === 0 || video.videoHeight === 0) {
                            Swal.showValidationMessage('Camera not ready. Please wait a moment.');
                            return false;
                        }
                        
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0);
                        return canvas.toDataURL('image/jpeg', 0.8);
                    },
                    willClose: () => {
                        stream.getTracks().forEach(track => track.stop());
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        displayCapturedPhoto(result.value);
                        Swal.fire({
                            title: 'Photo Captured!',
                            text: 'Your photo has been successfully captured.',
                            icon: 'success',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                });
            })
            .catch(function(err) {
                console.error('Camera error:', err);
                Swal.fire('Camera Error', 'Unable to access camera. Please choose a file instead or check your camera permissions.', 'error');
            });
        } else {
            Swal.fire('Not Supported', 'Camera is not supported on this device. Please choose a file instead.', 'error');
        }
    }

    function displayCapturedPhoto(dataUrl) {
        // Convert data URL to blob
        fetch(dataUrl)
            .then(res => res.blob())
            .then(blob => {
                // Create a file from blob
                const file = new File([blob], "indigency_photo_" + Date.now() + ".jpg", { type: "image/jpeg" });
                
                // Create a DataTransfer object to set file input
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('userPhoto').files = dataTransfer.files;
                
                // Display preview
                const previewImage = document.getElementById('previewImage');
                const photoPreview = document.getElementById('photoPreview');
                const photoUploadContainer = document.getElementById('photoUploadContainer');
                
                if (previewImage && photoPreview && photoUploadContainer) {
                    previewImage.src = dataUrl;
                    photoPreview.style.display = 'block';
                    photoUploadContainer.classList.add('active');
                }
            })
            .catch(err => {
                console.error('Error processing captured photo:', err);
                Swal.fire('Error', 'Failed to process captured photo. Please try again.', 'error');
            });
    }

    function removePhoto() {
        const userPhoto = document.getElementById('userPhoto');
        const photoPreview = document.getElementById('photoPreview');
        const photoUploadContainer = document.getElementById('photoUploadContainer');
        
        if (userPhoto) userPhoto.value = '';
        if (photoPreview) photoPreview.style.display = 'none';
        if (photoUploadContainer) photoUploadContainer.classList.remove('active');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const documentTypeSelect = document.getElementById('documentType');
        const feeAmountElement = document.getElementById('feeAmount');
        const clearanceFields = document.getElementById('clearanceFields');
        const residencyFields = document.getElementById('residencyFields');
        const indigencyFields = document.getElementById('indigencyFields');
        const cedulaFields = document.getElementById('cedulaFields');
        const businessPermitFields = document.getElementById('businessPermitFields');
        const firstTimeJobSeekerFields = document.getElementById('firstTimeJobSeekerFields');
        const cedulaNote = document.querySelector('.cedula-note');
        const form = document.getElementById('docRequestForm');
        const submitBtn = document.getElementById('submitBtn');
        const userPhotoInput = document.getElementById('userPhoto');

        // Handle file selection
        if (userPhotoInput) {
            userPhotoInput.addEventListener('change', function(e) {
                // Check all validation restrictions
                if (hasInsufficientResidencyJS || hasPendingBlotterJS || !isWithinTimeGateJS) {
                    this.value = '';
                    Swal.fire('Error', 'File upload is disabled due to validation restrictions.', 'error');
                    return;
                }
                
                const file = e.target.files[0];
                if (file) {
                    // Validate file size (5MB max)
                    if (file.size > 5 * 1024 * 1024) {
                        Swal.fire('File Too Large', 'File size must be less than 5MB. Please choose a smaller image.', 'error');
                        this.value = '';
                        return;
                    }
                    
                    // Validate file type
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
                    if (!allowedTypes.includes(file.type)) {
                        Swal.fire('Invalid File Type', 'Please select a JPG or PNG image file only.', 'error');
                        this.value = '';
                        return;
                    }
                    
                    // Display preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const previewImage = document.getElementById('previewImage');
                        const photoPreview = document.getElementById('photoPreview');
                        const photoUploadContainer = document.getElementById('photoUploadContainer');
                        
                        if (previewImage && photoPreview && photoUploadContainer) {
                            previewImage.src = e.target.result;
                            photoPreview.style.display = 'block';
                            photoUploadContainer.classList.add('active');
                            
                            Swal.fire({
                                title: 'Photo Uploaded!',
                                text: 'Your photo has been successfully uploaded.',
                                icon: 'success',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        }
                    };
                    reader.onerror = function() {
                        Swal.fire('Upload Error', 'Failed to read the selected file. Please try again.', 'error');
                        userPhotoInput.value = '';
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        function hideAllDocumentFields() {
            const allFields = document.querySelectorAll('.document-fields');
            allFields.forEach(field => {
                field.style.display = 'none';
            });
            
            // Hide cedula note
            if (cedulaNote) {
                cedulaNote.style.display = 'none';
            }
            
            // Remove required attribute from ALL form inputs
            const allInputs = document.querySelectorAll('#docRequestForm input[type="text"], #docRequestForm input[type="number"], #docRequestForm input[type="file"]');
            allInputs.forEach(input => {
                input.required = false;
            });
            
            // Reset photo upload
            removePhoto();
        }

        function setFieldsRequired(container, documentCode) {
            if (!container) return;
            
            // Check all validation restrictions
            if (hasInsufficientResidencyJS || hasPendingBlotterJS || !isWithinTimeGateJS) {
                return;
            }

            // Set required fields based on document type
            switch(documentCode) {
                case 'barangay_clearance':
                    const purposeClearance = document.getElementById('purposeClearance');
                    if (purposeClearance) purposeClearance.required = true;
                    break;

                case 'proof_of_residency':
                    const residencyDuration = document.getElementById('residencyDuration');
                    const residencyPurpose = document.getElementById('residencyPurpose');
                    if (residencyDuration) residencyDuration.required = true;
                    if (residencyPurpose) residencyPurpose.required = true;
                    break;

                case 'barangay_indigency':
                    const indigencyReason = document.getElementById('indigencyReason');
                    const userPhoto = document.getElementById('userPhoto');
                    if (indigencyReason) indigencyReason.required = true;
                    if (userPhoto) userPhoto.required = true;
                    break;

                case 'cedula':
                    const cedulaOccupation = document.getElementById('cedulaOccupation');
                    const cedulaIncome = document.getElementById('cedulaIncome');
                    if (cedulaOccupation) cedulaOccupation.required = true;
                    if (cedulaIncome) cedulaIncome.required = true;
                    break;

                case 'business_permit_clearance':
                    const businessName = document.getElementById('businessName');
                    const businessType = document.getElementById('businessType');
                    const businessAddress = document.getElementById('businessAddress');
                    const businessPurpose = document.getElementById('businessPurpose');
                    
                    // Enable and set required fields
                    [businessName, businessType, businessAddress, businessPurpose].forEach(field => {
                        if (field) {
                            field.required = true;
                            field.disabled = false;
                            
                            // Add input event listener for validation
                            field.addEventListener('input', function() {
                                if (!this.value.trim()) {
                                    this.setCustomValidity('This field is required');
                                } else {
                                    this.setCustomValidity('');
                                }
                            });
                        }
                    });
                    break;

                case 'first_time_job_seeker':
                    const jobSeekerPurpose = document.getElementById('jobSeekerPurpose');
                    if (jobSeekerPurpose) jobSeekerPurpose.required = true;
                    break;
            }
        }

        if (documentTypeSelect) {
            documentTypeSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const documentCode = selectedOption.dataset.code || '';
                
                // Update fee label using barangayPrices
                if (feeAmountElement) {
                    const fee = barangayPrices[documentCode] !== undefined ? barangayPrices[documentCode] : 0;
                    feeAmountElement.textContent = fee > 0 ? `₱${parseFloat(fee).toFixed(2)}` : 'Free';
                }
                
                hideAllDocumentFields();
                
                // Show appropriate fields and set required fields based on document type
                switch(documentCode) {
                    case 'barangay_clearance':
                        clearanceFields.style.display = 'block';
                        setFieldsRequired(clearanceFields, documentCode);
                        break;
                    case 'proof_of_residency':
                        residencyFields.style.display = 'block';
                        setFieldsRequired(residencyFields, documentCode);
                        break;
                    case 'barangay_indigency':
                        indigencyFields.style.display = 'block';
                        setFieldsRequired(indigencyFields, documentCode);
                        break;
                    case 'cedula':
                        cedulaFields.style.display = 'block';
                        if (cedulaNote) cedulaNote.style.display = 'block';
                        setFieldsRequired(cedulaFields, documentCode);
                        break;
                    case 'business_permit_clearance':
                        businessPermitFields.style.display = 'block';
                        setFieldsRequired(businessPermitFields, documentCode);
                        break;
                    case 'first_time_job_seeker':
                        firstTimeJobSeekerFields.style.display = 'block';
                        setFieldsRequired(firstTimeJobSeekerFields, documentCode);
                        break;
                }
            });
        }

        // Form submission handler
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                if (!form.checkValidity()) {
                    return; // Let browser handle validation errors
                }

                e.preventDefault(); // Prevent immediate submission

                // Time gate check enabled
                if (!isWithinTimeGateJS) {
                    Swal.fire({
                        title: 'Outside Operating Hours',
                        text: 'Document requests can only be submitted between 8:00 AM and 5:00 PM.',
                        icon: 'error'
                    });
                    return;
                }

                if (hasPendingBlotterJS) {
                    Swal.fire({
                        title: 'Request Restricted',
                        text: 'You have pending blotter case(s). Document requests are not allowed until your case(s) are resolved.',
                        icon: 'error'
                    });
                    return;
                }

                if (hasInsufficientResidencyJS) {
                    Swal.fire({
                        title: 'Insufficient Residency',
                        text: 'You need to be a resident for at least 6 months to request documents. Please check your residency information or contact the barangay office.',
                        icon: 'error'
                    });
                    return;
                }

                <?php if ($hasPendingRequest): ?>
                Swal.fire({
                    title: 'Pending Request Warning',
                    text: 'You have pending document requests. Are you sure you want to submit a new request?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, submit anyway',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitForm();
                    }
                });
                <?php else: ?>
                Swal.fire({
                    title: 'Confirm Submission',
                    text: 'Are you sure you want to submit this document request?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, submit',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitForm();
                    }
                });
                <?php endif; ?>
            });
        }

        function submitForm() {
            // Update button state
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            form.submit();

            // Re-enable button after timeout if something goes wrong
            setTimeout(function() {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Request';
                }
            }, 15000);
        }

        // Auto-select document type if provided in URL (?documentType=code)
        const urlParams = new URLSearchParams(window.location.search);
        const docTypeParam = urlParams.get('documentType');
        if (docTypeParam && documentTypeSelect) {
            for (let i = 0; i < documentTypeSelect.options.length; i++) {
                if (documentTypeSelect.options[i].dataset.code === docTypeParam) {
                    documentTypeSelect.selectedIndex = i;
                    documentTypeSelect.dispatchEvent(new Event('change'));
                    break;
                }
            }
        }

        // Initial check for submit button state based on validation conditions
        // Time gate check enabled
        if (hasPendingBlotterJS || hasInsufficientResidencyJS || !isWithinTimeGateJS) {
            if (submitBtn) {
                submitBtn.disabled = true;
                if (!isWithinTimeGateJS) {
                    submitBtn.textContent = 'Outside Operating Hours (8AM-5PM)';
                } else if (hasInsufficientResidencyJS) {
                    submitBtn.textContent = 'Insufficient Residency Period';
                } else if (hasPendingBlotterJS) {
                    submitBtn.textContent = 'Restricted - Pending Blotter Case';
                }
            }
        }
    });
    </script>
    
    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>
</body>
</html>