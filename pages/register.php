<!DOCTYPE html>
<html lang="en">
<head>  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Barangay Hub Register</title>  <link rel="stylesheet" href="../styles/register.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body>  <div class="register-container">
    <div class="header">
      <img src="../photo/logo.png" alt="Government Logo">
      <h1>Create Account</h1>
    </div>
    <form action="../functions/register.php" method="POST" enctype="multipart/form-data">
      <div class="input-group">
        <input type="hidden" name="role_id" value="3">
        <label for="email">Email</label>
        <input type="text" id="email" name="email" required>
      </div>
      <div class="input-group">
        <label for="password">Password</label>
        <div class="password-container">
          <input type="password" id="password" name="password" required>
          <button type="button" class="toggle-password visible" aria-label="Toggle password visibility">
            <div class="eye-icon">
              <svg viewBox="0 0 24 24">
                <path d="M12 5C5.64 5 1 12 1 12s4.64 7 11 7 11-7 11-7-4.64-7-11-7zm0 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/>
                <circle cx="12" cy="12" r="2.5"/>
              </svg>
              <div class="eye-slash"></div>
            </div>
          </button>
        </div>
      </div>
      <div class="input-group">
        <label for="confirmPassword">Confirm Password</label>
        <div class="password-container">
          <input type="password" id="confirmPassword" name="confirmPassword" required>
          <button type="button" class="toggle-password visible" aria-label="Toggle password visibility">
            <div class="eye-icon">
              <svg viewBox="0 0 24 24">
                <path d="M12 5C5.64 5 1 12 1 12s4.64 7 11 7 11-7 11-7-4.64-7-11-7zm0 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"/>
                <circle cx="12" cy="12" r="2.5"/>
              </svg>
              <div class="eye-slash"></div>
            </div>
          </button>
        </div>
      </div>
      
      <div class="input-group">
        <label for="govt_id">Government ID (Required)</label>
        <div class="upload-section" id="dropZone">
          <i class="fas fa-cloud-upload-alt"></i>
          <p>Drag and drop your ID document here or click to browse</p>
          <p class="small-text">Supported formats: JPG, PNG, PDF</p>
          <input type="file" id="govt_id" name="govt_id" accept="image/*,.pdf" hidden required>
        </div>
        <img id="id_preview" alt="ID Preview">
      </div>
        <div id="extractedFields" class="extracted-fields" style="display: none;">
        <h3>Extracted Information <span class="edit-note">(You can edit these fields if needed)</span></h3>
        <div id="extractedData"></div>
      </div>
      
      <button type="submit" class="register-btn"><span>Register</span></button>
    </form>    <div class="footer-links">
        <a href="../pages/login.php" class="help-link">Back to Login</a>
    </div>
    <div class="footer">
      <div class="footer-info">
        <p>&copy; 2025 Barangay Hub. All Rights Reserved.</p>
      </div>
      <div class="security-note">
        <svg viewBox="0 0 24 24">
          <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
        </svg>
        <span>Secure Government Portal</span>
      </div>
    </div>
  </div>  <script>
    // Toggle password visibility for both password fields.
    const toggleButtons = document.querySelectorAll('.toggle-password');
    toggleButtons.forEach(function(toggle) {
      toggle.addEventListener('click', function() {
        const passwordInput = this.parentElement.querySelector('input');
        const isPassword = passwordInput.type === 'password';
        passwordInput.type = isPassword ? 'text' : 'password';
        this.classList.toggle('visible');
      });
    });
    
    // ID Document upload handling
    document.addEventListener('DOMContentLoaded', function() {
      const dropZone = document.getElementById('dropZone');
      const fileInput = document.getElementById('govt_id');
      const preview = document.getElementById('id_preview');
      const extractedFields = document.getElementById('extractedFields');
      const extractedData = document.getElementById('extractedData');
      
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
        // Create loading indicator
        const loadingIndicator = document.createElement('div');
        loadingIndicator.className = 'loading-indicator';
        loadingIndicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i><p>Processing your document...</p>';
        dropZone.parentNode.insertBefore(loadingIndicator, dropZone.nextSibling);
        loadingIndicator.style.display = 'block';
        
        // Hide extracted fields while processing
        extractedFields.style.display = 'none';
        
        const formData = new FormData();
        formData.append('govt_id', file);
        
        // Send to server for processing
        fetch('../scripts/process_id.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          // Hide loading indicator
          loadingIndicator.style.display = 'none';
          
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
          
          // Show extracted fields section
          extractedFields.style.display = 'block';
        })
        .catch(error => {
          loadingIndicator.style.display = 'none';
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while processing the document.'
          });
          console.error('Error:', error);
        });
      }
        function displayResults(data) {
        extractedData.innerHTML = '';
          // Define the fields to display
        const fields = [
          { key: 'full_name', label: 'Full Name' },
          { key: 'given_name', label: 'Given Name' },
          { key: 'middle_name', label: 'Middle Name' },
          { key: 'last_name', label: 'Last Name' },
          { key: 'address', label: 'Address' },
          { key: 'date_of_birth', label: 'Date of Birth' },
          { key: 'id_number', label: 'ID Number' },
          { key: 'type_of_id', label: 'Type of ID' }
        ];
          // Create editable fields for each data item
        fields.forEach(field => {
          const value = data[field.key] || '';
          
          // Create visual display with editable input
          const fieldDiv = document.createElement('div');
          fieldDiv.className = 'data-field';
          
          const nameDiv = document.createElement('div');
          nameDiv.className = 'field-name';
          nameDiv.textContent = field.label + ':';
          
          const inputDiv = document.createElement('div');
          inputDiv.className = 'field-value';
          
          if (field.key === 'address') {
            // Create textarea for address
            const textarea = document.createElement('textarea');
            textarea.className = 'editable-field address-field';
            textarea.name = 'extracted_' + field.key;
            textarea.value = value;
            textarea.placeholder = 'Not detected';
            textarea.rows = 3;
            inputDiv.appendChild(textarea);          } else if (field.key === 'date_of_birth') {
            // Create date input for date of birth
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'editable-field date-picker';
            input.name = 'extracted_' + field.key;
            input.value = value;
            input.placeholder = 'Not detected';
            inputDiv.appendChild(input);
            
            // Initialize flatpickr on the date input
            flatpickr(input, {
              dateFormat: "Y-m-d",
              allowInput: true,
              maxDate: "today", // Prevent future dates
              yearRange: 100, // Allow dates up to 100 years in the past
              parseDate: (datestr) => {
                // Handle different date formats that might come from the ID
                if (!datestr) return null;
                
                // Try to parse common date formats
                let parsedDate;
                
                // Try MM/DD/YYYY format
                if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(datestr)) {
                  const parts = datestr.split('/');
                  parsedDate = new Date(parts[2], parts[0] - 1, parts[1]);
                } 
                // Try DD/MM/YYYY format
                else if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(datestr)) {
                  const parts = datestr.split('/');
                  parsedDate = new Date(parts[2], parts[1] - 1, parts[0]);
                }
                // Try YYYY-MM-DD format
                else if (/^\d{4}-\d{1,2}-\d{1,2}$/.test(datestr)) {
                  parsedDate = new Date(datestr);
                }
                // Try DD-MM-YYYY format
                else if (/^\d{1,2}-\d{1,2}-\d{4}$/.test(datestr)) {
                  const parts = datestr.split('-');
                  parsedDate = new Date(parts[2], parts[1] - 1, parts[0]);
                }
                
                return parsedDate || null;
              },
              onChange: function(selectedDates, dateStr) {
                // When date changes, update the input value
                input.value = dateStr;
              }
            });
          } else {
            // Create editable input field for other fields
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'editable-field';
            input.name = 'extracted_' + field.key;
            input.value = value;
            input.placeholder = 'Not detected';
            inputDiv.appendChild(input);
          }
          
          fieldDiv.appendChild(nameDiv);
          fieldDiv.appendChild(inputDiv);
          extractedData.appendChild(fieldDiv);
        });
      }
    });
  </script>
</body>
</html>
<?php
if(isset($_SESSION['alert'])) {
    echo $_SESSION['alert'];
    unset($_SESSION['alert']);
}
?>
