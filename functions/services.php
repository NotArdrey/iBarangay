<?php
// functions/services.php  – full rewrite
session_start();
require __DIR__ . '/../config/dbconn.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ─────── guard ─────── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Use the form to submit a request.';
    header('Location: ../pages/services.php');
    exit;
}
if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in first.';
    header('Location: ../pages/index.php');
    exit;
}

$userId    = (int) $_SESSION['user_id'];
$userEmail = $_SESSION['user_email'] ?? '';
$userName  = $_SESSION['user_name']  ?? 'User';

try {
    $pdo->beginTransaction();

    /* ──────── ID upload ──────── */
    if (!isset($_FILES['uploadId']) || $_FILES['uploadId']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Valid ID is required.');
    }
    $allowedExt = ['jpg','jpeg','png','pdf'];
    $ext = strtolower(pathinfo($_FILES['uploadId']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        throw new Exception('Invalid ID format; only JPG, PNG, PDF allowed.');
    }
    if ($_FILES['uploadId']['size'] > 2 * 1024 * 1024) {
        throw new Exception('ID file too large; max 2 MB.');
    }
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $idFilename = sprintf('id_user_%d_%d.%s', $userId, time(), $ext);
    $idTarget   = $uploadDir . $idFilename;
    if (!move_uploaded_file($_FILES['uploadId']['tmp_name'], $idTarget)) {
        throw new Exception('Failed to save uploaded ID.');
    }
    $idPath = 'uploads/' . $idFilename;
    $pdo->prepare("UPDATE Users SET id_image_path = ? WHERE user_id = ?")
        ->execute([$idPath, $userId]);

    /* ─────── payment / proof ─────── */
    $delivery      = $_POST['deliveryMethod'] ?? 'Hardcopy';
    $paymentAmount = (float) ($_POST['paymentAmount'] ?? 0);
    $proofPath     = null;

    if ($delivery === 'Softcopy' && $paymentAmount > 0) {
        if (!isset($_FILES['uploadProof']) || $_FILES['uploadProof']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Payment receipt is required for softcopy with fee.');
        }
        $ext2 = strtolower(pathinfo($_FILES['uploadProof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext2, $allowedExt)) {
            throw new Exception('Invalid receipt format; only JPG, PNG, PDF allowed.');
        }
        if ($_FILES['uploadProof']['size'] > 2 * 1024 * 1024) {
            throw new Exception('Receipt file too large; max 2 MB.');
        }
        $proofFilename = sprintf('proof_user_%d_%d.%s', $userId, time(), $ext2);
        $proofTarget   = $uploadDir . $proofFilename;
        if (!move_uploaded_file($_FILES['uploadProof']['tmp_name'], $proofTarget)) {
            throw new Exception('Failed to save payment receipt.');
        }
        $proofPath = 'uploads/' . $proofFilename;
    }

    /* ─────── validate doc & barangay ─────── */
    $docTypeId  = filter_input(INPUT_POST, 'document_type_id', FILTER_VALIDATE_INT);
    $barangayId = filter_input(INPUT_POST, 'barangay_id',     FILTER_VALIDATE_INT);
    if (!$docTypeId || !$barangayId) {
        throw new Exception('Please select document type and barangay.');
    }

    $chk = $pdo->prepare("SELECT COUNT(*) FROM DocumentType WHERE document_type_id = ?");
    $chk->execute([$docTypeId]);
    if (!$chk->fetchColumn()) {
        throw new Exception('Document type not found.');
    }

    $chk = $pdo->prepare("SELECT COUNT(*) FROM Barangay WHERE barangay_id = ?");
    $chk->execute([$barangayId]);
    if (!$chk->fetchColumn()) {
        throw new Exception('Barangay not found.');
    }

    /* ─────── insert request ─────── */
    $pdo->prepare("
        INSERT INTO DocumentRequest
            (user_id, document_type_id, barangay_id, delivery_method, proof_image_path)
        VALUES (?,?,?,?,?)
    ")->execute([$userId, $docTypeId, $barangayId, $delivery, $proofPath]);
    $requestId = $pdo->lastInsertId();

    /* ─────── extra attributes ─────── */
    $attrs = [
        'clearance_purpose'  => $_POST['purposeClearance']  ?? null,
        'residency_duration' => $_POST['residencyDuration'] ?? null,
        'residency_purpose'  => $_POST['residencyPurpose']  ?? null,
        'gmc_purpose'        => $_POST['gmcPurpose']        ?? null,
        'nic_reason'         => $_POST['nicReason']         ?? null,
        'indigency_income'   => $_POST['indigencyIncome']   ?? null,
        'indigency_reason'   => $_POST['indigencyReason']   ?? null,
    ];
    $ins = $pdo->prepare("
        INSERT INTO DocumentRequestAttribute (request_id, attr_key, attr_value)
        VALUES (?,?,?)
    ");
    foreach ($attrs as $k => $v) {
        if ($v !== null && trim($v) !== '') {
            $ins->execute([$requestId, $k, trim($v)]);
        }
    }

    /* ─────── audit trail ─────── */
    $pdo->prepare("
        INSERT INTO AuditTrail
            (admin_user_id, action, table_name, record_id, description)
        VALUES (?,?,?,?,?)
    ")->execute([
        $userId,
        'INSERT',
        'DocumentRequest',
        $requestId,
        'Submitted document request'
    ]);

    $pdo->commit();

    $_SESSION['success'] = [
        'title'      => 'Success!',
        'message'    => 'Your document request was submitted.',
        'processing' => 'We will process it shortly and email you updates on your request.'
    ];
    header('Location: ../pages/services.php');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
    header('Location: ../pages/services.php');
    exit;
}
