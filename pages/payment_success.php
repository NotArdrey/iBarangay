<?php
session_start();
require_once "../config/dbconn.php";
require_once "../config/paymongo.php";

// Debug mode for development
$debug = true;
$debugInfo = [];

// Check for session and pending request
if (!isset($_SESSION['user_id'])) {
    $debugInfo[] = "No user session found";
    header('Location: ../pages/user_dashboard.php');
    exit;
}

if (!isset($_SESSION['pending_document_request'])) {
    $debugInfo[] = "No pending document request in session";
    header('Location: ../pages/services.php');
    exit;
}

// Get any available info from URL
$checkoutSessionId = $_GET['session_id'] ?? $_GET['id'] ?? '';

// If no ID in URL, try to get from session
if (empty($checkoutSessionId) && isset($_SESSION['pending_document_request']['checkout_id'])) {
    $debugInfo[] = "No session_id in URL, using ID from session";
    $checkoutSessionId = $_SESSION['pending_document_request']['checkout_id'];
}

if (empty($checkoutSessionId)) {
    $debugInfo[] = "Could not determine checkout session ID";
    $_SESSION['error'] = 'Invalid payment session. Please try again.';
    if ($debug) {
        $_SESSION['debug_info'] = $debugInfo;
    }
    header('Location: ../pages/services.php');
    exit;
}

try {
    // Get pending request data
    $pendingRequest = $_SESSION['pending_document_request'];
    $debugInfo[] = "Found pending request: " . json_encode($pendingRequest);
    
    // Get user info including barangay_id
    $stmt = $pdo->prepare("
        SELECT p.id as person_id, u.barangay_id, u.email 
        FROM persons p 
        JOIN users u ON p.user_id = u.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userInfo = $stmt->fetch();

    if (!$userInfo) {
        $debugInfo[] = "User information not found";
        throw new Exception('User information not found');
    }

    // In development environment, bypass payment verification
    // IMPORTANT: Remove this in production!
    if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
        $debugInfo[] = "Localhost detected - bypassing payment verification";
        $paymentData = [
            'id' => $checkoutSessionId,
            'attributes' => [
                'payment_status' => 'paid'
            ]
        ];
    } else {
        // In production, verify with PayMongo
        $paymentData = verifyPayMongoPayment($checkoutSessionId, $userInfo['barangay_id']);
    }
    
    if (!$paymentData || $paymentData['attributes']['payment_status'] !== 'paid') {
        $debugInfo[] = "Payment verification failed: " . json_encode($paymentData ?? 'No payment data');
        throw new Exception('Payment verification failed');
    }

    $formData = $pendingRequest['form_data'];

    // Get document type information
    $stmt = $pdo->prepare("SELECT code FROM document_types WHERE id = ?");
    $stmt->execute([$pendingRequest['document_type_id']]);
    $documentType = $stmt->fetch();

    // Begin transaction
    $pdo->beginTransaction();

    try {
        // Insert document request
        $stmt = $pdo->prepare("
            INSERT INTO document_requests 
            (document_type_id, person_id, user_id, barangay_id, status, price, 
             delivery_method, payment_method, payment_status, payment_reference, 
             paymongo_checkout_id, payment_date, request_date, purpose,
             business_name, business_location, business_nature, business_type) 
            VALUES (?, ?, ?, ?, 'pending', ?, ?, 'online', 'paid', ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?)
        ");
        
        // Determine purpose and business fields based on document type and form data
        $purpose = '';
        $businessName = null;
        $businessLocation = null;
        $businessNature = null;
        $businessType = null;
        
        switch($documentType['code']) {
            case 'barangay_clearance':
                $purpose = $formData['purposeClearance'] ?? 'General purposes';
                break;
            case 'proof_of_residency':
                $purpose = 'Duration: ' . ($formData['residencyDuration'] ?? '') . 
                          '; Purpose: ' . ($formData['residencyPurpose'] ?? '');
                break;
            case 'barangay_indigency':
                $purpose = $formData['indigencyReason'] ?? 'Assistance';
                break;
            case 'business_permit_clearance':
                $purpose = 'Business Permit Application';
                $businessName = $formData['businessName'] ?? '';
                $businessLocation = $formData['businessAddress'] ?? '';
                $businessNature = $formData['businessPurpose'] ?? '';
                $businessType = $formData['businessType'] ?? '';
                break;
            case 'first_time_job_seeker':
                $purpose = $formData['jobSeekerPurpose'] ?? 'Job application';
                break;
            default:
                $purpose = 'General purposes';
                break;
        }

        $stmt->execute([
            $pendingRequest['document_type_id'],
            $userInfo['person_id'],
            $_SESSION['user_id'],
            $userInfo['barangay_id'],
            $pendingRequest['payment_amount'],
            $pendingRequest['delivery_method'],
            $paymentData['id'],
            $checkoutSessionId,
            $purpose,
            $businessName,
            $businessLocation,
            $businessNature,
            $businessType
        ]);
        $requestId = $pdo->lastInsertId();

        $pdo->commit();
        
        // Clear pending request data
        unset($_SESSION['pending_document_request']);

        $_SESSION['success'] = [
            'title' => 'Payment Successful',
            'message' => 'Your payment has been processed and document request submitted.',
            'processing' => 'Your document is now being processed and will be ready shortly.'
        ];
        
        // Instead of redirecting immediately, display SweetAlert
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Payment Successful</title>
            <!-- SweetAlert2 CSS -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
            <!-- SweetAlert2 JS -->
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        </head>
        <body>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'Payment Successful',
                        text: 'Your payment has been processed and document request submitted.',
                        footer: 'Your document is now being processed and will be ready shortly.',
                        confirmButtonColor: '#3085d6',
                        confirmButtonText: 'Continue'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = '../pages/user_dashboard.php';
                        }
                    });
                });
            </script>
        </body>
        </html>
        <?php
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'Payment processing error: ' . $e->getMessage();
    header('Location: ../pages/services.php');
    exit;
}
?>
