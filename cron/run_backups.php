<?php
// cron/run_backups.php

// This script is intended to be run by a cron job to automate backups.

require_once dirname(__DIR__) . '/config/dbconn.php';
require_once dirname(__DIR__) . '/pages/barangay_backup.php'; // Reuse functions

// Load settings
$backupConfig = getBackupSettings();

if (!$backupConfig['auto_backup_enabled']) {
    logBackupActivity("Auto-backup is disabled. Exiting.", 'INFO');
    exit;
}

logBackupActivity("Cron job started.", 'INFO');

try {
    // --- Determine which backup type to run ---
    $now = time();
    $backupDir = rtrim($backupConfig['backup_dir'], '/') . '/';

    // Daily Backup Check
    if ($backupConfig['daily_backup_enabled']) {
        $todayBackups = glob($backupDir . "barangay_backup_daily_" . date('Y-m-d') . "_*.sql");
        if (empty($todayBackups)) {
            logBackupActivity("Creating daily backup...", 'INFO');
            createBackup($pdo, 'daily');
        }
    }

    // Weekly Backup Check (runs on Sunday)
    if ($backupConfig['weekly_backup_enabled'] && date('w') == 0) {
        $startOfWeek = date('Y-m-d', strtotime('last sunday'));
        $weekBackups = glob($backupDir . "barangay_backup_weekly_" . $startOfWeek . "_*.sql");
        if (empty($weekBackups)) {
            logBackupActivity("Creating weekly backup...", 'INFO');
            createBackup($pdo, 'weekly');
        }
    }

    // Monthly Backup Check (runs on the 1st of the month)
    if ($backupConfig['monthly_backup_enabled'] && date('j') == 1) {
        $startOfMonth = date('Y-m-01');
        $monthBackups = glob($backupDir . "barangay_backup_monthly_" . $startOfMonth . "_*.sql");
        if (empty($monthBackups)) {
            logBackupActivity("Creating monthly backup...", 'INFO');
            createBackup($pdo, 'monthly');
        }
    }

    // --- Cleanup old backups ---
    logBackupActivity("Running cleanup task...", 'INFO');
    $deletedCount = cleanupOldBackups();
    logBackupActivity("Cleanup finished. Deleted {$deletedCount} old files.", 'INFO');

} catch (Exception $e) {
    logBackupActivity("Cron job failed: " . $e->getMessage(), 'ERROR');
}

logBackupActivity("Cron job finished.", 'INFO'); 