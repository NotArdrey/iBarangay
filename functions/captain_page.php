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
    if (!$u || !in_array((int)$u['role_id'], [ROLE_CAPTAIN, ROLE_CHIEF])) {
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
        $chk = $pdo->prepare("SELECT barangay_id, role_id, first_name, last_name FROM users WHERE id=?");
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
        
        // Prevent deletion of captains and chiefs
        if (in_array((int)$row['role_id'], [ROLE_CAPTAIN, ROLE_CHIEF])) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete Captain or Kagawad Chief']);
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
            SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.role_id, u.is_active,
                   r.name as role_name
              FROM users u
         LEFT JOIN roles r ON u.role_id = r.id
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
            $limits = [ROLE_SECRETARY=>1,ROLE_TREASURER=>1,ROLE_CHIEF=>1,ROLE_COUNCILOR=>7];
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
        // collect new fields
        $fn    = trim($_POST['first_name'] ?? '');
        $ln    = trim($_POST['last_name']  ?? '');
        $email = trim($_POST['email']      ?? '');
        $phone = trim($_POST['phone']      ?? '');
        // validate
        if (!preg_match("/^[A-Za-z\s'-]{2,50}$/",$fn) ||
            !preg_match("/^[A-Za-z\s'-]{2,50}$/",$ln)) {
            echo json_encode(['success'=>false,'message'=>'Invalid name']);
            exit;
        }
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success'=>false,'message'=>'Invalid email']);
            exit;
        }
        if (!preg_match('/^(?:\+63|0)9\d{9}$/',$phone)) {
            echo json_encode(['success'=>false,'message'=>'Invalid phone']);
            exit;
        }
        // role limit
        $limits = [ROLE_SECRETARY=>1,ROLE_TREASURER=>1,ROLE_CHIEF=>1,ROLE_COUNCILOR=>7];
        if (isset($limits[$role])) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id=? AND barangay_id=?");
            $cnt->execute([$role,$bid]);
            if ((int)$cnt->fetchColumn() >= $limits[$role]) {
                echo json_encode(['success'=>false,'message'=>'Role limit reached']);
                exit;
            }
        }
        // password
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
        // insert
        $ins = $pdo->prepare("
            INSERT INTO users 
              (first_name,last_name,email,phone,role_id,barangay_id,password)
            VALUES (?,?,?,?,?,?,?)
        ");
        if ($ins->execute([$fn,$ln,$email,$phone,$role,$bid,$passHash])) {
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
        $fn    = trim($_POST['first_name']  ?? '');
        $ln    = trim($_POST['last_name']   ?? '');
        $email = trim($_POST['email']       ?? '');
        $phone = trim($_POST['phone']       ?? '');
        $role  = (int)($_POST['role_id']    ?? 0);
        // validate
        if (!preg_match("/^[A-Za-z\s'-]{2,50}$/",$fn) ||
            !preg_match("/^[A-Za-z\s'-]{2,50}$/",$ln) ||
            !filter_var($email,FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^(?:\+63|0)9\d{9}$/',$phone)) {
            echo json_encode(['success'=>false,'message'=>'Invalid input']);
            exit;
        }
        $upd = $pdo->prepare("
            UPDATE users 
               SET first_name=?, last_name=?, email=?, phone=?, role_id=? 
             WHERE id=? AND barangay_id=?
        ");
        if ($upd->execute([$fn,$ln,$email,$phone,$role,$eid,$bid])) {
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
    $allowed = [ROLE_SECRETARY,ROLE_TREASURER,ROLE_COUNCILOR,ROLE_CHIEF];
    $ph = str_repeat('?,',count($allowed)-1).'?';
    $rs = $pdo->prepare("SELECT id role_id,name role_name FROM roles WHERE id IN($ph)");
    $rs->execute($allowed);
    $roles = $rs->fetchAll(PDO::FETCH_ASSOC);

    // users table
    $off = $allowed;
    $ph = str_repeat('?,',count($off)-1).'?';
    $stm = $pdo->prepare("
      SELECT u.*, r.name role_name,
             CASE WHEN u.is_active=1 THEN 'Active' ELSE 'Inactive' END status_text,
             p.first_name, p.last_name
        FROM users u
        LEFT JOIN roles r ON u.role_id=r.id
        LEFT JOIN persons p ON u.id = p.user_id
       WHERE u.barangay_id=? AND u.role_id IN($ph)
       ORDER BY u.role_id, p.last_name, p.first_name
    ");
    $stm->execute(array_merge([$bid],$off));
    $users = $stm->fetchAll(PDO::FETCH_ASSOC);

    return compact('persons','barangayName','roles','users');
}
