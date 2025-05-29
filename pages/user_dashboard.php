<?php
session_start();
require "../config/dbconn.php";
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");
//../pages/user_dashboard.php
$events_result = [];
$orgChartData = [];
$barangayName = '';
$userName = '';
$userEmail = '';

// Flag to check if user has already requested First Time Job Seeker document
$hasRequestedFirstTimeJobSeeker = false;

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Fetch user's information including barangay_id and barangay name
    $sql = "SELECT u.barangay_id, u.first_name, u.last_name, u.email, b.name as barangay_name 
            FROM users u 
            LEFT JOIN barangay b ON u.barangay_id = b.id 
            WHERE u.id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user && isset($user['barangay_id'])) {
        $barangay_id = $user['barangay_id'];
        $barangayName = $user['barangay_name'] ? $user['barangay_name'] : '';
        $userName = trim($user['first_name'] . ' ' . $user['last_name']);
        $userEmail = $user['email'];
        $currentDateTime = date('Y-m-d H:i:s');

        // Fetch events using PDO
        $events_sql = "
        SELECT *
          FROM events
         WHERE barangay_id = ?
           AND (status = 'scheduled' OR status = 'postponed')
         ORDER BY
           CASE WHEN status = 'postponed' THEN 1 ELSE 0 END,
           start_datetime ASC
        ";
        $events_stmt = $pdo->prepare($events_sql);
        $events_stmt->execute([$barangay_id]);
        $events_result = $events_stmt->fetchAll();
      
        // Check if user has already requested First Time Job Seeker document
        $firstTimeJobSeekerCheck = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM document_requests dr
            JOIN document_types dt ON dr.document_type_id = dt.id
            JOIN persons p ON dr.person_id = p.id
            WHERE p.user_id = ? 
            AND dt.name = 'First Time Job Seeker'
        ");
        $firstTimeJobSeekerCheck->execute([$user_id]);
        $result = $firstTimeJobSeekerCheck->fetch(PDO::FETCH_ASSOC);
        $hasRequestedFirstTimeJobSeeker = $result['count'] > 0;

        // Fetch organizational chart members (excluding programmer, super_admin, and resident roles)
        $orgChartQuery = $pdo->prepare("
            SELECT DISTINCT
                u.first_name,
                u.last_name,
                u.email,
                r.name as role_name,
                r.description as role_description,
                ur.start_term_date,
                ur.end_term_date,
                ur.is_active,
                bs.barangay_captain_name,
                bs.contact_number as barangay_contact
            FROM users u
            JOIN user_roles ur ON u.id = ur.user_id
            JOIN roles r ON ur.role_id = r.id
            LEFT JOIN barangay_settings bs ON u.barangay_id = bs.barangay_id
            WHERE ur.barangay_id = ? 
            AND ur.is_active = TRUE
            AND r.name NOT IN ('programmer', 'super_admin', 'resident')
            AND u.is_active = TRUE
            ORDER BY 
                CASE r.name
                    WHEN 'barangay_captain' THEN 1
                    WHEN 'barangay_secretary' THEN 2
                    WHEN 'barangay_treasurer' THEN 3
                    WHEN 'barangay_councilor' THEN 4
                    WHEN 'barangay_health_worker' THEN 5
                    WHEN 'chief_officer' THEN 6
                    ELSE 7
                END,
                u.first_name, u.last_name
        ");
        $orgChartQuery->execute([$barangay_id]);
        $orgChartData = $orgChartQuery->fetchAll();
    }
}

// Fetch persons data using PDO
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM persons WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
}

// Fetch active custom services for the user's barangay
$stmt = $pdo->prepare("
    SELECT * FROM custom_services 
    WHERE barangay_id = ? AND is_active = 1
    ORDER BY display_order, name
");
$stmt->execute([$barangay_id]);
$custom_services = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>iBarangay - Community Portal</title>
  <!-- Link to the separated CSS file -->
  <link rel="stylesheet" href="../styles/user_dashboard.css" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body>
  <!-- Navigation Bar -->
<header> 
  <nav class="navbar">
    <a href="#" class="logo">
      <img src="../photo/logo.png" alt="iBarangay Logo" />
      <h2>iBarangay</h2>
    </a>
    <button class="mobile-menu-btn" aria-label="Toggle navigation menu">
      <i class="fas fa-bars"></i>
    </button>
    <div class="nav-links">
      <a href="#home">Home</a>
      <a href="#about">About</a>
      <a href="#services">Services</a>
      <a href="#contact">Contact</a>
      
      <!-- User Info Section -->
      <?php if (!empty($userName)): ?>
      <div class="user-info" onclick="window.location.href='../pages/edit_account.php'" style="cursor: pointer;">
        <div class="user-avatar">
          <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-details">
          <div class="user-name"><?php echo htmlspecialchars($userName); ?></div>
          <div class="user-barangay"><?php echo htmlspecialchars($barangayName); ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </nav>
</header>

  <!-- Add CSS for User Info in Navbar -->
  <style>
  /* User Info Styles - Minimalist Version */
.user-info {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 0.5rem 1rem;
    background: #ffffff;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    color: #333333;
    margin-left: 1rem;
    transition: all 0.2s ease;
}

.user-info:hover {
    background: #f8f8f8;
    border-color: #d0d0d0;
}

.user-avatar {
    font-size: 1.5rem;
    color: #666666;
    display: flex;
    align-items: center;
}

.user-details {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}

.user-name {
    font-size: 0.9rem;
    font-weight: 500;
    color: #0a2240; /* navy blue */
}

.user-barangay {
    font-size: 0.75rem;
    color: #0a2240; /* navy blue */
}
  </style>

  <main>
    <!-- Hero Section -->
    <section class="hero" id="home">
      <div class="hero-overlay"></div>
      <div class="hero-content" data-aos="fade-up">
        <?php if (!empty($barangayName)): ?>
        <h1>Welcome to <?php echo htmlspecialchars($barangayName); ?></h1>
        <?php else: ?>
        <h1>Welcome to iBarangay</h1>
        <?php endif; ?>       
        <p>Your one-stop platform for all barangay services</p>

        <a href="#services" class="btn cta-button">Explore Services</a>
      </div>
    </section>

<!-- Minimalist About Section - Carousel -->
<section class="about-section" id="about" data-aos="fade-up">
    <div class="section-header">
        <h2>Meet Our Council</h2>
    </div>
    
    <?php if (!empty($orgChartData)): ?>
    <div class="org-carousel-wrapper">
        <div class="carousel-container">
            <button class="nav-btn prev-btn" id="prevBtn">
                <i class="fas fa-chevron-left"></i>
            </button>
            
            <div class="carousel-track" id="carouselTrack">
                <?php
                // Group officials by role
                $roleGroups = [];
                foreach ($orgChartData as $member) {
                    $roleGroups[$member['role_name']][] = $member;
                }
                
                // Define role hierarchy
                $roleHierarchy = [
                    'barangay_captain' => 'Captain',
                    'barangay_secretary' => 'Secretary',
                    'barangay_treasurer' => 'Treasurer',
                    'barangay_councilor' => 'Councilor',
                    'barangay_health_worker' => 'Health Worker',
                    'chief_officer' => 'Chief Officer'
                ];
                
                // Create slides for each official
                foreach ($roleHierarchy as $roleKey => $roleDisplayName):
                    if (isset($roleGroups[$roleKey])):
                        foreach ($roleGroups[$roleKey] as $member):
                ?>
                <div class="carousel-slide">
                    <div class="official-card">
                        <div class="official-avatar">
                            <span><?php echo strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)); ?></span>
                        </div>
                        <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                        <p><?php echo htmlspecialchars($roleDisplayName); ?></p>
                    </div>
                </div>
                <?php 
                        endforeach;
                    endif;
                endforeach; 
                ?>
            </div>
            
            <button class="nav-btn next-btn" id="nextBtn">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        
        <div class="carousel-dots" id="carouselDots"></div>
    </div>
    <?php else: ?>
    <div class="no-data">
        <p>No team information available</p>
    </div>
    <?php endif; ?>
</section>

<!-- Scroll to Top -->
<button class="scroll-top" id="scrollTop">
    <i class="fas fa-arrow-up"></i>
</button>

<style>
    /* About Section - Consistent styling */
    .about-section {
        padding: 4rem 5%;
        background: #f8f9fa;
        min-height: 70vh;
    }

.section-header {
    text-align: center;
    margin-bottom: 5rem;
}

.section-header h2 {
    font-size: 3rem;
    font-weight: 300;
    color: #333;
    margin: 0;
}

/* Carousel Wrapper */
.org-carousel-wrapper {
    max-width: 1400px;
    margin: 0 auto;
}

.carousel-container {
    position: relative;
    display: flex;
    align-items: center;
    gap: 2rem;
    padding: 2rem 0;
}

.carousel-track {
    display: flex;
    overflow: hidden;
    width: 100%;
    scroll-behavior: smooth;
}

.carousel-slide {
    min-width: 400px;
    flex-shrink: 0;
    padding: 0 1rem;
    transition: opacity 0.3s ease;
}

/* Official Card - Minimalist */
.official-card {
    text-align: center;
    padding: 5rem 2rem;
    background: #fff;
    border: 1px solid #eee;
    border-radius: 15px;
    transition: all 0.2s ease;
    min-height: 380px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.official-card:hover {
    border-color: #ddd;
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}

.official-avatar {
    width: 130px;
    height: 130px;
    border-radius: 50%;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 2rem;
    font-size: 2rem;
    font-weight: 500;
    color: #666;
    border: 3px solid #eee;
}

.official-card h3 {
    font-size: 1.6rem;
    font-weight: 400;
    color: #333;
    margin: 0 0 1rem 0;
    line-height: 1.4;
}

.official-card p {
    font-size: 1.2rem;
    color: #888;
    margin: 0;
    font-weight: 300;
}

/* Navigation Buttons - Minimalist */
.nav-btn {
    width: 60px;
    height: 60px;
    border: 1px solid #ddd;
    background: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 1.2rem;
    color: #666;
    flex-shrink: 0;
}

.nav-btn:hover {
    border-color: #ccc;
    color: #333;
}

.nav-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

/* Carousel Dots - Minimalist */
.carousel-dots {
    display: flex;
    justify-content: center;
    gap: 1rem;
    margin-top: 4rem;
}

.dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #ddd;
    cursor: pointer;
    transition: background 0.2s ease;
}

.dot.active {
    background: #666;
}

/* No Data State */
.no-data {
    text-align: center;
    padding: 5rem;
    color: #888;
    font-size: 1.2rem;
}

/* Scroll to Top - Minimalist */
.scroll-top {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    width: 55px;
    height: 55px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 1.1rem;
    color: #666;
    z-index: 100;
}

.scroll-top:hover {
    border-color: #ccc;
    color: #333;
}

.scroll-top.show {
    display: flex;
}

/* Responsive - Mobile */
@media (max-width: 768px) {
    .about-section {
        padding: 4rem 5%;
    }
    
    .section-header {
        margin-bottom: 4rem;
    }
    
    .section-header h2 {
        font-size: 2.5rem;
    }
    
    .carousel-container {
        gap: 1.5rem;
        padding: 1.5rem 0;
    }
    
    .carousel-slide {
        min-width: 350px;
        padding: 0 1.5rem;
    }
    
    .official-card {
        padding: 3rem 1.5rem;
        min-height: 320px;
    }
    
    .official-avatar {
        width: 110px;
        height: 110px;
        font-size: 1.8rem;
    }
    
    .official-card h3 {
        font-size: 1.4rem;
    }
    
    .official-card p {
        font-size: 1.1rem;
    }
    
    .nav-btn {
        width: 52px;
        height: 52px;
        font-size: 1.1rem;
    }
    
    .scroll-top {
        bottom: 1.5rem;
        right: 1.5rem;
        width: 50px;
        height: 50px;
    }
}

@media (max-width: 480px) {
    .about-section {
        padding: 3rem 3%;
    }
    
    .section-header h2 {
        font-size: 2.2rem;
    }
    
    .carousel-slide {
        min-width: 300px;
        padding: 0 1rem;
    }
    
    .official-card {
        padding: 2.5rem 1rem;
        min-height: 280px;
    }
    
    .official-avatar {
        width: 100px;
        height: 100px;
        font-size: 1.6rem;
    }
    
    .official-card h3 {
        font-size: 1.3rem;
    }
    
    .official-card p {
        font-size: 1rem;
    }
    
    .nav-btn {
        width: 48px;
        height: 48px;
        font-size: 1rem;
    }
}

/* Simple animations */
.carousel-slide {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
</style>

    <!-- Services Section -->
    <section class="services-section" id="services">
        <div class="services-container">
            <div class="section-header">
                <h2>Services</h2>
                <p>Complete barangay services at your fingertips</p>
            </div>

            <!-- Barangay Certificates Category -->
            <div class="service-category">
                <div class="category-header" onclick="toggleCategory('certificates')" style="cursor: pointer;">
                    <div class="category-icon"><i class="fas fa-certificate"></i></div>
                    <h3>Barangay Certificates</h3>
                    <p>Official documents and certifications</p>
                    <div class="toggle-icon">
                        <i class="fas fa-chevron-down" id="certificates-icon"></i>
                    </div>
                </div>
                
                <div class="services-grid" id="certificates-grid" style="display: none;">
                    <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=barangay_clearance';" style="cursor:pointer;">
                        <div class="service-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="service-content">
                            <h4>Barangay Clearance</h4>
                            <p>Required for employment, business permits, and various transactions</p>
                            <a href="../pages/services.php?documentType=barangay_clearance" class="service-cta">
                                Request <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=barangay_indigency';" style="cursor:pointer;">
                        <div class="service-icon"><i class="fas fa-hand-holding-heart"></i></div>
                        <div class="service-content">
                            <h4>Certificate of Indigency</h4>
                            <p>For accessing social welfare programs and financial assistance</p>
                            <a href="../pages/services.php?documentType=barangay_indigency" class="service-cta">
                                Apply <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=business_permit_clearance';" style="cursor:pointer;">
                        <div class="service-icon"><i class="fas fa-store"></i></div>
                        <div class="service-content">
                            <h4>Business Permit Clearance</h4>
                            <p>Barangay clearance required for business license applications</p>
                            <a href="../pages/services.php?documentType=business_permit_clearance" class="service-cta">
                                Apply <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=cedula';" style="cursor:pointer;">
                        <div class="service-icon"><i class="fas fa-id-card"></i></div>
                        <div class="service-content">
                            <h4>Community Tax Certificate (Sedula)</h4>
                            <p>Annual tax certificate required for government transactions</p>
                            <a href="../pages/services.php?documentType=cedula" class="service-cta">
                                Get Certificate <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="service-item" onclick="window.location.href='../pages/services.php?documentType=proof_of_residency';" style="cursor:pointer;">
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
                        <div class="service-item" onclick="viewCustomService(<?php echo htmlspecialchars(json_encode($service)); ?>)">
                            <div class="service-icon"><i class="fas <?php echo htmlspecialchars($service['icon']); ?>"></i></div>
                            <div class="service-content">
                                <h4><?php echo htmlspecialchars($service['name']); ?></h4>
                                <p><?php echo htmlspecialchars($service['description']); ?></p>
                                <a href="#" class="service-cta">
                                    Request Service <i class="fas fa-arrow-right"></i>
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
                <div class="category-header" onclick="toggleCategory('blotter')" style="cursor: pointer;">
                    <div class="category-icon"><i class="fas fa-file-signature"></i></div>
                    <h3>Blotter Services</h3>
                    <p>Incident reporting and documentation</p>
                    <div class="toggle-icon">
                        <i class="fas fa-chevron-down" id="blotter-icon"></i>
                    </div>
                </div>
                
                <div class="services-grid" id="blotter-grid" style="display: none;">
                    <div class="service-item" onclick="window.location.href='../pages/blotter_request.php';" style="cursor:pointer;">
                        <div class="service-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="service-content">
                            <h4>File a Blotter Report</h4>
                            <p>Report incidents and request official documentation</p>
                            <a href="../pages/blotter_request.php" class="service-cta">
                                File Report <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="service-item" onclick="window.location.href='../pages/blotter_status.php';" style="cursor:pointer;">
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

    <style>
    /* Services Section - Consistent styling */
    .services-section {
        padding: 4rem 5%;
        background: #fff; /* Match contact-section background */
        min-height: 70vh;
    }

    .services-container {
        max-width: 1200px;
        margin: 0 auto;
    }

    .section-header {
        text-align: center;
        margin-bottom: 3rem;
    }

    .section-header h2 {
        font-size: 2.5rem;
        color: #2c3e50;
        margin-bottom: 1rem;
        font-weight: 600;
    }

    .section-header p {
        font-size: 1.2rem;
        color: #7f8c8d;
        max-width: 600px;
        margin: 0 auto;
    }

    /* Service Category Styles */
    .service-category {
        margin-bottom: 2rem;
        background: white;
        border-radius: 20px;
        padding: 0;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.05);
        overflow: hidden;
    }

    .category-header {
        position: relative;
        text-align: center;
        padding: 2.5rem;
        /* Darker blue gradient */
        background: linear-gradient(135deg, #0056b3 0%, #003366 100%);
        color: white;
        transition: all 0.3s ease;
    }

    .category-header:hover {
        /* Even darker blue gradient for hover */
        background: linear-gradient(135deg, #003366 0%, #001a33 100%);
    }

    .category-icon {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        font-size: 2rem;
        color: white;
    }

    .category-header h3 {
        font-size: 2.2rem;
        color: white;
        margin-bottom: 0.8rem;
        font-weight: 600;
    }

    .toggle-icon {
        position: absolute;
        bottom: 1rem;
        right: 2rem;
        font-size: 1.5rem;
        color: rgba(255, 255, 255, 0.8);
        transition: transform 0.3s ease;
    }

    .toggle-icon.expanded {
        transform: rotate(180deg);
    }

    /* Services Grid */
    .services-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 2rem;
        padding: 2.5rem;
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
        background: #fff;
        border-radius: 15px;
        padding: 2rem;
        border: 2px solid #f1f2f6;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .service-item::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #3498db 0%, #2ecc71 100%);
        transform: scaleX(0);
        transition: transform 0.3s ease;
    }

    .service-item:hover::before {
        transform: scaleX(1);
    }

    .service-item:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1);
        border-color: #e8e9ef;
    }

    .service-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        /* Darker blue gradient */
        background: linear-gradient(135deg, #0056b3 0%, #003366 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.5rem;
        font-size: 1.5rem;
        color: white;
    }

    .service-content h4 {
        font-size: 1.3rem;
        color: #2c3e50;
        margin-bottom: 1rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .service-content p {
        color: #7f8c8d;
        line-height: 1.6;
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
    }

    .service-cta {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #3498db;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.2s ease;
        font-size: 0.9rem;
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

    /* Responsive Design */
    @media (max-width: 1200px) {
        .services-grid {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
        }
    }

    @media (max-width: 768px) {
        .services-section {
            padding: 3rem 5%;
        }
        
        .section-header h2 {
            font-size: 2rem;
        }
        
        .service-category {
            margin-bottom: 1.5rem;
        }
        
        .category-header {
            padding: 2rem 1.5rem;
        }
        
        .category-icon {
            width: 70px;
            height: 70px;
            font-size: 1.8rem;
        }
        
        .category-header h3 {
            font-size: 1.8rem;
        }
        
        .services-grid {
            grid-template-columns: 1fr;
            gap: 1.5rem;
            padding: 2rem 1.5rem;
        }
        
        .service-item {
            padding: 1.5rem;
        }
        
        .service-content h4 {
            font-size: 1.2rem;
        }

        .toggle-icon {
            bottom: 0.8rem;
            right: 1.5rem;
            font-size: 1.3rem;
        }
    }

    @media (max-width: 480px) {
        .services-section {
            padding: 2.5rem 3%;
        }
        
        .section-header h2 {
            font-size: 1.8rem;
        }
        
        .category-header {
            padding: 1.5rem 1rem;
        }
        
        .category-header h3 {
            font-size: 1.6rem;
        }
        
        .services-grid {
            padding: 1.5rem 1rem;
        }
        
        .service-item {
            padding: 1.2rem;
        }
        
        .service-icon {
            width: 50px;
            height: 50px;
            font-size: 1.3rem;
        }

        .toggle-icon {
            bottom: 0.5rem;
            right: 1rem;
            font-size: 1.2rem;
        }
    }

    /* Animation for service items */
    .service-item {
        opacity: 0;
        transform: translateY(20px);
        animation: fadeInUp 0.6s ease forwards;
    }

    .service-item:nth-child(1) { animation-delay: 0.1s; }
    .service-item:nth-child(2) { animation-delay: 0.2s; }
    .service-item:nth-child(3) { animation-delay: 0.3s; }
    .service-item:nth-child(4) { animation-delay: 0.4s; }
    .service-item:nth-child(5) { animation-delay: 0.5s; }
    .service-item:nth-child(6) { animation-delay: 0.6s; }

    @keyframes fadeInUp {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Announcements Section - Consistent styling */
    .announcements-section {
        padding: 4rem 5%;
        background: #f8f9fa;
        min-height: 70vh;
    }

    .announcements-container {
        max-width: 1000px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
        gap: 2rem;
        padding: 0;
    }

    .announcement-card {
        background: white;
        border-radius: 15px;
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        border: 1px solid rgba(0,0,0,0.05);
        min-height: auto;
        display: flex;
        flex-direction: column;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }

    .announcement-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.08);
    }

    .announcement-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #3498db 0%, #2ecc71 100%);
    }

    .announcement-card h3 {
        font-size: 1.3rem;
        color: #2c3e50;
        margin: 1.5rem 1.5rem 1rem;
        line-height: 1.3;
        flex-shrink: 0;
    }

    .event-details {
        padding: 0 1.5rem 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .event-meta {
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
        margin-bottom: 1rem;
        flex-shrink: 0;
    }

    .meta-item {
        display: flex;
        align-items: center;
        color: #7f8c8d;
        font-size: 0.95rem;
    }

    .meta-item i {
        width: 24px;
        text-align: center;
        margin-right: 10px;
        color: #3498db;
        font-size: 1rem;
    }

    .event-date {
        font-weight: 500;
        color: #2c3e50;
        font-size: 0.95rem;
    }

    .event-location {
        font-size: 0.9rem;
    }

    .event-description {
        color: #34495e;
        line-height: 1.6;
        margin-bottom: 1.5rem;
        font-size: 0.95rem;
        flex: 1;
    }

    .event-organizer {
        font-size: 0.85rem;
        color: #7f8c8d;
        border-top: 1px solid #eee;
        padding-top: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        margin-top: auto;
    }

    .no-announcements {
        text-align: center;
        color: #7f8c8d;
        font-size: 1.1rem;
        grid-column: 1 / -1;
        padding: 3rem 0;
    }

    /* Postponed Event Styles */
    .postponed {
        position: relative;
        opacity: 0.8;
        background: #fff9e6;
    }

    .postponed-banner {
        background: #ffeb3b;
        color: #856404;
        padding: 8px 15px;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
    }

    .postponed::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: repeating-linear-gradient(
            45deg,
            transparent,
            transparent 10px,
            rgba(0,0,0,0.05) 10px,
            rgba(0,0,0,0.05) 20px
        );
        z-index: 1;
    }

    .postponed .announcement-card {
        position: relative;
        z-index: 2;
    }

    /* Responsive Design for Announcements */
    @media (max-width: 1000px) {
        .announcements-container {
            grid-template-columns: 1fr;
            max-width: 600px;
            gap: 1.5rem;
        }
    }

    @media (max-width: 600px) {
        .announcements-container {
            max-width: 100%;
            padding: 0 1rem;
            gap: 1rem;
        }
        
        .announcement-card h3 {
            font-size: 1.2rem;
            margin: 1.2rem 1.2rem 1rem;
        }
        
        .event-details {
            padding: 0 1.2rem 1.2rem;
        }

        .meta-item {
            font-size: 0.9rem;
        }

        .event-description {
            font-size: 0.9rem;
        }

        .event-organizer {
            font-size: 0.8rem;
        }
    }
    </style>

<script>
// Minimalist Carousel Controller
document.addEventListener('DOMContentLoaded', function() {
    const track = document.getElementById('carouselTrack');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const dotsContainer = document.getElementById('carouselDots');
    
    if (!track) return;
    
    const slides = track.querySelectorAll('.carousel-slide');
    const totalSlides = slides.length;
    let currentIndex = 0;
    
    // Determine slides per view based on screen size
    function getSlidesPerView() {
        const width = window.innerWidth;
        if (width <= 480) return 1;
        if (width <= 768) return 1;
        if (width <= 1024) return 2;
        if (width <= 1200) return 3;
        return 3;
    }
    
    let slidesPerView = getSlidesPerView();
    
    // Create dots
    function createDots() {
        dotsContainer.innerHTML = '';
        const totalDots = Math.ceil(totalSlides / slidesPerView);
        
        for (let i = 0; i < totalDots; i++) {
            const dot = document.createElement('div');
            dot.classList.add('dot');
            if (i === 0) dot.classList.add('active');
            dot.addEventListener('click', () => goToSlide(i * slidesPerView));
            dotsContainer.appendChild(dot);
        }
    }
    
    // Update carousel
    function updateCarousel() {
        const slideWidth = slides[0].offsetWidth;
        track.scrollTo({
            left: currentIndex * slideWidth,
            behavior: 'smooth'
        });
        
        // Update dots
        const dots = dotsContainer.querySelectorAll('.dot');
        dots.forEach((dot, index) => {
            dot.classList.toggle('active', index === Math.floor(currentIndex / slidesPerView));
        });
        
        // Update buttons
        prevBtn.disabled = currentIndex === 0;
        nextBtn.disabled = currentIndex >= totalSlides - slidesPerView;
    }
    
    // Go to slide
    function goToSlide(index) {
        currentIndex = Math.max(0, Math.min(index, totalSlides - slidesPerView));
        updateCarousel();
    }
    
    // Navigation
    prevBtn.addEventListener('click', () => {
        if (currentIndex > 0) {
            currentIndex -= slidesPerView;
            if (currentIndex < 0) currentIndex = 0;
            updateCarousel();
        }
    });
    
    nextBtn.addEventListener('click', () => {
        if (currentIndex < totalSlides - slidesPerView) {
            currentIndex += slidesPerView;
            updateCarousel();
        }
    });
    
    // Handle resize
    window.addEventListener('resize', () => {
        slidesPerView = getSlidesPerView();
        currentIndex = 0;
        createDots();
        updateCarousel();
    });
    
    // Initialize
    createDots();
    updateCarousel();
    
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
</script>

<!-- Announcements Section -->
<section class="announcements-section" id="announcements">
  <div class="section-header">
    <h2>Announcements</h2>
    <p>Stay updated with the latest news and events</p>
  </div>
  <div class="announcements-container">
    <?php if (count($events_result) > 0): ?>
      <?php foreach ($events_result as $event): ?>
        <div class="announcement-card <?= $event['status'] === 'postponed' ? 'postponed' : '' ?>">
          <?php if ($event['status'] === 'postponed'): ?>
            <div class="postponed-banner">
              <i class="fas fa-exclamation-triangle"></i>
              Event Postponed - New Date TBA
            </div>
          <?php endif; ?>
          <h3><?php echo htmlspecialchars($event['title']); ?></h3>
          <div class="event-details">
            <div class="event-meta">
              <div class="meta-item">
                <i class="fas fa-calendar-alt"></i>
                <div class="event-date">
                  <?php 
                    $start = new DateTime($event['start_datetime']);
                    $end = new DateTime($event['end_datetime']);
                    echo $start->format('M j, Y g:i A') . ' - ' . $end->format('g:i A');
                  ?>
                </div>
              </div>
              <div class="meta-item">
                <i class="fas fa-map-marker-alt"></i>
                <div class="event-location">
                  <?php echo htmlspecialchars($event['location']); ?>
                </div>
              </div>
            </div>
            <p class="event-description">
              <?php echo htmlspecialchars($event['description']); ?>
            </p>
            <div class="event-organizer">
              <i class="fas fa-user-tie"></i>
              Organized by: <?php echo htmlspecialchars($event['organizer']); ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="no-announcements">No upcoming events at the moment. Check back later!</p>
    <?php endif; ?>
  </div>
</section>

    <!-- Contact Section -->
    <section class="contact-section" id="contact" data-aos="fade-up">
      <div class="section-header">
        <h2>Contact Us</h2>
        <p>We'd love to hear from you</p>
      </div>
      <div class="contact-content">
        <div class="contact-form">
          <h3>Send Us a Message</h3>
          <form>
            <div class="form-group">
              <label for="name">Your Name</label>
              <input id="name" type="text" placeholder="Your Name" required />
            </div>
            <div class="form-group">
              <label for="email">Your Email</label>
              <input id="email" type="email" placeholder="Your Email" required />
            </div>
            <div class="form-group">
              <label for="message">Your Message</label>
              <textarea id="message" rows="5" placeholder="Your Message" required></textarea>
            </div>
            <button type="submit" class="btn cta-button">Submit</button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="footer">
    <p>&copy; 2025 iBarangay. All rights reserved.</p>
  </footer>

  <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
  <script>
    // Initialize AOS animations
    AOS.init({
      duration: 1000,
      once: true,
      easing: 'ease-out-quad'
    });

    // Mobile Menu Toggle
    const menuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    menuBtn.addEventListener('click', () => {
      navLinks.classList.toggle('active');
      menuBtn.classList.toggle('active');
    });

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        const headerHeight = document.querySelector('.navbar').offsetHeight;
        const targetPosition = target.offsetTop - headerHeight;
        window.scrollTo({
          top: targetPosition,
          behavior: 'smooth'
        });
      });
    });

    // Lazy loading for images
    const lazyImages = document.querySelectorAll('img[data-src]');
    const lazyLoad = target => {
      const io = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            img.removeAttribute('data-src');
            observer.disconnect();
          }
        });
      });
      io.observe(target);
    };
    lazyImages.forEach(lazyLoad);
  </script>

<!-- Custom Service Details Modal -->
<div id="customServiceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg p-8 max-w-2xl w-full max-h-[90vh] overflow-y-auto mx-4">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold" id="modalCustomServiceTitle"></h2>
            <button onclick="closeCustomServiceModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="space-y-6">
            <div>
                <h3 class="text-lg font-semibold mb-2">Description</h3>
                <p id="modalCustomServiceDescription" class="text-gray-600"></p>
            </div>
            <div>
                <h3 class="text-lg font-semibold mb-2">Requirements</h3>
                <ul id="modalCustomServiceRequirements" class="list-disc pl-5 text-gray-600 space-y-2"></ul>
            </div>
            <div>
                <h3 class="text-lg font-semibold mb-2">Process Guide</h3>
                <ol id="modalCustomServiceGuide" class="list-decimal pl-5 text-gray-600 space-y-2"></ol>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="text-lg font-semibold mb-2">Processing Time</h3>
                    <p id="modalCustomServiceTime" class="text-gray-600"></p>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-2">Fees</h3>
                    <p id="modalCustomServiceFees" class="text-gray-600"></p>
                </div>
            </div>
            <div class="mt-6 text-center">
                <button onclick="closeCustomServiceModal()" class="bg-blue-500 hover:bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg transition duration-200">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function viewCustomService(service) {
    // Set modal content
    document.getElementById('modalCustomServiceTitle').textContent = service.name;
    document.getElementById('modalCustomServiceDescription').textContent = service.description;
    
    // Format requirements as list
    const requirementsList = document.getElementById('modalCustomServiceRequirements');
    requirementsList.innerHTML = '';
    if (service.requirements) {
        service.requirements.split('\n').forEach(req => {
            if (req.trim()) {
                const li = document.createElement('li');
                li.textContent = req.trim();
                requirementsList.appendChild(li);
            }
        });
    }
    
    // Format guide as numbered list
    const guideList = document.getElementById('modalCustomServiceGuide');
    guideList.innerHTML = '';
    if (service.detailed_guide) {
        service.detailed_guide.split('\n').forEach(step => {
            if (step.trim()) {
                const li = document.createElement('li');
                li.textContent = step.trim();
                guideList.appendChild(li);
            }
        });
    }
    
    document.getElementById('modalCustomServiceTime').textContent = service.processing_time || 'Not specified';
    document.getElementById('modalCustomServiceFees').textContent = service.fees || 'Not specified';
    
    // Show modal
    document.getElementById('customServiceModal').style.display = 'flex';
}

function closeCustomServiceModal() {
    document.getElementById('customServiceModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('customServiceModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCustomServiceModal();
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