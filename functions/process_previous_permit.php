<?php
session_start();
// Ensure dbconn.php path is correct relative to process_previous_permit.php
require_once __DIR__ . '/../config/dbconn.php'; 
// Ensure vendor/autoload.php path is correct
require_once __DIR__ . '/../vendor/autoload.php';

use Google\Cloud\DocumentAI\V1\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\Cloud\DocumentAI\V1\RawDocument;
// V1beta3 might be needed for some processors or features, adjust if necessary
// use Google\Cloud\DocumentAI\V1beta3\DocumentProcessorServiceClient as DocumentProcessorServiceClientBeta;
// use Google\Cloud\DocumentAI\V1beta3\ProcessRequest as ProcessRequestBeta;
// use Google\Cloud\DocumentAI\V1beta3\RawDocument as RawDocumentBeta;

// --- Configuration ---
// These should ideally be stored in a secure configuration file or environment variables
// and not hardcoded directly in the script for production.
$config = [
    'GOOGLE_APPLICATION_CREDENTIALS' => 'C:\xampp\htdocs\secured_credentials\google_credentials.json', // WARNING: Move this file outside webroot
    'DOCUMENT_AI_PROJECT_ID' => 'western-dock-460409-f4',
    'DOCUMENT_AI_LOCATION' => 'us', // e.g., 'us' or 'eu'
    'BUSINESS_PERMIT_PROCESSOR_ID' => 'b6fafb50084af0e',
];

// Set the environment variable for Google Cloud authentication
// CRITICAL SECURITY NOTE: Ensure the credentials file is NOT in a web-accessible directory.
// Example: 'C:\your_secure_path\google_credentials.json'
if (file_exists($config['GOOGLE_APPLICATION_CREDENTIALS'])) {
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $config['GOOGLE_APPLICATION_CREDENTIALS']);
} else {
    // Log this error and exit if credentials are not found.
    error_log("Google credentials file not found at: " . $config['GOOGLE_APPLICATION_CREDENTIALS']);
    echo json_encode(['success' => false, 'message' => 'Server configuration error regarding Document AI credentials.']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['previous_permit_file'])) {
    $uploadedFile = $_FILES['previous_permit_file'];

    // Basic File Validation
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        error_log("File upload error: " . $uploadedFile['error']);
        echo json_encode(['success' => false, 'message' => 'File upload error: ' . $uploadedFile['error']]);
        exit;
    }

    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $fileMimeTypeValidation = mime_content_type($uploadedFile['tmp_name']);

    if (!in_array($fileMimeTypeValidation, $allowedMimeTypes)) {
        error_log("Invalid file type: " . $fileMimeTypeValidation);
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF, JPG, and PNG are allowed.']);
        exit;
    }

    if ($uploadedFile['size'] > 5 * 1024 * 1024) { // 5 MB
        error_log("File too large: " . $uploadedFile['size'] . " bytes");
        echo json_encode(['success' => false, 'message' => 'File is too large. Maximum 5MB allowed.']);
        exit;
    }

    $filePath = $uploadedFile['tmp_name'];
    
    // Logging before Document AI specific operations
    error_log("--- Document AI Processing Start ---");
    error_log("Config - Project ID: " . $config['DOCUMENT_AI_PROJECT_ID'] . " (Type: " . gettype($config['DOCUMENT_AI_PROJECT_ID']) . ")");
    error_log("Config - Location: " . $config['DOCUMENT_AI_LOCATION'] . " (Type: " . gettype($config['DOCUMENT_AI_LOCATION']) . ")");
    error_log("Config - Processor ID: " . $config['BUSINESS_PERMIT_PROCESSOR_ID'] . " (Type: " . gettype($config['BUSINESS_PERMIT_PROCESSOR_ID']) . ")");
    error_log("Config - Credentials Path: " . $config['GOOGLE_APPLICATION_CREDENTIALS']);
    error_log("Uploaded File Path (tmp_name): " . $filePath . " (Type: " . gettype($filePath) . ")");

    $content = file_get_contents($filePath);
    if ($content === false) {
        error_log("Failed to read file content from: " . $filePath);
        echo json_encode(['success' => false, 'message' => 'Server error: Failed to read uploaded file content.']);
        exit;
    }
    error_log("File Content Type (gettype of $content): " . gettype($content) . ". Length: " . strlen($content) . " bytes.");

    $fileMimeType = mime_content_type($filePath); // Re-check here for the variable to be passed
    if ($fileMimeType === false) {
        error_log("Failed to determine MIME type for: " . $filePath);
        echo json_encode(['success' => false, 'message' => 'Server error: Failed to determine file MIME type.']);
        exit;
    }
    error_log("File MIME Type to be used: " . $fileMimeType . " (Type: " . gettype($fileMimeType) . ")");

    try {
        $documentAiClient = new DocumentProcessorServiceClient();
        
        $processorNameString = $documentAiClient->processorName(
            $config['DOCUMENT_AI_PROJECT_ID'],
            $config['DOCUMENT_AI_LOCATION'],
            $config['BUSINESS_PERMIT_PROCESSOR_ID']
        );
        error_log("Constructed Processor Name String (processorNameString): " . $processorNameString . " (Type: " . gettype($processorNameString) . ")");
        
        if (!is_string($processorNameString)) {
            error_log("CRITICAL: processorNameString is NOT a string. Aborting before ProcessRequest construction.");
            echo json_encode(['success' => false, 'message' => 'Internal server error: Processor identification failed.']);
            exit;
        }

        $rawDocument = (new RawDocument())
            ->setContent($content)
            ->setMimeType($fileMimeType);

        error_log("Document AI Request components prepared. Calling processDocument with processorNameString and optional args...");
        error_log("Processor Name for call: " . $processorNameString . " (Type: " . gettype($processorNameString) . ")");
        error_log("RawDocument MimeType for call: " . $rawDocument->getMimeType() . " (Content length: " . strlen($rawDocument->getContent()) . ")");

        $result = $documentAiClient->processDocument(
            $processorNameString,
            [
                'rawDocument' => $rawDocument
            ]
        );
        
        $document = $result->getDocument();
        error_log("Document AI processDocument call successful.");

        $extractedData = [];
        // --- IMPORTANT: LOGIC TO MAP DOCUMENT AI ENTITIES TO YOUR FIELDS ---
        // This section is HIGHLY SPECIFIC to your Document AI processor's output schema.
        // You MUST inspect the $document->getEntities() array from a test run with your processor
        // and map the entity types and values correctly.
        //
        // Example (ADAPT THIS BASED ON YOUR PROCESSOR):
        // error_log("Document AI Entities raw: " . print_r($document->getEntities(), true)); 
        
        foreach ($document->getEntities() as $entity) {
            $entityType = trim(strtolower($entity->getType())); // Normalize type
            $entityText = $entity->getMentionText(); 
            // Use normalizedValue if available and seems more appropriate for certain fields
            $normalizedText = ($entity->getNormalizedValue() && $entity->getNormalizedValue()->getText()) ? $entity->getNormalizedValue()->getText() : null;
            error_log("DocAI Entity - Type: {$entityType}, MentionText: {$entityText}, NormalizedText: " . ($normalizedText ?? 'N/A'));

            // You need to find out what your processor calls these fields.
            // These are common guesses but might be different for your processor.
            switch ($entityType) {
                case 'business_name':
                case 'organization_name':
                case 'applicant_name': // If it picks up owner as business name sometimes
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
                // Attempt to capture owner/proprietor name - adjust these types based on your processor
                case 'owner_name':
                case 'proprietor_name':
                case 'proprietor':
                    // If ownerName is already set by a more specific type, don't override with a generic one unless desired
                    if (!isset($extractedData['ownerName']) || $entityType === 'owner_name' || $entityType === 'proprietor_name') {
                        $extractedData['ownerName'] = $normalizedText ?? $entityText;
                    }
                    break;
                // Add more cases as needed based on your processor's output
                // case 'permit_number':
                //    $extractedData['permitNumber'] = $entityText;
                //    break;
                // case 'issue_date':
                //    $extractedData['issueDate'] = $entity->getNormalizedValue() ? $entity->getNormalizedValue()->getText() : $entityText;
                //    break;
            }
        }
        
        $documentAiClient->close();

        // Note: $filePath (tmp_name) is usually deleted by PHP after script execution.
        // If you move the file, you'll need to manually delete it.

        if (!empty($extractedData) && (
            isset($extractedData['businessName']) || 
            isset($extractedData['businessLocation']) || 
            isset($extractedData['businessType']) || 
            isset($extractedData['businessNature']) ||
            isset($extractedData['ownerName'])
            )) {
            error_log("Extracted Data (including potential ownerName): " . json_encode($extractedData));
            echo json_encode(['success' => true, 'data' => $extractedData]);
        } else {
            $logMessage = "Document AI: No relevant business details extracted or mapped.";
            if (empty($extractedData) && $document->getEntities() && count($document->getEntities()) > 0) {
                $logMessage .= " Entities were found, but none matched mapping. Entities: ";
                foreach($document->getEntities() as $idx => $ent){
                    $logMessage .= ($idx > 0 ? ", " : "") . $ent->getType() . ":" . $ent->getMentionText();
                    if ($idx >=2) {
                        $logMessage .= "...";
                        break;
                    }
                }
            } elseif (!$document->getEntities() || count($document->getEntities()) === 0) {
                $logMessage .= " No entities were returned by Document AI.";
            }
            error_log($logMessage);
            echo json_encode(['success' => false, 'message' => 'Could not extract sufficient business details. Please fill manually.']);
        }

    } catch (\Google\ApiCore\ApiException $e) {
        error_log("Google API Exception: " . $e->getMessage() . " Status: " . $e->getStatus() . " Code: " . $e->getCode() . " Details: " . print_r($e->getMetadata(), true));
        $friendlyMessage = 'Error communicating with Document AI service.';
        if ($e->getStatus() === 'UNAUTHENTICATED' || strpos($e->getMessage(), 'credentials') !== false) {
            $friendlyMessage .= ' Check auth config.';
        } else if ($e->getStatus() === 'INVALID_ARGUMENT') {
             $friendlyMessage .= ' Check file format or processor config.';
        }
        echo json_encode(['success' => false, 'message' => $friendlyMessage, 'debug_error' => $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General Exception: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => 'Unexpected error processing document: ' . $e->getMessage()]);
    }
    exit;

} else {
    error_log("Invalid request: Method not POST or no file uploaded.");
    echo json_encode(['success' => false, 'message' => 'Invalid request method or no file uploaded.']);
    exit;
}

?> 