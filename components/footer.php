<?php
// Calculate base path for assets based on where this component is included from
$script_path = $_SERVER['SCRIPT_NAME'];
$script_dir = dirname($script_path);
$path_segments = array_filter(explode('/', $script_dir));
$is_subdirectory = (count($path_segments) > 1);
$asset_base = $is_subdirectory ? '../' : '';

require_once __DIR__ . '/../config/config.php'; // Include config for database connection

// Fetch social links
$social_links_stmt = $pdo->query("SELECT * FROM social_links WHERE status = 'active' ORDER BY sort_order ASC");
$social_links = $social_links_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
  <footer>
    <!-- content container (white layer INSIDE here) -->
    <div class="footer-container">
      <!-- white translucent layer that covers ONLY the container area -->
      <div class="footer-white-layer" aria-hidden="true"></div>

      <!-- CONTENT GRID (about + newsletter) -->
      <div class="footer-grid">
        <div class="footer-about">
          <h3>Liyas Mineral Water</h3>
          <p>
            Premium natural spring water sourced from protected mountain springs.
            Committed to quality, sustainability, and your health since 2003.
          </p>
          <div class="social-links">
            <?php foreach ($social_links as $link): ?>
                <a href="<?= htmlspecialchars($link['url']) ?>" target="_blank" aria-label="<?= htmlspecialchars($link['platform']) ?>">
                    <i class="<?= htmlspecialchars($link['icon_class']) ?>"></i>
                </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="newsletter">
          <h2>Subscribe to Our Newsletter</h2>
          <p>Stay hydrated and informed — get the latest offers and updates!</p>
          <form class="newsletter-form">
            <input type="email" placeholder="Enter your email" required aria-label="Email" />
            <button type="submit">Subscribe</button>
          </form>
        </div>
      </div>

   
      
    <div class="footer-grid">
          <div class="footer-bottom">
        <p>&copy; 2025 Liyas Mineral Water. All rights reserved.</p>
      </div>
      <div class="footer-bottom terms">
      <a href="terms.php" >Terms & Privacy Policy</a>
      </div>
    </div>
    </div>
  </footer>

  <!-- FOOTER OVERLAY WRAPPER — now has the background image -->
  <div class="footer-overlay-wrapper" aria-hidden="true">
    <svg
      class="footer-overlay footer-overlay--inline"
      viewBox="0 0 1600 400"
      preserveAspectRatio="none"
      aria-hidden="true"
    >
      <defs>
        <mask id="overlayCutoutInline" maskUnits="userSpaceOnUse">
          <rect x="0" y="0" width="1600" height="400" fill="white" />
          <g class="scroll-track" fill="black">
            <text x="0" y="300" class="hollow-text-svg">
              LIYAS LIYAS LIYAS LIYAS LIYAS LIYAS LIYAS LIYAS
            </text>
          </g>
        </mask>
      </defs>

      <rect
        x="0"
        y="0"
        width="1600"
        height="400"
        fill="#ffffff"
        mask="url(#overlayCutoutInline)"
      />
    </svg>
  </div>

  <style>
  .terms a{
          text-decoration: none;
          color: #555;
          font-size: 13px;
          transition: transform 0.3s ease;;
  }
  
  .terms a:hover{
      color: #559;

  }

  /* ===== FOOTER STRUCTURE ===== */
  footer {
    position: relative;
    overflow: visible;
    padding: 60px 0 0;
    font-family: 'Poppins', sans-serif;
    background: none; /* ✅ remove background from footer */
  }

  /* container: holds the white layer */
  .footer-container {
    position: relative;
    z-index: 2;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0px 20px 40px;
    box-sizing: border-box;
  }

  /* white translucent layer covers only the footer-container */
  .footer-white-layer {
    position: absolute;
    inset: 0;
    z-index: 1;
    pointer-events: none;
  }

  /* content sits above white layer */
  .footer-grid,
  .footer-bottom {
    position: relative;
    z-index: 3;
   
  }

  /* ===== FOOTER CONTENT ===== */
  .footer-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 3rem;
    align-items: start;
    text-align: left;
  }

  .footer-about h3 {
    color: #4ad2e2;
    font-weight: 700;
    margin: 0 0 12px;
  }

  .footer-about p {
    margin: 0 0 16px;
    color: #444;
    line-height: 1.6;
  }

  /* social links */
  .social-links {
    margin-top: 12px;
  }

  .social-links a {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 40px;
    height: 40px;
    margin: 0 6px 12px 0;
    border-radius: 50%;
    background: #fff;
    color: #4ad2e2;
    font-size: 18px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    transition: 0.18s;
    text-decoration: none;
  }

  .social-links a:hover {
    background: #4ad2e2;
    color: #fff;
  }

  /* newsletter */
  /* .newsletter {
    padding: 20px 24px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 14px;
    backdrop-filter: blur(6px);
    box-shadow: 0 6px 30px rgba(0, 0, 0, 0.06);
  } */

  .newsletter h2 {
    font-size: 22px;
    color: #4ad2e2;
    margin: 0 0 8px;
  }

  .newsletter p {
    color: #555;
    margin: 0 0 16px;
  }

  .newsletter-form {
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .newsletter-form input {
    flex: 1;
    padding: 12px 16px;
    border: 2px solid #4ad2e2;
    border-radius: 28px;
    outline: none;
    font-size: 15px;
  }

  .newsletter-form button {
    padding: 10px 20px;
    border-radius: 28px;
    border: none;
    background: #4ad2e2;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
  }

  /* footer bottom */
  .footer-bottom {
    margin-top: 30px;
    font-size: 14px;
    color: #555;
    text-decoration: none;
    text-align: center;
  }

  /* ===== FOOTER OVERLAY WRAPPER ===== */
.footer-overlay-wrapper {
  position: relative;
  width: 100%;
  height: 20em;
  z-index: 1;
  overflow: hidden;

  /* Remove inline background image handling */
  background: none;
}

/* ✅ Add a pseudo-element that holds the fixed background image */
.footer-overlay-wrapper {
  position: relative;
  width: 100%;
  height: 22em;
  overflow: hidden;
  z-index: 1;

  /* ✅ Full-width footer background, not global */
  background: url('<?php echo $asset_base; ?>../assets/images/bg1.jpg')
              bottom center no-repeat;
  background-size: cover;       /* fills width responsively */
  background-attachment: scroll; /* stays in footer, not global fixed */
  background-repeat: no-repeat;
}


  /* SVG fills wrapper */
  .footer-overlay--inline {
    width: 100%;
    height: 100%;
    display: block;
  }

  /* hollow text style */
  .hollow-text-svg {
    font-weight: 900;
    font-size: 25em !important;
    letter-spacing: 0.2em;
  }

  /* scrolling animation */
  .scroll-track {
    animation: scrollText 12s linear infinite;
    transform-box: fill-box;
  }

  @keyframes scrollText {
    0% {
      transform: translateX(0);
    }
    100% {
      transform: translateX(-600px);
    }
  }

  /* ===== RESPONSIVE ===== */
  @media (max-width: 991px) {
    .footer-grid {
      grid-template-columns: 1fr;
      text-align: center;
    }

    .newsletter-form {
      flex-direction: column;
    }

    .hollow-text-svg {
      font-size: 90px;
    }

    .footer-overlay-wrapper {
      height: 150px;
      margin-top: 16px;
    }
  }
  </style>
