<?php

// Add at the beginning of the file after requires
require_once __DIR__ . '/notification_helper.php';

function createServiceRequest($data) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // ... existing service request creation code ...
        
        // After successful creation, notify relevant users
        $request_id = $pdo->lastInsertId();
        
        // Notify barangay officials (captain and chief officers)
        createNotificationsForRoles(
            [2, 3], // Role IDs for captain and chief officers
            'service',
            'New Service Request',
            "A new service request #{$request_id} has been submitted and requires your attention.",
            'medium',
            'service_requests',
            $request_id,
            "../pages/service_details.php?id={$request_id}"
        );
        
        // Notify the user who submitted the request (action creator)
        createNotification(
            $data['user_id'],
            'service',
            'Service Request Submitted',
            "You have successfully submitted service request #{$request_id}. It is pending review.",
            'medium',
            'service_requests',
            $request_id,
            "../pages/service_status.php?id={$request_id}"
        );
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating service request: " . $e->getMessage());
        return false;
    }
}

function updateServiceStatus($request_id, $new_status, $updated_by_user_id) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // ... existing status update code ...
        
        // Get request details for notification
        $stmt = $pdo->prepare("
            SELECT sr.*, u.id as user_id
            FROM service_requests sr
            JOIN users u ON sr.user_id = u.id
            WHERE sr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Notify the user who submitted the request
        if ($request['user_id']) {
            createNotification(
                $request['user_id'],
                'service',
                'Service Request Status Updated',
                "Your service request #{$request_id} status has been updated to: {$new_status}",
                'medium',
                'service_requests',
                $request_id,
                "../pages/service_status.php?id={$request_id}"
            );
        }
        
        // If request is assigned, notify the assigned officer
        if ($request['assigned_to_user_id']) {
            createNotification(
                $request['assigned_to_user_id'],
                'service',
                'Service Request Assigned',
                "You have been assigned to service request #{$request_id}",
                'high',
                'service_requests',
                $request_id,
                "../pages/service_details.php?id={$request_id}"
            );
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating service status: " . $e->getMessage());
        return false;
    }
}

function scheduleServiceAppointment($request_id, $appointment_date, $appointment_time, $location, $scheduled_by_user_id) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // ... existing appointment scheduling code ...
        
        // Get request details for notification
        $stmt = $pdo->prepare("
            SELECT sr.*, u.id as user_id
            FROM service_requests sr
            JOIN users u ON sr.user_id = u.id
            WHERE sr.id = ?
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Notify the user who submitted the request
        if ($request['user_id']) {
            createNotification(
                $request['user_id'],
                'service',
                'Service Appointment Scheduled',
                "An appointment has been scheduled for your service request #{$request_id} on " . 
                date('M d, Y h:i A', strtotime("$appointment_date $appointment_time")) . 
                " at {$location}",
                'high',
                'service_requests',
                $request_id,
                "../pages/service_status.php?id={$request_id}"
            );
        }
        
        // Notify assigned officer if different from scheduler
        if ($request['assigned_to_user_id'] && $request['assigned_to_user_id'] != $scheduled_by_user_id) {
            createNotification(
                $request['assigned_to_user_id'],
                'service',
                'Service Appointment Scheduled',
                "An appointment has been scheduled for service request #{$request_id} on " . 
                date('M d, Y h:i A', strtotime("$appointment_date $appointment_time")) . 
                " at {$location}",
                'high',
                'service_requests',
                $request_id,
                "../pages/service_details.php?id={$request_id}"
            );
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error scheduling service appointment: " . $e->getMessage());
        return false;
    }
}

// ... rest of the file remains unchanged ... 