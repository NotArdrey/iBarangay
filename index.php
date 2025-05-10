<?php
// index.php - Redirects to the shop page with fallback

// Check if headers have already been sent
if (!headers_sent()) {
    header("Location: ../pages/login.php");
    exit;
} else {
    // Fallback for when headers have been sent
    echo '<script>window.location.href = "../pages/login.php";</script>';
    echo '<meta http-equiv="refresh" content="0;url=../pages/login.php">';
    echo '<p>Redirecting to <a href="../pages/login.php">login page</a>...</p>';
    exit;
}