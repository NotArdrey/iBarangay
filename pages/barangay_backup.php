<?php
require_once '../config/dbconn.php';


// Backup configuration
$backupConfig = [
    'backup_dir' => __DIR__ . '/../backups/',
    'auto_backup_enabled' => true,
    'daily_backup_enabled' => true,
    'weekly_backup_enabled' => true,
    'monthly_backup_enabled' => true,
    'daily_retention_days' => 7,      // Keep daily backups for 7 days
    'weekly_retention_weeks' => 4,    // Keep weekly backups for 4 weeks
    'monthly_retention_months' => 12, // Keep monthly backups for 12 months
    'backup_time_limit' => 300,       // 5 minutes timeout for backup operations
    'log_file' => __DIR__ . '/../logs/backup.log'
];

// Ensure directories exist
if (!is_dir($backupConfig['backup_dir'])) {
    mkdir($backupConfig['backup_dir'], 0755, true);
}
if (!is_dir(dirname($backupConfig['log_file']))) {
    mkdir(dirname($backupConfig['log_file']), 0755, true);
}

/**
 * Log backup activities
 */
function logBackupActivity($message, $level = 'INFO') {
    global $backupConfig;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($backupConfig['log_file'], $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Create a database backup with enhanced features
 */
function createBackup($pdo, $backupType = 'manual') {
    global $backupConfig;
    
    set_time_limit($backupConfig['backup_time_limit']);
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupConfig['backup_dir'] . "barangay_backup_{$backupType}_{$timestamp}.sql";
    
    try {
        $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tables)) {
            throw new Exception("No tables found in database");
        }
        
        $sql = "-- Barangay Database Backup\n";
        $sql .= "-- Database: {$dbName}\n";
        $sql .= "-- Backup Type: {$backupType}\n";
        $sql .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $totalRows = 0;
        foreach ($tables as $table) {
            // Table structure
            $stmt2 = $pdo->query("SHOW CREATE TABLE `$table`");
            $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Table structure for table `$table`\n";
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $row2['Create Table'] . ";\n\n";

            // Table data
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rowCount = $stmt->rowCount();
            if ($rowCount > 0) {
                $totalRows += $rowCount;
                $sql .= "--\n";
                $sql .= "-- Dumping data for table `$table`\n";
                $sql .= "--\n\n";
                
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $fields = array_keys($rows[0]);
                $sql .= "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES\n";
                
                $firstRow = true;
                foreach ($rows as $row) {
                    $sql .= $firstRow ? '' : ",\n";
                    $sql .= "(";
                    
                    $firstField = true;
                    foreach ($row as $field) {
                        $sql .= $firstField ? '' : ', ';
                        if ($field === null) {
                            $sql .= "NULL";
                        } else {
                            $sql .= $pdo->quote($field);
                        }
                        $firstField = false;
                    }
                    $sql .= ")";
                    $firstRow = false;
                }
                $sql .= ";\n\n";
            }
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $sql .= "-- End of backup. Total rows exported: {$totalRows}\n";
        
        if (file_put_contents($backupFile, $sql) === false) {
            throw new Exception("Failed to write backup file");
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
        // Clean up partial backup file
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
        $sql = file_get_contents($backupFilePath);
        if ($sql === false) {
            throw new Exception("Failed to read backup file");
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
        $pdo->beginTransaction();
        
        $result = $pdo->exec($sql);
        
        $pdo->commit();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");

        logBackupActivity("Database restored successfully from " . basename($backupFilePath), 'SUCCESS');
        
        return ['success' => true, 'queries_executed' => $result !== false];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        logBackupActivity("Restore failed: " . $e->getMessage(), 'ERROR');
        throw $e;
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
    
    try {
        // Clean up daily backups
        $dailyBackups = glob($backupDir . "barangay_backup_daily_*.sql");
        foreach ($dailyBackups as $file) {
            $fileTime = filemtime($file);
            $daysOld = (time() - $fileTime) / (24 * 60 * 60);
            if ($daysOld >= $backupConfig['daily_retention_days']) { // changed condition: ">=" instead of ">"
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedFiles++;
                    $totalSizeFreed += $fileSize;
                }
            }
        }
        
        // Clean up weekly backups
        $weeklyBackups = glob($backupDir . "barangay_backup_weekly_*.sql");
        foreach ($weeklyBackups as $file) {
            $fileTime = filemtime($file);
            $weeksOld = (time() - $fileTime) / (7 * 24 * 60 * 60);
            if ($weeksOld >= $backupConfig['weekly_retention_weeks']) { // changed condition: ">=" instead of ">"
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedFiles++;
                    $totalSizeFreed += $fileSize;
                }
            }
        }
        
        // Clean up monthly backups
        $monthlyBackups = glob($backupDir . "barangay_backup_monthly_*.sql");
        foreach ($monthlyBackups as $file) {
            $fileTime = filemtime($file);
            $monthsOld = (time() - $fileTime) / (30 * 24 * 60 * 60);
            if ($monthsOld >= $backupConfig['monthly_retention_months']) { // changed condition: ">=" instead of ">"
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $deletedFiles++;
                    $totalSizeFreed += $fileSize;
                }
            }
        }
        
        if ($deletedFiles > 0) {
            $sizeMB = round($totalSizeFreed / 1024 / 1024, 2);
            logBackupActivity("Cleanup completed: {$deletedFiles} old backup files removed, {$sizeMB} MB freed", 'INFO');
        }
        
        return $deletedFiles;
        
    } catch (Exception $e) {
        logBackupActivity("Cleanup failed: " . $e->getMessage(), 'ERROR');
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
    $now = time();
    
    switch ($backupType) {
        case 'daily':
            if (!$backupConfig['daily_backup_enabled']) return false;
            $pattern = $backupDir . "barangay_backup_daily_" . date('Y-m-d') . "_*.sql";
            $todayBackups = glob($pattern);
            return empty($todayBackups);
            
        case 'weekly':
            if (!$backupConfig['weekly_backup_enabled']) return false;
            // Run weekly backup on Sundays
            if (date('w') != 0) return false;
            $pattern = $backupDir . "barangay_backup_weekly_" . date('Y-m-d', strtotime('last Sunday')) . "_*.sql";
            $weeklyBackups = glob($pattern);
            return empty($weeklyBackups);
            
        case 'monthly':
            if (!$backupConfig['monthly_backup_enabled']) return false;
            // Run monthly backup on the 1st day of month
            if (date('j') != 1) return false;
            $pattern = $backupDir . "barangay_backup_monthly_" . date('Y-m') . "-01_*.sql";
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
            logBackupActivity("Auto daily backup failed: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Weekly backup
    if (shouldRunAutoBackup('weekly')) {
        try {
            $result = createBackup($pdo, 'weekly');
            $backupsCreated[] = ['type' => 'weekly', 'result' => $result];
        } catch (Exception $e) {
            logBackupActivity("Auto weekly backup failed: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Monthly backup
    if (shouldRunAutoBackup('monthly')) {
        try {
            $result = createBackup($pdo, 'monthly');
            $backupsCreated[] = ['type' => 'monthly', 'result' => $result];
        } catch (Exception $e) {
            logBackupActivity("Auto monthly backup failed: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Always cleanup old backups (change applied)
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
    
    $allBackups = glob($backupDir . "barangay_backup_*.sql");
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
        if (strpos(basename($file), '_daily_') !== false) {
            $stats['daily_backups']++;
        } elseif (strpos(basename($file), '_weekly_') !== false) {
            $stats['weekly_backups']++;
        } elseif (strpos(basename($file), '_monthly_') !== false) {
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

// Comment out auto backup unless you intentionally want it on each GET request
// $autoBackupResults = runAutoBackups($pdo);

// Handle manual backup or restore actions
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['manual_backup'])) {
        try {
            $result = createBackup($pdo, 'manual');
            $message = "Manual backup created successfully: " . $result['filename'] . 
                      " (Size: " . $result['size'] . " MB, Rows: " . $result['rows'] . ")";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Manual backup failed: " . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if (isset($_POST['restore'])) {
        $fileName = $_POST['restore_file'] ?? '';
        if ($fileName) {
            $backupFilePath = $backupConfig['backup_dir'] . $fileName;
            try {
                $result = restoreBackup($pdo, $backupFilePath);
                $message = "Database restored successfully from " . $fileName . 
                          " (" . $result['queries_executed'] . " queries executed)";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Restore failed: " . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = "No backup file specified for restoration.";
            $messageType = 'error';
        }
    }
    
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
$backupFiles = glob($backupConfig['backup_dir'] . "barangay_backup_*.sql");
$backupFiles = array_map('basename', $backupFiles);
rsort($backupFiles); // Sort newest first

$stats = getBackupStats();

// Show auto backup results
if (!empty($autoBackupResults)) {
    foreach ($autoBackupResults as $autoBackup) {
        $type = $autoBackup['type'];
        $result = $autoBackup['result'];
        $autoMessage = "Automatic {$type} backup created: " . $result['filename'] . 
                      " (Size: " . $result['size'] . " MB)";
        // You might want to store this in session to show it properly
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Barangay Enhanced Backup & Restore System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Include SweetAlert -->
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-6 max-w-6xl">
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h1 class="text-4xl font-bold text-blue-800 mb-2 flex items-center">
                <i class="fas fa-database mr-3"></i>
                Barangay Enhanced Backup & Restore System
            </h1>
            <p class="text-gray-600">Automated database backup and restore with advanced features</p>
        </div>

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
                        <p class="text-lg font-bold <?php echo $backupConfig['auto_backup_enabled'] ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $backupConfig['auto_backup_enabled'] ? 'Enabled' : 'Disabled'; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auto Backup Status -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-8">
            <h2 class="text-2xl font-semibold text-blue-700 mb-4 flex items-center">
                <i class="fas fa-robot mr-2"></i>
                Automatic Backup Status
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="border rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-2">
                        <i class="fas fa-calendar-day mr-2 text-blue-500"></i>Daily Backups
                    </h3>
                    <p class="text-sm text-gray-600">Status: 
                        <span class="<?php echo $backupConfig['daily_backup_enabled'] ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $backupConfig['daily_backup_enabled'] ? 'Enabled' : 'Disabled'; ?>
                        </span>
                    </p>
                    <p class="text-sm text-gray-600">Retention: <?php echo $backupConfig['daily_retention_days']; ?> days</p>
                    <p class="text-sm text-gray-600">Count: <?php echo $stats['daily_backups']; ?> files</p>
                </div>
                
                <div class="border rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-2">
                        <i class="fas fa-calendar-week mr-2 text-green-500"></i>Weekly Backups
                    </h3>
                    <p class="text-sm text-gray-600">Status: 
                        <span class="<?php echo $backupConfig['weekly_backup_enabled'] ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $backupConfig['weekly_backup_enabled'] ? 'Enabled' : 'Disabled'; ?>
                        </span>
                    </p>
                    <p class="text-sm text-gray-600">Retention: <?php echo $backupConfig['weekly_retention_weeks']; ?> weeks</p>
                    <p class="text-sm text-gray-600">Count: <?php echo $stats['weekly_backups']; ?> files</p>
                </div>
                
                <div class="border rounded-lg p-4">
                    <h3 class="font-semibold text-gray-800 mb-2">
                        <i class="fas fa-calendar-alt mr-2 text-purple-500"></i>Monthly Backups
                    </h3>
                    <p class="text-sm text-gray-600">Status: 
                        <span class="<?php echo $backupConfig['monthly_backup_enabled'] ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $backupConfig['monthly_backup_enabled'] ? 'Enabled' : 'Disabled'; ?>
                        </span>
                    </p>
                    <p class="text-sm text-gray-600">Retention: <?php echo $backupConfig['monthly_retention_months']; ?> months</p>
                    <p class="text-sm text-gray-600">Count: <?php echo $stats['monthly_backups']; ?> files</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Manual Backup -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold text-blue-700 mb-4 flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i>Create Manual Backup
                </h2>
                <p class="text-gray-600 mb-4">Create an immediate backup of the database.</p>
                <form method="post">
                    <button type="submit" name="manual_backup" 
                            class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded transition duration-200">
                        <i class="fas fa-download mr-2"></i>Create Backup Now
                    </button>
                </form>
            </div>
            <!-- Restore Backup -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold text-green-700 mb-4 flex items-center">
                    <i class="fas fa-upload mr-2"></i>Restore Database
                </h2>
                <p class="text-gray-600 mb-4">Restore database from a backup file.</p>
                <form method="post" id="restore-form">
                    <select name="restore_file" class="w-full p-2 border rounded mb-4" required>
                        <option value="">Select backup file...</option>
                        <?php foreach ($backupFiles as $file): ?>
                            <option value="<?php echo htmlspecialchars($file); ?>">
                                <?php echo htmlspecialchars($file); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="restore" 
                            class="w-full bg-green-500 hover:bg-green-700 text-white font-bold py-3 px-4 rounded transition duration-200">
                        <i class="fas fa-undo mr-2"></i>Restore Database
                    </button>
                </form>
            </div>
            <!-- Cleanup -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold text-orange-700 mb-4 flex items-center">
                    <i class="fas fa-broom mr-2"></i>Cleanup Old Backups
                </h2>
                <p class="text-gray-600 mb-4">Remove old backup files based on retention policy.</p>
                <form method="post">
                    <button type="submit" name="cleanup" 
                            class="w-full bg-orange-500 hover:bg-orange-700 text-white font-bold py-3 px-4 rounded transition duration-200">
                        <i class="fas fa-trash-alt mr-2"></i>Cleanup Now
                    </button>
                </form>
            </div>
        </div>

        <!-- Available Backups -->
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-2xl font-semibold text-blue-700 mb-4 flex items-center">
                <i class="fas fa-list mr-2"></i>Available Backups (<?php echo count($backupFiles); ?>)
            </h2>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="mt-8 text-center text-gray-500 text-sm">
            <p>Enhanced Barangay Backup System with Automated Scheduling & Retention Management</p>
            <p>Last page load: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
    <script>
        // Auto-refresh page every 5 minutes to check for new auto backups
        setTimeout(function() {
            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        }, 300000);

        // Use SweetAlert for restore confirmation
        document.getElementById('restore-form').addEventListener('submit', function (e) {
            e.preventDefault(); // Prevent the form from submitting immediately
            
            const restoreFile = this.elements['restore_file'].value;
            if (!restoreFile) {
                swal("No File Selected", "Please select a backup file to restore.", "warning");
                return;
            }

            swal({
                title: "Are you sure?",
                text: "This will completely replace your current database with the backup data. All current data will be lost.",
                icon: "warning",
                buttons: ["Cancel", "Restore Now"],
                dangerMode: true,
            }).then((willRestore) => {
                if (willRestore) {
                    // If confirmed, create a hidden input to signify the restore action and submit the form
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'restore';
                    hiddenInput.value = 'true';
                    this.appendChild(hiddenInput);
                    this.submit();
                }
            });
        });

        // Use SweetAlert for cleanup confirmation
        document.querySelectorAll('button[name="cleanup"]').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                swal({
                    title: "Are you sure?",
                    text: "This will delete old backup files according to the retention policy. Continue?",
                    icon: "warning",
                    buttons: true,
                    dangerMode: true,
                }).then((willCleanup) => {
                    if (willCleanup) {
                        this.form.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>