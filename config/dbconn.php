<?php
// ../config/dbconn.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/env.php';

// Load environment variables
Env::load();

// Database configuration using environment variables
$host = Env::get('DB_HOST', 'localhost');
$dbname = Env::get('DB_NAME', 'ibarangay_db');
$username = Env::get('DB_USER', 'root');
$password = Env::get('DB_PASS', '');

// DSN (Data Source Name)
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

// PDO options for better error handling and performance
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Establish database connection with exception handling
try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Log error for administrator (not visible to users)
    error_log("Database Connection Error: " . $e->getMessage());
    
    // Show detailed error for debugging
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px;'>";
    echo "<h3>Database Connection Error</h3>";
    echo "<p><strong>Error Message:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>Error Code:</strong> " . $e->getCode() . "</p>";
    echo "</div>";
    die();
}

// Check if function exists before declaring
if (!function_exists('validateInput')) {
    function validateInput($data, $type = 'string', $options = []) {
        switch ($type) {
            case 'int':
                return filter_var($data, FILTER_VALIDATE_INT, $options) !== false;
            case 'email':
                return filter_var($data, FILTER_VALIDATE_EMAIL) !== false;
            case 'date':
                $date = DateTime::createFromFormat('Y-m-d', $data);
                return $date && $date->format('Y-m-d') === $data;
            case 'phone':
                // Basic Philippine phone number validation
                return preg_match('/^(09|\+639)\d{9}$/', $data);
            case 'string':
            default:
                return is_string($data) && strlen(trim($data)) > 0;
        }
    }
}

// Check if function exists before declaring
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data, $type = 'string') {
        switch ($type) {
            case 'int':
                return filter_var($data, FILTER_SANITIZE_NUMBER_INT);
            case 'email':
                return filter_var($data, FILTER_SANITIZE_EMAIL);
            case 'string':
            default:
                $data = trim($data);
                $data = stripslashes($data);
                $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
                return $data;
        }
    }
}
?>