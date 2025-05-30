<?php
// blotter.php – ADMIN SIDE
session_start();
require "../config/dbconn.php";
require "../vendor/autoload.php";
use Dompdf\Dompdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;  

// Authentication & role check
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}

function transcribeFile(string $filePath): string {
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if (!$apiKey) throw new Exception("Missing OPENAI_API_KEY");

    $fileSize = filesize($filePath);
    if ($fileSize > 25 * 1024 * 1024) {
        throw new Exception("File too large. Maximum size is 25MB.");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($filePath);
    $allowedTypes = [
        'audio/mpeg', 'audio/mp4', 'audio/mp3', 'audio/wav', 'audio/x-wav', 
        'audio/webm', 'audio/ogg', 'video/mp4', 'video/webm', 'video/ogg'
    ];
    
    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception("Unsupported file type: $mimeType. Please upload an audio or video file.");
    }

    $cfile = new CURLFile($filePath);
    $post = [
        'file'  => $cfile,
        'model' => 'whisper-1',
        'response_format' => 'json',
        'temperature' => 0.2,
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$apiKey}", "Content-Type: multipart/form-data"],
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 300,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) throw new Exception('Connection error: ' . curl_error($ch));
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($resp, true);
        $message = $error['error']['message'] ?? "API returned HTTP $httpCode";
        throw new Exception("Transcription failed: $message");
    }

    $data = json_decode($resp, true);
    if (empty($data['text'])) throw new Exception('No transcription text returned from API.');

    return trim($data['text']);
}

$current_admin_id = $_SESSION['user_id'];
$bid = $_SESSION['barangay_id'];
$role = $_SESSION['role_id'];
$allowedStatuses = ['pending','open','closed','completed','solved','endorsed_to_court','cfa_eligible'];

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

function updateCaseStatus($pdo, $caseId) {
    $stmt = $pdo->prepare("
        SELECT hearing_number, hearing_outcome, is_mediation_successful 
        FROM case_hearings 
        WHERE blotter_case_id = ? 
        ORDER BY hearing_number DESC 
        LIMIT 1
    ");
    $stmt->execute([$caseId]);
    $lastHearing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastHearing) {
        if ($lastHearing['is_mediation_successful']) {
            $pdo->prepare("UPDATE blotter_cases SET status = 'solved' WHERE id = ?")->execute([$caseId]);
        } elseif ($lastHearing['hearing_number'] >= 3 && 
                in_array($lastHearing['hearing_outcome'], ['failed', 'no_show'])) {
            $pdo->prepare("UPDATE blotter_cases SET status = 'cfa', is_cfa_eligible = TRUE WHERE id = ?")
                ->execute([$caseId]);
        }
    }
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

    // Get captain's esignature if available and case is open or later
    $esignaturePath = null;
    if (in_array($case['status'], ['open','closed','completed','solved','endorsed_to_court','cfa_eligible'])) {
        $esignaturePath = getCaptainEsignature($pdo, $case['barangay_id']);
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
    
    // Get captain's esignature if available and case is open or later
    $esignaturePath = null;
    if (in_array($case['status'], ['open','closed','completed','solved','endorsed_to_court','cfa_eligible'])) {
        $esignaturePath = getCaptainEsignature($pdo, $case['barangay_id']);
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
                        <?php if ($esignaturePath): ?>
                            <img src="<?= htmlspecialchars($esignaturePath) ?>" alt="E-signature" style="height:50px;max-width:180px;display:block;margin-left:auto;margin-bottom:2mm;">
                        <?php endif; ?>
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
        SELECT u.esignature_path
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.barangay_id = ? AND r.name = 'Barangay Captain'
        LIMIT 1
    ");
    $stmt->execute([$barangayId]);
    $path = $stmt->fetchColumn();
    return $path ? "../$path" : null;
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

    // Only attempt transcription if no description and a file was uploaded
    if (empty($description) && !empty($_FILES['transcript_file']['tmp_name'])) {
        try {
            $_SESSION['info_message'] = 'Processing audio transcription...';
            session_write_close();
            $tmpPath = $_FILES['transcript_file']['tmp_name'];
            $description = transcribeFile($tmpPath);
            session_start();
            unset($_SESSION['info_message']);
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Transcription failed: ' . $e->getMessage();
            header('Location: blotter.php');
            exit;
        }
    }

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

// Handle AJAX transcription requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transcribe_only') {
    header('Content-Type: application/json');
    
    if (empty($_FILES['transcript_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }
    
    try {
        $tmpPath = $_FILES['transcript_file']['tmp_name'];
        $transcriptionText = transcribeFile($tmpPath);
        
        echo json_encode([
            'success' => true, 
            'text' => $transcriptionText
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// === AJAX actions ===
if (!empty($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $id     = intval($_GET['id'] ?? 0);

    if (in_array($action, ['delete','complete','set_status','add_intervention','update_case', 'schedule_hearing', 'record_hearing', 'issue_cfa'], true)
        && !in_array($role, [3, 4, 5], true)) {
        echo json_encode(['success'=>false,'message'=>'Permission denied']);
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
                            SELECT id
                            FROM case_interventions
                            WHERE name = 'M/CSWD'
                          ), bc.id, NULL
                        )) AS mcwsd,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id
                            FROM case_interventions
                            WHERE name = 'PNP'
                          ), bc.id, NULL
                        )) AS total_pnp,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id
                            FROM case_interventions
                            WHERE name = 'Court'
                          ), bc.id, NULL
                        )) AS total_court,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id
                            FROM case_interventions
                            WHERE name = 'Issued BPO'
                          ), bc.id, NULL
                        )) AS total_bpo,
                        COUNT(DISTINCT IF(
                          bci.intervention_id = (
                            SELECT id
                            FROM case_interventions
                            WHERE name = 'Medical'
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
                          <th>Nature of case</th>
                          <th>Total number of case reported</th>
                          <th>M/CSWD</th>
                          <th>PNP</th>
                          <th>COURT</th>
                          <th>ISSUED BPOs</th>
                          <th>MEDICAL</th>
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
                $pdo->prepare("UPDATE blotter_cases SET status='closed' WHERE id=?")
                    ->execute([$id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, 'Status → Deleted');
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
                      WHEN bp.external_participant_id IS NOT NULL THEN 'unregistered'
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
                $data = json_decode(file_get_contents('php://input'), true);
                if (empty($data['hearing_date']) || empty($data['hearing_time'])) {
                    echo json_encode(['success'=>false,'message'=>'Hearing date and time are required']);
                    exit;
                }

                // Only Barangay Captain allowed
                if (
                    empty($data['presiding_officer']) ||
                    strtolower(trim($data['presiding_officer'])) !== 'barangay captain' ||
                    (isset($data['officer_position']) && strtolower($data['officer_position']) !== 'barangay_captain')
                ) {
                    echo json_encode(['success'=>false,'message'=>'Only Barangay Captain can be the presiding officer for the hearing.']);
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
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'proposed')
                ");
                $stmt->execute([
                    $id,
                    $current_admin_id,
                    $data['hearing_date'],
                    $data['hearing_time'],
                    $data['hearing_location'] ?? 'Barangay Hall',
                    $data['presiding_officer'] ?? 'Barangay Captain',
                    $data['officer_position'] ?? 'barangay_captain'
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
                            INSERT INTO hearing_attendances 
                            (hearing_id, participant_id, is_present, participant_type, attendance_remarks)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                            is_present = VALUES(is_present),
                            attendance_remarks = VALUES(attendance_remarks)
                        ")->execute([
                            $hearingId,
                            $attendance['participant_id'],
                            $attendance['is_present'] ? 1 : 0,
                            $attendance['type'],
                            $attendance['remarks'] ?? ''
                        ]);
                    }
                }
                
                // Update case status based on hearing outcome
                updateCaseStatus($pdo, $id);
                
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
                            INSERT INTO blotter_case_interventions
                              (blotter_case_id, intervention_id, intervened_at)
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
                            $key = $cid . '-' . intval($p['user_id']) . '-' . $p['role'];
                            if (isset($insertedParticipants[$key])) continue;
                            $insertedParticipants[$key] = true;
                            $regStmt->execute([$cid, (int)$p['user_id'], $p['role']]);
                        } else {
                            $fname = trim($p['first_name']);
                            $lname = trim($p['last_name']);
                            $key = $cid . '-null-' . $fname . '-' . $lname . '-' . $p['role'];
                            if (isset($insertedParticipants[$key])) continue;
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
    SELECT bc.*, GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories
    FROM blotter_cases bc
    LEFT JOIN blotter_case_categories bcc ON bc.id=bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id=cc.id
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
    /* Transcription loader styles */
    .transcript-loader {
        display: none;
        position: relative;
        padding: 15px;
        text-align: center;
        background-color: #f9fafb;
        border-radius: 8px;
        margin-top: 10px;
    }
    .spinner {
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 4px solid rgba(0, 0, 0, 0.1);
        border-radius: 50%;
        border-top-color: #3b82f6;
        animation: spin 1s ease-in-out infinite;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    .transcript-status {
        margin-top: 10px;
        font-size: 14px;
    }
    .transcript-result {
        display: none;
        margin-top: 10px;
        padding: 10px;
        background-color: #ecfdf5;
        border-radius: 8px;
        color: #065f46;
    }
    
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
                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
              <?php foreach ($allowedStatuses as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
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
                    class="text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5">
              Add Registered
            </button>
            <button type="button" id="editAddUnregisteredBtn"
                    class="text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5">
              Add Unregistered
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
          <!-- Improved Audio/Video Upload Section -->
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700">
              Upload Audio/Video for Transcription (optional)
            </label>
            <div class="flex items-center mt-1">
              <input 
                type="file" 
                id="transcript_file" 
                name="transcript_file" 
                accept="audio/*,video/*"
                class="block w-full text-sm text-gray-900 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
              />
              <button 
                type="button" 
                id="transcribe_btn"
                class="ml-3 inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              >
                Transcribe
              </button>
            </div>
            <div id="transcript_loader" class="transcript-loader">
              <div class="spinner"></div>
              <div class="transcript-status">Transcribing your audio/video... This may take a few moments.</div>
            </div>
            <div id="transcript_result" class="transcript-result">
              <div class="font-medium">Transcription Complete!</div>
              <div id="transcript_text" class="mt-1 text-sm"></div>
            </div>
          </div>
                <!-- Interventions -->
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Interventions</label>
        <div class="grid grid-cols-2 gap-2">
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
          <label class="block text-sm font-medium text-gray-700">Categories <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-2 gap-2">
            <?php foreach ($categories as $i => $cat): ?>
              <label class="flex items-center gap-2">
                <input
                  type="checkbox"
                  name="categories[]"
                  value="<?= $cat['id'] ?>"
                >
                <?= htmlspecialchars($cat['name']) ?>
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
                      class="text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:outline-none focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5">
                Add Registered Resident
              </button>
              <button type="button" id="addUnregisteredBtn"
                      class="text-white bg-green-600 hover:bg-green-700 focus:ring-4 focus:outline-none focus:ring-green-300 font-medium rounded-lg text-sm px-5 py-2.5">
                Add Unregistered Person
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
      <button 
        id="openModalBtn"
        class="w-full sm:w-auto text-white bg-blue-600 hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 
               font-medium rounded-lg text-sm px-5 py-2.5"
      >
        + Add New Case
      </button>
      <a
        href="?action=generate_report&year=<?=date('Y')?>&month=<?=date('n')?>"
        class="w-full sm:w-auto inline-block text-center text-white bg-indigo-600 hover:bg-indigo-700 
               focus:ring-4 focus:ring-indigo-300 font-medium rounded-lg text-sm px-5 py-2.5"
      >
        Generate <?=date('F Y')?> Report
      </a>
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
              // Display the name of who reported (complainant)
              $stmt = $pdo->prepare("
                SELECT COALESCE(CONCAT(p.first_name, ' ', p.last_name), CONCAT(ep.first_name, ' ', ep.last_name)) AS reporter
                FROM blotter_participants bp
                LEFT JOIN persons p ON bp.person_id = p.id
                LEFT JOIN external_participants ep ON bp.external_participant_id = ep.id
                WHERE bp.blotter_case_id = ? AND bp.role = 'complainant'
                LIMIT 1
              ");
              $stmt->execute([$case['id']]);
              $reporter = $stmt->fetchColumn();
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
              <select class="status-select p-1 border rounded text-sm" data-id="<?= $case['id'] ?>">
                <?php foreach ($allowedStatuses as $s): ?>
                  <option value="<?= $s ?>" <?= $case['status']===$s?'selected':'' ?>>
                    <?= ucfirst(str_replace('_', ' ', $s)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <span class="status-badge status-<?= str_replace('_', '-', $case['status']) ?>">
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
            <div class="hearing-actions">
              <button class="view-btn text-blue-600 hover:text-blue-900" data-id="<?= $case['id'] ?>">View</button>
              <button class="edit-btn text-indigo-600 hover:text-indigo-900" data-id="<?= $case['id'] ?>">Edit</button>
              
              <!-- Document Generation Buttons -->
              <div class="document-buttons">
                <button class="btn-summons generate-summons-btn" data-id="<?= $case['id'] ?>" title="Generate Summons">
                  📋 Summons
                </button>
                <button class="btn-report-form generate-report-form-btn" data-id="<?= $case['id'] ?>" title="Generate Report Form">
                  📄 Report
                </button>
              </div>
              
              <?php if (in_array($role, [3, 4, 5])): ?>
                <?php if (($case['hearing_count'] ?? 0) < 3 && !in_array($case['status'], ['solved', 'endorsed_to_court'])): ?>
                  <button class="btn-schedule schedule-hearing-btn" data-id="<?= $case['id'] ?>">Schedule Hearing</button>
                <?php endif; ?>
                <?php if (!empty($case['is_cfa_eligible']) && $case['status'] === 'cfa_eligible'): ?>
                  <button class="btn-cfa issue-cfa-btn" data-id="<?= $case['id'] ?>">Issue CFA</button>
                <?php endif; ?>
                <?php if ($case['status'] !== 'closed'): ?>
                  <button class="complete-btn text-green-600 hover:text-green-900" data-id="<?= $case['id'] ?>">Close</button>
                <?php endif; ?>
                <button class="delete-btn text-red-600 hover:text-red-900" data-id="<?= $case['id'] ?>">Dismiss</button>
                <?php if (in_array($case['status'], ['closed', 'solved'])): ?>
                  <button class="intervention-btn text-purple-600 hover:text-purple-900" data-id="<?= $case['id'] ?>">Intervene</button>
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
document.addEventListener('DOMContentLoaded', () => {
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
    window.toggleViewBlotterModal = () => {
      document.getElementById('viewBlotterModal').classList.toggle('hidden');
    };
    
    window.toggleAddBlotterModal = () => {
      document.getElementById('addBlotterModal').classList.toggle('hidden');
    };
    
    window.toggleEditBlotterModal = () => {
      document.getElementById('editBlotterModal').classList.toggle('hidden');
    };
    
    // Add modal setup
    const addModal = document.getElementById('addBlotterModal');
    const openBtn  = document.getElementById('openModalBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const participantContainer = document.getElementById('participantContainer');
    
    // Event listeners
    openBtn.addEventListener('click', () => {
      participantContainer.innerHTML = '';
      addModal.classList.remove('hidden');
    });
    
    cancelBtn.addEventListener('click', () => addModal.classList.add('hidden'));
    
    document.getElementById('addRegisteredBtn')
      .addEventListener('click', () => addParticipant(registeredTemplate, participantContainer));
    document.getElementById('addUnregisteredBtn')
      .addEventListener('click', () => addParticipant(unregisteredTemplate, participantContainer));

    // Edit modal setup
    const editModal  = document.getElementById('editBlotterModal');
    const editForm   = document.getElementById('editBlotterForm');
    const editCancel = document.getElementById('editCancelBtn');
    const editPartCont = document.getElementById('editParticipantContainer');
    
    document.getElementById('editAddRegisteredBtn')
      .addEventListener('click', () => addParticipant(registeredTemplate, editPartCont));
    document.getElementById('editAddUnregisteredBtn')
      .addEventListener('click', () => addParticipant(unregisteredTemplate, editPartCont));
    
    editCancel.addEventListener('click', () => editModal.classList.add('hidden'));

    // Status change handlers
    document.querySelectorAll('.status-select').forEach(el => {
      el.addEventListener('change', async () => {
        const res = await fetch(`?action=set_status&id=${el.dataset.id}&new_status=${encodeURIComponent(el.value)}`);
        const data = await res.json();
        if (!data.success) alert(data.message || 'Failed');
        else location.reload();
      });
    });

    // View modal handler
    async function openViewModal(caseId) {
      const response = await fetch(`?action=get_case_details&id=${caseId}`);
      const data = await response.json();
      
      if (!data.success || !data.case) {
        Swal.fire('Error', 'Could not load case details', 'error');
        return;
      }

      // Populate basic case details
      document.getElementById('viewCaseNumber').textContent = data.case.case_number || '—';
      document.getElementById('viewDate').textContent = data.case.date_reported ? 
        new Date(data.case.date_reported).toLocaleString() : '—';
      document.getElementById('viewLocation').textContent = data.case.location || '—';
      document.getElementById('viewDescription').textContent = data.case.description || '—';
      document.getElementById('viewCategories').textContent = data.case.categories || '—';
      document.getElementById('viewStatus').textContent = data.case.status ? 
        data.case.status.charAt(0).toUpperCase() + data.case.status.slice(1) : '—';

      // Populate participants list
      document.getElementById('viewParticipants').innerHTML = data.participants.length ?
        data.participants.map(p => `
          <li>
            <strong>${p.first_name} ${p.last_name}</strong> (${p.role})
            ${p.contact_number ? `<br>Contact: ${p.contact_number}` : ''}
            ${p.address ? `<br>Address: ${p.address}` : ''}
          </li>
        `).join('') : '<li>No participants recorded</li>';

      // Populate interventions
      const iList = data.interventions.length ?
        data.interventions.map(i => `<li><strong>${i.intervention_name}</strong> (${i.intervened_at}): ${i.remarks || 'No remarks'}</li>`).join('') :
        '<li>None</li>';
      document.getElementById('viewInterventions').innerHTML = iList;

      // Display hearings
      const hearingsBody = document.getElementById('viewHearingsBody');
      if (data.hearings && data.hearings.length > 0) {
        hearingsBody.innerHTML = data.hearings.map(h => {
          const outcome = h.hearing_outcome ? h.hearing_outcome.replace('_', '-') : 'scheduled';
          const outcomeText = h.hearing_outcome ? h.hearing_outcome.replace('_', ' ') : 'Scheduled';
          return `
          <tr>
            <td>Hearing ${h.hearing_number}</td>
            <td>${new Date(h.hearing_date).toLocaleString()}</td>
            <td>${h.presiding_officer_name} (${h.presiding_officer_position ? h.presiding_officer_position.replace('_', ' ') : ''})</td>
            <td>
              <span class="status-badge status-${outcome}">
                ${outcomeText}
              </span>
              ${h.is_mediation_successful ? '<br><strong>✓ Mediation Successful</strong>' : ''}
            </td>
            <td>
              ${h.hearing_outcome === 'scheduled' ? 
                `<button class="btn-record record-hearing-btn" data-hearing-id="${h.id}" data-case-id="${caseId}">Record Outcome</button>` : 
                'Completed'
              }
            </td>
          </tr>
        `}).join('');
      } else {
        hearingsBody.innerHTML = '<tr><td colspan="5">No hearings scheduled</td></tr>';
      }

      // Show the modal
      toggleViewBlotterModal();
    }

    // Event delegation for document generation buttons
    document.addEventListener('click', e => {
      if (e.target.classList.contains('generate-summons-btn')) {
        const caseId = e.target.dataset.id;
        window.open(`?action=generate_summons&id=${caseId}`, '_blank');
      }
      
      if (e.target.classList.contains('generate-report-form-btn')) {
        const caseId = e.target.dataset.id;
        window.open(`?action=generate_report_form&id=${caseId}`, '_blank');
      }
    });

    // View buttons
    document.querySelectorAll('.view-btn').forEach(btn => {
      btn.addEventListener('click', () => openViewModal(btn.dataset.id));
    });

    // Complete button handler
    document.querySelectorAll('.complete-btn').forEach(btn => btn.addEventListener('click', async () => {
      const ok = await Swal.fire({ title:'Close this case?', icon:'question', showCancelButton:true });
      if (!ok.isConfirmed) return;
      const res = await fetch(`?action=complete&id=${btn.dataset.id}`);
      const d   = await res.json();
      if (d.success) Swal.fire({ icon:'success', timer:1200 }).then(() => location.reload());
      else Swal.fire('Error', d.message, 'error');
    }));

    // Delete button handler
    document.querySelectorAll('.delete-btn').forEach(btn => btn.addEventListener('click', async () => {
      const ok = await Swal.fire({ title:'Dismiss this case?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33' });
      if (!ok.isConfirmed) return;
      const res = await fetch(`?action=delete&id=${btn.dataset.id}`);
      const d   = await res.json();
      if (d.success) Swal.fire({ icon:'success', timer:1200 }).then(() => location.reload());
      else Swal.fire('Error', d.message, 'error');
    }));

    // Intervention button handler
    document.querySelectorAll('.intervention-btn').forEach(btn => btn.addEventListener('click', async () => {
      let opts = '';
      <?php foreach ($interventions as $int): ?>
        opts += `<option value="<?= $int['id'] ?>"><?= htmlspecialchars($int['name']) ?></option>`;
      <?php endforeach; ?>
      const { value } = await Swal.fire({
        title: 'Add Intervention',
        html: `
          <select id="iv" class="swal2-input"><option value="">Select type</option>${opts}</select>
          <input id="d" type="date" class="swal2-input" value="${new Date().toISOString().split('T')[0]}">
          <textarea id="r" class="swal2-textarea" placeholder="Remarks"></textarea>`,
        focusConfirm:false,
        showCancelButton:true,
        preConfirm: () => {
          const iv = document.getElementById('iv').value;
          const dt = document.getElementById('d').value;
          if (!iv||!dt) { Swal.showValidationMessage('Select type & date'); return false; }
          return { intervention_id: iv, date_intervened: dt, remarks: document.getElementById('r').value };
        }
      });
      if (!value) return;
      const res = await fetch(`?action=add_intervention&id=${btn.dataset.id}`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(value)
      });
      const d   = await res.json();
      if (d.success) Swal.fire({ icon:'success', timer:1200 }).then(() => location.reload());
      else Swal.fire('Error', d.message, 'error');
    }));

    // Schedule Hearing Handler
    document.querySelectorAll('.schedule-hearing-btn').forEach(btn => btn.addEventListener('click', async () => {
      const today = new Date();
      const minDate = today.toISOString().split('T')[0];
      const maxDateObj = new Date(today.getTime() + 5 * 24 * 60 * 60 * 1000);
      const maxDate = maxDateObj.toISOString().split('T')[0];

      const { value } = await Swal.fire({
        title: 'Schedule Hearing',
        html: `
          <input id="hearingDate" type="date" class="swal2-input" min="${minDate}" max="${maxDate}" required>
          <input id="hearingTime" type="time" class="swal2-input" required>
          <input id="presidingOfficer" class="swal2-input" value="Barangay Captain" readonly>
          <textarea id="hearingNotes" class="swal2-textarea" placeholder="Hearing notes (optional)"></textarea>`,
        focusConfirm: false,
        showCancelButton: true,
        preConfirm: () => {
          const date = document.getElementById('hearingDate').value;
          const time = document.getElementById('hearingTime').value;
          const officer = document.getElementById('presidingOfficer').value;
          if (!date || !time) {
            Swal.showValidationMessage('Date and time are required');
            return false;
          }
          return {
            hearing_date: date,
            hearing_time: time,
            presiding_officer: officer,
            officer_position: 'barangay_captain',
            notes: document.getElementById('hearingNotes').value
          };
        }
      });

      if (!value) return;

      const res = await fetch(`?action=schedule_hearing&id=${btn.dataset.id}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(value)
      });

      const data = await res.json();
      if (data.success) {
        Swal.fire('Success', 'Hearing scheduled successfully', 'success').then(() => location.reload());
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    }));

    // Record Hearing Handler (event delegation)
    document.addEventListener('click', async e => {
      if (e.target.classList.contains('record-hearing-btn')) {
        const hearingId = e.target.dataset.hearingId;
        const caseId = e.target.dataset.caseId;
        
        const { value } = await Swal.fire({
          title: 'Record Hearing Outcome',
          html: `
            <select id="outcome" class="swal2-input">
              <option value="conducted">Hearing Conducted</option>
              <option value="both_present">Both Parties Present</option>
              <option value="no_show_complainant">Complainant Did Not Appear</option>
              <option value="no_show_respondent">Respondent Did Not Appear</option>
              <option value="postponed">Postponed</option>
              <option value="mediation_successful">Mediation Successful (Case Resolved)</option>
              <option value="failed">Mediation Failed</option>
            </select>
            <textarea id="resolution" class="swal2-textarea" placeholder="Resolution details"></textarea>
            <textarea id="notes" class="swal2-textarea" placeholder="Additional remarks"></textarea>`,
          focusConfirm: false,
          showCancelButton: true,
          preConfirm: () => {
            const outcome = document.getElementById('outcome').value;
            if (!outcome) {
              Swal.showValidationMessage('Please select an outcome');
              return false;
            }
            return {
              hearing_id: hearingId,
              outcome: outcome,
              resolution: document.getElementById('resolution').value,
              notes: document.getElementById('notes').value
            };
          }
        });
        
        if (!value) return;
        
        const res = await fetch(`?action=record_hearing&id=${caseId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(value)
        });
        
        const data = await res.json();
        if (data.success) {
          Swal.fire('Success', 'Hearing outcome recorded successfully', 'success').then(() => location.reload());
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      }
    });

    // Issue CFA Handler
    document.querySelectorAll('.issue-cfa-btn').forEach(btn => btn.addEventListener('click', async () => {
      const caseId = btn.dataset.id;
      
      // Get case details
      const caseRes = await fetch(`?action=get_case_details&id=${caseId}`);
      const caseData = await caseRes.json();
      
      if (!caseData.success) {
        Swal.fire('Error', 'Could not fetch case details', 'error');
        return;
      }
      
      const complainants = caseData.participants.filter(p => p.role.toLowerCase() === 'complainant');
      
      if (complainants.length === 0) {
        Swal.fire('Error', 'No complainant found for this case', 'error');
        return;
      }
      
      let complainantOptions = '';
      complainants.forEach(c => {
        complainantOptions += `<option value="${c.participant_id}">${c.first_name} ${c.last_name}</option>`;
      });
      
      const { value } = await Swal.fire({
        title: 'Issue Certificate to File Action',
        html: `
          <p>This will issue a CFA certificate and mark the case as "Endorsed to Court".</p>
          <select id="complainantId" class="swal2-input">
            <option value="">Select Complainant</option>
            ${complainantOptions}
          </select>`,
        focusConfirm: false,
        showCancelButton: true,
        preConfirm: () => {
          const complainantId = document.getElementById('complainantId').value;
          if (!complainantId) {
            Swal.showValidationMessage('Please select a complainant');
            return false;
          }
          return { complainant_id: complainantId };
        }
      });
      
      if (!value) return;
      
      const res = await fetch(`?action=issue_cfa&id=${caseId}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(value)
      });
      
      const data = await res.json();
      if (data.success) {
        Swal.fire('Success', `CFA Certificate issued: ${data.certificate_number}`, 'success').then(() => location.reload());
      } else {
        Swal.fire('Error', data.message, 'error');
      }
    }));

    // Edit button handler
    document.addEventListener('click', async e => {
      if (!e.target.classList.contains('edit-btn')) return;
      const id = e.target.dataset.id;

      // Reset form
      editForm.reset();
      editPartCont.innerHTML = '';
      document.getElementById('editHearingsContainer').innerHTML = '';
      
      // Clear existing checks
      document.querySelectorAll('#editCategoryContainer input, #editInterventionContainer input')
        .forEach(cb => cb.checked = false);

      Swal.fire({ title: 'Loading…', didOpen: () => Swal.showLoading(), showConfirmButton: false });
      const response = await fetch(`?action=get_case_details&id=${id}`);
      const payload  = await response.json();
      Swal.close();

      if (!payload.success) {
        Swal.fire('Error', payload.message, 'error');
        return;
      }

      // Populate fields
      document.getElementById('editCaseId').value      = id;
      document.getElementById('editLocation').value    = payload.case.location;
      document.getElementById('editDescription').value = payload.case.description;
      document.getElementById('editStatus').value      = payload.case.status || '';

      // Only show intervention section if case is closed or solved
      const interventionSection = document.querySelector('.space-y-2:has(#editInterventionContainer)');
      if (interventionSection) {
        if (['closed', 'solved'].includes(payload.case.status)) {
          interventionSection.style.display = 'block';
        } else {
          interventionSection.style.display = 'none';
        }
      }

      // Populate participants
      payload.participants.forEach((p, idx) => {
        const tmpl = p.participant_type === 'registered' ? registeredTemplate : unregisteredTemplate;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = tmpl.replace(/INDEX/g, idx);
        const node = wrapper.firstElementChild;

        if (p.participant_type === 'registered') {
          node.querySelector('select[name$="[user_id]"]').value = p.person_id || p.user_id;
        } else {
          node.querySelector('input[name$="[first_name]"]').value     = p.first_name;
          node.querySelector('input[name$="[last_name]"]').value      = p.last_name;
          node.querySelector('input[name$="[contact_number]"]').value = p.contact_number || '';
          node.querySelector('input[name$="[address]"]').value        = p.address        || '';
          node.querySelector('input[name$="[age]"]').value            = p.age            || '';
          node.querySelector('select[name$="[gender]"]').value        = p.gender         || '';
        }
        node.querySelector('select[name$="[role]"]').value = p.role;
        node.querySelector('.remove-participant').addEventListener('click', () => node.remove());
        editPartCont.appendChild(node);
      });

      // Display hearings
      if (payload.hearings && payload.hearings.length > 0) {
        const hearingsHtml = `
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
            <tbody>
              ${payload.hearings.map(h => {
                const outcome = h.hearing_outcome ? h.hearing_outcome.replace('_', '-') : 'scheduled';
                const outcomeText = h.hearing_outcome ? h.hearing_outcome.replace('_', ' ') : 'Scheduled';
                return `
                <tr>
                  <td>Hearing ${h.hearing_number}</td>
                  <td>${new Date(h.hearing_date).toLocaleString()}</td>
                  <td>${h.presiding_officer_name} (${h.presiding_officer_position ? h.presiding_officer_position.replace('_', ' ') : ''})</td>
                  <td>
                    <span class="status-badge status-${outcome}">
                      ${outcomeText}
                    </span>
                    ${h.is_mediation_successful ? '<br><strong>✓ Mediation Successful</strong>' : ''}
                  </td>
                  <td>
                    ${h.hearing_outcome === 'scheduled' ? 
                      `<button class="btn-record record-hearing-btn" data-hearing-id="${h.id}" data-case-id="${id}">Record Outcome</button>` : 
                      'Completed'
                    }
                  </td>
                </tr>
              `}).join('')}
            </tbody>
          </table>
        `;
        document.getElementById('editHearingsContainer').innerHTML = hearingsHtml;
      } else {
        document.getElementById('editHearingsContainer').innerHTML = '<p class="text-gray-500">No hearings scheduled</p>';
      }

      // Show modal
      editModal.classList.remove('hidden');
    });

    // Edit form submit handler
    editForm.addEventListener('submit', async e => {
      e.preventDefault();
      const formData = { 
        case_id:       document.getElementById('editCaseId').value,
        location:      document.getElementById('editLocation').value.trim(),
        description:   document.getElementById('editDescription').value.trim(),
        status:        document.getElementById('editStatus').value,
        participants:  Array.from(editPartCont.children).map(node => {
          const isReg = !!node.querySelector('select[name$="[user_id]"]');
          if (isReg) {
            return {
              user_id: node.querySelector('select[name$="[user_id]"]').value,
              role:    node.querySelector('select[name$="[role]"]').value
            };
          } else {
            return {
              first_name: node.querySelector('input[name$="[first_name]"]').value.trim(),
              last_name: node.querySelector('input[name$="[last_name]"]').value.trim(),
              contact_number: node.querySelector('input[name$="[contact_number]"]').value.trim(),
              address: node.querySelector('input[name$="[address]"]').value.trim(),
              age: node.querySelector('input[name$="[age]"]').value,
              gender: node.querySelector('select[name$="[gender]"]').value,
              role: node.querySelector('select[name$="[role]"]').value
            };
          }
        })
      };
      
      // Only include interventions if case is closed or solved
      const interventionContainer = document.getElementById('editInterventionContainer');
      if (interventionContainer && ['closed', 'solved'].includes(formData.status)) {
        formData.interventions = Array.from(interventionContainer.querySelectorAll('input[type="checkbox"]:checked'))
          .map(cb => parseInt(cb.value));
      } else {
        formData.interventions = [];
      }
      
      // Validation
      if (!formData.location || !formData.description || !formData.participants.length) {
        return Swal.fire('Error', 'Location, description, and at least one participant are required', 'error');
      }
      
      Swal.fire({ title:'Saving...', didOpen:()=>Swal.showLoading() });
      const res = await fetch('?action=update_case', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(formData)
      });
      const d = await res.json();
      if (d.success) {
        Swal.fire('Saved!','Case updated successfully','success').then(() => {
          editModal.classList.add('hidden');
          location.reload();
        });
      } else {
        Swal.fire('Error', d.message || 'Failed', 'error');
      }
    });

    // Audio/Video Transcription
    document.getElementById('transcribe_btn').addEventListener('click', function() {
      const fileInput = document.getElementById('transcript_file');
      if (!fileInput.files || fileInput.files.length === 0) {
        Swal.fire('Error', 'Please select an audio or video file to transcribe.', 'error');
        return;
      }
      
      const file = fileInput.files[0];
      const allowedTypes = ['audio/mpeg', 'audio/mp4', 'audio/mp3', 'audio/wav', 'audio/x-wav', 
                         'audio/webm', 'audio/ogg', 'video/mp4', 'video/webm', 'video/ogg'];
      
      if (!allowedTypes.includes(file.type)) {
        Swal.fire('Error', 'Please upload a supported audio or video file format.', 'error');
        return;
      }
      
      if (file.size > 25 * 1024 * 1024) { // 25MB limit
        Swal.fire('Error', 'File too large. Maximum size is 25MB.', 'error');
        return;
      }
      
      // Show loader
      document.getElementById('transcript_loader').style.display = 'block';
      document.getElementById('transcript_result').style.display = 'none';
      
      const formData = new FormData();
      formData.append('transcript_file', file);
      formData.append('action', 'transcribe_only');
      
      fetch('blotter.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          document.getElementById('transcript_result').style.display = 'block';
          document.getElementById('transcript_text').textContent = data.text;
          const complaintField = document.querySelector("textarea[name='complaint']");
          complaintField.value = data.text;
          complaintField.classList.add('bg-green-50');
          setTimeout(() => {
            complaintField.classList.remove('bg-green-50');
          }, 2000);
        } else {
          Swal.fire('Error', data.message || 'Transcription failed.', 'error');
        }
      })
      .catch(error => {
        Swal.fire('Error', 'Failed to connect to the server.', 'error');
      })
      .finally(() => {
        document.getElementById('transcript_loader').style.display = 'none';
      });
    });
});
  </script>
</section>
</body>
</html>