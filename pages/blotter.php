<?php
// blotter.php – ADMIN SIDE
session_start();
require "../config/dbconn.php";
require "../vendor/autoload.php";
use Dompdf\Dompdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;  

// Define role constants
const ROLE_PROGRAMMER   = 1;
const ROLE_SUPER_ADMIN  = 2;
const ROLE_CAPTAIN      = 3;
const ROLE_SECRETARY    = 4;
const ROLE_TREASURER    = 5;
const ROLE_COUNCILOR    = 6;
const ROLE_CHIEF        = 7;
const ROLE_RESIDENT     = 8;

// Authentication & role check
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

// Check if user has appropriate role for blotter management
$current_admin_id = $_SESSION['user_id'];
$bid = $_SESSION['barangay_id'];
$role = $_SESSION['role_id'];

// Define role-based permissions
$canManageBlotter = in_array($role, [ROLE_CAPTAIN, ROLE_SECRETARY, ROLE_CHIEF]);
$canScheduleHearings = in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF]);
$canIssueCFA = in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF]);
$canGenerateReports = in_array($role, [ROLE_CAPTAIN, ROLE_SECRETARY, ROLE_CHIEF]);

// Redirect users without blotter management permissions
if (!$canManageBlotter && !isset($_GET['action'])) {
    $_SESSION['error_message'] = "You don't have permission to access the blotter management system.";
    header("Location: dashboard.php");
    exit;
}

$current_admin_id = $_SESSION['user_id'];
$bid = $_SESSION['barangay_id'];
$role = $_SESSION['role_id'];
$allowedStatuses = ['pending','open','closed','completed','solved','endorsed_to_court','cfa_eligible','dismissed'];

function logAuditTrail($pdo, $adminId, $action, $table, $recordId, $desc = '') {
    $pdo->prepare("INSERT INTO audit_trails
        (user_id, admin_user_id, action, table_name, record_id, description)
        VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$adminId, $adminId, $action, $table, $recordId, $desc]);
}

function getResidents($pdo, $bid) {
    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, CONCAT(u.first_name,' ',u.last_name) AS name
        FROM users u WHERE u.barangay_id = ?
        UNION
        SELECT p.id AS user_id, CONCAT(p.first_name,' ',p.last_name) AS name  
        FROM persons p
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
        WHERE a.barangay_id = ? AND p.user_id IS NULL
    ");
    $stmt->execute([$bid, $bid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function validateBlotterData(array $data, &$errors) {
    if (empty(trim($data['location'] ?? ''))) $errors[] = 'Location is required.';
    if (empty(trim($data['description'] ?? ''))) $errors[] = 'Description is required.';
    if (empty($data['categories']) || !is_array($data['categories'])) $errors[] = 'At least one category must be selected.';
    
    if (empty($data['participants']) || !is_array($data['participants'])) {
        $errors[] = 'At least one participant is required.';
    } else {
        foreach ($data['participants'] as $idx => $p) {
            if (!empty($p['user_id']) && !ctype_digit(strval($p['user_id']))) {
                $errors[] = "Participant #".($idx+1)." has invalid user ID.";
            } else {
                if (empty(trim($p['first_name'] ?? ''))) $errors[] = "Participant #".($idx+1)." first name is required.";
                if (empty(trim($p['last_name'] ?? ''))) $errors[] = "Participant #".($idx+1)." last name is required.";
                if (!empty($p['age']) && !ctype_digit(strval($p['age']))) $errors[] = "Participant #".($idx+1)." age must be a number.";
                if (!empty($p['gender']) && !in_array($p['gender'], ['Male','Female','Other'], true)) {
                    $errors[] = "Participant #".($idx+1)." has invalid gender.";
                }
            }
            if (empty(trim($p['role'] ?? ''))) $errors[] = "Participant #".($idx+1)." role is required.";
        }
    }
    return empty($errors);
}

function updateCaseStatus($pdo, $caseId, $newStatus, $userId) {
    $stmt = $pdo->prepare("
        UPDATE blotter_cases 
        SET status = ?, 
            updated_at = CURRENT_TIMESTAMP,
            resolved_at = CASE 
                WHEN ? IN ('closed', 'dismissed') THEN CURRENT_TIMESTAMP 
                ELSE resolved_at 
            END
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $newStatus, $caseId]);
    
    // Log the status change
    logAuditTrail($pdo, $userId, 'UPDATE', 'blotter_cases', $caseId, "Case status updated to: $newStatus");
    
    return $stmt->rowCount() > 0;
}

function generateCFACertificate($pdo, $caseId, $complainantId, $issuedBy) {
    $certNumber = 'CFA-' . date('Y') . '-' . str_pad($caseId, 4, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("
        INSERT INTO cfa_certificates 
        (blotter_case_id, complainant_person_id, issued_by_user_id, certificate_number, issued_at, reason)
        VALUES (?, ?, ?, ?, NOW(), 'Failed mediation after maximum hearings')
    ");
    $stmt->execute([$caseId, $complainantId, $issuedBy, $certNumber]);
    $pdo->prepare("
        UPDATE blotter_cases 
        SET status = 'endorsed_to_court', cfa_issued_at = NOW(), endorsed_to_court_at = NOW() 
        WHERE id = ?
    ")->execute([$caseId]);
    return $certNumber;
}

function generateSummonsForm($pdo, $caseId) {
    $stmt = $pdo->prepare("
        SELECT bc.*, GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories
        FROM blotter_cases bc
        LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
        LEFT JOIN case_categories cc ON bcc.category_id = cc.id
        WHERE bc.id = ?
        GROUP BY bc.id
    ");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) throw new Exception("Case not found");

    // Get appropriate signature based on availability
    // Prioritize captain's signature if available, otherwise use chief officer's
    $esignaturePath = null;
    if (in_array($case['status'], ['open','closed','completed','solved','endorsed_to_court','cfa_eligible'])) {
        $captainEsignaturePath = getCaptainEsignature($pdo, $case['barangay_id']);
        if ($captainEsignaturePath) {
            $esignaturePath = $captainEsignaturePath;
        } else {
            $esignaturePath = getChiefOfficerEsignature($pdo, $case['barangay_id']);
        }
    }

    $pStmt = $pdo->prepare("
        SELECT bp.role,
            COALESCE(CONCAT(p.first_name, ' ', p.last_name), 
            CONCAT(ep.first_name, ' ', ep.last_name)) AS full_name
        FROM blotter_participants bp
        LEFT JOIN persons p ON bp.person_id = p.id
        LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
        WHERE bp.blotter_case_id = ?
    ");
    $pStmt->execute([$caseId]);
    $participants = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $complainants = array_filter($participants, fn($p) => $p['role'] === 'complainant');
    $respondents = array_filter($participants, fn($p) => $p['role'] === 'respondent');

    ob_start(); ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 25mm 20mm; size: A4; }
            body { font-family: 'Times New Roman', serif; font-size: 12pt; margin: 0; color: #000; }
            /* Remove absolute positioning for form-number to avoid overlap */
            .form-number { font-size: 11pt; margin-bottom: 2mm; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
            .header-table td { vertical-align: middle; }
            .logo-cell { width: 70px; text-align: center; }
            .logo-placeholder { width: 60px; height: 60px; border: 1px solid #000; border-radius: 50%; margin: 0 auto; }
            .center-cell { text-align: center; font-size: 11pt; font-weight: bold; }
            .main-title { text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin: 2mm 0 2mm 0; }
            .divider { border-top: 1.5px solid #000; margin: 2mm 0 2mm 0; }
            .case-table { width: 100%; margin-bottom: 2mm; }
            .case-table td { font-size: 12pt; }
            .underline { border-bottom: 1px solid #000; min-width: 120px; display: inline-block; }
            .parties-table { width: 100%; margin-bottom: 2mm; }
            .parties-table td { vertical-align: top; }
            .label { font-size: 11pt; }
            .summons-title { text-align: center; font-weight: bold; text-decoration: underline; margin: 2mm 0; }
            .lines { border-bottom: 1px solid #000; height: 12px; margin: 1.5mm 0; }
            .signature-section { margin-top: 8mm; }
            .signature-line { border-bottom: 1px solid #000; width: 60mm; display: inline-block; margin-bottom: 2mm; }
            .footer-motto { text-align: center; margin-top: 10mm; font-style: italic; font-size: 12pt; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="form-number">KP Pormularyo Blg. 7</div>
        <table class="header-table">
            <tr>
                <td class="logo-cell"><div class="logo-placeholder"></div></td>
                <td class="center-cell">
                    Republika ng Pilipinas<br>
                    Lalawigan ng Bulacan<br>
                    Bayan ng San Rafael<br>
                    Barangay Tambubong
                </td>
                <td class="logo-cell"><div class="logo-placeholder"></div></td>
            </tr>
        </table>
        <div class="main-title">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
        <div class="divider"></div>
        <div class="case-table">
            <div style="width:45%;display:inline-block;vertical-align:top;">
                <div class="underline" style="min-width:180px;">
                    <?php foreach($complainants as $c): ?>
                        <?= htmlspecialchars($c['full_name'] ?? 'Unknown') ?><br>
                    <?php endforeach; ?>
                    <?php if (empty($complainants)): ?>
                        &nbsp;
                    <?php endif; ?>
                </div>
                <div class="label">(Mga) Maysumbong</div>
                <div style="margin:2mm 0;">-laban kay (kina)-</div>
                <div class="underline" style="min-width:180px;">
                    <?php foreach($respondents as $r): ?>
                        <?= htmlspecialchars($r['full_name'] ?? 'Unknown') ?><br>
                    <?php endforeach; ?>
                    <?php if (empty($respondents)): ?>
                        &nbsp;
                    <?php endif; ?>
                </div>
                <div class="label">(Mga) Ipinagsusumbong</div>
            </div>
            <div style="width:10%;display:inline-block;"></div>
            <div style="width:45%;display:inline-block;vertical-align:top;">
                Usaping Barangay Blg. <span class="underline" style="min-width:80px;"><?= htmlspecialchars($case['case_number'] ?? '') ?></span><br>
                Ukol sa <span class="underline" style="min-width:120px;"><?= htmlspecialchars($case['categories'] ?? '') ?></span>
            </div>
        </div>
        <div class="summons-title">PATAWAG</div>
        <div style="margin-bottom:2mm;">
            Kay/Kina: <span class="underline" style="min-width:180px;">
                <?php foreach($respondents as $r) echo htmlspecialchars($r['full_name']) . ' '; ?>
            </span>
            <div class="label">(Mga) Ipinagsusumbong</div>
        </div>
        <div style="margin-bottom:2mm;">
            Sa pamamagitan nito, kayo'y tinatawag upang personal na humarap sa akin, kasama ang inyong mga testigo, sa ika-<span class="underline" style="min-width:30px;"></span> araw ng <span class="underline" style="min-width:80px;"></span>, 20<span class="underline" style="min-width:30px;"></span>, sa ganap na ika-<span class="underline" style="min-width:40px;"></span> ng umaga/hapon, upang sagutin ang isang sumbong na idinulog sa akin, na ang kopya'y kalakip nito, para pagmagitanan/pagpakasunduin kayo sa inyong alitan ng (mga) maysumbong.
        </div>
        <div style="margin-bottom:2mm;">
            Sa pamamagitan nito, kayo'y binabalaan na ang inyong pagtanggi o sadyang di-pagharap bilang pagtaliwas sa patawag na ito ay magbibigay ng karapatan sa (mga) maysumbong upang tuwiran kayong ipagsakdal sa hukuman/tanggapan ng pamahalaan, na doon ay mahahadlangan kayong magharap ng kontra-demanda bunga ng nabanggit na sumbong.
        </div>
        <div style="margin-bottom:2mm;">
            TUPARIN ITO, at kung hindi'y parurusahan kayo sa salang paglapastangan sa hukuman.
        </div>
        <div class="signature-section">
            <table style="width:100%; margin-top:6mm;">
                <tr>
                    <td>
                        Ngayon ika-<span class="underline" style="min-width:30px;"></span> araw ng <span class="underline" style="min-width:80px;"></span>, 20<span class="underline" style="min-width:30px;"></span>.
                    </td>
                    <td style="text-align:right;">
                        <?php if ($esignaturePath): ?>
                            <img src="<?= htmlspecialchars($esignaturePath) ?>" alt="E-signature" style="height:50px;max-width:180px;display:block;margin-left:auto;margin-bottom:2mm;">
                        <?php endif; ?>
                        <div class="signature-line"></div><br>
                        Punong Barangay/Pangulo ng Lupon
                    </td>
                </tr>
            </table>
        </div>
        <div class="footer-motto">"ASENSO at PROGRESO"</div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function generateReportForm($pdo, $caseId) {
    $stmt = $pdo->prepare("
        SELECT bc.*, 
               GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories
        FROM blotter_cases bc
        LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
        LEFT JOIN case_categories cc ON bcc.category_id = cc.id
        WHERE bc.id = ?
        GROUP BY bc.id
    ");
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$case) {
        throw new Exception("Case not found");
    }
    
    // Get appropriate signature based on availability
    // Prioritize captain's signature if available, otherwise use chief officer's
    $esignaturePath = null;
    if (in_array($case['status'], ['open','closed','completed','solved','endorsed_to_court','cfa_eligible'])) {
        $captainEsignaturePath = getCaptainEsignature($pdo, $case['barangay_id']);
        if ($captainEsignaturePath) {
            $esignaturePath = $captainEsignaturePath;
        } else {
            $esignaturePath = getChiefOfficerEsignature($pdo, $case['barangay_id']);
        }
    }

    // Get participants
    $pStmt = $pdo->prepare("
        SELECT 
            bp.role,
            COALESCE(CONCAT(p.first_name, ' ', p.last_name), CONCAT(ep.first_name, ' ', ep.last_name)) AS full_name,
            COALESCE(CONCAT(a.house_no, ' ', a.street, ', ', b.name), ep.address) AS address
        FROM blotter_participants bp
        LEFT JOIN persons p ON bp.person_id = p.id
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
        LEFT JOIN barangay b ON a.barangay_id = b.id
        LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
        WHERE bp.blotter_case_id = ?
    ");
    $pStmt->execute([$caseId]);
    $participants = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $complainants = array_filter($participants, fn($p) => $p['role'] === 'complainant');
    $respondents = array_filter($participants, fn($p) => $p['role'] === 'respondent');
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 25mm 20mm; size: A4; }
            body { font-family: 'Times New Roman', serif; font-size: 12pt; margin: 0; color: #000; }
            /* Remove absolute positioning for form-number to avoid overlap */
            .form-number { font-size: 11pt; margin-bottom: 2mm; }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; }
            .header-table td { vertical-align: middle; }
            .logo-cell { width: 70px; text-align: center; }
            .logo-placeholder { width: 60px; height: 60px; border: 1px solid #000; border-radius: 50%; margin: 0 auto; }
            .center-cell { text-align: center; font-size: 11pt; font-weight: bold; }
            .main-title { text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin: 2mm 0 2mm 0; }
            .divider { border-top: 1.5px solid #000; margin: 2mm 0 2mm 0; }
            .case-table { width: 100%; margin-bottom: 2mm; }
            .case-table td { font-size: 12pt; }
            .underline { border-bottom: 1px solid #000; min-width: 120px; display: inline-block; }
            .parties-table { width: 100%; margin-bottom: 2mm; }
            .parties-table td { vertical-align: top; }
            .label { font-size: 11pt; }
            .sumbong-title { text-align: center; font-weight: bold; text-decoration: underline; margin: 2mm 0; }
            .lines { border-bottom: 1px solid #000; height: 12px; margin: 1.5mm 0; }
            .signature-section { margin-top: 8mm; }
            .signature-line { border-bottom: 1px solid #000; width: 60mm; display: inline-block; margin-bottom: 2mm; }
            .footer-motto { text-align: center; margin-top: 10mm; font-style: italic; font-size: 12pt; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="form-number">KP Pormularyo Blg. 7</div>
        <table class="header-table">
            <tr>
                <td class="logo-cell"><div class="logo-placeholder"></div></td>
                <td class="center-cell">
                    Republika ng Pilipinas<br>
                    Lalawigan ng Bulacan<br>
                    Bayan ng San Rafael<br>
                    Barangay Tambubong
                </td>
                <td class="logo-cell"><div class="logo-placeholder"></div></td>
            </tr>
        </table>
        <div class="main-title">TANGGAPAN NG LUPONG TAGAPAMAYAPA</div>
        <div class="divider"></div>
        <div class="case-table">
            <div style="width:45%;display:inline-block;vertical-align:top;">
                <div class="underline" style="min-width:180px;">
                    <?php foreach($complainants as $c): ?>
                        <?= htmlspecialchars($c['full_name'] ?? 'Unknown') ?><br>
                    <?php endforeach; ?>
                    <?php if (empty($complainants)): ?>
                        &nbsp;
                    <?php endif; ?>
                </div>
                <div class="label">(Mga) Maysumbong</div>
                <div style="margin:2mm 0;">-laban kay (kina)-</div>
                <div class="underline" style="min-width:180px;">
                    <?php foreach($respondents as $r): ?>
                        <?= htmlspecialchars($r['full_name'] ?? 'Unknown') ?><br>
                    <?php endforeach; ?>
                    <?php if (empty($respondents)): ?>
                        &nbsp;
                    <?php endif; ?>
                </div>
                <div class="label">(Mga) Ipinagsusumbong</div>
            </div>
            <div style="width:10%;display:inline-block;"></div>
            <div style="width:45%;display:inline-block;vertical-align:top;">
                Usaping Barangay Blg. <span class="underline" style="min-width:80px;"><?= htmlspecialchars($case['case_number'] ?? '') ?></span><br>
                Para sa <span class="underline" style="min-width:120px;"><?= htmlspecialchars($case['categories'] ?? '') ?></span>
            </div>
        </div>
        <div class="sumbong-title">SUMBONG</div>
        <div style="margin-bottom:2mm;">
            AKO/KAMI, sa pamamagitan nito, ay naghahain ng sumbong laban sa (mga) ipinagsusumbong na binabanggit sa itaas dahil sa paglabag sa aking/aming mga karapatan at kapakanan sa sumusunod na paraan:
        </div>
        <?php for($i=0;$i<6;$i++): ?><div class="lines"></div><?php endfor; ?>
        <div style="margin:2mm 0;">
            DAHIL DITO, AKO/KAMI ay namamanhik na ipagkaloob sa akin/amin ang sumusunod na (mga) kalunasan nang naa alinsunod sa batas at/o pagkamakatuwiran:
        </div>
        <?php for($i=0;$i<4;$i++): ?><div class="lines"></div><?php endfor; ?>
        <div class="signature-section">
            <table style="width:100%; margin-top:6mm;">
                <tr>
                    <td style="width:60%">
                        Ginawa ngayong ika- <span class="underline" style="min-width:30px;"></span> araw ng <span class="underline" style="min-width:80px;"></span>, 20<span class="underline" style="min-width:30px;"></span>.
                    </td>
                    <td style="width:40%; text-align:right;">
                        <div class="signature-line"></div><br>
                        (Mga) Maysumbong
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="height:10mm"></td>
                </tr>
                <tr>
                    <td>
                        Tinanggap at itinala ngayong ika- <span class="underline" style="min-width:30px;"></span> araw ng <span class="underline" style="min-width:80px;"></span>, 20<span class="underline" style="min-width:30px;"></span>.
                    </td>
                    <td style="text-align:right;">
                        <?php if ($esignaturePath): ?>
                            <img src="<?= htmlspecialchars($esignaturePath) ?>" alt="E-signature" style="height:50px;max-width:180px;display:block;margin-left:auto;margin-bottom:2mm;">
                        <?php endif; ?>
                        <div class="signature-line"></div><br>
                        Punong Barangay/Pangulo ng Lupon
                    </td>
                </tr>
            </table>
        </div>
        <div class="footer-motto">"ASENSO at PROGRESO"</div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// Add this function after generateSummonsForm()
function generateSummonsPDF($pdo, $caseId) {
    $html = generateSummonsForm($pdo, $caseId);
    $pdf = new Dompdf();
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4','portrait');
    $pdf->render();
    return $pdf->output();
}



// Helper to get captain's esignature for a barangay
function getCaptainEsignature($pdo, $barangayId) {
    $stmt = $pdo->prepare("
        SELECT esignature_path 
        FROM users 
        WHERE role_id = 3 
        AND barangay_id = ? 
        AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$barangayId]);
    $path = $stmt->fetchColumn();
    
    // Return full path if exists, otherwise null
    return $path ? $path : null;
}

function getChiefOfficerEsignature($pdo, $barangayId) {
    $stmt = $pdo->prepare("
        SELECT esignature_path 
        FROM users 
        WHERE role_id = 7 
        AND barangay_id = ? 
        AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$barangayId]);
    $path = $stmt->fetchColumn();
    
    // Return full path if exists, otherwise null
    return $path ? $path : null;
}

function sendSummonsEmails($pdo, $caseId, $proposalId) {
    // Generate PDF once for all emails
    $pdfContent = generateSummonsPDF($pdo, $caseId);
    $filename = "Summons-Case-{$caseId}.pdf";

    // Fetch emails for complainants and respondents
    $stmt = $pdo->prepare("
        SELECT u.email, CONCAT(u.first_name, ' ', u.last_name) AS name
        FROM users u
        JOIN blotter_participants bp ON u.id = bp.person_id
        WHERE bp.blotter_case_id = ? AND bp.role IN ('complainant','respondent')
    ");
    $stmt->execute([$caseId]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($participants as $p) {
        $mail = new PHPMailer(true);
        try {
            // Server settings (adjust as needed)
            $mail->isSMTP();
            $mail->Host       = 'smtp.example.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'barangayhub2@gmail.com';
            $mail->Password   = 'eisy hpjz rdnt bwrp';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            // Recipients
            $mail->setFrom('noreply@barangayhub.com', 'iBarangay');
            $mail->addAddress($p['email'], $p['name']);

            // Attach PDF
            $mail->addStringAttachment($pdfContent, $filename);

            // Email content
            $mail->isHTML(true);
            $mail->Subject = 'Summons Notice for Your Blotter Case';
            $mail->Body    = 'Dear ' . htmlspecialchars($p['name']) . ',<br><br>'
                . 'You are being summoned for a hearing regarding your case. '
                . 'Please find the attached summons document.<br><br>'
                . 'Thank you,<br>iBarangay Admin';
            $mail->AltBody = 'Dear ' . $p['name'] . ', '
                . 'You are being summoned for a hearing regarding your case. '
                . 'Please find the attached summons document.';

            $mail->send();
        } catch (Exception $e) {
            error_log("Summons email failed for " . $p['email'] . ": " . $mail->ErrorInfo);
        }
    }
}



// === POST: Add New Case ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['blotter_submit'])) {
    $categories = $_POST['categories'] ?? [];
    if (empty($categories) || !is_array($categories)) {
        $_SESSION['error_message'] = 'At least one category must be selected.';
        header('Location: blotter.php');
        exit;
    }

    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['complaint'] ?? '');
    $participants = $_POST['participants'] ?? [];

    if ($location === '' || $description === '' || !is_array($participants) || count($participants) === 0) {
        $_SESSION['error_message'] = 'All fields are required and at least one participant must be added.';
        header('Location: blotter.php');
        exit;
    }

    // Check for pending schedule proposal for this location and barangay
    $stmt = $pdo->prepare("
        SELECT sp.id 
        FROM schedule_proposals sp
        JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
        WHERE bc.location = ? AND bc.barangay_id = ? AND sp.status IN ('proposed','user_confirmed','captain_confirmed')
        LIMIT 1
    ");
    $stmt->execute([$location, $bid]);
    if ($stmt->fetchColumn()) {
        $_SESSION['error_message'] = 'There is already a pending hearing schedule proposal for this location. Please resolve it before adding a new case.';
        header('Location: blotter.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Generate case number
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(case_number, -4) AS UNSIGNED)) as max_num FROM blotter_cases WHERE case_number LIKE ?");
        $stmt->execute(["%-$year-%"]);
        $result = $stmt->fetch();
        $nextNum = ($result['max_num'] ?? 0) + 1;
        $caseNumber = 'BRG-' . $year . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $pdo->prepare("
            INSERT INTO blotter_cases
            (case_number, location, description, status, barangay_id, incident_date)
            VALUES (?, ?, ?, 'pending', ?, NOW())
        ")->execute([$caseNumber, $location, $description, $bid]);
        $caseId = $pdo->lastInsertId();


        $pdo->prepare("UPDATE blotter_cases SET filing_date = NOW() WHERE id = ?")->execute([$caseId]);

        // Notify Captain and Chief Officer
        $stmt = $pdo->prepare("
            INSERT INTO case_notifications (blotter_case_id, notified_user_id, notification_type)
            SELECT ?, id, 'case_filed' 
            FROM users 
            WHERE role_id IN (3, 7) AND barangay_id = ? AND is_active = 1
        ");
        $stmt->execute([$caseId, $bid]);

        // Categories
        if (!empty($_POST['categories'])) {
            $catStmt = $pdo->prepare("
                INSERT INTO blotter_case_categories (blotter_case_id, category_id)
                VALUES (?, ?)
            ");
            foreach ($_POST['categories'] as $catId) {
                $catStmt->execute([$caseId, (int)$catId]);
            }
        }

        // Interventions
        if (!empty($_POST['interventions']) && is_array($_POST['interventions'])) {
            $intStmt = $pdo->prepare("
                INSERT INTO blotter_case_interventions
                  (blotter_case_id, intervention_id, intervened_at)
                VALUES (?, ?, NOW())
            ");
            foreach ($_POST['interventions'] as $intId) {
                $intStmt->execute([$caseId, (int)$intId]);
            }
        }

        // Participants
        $regStmt = $pdo->prepare("
            INSERT INTO blotter_participants
            (blotter_case_id, person_id, role)
            VALUES (?, ?, ?)
        ");
        $extStmt = $pdo->prepare("
            INSERT INTO external_participants (first_name, last_name, contact_number, address, age, gender)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $bpStmt = $pdo->prepare("
            INSERT INTO blotter_participants (blotter_case_id, external_participant_id, role)
            VALUES (?, ?, ?)
        ");

        $insertedParticipants = [];
        foreach ($participants as $p) {
            if (!empty($p['user_id'])) {
                // Build a unique key for registered participant
                $key = $caseId . '-' . intval($p['user_id']) . '-' . $p['role'];
                if (isset($insertedParticipants[$key])) {
                    continue;
                }
                $insertedParticipants[$key] = true;
                $regStmt->execute([$caseId, (int)$p['user_id'], $p['role']]);
            } else {
                $fname = trim($p['first_name']);
                $lname = trim($p['last_name']);
                // Build a unique key for unregistered participant
                $key = $caseId . '-null-' . $fname . '-' . $lname . '-' . $p['role'];
                if (isset($insertedParticipants[$key])) {
                    continue;
                }
                $insertedParticipants[$key] = true;
                $extStmt->execute([
                    $fname,
                    $lname,
                    $p['contact_number'] ?? null,
                    $p['address'] ?? null,
                    $p['age'] ?? null,
                    $p['gender'] ?? null
                ]);
                $externalId = $pdo->lastInsertId();
                $bpStmt->execute([$caseId, $externalId, $p['role']]);
            }
        }

        $pdo->commit();
        logAuditTrail($pdo, $current_admin_id, 'INSERT', 'blotter_cases', $caseId, "New case filed ($location)");
        $_SESSION['success_message'] = 'New blotter case recorded with case number: ' . $caseNumber;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = 'Error adding case: ' . $e->getMessage();
    }

    header('Location: blotter.php');
    exit;
}

// === AJAX actions ===
if (!empty($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $id     = intval($_GET['id'] ?? 0);

    // Role-based permission checks for different actions
    $permissionDenied = false;
    
    if (in_array($action, ['delete', 'complete', 'set_status'])) {
        if (!$canManageBlotter) $permissionDenied = true;
    }
    
    if (in_array($action, ['schedule_hearing', 'issue_cfa'])) {
        if (!$canScheduleHearings) $permissionDenied = true;
    }
    
    if (in_array($action, ['add_intervention', 'update_case', 'record_hearing'])) {
        if (!$canManageBlotter) $permissionDenied = true;
    }
    
    if (in_array($action, ['generate_report'])) {
        if (!$canGenerateReports) $permissionDenied = true;
    }
    
    if ($permissionDenied) {
        echo json_encode(['success'=>false,'message'=>'Permission denied for this action']);
        exit;
    }

    try {
        switch ($action) {

            case 'generate_summons':
                if (!$id) {
                    echo json_encode(['success'=>false,'message'=>'Invalid case ID']);
                    exit;
                }
                
                $html = generateSummonsForm($pdo, $id);
                $pdf = new Dompdf();
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('A4','portrait');
                $pdf->render();

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Summons-Case-'.$id.'.pdf"');
                echo $pdf->output();
                exit;

            case 'generate_report_form':
                if (!$id) {
                    echo json_encode(['success'=>false,'message'=>'Invalid case ID']);
                    exit;
                }
                
                $html = generateReportForm($pdo, $id);
                $pdf = new Dompdf();
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('A4','portrait');
                $pdf->render();

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Report-Form-Case-'.$id.'.pdf"');
                echo $pdf->output();
                exit;


                case 'sign_case':
                  if (!in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF])) {
                      echo json_encode(['success'=>false,'message'=>'Permission denied']);
                      exit;
                  }
                  
                  $signatureColumn = ($role == ROLE_CAPTAIN) ? 'captain_signature_date' : 'chief_signature_date';
                  
                  $stmt = $pdo->prepare("
                      UPDATE blotter_cases 
                      SET $signatureColumn = NOW()
                      WHERE id = ? AND barangay_id = ? AND status IN ('closed', 'dismissed')
                  ");
                  
                  if ($stmt->execute([$id, $bid])) {
                      // Check if both signatures are present
                      $stmt = $pdo->prepare("
                          SELECT captain_signature_date, chief_signature_date 
                          FROM blotter_cases 
                          WHERE id = ?
                      ");
                      $stmt->execute([$id]);
                      $signatures = $stmt->fetch(PDO::FETCH_ASSOC);
                      
                      if ($signatures['captain_signature_date'] && $signatures['chief_signature_date']) {
                          $pdo->prepare("UPDATE blotter_cases SET status = 'completed' WHERE id = ?")->execute([$id]);
                      }
                      
                      echo json_encode(['success'=>true, 'message'=>'Case signed successfully']);
                  } else {
                      echo json_encode(['success'=>false,'message'=>'Failed to sign case']);
                  }
                  break;

            case 'generate_report':
                $year  = intval($_GET['year']  ?? date('Y'));
                $month = intval($_GET['month'] ?? date('n'));

                // Fetch the latest monthly report for the given year/month
                $stmt = $pdo->prepare("
                    SELECT
                        m.*,
                        CONCAT(u.first_name, ' ', u.last_name) AS prepared_by_name
                    FROM monthly_reports m
                    JOIN users u ON m.prepared_by_user_id = u.id
                    WHERE m.report_year  = :y
                      AND m.report_month = :m
                      AND m.barangay_id = :bid
                    ORDER BY m.id DESC
                    LIMIT 1
                ");
                $stmt->execute(['y'=>$year,'m'=>$month,'bid'=>$bid]);
                $report = $stmt->fetch(PDO::FETCH_ASSOC);

                // Always run the details query
                $dStmt = $pdo->prepare("
                    SELECT
                        c.name AS category_name,
                        COUNT(DISTINCT bc.id) AS total_cases,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id FROM case_interventions WHERE name = 'M/CSWD'
                          ), bc.id, NULL
                        )) AS mcwsd,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id FROM case_interventions WHERE name = 'PNP'
                          ), bc.id, NULL
                        )) AS total_pnp,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id FROM case_interventions WHERE name = 'Court'
                          ), bc.id, NULL
                        )) AS total_court,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id FROM case_interventions WHERE name = 'Issued BPO'
                          ), bc.id, NULL
                        )) AS total_bpo,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id FROM case_interventions WHERE name = 'Medical'
                          ), bc.id, NULL
                        )) AS total_medical
                    FROM case_categories c
                    LEFT JOIN blotter_case_categories bcc
                      ON c.id = bcc.category_id
                    LEFT JOIN blotter_cases bc
                      ON bc.id = bcc.blotter_case_id
                        AND YEAR(COALESCE(bc.incident_date, bc.created_at)) = :y
                        AND MONTH(COALESCE(bc.incident_date, bc.created_at)) = :m
                        AND bc.barangay_id = :bid
                        AND bc.status != 'Deleted'
                    LEFT JOIN blotter_case_interventions bci
                      ON bci.blotter_case_id = bc.id
                    GROUP BY c.id
                    ORDER BY c.name
                ");
                $dStmt->execute([
                    'y' => $year,
                    'm' => $month,
                    'bid' => $bid
                ]);
                $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);

                // If no monthly report and no cases, show friendly message and exit
                $hasCases = false;
                foreach ($details as $row) {
                    if ($row['total_cases'] > 0) {
                        $hasCases = true;
                        break;
                    }
                }
                if (!$report && !$hasCases) {
                    header('Content-Type: text/html');
                    echo "<!DOCTYPE html><html><head><title>No Report</title></head><body style='font-family:sans-serif;padding:2em;'><h2>No monthly report found for the selected period.</h2><p>Please ensure a report has been created for this barangay, year, and month.</p><a href='blotter.php' style='color:#2563eb;'>Back to Blotter Cases</a></body></html>";
                    exit;
                }

                // Build HTML payload
                ob_start(); ?>
                <!doctype html>
                <html><head>
                  <meta charset="utf-8">
                  <style>
                    body { font-family: 'DejaVu Sans', sans-serif; }
                    table { width:100%; border-collapse:collapse; margin-top:1rem; }
                    th,td { border:1px solid #333; padding:6px; text-align:center; }
                    th { background:#eee; }
                  </style>
                </head><body>
                  <h1>Monthly Report – <?= htmlspecialchars("$month/$year") ?></h1>
                  <p>
                    Prepared by <?= htmlspecialchars($report['prepared_by_name'] ?? 'N/A') ?>
                    on <?= !empty($report['submitted_at']) && $report['submitted_at'] !== '0000-00-00 00:00:00'
                            ? date('M j, Y g:i A', strtotime($report['submitted_at']))
                            : 'N/A' ?>
                  </p>
                    <table>
                      <thead>
                        <tr>
                          <th>Category</th>
                          <th>Total Cases</th>
                          <th>M/CSWD</th>
                          <th>PNP</th>
                          <th>Court</th>
                          <th>BPO</th>
                          <th>Medical</th>
                        </tr>
                      </thead>
                    <tbody>
                      <?php foreach ($details as $row): ?>
                      <tr>
                      <td><?= htmlspecialchars($row['category_name']) ?></td>
                      <td><?= $row['total_cases'] ?></td>
                      <td><?= $row['mcwsd'] ?></td>      
                      <td><?= $row['total_pnp'] ?></td>
                      <td><?= $row['total_court'] ?></td>
                      <td><?= $row['total_bpo'] ?></td>
                      <td><?= $row['total_medical'] ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </body></html>
                <?php
                $html = ob_get_clean();
                
                $pdf = new Dompdf();
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('A4','landscape');
                $pdf->render();

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Report-'.$year.'-'.$month.'.pdf"');
                echo $pdf->output();
                exit;

            case 'delete':
                $pdo->prepare("UPDATE blotter_cases SET status='dismissed' WHERE id=?")
                    ->execute([$id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, 'Status → Dismissed');
                echo json_encode(['success'=>true]);
                break;

            case 'complete':
                $pdo->prepare("UPDATE blotter_cases SET status='closed' WHERE id=?")
                    ->execute([$id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, 'Status → Closed');
                echo json_encode(['success'=>true]);
                break;

            case 'set_status':
                $newStatus = $_GET['new_status'] ?? '';
                if (!in_array($newStatus, $allowedStatuses, true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid status']);
                    exit;
                }
                $pdo->prepare("UPDATE blotter_cases SET status=? WHERE id=?")
                    ->execute([$newStatus, $id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, "Status → $newStatus");
                echo json_encode(['success'=>true]);
                break;

            case 'get_case_details':
                $caseStmt = $pdo->prepare("
                    SELECT bc.*, bc.status AS status, bc.incident_date AS date_reported, 
                           GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories
                    FROM blotter_cases bc
                    LEFT JOIN blotter_case_categories bcc ON bc.id = bcc.blotter_case_id
                    LEFT JOIN case_categories cc ON bcc.category_id = cc.id
                    WHERE bc.id = ?
                    GROUP BY bc.id
                ");
                $caseStmt->execute([$id]);
                $caseData = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                
                $pStmt = $pdo->prepare("
                  SELECT 
                    bp.id AS participant_id,
                    bp.person_id,
                    bp.external_participant_id,
                    COALESCE(p.first_name, ep.first_name) AS first_name,
                    COALESCE(p.last_name, ep.last_name) AS last_name,
                    COALESCE(p.contact_number, ep.contact_number) AS contact_number,
                    COALESCE(CONCAT(a.house_no, ' ', a.street, ', ', b.name), ep.address) AS address,
                    COALESCE(FLOOR(DATEDIFF(CURDATE(), p.birth_date)/365), ep.age) AS age,
                    COALESCE(p.gender, ep.gender) AS gender,
                    bp.role,
                    CASE 
                      WHEN bp.person_id IS NOT NULL THEN 'registered'
                      WHEN bp.external_participant_id IS NOT NULL THEN 'external'
                      ELSE 'unknown'
                    END AS participant_type
                  FROM blotter_participants bp
                  LEFT JOIN persons p ON bp.person_id = p.id
                  LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
                  LEFT JOIN barangay b ON a.barangay_id = b.id
                  LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
                  WHERE bp.blotter_case_id = ?
                ");
                $pStmt->execute([$id]);
                $participants = $pStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $iStmt = $pdo->prepare("
                    SELECT ci.id AS intervention_id, ci.name AS intervention_name, 
                           bci.intervened_at, bci.remarks
                    FROM blotter_case_interventions bci
                    JOIN case_interventions ci ON bci.intervention_id = ci.id
                    WHERE bci.blotter_case_id = ?
                ");
                $iStmt->execute([$id]);
                $interventions = $iStmt->fetchAll(PDO::FETCH_ASSOC);

                // Get hearings data
                $hStmt = $pdo->prepare("
                    SELECT ch.*, 
                           ch.hearing_date,
                           ch.presiding_officer_name,
                           ch.presiding_officer_position,
                           ch.hearing_outcome,
                           ch.resolution_details,
                           ch.is_mediation_successful
                    FROM case_hearings ch
                    WHERE ch.blotter_case_id = ?
                    ORDER BY ch.hearing_number ASC
                ");
                $hStmt->execute([$id]);
                $hearings = $hStmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'case' => $caseData,
                    'participants' => $participants,
                    'interventions' => $interventions,
                    'hearings' => $hearings
                ]);
                exit;

            case 'add_intervention':
                $data = json_decode(file_get_contents('php://input'), true);
                if (empty($data['intervention_id']) || empty($data['date_intervened'])) {
                    echo json_encode(['success'=>false,'message'=>'Invalid data']);
                    exit;
                }
                $pdo->prepare("
                    INSERT INTO blotter_case_interventions
                    (blotter_case_id, intervention_id, intervened_at, remarks)
                    VALUES (?, ?, ?, ?)
                ")->execute([
                    $id,
                    $data['intervention_id'],
                    $data['date_intervened'],
                    $data['remarks'] ?? null
                ]);
                echo json_encode(['success'=>true]);
                break;

                case 'schedule_hearing':
                  // Check if user can schedule (Captain or Chief Officer)
                  if (!in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF])) {
                      echo json_encode(['success'=>false,'message'=>'Only Captain or Chief Officer can schedule hearings']);
                      exit;
                  }
                  
                  // Check if case is accepted and within 5-day deadline
                  $stmt = $pdo->prepare("
                      SELECT bc.*, 
                             DATE_ADD(bc.filing_date, INTERVAL 5 DAY) as deadline,
                             COUNT(ch.id) as hearing_count
                      FROM blotter_cases bc
                      LEFT JOIN case_hearings ch ON bc.id = ch.blotter_case_id
                      WHERE bc.id = ? AND bc.barangay_id = ?
                      GROUP BY bc.id
                  ");
                  $stmt->execute([$id, $bid]);
                  $case = $stmt->fetch(PDO::FETCH_ASSOC);
                  
                  if (!$case) {
                      echo json_encode(['success'=>false,'message'=>'Case not found']);
                      exit;
                  }
                  
                  if ($case['status'] === 'pending') {
                      echo json_encode(['success'=>false,'message'=>'Case must be accepted before scheduling']);
                      exit;
                  }
                  
                  if ($case['hearing_count'] >= 3) {
                      echo json_encode(['success'=>false,'message'=>'Maximum of 3 hearings per case reached']);
                      exit;
                  }
                  
                  if (new DateTime() > new DateTime($case['deadline'])) {
                      echo json_encode(['success'=>false,'message'=>'Scheduling deadline exceeded (5 days from filing)']);
                      exit;
                  }
                  
                  // Check if there's a pending hearing
                  $stmt = $pdo->prepare("
                      SELECT id FROM case_hearings 
                      WHERE blotter_case_id = ? AND hearing_outcome = 'scheduled'
                  ");
                  $stmt->execute([$id]);
                  if ($stmt->fetch()) {
                      echo json_encode(['success'=>false,'message'=>'Complete current hearing before scheduling new one']);
                      exit;
                  }
              
                  $data = json_decode(file_get_contents('php://input'), true);
                  if (empty($data['hearing_date']) || empty($data['hearing_time'])) {
                      echo json_encode(['success'=>false,'message'=>'Hearing date and time are required']);
                      exit;
                  }
              
                  // Check if user is Captain or Chief Officer
                  $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
                  $stmt->execute([$current_admin_id]);
                  $userRole = $stmt->fetchColumn();
                  
                  if (!in_array($userRole, [ROLE_CAPTAIN, ROLE_CHIEF])) {
                      echo json_encode(['success'=>false,'message'=>'Only Barangay Captain or Chief Officer can schedule hearings.']);
                      exit;
                  }
              
                  // Hearing date must be within 5 days from today (inclusive)
                  $hearingDate = $data['hearing_date'];
                  $today = new DateTime();
                  $minDate = $today->format('Y-m-d');
                  $maxDate = (clone $today)->modify('+5 days')->format('Y-m-d');
                  if ($hearingDate < $minDate || $hearingDate > $maxDate) {
                      echo json_encode(['success'=>false,'message'=>'Hearing date must be within the next 5 days (including today).']);
                      exit;
                  }
              
                  // Check for existing pending proposal for this case
                  $stmt = $pdo->prepare("SELECT id FROM schedule_proposals WHERE blotter_case_id=? AND status IN ('proposed','user_confirmed','captain_confirmed')");
                  $stmt->execute([$id]);
                  if ($stmt->fetch()) {
                      echo json_encode(['success'=>false,'message'=>'There is already a pending schedule proposal for this case.']);
                      exit;
                  }
              
                  $pdo->beginTransaction();
                  // Insert schedule proposal
                  $stmt = $pdo->prepare("
                      INSERT INTO schedule_proposals
                      (blotter_case_id, proposed_by_user_id, proposed_date, proposed_time, hearing_location, presiding_officer, presiding_officer_position, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_user_confirmation')
                  ");
                  $stmt->execute([
                      $id,
                      $current_admin_id,
                      $data['hearing_date'],
                      $data['hearing_time'],
                      $data['hearing_location'] ?? 'Barangay Hall',
                      $data['presiding_officer'] ?? ($userRole === ROLE_CAPTAIN ? 'Barangay Captain' : 'Chief Officer'),
                      $data['officer_position'] ?? ($userRole === ROLE_CAPTAIN ? 'barangay_captain' : 'chief_officer')
                  ]);
                  $proposalId = $pdo->lastInsertId();
              
                  // Send summons emails to all parties using PHPMailer
                  sendSummonsEmails($pdo, $id, $proposalId);
              
                  $pdo->commit();
                  logAuditTrail($pdo, $current_admin_id, 'INSERT', 'schedule_proposals', $proposalId, "Scheduled hearing proposal for case $id");
                  echo json_encode(['success'=>true, 'message'=>'Summons sent to all parties. Awaiting confirmations.']);
                  break;


            case 'record_hearing':
                $data = json_decode(file_get_contents('php://input'), true);
                $hearingId = intval($data['hearing_id'] ?? 0);
                
                if (!$hearingId) {
                    echo json_encode(['success'=>false,'message'=>'Invalid hearing ID']);
                    exit;
                }

                $pdo->beginTransaction();
                
                // Update hearing outcome
                $pdo->prepare("
                    UPDATE case_hearings 
                    SET hearing_outcome = ?, 
                        resolution_details = ?,
                        is_mediation_successful = ?,
                        hearing_notes = ?
                    WHERE id = ?
                ")->execute([
                    $data['outcome'],
                    $data['resolution'] ?? '',
                    ($data['outcome'] === 'mediation_successful') ? 1 : 0,
                    $data['notes'] ?? '',
                    $hearingId
                ]);
                
                // Record attendance if provided
                if (!empty($data['attendance'])) {
                    foreach ($data['attendance'] as $attendance) {
                        $pdo->prepare("
                            INSERT INTO hearing_attendance (hearing_id, participant_id, attended, attendance_remarks)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                            attended = VALUES(attended),
                            attendance_remarks = VALUES(attendance_remarks)
                        ")->execute([
                            $hearingId,
                            $attendance['participant_id'],
                            $attendance['attended'] ? 1 : 0,
                            $attendance['remarks'] ?? ''
                        ]);
                    }
                }
                
                // Update case status based on hearing outcome
                updateCaseStatus($pdo, $id, $data['outcome'], $current_admin_id);
                
                $pdo->commit();
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'case_hearings', $hearingId, "Recorded hearing outcome: " . $data['outcome']);
                echo json_encode(['success'=>true]);
                break;

            case 'issue_cfa':
                $data = json_decode(file_get_contents('php://input'), true);
                $complainantId = intval($data['complainant_id'] ?? 0);
                
                if (!$complainantId) {
                    echo json_encode(['success'=>false,'message'=>'Invalid complainant ID']);
                    exit;
                }
                
                $certNumber = generateCFACertificate($pdo, $id, $complainantId, $current_admin_id);
                logAuditTrail($pdo, $current_admin_id, 'INSERT', 'cfa_certificates', $id, "Issued CFA certificate: $certNumber");
                echo json_encode(['success'=>true, 'certificate_number'=>$certNumber]);
                break;

            case 'get_available_schedules':
                $stmt = $pdo->prepare("
                    SELECT * FROM hearing_schedules 
                    WHERE hearing_date >= CURDATE() 
                    AND is_available = TRUE 
                    AND current_bookings < max_hearings_per_slot
                    ORDER BY hearing_date, hearing_time
                    LIMIT 20
                ");
                $stmt->execute();
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true, 'schedules'=>$schedules]);
                break;

            case 'update_case':
                $input = json_decode(file_get_contents('php://input'), true);
                $cid   = intval($input['case_id'] ?? 0);
                $loc   = trim($input['location'] ?? '');
                $descr = trim($input['description'] ?? '');
                $stat  = $input['status'] ?? '';
                if (!$cid || $loc==='' || $descr==='' || !in_array($stat, $allowedStatuses, true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid data']);
                    exit;
                }
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("
                        UPDATE blotter_cases
                        SET location=?, description=?, status=?
                        WHERE id=?
                    ")->execute([$loc, $descr, $stat, $cid]);

                    $pdo->prepare("DELETE FROM blotter_case_interventions WHERE blotter_case_id=?")
                        ->execute([$cid]);
                    if (!empty($input['interventions']) && is_array($input['interventions'])) {
                        $intStmt = $pdo->prepare("
                            INSERT INTO blotter_case_interventions (blotter_case_id, intervention_id, intervened_at)
                            VALUES (?, ?, NOW())
                        ");
                        foreach ($input['interventions'] as $intId) {
                            $intStmt->execute([$cid, (int)$intId]);
                        }
                    }

                    $pdo->prepare("DELETE FROM blotter_participants WHERE blotter_case_id=?")
                        ->execute([$cid]);

                    $regStmt = $pdo->prepare("
                        INSERT INTO blotter_participants
                        (blotter_case_id, person_id, role)
                        VALUES (?, ?, ?)
                    ");
                    $extStmt = $pdo->prepare("
                        INSERT INTO external_participants (first_name, last_name, contact_number, address, age, gender)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $bpStmt = $pdo->prepare("
                        INSERT INTO blotter_participants (blotter_case_id, external_participant_id, role)
                        VALUES (?, ?, ?)
                    ");

                    $insertedParticipants = [];
                    foreach ($input['participants'] as $p) {
                        if (!empty($p['user_id'])) {
                            $regStmt->execute([$cid, (int)$p['user_id'], $p['role']]);
                        } else {
                            $fname = trim($p['first_name']);
                            $lname = trim($p['last_name']);
                            $extStmt->execute([
                                $fname,
                                $lname,
                                $p['contact_number'] ?? null,
                                $p['address'] ?? null,
                                $p['age'] ?? null,
                                $p['gender'] ?? null
                            ]);
                            $externalId = $pdo->lastInsertId();
                            $bpStmt->execute([$cid, $externalId, $p['role']]);
                        }
                    }
                    $pdo->commit();
                    logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $cid, "Edited case #{$cid}");
                    echo json_encode(['success'=>true]);
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
                }
                break;

            case 'confirm_schedule':
                // Mark that the current respondent (User or Captain) confirms availability.
                // Update an imaginary field 'schedule_confirmations' (assume stored as JSON or two boolean fields)
                // For simplicity, assume we update a field on blotter_cases; here we set 'hearing_count' to negative value as approved.
                $stmt = $pdo->prepare("UPDATE blotter_cases SET status='approved' WHERE id=?");
                $stmt->execute([$id]);
                // Log audit for confirmation
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, "Schedule confirmed by party");
                echo json_encode(['success'=>true]);
                break;

            case 'reject_schedule':
                // Record conflict remark from either party
                $data = json_decode(file_get_contents('php://input'), true);
                $remark = $data['remark'] ?? 'No remark provided';
                // Assume we add the conflict remark in a field called resolution_details
                $stmt = $pdo->prepare("UPDATE blotter_cases SET status='conflict', resolution_details=? WHERE id=?");
                $stmt->execute([$remark, $id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, "Schedule rejected: " . $remark);
                echo json_encode(['success'=>true]);
                break;

            case 'propose_schedule':
                // Called by Captain from add_staff_official_barangaycaptian.php
                $hearing_date = $_POST['hearing_date'] ?? '';
                $hearing_time = $_POST['hearing_time'] ?? '';
                $remarks = $_POST['remarks'] ?? '';
                if(!$hearing_date || !$hearing_time){
                    echo json_encode(['success'=>false, 'message'=>'Date and time required']);
                    exit;
                }
                // Insert schedule proposal in a new schedule table or update the blotter_cases proposed schedule fields.
                $schedule = $hearing_date . ' ' . $hearing_time;
                $stmt = $pdo->prepare("UPDATE blotter_cases SET scheduled_hearing=?, status='pending_confirmation', resolution_details=? WHERE id=?");
                // For demo, assume $id is passed by GET or pre-defined
                $stmt->execute([$schedule, $remarks, $id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, "Proposed schedule: $schedule. Remarks: $remarks");
                echo json_encode(['success'=>true]);
                break;

            default:
                echo json_encode(['success'=>false,'message'=>'Unknown action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

require_once "../components/header.php";

// Fetch for UI
$stmt = $pdo->prepare("
    SELECT bc.*, GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories,
           bc.accepted_by_user_id, bc.filing_date, bc.scheduling_deadline,
           bc.captain_signature_date, bc.chief_signature_date,
           COUNT(ch.id) as hearing_count,
           EXISTS(SELECT 1 FROM case_hearings WHERE blotter_case_id = bc.id AND hearing_outcome = 'scheduled') as has_pending_hearing,
           (SELECT COUNT(*) FROM blotter_case_interventions WHERE blotter_case_id = bc.id) as intervention_count
    FROM blotter_cases bc
    LEFT JOIN blotter_case_categories bcc ON bc.id=bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id=cc.id
    LEFT JOIN case_hearings ch ON bc.id = ch.blotter_case_id
    WHERE bc.barangay_id=? AND bc.status!='deleted'
    GROUP BY bc.id
    ORDER BY bc.created_at DESC
");
$stmt->execute([$bid]);
$cases         = $stmt->fetchAll(PDO::FETCH_ASSOC);
$categories    = $pdo->query("SELECT * FROM case_categories ORDER BY name")->fetchAll();
$residents     = getResidents($pdo, $bid);
$interventions = $pdo->query("SELECT * FROM case_interventions ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Blotter Case Management with Hearing Process</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@10"></script>
  <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
  <style>
    /* Hearing table styles */
    .hearing-table {
        margin-top: 20px;
        border-collapse: collapse;
        width: 100%;
    }
    .hearing-table th,
    .hearing-table td {
        border: 1px solid #e5e7eb;
        padding: 8px 12px;
        text-align: left;
    }
    .hearing-table th {
        background-color: #f9fafb;
        font-weight: 600;
    }
    .hearing-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .hearing-actions button {
        padding: 4px 8px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-size: 12px;
    }
    .btn-schedule { background-color: #3b82f6; color: white; }
    .btn-record { background-color: #10b981; color: white; }
    .btn-cfa { background-color: #dc2626; color: white; }
    .btn-summons { background-color: #7c3aed; color: white; }
    .btn-report-form { background-color: #059669; color: white; }
    .status-badge {
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    .status-pending { background-color: #fef3c7; color: #92400e; }
    .status-open { background-color: #d1fae5; color: #065f46; }
    .status-solved { background-color: #c7f2c9; color: #166534; }
    .status-cfa-eligible { background-color: #fecaca; color: #991b1b; }
    .status-endorsed-to-court { background-color: #ddd6fe; color: #5b21b6; }
    
    /* Document buttons styling */
    .document-buttons {
        display: flex;
        gap: 4px;
        margin-top: 4px;
    }
    .document-buttons button {
        padding: 2px 6px;
        font-size: 10px;
        border-radius: 3px;
        border: none;
        cursor: pointer;
        color: white;
    }

    /* New styles for intervention indicator */
    .intervention-indicator {
        display: inline-block;
        font-size: 11px;
        background-color: #8b5cf6;
        color: white;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 4px;
        vertical-align: middle;
    }
    
    .intervention-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        background-color: #8b5cf6;
        color: white;
        border-radius: 50%;
        font-size: 12px;
        margin-right: 4px;
        font-weight: bold;
    }
  </style>
</head>
<body>
<section id="blotter" class="p-6">

<!-- Edit Modal -->
<div id="editBlotterModal" tabindex="-1"
     class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
  <div class="relative w-full max-w-4xl max-h-full mx-auto">
    <div class="relative bg-white rounded-lg shadow">
      <!-- Header -->
      <div class="flex items-start justify-between p-5 border-b rounded-t">
        <h3 class="text-xl font-semibold text-gray-900">Edit Case</h3>
        <button type="button" onclick="toggleEditBlotterModal()"
                class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center">
          <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
               viewBox="0 0 14 14">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
          </svg>
        </button>
      </div>
      <!-- Form -->
      <form id="editBlotterForm" class="p-6 space-y-4 overflow-y-auto max-h-[calc(100%-6rem)]">
        <input type="hidden" id="editCaseId" name="case_id">
        <div class="grid gap-4 md:grid-cols-2">
          <!-- Location -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Location <span class="text-red-500">*</span></label>
            <input id="editLocation" name="location" type="text" required
                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
          </div>
          <!-- Description -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Description <span class="text-red-500">*</span></label>
            <textarea id="editDescription" name="description" rows="4" required
                      class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500"></textarea>
          </div>
          <!-- Interventions -->
        <div class="space-y-2">
          <label class="block text-sm font-medium text-gray-700">Interventions</label>
          <div id="editInterventionContainer" class="grid grid-cols-2 gap-2">
            <?php foreach ($interventions as $int): ?>
              <label class="flex items-center gap-2">
                <input
                  type="checkbox"
                  name="interventions[]"
                  value="<?= $int['id'] ?>"
                >
                <?= htmlspecialchars($int['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
          <!-- Categories -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <select id="editStatus" name="status" required
                    class="w-full p-2 border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500">
              <option value="">Select Status</option>
              <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= $status ?>"><?= ucfirst(str_replace('_', ' ', $status)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <!-- Participants -->
        <div class="space-y-2">
          <label class="block text-sm font-medium text-gray-700">Participants</label>
          <div id="editParticipantContainer" class="space-y-2"></div>
          <div class="flex gap-2">
            <button type="button" id="editAddRegisteredBtn"
                    class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
              + Add Registered Resident
            </button>
            <button type="button" id="editAddUnregisteredBtn"
                    class="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm">
              + Add Unregistered Person
            </button>
          </div>
        </div>

        <!-- Hearings Section -->
                                                                               <div class="space-y-2">
          <label class="block text-sm font-medium text-gray-700">Hearings</label>
          <div id="editHearingsContainer">
            <!-- Hearings will be populated here -->
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end pt-6 space-x-3 border-t border-gray-200">
          <button type="button" id="editCancelBtn" onclick="toggleEditBlotterModal()"
                  class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900">
            Cancel
          </button>
          <button type="submit"
                  class="text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center">
            Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div id="addBlotterModal" tabindex="-1"
     class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
  <div class="relative w-full max-w-2xl max-h-full mx-auto">
    <div class="relative bg-white rounded-lg shadow">
      <!-- Header -->
      <div class="flex items-start justify-between p-5 border-b rounded-t">
        <h3 class="text-xl font-semibold text-gray-900">Add New Case</h3>
        <button type="button" onclick="toggleAddBlotterModal()"
                class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-8 h-8 ml-auto inline-flex justify-center items-center">
          <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none"
               viewBox="0 0 14 14">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
          </svg>
        </button>
      </div>
      <!-- Form -->
      <form 
         id="addBlotterForm"
          method="POST"
          enctype="multipart/form-data"
          class="p-6 space-y-4 overflow-y-auto max-h-[calc(100%-6rem)]"
      >
        <div class="grid gap-4 md:grid-cols-2">
          <!-- Location -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Location <span class="text-red-500">*</span></label>
            <input name="location" type="text" required placeholder="e.g. Barangay Hall"
                   class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
          </div>
          <!-- Description -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Description <span class="text-red-500">*</span></label>
            <textarea name="complaint" rows="4" required placeholder="Enter details..."
                      class="block p-2.5 w-full text-sm text-gray-900 bg-gray-50 rounded-lg border border-gray-300 focus:ring-blue-500 focus:border-blue-500"></textarea>
          </div>
                <!-- Interventions -->
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Interventions</label>
        <div class="grid grid-cols-2 gap-2">
          <?php foreach ($interventions as $int): ?>
            <label class="flex items-center gap-2">
              <input type="checkbox" name="interventions[]" value="<?= $int['id'] ?>" 
                     class="rounded">
              <span class="text-sm"><?= htmlspecialchars($int['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
          <!-- Categories -->
          <div>
          <label class="block text-sm font-medium text-gray-700">Categories <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-2 gap-2">
            <?php foreach ($categories as $i => $cat): ?>
              <label class="flex items-center gap-2">
                <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" 
                       class="rounded" <?= $i === 0 ? 'required' : '' ?>>
                <span class="text-sm"><?= htmlspecialchars($cat['name']) ?></span>
              </label>
            <?php endforeach; ?>
            </div>
          </div>
          <!-- Participants (span both columns) -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">Participants</label>
            <div id="participantContainer" class="space-y-2"></div>
            <div class="flex gap-2 mt-2">
              <button type="button" id="addRegisteredBtn"
                      class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                + Add Registered Resident
              </button>
              <button type="button" id="addUnregisteredBtn"
                      class="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm">
                + Add Unregistered Person
              </button>
            </div>
          </div>
        </div>
        <!-- Footer -->
        <div class="flex items-center justify-end pt-6 space-x-3 border-t border-gray-200">
          <button type="button" id="cancelBtn" onclick="toggleAddBlotterModal()"
                  class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-blue-300 rounded-lg border border-gray-200 text-sm font-medium px-5 py-2.5 hover:text-gray-900">
            Cancel
          </button>
          <button type="submit" name="blotter_submit"
                  class="text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5 text-center">
            Submit
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<section id="docRequests" class="mb-10">
  <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 space-y-4 md:space-y-0">
    <!-- Title -->
    <h1 class="text-3xl font-bold text-blue-800">
      Blotter Cases with Hearing Process
    </h1>
    <!-- Action buttons -->
    <div class="flex flex-col sm:flex-row sm:space-x-4 w-full md:w-auto">
      <?php if ($canManageBlotter): ?>
      <button 
        id="openModalBtn"
        class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5"
      >
        + Add New Case
      </button>
      <?php endif; ?>
      
      <?php if ($canGenerateReports): ?>
      <a
        href="?action=generate_report&year=<?=date('Y')?>&month=<?=date('n')?>"
        class="w-full sm:w-auto inline-block text-center text-white bg-indigo-600 hover:bg-indigo-700 
               focus:ring-4 focus:ring-indigo-300 font-medium rounded-lg text-sm px-5 py-2.5"
      >
        Generate <?=date('F Y')?> Report
      </a>
      <?php endif; ?>
    </div>
  </header>
  
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reported By</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Reported</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categories</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hearings</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if ($cases): foreach ($cases as $case): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <td class="px-4 py-3 text-sm text-gray-900">
            <?php
              $reporter = 'System Filed';
              $date = $case['incident_date'] ?: $case['created_at'];
              echo htmlspecialchars($reporter ?: '—');
            ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-900">
            <?php
              $date = !empty($case['incident_date']) ? $case['incident_date'] : ($case['created_at'] ?? null);
              echo $date ? date('M d, Y h:i A', strtotime($date)) : '—';
            ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($case['location']) ?></td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= $case['categories'] ?: 'None' ?></td>
          <td class="px-4 py-3">
            <?php if ($role <= 2): ?>
              <select class="status-select text-xs border rounded px-2 py-1" data-id="<?= $case['id'] ?>">
                <?php foreach ($allowedStatuses as $status): ?>
                  <option value="<?= $status ?>" <?= $case['status'] === $status ? 'selected' : '' ?>>
                    <?= ucfirst(str_replace('_', ' ', $status)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $case['status'])) ?>">
                <?= ucfirst(str_replace('_', ' ', $case['status'])) ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600">
            <?= ($case['hearing_count'] ?? 0) ?>/3
            <?php if (!empty($case['is_cfa_eligible'])): ?>
              <span class="text-red-600 font-medium">(CFA Eligible)</span>
            <?php endif; ?>
            <?php if (!empty($case['intervention_count']) && $case['intervention_count'] > 0): ?>
              <span class="intervention-indicator" title="<?= $case['intervention_count'] ?> intervention(s) recorded">
                <span class="intervention-icon">✓</span><?= $case['intervention_count'] ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600">
            <div class="hearing-actions">
            <?php if ($canManageBlotter): ?>
                <button class="edit-btn text-indigo-600 hover:text-indigo-900" data-id="<?= $case['id'] ?>">Edit</button>
                <!-- Document Generation Buttons -->
                <div class="document-buttons">
                    <?php if ($canManageBlotter): ?>
                    <button class="btn-summons generate-summons-btn" data-id="<?= $case['id'] ?>" title="Generate Summons">
                      📋 Summons
                    </button>
                    <button class="btn-report-form generate-report-form-btn" data-id="<?= $case['id'] ?>" title="Generate Report Form">
                      📄 Report
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($canScheduleHearings): ?>
                <?php if (($case['hearing_count'] ?? 0) < 3 && !in_array($case['status'], ['solved', 'endorsed_to_court', 'dismissed'])): ?>
                  <button class="btn-schedule schedule-hearing-btn" data-id="<?= $case['id'] ?>">Schedule Hearing</button>
                <?php endif; ?>
                <?php if (!empty($case['is_cfa_eligible']) && $case['status'] === 'cfa_eligible'): ?>
                  <button class="btn-cfa issue-cfa-btn" data-id="<?= $case['id'] ?>">Issue CFA</button>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($canManageBlotter): ?>
                <?php if ($case['status'] !== 'closed' && $case['status'] !== 'dismissed'): ?>
                  <button class="complete-btn text-green-600 hover:text-green-900" data-id="<?= $case['id'] ?>">Close</button>
                <?php endif; ?>
                <?php if ($case['status'] !== 'dismissed' && $case['status'] !== 'closed'): ?>
                  <button class="delete-btn text-red-600 hover:text-red-900" data-id="<?= $case['id'] ?>">Dismiss</button>
                <?php endif; ?>
                <?php if (in_array($case['status'], ['closed', 'solved', 'dismissed']) && empty($case['intervention_count'])): ?>
                  <button class="intervention-btn text-purple-600 hover:text-purple-900" data-id="<?= $case['id'] ?>">Intervene</button>
                <?php elseif (!empty($case['intervention_count'])): ?>
                  <span class="text-purple-600 font-medium flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Intervened
                  </span>
                <?php endif; ?>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="7" class="px-4 py-4 text-center text-gray-500">No cases found</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- View Modal -->
<div id="viewBlotterModal" tabindex="-1"
     class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0
            h-[calc(100%-1rem)] max-h-full">
  <div class="relative w-full max-w-4xl max-h-full mx-auto">
    <div class="relative bg-white rounded-lg shadow">
      <!-- Header -->
      <div class="flex items-start justify-between p-5 border-b rounded-t">
        <h3 class="text-xl font-semibold text-gray-900">Case Details</h3>
        <button type="button" onclick="toggleViewBlotterModal()"
                class="text-gray-400 hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm
                       w-8 h-8 ml-auto inline-flex justify-center items-center">
          <svg class="w-3 h-3" xmlns="http://www.w3.org/2000/svg" fill="none"
               viewBox="0 0 14 14" aria-hidden="true">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
          </svg>
        </button>
      </div>
      <!-- Body -->
      <div class="p-6 space-y-4 overflow-y-auto max-h-[calc(100%-6rem)] text-sm text-gray-800">
        <p><strong>Case Number:</strong> <span id="viewCaseNumber">—</span></p>
        <p><strong>Date:</strong> <span id="viewDate">—</span></p>
        <p><strong>Location:</strong> <span id="viewLocation">—</span></p>
        <p><strong>Description:</strong> <span id="viewDescription">—</span></p>
        <p><strong>Categories:</strong> <span id="viewCategories">—</span></p>
        <p><strong>Status:</strong> <span id="viewStatus">—</span></p>
        
        <h4 class="mt-4 text-lg font-medium">Participants</h4>
        <ul id="viewParticipants" class="list-disc pl-5 space-y-1"><li>—</li></ul>
        
        <h4 class="mt-4 text-lg font-medium">Interventions</h4>
        <ul id="viewInterventions" class="list-disc pl-5 space-y-1"><li>—</li></ul>
        
        <h4 class="mt-4 text-lg font-medium">Hearings History</h4>
        <div id="viewHearings">
          <table class="hearing-table">
            <thead>
              <tr>
                <th>Hearing #</th>
                <th>Date & Time</th>
                <th>Presiding Officer</th>
                <th>Outcome</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="viewHearingsBody">
              <tr><td colspan="5">No hearings scheduled</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <!-- Footer -->
      <div class="flex items-center justify-end p-5 border-t border-gray-200">
        <button type="button" onclick="toggleViewBlotterModal()"
                class="text-gray-500 bg-white hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-blue-300
                       font-medium rounded-lg text-sm px-5 py-2.5 border border-gray-200">
          Close
        </button>
      </div>
    </div>
  </div>
</div>

  <?php if (isset($_SESSION['success_message'])): ?>
    <div class="p-4 mb-4 text-green-800 bg-green-100 rounded"><?= htmlspecialchars($_SESSION['success_message']) ?></div>
    <?php unset($_SESSION['success_message']); ?>
  <?php elseif (isset($_SESSION['error_message'])): ?>
    <div class="p-4 mb-4 text-red-800 bg-red-100 rounded"><?= htmlspecialchars($_SESSION['error_message']) ?></div>
    <?php unset($_SESSION['error_message']); ?>
  <?php elseif (isset($_SESSION['info_message'])): ?>
    <div class="p-4 mb-4 text-blue-800 bg-blue-100 rounded flex items-center">
      <div class="spinner mr-3" style="display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(0, 0, 0, 0.1); border-radius: 50%; border-top-color: #3b82f6; animation: spin 1s ease-in-out infinite;"></div>
      <?= htmlspecialchars($_SESSION['info_message']) ?>
    </div>
    <?php unset($_SESSION['info_message']); ?>
  <?php endif; ?>
  
  <script>
document.addEventListener('DOMContentLoaded', function() {
    // Participant templates
    const registeredTemplate = `
      <div class="participant flex gap-2 bg-blue-50 p-2 rounded mb-2">
        <input type="hidden" name="participants[INDEX][type]" value="registered">
        <select name="participants[INDEX][user_id]" class="flex-1 p-2 border rounded" required>
          <option value="">Select Resident</option>
          <?php foreach ($residents as $r): ?>
            <option value="<?= $r['user_id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="participants[INDEX][role]" class="flex-1 p-2 border rounded">
          <option value="complainant">Complainant</option>
          <option value="respondent">Respondent</option>
          <option value="witness">Witness</option>
        </select>
        <button type="button" class="remove-participant px-2 bg-red-500 text-white rounded">×</button>
      </div>`;
      
    const unregisteredTemplate = `
      <div class="participant flex gap-2 bg-green-50 p-2 rounded mb-2">
        <input type="hidden" name="participants[INDEX][type]" value="unregistered">
        <div class="flex-1 grid grid-cols-2 gap-2">
          <input type="text" name="participants[INDEX][first_name]" placeholder="First Name" required class="p-2 border rounded">
          <input type="text" name="participants[INDEX][last_name]" placeholder="Last Name" required class="p-2 border rounded">
          <input type="text" name="participants[INDEX][contact_number]" placeholder="Contact" class="p-2 border rounded">
          <input type="text" name="participants[INDEX][address]" placeholder="Address" class="p-2 border rounded">
          <input type="number" name="participants[INDEX][age]" placeholder="Age" class="p-2 border rounded">
          <select name="participants[INDEX][gender]" class="p-2 border rounded">
            <option value="">Gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <select name="participants[INDEX][role]" class="w-28 p-2 border rounded">
          <option value="complainant">Complainant</option>
          <option value="respondent">Respondent</option>
          <option value="witness">Witness</option>
        </select>
        <button type="button" class="remove-participant px-2 bg-red-500 text-white rounded">×</button>
      </div>`;

    // Add participant function
    function addParticipant(template, container) {
      const idx = container.children.length;
      const wrapper = document.createElement('div');
      wrapper.innerHTML = template.replace(/INDEX/g, idx);
      const node = wrapper.firstElementChild;
      node.querySelector('.remove-participant').addEventListener('click', () => node.remove());
      container.appendChild(node);
      node.scrollIntoView({ behavior: 'smooth' });
    }
    
    // Modal toggle functions - Improve these to ensure robustness
    window.toggleAddBlotterModal = function() {
        const modal = document.getElementById('addBlotterModal');
        const container = document.getElementById('participantContainer');
        if (modal) {
            modal.classList.toggle('hidden');
            // Clear participants when opening
            if (!modal.classList.contains('hidden') && container) {
                container.innerHTML = '';
            }
        }
    };
    
    window.toggleEditBlotterModal = function() {
        const modal = document.getElementById('editBlotterModal');
        if (modal) modal.classList.toggle('hidden');
    };
    
    window.toggleViewBlotterModal = function() {
        const modal = document.getElementById('viewBlotterModal');
        if (modal) modal.classList.toggle('hidden');
    };

    // Add modal setup - Improve button click handler
    const addModal = document.getElementById('addBlotterModal');
    const openBtn  = document.getElementById('openModalBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const participantContainer = document.getElementById('participantContainer');
    
    // Event listeners - Make more robust
    if (openBtn) {
      // Make sure this handler works properly
      openBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (participantContainer) participantContainer.innerHTML = '';
        if (addModal) addModal.classList.remove('hidden');
      });
    }
    
    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => {
        if (addModal) addModal.classList.add('hidden');
      });
    }
    
    const addRegisteredBtn = document.getElementById('addRegisteredBtn');
    const addUnregisteredBtn = document.getElementById('addUnregisteredBtn');
    
    if (addRegisteredBtn) {
      addRegisteredBtn.addEventListener('click', () => addParticipant(registeredTemplate, participantContainer));
    }
    if (addUnregisteredBtn) {
      addUnregisteredBtn.addEventListener('click', () => addParticipant(unregisteredTemplate, participantContainer));
    }

    // Edit modal setup
    const editModal  = document.getElementById('editBlotterModal');
    const editForm   = document.getElementById('editBlotterForm');
    const editCancel = document.getElementById('editCancelBtn');
    const editPartCont = document.getElementById('editParticipantContainer');
    
    const editAddRegisteredBtn = document.getElementById('editAddRegisteredBtn');
    const editAddUnregisteredBtn = document.getElementById('editAddUnregisteredBtn');
    
    if (editAddRegisteredBtn) {
      editAddRegisteredBtn.addEventListener('click', () => addParticipant(registeredTemplate, editPartCont));
    }
    if (editAddUnregisteredBtn) {
      editAddUnregisteredBtn.addEventListener('click', () => addParticipant(unregisteredTemplate, editPartCont));
    }
    
    if (editCancel) {
      editCancel.addEventListener('click', () => editModal.classList.add('hidden'));
    }

    // Status change handlers using event delegation
    document.addEventListener('change', async (e) => {
      if (e.target.classList.contains('status-select')) {
        const res = await fetch(`?action=set_status&id=${e.target.dataset.id}&new_status=${encodeURIComponent(e.target.value)}`);
        const data = await res.json();
        if (!data.success) {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: data.message || 'Failed to update status'
          });
        } else {
          Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Status updated successfully',
            timer: 1500,
            showConfirmButton: false
          }).then(() => location.reload());
        }
      }
    });

    // Improved click event delegation
    document.addEventListener('click', async (e) => {
      // More robust target finding - look for the button itself or its parent if clicking on child elements
      let target = e.target;
      
      // Check if we clicked on something inside a button (like an icon or text)
      if (!target.matches('button, a') && !target.closest('button, a')) {
        return; // Not a clickable element
      }
      
      // If we clicked on a child element inside a button, get the actual button
      if (!target.matches('button, a')) {
        target = target.closest('button, a');
      }
      
      // Debug to verify correct element targeting
      console.log('Button clicked:', target.className, target);
      
      // View button
      if (target.classList.contains('view-btn')) {
        const caseId = target.dataset.id;
        await openViewModal(caseId);
        return;
      }
      
      // Edit button
      if (target.classList.contains('edit-btn')) {
        const id = target.dataset.id;
        await handleEditCase(id);
        return;
      }
      
      // Document generation buttons
      if (target.classList.contains('generate-summons-btn')) {
        const caseId = target.dataset.id;
        window.open(`?action=generate_summons&id=${caseId}`, '_blank');
        return;
      }
      
      if (target.classList.contains('generate-report-form-btn')) {
        const caseId = target.dataset.id;
        window.open(`?action=generate_report_form&id=${caseId}`, '_blank');
        return;
      }
      
      // Schedule hearing button
      if (target.classList.contains('schedule-hearing-btn')) {
        const caseId = target.dataset.id;
        await handleScheduleHearing(caseId);
        return;
      }
      
      // Issue CFA button
      if (target.classList.contains('issue-cfa-btn')) {
        const caseId = target.dataset.id;
        await handleIssueCFA(caseId);
        return;
      }
      
      // Complete button
      if (target.classList.contains('complete-btn')) {
        const caseId = target.dataset.id;
        await handleCompleteCase(caseId);
        return;
      }
      
      // Delete button
      if (target.classList.contains('delete-btn')) {
        const caseId = target.dataset.id;
        await handleDeleteCase(caseId);
        return;
      }
      
      // Intervention button
      if (target.classList.contains('intervention-btn')) {
        const caseId = target.dataset.id;
        await handleAddIntervention(caseId);
        return;
      }
      
      // Record hearing button
      if (target.classList.contains('record-hearing-btn')) {
        const hearingId = target.dataset.hearingId;
        const caseId = target.dataset.caseId;
        await handleRecordHearing(hearingId, caseId);
        return;
      }
    });

    // Participant event delegation - handle removal of participants
    document.getElementById('participantContainer')?.addEventListener('click', (e) => {
      if (e.target.classList.contains('remove-participant')) {
        e.target.closest('.participant').remove();
      }
    });
    
    document.getElementById('editParticipantContainer')?.addEventListener('click', (e) => {
      if (e.target.classList.contains('remove-participant')) {
        e.target.closest('.participant').remove();
      }
    });

    // Fix button handling by attaching event listeners directly when the page loads
    // This ensures all buttons will work regardless of when they're added to the DOM
    window.addEventListener('load', function() {
        console.log("Window loaded, attaching button handlers");
        
        // Add New Case button
        const openModalBtn = document.getElementById('openModalBtn');
        if (openModalBtn) {
            console.log("Found Add New Case button, attaching listener");
            openModalBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const modal = document.getElementById('addBlotterModal');
                const container = document.getElementById('participantContainer');
                if (container) container.innerHTML = '';
                if (modal) modal.classList.remove('hidden');
                console.log("Add New Case button clicked, showing modal");
            });
        }
        
        // Cancel button in add modal
        const cancelBtn = document.getElementById('cancelBtn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                const modal = document.getElementById('addBlotterModal');
                if (modal) modal.classList.add('hidden');
            });
        }
        
        // Directly attach handlers to all action buttons
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Edit button clicked, ID:", caseId);
                handleEditCase(caseId);
            });
        });
        
        document.querySelectorAll('.generate-summons-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Summons button clicked, ID:", caseId);
                window.open(`?action=generate_summons&id=${caseId}`, '_blank');
            });
        });
        
        document.querySelectorAll('.generate-report-form-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Report button clicked, ID:", caseId);
                window.open(`?action=generate_report_form&id=${caseId}`, '_blank');
            });
        });
        
        document.querySelectorAll('.schedule-hearing-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Schedule hearing button clicked, ID:", caseId);
                handleScheduleHearing(caseId);
            });
        });
        
        document.querySelectorAll('.complete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Close button clicked, ID:", caseId);
                handleCompleteCase(caseId);
            });
        });
        
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Dismiss button clicked, ID:", caseId);
                handleDeleteCase(caseId);
            });
        });
        
        document.querySelectorAll('.intervention-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const caseId = this.getAttribute('data-id');
                console.log("Intervention button clicked, ID:", caseId);
                handleAddIntervention(caseId);
            });
        });
    });
    
    // Remove the nested DOMContentLoaded listener that might be causing conflicts
    // document.addEventListener('DOMContentLoaded', () => {
    //     // This nested listener was likely causing issues
    // });
});
</script>
  <script>
document.addEventListener('DOMContentLoaded', function() {
    // ...existing code...

    // Add/update these handler functions
    
    // Function to handle editing a case
    async function handleEditCase(caseId) {
      try {
        // Show loading state
        Swal.fire({
          title: 'Loading...',
          text: 'Fetching case details',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });
        
        // Fetch case details from the server
        const response = await fetch(`?action=get_case_details&id=${caseId}`);
        const data = await response.json();
        Swal.close();
        
        if (!data.success) {
          Swal.fire('Error', 'Failed to load case details', 'error');
          return;
        }
        
        const { case: caseData, participants, interventions } = data;
        
        // Populate the edit form
        document.getElementById('editCaseId').value = caseId;
        document.getElementById('editLocation').value = caseData.location || '';
        document.getElementById('editDescription').value = caseData.description || '';
        document.getElementById('editStatus').value = caseData.status || 'pending';
        
        // Check intervention checkboxes
        const interventionCheckboxes = document.querySelectorAll('#editInterventionContainer input[type="checkbox"]');
        interventionCheckboxes.forEach(checkbox => {
          checkbox.checked = false; // Reset all checkboxes
        });
        
        interventions.forEach(intervention => {
          const checkbox = document.querySelector(`#editInterventionContainer input[value="${intervention.intervention_id}"]`);
          if (checkbox) checkbox.checked = true;
        });
        
        // Populate participants
        const participantContainer = document.getElementById('editParticipantContainer');
        participantContainer.innerHTML = '';
        
        participants.forEach((participant, index) => {
          if (participant.participant_type === 'registered') {
            // Add a registered participant row
            const template = `
              <div class="participant flex gap-2 bg-blue-50 p-2 rounded mb-2">
                <input type="hidden" name="participants[${index}][type]" value="registered">
                <select name="participants[${index}][user_id]" class="flex-1 p-2 border rounded" required>
                  <option value="">Select Resident</option>
                  <?php foreach ($residents as $r): ?>
                    <option value="<?= $r['user_id'] ?>" ${participant.person_id == <?= $r['user_id'] ?> ? 'selected' : ''}><?= htmlspecialchars($r['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="participants[${index}][role]" class="flex-1 p-2 border rounded">
                  <option value="complainant" ${participant.role === 'complainant' ? 'selected' : ''}>Complainant</option>
                  <option value="respondent" ${participant.role === 'respondent' ? 'selected' : ''}>Respondent</option>
                  <option value="witness" ${participant.role === 'witness' ? 'selected' : ''}>Witness</option>
                </select>
                <button type="button" class="remove-participant px-2 bg-red-500 text-white rounded">×</button>
              </div>`;
            
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template;
            const node = wrapper.firstElementChild;
            node.querySelector('.remove-participant').addEventListener('click', () => node.remove());
            participantContainer.appendChild(node);
            
            // Set the selected value for the resident dropdown
            const userSelect = node.querySelector(`select[name="participants[${index}][user_id]"]`);
            if (userSelect) {
              for (let i = 0; i < userSelect.options.length; i++) {
                if (userSelect.options[i].value == participant.person_id) {
                  userSelect.options[i].selected = true;
                  break;
                }
              }
            }
          } else {
            // External participant
            const template = `
              <div class="participant flex gap-2 bg-green-50 p-2 rounded mb-2">
                <input type="hidden" name="participants[${index}][type]" value="unregistered">
                <div class="flex-1 grid grid-cols-2 gap-2">
                  <input type="text" name="participants[${index}][first_name]" placeholder="First Name" required value="${participant.first_name || ''}" class="p-2 border rounded">
                  <input type="text" name="participants[${index}][last_name]" placeholder="Last Name" required value="${participant.last_name || ''}" class="p-2 border rounded">
                  <input type="text" name="participants[${index}][contact_number]" placeholder="Contact" value="${participant.contact_number || ''}" class="p-2 border rounded">
                  <input type="text" name="participants[${index}][address]" placeholder="Address" value="${participant.address || ''}" class="p-2 border rounded">
                  <input type="number" name="participants[${index}][age]" placeholder="Age" value="${participant.age || ''}" class="p-2 border rounded">
                  <select name="participants[${index}][gender]" class="p-2 border rounded">
                    <option value="">Gender</option>
                    <option value="Male" ${participant.gender === 'Male' ? 'selected' : ''}>Male</option>
                    <option value="Female" ${participant.gender === 'Female' ? 'selected' : ''}>Female</option>
                    <option value="Other" ${participant.gender === 'Other' ? 'selected' : ''}>Other</option>
                  </select>
                </div>
                <select name="participants[${index}][role]" class="w-28 p-2 border rounded">
                  <option value="complainant" ${participant.role === 'complainant' ? 'selected' : ''}>Complainant</option>
                  <option value="respondent" ${participant.role === 'respondent' ? 'selected' : ''}>Respondent</option>
                  <option value="witness" ${participant.role === 'witness' ? 'selected' : ''}>Witness</option>
                </select>
                <button type="button" class="remove-participant px-2 bg-red-500 text-white rounded">×</button>
              </div>`;
            
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template;
            const node = wrapper.firstElementChild;
            node.querySelector('.remove-participant').addEventListener('click', () => node.remove());
            participantContainer.appendChild(node);
          }
        });
        
        // Show the edit modal
        document.getElementById('editBlotterModal').classList.remove('hidden');
        
      } catch (error) {
        console.error('Error loading case details:', error);
        Swal.fire('Error', 'An unexpected error occurred while loading case details', 'error');
      }
    }
    
    // Improved scheduleHearing function with proper date validation
    async function handleScheduleHearing(caseId) {
      try {
        // Create today's date and max date (5 days from now)
        const today = new Date();
        const todayStr = today.toISOString().split('T')[0]; // Format as YYYY-MM-DD
        
        // Calculate max date (5 days from today)
        const maxDate = new Date();
        maxDate.setDate(today.getDate() + 5);
        const maxDateStr = maxDate.toISOString().split('T')[0];
        
        // Get available dates/times from the server or create date picker
        const { value: formValues } = await Swal.fire({
          title: 'Schedule Hearing',
          html: `
            <div class="mb-3">
              <label class="block text-gray-700 text-sm font-bold mb-2" for="hearing-date">
                Hearing Date (within 5 days)
              </label>
              <input id="hearing-date" type="date" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight" 
                min="${todayStr}" max="${maxDateStr}" required>
              <small class="text-gray-500">Hearings must be scheduled within 5 days from today</small>
            </div>
            <div class="mb-3">
              <label class="block text-gray-700 text-sm font-bold mb-2" for="hearing-time">
                Hearing Time
              </label>
              <input id="hearing-time" type="time" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight" required>
            </div>
            <div class="mb-3">
              <label class="block text-gray-700 text-sm font-bold mb-2" for="hearing-location">
                Location
              </label>
              <input id="hearing-location" type="text" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight" value="Barangay Hall" required>
            </div>
          `,
          focusConfirm: false,
          showCancelButton: true,
          confirmButtonText: 'Schedule',
          preConfirm: () => {
            const hearingDate = document.getElementById('hearing-date').value;
            const hearingTime = document.getElementById('hearing-time').value;
            const hearingLocation = document.getElementById('hearing-location').value;
            
            if (!hearingDate || !hearingTime) {
              Swal.showValidationMessage('Please fill in all required fields');
              return false;
            }
            
            // Additional validation for date range
            if (hearingDate < todayStr || hearingDate > maxDateStr) {
              Swal.showValidationMessage('Hearing date must be within the next 5 days (including today)');
              return false;
            }
            
            return {
              hearing_date: hearingDate,
              hearing_time: hearingTime,
              hearing_location: hearingLocation
            };
          }
        });
        
        if (formValues) {
          // Show loading state
          Swal.fire({
            title: 'Scheduling...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
              Swal.showLoading();
            }
          });
          
          // Send the scheduling request to the server
          const response = await fetch(`?action=schedule_hearing&id=${caseId}`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify(formValues)
          });
          
          const data = await response.json();
          
          if (data.success) {
            Swal.fire({
              icon: 'success',
              title: 'Success!',
              text: data.message || 'Hearing has been scheduled successfully.',
              timer: 2000,
              showConfirmButton: false
            }).then(() => location.reload());
          } else {
            Swal.fire('Error', data.message || 'Failed to schedule hearing', 'error');
          }
        }
      } catch (error) {
        console.error('Error scheduling hearing:', error);
        Swal.fire('Error', 'An unexpected error occurred.', 'error');
      }
    }
    
    // Add form submission handler for edit form
    document.getElementById('editBlotterForm').addEventListener('submit', async function(e) {
      e.preventDefault();
      
      try {
        // Get form data
        const formData = {
          case_id: document.getElementById('editCaseId').value,
          location: document.getElementById('editLocation').value,
          description: document.getElementById('editDescription').value,
          status: document.getElementById('editStatus').value,
          interventions: [],
          participants: []
        };
        
        // Get checked interventions
        document.querySelectorAll('#editInterventionContainer input[type="checkbox"]:checked').forEach(checkbox => {
          formData.interventions.push(checkbox.value);
        });
        
        // Get participants
        document.querySelectorAll('#editParticipantContainer .participant').forEach(participant => {
          const participantType = participant.querySelector('input[name$="[type]"]').value;
          const participantData = {};
          
          if (participantType === 'registered') {
            participantData.user_id = participant.querySelector('select[name$="[user_id]"]').value;
            participantData.role = participant.querySelector('select[name$="[role]"]').value;
          } else {
            // Unregistered/external
            participantData.first_name = participant.querySelector('input[name$="[first_name]"]').value;
            participantData.last_name = participant.querySelector('input[name$="[last_name]"]').value;
            participantData.contact_number = participant.querySelector('input[name$="[contact_number]"]').value;
            participantData.address = participant.querySelector('input[name$="[address]"]').value;
            participantData.age = participant.querySelector('input[name$="[age]"]').value;
            participantData.gender = participant.querySelector('select[name$="[gender]"]').value;
            participantData.role = participant.querySelector('select[name$="[role]"]').value;
          }
          
          formData.participants.push(participantData);
        });
        
        // Validate data
        if (!formData.location.trim() || !formData.description.trim()) {
          Swal.fire('Error', 'Location and description are required', 'error');
          return;
        }
        
        if (formData.participants.length === 0) {
          Swal.fire('Error', 'At least one participant is required', 'error');
          return;
        }
        
        // Show loading state
        Swal.fire({
          title: 'Saving...',
          text: 'Updating case details',
          allowOutsideClick: false,
          didOpen: () => {
            Swal.showLoading();
          }
        });
        
        // Send data to server
        const response = await fetch('?action=update_case', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
          Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: 'Case has been updated successfully.',
            timer: 1500,
            showConfirmButton: false
          }).then(() => {
            // Close modal and reload page
            document.getElementById('editBlotterModal').classList.add('hidden');
            location.reload();
          });
        } else {
          Swal.fire('Error', result.message || 'Failed to update case', 'error');
        }
        
      } catch (error) {
        console.error('Error updating case:', error);
        Swal.fire('Error', 'An unexpected error occurred while saving the case', 'error');
      }
    });

    // Make handler functions available globally
    window.handleEditCase = handleEditCase;
    window.handleCompleteCase = handleCompleteCase;
    window.handleDeleteCase = handleDeleteCase;
    window.handleScheduleHearing = handleScheduleHearing;
    window.handleIssueCFA = handleIssueCFA;
    window.handleAddIntervention = handleAddIntervention;
});
</script>
