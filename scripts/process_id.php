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
    // Google Cloud configuration
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . __DIR__ . '/../config/google_credentials.json');
    
    // Your Document AI processor details
    $projectId = 'western-dock-460409-f4'; // Replace with your actual Google Cloud project ID
    $location = 'us'; // Replace with your processor location (e.g., us, eu)
    $processorId = '2202a8e5fae104f'; // Replace with your Document AI processor ID
    
    // Initialize Document AI client
    $client = new DocumentProcessorServiceClient();
    $formattedName = $client->processorName($projectId, $location, $processorId);
    
    // Read the file content
    $content = file_get_contents($fileName);
    
    // Create a raw document
    $rawDocument = new RawDocument();
    $rawDocument->setContent($content);
    $rawDocument->setMimeType('image/jpeg');
    
    // Create and configure the process request
    $request = new ProcessRequest();
    $request->setName($formattedName);
    $request->setRawDocument($rawDocument);
    
    // Process the document
    $response = $client->processDocument($formattedName, [
        'rawDocument' => $rawDocument
    ]);
    $document = $response->getDocument();
    
    // Extract fields from the processed document
    $extractedData = [];
    
    foreach ($document->getEntities() as $entity) {
        $fieldName = strtolower($entity->getType());
        $fieldValue = $entity->getMentionText();

        $extractedData[$fieldName] = $fieldValue;
    }
    
    // Common field mappings for ID cards
    $result = [
        'full_name' => $extractedData['name'] ?? '',
        'first_name' => $extractedData['first_name'] ?? '',
        'middle_name' => $extractedData['middle_name'] ?? '',
        'last_name' => $extractedData['last_name'] ?? '',
        'birth_date' => $extractedData['birth_date'] ?? $extractedData['date_of_birth'] ?? '',
        'address' => $extractedData['address'] ?? '',
        'id_number' => $extractedData['id_number'] ?? $extractedData['card_number'] ?? '',
    ];
    
    // Clean up temporary file
    unlink($fileName);
    
    // Return the extracted data
    echo json_encode([
        'success' => true,
        'data' => $result
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
