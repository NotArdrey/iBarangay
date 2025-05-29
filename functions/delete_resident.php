<?php
require_once "../config/dbconn.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if request method is DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
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
        SELECT p.id, p.first_name, p.last_name
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
        throw new Exception('Resident not found or unauthorized to delete');
    }

    // Delete related records first (maintaining referential integrity)
    
    // Delete from addresses
    $stmt = $pdo->prepare("DELETE FROM addresses WHERE person_id = ?");
    $stmt->execute([$resident_id]);

    // Delete from household_members
    $stmt = $pdo->prepare("DELETE FROM household_members WHERE person_id = ?");
    $stmt->execute([$resident_id]);

    // Finally, delete from persons table
    $stmt = $pdo->prepare("DELETE FROM persons WHERE id = ?");
    $stmt->execute([$resident_id]);

    // Log the deletion in audit trail
    $stmt = $pdo->prepare("
        INSERT INTO audit_trails (
            user_id, action, table_name, record_id, description
        ) VALUES (
            :user_id, 'DELETE', 'persons', :record_id, :description
        )
    ");
    
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':record_id' => $resident_id,
        ':description' => "Deleted resident: {$resident['first_name']} {$resident['last_name']}"
    ]);

    // Commit transaction
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Resident deleted successfully']);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error deleting resident: ' . $e->getMessage()
    ]);
}
?> 