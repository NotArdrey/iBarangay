<?php
// ======================================
// DATABASE CONNECTION AND INITIALIZATION
// ======================================
require __DIR__ . '/../config/dbconn.php';

// Get current admin's barangay info from session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentAdminBarangayId = $_SESSION['barangay_id'] ?? null;

// Validate document request ID
if (!isset($docRequestId)) {
    if (!isset($_GET['id'])) {
        header("Location: ../pages/barangay_admin_dashboard.php");
        exit();
    }
    $docRequestId = (int)$_GET['id'];
}

// ======================================
// SECURITY CHECK - ENSURE ADMIN CAN ONLY ACCESS THEIR BARANGAY DOCUMENTS
// ======================================
// First, get the document's barangay to verify admin access
$securityCheckSql = "SELECT dr.barangay_id, b.name as barangay_name FROM document_requests dr 
                     JOIN barangay b ON dr.barangay_id = b.id 
                     WHERE dr.id = :docRequestId";
$securityStmt = $pdo->prepare($securityCheckSql);
$securityStmt->execute([':docRequestId' => $docRequestId]);
$docBarangayCheck = $securityStmt->fetch(PDO::FETCH_ASSOC);

// Ensure admin can only access documents from their barangay
if (!$docBarangayCheck || ($currentAdminBarangayId && $docBarangayCheck['barangay_id'] != $currentAdminBarangayId)) {
    echo "<h1>Access Denied</h1>";
    echo "<p>You can only access documents from your assigned barangay (" . ($_SESSION['barangay_name'] ?? 'Unknown') . ").</p>";
    echo "<p>This document belongs to: " . ($docBarangayCheck['barangay_name'] ?? 'Unknown Barangay') . "</p>";
    exit();
}

// ======================================
// DATABASE QUERIES
// ======================================
// Enhanced query to fetch document request and related information
$sql = "
    SELECT 
        dr.*,
        dt.name AS document_name,
        dt.code AS document_code,
        b.name AS barangay_name,
        b.id AS barangay_id,
        -- Person information from persons table
        p.id AS person_id,
        p.first_name AS person_first_name,
        p.middle_name AS person_middle_name,
        p.last_name AS person_last_name,
        p.birth_date AS person_birth_date,
        p.birth_place AS person_birth_place,
        p.gender AS person_gender,
        p.civil_status AS person_civil_status,
        p.contact_number AS person_contact,
        -- Emergency contact information
        ec.contact_name AS emergency_contact_name,
        ec.contact_number AS emergency_contact_number,
        ec.contact_address AS emergency_contact_address
    FROM document_requests dr
    JOIN document_types dt ON dr.document_type_id = dt.id
    JOIN barangay b ON dr.barangay_id = b.id
    LEFT JOIN persons p ON dr.person_id = p.id
    LEFT JOIN emergency_contacts ec ON p.id = ec.person_id
    WHERE dr.id = :docRequestId
";

// Execute main query
$stmt = $pdo->prepare($sql);
$stmt->execute([':docRequestId' => $docRequestId]);
$docRequest = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if document exists
if (!$docRequest) {
    echo "<h1>Document Request Not Found</h1>";
    exit();
}

// Check if document is a cedula
if ($docRequest['document_code'] === 'cedula' || $docRequest['document_code'] === 'community_tax_certificate') {
    echo "<h1>Community Tax Certificate (Cedula)</h1>";
    echo "<p>Community Tax Certificates (Cedula) must be obtained in person at the Barangay Hall.</p>";
    echo "<p>Please visit your Barangay Hall during office hours to process this document.</p>";
    exit();
}

// ======================================
// FETCH BARANGAY OFFICIALS (DYNAMIC)
// ======================================
// Query to get barangay officials for the specific barangay
$officialsSql = "
    SELECT
        CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS full_name,
        p.first_name,
        p.middle_name,
        p.last_name,
        r.name AS role_name,
        r.id AS role_id
    FROM users u
    JOIN persons p ON u.id = p.user_id
    JOIN roles r ON u.role_id = r.id
    WHERE u.barangay_id = :barangay_id AND r.id IN (3, 4, 5)
    ORDER BY r.id ASC
";

// Execute officials query using the document's barangay_id
$stmtOfficials = $pdo->prepare($officialsSql);
$stmtOfficials->execute([':barangay_id' => $docRequest['barangay_id']]);
$barangayOfficials = $stmtOfficials->fetchAll(PDO::FETCH_ASSOC);

// ======================================
// INITIALIZE OFFICIALS DATA WITH BARANGAY-SPECIFIC DEFAULTS
// ======================================
// Set barangay-specific default values for officials
$barangayNameForDefaults = strtoupper($docRequest['barangay_name']);
$captain = [
    'full_name' => $barangayNameForDefaults . ' BARANGAY CAPTAIN', 
    'role_name' => 'barangay_captain'
];
$secretary = [
    'full_name' => $barangayNameForDefaults . ' BARANGAY SECRETARY', 
    'role_name' => 'barangay_secretary'
];
$treasurer = [
    'full_name' => $barangayNameForDefaults . ' BARANGAY TREASURER', 
    'role_name' => 'barangay_treasurer'
];

// Map fetched officials to their roles
foreach ($barangayOfficials as $official) {
    if ($official['role_id'] == 3) {
        $captain = $official;
    } elseif ($official['role_id'] == 4) {
        $secretary = $official;
    } elseif ($official['role_id'] == 5) {
        $treasurer = $official;
    }
}

// ======================================
// PREPARE DOCUMENT DATA
// ======================================
// Personal Information
$firstName = $docRequest['first_name'] ?? $docRequest['person_first_name'] ?? '';
$middleName = $docRequest['middle_name'] ?? $docRequest['person_middle_name'] ?? '';
$lastName = $docRequest['last_name'] ?? $docRequest['person_last_name'] ?? '';
$birthDate = $docRequest['date_of_birth'] ?? $docRequest['person_birth_date'] ?? null;
$birthPlace = $docRequest['place_of_birth'] ?? $docRequest['person_birth_place'] ?? 'SAN RAFAEL, BULACAN';
$gender = $docRequest['sex'] ?? $docRequest['person_gender'] ?? '';
$civilStatus = $docRequest['civil_status'] ?? $docRequest['person_civil_status'] ?? '';
$contactNumber = $docRequest['cp_number'] ?? $docRequest['person_contact'] ?? '';
$purpose = $docRequest['purpose'] ?? 'FOR GENERAL PURPOSES';
$yearsOfResidence = $docRequest['years_of_residence'] ?? '0';

// Format address with dynamic barangay name
$address = '';
if (!empty($docRequest['address_no'])) {
    $address .= $docRequest['address_no'] . ' ';
}
if (!empty($docRequest['street'])) {
    $address .= $docRequest['street'] . ', ';
}
$address .= strtoupper($docRequest['barangay_name'] . ', SAN RAFAEL, BULACAN');

// Business specific information
$businessName = $docRequest['business_name'] ?? '';
$businessNature = $docRequest['business_nature'] ?? '';
$businessLocation = $docRequest['business_location'] ?? $address;

// Construction/Building specific information
$constructionLocation = $docRequest['construction_location'] ?? $address;
$titleNumber = $docRequest['title_number'] ?? '';

// Format name variations
$middle = $middleName ? ' ' . $middleName . ' ' : ' ';
$fullName = strtoupper($firstName . $middle . $lastName);

// Calculate age
$age = null;
if ($birthDate) {
    $birthDateObj = new DateTime($birthDate);
    $age = (new DateTime())->diff($birthDateObj)->y;
}

// ======================================
// DOCUMENT METADATA (BARANGAY-SPECIFIC)
// ======================================
// Format dates
$issuedDate = date('jS \d\a\y \o\f F Y');
$validUntil = date('jS \d\a\y \o\f F Y', strtotime('+6 months'));

// Document details with dynamic barangay information
$docType = $docRequest['document_name'];
$barangayName = strtoupper($docRequest['barangay_name']);
$documentCode = $docRequest['document_code'];

// Generate certificate number with barangay-specific prefix
$barangayPrefix = strtoupper(substr($docRequest['barangay_name'], 0, 3));
$certificateNumber = $barangayPrefix . '-' . strtoupper(substr($documentCode, 0, 2)) . '-' . date('Y') . '-' . str_pad($docRequestId, 6, '0', STR_PAD_LEFT);

// Payment information
$ctcNumber = $docRequest['ctc_number'] ?: 'N/A';
$orNumber = $docRequest['or_number'] ?: 'N/A';
$issuedOn = $docRequest['created_at'] ? date('d/m/Y', strtotime($docRequest['created_at'])) : date('d/m/Y');

// ======================================
// BARANGAY-SPECIFIC CUSTOMIZATIONS
// ======================================
// Define barangay-specific colors and styles
$barangayStyles = [
    'default' => ['primary' => '#1e40af', 'secondary' => '#dc2626'],
    // Add specific barangay customizations based on your database
    'BMA-BALAGTAS' => ['primary' => '#059669', 'secondary' => '#dc2626'],
    'BANCA‐BANCA' => ['primary' => '#7c3aed', 'secondary' => '#dc2626'],
    'CAINGIN' => ['primary' => '#0891b2', 'secondary' => '#dc2626'],
    'CAPIHAN' => ['primary' => '#c2410c', 'secondary' => '#dc2626'],
    'TAMBUBONG' => ['primary' => '#16a34a', 'secondary' => '#dc2626'],
    'PANTUBIG' => ['primary' => '#0284c7', 'secondary' => '#dc2626'],
    // Add more barangays as needed
];

$currentBarangayStyle = $barangayStyles[$barangayName] ?? $barangayStyles['default'];
$primaryColor = $currentBarangayStyle['primary'];
$secondaryColor = $currentBarangayStyle['secondary'];

// Get barangay-specific settings if available
$barangaySettingsSql = "SELECT * FROM barangay_settings WHERE barangay_id = :barangay_id";
$barangaySettingsStmt = $pdo->prepare($barangaySettingsSql);
$barangaySettingsStmt->execute([':barangay_id' => $docRequest['barangay_id']]);
$barangaySettings = $barangaySettingsStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($docType) ?> - <?= htmlspecialchars($barangayName) ?></title>
    <!-- ====================================== -->
    <!-- STYLES AND FORMATTING                  -->
    <!-- ====================================== -->
    <style>
        /* Page Setup for A4 Size */
        @page {
            margin: 5mm;
            margin-right: 15mm;
            size: A4 portrait;
        }
        
        /* Global Reset and Base Styles */
        * {
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Times New Roman', serif;
            margin: 0;
            padding: 0;
            color: #000;
            line-height: 1.2;
            background: #fff;
            font-size: 10pt;
        }
        
        /* Certificate Container and Decorative Elements */
        .certificate-container {
            width: 100%;
            max-width: 200mm;
            margin: 0;
            position: relative;
            background: #fff;
            height: 270mm;
            max-height: 277mm;
            display: flex;
            flex-direction: column;
            padding: 5mm;
            border: 2px solid <?= $primaryColor ?>;
            overflow: hidden;
        }
        
        .ornamental-corner {
            position: absolute;
            width: 15px;
            height: 15px;
            border: 1px solid <?= $primaryColor ?>;
        }
        
        .corner-tl { top: 8px; left: 8px; border-right: none; border-bottom: none; }
        .corner-tr { top: 8px; right: 8px; border-left: none; border-bottom: none; }
        .corner-bl { bottom: 8px; left: 8px; border-right: none; border-top: none; }
        .corner-br { bottom: 8px; right: 8px; border-left: none; border-top: none; }
        
        .header {
            text-align: center;
            margin-bottom: 6px;
            position: relative;
            padding: 5px 50px;
            border-bottom: 1px solid <?= $primaryColor ?>;
        }
        
        .header h1, .header h2, .header h3, .header p {
            margin: 0;
            color: <?= $primaryColor ?>;
            line-height: 1.1;
        }
        
        .header h1 {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-bottom: 0px;
        }
        
        .header h2 {
            font-size: 10pt;
            font-weight: bold;
            margin-bottom: 0px;
        }
        
        .header h3 {
            font-size: 8pt;
            margin-bottom: 1px;
        }
        
        .seal-placeholder {
            position: absolute;
            width: 35px;
            height: 35px;
            border: 1px solid <?= $primaryColor ?>;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 5pt;
            text-align: center;
            background: #f1f5f9;
            font-weight: bold;
        }
        
        .seal-left {
            left: 5px;
            top: 5px;
        }
        
        .seal-right {
            right: 5px;
            top: 5px;
        }
        
        .certificate-title {
            font-size: 14pt;
            font-weight: bold;
            text-align: center;
            margin: 6px 0;
            color: <?= $secondaryColor ?>;
            text-decoration: underline;
            letter-spacing: 0.8px;
        }
        
        .content {
            flex: 1;
            font-size: 9pt;
            line-height: 1.3;
            text-align: justify;
            margin: 3px 0;
        }
        
        .content h4 {
            text-align: center;
            margin: 6px 0;
            color: <?= $primaryColor ?>;
            font-size: 10pt;
            font-weight: bold;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2px;
            margin: 5px 0;
        }
        
        .info-row {
            margin: 0px;
            display: flex;
            align-items: flex-start;
            font-size: 8pt;
        }
        
        .info-label {
            width: 80px;
            font-weight: bold;
            flex-shrink: 0;
            color: <?= $primaryColor ?>;
        }
        
        .info-separator {
            margin: 0 3px;
            flex-shrink: 0;
        }
        
        .info-value {
            flex: 1;
            text-transform: uppercase;
            font-weight: bold;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 1px;
        }
        
        .verification-section {
            margin: 5px 0;
            font-size: 8pt;
            border-top: 1px solid <?= $primaryColor ?>;
            padding-top: 4px;
            padding: 4px;
        }
        
        .verification-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2px;
            margin: 3px 0;
        }
        
        .verification-item {
            text-align: left;
        }
        
        .verification-label {
            font-weight: bold;
            margin-bottom: 1px;
            color: <?= $primaryColor ?>;
            font-size: 7pt;
        }
        
        .verification-value {
            font-size: 7pt;
            border-bottom: 1px solid #d1d5db;
        }
        
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 5px;
            border-top: 1px solid <?= $primaryColor ?>;
            padding-top: 5px;
        }
        
        .signature-block {
            text-align: center;
        }
        
        .signature-line {
            border-bottom: 2px solid #000;
            height: 15px;
            margin: 8px auto 3px;
            width: 130px;
        }
        
        .official-name {
            font-weight: bold;
            font-size: 9pt;
            margin-bottom: 1px;
            text-transform: uppercase;
            color: <?= $primaryColor ?>;
        }
        
        .official-title {
            font-size: 8pt;
            font-style: italic;
            color: #64748b;
        }
        
        .document-number {
            position: absolute;
            top: 8px;
            right: 15px;
            font-size: 8pt;
            color: #64748b;
            font-weight: bold;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        .watermark {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 48pt;
            color: rgba(30, 64, 175, 0.05);
            white-space: nowrap;
            pointer-events: none;
            z-index: 1;
            font-weight: bold;
        }
        
        .record-info {
            position: absolute;
            bottom: 5px;
            right: 15px;
            font-size: 7pt;
            color: #64748b;
            background: #f8fafc;
            padding: 2px 5px;
            border-radius: 2px;
        }
        
        .business-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            margin: 8px 0;
            text-align: center;
        }
        
        .business-item {
            margin: 3px 0;
        }
        
        .business-label {
            font-size: 7pt;
            color: #64748b;
            margin-top: 2px;
        }
        
        .business-value {
            font-size: 9pt;
            font-weight: bold;
            color: <?= $secondaryColor ?>;
            border-bottom: 1px solid #e5e7eb;
            padding: 2px;
            min-height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .applicant-signature {
            text-align: left;
        }
        
        .thumb-marks {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3px;
            margin-top: 4px;
        }
        
        .thumb-mark {
            border: 1px solid #000;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6pt;
            background: #f9fafb;
        }
        
        .qr-placeholder {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            width: 35px;
            height: 35px;
            border: 1px solid #d1d5db;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6pt;
            background: #f9fafb;
            border-radius: 3px;
        }
        
        .purpose-highlight {
            text-align: center;
            margin: 10px 0;
            padding: 4px;
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            font-weight: bold;
            color: #92400e;
        }
        
        /* Barangay-specific header styling */
        .barangay-header {
            background: linear-gradient(135deg, <?= $primaryColor ?>22, transparent);
            border-radius: 4px;
            padding: 2px;
        }
        
        @media print {
            .certificate-container {
                height: auto;
                min-height: 257mm;
                max-height: 257mm;
                overflow: hidden;
            }
            
            .content {
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <!-- ====================================== -->
    <!-- CERTIFICATE STRUCTURE                  -->
    <!-- ====================================== -->
    <div class="certificate-container">
        <!-- Decorative Elements -->
        <div class="ornamental-corner corner-tl"></div>
        <div class="ornamental-corner corner-tr"></div>
        <div class="ornamental-corner corner-bl"></div>
        <div class="ornamental-corner corner-br"></div>
        
        <!-- Document Identifiers -->
        <div class="document-number"><?= $certificateNumber ?></div>
        <div class="watermark"><?= strtoupper($barangayName) ?></div>

        <!-- Header Section with Dynamic Barangay -->
        <div class="header barangay-header">
            <!-- Official Seals -->
            <div class="seal-placeholder seal-left">PROVINCIAL<br>SEAL</div>
            <div class="seal-placeholder seal-right">MUNICIPAL<br>SEAL</div>
            
            <!-- Government Headers -->
            <h1>Republic of the Philippines</h1>
            <h2>Province of Bulacan</h2>
            <h2>Municipality of San Rafael</h2>
            <h1 style="font-size: 15pt; margin-top: 3px;">BARANGAY <?= $barangayName ?></h1>
            <p style="font-size: 9pt; margin-top: 2px;">Office of the Punong Barangay</p>
        </div>

        <!-- Document Title -->
        <h1 class="certificate-title"><?= strtoupper($docType) ?></h1>

        <!-- Main Content Area -->
        <div class="content">
            <h4>TO WHOM IT MAY CONCERN:</h4>
            
            <?php 
            // ====================================== 
            // DOCUMENT TYPE SPECIFIC CONTENT
            // ======================================
            if (in_array($documentCode, ['business_permit_clearance'])): 
            ?>
                <!-- Business Permit Template -->
                <p>This is to certify that the business or trade activity described below:</p>
                
                <div class="business-info">
                    <div class="business-item">
                        <div class="business-value"><?= htmlspecialchars($businessName) ?></div>
                        <div class="business-label">(Business Name)</div>
                    </div>
                    <div class="business-item">
                        <div class="business-value"><?= htmlspecialchars($businessLocation) ?></div>
                        <div class="business-label">(Business Location)</div>
                    </div>
                    <div class="business-item">
                        <div class="business-value"><?= htmlspecialchars($fullName) ?></div>
                        <div class="business-label">(President/Owner)</div>
                    </div>
                    <div class="business-item">
                        <div class="business-value"><?= htmlspecialchars($address) ?></div>
                        <div class="business-label">(Address of Owner/Manager)</div>
                    </div>
                    <div class="business-item">
                        <div class="business-value"><?= htmlspecialchars($businessNature) ?></div>
                        <div class="business-label">(Nature of Business)</div>
                    </div>
                </div>
                
                <p>proposed to be established in this Barangay and is being applied for a <strong>Barangay Business Clearance</strong> to be used in securing a corresponding Mayor's Permit has been found to be in conformity with the provisions of existing Barangay Ordinances, rules and regulations being enforced in this Barangay.</p>
                
                <p>In view of the foregoing, the undersigned interposes no objections for the issuance of the corresponding Mayor's Permit being applied for.</p>
                
                <p>This permit shall be valid until <strong>December 31, <?= date('Y') ?></strong> and can be cancelled/revoked anytime the establishment is found to have violated any law or ordinance within this Barangay.</p>
                
            <?php elseif (in_array($documentCode, ['barangay_indigency'])): ?>
                <!-- Indigency Certificate Template -->
                <p>This is to certify that <strong><?= $fullName ?></strong>, <?= $age ?> years old, with address at <?= $address ?>, is belonging to the Indigent Family in our Barangay.</p>
                
                <p>As per records of this office, subject person has <strong>NO DEROGATORY RECORDS</strong>.</p>
                
                <p>This certification is issued upon the request of the above person to be used for:</p>
                <div class="purpose-highlight"><?= htmlspecialchars($purpose) ?></div>
                
            <?php elseif (in_array($documentCode, ['proof_of_residency'])): ?>
                <!-- Residency Certificate Template -->
                <p>This is to certify that the person whose name, signature, thumb marks and other personal data appearing hereon, has requested for a Certification of Residency from this Office and the results are listed below.</p>
                
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">NAME</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($fullName) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">ADDRESS</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($address) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">DATE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= $birthDate ? date('d F Y', strtotime($birthDate)) : '' ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">PLACE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($birthPlace) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">YEARS OF RESIDENCY</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($yearsOfResidence) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">PURPOSE</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars(strtoupper($purpose)) ?></div>
                    </div>
                </div>
                
                <p>This is to further certify that she/he is a bonafide resident of this Barangay.</p>
                <p>This certification is issued upon the request of the above-named person for whatever legal purpose and intents it is deemed necessary.</p>
                
            <?php elseif (in_array($documentCode, ['building_clearance'])): ?>
                <!-- Building Clearance Template -->
                <p>This is to certify that the person whose name, signature, thumb marks and other personal data appearing hereon, has requested for a Building Clearance from this Office and the results are listed below.</p>
                
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">NAME</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($fullName) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">ADDRESS</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($address) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">DATE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= $birthDate ? date('d F Y', strtotime($birthDate)) : '' ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">PLACE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($birthPlace) ?></div>
                    </div>
                </div>
                
                <p>This is to further certify that the Office of the Punong Barangay of <?= ucwords(strtolower($barangayName)) ?>, Municipality of San Rafael, Bulacan interposes no objection to the request of the above named person for the construction of building in private property located at <strong><?= htmlspecialchars($constructionLocation) ?></strong>.</p>
                
                <p>In relation hereto, document presented to this office include xerox copy of the following:</p>
                <p><strong>1. <?= htmlspecialchars($titleNumber) ?></strong></p>
                
            <?php elseif (in_array($documentCode, ['fencing_clearance'])): ?>
                <!-- Fencing Clearance Template -->
                <p>This is to certify that the person whose name, signature, thumb marks and other personal data appearing hereon, has requested for a Fencing Clearance from this Office and the results are listed below.</p>
                
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">NAME</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($fullName) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">ADDRESS</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($address) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">DATE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= $birthDate ? date('d F Y', strtotime($birthDate)) : '' ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">PLACE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($birthPlace) ?></div>
                    </div>
                </div>
                
                <p>This is to further certify that the Office of the Punong Barangay of <?= ucwords(strtolower($barangayName)) ?>, Municipality of San Rafael, Bulacan interposes no objection to the request of the above named person for the construction of fence in private property located at <strong><?= htmlspecialchars($constructionLocation) ?></strong>.</p>
                
                <p>In relation hereto, document presented to this office include xerox copy of the following documents.</p>
                
            <?php else: ?>
                <!-- Default Certificate Template -->
                <p>This is to certify that the person whose name, signature, thumb marks and other personal data appearing hereon, has requested for a Barangay Clearance from this Office and the results are listed below.</p>
                
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">NAME</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($fullName) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">ADDRESS</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($address) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">DATE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= $birthDate ? date('d F Y', strtotime($birthDate)) : '' ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">PLACE OF BIRTH</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($birthPlace) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">CIVIL STATUS</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars(strtoupper($civilStatus)) ?></div>
                    </div>
                    <?php if ($contactNumber): ?>
                    <div class="info-row">
                        <div class="info-label">CONTACT NO.</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($contactNumber) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($yearsOfResidence && in_array($documentCode, ['proof_of_residency'])): ?>
                    <div class="info-row">
                        <div class="info-label">YEARS OF RESIDENCY</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars($yearsOfResidence) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-label">PURPOSE</div>
                        <div class="info-separator">:</div>
                        <div class="info-value"><?= htmlspecialchars(strtoupper($purpose)) ?></div>
                    </div>
                </div>
                
                <p>This is to further certify that she/he is known to me with a good moral character, law abiding citizen in the community. She/he has no criminal Record found in our Barangay Records.</p>
                
            <?php endif; ?>
        </div>

        <!-- Verification Section -->
        <div class="verification-section">
            <!-- Official Verification -->
            <div style="text-align: left; margin: 2px 0;">
                <div><strong>Verified by:</strong></div>
                <div style="margin-top: 1px;">
                    <div class="official-name"><?= strtoupper($secretary['full_name']) ?></div>
                    <div style="font-size: 6pt;">Barangay Secretary</div>
                </div>
            </div>
            
            <!-- Document Details -->
            <div class="verification-grid">
                <div class="verification-item">
                    <div class="verification-label">Given this</div>
                    <div class="verification-value"><?= $issuedDate ?></div>
                </div>
                <div class="verification-item">
                    <div class="verification-label">Valid until:</div>
                    <div class="verification-value"><?= $validUntil ?></div>
                </div>
                <div class="verification-item">
                    <div class="verification-label">CTC NO.</div>
                    <div class="verification-value"><?= $ctcNumber ?></div>
                </div>
                <div class="verification-item">
                    <div class="verification-label">ISSUED AT</div>
                    <div class="verification-value">SAN RAFAEL, BULACAN</div>
                </div>
                <div class="verification-item">
                    <div class="verification-label">ISSUED ON</div>
                    <div class="verification-value"><?= $issuedOn ?></div>
                </div>
                <div class="verification-item">
                    <div class="verification-label">O.R. NO.</div>
                    <div class="verification-value"><?= $orNumber ?></div>
                </div>
            </div>
            
            <div style="text-align: left; margin-top: 2px;">
                <div><strong>PREPARED BY:</strong> <?= htmlspecialchars($barangayName) ?> Administrator</div>
            </div>
        </div>

        <!-- Signature Section -->
        <div class="signatures">
            <!-- Applicant's Signature -->
            <div class="signature-block">
                <div class="applicant-signature">
                    <div style="font-size: 7pt; margin-bottom: 1px;">Signature of Applicant</div>
                    <div style="border-bottom: 1px solid #000; height: 10px; width: 100px; margin-bottom: 3px;"></div>
                    <div class="thumb-marks">
                        <div class="thumb-mark">LEFT</div>
                        <div class="thumb-mark">RIGHT</div>
                    </div>
                </div>
            </div>
            
            <!-- Official's Signature -->
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="official-name"><?= strtoupper($captain['full_name']) ?></div>
                <div class="official-title">Punong Barangay</div>
            </div>
        </div>

        <!-- Footer Elements -->
        <div class="qr-placeholder">QR</div>
        <div class="record-info">Record <?= str_pad($docRequestId, 2, '0', STR_PAD_LEFT) ?>/443 - <?= $barangayName ?></div>
    </div>

    <!-- ====================================== -->
    <!-- JAVASCRIPT FOR ADDITIONAL FEATURES     -->
    <!-- ====================================== -->
    <script>
        // Print functionality
        function printDocument() {
            window.print();
        }
        
        // Add print button if needed
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-print if URL parameter is set
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('print') === 'true') {
                setTimeout(() => {
                    window.print();
                }, 1000);
            }
        });
        
        // Dynamic content adjustments based on barangay
        function adjustContentForBarangay() {
            const barangayName = '<?= $barangayName ?>';
            
            // Add barangay-specific adjustments here if needed
            switch(barangayName) {
                case 'BAGBAGUIN':
                    // Specific adjustments for Bagbaguin
                    break;
                case 'BALIUAG':
                    // Specific adjustments for Baliuag
                    break;
                default:
                    // Default behavior
                    break;
            }
        }
        
        // Call the adjustment function
        adjustContentForBarangay();
    </script>
</body>
</html>