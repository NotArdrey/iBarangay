<?php
require "../config/dbconn.php";
require_once "../components/header.php";

if (!isset($_GET['id'])) {
    $_SESSION['error'] = "No household ID specified.";
    echo "<script>window.location.href = 'manage_households.php';</script>";
    exit;
}

$household_id = $_GET['id'];
$barangay_id = $_SESSION['barangay_id'];

try {
    // Start transaction
    $pdo->beginTransaction();

    // Get household info for audit trail
    $stmt = $pdo->prepare("
        SELECT h.*, p.name as purok_name 
        FROM households h 
        LEFT JOIN purok p ON h.purok_id = p.id 
        WHERE h.id = ? AND h.barangay_id = ?
    ");
    $stmt->execute([$household_id, $barangay_id]);
    $household = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$household) {
        throw new Exception("Household not found.");
    }

    // Check if household has any members
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM household_members 
        WHERE household_id = ?
    ");
    $stmt->execute([$household_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        throw new Exception("Cannot delete household because it has residents assigned to it.");
    }

    // Delete the household
    $stmt = $pdo->prepare("DELETE FROM households WHERE id = ? AND barangay_id = ?");
    $stmt->execute([$household_id, $barangay_id]);

    // Log to audit trail
    $stmt = $pdo->prepare("
        INSERT INTO audit_trails (
            user_id, action, table_name, record_id, description
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        'DELETE',
        'households',
        $household_id,
        "Deleted household number: {$household['household_number']} from Purok: {$household['purok_name']}"
    ]);

    $pdo->commit();

    // Set success message in session
    $_SESSION['success'] = "Household deleted successfully";
    echo "<script>window.location.href = 'manage_households.php';</script>";
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    // Set error message in session
    $_SESSION['error'] = $e->getMessage();
    echo "<script>window.location.href = 'manage_households.php';</script>";
    exit;
} 