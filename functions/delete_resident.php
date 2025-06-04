<?php
require_once "../config/dbconn.php";
require_once "../vendor/autoload.php"; 
require_once "email_template.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Email configuration
function sendArchiveNotification($email, $firstName, $lastName, $barangayName) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ibarangay.system@gmail.com';
        $mail->Password   = 'nxxn vxyb kxum cuvd';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
        $mail->addAddress($email, $firstName . ' ' . $lastName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Barangay Record Has Been Archived';
        
        // Create email content
        $content = "
            <p>Dear {$firstName} {$lastName},</p>
            <p>This is to inform you that your record in {$barangayName} has been archived by the barangay administration.</p>
            <p>As a result of this action:</p>
            <ul>
                <li>You will not be able to access this barangay's system temporarily</li>
                <li>If you have records in other barangays, you can still access those systems</li>
                <li>Your data remains secure and can be restored if needed</li>
            </ul>
            <p>If you believe this is an error or need assistance, please contact the barangay office during office hours:</p>
            <p><strong>Office Hours:</strong> Monday to Friday, 8:00 AM to 5:00 PM</p>
        ";
        
        $mail->Body = getEmailTemplate(
            'Record Archive Notification',
            $content
        );

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if request method is DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get resident ID from URL parameters
$resident_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($resident_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid resident ID']);
    exit;
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // First, check if the resident exists and belongs to the current barangay
    $check_stmt = $pdo->prepare("
        SELECT p.id, p.first_name, p.last_name, p.user_id
        FROM persons p
        LEFT JOIN household_members hm ON p.id = hm.person_id
        LEFT JOIN households h ON hm.household_id = h.id
        WHERE p.id = ? 
        AND (h.barangay_id = ? OR h.barangay_id IS NULL)
        AND p.id IN (
            SELECT person_id 
            FROM household_members 
            WHERE household_id IN (
                SELECT id 
                FROM households 
                WHERE barangay_id = ?
            )
        )
    ");
    $check_stmt->execute([$resident_id, $_SESSION['barangay_id'], $_SESSION['barangay_id']]);
    $resident = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resident) {
        throw new Exception('Resident not found or unauthorized to archive');
    }

    // Archive the resident
    $stmt = $pdo->prepare("UPDATE persons SET is_archived = TRUE WHERE id = ?");
    $stmt->execute([$resident_id]);

    // If the resident has a user account, send email notification instead of deactivating
    if ($resident['user_id']) {
        // Get user's email and barangay name
        $userStmt = $pdo->prepare("
            SELECT u.email, b.name as barangay_name 
            FROM users u 
            JOIN barangay b ON b.id = ? 
            WHERE u.id = ?
        ");
        $userStmt->execute([$_SESSION['barangay_id'], $resident['user_id']]);
        $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($userInfo) {
            sendArchiveNotification(
                $userInfo['email'],
                $resident['first_name'],
                $resident['last_name'],
                $userInfo['barangay_name']
            );
        }

        // Log the notification in audit trail
        $stmt = $pdo->prepare("
            INSERT INTO audit_trails (
                user_id, action, table_name, record_id, description
            ) VALUES (
                :user_id, 'ARCHIVE_NOTIFICATION', 'users', :record_id, :description
            )
        ");
        
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':record_id' => $resident['user_id'],
            ':description' => "Sent archive notification to resident: {$resident['first_name']} {$resident['last_name']}"
        ]);
    }

    // Log the resident archiving in audit trail
    $stmt = $pdo->prepare("
        INSERT INTO audit_trails (
            user_id, action, table_name, record_id, description
        ) VALUES (
            :user_id, 'ARCHIVE', 'persons', :record_id, :description
        )
    ");
    
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':record_id' => $resident_id,
        ':description' => "Archived resident: {$resident['first_name']} {$resident['last_name']}"
    ]);

    // Commit transaction
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Resident and associated user account have been archived successfully']);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error archiving resident: ' . $e->getMessage()
    ]);
}
?> 