<?php
// functions/index.php

// 1) Allow pop-ups under the same origin
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

session_start();
session_regenerate_id(true); // Regenerate session ID at the start
require __DIR__ . "/../config/dbconn.php";

/**
 * Insert an audit record
 */
function logAuditTrail(PDO $pdo, int $user_id, string $action, ?string $table_name = null, ?int $record_id = null, string $description = '')
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_trails 
         (user_id, action, table_name, record_id, new_values) 
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
 * Get dashboard URL directly based on role
 */
function postLoginRedirect(int $role_id, ?string $first_name): string
{
    // No profile completion check - directly get dashboard URL
    return getDashboardUrl($role_id);
}

/**
 * Check if a role ID exists in the roles table
 */
function isValidRole(PDO $pdo, int $role_id): bool
{
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Get user's role and barangay information
 */
function getUserRoleInfo(PDO $pdo, int $user_id): ?array
{
    // First, check the direct role_id in the users table
    $userRoleStmt = $pdo->prepare("SELECT role_id, barangay_id FROM users WHERE id = ? AND is_active = TRUE");
    $userRoleStmt->execute([$user_id]);
    $userRole = $userRoleStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userRole && isValidRole($pdo, $userRole['role_id'])) {
        // Get role name
        $roleNameStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $roleNameStmt->execute([$userRole['role_id']]);
        $roleName = $roleNameStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get barangay name
        $barangayName = null;
        if ($userRole['barangay_id']) {
            $barangayStmt = $pdo->prepare("SELECT name FROM barangay WHERE id = ?");
            $barangayStmt->execute([$userRole['barangay_id']]);
            $barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);
            $barangayName = $barangay ? $barangay['name'] : null;
        }
        
        return [
            'role_id' => $userRole['role_id'],
            'barangay_id' => $userRole['barangay_id'],
            'barangay_name' => $barangayName,
            'role_name' => $roleName ? $roleName['name'] : 'unknown'
        ];
    }
    
    // As fallback, check the user_roles table (previous method)
    $stmt = $pdo->prepare("
        SELECT ur.role_id, ur.barangay_id, b.name as barangay_name, r.name as role_name
        FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        LEFT JOIN barangay b ON ur.barangay_id = b.id
        WHERE ur.user_id = ? AND ur.is_active = TRUE
        ORDER BY ur.role_id
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Convert false to null to match the function's return type declaration
    return $result === false ? null : $result;
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
        // Check if user is already logged in with a different account
        if (isset($_SESSION['user_id']) && isset($_SESSION['email']) && $_SESSION['email'] !== $email) {
            throw new Exception("You are already logged in as " . $_SESSION['email'] . ". Please log out first before logging in as a different user.");
        }

        // Fetch user data with person information
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.password, u.email_verified_at, u.is_active,
                   p.first_name, p.middle_name, p.last_name
            FROM users u
            LEFT JOIN persons p ON u.id = p.user_id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $user || ! password_verify($password, $user['password'])) {
            throw new Exception("Invalid credentials");
        }
        if ($user['email_verified_at'] === null || $user['is_active'] != 1) {
            throw new Exception("Account not verified or inactive");
        }

        // Get user role information
        $roleInfo = getUserRoleInfo($pdo, $user['id']);
        if (!$roleInfo) {
            // Check if user has a role_id in the users table
            $roleStmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
            $roleStmt->execute([$user['id']]);
            $userRole = $roleStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userRole || !$userRole['role_id']) {
                throw new Exception("No role assigned to this user account");
            } else if (!isValidRole($pdo, $userRole['role_id'])) {
                throw new Exception("Invalid role assigned to this user account (Role ID: {$userRole['role_id']})");
            } else {
                throw new Exception("Role found but not active or properly configured");
            }
        }

        session_regenerate_id(true);

        // Set session variables
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role_id']    = $roleInfo['role_id'];
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['middle_name'] = $user['middle_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';

        // Set barangay info if applicable
        if ($roleInfo['barangay_id']) {
            $_SESSION['barangay_id']   = $roleInfo['barangay_id'];
            $_SESSION['barangay_name'] = $roleInfo['barangay_name'];
        }

        // Update last login
        $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        logAuditTrail(
            $pdo,
            $user['id'],
            "LOGIN",
            "users",
            $user['id'],
            "Email login"
        );

        header("Location: " . postLoginRedirect(
            $roleInfo['role_id'],
            $user['first_name'] ?? ''
        ));
        exit;
    } catch (Exception $e) {
        $_SESSION['login_error'] = $e->getMessage();
        header("Location: ../pages/login.php");
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

        // Check existing user
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, p.first_name, p.middle_name, p.last_name
            FROM users u
            LEFT JOIN persons p ON u.id = p.user_id
            WHERE u.email = ?
        ");
        $stmt->execute([$tokenInfo['email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $user) {
            // Create new resident user
            $pdo->beginTransaction();
            
            // Insert user
            $ins = $pdo->prepare("
                INSERT INTO users (email, password, email_verified_at, is_active)
                VALUES (?, '', NOW(), TRUE)
            ");
            $ins->execute([$tokenInfo['email']]);
            $newUserId = $pdo->lastInsertId();

            // Create person record if we have name info from Google
            if (!empty($tokenInfo['given_name']) || !empty($tokenInfo['family_name'])) {
                $personStmt = $pdo->prepare("
                    INSERT INTO persons (user_id, first_name, last_name, birth_date, gender, civil_status)
                    VALUES (?, ?, ?, '1990-01-01', 'Male', 'Single')
                ");
                $personStmt->execute([
                    $newUserId,
                    $tokenInfo['given_name'] ?? '',
                    $tokenInfo['family_name'] ?? ''
                ]);
            }

            // Assign resident role (role_id = 8) to a default barangay (id = 1)
            $roleStmt = $pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, barangay_id, is_active)
                VALUES (?, 8, 1, TRUE)
            ");
            $roleStmt->execute([$newUserId]);

            session_regenerate_id(true);
            $_SESSION['user_id']    = $newUserId;
            $_SESSION['email']      = $tokenInfo['email'];
            $_SESSION['role_id']    = 8;
            $_SESSION['first_name'] = $tokenInfo['given_name'] ?? '';
            $_SESSION['last_name']  = $tokenInfo['family_name'] ?? '';

            logAuditTrail(
                $pdo,
                $newUserId,
                "ACCOUNT_CREATED",
                "users",
                $newUserId,
                "Google signup"
            );
            $pdo->commit();
        } else {
            // Existing user login
            $roleInfo = getUserRoleInfo($pdo, $user['id']);
            if (!$roleInfo) {
                // If no role exists, assign resident role
                $roleStmt = $pdo->prepare("
                    INSERT INTO user_roles (user_id, role_id, barangay_id, is_active)
                    VALUES (?, 8, ?, TRUE)
                ");
                $roleStmt->execute([$user['id'], $user['barangay_id']]);
                $roleInfo = ['role_id' => 8, 'barangay_id' => $user['barangay_id'], 'barangay_name' => null];
            }

            // Verify that the user's barangay is still active
            $stmt = $pdo->prepare("
                SELECT b.id, b.name 
                FROM barangay b 
                WHERE b.id = ? AND b.is_active = TRUE
            ");
            $stmt->execute([$roleInfo['barangay_id']]);
            $barangay = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$barangay) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Your barangay access has been deactivated. Please contact the barangay office for assistance.'
                ]);
                exit;
            }

            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role_id']    = $roleInfo['role_id'];
            $_SESSION['first_name'] = $user['first_name'] ?? '';
            $_SESSION['middle_name'] = $user['middle_name'] ?? '';
            $_SESSION['last_name'] = $user['last_name'] ?? '';

            if ($roleInfo['barangay_id']) {
                $_SESSION['barangay_id']   = $roleInfo['barangay_id'];
                $_SESSION['barangay_name'] = $barangay['name'];
            }

            // Update last login
            $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);

            logAuditTrail(
                $pdo,
                $user['id'],
                "LOGIN",
                "users",
                $user['id'],
                "Google login"
            );
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success'  => true,
            'redirect' => postLoginRedirect(
                $_SESSION['role_id'],
                $_SESSION['first_name']
            )
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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