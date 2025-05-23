<?php
/**
 * Database Initialization Script
 * 
 * This script establishes database connection and initializes the database schema
 * if needed. Run this script only once to set up the database.
 */

// Database connection parameters
$host = 'localhost';
$username = 'root';
$password = '';
$charset = 'utf8mb4';

try {
    // Connect without selecting a database first
    $pdo = new PDO("mysql:host=$host;charset=$charset", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "<h1>iBarangay Database Initialization</h1>";
    
    // --- DROP AND CREATE DATABASE ---
    $pdo->exec("DROP DATABASE IF EXISTS barangay");
    $pdo->exec("CREATE DATABASE barangay");
    $pdo->exec("USE barangay");
    
    echo "<p>Database created successfully.</p>";
    
    // --- EXECUTE THE SCHEMA SQL ---
    $sql = file_get_contents('schema.sql');
    
    // Split statements by semicolons (this is a simple approach and may not work for all SQL)
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "<p>Schema created successfully.</p>";
    
    // --- CREATE ADMIN USER ---
    $email = 'admin@ibarangay.com';
    $password = password_hash('password123', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, role_id, barangay_id, first_name, last_name, gender, email_verified_at, is_active) 
        VALUES (?, ?, 2, 1, 'System', 'Administrator', 'Male', NOW(), TRUE)
    ");
    $stmt->execute([$email, $password]);
    
    $userId = $pdo->lastInsertId();
    
    // Insert admin into persons table
    $stmt = $pdo->prepare("
        INSERT INTO persons (user_id, first_name, last_name, birth_date, gender, civil_status) 
        VALUES (?, 'System', 'Administrator', '1990-01-01', 'Male', 'Single')
    ");
    $stmt->execute([$userId]);
    
    // Add admin role
    $stmt = $pdo->prepare("
        INSERT INTO user_roles (user_id, role_id, barangay_id, is_active) 
        VALUES (?, 2, 1, TRUE)
    ");
    $stmt->execute([$userId]);
    
    echo "<p>Admin user created successfully.</p>";
    echo "<p>Email: $email</p>";
    echo "<p>Password: password123</p>";
    
    // Success message
    echo "<div style='padding: 20px; background-color: #d4edda; color: #155724; margin-top: 20px; border-radius: 5px;'>
        <h2>Database initialization complete!</h2>
        <p>You can now log in to the iBarangay system using the admin credentials above.</p>
        <p><a href='../index.php'>Go to Login Page</a></p>
    </div>";
    
} catch (PDOException $e) {
    echo "<div style='padding: 20px; background-color: #f8d7da; color: #721c24; margin-top: 20px; border-radius: 5px;'>
        <h2>Database initialization failed</h2>
        <p>Error: " . htmlspecialchars($e->getMessage()) . "</p>
    </div>";
} 