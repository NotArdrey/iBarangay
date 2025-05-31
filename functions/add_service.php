<?php
session_start();
require_once '../config/dbconn.php';

// Check if user has appropriate role
if (!in_array($_SESSION['role_id'], [3,4,5,6,7])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
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
    $barangay_id = $_SESSION['barangay_id'];

    // Validate required fields
    $errors = [];
    if (empty($name)) $errors[] = 'Service name is required';
    if (empty($description)) $errors[] = 'Description is required';
    if (empty($requirements)) $errors[] = 'Requirements are required';
    if (empty($detailed_guide)) $errors[] = 'Step-by-step guide is required';
    if (empty($processing_time)) $errors[] = 'Processing time is required';
    if (empty($fees)) $errors[] = 'Fees information is required';

    // Handle photo upload
    $photo_filename = null;
    if (isset($_FILES['service_photo']) && $_FILES['service_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/service_photos/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_tmp = $_FILES['service_photo']['tmp_name'];
        $file_name = $_FILES['service_photo']['name'];
        $file_size = $_FILES['service_photo']['size'];
        $file_type = $_FILES['service_photo']['type'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = 'Invalid file type. Only JPEG, PNG, and GIF are allowed.';
        }
        
        // Validate file size (5MB limit)
        if ($file_size > 5 * 1024 * 1024) {
            $errors[] = 'File size must be less than 5MB.';
        }
        
        if (empty($errors)) {
            // Generate unique filename
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $photo_filename = 'service_' . uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $photo_filename;
            
            if (!move_uploaded_file($file_tmp, $upload_path)) {
                $errors[] = 'Failed to upload photo.';
            }
        }
    } else {
        $errors[] = 'Service photo is required.';
    }

    if (!empty($errors)) {
        // Clean up uploaded file if there were other errors
        if ($photo_filename && file_exists($upload_dir . $photo_filename)) {
            unlink($upload_dir . $photo_filename);
        }
        
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

        // Get or create a default category for this barangay
        $stmt = $pdo->prepare("
            SELECT id FROM service_categories 
            WHERE barangay_id = ? AND name = 'General Services'
            LIMIT 1
        ");
        $stmt->execute([$barangay_id]);
        $category = $stmt->fetch();
        
        if (!$category) {
            // Create default category
            $stmt = $pdo->prepare("
                INSERT INTO service_categories (barangay_id, name, description, icon, display_order, is_active) 
                VALUES (?, 'General Services', 'General barangay services', 'fa-cog', 1, 1)
            ");
            $stmt->execute([$barangay_id]);
            $category_id = $pdo->lastInsertId();
        } else {
            $category_id = $category['id'];
        }

        // Get the current max display_order
        $stmt = $pdo->prepare("
            SELECT MAX(display_order) as max_order 
            FROM custom_services 
            WHERE barangay_id = ?
        ");
        $stmt->execute([$barangay_id]);
        $result = $stmt->fetch();
        $display_order = ($result['max_order'] ?? 0) + 1;

        // First, check if the service_photo column exists, if not add it
        $stmt = $pdo->prepare("SHOW COLUMNS FROM custom_services LIKE 'service_photo'");
        $stmt->execute();
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE custom_services ADD COLUMN service_photo VARCHAR(255) AFTER additional_notes");
        }

        // Insert new service with all fields
        $stmt = $pdo->prepare("
            INSERT INTO custom_services (
                category_id, barangay_id, name, description, icon,
                requirements, detailed_guide, processing_time, fees, 
                service_type, priority_level, availability_type, additional_notes,
                service_photo, display_order, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
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
            $photo_filename,
            $display_order
        ]);

        // Log the action
        $service_id = $pdo->lastInsertId();
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
        
        // Clean up uploaded file on error
        if ($photo_filename && file_exists($upload_dir . $photo_filename)) {
            unlink($upload_dir . $photo_filename);
        }
        
        error_log($e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Database error occurred while adding service. Error: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        // Clean up uploaded file on error
        if ($photo_filename && file_exists($upload_dir . $photo_filename)) {
            unlink($upload_dir . $photo_filename);
        }
        
        error_log($e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'An unexpected error occurred. Error: ' . $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}