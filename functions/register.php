<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set the error log path to an absolute path
$logPath = 'C:/xampp/php/logs/php_error.log';
ini_set('error_log', $logPath);

// Test if we can write to the error log
error_log("=== REGISTRATION DEBUG START ===");

// Function to write debug logs
function writeDebugLog($message) {
    global $logPath;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    error_log($logMessage);
}

session_start();
require '../config/dbconn.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use Dotenv\Dotenv;

// Include the PHPMailer autoloader
require_once '../vendor/autoload.php';
require_once 'email_template.php';

// ------------------------------------------------------
// Email Verification: Process link click with token
// ------------------------------------------------------
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    // Prepare statement to find user with the provided token
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE verification_token = :token AND email_verified_at IS NULL AND verification_expiry > NOW()");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if ($user) {
        $user_id = $user['id'];
        $userEmail = $user['email'];

        // Update user record to mark as verified and clear token data
        $stmt2 = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), verification_token = NULL, verification_expiry = NULL WHERE id = :user_id");
        if ($stmt2->execute([':user_id' => $user_id])) {
            // Prepare and send confirmation email to user
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'ibarangay.system@gmail.com';
                $mail->Password   = 'nxxn vxyb kxum cuvd';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                $mail->addAddress($userEmail);

                $mail->isHTML(true);
                $mail->Subject = 'Registration Completed';
                $mail->Body    = 'Congratulations! Your email has been verified and your account is now active. You have been registered successfully.';
                $mail->AltBody = 'Congratulations! Your email has been verified and your account is now active.';
                $mail->send();
            } catch (Exception $e) {
                // Log the error if needed, but do not block verification
            }
            $message = "Your email has been verified successfully!";
            $icon = "success";
        } else {
            $message = "Update failed.";
            $icon = "error";
        }
    } else {
        $message = "Invalid or expired verification token.";
        $icon = "error";
    }

    // Display the result to the user with SweetAlert
    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Email Verification</title>
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    </head>
    <body>
        <script>
            Swal.fire({
                icon: '$icon',
                title: '" . ($icon === 'success' ? 'Verified' : 'Error') . "',
                text: '$message'
            }).then(() => {
                window.location.href = '../pages/login.php';
            });
        </script>
    </body>
    </html>";
    exit();
}

// ------------------------------------------------------
// Process ID Document with Google Document AI
// ------------------------------------------------------
function processIdWithDocumentAI() {
    // HTML form for uploading ID document
    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>ID Document Processing</title>
        <link rel="stylesheet" href="../styles/register.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            .id-upload-container {
                max-width: 800px;
                margin: 20px auto;
                padding: 20px;
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            .upload-section {
                border: 2px dashed #ccc;
                border-radius: 5px;
                padding: 20px;
                text-align: center;
                margin-bottom: 20px;
                cursor: pointer;
            }
            .upload-section:hover {
                border-color: #4e73df;
                background-color: #f8f9fc;
            }
            .upload-section i {
                font-size: 48px;
                color: #4e73df;
                margin-bottom: 10px;
            }
            #id_preview {
                max-width: 100%;
                max-height: 300px;
                display: none;
                margin: 10px auto;
                border-radius: 5px;
            }
            .results-container {
                margin-top: 20px;
                display: none;
            }
            .loading-spinner {
                display: none;
                text-align: center;
                padding: 20px;
            }
            .loading-spinner i {
                font-size: 48px;
                color: #4e73df;
            }            .data-field {
                display: flex;
                padding: 10px;
                border-bottom: 1px solid #eee;
                align-items: flex-start;
            }
            .field-name {
                font-weight: bold;
                width: 150px;
                padding-top: 6px;
            }.field-value {
                flex-grow: 1;
            }
            .readonly-textarea {
                width: 100%;
                min-height: 80px;
                border: 1px solid #eee;
                border-radius: 4px;
                padding: 8px;
                background-color: #f8f9fc;
                font-family: inherit;
                resize: none;
                color: #333;
            }
            .back-btn {
                margin-top: 20px;
                background-color: #4e73df;
                color: white;
                border: none;
                padding: 10px 15px;
                border-radius: 5px;
                cursor: pointer;
            }
            .back-btn:hover {
                background-color: #2e59d9;
            }
        </style>
    </head>
    <body>
        <div class="id-upload-container">
            <h2>ID Document Processing</h2>
            <p>Upload your ID document to automatically extract information using Google Document AI.</p>
            
            <form id="idUploadForm" enctype="multipart/form-data">
                <div class="upload-section" id="dropZone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Drag and drop your ID document here or click to browse</p>
                    <input type="file" id="id_document" name="id_document" accept="image/*,.pdf" hidden>
                </div>
                <img id="id_preview" alt="ID Preview">
                
                <div class="loading-spinner" id="loadingSpinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Processing your document with Google Document AI...</p>
                </div>
                
                <div class="results-container" id="resultsContainer">
                    <h3>Extracted Information</h3>
                    <div id="extractedData"></div>
                </div>
                
                <button type="button" class="back-btn" onclick="window.location.href='../pages/register.php'">Back to Registration</button>
            </form>
        </div>
        
        <script>
            // Set up the drag and drop functionality
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('id_document');
            const preview = document.getElementById('id_preview');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const resultsContainer = document.getElementById('resultsContainer');
            const extractedDataDiv = document.getElementById('extractedData');
            
            // Click to select file
            dropZone.addEventListener('click', () => fileInput.click());
            
            // Drag and drop events
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.style.borderColor = '#4e73df';
                dropZone.style.backgroundColor = '#f8f9fc';
            });
            
            dropZone.addEventListener('dragleave', () => {
                dropZone.style.borderColor = '#ccc';
                dropZone.style.backgroundColor = '#fff';
            });
            
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.style.borderColor = '#ccc';
                dropZone.style.backgroundColor = '#fff';
                
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    handleFileSelected();
                }
            });
            
            // Handle file selection
            fileInput.addEventListener('change', handleFileSelected);
            
            function handleFileSelected() {
                const file = fileInput.files[0];
                if (file) {
                    // Show preview if it's an image
                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            preview.src = e.target.result;
                            preview.style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                    } else {
                        preview.style.display = 'none';
                    }
                    
                    // Process the file with Document AI
                    processFile(file);
                }
            }
            
            function processFile(file) {
                const formData = new FormData();
                formData.append('govt_id', file);
                
                // Show loading spinner
                loadingSpinner.style.display = 'block';
                resultsContainer.style.display = 'none';
                
                // Send to server for processing
                fetch('../scripts/process_id.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading spinner
                    loadingSpinner.style.display = 'none';
                    
                    if (data.error) {
                        // Show error
                        Swal.fire({
                            icon: 'error',
                            title: 'Processing Error',
                            text: data.error
                        });
                        return;
                    }
                    
                    // Display results
                    displayResults(data.data);
                    resultsContainer.style.display = 'block';
                })
                .catch(error => {
                    loadingSpinner.style.display = 'none';
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while processing the document.'
                    });
                    console.error('Error:', error);
                });
            }
            
            function displayResults(data) {
                extractedDataDiv.innerHTML = '';                    // Define the fields to display (based on the image you provided)
                const fields = [
                    { key: 'address', label: 'Address' },
                    { key: 'date_of_birth', label: 'Date of Birth' },
                    { key: 'full_name', label: 'Full Name' },
                    { key: 'given_name', label: 'Given Name' },
                    { key: 'id_number', label: 'ID Number' },
                    { key: 'last_name', label: 'Last Name' },
                    { key: 'middle_name', label: 'Middle Name' },
                    { key: 'type_of_id', label: 'Type of ID' }
                ];
                  // Create HTML elements for each field
                fields.forEach(field => {
                    const value = data[field.key] || 'Not detected';
                    
                    const fieldDiv = document.createElement('div');
                    fieldDiv.className = 'data-field';
                    
                    const nameDiv = document.createElement('div');
                    nameDiv.className = 'field-name';
                    nameDiv.textContent = field.label + ':';
                    
                    const valueDiv = document.createElement('div');
                    valueDiv.className = 'field-value';
                    
                    if (field.key === 'address') {
                        // For address, create a textarea that's readonly in this context
                        const textArea = document.createElement('textarea');
                        textArea.readOnly = true;
                        textArea.className = 'readonly-textarea';
                        textArea.value = value;
                        textArea.rows = 3;
                        valueDiv.appendChild(textArea);
                    } else {
                        // For other fields, display as text
                        valueDiv.textContent = value;
                    }
                    
                    fieldDiv.appendChild(nameDiv);
                    fieldDiv.appendChild(valueDiv);
                    extractedDataDiv.appendChild(fieldDiv);
                });
            }
        </script>
    </body>
    </html>
    HTML;
    
    echo $html;
    exit();
}

// Add this function before the registration process
function verifyPersonInCensus($pdo, $data) {
    try {
        $mismatchDetails = [];
        
        // First check if person exists in both records
        $stmt = $pdo->prepare("
            SELECT 'census' as source, p.id, p.first_name, p.middle_name, p.last_name, p.birth_date, p.gender, a.barangay_id,
                   pi.other_id_number as census_id_number
            FROM persons p
            LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE
            LEFT JOIN person_identification pi ON p.id = pi.person_id
            WHERE LOWER(TRIM(p.last_name)) = LOWER(TRIM(:census_last_name))
            AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(:census_first_name))
            AND p.birth_date = :census_birth_date
            AND p.is_archived = FALSE
            UNION ALL
            SELECT 'temporary' as source, id, first_name, middle_name, last_name, date_of_birth as birth_date, NULL as gender, barangay_id,
                   id_number as census_id_number
            FROM temporary_records
            WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:temp_last_name))
            AND LOWER(TRIM(first_name)) = LOWER(TRIM(:temp_first_name))
            AND date_of_birth = :temp_birth_date
        ");

        $params = [
            ':census_first_name' => trim($data['first_name']),
            ':census_last_name' => trim($data['last_name']),
            ':census_birth_date' => $data['birth_date'],
            ':temp_first_name' => trim($data['first_name']),
            ':temp_last_name' => trim($data['last_name']),
            ':temp_birth_date' => $data['birth_date']
        ];

        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if person exists in both records
        if (count($records) > 1) {
            return [
                'success' => false,
                'message' => 'Person found in both census and temporary records. Please contact the barangay office for assistance.',
                'debug_info' => ['Person exists in multiple records']
            ];
        }

        // If person exists in exactly one record
        if (count($records) === 1) {
            $record = $records[0];
            
            // Check if ID number matches
            if (strtolower(trim($record['census_id_number'])) === strtolower(trim($data['id_number']))) {
                return [
                    'success' => true,
                    'person_id' => $record['id'],
                    'barangay_id' => $record['barangay_id'],
                    'message' => 'Person verified in ' . $record['source'] . ' records.',
                    'source' => $record['source']
                ];
            } else {
                $mismatchDetails[] = "ID number mismatch in " . $record['source'] . " records";
            }
        }

        // Check for partial matches in both records
        $partialParams = [
            ':first_name' => trim($data['first_name']),
            ':last_name' => trim($data['last_name']),
            ':birth_date' => $data['birth_date'],
            ':id_number' => trim($data['id_number'])
        ];
        
        $stmt = $pdo->prepare("
            SELECT 'census' as source, first_name, last_name, birth_date, pi.other_id_number as id_number
            FROM persons p
            LEFT JOIN person_identification pi ON p.id = pi.person_id
            WHERE (LOWER(TRIM(last_name)) = LOWER(TRIM(:last_name))
            OR LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name))
            OR birth_date = :birth_date
            OR LOWER(TRIM(pi.other_id_number)) = LOWER(TRIM(:id_number)))
            AND p.is_archived = FALSE
            UNION ALL
            SELECT 'temporary' as source, first_name, last_name, date_of_birth as birth_date, id_number
            FROM temporary_records
            WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:last_name))
            OR LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name))
            OR date_of_birth = :birth_date
            OR LOWER(TRIM(id_number)) = LOWER(TRIM(:id_number))
        ");
        
        $stmt->execute($partialParams);
        $partialMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($partialMatches)) {
            foreach ($partialMatches as $match) {
                if (strtolower(trim($match['last_name'])) !== strtolower(trim($data['last_name']))) {
                    $mismatchDetails[] = "Last name mismatch in " . $match['source'] . " records: Expected '" . $match['last_name'] . "', Got '" . $data['last_name'] . "'";
                }
                if (strtolower(trim($match['first_name'])) !== strtolower(trim($data['first_name']))) {
                    $mismatchDetails[] = "First name mismatch in " . $match['source'] . " records: Expected '" . $match['first_name'] . "', Got '" . $data['first_name'] . "'";
                }
                if ($match['birth_date'] !== $data['birth_date']) {
                    $mismatchDetails[] = "Birth date mismatch in " . $match['source'] . " records: Expected '" . $match['birth_date'] . "', Got '" . $data['birth_date'] . "'";
                }
                if (strtolower(trim($match['id_number'])) !== strtolower(trim($data['id_number']))) {
                    $mismatchDetails[] = "ID number mismatch in " . $match['source'] . " records: Expected '" . $match['id_number'] . "', Got '" . $data['id_number'] . "'";
                }
            }
        }

        return [
            'success' => false,
            'message' => 'No matching record found. Please ensure your information matches the records.',
            'debug_info' => $mismatchDetails
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'An error occurred during verification. Please try again later.',
            'debug_info' => ['Database error: ' . $e->getMessage()]
        ];
    }
}

// ------------------------------------------------------
// Registration Process: Creating a New User Account
// ------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve and trim form inputs
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirmPassword'];
    $person_id = isset($_POST['person_id']) ? trim($_POST['person_id']) : null;
    $barangay_id = null; // Initialize barangay_id
    $errors = [];

    // Debug output
    writeDebugLog("POST data received: " . print_r($_POST, true));

    // Accept birth_date as Y-m-d (from HTML5 date input)
    $birth_date = $_POST['birth_date'];
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $birth_date)) {
        writeDebugLog("Received birth date: " . $birth_date);
    } else {
        writeDebugLog("Invalid birth date format. Input was: " . $_POST['birth_date']);
        $errors[] = "Invalid birth date format. Please use YYYY-MM-DD format.";
    }

    // Enhanced verification: allow registration in selected barangay, block only if both census and temporary in the SAME barangay
    $verificationData = [
        'first_name' => $_POST['first_name'],
        'middle_name' => $_POST['middle_name'],
        'last_name' => $_POST['last_name'],
        'birth_date' => $birth_date,
        'id_number' => $_POST['id_number']
    ];
    $selected_barangay_id = isset($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null;
    $record_source = $_POST['record_source'] ?? null;
    // Fetch all census and temporary records for this person
    $censusSql = "
        SELECT 'census' as source, p.id, a.barangay_id
        FROM persons p
        LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = 1
        WHERE LOWER(TRIM(p.last_name)) = LOWER(TRIM(:last_name))
        AND LOWER(TRIM(p.first_name)) = LOWER(TRIM(:first_name))
        AND p.birth_date = :birth_date
    ";
    $tempSql = "
        SELECT 'temporary' as source, id, barangay_id
        FROM temporary_records
        WHERE LOWER(TRIM(last_name)) = LOWER(TRIM(:last_name))
        AND LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name))
        AND date_of_birth = :birth_date
    ";
    $params = [
        ':first_name' => trim($_POST['first_name']),
        ':last_name' => trim($_POST['last_name']),
        ':birth_date' => $birth_date
    ];
    $censusStmt = $pdo->prepare($censusSql);
    $censusStmt->execute($params);
    $censusRecords = $censusStmt->fetchAll(PDO::FETCH_ASSOC);
    $tempStmt = $pdo->prepare($tempSql);
    $tempStmt->execute($params);
    $tempRecords = $tempStmt->fetchAll(PDO::FETCH_ASSOC);
    // Check for both census and temporary in the SAME barangay
    $censusBarangayIds = array_column($censusRecords, 'barangay_id');
    $tempBarangayIds = array_column($tempRecords, 'barangay_id');
    $overlap = array_intersect($censusBarangayIds, $tempBarangayIds);
    if ($selected_barangay_id && in_array($selected_barangay_id, $overlap)) {
        $errors[] = "Person found in both census and temporary records in the selected barangay. Please contact the barangay office for assistance.";
    }
    // If selected barangay has a census record, always prefer that
    $selectedCensus = null;
    foreach ($censusRecords as $rec) {
        if ($rec['barangay_id'] == $selected_barangay_id) {
            $selectedCensus = $rec;
            break;
        }
    }
    if ($selectedCensus) {
        $person_id = $selectedCensus['id'];
        $record_source = 'census';
    } else {
        // Otherwise, use the temporary record for the selected barangay
        foreach ($tempRecords as $rec) {
            if ($rec['barangay_id'] == $selected_barangay_id) {
                $person_id = $rec['id'];
                $record_source = 'temporary';
                break;
            }
        }
    }

    // Verify person_id is provided - this confirms the person exists in the database
    if (empty($person_id)) {
        $errors[] = "Identity verification failed. Only verified residents can register.";
    } else {
        // Check if person exists and is not already linked to a user account
        $stmt = $pdo->prepare("
            SELECT p.id, p.user_id, p.first_name, p.last_name, p.gender, a.barangay_id 
            FROM persons p 
            LEFT JOIN addresses a ON p.id = a.person_id AND a.is_primary = TRUE 
            WHERE p.id = ? AND p.is_archived = FALSE
        ");
        $stmt->execute([$person_id]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$person) {
            $errors[] = "The provided identity verification has failed.";
        } elseif (!empty($person['user_id'])) {
            $errors[] = "This identity is already linked to an existing account. Please log in or use the password recovery option.";
        } else {
            // Store the person's information for later use
            $personData = $person;
            $selected_barangay_id = $person['barangay_id'];
        }
    }

    // Validate email
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    // Validate password
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }

    // Confirm password check
    if (empty($confirmPassword)) {
        $errors[] = "Please confirm your password.";
    } elseif ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }

    // Validate ID upload
    if (!isset($_FILES['govt_id']) || $_FILES['govt_id']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Government ID is required for registration.";
    } else {
        // Check valid file types
        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $_FILES['govt_id']['tmp_name']);
        finfo_close($file_info);
        
        if (!in_array($mime_type, $allowed_types)) {
            $errors[] = "Invalid file type. Only JPG, PNG, and PDF are allowed.";
        }
    }

    // Validate ID type
    if (empty($_POST['id_type'])) {
        $errors[] = "ID type is required.";
    } else {
        $idType = trim($_POST['id_type']);
        if (strlen($idType) > 50) {
            $errors[] = "ID type must not exceed 50 characters.";
        }
    }

    // Validate ID expiration date only if provided
    if (!empty($_POST['id_expiration'])) {
        $expirationDate = strtotime($_POST['id_expiration']);
        if ($expirationDate === false) {
            $errors[] = "Invalid ID expiration date format.";
        } elseif ($expirationDate < strtotime('today')) {
            $errors[] = "ID has already expired. Please provide a valid ID.";
        }
    }

    // Collect extracted data from ID document (if available)
    $extractedData = [];
    $extractableFields = ['full_name', 'given_name', 'middle_name', 'last_name', 'address', 
                         'date_of_birth', 'id_number', 'type_of_id'];
    
    foreach ($extractableFields as $field) {
        $key = 'extracted_' . $field;
        if (isset($_POST[$key])) {
            $extractedData[$field] = $_POST[$key];
        }
    }

    // Set role_id for residents (every new user gets role_id = 3)
    $role_id = 8;

    if (empty($errors)) {
        // Hash the password
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        // Generate a verification token and set expiry to 24 hours
        $verificationToken = bin2hex(random_bytes(16));
        $verificationExpiry = date('Y-m-d H:i:s', strtotime('+1 day'));

        // Check if the email is already registered
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_active = TRUE");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $errors[] = "This email is already registered.";
        }

        if (empty($errors)) {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Get the verified person's information to use in the user account
                $personStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, gender FROM persons WHERE id = ? AND is_archived = FALSE");
                $personStmt->execute([$person_id]);
                $personData = $personStmt->fetch(PDO::FETCH_ASSOC);

                // Get the phone from the form
                $phone = trim($_POST['phone'] ?? '');
                
                // Read the ID image file into a variable
                $idImageData = null;
                if (isset($_FILES['govt_id']) && $_FILES['govt_id']['error'] === UPLOAD_ERR_OK) {
                    $idImageData = file_get_contents($_FILES['govt_id']['tmp_name']);
                }

                // Insert the new user record with all available information
                $stmt = $pdo->prepare("INSERT INTO users (
                    email, 
                    phone,
                    password, 
                    role_id,
                    barangay_id, 
                    verification_token, 
                    verification_expiry,
                    govt_id_image,
                    id_expiration_date,
                    id_type,
                    id_number,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)");

                if (!$stmt->execute([
                    $email, 
                    $phone,
                    $passwordHash, 
                    $role_id,
                    $selected_barangay_id,
                    $verificationToken, 
                    $verificationExpiry,
                    $idImageData,
                    !empty($_POST['id_expiration']) ? $_POST['id_expiration'] : null,
                    $_POST['id_type'] ?? null,
                    $_POST['id_number'] ?? null
                ])) {
                    throw new Exception("Failed to create user account.");
                }
                
                // Get the newly created user ID
                $user_id = $pdo->lastInsertId();
                
                // Link the user to the verified person record
                $sql = "UPDATE persons SET user_id = ? WHERE id = ? AND is_archived = FALSE";
                $stmt = $pdo->prepare($sql);
                if (!$stmt->execute([$user_id, $person_id])) {
                    throw new Exception("Failed to link user account to person record.");
                }
                
                // Handle ID document file - store in filesystem as backup
                if (isset($_FILES['govt_id']) && $_FILES['govt_id']['error'] === UPLOAD_ERR_OK) {                    
                    // Create a unique filename for the ID document
                    $fileExt = pathinfo($_FILES['govt_id']['name'], PATHINFO_EXTENSION);
                    $newFilename = 'id_user_' . $user_id . '_' . time() . '.' . $fileExt;
                    
                    // Move the uploaded file to the uploads directory
                    $uploadPath = __DIR__ . '/../uploads/' . $newFilename;
                    move_uploaded_file($_FILES['govt_id']['tmp_name'], $uploadPath);
                }
                
                // Commit transaction
                $pdo->commit();
                
                // Create the verification link
                $verificationLink = "https://localhost/Ibarangay/functions/register.php?token=" . $verificationToken;
                
                // Send verification email using PHPMailer
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'ibarangay.system@gmail.com';
                    $mail->Password   = 'nxxn vxyb kxum cuvd';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('iBarangay@gmail.com', 'iBarangay System');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'Email Verification';
                    $mail->Body    = getVerificationEmailTemplate($verificationLink);
                    $mail->send();

                    $message = "Registration successful! Please check your email to verify your account.";
                    $icon = "success";
                    $redirectUrl = "../pages/login.php";
                    
                    // Now show the success message with button to return to login
                    echo "<!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <title>Registration Success</title>
                        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
                        <style>
                            body, .swal2-popup {
                                font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                            }
                            .swal2-title, .swal2-html-container {
                                font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                            }
                            .btn-login {
                                background-color: #3b82f6;
                                color: white;
                                border: none;
                                padding: 10px 25px;
                                border-radius: 5px;
                                font-size: 16px;
                                font-weight: 600;
                                cursor: pointer;
                                transition: all 0.3s;
                                box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2);
                                font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                            }
                            .btn-login:hover {
                                background-color: #2563eb;
                                transform: translateY(-2px);
                                box-shadow: 0 6px 8px rgba(37, 99, 235, 0.3);
                            }
                            .btn-login:active {
                                transform: translateY(0);
                            }
                        </style>
                    </head>
                    <body>
                        <script>
                            Swal.fire({
                                icon: 'success',
                                title: 'Registration Successful!',
                                html: 'Your account has been created and a verification email has been sent to your address.<br>Please check your email to verify your account.<br><br><button class=\"btn-login\" onclick=\"window.location.href=\'../pages/login.php\'\">Return to Login</button>',
                                showConfirmButton: false,
                                footer: '<a href=\"../functions/resend_verification.php?email=" . urlencode($email) . "\">Resend verification email?</a>'
                            });
                        </script>
                    </body>
                    </html>";
                    exit();
                } catch (Exception $e) {
                    // Close the loading animation and show error message
                    echo "<!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <title>Email Error</title>
                        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
                        <style>
                            body, .swal2-popup {
                                font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                            }
                            .swal2-title, .swal2-html-container {
                                font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                            }
                            .btn-login {
                                background-color: #3b82f6;
                                color: white;
                                border: none;
                                padding: 10px 25px;
                                border-radius: 5px;
                                font-size: 16px;
                                font-weight: 600;
                                cursor: pointer;
                                transition: all 0.3s;
                                box-shadow: 0 4px 6px rgba(59, 130, 246, 0.2);
                                font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                            }
                            .btn-login:hover {
                                background-color: #2563eb;
                                transform: translateY(-2px);
                                box-shadow: 0 6px 8px rgba(37, 99, 235, 0.3);
                            }
                            .btn-login:active {
                                transform: translateY(0);
                            }
                        </style>
                    </head>
                    <body>
                        <script>
                            Swal.fire({
                                icon: 'error',
                                title: 'Email Verification Error',
                                html: 'We couldn\\'t send the verification email. Your account has been created, but you need to verify it.<br><br><button class=\"btn-login\" onclick=\"window.location.href=\\'../pages/login.php\\'\">Return to Login</button>',
                                showConfirmButton: false,
                                footer: '<a href=\"../functions/resend_verification.php?email=" . urlencode($email) . "\">Try sending verification email again?</a>'
                            });
                        </script>
                    </body>
                    </html>";
                    exit();
                }
            } catch (Exception $e) {
                // If anything fails, roll back the transaction
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }

    if (!empty($errors)) {
        // Format the error message with detailed information
        $errorMessage = implode("\n", $errors);
        if (isset($verificationResult['debug_info']) && !empty($verificationResult['debug_info'])) {
            $errorMessage .= "\n\nDetailed Information:\n" . implode("\n", $verificationResult['debug_info']);
        }
        
        echo "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Registration Error</title>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <style>
                body, .swal2-popup {
                    font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                }
                .swal2-title, .swal2-html-container {
                    font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                }
                .error-details {
                    text-align: left;
                    margin-top: 15px;
                    padding: 10px;
                    background-color: #f8f9fc;
                    border-radius: 5px;
                    font-size: 14px;
                }
            </style>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Verification Failed',
                    html: `Your information does not match our records.<br><br>
                           <div class='error-details'>
                           <strong>Please check the following:</strong><br>
                           " . nl2br(htmlspecialchars($errorMessage)) . "
                           </div>`,
                    confirmButtonText: 'Try Again',
                    confirmButtonColor: '#3b82f6'
                }).then(() => {
                    window.location.href = '../pages/register.php';
                });
            </script>
        </body>
        </html>";
        exit();
    } else {
        echo "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Registration Success</title>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: '$message',
                    footer: '<a href=\"../functions/resend_verification.php?email=" . urlencode($email) . "\">Resend verification email?</a>'
                }).then(() => {
                    window.location.href = '$redirectUrl';
                });
            </script>
        </body>
        </html>";
        exit();
    }
} else {
    // Direct access without POST data - redirect to registration page
    header("Location: ../pages/register.php");
    exit();
}
?>