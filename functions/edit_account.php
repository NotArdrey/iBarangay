<?php
/**
 * Returns the dashboard URL based on the user's role.
 *
 * @param int $role_id The user's role ID.
 * @return string The appropriate dashboard URL.
 */
function getDashboardUrl($role_id) {
    if ($role_id == 1) {
        return "../pages/super_admin_dashboard.php";
    } elseif ($role_id == 2) {
        return "../pages/barangay_admin_dashboard.php";
    } else {
        return "../pages/user_dashboard.php";
    }
}

/**
 * Loads the barangay name for a given user based on email.
 *
 * @param PDO $pdo The database connection object.
 * @param string $email The user's email.
 * @return string|null The barangay name if found, or null otherwise.
 */
function loadBarangayInfo($pdo, $email) {
    // Retrieve the barangay_id from the Users table
    $stmt = $pdo->prepare("SELECT barangay_id FROM Users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userRecord && !empty($userRecord['barangay_id'])) {
        $stmt2 = $pdo->prepare("SELECT barangay_name FROM Barangay WHERE barangay_id = :barangay_id LIMIT 1");
        $stmt2->execute([':barangay_id' => $userRecord['barangay_id']]);
        $barangayRecord = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($barangayRecord) {
            return $barangayRecord['barangay_name'];
        }
    }
    return null;
}
?>
