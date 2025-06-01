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
    $service_id = $_POST['service_id'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = $_POST['icon'] ?? '';
    $requirements = trim($_POST['requirements'] ?? '');
    $detailed_guide = trim($_POST['detailed_guide'] ?? '');
    $processing_time = trim($_POST['processing_time'] ?? '');
    $fees = trim($_POST['fees'] ?? '');
    $barangay_id = $_SESSION['barangay_id'];

    // Validation
    if (empty($service_id) || empty($name) || empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Service ID, name, and description are required']);
        exit();
    }

    try {
        // Start transaction
        $pdo->beginTransaction();

        // Verify service exists and belongs to the barangay
        $stmt = $pdo->prepare("
            SELECT id, service_photo
            FROM custom_services 
            WHERE id = ? AND barangay_id = ?
        ");
        $stmt->execute([$service_id, $barangay_id]);
        $existing_service = $stmt->fetch();
        
        if (!$existing_service) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Service not found or you do not have permission to modify this service']);
            exit();
        }

        // Handle photo upload if new photo is provided
        $photo_filename = $existing_service['service_photo']; // Keep existing photo by default
        $old_photo = $existing_service['service_photo'];
        
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
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and GIF are allowed.']);
                exit();
            }
            
            // Validate file size (5MB limit)
            if ($file_size > 5 * 1024 * 1024) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB.']);
                exit();
            }
            
            // Generate unique filename
            $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
            $photo_filename = 'service_' . uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $photo_filename;
            
            if (!move_uploaded_file($file_tmp, $upload_path)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Failed to upload photo.']);
                exit();
            }
        }

        // Update the service
        $stmt = $pdo->prepare("
            UPDATE custom_services 
            SET name = ?, 
                description = ?, 
                icon = ?,
                requirements = ?,
                detailed_guide = ?,
                processing_time = ?,
                fees = ?,
                service_photo = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND barangay_id = ?
        ");
        
        $update_result = $stmt->execute([
            $name,
            $description,
            $icon,
            $requirements,
            $detailed_guide,
            $processing_time,
            $fees,
            $photo_filename,
            $service_id,
            $barangay_id
        ]);

        if (!$update_result) {
            $pdo->rollBack();
            // Clean up new uploaded file on error
            if (isset($_FILES['service_photo']) && $_FILES['service_photo']['error'] === UPLOAD_ERR_OK && $photo_filename !== $old_photo && file_exists($upload_dir . $photo_filename)) {
                unlink($upload_dir . $photo_filename);
            }
            echo json_encode(['success' => false, 'message' => 'Failed to update service in database']);
            exit();
        }

        // Delete old photo if a new one was uploaded and old one exists
        if (isset($_FILES['service_photo']) && $_FILES['service_photo']['error'] === UPLOAD_ERR_OK && $old_photo && $old_photo !== $photo_filename) {
            $old_photo_path = $upload_dir . $old_photo;
            if (file_exists($old_photo_path)) {
                unlink($old_photo_path);
            }
        }

        // Log the action
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (user_id, action, table_name, record_id, description)
            VALUES (?, 'UPDATE', 'custom_services', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $service_id,
            "Updated service: $name"
        ]);

        // Commit transaction
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Service updated successfully']);

    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        // Clean up new uploaded file on error
        if (isset($photo_filename) && $photo_filename !== $old_photo && file_exists($upload_dir . $photo_filename)) {
            unlink($upload_dir . $photo_filename);
        }
        
        error_log("Update service error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error occurred while updating service']);
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        
        // Clean up new uploaded file on error
        if (isset($photo_filename) && $photo_filename !== $old_photo && file_exists($upload_dir . $photo_filename)) {
            unlink($upload_dir . $photo_filename);
        }
        
        error_log("Update service error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}