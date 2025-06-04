<?php
session_start();
require_once __DIR__ . '/../config/dbconn.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Cloud\DocumentAI\V1\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;

// Ensure the GOOGLE_APPLICATION_CREDENTIALS path is correct and secure
// It's best practice to store this path in an environment variable or a secure config file outside the webroot.
$google_credentials_path = 'C:\\xampp\\htdocs\\secured_credentials\\google_credentials.json'; // WARNING: Ensure this is NOT in a web-accessible directory.

$config = [
    'GOOGLE_APPLICATION_CREDENTIALS' => $google_credentials_path,
    'DOCUMENT_AI_PROJECT_ID' => 'western-dock-460409-f4', // Replace with your Project ID
    'DOCUMENT_AI_LOCATION' => 'us', // Replace with your Document AI processor location (e.g., 'us' or 'eu')
    'BUSINESS_PERMIT_PROCESSOR_ID' => 'b6fafb50084af0e', // Replace with your Business Permit Processor ID
];

if (file_exists($config['GOOGLE_APPLICATION_CREDENTIALS'])) {
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $config['GOOGLE_APPLICATION_CREDENTIALS']);
} else {
    error_log("FATAL: Google credentials file not found at: " . $config['GOOGLE_APPLICATION_CREDENTIALS']);
    echo json_encode(['success' => false, 'message' => 'Server configuration error: Document AI credentials missing or misconfigured. Please contact support.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['renewal_permit_file'])) {
    $uploadedFile = $_FILES['renewal_permit_file'];
    $loggedInUserId = $_POST['user_id'] ?? null;

    if (empty($loggedInUserId)) {
        echo json_encode(['success' => false, 'message' => 'User not logged in or session expired. Please log in again.']);
        exit;
    }

    // File Validation
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        error_log("File upload error for renewal: " . $uploadedFile['error']);
        echo json_encode(['success' => false, 'message' => 'File upload error: ' . $uploadedFile['error']]);
        exit;
    }

    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $fileMimeTypeValidation = mime_content_type($uploadedFile['tmp_name']);
    if (!in_array($fileMimeTypeValidation, $allowedMimeTypes)) {
        error_log("Invalid file type for renewal: " . $fileMimeTypeValidation);
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF, JPG, and PNG are allowed.']);
        exit;
    }

    if ($uploadedFile['size'] > 5 * 1024 * 1024) { // 5 MB
        error_log("File too large for renewal: " . $uploadedFile['size']);
        echo json_encode(['success' => false, 'message' => 'File is too large. Maximum 5MB allowed.']);
        exit;
    }

    $filePath = $uploadedFile['tmp_name'];
    $content = file_get_contents($filePath);
    $fileMimeType = mime_content_type($filePath); // Re-check for safety

    if ($content === false || $fileMimeType === false) {
        error_log("Server error reading renewal file content or MIME type.");
        echo json_encode(['success' => false, 'message' => 'Server error: Could not read uploaded file.']);
        exit;
    }

    try {
        $documentAiClient = new DocumentProcessorServiceClient();
        $processorNameString = $documentAiClient->processorName(
            $config['DOCUMENT_AI_PROJECT_ID'],
            $config['DOCUMENT_AI_LOCATION'],
            $config['BUSINESS_PERMIT_PROCESSOR_ID']
        );

        $rawDocument = (new RawDocument())
            ->setContent($content)
            ->setMimeType($fileMimeType);
        
        error_log("Calling Document AI for renewal. Processor: $processorNameString, MimeType: $fileMimeType");

        $processRequest = (new ProcessRequest())
            ->setName($processorNameString)
            ->setRawDocument($rawDocument);

        $result = $documentAiClient->processDocument($processRequest);
        $document = $result->getDocument();
        $documentAiClient->close();
        error_log("Document AI processing for renewal completed.");

        // --- DETAILED ENTITY LOGGING --- START ---
        $all_entities_log = "Document AI - All Extracted Entities for uploaded permit:\n";
        if ($document->getEntities() && count($document->getEntities()) > 0) {
            foreach ($document->getEntities() as $entity_idx => $log_entity) {
                $all_entities_log .= "  Entity #{$entity_idx}:\n";
                $all_entities_log .= "    Type: " . $log_entity->getType() . "\n";
                $all_entities_log .= "    Mention Text: " . $log_entity->getMentionText() . "\n";
                if ($log_entity->getNormalizedValue() && $log_entity->getNormalizedValue()->getText()) {
                    $all_entities_log .= "    Normalized Text: " . $log_entity->getNormalizedValue()->getText() . "\n";
                }
                $all_entities_log .= "    Confidence: " . $log_entity->getConfidence() . "\n";
            }
        } else {
            $all_entities_log .= "  No entities were extracted by Document AI.\n";
        }
        error_log($all_entities_log);
        // --- DETAILED ENTITY LOGGING --- END ---

        $extractedData = [];
        $extractedOwnerName = null;

        foreach ($document->getEntities() as $entity) {
            $entityType = trim(strtolower($entity->getType()));
            $entityText = trim($entity->getMentionText());
            $normalizedText = ($entity->getNormalizedValue() && trim($entity->getNormalizedValue()->getText())) ? trim($entity->getNormalizedValue()->getText()) : null;
            
            error_log("DocAI Entity (Renewal) - Type: {$entityType}, MentionText: \"{$entityText}\", NormalizedText: \"".($normalizedText ?? 'N/A')."\"");

            switch ($entityType) {
                case 'business_name':
                case 'organization_name':
                    $extractedData['businessName'] = $normalizedText ?? $entityText;
                    break;
                case 'business_address':
                case 'address':
                case 'location':
                case 'full_address':
                    $extractedData['businessLocation'] = $normalizedText ?? $entityText;
                    break;
                case 'business_type':
                case 'organization_type':
                case 'type_of_business':
                    $extractedData['businessType'] = $normalizedText ?? $entityText;
                    break;
                case 'business_activity':
                case 'nature_of_business':
                case 'line_of_business':
                    $extractedData['businessNature'] = $normalizedText ?? $entityText;
                    break;
                case 'owner_name':
                case 'proprietor_name':
                case 'proprietor':
                case 'applicant_name': 
                    if (empty($extractedOwnerName) && !empty($entityText)) { 
                        $extractedOwnerName = $normalizedText ?? $entityText;
                        $extractedData['ownerName'] = $extractedOwnerName; 
                        error_log("Owner name candidate found from permit: \"{$extractedOwnerName}\" from type \"{$entityType}\"");
                    }
                    break;
            }
        }
        
        // If Document AI didn't extract any relevant data, it might be an issue.
        // We no longer strictly require owner name here since validation is off, 
        // but some data should be present for auto-fill to be useful.
        if (empty($extractedData)) {
            $logMessage = "Document AI (Renewal): Could not extract sufficient business details for auto-fill.";
            if (empty($extractedOwnerName)) $logMessage .= " Owner name not found on permit (for logging purposes).";
            else $logMessage .= " Owner name found: '{$extractedOwnerName}' (for logging purposes).";
            error_log($logMessage . " Raw entities count: " . count($document->getEntities()));
            echo json_encode(['success' => false, 'message' => 'Could not extract sufficient business details from the uploaded permit for auto-fill. Please ensure the document is clear or fill the form manually.']);
            exit;
        }

        // Owner Validation Disabled: Proceed if Document AI extracted data
        error_log("Owner validation disabled. Proceeding with extracted data for renewal (User ID: $loggedInUserId). Extracted Owner: '{$extractedOwnerName}'");
        echo json_encode(['success' => true, 'message' => 'Permit processed. Business details extracted.', 'extracted_data' => $extractedData]);

    } catch (\Google\ApiCore\ApiException $e) {
        error_log("Google API Exception (Renewal Validation): " . $e->getMessage() . " Status: " . $e->getStatus() . " Code: " . $e->getCode());
        $friendlyMessage = 'Error communicating with the Document AI service.';
        if (strpos(strtolower($e->getMessage()), 'permission denied') !== false || strpos(strtolower($e->getMessage()), 'credential') !== false) {
             $friendlyMessage .= ' There might be an issue with API permissions or credentials.';
        }
        echo json_encode(['success' => false, 'message' => $friendlyMessage, 'debug_error' => $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General Exception (Renewal Validation): " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred while processing your document. Please try again later.']);
    }
    exit;

} else {
    error_log("Invalid request method or missing parameters for renewal validation.");
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please ensure you have uploaded a file.']);
    exit;
}
?> 