<?php
// functions/logout.php
// Make sure no output has been sent yet
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

session_start();
require __DIR__ . '/../config/dbconn.php'; // $pdo

/**
 * Insert an entry into audit_trails.
 */
function logAuditTrail(PDO $pdo, int $user_id, string $action, string $table_name = null, $record_id = null, string $description = '') 
{
    $stmt = $pdo->prepare("
        INSERT INTO audit_trails 
          (user_id, action, table_name, record_id, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $user_id,
        $action,
        $table_name,
        $record_id,
        $description,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

if (!empty($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
    try {
        logAuditTrail(
            $pdo,
            $user_id,
            "LOGOUT",
            "users",
            $user_id,
            "User logged out of the system"
        );
    } catch (PDOException $e) {
        // Log error but continue with logout
        error_log("Failed to log audit trail: " . $e->getMessage());
    }
}

// Destroy everything
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
session_destroy();

// Redirect back to public index
header("Location: ../pages/login.php");
exit;