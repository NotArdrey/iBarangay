<?php
// NAVIGATION BAR COMPONENT
if (!isset($user_info) || !$user_info) {
  // Example: adjust according to your session/user management
  if (isset($_SESSION['user_id'])) {
    // Use absolute path for dbconn.php with correct relative path
    require '../config/dbconn.php';
    $user_id = $_SESSION['user_id'];
    // Updated query to join with persons table
    $stmt = $pdo->prepare("
        SELECT p.first_name, p.last_name, b.name as barangay_name 
        FROM users u 
        LEFT JOIN persons p ON u.id = p.user_id 
        LEFT JOIN barangay b ON u.barangay_id = b.id 
        WHERE u.id = ?
    ");
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
    padding: 0.75rem 2rem;
    position: fixed; /* changed from sticky to fixed */
    top: 0;
    left: 0;
    width: 100%; /* ensure full width */
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-sizing: border-box;
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
      left: -150%;
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

  /* Notification Styles */
  .notification-bell {
    position: relative;
    margin-right: 20px;
  }

  #notifBell {
    color: #2c3e50;
    transition: color 0.3s ease;
  }

  #notifBell:hover {
    color: #0056b3;
  }

  #notifCount {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #dc3545;
    color: white;
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
  }

  .notification-dropdown {
    display: none;
    position: absolute;
    right: -10px;
    top: 40px;
    width: 320px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
  }

  .notification-dropdown.show {
    display: block;
  }

  .notification-header {
    padding: 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .notification-header h3 {
    margin: 0;
    font-size: 1rem;
    color: #2c3e50;
  }

  .view-all {
    color: #0056b3;
    text-decoration: none;
    font-size: 0.9rem;
  }

  .notification-list {
    max-height: 300px;
    overflow-y: auto;
  }

  .notification-item {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
    transition: background 0.2s;
  }

  .notification-item:hover {
    background: #f8f9fa;
  }

  .notification-item.unread {
    background: #f0f7ff;
  }

  .notification-item .title {
    font-weight: 500;
    color: #2c3e50;
    margin-bottom: 4px;
  }

  .notification-item .message {
    font-size: 0.9rem;
    color: #666;
    margin-bottom: 4px;
  }

  .notification-item .time {
    font-size: 0.8rem;
    color: #999;
  }

  .notification-item .actions {
    display: flex;
    gap: 10px;
    margin-top: 8px;
  }

  .mark-read-btn {
    background: none;
    border: none;
    color: #0056b3;
    font-size: 0.8rem;
    cursor: pointer;
    padding: 2px 8px;
    border-radius: 4px;
  }

  .mark-read-btn:hover {
    background: #e6f0ff;
  }

  @media (max-width: 768px) {
    .notification-dropdown {
      position: fixed;
      top: 70px;
      right: 0;
      left: 0;
      width: 100%;
      max-height: calc(100vh - 70px);
      border-radius: 0;
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
    
    <!-- Add notification bell -->
    <div class="notification-bell" style="position: relative;">
        <i class="fas fa-bell" id="notifBell" style="font-size: 1.2rem; cursor: pointer;"></i>
        <span id="notifCount" style="display: none;"></span>
        <div id="notifDropdown" class="notification-dropdown">
            <div class="notification-header">
                <h3>Notifications</h3>
                <a href="../pages/notifications.php" class="view-all">View All</a>
            </div>
            <div class="notification-list"></div>
        </div>
    </div>
    
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
    <div id="scheduleNotif" style="position: relative;">
      <i class="fas fa-calendar-check" style="font-size: 1.5rem; cursor: pointer;" onclick="location.href='../pages/blotter_status.php'"></i>
      <span id="schedBadge" style="position: absolute; top: -5px; right: -5px; background:#dc2626; color: white; font-size: .7rem; border-radius: 50%; padding: 2px 6px; display:none;">0</span>
    </div>
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
      // Close mobile menu when a nav link is clicked
      navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function() {
          navLinks.classList.remove('active');
        });
      });
    }
  });
  
  // Example AJAX to fetch schedule notifications count
  function updateScheduleNotif(){
    fetch('?action=get_schedule_notifications')
      .then(response => response.json())
      .then(data => {
        var badge = document.getElementById('schedBadge');
        if(data.count > 0){
          badge.textContent = data.count;
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
      });
  }
  document.addEventListener('DOMContentLoaded', updateScheduleNotif);
  // Optionally set interval to poll notifications
  setInterval(updateScheduleNotif, 60000);

  document.addEventListener('DOMContentLoaded', function() {
    const notifBell = document.getElementById('notifBell');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifCount = document.getElementById('notifCount');
    const notifList = document.querySelector('.notification-list');

    // Check if on notifications page
    const isNotificationsPage = window.location.pathname.includes('notifications.php');
    if (isNotificationsPage) {
      notifBell.style.pointerEvents = 'none';
      notifBell.style.opacity = '0.5'; // visually indicate disabled
      if (notifDropdown) notifDropdown.classList.remove('show');
    } else {
      // Toggle dropdown
      notifBell.addEventListener('click', function(e) {
          e.stopPropagation();
          notifDropdown.classList.toggle('show');
          if (notifDropdown.classList.contains('show')) {
              fetchNotifications();
          }
      });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!notifDropdown.contains(e.target) && e.target !== notifBell) {
            notifDropdown.classList.remove('show');
        }
    });

    // Fetch notifications
    function fetchNotifications() {
        fetch('../api/notifications_api.php?action=get_notifications&limit=5')
            .then(response => response.json())
            .then(data => {
                // Update notification count
                if (data.unread_count > 0) {
                    notifCount.textContent = data.unread_count;
                    notifCount.style.display = 'inline-block';
                } else {
                    notifCount.style.display = 'none';
                }

                // Update notification list
                if (data.notifications.length === 0) {
                    notifList.innerHTML = '<div class="notification-item"><div class="message">No notifications</div></div>';
                } else {
                    notifList.innerHTML = data.notifications.map(notif => `
                        <div class="notification-item ${notif.is_read ? '' : 'unread'}">
                            <div class="title">${notif.title}</div>
                            <div class="message">${notif.message}</div>
                            <div class="time">${new Date(notif.created_at).toLocaleString()}</div>
                            ${!notif.is_read ? `
                                <div class="actions">
                                    <button class="mark-read-btn" onclick="markAsRead(${notif.id})">
                                        Mark as read
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                    `).join('');
                }
            })
            .catch(error => console.error('Error fetching notifications:', error));
    }

    // Mark notification as read
    window.markAsRead = function(notificationId) {
        fetch('../api/notifications_api.php?action=mark_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `notification_id=${notificationId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                fetchNotifications();
            }
        })
        .catch(error => console.error('Error marking notification as read:', error));
    };

    // Initial fetch
    if (!isNotificationsPage) fetchNotifications();

    // Poll for new notifications every minute
    if (!isNotificationsPage) setInterval(fetchNotifications, 60000);
  });
</script>