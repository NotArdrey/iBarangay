<?php
require_once "../components/header.php";
require "../config/dbconn.php";

// Check if user is logged in and is a captain
requireRole(3); // 3 is the role ID for captain

if (!isset($_GET['case_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Case ID is required']);
    exit;
}

$caseId = (int)$_GET['case_id'];
$bid = $_SESSION['barangay_id'];

try {
    // Get case details
    $stmt = $pdo->prepare("
        SELECT b.*, 
               CONCAT(c.first_name, ' ', c.last_name) as complainant_name,
               CONCAT(r.first_name, ' ', r.last_name) as respondent_name,
               bc.name as category_name
        FROM blotter_cases b
        LEFT JOIN persons c ON b.complainant_id = c.id
        LEFT JOIN persons r ON b.respondent_id = r.id
        LEFT JOIN blotter_categories bc ON b.category_id = bc.id
        WHERE b.id = ? AND b.barangay_id = ?
    ");
    $stmt->execute([$caseId, $bid]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Case not found']);
        exit;
    }

    // Get interventions
    $intStmt = $pdo->prepare("
        SELECT ir.*, i.name as intervention_name
        FROM blotter_intervention_records ir
        JOIN blotter_interventions i ON ir.intervention_id = i.id
        WHERE ir.case_id = ?
        ORDER BY ir.created_at DESC
    ");
    $intStmt->execute([$caseId]);
    $case['interventions'] = $intStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'case' => $case]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
} 