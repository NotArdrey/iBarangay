<?php
// Check if file was uploaded
if (!isset($_FILES['govt_id']) || $_FILES['govt_id']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

// Create a temporary directory if it doesn't exist
$tempDir = '../temp_uploads';
if (!file_exists($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Get the temporary file path
$tempFilePath = $_FILES['govt_id']['tmp_name'];

// Create a unique name for the file
$fileName = uniqid('id_') . '.jpg';
$targetFilePath = $tempDir . '/' . $fileName;

// Move the uploaded file to our temp directory
if (!move_uploaded_file($tempFilePath, $targetFilePath)) {
    echo json_encode(['error' => 'Failed to save the uploaded file']);
    exit;
}

// Check if required dependencies are installed
if (!function_exists('exec')) {
    unlink($targetFilePath); // Clean up
    echo json_encode(['error' => 'The exec function is disabled on this server']);
    exit;
}

// Try to use python/python3 command
$pythonCmd = "python";
exec("$pythonCmd --version 2>&1", $pythonOutput, $pythonReturnCode);

if ($pythonReturnCode !== 0) {
    $pythonCmd = "python3";
    exec("$pythonCmd --version 2>&1", $pythonOutput, $pythonReturnCode);
    
    if ($pythonReturnCode !== 0) {
        unlink($targetFilePath);
        echo json_encode(['error' => 'Python is not installed or not in PATH']);
        exit;
    }
}

// Call the Python script to extract data from the ID
$scriptPath = __DIR__ . "/extract_id_data.py";
$command = escapeshellcmd("$pythonCmd \"$scriptPath\" " . escapeshellarg($targetFilePath));
$output = [];
exec($command . " 2>&1", $output, $returnCode);

// Debug information
$debug = [
    'command' => $command,
    'return_code' => $returnCode,
    'output' => $output,
    'python_version' => $pythonOutput,
];

// Clean up the temporary file
unlink($targetFilePath);

// Check if the Python script executed successfully
if ($returnCode !== 0) {
    echo json_encode([
        'error' => 'Failed to process the ID image. Python script returned error code ' . $returnCode,
        'debug' => $debug
    ]);
    exit;
}

// Try to parse the JSON output from the Python script
$rawOutput = implode("\n", $output);

// Check if the output is empty
if (empty($rawOutput)) {
    echo json_encode([
        'error' => 'The Python script did not produce any output',
        'debug' => $debug
    ]);
    exit;
}

// Attempt to parse the JSON
$result = json_decode($rawOutput, true);

// Check for JSON parsing errors
if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
    $jsonError = json_last_error_msg();
    echo json_encode([
        'error' => "Failed to parse JSON output: $jsonError",
        'raw_output' => $rawOutput,
        'debug' => $debug
    ]);
    exit;
}

// Check if there was an error in the Python script
if (isset($result['error'])) {
    echo json_encode([
        'error' => $result['error'],
        'debug' => $debug
    ]);
    exit;
}

// Return the extracted data
echo json_encode([
    'success' => true,
    'data' => $result
]);
?> 