<?php
session_start();
require_once '../config/dbconn.php';

// Ensure the user's barangay_id is set in the session and is valid
if (!isset($_SESSION['barangay_id']) || !is_numeric($_SESSION['barangay_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Barangay not set in session. Please log in again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Always use the barangay_id from the session (never from user input)
    $barangay_id = (int)$_SESSION['barangay_id'];
    
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? 'fa-file');
    $requirements = trim($_POST['requirements'] ?? '');
    $detailed_guide = trim($_POST['detailed_guide'] ?? '');
    $processing_time = trim($_POST['processing_time'] ?? '');
    $fees = trim($_POST['fees'] ?? '');
    $service_type = trim($_POST['service_type'] ?? 'general');
    $priority = trim($_POST['priority'] ?? 'normal');
    $availability = trim($_POST['availability'] ?? 'always');
    $additional_notes = trim($_POST['additional_notes'] ?? '');
    $category_id = null; // Set to null for custom services

    // Validate required fields
    $errors = [];
    if (empty($name)) $errors[] = 'Service name is required';
    if (empty($description)) $errors[] = 'Description is required';
    if (empty($requirements)) $errors[] = 'Requirements are required';
    if (empty($detailed_guide)) $errors[] = 'Step-by-step guide is required';
    if (empty($processing_time)) $errors[] = 'Processing time is required';
    if (empty($fees)) $errors[] = 'Fees information is required';

    if (!empty($errors)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Validation failed', 
            'errors' => $errors
        ]);
        exit();
    }

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Get the current max display_order for this barangay only
        $stmt = $pdo->prepare("
            SELECT MAX(display_order) as max_order 
            FROM custom_services 
            WHERE barangay_id = ?
        ");
        $stmt->execute([$barangay_id]);
        $result = $stmt->fetch();
        $display_order = ($result['max_order'] ?? 0) + 1;

        // Insert new service (category_id can be NULL)
        $stmt = $pdo->prepare("
            INSERT INTO custom_services (
                category_id, barangay_id, name, description, icon,
                requirements, detailed_guide, processing_time, fees, 
                service_type, priority_level, availability_type, additional_notes,
                display_order, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $category_id,
            $barangay_id,
            $name,
            $description,
            $icon,
            $requirements,
            $detailed_guide,
            $processing_time,
            $fees,
            $service_type,
            $priority,
            $availability,
            $additional_notes,
            $display_order
        ]);

        // Get the inserted service ID
        $service_id = $pdo->lastInsertId();

        // Log the action
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
            VALUES (?, 'INSERT', 'custom_services', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $service_id,
            "Added new service: $name"
        ]);

        // Commit transaction
        $pdo->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'Service added successfully',
            'service_id' => $service_id
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        error_log($e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred while adding service'
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        error_log($e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'An unexpected error occurred'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
} 