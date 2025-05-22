<?php
session_start();
require '../config/dbconn.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use Dotenv\Dotenv;

// Include the PHPMailer autoloader
require_once '../vendor/autoload.php';

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
                $mail->Username   = 'barangayhub2@gmail.com';
                $mail->Password   = 'eisy hpjz rdnt bwrp';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;

                $mail->setFrom('noreply@barangayhub.com', 'Barangay Hub');
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
// Registration Process: Creating a New User Account
// ------------------------------------------------------
elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve and trim form inputs
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirmPassword'];
    $person_id = isset($_POST['person_id']) ? trim($_POST['person_id']) : null;
    $errors = [];

    // Verify person_id is provided - this confirms the person exists in the database
    if (empty($person_id)) {
        $errors[] = "Identity verification failed. Only verified residents can register.";
    } else {
        // Verify the person_id exists and is not already linked to a user
        $stmt = $pdo->prepare("SELECT id, user_id FROM persons WHERE id = ?");
        $stmt->execute([$person_id]);
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$person) {
            $errors[] = "The provided identity verification has failed.";
        } elseif (!empty($person['user_id'])) {
            $errors[] = "This identity is already linked to an existing account. Please log in or use the password recovery option.";
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
    }    // Confirm password check
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
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $errors[] = "This email is already registered.";
        }        if (empty($errors)) {
            // Show loading animation immediately after verification
            echo "<!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <title>Processing Registration</title>
                <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
                <style>
                    body, .swal2-popup {
                        font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                    }
                    .swal2-title, .swal2-html-container {
                        font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                    }
                    .email-animation {
                        text-align: center;
                        margin: 20px auto;
                    }
                    .email-animation i {
                        font-size: 40px;
                        color: #3b82f6;
                    }
                    @keyframes fly {
                        0% { transform: translateY(0) scale(0.8); opacity: 0; }
                        20% { transform: translateY(-5px) scale(0.9); opacity: 0.6; }
                        50% { transform: translateY(-15px) scale(1); opacity: 1; }
                        80% { transform: translateY(-25px) scale(0.9); opacity: 0.6; }
                        100% { transform: translateY(-35px) scale(0.8); opacity: 0; }
                    }
                    .envelope {
                        animation: fly 2s infinite;
                        display: inline-block;
                    }
                    .btn-login {
                        background-color: #3b82f6;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 5px;
                        font-size: 16px;
                        cursor: pointer;
                        margin-top: 20px;
                        transition: background-color 0.3s;
                        font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif !important;
                    }
                    .btn-login:hover {
                        background-color: #2563eb;
                    }
                </style>
                <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' />
            </head>
            <body>
                <script>
                    let timerInterval;
                    Swal.fire({
                        title: 'Processing Registration',
                        html: '<div class=\"email-animation\"><i class=\"fas fa-envelope envelope\"></i></div><p>Please wait while we process your registration...</p>',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                </script>
            </body>
            </html>";
            flush();
            ob_flush();
        
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Get the verified person's information to use in the user account
                $personStmt = $pdo->prepare("SELECT first_name, middle_name, last_name, gender FROM persons WHERE id = ?");
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
                    first_name,
                    last_name,
                    gender,
                    verification_token, 
                    verification_expiry,
                    govt_id_image,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)");

                if (!$stmt->execute([
                    $email, 
                    $phone,
                    $passwordHash, 
                    $role_id, 
                    $personData['first_name'],
                    $personData['last_name'],
                    $personData['gender'],
                    $verificationToken, 
                    $verificationExpiry,
                    $idImageData
                ])) {
                    throw new Exception("Failed to create user account.");
                }
                
                // Get the newly created user ID
                $user_id = $pdo->lastInsertId();
                
                // Link the user to the verified person record
                $sql = "UPDATE persons SET user_id = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                if (!$stmt->execute([$user_id, $person_id])) {
                    throw new Exception("Failed to link user account to person record.");
                }
                
                // Handle ID document file - store in filesystem as backup
                // Note: We've already stored the image in the database above
                if (isset($_FILES['govt_id']) && $_FILES['govt_id']['error'] === UPLOAD_ERR_OK) {                    
                    // Create a unique filename for the ID document
                    $fileExt = pathinfo($_FILES['govt_id']['name'], PATHINFO_EXTENSION);
                    $newFilename = 'id_user_' . $user_id . '_' . time() . '.' . $fileExt;
                    
                    // Move the uploaded file to the uploads directory
                    $uploadPath = __DIR__ . '/../uploads/' . $newFilename;
                    move_uploaded_file($_FILES['govt_id']['tmp_name'], $uploadPath);
                    
                    // Store the file path in the person_identification table as a backup
                    $checkStmt = $pdo->prepare("SELECT id FROM person_identification WHERE person_id = ?");
                    $checkStmt->execute([$person_id]);
                    
                    if ($checkStmt->rowCount() > 0) {
                        // Update existing record
                        $idStmt = $pdo->prepare("UPDATE person_identification SET id_image_path = ? WHERE person_id = ?");
                        $idStmt->execute(['uploads/' . $newFilename, $person_id]);
                    } else {
                        // Create new record
                        $idStmt = $pdo->prepare("INSERT INTO person_identification (person_id, id_image_path) VALUES (?, ?)");
                        $idStmt->execute([$person_id, 'uploads/' . $newFilename]);
                    }
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
                    $mail->Username   = 'barangayhub2@gmail.com';
                    $mail->Password   = 'eisy hpjz rdnt bwrp';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('noreply@Ibarangay.com', 'Barangay Hub');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'Email Verification';
                    $mail->Body    = "Thank you for registering. Please verify your email by clicking the following link: <a href='$verificationLink'>$verificationLink</a><br>Your link will expire in 24 hours.";
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
        $errorMessage = implode("\n", $errors);
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
            </style>
        </head>
        <body>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '$errorMessage'
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
?>