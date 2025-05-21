<?php
// This file provides a dedicated page for ID document processing using Google Document AI

// Include necessary files
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/dbconn.php';

// Process ID Document with Google Document AI
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
            body {
                font-family: 'Arial', sans-serif;
                background-color: #f8f9fc;
                margin: 0;
                padding: 0;
            }
            
            .id-upload-container {
                max-width: 800px;
                margin: 40px auto;
                padding: 30px;
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
            
            h2 {
                color: #4e73df;
                text-align: center;
                margin-bottom: 20px;
            }
            
            .upload-section {
                border: 2px dashed #ccc;
                border-radius: 8px;
                padding: 40px 20px;
                text-align: center;
                margin-bottom: 30px;
                cursor: pointer;
                transition: all 0.3s ease;
            }
            
            .upload-section:hover {
                border-color: #4e73df;
                background-color: #f8f9fc;
            }
            
            .upload-section i {
                font-size: 60px;
                color: #4e73df;
                margin-bottom: 15px;
            }
            
            #id_preview {
                max-width: 100%;
                max-height: 400px;
                display: none;
                margin: 20px auto;
                border-radius: 8px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }
            
            .results-container {
                margin-top: 30px;
                display: none;
                background-color: #f8f9fc;
                border-radius: 8px;
                padding: 20px;
            }
            
            .results-container h3 {
                color: #4e73df;
                border-bottom: 1px solid #e3e6f0;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }
            
            .loading-spinner {
                display: none;
                text-align: center;
                padding: 30px;
            }
            
            .loading-spinner i {
                font-size: 60px;
                color: #4e73df;
            }
            
            .data-field {
                display: flex;
                padding: 12px;
                margin-bottom: 8px;
                border-radius: 5px;
                background-color: white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            
            .field-name {
                font-weight: bold;
                width: 180px;
                color: #444;
            }
            
            .field-value {
                flex-grow: 1;
                color: #333;
            }
            
            .action-buttons {
                display: flex;
                justify-content: space-between;
                margin-top: 30px;
            }
            
            .btn {
                padding: 12px 20px;
                border-radius: 5px;
                border: none;
                cursor: pointer;
                font-weight: bold;
                transition: all 0.3s;
            }
            
            .back-btn {
                background-color: #e74a3b;
                color: white;
            }
            
            .back-btn:hover {
                background-color: #d52a1a;
            }
            
            .new-id-btn {
                background-color: #4e73df;
                color: white;
            }
            
            .new-id-btn:hover {
                background-color: #2e59d9;
            }
            
            .debug-section {
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px dashed #ccc;
                display: none;
            }
            
            .toggle-debug {
                background-color: #f8f9fc;
                border: 1px solid #e3e6f0;
                color: #858796;
                padding: 5px 10px;
                font-size: 0.8rem;
                border-radius: 4px;
                cursor: pointer;
                margin-top: 20px;
            }
            
            .debug-content {
                background-color: #f1f1f1;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 4px;
                font-family: monospace;
                white-space: pre-wrap;
                margin-top: 10px;
                max-height: 300px;
                overflow-y: auto;
                font-size: 0.8rem;
            }
        </style>
    </head>
    <body>
        <div class="id-upload-container">
            <h2>ID Document Processing with Google Document AI</h2>
            <p>Upload your ID document to automatically extract information using Google's Document AI. This tool can recognize and extract data from various ID documents including driver's licenses, passports, and government IDs.</p>
            
            <form id="idUploadForm" enctype="multipart/form-data">
                <div class="upload-section" id="dropZone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Drag and drop your ID document here or click to browse</p>
                    <p class="small-text">Supported formats: JPG, PNG, PDF</p>
                    <input type="file" id="id_document" name="id_document" accept="image/*,.pdf" hidden>
                </div>
                <img id="id_preview" alt="ID Preview">
                
                <div class="loading-spinner" id="loadingSpinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Processing your document with Google Document AI...</p>
                    <p>This may take a few moments</p>
                </div>
                
                <div class="results-container" id="resultsContainer">
                    <h3>Extracted Information</h3>
                    <div id="extractedData"></div>
                    
                    <div class="action-buttons">
                        <button type="button" class="btn back-btn" onclick="window.location.href='../pages/register.php'">Back to Registration</button>
                        <button type="button" class="btn new-id-btn" onclick="resetForm()">Process Another ID</button>
                    </div>
                    
                    <button type="button" class="toggle-debug" onclick="toggleDebug()">Show Technical Details</button>
                    <div id="debugSection" class="debug-section">
                        <h4>Raw API Response</h4>
                        <div id="debugContent" class="debug-content"></div>
                    </div>
                </div>
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
            const debugContent = document.getElementById('debugContent');
            
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
                    
                    // Store debug info
                    debugContent.textContent = JSON.stringify(data.debug, null, 2);
                    
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
                extractedDataDiv.innerHTML = '';
                
                // Define the fields to display (based on the image you provided)
                const fields = [
                    { key: 'address', label: 'Address' },
                    { key: 'date_of_birth', label: 'Date of Birth' },
                    { key: 'expiration_date', label: 'Expiration Date' },
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
                    valueDiv.textContent = value;
                    
                    fieldDiv.appendChild(nameDiv);
                    fieldDiv.appendChild(valueDiv);
                    extractedDataDiv.appendChild(fieldDiv);
                });
            }
            
            function resetForm() {
                // Clear the file input
                fileInput.value = '';
                
                // Hide the preview
                preview.style.display = 'none';
                
                // Hide results
                resultsContainer.style.display = 'none';
                
                // Show drop zone
                dropZone.style.display = 'block';
            }
            
            function toggleDebug() {
                const debugSection = document.getElementById('debugSection');
                if (debugSection.style.display === 'block') {
                    debugSection.style.display = 'none';
                    event.target.textContent = 'Show Technical Details';
                } else {
                    debugSection.style.display = 'block';
                    event.target.textContent = 'Hide Technical Details';
                }
            }
        </script>
    </body>
    </html>
    HTML;
    
    echo $html;
    exit();
}

// Always process the ID
processIdWithDocumentAI();
?>
