<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Google\Cloud\DocumentAI\V1\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\RawDocument;
use Google\Cloud\DocumentAI\V1\ProcessRequest;
use Google\ApiCore\ApiException;

// Check if file was uploaded
if (!isset($_FILES['govt_id']) || $_FILES['govt_id']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

// Get the temporary file path
$tempFilePath = $_FILES['govt_id']['tmp_name'];

// Create a unique name for the file
$fileName = uniqid('id_') . '.jpg';

// Move the uploaded file to our temp directory
if (!move_uploaded_file($tempFilePath, $fileName)) {
    echo json_encode(['error' => 'Failed to save the uploaded file']);
    exit;
}

// Return success with file uploaded message - no OCR processing
$result = [
    'success' => true,
    'message' => 'ID image uploaded successfully',
    'file_path' => $fileName
];

// Check if there's any error in the result
if (isset($result['error'])) {
    echo json_encode([
        'error' => $result['error'],
        'debug' => $debug ?? null
    ]);
    exit;
}

// Check if OCR processing is disabled
$enableOcr = true; // You can make this a configurable option or GET/POST parameter
if (!$enableOcr) {
    // Return the success response without OCR
    echo json_encode([
        'success' => true,
        'data' => [
            'message' => 'ID image uploaded successfully. OCR processing has been disabled.',
            'file_path' => $result['file_path'] ?? null
        ]
    ]);
    exit;
}

// Process the image with Google Document AI
try {
    // Load environment variables if .env file exists
    if (file_exists(__DIR__ . '/../.env')) {
        $envFile = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($envFile as $line) {
            if (strpos($line, '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }
    
    // Google Cloud configuration - use environment variables or fallback to defaults
    $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: __DIR__ . '/../config/google_credentials.json';
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credentialsPath);
    
    // Your Document AI processor details from environment variables or fallback to defaults
    $projectId = getenv('DOCUMENT_AI_PROJECT_ID') ?: 'western-dock-460409-f4';
    $location = getenv('DOCUMENT_AI_LOCATION') ?: 'us';
    $processorId = getenv('DOCUMENT_AI_PROCESSOR_ID') ?: '2202a8e5fae104f';
    
    // Initialize Document AI client
    $client = new DocumentProcessorServiceClient();
    $formattedName = $client->processorName($projectId, $location, $processorId);
    
    // Read the file content
    $content = file_get_contents($fileName);
    
    // Create a raw document
    $rawDocument = new RawDocument();
    $rawDocument->setContent($content);
    $rawDocument->setMimeType('image/jpeg');
    
    // Process the document - passing parameters directly in the array
    $response = $client->processDocument($formattedName, [
        'rawDocument' => $rawDocument
    ]);
    $document = $response->getDocument();
      // Extract fields from the processed document
    $extractedData = [];
    
    foreach ($document->getEntities() as $entity) {
        $fieldName = strtolower($entity->getType());
        $fieldValue = $entity->getMentionText();
        
        // Debug information
        $debug[] = ["type" => $fieldName, "value" => $fieldValue];
        
        // Store extracted data
        $extractedData[$fieldName] = $fieldValue;
    }
    
    // Map field names based on what we expect from Google Document AI
    // These field names should match those shown in your Document AI processor's output
    $result = [
        'address' => $extractedData['address'] ?? '',
        'date_of_birth' => $extractedData['dateofbirth'] ?? $extractedData['date_of_birth'] ?? '',
        'expiration_date' => $extractedData['expirationdate'] ?? $extractedData['expiration_date'] ?? '',
        'full_name' => $extractedData['fullname'] ?? $extractedData['full_name'] ?? '',
        'given_name' => $extractedData['givenname'] ?? $extractedData['given_name'] ?? $extractedData['first_name'] ?? '',
        'id_number' => $extractedData['idnumber'] ?? $extractedData['id_number'] ?? $extractedData['document_id'] ?? '',
        'last_name' => $extractedData['lastname'] ?? $extractedData['last_name'] ?? $extractedData['family_name'] ?? '',
        'middle_name' => $extractedData['middlename'] ?? $extractedData['middle_name'] ?? '',
        'type_of_id' => $extractedData['typeofid'] ?? $extractedData['type_of_id'] ?? $extractedData['document_type'] ?? '',
    ];
    
    // Clean up temporary file
    unlink($fileName);
      // Return the extracted data along with debug info for troubleshooting
    echo json_encode([
        'success' => true,
        'data' => $result,
        'debug' => [
            'raw_fields' => $extractedData,
            'debug_info' => $debug ?? []
        ]
    ]);
    exit;

} catch (ApiException $e) {
    // Handle Google API errors
    echo json_encode([
        'error' => 'Document AI API error: ' . $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
    // Clean up temporary file if it exists
    if (file_exists($fileName)) {
        unlink($fileName);
    }
    exit;

} catch (Exception $e) {
    // Handle any other errors
    echo json_encode([
        'error' => 'Error processing document: ' . $e->getMessage()
    ]);
    
    // Clean up temporary file if it exists
    if (file_exists($fileName)) {
        unlink($fileName);
    }
    exit;
}
