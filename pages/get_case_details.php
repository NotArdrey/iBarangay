<?php
require_once "../components/header.php";
require "../config/dbconn.php";

// Define requireRole if it's not already defined (e.g., by header.php or other includes)
// This function checks if the current user has one of the specified roles.
// If not, it sends an error response and exits.
if (!function_exists('requireRole')) {
    function requireRole($roles) {
        if (session_status() === PHP_SESSION_NONE) {
            // Start session if not already started.
            // Note: session_start() should ideally be called earlier, e.g., in a global bootstrap file or header.
            // Calling it here ensures it's active for role checking if not handled elsewhere.
            session_start();
        }

        // Assumption: User's role ID is stored in $_SESSION['user_role_id']
        // Adjust this key if your application uses a different session variable.
        $userRoleId = $_SESSION['user_role_id'] ?? null;

        if ($userRoleId === null) {
            // User not logged in or role not set in session.
            http_response_code(401); // Unauthorized
            echo json_encode(['success' => false, 'message' => 'Unauthorized: User role not found in session. Please log in.']);
            exit;
        }

        $isAllowed = false;
        if (is_array($roles)) {
            // Check if user's role is in the array of allowed roles
            if (in_array($userRoleId, $roles, true)) { // Use strict comparison
                $isAllowed = true;
            }
        } elseif ((int)$userRoleId === (int)$roles) { // Compare as integers if a single role ID is passed
            $isAllowed = true;
        }

        if (!$isAllowed) {
            http_response_code(403); // Forbidden
            $requiredRolesString = is_array($roles) ? implode(', ', $roles) : (string)$roles;
            echo json_encode(['success' => false, 'message' => "Access Denied. You do not have the required role(s): {$requiredRolesString}."]);
            exit;
        }
    }
}

// Check if user is logged in and has an authorized role (captain or role 7)
requireRole([3, 7]); // Authorized roles: 3 (captain), 7 (e.g., secretary or other authorized personnel)

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