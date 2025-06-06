<?php
session_start();
require_once "../config/dbconn.php";
require_once '../config/paymongo.php';
// Check for pending requests - UPDATED to use new table structure
$hasPendingRequest = false;
$pendingRequests = [];
$hasPendingBlotter = false;
$pendingBlotterCases = [];
$hasInsufficientResidency = false;
$residencyDetails = [];
$canRequestDocuments = true; // New flag
if (!isset($_SESSION['barangay_id']) && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT barangay_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userBarangay = $stmt->fetch();
    if ($userBarangay) {
        $_SESSION['barangay_id'] = $userBarangay['barangay_id'];
    }
}
$barangay_id = $_SESSION['barangay_id'] ?? 1;

// Fetch person_id for the logged-in user
$person_id = null;
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['person_id'])) {
        $person_id = $_SESSION['person_id'];
    } else {
        $stmtPerson = $pdo->prepare("SELECT id FROM persons WHERE user_id = ? AND is_archived = FALSE LIMIT 1");
        $stmtPerson->execute([$_SESSION['user_id']]);
        $person = $stmtPerson->fetch();
        if ($person) {
            $_SESSION['person_id'] = $person['id'];
            $person_id = $person['id'];
        }
    }
}


// Check if First Time Job Seeker certificate can be availed
$canAvailFirstTimeJobSeeker = true;
if ($person_id) {
    $stmtFtjs = $pdo->prepare("SELECT 1 FROM document_request_restrictions WHERE person_id = ? AND document_type_code = 'first_time_job_seeker' LIMIT 1");
    $stmtFtjs->execute([$person_id]);
    if ($stmtFtjs->fetch()) {
        $canAvailFirstTimeJobSeeker = false;
    }
} else {
    // If no person_id, user likely can't request documents anyway, or this is a guest view.
    // Default to true, but JS should ideally prevent submission if no person_id for FTJS.
    // Or, more robustly, hide FTJS if no person_id. For now, this matches existing.
}

// Check if Cedula can be availed for the current year (for requesting a new Cedula)
$canAvailCedula = true;
if ($person_id) {
    $stmtCedulaAvail = $pdo->prepare("
        SELECT 1 
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.person_id = ? 
          AND dt.code = 'cedula' 
          AND YEAR(dr.request_date) = YEAR(CURDATE()) 
          AND dr.status = 'completed' 
        LIMIT 1
    ");
    $stmtCedulaAvail->execute([$person_id]);
    if ($stmtCedulaAvail->fetch()) {
        $canAvailCedula = false; // Cannot request a new one if already completed this year
    }
}

// Check if user has a completed Cedula for current year (any barangay) - for Barangay Clearance eligibility
$hasCompletedCedulaThisYear = false;
if ($person_id) {
    $stmtCedulaCheck = $pdo->prepare("
        SELECT 1 
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.person_id = ? 
          AND dt.code = 'cedula' 
          AND YEAR(dr.request_date) = YEAR(CURDATE()) 
          AND dr.status = 'completed'
        LIMIT 1
    ");
    $stmtCedulaCheck->execute([$person_id]);
    if ($stmtCedulaCheck->fetch()) {
        $hasCompletedCedulaThisYear = true;
    }
}

// Prepare Barangay Clearance eligibility message based on Cedula status
$barangayClearanceEligibilityMessage = '';
$barangayClearanceEligible = false;
if ($hasCompletedCedulaThisYear) {
    $barangayClearanceEligibilityMessage = "You have a completed Cedula for the current year. You are eligible to request a Barangay Clearance.";
    $barangayClearanceEligible = true;
} else {
    $barangayClearanceEligibilityMessage = "A completed Cedula for the current year is required to request a Barangay Clearance. Please obtain your Cedula first.";
    $barangayClearanceEligible = false;
}


$paymongoAvailable = isPayMongoAvailable($barangay_id);

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

    // Residency check: must have at least 6 months residency
    if (isset($_SESSION['user_id'])) {
        // Still fetch residency data but don't use it for validation
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
                    WHEN p.years_of_residency >= 1 THEN p.years_of_residency * 12
                    WHEN a.years_in_san_rafael >= 1 THEN a.years_in_san_rafael * 12
                    ELSE TIMESTAMPDIFF(MONTH, a.created_at, NOW())
                END as computed_months
            FROM persons p
            LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
            WHERE p.user_id = ? AND a.barangay_id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $barangay_id]);
        $residencyDetails = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($residencyDetails) {
            $months = (int)$residencyDetails['computed_months'];
            if ($months < 6) {
                $hasInsufficientResidency = true;
                $canRequestDocuments = false;
            }
        } else {
            $hasInsufficientResidency = true;
            $canRequestDocuments = false;
        }
    }

    // Blotter check: allow only if all cases are closed or dismissed
    $hasActiveBlotter = false;
    $activeBlotterCases = [];
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("
            SELECT bc.id, bc.case_number, bc.status, b.name as barangay_name, bc.description,
                   bc.incident_date, bp.role
            FROM blotter_cases bc
            JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
            JOIN persons p ON bp.person_id = p.id
            JOIN barangay b ON bc.barangay_id = b.id
            WHERE p.user_id = ?
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $allBlotterCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allBlotterCases as $case) {
            if (!in_array(strtolower($case['status']), ['closed', 'dismissed'])) {
                $hasActiveBlotter = true;
                $activeBlotterCases[] = $case;
            }
        }
        if ($hasActiveBlotter) {
            $canRequestDocuments = false;
        }
    }

    // Still show the form and pending requests section, but indicate restrictions
}

// Handle form submission for document requests - UPDATED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if user has pending blotter cases in ANY barangay
        if ($hasPendingBlotter) {
            $caseDetails = [];
            foreach ($pendingBlotterCases as $case) {
                $caseDetails[] = "Case #" . $case['case_number'] . " in " . $case['barangay_name'];
            }
            throw new Exception("You have pending blotter case(s): " . implode(", ", $caseDetails) . ". Document requests are not allowed until your case(s) are resolved.");
        }

        // Remove residency check - commenting out this block
        /*
        if ($hasInsufficientResidency) {
            if ($residencyDetails['residency_status'] === 'no_record') {
                throw new Exception("You need to be a resident for at least 6 months to request documents. Please contact the barangay office to update your residency information.");
            } else {
                $monthsLived = $residencyDetails['computed_months'];
                $monthsNeeded = 6 - $monthsLived;
                throw new Exception("Insufficient residency period. You need to be a resident for at least 6 months to request documents. You currently have {$monthsLived} month(s) of recorded residency. Please wait {$monthsNeeded} more month(s) or contact the barangay office if this is incorrect.");
            }
        }
        */

        // Check if user has pending requests - REMOVED THIS CHECK
        /*
        if ($hasPendingRequest && !isset($_POST['override_pending'])) {
            throw new Exception("You have pending document requests. Please wait for them to be processed before submitting new requests.");
        }
        */

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

        // Get user information and person record
        $stmt = $pdo->prepare("
            SELECT u.first_name, u.last_name, u.gender, u.id, p.id as person_id
            FROM users u 
            LEFT JOIN persons p ON u.id = p.user_id
            WHERE u.id = ?
        ");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();
        
        if (!$user_info) {
            throw new Exception("User not found. Please log in again.");
        }

        // If no person record exists, create one
        $person_id = $user_info['person_id'];
        if (!$person_id) {
            $stmt = $pdo->prepare("
                INSERT INTO persons (user_id, first_name, last_name, birth_date, birth_place, gender, civil_status, citizenship)
                VALUES (?, ?, ?, '1990-01-01', 'Unknown', ?, 'SINGLE', 'Filipino')
            ");
            $stmt->execute([
                $user_id,
                $user_info['first_name'],
                $user_info['last_name'],
                strtoupper($user_info['gender'])
            ]);
            $person_id = $pdo->lastInsertId();
        }

        // --- FTJS LOGIC START ---
        $ftjsAvailed = isset($_POST['ftjs_availed']) && $_POST['ftjs_availed'] === 'on';
        $jobSeekerPurposeFtjs = trim($_POST['job_seeker_purpose_ftjs'] ?? '');

        // If FTJS is checked and document type is barangay_clearance, treat as first_time_job_seeker
        $isBarangayClearance = false;
        $isFTJS = false;
        $originalDocumentTypeId = $documentTypeId;
        $ftjsDocumentTypeId = null;

        if ($ftjsAvailed) {
            // Get the document_type_id for 'first_time_job_seeker'
            $stmt = $pdo->prepare("SELECT id FROM document_types WHERE code = 'first_time_job_seeker' LIMIT 1");
            $stmt->execute();
            $ftjsType = $stmt->fetch();
            if (!$ftjsType) {
                throw new Exception("First Time Job Seeker document type not found.");
            }
            $ftjsDocumentTypeId = $ftjsType['id'];

            // Only allow FTJS if selected document is barangay_clearance
            $stmt = $pdo->prepare("SELECT code FROM document_types WHERE id = ?");
            $stmt->execute([$documentTypeId]);
            $selectedDoc = $stmt->fetch();
            if ($selectedDoc && $selectedDoc['code'] === 'barangay_clearance') {
                $isBarangayClearance = true;
                $isFTJS = true;
                $documentTypeId = $ftjsDocumentTypeId;
            }
        }
        // --- FTJS LOGIC END ---

        // Get document type info for determining price and validation
        $stmt = $pdo->prepare("
            SELECT dt.*, COALESCE(bdp.price, dt.default_fee) as final_price
            FROM document_types dt
            LEFT JOIN barangay_document_prices bdp ON bdp.document_type_id = dt.id 
                AND bdp.barangay_id = ?
            WHERE dt.id = ?
        ");
        $stmt->execute([$barangay_id, $documentTypeId]);
        $documentType = $stmt->fetch();

        if (!$documentType) {
            throw new Exception("Invalid document type selected");
        }

        // Check First Time Job Seeker restriction using simple query
        if ($documentType['code'] === 'first_time_job_seeker') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as existing_count
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                WHERE dr.person_id = ? 
                AND dt.code = 'first_time_job_seeker'
                AND dr.status NOT IN ('rejected', 'cancelled')
            ");
            $stmt->execute([$person_id]);
            $result = $stmt->fetch();
            
            if ($result['existing_count'] > 0) {
                throw new Exception('First Time Job Seeker certificate can only be requested once per person.');
            }
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

            // Prepare the main insert statement - FIXED VERSION
            $stmt = $pdo->prepare("
                INSERT INTO document_requests (
                    person_id, user_id, document_type_id, barangay_id, 
                    requested_by_user_id, status, request_date, price,
                    purpose, proof_image_path,
                    business_name, business_location, business_nature, business_type,
                    delivery_method, payment_method
                ) VALUES (
                    ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            // Prepare values based on document type
            $purpose = '';
            $businessName = null;
            $businessLocation = null;
            $businessNature = null;
            $businessType = null;

            // Set document-specific values
            if ($isFTJS) {
                // FTJS: Use FTJS purpose, price is always 0
                $purpose = $jobSeekerPurposeFtjs;
                $finalPrice = 0;
            } else {
                switch($documentType['code']) {
                    case 'barangay_clearance':
                        $purpose = $_POST['purposeClearance'] ?? '';
                        $finalPrice = $documentType['final_price'];
                        break;
                    case 'proof_of_residency':
                        $purpose = 'Duration: ' . ($_POST['residencyDuration'] ?? '') . 
                                   '; Purpose: ' . ($_POST['residencyPurpose'] ?? '');
                        $finalPrice = $documentType['final_price'];
                        break;
                    case 'barangay_indigency':
                        $purpose = $_POST['indigencyReason'] ?? '';
                        $finalPrice = $documentType['final_price'];
                        break;
                    case 'business_permit_clearance':
                        $businessName = $_POST['businessName'] ?? '';
                        $businessLocation = $_POST['businessAddress'] ?? '';
                        $businessNature = $_POST['businessPurpose'] ?? '';
                        $businessType = $_POST['businessType'] ?? '';
                        $purpose = 'Business Permit Application';
                        $finalPrice = $documentType['final_price'];
                        
                        // Validate required business fields
                        if (empty($businessName) || empty($businessLocation) || empty($businessNature) || empty($businessType)) {
                            throw new Exception("All business information fields are required for Business Permit Clearance.");
                        }
                        break;
                    case 'first_time_job_seeker':
                        $purpose = $_POST['jobSeekerPurpose'] ?? '';
                        $finalPrice = 0;
                        break;
                    case 'cedula':
                        $purpose = 'Community Tax Certificate';
                        $finalPrice = $documentType['final_price'];
                        break;
                    default:
                        $purpose = 'General purposes';
                        $finalPrice = $documentType['final_price'];
                        break;
                }
            }

            // Execute the insert
            $stmt->execute([
                $person_id,
                $user_id,
                $documentTypeId,
                $barangay_id,
                $user_id,
                $isFTJS ? 0 : $finalPrice, // Price is 0 for FTJS
                $purpose,
                $imagePath,
                $businessName,
                $businessLocation,
                $businessNature,
                $businessType,
                implode(',', $_POST['delivery_method'] ?? []), // Handle multiple delivery methods
                isset($_POST['proceed_to_payment']) ? 'online' : ($_POST['payment_method'] ?? 'cash')
            ]);

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
    $barangayPrices[$doc['code']] = (float)$doc['price']; 
} // Added missing closing curly brace here

$paymongoAvailable = isPayMongoAvailable($barangay_id);


$selectedDocumentType = $_GET['documentType'] ?? '';
$showPending = isset($_GET['show_pending']) || isset($_SESSION['show_pending']);
unset($_SESSION['show_pending']);

// Always allow submissions (time gate disabled)
$isWithinTimeGate = true;

// Get PayMongo availability for this barangay - FIXED
require_once '../config/paymongo.php';
$paymongoAvailable = isPayMongoAvailable($barangay_id);
// Debug output to verify PayMongo availability
error_log("PayMongo available for barangay #$barangay_id: " . ($paymongoAvailable ? 'Yes' : 'No'));

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
    /* [Previous CSS styles remain the same] */
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

    .swal2-container {
        z-index: 9999 !important;
    }

    .wizard-section {
        padding-bottom: 2rem;
    }

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

    .first-time-job-seeker-warning {
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

    .first-time-job-seeker-warning i {
        font-size: 1.2rem;
    }

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

    .camera-popup {
        border-radius: 15px !important;
    }

    .camera-popup .swal2-html-container {
        margin: 1rem 0 !important;
    }

    /* Style for Cedula eligibility message */
    .cedula-eligibility-notice {
        padding: 0.75rem 1.25rem;
        margin-bottom: 1rem;
        border: 1px solid transparent;
        border-radius: 0.25rem;
    }
    .cedula-eligible {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    .cedula-ineligible {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
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
        <?php if (!$canRequestDocuments): ?>
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                <?php if ($hasInsufficientResidency): ?>
                    <div class="residency-warning">
                        <h4><i class="fas fa-home"></i> Residency Requirement Not Met</h4>
                        <p>
                            You must be a resident of the barangay for at least <strong>6 months</strong> to request documents.<br>
                            <?php if ($residencyDetails): ?>
                                Current residency period: <strong><?= (int)$residencyDetails['computed_months'] ?> month(s)</strong>
                            <?php else: ?>
                                No residency record found.
                            <?php endif; ?>
                        </p>
                        <p>
                            Please contact the barangay office to update your residency information if you believe this is incorrect.
                        </p>
                    </div>
                <?php endif; ?>
                <?php if ($hasActiveBlotter): ?>
                    <div class="blotter-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p><strong>Document Request Restricted</strong></p>
                        <p>You currently have active blotter case(s) (not closed or dismissed):</p>
                        <ul>
                            <?php foreach ($activeBlotterCases as $case): ?>
                            <li>Case #<?= htmlspecialchars($case['case_number']) ?> in <?= htmlspecialchars($case['barangay_name']) ?>
                                <br>
                                <small>Status: <?= ucfirst($case['status']) ?> | Role: <?= ucfirst($case['role']) ?></small>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <p>Document requests are not allowed until your case(s) are closed or dismissed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        <?php else: ?>
        
        <section class="wizard-section">
            <div class="wizard-container">
                <h2 class="form-header">Document Request</h2>
                
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

                <?php /* Remove residency warning entirely - we're hiding this section
                if ($hasInsufficientResidency): ?>
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
                <?php endif; */ ?>
                
                <form method="POST" action="../functions/services.php" enctype="multipart/form-data" id="docRequestForm">
                    <div class="form-row">
                        <label for="documentType">Document Type</label>
                        <select id="documentType" name="document_type_id" required <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                            <option value="">Select Document</option>
                            <?php
                            // Always show all 5 supported documents in the correct order (FTJS is now a checkbox)
                            $requiredDocs = [
                                'barangay_clearance',
                                'barangay_indigency',
                                'business_permit_clearance',
                                'cedula',
                                'proof_of_residency'
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

                    <!-- Cedula Eligibility Notice for Barangay Clearance -->
                    <div id="cedulaEligibilityNotice" class="cedula-eligibility-notice" style="display: none;">
                        <?= htmlspecialchars($barangayClearanceEligibilityMessage) ?>
                    </div>

                    <!-- FTJS Checkbox Row - Initially Hidden, shown for Barangay Clearance if eligible -->
                    <div class="form-row" id="ftjsCheckboxRow" style="display: none;">
                        <div style="display: flex; align-items: center;">
                            <input type="checkbox" id="ftjsCheckbox" name="ftjs_availed" style="width: auto; margin-right: 10px;">
                            <label for="ftjsCheckbox" style="margin-bottom: 0; font-weight: normal;">Avail as First Time Job Seeker (RA 11261) - Free Barangay Clearance</label>
                        </div>
                        <small class="input-help">Checking this will make the Barangay Clearance free. This benefit can only be availed once.</small>
                    </div>

                    <!-- FTJS Purpose - Initially Hidden, shown if FTJS checkbox is checked -->
                    <div class="form-row" id="ftjsPurposeContainer" style="display: none;">
                        <label for="jobSeekerPurposeFtjs">Purpose for First Time Job Seeker <span style="color: red;">*</span></label>
                        <input type="text" id="jobSeekerPurposeFtjs" name="job_seeker_purpose_ftjs" placeholder="Enter where you will use this certificate (e.g., Company name, Job application)" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                    </div>

                    <!-- Document price/fee label -->
                    <div class="form-row">
                        <label>Document Fee:</label>
                        <span id="feeAmount" style="font-weight:bold;">₱0.00</span>
                    </div>

                    <!-- Delivery Method Selection -->
                    <div class="form-row" id="deliveryMethodRow">
                        <label for="deliveryMethod">Delivery Method <span style="color: red">*</span></label>
                        <div>
                            <label><input type="checkbox" name="delivery_method[]" value="hardcopy" id="deliveryHardcopy"> Hardcopy</label>
                            <label style="margin-left:1rem;"><input type="checkbox" name="delivery_method[]" value="softcopy" id="deliverySoftcopy"> Softcopy</label>
                        </div>
                        <span class="input-help">You may select one or both delivery options.</span>
                    </div>

                    <!-- Payment Method Selection -->
                    <div class="form-row" id="paymentMethodRow" style="display: none;">
                        <label for="paymentMethod">Payment Method *</label>
                        <select id="paymentMethod" name="payment_method" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                            <option value="">Select Payment Method</option>
                            <option value="cash">Cash (Pay at Barangay Office)</option>
                            <?php if ($paymongoAvailable): ?>
                                <option value="online">Online Payment (PayMongo)</option>
                            <?php endif; ?>
                        </select>
                        <small class="input-help">
                            Cash payment requires payment confirmation at the barangay office.
                            <?php if (!$paymongoAvailable): ?>
                                <br><em>Note: Online payment is not available for this barangay.</em>
                            <?php endif; ?>
                        </small>
                    </div>

                    <!-- Payment Info Display -->
                    <div id="paymentInfo" class="document-info" style="display: none;">
                        <h4>Payment Information</h4>
                        <p id="paymentInfoText">Select payment method to see details</p>
                    </div>

                    <!-- First Time Job Seeker Warning (General - can be kept or removed if checkbox help text is enough) -->
                    <div id="firstTimeJobSeekerWarning" class="first-time-job-seeker-warning" style="display: none;">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Important:</strong> First Time Job Seeker status (for free Barangay Clearance) can only be availed once.
                        </div>
                    </div>

                    <!-- Document-specific fields -->
                    <div id="clearanceFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="purposeClearance">Purpose of Clearance <span id="purposeClearanceRequiredAst" style="color: red;">*</span></label>
                            <select id="purposeClearance" name="purposeClearance" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                <option value="">Select Purpose</option>
                                <option value="Employment">Employment</option>
                                <option value="ID Application">ID Application</option>
                                <option value="Loan Application">Loan Application</option>
                                <option value="Proof of Residency">Proof of Residency</option>
                                <option value="Travel/Visa Application">Travel/Visa Application</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" id="purposeClearanceOther" name="purposeClearanceOther" placeholder="Please specify other purpose" style="display: none; margin-top: 10px;" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div id="residencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="residencyDuration">Duration of Residency</label>
                            <input type="text" id="residencyDuration" name="residencyDuration" placeholder="e.g., 5 years" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="residencyPurpose">Purpose</label>
                            <select id="residencyPurpose" name="residencyPurpose" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                <option value="">Select Purpose</option>
                                <option value="School Enrollment">School Enrollment</option>
                                <option value="Scholarship Application">Scholarship Application</option>
                                <option value="Bank Account Opening">Bank Account Opening</option>
                                <option value="Proof of Address">Proof of Address</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" id="residencyPurposeOther" name="residencyPurposeOther" placeholder="Please specify other purpose" style="display: none; margin-top: 10px;" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>

                    <div id="indigencyFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="indigencyReason">Reason for Requesting</label>
                            <select id="indigencyReason" name="indigencyReason" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                <option value="">Select Reason</option>
                                <option value="Medical Assistance">Medical Assistance</option>
                                <option value="Educational Assistance">Educational Assistance</option>
                                <option value="Financial Assistance">Financial Assistance</option>
                                <option value="Hospitalization">Hospitalization</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" id="indigencyReasonOther" name="indigencyReasonOther" placeholder="Please specify other reason" style="display: none; margin-top: 10px;" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label>Your Photo <span style="color: red;">*</span></label>
                            <div class="photo-upload-container" id="photoUploadContainer">
                                <input type="file" id="userPhoto" name="userPhoto" accept="image/jpeg,image/jpg,image/png" style="display: none;" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                
                                <div class="upload-options">
                                    <button type="button" class="upload-btn" onclick="openCamera();" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                        <i class="fas fa-camera"></i> Take Photo
                                    </button>
                                </div>
                                
                                <p class="upload-hint">
                                    Upload a recent photo of yourself (JPG or PNG, max 5MB)
                                </p>
                                
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
                        <div class="cedula-note">
                            <strong>Note:</strong> Community Tax Certificate (Cedula) must be obtained in person at the Barangay Hall during office hours.
                        </div>
                    </div>

                    <div id="businessPermitFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="businessName">Business Name <span style="color: red;">*</span></label>
                            <input type="text" id="businessName" name="businessName" placeholder="Enter business name" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="businessType">Type of Business <span style="color: red;">*</span></label>
                            <select id="businessType" name="businessType" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                                <option value="">Select Type</option>
                                <option value="Retail">Retail</option>
                                <option value="Food and Beverage">Food & Beverage (Restaurant, Cafe, etc.)</option>
                                <option value="Services">Services (e.g., Salon, Repair Shop)</option>
                                <option value="Sari-sari Store">Sari-sari Store</option>
                                <option value="Online Business">Online Business</option>
                                <option value="Others">Others</option>
                            </select>
                            <input type="text" id="businessTypeOther" name="businessTypeOther" placeholder="Please specify other type of business" style="display: none; margin-top: 10px;" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="businessAddress">Business Location/Address <span style="color: red;">*</span></label>
                            <input type="text" id="businessAddress" name="businessAddress" placeholder="Enter complete business address" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                        <div class="form-row">
                            <label for="businessPurpose">Nature of Business <span style="color: red;">*</span></label>
                            <input type="text" id="businessPurpose" name="businessPurpose" placeholder="Describe the nature of business operations" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                            <small class="input-help">Describe what your business does (e.g., Food Service, General Merchandise, etc.)</small>
                        </div>
                    </div>

                    <?php /* Remove this as FTJS is now a checkbox for Barangay Clearance
                    <div id="firstTimeJobSeekerFields" class="document-fields" style="display: none;">
                        <div class="form-row">
                            <label for="jobSeekerPurpose">Purpose/Institution</label>
                            <input type="text" id="jobSeekerPurpose" name="jobSeekerPurpose" placeholder="Enter where you will use this certificate (e.g., Company name, Job application, etc.)" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    */ ?>

                    <?php /* if ($hasPendingRequest): ?> // This was already commented out
                    <input type="hidden" name="override_pending" value="1">
                    <?php endif; */ ?>

                    <button type="submit" class="btn cta-button" id="submitBtn" <?= ($hasPendingBlotter || !$isWithinTimeGate) ? 'disabled' : '' ?>>
                        <?php if (!$isWithinTimeGate): ?>
                            Outside Operating Hours (8AM-5PM)
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

    <canvas id="cameraCanvas" style="display: none;"></canvas>

    <script>
    var barangayPrices = <?= json_encode($barangayPrices, JSON_NUMERIC_CHECK) ?>;
    var paymongoAvailable = <?= $paymongoAvailable ? 'true' : 'false' ?>;

    var canAvailFirstTimeJobSeekerJS = <?= json_encode($canAvailFirstTimeJobSeeker) ?>;
    var hasCompletedCedulaThisYearJS = <?= json_encode($hasCompletedCedulaThisYear) ?>;
    var barangayClearanceEligibleJS = <?= json_encode($barangayClearanceEligible) ?>;

    var hasInsufficientResidencyJS = false; // Always set to false to bypass validation
    var hasPendingBlotterJS = <?= json_encode($hasPendingBlotter) ?>;
    var isWithinTimeGateJS = <?= json_encode($isWithinTimeGate) ?>;
    
    function openCamera() {
        if (hasPendingBlotterJS || !isWithinTimeGateJS) {
            Swal.fire('Error', 'Camera function is disabled due to validation restrictions.', 'error');
            return;
        }
        
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    width: { ideal: 640 }, 
                    height: { ideal: 480 },
                    facingMode: 'user'
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
        fetch(dataUrl)
            .then(res => res.blob())
            .then(blob => {
                const file = new File([blob], "indigency_photo_" + Date.now() + ".jpg", { type: "image/jpeg" });
                
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                document.getElementById('userPhoto').files = dataTransfer.files;
                
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

    // Check for existing First Time Job Seeker requests
    async function checkFirstTimeJobSeekerEligibility(userId) {
        if (!userId) return true;
        
        try {
            const response = await fetch('../api/check_first_time_job_seeker.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ user_id: userId })
            });
            const result = await response.json();
            return result.eligible;
        } catch (error) {
            console.error('Error checking eligibility:', error);
            return true; // Allow submission if check fails
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        function setupOtherFieldListener(selectId, otherInputId) {
            const selectElement = document.getElementById(selectId);
            const otherInputElement = document.getElementById(otherInputId);

            if (selectElement && otherInputElement) {
                selectElement.addEventListener('change', function() {
                    if (this.value === 'Others') {
                        otherInputElement.style.display = 'block';
                        otherInputElement.required = true;
                    } else {
                        otherInputElement.style.display = 'none';
                        otherInputElement.required = false;
                        otherInputElement.value = '';
                    }
                });
            }
        }

        setupOtherFieldListener('purposeClearance', 'purposeClearanceOther');
        setupOtherFieldListener('residencyPurpose', 'residencyPurposeOther');
        setupOtherFieldListener('indigencyReason', 'indigencyReasonOther');
        setupOtherFieldListener('businessType', 'businessTypeOther');

        const documentTypeSelect = document.getElementById('documentType');
        const deliveryMethodSelect = document.getElementById('deliveryMethod');
        const feeAmountElement = document.getElementById('feeAmount');
        
        const clearanceFields = document.getElementById('clearanceFields');
        const purposeClearanceInput = document.getElementById('purposeClearance'); // This is a select now
        const purposeClearanceRequiredAst = document.getElementById('purposeClearanceRequiredAst');

        const residencyFields = document.getElementById('residencyFields');
        const indigencyFields = document.getElementById('indigencyFields');
        const cedulaFields = document.getElementById('cedulaFields');
        const businessPermitFields = document.getElementById('businessPermitFields');
        // const firstTimeJobSeekerFields = document.getElementById('firstTimeJobSeekerFields'); // This is removed

        const firstTimeJobSeekerWarning = document.getElementById('firstTimeJobSeekerWarning'); 
        
        // FTJS elements for Barangay Clearance
        const ftjsCheckboxRow = document.getElementById('ftjsCheckboxRow');
        const ftjsCheckbox = document.getElementById('ftjsCheckbox');
        const ftjsPurposeContainer = document.getElementById('ftjsPurposeContainer');
        const jobSeekerPurposeFtjsInput = document.getElementById('jobSeekerPurposeFtjs');
        
        const cedulaEligibilityNoticeDiv = document.getElementById('cedulaEligibilityNotice');
        
        const form = document.getElementById('docRequestForm');
        const submitBtn = document.getElementById('submitBtn');
        const userPhotoInput = document.getElementById('userPhoto');

        // Handle file selection
        if (userPhotoInput) {
            userPhotoInput.addEventListener('change', function(e) {
                if (hasPendingBlotterJS || !isWithinTimeGateJS) {
                    this.value = '';
                    Swal.fire('Error', 'File upload is disabled due to validation restrictions.', 'error');
                    return;
                }
                
                const file = e.target.files[0];
                if (file) {
                    if (file.size > 5 * 1024 * 1024) {
                        Swal.fire('File Too Large', 'File size must be less than 5MB. Please choose a smaller image.', 'error');
                        this.value = '';
                        return;
                    }
                    
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
                    if (!allowedTypes.includes(file.type)) {
                        Swal.fire('Invalid File Type', 'Please select a JPG or PNG image file only.', 'error');
                        this.value = '';
                        return;
                    }
                    
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
            
            if (firstTimeJobSeekerWarning) { 
                firstTimeJobSeekerWarning.style.display = 'none';
            }

            // Hide FTJS checkbox and purpose field for Barangay Clearance
            if (ftjsCheckboxRow) ftjsCheckboxRow.style.display = 'none';
            if (ftjsCheckbox) ftjsCheckbox.checked = false; // Uncheck it
            if (ftjsPurposeContainer) ftjsPurposeContainer.style.display = 'none';
            if (jobSeekerPurposeFtjsInput) jobSeekerPurposeFtjsInput.required = false;
            
            const purposeClearanceSelect = document.getElementById('purposeClearance');
            if (purposeClearanceSelect) purposeClearanceSelect.required = false;

            if (purposeClearanceRequiredAst) purposeClearanceRequiredAst.style.display = 'inline';


            if (cedulaEligibilityNoticeDiv) cedulaEligibilityNoticeDiv.style.display = 'none';
            
            const allInputs = document.querySelectorAll('#docRequestForm input[type="text"], #docRequestForm input[type="number"], #docRequestForm input[type="file"], #docRequestForm select');
            allInputs.forEach(input => {
                // General reset, specific requirements are set later
                if(input.id !== 'jobSeekerPurposeFtjs' && input.id !== 'purposeClearance') {
                    input.required = false;
                }
            });
            removePhoto();
        }

        function updateRequiredFields(type, required) {
            const fieldMap = {
                // 'clearance' handled by ftjsCheckbox logic now
                'residency': ['residencyDuration', 'residencyPurpose'],
                'indigency': ['indigencyReason'],
                'business': ['businessName', 'businessType', 'businessAddress', 'businessPurpose'],
                // 'first_time_job_seeker': ['jobSeekerPurpose'] // This is removed
            };

            if (type === false) { // Called by hideAllDocumentFields
                Object.values(fieldMap).flat().forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
                const purposeClearanceSelect = document.getElementById('purposeClearance');
                if (purposeClearanceSelect) purposeClearanceSelect.required = false;
                if (jobSeekerPurposeFtjsInput) jobSeekerPurposeFtjsInput.required = false;

                const deliveryMethod = document.getElementById('deliveryMethod');
                const paymentMethod = document.getElementById('paymentMethod');
                if (deliveryMethod) deliveryMethod.required = false;
                if (paymentMethod) paymentMethod.required = false;

            } else if (fieldMap[type]) {
                fieldMap[type].forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = required;
                });
            }
        }

        function updateFeeDisplay() {
            var selected = documentTypeSelect.options[documentTypeSelect.selectedIndex];
            var code = selected ? selected.dataset.code : '';
            var fee = 0;

            if (code && barangayPrices[code] !== undefined) {
                fee = parseFloat(barangayPrices[code]) || 0;
            }

            // If FTJS is checked for Barangay Clearance and user is eligible, fee is 0
            if (ftjsCheckbox && ftjsCheckbox.checked && code === 'barangay_clearance' && canAvailFirstTimeJobSeekerJS) {
                fee = 0;
            }

            if (feeAmountElement) {
                feeAmountElement.textContent = fee > 0 ? `₱${fee.toFixed(2)}` : 'Free';
            }
            
            var paymentMethodRow = document.getElementById('paymentMethodRow');
            var paymentMethodSelect = document.getElementById('paymentMethod'); // Renamed for clarity
            if (paymentMethodRow && paymentMethodSelect) {
                if (fee > 0) {
                    paymentMethodRow.style.display = 'block';
                    paymentMethodSelect.required = true;
                } else {
                    paymentMethodRow.style.display = 'none';
                    paymentMethodSelect.required = false;
                    paymentMethodSelect.value = 'cash'; // Default for free documents
                }
            }
        }

        if (documentTypeSelect) {
            documentTypeSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const documentCode = selectedOption.dataset.code || '';
                
                hideAllDocumentFields(); 
                updateRequiredFields(false); // Reset all field requirements

                // Handle FTJS checkbox visibility for Barangay Clearance
                if (documentCode === 'barangay_clearance' && canAvailFirstTimeJobSeekerJS) {
                    if (ftjsCheckboxRow) ftjsCheckboxRow.style.display = 'block';
                    // If FTJS not available, general warning might be useful
                    if (firstTimeJobSeekerWarning && !canAvailFirstTimeJobSeekerJS) {
                        // firstTimeJobSeekerWarning.style.display = 'block'; // Optional: show general warning if FTJS not available
                    }
                } else {
                    if (ftjsCheckboxRow) ftjsCheckboxRow.style.display = 'none';
                    if (ftjsCheckbox) ftjsCheckbox.checked = false; // Ensure it's unchecked
                    if (ftjsPurposeContainer) ftjsPurposeContainer.style.display = 'none';
                    if (jobSeekerPurposeFtjsInput) jobSeekerPurposeFtjsInput.required = false;
                }
                // Trigger change on ftjsCheckbox to correctly set initial purpose field requirements for clearance
                if (ftjsCheckbox) ftjsCheckbox.dispatchEvent(new Event('change'));


                if (cedulaEligibilityNoticeDiv) {
                    if (documentCode === 'barangay_clearance') {
                        cedulaEligibilityNoticeDiv.style.display = 'block';
                        cedulaEligibilityNoticeDiv.textContent = barangayClearanceEligibleJS ? 
                            "You have a completed Cedula for the current year. You are eligible to request a Barangay Clearance." :
                            "A completed Cedula for the current year is required to request a Barangay Clearance. Please obtain your Cedula first.";
                        cedulaEligibilityNoticeDiv.className = barangayClearanceEligibleJS ?
                            'cedula-eligibility-notice cedula-eligible' : 
                            'cedula-eligibility-notice cedula-ineligible';
                    }
                }
                
                switch(documentCode) {
                    case 'barangay_clearance':
                        if (clearanceFields) clearanceFields.style.display = 'block';
                        const purposeClearanceSelect = document.getElementById('purposeClearance');
                        if (purposeClearanceSelect && ftjsCheckbox && !ftjsCheckbox.checked) {
                             purposeClearanceSelect.required = true;
                             if(purposeClearanceRequiredAst) purposeClearanceRequiredAst.style.display = 'inline';
                        } else if (purposeClearanceSelect) {
                             purposeClearanceSelect.required = false;
                             if(purposeClearanceRequiredAst) purposeClearanceRequiredAst.style.display = 'none';
                        }
                        break;
                    case 'proof_of_residency':
                        if (residencyFields) residencyFields.style.display = 'block';
                        updateRequiredFields('residency', true);
                        break;
                    case 'barangay_indigency':
                        if (indigencyFields) indigencyFields.style.display = 'block';
                        updateRequiredFields('indigency', true);
                        if (userPhotoInput) userPhotoInput.required = true;
                        break;
                    case 'business_permit_clearance':
                        if (businessPermitFields) businessPermitFields.style.display = 'block';
                        updateRequiredFields('business', true);
                        break;
                    case 'cedula':
                        if (cedulaFields) cedulaFields.style.display = 'block';
                        break;
                    default:
                        // No specific fields for other types or if none selected
                        break;
                }
                
                if (deliveryMethodSelect) {
                    if (documentCode === 'cedula' || documentCode === 'community_tax_certificate') {
                        Array.from(deliveryMethodSelect.options).forEach(opt => {
                            if (opt.value.toLowerCase() === 'softcopy') {
                                opt.disabled = true;
                                opt.style.display = 'none';
                            }
                            if (opt.value.toLowerCase() === 'hardcopy') {
                                opt.disabled = false;
                                opt.style.display = '';
                            }
                        });
                        deliveryMethodSelect.value = 'hardcopy';
                    } else {
                        Array.from(deliveryMethodSelect.options).forEach(opt => {
                            opt.disabled = false;
                            opt.style.display = '';
                        });
                    }
                }
                updateFeeDisplay();
            });
        }

        if (ftjsCheckbox) {
            ftjsCheckbox.addEventListener('change', function() {
                const isBarangayClearanceSelected = documentTypeSelect.options[documentTypeSelect.selectedIndex]?.dataset.code === 'barangay_clearance';
                if (this.checked && isBarangayClearanceSelected && canAvailFirstTimeJobSeekerJS) {
                    if (ftjsPurposeContainer) ftjsPurposeContainer.style.display = 'block';
                    if (jobSeekerPurposeFtjsInput) jobSeekerPurposeFtjsInput.required = true;
                    
                    const purposeClearanceSelect = document.getElementById('purposeClearance');
                    if(purposeClearanceSelect) purposeClearanceSelect.required = false;
                    if (purposeClearanceRequiredAst) purposeClearanceRequiredAst.style.display = 'none';


                } else {
                    if (ftjsPurposeContainer) ftjsPurposeContainer.style.display = 'none';
                    if (jobSeekerPurposeFtjsInput) jobSeekerPurposeFtjsInput.required = false;
                    // If barangay clearance is selected and FTJS is unchecked, standard purpose is required
                    const purposeClearanceSelect = document.getElementById('purposeClearance');
                    if (purposeClearanceSelect && isBarangayClearanceSelected) {
                         purposeClearanceSelect.required = true;
                         if(purposeClearanceRequiredAst) purposeClearanceRequiredAst.style.display = 'inline';
                    } else if (purposeClearanceSelect) {
                         purposeClearanceSelect.required = false; // Not required if not barangay clearance
                         if(purposeClearanceRequiredAst) purposeClearanceRequiredAst.style.display = 'none';
                    }
                }
                updateFeeDisplay();
            });
        }

        // Handle payment method change
        const paymentMethodSelect = document.getElementById('paymentMethod');
        const paymentInfo = document.getElementById('paymentInfo');
        const paymentInfoText = document.getElementById('paymentInfoText');

        if (paymentMethodSelect) {
            paymentMethodSelect.addEventListener('change', function() {
                const selectedMethod = this.value;
                const selectedDoc = documentTypeSelect.options[documentTypeSelect.selectedIndex];
                const fee = barangayPrices[selectedDoc.dataset.code] || 0;
                
                if (selectedMethod && fee > 0) {
                    paymentInfo.style.display = 'block';
                    
                    if (selectedMethod === 'cash') {
                        paymentInfoText.innerHTML = `
                            <strong>Cash Payment:</strong><br>
                            • Pay ₱${fee.toFixed(2)} at the Barangay Office<br>
                            • Document will be processed after payment confirmation<br>
                            • Bring valid ID when paying
                        `;
                    } else if (selectedMethod === 'online') {
                        paymentInfoText.innerHTML = `
                            <strong>Online Payment:</strong><br>
                            • Pay ₱${fee.toFixed(2)} via PayMongo<br>
                            • You will be redirected to secure payment page<br>
                            • Document processing starts after successful payment<br>
                            • Payment confirmation will be sent via email
                        `;
                    }
                } else {
                    paymentInfo.style.display = 'none';
                }
            });
        }

        // Form submission handler
        if (form && submitBtn) {
            form.addEventListener('submit', function(e) {
                // First check basic validation requirements
                if (hasPendingBlotterJS || !isWithinTimeGateJS) {
                    e.preventDefault();
                    Swal.fire('Error', 'Form submission is currently restricted.', 'error');
                    return;
                }

                const selectedDocType = documentTypeSelect.options[documentTypeSelect.selectedIndex];
                if (!selectedDocType || !selectedDocType.value) {
                    e.preventDefault();
                    Swal.fire('Error', 'Please select a document type.', 'error');
                    return;
                }

                const selectedDelivery = getSelectedDeliveryMethods();
                if (selectedDelivery.length === 0) {
                    e.preventDefault();
                    Swal.fire('Delivery Method Required', 'Please select at least one delivery method.', 'warning');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Request';
                    return false;
                }

                // Check payment method for paid documents
                const fee = barangayPrices[selectedDocType.dataset.code] || 0;
                const ftjsChecked = ftjsCheckbox && ftjsCheckbox.checked;
                const finalFee = (selectedDocType.dataset.code === 'barangay_clearance' && ftjsChecked) ? 0 : fee;
                
                if (finalFee > 0) {
                    const paymentMethod = document.getElementById('paymentMethod')?.value;
                    if (!paymentMethod) {
                        e.preventDefault();
                        Swal.fire('Error', 'Please select a payment method.', 'error');
                        return;
                    }
                }

                // Validate document-specific required fields
                if (selectedDocType.dataset.code === 'barangay_clearance') {
                    const ftjsChecked = ftjsCheckbox && ftjsCheckbox.checked;
                    if (ftjsChecked) {
                        // Check FTJS purpose field
                        const ftjsPurpose = jobSeekerPurposeFtjsInput?.value?.trim();
                        if (!ftjsPurpose) {
                            e.preventDefault();
                            Swal.fire('Error', 'Please enter the purpose for First Time Job Seeker certificate.', 'error');
                            return;
                        }
                    } else {
                        // Check standard clearance purpose field
                        const clearancePurposeSelect = document.getElementById('purposeClearance');
                        const clearancePurpose = clearancePurposeSelect?.value?.trim();

                        if (!clearancePurpose) {
                            e.preventDefault();
                            Swal.fire('Error', 'Please select a purpose for Barangay Clearance.', 'error');
                            return;
                        }

                        if(clearancePurpose === 'Others') {
                            const otherPurpose = document.getElementById('purposeClearanceOther')?.value?.trim();
                            if(!otherPurpose) {
                                e.preventDefault();
                                Swal.fire('Error', 'Please specify the purpose for Barangay Clearance.', 'error');
                                return;
                            }
                        }
                    }
                }

                // Validate Cedula requirement for Barangay Clearance (only show warning, don't block)
                if (selectedDocType.dataset.code === 'barangay_clearance' && !hasCompletedCedulaThisYearJS) {
                    const ftjsChecked = ftjsCheckbox && ftjsCheckbox.checked;
                    if (ftjsChecked) {
                        // For FTJS, we don't need to check for Cedula, proceed with form submission
                    } else {
                        e.preventDefault();
                        Swal.fire({
                            title: 'Cedula Requirement',
                            text: 'A completed Cedula for the current year is recommended for Barangay Clearance. Do you want to proceed anyway?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Proceed Anyway',
                            cancelButtonText: 'Cancel'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // User confirmed, submit the form
                                submitBtn.disabled = true;
                                submitBtn.textContent = 'Submitting...';
                                
                                // Create a new form submission
                                const formData = new FormData(form);
                                formData.append('bypass_cedula_check', '1');
                                
                                fetch(form.action, {
                                    method: 'POST',
                                    body: formData
                                }).then(response => {
                                    if (response.ok) {
                                        // A successful fetch won't automatically redirect, so we do it manually.
                                        // The server should ideally send back a redirect response or JSON.
                                        // Assuming success, we redirect to the pending page.
                                        window.location.href = '../pages/services.php?show_pending=1';
                                    } else {
                                        // If the server returns an error, we can try to parse it.
                                        response.text().then(text => {
                                             Swal.fire('Error', 'Submission failed: ' + text, 'error');
                                        });
                                        throw new Error('Submission failed');
                                    }
                                }).catch(error => {
                                    submitBtn.disabled = false;
                                    submitBtn.textContent = 'Submit Request';
                                    console.error("Fetch error:", error);
                                });
                            }
                        });
                        return; // Stop further execution
                    }
                }

                // Validate indigency photo requirement
                if (selectedDocType.dataset.code === 'barangay_indigency') {
                    const photoInput = document.getElementById('userPhoto');
                    if (!photoInput || !photoInput.files || photoInput.files.length === 0) {
                        e.preventDefault();
                        Swal.fire('Error', 'Photo is required for Barangay Indigency Certificate.', 'error');
                        return;
                    }
                }

                // Additional validation for other required fields based on document type
                const requiredFieldsMap = {
                    'proof_of_residency': ['residencyDuration', 'residencyPurpose'],
                    'barangay_indigency': ['indigencyReason'],
                    'business_permit_clearance': ['businessName', 'businessType', 'businessAddress', 'businessPurpose']
                };

                const docCode = selectedDocType.dataset.code;
                if (requiredFieldsMap[docCode]) {
                    for (const fieldId of requiredFieldsMap[docCode]) {
                        const field = document.getElementById(fieldId);
                        if (field && !field.value.trim()) {
                            e.preventDefault();
                            // Check for "Others" fields
                            const otherField = document.getElementById(fieldId + 'Other');
                            if (field.value === 'Others' && otherField && !otherField.value.trim()) {
                                 Swal.fire('Error', `Please specify your reason when selecting "Others".`, 'error');
                                 return;
                            } else if (field.value !== 'Others') {
                                Swal.fire('Error', `Please fill in all required fields for ${selectedDocType.text}.`, 'error');
                                return;
                            }
                        }
                    }
                }

                // If we get here, the form is valid from a high-level perspective.
                // The Cedula check below might still prevent default submission and show a popup.
                
                // Special Cedula check for non-FTJS Barangay Clearance
                if (selectedDocType.dataset.code === 'barangay_clearance' && !ftjsChecked && !hasCompletedCedulaThisYearJS) {
                    e.preventDefault(); // Stop submission to show confirmation
                    Swal.fire({
                        title: 'Cedula Requirement',
                        text: 'A completed Cedula for the current year is recommended for Barangay Clearance. Do you want to proceed anyway?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Proceed Anyway',
                        cancelButtonText: 'Cancel'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // User wants to proceed without Cedula. We manually submit the form.
                            // To prevent this check from running again, we can add a hidden input.
                            const bypassInput = document.createElement('input');
                            bypassInput.type = 'hidden';
                            bypassInput.name = 'bypass_cedula_check';
                            bypassInput.value = '1';
                            form.appendChild(bypassInput);
                            
                            // Disable button and submit
                            submitBtn.disabled = true;
                            submitBtn.textContent = 'Submitting...';
                            form.submit();
                        }
                    });
                    return; // IMPORTANT: Stop execution here to wait for Swal confirmation
                }

                // If we get here, it means it's either not a Barangay Clearance, or it's an FTJS request,
                // or the user has a completed Cedula, or the user already bypassed the warning.
                // In all these cases, we can proceed with the normal form submission.

                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
                
                // No e.preventDefault() here, let the form submit naturally.
            });
        }

   
});
    </script>
    
    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>
</body>
</html>