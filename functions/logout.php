<?php
// functions/logout.php
// Make sure no output has been sent yet
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

session_start();
require __DIR__ . '/../config/dbconn.php'; // $pdo

/**
 * Insert an entry into AuditTrail.
 */
function logAuditTrail(PDO $pdo, int $user_id, string $action, string $table_name = null, $record_id = null, string $description = '')
{
    $stmt = $pdo->prepare("
        INSERT INTO AuditTrail 
          (admin_user_id, action, table_name, record_id, description)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $action,
        $table_name,
        $record_id,
        $description
    ]);
}

if (!empty($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
    logAuditTrail(
        $pdo,
        $user_id,
        "LOGOUT",
        "Users",
        $user_id,
        "User clicked logout"
    );
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
header("Location: ../pages/index.php");
exit;