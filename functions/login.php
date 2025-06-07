<?php
// functions/index.php

// 1) Allow pop-ups under the same origin
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

// Only start session if one hasn't been started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
        case 9: // Added ROLE_HEALTH_WORKER
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
 * Get all accessible barangay profiles for a user by matching their name via the addresses table.
 */
function getUserBarangays(PDO $pdo, int $user_id): array
{
    // Get the user's name from the users table. This is the authoritative identity.
    $userStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $userStmt->execute([$user_id]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // If the user has no name in their user profile, we cannot find matching records.
    if (!$user || empty($user['first_name']) || empty($user['last_name'])) {
        error_log("getUserBarangays: User ID {$user_id} has no name in the users table. Cannot find profiles.");
        return [];
    }
    
    // Find all person records that match the user's name across all barangays via the addresses table.
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            b.id, 
            b.name, 
            CASE WHEN p.is_archived = TRUE THEN 'archived' ELSE 'active' END as status
        FROM persons p
        JOIN addresses a ON p.id = a.person_id
        JOIN barangay b ON a.barangay_id = b.id
        WHERE LOWER(p.first_name) = LOWER(?) AND LOWER(p.last_name) = LOWER(?)
        ORDER BY b.name
    ");
    $stmt->execute([$user['first_name'], $user['last_name']]);
    
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nameForLog = "{$user['first_name']} {$user['last_name']}";
    error_log("getUserBarangays: Found " . count($barangays) . " potential profile(s) for user '{$nameForLog}' (ID: {$user_id})");
    return $barangays;
}

/**
 * Intelligently updates a user's barangay by linking their account to the correct person record via name.
 */
function updateUserBarangay(PDO $pdo, int $user_id, int $barangay_id): bool
{
    try {
        // Get user's name from the `users` table, which is the source of truth for the account identity.
        $userStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $userStmt->execute([$user_id]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['first_name'] || !$user['last_name']) {
            error_log("updateUserBarangay: Cannot find name for user_id {$user_id}. Cannot perform name-based linking.");
            return false;
        }
        $first_name = $user['first_name'];
        $last_name = $user['last_name'];

        $pdo->beginTransaction();

        // Unlink this user_id from any and all person records it might be currently attached to.
        $clearStmt = $pdo->prepare("UPDATE persons SET user_id = NULL WHERE user_id = ?");
        $clearStmt->execute([$user_id]);

        // Find the specific, unlinked person record in the target barangay that matches the user's name.
        $findPersonStmt = $pdo->prepare("
            SELECT p.id
            FROM persons p
            JOIN addresses a ON p.id = a.person_id
            WHERE a.barangay_id = ?
            AND LOWER(p.first_name) = LOWER(?)
            AND LOWER(p.last_name) = LOWER(?)
            AND p.user_id IS NULL
            LIMIT 1
        ");
        $findPersonStmt->execute([$barangay_id, $first_name, $last_name]);
        $personId = $findPersonStmt->fetchColumn();

        if (!$personId) {
            $pdo->rollBack();
            error_log("updateUserBarangay: Could not find a matching, unlinked person record for user_id {$user_id} with name '{$first_name} {$last_name}' in barangay {$barangay_id}.");
            return false;
        }

        // Link the found person record to this user account.
        $updatePersonStmt = $pdo->prepare("UPDATE persons SET user_id = ? WHERE id = ?");
        $updatePersonStmt->execute([$user_id, $personId]);

        // Finally, update the primary barangay_id on the user's record for context.
        $updateUserStmt = $pdo->prepare("UPDATE users SET barangay_id = ? WHERE id = ?");
        $updateUserStmt->execute([$barangay_id, $user_id]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in updateUserBarangay: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if user has any active records
 */
function hasActiveRecords(PDO $pdo, int $user_id): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM persons p
        JOIN household_members hm ON p.id = hm.person_id
        JOIN households h ON hm.household_id = h.id
        WHERE p.user_id = ? AND p.is_archived = FALSE
    ");
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Get user's role and barangay information
 */
function getUserRoleInfo(PDO $pdo, int $user_id): ?array
{
    // First, check the direct role_id in the users table
    $userRoleStmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ? AND is_active = TRUE");
    $userRoleStmt->execute([$user_id]);
    $userRole = $userRoleStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userRole && isValidRole($pdo, $userRole['role_id'])) {
        // Get role name
        $roleNameStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
        $roleNameStmt->execute([$userRole['role_id']]);
        $roleName = $roleNameStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get all accessible barangays
        $barangays = getUserBarangays($pdo, $user_id);
        
        return [
            'role_id' => $userRole['role_id'],
            'role_name' => $roleName ? $roleName['name'] : 'unknown',
            'accessible_barangays' => $barangays
        ];
    }
    
    // As fallback, check the user_roles table (previous method)
    $stmt = $pdo->prepare("
        SELECT ur.role_id, r.name as role_name
        FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.user_id = ? AND ur.is_active = TRUE
        ORDER BY ur.role_id
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        // Get all accessible barangays
        $barangays = getUserBarangays($pdo, $user_id);
        $result['accessible_barangays'] = $barangays;
    }
    
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

        // Fetch user data from the users table, which is the source of truth for the account name.
        $stmt = $pdo->prepare("
            SELECT id, email, password, email_verified_at, is_active, first_name, last_name
            FROM users
            WHERE email = ?
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
                 $roleInfo = ['role_id' => $userRole['role_id']];
            }
        }

        session_regenerate_id(true);

        // Set initial session variables from the users table.
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role_id']    = $roleInfo['role_id'];
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';

        // For residents and similar roles, perform intelligent redirection.
        if (in_array($roleInfo['role_id'], [8, 9])) { // 8=Resident, 9=Health Worker
            $barangays = getUserBarangays($pdo, $user['id']);
            $_SESSION['accessible_barangays'] = $barangays;
            
            $active_barangays = array_filter($barangays, fn($b) => $b['status'] !== 'archived');

            if (count($active_barangays) === 1) {
                // Only one active profile found, so we can automatically select it.
                $the_barangay = array_values($active_barangays)[0];
                $selected_barangay_id = $the_barangay['id'];
                
                // Use the smart function to link the profile correctly.
                if (!updateUserBarangay($pdo, $_SESSION['user_id'], $selected_barangay_id)) {
                    $_SESSION['login_error'] = "Could not automatically link your profile. Please contact support.";
                    header("Location: ../pages/login.php");
                    exit;
                }

                // Now that the link is correct, re-fetch person data to get full details.
                $personStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, religion, education_level FROM persons WHERE user_id = ?");
                $personStmt->execute([$_SESSION['user_id']]);
                $personData = $personStmt->fetch(PDO::FETCH_ASSOC);
                if ($personData) {
                    $_SESSION['first_name'] = $personData['first_name'];
                    $_SESSION['middle_name'] = $personData['middle_name'] ?? '';
                    $_SESSION['last_name'] = $personData['last_name'];
                    $_SESSION['religion'] = $personData['religion'] ?? '';
                    $_SESSION['education_level'] = $personData['education_level'] ?? '';
                }

                $_SESSION['barangay_id'] = $selected_barangay_id;
                $_SESSION['barangay_name'] = $the_barangay['name'];

                // Update last login
                $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$_SESSION['user_id']]);

                logAuditTrail($pdo, $_SESSION['user_id'], "LOGIN", "users", $_SESSION['user_id'], "Auto-selected barangay: " . $the_barangay['name']);
                
                header("Location: " . getDashboardUrl($_SESSION['role_id']));
                exit;
            } else {
                // 0 or >1 active barangays, redirect to selection page to resolve ambiguity.
                header("Location: ../pages/select_barangay.php");
                exit;
            }
        } else {
            // For other roles, redirect directly to their dashboard
            header("Location: " . getDashboardUrl($roleInfo['role_id']));
            exit;
        }
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
            SELECT u.id, u.email, p.first_name, p.middle_name, p.last_name, p.religion, p.education_level
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
            $_SESSION['religion'] = $user['religion'] ?? '';
            $_SESSION['education_level'] = $user['education_level'] ?? '';

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
            $_SESSION['religion'] = $user['religion'] ?? '';
            $_SESSION['education_level'] = $user['education_level'] ?? '';

            if ($roleInfo['barangay_id']) {
                $_SESSION['barangay_id']   = $roleInfo['barangay_id'];
                $_SESSION['barangay_name'] = $barangay['name'];
            }

            // Store accessible barangays in session
            if (!empty($roleInfo['accessible_barangays'])) {
                $_SESSION['accessible_barangays'] = $roleInfo['accessible_barangays'];
                // If user has multiple barangay access, redirect to barangay selection page
                if ($roleInfo['role_id'] === 9 && count($roleInfo['accessible_barangays']) > 1) {
                    header("Location: ../pages/select_barangay.php");
                    exit;
                }
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
