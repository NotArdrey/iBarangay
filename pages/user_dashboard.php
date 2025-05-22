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
            WHERE dr.user_id = ? 
            AND dt.document_name = 'First Time Job Seeker'
        ");
        //$firstTimeJobSeekerCheck->execute([$user_id]);
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
    color: #333333;
}

.user-barangay {
    font-size: 0.75rem;
    color: #666666;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .user-info {
        margin: 1rem 0;
        padding: 0.8rem;
        justify-content: center;
        width: 100%;
    }
    
    .user-details {
        text-align: left;
    }
}

/* Alternative compact style for smaller screens */
@media (max-width: 1200px) and (min-width: 769px) {
    .user-info {
        padding: 0.4rem 0.8rem;
    }
    
    .user-name {
        font-size: 0.85rem;
    }
    
    .user-barangay {
        font-size: 0.7rem;
    }
    
    .user-avatar {
        font-size: 1.3rem;
    }
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
/* Minimalist About Section */
.about-section {
    padding: 6rem 5%;
    background: #fff;
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
    padding: 0 rem;
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
</script>


</script>

    <!-- Services Section -->
    <section class="services-section" id="services">
    <div class="services-container">

    <div class="section-header">
        <h2>Services</h2>
    </div>
  <div class="services-list">
    <div class="service-item"
         onclick="window.location.href='../pages/services.php?documentType=barangayClearance';"
         style="cursor:pointer;">
      <div class="service-icon"><i class="fas fa-file-alt"></i></div>
      <div class="service-content">
        <h3>Barangay Clearance</h3>
        <p>Obtain official barangay clearance for various transactions and requirements.</p>
        <a href="../pages/services.php?documentType=barangayClearance" class="service-cta">
          Get Started <i class="fas fa-arrow-right arrow-icon"></i>
        </a>
      </div>
    </div>

    <?php if (!$hasRequestedFirstTimeJobSeeker): ?>
    <div class="service-item"
         onclick="window.location.href='../pages/services.php?documentType=firstTimeJobSeeker';"
         style="cursor:pointer;">
      <div class="service-icon"><i class="fas fa-briefcase"></i></div>
      <div class="service-content">
        <h3>First Time Job Seeker</h3>
        <p>Assistance and certification for first-time job seekers in the community.</p>
        <a href="../pages/services.php?documentType=firstTimeJobSeeker" class="service-cta">
          Apply Now <i class="fas fa-arrow-right arrow-icon"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <div class="service-item"
         onclick="window.location.href='../pages/services.php?documentType=proofOfResidency';"
         style="cursor:pointer;">
      <div class="service-icon"><i class="fas fa-home"></i></div>
      <div class="service-content">
        <h3>Proof of Residency</h3>
        <p>Get official certification of your residency status for legal and administrative purposes.</p>
        <a href="../pages/services.php?documentType=proofOfResidency" class="service-cta">
          Request Certificate <i class="fas fa-arrow-right arrow-icon"></i>
        </a>
      </div>
    </div>

    <div class="service-item"
         onclick="window.location.href='../pages/services.php?documentType=barangayIndigency';"
         style="cursor:pointer;">
      <div class="service-icon"><i class="fas fa-hand-holding-heart"></i></div>
      <div class="service-content">
        <h3>Barangay Indigency</h3>
        <p>Obtain certification for social welfare and financial assistance programs.</p>
        <a href="../pages/services.php?documentType=barangayIndigency" class="service-cta">
          Apply Here <i class="fas fa-arrow-right arrow-icon"></i>
        </a>
      </div>
    </div>

    <div class="service-item"
         onclick="window.location.href='../pages/services.php?documentType=goodMoralCertificate';"
         style="cursor:pointer;">
      <div class="service-icon"><i class="fas fa-user-check"></i></div>
      <div class="service-content">
        <h3>Good Moral Certificate</h3>
        <p>Request certification of good moral character for employment and education purposes.</p>
        <a href="../pages/services.php?documentType=goodMoralCertificate" class="service-cta">
          Get Certified <i class="fas fa-arrow-right arrow-icon"></i>
        </a>
      </div>
    </div>

    <div class="service-item"
         onclick="window.location.href='../pages/services.php?documentType=noIncomeCertification';"
         style="cursor:pointer;">
      <div class="service-icon"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="service-content">
        <h3>No Income Certification</h3>
        <p>Official certification for individuals without regular income source.</p>
        <a href="../pages/services.php?documentType=noIncomeCertification" class="service-cta">
          Request Now <i class="fas fa-arrow-right arrow-icon"></i>
        </a>
      </div>
    </div>
  </div>
</div>
    </section>

    <style>
.announcements-section {
    padding: 4rem 1.5rem;
    background: #f8f9fa;
}

.section-header {
    text-align: center;
    margin-bottom: 3rem;
}

.section-header h2 {
    font-size: 2.5rem;
    color: #2c3e50;
    margin-bottom: 1rem;
    position: relative;
}

.section-header h2::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 50%;
    transform: translateX(-50%);
    width: 60px;
    height: 3px;
    background: #3498db;
    border-radius: 2px;
}

.announcements-container {
    max-width: 1200px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2rem;
    padding: 0 1rem;
}

.announcement-card {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    position: relative;
    border: 1px solid rgba(0,0,0,0.05);
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
}

.event-details {
    padding: 0 1.5rem 1.5rem;
}

.event-meta {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    margin-bottom: 1rem;
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
}

.event-date {
    font-weight: 500;
    color: #2c3e50;
}

.event-location {
    font-size: 0.9rem;
}

.event-description {
    color: #34495e;
    line-height: 1.6;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}

.event-organizer {
    font-size: 0.85rem;
    color: #7f8c8d;
    border-top: 1px solid #eee;
    padding-top: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.no-announcements {
    text-align: center;
    color: #7f8c8d;
    font-size: 1.1rem;
    grid-column: 1 / -1;
    padding: 3rem 0;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .announcements-container {
        grid-template-columns: 1fr;
        max-width: 600px;
    }
    
    .announcement-card {
        margin-bottom: 1rem;
    }
}

@media (max-width: 768px) {
    .section-header h2 {
        font-size: 2rem;
    }
    
    .announcement-card h3 {
        font-size: 1.2rem;
    }
}
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
}
</style>

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
        <div class="announcement-card">
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
</body>
</html>

<?php
  if(isset($_SESSION['alert'])) {
    echo $_SESSION['alert'];
    unset($_SESSION['alert']);
  }
?>