<?php
require __DIR__ . '/../config/dbconn.php';

if (!isset($_GET['id'])) {
    header("Location: ../pages/barangay_admin_dashboard.php");
    exit();
}
//../functions/document_template.php
$docRequestId = (int)$_GET['id'];

// Main document query
$sql = "
    SELECT
        dr.request_date,
        dr.barangay_id,
        dt.document_name,
        u.user_id,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.birth_date,
        b.barangay_name,
        MAX(CASE WHEN a.attr_key = 'residency_duration' THEN a.attr_value END) AS residency_duration,
        MAX(CASE WHEN a.attr_key = 'indigency_income' THEN a.attr_value END) AS indigency_income,
        MAX(CASE WHEN a.attr_key = 'ra_reference' THEN a.attr_value END) AS ra_reference
    FROM DocumentRequest dr
    JOIN DocumentType dt ON dr.document_type_id = dt.document_type_id
    JOIN Users u ON dr.user_id = u.user_id
    JOIN Barangay b ON dr.barangay_id = b.barangay_id
    LEFT JOIN DocumentRequestAttribute a ON a.request_id = dr.document_request_id
    WHERE dr.document_request_id = :docRequestId
    GROUP BY dr.document_request_id;
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':docRequestId' => $docRequestId]);
$docRequest = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$docRequest) {
    header("Location: not_found.php");
    exit();
}

// Fetch officials
$officialsSql = "
    SELECT 
        u.first_name, 
        u.middle_name, 
        u.last_name, 
        r.role_name
    FROM Users u
    JOIN Role r ON u.role_id = r.role_id
    WHERE u.barangay_id = :barangayId
      AND r.role_name IN (
          'Barangay Captain',
          'Barangay Secretary',
          'Barangay Treasurer',
          'Chief Officer',
          'Barangay Councilor'
      )
    ORDER BY FIELD(r.role_name,
        'Barangay Captain',
        'Barangay Secretary',
        'Barangay Treasurer',
        'Chief Officer',
        'Barangay Councilor'
    )
";

$stmtOfficials = $pdo->prepare($officialsSql);
$stmtOfficials->execute([':barangayId' => $docRequest['barangay_id']]);
$officials = $stmtOfficials->fetchAll(PDO::FETCH_ASSOC);

// Group officials
$officialsGrouped = [
    'captain'    => [],
    'councilors' => [],
    'secretary'  => [],
    'treasurer'  => [],
    'chief'      => []
];
foreach ($officials as $off) {
    switch ($off['role_name']) {
        case 'Barangay Captain':
            $officialsGrouped['captain'] = $off;
            break;
        case 'Barangay Councilor':
            $officialsGrouped['councilors'][] = $off;
            break;
        case 'Barangay Secretary':
            $officialsGrouped['secretary'] = $off;
            break;
        case 'Barangay Treasurer':
            $officialsGrouped['treasurer'] = $off;
            break;
        case 'Chief Officer':
            $officialsGrouped['chief'] = $off;
            break;
    }
}

// Calculate age
$birthDate = new DateTime($docRequest['birth_date']);
$age = (new DateTime())->diff($birthDate)->y;

// Format requester name
$middle = $docRequest['middle_name'] ? $docRequest['middle_name'] . ' ' : '';
$requesterName = strtoupper("{$docRequest['first_name']} {$middle}{$docRequest['last_name']}");

// Format request date
$formattedDate = (new DateTime($docRequest['request_date']))->format('jS \d\a\y \o\f F, Y');

// Document-specific flags
$docType      = $docRequest['document_name'];
$showIndig    = $docType === 'Certificate of Indigency';
$showRA       = $docType === 'First Time Jobseeker Certification';
$purpose      = match($docType) {
    'First Time Jobseeker Certification' => 'availing the benefits of Republic Act 11261 (First Time Jobseeker Act of 2019)',
    'Certificate of Indigency'            => 'FINANCIAL ASSISTANCE purposes only',
    default                               => 'any legal intent and purposes'
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($docType) ?> - <?= htmlspecialchars($docRequest['barangay_name']) ?></title>
    <style>
        body { font-family: 'Times New Roman', serif; line-height: 1.5; margin: 2cm; }
        .header { text-align: center; margin-bottom: 1.5cm; }
        .certificate-title { font-size: 24pt; margin-bottom: 0.5cm; text-decoration: underline; }
        .content { font-size: 12pt; text-align: justify; margin-bottom: 1.5cm; }
        .signatures { margin-top: 2cm; }
        .signature-block { margin: 1cm 0; }
        .signature-line { border-bottom: 1px solid #000; width: 60%; margin: 10px 0; }
        .official-list { margin: 5px 0; }
        .footer-note { font-size: 10pt; text-align: center; margin-top: 1cm; }
        .uppercase { text-transform: uppercase; }
    </style>
</head>
<body>
<title><?= htmlspecialchars($docType) ?> - <?= htmlspecialchars($docRequest['barangay_name']) ?></title>
    <style>
        body { 
            font-family: 'Arial', sans-serif; 
            margin: 2cm;
            background-image: url('barangay-seal-watermark.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: 400px;
        }
        .certificate-border {
            border: 3px double #000;
            padding: 2.5cm;
            position: relative;
        }
        .header { 
            text-align: center; 
            margin-bottom: 1.5cm;
            border-bottom: 2px solid #000;
            padding-bottom: 1rem;
        }
        .logo-left, .logo-right {
            position: absolute;
            top: 20px;
            width: 80px;
        }
        .logo-left { left: 20px; }
        .logo-right { right: 20px; }
        .certificate-title {
            font-size: 28pt;
            margin: 1.5rem 0;
            color: #2c3e50;
            letter-spacing: 2px;
        }
        .content {
            font-size: 13pt;
            text-align: justify;
            line-height: 1.8;
            margin: 2rem 0;
        }
        .signatures {
            margin-top: 3cm;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }
        .signature-block {
            text-align: center;
            margin: 1cm 0;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            width: 80%;
            margin: 0 auto;
            height: 30px;
        }
        .official-name {
            font-weight: bold;
            margin-top: 5px;
            text-transform: uppercase;
        }
        .footer-note {
            position: absolute;
            bottom: 1cm;
            width: 100%;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }
        .dry-seal {
            position: absolute;
            right: 2cm;
            bottom: 3cm;
            opacity: 0.8;
        }
        .uppercase { text-transform: uppercase; }
        .emphasis { 
            font-weight: bold;
            border-bottom: 1px dashed #000;
        }
    </style>
</head>
<body>
    <div class="certificate-border">
        <img src="ph-flag.png" class="logo-left">
        <img src="barangay-logo.png" class="logo-right">
        
        <div class="header">
            <h3>REPUBLIC OF THE PHILIPPINES</h3>
            <h4>PROVINCE OF BULACAN</h4>
            <h4>MUNICIPALITY OF SAN RAFAEL</h4>
            <h2 style="margin-top:1rem;">BARANGAY <?= htmlspecialchars(strtoupper($docRequest['barangay_name'])) ?></h2>
            <p>Office of the Punong Barangay</p>
        </div>

        <h1 class="certificate-title"><?= htmlspecialchars($docType) ?></h1>

        <div class="content">
            <p>TO WHOM IT MAY CONCERN:</p>
            
            <p style="text-indent: 50px;">This is to certify that <span class="emphasis uppercase"><?= $requesterName ?></span>, 
               <?= $age ?> years of age, 
               <?php if ($docRequest['residency_duration']): ?>
               a bonafide resident of <?= htmlspecialchars($docRequest['barangay_name']) ?> 
               for <?= htmlspecialchars($docRequest['residency_duration']) ?>,
               <?php endif; ?>
               is known to be of good moral character and has no derogatory record in this barangay.</p>

            <?php if ($showIndig): ?>
            <p style="text-indent: 50px; margin-top:1rem;">This further certifies that the aforementioned individual belongs to an INDIGENT FAMILY 
                with <?= htmlspecialchars($docRequest['indigency_income'] ?? 'no regular source of income') ?> 
                as verified by our Barangay Social Welfare and Development Office.</p>
            <?php endif; ?>

            <?php if ($showRA): ?>
            <p style="text-indent: 50px; margin-top:1rem;">This certification is issued pursuant to Republic Act 11261 (First Time Jobseeker Act) 
                and <?= htmlspecialchars($docRequest['ra_reference'] ?? 'as validated by our Public Employment Service Office') ?>.</p>
            <?php endif; ?>

            <p style="text-indent: 50px; margin-top:1rem;">Issued this <?= $formattedDate ?> at Barangay <?= htmlspecialchars($docRequest['barangay_name']) ?>, 
                San Rafael, Bulacan, for <?= $purpose ?>.</p>
        </div>

        <div class="signatures">
            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="official-name"><?= strtoupper($officialsGrouped['captain']['first_name'] . ' ' . $officialsGrouped['captain']['last_name']) ?></div>
                <div>Punong Barangay</div>
            </div>

            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="official-name"><?= strtoupper($officialsGrouped['secretary']['first_name'] . ' ' . $officialsGrouped['secretary']['last_name']) ?></div>
                <div>Barangay Secretary</div>
            </div>

            <div class="signature-block">
                <div class="signature-line"></div>
                <div class="official-name"><?= strtoupper($officialsGrouped['treasurer']['first_name'] . ' ' . $officialsGrouped['treasurer']['last_name']) ?></div>
                <div>Barangay Treasurer</div>
            </div>
        </div>

        <div class="footer-note">
            <p>NOT VALID WITHOUT OFFICIAL SEAL</p>
            <p>This document was issued through the Barangay Document Management System (BDMS)</p>
            <p>Barangay Clearance No: <?= strtoupper(uniqid('BC-')) ?></p>
        </div>

        <img src="dry-seal.png" class="dry-seal" style="width: 120px;">
    </div>
</body>
</html>

<?php
// — Fixed include: remove the query string and reference file directly
require_once __DIR__ . '/../functions/document_template.php';

// now, if that file defines a function, call it here, e.g.
// render_document_template($docRequestId);
?>
