<?php
// This script is designed to be run from the command line or a cron job/scheduled task.
// It will execute the automatic backup and cleanup procedures.

echo "Starting iBarangay Automatic Backup Task...\n";

// Set a long execution time
set_time_limit(1800); // 30 minutes

// Change to the script's directory to ensure correct relative paths
chdir(__DIR__);

// Include necessary files
require_once '../config/dbconn.php';
require_once '../pages/barangay_backup.php';

echo "Database connection and backup functions loaded.\n";

try {
    // Run the main auto backup function
    // This will check if backups are due and run them, then run cleanup.
    echo "Running auto-backup and cleanup process...\n";
    $results = runAutoBackups($pdo);

    if (empty($results)) {
        echo "No scheduled backups were due to run.\n";
    } else {
        foreach ($results as $result) {
            echo " - {$result['type']} backup created: {$result['result']['filename']}\n";
        }
    }
    
    // Explicitly call cleanup in case no backups ran
    echo "Running cleanup for old backups...\n";
    $deletedCount = cleanupOldBackups();
    echo "Cleanup complete. Deleted {$deletedCount} old backup files.\n";


    echo "Backup task finished successfully.\n";

} catch (Exception $e) {
    // Log any errors
    $errorMessage = "Automatic backup task failed: " . $e->getMessage() . "\n";
    echo $errorMessage;
    // Also log to the main backup log file
    logBackupActivity($errorMessage, 'CRITICAL');
    exit(1); // Exit with an error code
}

exit(0); // Success 