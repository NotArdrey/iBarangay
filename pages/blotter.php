<?php
// blotter.php – ADMIN SIDE
session_start();
require "../config/dbconn.php";
require "../vendor/autoload.php";
use Dompdf\Dompdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;  

// Define role constants - Updated to match database IDs
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
        SELECT u.id AS user_id, CONCAT(p.first_name,' ',p.last_name) AS name
        FROM users u
        LEFT JOIN persons p ON p.user_id = u.id
        WHERE u.barangay_id = ?
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

    // Get barangay info based on case's barangay_id
    $barangayStmt = $pdo->prepare("
        SELECT name FROM barangay WHERE id = ?
    ");
    $barangayStmt->execute([$case['barangay_id']]);
    $barangayName = $barangayStmt->fetchColumn() ?: 'Tambubong';

    // Get appropriate signature based on availability
    $esignaturePath = null;
    $captainEsignaturePath = getCaptainEsignature($pdo, $case['barangay_id']);
    if ($captainEsignaturePath) {
        $esignaturePath = $captainEsignaturePath;
    } else {
        $chiefEsignaturePath = getChiefOfficerEsignature($pdo, $case['barangay_id']);
        if ($chiefEsignaturePath) {
            $esignaturePath = $chiefEsignaturePath;
        }
    }

    // embed signature as data URI
    $sigData = '';
    if ($esignaturePath) {
        $full = $_SERVER['DOCUMENT_ROOT'] . '/iBarangay/' . $esignaturePath;
        if (file_exists($full)) {
            $type = mime_content_type($full);
            $bin  = base64_encode(file_get_contents($full));
            $sigData = "data:{$type};base64,{$bin}";
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

    // Auto-fill current date
    $currentDate = new DateTime();
    $day = $currentDate->format('j');
    $month = $currentDate->format('F');
    $year = $currentDate->format('Y');

    // fetch latest pending schedule proposal
    $sStmt = $pdo->prepare("
        SELECT proposed_date, proposed_time
        FROM schedule_proposals
        WHERE blotter_case_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $sStmt->execute([$caseId]);
    $sched = $sStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $hDate = isset($sched['proposed_date']) ? new DateTime($sched['proposed_date']) : new DateTime();
    $hDay   = $hDate->format('j');
    $hMonth = $hDate->format('F');
    $hYear  = $hDate->format('Y');
    $hTime  = isset($sched['proposed_time'])
             ? DateTime::createFromFormat('H:i:s', $sched['proposed_time'])->format('g:i A')
             : '';

    ob_start(); ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 25mm 20mm; size: A4; }
            body { font-family: 'Times New Roman', serif; font-size: 12pt; margin: 0; color: #000; }
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
                    Barangay <?= htmlspecialchars($barangayName) ?>
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
                    <?php if (empty($complainants)): ?>&nbsp;<?php endif; ?>
                </div>
                <div class="label">(Mga) Maysumbong</div>

                <div style="margin:2mm 0;">
                    laban kay (kina):
                    <span class="underline" style="min-width:180px; display:inline-block; vertical-align:top;">
                        <?php foreach($respondents as $r): ?>
                            <?= htmlspecialchars($r['full_name'] ?? 'Unknown') ?><br>
                        <?php endforeach; ?>
                        <?php if (empty($respondents)): ?>&nbsp;<?php endif; ?>
                    </span>
                    <span class="label" style="display:inline-block; vertical-align:middle;margin-bottom: 30px;">
                        (Mga) Ipinagsusumbong
                    </span>
                </div>
            </div>
            <div style="width:10%;display:inline-block;"></div>
            <div style="width:45%;display:inline-block;vertical-align:top;">
                Usaping Barangay Blg. <span class="underline" style="min-width:80px;"><?= htmlspecialchars($case['case_number'] ?? '') ?></span><br>
                Ukol sa <span class="underline" style="min-width:120px;"><?= htmlspecialchars($case['categories'] ?? '') ?></span>
            </div>
        </div>
        <div class="summons-title">PATAWAG</div>
        <div style="margin-bottom:2mm;">
            Kay/Kina:
            <span class="underline"
                  style="min-width:180px; display:inline-block; vertical-align:middle; margin-right:4px;">
                <?php foreach ($respondents as $r): ?>
                    <?= htmlspecialchars($r['full_name'] ?? 'Unknown') ?><br>
                <?php endforeach; ?>
                <?php if (empty($respondents)): ?>&nbsp;<?php endif; ?>
            </span>
            <span class="label" style="vertical-align:middle;">(Mga) Ipinagsusumbong</span>
        </div>
        <div style="margin-bottom:2mm;">
            Sa pamamagitan nito, kayo'y tinatawag upang personal na humarap sa akin, kasama ang inyong mga testigo,
            sa ika-<span class="underline" style="min-width:30px;"><?= $hDay ?></span> araw ng 
            <span class="underline" style="min-width:80px;"><?= $hMonth ?></span>, <?= $hYear ?>,
            sa ganap na ika-<span class="underline" style="min-width:40px;"><?= $hTime ?></span> ng umaga/hapon,
            upang sagutin ang isang sumbong na idinulog sa akin, na ang kopya'y kalakip nito, para pagmagitanan/pagpakasunduin kayo sa inyong alitan ng (mga) maysumbong.
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
                    <td style="width:60%">
                        Ngayon ika-<span class="underline" style="min-width:30px;"><?= $day ?></span> araw ng <span class="underline" style="min-width:80px;"><?= $month ?></span>, <?= $year ?>.
                    </td>
                    <td style="width:40%; text-align:center;">
                        <?php if ($sigData): ?>
                            <img src="<?= $sigData ?>" alt="E-signature"
                                 style="height:50px;max-width:180px;display:block;margin-left:auto;margin-right:auto;margin-bottom:2mm;">
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
    
    // Get barangay info based on case's barangay_id
    $barangayStmt = $pdo->prepare("
        SELECT name FROM barangay WHERE id = ?
    ");
    $barangayStmt->execute([$case['barangay_id']]);
    $barangayName = $barangayStmt->fetchColumn() ?: 'Tambubong';
    
    // Get appropriate signature based on availability
    $esignaturePath = null;
    $captainEsignaturePath = getCaptainEsignature($pdo, $case['barangay_id']);
    if ($captainEsignaturePath) {
        $esignaturePath = $captainEsignaturePath;
    } else {
        $chiefEsignaturePath = getChiefOfficerEsignature($pdo, $case['barangay_id']);
        if ($chiefEsignaturePath) {
            $esignaturePath = $chiefEsignaturePath;
        }
    }

    // embed signature as data URI
    $sigData = '';
    if ($esignaturePath) {
        $full = $_SERVER['DOCUMENT_ROOT'] . '/iBarangay/' . $esignaturePath;
        if (file_exists($full)) {
            $type = mime_content_type($full);
            $bin  = base64_encode(file_get_contents($full));
            $sigData = "data:{$type};base64,{$bin}";
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
    
    // Auto-fill current date
    $currentDate = new DateTime();
    $day = $currentDate->format('j');
    $month = $currentDate->format('F');
    $year = $currentDate->format('Y');
    
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
                    Barangay <?= htmlspecialchars($barangayName) ?>
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
                    <?php if (empty($complainants)): ?>&nbsp;<?php endif; ?>
                </div>
                <div class="label">(Mga) Maysumbong</div>

                <div style="margin:2mm 0;">
                    laban kay (kina):
                    <span class="underline" style="min-width:180px; display:inline-block; vertical-align:top;">
                        <?php foreach($respondents as $r): ?>
                            <?= htmlspecialchars($r['full_name'] ?? 'Unknown') ?><br>
                        <?php endforeach; ?>
                        <?php if (empty($respondents)): ?>&nbsp;<?php endif; ?>
                    </span>
                    <span class="label" style="display:inline-block; vertical-align:middle;margin-bottom: 30px;">
                        (Mga) Ipinagsusumbong
                    </span>
                </div>
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
                        Ginawa ngayong ika- <span class="underline" style="min-width:30px;"><?= $day ?></span> araw ng <span class="underline" style="min-width:80px;"><?= $month ?></span>, <?= $year ?>.
                    </td>
                    <td style="width:40%; text-align:center;">
                        <div class="signature-line"></div><br>
                        (Mga) Maysumbong
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="height:10mm"></td>
                </tr>
                <tr>
                    <td>
                        Tinanggap at itinala ngayong ika- <span class="underline" style="min-width:30px;"><?= $day ?></span> araw ng <span class="underline" style="min-width:80px;"><?= $month ?></span>, <?= $year ?>.
                    </td>
                    <td style="text-align:center;">
                        <?php if ($sigData): ?>
                            <img src="<?= $sigData ?>" alt="E-signature"
                                 style="height:50px;max-width:180px;display:block;margin-left:auto;margin-right:auto;margin-bottom:2mm;">
                        <?php endif; ?>
                        <div class="signature-line"></div><br>
                        Punong Barangay/Pangulo ng Lupon
                    </td>
                </tr>
            </table>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// --- add missing helpers for e-signature paths ---
function getCaptainEsignature($pdo, $barangayId) {
    $stmt = $pdo->prepare("
        SELECT u.esignature_path
        FROM users u
        WHERE u.role_id = 3
          AND u.barangay_id = ?
          AND u.is_active = 1
          AND u.esignature_path IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$barangayId]);
    $path = $stmt->fetchColumn();
    if ($path) {
        $webPath  = str_replace('../', '', $path);
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/iBarangay/' . $webPath;
        if (file_exists($fullPath)) {
            return $webPath;
        }
    }
    return null;
}

function getChiefOfficerEsignature($pdo, $barangayId) {
    $stmt = $pdo->prepare("
        SELECT u.chief_officer_esignature_path
        FROM users u
        WHERE u.role_id = 7
          AND u.barangay_id = ?
          AND u.is_active = 1
          AND u.chief_officer_esignature_path IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$barangayId]);
    $path = $stmt->fetchColumn();
    if ($path) {
        $webPath  = str_replace('../', '', $path);
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/iBarangay/' . $webPath;
        if (file_exists($fullPath)) {
            return $webPath;
        }
    }
    return null;
}

function sendSummonsEmails($pdo, $caseId, $proposalId) {
    // ...existing code...
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

        // REMOVE: Notify Captain and Chief Officer (case_notifications table does not exist)

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
        $participantIds = [];
        foreach ($participants as $p) {
            if (!empty($p['user_id'])) {
                // Build a unique key for registered participant
                $key = $caseId . '-' . intval($p['user_id']) . '-' . $p['role'];
                if (isset($insertedParticipants[$key])) {
                    continue;
                }
                $insertedParticipants[$key] = true;
                $regStmt->execute([$caseId, (int)$p['user_id'], $p['role']]);
                $participantId = $pdo->lastInsertId();
                $participantIds[] = $participantId;
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
                $participantId = $pdo->lastInsertId();
                $participantIds[] = $participantId;
            }
        }

        // --- Insert participant_notifications for all participants (if not already present) ---
        $notifStmt = $pdo->prepare("
            INSERT IGNORE INTO participant_notifications
                (blotter_case_id, participant_id, delivery_method, delivery_status, delivery_address)
            VALUES (?, ?, ?, 'pending', ?)
        ");
        foreach ($participantIds as $pid) {
            // Determine delivery method and address
            $info = $pdo->prepare("
                SELECT bp.id, p.user_id, u.email, a.house_no, a.street, b.name AS barangay_name
                FROM blotter_participants bp
                LEFT JOIN persons p ON bp.person_id = p.id
                LEFT JOIN users u ON p.user_id = u.id
                LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
                LEFT JOIN barangay b ON a.barangay_id = b.id
                WHERE bp.id = ?
            ");
            $info->execute([$pid]);
            $row = $info->fetch(PDO::FETCH_ASSOC);
            $method = (!empty($row['email'])) ? 'email' : 'physical';
            $address = (!empty($row['house_no']) && !empty($row['street']) && !empty($row['barangay_name']))
                ? ($row['house_no'] . ' ' . $row['street'] . ', ' . $row['barangay_name'])
                : 'Address not provided';
            $notifStmt->execute([$caseId, $pid, $method, $address]);
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

    // ...existing code for AJAX actions...
    
    try {
        switch($action) {
            
            // Add new action handler for signature uploads
            case 'upload_signature':
                header('Content-Type: text/html'); // Change content type for redirect
                
                // Validate that user has appropriate role
                if (!in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF])) {
                    $_SESSION['error_message'] = "You don't have permission to upload signatures";
                    header("Location: dashboard.php");
                    exit;
                }

                if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
                    $_SESSION['error_message'] = 'Error uploading file: ' . ($_FILES['signature_file']['error'] ?? 'No file uploaded');
                    header("Location: blotter.php");
                    exit;
                }

                // Validate file type and size
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $maxSize = 2 * 1024 * 1024; // 2MB

                if (!in_array($_FILES['signature_file']['type'], $allowedTypes)) {
                    $_SESSION['error_message'] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
                    header("Location: blotter.php");
                    exit;
                }

                if ($_FILES['signature_file']['size'] > $maxSize) {
                    $_SESSION['error_message'] = 'File is too large. Maximum size is 2MB.';
                    header("Location: blotter.php");
                    exit;
                }

                // Create signature directory if it doesn't exist
                $uploadDir = '../uploads/signatures/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                // Generate unique filename
                $filename = 'signature_' . $role . '_' . $current_admin_id . '_' . time() . '_' . 
                           pathinfo($_FILES['signature_file']['name'], PATHINFO_EXTENSION);
                $filepath = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['signature_file']['tmp_name'], $filepath)) {
                    $_SESSION['error_message'] = 'Failed to move uploaded file';
                    header("Location: blotter.php");
                    exit;
                }

                // Update the correct signature field based on role
                $dbPath = 'uploads/signatures/' . $filename;
                if ($role === ROLE_CAPTAIN) {
                    $signatureColumn = 'esignature_path';
                } else {
                    $signatureColumn = 'chief_officer_esignature_path';
                }
                
                $stmt = $pdo->prepare("UPDATE users SET $signatureColumn = ? WHERE id = ?");
                if ($stmt->execute([$dbPath, $current_admin_id])) {
                    $_SESSION['success_message'] = 'E-signature uploaded successfully';
                    logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'users', $current_admin_id, 
                                  "Updated $signatureColumn");
                } else {
                    $_SESSION['error_message'] = 'Failed to update signature in database';
                }
                
                header("Location: blotter.php");
                exit;

            case 'generate_summons':
                if (!$id) {
                    echo json_encode(['success'=>false,'message'=>'Invalid case ID']);
                    exit;
                }
                
                $html = generateSummonsForm($pdo, $id);
                $pdf = new Dompdf();
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('A4','portrait');
                try {
                    $pdf->render();
                } catch (Exception $e) {
                    // GD not available → remove images and re-render
                    $htmlNoImg = preg_replace('/<img[^>]+>/', '', $html);
                    $pdf->loadHtml($htmlNoImg, 'UTF-8');
                    $pdf->render();
                }

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
                try {
                    $pdf->render();
                } catch (Exception $e) {
                    $htmlNoImg = preg_replace('/<img[^>]+>/', '', $html);
                    $pdf->loadHtml($htmlNoImg, 'UTF-8');
                    $pdf->render();
                }

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

                // Always generate report from actual case data instead of requiring pre-existing monthly reports
                $dStmt = $pdo->prepare("
                    SELECT
                        c.name AS category_name,
                        COUNT(DISTINCT bc.id) AS total_cases,
                        SUM(CASE WHEN EXISTS(
                            SELECT 1 FROM blotter_case_interventions bci 
                            JOIN case_interventions ci ON bci.intervention_id = ci.id
                            WHERE bci.blotter_case_id = bc.id AND ci.name = 'M/CSWD'
                        ) THEN 1 ELSE 0 END) AS mcwsd,
                        SUM(CASE WHEN EXISTS(
                            SELECT 1 FROM blotter_case_interventions bci 
                            JOIN case_interventions ci ON bci.intervention_id = ci.id
                            WHERE bci.blotter_case_id = bc.id AND ci.name = 'PNP'
                        ) THEN 1 ELSE 0 END) AS total_pnp,
                        SUM(CASE WHEN EXISTS(
                            SELECT 1 FROM blotter_case_interventions bci 
                            JOIN case_interventions ci ON bci.intervention_id = ci.id
                            WHERE bci.blotter_case_id = bc.id AND ci.name = 'Court'
                        ) THEN 1 ELSE 0 END) AS total_court,
                        SUM(CASE WHEN EXISTS(
                            SELECT 1 FROM blotter_case_interventions bci 
                            JOIN case_interventions ci ON bci.intervention_id = ci.id
                            WHERE bci.blotter_case_id = bc.id AND ci.name = 'Issued BPO'
                        ) THEN 1 ELSE 0 END) AS total_bpo,
                        SUM(CASE WHEN EXISTS(
                            SELECT 1 FROM blotter_case_interventions bci 
                            JOIN case_interventions ci ON bci.intervention_id = ci.id
                            WHERE bci.blotter_case_id = bc.id AND ci.name = 'Medical'
                        ) THEN 1 ELSE 0 END) AS total_medical
                    FROM case_categories c
                    LEFT JOIN blotter_case_categories bcc
                      ON c.id = bcc.category_id
                    LEFT JOIN blotter_cases bc
                      ON bc.id = bcc.blotter_case_id
                        AND YEAR(COALESCE(bc.incident_date, bc.created_at)) = :y
                        AND MONTH(COALESCE(bc.incident_date, bc.created_at)) = :m
                        AND bc.barangay_id = :bid
                        AND bc.status IN ('closed', 'completed', 'solved', 'endorsed_to_court', 'dismissed')
                    GROUP BY c.id, c.name
                    ORDER BY c.name
                ");
                $dStmt->execute([
                    'y' => $year,
                    'm' => $month,
                    'bid' => $bid
                ]);
                $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);

                // Check if there are any cases to report on
                $hasCases = false;
                $totalCasesCount = 0;
                foreach ($details as $row) {
                    $totalCasesCount += $row['total_cases'];
                    if ($row['total_cases'] > 0) {
                        $hasCases = true;
                    }
                }

                // Always try to fetch existing monthly report for prepared_by info
                $stmt = $pdo->prepare("
                    SELECT
                        m.*,
                        CONCAT(u.first_name, ' ', u.last_name) AS prepared_by_name
                    FROM monthly_reports m
                    JOIN users u ON m.prepared_by_user_id = u.id
                    WHERE m.report_year = :y
                      AND m.report_month = :m
                      AND m.barangay_id = :bid
                    ORDER BY m.id DESC
                    LIMIT 1
                ");
                $stmt->execute(['y'=>$year,'m'=>$month,'bid'=>$bid]);
                $report = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get current user info for prepared_by if no existing report
                if (!$report) {
                    $userStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ?");
                    $userStmt->execute([$current_admin_id]);
                    $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $report = [
                        'prepared_by_name' => $currentUser['name'] ?? 'System Generated',
                        'submitted_at' => date('Y-m-d H:i:s')
                    ];
                }

                // If no cases found, show informative message instead of error
                if (!$hasCases && $totalCasesCount == 0) {
                    header('Content-Type: text/html');
                    echo "<!DOCTYPE html><html><head><title>No Cases Found</title></head><body style='font-family:sans-serif;padding:2em;'><h2>No completed cases found for " . date('F Y', mktime(0, 0, 0, $month, 1, $year)) . "</h2><p>There are no closed, completed, solved, endorsed to court, or dismissed cases for the selected period in this barangay.</p><a href='blotter.php' style='color:#2563eb;'>Back to Blotter Cases</a></body></html>";
                    exit;
                }

                // Generate the month name
                $monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

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
                    .header { text-align: center; margin-bottom: 20px; }
                  </style>
                </head>
                <body>
                  <div class="header">
                    <h1>Monthly Blotter Report</h1>
                    <p><strong>Barangay:</strong> <?= htmlspecialchars($bid) ?></p>
                    <p><strong>Month:</strong> <?= htmlspecialchars($monthName) ?> <?= $year ?></p>
                    <p><strong>Prepared by:</strong> <?= htmlspecialchars($report['prepared_by_name']) ?></p>
                    <p><strong>Generated on:</strong> <?= date('F j, Y g:i A', strtotime($report['submitted_at'])) ?></p>
                  </div>
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
                      <?php 
                      $totalCases = 0;
                      $totalMcwsd = 0;
                      $totalPnp = 0;
                      $totalCourt = 0;
                      $totalBpo = 0;
                      $totalMedical = 0;
                      
                      foreach ($details as $row): 
                        $totalCases += $row['total_cases'];
                        $totalMcwsd += $row['mcwsd'];
                        $totalPnp += $row['total_pnp'];
                        $totalCourt += $row['total_court'];
                        $totalBpo += $row['total_bpo'];
                        $totalMedical += $row['total_medical'];
                      ?>
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
                      <tr style="background: #e9e9e9; font-weight: bold;">
                        <td>TOTAL</td>
                        <td><?= $totalCases ?></td>
                        <td><?= $totalMcwsd ?></td>
                        <td><?= $totalPnp ?></td>
                        <td><?= $totalCourt ?></td>
                        <td><?= $totalBpo ?></td>
                        <td><?= $totalMedical ?></td>
                      </tr>
                    </tbody>
                  </table>
                  <div style="margin-top: 30px; font-size: 12px; color: #666;">
                    <p><em>Note: This report includes cases with status: closed, completed, solved, endorsed to court, or dismissed.</em></p>
                    <p><em>Report generated automatically from case database on <?= date('Y-m-d H:i:s') ?>.</em></p>
                  </div>
                </body></html>
                <?php
                $html = ob_get_clean();
                
                $pdf = new Dompdf();
                $pdf->loadHtml($html, 'UTF-8');
                $pdf->setPaper('A4','landscape');
                try {
                    $pdf->render();
                } catch (Exception $e) {
                    $htmlNoImg = preg_replace('/<img[^>]+>/', '', $html);
                    $pdf->loadHtml($htmlNoImg, 'UTF-8');
                    $pdf->render();
                }

                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Blotter-Report-'.$year.'-'.$month.'.pdf"');
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
                           GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories,
                           GROUP_CONCAT(bcc.category_id)   AS category_ids
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
                  
                  // Check if the new columns exist
                  $columnCheck = $pdo->query("SHOW COLUMNS FROM blotter_cases LIKE 'hearing_attempts'");
                  $hasNewColumns = $columnCheck->rowCount() > 0;
                  
                  // Check if case is within 5-day deadline and hearing limits
                  $sql = "
                      SELECT bc.*, 
                             DATE_ADD(bc.filing_date, INTERVAL 5 DAY) as deadline,
                             COUNT(ch.id) as hearing_count";
                  
                  if ($hasNewColumns) {
                      $sql .= ",
                             bc.hearing_attempts,
                             bc.max_hearing_attempts";
                  } else {
                      $sql .= ",
                             0 as hearing_attempts,
                             3 as max_hearing_attempts";
                  }
                  
                  $sql .= "
                      FROM blotter_cases bc
                      LEFT JOIN case_hearings ch ON bc.id = ch.blotter_case_id
                      WHERE bc.id = ? AND bc.barangay_id = ?
                      GROUP BY bc.id
                  ";
                  
                  $stmt = $pdo->prepare($sql);
                  $stmt->execute([$id, $bid]);
                  $case = $stmt->fetch(PDO::FETCH_ASSOC);
                  
                  if (!$case) {
                      echo json_encode(['success'=>false,'message'=>'Case not found']);
                      exit;
                  }
                  
                  // Check if max hearing attempts reached (only if new columns exist)
                  if ($hasNewColumns && $case['hearing_attempts'] >= $case['max_hearing_attempts']) {
                      // Automatically mark as CFA eligible
                      $pdo->prepare("UPDATE blotter_cases SET is_cfa_eligible = TRUE, status = 'cfa_eligible', cfa_reason = 'Maximum hearing attempts reached' WHERE id = ?")
                          ->execute([$id]);
                      echo json_encode(['success'=>false,'message'=>'Maximum hearing attempts reached. Case is now eligible for CFA issuance.']);
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
              
                  // Hearing date must be within 5 days from today (inclusive)
                  $today = new DateTime();
                  $minDate = $today->format('Y-m-d');
                  $maxDate = $today->modify('+5 days')->format('Y-m-d');
                  
                  if ($data['hearing_date'] < $minDate || $data['hearing_date'] > $maxDate) {
                      echo json_encode(['success'=>false,'message'=>'Hearing date must be within the next 5 days (including today).']);
                      exit;
                  }
              
                  // Check for existing pending proposal for this case
                  $stmt = $pdo->prepare("SELECT id FROM schedule_proposals WHERE blotter_case_id = ? AND status = 'proposed'");
                  $stmt->execute([$id]);
                  if ($stmt->fetch()) {
                      echo json_encode(['success'=>false,'message'=>'There is already a pending schedule proposal for this case.']);
                      exit;
                  }
              
                  $pdo->beginTransaction();
                  
                  // Set status based on who is proposing the schedule
                  $proposalStatus = ($role === ROLE_CAPTAIN) ? 'pending_officer_confirmation' : 'pending_user_confirmation';
                  
                  $stmt = $pdo->prepare("
                      INSERT INTO schedule_proposals
                      (blotter_case_id, proposed_by_user_id, proposed_by_role_id, proposed_date, proposed_time,
                       hearing_location, presiding_officer, presiding_officer_position, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                  ");
                  $stmt->execute([
                      $id,
                      $current_admin_id,
                      $role,
                      $data['hearing_date'],
                      $data['hearing_time'],
                      $data['hearing_location'] ?? 'Barangay Hall',
                      $data['presiding_officer'] ?? ($role === ROLE_CAPTAIN ? 'Barangay Captain' : 'Chief Officer'),
                      $data['officer_position'] ?? ($role === ROLE_CAPTAIN ? 'barangay_captain' : 'chief_officer'),
                      $proposalStatus
                  ]);
                  $proposalId = $pdo->lastInsertId();

                  // Increment hearing attempts if new columns exist
                  if ($hasNewColumns) {
                      $pdo->prepare("UPDATE blotter_cases SET hearing_attempts = COALESCE(hearing_attempts, 0) + 1 WHERE id = ?")
                          ->execute([$id]);
                  }

                  // set scheduling_status to match UI condition (schedule_proposed)
                  $pdo->prepare("UPDATE blotter_cases SET scheduling_status='schedule_proposed' WHERE id=?")
                      ->execute([$id]);

                  $pdo->commit();
                  logAuditTrail($pdo, $current_admin_id,'INSERT','schedule_proposals',$proposalId,
                      "Scheduled hearing pending confirmation by role $otherRole"
                  );
                  echo json_encode([
                    'success'=>true,
                    'message'=>'Schedule proposed; awaiting confirmation by '.($role===ROLE_CAPTAIN?'Chief Officer':'Barangay Captain')
                  ]);
                  break;

            case 'approve_schedule':
    if (!in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF])) {
        echo json_encode(['success'=>false,'message'=>'Only Captain or Chief Officer can approve schedules']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        
        // Fetch the proposal
        $stmt = $pdo->prepare("
            SELECT sp.*, bc.id as case_id, bc.hearing_count 
            FROM schedule_proposals sp
            JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
            WHERE sp.blotter_case_id = ? 
              AND bc.barangay_id = ?
            ORDER BY sp.id DESC LIMIT 1
        ");
        $stmt->execute([$id, $bid]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$proposal) {
            echo json_encode(['success'=>false,'message'=>'No valid pending proposal found']);
            exit;
        }

        // Update only the captain confirmation
        $pdo->prepare("
            UPDATE schedule_proposals
            SET captain_confirmed = 1,
                captain_confirmed_at = NOW()
            WHERE id = ?
        ")->execute([$proposal['id']]);

        // Check if BOTH parties have confirmed
        $checkStmt = $pdo->prepare("
            SELECT complainant_confirmed, respondent_confirmed, captain_confirmed
            FROM schedule_proposals
            WHERE id = ?
        ");
        $checkStmt->execute([$proposal['id']]);
        $confirmations = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // Only finalize if ALL THREE are confirmed
        if ($confirmations['complainant_confirmed'] && 
            $confirmations['respondent_confirmed'] && 
            $confirmations['captain_confirmed']) {
            
            // Insert into case_hearings
            $pdo->prepare("
                INSERT INTO case_hearings
                (blotter_case_id, hearing_date, hearing_type, hearing_outcome,
                 presiding_officer_name, presiding_officer_position, hearing_number)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $id,
                $proposal['proposed_date'] . ' ' . $proposal['proposed_time'],
                ($proposal['hearing_count'] === 0) ? 'initial' : 'mediation',
                'scheduled',
                $proposal['presiding_officer'],
                $proposal['presiding_officer_position'],
                ($proposal['hearing_count'] ?? 0) + 1
            ]);
            
            // Update statuses
            $pdo->prepare("
                UPDATE schedule_proposals
                SET status = 'both_confirmed'
                WHERE id = ?
            ")->execute([$proposal['id']]);
            
            $pdo->prepare("
                UPDATE blotter_cases
                SET status = 'open',
                    scheduling_status = 'scheduled',
                    hearing_count = COALESCE(hearing_count, 0) + 1
                WHERE id = ?
            ")->execute([$id]);
            
            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>'All parties confirmed. Hearing scheduled.']);
        } else {
            $pdo->commit();
            $missing = [];
            if (!$confirmations['complainant_confirmed']) $missing[] = 'complainant';
            if (!$confirmations['respondent_confirmed']) $missing[] = 'respondent';
            
            echo json_encode([
                'success'=>true,
                'message'=>'Your approval is recorded. Still waiting for: ' . implode(' and ', $missing)
            ]);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    break;

               case 'reject_schedule':
    if (!in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF])) {
        echo json_encode(['success'=>false,'message'=>'Only Captain or Chief Officer can reject schedules']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $reason = $data['reason'] ?? 'Schedule conflict with officer availability';
    
    try {
        $pdo->beginTransaction();
        
        // Get the latest proposal
        $stmt = $pdo->prepare("
            SELECT sp.*, bc.id as case_id
            FROM schedule_proposals sp
            JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
            WHERE sp.blotter_case_id = ? 
              AND bc.barangay_id = ?
            ORDER BY sp.id DESC LIMIT 1
        ");
        $stmt->execute([$id, $bid]);
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$proposal) {
            echo json_encode(['success'=>false,'message'=>'No valid proposal found']);
            exit;
        }
        
        // Mark as officer conflict (not participant conflict)
        $stmt = $pdo->prepare("
            UPDATE schedule_proposals
            SET status = 'officer_conflict', 
                conflict_reason = ?,
                captain_confirmed = 0
            WHERE id = ?
        ");
        $stmt->execute([$reason, $proposal['id']]);
        
        // Reset scheduling status to allow new proposal
        $stmt = $pdo->prepare("
            UPDATE blotter_cases
            SET scheduling_status = 'pending_schedule'
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        
        $pdo->commit();
        
        echo json_encode(['success'=>true,'message'=>'Schedule rejected by officer. A new schedule can be proposed.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    break;

            case 'get_available_slots':
                header('Content-Type: application/json');
                $date = $_GET['date'] ?? '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid date']);
                    exit;
                }
                // fetch booked times for this barangay on that date
                $slotStmt = $pdo->prepare("
                    SELECT TIME(hearing_date) AS slot
                    FROM case_hearings ch
                    JOIN blotter_cases bc ON ch.blotter_case_id = bc.id
                    WHERE DATE(ch.hearing_date)=? AND bc.barangay_id=?
                ");
                $slotStmt->execute([$date, $bid]);
                $booked = array_column($slotStmt->fetchAll(PDO::FETCH_ASSOC),'slot');
                echo json_encode(['success'=>true,'booked'=>$booked]);
                exit;

            case 'confirm_proposal':
                header('Content-Type: application/json');
                $proposalId = intval($_REQUEST['proposal_id'] ?? 0);
                $decision   = $_REQUEST['decision'] ?? ''; // 'confirm' or 'reject'
                if (!$proposalId || !in_array($decision, ['confirm','reject'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
                }
                // fetch proposal & case participants
                $sp = $pdo->prepare("
                  SELECT sp.*, bp.person_id AS complainant_pid, 
                         (SELECT person_id FROM blotter_participants 
                            WHERE blotter_case_id=sp.blotter_case_id AND role='respondent' LIMIT 1
                         ) AS respondent_pid
                  FROM schedule_proposals sp
                  WHERE sp.id = ?
                ");
                $sp->execute([$proposalId]);
                $proposal = $sp->fetch(PDO::FETCH_ASSOC);
                if (!$proposal) {
                    echo json_encode(['success'=>false,'message'=>'Not found']); exit;
                }
                // determine which flag to flip
                $personId = // fetch current user’s person_id
                  $pdo->prepare("SELECT id FROM persons WHERE user_id=?")
                      ->execute([$_SESSION['user_id']]) 
                  && ($pid = $pdo->lastInsertId()) ? $pid : null;
                $isComplainant = $personId && $personId == $proposal['complainant_pid'];
                $isRespondent = $personId && $personId == $proposal['respondent_pid'];
                if (!$isComplainant && !$isRespondent) {
                    echo json_encode(['success'=>false,'message'=>'Not a party']); exit;
                }
                // on reject → immediate conflict
                if ($decision === 'reject') {
                    $pdo->prepare("UPDATE schedule_proposals SET status='conflict', conflict_reason=? WHERE id=?")
                        ->execute([$_REQUEST['reason'] ?? 'Declined', $proposalId]);
                    // reset blotter_cases to allow new proposals
                    $pdo->prepare("UPDATE blotter_cases SET scheduling_status='pending_schedule' WHERE id=?")
                        ->execute([$proposal['blotter_case_id']]);
                    echo json_encode(['success'=>true,'message'=>'You have declined this schedule']); exit;
                }
                // on confirm → flip correct column
                $col = $isComplainant ? 'complainant_confirmed' : 'respondent_confirmed';
                $pdo->prepare("UPDATE schedule_proposals SET {$col}=TRUE WHERE id=?")
                    ->execute([$proposalId]);
                // re‐fetch to see if both True
                $sp2 = $pdo->prepare("SELECT complainant_confirmed,respondent_confirmed FROM schedule_proposals WHERE id=?");
                $sp2->execute([$proposalId]);
                $flags = $sp2->fetch(PDO::FETCH_ASSOC);
                if ($flags['complainant_confirmed'] && $flags['respondent_confirmed']) {
                    // finalize hearing
                    $pdo->beginTransaction();
                    // insert hearing
                    $pdo->prepare("
                      INSERT INTO case_hearings 
                        (blotter_case_id, hearing_date, hearing_type, hearing_outcome,
                         presiding_officer_name, presiding_officer_position, hearing_number)
                      VALUES(?,CONCAT(?, ' ',?), 'mediation','scheduled',?,?,?)
                    ")->execute([
                      $proposal['blotter_case_id'],
                      $proposal['proposed_date'],
                      $proposal['proposed_time'],
                      $proposal['presiding_officer'],
                      $proposal['presiding_officer_position'],
                      ($proposal['hearing_number'] ?? 0) + 1
                    ]);
                    // update case
                    $pdo->prepare("
                      UPDATE blotter_cases
                      SET scheduling_status='scheduled', status='open'
                      WHERE id=?
                    ")->execute([$proposal['blotter_case_id']]);
                    $pdo->commit();
                    echo json_encode(['success'=>true,'message'=>'Hearing confirmed by both parties']);
                } else {
                    echo json_encode(['success'=>true,'message'=>'Your confirmation is recorded. Waiting on other party']);
                }
                exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// --- fetch pending proposals for current user BEFORE requiring header ---
// This is the key part that needs to be modified for proper role-based approvals
$pendingStatus = ($role === ROLE_CAPTAIN) 
    ? 'pending_user_confirmation'  // Captain should see proposals waiting for captain confirmation
    : 'pending_officer_confirmation'; // Chief should see proposals waiting for officer confirmation

$ppStmt = $pdo->prepare("
    SELECT sp.blotter_case_id AS case_id,
           bc.case_number,
           sp.proposed_date,
           sp.proposed_time,
           sp.id AS proposal_id,
           CONCAT(p.first_name, ' ', p.last_name) AS proposed_by_name
    FROM schedule_proposals sp
    JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
    JOIN users u ON sp.proposed_by_user_id = u.id
    LEFT JOIN persons p ON p.user_id = u.id
    WHERE sp.status = ?
      AND bc.barangay_id = ?
    ORDER BY sp.proposed_date ASC, sp.proposed_time ASC
");
$ppStmt->execute([$pendingStatus, $bid]);
$pendingProposals = $ppStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch cases for UI BEFORE requiring header
$stmt = $pdo->prepare("
    SELECT
      bc.*,
      bc.scheduling_status,
      GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories,
      bc.accepted_by_user_id,
      bc.filing_date,
      bc.scheduling_deadline,
      bc.captain_signature_date,
      bc.chief_signature_date,
      COUNT(ch.id) AS hearing_count,
      -- Get primary complainant name for 'Reported By' column
      (
        SELECT COALESCE(
          CONCAT(p.first_name, ' ', p.last_name),
          CONCAT(ep.first_name, ' ', ep.last_name),
          'Unknown'
        )
        FROM blotter_participants bp
        LEFT JOIN persons p ON bp.person_id = p.id
        LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
        WHERE bp.blotter_case_id = bc.id 
          AND bp.role = 'complainant'
        ORDER BY bp.id ASC
        LIMIT 1
      ) AS reported_by_name,
      EXISTS(
        SELECT 1
        FROM case_hearings
        WHERE blotter_case_id = bc.id
          AND hearing_outcome = 'scheduled'
      ) AS has_pending_hearing,
      (
        SELECT COUNT(*)
        FROM blotter_case_interventions
        WHERE blotter_case_id = bc.id
      ) AS intervention_count,
      -- Check if current user can approve pending schedules based on role-specific status
      CASE 
        WHEN bc.scheduling_status = 'schedule_proposed' THEN
          CASE 
            WHEN EXISTS(
              SELECT 1 FROM schedule_proposals sp
              WHERE sp.blotter_case_id = bc.id 
                AND sp.status = ?
            ) THEN 1
            ELSE 0
          END
        ELSE 0
      END AS can_approve_schedule,
      -- Get the proposer info for pending schedules
      (
        SELECT CONCAT(p.first_name, ' ', p.last_name)
        FROM schedule_proposals sp
        JOIN users u ON sp.proposed_by_user_id = u.id
        LEFT JOIN persons p ON p.user_id = u.id
        WHERE sp.blotter_case_id = bc.id 
          AND sp.status IN ('pending_officer_confirmation', 'pending_user_confirmation')
        ORDER BY sp.id DESC
        LIMIT 1
      ) AS schedule_proposer,
      (
        SELECT sp.proposed_date
        FROM schedule_proposals sp
        WHERE sp.blotter_case_id = bc.id 
          AND sp.status IN ('pending_officer_confirmation', 'pending_user_confirmation')
        ORDER BY sp.id DESC
        LIMIT 1
      ) AS pending_schedule_date,
      (
        SELECT sp.proposed_time
        FROM schedule_proposals sp
        WHERE sp.blotter_case_id = bc.id 
          AND sp.status IN ('pending_officer_confirmation', 'pending_user_confirmation')
        ORDER BY sp.id DESC
        LIMIT 1
      ) AS pending_schedule_time
    FROM blotter_cases bc
    LEFT JOIN blotter_case_categories bcc
      ON bc.id = bcc.blotter_case_id
    LEFT JOIN case_categories cc
      ON bcc.category_id = cc.id
    LEFT JOIN case_hearings ch
      ON bc.id = ch.blotter_case_id
    WHERE bc.barangay_id = ?
      AND bc.status != 'deleted'
    GROUP BY bc.id
    ORDER BY bc.created_at DESC
");
$stmt->execute([$pendingStatus, $bid]);
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
// ensure $cases is always defined
$cases = $cases ?? [];

$categories    = $pdo->query("SELECT * FROM case_categories ORDER BY name")->fetchAll();
$residents     = getResidents($pdo, $bid);
$interventions = $pdo->query("SELECT * FROM case_interventions ORDER BY name")->fetchAll();

require_once "../components/header.php";
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
        gap: 4px;
        flex-wrap: wrap;
        align-items: flex-start;
    }
    .hearing-actions button {
        padding: 4px 8px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
        font-size: 11px;
        white-space: nowrap;
    }
    .btn-schedule { background-color: #3b82f6; color: white; }
    .btn-record { background-color: #10b981; color: white; }
    .btn-cfa { background-color: #dc2626; color: white; }
    .btn-summons { background-color: #7c3aed; color: white; }
    .btn-report-form { background-color: #059669; color: white; }
    .btn-approve { background-color: #10b981; color: white; }
    .btn-reject { background-color: #dc2626; color: white; }
    
    /* Schedule pending notification styles */
    .schedule-pending-info {
        font-size: 10px;
        line-height: 1.2;
        margin-bottom: 4px;
        padding: 4px;
        border-radius: 4px;
    }
    
    .schedule-approval-buttons {
        display: flex;
        gap: 4px;
        margin-bottom: 6px;
    }
    
    .schedule-approval-buttons button {
        padding: 3px 6px;
        font-size: 10px;
        border-radius: 3px;
        border: none;
        cursor: pointer;
        color: white;
        min-width: 50px;
    }
    
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
    .status-schedule-pending-confirmation { background-color: #fef3c7; color: #92400e; }
    
    /* Document buttons styling */
    .document-buttons {
        display: flex;
        gap: 4px;
        margin-top: 4px;
        flex-wrap: wrap;
    }
    .document-buttons button {
        padding:  3px 6px;
        font-size: 10px;
        border-radius:  3px;
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

    /* Pending approvals banner styling */
    .pending-approvals-banner {
        margin: 1rem 0;
        padding: 1rem;
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 2px solid #f59e0b;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    .pending-approvals-title {
        font-size: 1.125rem;
        font-weight: 600;
        color: #92400e;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
    }
    
    .pending-approvals-title::before {
        content: "⚠️";
        margin-right: 0.5rem;
        font-size: 1.25rem;
    }
    
    .pending-proposal-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
        background: rgba(255, 255, 255, 0.7);
        border-radius: 0.375rem;
        border-left: 4px solid #f59e0b;
    }
    
    .pending-proposal-info {
        flex: 1;
        font-size: 0.875rem;
        color: #92400e;
    }
    
    .pending-proposal-actions {
        display: flex;
        gap: 0.5rem;
        margin-left: 1rem;
    }
    
    .pending-proposal-actions button {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 500;
        border-radius: 0.25rem;
        border: none;
        cursor: pointer;
        color: white;
        min-width: 50px;
    }
    
    .pending-proposal-actions .btn-approve {
        background-color: #10b981;
        color: white;
    }
    
    .pending-proposal-actions .btn-approve:hover {
        background-color: #059669;
        transform: translateY(-1px);
    }
    
    .pending-proposal-actions .btn-reject {
        background-color: #dc2626;
        color: white;
    }
    
    .pending-proposal-actions .btn-reject:hover {
        background-color: #b91c1c;
        transform: translateY(-1px);
    }
  </style>
</head>
<body>

<section id="blotter" class="p-6">

<!-- Include signature upload modal -->
<?php include '../components/signature_upload_modal.php'; ?>

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
      <form id="editBlotterForm" class="p-6 space-y-4 overflow-y-auto max-h-[calc(100%-6rem)]" enctype="multipart/form-data">
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
          <!-- Status -->
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
          <!-- Categories -->
          <div>
          <label class="block text-sm font-medium text-gray-700">Categories <span class="text-red-500">*</span></label>
            <div id="editCategoryContainer" class="grid grid-cols-2 gap-2">
              <?php foreach ($categories as $i => $cat): ?>
                <label class="flex items-center gap-2">
                  <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" class="rounded" <?= $i===0 ? 'required' : '' ?>>
                  <span class="text-sm"><?= htmlspecialchars($cat['name']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <!-- Participants -->
        <div class="space-y-2">
          <label class="block text-sm font-medium text-gray-700">Participants</label>
          <div id="editParticipantContainer" class="space-y-2"></div>
          <div class="flex gap-2">
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
      
      <?php if (in_array($role, [ROLE_CAPTAIN, ROLE_CHIEF])): ?>
      <button 
        onclick="toggleSignatureModal()"
        class="w-full sm:w-auto text-white bg-purple-600 hover:bg-purple-700 focus:ring-4 focus:ring-purple-300 
               font-medium rounded-lg text-sm px-5 py-2.5"
      >
         Upload Signature
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
              // Use the reported_by_name from the query, fallback to 'Unknown' if empty
              $reporter = !empty($case['reported_by_name']) ? $case['reported_by_name'] : 'Unknown';
              echo htmlspecialchars($reporter);
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
          </td>
          <td class="px-4 py-3 text-sm text-gray-600">
            <!-- Schedule pending notifications and approval buttons -->
            <?php if ($case['scheduling_status']==='schedule_proposed' && $case['can_approve_schedule']): ?>
              <div class="schedule-pending-info bg-yellow-50 border border-yellow-200 rounded">
                <strong>Pending Schedule:</strong><br>
                Proposed by: <?= htmlspecialchars($case['schedule_proposer'] ?? 'Unknown') ?><br>
                Date: <?= $case['pending_schedule_date']? date('M d, Y',strtotime($case['pending_schedule_date'])):'N/A' ?><br>
                Time: <?= $case['pending_schedule_time']? date('g:i A',strtotime($case['pending_schedule_time'])):'N/A' ?>
              </div>
              <div class="schedule-approval-buttons">
                <button class="btn-approve approve-schedule-btn" data-id="<?= $case['id'] ?>">Available</button>
                <button class="btn-reject reject-schedule-btn" data-id="<?= $case['id'] ?>">Not Available</button>
              </div>
            <?php elseif ($case['scheduling_status']==='schedule_proposed' && !$case['can_approve_schedule']): ?>
              <div class="schedule-pending-info bg-blue-50 border border-blue-200 rounded">
                <strong>Schedule Pending:</strong><br>
                Waiting for approval from <?= ($role === ROLE_CAPTAIN) ? 'Chief Officer' : 'Barangay Captain' ?>
              </div>
            <?php endif; ?>
            
            <!-- Regular action buttons -->
            <div class="hearing-actions">
              <?php if ($canManageBlotter): ?>
                  <button class="edit-btn text-indigo-600 hover:text-indigo-900" data-id="<?= $case['id'] ?>">Edit</button>
                  
                  <!-- Document Generation Buttons -->
                  <div class="document-buttons">
                      <button class="btn-summons generate-summons-btn" data-id="<?= $case['id'] ?>" title="Generate Summons">
                        Summons
                      </button>
                      <button class="btn-report-form generate-report-form-btn" data-id="<?= $case['id'] ?>" title="Generate Report Form">
                        Report
                      </button>
                  </div>
              <?php endif; ?>
              
              <?php if ($canScheduleHearings && $case['scheduling_status'] !== 'schedule_pending_confirmation'): ?>
                  <?php if (
                    ($case['hearing_count'] ?? 0) < 3
                    && !in_array($case['status'], ['solved','endorsed_to_court','dismissed'])
                    && !$case['has_pending_hearing']
                  ): ?>
                    <button class="btn-schedule schedule-hearing-btn" data-id="<?= $case['id'] ?>">
                      Schedule Hearing
                    </button>
                  <?php endif; ?>
                  <?php if (!empty($case['is_cfa_eligible']) && $case['status'] === 'cfa_eligible'): ?>
                    <button class="btn-cfa issue-cfa-btn" data-id="<?= $case['id'] ?>">
                      Issue CFA
                    </button>
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
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
// Define handler functions globally first
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

    // Populate categories
    const categoryCheckboxes = document.querySelectorAll('#editCategoryContainer input[type="checkbox"]');
    categoryCheckboxes.forEach(cb => cb.checked = false);
    const categoryIds = caseData.category_ids ? caseData.category_ids.split(',').map(id => id.trim()) : [];
    categoryIds.forEach(id => {
        const cb = document.querySelector(`#editCategoryContainer input[value="${id}"]`);
        if (cb) cb.checked = true;
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

async function handleCompleteCase(caseId) {
  try {
    const result = await Swal.fire({
      title: 'Close Case?',
      text: 'This will mark the case as closed. Continue?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, close it'
    });
    
    if (result.isConfirmed) {
      const response = await fetch(`?action=complete&id=${caseId}`);
      const data = await response.json();
      
      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Success!',
          text: 'Case has been closed successfully.',
          timer: 1500,
          showConfirmButton: false
        }).then(() => location.reload());
      } else {
        Swal.fire('Error', data.message || 'Failed to close case', 'error');
      }
    }
  } catch (error) {
    console.error('Error closing case:', error);
    Swal.fire('Error', 'An unexpected error occurred.', 'error');
  }
}

async function handleDeleteCase(caseId) {
  try {
    const result = await Swal.fire({
      title: 'Dismiss Case?',
      text: 'This will dismiss the case. Continue?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc2626',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, dismiss it'
    });
    
    if (result.isConfirmed) {
      const response = await fetch(`?action=delete&id=${caseId}`);
      const data = await response.json();
      
      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Success!',
          text: 'Case has been dismissed successfully.',
          timer: 1500,
          showConfirmButton: false
        }).then(() => location.reload());
      } else {
        Swal.fire('Error', data.message || 'Failed to dismiss case', 'error');
      }
    }
  } catch (error) {
    console.error('Error dismissing case:', error);
    Swal.fire('Error', 'An unexpected error occurred.', 'error');
  }
}

// Populate time slots excluding already booked
async function populateTimes(date, selectEl) {
    selectEl.innerHTML = '<option>Loading...</option>';
    const res = await fetch(`?action=get_available_slots&date=${date}`);
    const data = await res.json();
    const slots = [];
    for (let h = 8; h <= 17; h++) {
        const hh = String(h).padStart(2,'0');
        const val = `${hh}:00`;
        const txt = new Date(`${date}T${val}:00`)
                      .toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
        slots.push({val,txt});
    }
    selectEl.innerHTML = '';
    const available = slots.filter(s => !data.booked.includes(s.val+':00'));
    if (available.length) {
        available.forEach(s => selectEl.add(new Option(s.txt, s.val)));
    } else {
        selectEl.add(new Option('No available times',''));
    }
}

async function handleScheduleHearing(caseId) {
    const today = new Date().toISOString().split('T')[0];
    const maxDate = new Date(Date.now()+5*86400000).toISOString().split('T')[0];

    const { value: formValues } = await Swal.fire({
        title: 'Schedule Hearing',
        html: `
          <label>Date</label>
          <input id="hearing-date" type="date" min="${today}" max="${maxDate}" class="swal2-input">
          <label>Time</label>
          <select id="hearing-time" class="swal2-input"></select>
          <label>Location</label>
          <input id="hearing-location" type="text" value="Barangay Hall" class="swal2-input">
        `,
        showCancelButton: true,
        confirmButtonText: 'Schedule',
        focusConfirm: false,
        didOpen: () => {
            const dateEl = Swal.getPopup().querySelector('#hearing-date');
            const timeEl = Swal.getPopup().querySelector('#hearing-time');
            dateEl.value = today;
            dateEl.addEventListener('change', () => populateTimes(dateEl.value, timeEl));
            populateTimes(today, timeEl);
        },
        preConfirm: () => {
            const d = document.getElementById('hearing-date').value;
            const t = document.getElementById('hearing-time').value;
            const loc = document.getElementById('hearing-location').value;
            if (!d || !t) {
                Swal.showValidationMessage('Please select date and time');
                return false;
            }
            return { hearing_date: d, hearing_time: t, hearing_location: loc };
        }
    });

    if (formValues) {
        Swal.fire({ title:'Scheduling...', didOpen:()=>Swal.showLoading() });
        const res = await fetch(`?action=schedule_hearing&id=${caseId}`, {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(formValues)
        });
        const data = await res.json();
        Swal.close();
        if (data.success) Swal.fire({ icon:'success', title:'Scheduled', timer:1500 }).then(()=>location.reload());
        else Swal.fire('Error', data.message,'error');
    }
}

async function handleIssueCFA(caseId) {
  try {
    // First get case details to show complainants
    const detailsResponse = await fetch(`?action=get_case_details&id=${caseId}`);
    const detailsData = await detailsResponse.json();
    
    if (!detailsData.success) {
      Swal.fire('Error', 'Failed to load case details', 'error');
      return;
    }
    
    const complainants = detailsData.participants.filter(p => p.role === 'complainant');
    
    if (complainants.length === 0) {
      Swal.fire('Error', 'No complainants found for this case', 'error');
      return;
    }
    
    // Show complainant selection
    const { value: complainantId } = await Swal.fire({
      title: 'Select Complainant',
      text: 'Select the complainant for whom to issue the CFA certificate:',
      input: 'select',
      inputOptions: complainants.reduce((options, c) => {
        const id = c.person_id || c.external_participant_id;
        const name = `${c.first_name} ${c.last_name}`;
        options[id] = name;
        return options;
      }, {}),
      showCancelButton: true,
      inputValidator: (value) => {
        if (!value) {
          return 'Please select a complainant';
        }
      }
    });
    
    if (complainantId) {
      // Issue CFA
      const response = await fetch(`?action=issue_cfa&id=${caseId}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ complainant_id: complainantId })
      });
      
      const data = await response.json();
      
      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'CFA Issued!',
          text: `Certificate number: ${data.certificate_number}`,
          timer: 3000,
          showConfirmButton: true
        }).then(() => location.reload());
      } else {
        Swal.fire('Error', data.message || 'Failed to issue CFA', 'error');
      }
    }
  } catch (error) {
    console.error('Error issuing CFA:', error);
    Swal.fire('Error', 'An unexpected error occurred.', 'error');
  }
}

async function handleAddIntervention(caseId) {
  try {
    const { value: formValues } = await Swal.fire({
      title: 'Add Intervention',
      html: `
        <div class="mb-3">
          <label class="block text-gray-700 text-sm font-bold mb-2">Intervention Type</label>
          <select id="intervention-type" class="w-full p-2 border rounded">
            <option value="">Select intervention...</option>
            <?php foreach ($interventions as $int): ?>
              <option value="<?= $int['id'] ?>"><?= htmlspecialchars($int['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="block text-gray-700 text-sm font-bold mb-2">Date Intervened</label>
          <input id="intervention-date" type="date" class="w-full p-2 border rounded" value="${new Date().toISOString().split('T')[0]}">
        </div>
        <div class="mb-3">
          <label class="block text-gray-700 text-sm font-bold mb-2">Remarks</label>
          <textarea id="intervention-remarks" class="w-full p-2 border rounded" rows="3"></textarea>
        </div>
      `,
      focusConfirm: false,
      showCancelButton: true,
      confirmButtonText: 'Add Intervention',
      preConfirm: () => {
        const interventionType = document.getElementById('intervention-type').value;
        const interventionDate = document.getElementById('intervention-date').value;
        const remarks = document.getElementById('intervention-remarks').value;
        
        if (!interventionType || !interventionDate) {
          Swal.showValidationMessage('Please fill in all required fields');
          return false;
        }
        
        return {
          intervention_id: interventionType,
          date_intervened: interventionDate,
          remarks: remarks
        };
      }
    });
    
    if (formValues) {
      const response = await fetch(`?action=add_intervention&id=${caseId}`, {
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
          text: 'Intervention has been added successfully.',
          timer: 1500,
          showConfirmButton: false
        }).then(() => location.reload());
      } else {
        Swal.fire('Error', data.message || 'Failed to add intervention', 'error');
      }
    }
  } catch (error) {
    console.error('Error adding intervention:', error);
    Swal.fire('Error', 'An unexpected error occurred.', 'error');
  }
}

// Now document ready event listener
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
    
    // Modal toggle functions
    window.toggleAddBlotterModal = function() {
        const modal = document.getElementById('addBlotterModal');
        const container = document.getElementById('participantContainer');
        if (modal) {
            modal.classList.toggle('hidden');
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

    // Button event listeners
    const openBtn = document.getElementById('openModalBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const participantContainer = document.getElementById('participantContainer');
    
    if (openBtn) {
      openBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (participantContainer) participantContainer.innerHTML = '';
        document.getElementById('addBlotterModal').classList.remove('hidden');
      });
    }
    
    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => {
        document.getElementById('addBlotterModal').classList.add('hidden');
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
    const editModal = document.getElementById('editBlotterModal');
    const editForm = document.getElementById('editBlotterForm');
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

    // Direct button event listeners - this is the key fix
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const caseId = this.getAttribute('data-id');
            handleEditCase(caseId);
        });
    });
    
    document.querySelectorAll('.generate-summons-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const caseId = this.getAttribute('data-id');
            window.open(`?action=generate_summons&id=${caseId}`, '_blank');
        });
    });
    
    document.querySelectorAll('.generate-report-form-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const caseId = this.getAttribute('data-id');
            window.open(`?action=generate_report_form&id=${caseId}`, '_blank');
        });
    });
    
    document.querySelectorAll('.schedule-hearing-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const caseId = this.getAttribute('data-id');
            handleScheduleHearing(caseId);
        });
    });
    
    document.querySelectorAll('.complete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const caseId = this.getAttribute('data-id');
            handleCompleteCase(caseId);
        });
    });
    
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', () => handleDeleteCase(btn.dataset.id));
    });
    
    document.querySelectorAll('.approve-schedule-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const caseId = btn.dataset.id;
        Swal.fire({
          title: 'Confirm Schedule',
          text: 'Are you available for this hearing date and time?',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#10b981',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'Yes, approve'
        }).then((result) => {
          if (result.isConfirmed) {
            Swal.fire({
              title: 'Processing...',
              text: 'Confirming your availability',
              allowOutsideClick: false,
              didOpen: () => {
                Swal.showLoading();
              }
            });
            
            fetch(`?action=approve_schedule&id=${caseId}`)
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  Swal.fire({
                    title: 'Success!',
                    text: data.message || 'Schedule approved successfully',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                  }).then(() => location.reload());
                } else {
                  Swal.fire('Error', data.message || 'Failed to approve schedule', 'error');
                }
              })
              .catch(error => {
                console.error('Error approving schedule:', error);
                Swal.fire('Error', 'An unexpected error occurred', 'error');
              });
          }
        });
      });
    });
    
    document.querySelectorAll('.reject-schedule-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const caseId = btn.dataset.id;
        Swal.fire({
          title: 'Reject Schedule',
          text: 'Please provide a reason for rejecting this schedule:',
          input: 'text',
          inputPlaceholder: 'Enter your reason',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#dc2626',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'Reject schedule',
          inputValidator: (value) => {
            if (!value) {
              return 'Please provide a reason';
            }
          }
        }).then((result) => {
          if (result.isConfirmed) {
            Swal.fire({
              title: 'Processing...',
              text: 'Rejecting the schedule proposal',
              allowOutsideClick: false,
              didOpen: () => {
                Swal.showLoading();
              }
            });
            
            fetch(`?action=reject_schedule&id=${caseId}`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({ reason: result.value })
            })
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  Swal.fire({
                    title: 'Schedule Rejected',
                    text: data.message || 'Schedule rejected successfully',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                  }).then(() => location.reload());
                } else {
                  Swal.fire({
                    title: 'Error',
                    text: data.message || 'Failed to reject schedule',
                    icon: 'error'
                  });
                }
              })
              .catch(error => {
                console.error('Error rejecting schedule:', error);
                Swal.fire({
                  title: 'Error',
                  text: 'An unexpected error occurred',
                  icon: 'error'
                });
              });
          }
        });
      });
    });

    // Add status change handler
    document.querySelectorAll('.status-select').forEach(select => {      
      select.addEventListener('change', function() {
        const caseId = this.getAttribute('data-id');
        const newStatus = this.value;
        
        if (!newStatus) return;
        
        // Confirm status change
        Swal.fire({
          title: 'Change Status?',
          text: `This will change the case status to ${newStatus}.`,
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#3b82f6',
          cancelButtonColor: '#6b7280',
          confirmButtonText: 'Yes, change it'
        }).then((result) => {
          if (result.isConfirmed) {
            fetch(`?action=set_status&id=${caseId}&new_status=${newStatus}`)
              .then(response => response.json())
              .then(data => {
                if (data.success) {
                  Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: `Status changed to ${newStatus}.`,
                    timer: 1500,
                    showConfirmButton: false
                  }).then(() => location.reload());
                } else {
                  Swal.fire('Error', data.message || 'Failed to change status', 'error');
                }
              })
              .catch(error => {
                console.error('Error changing status:', error);
                Swal.fire('Error', 'An unexpected error occurred.', 'error');
              });
          } else {
            // Revert the select to the previous value if change was not confirmed
            const currentStatus = this.getAttribute('data-current-status');
            this.value = currentStatus;
          }
        });
      });
    });
});
</script>
</body>
</html>