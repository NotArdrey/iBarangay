<?php
function captain_start() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
}

function captain_checkAccess(PDO $pdo) {
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        header('Location: ../pages/login.php');
        exit;
    }
    $stmt = $pdo->prepare('SELECT role_id, barangay_id FROM users WHERE id=?');
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || !in_array((int)$u['role_id'], [ROLE_CAPTAIN, ROLE_CHAIRPERSON])) { // Changed from ROLE_CHIEF
        if ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
             strtolower($_SERVER['HTTP_X_REQUESTED_WITH'])==='xmlhttprequest')) {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'Forbidden']);
        } else {
            header('Location: ../pages/login.php');
        }
        exit;
    }
    return $u['barangay_id'];
}

function captain_handleActions(PDO $pdo, $bid) {
    // Get current signature
    if (isset($_GET['get_current_signature'])) {
        header('Content-Type: application/json');
        $current_admin_id = $_SESSION['user_id'];
        $role = $_SESSION['role_id'];
        
        // Only handle captain signatures in this function
        if ($role === ROLE_CAPTAIN) {
            $stmt = $pdo->prepare("SELECT esignature_path as signature_path, updated_at FROM users WHERE id = ?");
            $stmt->execute([$current_admin_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && $result['signature_path']) {
                echo json_encode([
                    'success' => true,
                    'signature_path' => $result['signature_path'],
                    'uploaded_at' => $result['updated_at']
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No signature found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Only Captain can access signatures through this endpoint']);
        }
        exit;
    }

    // Upload signature
    if (isset($_POST['upload_signature'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
            exit;
        }
        
        $current_admin_id = $_SESSION['user_id'];
        $role = $_SESSION['role_id'];
        
        // Validate that user is a captain
        if ($role !== ROLE_CAPTAIN) {
            echo json_encode(['success'=>false,'message'=>'Only Captain can upload signatures through this endpoint']);
            exit;
        }

        if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'message'=>'No file uploaded or upload error']);
            exit;
        }

        // Validate file type and size
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        if (!in_array($_FILES['signature_file']['type'], $allowedTypes)) {
            echo json_encode(['success'=>false,'message'=>'Invalid file type. Only JPEG, PNG, and GIF are allowed']);
            exit;
        }

        if ($_FILES['signature_file']['size'] > $maxSize) {
            echo json_encode(['success'=>false,'message'=>'File size too large. Maximum 2MB allowed']);
            exit;
        }

        // Create signature directory if it doesn't exist
        $uploadDir = '../uploads/signatures/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename for captain
        $extension = pathinfo($_FILES['signature_file']['name'], PATHINFO_EXTENSION);
        $filename = 'captain_signature_' . $current_admin_id . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;

        if (!move_uploaded_file($_FILES['signature_file']['tmp_name'], $filepath)) {
            echo json_encode(['success'=>false,'message'=>'Failed to save uploaded file']);
            exit;
        }

        // Update the captain signature field with correct path format
        $dbPath = 'uploads/signatures/' . $filename;
        
        $stmt = $pdo->prepare("UPDATE users SET esignature_path = ?, updated_at = NOW() WHERE id = ? AND role_id = ?");
        if ($stmt->execute([$dbPath, $current_admin_id, ROLE_CAPTAIN])) {
            // Verify the file was saved and path is correct
            $verifyPath = '../' . $dbPath;
            if (file_exists($verifyPath)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Captain signature uploaded successfully',
                    'signature_path' => $dbPath,
                    'file_exists' => true,
                    'debug_info' => [
                        'uploaded_filename' => $filename,
                        'db_path' => $dbPath,
                        'verify_path' => $verifyPath,
                        'file_size' => filesize($verifyPath),
                        'current_admin_id' => $current_admin_id
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Signature uploaded to database but file verification failed',
                    'signature_path' => $dbPath,
                    'file_exists' => false,
                    'debug_info' => [
                        'uploaded_filename' => $filename,
                        'db_path' => $dbPath,
                        'verify_path' => $verifyPath
                    ]
                ]);
            }
        } else {
            // Clean up the uploaded file if database update failed
            @unlink($filepath);
            echo json_encode(['success'=>false,'message'=>'Failed to update database']);
        }
        exit;
    }

    // toggle status
    if (isset($_GET['toggle_status'])) {
        $uid = (int)$_GET['user_id'];
        $action = $_GET['action'];
        if (!in_array($action, ['activate','deactivate'])) {
            echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
        }
        $chk = $pdo->prepare("SELECT barangay_id FROM users WHERE id=?");
        $chk->execute([$uid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['barangay_id']!=$bid) {
            echo json_encode(['success'=>false,'message'=>'Access denied']); exit;
        }
        $new = $action==='activate'?1:0;
        $upd = $pdo->prepare("UPDATE users SET is_active=? WHERE id=?");
        if ($upd->execute([$new,$uid])) {
            echo json_encode(['success'=>true,'newStatus'=> $new?'yes':'no']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Update failed']);
        }
        exit;
    }

    // delete user
    if (isset($_GET['delete_id'])) {
        // Add CSRF token validation for delete operations
        if (!hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'] ?? '')) {
            echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']); 
            exit;
        }
        $uid = (int)$_GET['delete_id'];
        
        // Validate user ID
        if ($uid <= 0) {
            echo json_encode(['success'=>false,'message'=>'Invalid user ID']);
            exit;
        }
        // Check if user exists and belongs to current barangay
        $chk = $pdo->prepare("
            SELECT u.barangay_id, u.role_id, p.id as person_id
            FROM users u
            LEFT JOIN persons p ON p.user_id = u.id
            WHERE u.id=?
        ");
        $chk->execute([$uid]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success'=>false,'message'=>'User not found']);
            exit;
        }
        if ($row['barangay_id'] != $bid) {
            echo json_encode(['success'=>false,'message'=>'Access denied - User not in your barangay']);
            exit;
        }
        if (in_array((int)$row['role_id'], [ROLE_CAPTAIN, ROLE_CHAIRPERSON])) { // Changed from ROLE_CHIEF
            echo json_encode(['success'=>false,'message'=>'Cannot delete Captain or Barangay Chairperson']); // Updated message
            exit;
        }
        
        // Check for active associations (documents, transactions, etc.)
        $assocCheck = $pdo->prepare("
            SELECT COUNT(*) as total FROM (
                SELECT 1 FROM document_requests WHERE requested_by = ? 
                UNION ALL
                SELECT 1 FROM blotter_reports WHERE complainant_id = ? OR respondent_id = ?
                UNION ALL  
                SELECT 1 FROM announcements WHERE created_by = ?
            ) as associations
        ");
        $assocCheck->execute([$uid, $uid, $uid, $uid]);
        $associations = $assocCheck->fetchColumn();
        
        if ($associations > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete user with active records. Deactivate instead.']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Update persons table to remove user association
            $pdo->prepare("UPDATE persons SET user_id = NULL WHERE user_id = ?")->execute([$uid]);
            
            // Delete the user
            $del = $pdo->prepare("DELETE FROM users WHERE id=?");
            if ($del->execute([$uid])) {
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>'User deleted successfully']);
            } else {
                $pdo->rollBack();
                echo json_encode(['success'=>false,'message'=>'Delete failed']);
            }
        } catch(PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // get person details
    if (isset($_GET['get_person'])) {
        $pid = (int)$_GET['person_id'];
        
        // Validate person ID
        if ($pid <= 0) {
            echo json_encode(['success'=>false,'message'=>'Invalid person ID']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT p.first_name, p.last_name, p.user_id, u.email, u.role_id,
                   CASE WHEN p.user_id IS NOT NULL THEN 'Yes' ELSE 'No' END as has_account
              FROM persons p
         LEFT JOIN users u ON p.user_id = u.id
              JOIN addresses a ON a.person_id = p.id AND a.barangay_id = ?
             WHERE p.id = ?
        ");
        $stmt->execute([$bid, $pid]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$person) {
            echo json_encode(['success'=>false,'message'=>'Person not found or not in your barangay']);
            exit;
        }
        
        // Add role name if user exists
        if ($person['user_id']) {
            $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
            $roleStmt->execute([$person['role_id']]);
            $person['role_name'] = $roleStmt->fetchColumn() ?: 'Unknown';
        }
        
        echo json_encode(['success'=>true,'data'=>$person]);
        exit;
    }

    // get user for edit
    if (isset($_GET['get_user'])) {
        $uid = (int)$_GET['user_id'];
        
        // Validate user ID
        if ($uid <= 0) {
            echo json_encode(['success'=>false,'message'=>'Invalid user ID']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.phone, u.role_id, u.is_active,
                   r.name as role_name,
                   p.id as person_id, p.first_name, p.last_name, p.middle_name, p.suffix, p.birth_date, p.gender, p.civil_status, p.citizenship, p.religion, p.occupation, p.contact_number
              FROM users u
         LEFT JOIN roles r ON u.role_id = r.id
         LEFT JOIN persons p ON p.user_id = u.id
             WHERE u.id = ? AND u.barangay_id = ?
        ");
        $stmt->execute([$uid, $bid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(['success'=>false,'message'=>'User not found or access denied']);
            exit;
        }
        
        // Prevent editing of captains by non-captains
        $currentUserRole = $_SESSION['role_id'] ?? 0;
        if ((int)$user['role_id'] === ROLE_CAPTAIN && $currentUserRole !== ROLE_CAPTAIN) {
            echo json_encode(['success'=>false,'message'=>'Cannot edit Captain account']);
            exit;
        }
        
        echo json_encode(['success'=>true,'data'=>$user]);
        exit;
    }

    // add user
    if (isset($_POST['add_user'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
            exit;
        }
        $role = (int)($_POST['role_id']    ?? 0);
        $pid  = (int)($_POST['person_id']  ?? 0);
        if (!$role || !$pid) {
            echo json_encode(['success'=>false,'message'=>'Invalid selection']);
            exit;
        }
        // fetch census person
        $pStmt = $pdo->prepare("SELECT user_id FROM persons WHERE id=?");
        $pStmt->execute([$pid]);
        $person = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            echo json_encode(['success'=>false,'message'=>'Person not found']);
            exit;
        }
        // promote existing user
        if ($person['user_id']) {
            $limits = [ROLE_SECRETARY=>1,ROLE_TREASURER=>1,ROLE_CHAIRPERSON=>1,ROLE_COUNCILOR=>7]; // Changed from ROLE_CHIEF
            if (isset($limits[$role])) {
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id=? AND barangay_id=?");
                $cnt->execute([$role,$bid]);
                if ((int)$cnt->fetchColumn() >= $limits[$role]) {
                    echo json_encode(['success'=>false,'message'=>'Role limit reached']);
                    exit;
                }
            }
            $upd = $pdo->prepare("UPDATE users SET role_id=? WHERE id=?");
            if ($upd->execute([$role,$person['user_id']])) {
                echo json_encode(['success'=>true,'message'=>'Role updated']);
            } else {
                echo json_encode(['success'=>false,'message'=>'Failed to update role']);
            }
            exit;
        }
        $email = trim($_POST['email']      ?? '');
        $phone = trim($_POST['phone']      ?? '');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'message'=>'Invalid email']);
            exit;
        }
        if (!preg_match('/^(?:\+63|0)9\d{9}$/',$phone)) {
            echo json_encode(['success'=>false,'message'=>'Invalid phone']);
            exit;
        }
        $limits = [ROLE_SECRETARY=>1,ROLE_TREASURER=>1,ROLE_CHAIRPERSON=>1,ROLE_COUNCILOR=>7]; // Changed from ROLE_CHIEF
        if (isset($limits[$role])) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id=? AND barangay_id=?");
            $cnt->execute([$role,$bid]);
            if ((int)$cnt->fetchColumn() >= $limits[$role]) {
                echo json_encode(['success'=>false,'message'=>'Role limit reached']);
                exit;
            }
        }
        $pwd  = $_POST['password']         ?? '';
        $pwd2 = $_POST['confirm_password'] ?? '';
        if ($pwd!==$pwd2) {
            echo json_encode(['success'=>false,'message'=>'Passwords do not match']);
            exit;
        }
        if (strlen($pwd)<8) {
            echo json_encode(['success'=>false,'message'=>'Password must be ≥8 chars']);
            exit;
        }
        $passHash = password_hash($pwd,PASSWORD_DEFAULT);
        // insert user (no first_name/last_name)
        $ins = $pdo->prepare("
            INSERT INTO users 
              (email,phone,role_id,barangay_id,password)
            VALUES (?,?,?,?,?)
        ");
        if ($ins->execute([$email,$phone,$role,$bid,$passHash])) {
            $uid = $pdo->lastInsertId();
            $pdo->prepare("UPDATE persons SET user_id=? WHERE id=?")
                ->execute([$uid,$pid]);
            echo json_encode(['success'=>true,'message'=>'User added successfully']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Failed to add user']);
        }
        exit;
    }

    // edit user
    if (isset($_POST['edit_user'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
            exit;
        }
        $eid   = (int)($_POST['user_id']    ?? 0);
        $email = trim($_POST['email']       ?? '');
        $phone = trim($_POST['phone']       ?? '');
        $role  = (int)($_POST['role_id']    ?? 0);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^(?:\+63|0)9\d{9}$/',$phone)) {
            echo json_encode(['success'=>false,'message'=>'Invalid input']);
            exit;
        }
        $upd = $pdo->prepare("
            UPDATE users 
               SET email=?, phone=?, role_id=? 
             WHERE id=? AND barangay_id=?
        ");
        if ($upd->execute([$email,$phone,$role,$eid,$bid])) {
            echo json_encode(['success'=>true,'message'=>'User updated']);
        } else {
            echo json_encode(['success'=>false,'message'=>'Update failed']);
        }
        exit;
    }
}

function captain_loadData(PDO $pdo, $bid) {
    // census persons
    $pl = $pdo->prepare(
      "SELECT p.id, CONCAT(p.first_name,' ',p.last_name) AS person_name
         FROM persons p
         JOIN addresses a ON a.person_id=p.id
        WHERE a.barangay_id=?"
    );
    $pl->execute([$bid]);
    $persons = $pl->fetchAll(PDO::FETCH_ASSOC);

    // barangay name
    $bn = $pdo->prepare("SELECT name FROM barangay WHERE id=?");
    $bn->execute([$bid]);
    $barangayName = $bn->fetchColumn();

    // roles dropdown
    // Add ROLE_HEALTH_WORKER to allowed roles for assignment
    $allowed = [ROLE_SECRETARY,ROLE_TREASURER,ROLE_COUNCILOR,ROLE_CHAIRPERSON, ROLE_HEALTH_WORKER]; // Changed from ROLE_CHIEF
    $ph = str_repeat('?,',count($allowed)-1).'?';
    $rs = $pdo->prepare("SELECT id role_id,name role_name FROM roles WHERE id IN($ph) ORDER BY name"); // Added ORDER BY for consistency
    $rs->execute($allowed);
    $roles = $rs->fetchAll(PDO::FETCH_ASSOC);

    // users table
    $off = $allowed; // Users to be managed now include Health Workers
    $ph = str_repeat('?,',count($off)-1).'?';
    $stm = $pdo->prepare("
      SELECT u.*, r.name role_name,
             p.id as person_id, p.first_name, p.last_name, p.middle_name, p.suffix, p.birth_date, p.gender, p.civil_status, p.citizenship, p.religion, p.occupation, p.contact_number,
             CASE WHEN u.is_active=1 THEN 'Active' ELSE 'Inactive' END status_text
        FROM users u
        LEFT JOIN roles r ON u.role_id=r.id
        LEFT JOIN persons p ON p.user_id = u.id
       WHERE u.barangay_id=? AND u.role_id IN($ph)
       ORDER BY p.last_name, p.first_name
    ");
    $stm->execute(array_merge([$bid],$off));
    $users = $stm->fetchAll(PDO::FETCH_ASSOC);

    return compact('persons','barangayName','roles','users');
}
