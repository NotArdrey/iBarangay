<?php
// header.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/dbconn.php'; // defines $pdo

// ── Session & Authentication Guard ───────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

// ── Load User Info from DB ────────────────────────────────────────
$userId = (int) $_SESSION['user_id'];

// Updated query to get role info directly from users table (primary source)
$stmt = $pdo->prepare('
    SELECT u.role_id, u.barangay_id, u.email, u.is_active
    FROM users u
    WHERE u.id = ? AND u.is_active = 1
    LIMIT 1
');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// If no role found in users table, check user_roles table as fallback
if (!$user || !$user['role_id']) {
    $stmt = $pdo->prepare('
        SELECT ur.role_id, ur.barangay_id, u.email, u.is_active
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
        WHERE u.id = ? AND u.is_active = 1
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$user || !$user['is_active']) {
    // Invalid session: user no longer exists or is inactive
    session_destroy();
    header('Location: ../pages/login.php');
    exit;
}

// ── Store in Session ──────────────────────────────────────────────
$_SESSION['role_id']     = (int) ($user['role_id'] ?? 8); // Default to resident role
$_SESSION['barangay_id'] = (int) ($user['barangay_id'] ?? 1); // Default barangay

// ── Lookup Barangay Name for Official Roles ───────────────────────
$officialRoles = [3,4,5,6,7]; // e.g. Captain, Secretary, Treasurer, etc.
if (in_array($_SESSION['role_id'], $officialRoles, true) && $_SESSION['barangay_id']) {
    $stmt2 = $pdo->prepare('
        SELECT name
        FROM barangay
        WHERE id = ?
        LIMIT 1
    ');
    $stmt2->execute([$_SESSION['barangay_id']]);
    $bName = $stmt2->fetchColumn();
    $_SESSION['barangay_name'] = $bName ?: 'iBarangay';
} else {
    $_SESSION['barangay_name'] = 'iBarangay';
}

// Debug information (remove this in production)
error_log("User ID: " . $userId . ", Role ID: " . $_SESSION['role_id'] . ", Barangay ID: " . $_SESSION['barangay_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Barangay Administration System</title>

  <!-- Tailwind & Flowbite -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/flowbite/2.2.0/flowbite.min.css" rel="stylesheet" />

  <!-- SweetAlert2 & Tesseract.js -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://unpkg.com/tesseract.js@v2.1.5/dist/tesseract.min.js"></script>

<style>
    .nav-link {
      display: flex;
      align-items: center;
      padding: 0.75rem;
      border-radius: 0.5rem;
      transition: background-color 0.2s ease, color 0.2s ease;
    }
    .nav-link:hover {
      background-color: #f3f4f6;
      color: #1e40af;
    }
    .icon-container {
      width: 2.5rem;
      height: 2.5rem;
      background-color: #f9fafb;
      border-radius: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 0.75rem;
      transition: transform 0.2s ease, background-color 0.2s ease;
      overflow: visible;
    }
    .icon-container svg {
      display: block;
      width: 1.25rem;
      height: 1.25rem;
    }
    .nav-link:hover .icon-container {
      transform: scale(1.05);
      background-color: #e0e7ff;
    }
    .modal {
      position: fixed;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.6);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 50;
    }
    .modal-content {
      background: #fff;
      padding: 1.5rem;
      border-radius: 0.5rem;
      width: 90%;
      max-width: 800px;
    }
    
    /* Custom scrollbar styling for sidebar navigation */
    nav::-webkit-scrollbar {
      width: 6px;
    }
    nav::-webkit-scrollbar-track {
      background: #f1f5f9;
      border-radius: 3px;
    }
    nav::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 3px;
    }
    nav::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }
    
    /* Firefox scrollbar styling */
    nav {
      scrollbar-width: thin;
      scrollbar-color: #cbd5e1 #f1f5f9;
    }

    .swal2-confirm-button,
    .swal2-cancel-button {
      opacity: 1 !important;
      visibility: visible !important;
      display: inline-block !important;
      padding: 12px 30px !important;
      font-size: 1.1em !important;
      font-weight: 500 !important;
      border-radius: 5px !important;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
      transition: all 0.3s ease !important;
    }

    .swal2-confirm-button {
      background-color: #d33 !important;
      color: white !important;
      border: none !important;
    }

    .swal2-confirm-button:hover {
      background-color: #c22 !important;
      transform: translateY(-1px) !important;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2) !important;
    }

    .swal2-cancel-button {
      background-color: #3085d6 !important;
      color: white !important;
      border: none !important;
      margin-left: 10px !important;
    }

    .swal2-cancel-button:hover {
      background-color: #2b7ac9 !important;
      transform: translateY(-1px) !important;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2) !important;
    }
  </style>
</head>
<body class="bg-gray-50">

  <!-- Sidebar Navigation -->
  <aside class="fixed left-0 top-0 h-screen w-64 bg-white border-r border-gray-200 shadow-md flex flex-col">
    <div class="p-4 border-b border-gray-200 flex-shrink-0">
      <h2 class="text-2xl font-bold text-blue-800">
        <?= htmlspecialchars($_SESSION['barangay_name'] ?? 'iBarangay') ?>
      </h2>
      <p class="text-sm text-gray-600">Administration System</p>
    </div>
    <nav aria-label="Main Navigation" class="flex-1 overflow-y-auto p-4">
      <ul class="space-y-1">

        <!-- Dashboard -->
        <li>
  <a href="../pages/barangay_admin_dashboard.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Dashboard</span>
  </a>
</li>

<!-- Residents -->
<li>
  <a href="../pages/residents.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Resident Accounts</span>
  </a>
</li>

<!-- Manage Census -->
<li>
  <a href="../pages/manage_census.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
          d="M17 20h5v-2a4 4 0 00-5-3.87M9 20H4v-2a4 4 0 015-3.87M12 12a4 4 0 100-8 4 4 0 000 8zm6 0a3 3 0 100-6 3 3 0 000 6zm-12 0a3 3 0 100-6 3 3 0 000 6z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Manage Census</span>
  </a>
</li>

<?php if ((int)$_SESSION['role_id'] === 3): ?>
<!-- Staff Officials - Only visible to Barangay Captain (role_id = 3) -->
<li>
  <a href="../pages/captain_page.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Staff Officials</span>
  </a>
</li>
<?php endif; ?>

<!-- Document Requests -->
<li>
  <a href="../pages/doc_request.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Document Requests</span>
  </a>
</li>

<!-- PayMongo Settings -->
<li>
  <a href="../pages/paymongo_settings.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M16.5 10.5V6m0 0l-3 3m3-3l3 3M4.5 19.5h15a3 3 0 003-3 2.993 2.993 0 00-2.88-3H19.5V9a4.5 4.5 0 00-9 0v4.5H3.38A2.993 2.993 0 00.5 16.5a3 3 0 003 3z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">PayMongo Settings</span>
  </a>
</li>

<?php if (in_array((int)$_SESSION['role_id'], [3,4,5,6,7])): ?>
<!-- Manage Services (Officials Only) -->
<li>
  <a href="../pages/manage_services.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Manage Services</span>
  </a>
</li>
<?php endif; ?>


<!-- Blotter -->
<li>
  <a href="../pages/blotter.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Blotter</span>
  </a>
</li>

<!-- Events -->
<li>
  <a href="../pages/events.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Events</span>
  </a>
</li>

<!-- Audit Trail -->
<li>
  <a href="../pages/audit_trail.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
        d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Audit Trail</span>
  </a>
</li>

<li>
  <a href="../pages/barangay_backup.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" 
          d="M16.5 10.5V6m0 0l-3 3m3-3l3 3M4.5 19.5h15a3 3 0 003-3 2.993 2.993 0 00-2.88-3H19.5V9a4.5 4.5 0 00-9 0v4.5H3.38A2.993 2.993 0 00.5 16.5a3 3 0 003 3z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Backup and Restoration</span>
  </a>
</li>

<!-- Log out -->
<li>
  <form id="logoutForm" action="../functions/logout.php" method="post">
    <button type="submit" id="logoutBtn" class="nav-link w-full text-left">
      <span class="icon-container logout">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" 
          d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
        </svg>
      </span>
      <span class="font-medium text-gray-700">Logout</span>
    </button>
  </form>
</li>

      </ul>
    </nav>
  </aside>

  <main class="ml-64 p-8 space-y-8">
    <script>
   document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('logoutBtn').addEventListener('click', function(e) {
    e.preventDefault();
    Swal.fire({
      title: 'Ready to leave?',
      text: "Select 'Logout' below if you are ready to end your current session.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#d33',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Logout',
      cancelButtonText: 'Cancel',
      customClass: {
        confirmButton: 'swal2-confirm-button',
        cancelButton: 'swal2-cancel-button'
      }
    }).then((result) => {
      if (result.isConfirmed) {
        document.getElementById('logoutForm').submit();
      }
    });
  });
});
</script>