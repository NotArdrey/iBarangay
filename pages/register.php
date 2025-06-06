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

// Function to check if phone number already exists
function checkPhoneExists($phone) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Error checking phone number: " . $e->getMessage());
        return false;
    }
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

    .password-strength {
      margin-top: 10px;
    }

    .strength-meter {
      height: 5px;
      background-color: #eee;
      border-radius: 3px;
      margin-bottom: 5px;
      transition: all 0.3s ease;
    }

    .strength-text {
      font-size: 12px;
      margin-bottom: 10px;
      color: #666;
    }

    .strength-requirements {
      font-size: 12px;
      color: #666;
    }

    .requirement {
      margin: 5px 0;
      color: #999;
      display: flex;
      align-items: center;
    }

    .requirement::before {
      content: '×';
      margin-right: 5px;
      color: #dc3545;
    }

    .requirement.valid {
      color: #28a745;
    }

    .requirement.valid::before {
      content: '✓';
      color: #28a745;
    }

    .strength-meter.weak {
      background-color: #dc3545;
      width: 25%;
    }

    .strength-meter.medium {
      background-color: #ffc107;
      width: 50%;
    }

    .strength-meter.strong {
      background-color: #28a745;
      width: 100%;
    }

    /* Additional styles for SweetAlert custom classes */
    .barangay-selection-modal .swal2-popup {
      width: auto;
      max-width: 500px;
    }
    .barangay-selection-popup {
      border-radius: 12px;
    }
  </style>
</head>

<body>
  <div class="register-wrapper">
    <div class="branding-side">
      <div class="branding-content">
        <img src="../photo/logo.png" alt="iBarangay Logo">
        <h1>Welcome to iBarangay</h1>
        <p>Your first step towards seamless access to community services. Let's get you set up.</p>
      </div>
    </div>
    <div class="form-side">
  <div class="register-container">
    <div class="header">
      <img src="../photo/logo.png" alt="Government Logo">
          <h1>Create Your iBarangay Account</h1>
          <p>Join our digital community. Please provide accurate information as it appears on your official documents.</p>
        </div>

        <div class="onboarding-flow">
          <div class="progress-bar">
            <div class="step active" data-step="1">
              <div class="dot">1</div>
              <span>Account</span>
            </div>
            <div class="step" data-step="2">
              <div class="dot">2</div>
              <span>Verification</span>
            </div>
            <div class="step" data-step="3">
              <div class="dot">3</div>
              <span>Information</span>
            </div>
            <div class="step" data-step="4">
              <div class="dot">4</div>
              <span>Submit</span>
            </div>
    </div>

          <form action="../functions/register.php" method="POST" enctype="multipart/form-data" id="registration-form">
        <input type="hidden" name="role_id" value="3">
        <input type="hidden" name="person_id" id="person_id" value="">
            
            <!-- Step 1: Account Information -->
            <div class="form-step active" data-step="1">
              <div class="grid-container">
                <div class="input-group">
                  <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                  <input type="email" id="email" name="email" required placeholder="you@gmail.com">
                </div>
                <div class="input-group">
                  <label for="phone"><i class="fas fa-phone"></i> Phone Number</label>
                  <input type="tel" id="phone" name="phone" placeholder="09123456789" required>
                </div>
                <div class="input-group">
                  <label for="password"><i class="fas fa-lock"></i> Password</label>
                  <div class="password-container">
                    <input type="password" id="password" name="password" required>
                  </div>
      </div>
      <div class="input-group">
                  <label for="confirmPassword"><i class="fas fa-lock"></i> Confirm Password</label>
                  <div class="password-container">
                    <input type="password" id="confirmPassword" name="confirmPassword" required>
                  </div>
                </div>
                <div class="input-group full-width" style="margin-top: -1rem;">
                  <div class="password-strength">
                    <div class="strength-meter"><div class="strength-meter-fill"></div></div>
                    <div class="strength-text">Password strength</div>
                    <ul class="strength-requirements">
                      <li class="requirement" data-requirement="length"><i class="fas fa-times"></i>At least 8 characters</li>
                      <li class="requirement" data-requirement="uppercase"><i class="fas fa-times"></i>One uppercase letter</li>
                      <li class="requirement" data-requirement="number"><i class="fas fa-times"></i>One number</li>
                      <li class="requirement" data-requirement="special"><i class="fas fa-times"></i>One special character</li>
                    </ul>
                  </div>
                </div>
              </div>
      </div>
            
            <!-- Step 2: ID Verification -->
            <div class="form-step" data-step="2">
              <div class="grid-container">
      <div class="input-group full-width">
                  <label for="govt_id"><i class="fas fa-id-badge"></i> Government ID (Required)</label>
        <div class="upload-section" id="dropZone">
          <i class="fas fa-cloud-upload-alt"></i>
                    <p>Drag & drop your ID document here or click to browse</p>
                    <p class="small-text">Supported formats: JPG, PNG. Max size: 5MB</p>
          <input type="file" id="govt_id" name="govt_id" accept="image/*" hidden>
        </div>
        <img id="id_preview" alt="ID Preview">
      </div>
      <div class="input-group">
                  <label for="id_type"><i class="fas fa-id-card"></i> Type of ID</label>
                  <input type="text" id="id_type" name="id_type" required placeholder="e.g., National ID, Driver's License">
      </div>
      <div class="input-group">
                  <label for="id_number"><i class="fas fa-hashtag"></i> ID Number</label>
                  <input type="text" id="id_number" name="id_number" required placeholder="Found on your ID">
      </div>
      </div>
      </div>

            <!-- Step 3: Personal Information -->
            <div class="form-step" data-step="3">
              <div class="grid-container">
      <div class="input-group">
                  <label for="first_name"><i class="fas fa-user"></i> First Name</label>
                  <input type="text" id="first_name" name="first_name" required placeholder="Juan">
      </div>
      <div class="input-group">
                  <label for="last_name"><i class="fas fa-user"></i> Last Name</label>
                  <input type="text" id="last_name" name="last_name" required placeholder="Dela Cruz">
      </div>
      <div class="input-group">
                  <label for="middle_name"><i class="fas fa-user"></i> Middle Name</label>
                  <input type="text" id="middle_name" name="middle_name" placeholder="(Optional)">
      </div>
      <div class="input-group">
                  <label for="birth_date"><i class="fas fa-calendar-alt"></i> Date of Birth</label>
                  <input type="date" id="birth_date" name="birth_date" class="date-picker" required placeholder="YYYY-MM-DD">
          </div>
        </div>
      </div>
            
            <!-- Step 4: Review and Submit -->
            <div class="form-step" data-step="4">
                <h2 class="review-title">Review Your Information</h2>
                <p class="review-subtitle">Please ensure all details are correct before submitting.</p>
                <div id="review-summary"></div>
            </div>

            <div class="form-navigation">
              <button type="button" class="nav-btn prev-btn" style="display: none;">Previous</button>
              <button type="button" class="nav-btn next-btn">Next</button>
              <button type="submit" class="nav-btn submit-btn" style="display: none;">Create Account</button>
            </div>
          </form>
      </div>

        <div class="footer">
    <div class="footer-links">
            <a href="../pages/login.php" class="help-link">Already have an account? Sign In</a>
    </div>
      <div class="footer-info">
        <p>&copy; 2025 iBarangay. All Rights Reserved.</p>
      </div>
      <div class="security-note">
            <svg viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z" />
        </svg>
        <span>Secure Government Portal</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Stepper logic
      const nextButtons = document.querySelectorAll('.next-btn');
      const prevButtons = document.querySelectorAll('.prev-btn');
      const formSteps = document.querySelectorAll('.form-step');
      const progressSteps = document.querySelectorAll('.progress-bar .step');
      const submitBtn = document.querySelector('.submit-btn');
      let currentStep = 1;

      nextButtons.forEach(button => {
        button.addEventListener('click', () => {
          if (validateStep(currentStep)) {
            currentStep++;
            updateFormSteps();
            updateProgressBar();
          }
        });
      });

      prevButtons.forEach(button => {
        button.addEventListener('click', () => {
          currentStep--;
          updateFormSteps();
          updateProgressBar();
      });
    });

      function updateFormSteps() {
        formSteps.forEach(step => {
          step.classList.toggle('active', parseInt(step.dataset.step) === currentStep);
        });

        document.querySelector('.prev-btn').style.display = currentStep > 1 ? 'inline-block' : 'none';
        document.querySelector('.next-btn').style.display = currentStep < formSteps.length ? 'inline-block' : 'none';
        submitBtn.style.display = currentStep === formSteps.length ? 'inline-block' : 'none';

        if (currentStep === formSteps.length) {
            populateReview();
        }
      }
      
      function updateProgressBar() {
        progressSteps.forEach((step, idx) => {
          const stepNum = parseInt(step.dataset.step);
          if (stepNum < currentStep) {
            step.classList.add('completed');
            step.classList.remove('active');
          } else if (stepNum === currentStep) {
            step.classList.add('active');
            step.classList.remove('completed');
          } else {
            step.classList.remove('active', 'completed');
          }
        });
      }

      function validateStep(step) {
          let isValid = true;
          const inputs = formSteps[step - 1].querySelectorAll('[required]');
          
          inputs.forEach(input => {
              if (!input.value.trim()) {
                  isValid = false;
        Swal.fire({
          icon: 'error',
                      title: 'Missing Information',
                      text: `Please fill out the ${input.labels[0].textContent.replace('*','').trim()} field.`,
                      confirmButtonColor: '#4F46E5'
        });
              }
          });

          if (!isValid) return false;

          // Step 1 specific validation
          if (step === 1) {
              const email = document.getElementById('email').value.trim();
              const phone = document.getElementById('phone').value.trim();
              const password = document.getElementById('password').value;
              const confirmPassword = document.getElementById('confirmPassword').value;
              
      const emailRegex = /^[a-zA-Z0-9._%+-]+@gmail\.com$/;
      if (!emailRegex.test(email)) {
                  Swal.fire({ icon: 'error', title: 'Invalid Email', text: 'Only Gmail addresses are accepted.', confirmButtonColor: '#4F46E5'});
                  return false;
              }

              const phoneRegex = /^09\d{9}$/;
              if (!phoneRegex.test(phone)) {
                  Swal.fire({ icon: 'error', title: 'Invalid Phone Number', text: 'Please enter a valid Philippine mobile number (09xxxxxxxxx).', confirmButtonColor: '#4F46E5' });
                  return false;
              }

      const passwordRegex = /^(?=.*[A-Z])(?=.*[0-9!@#$%^&*])[A-Za-z0-9!@#$%^&*]{8,}$/;
      if (!passwordRegex.test(password)) {
                  Swal.fire({ icon: 'error', title: 'Invalid Password', text: 'Password must be at least 8 characters long and contain at least one capital letter and one number or special character.', confirmButtonColor: '#4F46E5' });
                  return false;
              }
      if (password !== confirmPassword) {
                  Swal.fire({ icon: 'error', title: 'Passwords Do Not Match', text: 'Please make sure your passwords match.', confirmButtonColor: '#4F46E5' });
                  return false;
              }
      }
      
          // Step 2 specific validation
          if (step === 2) {
              const fileInput = document.getElementById('govt_id');
              if (!fileInput.files || fileInput.files.length === 0) {
                  Swal.fire({ icon: 'error', title: 'Missing Government ID', text: 'Please upload a valid government ID document.', confirmButtonColor: '#4F46E5'});
                  return false;
              }
          }

          return true;
      }

      function populateReview() {
        const summaryContainer = document.getElementById('review-summary');
        const formData = new FormData(document.getElementById('registration-form'));
        let summaryHTML = '';

        const fieldLabels = {
          'email': 'Email',
          'phone': 'Phone Number',
          'id_type': 'ID Type',
          'id_number': 'ID Number',
          'first_name': 'First Name',
          'last_name': 'Last Name',
          'middle_name': 'Middle Name',
          'birth_date': 'Birth Date',
        };

        for (const [name, label] of Object.entries(fieldLabels)) {
            const value = formData.get(name);
            if(value) {
                summaryHTML += `
                    <div class="review-summary-item">
                        <span class="label">${label}</span>
                        <span class="value">${value}</span>
                    </div>
                `;
            }
        }
        
        const idPreview = document.getElementById('id_preview');
        if (idPreview && idPreview.style.display === 'block') {
             summaryHTML += `
                <div class="review-summary-item">
                    <span class="label">ID Preview</span>
                    <span class="value"><img src="${idPreview.src}" alt="ID Preview"></span>
                </div>
            `;
        }

        summaryContainer.innerHTML = summaryHTML;
      }

      // Existing logic
      document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
          const passwordInput = this.closest('.password-container').querySelector('input');
          if (passwordInput) {
              passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
              this.classList.toggle('visible');
          }
        });
      });

      // Form submission logic
      document.getElementById('registration-form').addEventListener('submit', async function(event) {
        event.preventDefault();
        
        // ... (The entire verification logic from the original `submit` event listener)
        // This includes: verification fetch, SweetAlerts for success/error/barangay selection
        const firstName = document.getElementById('first_name').value.trim();
        const middleName = document.getElementById('middle_name').value.trim();
        const lastName = document.getElementById('last_name').value.trim();
        const birthDate = document.getElementById('birth_date').value.trim();
        const idNumber = document.getElementById('id_number').value.trim();
        
      Swal.fire({
        title: 'Verifying...',
        text: 'Please wait while we verify your information.',
        allowOutsideClick: false,
          didOpen: () => { Swal.showLoading(); }
      });
      
      const verifyData = new FormData();
      verifyData.append('first_name', firstName);
      verifyData.append('middle_name', middleName);
      verifyData.append('last_name', lastName);
      verifyData.append('birth_date', birthDate);
      verifyData.append('id_number', idNumber);
      
        fetch('../functions/verify_person.php', { method: 'POST', body: verifyData })
      .then(response => response.json())
      .then(data => {
        Swal.close();
        if (data.status === 'success') {
          if (data.exists) {
            if (data.has_account) {
                        Swal.fire({ icon: 'error', title: 'Account Already Exists', text: 'You already have an account in another barangay.', confirmButtonText: 'OK' });
              return;
            }
            if (data.barangay_records && data.barangay_records.length > 1) {
              let barangayOptions = data.barangay_records.map(record =>
                `<option value="${record.id}" data-source="${record.source}" data-person="${record.person_id}">${record.barangay_name ? record.barangay_name : 'Barangay #' + record.id} (${record.source})</option>`
              ).join('');
              Swal.fire({
                title: 'Select Your Primary Barangay',
                        html: `<p>You are registered in multiple barangays. Please select your primary barangay:</p><select id="barangay_select" class="swal2-input">${barangayOptions}</select>`,
                showCancelButton: true,
                confirmButtonText: 'Continue',
                preConfirm: () => {
                  const select = document.getElementById('barangay_select');
                          if (!select.value) {
                    Swal.showValidationMessage('Please select a barangay');
                    return false;
                  }
                          document.getElementById('person_id').value = select.options[select.selectedIndex].getAttribute('data-person');
                          return {barangay_id: select.value, source: select.options[select.selectedIndex].getAttribute('data-source')};
                }
              }).then((result) => {
                if (result.isConfirmed) {
                          const form = document.getElementById('registration-form');
                  const barangayInput = document.createElement('input');
                  barangayInput.type = 'hidden';
                  barangayInput.name = 'barangay_id';
                  barangayInput.value = result.value.barangay_id;
                          form.appendChild(barangayInput);
                  const sourceInput = document.createElement('input');
                  sourceInput.type = 'hidden';
                  sourceInput.name = 'record_source';
                  sourceInput.value = result.value.source;
                          form.appendChild(sourceInput);
                          form.submit();
                }
              });
            } else if (data.barangay_records && data.barangay_records.length === 1) {
                        const form = document.getElementById('registration-form');
              const barangayInput = document.createElement('input');
              barangayInput.type = 'hidden';
              barangayInput.name = 'barangay_id';
              barangayInput.value = data.barangay_records[0].id;
                        form.appendChild(barangayInput);
              const sourceInput = document.createElement('input');
              sourceInput.type = 'hidden';
              sourceInput.name = 'record_source';
              sourceInput.value = data.barangay_records[0].source;
                        form.appendChild(sourceInput);
                        form.submit();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Verification Failed', text: 'No barangay records found. Please contact the barangay office.'});
                    }
                } else {
                    Swal.fire({ icon: 'error', title: 'Verification Failed', text: data.message || 'Your information does not match our records.' });
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Verification Error', text: data.message || 'An error occurred.' });
        }
      })
      .catch(error => {
        Swal.close();
        console.error('Error:', error);
            Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to the verification service.' });
      });
    });

    // ID Document upload handling
      const dropZone = document.getElementById('dropZone');
      const fileInput = document.getElementById('govt_id');
      const preview = document.getElementById('id_preview');

      dropZone.addEventListener('click', () => fileInput.click());
      dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.style.borderColor = 'var(--primary-color)'; });
      dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--border-color)'; });
      dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--border-color)';
        if (e.dataTransfer.files.length) {
          fileInput.files = e.dataTransfer.files;
          handleFileSelected();
        }
      });

      fileInput.addEventListener('change', handleFileSelected);

      function handleFileSelected() {
        const file = fileInput.files[0];
        if (file) {
          if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => { preview.src = e.target.result; preview.style.display = 'block'; };
            reader.readAsDataURL(file);
          } else {
            preview.style.display = 'none';
          }
          processFile(file);
        }
      }      
        
      function processFile(file) {
        const loadingIndicator = document.createElement('div');
        loadingIndicator.className = 'loading-indicator';
        loadingIndicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i><p>Processing your document...</p>';
        dropZone.parentNode.insertBefore(loadingIndicator, dropZone.nextSibling);
        loadingIndicator.style.display = 'block';

        const formData = new FormData();
        formData.append('govt_id', file);

        fetch('../scripts/process_id.php', { method: 'POST', body: formData })
          .then(response => response.json())
          .then(data => {
            loadingIndicator.style.display = 'none';
            if (data.error) {
              Swal.fire({ icon: 'error', title: 'Processing Error', text: data.error });
            } else {
              displayResults(data.data);
              Swal.fire({ icon: 'success', title: 'Document Processed', text: 'Information has been extracted. Please verify all fields.', timer: 2000, timerProgressBar: true });
            }
          })
          .catch(error => {
            loadingIndicator.style.display = 'none';
            Swal.fire({ icon: 'error', title: 'Error', text: 'An error occurred while processing the document.' });
            console.error('Error:', error);
          });
      }

      function displayResults(data) {
        const fieldMappings = {
          'type_of_id': 'id_type', 'id_number': 'id_number', 'expiration_date': 'id_expiration',
          'given_name': 'first_name', 'middle_name': 'middle_name', 'last_name': 'last_name',
          'date_of_birth': 'birth_date'
        };

        for (const [extractedField, formField] of Object.entries(fieldMappings)) {
          const value = data[extractedField] || '';
          const inputElement = document.getElementById(formField);
          if (inputElement) {
            if ((formField === 'birth_date' || formField === 'id_expiration') && value) {
              try {
                const date = new Date(value);
                if (!isNaN(date.getTime())) inputElement.value = date.toISOString().split('T')[0];
              } catch (e) { console.error('Error formatting date:', e); }
            } else {
              inputElement.value = value;
            }
            inputElement.classList.add('auto-populated');
            setTimeout(() => inputElement.classList.remove('auto-populated'), 3000);
          }
        }
      }

      // Password strength checker
    document.getElementById('password').addEventListener('input', function(e) {
      const password = e.target.value;
        const strengthMeterFill = document.querySelector('.strength-meter-fill');
      const strengthText = document.querySelector('.strength-text');
        const requirements = {
            length: document.querySelector('.requirement[data-requirement="length"]'),
            uppercase: document.querySelector('.requirement[data-requirement="uppercase"]'),
            number: document.querySelector('.requirement[data-requirement="number"]'),
            special: document.querySelector('.requirement[data-requirement="special"]')
        };
        
        strengthMeterFill.className = 'strength-meter-fill';
        Object.values(requirements).forEach(req => req.classList.remove('valid'));
      
      const hasLength = password.length >= 8;
      const hasUppercase = /[A-Z]/.test(password);
      const hasNumber = /[0-9]/.test(password);
      const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
      
        if (hasLength) requirements.length.classList.add('valid');
        if (hasUppercase) requirements.uppercase.classList.add('valid');
        if (hasNumber) requirements.number.classList.add('valid');
        if (hasSpecial) requirements.special.classList.add('valid');
      
      const strength = [hasLength, hasUppercase, hasNumber, hasSpecial].filter(Boolean).length;
      
      if (password.length === 0) {
          strengthText.textContent = 'Password strength';
        } else if (strength <= 2) {
          strengthMeterFill.classList.add('weak');
          strengthText.textContent = 'Weak';
        } else if (strength === 3) {
          strengthMeterFill.classList.add('medium');
          strengthText.textContent = 'Medium';
      } else if (strength === 4) {
          strengthMeterFill.classList.add('strong');
          strengthText.textContent = 'Strong';
      }
      });
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