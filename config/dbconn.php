<?php
// ../config/dbconn.php
$host = 'localhost';
$dbname = 'barangay';
$username = 'root';
$db_password = 'password';

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $username, $db_password, $options);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
