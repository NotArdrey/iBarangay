<?php
session_start();
require "../config/dbconn.php";
require "../vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Configure PHPMailer
function configureMailer() {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'barangayhub2@gmail.com';
    $mail->Password = 'eisy hpjz rdnt bwrp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->setFrom('noreply@ibarangay.com', 'iBarangay System');
    return $mail;
}

// Add this line to ensure $conn is set from $pdo
$conn = $pdo;

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 4;
$user_info = null;
$barangay_name = "Barangay";
$barangay_id = 32;

if ($user_id) {
    $sql = "SELECT p.first_name, p.last_name, u.barangay_id, b.name as barangay_name
            FROM users u
            LEFT JOIN persons p ON p.user_id = u.id
            LEFT JOIN barangay b ON u.barangay_id = b.id
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $user_info = $row;
        $barangay_name = $row['barangay_name'];
        $barangay_id = $row['barangay_id'];
    }
}

// Get user's person record for participants
$stmt = $conn->prepare("SELECT id FROM persons WHERE user_id = ?");
$stmt->execute([$user_id]);
$person_id = $stmt->fetchColumn();

// Fetch all persons for witness/participant selection
$all_persons = [];
$stmt = $conn->query("SELECT id, first_name, last_name FROM persons ORDER BY last_name, first_name");
$all_persons = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories and interventions
$categories = $conn->query("SELECT * FROM case_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$interventions = $conn->query("SELECT * FROM case_interventions ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Enhanced category detection with comprehensive keyword mapping
function detectCategoryIntelligent($description, $conn) {
    $categories = $conn->query("SELECT id, name FROM case_categories")->fetchAll(PDO::FETCH_ASSOC);
    $desc = strtolower($description);
    
    // Comprehensive keyword mapping for better detection
    $mappings = [
        'physical' => [
            'hit', 'punch', 'kick', 'slap', 'push', 'shove', 'assault', 'attack', 'fight', 
            'violence', 'physical', 'injury', 'hurt', 'wound', 'bruise', 'beaten', 'beaten up',
            'physical harm', 'bodily harm', 'physical abuse', 'physical violence'
        ],
        'sexual' => [
            'sexual', 'rape', 'harassment', 'abuse', 'molest', 'inappropriate touch',
            'sexual assault', 'sexual harassment', 'unwanted advances', 'indecent',
            'sexual abuse', 'molestation', 'inappropriate behavior'
        ],
        'psychological' => [
            'threat', 'intimidate', 'scare', 'verbal', 'mental', 'emotional', 
            'psychological', 'stress', 'anxiety', 'depression', 'trauma',
            'verbal abuse', 'emotional abuse', 'mental abuse', 'threatening'
        ],
        'economic' => [
            'money', 'debt', 'loan', 'payment', 'salary', 'wage', 'financial',
            'economic', 'property', 'business', 'contract', 'agreement',
            'financial abuse', 'economic abuse', 'unpaid'
        ],
        'harassment' => [
            'harass', 'bully', 'annoy', 'disturb', 'pester', 'trouble',
            'harassment', 'bullying', 'stalking', 'following', 'bothering'
        ],
        'trafficking' => [
            'traffick', 'forced', 'slavery', 'exploitation', 'forced labor',
            'human trafficking', 'forced work', 'illegal recruitment'
        ],
        'domestic' => [
            'family', 'spouse', 'husband', 'wife', 'domestic', 'home',
            'family violence', 'domestic violence', 'marital', 'relationship'
        ],
        'child' => [
            'child', 'minor', 'kid', 'baby', 'infant', 'teenager', 'juvenile',
            'child abuse', 'child neglect', 'minor abuse'
        ],
        'elder' => [
            'elderly', 'senior', 'old', 'aged', 'grandmother', 'grandfather',
            'elder abuse', 'senior abuse', 'elderly abuse'
        ],
        'property' => [
            'theft', 'steal', 'rob', 'burglary', 'property', 'missing',
            'stolen', 'robbery', 'burglary', 'trespassing', 'vandalism'
        ]
    ];

    // Score each category based on keyword matches
    $categoryScores = [];
    foreach ($categories as $cat) {
        $categoryScores[$cat['id']] = 0;
        $catName = strtolower($cat['name']);
        
        // Check direct category name match
        if (strpos($desc, $catName) !== false) {
            $categoryScores[$cat['id']] = 10;
        }
        
        // Check keyword mappings
        foreach ($mappings as $type => $keywords) {
            if (strpos($catName, $type) !== false) {
                foreach ($keywords as $keyword) {
                    if (strpos($desc, $keyword) !== false) {
                        $categoryScores[$cat['id']] += 5;
                        // Bonus for exact phrase matches
                        if (strpos($desc, " $keyword ") !== false) {
                            $categoryScores[$cat['id']] += 2;
                        }
                    }
                }
            }
        }
    }
    
    // Return all categories with scores above threshold
    $selectedCategories = [];
    foreach ($categoryScores as $catId => $score) {
        if ($score >= 5) { // Minimum threshold
            $selectedCategories[] = $catId;
        }
    }
    
    // If no categories detected, return a default
    if (empty($selectedCategories)) {
        foreach ($categories as $cat) {
            if (stripos($cat['name'], 'other') !== false) {
                $selectedCategories[] = $cat['id'];
                break;
            }
        }
        
        if (empty($selectedCategories)) {
            $selectedCategories[] = $categories[0]['id'] ?? 1;
        }
    }
    
    return $selectedCategories;
}

// Enhanced email notification function
function sendCaseFiledNotification($userEmail, $userName, $caseNumber, $caseDetails) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'barangayhub2@gmail.com';
        $mail->Password = 'eisy hpjz rdnt bwrp';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('noreply@ibarangay.com', 'iBarangay System');
        $mail->addAddress($userEmail, $userName);
        $mail->isHTML(true);
        
        $mail->Subject = "Blotter Case Filed Successfully - Case #$caseNumber";
        
        $mail->Body = generateEmailTemplate($userName, $caseNumber, $caseDetails) .
                      "<br><br><strong>Note:</strong> A schedule proposal will be sent soon. Please check your blotter status for confirmation.";
        $mail->AltBody = generatePlainTextEmail($userName, $caseNumber, $caseDetails);
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Email notification failed: " . $e->getMessage());
        return false;
    }
}

function generateEmailTemplate($userName, $caseNumber, $caseDetails) {
    $incidentDate = date('F j, Y', strtotime($caseDetails['incident_date']));
    $location = htmlspecialchars($caseDetails['location']);
    $categories = htmlspecialchars($caseDetails['categories']);
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Blotter Case Filed</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0056b3; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
            .case-details { background: white; padding: 20px; border-radius: 6px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 10px; }
            .detail-label { font-weight: bold; width: 120px; }
            .detail-value { flex: 1; }
            .status-badge { background: #fff3cd; color: #856404; padding: 8px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; display: inline-block; }
            .next-steps { background: #e8f5e8; padding: 20px; border-radius: 6px; margin: 20px 0; }
            .btn { background: #0056b3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 10px 0; }
            .footer { text-align: center; color: #666; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏛️ iBarangay System</h1>
                <h2>Blotter Case Filed Successfully</h2>
            </div>
            
            <div class='content'>
                <p>Dear <strong>$userName</strong>,</p>
                
                <p>Your blotter case has been successfully filed and submitted to the barangay office for processing. Below are the details of your case:</p>
                
                <div class='case-details'>
                    <h3>📋 Case Information</h3>
                    <div class='detail-row'>
                        <div class='detail-label'>Case Number:</div>
                        <div class='detail-value'><strong>$caseNumber</strong></div>
                    </div>
                    <div class='detail-row'>
                        <div class='detail-label'>Incident Date:</div>
                        <div class='detail-value'>$incidentDate</div>
                    </div>
                    <div class='detail-row'>
                        <div class='detail-label'>Location:</div>
                        <div class='detail-value'>$location</div>
                    </div>
                    <div class='detail-row'>
                        <div class='detail-label'>Categories:</div>
                        <div class='detail-value'>$categories</div>
                    </div>
                    <div class='detail-row'>
                        <div class='detail-label'>Status:</div>
                        <div class='detail-value'><span class='status-badge'>Pending Review</span></div>
                    </div>
                </div>
                
                <div class='next-steps'>
                    <h3>📝 What Happens Next?</h3>
                    <ol>
                        <li><strong>Case Review:</strong> The barangay office will review your case within 24-48 hours.</li>
                        <li><strong>Schedule Notification:</strong> You will receive notifications about hearing schedules through this system.</li>
                        <li><strong>Mutual Confirmation:</strong> When a hearing is proposed, you'll need to confirm your availability.</li>
                        <li><strong>Hearing Process:</strong> Attend the scheduled hearings for mediation and resolution.</li>
                    </ol>
                </div>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='https://ibarangay.com/pages/blotter_status.php' class='btn'>
                        📊 View Case Status
                    </a>
                </div>
                
                <div style='background: #fff3cd; padding: 15px; border-radius: 6px; margin: 20px 0;'>
                    <strong>⚠️ Important Reminders:</strong>
                    <ul>
                        <li>Keep your contact information updated in your profile</li>
                        <li>Respond promptly to scheduling notifications</li>
                        <li>Arrive 15 minutes early for any scheduled hearings</li>
                        <li>Bring relevant documents and witnesses to hearings</li>
                    </ul>
                </div>
                
                <p>If you have any questions or concerns about your case, please contact the barangay office during business hours or use the messaging system in your dashboard.</p>
                
                <p>Thank you for using the iBarangay system to file your case. We are committed to providing fair and efficient resolution to all community matters.</p>
            </div>
            
            <div class='footer'>
                <p><strong>iBarangay System</strong><br>
                Digitizing Barangay Services for Better Community Governance</p>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>For support, visit your barangay office or use the contact form in the system.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function generatePlainTextEmail($userName, $caseNumber, $caseDetails) {
    $incidentDate = date('F j, Y', strtotime($caseDetails['incident_date']));
    
    return "
    iBarangay System - Blotter Case Filed Successfully
    
    Dear $userName,
    
    Your blotter case has been successfully filed with case number: $caseNumber
    
    Case Details:
    - Case Number: $caseNumber
    - Incident Date: $incidentDate
    - Location: {$caseDetails['location']}
    - Categories: {$caseDetails['categories']}
    - Status: Pending Review
    
    What Happens Next:
    1. The barangay office will review your case within 24-48 hours
    2. You will receive notifications about hearing schedules
    3. Confirm your availability when hearings are proposed
    4. Attend scheduled hearings for mediation and resolution
    
    Important Reminders:
    - Keep your contact information updated
    - Respond promptly to scheduling notifications
    - Arrive 15 minutes early for hearings
    - Bring relevant documents and witnesses
    
    Visit https://ibarangay.com/pages/blotter_status.php to view your case status.
    
    Thank you for using iBarangay.
    ";
}

// Handle AJAX request for automatic analysis
if (isset($_POST['action']) && $_POST['action'] === 'analyze_description') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $description = $input['description'] ?? '';
    
    if (!empty($description)) {
        $suggested_categories = detectCategoryIntelligent($description, $conn);
        echo json_encode([
            'success' => true,
            'categories' => $suggested_categories
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Handle form submission with comprehensive processing and email notification
$success = false;
$error = '';
$showSweetAlert = false;
$caseNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $incident_date = trim($_POST['incident_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $role = $_POST['role'] ?? 'complainant';
    $participants = $_POST['participants'] ?? [];

    // Enhanced Validation
    if (!$incident_date || !$location || !$description || !$role) {
        $error = "Incident date, location, description, and your role are required.";
    } elseif (strlen($description) < 10) {
        $error = "Description must be at least 10 characters.";
    } else {
        // Validate participants
        foreach ($participants as $p) {
            if (!empty($p['user_id'])) {
                if (!in_array($p['role'], ['complainant', 'witness', 'respondent'])) {
                    $error = "Invalid role for registered participant.";
                    break;
                }
            } else {
                if (empty($p['first_name']) || empty($p['last_name']) || !in_array($p['role'], ['complainant', 'witness', 'respondent'])) {
                    $error = "External participants must have first name, last name, and valid role.";
                    break;
                }
            }
        }
    }

    if (!$error) {
        try {
            $conn->beginTransaction();

            // Auto-detect categories based on description
            $selected_categories = detectCategoryIntelligent($description, $conn);

            // Generate case number
            $year = date('Y');
            $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(case_number, -4) AS UNSIGNED)) as max_num FROM blotter_cases WHERE case_number LIKE ?");
            $stmt->execute(["%-$year-%"]);
            $result = $stmt->fetch();
            $nextNum = ($result['max_num'] ?? 0) + 1;
            $caseNumber = 'BRG-' . $year . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

            // Insert blotter case
            $stmt = $conn->prepare("
                INSERT INTO blotter_cases 
                (case_number, incident_date, location, description, status, barangay_id, reported_by_person_id, 
                 created_at) 
                VALUES (?, ?, ?, ?, 'pending', ?, ?, NOW())
            ");
            $stmt->execute([$caseNumber, $incident_date, $location, $description, $barangay_id, $person_id]);
            $case_id = $conn->lastInsertId();

            // Debug: Uncomment to check case insert
            // echo "Inserted case_id: $case_id, status: pending, barangay_id: $barangay_id, person_id: $person_id";

            // Insert auto-detected categories
            $catStmt = $conn->prepare("INSERT INTO blotter_case_categories (blotter_case_id, category_id) VALUES (?, ?)");
            foreach ($selected_categories as $category_id) {
                $catStmt->execute([$case_id, (int)$category_id]);
            }

            // Get category names for email
            $catNames = $conn->prepare("SELECT GROUP_CONCAT(name SEPARATOR ', ') as names FROM case_categories WHERE id IN (" . implode(',', array_fill(0, count($selected_categories), '?')) . ")");
            $catNames->execute($selected_categories);
            $categoryNames = $catNames->fetchColumn();

            // Insert main participant (the user filing the case)
            $stmt = $conn->prepare("INSERT INTO blotter_participants (blotter_case_id, person_id, role, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$case_id, $person_id, $role]);

            // Process additional participants
            if (!empty($participants)) {
                $regStmt = $conn->prepare("INSERT INTO blotter_participants (blotter_case_id, person_id, role, created_at) VALUES (?, ?, ?, NOW())");
                $extStmt = $conn->prepare("INSERT INTO external_participants (first_name, last_name, contact_number, address, age, gender) VALUES (?, ?, ?, ?, ?, ?)");
                $bpStmt = $conn->prepare("INSERT INTO blotter_participants (blotter_case_id, external_participant_id, role, created_at) VALUES (?, ?, ?, NOW())");

                foreach ($participants as $p) {
                    if (!empty($p['user_id'])) {
                        // Registered participant
                        if ($p['user_id'] != $person_id) { // Avoid duplicating self
                            $regStmt->execute([$case_id, (int)$p['user_id'], $p['role']]);
                        }
                    } else {
                        // External participant
                        $fname = trim($p['first_name'] ?? '');
                        $lname = trim($p['last_name'] ?? '');
                        if ($fname && $lname) {
                            $extStmt->execute([
                                $fname,
                                $lname,
                                $p['contact_number'] ?? null,
                                $p['address'] ?? null,
                                $p['age'] ?? null,
                                $p['gender'] ?? null
                            ]);
                            $external_id = $conn->lastInsertId();
                            $bpStmt->execute([$case_id, $external_id, $p['role']]);
                        }
                    }
                }
            }

            // Create notification for barangay officials
            $stmt = $conn->prepare("
                SELECT u.id FROM users u 
                WHERE u.barangay_id = ? AND u.role_id IN (3, 4, 5) AND u.is_active = TRUE
            ");
            $stmt->execute([$barangay_id]);
            $officials = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($officials as $official_id) {
                $stmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, title, message, related_table, related_id, action_url)
                    VALUES (?, 'blotter_update', 'New Blotter Case Filed', ?, 'blotter_cases', ?, ?)
                ");
                $message = "A new blotter case ($caseNumber) has been filed and requires attention.";
                $stmt->execute([
                    $official_id, 
                    $message,
                    $case_id, 
                    '../admin/blotter.php'
                ]);
            }

            $conn->commit();
            $success = true;
            $showSweetAlert = true;

            // Send comprehensive email notification
            if ($user_info['email']) {
                $caseDetails = [
                    'incident_date' => $incident_date,
                    'location' => $location,
                    'categories' => $categoryNames
                ];
                
                $emailSent = sendCaseFiledNotification(
                    $user_info['email'], 
                    $user_info['first_name'] . ' ' . $user_info['last_name'],
                    $caseNumber,
                    $caseDetails
                );
                
                if (!$emailSent) {
                    error_log("Failed to send email notification for case: $caseNumber");
                }
            }

            // Redirect to clear POST and show SweetAlert
            header("Location: blotter_request.php?success=1&case_number=" . urlencode($caseNumber));
            exit;

        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Error filing case: " . $e->getMessage();
        }
    }
}

// Show SweetAlert if redirected after success
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $showSweetAlert = true;
    $caseNumber = $_GET['case_number'] ?? '';
}

// Only include navbar after all header() calls and redirects
require "../components/navbar.php";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Blotter Case - iBarangay</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #0056b3;
            --secondary-color: #3498db;
            --success-color: #059669;
            --warning-color: #d97706;
            --error-color: #dc2626;
            --text-dark: #2c3e50;
            --text-light: #7f8c8d;
            --bg-light: #f8f9fa;
            --white: #ffffff;
            --border-light: #e0e0e0;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.1);
            --shadow-md: 0 5px 15px rgba(0,0,0,0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-light);
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        html::-webkit-scrollbar, body::-webkit-scrollbar {
            display: none;
        }

        .page-wrapper {
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--white);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            padding: 2rem;
        }

        h2 {
            color: var(--primary-color);
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            background: #fafbfc;
        }

        .form-section h3 {
            color: var(--text-dark);
            font-size: 1.2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            display: block;
        }

        .required {
            color: var(--error-color);
        }

        input[type="text"], 
        input[type="datetime-local"], 
        input[type="number"],
        input[type="tel"],
        textarea, 
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-light);
            border-radius: 6px;
            font-size: 1rem;
            background: var(--white);
            transition: all 0.3s ease;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 86, 179, 0.1);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .participants-container {
            border: 1px solid var(--border-light);
            border-radius: 6px;
            max-height: 300px;
            overflow-y: auto;
        }

        .participant-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border-light);
            background: var(--white);
            margin-bottom: 0.5rem;
            border-radius: 6px;
        }

        .participant-item:last-child {
            border-bottom: none;
        }

        .participant-registered {
            background: #eff6ff;
            border-left: 4px solid var(--primary-color);
        }

        .participant-external {
            background: #f0fdf4;
            border-left: 4px solid var(--success-color);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #004494;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background: var(--error-color);
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #ecfdf5;
            color: var(--success-color);
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: var(--error-color);
            border: 1px solid #fecaca;
        }

        .alert-info {
            background: #eff6ff;
            color: var(--primary-color);
            border: 1px solid #bfdbfe;
        }

        .analysis-indicator {
            position: absolute;
            right: 10px;
            top: 10px;
            background: var(--success-color);
            color: white;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.75rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .analysis-indicator.show {
            opacity: 1;
        }

        .analysis-indicator.analyzing {
            background: var(--warning-color);
        }

        .back-button {
            background: var(--secondary-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .back-button:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .footer {
            background: var(--text-dark);
            color: white;
            text-align: center;
            padding: 2rem;
            margin-top: 2rem;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 768px) {
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
            
            .page-wrapper {
                padding: 1rem;
            }
            
            .container {
                padding: 1.5rem;
            }
        }

        .description-analyzer {
            position: relative;
        }

        .loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Enhanced success styling */
        .success-container {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
            border-radius: 12px;
            border: 2px solid #bbf7d0;
        }

        .success-icon {
            font-size: 3rem;
            color: var(--success-color);
            margin-bottom: 1rem;
        }

        .case-number-display {
            background: var(--white);
            padding: 1rem 2rem;
            border-radius: 8px;
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 1rem 0;
            border: 2px solid var(--primary-color);
        }

        #notifBell { position:relative; cursor:pointer; }
        #notifBell.has-notif::after {
          content: '';
          position: absolute; top: 2px; right: 2px;
          width: 8px; height: 8px; background: #dc2626; border-radius: 50%;
        }
        #notifCount {
          position: absolute; top: -4px; right: -4px;
          background: #dc2626; color: #fff; font-size: 11px; border-radius: 10px;
          padding: 0 5px; display: none;
        }
        #notifDropdown {
          display: none; position: absolute; right: 0; top: 40px; background: #fff;
          min-width: 260px; box-shadow: 0 2px 8px #0002; border-radius: 8px; z-index: 100;
          max-height: 350px; overflow-y: auto;
        }
        #notifDropdown.show { display: block; }
        .notif-item { padding: 10px 14px; border-bottom: 1px solid #eee; }
        .notif-item:last-child { border-bottom: none; }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="container">
            <a href="../pages/user_dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
            
            <h2>
                <i class="fas fa-gavel"></i>
                File Blotter Case
            </h2>

            <?php if ($success): ?>
                <div class="success-container">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Case Filed Successfully!</h3>
                    <?php if ($caseNumber): ?>
                    <div class="case-number-display">
                        Case Number: <?= htmlspecialchars($caseNumber) ?>
                    </div>
                    <?php endif; ?>
                    <p>Your blotter case has been filed and submitted to the barangay office. You will receive email notifications about the progress and any scheduled hearings.</p>
                    <div style="margin-top: 1.5rem;">
                        <a href="../pages/blotter_status.php" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View Case Status
                        </a>
                    </div>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="post" id="blotterForm" onsubmit="return confirmSubmit(event)">
                <!-- Basic Information Section -->
                <div class="form-section">
                    <h3><i class="fas fa-info-circle"></i> Basic Information</h3>
                    
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="incident_date">Incident Date and Time <span class="required">*</span></label>
                            <input type="datetime-local" id="incident_date" name="incident_date" required 
                                   value="<?= htmlspecialchars($_POST['incident_date'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="location">Location <span class="required">*</span></label>
                            <input type="text" id="location" name="location" required 
                                   placeholder="Where did the incident happen?" 
                                   value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">Description of Incident <span class="required">*</span></label>
                        <div class="description-analyzer">
                            <textarea id="description" name="description" required 
                                      placeholder="Please provide a detailed description of what happened..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                            <div class="analysis-indicator" id="analysisIndicator">
                                <i class="fas fa-brain"></i> <span id="indicatorText">Analyzing...</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="role">Your Role in this Case <span class="required">*</span></label>
                        <select id="role" name="role" required>
                            <option value="complainant" <?= (!isset($_POST['role']) || $_POST['role'] == 'complainant') ? 'selected' : '' ?>>Complainant (Person filing the complaint)</option>
                            <option value="witness" <?= (isset($_POST['role']) && $_POST['role'] == 'witness') ? 'selected' : '' ?>>Witness (Saw what happened)</option>
                        </select>
                    </div>
                </div>

                <!-- Participants Section -->
                <div class="form-section">
                    <h3><i class="fas fa-users"></i> Other Participants</h3>
                    <p class="text-light">Add other people involved in this case (witnesses, other complainants, accused, etc.)</p>
                    
                    <div id="participantContainer" class="participants-container">
                        <!-- Participants will be added here dynamically -->
                    </div>
                    
                    <div style="margin-top: 1rem;">
                        <button type="button" class="btn btn-primary" id="addRegisteredBtn">
                            <i class="fas fa-user-plus"></i> Add Registered Resident
                        </button>
                        <button type="button" class="btn btn-success" id="addExternalBtn">
                            <i class="fas fa-user-plus"></i> Add External Person
                        </button>
                    </div>
                </div>

                <!-- Submit Section -->
                <div class="form-section">
                    <div style="text-align: center;">
                        <button type="submit" class="btn btn-primary" style="padding: 1rem 2rem; font-size: 1.1rem;">
                            <i class="fas fa-paper-plane"></i>
                            Submit Blotter Case
                        </button>
                    </div>
                    <p style="text-align: center; margin-top: 1rem; font-size: 0.9rem; color: var(--text-light);">
                        By submitting this form, you confirm that the information provided is accurate and complete. 
                        You will receive email updates about your case status and hearing schedules.
                    </p>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>

    <!-- Add notification bell to navbar -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Notification badge logic for User
      function fetchNotifications() {
        fetch('../api/scheduling_api.php?action=get_user_notifications')
          .then(res => res.json())
          .then(data => {
            const notifBell = document.getElementById('notifBell');
            const notifCount = document.getElementById('notifCount');
            if (data.count > 0) {
              notifBell.classList.add('has-notif');
              notifCount.textContent = data.count;
              notifCount.style.display = 'inline-block';
            } else {
              notifBell.classList.remove('has-notif');
              notifCount.style.display = 'none';
            }
            // Render dropdown
            const notifDropdown = document.getElementById('notifDropdown');
            notifDropdown.innerHTML = data.notifications.map(n =>
              `<div class="notif-item">
                <div><strong>${n.title}</strong></div>
                <div style="font-size:12px;">${n.message}</div>
                <div style="font-size:11px;color:#888;">${n.created_at}</div>
              </div>`
            ).join('') || '<div class="notif-item">No notifications</div>';
          });
      }
      setInterval(fetchNotifications, 60000);
      fetchNotifications();

      document.getElementById('notifBell').onclick = function() {
        document.getElementById('notifDropdown').classList.toggle('show');
      };
    });
    </script>
    <style>
    #notifBell { position:relative; cursor:pointer; }
    #notifBell.has-notif::after {
      content: '';
      position: absolute; top: 2px; right: 2px;
      width: 8px; height: 8px; background: #dc2626; border-radius: 50%;
    }
    #notifCount {
      position: absolute; top: -4px; right: -4px;
      background: #dc2626; color: #fff; font-size: 11px; border-radius: 10px;
      padding: 0 5px; display: none;
    }
    #notifDropdown {
      display: none; position: absolute; right: 0; top: 40px; background: #fff;
      min-width: 260px; box-shadow: 0 2px 8px #0002; border-radius: 8px; z-index: 100;
      max-height: 350px; overflow-y: auto;
    }
    #notifDropdown.show { display: block; }
    .notif-item { padding: 10px 14px; border-bottom: 1px solid #eee; }
    .notif-item:last-child { border-bottom: none; }
    </style>
    <!-- Add to navbar (inside header.php or here if needed) -->
    <div style="position:relative;display:inline-block;">
      <span id="notifBell" style="font-size:22px;vertical-align:middle;">
        <i class="fas fa-bell"></i>
        <span id="notifCount"></span>
      </span>
      <div id="notifDropdown"></div>
    </div>

    <!-- Show proposed schedules and allow user to confirm/reject -->
    <?php
    // Fetch latest schedule proposal for this user
    $stmt = $conn->prepare("SELECT sp.*, bc.case_number FROM schedule_proposals sp
      JOIN blotter_cases bc ON sp.blotter_case_id = bc.id
      JOIN blotter_participants bp ON bc.id = bp.blotter_case_id
      JOIN persons p ON bp.person_id = p.id
      WHERE p.user_id = ? ORDER BY sp.created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($proposal):
    ?>
    <div class="form-section" style="background:#f9fafb;">
      <h3><i class="fas fa-calendar-alt"></i> Proposed Hearing Schedule</h3>
      <div>
        <strong>Case:</strong> <?= htmlspecialchars($proposal['case_number']) ?><br>
        <strong>Date:</strong> <?= date('M d, Y', strtotime($proposal['proposed_date'])) ?><br>
        <strong>Time:</strong> <?= date('H:i', strtotime($proposal['proposed_time'])) ?><br>
        <strong>Status:</strong> <?= ucfirst($proposal['status']) ?><br>
        <strong>Captain:</strong> <?= $proposal['captain_confirmed'] ? 'Confirmed' : 'Pending' ?><br>
        <?php if ($proposal['remarks']): ?>
          <div style="font-size:12px;color:#888;">Remarks: <?= htmlspecialchars($proposal['remarks']) ?></div>
        <?php endif; ?>
        <?php if ($proposal['status'] === 'proposed' && !$proposal['user_confirmed']): ?>
          <button class="btn btn-success" onclick="userConfirmSchedule(<?= $proposal['id'] ?>)">Confirm Availability</button>
          <button class="btn btn-danger" onclick="userRejectSchedule(<?= $proposal['id'] ?>)">Can't Attend</button>
        <?php elseif ($proposal['user_confirmed'] && !$proposal['captain_confirmed']): ?>
          <div class="alert alert-info">You have confirmed. Waiting for Captain's confirmation.</div>
        <?php elseif ($proposal['status'] === 'both_confirmed'): ?>
          <div class="alert alert-success">Schedule confirmed by both parties.</div>
        <?php elseif ($proposal['status'] === 'conflict'): ?>
          <div class="alert alert-error">Conflict: <?= htmlspecialchars($proposal['remarks']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <script>
    function userConfirmSchedule(proposalId) {
      Swal.fire({
        title: 'Confirm your availability?',
        icon: 'question',
        showCancelButton: true
      }).then(async (result) => {
        if (!result.isConfirmed) return;
        const res = await fetch('../api/scheduling_api.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ action: 'user_confirm', proposal_id: proposalId })
        });
        const data = await res.json();
        if (data.success) {
          Swal.fire('Confirmed!', '', 'success').then(() => location.reload());
        } else {
          Swal.fire('Error', data.message || 'Failed', 'error');
        }
      });
    }
    function userRejectSchedule(proposalId) {
      Swal.fire({
        title: 'Cannot attend?',
        input: 'textarea',
        inputLabel: 'Remarks (reason for conflict)',
        showCancelButton: true
      }).then(async (result) => {
        if (!result.isConfirmed) return;
        const res = await fetch('../api/scheduling_api.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ action: 'user_reject', proposal_id: proposalId, remarks: result.value })
        });
        const data = await res.json();
        if (data.success) {
          Swal.fire('Submitted!', '', 'success').then(() => location.reload());
        } else {
          Swal.fire('Error', data.message || 'Failed', 'error');
        }
      });
    }
    </script>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Function to confirm form submission
        function confirmSubmit(event) {
            event.preventDefault();
            
            // Get form data
            const form = document.getElementById('blotterForm');
            const formData = new FormData(form);
            
            // Validate required fields
            const requiredFields = ['incident_date', 'location', 'description', 'role'];
            const missingFields = requiredFields.filter(field => !formData.get(field));
            
            if (missingFields.length > 0) {
                Swal.fire({
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.',
                    icon: 'warning',
                    confirmButtonColor: '#0056b3'
                });
                return false;
            }

            // Show confirmation dialog
            Swal.fire({
                title: 'Confirm Blotter Case Submission',
                html: `
                    <div class="text-left">
                        <p>Are you sure you want to submit this blotter case?</p>
                        <div class="mt-3 p-3 bg-gray-50 rounded">
                            <p><strong>Incident Date:</strong> ${formData.get('incident_date')}</p>
                            <p><strong>Location:</strong> ${formData.get('location')}</p>
                            <p><strong>Description:</strong> ${formData.get('description').substring(0, 100)}${formData.get('description').length > 100 ? '...' : ''}</p>
                        </div>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0056b3',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, submit case',
                cancelButtonText: 'Review details',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return new Promise((resolve) => {
                        // Submit the form
                        form.submit();
                        resolve();
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading state
                    Swal.fire({
                        title: 'Submitting Case',
                        html: 'Please wait while we process your blotter case...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            });

            return false;
        }

        // Handle success message from URL parameters
        <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        Swal.fire({
            title: 'Case Filed Successfully!',
            html: `
                <div class="text-left">
                    <p>Your blotter case has been filed successfully.</p>
                    <div class="mt-3 p-3 bg-green-50 rounded">
                        <p><strong>Case Number:</strong> <?= htmlspecialchars($_GET['case_number'] ?? '') ?></p>
                        <p class="text-sm text-gray-600 mt-2">You will receive an email confirmation shortly.</p>
                    </div>
                </div>
            `,
            icon: 'success',
            confirmButtonColor: '#0056b3',
            confirmButtonText: 'View Case Status'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'blotter_status.php';
            }
        });
        <?php endif; ?>

        <?php if ($error): ?>
        Swal.fire({
            title: 'Error',
            text: '<?= addslashes($error) ?>',
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        <?php endif; ?>

        // Participant management
        const participantContainer = document.getElementById('participantContainer');
        const addRegisteredBtn = document.getElementById('addRegisteredBtn');
        const addExternalBtn = document.getElementById('addExternalBtn');
        let participantCount = 0;

        // Add Registered Resident
        addRegisteredBtn.addEventListener('click', function() {
            const participantDiv = document.createElement('div');
            participantDiv.className = 'participant-item participant-registered';
            participantDiv.innerHTML = `
                <div style="flex: 1;">
                    <select name="participants[${participantCount}][user_id]" class="form-control" required>
                        <option value="">Select Registered Resident</option>
                        <?php foreach ($all_persons as $person): ?>
                            <option value="<?= $person['id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="participants[${participantCount}][role]" class="form-control" required style="margin-top: 0.5rem;">
                        <option value="complainant">Complainant</option>
                        <option value="respondent">Respondent</option>
                        <option value="witness">Witness</option>
                    </select>
                </div>
                <button type="button" class="btn btn-danger" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            participantContainer.appendChild(participantDiv);
            participantCount++;
        });

        // Add External Person
        addExternalBtn.addEventListener('click', function() {
            const participantDiv = document.createElement('div');
            participantDiv.className = 'participant-item participant-external';
            participantDiv.innerHTML = `
                <div style="flex: 1;">
                    <div class="grid-2" style="gap: 0.5rem;">
                        <input type="text" name="participants[${participantCount}][first_name]" placeholder="First Name" required>
                        <input type="text" name="participants[${participantCount}][last_name]" placeholder="Last Name" required>
                    </div>
                    <div class="grid-2" style="gap: 0.5rem; margin-top: 0.5rem;">
                        <input type="tel" name="participants[${participantCount}][contact_number]" placeholder="Contact Number">
                        <input type="text" name="participants[${participantCount}][address]" placeholder="Address">
                    </div>
                    <div class="grid-2" style="gap: 0.5rem; margin-top: 0.5rem;">
                        <input type="number" name="participants[${participantCount}][age]" placeholder="Age" min="1" max="120">
                        <select name="participants[${participantCount}][gender]" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <select name="participants[${participantCount}][role]" class="form-control" required style="margin-top: 0.5rem;">
                        <option value="complainant">Complainant</option>
                        <option value="respondent">Respondent</option>
                        <option value="witness">Witness</option>
                    </select>
                </div>
                <button type="button" class="btn btn-danger" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            participantContainer.appendChild(participantDiv);
            participantCount++;
        });
    });
    </script>
</body>
</html>