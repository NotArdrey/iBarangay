<?php
session_start();
require "../config/dbconn.php";
header("Cross-Origin-Opener-Policy: same-origin-allow-popups");
//../pages/user_dashboard.php
$events_result = [];

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Fetch user's barangay_id using PDO
    $sql = "SELECT barangay_id FROM Users WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user && isset($user['barangay_id'])) {
        $barangay_id = $user['barangay_id'];
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
      $stmt = $pdo->prepare($events_sql);
      $stmt->execute([$barangay_id]);   // only bind barangay_id
      $events_result = $stmt->fetchAll();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Barangay Hub - Community Portal</title>
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
        <img src="../photo/logo.png" alt="Barangay Hub Logo" />
        <h2>Barangay Hub</h2>
      </a>
      <button class="mobile-menu-btn" aria-label="Toggle navigation menu">
        <i class="fas fa-bars"></i>
      </button>
      <div class="nav-links">
        <a href="#home">Home</a>
        <a href="#about">About</a>
        <a href="#services">Services</a>
        <a href="#contact">Contact</a>
        <!-- Edit Account Option Added Here -->
        <a href="../pages/edit_account.php">Account</a>
      </div>
    </nav>
  </header>

  <main>
    <!-- Hero Section -->
    <section class="hero" id="home">
      <div class="hero-overlay"></div>
      <div class="hero-content" data-aos="fade-up">
        <h1>Welcome to Barangay Hub</h1>
        <p>Your one-stop platform for all barangay services</p>
        <a href="#services" class="btn cta-button">Explore Services</a>
      </div>
    </section>

    <!-- About Section -->
    <section class="about-section" id="about" data-aos="fade-up">
      <div class="section-header">
        <h2>About Us</h2>
        <p>Learn more about Barangay Hub</p>
      </div>
      <div class="about-content">
        <div class="about-card history">
          <h3>Our History</h3>
          <p>
            Barangay Hub was created to unify all barangay services under one platform.
            Our goal is to foster community engagement and simplify access to essential services.
          </p>
        </div>
        <div class="about-card mission">
          <h3>Our Mission</h3>
          <p>
            Deliver accessible and efficient services for all residents by consolidating barangay services
            in one easy-to-use platform.
          </p>
        </div>
        <div class="about-card vision">
          <h3>Our Vision</h3>
          <p>
            Empower our community through digital innovation, ensuring that every resident can access vital services effortlessly.
          </p>
        </div>
      </div>
    </section>

    <!-- Services Section -->
    <section class="services-section" id="services">
    <div class="services-container">
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
    <p>&copy; 2025 Barangay Hub. All rights reserved.</p>
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
