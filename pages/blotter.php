<?php
// blotter.php – Blotter Case Management (full rewrite)
session_start();
require "../config/dbconn.php";
require "../vendor/autoload.php";
use Dompdf\Dompdf;  
// Authentication & role check
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2) {
    header("Location: ../pages/login.php");
    exit;
}
function transcribeFile(string $filePath): string
{
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if (!$apiKey) {
        throw new Exception("Missing OPENAI_API_KEY");
    }

    // Check file size
    $fileSize = filesize($filePath);
    if ($fileSize > 25 * 1024 * 1024) { // 25MB limit
        throw new Exception("File too large. Maximum size is 25MB.");
    }

    // Check file mime type
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
        'response_format' => 'json', // Ensure JSON response
        'temperature' => 0.2, // Lower temperature for more accurate transcriptions
    ];

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$apiKey}",
            "Content-Type: multipart/form-data"
        ],
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 300, // 5-minute timeout for large files
        CURLOPT_CONNECTTIMEOUT => 30, // 30-second connection timeout
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception('Connection error: ' . curl_error($ch));
    }
    
    curl_close($ch);

    if ($httpCode !== 200) {
        $error = json_decode($resp, true);
        $message = isset($error['error']['message']) ? $error['error']['message'] : "API returned HTTP $httpCode";
        throw new Exception("Transcription failed: $message");
    }

    $data = json_decode($resp, true);
    if (empty($data['text'])) {
        throw new Exception('No transcription text returned from API.');
    }

    return trim($data['text']);
}

$current_admin_id = $_SESSION['user_id'];
$bid              = $_SESSION['barangay_id'];
$role             = $_SESSION['role_id'];
$allowedStatuses  = ['Pending','Open','Closed','Completed'];

// Helpers
function logAuditTrail($pdo, $adminId, $action, $table, $recordId, $desc = '') {
    $pdo->prepare("INSERT INTO audit_trail
        (admin_user_id, action, table_name, record_id, description)
        VALUES (?, ?, ?, ?, ?)")
        ->execute([$adminId, $action, $table, $recordId, $desc]);
}

function getResidents($pdo, $bid) {
    $stmt = $pdo->prepare("
        SELECT id AS user_id, CONCAT(first_name,' ',last_name) AS name
        FROM users WHERE barangay_id = ?
    ");
    $stmt->execute([$bid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function validateBlotterData(array $data, &$errors) {
  // location & description
  if (empty(trim($data['location'] ?? ''))) {
      $errors[] = 'Location is required.';
  }
  if (empty(trim($data['description'] ?? ''))) {
      $errors[] = 'Description is required.';
  }
  // categories
  if (empty($data['categories']) || !is_array($data['categories'])) {
      $errors[] = 'At least one category must be selected.';
  }
  // participants
  if (empty($data['participants']) || !is_array($data['participants'])) {
      $errors[] = 'At least one participant is required.';
  } else {
      foreach ($data['participants'] as $idx => $p) {
          if (!empty($p['user_id'])) {
              if (!ctype_digit(strval($p['user_id']))) {
                  $errors[] = "Participant #".($idx+1)." has invalid user ID.";
              }
          } else {
              if (empty(trim($p['first_name'] ?? ''))) {
                  $errors[] = "Participant #".($idx+1)." first name is required.";
              }
              if (empty(trim($p['last_name'] ?? ''))) {
                  $errors[] = "Participant #".($idx+1)." last name is required.";
              }
              if (!empty($p['age']) && !ctype_digit(strval($p['age']))) {
                  $errors[] = "Participant #".($idx+1)." age must be a number.";
              }
              if (!empty($p['gender']) && !in_array($p['gender'], ['Male','Female','Other'], true)) {
                  $errors[] = "Participant #".($idx+1)." has invalid gender.";
              }
          }
          if (empty(trim($p['role'] ?? ''))) {
              $errors[] = "Participant #".($idx+1)." role is required.";
          }
      }
  }
  return empty($errors);
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
  
  // If the complaint field is already filled (via AJAX transcription), use that
  $description = trim($_POST['complaint'] ?? '');
  
  // Only attempt transcription if no description and a file was uploaded
  if (empty($description) && !empty($_FILES['transcript_file']['tmp_name'])) {
      try {
          // Show loading message
          $_SESSION['info_message'] = 'Processing audio transcription...';
          session_write_close(); // Allow the message to be displayed
          
          // move/immediately feed the temp file into Whisper
          $tmpPath = $_FILES['transcript_file']['tmp_name'];
          $description = transcribeFile($tmpPath);
          
          // Clear the info message
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

  try {
      $pdo->beginTransaction();

      // Insert case
      $pdo->prepare("
          INSERT INTO blotter_cases
          (date_reported, location, description, status, barangay_id)
          VALUES (NOW(), ?, ?, 'Pending', ?)
      ")->execute([$location, $description, $bid]);
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
      if (!empty($_POST['interventions']) && is_array($_POST['interventions'])) {
        $intStmt = $pdo->prepare("
            INSERT INTO blotter_case_interventions
              (blotter_case_id, intervention_id, date_intervened)
            VALUES (?, ?, NOW())
        ");
        foreach ($_POST['interventions'] as $intId) {
            $intStmt->execute([$caseId, (int)$intId]);
        }
      }
      // Participants
      $regStmt = $pdo->prepare("
      INSERT INTO blotter_participants
      (blotter_case_id, user_id, role, is_registered)
      VALUES (?, ?, ?, 'Yes')
  ");
  $unregStmt = $pdo->prepare("
      INSERT INTO blotter_participants
      (blotter_case_id, first_name, last_name, contact_number, address, age, gender, role, is_registered)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'No')
  ");
  
  foreach ($participants as $p) {
      if (!empty($p['user_id'])) {
          $regStmt->execute([$caseId, (int)$p['user_id'], $p['role']]);
      } else {
          $unregStmt->execute([
              $caseId,
              $p['first_name'],
              $p['last_name'],
              $p['contact_number'] ?? null,
              $p['address'] ?? null,  // New field
              $p['age'] ?? null,      // New field
              $p['gender'] ?? null,   // New field
              $p['role']
          ]);
      }
  }

      $pdo->commit();
      logAuditTrail($pdo, $current_admin_id, 'INSERT', 'blotter_cases', $caseId, "New case filed ($location)");
      $_SESSION['success_message'] = 'New blotter case recorded.';
  } catch (Exception $e) {
      $pdo->rollBack();
      $_SESSION['error_message'] = 'Error adding case: ' . $e->getMessage();
  }

  header('Location: blotter.php');
  exit;
}

// Handle AJAX transcription requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'transcribe_only') {
  // Set content type to JSON
  header('Content-Type: application/json');
  
  if (empty($_FILES['transcript_file']['tmp_name'])) {
      echo json_encode(['success' => false, 'message' => 'No file uploaded']);
      exit;
  }
  
  try {
      // Get the file and transcribe it
      $tmpPath = $_FILES['transcript_file']['tmp_name'];
      $transcriptionText = transcribeFile($tmpPath);
      
      // Return success with transcription text
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

    if (in_array($action, ['delete','complete','set_status','add_intervention','update_case'], true)
        && !in_array($role, [3, 4, 5], true)) {
        echo json_encode(['success'=>false,'message'=>'Permission denied']);
        exit;
    }

    try {
        switch ($action) {

            case 'generate_report':
              // 1) pick year/month (default to current)
              $year  = intval($_GET['year']  ?? date('Y'));
              $month = intval($_GET['month'] ?? date('n'));

              // 2) call your stored proc to populate MonthlyReport & Detail
              $proc = $pdo->prepare("CALL GenerateMonthlyReport(:y, :m, :admin)");
              $proc->execute([
                  'y'     => $year,
                  'm'     => $month,
                  'admin' => $current_admin_id
              ]);

              // 3) fetch the *newest* report row
              $stmt = $pdo->prepare("
              SELECT
                m.*,
                CONCAT(u.first_name, ' ', u.last_name) AS prepared_by_name
              FROM monthly_reports m
              JOIN users         u ON m.prepared_by = u.user_id
              WHERE m.report_year  = :y
                AND m.report_month = :m
              ORDER BY m.monthly_report_id DESC
              LIMIT 1
            ");
            $stmt->execute(['y'=>$year,'m'=>$month]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);

              // 4) fetch its details
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
                      AND YEAR(bc.date_reported) = :y
                      AND MONTH(bc.date_reported)= :m
                  LEFT JOIN blotter_case_interventions bci
                    ON bci.blotter_case_id = bc.id
                  GROUP BY c.id
                  ORDER BY c.name
              ");
              $dStmt->execute([
                  'y' => $year,
                  'm' => $month
              ]);
              $details = $dStmt->fetchAll(PDO::FETCH_ASSOC);

              // 5) build an HTML payload
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
                  Prepared by <?= htmlspecialchars($report['prepared_by_name']) ?>
                  on <?= date('M j, Y g:i A', strtotime($report['date_submitted'])) ?>
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

              // 6) render PDF
              $pdf = new Dompdf();
              $pdf->loadHtml($html, 'UTF-8');
              $pdf->setPaper('A4','landscape');
              $pdf->render();

              // 7) stream it back inline
              header('Content-Type: application/pdf');
              header('Content-Disposition: inline; filename="Report-'.$year.'-'.$month.'.pdf"');
              echo $pdf->output();
              exit;


            case 'delete':
                $pdo->prepare("UPDATE blotter_cases SET status='Deleted' WHERE id=?")
                    ->execute([$id]);
                logAuditTrail($pdo, $current_admin_id, 'UPDATE', 'blotter_cases', $id, 'Status → Deleted');
                echo json_encode(['success'=>true]);
                break;

            case 'complete':
                $pdo->prepare("UPDATE blotter_cases SET status='Closed' WHERE id=?")
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
                    SELECT bc.*, GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories
                    FROM blotter_cases bc
                    LEFT JOIN blotter_case_categories bcc ON bc.id=bcc.blotter_case_id
                    LEFT JOIN case_categories cc ON bcc.category_id=cc.id
                    WHERE bc.id=?
                    GROUP BY bc.id
                ");
                $caseStmt->execute([$id]);
                $caseData = $caseStmt->fetch(PDO::FETCH_ASSOC) ?: null;

                $pStmt = $pdo->prepare("
    SELECT 
        bp.id AS participant_id,
        bp.user_id,
        COALESCE(u.first_name, bp.first_name) AS first_name,
        COALESCE(u.last_name, bp.last_name) AS last_name,
        bp.contact_number,
        bp.address,       
        bp.age,
        bp.gender,
        bp.role,
        CASE WHEN u.id IS NULL THEN 'No' ELSE 'Yes' END AS is_registered
    FROM blotter_participants bp
    LEFT JOIN users u ON bp.user_id = u.id
    WHERE bp.blotter_case_id = ?
            ");
                $pStmt->execute([$id]);

                $iStmt = $pdo->prepare("
                    SELECT ci.name AS intervention_name, bci.intervened_at, bci.remarks
                    FROM blotter_case_interventions bci
                    JOIN case_interventions ci ON bci.intervention_id=ci.id
                    WHERE bci.blotter_case_id=?
                ");
                $iStmt->execute([$id]);

                echo json_encode([
                    'success'       => true,
                    'case'          => $caseData,
                    'participants'  => $pStmt->fetchAll(PDO::FETCH_ASSOC),
                    'interventions' => $iStmt->fetchAll(PDO::FETCH_ASSOC)
                ]);
                break;

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
                          (blotter_case_id, intervention_id, date_intervened)
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
                        (blotter_case_id, user_id, role, is_registered)
                        VALUES (?, ?, ?, 'Yes')
                    ");
                    $unregStmt = $pdo->prepare("
                    INSERT INTO blotter_participants
                    (blotter_case_id, first_name, last_name, contact_number, address, age, gender, role, is_registered)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'No')
                ");
                
                foreach ($input['participants'] as $p) {
                  if (!empty($p['user_id'])) {
                      $regStmt->execute([$cid, (int)$p['user_id'], $p['role']]);
                  } else {
                      $unregStmt->execute([
                          $cid,
                          $p['first_name'],
                          $p['last_name'],
                          $p['contact_number'] ?? null,
                          $p['address'] ?? null,  // New field
                          $p['age'] ?? null,      // New field
                          $p['gender'] ?? null,   // New field
                          $p['role']
                      ]);
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

            default:
                echo json_encode(['success'=>false,'message'=>'Unknown action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

require_once "../pages/header.php";

// Fetch for UI
$stmt = $pdo->prepare("
    SELECT bc.*, GROUP_CONCAT(cc.name SEPARATOR ', ') AS categories
    FROM blotter_cases bc
    LEFT JOIN blotter_case_categories bcc ON bc.id=bcc.blotter_case_id
    LEFT JOIN case_categories cc ON bcc.category_id=cc.id
    WHERE bc.barangay_id=? AND bc.status!='Deleted'
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
  <title>Blotter Case Management</title>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
  </style>
</head>
<body>
<section id="blotter" class="p-6">
<!-- Edit Modal -->
<div id="editBlotterModal" tabindex="-1"
     class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0 h-[calc(100%-1rem)] max-h-full">
  <div class="relative w-full max-w-2xl max-h-full mx-auto">
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
            <label class="block text-sm font-medium text-gray-700">Categories</label>
            <div id="editCategoryContainer" class="grid grid-cols-2 gap-2">
              <?php foreach ($categories as $cat): ?>
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
          <!-- Status -->
          <div>
            <label class="block text-sm font-medium text-gray-700">Status</label>
            <select id="editStatus" name="status" required
                    class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
              <?php foreach ($allowedStatuses as $s): ?>
                <option value="<?= $s ?>"><?= $s ?></option>
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
      Blotter Cases
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
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Reported</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categories</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
          <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if ($cases): foreach ($cases as $case): ?>
        <tr class="hover:bg-gray-50 transition-colors">
          <td class="px-4 py-3 text-sm text-gray-900">
            <?php
              // Show created_at as "Date Reported" if date_reported is not set
              $date = !empty($case['date_reported']) ? $case['date_reported'] : ($case['created_at'] ?? null);
              echo $date ? date('M d, Y h:i A', strtotime($date)) : '—';
            ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($case['location']) ?></td>
          <td class="px-4 py-3 text-sm text-gray-600"><?= $case['categories'] ?: 'None' ?></td>
          <td class="px-4 py-3">
            <?php if ($role === 1): ?>
              <select class="status-select p-1 border rounded text-sm" data-id="<?= $case['id'] ?>">
                <?php foreach ($allowedStatuses as $s): ?>
                  <?php $disabled = ($s==='Completed' && $case['status']!=='Closed') ? 'disabled':''; ?>
                  <option value="<?= $s ?>" <?= $case['status']===$s?'selected':'' ?> <?= $disabled ?>>
                    <?= $s ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <span class="px-2 py-1 text-sm font-medium rounded
                <?= $case['status']==='Pending'?'bg-yellow-100 text-yellow-800'
                 :($case['status']==='Open'?'bg-green-100 text-green-800':'bg-gray-200 text-gray-800') ?>">
                <?= $case['status'] ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-sm text-gray-600">
            <div class="flex items-center space-x-3">
              <button class="view-btn text-blue-600 hover:text-blue-900" data-id="<?= $case['id'] ?>">View</button>
              <button class="edit-btn text-indigo-600 hover:text-indigo-900" data-id="<?= $case['id'] ?>">Edit</button>
              <?php if ($role === 1): ?>
                <?php if ($case['status'] !== 'Closed'): ?>
                  <button class="complete-btn text-green-600 hover:text-green-900" data-id="<?= $case['id'] ?>">Close</button>
                <?php endif; ?>
                <button class="delete-btn text-red-600 hover:text-red-900" data-id="<?= $case['id'] ?>">Delete</button>
                <button class="intervention-btn text-purple-600 hover:text-purple-900" data-id="<?= $case['id'] ?>">Intervene</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; else: ?>
          <tr>
            <td colspan="5" class="px-4 py-4 text-center text-gray-500">No cases found</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div id="viewBlotterModal" tabindex="-1"
     class="hidden fixed top-0 left-0 right-0 z-50 w-full p-4 overflow-x-hidden overflow-y-auto md:inset-0
            h-[calc(100%-1rem)] max-h-full">
  <div class="relative w-full max-w-2xl max-h-full mx-auto">
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
        <p><strong>Date:</strong> <span id="viewDate">—</span></p>
        <p><strong>Location:</strong> <span id="viewLocation">—</span></p>
        <p><strong>Description:</strong> <span id="viewDescription">—</span></p>
        <p><strong>Categories:</strong> <span id="viewCategories">—</span></p>
        <p><strong>Status:</strong> <span id="viewStatus">—</span></p>
        <h4 class="mt-4 text-lg font-medium">Participants</h4>
        <ul id="viewParticipants" class="list-disc pl-5 space-y-1"><li>—</li></ul>
        <h4 class="mt-4 text-lg font-medium">Interventions</h4>
        <ul id="viewInterventions" class="list-disc pl-5 space-y-1"><li>—</li></ul>
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
          <option value="Complainant">Complainant</option>
          <option value="Respondent">Respondent</option>
          <option value="Witness">Witness</option>
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
        <!-- Add these fields -->
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
        <option value="Complainant">Complainant</option>
        <option value="Respondent">Respondent</option>
        <option value="Witness">Witness</option>
    </select>
    <button type="button" class="remove-participant px-2 bg-red-500 text-white rounded">×</button>
</div>`;


    function addParticipant(template, container) {
      const idx = container.children.length;
      const wrapper = document.createElement('div');
      wrapper.innerHTML = template.replace(/INDEX/g, idx);
      const node = wrapper.firstElementChild;
      node.querySelector('.remove-participant').addEventListener('click', () => node.remove());
      container.appendChild(node);
      node.scrollIntoView({ behavior: 'smooth' });
    }
    window.toggleViewBlotterModal = () => {
    document.getElementById('viewBlotterModal').classList.toggle('hidden');
  };
    // Add New

    window.toggleAddBlotterModal = () => {
      document.getElementById('addBlotterModal').classList.toggle('hidden');
    };


    const addModal = document.getElementById('addBlotterModal');
    const openBtn  = document.getElementById('openModalBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const participantContainer = document.getElementById('participantContainer');

    openBtn.addEventListener('click', () => {
      participantContainer.innerHTML = '';
      addModal.classList.remove('hidden');
    });
    cancelBtn.addEventListener('click', () => addModal.classList.add('hidden'));
    document.getElementById('addRegisteredBtn')
      .addEventListener('click', () => addParticipant(registeredTemplate, participantContainer));
    document.getElementById('addUnregisteredBtn')
      .addEventListener('click', () => addParticipant(unregisteredTemplate, participantContainer));

    // Edit
    const editModal  = document.getElementById('editBlotterModal');
    const editForm   = document.getElementById('editBlotterForm');
    const editCancel = document.getElementById('editCancelBtn');
    const editPartCont = document.getElementById('editParticipantContainer');

    document.getElementById('editAddRegisteredBtn')
      .addEventListener('click', () => addParticipant(registeredTemplate, editPartCont));
    document.getElementById('editAddUnregisteredBtn')
      .addEventListener('click', () => addParticipant(unregisteredTemplate, editPartCont));
    editCancel.addEventListener('click', () => editModal.classList.add('hidden'));

    // Status change, view, complete, delete, intervention, edit-button handlers…
    document.querySelectorAll('.status-select').forEach(el => {
      el.addEventListener('change', async () => {
        const res = await fetch(`?action=set_status&id=${el.dataset.id}&new_status=${encodeURIComponent(el.value)}`);
        const data = await res.json();
        if (!data.success) alert(data.message || 'Failed');
        else location.reload();
      });
    });

   // Toggle visibility of the view modal
   function toggleViewBlotterModal() {
    document.getElementById('viewBlotterModal').classList.toggle('hidden');
  }

  // Fetch details and populate the view modal
  async function openViewModal(caseId) {
    try {
      const res = await fetch(`?action=get_case_details&id=${caseId}`);
      const d   = await res.json();
      if (!d.success) {
        return Swal.fire('Error', d.message, 'error');
      }

      // Populate fields
      document.getElementById('viewDate').textContent          = d.case.date_reported;
      document.getElementById('viewLocation').textContent      = d.case.location;
      document.getElementById('viewDescription').textContent   = d.case.description;
      document.getElementById('viewCategories').textContent    = d.case.categories || 'None';
      document.getElementById('viewStatus').textContent        = d.case.status;

      // Build lists
      const pList = d.participants.map(p => {
    let details = [];
    if (p.is_registered === 'No') {
        if (p.contact_number) details.push(`Contact: ${p.contact_number}`);
        if (p.address) details.push(`Address: ${p.address}`);
        if (p.age) details.push(`Age: ${p.age}`);
        if (p.gender) details.push(`Gender: ${p.gender}`);
    }
    return `<li>${p.first_name} ${p.last_name} (${p.role}) 
            ${details.length > 0 ? '<br>Details: ' + details.join(', ') : ''}</li>`;
}).join('');
      document.getElementById('viewParticipants').innerHTML = pList;

      const iList = d.interventions.length
        ? d.interventions.map(i => `<li><strong>${i.intervention_name}</strong> (${i.date_intervened}): ${i.remarks || 'No remarks'}</li>`).join('')
        : '<li>None</li>';
      document.getElementById('viewInterventions').innerHTML = iList;

      // Show the modal
      toggleViewBlotterModal();
    } catch (err) {
      console.error(err);
      Swal.fire('Error', 'Failed to load case details.', 'error');
    }
  }

  // Attach handler to all view buttons
  document.querySelectorAll('.view-btn').forEach(btn =>
    btn.addEventListener('click', () => openViewModal(btn.dataset.id))
  );

    document.querySelectorAll('.complete-btn').forEach(btn => btn.addEventListener('click', async () => {
      const ok = await Swal.fire({ title:'Close this case?', icon:'question', showCancelButton:true });
      if (!ok.isConfirmed) return;
      const res = await fetch(`?action=complete&id=${btn.dataset.id}`);
      const d   = await res.json();
      if (d.success) Swal.fire({ icon:'success', timer:1200 }).then(() => location.reload());
      else Swal.fire('Error', d.message, 'error');
    }));

    document.querySelectorAll('.delete-btn').forEach(btn => btn.addEventListener('click', async () => {
      const ok = await Swal.fire({ title:'Delete permanently?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d33' });
      if (!ok.isConfirmed) return;
      const res = await fetch(`?action=delete&id=${btn.dataset.id}`);
      const d   = await res.json();
      if (d.success) Swal.fire({ icon:'success', timer:1200 }).then(() => location.reload());
      else Swal.fire('Error', d.message, 'error');
    }));

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

    // Edit button handler
    document.body.addEventListener('click', async e => {
      if (!e.target.classList.contains('edit-btn')) return;
  const id = e.target.dataset.id;

  // reset form & clear checks
  editForm.reset();
  editPartCont.innerHTML = '';
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

  // populate fields
  document.getElementById('editCaseId').value      = id;
  document.getElementById('editLocation').value    = payload.case.location;
  document.getElementById('editDescription').value = payload.case.description;
  document.getElementById('editStatus').value      = payload.case.status;

  // autofill categories by name
  const cats = payload.case.categories
    ? payload.case.categories.split(',').map(s => s.trim())
    : [];
  document.querySelectorAll('#editCategoryContainer label').forEach(label => {
    const box = label.querySelector('input[type="checkbox"]');
    if (cats.includes(label.textContent.trim())) box.checked = true;
  });

  // autofill interventions by name
  const ints = payload.interventions.map(i => i.intervention_name);
  document.querySelectorAll('#editInterventionContainer label').forEach(label => {
    const box = label.querySelector('input[type="checkbox"]');
    if (ints.includes(label.textContent.trim())) box.checked = true;
  });

  // build participants
  payload.participants.forEach((p, idx) => {
    const tmpl = p.is_registered === 'Yes' ? registeredTemplate : unregisteredTemplate;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = tmpl.replace(/INDEX/g, idx);
    const node = wrapper.firstElementChild;

    if (p.is_registered === 'Yes') {
      node.querySelector('select[name$="[user_id]"]').value = p.user_id;
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

  // finally, show the modal
  editModal.classList.remove('hidden');
});

  // 2) Single, consolidated submit handler
  editForm.addEventListener('submit', async e => {
    e.preventDefault();
    const formData = {
      case_id:       document.getElementById('editCaseId').value,
      location:      document.getElementById('editLocation').value.trim(),
      description:   document.getElementById('editDescription').value.trim(),
      status:        document.getElementById('editStatus').value,
      categories:    Array.from(
                       document.querySelectorAll('#editCategoryContainer input:checked')
                     ).map(cb => cb.value),
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

    Swal.fire({ title:'Saving…', didOpen:()=>Swal.showLoading() });
    const res = await fetch('?action=update_case', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(formData)
    });
    const d = await res.json();
    if (d.success) {
      Swal.fire('Saved!','Case updated successfully','success')
        .then(() => { editModal.classList.add('hidden'); location.reload(); });
    } else {
      Swal.fire('Error', d.message || 'Failed', 'error');
    }
  });

    // Edit form submit
    editForm.addEventListener('submit', async e => {
      e.preventDefault();
      const categoryNames = d.case.categories
  ? d.case.categories.split(', ').map(s => s.trim())
  : [];

      const formData = {
        case_id: document.getElementById('editCaseId').value,
        location: document.getElementById('editLocation').value.trim(),
        description: document.getElementById('editDescription').value.trim(),
        status: document.getElementById('editStatus').value,
        
        categories: Array.from(
          document.querySelectorAll('#editCategoryContainer input[type="checkbox"]:checked')
        ).map(cb => cb.value),
        participants: Array.from(editPartCont.children).map(node => {
          const reg = !!node.querySelector('select[name$="[user_id]"]');
          if (reg) {
            return {
              user_id: node.querySelector('select[name$="[user_id]"]').value,
              role: node.querySelector('select[name$="[role]"]').value
            };
          } else {
            return {
              first_name: node.querySelector('input[name$="[first_name]"]').value.trim(),
              last_name: node.querySelector('input[name$="[last_name]"]').value.trim(),
              contact_number: node.querySelector('input[name$="[contact_number]"]').value.trim(),
              role: node.querySelector('select[name$="[role]"]').value
            };
          }
        })
      };
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
    
    // Audio/Video Transcription via AJAX
    $("#transcribe_btn").click(function() {
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
      
      // Show loading animation
      $("#transcript_loader").show();
      $("#transcript_result").hide();
      
      const formData = new FormData();
      formData.append('transcript_file', file);
      formData.append('action', 'transcribe_only');
      
      $.ajax({
        url: 'blotter.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
          try {
            const data = JSON.parse(response);
            if (data.success) {
              // Show result and populate textarea
              $("#transcript_result").show();
              $("#transcript_text").text(data.text);
              $("textarea[name='complaint']").val(data.text);
              
              // Highlight the populated textarea
              $("textarea[name='complaint']").addClass('bg-green-50').animate({
                backgroundColor: '#ffffff'
              }, 2000);
              
              Swal.fire({
                icon: 'success',
                title: 'Transcription Complete',
                text: 'The audio has been transcribed and added to the description field.'
              });
            } else {
              Swal.fire('Error', data.message || 'Transcription failed.', 'error');
            }
          } catch (e) {
            Swal.fire('Error', 'Invalid response from server.', 'error');
          }
        },
        error: function() {
          Swal.fire('Error', 'Failed to connect to the server.', 'error');
        },
        complete: function() {
          // Hide loading animation
          $("#transcript_loader").hide();
        }
      });
    });

  });


  if (d.case.categories) {
  const categoryNames = d.case.categories.split(', ');
  document.querySelectorAll('#editCategoryContainer input[type="checkbox"]').forEach(cb => {
    const categoryLabel = cb.parentElement.textContent.trim();
    if (categoryNames.includes(categoryLabel)) {
      cb.checked = true;
    }
    
  });
}
const selInts = d.interventions.map(i => String(i.intervention_id));
document.querySelectorAll('#editInterventionContainer input[type="checkbox"]').forEach(cb => {
  if (selInts.includes(cb.value)) cb.checked = true;
});
document.getElementById('addBlotterForm').addEventListener('submit', function(e) {
  const checked = document.querySelectorAll('#addBlotterForm input[name="categories[]"]:checked');
  if (checked.length === 0) {
    e.preventDefault();
    Swal.fire('Error','Please select at least one category','error');
  }
});
  </script>
</section>
</body>
</html>