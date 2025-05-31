<?php
session_start();
require "../config/dbconn.php";

$conn = $pdo;
global $conn;

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 4;
$user_info = null;
$barangay_name = "Barangay";
$barangay_id = 32; 

if ($user_id) {
    $sql = "SELECT u.first_name, u.last_name, u.barangay_id, b.name as barangay_name 
            FROM users u 
            LEFT JOIN barangay b ON u.barangay_id = b.id 
            WHERE u.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $user_info = $row;
        $barangay_name = $row['barangay_name'];
        $barangay_id = $row['barangay_id'];
    }

    $stmt = null;
}

// NOW INCLUDE NAVBAR AFTER VARIABLES ARE SET
require "../components/navbar.php";

$emergency_contacts = [
    'local_barangay_contact' => null,
    'pnp_contact' => null,
    'bfp_contact' => null
];

// Use the correct column names from your database schema
$sqlLocal = "SELECT contact_number FROM barangay_settings WHERE barangay_id = ?";
$stmtLocal = $conn->prepare($sqlLocal);
$stmtLocal->execute([$barangay_id]);
$rowLocal = $stmtLocal->fetch(PDO::FETCH_ASSOC);
$emergency_contacts['local_barangay_contact'] = $rowLocal && !empty($rowLocal['contact_number'])
    ? $rowLocal['contact_number']
    : 'No Available Number';

$sqlGlobal = "SELECT pnp_contact, bfp_contact FROM barangay_settings WHERE barangay_id = 0";
$stmtGlobal = $conn->prepare($sqlGlobal);
$stmtGlobal->execute();
$rowGlobal = $stmtGlobal->fetch(PDO::FETCH_ASSOC);
$emergency_contacts['pnp_contact'] = $rowGlobal && !empty($rowGlobal['pnp_contact'])
    ? $rowGlobal['pnp_contact']
    : 'No Available Number';
$emergency_contacts['bfp_contact'] = $rowGlobal && !empty($rowGlobal['bfp_contact'])
    ? $rowGlobal['bfp_contact']
    : 'No Available Number';

$stmt = null;

$announcements = [];
$sql = "SELECT title, description, start_datetime, location, organizer 
        FROM events 
        WHERE barangay_id = ? 
        AND start_datetime >= NOW() 
        ORDER BY start_datetime ASC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute([$barangay_id]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $announcements[] = $row;
}
$stmt = null;

$sql = "SELECT u.first_name, u.last_name, r.name as role
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.barangay_id = ? 
          AND r.name IN ('barangay_captain','barangay_secretary','barangay_treasurer','barangay_councilor')
        ORDER BY FIELD(r.name, 'barangay_captain','barangay_secretary','barangay_treasurer','barangay_councilor')";
$stmt = $conn->prepare($sql);
$stmt->execute([$barangay_id]);
$council = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>iBarangay - Community Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <link href="https://unpkg.com/aos@next/dist/aos.css" rel="stylesheet" />
  <style>

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    :root {
      --primary-color: #0056b3;
      --primary-dark: #003366;
      --secondary-color: #3498db;
      --text-dark: #2c3e50;
      --text-light: #7f8c8d;
      --bg-light: #f8f9fa;
      --white: #ffffff;
      --sidebar-bg: #2c3e50;
      --sidebar-hover: #34495e;
      --border-light: #e0e0e0;
      --shadow-sm: 0 2px 10px rgba(0,0,0,0.1);
      --shadow-md: 0 5px 15px rgba(0,0,0,0.08);
      --shadow-lg: 0 15px 30px rgba(0,0,0,0.1);
    }

    /* Remove scrollbar for WebKit and Firefox */
    html::-webkit-scrollbar, body::-webkit-scrollbar {
      display: none;
    }
    body {
      -ms-overflow-style: none; /* IE and Edge */
      scrollbar-width: none; /* Firefox */
    }

    /* Ensure body takes full height and navbar stays on top */
    html, body {
      height: 100%;
    }

    body {
      display: flex;
      flex-direction: column;
    }

    /* Main wrapper to contain everything below navbar */
    .page-wrapper {
      flex: 1;
      margin-top: 70px; /* Add top margin equal to navbar height */
    }

    .main-layout {
      display: flex;
      min-height: calc(100vh - 70px); /* Adjust for navbar height */
    }

    .main-content-area {
      flex: 1;
      width: 100%;
      min-width: 0; /* Prevents overflow on flex children */
    }

    .hero {
      background: url("../photo/bg2.jpg") no-repeat;
      background-position: 50% 60%;
      color: #ffffff;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.6);
      padding: clamp(3rem, 8vw, 6rem) clamp(1rem, 5vw, 5%) clamp(2rem, 5vw, 4rem);
      position: relative;
      overflow: hidden;
      min-height: 80vh;
      display: flex;
      align-items: center;
      width: 100%;
      box-sizing: border-box;
    }

    .hero-overlay {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0,0,0,0.3);
    }

    .hero-content {
      position: relative;
      z-index: 2;
      text-align: center;
      max-width: 800px;
      margin: 0 auto;
      width: 100%;
    }

    .hero-content h1 {
      margin-bottom: clamp(1rem, 2vw, 1.5rem);
      font-weight: 700;
      font-size: clamp(2rem, 6vw, 60px); /* Responsive font size */
      word-break: break-word;
    }

    .hero-content p {
      font-size: clamp(1rem, 2vw, 1.3rem);
      margin-bottom: clamp(1.5rem, 3vw, 2.5rem);
      opacity: 0.9;
    }

    .cta-button {
      /* Updated to have the same color as "Just In" news */
      background: var(--sidebar-hover);
      color: var(--white);
      padding: 1rem 2rem; /* increased padding */
      border-radius: 50px;
      text-decoration: none;
      font-weight: 600;
      font-size: 1.1rem;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }

    .cta-button:hover {
      transform: translateY(-3px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.3);
    }

    /* Sidebar Styles */
    .sidebar {
      width: min(350px, 90vw);
      background: var(--sidebar-bg);
      color: white;
      position: sticky;
      top: 70px; /* Adjust to be below navbar */
      height: calc(100vh - 70px); /* Adjust height */
      overflow-y: auto;
      flex-shrink: 0;
    }

    .sidebar-header {
      background: var(--sidebar-hover);
      padding: clamp(1rem, 2vw, 1.5rem);
      border-bottom: 1px solid #465a75;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .sidebar-header h2 {
      font-size: clamp(1.2rem, 2vw, 1.5rem);
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .sidebar-header i {
      color: var(--secondary-color);
    }

    .sidebar-content {
      padding: 0;
    }

    .sidebar-item {
      padding: clamp(1rem, 2vw, 1.5rem);
      border-bottom: 1px solid #465a75;
      transition: background 0.3s ease;
      cursor: pointer;
    }

    .sidebar-item:hover {
      background: var(--sidebar-hover);
    }

    .sidebar-time {
      color: var(--secondary-color);
      font-size: clamp(0.7rem, 1vw, 0.8rem);
      font-weight: 500;
      margin-bottom: 0.5rem;
    }

    .sidebar-item h4 {
      font-size: clamp(0.9rem, 1.5vw, 1rem);
      margin-bottom: 0.8rem;
      color: #ecf0f1;
      font-weight: 500;
      line-height: 1.3;
    }

    .sidebar-item p {
      font-size: clamp(0.8rem, 1vw, 0.85rem);
      line-height: 1.4;
      color: #bdc3c7;
      margin-bottom: 0.8rem;
    }

    .sidebar-meta {
      font-size: clamp(0.7rem, 1vw, 0.75rem);
      color: #95a5a6;
      display: flex;
      align-items: center;
      gap: 0.3rem;
      flex-wrap: wrap;
    }

    .sidebar-meta i {
      color: var(--secondary-color);
    }

    /* Mobile Sidebar Styles */
    .sidebar.mobile-announcements {
      width: min(calc(100% - 2rem), 600px);
      position: static;
      height: auto;
      max-height: min(70vh, 800px);
      margin: 2rem auto;
      border-radius: clamp(10px, 2vw, 15px);
      overflow: hidden;
      box-shadow: var(--shadow-md);
      transition: all 0.3s ease;
    }

    @media (max-width: 768px) {
      .sidebar.mobile-announcements {
        width: calc(100% - 2rem);
        margin: 1rem;
        max-height: 60vh;
      }
    }

    @media (max-width: 480px) {
      .sidebar.mobile-announcements {
        width: calc(100% - 1rem);
        margin: 0.5rem;
        border-radius: 8px;
      }
    }

    @media (max-width: 600px) {
      .sidebar.mobile-announcements {
        width: 90%;
        margin: 1rem auto;
      }
    }

    /* About Section */
    .section-divider {
      width: 100%;
      border: none;
      border-top: 2px solid #e0e0e0;
      margin: 2.5rem 0 2.5rem 0;
      box-sizing: border-box;
    }

    .about-section {
      padding: clamp(2rem, 5vw, 4rem) clamp(1rem, 5vw, 5%);
      background: var(--bg-light); /* Ensure grey background */
    }

    .section-header {
      text-align: center;
      margin-bottom: clamp(2rem, 4vw, 3rem);
    }

    .section-header h2 {
      color: var(--text-dark);
      margin-bottom: clamp(0.5rem, 1vw, 1rem);
      font-weight: 600;
    }

    .section-header p {
      font-size: clamp(1rem, 1.5vw, 1.2rem);
      color: var(--text-light);
      max-width: 600px;
      margin: 0 auto;
    }

    /* FIXED Carousel Styles */
    .org-carousel-wrapper {
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 1rem;
    }

    .carousel-container {
      position: relative;
      display: flex;
      align-items: center;
      gap: clamp(0.5rem, 2vw, 2rem);
      padding: clamp(1rem, 2vw, 2rem) 0;
    }

    .carousel-track {
      display: flex;
      overflow: hidden;
      scroll-snap-type: x mandatory;
      -webkit-overflow-scrolling: touch;
      width: 100%;
      scroll-behavior: smooth;
      gap: 1rem;
      padding: 0.5rem;
    }

    .carousel-track::-webkit-scrollbar {
      display: none;
    }

    .carousel-slide {
      flex-shrink: 0;
      scroll-snap-align: start;
      transition: opacity 0.3s ease;
      /* Width will be set dynamically by JavaScript */
    }

    .official-card {
      text-align: center;
      padding: clamp(1.5rem, 3vw, 3rem) clamp(1rem, 2vw, 2rem);
      background: var(--white);
      border: 1px solid #eee;
      border-radius: 15px;
      transition: all 0.2s ease;
      min-height: 250px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      height: 100%;
      width: 100%;
    }

    .official-card:hover {
      border-color: #ddd;
      box-shadow: var(--shadow-md);
      transform: translateY(-2px);
    }

    .official-avatar {
      width: clamp(70px, 15vw, 100px);
      height: clamp(70px, 15vw, 100px);
      border-radius: 50%;
      background: #f5f5f5;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto clamp(1rem, 2vw, 1.5rem);
      font-size: clamp(1.2rem, 2vw, 1.5rem);
      font-weight: 500;
      color: #666;
      border: 3px solid #eee;
    }

    .official-card h3 {
      font-size: clamp(1.1rem, 2vw, 1.3rem);
      font-weight: 500;
      color: var(--text-dark);
      margin: 0 0 0.5rem 0;
      line-height: 1.4;
    }

    .official-card p {
      font-size: clamp(0.9rem, 1.5vw, 1rem);
      color: #888;
      margin: 0;
      font-weight: 300;
    }

    .nav-btn {
      width: clamp(40px, 5vw, 50px);
      height: clamp(40px, 5vw, 50px);
      border: 1px solid #ddd;
      background: var(--white);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
      font-size: clamp(0.9rem, 1.5vw, 1rem);
      color: #666;
      flex-shrink: 0;
    }

    .nav-btn:hover:not(:disabled) {
      border-color: #ccc;
      color: var(--text-dark);
      transform: scale(1.1);
    }

    .nav-btn:disabled {
      opacity: 0.3;
      cursor: not-allowed;
    }

    .carousel-dots {
      display: flex;
      justify-content: center;
      gap: clamp(0.5rem, 1vw, 1rem);
      margin-top: clamp(1rem, 2vw, 2rem);
    }

    .dot {
      width: clamp(8px, 1vw, 12px);
      height: clamp(8px, 1vw, 12px);
      border-radius: 50%;
      background: #ddd;
      cursor: pointer;
      transition: all 0.3s ease;
      border: none;
    }

    .dot.active {
      background: var(--primary-color);
      transform: scale(1.3);
    }

    .dot:hover {
      background: var(--secondary-color);
    }

    /* Services Section */
    .services-section {
      padding: clamp(2rem, 5vw, 4rem) clamp(1rem, 5vw, 5%);
      background: var(--bg-light); /* Changed from white to grey */
    }

    .services-container {
      max-width: 1200px;
      margin: 0 auto;
    }

    .service-category {
      margin-bottom: clamp(1.5rem, 3vw, 2rem);
      background: white;
      border-radius: 20px;
      padding: 0;
      box-shadow: var(--shadow-md);
      border: 1px solid rgba(0,0,0,0.05);
      overflow: hidden;
    }

    .category-header {
      position: relative;
      text-align: center;
      padding: clamp(1.5rem, 3vw, 2.5rem);
      background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
      color: white;
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .category-header:hover {
      background: linear-gradient(135deg, var(--primary-dark) 0%, #001a33 100%);
    }

    .category-icon {
      width: clamp(60px, 10vw, 80px);
      height: clamp(60px, 10vw, 80px);
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto clamp(1rem, 2vw, 1.5rem);
      font-size: clamp(1.5rem, 3vw, 2rem);
      color: white;
    }

    .category-header h3 {
      font-size: clamp(1.5rem, 3vw, 2.2rem);
      color: white;
      margin-bottom: 0.8rem;
      font-weight: 600;
    }

    .category-header p {
      color: rgba(255, 255, 255, 0.9);
      font-size: clamp(0.9rem, 1.5vw, 1rem);
    }

    .toggle-icon {
      position: absolute;
      bottom: 1rem;
      right: clamp(1rem, 2vw, 2rem);
      font-size: clamp(1.2rem, 2vw, 1.5rem);
      color: rgba(255, 255, 255, 0.8);
      transition: transform 0.3s ease;
    }

    .toggle-icon.expanded {
      transform: rotate(180deg);
    }

    .services-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
      gap: clamp(1rem, 2vw, 2rem);
      padding: clamp(1.5rem, 3vw, 2.5rem);
      transition: all 0.3s ease;
      opacity: 0;
      max-height: 0;
      overflow: hidden;
    }

    .services-grid.show {
      opacity: 1;
      max-height: none;
    }

    .service-item {
      background: var(--white);
      border-radius: 15px;
      padding: clamp(1.5rem, 3vw, 2rem);
      border: 2px solid #f1f2f6;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
      cursor: pointer;
    }

    .service-item::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--secondary-color) 0%, #2ecc71 100%);
      transform: scaleX(0);
      transition: transform 0.3s ease;
    }

    .service-item:hover::before {
      transform: scaleX(1);
    }

    .service-item:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-lg);
      border-color: #e8e9ef;
    }

    .service-icon {
      width: clamp(50px, 8vw, 60px);
      height: clamp(50px, 8vw, 60px);
      border-radius: 12px;
      background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: clamp(1rem, 2vw, 1.5rem);
      font-size: clamp(1.2rem, 2vw, 1.5rem);
      color: white;
    }

    .service-content h4 {
      font-size: clamp(1.1rem, 2vw, 1.3rem);
      color: var(--text-dark);
      margin-bottom: clamp(0.75rem, 1.5vw, 1rem);
      font-weight: 600;
      line-height: 1.3;
    }

    .service-content p {
      color: var(--text-light);
      line-height: 1.6;
      margin-bottom: clamp(1rem, 2vw, 1.5rem);
      font-size: clamp(0.85rem, 1.5vw, 0.95rem);
    }

    .service-cta {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      color: var(--secondary-color);
      text-decoration: none;
      font-weight: 500;
      transition: all 0.2s ease;
      font-size: clamp(0.85rem, 1.5vw, 0.9rem);
    }

    .service-cta:hover {
      color: #2980b9;
      gap: 0.8rem;
    }

    .service-cta i {
      font-size: 0.8rem;
      transition: transform 0.2s ease;
    }

    .service-cta:hover i {
      transform: translateX(3px);
    }

    /* Contact Section */
    .contact-section {
      padding: clamp(2rem, 5vw, 4rem) clamp(1rem, 5vw, 5%);
      background: var(--bg-light); /* Ensure grey background */
    }

    .contact-container {
      max-width: 1000px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
      gap: clamp(1.5rem, 3vw, 2rem);
    }

    .contact-card {
      background: white;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: var(--shadow-md);
      border: 1px solid rgba(0,0,0,0.05);
      transition: all 0.3s ease;
      position: relative;
    }

    .contact-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-lg);
    }

    .contact-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      transition: transform 0.3s ease;
    }

    .emergency-card::before {
      background: linear-gradient(90deg, #e74c3c 0%, #c0392b 100%);
    }

    .services-card::before {
      background: linear-gradient(90deg, #2ecc71 0%, #27ae60 100%);
    }

    .card-header {
      text-align: center;
      padding: clamp(1.5rem, 3vw, 2.5rem) clamp(1rem, 2vw, 2rem) clamp(1rem, 2vw, 1.5rem);
      border-bottom: 1px solid #f1f2f6;
    }

    .card-icon {
      width: clamp(60px, 10vw, 80px);
      height: clamp(60px, 10vw, 80px);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto clamp(1rem, 2vw, 1.5rem);
      font-size: clamp(1.5rem, 3vw, 2rem);
      color: white;
    }

    .emergency-icon {
      background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    }

    .services-icon {
      background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
    }

    .card-header h3 {
      font-size: clamp(1.3rem, 2.5vw, 1.8rem);
      color: var(--text-dark);
      margin-bottom: 0.5rem;
      font-weight: 600;
    }

    .card-header p {
      color: var(--text-light);
      font-size: clamp(0.9rem, 1.5vw, 1rem);
      margin: 0;
    }

    .contact-info {
      padding: clamp(1.5rem, 3vw, 2rem);
    }

    .contact-item {
      display: flex;
      flex-direction: column;
      gap: 0.8rem;
      margin-bottom: clamp(1.5rem, 3vw, 2rem);
      padding-bottom: clamp(1rem, 2vw, 1.5rem);
      border-bottom: 1px solid var(--bg-light);
    }

    .contact-item:last-child {
      margin-bottom: 0;
      padding-bottom: 0;
      border-bottom: none;
    }

    .contact-label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.8rem;
      font-weight: 500;
      color: var(--text-dark);
      font-size: clamp(0.85rem, 1.5vw, 0.95rem);
      flex-wrap: wrap;
    }

    .contact-value {
      color: #34495e;
      font-size: clamp(0.9rem, 1.5vw, 1rem);
      line-height: 1.5;
      font-weight: 400;
      text-align: center;
    }

    /* Scroll to Top */
    .scroll-top {
      position: fixed;
      bottom: clamp(1.5rem, 3vw, 2rem);
      right: clamp(1.5rem, 3vw, 2rem);
      width: clamp(45px, 7vw, 55px);
      height: clamp(45px, 7vw, 55px);
      background: var(--white);
      border: 1px solid #ddd;
      border-radius: 50%;
      display: none;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.2s ease;
      font-size: clamp(1rem, 1.5vw, 1.1rem);
      color: #666;
      z-index: 100;
      box-shadow: var(--shadow-sm);
    }

    .scroll-top:hover {
      border-color: #ccc;
      color: var(--text-dark);
      transform: scale(1.1);
    }

    .scroll-top.show {
      display: flex;
    }

    /* Footer */
    .footer {
      background: var(--sidebar-bg);
      color: white;
      text-align: center;
      padding: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 5vw, 5%);
      border-top: 2px solid #e0e0e0; 
    }

    .footer p {
      font-size: clamp(0.85rem, 1.5vw, 1rem);
    }

    /* Tablet Styles */
    @media (max-width: 1024px) {
      .carousel-slide {
        width: calc(50% - 0.5rem) !important; /* 2 cards per view on tablet */
      }
      
      .services-grid {
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      }
    }

    /* Mobile Styles */
    @media (max-width: 768px) {
      /* Mobile Carousel */
      .carousel-container {
        gap: 0.5rem;
      }

      .nav-btn {
        display: none;
      }

      .carousel-track {
        overflow-x: auto;
        padding-bottom: 1rem;
      }

      .carousel-slide {
        width: calc(100% - 1rem) !important; /* 1 card per view on mobile */
      }

      /* Mobile Service Grid */
      .services-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }

      /* Mobile Contact Cards */
      .contact-container {
        grid-template-columns: 1fr;
      }

      /* Adjust width and margins for better responsiveness on mobile devices: */
      .sidebar.mobile-announcements {
        width: calc(100% - 2rem);
        margin-left: 1rem;
        margin-right: 1rem;
      }

      /* Add margin to Just In (mobile announcements) to match services section */
      .sidebar.mobile-announcements {
        margin-left: clamp(1rem, 5vw, 5%);
        margin-right: clamp(1rem, 5vw, 5%);
      }
    }

    /* Small Mobile */
    @media (max-width: 480px) {
      .hero {
        min-height: 60vh;
      }

      .carousel-slide {
        min-width: 85vw;
      }

      .category-header {
        padding: 1.5rem 1rem;
      }

      .toggle-icon {
        right: 1rem;
        font-size: 1rem;
      }

      .service-item {
        padding: 1.25rem;
      }

      .contact-info {
        padding: 1.25rem;
      }
    }

    /* Very Small Devices */
    @media (max-width: 360px) {
      body {
        font-size: 14px;
      }

      .hero-content h1 {
        font-size: 1.5rem;
      }

      .hero-content p {
        font-size: 0.9rem;
      }

      .cta-button {
        padding: 0.75rem 1.25rem;
        font-size: 0.85rem;
      }
    }

    /* Landscape Mobile */
    @media (max-height: 600px) and (orientation: landscape) {
      .hero {
        min-height: 80vh;
        padding: 2rem 5%;
      }

      .sidebar {
        height: 100vh;
      }

      .sidebar.mobile-announcements {
        max-height: 50vh;
      }
    }

    /* Desktop First Approach for Sidebar */
    @media (min-width: 1201px) {
      .sidebar.mobile-announcements {
        display: none !important;
      }

      .carousel-slide {
        width: calc(33.333% - 0.667rem) !important; /* 3 cards per view on desktop */
      }
    }

    @media (max-width: 1200px) {
      .main-layout {
        flex-direction: column;
      }

      .sidebar.desktop-announcements {
        display: none !important;
      }

      .sidebar.mobile-announcements {
        display: block !important;
      }

      .main-content-area {
        padding-right: 0;
      }
    }

    /* Touch-friendly tap targets */
    @media (hover: none) {
      .service-item,
      .contact-card,
      .cta-button,
      .service-cta {
        -webkit-tap-highlight-color: transparent;
      }

      .service-item:active {
        transform: scale(0.98);
      }

      .cta-button:active {
        transform: scale(0.95);
      }
    }

    /* Print Styles */
    @media print {
      .sidebar,
      .scroll-top {
        display: none !important;
      }

      .main-layout {
        margin-top: 0;
      }

      .hero {
        background: none;
        color: black;
        padding: 1rem;
      }

      .hero-overlay {
        display: none;
      }

      .services-grid {
        display: block !important;
        opacity: 1 !important;
        max-height: none !important;
      }

      .service-item {
        page-break-inside: avoid;
        margin-bottom: 1rem;
      }
    }

    body, .page-wrapper {
      background: var(--bg-light) !important;
      border: none !important;
    }

    /* Ensure anchor targets are not hidden behind fixed navbar */
    section, .about-section, .services-section, .contact-section {
      scroll-margin-top: 80px; /* Adjust to navbar height + spacing */
    }

    /* Custom SweetAlert2 Styles */
    .swal-two-column-popup {
      border-radius: 12px !important;
      padding: 0 !important;
    }

    .swal-compact-title {
      font-size: 1.25rem !important;
      font-weight: 600 !important;
      color: #2c3e50 !important;
      padding: 1rem 1.5rem 0.5rem !important;
      margin-bottom: 0 !important;
    }

    .service-title {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .service-title i {
      color: #3498db;
      font-size: 1.1em;
    }

    .swal-two-column-content {
      padding: 0 1.5rem 1rem !important;
      max-height: 75vh !important;
      overflow-y: auto !important;
    }

    /* Service Modal Container */
    .service-modal-container {
      display: flex;
      gap: 1.5rem;
      text-align: left;
    }

    .left-column {
      flex: 1;
      min-width: 0;
    }

    .right-column {
      flex: 1;
      min-width: 0;
    }

    /* Photo Section */
    .service-photo-container {
      margin-bottom: 1rem;
      position: relative;
    }

    .service-photo {
      width: 100%;
      height: 160px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid #e5e7eb;
      transition: all 0.3s ease;
    }

    .clickable-photo {
      cursor: pointer;
    }

    .clickable-photo:hover {
      border-color: #3498db;
      box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
      transform: scale(1.02);
    }

    .photo-expand-hint {
      position: absolute;
      top: 0.5rem;
      right: 0.5rem;
      background: rgba(0, 0, 0, 0.7);
      color: white;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
      opacity: 0;
      transition: opacity 0.3s ease;
      pointer-events: none;
    }

    .service-photo-container:hover .photo-expand-hint {
      opacity: 1;
    }

    .service-photo-placeholder {
      width: 100%;
      height: 160px;
      background: #f3f4f6;
      border: 2px dashed #d1d5db;
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: #9ca3af;
    }

    .service-photo-placeholder i {
      font-size: 2rem;
      margin-bottom: 0.5rem;
    }

    .service-photo-placeholder p {
      margin: 0;
      font-size: 0.875rem;
    }

    /* Photo Expansion Modal Styles */
    .photo-expansion-popup {
      border-radius: 12px !important;
      background: #ffffff !important;
    }

    .photo-expansion-title {
      font-size: 1.25rem !important;
      font-weight: 600 !important;
      color: #2c3e50 !important;
      padding: 1.5rem 1.5rem 0.5rem !important;
      margin-bottom: 0 !important;
    }

    .photo-expansion-content {
      padding: 0 1.5rem 1.5rem !important;
    }

    .expanded-photo-container {
      text-align: center;
    }

    .expanded-photo {
      width: 100%;
      max-height: 70vh;
      object-fit: contain;
      border-radius: 8px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
      background: #f8f9fa;
    }

    .photo-caption {
      margin-top: 1rem;
      color: #6b7280;
      font-size: 0.875rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .photo-caption i {
      color: #10b981;
    }

    /* Responsive adjustments for photo expansion */
    @media (max-width: 768px) {
      .photo-expansion-popup {
        width: 95% !important;
        margin: 0 !important;
      }

      .expanded-photo {
        max-height: 60vh;
      }

      .photo-expansion-title {
        font-size: 1.1rem !important;
        padding: 1rem 1rem 0.5rem !important;
      }

      .photo-expansion-content {
        padding: 0 1rem 1rem !important;
      }
    }

    .verification-badge {
      margin-top: 0.5rem;
      padding: 0.5rem;
      border-radius: 6px;
      text-align: center;
      font-size: 0.875rem;
      font-weight: 500;
    }

    .verification-badge.verified {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #bbf7d0;
    }

    .verification-badge.pending {
      background: #fef3c7;
      color: #92400e;
      border: 1px solid #fde68a;
    }

    .verification-badge i {
      margin-right: 0.25rem;
    }

    /* Badges */
    .badges-container {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 1rem;
    }

    .badge {
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
      font-weight: 500;
    }

    .badge-type {
      background: #dbeafe;
      color: #1e40af;
    }

    .badge-urgent {
      background: #fee2e2;
      color: #dc2626;
    }

    .badge-high {
      background: #fed7aa;
      color: #ea580c;
    }

    .badge-normal {
      background: #f3f4f6;
      color: #374151;
    }

    .badge-availability {
      background: #dcfce7;
      color: #166534;
    }

    /* Info Sections */
    .quick-info {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      margin-bottom: 1rem;
    }

    .info-box {
      background: #f9fafb;
      padding: 0.75rem;
      border-radius: 6px;
      border: 1px solid #e5e7eb;
    }

    .info-box h4 {
      display: flex;
      align-items: center;
      gap: 0.25rem;
      font-size: 0.75rem;
      font-weight: 600;
      color: #374151;
      margin: 0 0 0.25rem 0;
    }

    .info-box h4 i {
      font-size: 0.75rem;
    }

    .info-box p {
      margin: 0;
      color: #6b7280;
      font-size: 0.875rem;
    }

    .quick-info-horizontal {
      display: flex;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .quick-info-horizontal .info-box {
      flex: 1;
    }

    .info-section {
      margin-bottom: 1rem;
    }

    .info-section h4 {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.875rem;
      font-weight: 600;
      color: #374151;
      margin: 0 0 0.5rem 0;
    }

    .info-section h4 i {
      font-size: 0.875rem;
      color: #3498db;
    }

    .info-section p {
      background: #f9fafb;
      padding: 0.75rem;
      border-radius: 6px;
      margin: 0;
      color: #6b7280;
      font-size: 0.875rem;
      line-height: 1.4;
    }

    /* Accordion */
    .accordion-section {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .accordion-item {
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      overflow: hidden;
      margin-bottom: 0.5rem;
    }

    .accordion-header {
      width: 100%;
      padding: 0.75rem;
      background: #f9fafb;
      border: none;
      text-align: left;
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.875rem;
      font-weight: 600;
      color: #374151;
      transition: background-color 0.2s;
    }

    .accordion-header:hover {
      background: #f3f4f6;
    }

    .accordion-header span {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .accordion-header i:last-child {
      transition: transform 0.2s;
    }

    .accordion-content {
      padding: 0.75rem;
      background: white;
      border-top: 1px solid #e5e7eb;
      display: none;
    }

    .accordion-content.show {
      display: block;
    }

    /* Modal Footer with Centered Contact Button */
    .modal-footer-center {
      margin-top: 2rem;
      padding-top: 1.5rem;
      border-top: 1px solid #e5e7eb;
      text-align: center;
    }

    .contact-button-centered {
      background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 0.75rem 2rem;
      font-weight: 600;
      font-size: 0.95rem;
      box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
      transition: all 0.3s ease;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: none;
      min-width: 180px;
      justify-content: center;
    }

    .contact-button-centered:hover {
      background: linear-gradient(135deg, #218838 0%, #1e9ecb 100%);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
    }

    .contact-button-centered:active {
      transform: translateY(0);
      box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
    }

    .contact-button-centered i {
      font-size: 0.9rem;
    }

    /* Hide default SweetAlert2 actions when using custom footer */
    .swal-two-column-popup .swal2-actions {
      display: none !important;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .service-modal-container {
        flex-direction: column;
        gap: 1rem;
      }

      .swal-two-column-popup {
        width: 95% !important;
      }

      .swal-two-column-content {
        padding: 0 1rem 0.75rem !important;
        max-height: 80vh !important;
      }

      .swal-compact-title {
        font-size: 1.1rem !important;
        padding: 0.75rem 1rem 0.25rem !important;
      }

      .modal-footer-center {
        margin-top: 1.5rem;
        padding-top: 1rem;
      }
      
      .contact-button-centered {
        width: 100%;
        max-width: 300px;
        padding: 1rem 1.5rem;
        font-size: 1rem;
      }

      .quick-info-horizontal {
        flex-direction: column;
        gap: 0.75rem;
      }
    }
  </style>
</head>
<body>
  <!-- Wrapper to contain everything below navbar -->
  <div class="page-wrapper">
    <main>
      <div class="main-layout">
        <!-- Main Content Area -->
        <div class="main-content-area">
          <!-- Hero Section -->
          <section class="hero" id="home">
            <div class="hero-overlay"></div>
            <div class="hero-content" data-aos="fade-up">
              <h1>Welcome to <?php echo htmlspecialchars($barangay_name); ?></h1>
              <p>Your one-stop platform for all barangay services</p>
              <a href="#services" class="btn cta-button">Explore Services</a>
            </div>
          </section>

          <!-- Sidebar - Announcements (MOBILE ONLY) -->
          <aside class="sidebar mobile-announcements" id="mobile-announcements">
            <div class="sidebar-header">
              <h2><i class="fas fa-bullhorn"></i> Just In</h2>
            </div>
            <div class="sidebar-content">
              <?php if (empty($announcements)): ?>
                <div class="sidebar-item">
                  <div class="sidebar-time">Now</div>
                  <h4>No Current Announcements</h4>
                  <p>Check back later for updates and announcements.</p>
                  <div class="sidebar-meta">
                    <i class="fas fa-info-circle"></i> System Message
                  </div>
                </div>
              <?php else: ?>
                <?php foreach ($announcements as $announcement): 
                  $datetime = new DateTime($announcement['start_datetime']);
                  $time = $datetime->format('g:i A');
                  $date = $datetime->format('F j, Y g:i A');
                ?>
                  <div class="sidebar-item">
                    <div class="sidebar-time"><?php echo $time; ?></div>
                    <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                    <p><?php echo htmlspecialchars($announcement['description']); ?></p>
                    <div class="sidebar-meta">
                      <i class="fas fa-calendar"></i> <?php echo $date; ?>
                      <?php if (!empty($announcement['location'])): ?>
                        <br><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($announcement['location']); ?>
                      <?php endif; ?>
                      <?php if (!empty($announcement['organizer'])): ?>
                        <br><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($announcement['organizer']); ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </aside>

          <!-- About Section - Meet Our Council -->
          <section class="about-section" id="about" data-aos="fade-up">
            <div class="section-header">
              <h2>Meet Our Council</h2>
              <p>Dedicated officials serving our community</p>
            </div>
            
            <div class="org-carousel-wrapper">
              <div class="carousel-container">
                <button class="nav-btn prev-btn" id="prevBtn">
                  <i class="fas fa-chevron-left"></i>
                </button>
                
                <div class="carousel-track" id="carouselTrack">
                  <?php if(!empty($council)): ?>
                    <?php foreach($council as $member): 
                      $initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                    ?>
                      <div class="carousel-slide">
                        <div class="official-card">
                          <div class="official-avatar">
                            <span><?php echo htmlspecialchars($initials); ?></span>
                          </div>
                          <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                          <p><?php echo htmlspecialchars($member['role']); ?></p>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                      <div class="carousel-slide">
                        <div class="official-card">
                          <div class="official-avatar">
                            <span>NA</span>
                          </div>
                          <h3>No Council Members</h3>
                          <p></p>
                        </div>
                      </div>
                  <?php endif; ?>
                </div>
                
                <button class="nav-btn next-btn" id="nextBtn">
                  <i class="fas fa-chevron-right"></i>
                </button>
              </div>
              
              <div class="carousel-dots" id="carouselDots"></div>
            </div>
          </section>

          <!-- Section Divider -->
          <hr class="section-divider">

          <!-- Services Section -->
          <section class="services-section" id="services">
            <div class="services-container">
              <div class="section-header">
                <h2>Our Services</h2>
                <p>Complete range of barangay services for our community</p>
              </div>

              <!-- Barangay Certificates Category -->
              <div class="service-category">
                <div class="category-header" onclick="toggleCategory('certificates')">
                  <div class="category-icon"><i class="fas fa-certificate"></i></div>
                  <h3>Barangay Certificates</h3>
                  <p>Official documents and certifications</p>
                  <div class="toggle-icon">
                    <i class="fas fa-chevron-down" id="certificates-icon"></i>
                  </div>
                </div>
                
                <div class="services-grid" id="certificates-grid">
                  <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=barangay_clearance';">
                    <div class="service-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="service-content">
                      <h4>Barangay Clearance</h4>
                      <p>Required for employment, business permits, and various transactions</p>
                      <a href="../pages/services.php?documentType=barangay_clearance" class="service-cta">
                        Request <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>

                  <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=barangay_indigency';">
                    <div class="service-icon"><i class="fas fa-hand-holding-heart"></i></div>
                    <div class="service-content">
                      <h4>Certificate of Indigency</h4>
                      <p>For accessing social welfare programs and financial assistance</p>
                      <a href="../pages/services.php?documentType=barangay_indigency" class="service-cta">
                        Apply <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>

                  <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=business_permit_clearance';">
                    <div class="service-icon"><i class="fas fa-store"></i></div>
                    <div class="service-content">
                      <h4>Business Permit Clearance</h4>
                      <p>Barangay clearance required for business license applications</p>
                      <a href="../pages/services.php?documentType=business_permit_clearance" class="service-cta">
                        Apply <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>

                  <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=cedula';">
                    <div class="service-icon"><i class="fas fa-id-card"></i></div>
                    <div class="service-content">
                      <h4>Community Tax Certificate (Sedula)</h4>
                      <p>Annual tax certificate required for government transactions</p>
                      <a href="../pages/services.php?documentType=cedula" class="service-cta">
                        Get Certificate <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>

                  <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=proof_of_residency';">
                    <div class="service-icon"><i class="fas fa-home"></i></div>
                    <div class="service-content">
                      <h4>Certificate of Residency</h4>
                      <p>Official proof of residence in the barangay</p>
                      <a href="../pages/services.php?documentType=proof_of_residency" class="service-cta">
                        Request <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>
                </div>
              </div>

            
            <!-- Other Services Category -->
            <div class="service-category">
                <div class="category-header" onclick="toggleCategory('other')" style="cursor: pointer;">
                    <div class="category-icon"><i class="fas fa-hands-helping"></i></div>
                    <h3>Other Services</h3>
                    <p>Social services and community assistance</p>
                    <div class="toggle-icon">
                        <i class="fas fa-chevron-down" id="other-icon"></i>
                    </div>
                </div>
                
                <div class="services-grid" id="other-grid" style="display: none;">
                    <?php 
                    // Fetch active custom services
                    $services_stmt = $pdo->prepare("
                        SELECT * FROM custom_services 
                        WHERE barangay_id = ? AND is_active = 1 
                        ORDER BY display_order, name
                    ");
                    $services_stmt->execute([$barangay_id]);
                    $custom_services = $services_stmt->fetchAll();

                    if (count($custom_services) > 0):
                        foreach ($custom_services as $service): 
                    ?>
                        <div class="service-item" onclick="viewCustomServiceDetails(<?php echo htmlspecialchars(json_encode($service)); ?>)">
                            <div class="service-icon"><i class="fas <?php echo htmlspecialchars($service['icon']); ?>"></i></div>
                            <div class="service-content">
                                <h4><?php echo htmlspecialchars($service['name']); ?></h4>
                                <p><?php echo htmlspecialchars($service['description']); ?></p>
                                <a href="#" class="service-cta">
                                    View Details <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    <?php 
                        endforeach;
                    else:
                    ?>
                        <div class="col-span-full text-center py-8">
                            <p class="text-gray-500">No custom services available at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

              <!-- Blotter Services Category -->
              <div class="service-category">
                <div class="category-header" onclick="toggleCategory('blotter')">
                  <div class="category-icon"><i class="fas fa-file-signature"></i></div>
                  <h3>Blotter Services</h3>
                  <p>Incident reporting and documentation</p>
                  <div class="toggle-icon">
                    <i class="fas fa-chevron-down" id="blotter-icon"></i>
                  </div>
                </div>
                
                <div class="services-grid" id="blotter-grid">
                  <div class="service-item" onclick="window.location.href='../pages/blotter_request.php';">
                    <div class="service-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="service-content">
                      <h4>File a Blotter Report</h4>
                      <p>Report incidents and request official documentation</p>
                      <a href="../pages/blotter_request.php" class="service-cta">
                        File Report <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>

                  <div class="service-item" onclick="window.location.href='../pages/blotter_status.php';">
                    <div class="service-icon"><i class="fas fa-search"></i></div>
                    <div class="service-content">
                      <h4>Check Blotter Status</h4>
                      <p>Track the status of your blotter requests</p>
                      <a href="../pages/blotter_status.php" class="service-cta">
                        Check Status <i class="fas fa-arrow-right"></i>
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Section Divider -->
          <hr class="section-divider">

          <!-- Contact Section -->
          <section class="contact-section" id="contact" data-aos="fade-up">
            <div class="section-header">
              <h2>Contact Us</h2>
              <p>Get in touch with your barangay officials</p>
            </div>
            
            <div class="contact-container">
              <!-- Emergency Contacts Card -->
              <div class="contact-card emergency-card">
                <div class="card-header">
                  <div class="card-icon emergency-icon">
                    <i class="fas fa-phone-alt"></i>
                  </div>
                  <h3>Emergency Contacts</h3>
                  <p>24/7 Emergency Services</p>
                </div>
                <div class="contact-info text-center">
                  <div class="contact-item">
                    <div class="contact-label">
                      <i class="fas fa-shield-alt"></i>
                      <span>Philippine National Police (PNP)</span>
                    </div>
                    <div class="contact-value"><?php echo htmlspecialchars($emergency_contacts['pnp_contact']); ?></div>
                  </div>
                  <div class="contact-item">
                    <div class="contact-label">
                      <i class="fas fa-fire-extinguisher"></i>
                      <span>Bureau of Fire Protection (BFP)</span>
                    </div>
                    <div class="contact-value"><?php echo htmlspecialchars($emergency_contacts['bfp_contact']); ?></div>
                  </div>
                  <div class="contact-item">
                    <div class="contact-label">
                      <i class="fas fa-building"></i>
                      <span>Barangay Contact Number</span>
                    </div>
                    <div class="contact-value"><?php echo htmlspecialchars($emergency_contacts['local_barangay_contact']); ?></div>
                  </div>
                </div>
              </div>

              <!-- Quick Services Card -->
              <div class="contact-card services-card">
                <div class="card-header">
                  <div class="card-icon services-icon">
                    <i class="fas fa-headset"></i>
                  </div>
                  <h3>Get Help</h3>
                  <p>How to reach us for assistance</p>
                </div>
                <div class="contact-info text-center">
                  <div class="contact-item">
                    <div class="contact-label">
                      <i class="fas fa-file-alt"></i>
                      <span>Document Requests</span>
                    </div>
                    <div class="contact-value">Use our online services portal</div>
                  </div>
                  <div class="contact-item">
                    <div class="contact-label">
                      <i class="fas fa-clock"></i>
                      <span>Regular Office Hours</span>
                    </div>
                    <div class="contact-value">Monday - Friday<br>8:00 AM - 5:00 PM</div>
                  </div>
                  <div class="contact-item">
                    <div class="contact-label">
                      <i class="fas fa-exclamation-circle"></i>
                      <span>Complaints & Reports</span>
                    </div>
                    <div class="contact-value">File through our blotter services</div>
                  </div>
                </div>
              </div>
            </div>
          </section>
        </div>

        <!-- Sidebar - Announcements (DESKTOP ONLY) -->
        <aside class="sidebar desktop-announcements" id="desktop-announcements">
          <div class="sidebar-header">
            <h2><i class="fas fa-bullhorn"></i> Just In</h2>
          </div>
          <div class="sidebar-content">
            <?php if (empty($announcements)): ?>
              <div class="sidebar-item">
                <div class="sidebar-time">Now</div>
                <h4>No Current Announcements</h4>
                <p>Check back later for updates and announcements.</p>
                <div class="sidebar-meta">
                  <i class="fas fa-info-circle"></i> System Message
                </div>
              </div>
            <?php else: ?>
              <?php foreach ($announcements as $announcement): 
                $datetime = new DateTime($announcement['start_datetime']);
                $time = $datetime->format('g:i A');
                $date = $datetime->format('F j, Y g:i A');
              ?>
                <div class="sidebar-item">
                  <div class="sidebar-time"><?php echo $time; ?></div>
                  <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                  <p><?php echo htmlspecialchars($announcement['description']); ?></p>
                  <div class="sidebar-meta">
                    <i class="fas fa-calendar"></i> <?php echo $date; ?>
                    <?php if (!empty($announcement['location'])): ?>
                      <br><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($announcement['location']); ?>
                    <?php endif; ?>
                    <?php if (!empty($announcement['organizer'])): ?>
                      <br><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($announcement['organizer']); ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </aside>
      </div>

      <!-- Scroll to Top Button -->
      <button class="scroll-top" id="scrollTop">
        <i class="fas fa-arrow-up"></i>
      </button>

    </main>
    <footer class="footer">
      <p>&copy; 2025 iBarangay. All rights reserved.</p>
    </footer>
  </div>

  <!-- Scripts -->
  <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // Photo expansion function (moved from PHP to JS)
    function expandPhoto(photoUrl, serviceName) {
      Swal.fire({
        title: `${serviceName} - Verification Photo`,
        html: `
          <div class="expanded-photo-container">
            <img src="${photoUrl}" alt="Service Verification Photo" class="expanded-photo">
            <p class="photo-caption">
              <i class="fas fa-shield-alt"></i> 
              Official verification photo for ${serviceName}
            </p>
          </div>
        `,
        width: '90%',
        maxWidth: '800px',
        showCloseButton: true,
        showConfirmButton: false,
        customClass: {
          popup: 'photo-expansion-popup',
          title: 'photo-expansion-title',
          htmlContainer: 'photo-expansion-content'
        },
        backdrop: `
          rgba(0,0,0,0.8)
          url("data:image/svg+xml,%3csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3e%3cg fill='none' fill-rule='evenodd'%3e%3cg fill='%23ffffff' fill-opacity='0.1'%3e%3ccircle cx='30' cy='30' r='4'/%3e%3c/g%3e%3c/g%3e%3c/svg%3e")
        `
      });
    }

    // Initialize AOS animations
    AOS.init({
      duration: 800,
      once: true,
      easing: 'ease-out-quad',
      offset: 50
    });

    // FIXED CAROUSEL CONTROLLER
    document.addEventListener('DOMContentLoaded', function() {
      const track = document.getElementById('carouselTrack');
      const prevBtn = document.getElementById('prevBtn');
      const nextBtn = document.getElementById('nextBtn');
      const dotsContainer = document.getElementById('carouselDots');
      
      if (!track) return;
      
      const slides = track.querySelectorAll('.carousel-slide');
      const totalSlides = slides.length;
      let currentIndex = 0;
      let slidesPerView = getSlidesPerView();
      
      // FIXED: Responsive slides per view function
      function getSlidesPerView() {
        const width = window.innerWidth;
        if (width <= 768) return 1;      // Mobile: 1 card
        if (width <= 1024) return 2;     // Tablet: 2 cards
        return 3;                        // Desktop: 3 cards
      }
      
      // FIXED: Create dots based on total groups
      function createDots() {
        dotsContainer.innerHTML = '';
        const totalDots = Math.ceil(totalSlides / slidesPerView);
        
        for (let i = 0; i < totalDots; i++) {
          const dot = document.createElement('button');
          dot.classList.add('dot');
          dot.setAttribute('aria-label', `Go to slide group ${i + 1}`);
          if (i === 0) dot.classList.add('active');
          
          dot.addEventListener('click', () => {
            currentIndex = i * slidesPerView;
            // Make sure we don't go beyond the last slide
            if (currentIndex >= totalSlides) {
              currentIndex = totalSlides - slidesPerView;
            }
            updateCarousel();
          });
          
          dotsContainer.appendChild(dot);
        }
      }
      
      // FIXED: Update carousel display and controls
      function updateCarousel() {
        // Update slide widths based on current view
        slides.forEach(slide => {
          if (slidesPerView === 1) {
            slide.style.width = 'calc(100% - 1rem)';
          } else if (slidesPerView === 2) {
            slide.style.width = 'calc(50% - 0.5rem)';
          } else {
            slide.style.width = 'calc(33.333% - 0.667rem)';
          }
        });

        // Calculate scroll position
        const slideWidth = slides[0].offsetWidth;
        const gap = parseFloat(getComputedStyle(track).gap) || 16;
        const scrollDistance = currentIndex * (slideWidth + gap);
        
        // Scroll to position
        track.scrollTo({
          left: scrollDistance,
          behavior: 'smooth'
        });
        
        // FIXED: Update dots based on current position
        const dots = dotsContainer.querySelectorAll('.dot');
        const activeDotIndex = Math.floor(currentIndex / slidesPerView);
        dots.forEach((dot, index) => {
          dot.classList.toggle('active', index === activeDotIndex);
        });
        
        // Update navigation buttons
        prevBtn.disabled = currentIndex === 0;
        nextBtn.disabled = currentIndex >= totalSlides - slidesPerView;
        
        // Update button opacity
        prevBtn.style.opacity = prevBtn.disabled ? '0.3' : '1';
        nextBtn.style.opacity = nextBtn.disabled ? '0.3' : '1';
      }
      
      // Navigation functions
      function goToPrev() {
        if (currentIndex > 0) {
          currentIndex = Math.max(0, currentIndex - slidesPerView);
          updateCarousel();
        }
      }
      
      function goToNext() {
        if (currentIndex < totalSlides - slidesPerView) {
          currentIndex = Math.min(totalSlides - slidesPerView, currentIndex + slidesPerView);
          updateCarousel();
        }
      }
      
      // Event listeners
      prevBtn.addEventListener('click', goToPrev);
      nextBtn.addEventListener('click', goToNext);
      
      // Touch/Swipe support for mobile
      let touchStartX = 0;
      let touchEndX = 0;
      let isScrolling = false;
      
      track.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
        isScrolling = false;
      }, { passive: true });
      
      track.addEventListener('touchmove', (e) => {
        isScrolling = true;
      }, { passive: true });
      
      track.addEventListener('touchend', (e) => {
        if (isScrolling) return; // Don't trigger swipe if user was scrolling
        
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
      }, { passive: true });
      
      function handleSwipe() {
        const swipeThreshold = 50;
        const diff = touchStartX - touchEndX;
        
        if (Math.abs(diff) > swipeThreshold) {
          if (diff > 0) {
            // Swipe left - next
            goToNext();
          } else {
            // Swipe right - prev
            goToPrev();
          }
        }
      }
      
      // Keyboard navigation
      track.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') {
          e.preventDefault();
          goToPrev();
        } else if (e.key === 'ArrowRight') {
          e.preventDefault();
          goToNext();
        }
      });
      
      // FIXED: Handle window resize
      let resizeTimer;
      window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
          const newSlidesPerView = getSlidesPerView();
          
          if (newSlidesPerView !== slidesPerView) {
            slidesPerView = newSlidesPerView;
            // Adjust currentIndex to be valid for new slidesPerView
            currentIndex = Math.min(currentIndex, Math.max(0, totalSlides - newSlidesPerView));
            createDots();
            updateCarousel();
          }
        }, 250);
      });
      
      // Initialize carousel
      createDots();
      updateCarousel();
      
      // Make track focusable for keyboard navigation
      track.setAttribute('tabindex', '0');
    });

    // Scroll to top button
    const scrollBtn = document.getElementById('scrollTop');
    
    window.addEventListener('scroll', () => {
      if (window.pageYOffset > 300) {
        scrollBtn.classList.add('show');
      } else {
        scrollBtn.classList.remove('show');
      }
    });
    
    scrollBtn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // Toggle Category Function
    function toggleCategory(categoryName) {
      const grid = document.getElementById(categoryName + '-grid');
      const icon = document.getElementById(categoryName + '-icon');
      const toggleIcon = icon.parentElement;
      
      if (grid.style.display === 'none' || grid.style.display === '') {
        // Show the grid
        grid.style.display = 'grid';
        setTimeout(() => {
          grid.classList.add('show');
        }, 10);
        
        // Rotate the icon
        toggleIcon.classList.add('expanded');
      } else {
        // Hide the grid
        grid.classList.remove('show');
        toggleIcon.classList.remove('expanded');
        
        setTimeout(() => {
          grid.style.display = 'none';
        }, 300);
      }
    }

    // Intersection Observer for fade-in animations
    const observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, observerOptions);

    // Observe service items
    document.querySelectorAll('.service-item').forEach(item => {
      item.style.opacity = '0';
      item.style.transform = 'translateY(20px)';
      item.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
      observer.observe(item);
    });

    // Handle announcements sidebar position
    function handleAnnouncementsPosition() {
      const mobileSidebar = document.getElementById('mobile-announcements');
      const desktopSidebar = document.getElementById('desktop-announcements');
      const mainContentArea = document.querySelector('.main-content-area');
      const heroSection = document.querySelector('.hero');
      const aboutSection = document.querySelector('.about-section');

      if (window.innerWidth <= 1200) {
        // Mobile/Tablet view
        if (mobileSidebar && heroSection && aboutSection) {
          // Insert mobile sidebar after hero section
          heroSection.insertAdjacentElement('afterend', mobileSidebar);
        }
      }
    }

    handleAnnouncementsPosition();

    // Performance optimization - Debounce resize events
    function debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }

    // Apply debounce to resize handlers
    window.addEventListener('resize', debounce(handleAnnouncementsPosition, 250));

    // Custom Service Modal Functions - Updated with centered contact button
    function viewCustomServiceDetails(service) {
      // Prepare the HTML content for SweetAlert
      const photoSection = service.service_photo 
        ? `<div class="service-photo-container">
             <img src="../uploads/service_photos/${service.service_photo}" 
                  alt="Service Verification Photo" 
                  class="service-photo clickable-photo"
                  onclick="expandPhoto('../uploads/service_photos/${service.service_photo}', '${service.name}')">
             <div class="photo-expand-hint">
               <i class="fas fa-expand-alt"></i> Click to expand
             </div>
             <div class="verification-badge verified">
               <i class="fas fa-shield-alt"></i> Verified Service
             </div>
           </div>`
        : `<div class="service-photo-container">
             <div class="service-photo-placeholder">
               <i class="fas fa-image"></i>
               <p>No photo available</p>
             </div>
             <div class="verification-badge pending">
               <i class="fas fa-clock"></i> Pending Verification
             </div>
           </div>`;

      const requirementsList = formatServiceListForSwal(service.requirements);
      const guideList = formatServiceListForSwal(service.detailed_guide);
      
      // Priority badge styling
      let priorityBadge = '';
      switch(service.priority_level) {
        case 'urgent':
          priorityBadge = '<span class="badge badge-urgent">Urgent</span>';
          break;
        case 'high':
          priorityBadge = '<span class="badge badge-high">High</span>';
          break;
        default:
          priorityBadge = '<span class="badge badge-normal">Normal</span>';
      }

      const additionalNotesSection = service.additional_notes && service.additional_notes.trim()
        ? `<div class="info-section">
             <h4><i class="fas fa-sticky-note"></i> Additional Notes</h4>
             <p>${service.additional_notes}</p>
           </div>`
        : '';

      Swal.fire({
        title: `<div class="service-title">
                  <i class="fas ${service.icon}"></i>
                  ${service.name}
                </div>`,
        html: `
          <div class="service-modal-container">
            
            <!-- Left Column - Service Information -->
            <div class="left-column">
              <div class="info-section">
                <h4><i class="fas fa-info-circle"></i> Description</h4>
                <p>${service.description}</p>
              </div>

              <div class="accordion-section">
                <div class="accordion-item">
                  <button class="accordion-header" onclick="toggleSection('requirements-${service.id}')">
                    <span><i class="fas fa-clipboard-list"></i> Requirements</span>
                    <i class="fas fa-chevron-down" id="requirements-${service.id}-icon"></i>
                  </button>
                  <div id="requirements-${service.id}" class="accordion-content">
                    ${requirementsList}
                  </div>
                </div>
                
                <div class="accordion-item">
                  <button class="accordion-header" onclick="toggleSection('guide-${service.id}')">
                    <span><i class="fas fa-list-ol"></i> Step-by-Step Guide</span>
                    <i class="fas fa-chevron-down" id="guide-${service.id}-icon"></i>
                  </button>
                  <div id="guide-${service.id}" class="accordion-content">
                    ${guideList}
                  </div>
                </div>
              </div>

              <div class="quick-info-horizontal">
                <div class="info-box">
                  <h4><i class="fas fa-clock"></i> Processing Time</h4>
                  <p>${service.processing_time || 'Not specified'}</p>
                </div>
                <div class="info-box">
                  <h4><i class="fas fa-money-bill-wave"></i> Fees</h4>
                  <p>${service.fees || 'Not specified'}</p>
                </div>
              </div>

              ${additionalNotesSection}
            </div>

            <!-- Right Column - Service Photo -->
            <div class="right-column">
              <div class="photo-section">
                <h4 class="photo-section-title">Service Photo</h4>
                ${photoSection}
                
                <div class="badges-container">
                  <span class="badge badge-type">${service.service_type || 'General'}</span>
                  ${priorityBadge}
                  <span class="badge badge-availability">${service.availability_type || 'Always Available'}</span>
                </div>
              </div>
            </div>
          </div>
          
         
       
        `,
        width: '90%',
        maxWidth: '900px',
        showCloseButton: true,
        showCancelButton: false,
        showConfirmButton: false, // Disable default confirm button
        customClass: {
          popup: 'swal-two-column-popup',
          title: 'swal-compact-title',
          htmlContainer: 'swal-two-column-content'
        }
      });
    }

    // Toggle section function for accordion
    function toggleSection(sectionId) {
      const section = document.getElementById(sectionId);
      const icon = document.getElementById(sectionId + '-icon');
      
      if (section && icon) {
        if (section.style.display === 'none' || section.style.display === '') {
          section.style.display = 'block';
          section.classList.add('show');
          icon.style.transform = 'rotate(180deg)';
        } else {
          section.style.display = 'none';
          section.classList.remove('show');
          icon.style.transform = 'rotate(0deg)';
        }
      }
    }

    function formatServiceListForSwal(text) {
      if (!text) return '<p style="color: #9ca3af; font-size: 0.875rem;">Not specified</p>';
      
      return text.split('\n')
        .map(line => line.trim())
        .filter(line => line)
        .map((line, index) => {
          // Remove existing numbering or bullet points
          const cleanLine = line.replace(/^[-•*]\s*/, '').replace(/^\d+\.\s*/, '');
          return `<div style="display: flex; align-items: flex-start; margin-bottom: 0.5rem;">
                    <span style="flex-shrink: 0; width: 1.25rem; height: 1.25rem; background: #dbeafe; color: #1e40af; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 600; margin-right: 0.5rem; margin-top: 0.125rem;">
                      ${index + 1}
                    </span>
                    <span style="color: #6b7280; font-size: 0.875rem; line-height: 1.4;">${cleanLine}</span>
                  </div>`;
        })
        .join('');
    }

    function contactBarangay() {
      Swal.fire({
        title: 'Contact Barangay',
        html: `
          <div class="text-left">
            <p class="mb-4">You can contact the barangay office through:</p>
            <div class="space-y-2">
              <p><i class="fas fa-phone mr-2 text-blue-500"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($emergency_contacts['local_barangay_contact']); ?></p>
              <p><i class="fas fa-map-marker-alt mr-2 text-green-500"></i> <strong>Address:</strong> Barangay Hall, <?php echo htmlspecialchars($barangay_name); ?></p>
              <p><i class="fas fa-clock mr-2 text-orange-500"></i> <strong>Office Hours:</strong> 8:00 AM - 5:00 PM (Mon-Fri)</p>
            </div>
          </div>
        `,
        icon: 'info',
        confirmButtonText: 'Close',
        confirmButtonColor: '#3085d6'
      });
    }
  </script>
</body>
</html>