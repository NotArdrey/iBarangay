<?php
require "../config/dbconn.php";

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['import_error'] = "The import feature is currently disabled.";
header("Location: ../pages/census_records.php");
exit; 