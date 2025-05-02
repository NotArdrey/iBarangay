

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Change Email Address</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    :root {
      --primary-color: #6366f1;
      --secondary-color: #4f46e5;
      --accent-color: #818cf8;
      --light-bg: #f8fafc;
    }

    body {
      background: var(--light-bg);
      min-height: 100vh;
      display: flex;
      align-items: center;
    }

    .auth-card {
      background: white;
      border-radius: 1rem;
      box-shadow: 0 10px 25px rgba(0,0,0,0.05);
      transition: transform 0.3s ease;
      max-width: 450px;
      margin: 0 auto;
    }

    .auth-card:hover {
      transform: translateY(-5px);
    }

    .card-header {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: white;
      border-radius: 1rem 1rem 0 0;
      padding: 2rem;
      text-align: center;
    }

    .form-control {
      border-radius: 0.75rem;
      padding: 0.75rem 1.25rem;
      transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .form-control:focus {
      border-color: var(--accent-color);
      box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
    }

    .input-icon {
      position: absolute;
      right: 1.25rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--primary-color);
    }

    .btn-primary {
      background: var(--primary-color);
      border: none;
      padding: 0.75rem 1.5rem;
      border-radius: 0.75rem;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .btn-primary:hover {
      background: var(--secondary-color);
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(99,102,241,0.3);
    }

    .back-link {
      color: var(--primary-color);
      transition: color 0.3s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .back-link:hover {
      color: var(--secondary-color);
    }

    @media (max-width: 576px) {
      .auth-card {
        margin: 1rem;
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="auth-card">
      <div class="card-header">
        <h2 class="mb-0">Update Email Address</h2>
        <p class="mb-0 mt-2 opacity-75">Secure your account with a new email</p>
      </div>
      <div class="card-body p-4">
        <form class="needs-validation" method="POST" novalidate>
          <div class="mb-4 position-relative">
            <label for="current_password" class="form-label">Current Password</label>
            <div class="input-group">
              <input type="password" class="form-control" id="current_password" name="current_password" required>
              <i class="fas fa-lock input-icon"></i>
            </div>
          </div>

          <div class="mb-4 position-relative">
            <label for="new_email" class="form-label">New Email Address</label>
            <div class="input-group">
              <input type="email" class="form-control" id="new_email" name="new_email" required>
              <i class="fas fa-envelope input-icon"></i>
            </div>
          </div>

          <div class="mb-4 position-relative">
            <label for="confirm_new_email" class="form-label">Confirm New Email</label>
            <div class="input-group">
              <input type="email" class="form-control" id="confirm_new_email" name="confirm_new_email" required>
              <i class="fas fa-check-circle input-icon"></i>
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-100 mt-3">
            <i class="fas fa-sync me-2"></i>Update Email
          </button>
        </form>

        <div class="text-center mt-4">
          <a href="../pages/user_dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Return to Dashboard
          </a>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  
  <?php if (!empty($error_message)): ?>
    <script>
      Swal.fire({
        icon: 'error',
        title: 'Update Failed',
        html: <?php echo json_encode($error_message); ?>,
        confirmButtonColor: 'var(--primary-color)',
        background: 'var(--light-bg)'
      });
    </script>
  <?php endif; ?>
  
  <?php if (!empty($success_message)): ?>
    <script>
      Swal.fire({
        icon: 'success',
        title: 'Verification Sent!',
        html: <?php echo json_encode($success_message); ?>,
        confirmButtonColor: 'var(--primary-color)',
        background: 'var(--light-bg)'
      });
    </script>
  <?php endif; ?>
</body>
</html>