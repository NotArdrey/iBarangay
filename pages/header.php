<?php
// header.php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/dbconn.php'; // defines $pdo

// ── Session & Authentication Guard ───────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: ../pages/index.php');
    exit;
}

// ── Load User Info from DB ────────────────────────────────────────
$userId = (int) $_SESSION['user_id'];
$stmt = $pdo->prepare('
    SELECT role_id, barangay_id
      FROM Users
     WHERE user_id = ?
    LIMIT 1
');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Invalid session: user no longer exists
    session_destroy();
    header('Location: ../pages/index.php');
    exit;
}

// ── Store in Session ──────────────────────────────────────────────
$_SESSION['role_id']     = (int) $user['role_id'];
$_SESSION['barangay_id'] = (int) $user['barangay_id'];

// ── Lookup Barangay Name for Official Roles ───────────────────────
$officialRoles = [3,4,5,6,7]; // e.g. Captain, Secretary, Treasurer, etc.
if (in_array($_SESSION['role_id'], $officialRoles, true)) {
    $stmt2 = $pdo->prepare('
        SELECT barangay_name
          FROM Barangay
         WHERE barangay_id = ?
        LIMIT 1
    ');
    $stmt2->execute([$_SESSION['barangay_id']]);
    $bName = $stmt2->fetchColumn();
    $_SESSION['barangay_name'] = $bName ?: 'Barangay Hub';
} else {
    unset($_SESSION['barangay_name']);
}
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
    .icon-container.logout {
      background-color: #fff5f5;
    }
    .nav-link:hover .icon-container.logout {
      background-color: #fee2e2;
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
  </style>
</head>
<body class="bg-gray-50">

  <!-- Sidebar Navigation -->
  <aside class="fixed left-0 top-0 h-screen w-64 bg-white border-r border-gray-200 p-4 shadow-md">
    <div class="mb-8 px-2">
    <h2 class="text-2xl font-bold text-blue-800">
  <?= htmlspecialchars($_SESSION['barangay_name'] ?? 'Barangay Hub') ?>
</h2>
      <p class="text-sm text-gray-600">Administration System</p>
    </div>
    <nav aria-label="Main Navigation">
      <ul class="space-y-1">

        <!-- Dashboard -->
        <li>
  <a href="../pages/barangay_admin_dashboard.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
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
        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Residents</span>
  </a>
</li>

<!-- Document Requests -->
<li>
  <a href="../pages/doc_request.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Document Requests</span>
  </a>
</li>

<!-- Blotter -->
<li>
  <a href="../pages/blotter.php" class="nav-link">
    <span class="icon-container">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
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
        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
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
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
      </svg>
    </span>
    <span class="font-medium text-gray-700">Audit Trail</span>
  </a>
</li>

<!-- Logout -->
<li>
  <form id="logoutForm" action="../functions/logout.php" method="post">
    <button type="button" id="logoutBtn" class="nav-link">
      <span class="icon-container logout">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
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
        document.getElementById('logoutBtn').addEventListener('click', () => {
          Swal.fire({
            title: 'Ready to leave?',
            text: "Select 'Logout' below if you are ready to end your current session.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Logout',
            cancelButtonText: 'Cancel'
          }).then((result) => {
            if (result.isConfirmed) {
              document.getElementById('logoutForm').submit();
            }
          });
        });
      });
    </script>
