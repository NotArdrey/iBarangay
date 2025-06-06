<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/dbconn.php';

/**
 * Fetches backup configuration from the database.
 * Creates the table from an SQL file if it doesn't exist.
 */
function getBackupConfig(PDO $pdo) {
    $defaults = [
        'auto_backup_enabled' => true,
        'daily_backup_enabled' => true,
        'weekly_backup_enabled' => true,
        'monthly_backup_enabled' => true,
        'daily_retention_days' => 7,
        'weekly_retention_weeks' => 4,
        'monthly_retention_months' => 12,
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM backup_settings");
        $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $config = array_merge($defaults, $dbSettings);
    } catch (PDOException $e) {
        // Table doesn't exist, try to create it
        try {
            $sql = file_get_contents(__DIR__ . '/../database/backup_settings.sql');
            $pdo->exec($sql);
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM backup_settings");
            $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $config = array_merge($defaults, $dbSettings);
        } catch (Exception $e) {
            // Fallback to defaults if everything fails
            $config = $defaults;
        }
    }

    // Type casting
    $config['auto_backup_enabled'] = (bool)($config['auto_backup_enabled'] ?? false);
    // ... (cast all other config values as bool/int)

    // Add static configurations
    $config['backup_dir'] = __DIR__ . '/../backups/';
    $config['backup_time_limit'] = 300;
    $config['log_file'] = __DIR__ . '/../logs/backup.log';

    return $config;
}

/**
 * Saves backup configuration to the database.
 */
function saveBackupConfig(PDO $pdo, $configData) {
    $stmt = $pdo->prepare("INSERT INTO backup_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = :value");
    foreach ($configData as $key => $value) {
        $stmt->execute([':key' => $key, ':value' => $value]);
    }
}

// Get initial backup configuration
$backupConfig = getBackupConfig($pdo);

// Enhanced backup configuration
$backupConfig = [
    'backup_dir' => __DIR__ . '/../backups/',
    'auto_backup_enabled' => true,
    'daily_backup_enabled' => true,
    'weekly_backup_enabled' => true,
    'monthly_backup_enabled' => true,
    'daily_retention_days' => 7,
    'weekly_retention_weeks' => 4,
    'monthly_retention_months' => 12,
    'backup_time_limit' => 600,       // 10 minutes timeout
    'log_file' => __DIR__ . '/../logs/backup.log',
    'max_backup_size' => 500 * 1024 * 1024, // 500MB max
    'compression_enabled' => true,
    'include_data' => true,           // NEW: Control data inclusion
    'batch_size' => 1000             // NEW: Process data in batches
];

// Ensure directories exist
if (!is_dir($backupConfig['backup_dir'])) {
    mkdir($backupConfig['backup_dir'], 0755, true);
}
if (!is_dir(dirname($backupConfig['log_file']))) {
    mkdir(dirname($backupConfig['log_file']), 0755, true);
}

/**
 * Enhanced logging with different levels and better formatting
 */
function logBackupActivity($message, $level = 'INFO', $details = null) {
    global $backupConfig;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}";
    
    if ($details) {
        $logEntry .= " | Details: " . (is_array($details) ? json_encode($details) : $details);
    }
    
    $logEntry .= " | Memory: " . formatBytes(memory_get_usage(true)) . PHP_EOL;
    
    file_put_contents($backupConfig['log_file'], $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also log to PHP error log for critical errors
    if ($level === 'ERROR' || $level === 'CRITICAL') {
        error_log($logEntry);
    }
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Get table dependencies for proper restore order
 */
function getTableDependencies($pdo) {
    $query = "
        SELECT 
            TABLE_NAME,
            REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE 
            TABLE_SCHEMA = DATABASE() 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME
    ";
    
    $stmt = $pdo->query($query);
    $dependencies = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $table = $row['TABLE_NAME'];
        $referenced = $row['REFERENCED_TABLE_NAME'];
        
        if (!isset($dependencies[$table])) {
            $dependencies[$table] = [];
        }
        if (!in_array($referenced, $dependencies[$table])) {
            $dependencies[$table][] = $referenced;
        }
    }
    
    return $dependencies;
}

/**
 * Sort tables by dependencies
 */
function sortTablesByDependencies($tables, $dependencies) {
    $sorted = [];
    $remaining = $tables;
    $iterations = 0;
    $maxIterations = count($tables) * 2;
    
    while (!empty($remaining) && $iterations < $maxIterations) {
        $addedInThisIteration = [];
        
        foreach ($remaining as $table) {
            $canAdd = true;
            
            if (isset($dependencies[$table])) {
                foreach ($dependencies[$table] as $dependency) {
                    if (in_array($dependency, $remaining) && !in_array($dependency, $addedInThisIteration)) {
                        $canAdd = false;
                        break;
                    }
                }
            }
            
            if ($canAdd) {
                $sorted[] = $table;
                $addedInThisIteration[] = $table;
            }
        }
        
        $remaining = array_diff($remaining, $addedInThisIteration);
        $iterations++;
    }
    
    // Add any remaining tables
    $sorted = array_merge($sorted, $remaining);
    
    return $sorted;
}

/**
 * Enhanced backup creation with full data support
 */
function createBackup($pdo, $backupType = 'manual') {
    global $backupConfig;
    
    set_time_limit($backupConfig['backup_time_limit']);
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupConfig['backup_dir'] . "barangay_backup_{$backupType}_{$timestamp}.sql.gz";
    
    try {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        if (empty($tables)) {
            throw new Exception("No tables found in database");
        }
        
        $handle = gzopen($backupFile, 'w9');
        if ($handle === false) {
            throw new Exception("Failed to create gzipped backup file.");
        }

        gzwrite($handle, "-- Barangay Database Backup\n");
        gzwrite($handle, "-- Database: {$dbName}\n");
        gzwrite($handle, "-- Backup Type: {$backupType}\n");
        gzwrite($handle, "-- Created: " . date('Y-m-d H:i:s') . "\n");
        gzwrite($handle, "-- Tables: " . count($tables) . "\n\n");
        gzwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        
        $totalRows = 0;
        foreach ($tables as $table) {
            // Table structure
            $stmt2 = $pdo->query("SHOW CREATE TABLE `$table`");
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            gzwrite($handle, "-- --------------------------------------------------------\n");
            gzwrite($handle, "-- Table structure for `$table`\n");
            gzwrite($handle, "-- --------------------------------------------------------\n");
            gzwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
            gzwrite($handle, $row2['Create Table'] . ";\n\n");

            // Get column information to filter out generated columns
            $stmt_cols = $pdo->prepare(
                "SELECT COLUMN_NAME, EXTRA
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name"
            );
            $stmt_cols->execute([':db_name' => $dbName, ':table_name' => $table]);

            $non_generated_columns = [];
            while ($col_row = $stmt_cols->fetch(PDO::FETCH_ASSOC)) {
                if (strpos(strtoupper($col_row['EXTRA']), 'GENERATED') === false) {
                    $non_generated_columns[] = $col_row['COLUMN_NAME'];
                }
            }

            // If no columns are insertable, skip data dump
            if (empty($non_generated_columns)) {
                continue;
            }
            
            $column_list_sql = '`' . implode('`, `', $non_generated_columns) . '`';
            
            // Table data
            $stmt3 = $pdo->query("SELECT {$column_list_sql} FROM `$table`");
            $num_rows = 0;
            while ($row = $stmt3->fetch(PDO::FETCH_ASSOC)) {
                if ($num_rows === 0) {
                    gzwrite($handle, "--\n-- Dumping data for table `$table`\n--\n");
                }
                
                $sql = "INSERT INTO `$table` ({$column_list_sql}) VALUES(";
                $values = [];
                foreach ($row as $value) {
                    if (is_null($value)) {
                        $values[] = "NULL";
                    } else {
                        $values[] = $pdo->quote($value);
                    }
                }
                $sql .= implode(', ', $values) . ");\n";
                gzwrite($handle, $sql);
                $num_rows++;
            }
            $totalRows += $num_rows;
            if($num_rows > 0) {
                gzwrite($handle, "\n");
            }
        }
        
        gzwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        gzwrite($handle, "-- End of backup\n");
        gzwrite($handle, "-- Total rows exported: {$totalRows}\n");
        
        gzclose($handle);
        
        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            throw new Exception("Backup file verification failed");
        }
        
        $backupFileName = basename($backupFile);
        $fileSizeMB = round(filesize($backupFile) / 1024 / 1024, 2);
        
        logBackupActivity("Backup created successfully: {$backupFileName} ({$fileSizeMB} MB, {$totalRows} rows)", 'SUCCESS');
        
        return [
            'success' => true,
            'filename' => $backupFileName,
            'filepath' => $backupFile,
            'size' => $fileSizeMB,
            'rows' => $totalRows,
            'tables' => count($tables)
        ];
        
    } catch (Exception $e) {
        logBackupActivity("Backup failed: " . $e->getMessage(), 'ERROR');
        if (isset($handle) && is_resource($handle)) {
            gzclose($handle);
        }
        if (isset($backupFile) && file_exists($backupFile)) {
            unlink($backupFile);
        }
        throw $e;
    }
}

/**
 * Restore database from backup
 */
function restoreBackup($pdo, $backupFilePath) {
    global $backupConfig;

    set_time_limit($backupConfig['backup_time_limit']);

    if (!file_exists($backupFilePath)) {
        throw new Exception("Backup file not found: " . $backupFilePath);
    }

    try {
        // Decompress and read the SQL file
        $gz = gzopen($backupFilePath, 'rb');
        if (!$gz) {
            throw new Exception("Cannot open gzipped file: " . $backupFilePath);
        }
        
        $sql = '';
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 4096);
        }
        gzclose($gz);

        if (empty($sql)) {
            throw new Exception("Backup file is empty.");
        }

        // DDL statements like DROP/CREATE TABLE cause implicit commits,
        // so we can't wrap the execution in a single PDO transaction.
        $pdo->exec($sql);
        
        // Extract the number of rows from the backup file comment for logging
        $rowsExported = 0;
        if (preg_match('/-- Total rows exported: (\d+)/', $sql, $matches)) {
            $rowsExported = (int)$matches[1];
        }

        logBackupActivity("Database restored successfully from " . basename($backupFilePath) . " ({$rowsExported} rows imported)", 'SUCCESS');

        return [
            'success' => true,
            'queries_executed' => 'N/A (script executed)', // Not counting individual queries anymore
            'rows_imported' => $rowsExported
        ];

    } catch (Exception $e) {
        // No transaction to roll back, just log and re-throw
        logBackupActivity("Restore failed: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * Enhanced auto backup control
 */
function getAutoBackupSettings() {
    global $backupConfig;
    return [
        'auto_backup_enabled' => $backupConfig['auto_backup_enabled'],
        'daily_backup_enabled' => $backupConfig['daily_backup_enabled'],
        'weekly_backup_enabled' => $backupConfig['weekly_backup_enabled'],
        'monthly_backup_enabled' => $backupConfig['monthly_backup_enabled'],
        'daily_retention_days' => $backupConfig['daily_retention_days'],
        'weekly_retention_weeks' => $backupConfig['weekly_retention_weeks'],
        'monthly_retention_months' => $backupConfig['monthly_retention_months']
    ];
}

function updateAutoBackupSettings($settings) {
    global $backupConfig;
    
    // Validate settings
    $validSettings = [
        'auto_backup_enabled' => filter_var($settings['auto_backup_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'daily_backup_enabled' => filter_var($settings['daily_backup_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'weekly_backup_enabled' => filter_var($settings['weekly_backup_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'monthly_backup_enabled' => filter_var($settings['monthly_backup_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'daily_retention_days' => max(1, intval($settings['daily_retention_days'] ?? 7)),
        'weekly_retention_weeks' => max(1, intval($settings['weekly_retention_weeks'] ?? 4)),
        'monthly_retention_months' => max(1, intval($settings['monthly_retention_months'] ?? 12))
    ];
    
    // Save to configuration file
    $configFile = __DIR__ . '/../config/backup_config.json';
    file_put_contents($configFile, json_encode($validSettings, JSON_PRETTY_PRINT));
    
    // Update runtime config
    foreach ($validSettings as $key => $value) {
        $backupConfig[$key] = $value;
    }
    
    logBackupActivity("Auto backup settings updated", 'INFO', $validSettings);
    
    return $validSettings;
}

// Load custom configuration if exists
$configFile = __DIR__ . '/../config/backup_config.json';
if (file_exists($configFile)) {
    $customConfig = json_decode(file_get_contents($configFile), true);
    if ($customConfig) {
        $backupConfig = array_merge($backupConfig, $customConfig);
    }
}

/**
 * Clean up old backup files based on retention policy
 */
function cleanupOldBackups() {
    global $backupConfig;
    
    $backupDir = $backupConfig['backup_dir'];
    $deletedFiles = 0;
    $totalSizeFreed = 0;
    
    logBackupActivity("Starting backup cleanup", 'INFO');
    
    try {
        // Clean up daily backups
        $dailyBackups = glob($backupDir . "barangay_backup_daily_*");
        foreach ($dailyBackups as $file) {
            $fileTime = filemtime($file);
            $daysOld = (time() - $fileTime) / (24 * 60 * 60);
            if ($daysOld >= $backupConfig['daily_retention_days']) {
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedFiles++;
                    $totalSizeFreed += $fileSize;
                    logBackupActivity("Deleted daily backup", 'INFO', ['file' => basename($file), 'age_days' => round($daysOld, 1)]);
                }
            }
        }
        
        // Clean up weekly backups
        $weeklyBackups = glob($backupDir . "barangay_backup_weekly_*");
        foreach ($weeklyBackups as $file) {
            $fileTime = filemtime($file);
            $weeksOld = (time() - $fileTime) / (7 * 24 * 60 * 60);
            if ($weeksOld >= $backupConfig['weekly_retention_weeks']) {
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedFiles++;
                    $totalSizeFreed += $fileSize;
                    logBackupActivity("Deleted weekly backup", 'INFO', ['file' => basename($file), 'age_weeks' => round($weeksOld, 1)]);
                }
            }
        }
        
        // Clean up monthly backups
        $monthlyBackups = glob($backupDir . "barangay_backup_monthly_*");
        foreach ($monthlyBackups as $file) {
            $fileTime = filemtime($file);
            $monthsOld = (time() - $fileTime) / (30 * 24 * 60 * 60);
            if ($monthsOld >= $backupConfig['monthly_retention_months']) {
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedFiles++;
                    $totalSizeFreed += $fileSize;
                    logBackupActivity("Deleted monthly backup", 'INFO', ['file' => basename($file), 'age_months' => round($monthsOld, 1)]);
                }
            }
        }
        
        if ($deletedFiles > 0) {
            $sizeMB = round($totalSizeFreed / 1024 / 1024, 2);
            logBackupActivity("Cleanup completed", 'SUCCESS', [
                'deleted_files' => $deletedFiles,
                'space_freed' => $sizeMB . ' MB'
            ]);
        } else {
            logBackupActivity("Cleanup completed - no files to delete", 'INFO');
        }
        
        return $deletedFiles;
        
    } catch (Exception $e) {
        logBackupActivity("Cleanup failed", 'ERROR', ['error' => $e->getMessage()]);
        return 0;
    }
}

/**
 * Check if auto backup should run
 */
function shouldRunAutoBackup($backupType) {
    global $backupConfig;
    
    if (!$backupConfig['auto_backup_enabled']) {
        return false;
    }
    
    $backupDir = $backupConfig['backup_dir'];
    
    switch ($backupType) {
        case 'daily':
            if (!$backupConfig['daily_backup_enabled']) return false;
            $pattern = $backupDir . "barangay_backup_daily_" . date('Y-m-d') . "_*";
            $todayBackups = glob($pattern);
            return empty($todayBackups);
            
        case 'weekly':
            if (!$backupConfig['weekly_backup_enabled']) return false;
            if (date('w') != 0) return false; // Sunday
            $pattern = $backupDir . "barangay_backup_weekly_" . date('Y-m-d', strtotime('last Sunday')) . "_*";
            $weeklyBackups = glob($pattern);
            return empty($weeklyBackups);
            
        case 'monthly':
            if (!$backupConfig['monthly_backup_enabled']) return false;
            if (date('j') != 1) return false; // First day of month
            $pattern = $backupDir . "barangay_backup_monthly_" . date('Y-m') . "-01_*";
            $monthlyBackups = glob($pattern);
            return empty($monthlyBackups);
    }
    
    return false;
}

/**
 * Run automatic backups
 */
function runAutoBackups($pdo) {
    $backupsCreated = [];
    
    // Daily backup
    if (shouldRunAutoBackup('daily')) {
        try {
            $result = createBackup($pdo, 'daily');
            $backupsCreated[] = ['type' => 'daily', 'result' => $result];
        } catch (Exception $e) {
            logBackupActivity("Auto daily backup failed", 'ERROR', ['error' => $e->getMessage()]);
        }
    }
    
    // Weekly backup
    if (shouldRunAutoBackup('weekly')) {
        try {
            $result = createBackup($pdo, 'weekly');
            $backupsCreated[] = ['type' => 'weekly', 'result' => $result];
        } catch (Exception $e) {
            logBackupActivity("Auto weekly backup failed", 'ERROR', ['error' => $e->getMessage()]);
        }
    }
    
    // Monthly backup
    if (shouldRunAutoBackup('monthly')) {
        try {
            $result = createBackup($pdo, 'monthly');
            $backupsCreated[] = ['type' => 'monthly', 'result' => $result];
        } catch (Exception $e) {
            logBackupActivity("Auto monthly backup failed", 'ERROR', ['error' => $e->getMessage()]);
        }
    }
    
    // Cleanup old backups
    cleanupOldBackups();
    
    return $backupsCreated;
}

/**
 * Get backup statistics
 */
function getBackupStats() {
    global $backupConfig;
    
    $backupDir = $backupConfig['backup_dir'];
    $stats = [
        'total_backups' => 0,
        'total_size' => 0,
        'daily_backups' => 0,
        'weekly_backups' => 0,
        'monthly_backups' => 0,
        'manual_backups' => 0,
        'latest_backup' => null,
        'oldest_backup' => null
    ];
    
    $allBackups = array_merge(
        glob($backupDir . "barangay_backup_*.sql"),
        glob($backupDir . "barangay_backup_*.sql.gz")
    );
    $stats['total_backups'] = count($allBackups);
    
    $latestTime = 0;
    $oldestTime = PHP_INT_MAX;
    
    foreach ($allBackups as $file) {
        $fileSize = filesize($file);
        $fileTime = filemtime($file);
        $stats['total_size'] += $fileSize;
        
        if ($fileTime > $latestTime) {
            $latestTime = $fileTime;
            $stats['latest_backup'] = basename($file);
        }
        
        if ($fileTime < $oldestTime) {
            $oldestTime = $fileTime;
            $stats['oldest_backup'] = basename($file);
        }
        
        // Count by type
        $filename = basename($file);
        if (strpos($filename, '_daily_') !== false) {
            $stats['daily_backups']++;
        } elseif (strpos($filename, '_weekly_') !== false) {
            $stats['weekly_backups']++;
        } elseif (strpos($filename, '_monthly_') !== false) {
            $stats['monthly_backups']++;
        } else {
            $stats['manual_backups']++;
        }
    }
    
    $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);
    $stats['latest_backup_time'] = $latestTime > 0 ? date('Y-m-d H:i:s', $latestTime) : null;
    $stats['oldest_backup_time'] = $oldestTime < PHP_INT_MAX ? date('Y-m-d H:i:s', $oldestTime) : null;
    
    return $stats;
}

/**
 * Get backup logs
 */
function getBackupLogs($limit = 100) {
    global $backupConfig;
    
    if (!file_exists($backupConfig['log_file'])) {
        return [];
    }
    
    $logs = file($backupConfig['log_file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $logs = array_reverse($logs); // Most recent first
    
    if ($limit > 0) {
        $logs = array_slice($logs, 0, $limit);
    }
    
    return $logs;
}

// Handle AJAX requests for progress updates
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'backup_progress':
            session_write_close();
            echo json_encode(['status' => $_SESSION['backup_progress'] ?? ['stage' => 'waiting']]);
            exit;
            
        case 'restore_progress':
            session_write_close();
            echo json_encode(['status' => $_SESSION['restore_progress'] ?? ['stage' => 'waiting']]);
            exit;
            
        case 'get_logs':
            $logs = getBackupLogs(50);
            echo json_encode(['logs' => $logs]);
            exit;
            
        case 'get_settings':
            echo json_encode(getAutoBackupSettings());
            exit;
    }
}

// Handle form submissions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Manual backup
    if (isset($_POST['manual_backup'])) {
        try {
            $_SESSION['backup_progress'] = ['stage' => 'starting', 'percentage' => 0];
            
            $includeData = isset($_POST['include_data']) ? true : false;
            
            $result = createBackup($pdo, 'manual', $includeData, function($progress) {
                $_SESSION['backup_progress'] = $progress;
            });
            
            $_SESSION['backup_progress'] = ['stage' => 'completed', 'percentage' => 100];
            
            $message = "Manual backup created successfully: " . $result['filename'] . 
                      " (Size: " . $result['size'] . " MB, Rows: " . $result['rows'] . ", Tables: " . $result['tables'] . ")";
            $messageType = 'success';
            
        } catch (Exception $e) {
            $_SESSION['backup_progress'] = ['stage' => 'error', 'message' => $e->getMessage()];
            $message = "Manual backup failed: " . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    // Restore backup
    if (isset($_POST['restore'])) {
        $fileName = $_POST['restore_file'] ?? '';
        if ($fileName) {
            $backupFilePath = $backupConfig['backup_dir'] . $fileName;
            try {
                $_SESSION['restore_progress'] = ['stage' => 'starting', 'percentage' => 0];
                
                $result = restoreBackup($pdo, $backupFilePath, function($progress) {
                    $_SESSION['restore_progress'] = $progress;
                });
                
                $_SESSION['restore_progress'] = ['stage' => 'completed', 'percentage' => 100];
                
                $warningMsg = !empty($result['errors']) ? " (with " . count($result['errors']) . " warnings)" : "";
                $message = "Database restored successfully from " . $fileName . 
                          " (" . $result['queries_executed'] . " queries executed)" . $warningMsg;
                $messageType = 'success';
                
            } catch (Exception $e) {
                $_SESSION['restore_progress'] = ['stage' => 'error', 'message' => $e->getMessage()];
                $message = "Restore failed: " . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = "No backup file specified for restoration.";
            $messageType = 'error';
        }
    }
    
    // Cleanup old backups
    if (isset($_POST['cleanup'])) {
        try {
            $deletedCount = cleanupOldBackups();
            $message = "Cleanup completed: {$deletedCount} old backup files removed.";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Cleanup failed: " . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    // Manually trigger auto backup cycle
    if (isset($_POST['run_auto_cycle'])) {
        try {
            $results = runAutoBackups($pdo);
            $createdCount = count($results);
            $deletedCount = cleanupOldBackups(); // cleanup is also in runAutoBackups, but running it again is safe.
            $message = "Automation cycle completed. Created {$createdCount} new backup(s) and deleted {$deletedCount} old backup(s).";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Automation cycle failed: " . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    // Update auto backup settings
    if (isset($_POST['update_settings'])) {
        try {
            $settings = updateAutoBackupSettings($_POST);
            $message = "Auto backup settings updated successfully.";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Failed to update settings: " . $e->getMessage();
            $messageType = 'error';
        }
    }

    if (isset($_POST['upload_and_restore'])) {
        if (isset($_FILES['uploaded_backup_file']) && $_FILES['uploaded_backup_file']['error'] == UPLOAD_ERR_OK) {
            $uploadedFile = $_FILES['uploaded_backup_file'];
            $tempPath = $uploadedFile['tmp_name'];
            $originalName = basename($uploadedFile['name']);

            // Security check for .sql.gz files
            if (preg_match('/\.sql\.gz$/', $originalName)) {
                $restored = false;
                try {
                    $result = restoreBackup($pdo, $tempPath);
                    $_SESSION['backup_message'] = "Database successfully restored from uploaded file: " . $originalName;
                    $_SESSION['backup_message_type'] = 'success';
                    $restored = true;
                } catch (Exception $e) {
                    $_SESSION['backup_message'] = "Restore from uploaded file failed: " . $e->getMessage();
                    $_SESSION['backup_message_type'] = 'error';
                }
            } else {
                $_SESSION['backup_message'] = "Invalid file type. Please upload a .sql.gz file.";
                $_SESSION['backup_message_type'] = 'error';
            }
        } else {
            $_SESSION['backup_message'] = "File upload failed or no file selected. Error code: " . ($_FILES['uploaded_backup_file']['error'] ?? 'N/A');
            $_SESSION['backup_message_type'] = 'error';
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
    
    // Store message in session before redirect
    $_SESSION['backup_message'] = $message;
    $_SESSION['backup_message_type'] = $messageType;
    
    // Prevent resubmission on refresh by redirecting
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

// On GET, retrieve message from session if available
if (isset($_SESSION['backup_message'])) {
    $message = $_SESSION['backup_message'];
    $messageType = $_SESSION['backup_message_type'] ?? 'info';
    unset($_SESSION['backup_message'], $_SESSION['backup_message_type']);
}

require_once '../components/header.php';

// Get backup files and statistics
$backupFiles = glob($backupConfig['backup_dir'] . "barangay_backup_*.sql.gz");
$backupFiles = array_map('basename', $backupFiles);
rsort($backupFiles); // Sort newest first

$stats = getBackupStats();
$autoBackupSettings = getAutoBackupSettings();

// Run auto backups if needed (commented out by default)
// $autoBackupResults = runAutoBackups($pdo);

// Handle Download Request
if (isset($_GET['download_file'])) {
    $fileName = basename($_GET['download_file']);
    $filePath = $backupConfig['backup_dir'] . $fileName;

    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $_SESSION['backup_message'] = "File not found.";
        $_SESSION['backup_message_type'] = 'error';
        header("Location: barangay_backup.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Enhanced Barangay Backup & Restore System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <style>
        .progress-bar {
            transition: width 0.3s ease;
        }
        .loading-spinner {
            border: 4px solid #f3f4f6;
            border-top: 4px solid #3b82f6;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            white-space: pre-wrap;
        }
        .log-success { color: #059669; }
        .log-error { color: #dc2626; }
        .log-warning { color: #d97706; }
        .log-info { color: #0284c7; }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center z-50 hidden">
        <div class="bg-white p-6 rounded-lg shadow-xl flex items-center">
            <svg class="animate-spin h-8 w-8 text-blue-600 mr-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="text-lg font-semibold text-gray-700">Restoring database, please wait...</span>
        </div>
    </div>
    <div class="container mx-auto p-6 max-w-7xl">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h1 class="text-4xl font-bold text-blue-800 mb-2 flex items-center">
                <i class="fas fa-database mr-3"></i>
                Enhanced Barangay Backup & Restore System
            </h1>
            <p class="text-gray-600">Comprehensive database backup and restore with advanced features and admin controls</p>
        </div>

        <!-- Tab Navigation -->
        <div class="mb-8 border-b border-gray-200">
            <nav class="flex space-x-4" aria-label="Tabs">
                <a href="?tab=backups" id="tab-backups" class="tab-link px-3 py-2 font-medium text-sm rounded-md">
                    <i class="fas fa-archive mr-2"></i>Backups & Restore
                </a>
                <a href="?tab=settings" id="tab-settings" class="tab-link px-3 py-2 font-medium text-sm rounded-md">
                    <i class="fas fa-cog mr-2"></i>Settings
                </a>
            </nav>
        </div>

        <?php $active_tab = $_GET['tab'] ?? 'backups'; ?>

        <!-- Tab Content: Backups -->
        <div id="content-backups" class="tab-content <?php if ($active_tab !== 'backups') echo 'hidden'; ?>">
            <!-- Messages -->
            <?php if ($message): ?>
                <div class="p-4 mb-6 rounded-lg <?php 
                    echo $messageType === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 
                        ($messageType === 'error' ? 'bg-red-100 border border-red-400 text-red-700' : 
                         'bg-blue-100 border border-blue-400 text-blue-700'); 
                ?>">
                    <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 
                        ($messageType === 'error' ? 'fa-exclamation-triangle' : 'fa-info-circle'); ?> mr-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Progress Indicators -->
            <div id="backup-progress" class="hidden mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center mb-4">
                        <div class="loading-spinner mr-4"></div>
                        <h3 class="text-lg font-semibold">Creating Backup...</h3>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4 mb-2">
                        <div id="backup-progress-bar" class="bg-blue-600 h-4 rounded-full progress-bar" style="width: 0%"></div>
                    </div>
                    <p id="backup-progress-text" class="text-sm text-gray-600">Initializing...</p>
                </div>
            </div>

            <div id="restore-progress" class="hidden mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center mb-4">
                        <div class="loading-spinner mr-4"></div>
                        <h3 class="text-lg font-semibold">Restoring Database...</h3>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4 mb-2">
                        <div id="restore-progress-bar" class="bg-green-600 h-4 rounded-full progress-bar" style="width: 0%"></div>
                    </div>
                    <p id="restore-progress-text" class="text-sm text-gray-600">Initializing...</p>
                </div>
            </div>

            <!-- Dashboard Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-archive text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Backups</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_backups']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-hdd text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Size</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_size_mb']; ?> MB</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Latest Backup</p>
                            <p class="text-sm font-bold text-gray-900"><?php echo $stats['latest_backup_time'] ?? 'None'; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-cog text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Auto Backup</p>
                            <p class="text-lg font-bold <?php echo $autoBackupSettings['auto_backup_enabled'] ? 'text-green-600' : 'text-red-600'; ?>">
                                <?php echo $autoBackupSettings['auto_backup_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Auto Backup Settings -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-robot mr-2"></i>
                    Auto Backup Configuration
                </h2>
                
                <form method="post" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Main Settings -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-medium text-gray-800">Main Settings</h3>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="auto_backup_enabled" name="auto_backup_enabled" value="1" 
                                       <?php echo $autoBackupSettings['auto_backup_enabled'] ? 'checked' : ''; ?>
                                       class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                                <label for="auto_backup_enabled" class="ml-2 text-sm font-medium text-gray-900">
                                    Enable Auto Backup
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="daily_backup_enabled" name="daily_backup_enabled" value="1"
                                       <?php echo $autoBackupSettings['daily_backup_enabled'] ? 'checked' : ''; ?>
                                       class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                                <label for="daily_backup_enabled" class="ml-2 text-sm font-medium text-gray-900">
                                    Daily Backups (<?php echo $stats['daily_backups']; ?> files)
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="weekly_backup_enabled" name="weekly_backup_enabled" value="1"
                                       <?php echo $autoBackupSettings['weekly_backup_enabled'] ? 'checked' : ''; ?>
                                       class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                                <label for="weekly_backup_enabled" class="ml-2 text-sm font-medium text-gray-900">
                                    Weekly Backups (<?php echo $stats['weekly_backups']; ?> files)
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="monthly_backup_enabled" name="monthly_backup_enabled" value="1"
                                       <?php echo $autoBackupSettings['monthly_backup_enabled'] ? 'checked' : ''; ?>
                                       class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                                <label for="monthly_backup_enabled" class="ml-2 text-sm font-medium text-gray-900">
                                    Monthly Backups (<?php echo $stats['monthly_backups']; ?> files)
                                </label>
                            </div>
                        </div>
                        
                        <!-- Retention Settings -->
                        <div class="space-y-4">
                            <h3 class="text-lg font-medium text-gray-800">Retention Policies</h3>
                            
                            <div>
                                <label for="daily_retention_days" class="block text-sm font-medium text-gray-700">
                                    Daily Backup Retention (days)
                                </label>
                                <input type="number" id="daily_retention_days" name="daily_retention_days" 
                                       value="<?php echo $autoBackupSettings['daily_retention_days']; ?>"
                                       min="1" max="30" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label for="weekly_retention_weeks" class="block text-sm font-medium text-gray-700">
                                    Weekly Backup Retention (weeks)
                                </label>
                                <input type="number" id="weekly_retention_weeks" name="weekly_retention_weeks"
                                       value="<?php echo $autoBackupSettings['weekly_retention_weeks']; ?>"
                                       min="1" max="12" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label for="monthly_retention_months" class="block text-sm font-medium text-gray-700">
                                    Monthly Backup Retention (months)
                                </label>
                                <input type="number" id="monthly_retention_months" name="monthly_retention_months"
                                       value="<?php echo $autoBackupSettings['monthly_retention_months']; ?>"
                                       min="1" max="24" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" name="update_settings" 
                                class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            <i class="fas fa-save mr-2"></i>Update Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Action Buttons -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <!-- Manual Backup -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold text-blue-700 mb-4 flex items-center"><i class="fas fa-plus-circle mr-2"></i>Create Manual Backup</h2>
                    <p class="text-gray-600 mb-4">Create an immediate, full backup of the database.</p>
                    <form method="post">
                        <button type="submit" name="manual_backup" class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition duration-200">
                            <i class="fas fa-download mr-2"></i>Create Backup Now
                        </button>
                    </form>
                </div>
                <!-- Run Auto Cycle -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold text-teal-700 mb-4 flex items-center"><i class="fas fa-robot mr-2"></i>Run Automation Cycle</h2>
                    <p class="text-gray-600 mb-4">Manually trigger the auto-backup and cleanup process.</p>
                    <form method="post">
                        <button type="submit" name="run_auto_cycle" class="w-full bg-teal-500 hover:bg-teal-700 text-white font-bold py-3 px-4 rounded transition duration-200">
                            <i class="fas fa-play-circle mr-2"></i>Run Cycle Now
                        </button>
                    </form>
                </div>
            </div>

            <!-- Restore Section -->
            <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-semibold text-green-700 mb-4 border-b pb-3"><i class="fas fa-undo mr-2"></i>Restore Database</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Restore from List -->
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Restore from an existing backup</h3>
                        <p class="text-gray-600 mb-4 text-sm">Select a backup file from the list below to restore the database.</p>
                        <form method="post" id="restore-form">
                            <select name="restore_file" class="w-full p-2 border rounded mb-4" required>
                                <option value="">Select a backup file...</option>
                                <?php foreach ($backupFiles as $file): ?>
                                    <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="restore" class="w-full bg-green-500 hover:bg-green-700 text-white font-bold py-3 px-4 rounded">
                                <i class="fas fa-undo mr-2"></i>Restore Selected File
                            </button>
                        </form>
                    </div>
                    <!-- Upload & Restore -->
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Restore from an uploaded file</h3>
                        <p class="text-gray-600 mb-4 text-sm">Upload a `.sql.gz` backup file from your computer to restore the database.</p>
                        <form method="post" enctype="multipart/form-data">
                            <input type="file" name="uploaded_backup_file" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 mb-4" required>
                            <button type="submit" name="upload_and_restore" class="w-full bg-purple-500 hover:bg-purple-700 text-white font-bold py-3 px-4 rounded">
                                <i class="fas fa-upload mr-2"></i>Upload & Restore
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Available Backups -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-semibold text-blue-700 mb-4">Available Backups</h2>
                <?php if (empty($backupFiles)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-inbox text-4xl mb-4"></i>
                        <p>No backup files found. Create your first backup above.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Backup File
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Type
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Size
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Created
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($backupFiles as $file): 
                                    $filePath = $backupConfig['backup_dir'] . $file;
                                    $fileSize = file_exists($filePath) ? round(filesize($filePath) / 1024 / 1024, 2) : 0;
                                    $fileTime = file_exists($filePath) ? date('Y-m-d H:i:s', filemtime($filePath)) : 'Unknown';
                                    
                                    // Determine backup type
                                    $backupType = 'Manual';
                                    if (strpos($file, '_daily_') !== false) $backupType = 'Daily';
                                    elseif (strpos($file, '_weekly_') !== false) $backupType = 'Weekly';
                                    elseif (strpos($file, '_monthly_') !== false) $backupType = 'Monthly';
                                    
                                    $isCompressed = pathinfo($file, PATHINFO_EXTENSION) === 'gz';
                                    
                                    $typeColor = [
                                        'Manual' => 'bg-gray-100 text-gray-800',
                                        'Daily' => 'bg-blue-100 text-blue-800',
                                        'Weekly' => 'bg-green-100 text-green-800',
                                        'Monthly' => 'bg-purple-100 text-purple-800'
                                    ][$backupType];
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <i class="fas fa-file-archive mr-2 text-blue-500"></i>
                                            <?php echo htmlspecialchars($file); ?>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $typeColor; ?>">
                                                <?php echo $backupType; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $fileSize; ?> MB
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $fileTime; ?>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-center text-sm font-medium">
                                            <a href="?download_file=<?php echo urlencode($file); ?>" class="text-blue-600 hover:text-blue-900" title="Download this backup">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button class="text-red-600 hover:text-red-900 ml-4" title="Delete this backup" onclick="deleteBackup('<?php echo htmlspecialchars($file); ?>')">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Backup Logs -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-semibold text-blue-700 flex items-center">
                        <i class="fas fa-file-alt mr-2"></i>Backup Logs
                    </h2>
                    <button onclick="refreshLogs()" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                        <i class="fas fa-sync-alt mr-1"></i>Refresh
                    </button>
                </div>
                <div id="backup-logs" class="bg-gray-50 p-4 rounded-lg max-h-96 overflow-y-auto">
                    <div class="text-center text-gray-500">
                        <i class="fas fa-spinner fa-spin mr-2"></i>Loading logs...
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Content: Settings -->
        <div id="content-settings" class="tab-content <?php if ($active_tab !== 'settings') echo 'hidden'; ?>">
            <div class="bg-white rounded-lg shadow-lg p-8">
                <h2 class="text-3xl font-bold text-blue-800 mb-6 border-b pb-4">
                    <i class="fas fa-cogs mr-3"></i>Automation Settings
                </h2>
                <form method="post">
                    <div class="space-y-8">
                        <!-- Auto Backup Toggle -->
                        <div class="flex items-center justify-between p-5 border rounded-xl bg-gray-50">
                            <div>
                                <label for="auto_backup_enabled" class="font-semibold text-lg text-gray-800">Enable Automatic Backups</label>
                                <p class="text-sm text-gray-600 mt-1">Master switch to turn all automated backups on or off.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="auto_backup_enabled" name="auto_backup_enabled" class="sr-only peer" <?php echo ($backupConfig['auto_backup_enabled'] ?? true) ? 'checked' : ''; ?>>
                                <div class="w-14 h-8 bg-gray-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[4px] after:left-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                        </div>
                        
                        <!-- Schedules & Retention -->
                        <div class="grid md:grid-cols-3 gap-6">
                            <!-- Daily -->
                            <div class="bg-blue-50 p-6 rounded-lg border border-blue-200">
                                <div class="flex items-center mb-4">
                                    <input type="checkbox" id="daily_backup_enabled" name="daily_backup_enabled" class="h-5 w-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500" <?php echo ($backupConfig['daily_backup_enabled'] ?? true) ? 'checked' : ''; ?>>
                                    <label for="daily_backup_enabled" class="ml-3 text-lg font-medium text-gray-900">Daily Backups</label>
                                </div>
                                <label for="daily_retention_days" class="block text-sm font-medium text-gray-700">Retention (days)</label>
                                <input type="number" name="daily_retention_days" id="daily_retention_days" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" value="<?php echo htmlspecialchars($backupConfig['daily_retention_days'] ?? 7); ?>">
                            </div>
                            <!-- Weekly -->
                            <div class="bg-green-50 p-6 rounded-lg border border-green-200">
                                <div class="flex items-center mb-4">
                                    <input type="checkbox" id="weekly_backup_enabled" name="weekly_backup_enabled" class="h-5 w-5 text-green-600 border-gray-300 rounded focus:ring-green-500" <?php echo ($backupConfig['weekly_backup_enabled'] ?? true) ? 'checked' : ''; ?>>
                                    <label for="weekly_backup_enabled" class="ml-3 text-lg font-medium text-gray-900">Weekly Backups</label>
                                </div>
                                <label for="weekly_retention_weeks" class="block text-sm font-medium text-gray-700">Retention (weeks)</label>
                                <input type="number" name="weekly_retention_weeks" id="weekly_retention_weeks" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" value="<?php echo htmlspecialchars($backupConfig['weekly_retention_weeks'] ?? 4); ?>">
                            </div>
                            <!-- Monthly -->
                            <div class="bg-purple-50 p-6 rounded-lg border border-purple-200">
                                <div class="flex items-center mb-4">
                                    <input type="checkbox" id="monthly_backup_enabled" name="monthly_backup_enabled" class="h-5 w-5 text-purple-600 border-gray-300 rounded focus:ring-purple-500" <?php echo ($backupConfig['monthly_backup_enabled'] ?? true) ? 'checked' : ''; ?>>
                                    <label for="monthly_backup_enabled" class="ml-3 text-lg font-medium text-gray-900">Monthly Backups</label>
                                </div>
                                <label for="monthly_retention_months" class="block text-sm font-medium text-gray-700">Retention (months)</label>
                                <input type="number" name="monthly_retention_months" id="monthly_retention_months" class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" value="<?php echo htmlspecialchars($backupConfig['monthly_retention_months'] ?? 12); ?>">
                            </div>
                        </div>
                    </div>
                    <!-- Save Button -->
                    <div class="mt-10 pt-6 border-t text-right">
                        <button type="submit" name="save_settings" class="inline-flex justify-center py-3 px-8 border border-transparent shadow-sm text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i class="fas fa-save mr-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Progress tracking variables
        let backupProgressInterval;
        let restoreProgressInterval;

        // Form submission handlers with progress tracking
        document.getElementById('backup-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            swal({
                title: "Create Backup?",
                text: "This will create a complete backup of the database including all data.",
                icon: "info",
                buttons: true,
                dangerMode: false,
            }).then((willBackup) => {
                if (willBackup) {
                    showBackupProgress();
                    this.submit();
                }
            });
        });

        document.getElementById('restore-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selectedFile = this.querySelector('select[name="restore_file"]').value;
            if (!selectedFile) {
                swal("Error", "Please select a backup file to restore.", "error");
                return;
            }
            
            swal({
                title: "Are you sure?",
                text: "This will completely replace your current database with the backup data. All current data will be lost!",
                icon: "warning",
                buttons: true,
                dangerMode: true,
            }).then((willRestore) => {
                if (willRestore) {
                    showRestoreProgress();
                    this.submit();
                }
            });
        });

        // Progress display functions
        function showBackupProgress() {
            document.getElementById('backup-progress').classList.remove('hidden');
            startBackupProgressTracking();
        }

        function showRestoreProgress() {
            document.getElementById('restore-progress').classList.remove('hidden');
            startRestoreProgressTracking();
        }

        function hideBackupProgress() {
            document.getElementById('backup-progress').classList.add('hidden');
            if (backupProgressInterval) {
                clearInterval(backupProgressInterval);
            }
        }

        function hideRestoreProgress() {
            document.getElementById('restore-progress').classList.add('hidden');
            if (restoreProgressInterval) {
                clearInterval(restoreProgressInterval);
            }
        }

        // Progress tracking functions
        function startBackupProgressTracking() {
            backupProgressInterval = setInterval(() => {
                fetch('?action=backup_progress')
                    .then(response => response.json())
                    .then(data => {
                        updateBackupProgress(data.status);
                    })
                    .catch(error => {
                        console.error('Error fetching backup progress:', error);
                    });
            }, 1000);
        }

        function startRestoreProgressTracking() {
            restoreProgressInterval = setInterval(() => {
                fetch('?action=restore_progress')
                    .then(response => response.json())
                    .then(data => {
                        updateRestoreProgress(data.status);
                    })
                    .catch(error => {
                        console.error('Error fetching restore progress:', error);
                    });
            }, 1000);
        }

        function updateBackupProgress(status) {
            const progressBar = document.getElementById('backup-progress-bar');
            const progressText = document.getElementById('backup-progress-text');
            
            if (status.percentage !== undefined) {
                progressBar.style.width = status.percentage + '%';
            }
            
            let message = 'Processing...';
            switch (status.stage) {
                case 'processing_table':
                    message = `Processing table: ${status.table} (${status.current}/${status.total})`;
                    break;
                case 'completed':
                    message = 'Backup completed successfully!';
                    setTimeout(hideBackupProgress, 2000);
                    break;
                case 'error':
                    message = 'Error: ' + (status.message || 'Unknown error');
                    progressBar.classList.add('bg-red-600');
                    setTimeout(hideBackupProgress, 5000);
                    break;
                default:
                    message = status.message || 'Processing...';
            }
            
            progressText.textContent = message;
        }

        function updateRestoreProgress(status) {
            const progressBar = document.getElementById('restore-progress-bar');
            const progressText = document.getElementById('restore-progress-text');
            
            if (status.percentage !== undefined) {
                progressBar.style.width = status.percentage + '%';
            }
            
            let message = 'Processing...';
            switch (status.stage) {
                case 'preparing':
                case 'parsing':
                case 'executing':
                case 'finalizing':
                    message = status.message || 'Processing...';
                    break;
                case 'completed':
                    message = 'Restore completed successfully!';
                    setTimeout(hideRestoreProgress, 2000);
                    break;
                case 'error':
                    message = 'Error: ' + (status.message || 'Unknown error');
                    progressBar.classList.add('bg-red-600');
                    setTimeout(hideRestoreProgress, 5000);
                    break;
                default:
                    message = status.message || 'Processing...';
            }
            
            progressText.textContent = message;
        }

        // Log management
        function refreshLogs() {
            const logsContainer = document.getElementById('backup-logs');
            logsContainer.innerHTML = '<div class="text-center text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Loading logs...</div>';
            
            fetch('?action=get_logs')
                .then(response => response.json())
                .then(data => {
                    displayLogs(data.logs);
                })
                .catch(error => {
                    console.error('Error fetching logs:', error);
                    logsContainer.innerHTML = '<div class="text-red-500">Error loading logs</div>';
                });
        }

        function displayLogs(logs) {
            const logsContainer = document.getElementById('backup-logs');
            
            if (logs.length === 0) {
                logsContainer.innerHTML = '<div class="text-gray-500">No logs available</div>';
                return;
            }
            
            let logHtml = '';
            logs.forEach(log => {
                let logClass = 'log-info';
                if (log.includes('[SUCCESS]')) logClass = 'log-success';
                else if (log.includes('[ERROR]') || log.includes('[CRITICAL]')) logClass = 'log-error';
                else if (log.includes('[WARNING]')) logClass = 'log-warning';
                
                logHtml += `<div class="log-entry ${logClass} py-1 border-b border-gray-200">${escapeHtml(log)}</div>`;
            });
            
            logsContainer.innerHTML = logHtml;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Backup file actions
        function downloadBackup(filename) {
            window.location.href = `download_backup.php?file=${encodeURIComponent(filename)}`;
        }

        function deleteBackup(fileName) {
            swal({
                title: "Are you sure?",
                text: `You are about to delete the backup file: ${fileName}. This action cannot be undone.`,
                icon: "warning",
                buttons: ["Cancel", "Yes, delete it!"],
                dangerMode: true,
            }).then((willDelete) => {
                if (willDelete) {
                    const formData = new FormData();
                    formData.append('delete_file', fileName);
                    
                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if(response.ok) {
                            swal("Deleted!", "The backup file has been deleted.", "success")
                            .then(() => window.location.reload());
                        } else {
                            swal("Error!", "Failed to delete the file.", "error");
                        }
                    });
                }
            });
        }

        // Auto-refresh page if backup/restore is in progress
        function checkForActiveOperations() {
            // Check if there are any progress indicators visible
            const backupVisible = !document.getElementById('backup-progress').classList.contains('hidden');
            const restoreVisible = !document.getElementById('restore-progress').classList.contains('hidden');
            
            if (backupVisible) {
                startBackupProgressTracking();
            }
            if (restoreVisible) {
                startRestoreProgressTracking();
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Load logs on page load
            refreshLogs();
            
            // Check for active operations
            checkForActiveOperations();
            
            // Auto-refresh logs every 30 seconds
            setInterval(refreshLogs, 30000);
        });

        // Handle page visibility changes
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // Page became visible, refresh logs
                refreshLogs();
                checkForActiveOperations();
            }
        });

        // Settings form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            if (e.target.querySelector('button[name="update_settings"]')) {
                const autoEnabled = document.getElementById('auto_backup_enabled').checked;
                const dailyEnabled = document.getElementById('daily_backup_enabled').checked;
                const weeklyEnabled = document.getElementById('weekly_backup_enabled').checked;
                const monthlyEnabled = document.getElementById('monthly_backup_enabled').checked;
                
                if (autoEnabled && !dailyEnabled && !weeklyEnabled && !monthlyEnabled) {
                    e.preventDefault();
                    swal("Invalid Settings", "If auto backup is enabled, at least one backup type (daily, weekly, or monthly) must be enabled.", "error");
                    return false;
                }
            }
        });

        // Tooltips for better UX
        function addTooltips() {
            const tooltips = {
                'auto_backup_enabled': 'Enable or disable automatic backup system',
                'daily_backup_enabled': 'Create automatic backups daily',
                'weekly_backup_enabled': 'Create automatic backups weekly (Sundays)',
                'monthly_backup_enabled': 'Create automatic backups monthly (1st day)',
                'include_data': 'Include all table data in backup (recommended for complete backup)'
            };
            
            Object.keys(tooltips).forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.title = tooltips[id];
                }
            });
        }

        // Call tooltip function
        addTooltips();

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+B for backup
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                document.querySelector('button[name="manual_backup"]').click();
            }
            
            // Ctrl+R for refresh logs
            if (e.ctrlKey && e.key === 'r' && e.target.closest('#backup-logs')) {
                e.preventDefault();
                refreshLogs();
            }
        });

        // Auto-save settings on change (with debounce)
        let settingsTimeout;
        document.querySelectorAll('input[type="checkbox"], input[type="number"]').forEach(input => {
            if (input.closest('form').querySelector('button[name="update_settings"]')) {
                input.addEventListener('change', function() {
                    clearTimeout(settingsTimeout);
                    settingsTimeout = setTimeout(() => {
                        // Auto-save could be implemented here
                        console.log('Settings changed - consider auto-save');
                    }, 1000);
                });
            }
        });

        // JS to manage active tab styles based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const activeTab = '<?php echo $active_tab; ?>';
            const tabLink = document.getElementById(`tab-${activeTab}`);
            if(tabLink) {
                tabLink.classList.add('active-tab', 'text-blue-700', 'bg-blue-100');
            }
        });
    </script>
</body>
</html>

<?php
// Handle backup file deletion
if (isset($_POST['delete_file'])) {
    $fileName = $_POST['delete_file'];
    $filePath = $backupConfig['backup_dir'] . $fileName;
    
    if (file_exists($filePath) && strpos($fileName, 'barangay_backup_') === 0) {
        if (unlink($filePath)) {
            logBackupActivity("Backup file deleted", 'INFO', ['filename' => $fileName]);
            $_SESSION['backup_message'] = "Backup file '{$fileName}' deleted successfully.";
            $_SESSION['backup_message_type'] = 'success';
        } else {
            logBackupActivity("Failed to delete backup file", 'ERROR', ['filename' => $fileName]);
            $_SESSION['backup_message'] = "Failed to delete backup file '{$fileName}'.";
            $_SESSION['backup_message_type'] = 'error';
        }
    } else {
        logBackupActivity("Invalid backup file deletion attempt", 'WARNING', ['filename' => $fileName]);
        $_SESSION['backup_message'] = "Invalid backup file specified.";
        $_SESSION['backup_message_type'] = 'error';
    }
    
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}
?>