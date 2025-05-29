<?php
// ...existing code: load configuration and functions...
require_once __DIR__ . '/../config/dbconn.php';
require_once __DIR__ . '/../pages/barangay_backup.php';

try {
    $results = runAutoBackups($pdo);
    echo "Auto Backup completed.\n";
    if (!empty($results)) {
        foreach ($results as $backup) {
            echo ucfirst($backup['type']) . " backup created: " . $backup['result']['filename'] .
                 " (" . $backup['result']['size'] . " MB)\n";
        }
    } else {
        echo "No backups were created.\n";
    }
} catch (Exception $e) {
    echo "Auto Backup failed: " . $e->getMessage() . "\n";
}
