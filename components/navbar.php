<?php
// Ensure user info and barangay name are available
if (!isset($user_info) || !$user_info) {
  // Example: adjust according to your session/user management
  if (isset($_SESSION['user_id'])) {
    // Use absolute path for dbconn.php with correct relative path
    require '../config/dbconn.php';
    $user_id = $_SESSION['user_id'];
    // Updated PDO code: use u.id instead of u.user_id in WHERE clause
    $stmt = $pdo->prepare("SELECT u.first_name, u.last_name, b.name as barangay_name FROM users u LEFT JOIN barangay b ON u.barangay_id = b.id WHERE u.id = ?");
    $stmt->execute([$user_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $user_info = [
        'first_name' => $row['first_name'],
        'last_name'  => $row['last_name']
      ];
      $barangay_name = $row['barangay_name'];
    }
    // ...existing code...
  }
}
// ...existing code...
?>
<style>
  /* Navbar Styles */
  .navbar {
    background: #fff;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    padding: 0.75rem 5%;
    position: fixed; /* changed from sticky to fixed */
    top: 0;
    left: 0;
    width: 100%; /* ensure full width */
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .navbar .logo {
    display: flex;
    align-items: center;
    gap: 1rem;
    text-decoration: none;
    color: #2c3e50;
  }

  .navbar .logo img {
    height: 50px;
    width: 50px;
    object-fit: contain;
  }

  .navbar .logo h2 {
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0;
    color: #0056b3;
  }

  .mobile-menu-btn {
    display: none;
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #2c3e50;
    cursor: pointer;
    padding: 0.5rem;
  }

  .nav-links {
    display: flex;
    align-items: center;
    gap: 2rem;
  }

  .nav-links a {
    text-decoration: none;
    color: #2c3e50;
    transition: color 0.3s ease;
    position: relative;
  }

  .nav-links a:hover {
    color: #0056b3;
  }

  .nav-links a::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 0;
    width: 0;
    height: 2px;
    background: #0056b3;
    transition: width 0.3s ease;
  }

  .nav-links a:hover::after {
    width: 100%;
  }

  .user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 8px;
    transition: background 0.3s ease;
  }

  .user-info:hover {
    background: #f8f9fa;
  }

  .user-avatar {
    font-size: 2rem;
    color: #3498db;
  }

  .user-details {
    text-align: left;
  }

  .user-name {
    color: #2c3e50;
    font-size: 0.9rem;
  }

  .user-barangay {
    font-size: 0.8rem;
    color: #7f8c8d;
  }

  .dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    display: none;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 8px;
    min-width: 160px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 100;
    margin-top: 0.5rem;
  }

  .dropdown a {
    display: block;
    padding: 12px 16px;
    color: #2c3e50;
    text-decoration: none;
    transition: background 0.2s ease;
  }

  .dropdown a:hover {
    background: #f8f9fa;
  }

  .dropdown a:first-child {
    border-bottom: 1px solid #eee;
  }

  /* Mobile Responsive */
  @media (max-width: 768px) {
    .mobile-menu-btn {
      display: block;
    }

    .nav-links {
      position: fixed;
      top: 70px;
      left: -100%;
      width: 100%;
      height: calc(100vh - 70px);
      background: #fff;
      flex-direction: column;
      padding: 2rem;
      gap: 1.5rem;
      transition: left 0.3s ease;
      box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }

    .nav-links.active {
      left: 0;
    }

    .user-info {
      width: 100%;
      justify-content: center;
      padding: 1rem;
      border-top: 1px solid #eee;
    }
  }
</style>

<nav class="navbar">
  <a href="../pages/user_dashboard.php" class="logo">
    <img src="../photo/logo.png" alt="iBarangay Logo" />
    <h2>iBarangay</h2>
  </a>
  
  <button class="mobile-menu-btn" aria-label="Toggle navigation menu">
    <i class="fas fa-bars"></i>
  </button>
  
  <div class="nav-links">
    <a href="../pages/user_dashboard.php#home">Home</a>
    <a href="../pages/user_dashboard.php#about">About</a>
    <a href="../pages/user_dashboard.php#services">Services</a>
    <a href="../pages/user_dashboard.php#contact">Contact</a>
    
    <?php if (basename($_SERVER['PHP_SELF']) === 'edit_account.php'): ?>
      <a href="../functions/logout.php" style="color: #e74c3c;">Logout</a>
    <?php else: ?>
      <div class="user-info" style="position: relative;">
        <div class="user-avatar">
          <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-details">
          <div class="user-name">
            <?php echo isset($user_info) && $user_info ? htmlspecialchars($user_info['first_name'] . ' ' . $user_info['last_name']) : 'Guest User'; ?>
          </div>
          <div class="user-barangay">
            <?php echo isset($barangay_name) ? htmlspecialchars($barangay_name) : 'Barangay'; ?>
          </div>
        </div>
        <div class="dropdown">
          <a href="../pages/edit_account.php">Edit Account</a>
          <a href="../functions/logout.php">Logout</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</nav>
<div style="height:70px;"></div> <!-- Spacer div to prevent content from being hidden -->

<script>
  // Dropdown functionality
  document.addEventListener('DOMContentLoaded', function() {
    // User dropdown
    const userInfo = document.querySelector('.user-info');
    if (userInfo) {
      const dropdown = userInfo.querySelector('.dropdown');
      
      userInfo.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
      });
      
      // Close dropdown when clicking outside
      document.addEventListener('click', function() {
        if (dropdown) {
          dropdown.style.display = 'none';
        }
      });
    }
    
    // Mobile menu toggle
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    
    if (mobileMenuBtn && navLinks) {
      mobileMenuBtn.addEventListener('click', function() {
        navLinks.classList.toggle('active');
      });
    }
  });
</script>