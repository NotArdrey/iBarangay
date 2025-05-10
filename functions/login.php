<?php
// functions/index.php

// 1) Allow pop-ups under the same origin
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

session_start();
require __DIR__ . "/../config/dbconn.php";

/**
 * Insert an audit record
 */
function logAuditTrail(PDO $pdo, int $user_id, string $action, ?string $table_name = null, ?int $record_id = null, string $description = '')
{
    $stmt = $pdo->prepare(
        "INSERT INTO AuditTrail 
         (admin_user_id, action, table_name, record_id, description) 
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$user_id, $action, $table_name, $record_id, $description]);
}

/**
 * Map a role to its dashboard URL
 */
function getDashboardUrl(int $role_id): string
{
    switch ($role_id) {
        case 1:
            return '../pages/programmer_admin.php';
        case 2:
            return '../pages/super_admin.php';
        case 3:
        case 4:
        case 5:
        case 6:
        case 7:
            return '../pages/barangay_admin_dashboard.php';
        case 8:
        default:
            return '../pages/user_dashboard.php';
    }
}

/**
 * Force profile completion for residents with no first name
 */
function postLoginRedirect(int $role_id, ?string $first_name): string
{
    // trim and check for null/empty
    if ($role_id === 8 && (!isset($first_name) || trim($first_name) === '')) {
        return '../pages/complete_profile.php';
    }
    return getDashboardUrl($role_id);
}

/**
 * TRADITIONAL EMAIL/PASSWORD LOGIN
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['email'], $_POST['password'])
) {
    $email    = trim($_POST['email']);
    $password = $_POST['password'];

    try {
        // fetch user + hash + verification + first_name
        $stmt = $pdo->prepare("
            SELECT user_id, email, password_hash, isverify, is_active, role_id, first_name
            FROM Users
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            throw new Exception("Invalid credentials");
        }
        if ($user['isverify'] !== 'yes' || $user['is_active'] !== 'yes') {
            throw new Exception("Account not verified or inactive");
        }

        session_regenerate_id(true);

        // normalize first_name to empty string if null
        $firstName = $user['first_name'] ?? '';

        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role_id']    = $user['role_id'];
        $_SESSION['first_name'] = $firstName;

        // if barangay admin, also look up barangay_id/name
        if (in_array($user['role_id'], [3,4,5,6,7], true)) {
            $stmt2 = $pdo->prepare("
                SELECT u.barangay_id, b.barangay_name
                FROM Users u
                  JOIN Barangay b ON u.barangay_id = b.barangay_id
                WHERE u.user_id = ?
            ");
            $stmt2->execute([$user['user_id']]);
            if ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['barangay_id']   = $row['barangay_id'];
                $_SESSION['barangay_name'] = $row['barangay_name'];
            }
        }

        logAuditTrail(
            $pdo,
            $user['user_id'],
            "LOGIN",
            "Users",
            $user['user_id'],
            "Email login"
        );

        header("Location: " . postLoginRedirect(
            $user['role_id'],
            $firstName
        ));
        exit;
    } catch (Exception $e) {
        $_SESSION['login_error'] = $e->getMessage();
        header("Location: ../pages/index.php");
        exit;
    }
}

/**
 * GOOGLE OAUTH LOGIN/SIGNUP
 */
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (stripos($contentType, "application/json") !== false) {
    $input = json_decode(file_get_contents("php://input"), true);

    try {
        if (empty($input['token'])) {
            throw new Exception("No token provided");
        }

        // validate token with Google
        $tokenInfo = json_decode(file_get_contents(
            "https://oauth2.googleapis.com/tokeninfo?id_token="
            . urlencode($input['token'])
        ), true);
        if (!$tokenInfo
            || $tokenInfo['aud'] !== '1070456838675-ol86nondnkulmh8s9c5ceapm42tsampq.apps.googleusercontent.com'
        ) {
            throw new Exception("Invalid token");
        }

        // check existing user
        $stmt = $pdo->prepare("
            SELECT user_id, email, role_id, first_name
            FROM Users
            WHERE email = ?
        ");
        $stmt->execute([$tokenInfo['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $user) {
            // new resident
            $pdo->beginTransaction();
            $ins = $pdo->prepare("
                INSERT INTO Users (email, password_hash, isverify, role_id)
                VALUES (?, '', 'yes', 8)
            ");
            $ins->execute([$tokenInfo['email']]);
            $newId = $pdo->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['user_id']    = $newId;
            $_SESSION['email']      = $tokenInfo['email'];
            $_SESSION['role_id']    = 8;
            $_SESSION['first_name'] = ''; // force completion

            logAuditTrail(
                $pdo,
                $newId,
                "ACCOUNT_CREATED",
                "Users",
                $newId,
                "Google signup"
            );
            $pdo->commit();
        } else {
            // existing user → demote to resident
            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role_id']    = 8;
            $_SESSION['first_name'] = $user['first_name'] ?? '';

            if ($user['role_id'] !== 8) {
                $upd = $pdo->prepare("
                    UPDATE Users
                    SET role_id = 8
                    WHERE user_id = ?
                ");
                $upd->execute([$user['user_id']]);
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success'  => true,
            'redirect' => postLoginRedirect(
                8,
                $_SESSION['first_name']
            )
        ]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Fallback for any other request
http_response_code(400);
echo json_encode(['error' => 'Invalid request']);
exit;
