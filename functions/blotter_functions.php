<?php

// Add at the beginning of the file after requires
require_once __DIR__ . '/notification_helper.php';

function createBlotterCase($data) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // ... existing blotter case creation code ...
        
        // After successful creation, notify relevant users
        $case_id = $pdo->lastInsertId();
        
        // Notify barangay officials (captain and chief officers)
        createNotificationsForRoles(
            [2, 3], // Role IDs for captain and chief officers
            'blotter',
            'New Blotter Case Filed',
            "A new blotter case #{$case_id} has been filed and requires your attention.",
            'high',
            'blotter_cases',
            $case_id,
            "../pages/blotter_details.php?id={$case_id}"
        );
        
        // Notify the user who filed the case (action creator)
        createNotification(
            $data['reported_by_user_id'],
            'blotter',
            'Blotter Case Filed',
            "You have successfully filed blotter case #{$case_id}. It is pending review.",
            'medium',
            'blotter_cases',
            $case_id,
            "../pages/blotter_status.php?id={$case_id}"
        );
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error creating blotter case: " . $e->getMessage());
        return false;
    }
}

function updateBlotterStatus($case_id, $new_status, $updated_by_user_id) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // ... existing status update code ...
        
        // Get case details for notification
        $stmt = $pdo->prepare("
            SELECT bc.*, p.user_id as reported_by_user_id 
            FROM blotter_cases bc
            JOIN persons p ON bc.reported_by_person_id = p.id
            WHERE bc.id = ?
        ");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Notify the person who filed the case
        if ($case['reported_by_user_id']) {
            createNotification(
                $case['reported_by_user_id'],
                'blotter',
                'Blotter Case Status Updated',
                "Your blotter case #{$case_id} status has been updated to: {$new_status}",
                'medium',
                'blotter_cases',
                $case_id,
                "../pages/blotter_status.php?id={$case_id}"
            );
        }
        
        // If case is assigned, notify the assigned officer
        if ($case['assigned_to_user_id']) {
            createNotification(
                $case['assigned_to_user_id'],
                'blotter',
                'Blotter Case Assigned',
                "You have been assigned to blotter case #{$case_id}",
                'high',
                'blotter_cases',
                $case_id,
                "../pages/blotter_details.php?id={$case_id}"
            );
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating blotter status: " . $e->getMessage());
        return false;
    }
}

function scheduleHearing($case_id, $hearing_date, $hearing_time, $location, $scheduled_by_user_id) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        
        // ... existing hearing scheduling code ...
        
        // Get case details for notification
        $stmt = $pdo->prepare("
            SELECT bc.*, p.user_id as reported_by_user_id 
            FROM blotter_cases bc
            JOIN persons p ON bc.reported_by_person_id = p.id
            WHERE bc.id = ?
        ");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Notify all participants
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.user_id
            FROM blotter_participants bp
            JOIN persons p ON bp.person_id = p.id
            WHERE bp.blotter_case_id = ? AND p.user_id IS NOT NULL
        ");
        $stmt->execute([$case_id]);
        $participant_user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        createNotificationsForUsers(
            $participant_user_ids,
            'blotter',
            'Hearing Scheduled',
            "A hearing has been scheduled for blotter case #{$case_id} on " . 
            date('M d, Y h:i A', strtotime("$hearing_date $hearing_time")) . 
            " at {$location}",
            'high',
            'blotter_cases',
            $case_id,
            "../pages/blotter_status.php?id={$case_id}"
        );
        
        // Notify assigned officer if different from scheduler
        if ($case['assigned_to_user_id'] && $case['assigned_to_user_id'] != $scheduled_by_user_id) {
            createNotification(
                $case['assigned_to_user_id'],
                'blotter',
                'Hearing Scheduled',
                "A hearing has been scheduled for blotter case #{$case_id} on " . 
                date('M d, Y h:i A', strtotime("$hearing_date $hearing_time")) . 
                " at {$location}",
                'high',
                'blotter_cases',
                $case_id,
                "../pages/blotter_details.php?id={$case_id}"
            );
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error scheduling hearing: " . $e->getMessage());
        return false;
    }
} 