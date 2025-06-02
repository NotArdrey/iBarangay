<?php
require_once "../config/dbconn.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get resident ID from URL parameters
$resident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($resident_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid resident ID']);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // First, check if the resident exists and belongs to the current barangay
    $check_stmt = $pdo->prepare("
        SELECT p.id, p.first_name, p.last_name, p.user_id
        FROM persons p
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        WHERE p.id = ? 
        AND (h.barangay_id = ? OR h.barangay_id IS NULL)
        AND p.id IN (
            SELECT person_id 
            FROM household_members 
            WHERE household_id IN (
                SELECT id 
                FROM households 
                WHERE barangay_id = ?
            )
        )
    ");
    $check_stmt->execute([$resident_id, $_SESSION['barangay_id'], $_SESSION['barangay_id']]);
    $resident = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resident) {
        throw new Exception('Resident not found or unauthorized to restore');
    }

    // Restore the resident
    $stmt = $pdo->prepare("UPDATE persons SET is_archived = FALSE WHERE id = ?");
    $stmt->execute([$resident_id]);

    // If the resident has a user account, restore it as well
    if ($resident['user_id']) {
        $stmt = $pdo->prepare("UPDATE users SET is_active = TRUE WHERE id = ?");
        $stmt->execute([$resident['user_id']]);

        // Log the user account restoration in audit trail
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (
                user_id, action, table_name, record_id, description
            ) VALUES (
                :user_id, 'RESTORE', 'users', :record_id, :description
            )
        ");
        
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':record_id' => $resident['user_id'],
            ':description' => "Restored user account for resident: {$resident['first_name']} {$resident['last_name']}"
        ]);
    }

    // Log the resident restoration in audit trail
    $stmt = $pdo->prepare("
        INSERT INTO audit_trails (
            user_id, action, table_name, record_id, description
        ) VALUES (
            :user_id, 'RESTORE', 'persons', :record_id, :description
        )
    ");
    
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':record_id' => $resident_id,
        ':description' => "Restored resident: {$resident['first_name']} {$resident['last_name']}"
    ]);

    // Commit transaction
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Resident and associated user account have been restored successfully']);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error restoring resident: ' . $e->getMessage()
    ]);
}
?> 