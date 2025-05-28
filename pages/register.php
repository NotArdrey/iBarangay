<?php
// Load environment variables for XAMPP compatibility
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $env = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env as $line) {
        if (strpos($line, '#') !== 0 && strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            putenv(sprintf('%s=%s', trim($name), trim($value)));
            $_ENV[trim($name)] = trim($value);
        }
    }
}

// Check if Document AI credentials are properly loaded
if (!getenv('GOOGLE_APPLICATION_CREDENTIALS') || !file_exists(getenv('GOOGLE_APPLICATION_CREDENTIALS'))) {
    error_log('Google Document AI credentials not found or inaccessible: ' . getenv('GOOGLE_APPLICATION_CREDENTIALS'));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>iBarangay Register</title>
  <link rel="stylesheet" href="../styles/register.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <style>
    .auto-populated {
      animation: highlight 2s ease-out;
      background-color: rgba(76, 175, 80, 0.1);
      border: 1px solid rgba(76, 175, 80, 0.5) !important;
    }

    @keyframes highlight {
      0% {
        background-color: rgba(76, 175, 80, 0.5);
      }

      100% {
        background-color: rgba(76, 175, 80, 0.1);
      }
    }
  </style>
</head>

<body>
  <div class="register-container">
    <div class="header">
      <img src="../photo/logo.png" alt="Government Logo">
      <h1>Create Account</h1>
    </div>

    <form action="../functions/register.php" method="POST" enctype="multipart/form-data">
      <div class="input-group">
        <input type="hidden" name="role_id" value="3">
        <input type="hidden" name="person_id" id="person_id" value="">
        <label for="email">
          <i class="fas fa-envelope"></i> Email
        </label>
        <input type="text" id="email" name="email" required>
      </div>
      <div class="input-group">
        <label for="phone">
          <i class="fas fa-phone"></i> Phone Number
        </label>
        <input type="text" id="phone" name="phone" placeholder="e.g. 09123456789" required>
      </div>
      <div class="input-group full-width">
        <label for="govt_id">
          <i class="fas fa-id-badge"></i> Government ID (Required)
        </label>
        <div class="upload-section" id="dropZone">
          <i class="fas fa-cloud-upload-alt"></i>
          <p>Drag and drop your ID document here or click to browse</p>
          <p class="small-text">Supported formats: JPG, PNG</p>
          <input type="file" id="govt_id" name="govt_id" accept="image/*" hidden required>
        </div>
        <img id="id_preview" alt="ID Preview">
      </div>

      <div class="input-group">
        <label for="id_type">
          <i class="fas fa-id-card"></i> Type of ID
        </label>
        <input type="text" id="id_type" name="id_type" required>
      </div>

      <div class="input-group">
        <label for="id_number">
          <i class="fas fa-hashtag"></i> ID Number
        </label>
        <input type="text" id="id_number" name="id_number" required>
      </div>

      <div class="input-group">
        <label for="id_expiration">
          <i class="fas fa-calendar-times"></i> ID Expiration Date
        </label>
        <input type="date" id="id_expiration" name="id_expiration">
      </div>

      <div class="input-group">
        <label for="first_name">
          <i class="fas fa-user"></i> First Name
        </label>
        <input type="text" id="first_name" name="first_name" required>
      </div>

      <div class="input-group">
        <label for="middle_name">
          <i class="fas fa-user"></i> Middle Name
        </label>
        <input type="text" id="middle_name" name="middle_name" required>
      </div>

      <div class="input-group">
        <label for="last_name">
          <i class="fas fa-user"></i> Last Name
        </label>
        <input type="text" id="last_name" name="last_name" required>
      </div>

      <div class="input-group">
        <label for="gender">
          <i class="fas fa-venus-mars"></i> Gender
        </label>
        <select id="gender" name="gender" required class="gender-select">
          <option value="">Select Gender</option>
          <option value="Male">Male</option>
          <option value="Female">Female</option>
        </select>
      </div>

      <div class="input-group">
        <label for="birth_date">
          <i class="fas fa-calendar-alt"></i> Date of Birth
        </label>
        <input type="date" id="birth_date" name="birth_date" required>
      </div>

      <div class="input-group">
        <label for="address">
          <i class="fas fa-map-marker-alt"></i> Address
        </label>
        <textarea id="address" name="address" rows="3" required></textarea>
      </div>

      <div class="input-group">
        <label for="password">
          <i class="fas fa-lock"></i> Password
        </label>
        <div class="password-container">
          <input type="password" id="password" name="password" required>
          <button type="button" class="toggle-password visible" aria-label="Toggle password visibility">
            <div class="eye-icon">
              <svg viewBox="0 0 24 24">
                <path d="M12 5C5.64 5 1 12 1 12s4.64 7 11 7 11-7 11-7-4.64-7-11-7zm0 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" />
                <circle cx="12" cy="12" r="2.5" />
              </svg>
              <div class="eye-slash"></div>
            </div>
          </button>
        </div>
      </div>
      <div class="input-group">
        <label for="confirmPassword">
          <i class="fas fa-lock"></i> Confirm Password
        </label>
        <div class="password-container">
          <input type="password" id="confirmPassword" name="confirmPassword" required>
          <button type="button" class="toggle-password visible" aria-label="Toggle password visibility">
            <div class="eye-icon">
              <svg viewBox="0 0 24 24">
                <path d="M12 5C5.64 5 1 12 1 12s4.64 7 11 7 11-7 11-7-4.64-7-11-7zm0 12c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" />
                <circle cx="12" cy="12" r="2.5" />
              </svg>
              <div class="eye-slash"></div>
            </div>
          </button>
        </div>
      </div>

      <button type="submit" class="register-btn">
        <i class="fas fa-user-plus"></i>
        <span>Create Account</span>
      </button>
    </form>
    <div class="footer-links">
      <a href="../pages/login.php" class="help-link">Back to Login</a>
    </div>
    <div class="footer">
      <div class="footer-info">
        <p>&copy; 2025 iBarangay. All Rights Reserved.</p>
      </div>
      <div class="security-note">
        <svg viewBox="0 0 24 24">
          <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" />
        </svg>
        <span>Secure Government Portal</span>
      </div>
    </div>
  </div>
  <script>
    document.querySelectorAll('.toggle-password').forEach(button => {
      button.addEventListener('click', function() {
        const passwordInput = this.parentElement.querySelector('input');
        passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
        this.classList.toggle('visible');
      });
    });

    // Form validation and person verification
    document.querySelector('form').addEventListener('submit', function(event) {
      event.preventDefault();
      
      // Get the person details for verification
      const firstName = document.getElementById('first_name').value.trim();
      const middleName = document.getElementById('middle_name').value.trim();
      const lastName = document.getElementById('last_name').value.trim();
      const birthDate = document.getElementById('birth_date').value.trim();
      const gender = document.getElementById('gender').value.trim();
      const idNumber = document.getElementById('id_number').value.trim();
      
      // Validate required fields
      if (!firstName || !lastName || !birthDate || !gender) {
        Swal.fire({
          icon: 'error',
          title: 'Missing Information',
          text: 'Please fill in all required fields: First Name, Last Name, Birth Date, and Gender.'
        });
        return;
      }
      
      // Show loading indicator
      Swal.fire({
        title: 'Verifying...',
        text: 'Please wait while we verify your information.',
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        }
      });
      
      // Create form data for verification
      const verifyData = new FormData();
      verifyData.append('first_name', firstName);
      verifyData.append('middle_name', middleName);
      verifyData.append('last_name', lastName);
      verifyData.append('birth_date', birthDate);
      verifyData.append('gender', gender);
      verifyData.append('id_number', idNumber);
      
      // Send verification request
      fetch('../functions/verify_person.php', {
        method: 'POST',
        body: verifyData
      })
      .then(response => response.json())
      .then(data => {
        // Close loading indicator
        Swal.close();
        
        if (data.status === 'success') {
          if (data.exists) {
            // Store the person_id in the hidden field
            document.getElementById('person_id').value = data.person_id;
            
            // Show success message and submit form
            Swal.fire({
              icon: 'success',
              title: 'Verification Successful',
              text: 'Your identity has been verified. You can now complete your registration.',
              showConfirmButton: false,
              timer: 1500,
              willClose: () => {
                this.submit();
              }
            });
          } else {
            // Person not found, show error message
            Swal.fire({
              icon: 'error',
              title: 'Verification Failed',
              text: 'Your information does not match our records. Only verified residents can register. Please contact the barangay office for assistance.',
              confirmButtonText: 'OK'
            });
          }
        } else {
          // Error in verification
          Swal.fire({
            icon: 'error',
            title: 'Verification Error',
            text: data.message || 'An error occurred during verification. Please try again later.',
            confirmButtonText: 'OK'
          });
        }
      })
      .catch(error => {
        // Close loading indicator
        Swal.close();
        
        console.error('Error:', error);
        Swal.fire({
          icon: 'error',
          title: 'Connection Error',
          text: 'Could not connect to the verification service. Please try again later.',
          confirmButtonText: 'OK'
        });
      });
    });

    // ID Document upload handling
    document.addEventListener('DOMContentLoaded', function() {
      const dropZone = document.getElementById('dropZone');
      const fileInput = document.getElementById('govt_id');
      const preview = document.getElementById('id_preview');

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
      }      function processFile(file) {
        
        // Create loading indicator
        const loadingIndicator = document.createElement('div');
        loadingIndicator.className = 'loading-indicator';
        loadingIndicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i><p>Processing your document...</p>';
        dropZone.parentNode.insertBefore(loadingIndicator, dropZone.nextSibling);
        loadingIndicator.style.display = 'block';

        const formData = new FormData();
        formData.append('govt_id', file);
        formData.append('debug', 'true'); // Add debug flag to help identify issues

        // Send to server for processing
        fetch('../scripts/process_id.php', {
            method: 'POST',
            body: formData
          })
          .then(response => {
            if (!response.ok) {
              throw new Error('Network response was not ok ' + response.statusText);
            }
            return response.json();
          })
          .then(data => {
            // Hide loading indicator
            loadingIndicator.style.display = 'none';

            if (data.error) {
              // Show error with more detailed information
              let errorMessage = data.error;
              if (data.debug_info) {
                console.error('Debug info:', data.debug_info);
                if (data.debug_info.includes('credentials') || data.debug_info.includes('env')) {
                  errorMessage += " (Environment configuration issue detected)";
                }
              }
              
              Swal.fire({
                icon: 'error',
                title: 'Processing Error',
                text: errorMessage,
                footer: '<a href="#">Contact system administrator for help</a>'
              });
              console.error('Error details:', data);
              return;
            }

            // Populate form fields with extracted data
            displayResults(data.data);
          })
          .catch(error => {
            loadingIndicator.style.display = 'none';
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'An error occurred while processing the document. Please try again later.',
              footer: '<a href="#">Report this issue</a>'
            });
            console.error('Error:', error);
          });
      }

      function displayResults(data) {
        // Map extracted data to form fields
        const fieldMappings = {
          'type_of_id': 'id_type',
          'id_number': 'id_number',
          'expiration_date': 'id_expiration',
          'given_name': 'first_name',
          'middle_name': 'middle_name',
          'last_name': 'last_name',
          'address': 'address',
          'date_of_birth': 'birth_date'
        };

        // Populate form fields with extracted data
        for (const [extractedField, formField] of Object.entries(fieldMappings)) {
          const value = data[extractedField] || '';
          const inputElement = document.getElementById(formField);

          if (inputElement) {
            // For date fields, special handling for date format
            if ((formField === 'birth_date' || formField === 'id_expiration') && value) {
              // Convert to YYYY-MM-DD format if needed
              try {
                const date = new Date(value);
                if (!isNaN(date.getTime())) {
                  inputElement.value = date.toISOString().split('T')[0];
                }
              } catch (e) {
                console.error('Error formatting date:', e);
              }
            } else {
              inputElement.value = value;
            }

            // Add visual feedback that field was auto-populated
            inputElement.classList.add('auto-populated');
            setTimeout(() => {
              inputElement.classList.remove('auto-populated');
            }, 3000);
          }
        }

        // If full name is available but individual name parts aren't
        if (data.full_name && (!data.given_name || !data.last_name)) {
          // Try to parse full name
          const nameParts = data.full_name.trim().split(/\s+/);
          if (nameParts.length >= 2) {
            // Assume first part is first name
            if (!data.given_name) {
              document.getElementById('first_name').value = nameParts[0];
            }

            // Assume last part is last name
            if (!data.last_name) {
              document.getElementById('last_name').value = nameParts[nameParts.length - 1];
            }

            // If there are three or more parts, assume middle parts are middle name
            if (nameParts.length >= 3 && !data.middle_name) {
              document.getElementById('middle_name').value = nameParts.slice(1, -1).join(' ');
            }
          }
        }

        // Show success message
        Swal.fire({
          icon: 'success',
          title: 'Document Processed',
          text: 'Information has been extracted and populated. Please verify all fields.',
          timer: 3000,
          timerProgressBar: true
        });
      }
    });
  </script>
</body>

</html>
<?php
if (isset($_SESSION['alert'])) {
  echo $_SESSION['alert'];
  unset($_SESSION['alert']);
}
?>