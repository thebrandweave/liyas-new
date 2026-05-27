<!-- components/navbar.php -->
<?php
// Get the script path and determine base path
$base_path = '/'; 

// Set navigation links to actual pages
$home_link = rtrim($base_path, '/') . '/index-temp';
$about_link = rtrim($base_path, '/') . '/about/';
$products_link = rtrim($base_path, '/') . '/products/';
$contact_link = rtrim($base_path, '/') . '/contact/';
$cart_link = rtrim($base_path, '/') . '/cart/';

?>
<nav class="navbar fixed-top liyas-navbar">
  <div class="container d-flex justify-content-between align-items-center">
      

     <!--Logo (Left Side) -->
 <div>
     <a href="<?php echo $home_link; ?>" class="navbar-brand d-flex align-items-center">
  <img src="https://liyasinternational.com/assets/images/logo/logo.png" alt="Liyas Logo" class="navbar-logo">
</a>
 </div>


    <!-- Center Nav Items -->
    <ul class="navbar-nav flex-row gap-4 nav-items-placeholder d-none d-md-flex mx-auto">
      <li class="nav-item"><a href="<?php echo $home_link; ?>" class="nav-link">Home</a></li>
      <li class="nav-item"><a href="<?php echo $about_link; ?>" class="nav-link">About</a></li>
      <li class="nav-item"><a href="<?php echo $products_link; ?>" class="nav-link">Products</a></li>
      <li class="nav-item"><a href="<?php echo $contact_link; ?>" class="nav-link">Contact</a></li>
    </ul>

    <!-- Cart Icon -->
    <a href="<?php echo $cart_link; ?>" id="cart-icon" class="cart-icon-1" title="View Cart">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c.51 0 .962-.343 1.087-.835l1.823-6.423a.75.75 0 00-.67-1.03H6.088l-.523-1.974M16.5 21a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM8.25 21a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
        </svg>
    </a>
    
       <div><?php if (isset($_SESSION['user_id'])): ?>
  <a href="logout.php" class="top-login-btn" title="Logout">
    <img src="logout.png" alt="Logout" class="login-svg" style="width: 24px; height: 22px;padding:2px;"> <span style="font-size:15px;"> Logout</span>
</a>
<?php else: ?>
    <a href="login.php" class="top-login-btn" title="Login">
         <img src="user.png" alt="Logout" class="login-svg" style="width: 24px; height: 22px;padding:0px;"> <span style="font-size:15px;"> </span>
    </a>
<?php endif; ?></div>

    <!-- Hamburger Menu (Mobile) -->
    <button class="navbar-toggler d-md-none" type="button" id="mobileMenuBtn" aria-label="Toggle navigation">
      <span class="hamburger-icon"></span>
    </button>

  </div>

<!-- Mobile Menu Overlay -->
<div class="mobile-menu" id="mobileMenu">
  <ul>
    <li><a href="<?php echo $home_link; ?>" class="nav-link">Home</a></li>
    <li><a href="<?php echo $about_link; ?>" class="nav-link">About</a></li>
    <li><a href="<?php echo $products_link; ?>" class="nav-link">Products</a></li>
    <li><a href="<?php echo $contact_link; ?>" class="nav-link">Contact</a></li>

    <!-- Cart Icon -->
    <li>
      <a href="#" id="cart-icon" class="cart-icon nav-link" title="View Cart">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 24px; height: 24px;">
          <path stroke-linecap="round" stroke-linejoin="round" 
            d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c.51 0 .962-.343 1.087-.835l1.823-6.423a.75.75 0 00-.67-1.03H6.088l-.523-1.974M16.5 21a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM8.25 21a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
        </svg>
        <span style="font-size:15px;">Cart</span>
      </a>
    </li>

    <!-- Login / Logout -->
    <li class="login-button">
      <?php if (isset($_SESSION['user_id'])): ?>
        <a href="logout.php" class="top-login-btn-1 nav-link" title="Logout">
          <img src="logout.png" alt="Logout" style="width: 24px; height: 22px; padding:2px;">
          <span style="font-size:15px;">Logout</span>
        </a>
      <?php else: ?>
        <a href="login.php" class="top-login-btn-1 nav-link" title="Login">
          <img src="user.png" alt="Login" style="width: 24px; height: 22px;">
          <span style="font-size:15px;">Login</span>
        </a>
      <?php endif; ?>
    </li>

  </ul>
</div>

  <!-- Cart Sidebar -->
   
</nav>

<style>
  /* ======================
      NAVBAR BASE STYLING
  ====================== */
  body {
  padding-top: 90px; /* adjust based on navbar height */
}


.cart-icon-1 {
    color: #0f172a;
    text-decoration: none;
    transition: color 0.3s ease;
    position: relative;
    padding: 0.5rem;
}

.cart-icon-1:hover {
    color: #4ad2e2;
}

.cart-icon-1 svg {
    width: 24px;
    height: 24px;
}


.navbar-logo {
  height: 55px;
  width: auto;
  object-fit: contain;
  position: static; /* ✅ FIX */
}

/* Ensure proper spacing */
.liyas-navbar .container {
  position: relative;
   display: flex;
  align-items: center;
  min-height: 70px;
}
  .liyas-navbar {
    width: 100%;
    background: white;
    padding: 0.7rem 2rem;
    z-index: 1100;
    transition: background 0.3s ease, box-shadow 0.3s ease, padding 0.3s ease;
    top: 0;
    left: 0;
    position:fixed;
  }

  /* Navbar with background on scroll */
  .liyas-navbar.scrolled {
    backdrop-filter:blur(10px);
      /*box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06);*/
    
  }

  /* Center Nav Links */
  .nav-items-placeholder {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .nav-items-placeholder .nav-link {
    font-weight: 500;
    font-size: 1.05rem;
    color: #000000e6;
    text-decoration: none;
    transition: color 0.3s ease;
    letter-spacing: 0.5px;
    position: relative;
    padding: 0.5rem 0;
  }

  .nav-items-placeholder .nav-link:hover {
    color: rgba(74, 210, 226, 1);
  }

  /* Tablet adjustments */
  @media (min-width: 768px) and (max-width: 991px) {
    .liyas-navbar {
      /*padding: 1.8rem 1.5rem;*/
      padding-inline: 1em;
    }
    .nav-items-placeholder .nav-link {
      font-size: 0.95rem;
    }
    .nav-items-placeholder {
      gap: 1.5rem !important;
    }
    .cart-icon .nav-link{
        font-size:1.35em;
    }
    

  }
  
  @media (max-width:768px)
  {
       .navbar-logo{
         height:44px;
    width: auto;
    object-fit: contain;
    position: static;
 }
  }


  /* ======================
      HAMBURGER MENU
  ====================== */
  .navbar-toggler {
    background: none;
    border: none;
    cursor: pointer;
    width: 44px;
    height: 44px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    /* Better touch target */
    min-width: 44px;
    min-height: 44px;
    z-index: 1101;
  }

  .hamburger-icon,
  .hamburger-icon::before,
  .hamburger-icon::after {
    content: "";
    display: block;
    width: 26px;
    height: 2.5px;
    background-color: #0f172a;
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    transition: all 0.3s ease-in-out;
    border-radius: 2px;
  }

  .hamburger-icon {
    top: 50%;
    transform: translate(-50%, -50%);
  }

  .hamburger-icon::before {
    top: -9px;
    transform: translateX(-50%);
  }

  .hamburger-icon::after {
    top: 9px;
    transform: translateX(-50%);
  }

  .navbar-toggler.active .hamburger-icon {
    background-color: transparent;
  }

  .navbar-toggler.active .hamburger-icon::before {
    transform: translate(-50%, 0) rotate(45deg);
    top: 0;
  }

  .navbar-toggler.active .hamburger-icon::after {
    transform: translate(-50%, 0) rotate(-45deg);
    top: 0;
  }

  /* Active state color change */
  .navbar-toggler.active .hamburger-icon::before,
  .navbar-toggler.active .hamburger-icon::after {
    background-color: #0f172a;
  }

  /* ======================
      MOBILE MENU OVERLAY
  ====================== */
  .mobile-menu {
    position: fixed;
    top: 0;
    right: -100%;
    width: 100%;
    height: 100vh;
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    display: flex;
    justify-content: center;
    align-items: center;
    transition: right 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1099;
    overflow-y: auto;
  }

  .mobile-menu.active {
    right: 0;
  }

  .mobile-menu ul {
    list-style: none;
    padding: 0;
    text-align: center;
    width: 100%;
    max-width: 300px;
  }

  .mobile-menu ul li {
    margin: 1.5rem 0;
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.3s ease, transform 0.3s ease;
  }

  .mobile-menu.active ul li {
    opacity: 1;
    transform: translateY(0);
  }

  .mobile-menu.active ul li:nth-child(1) { transition-delay: 0.1s; }
  .mobile-menu.active ul li:nth-child(2) { transition-delay: 0.15s; }
  .mobile-menu.active ul li:nth-child(3) { transition-delay: 0.2s; }
  .mobile-menu.active ul li:nth-child(4) { transition-delay: 0.25s; }

  .mobile-menu ul .nav-link {
    font-size: clamp(1.25rem, 5vw, 1.75rem);
    font-weight: 600;
    color: #0f172a;
    text-decoration: none;
    transition: color 0.3s ease, transform 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    min-height: 44px;
  }

  .mobile-menu ul .nav-link:active {
    transform: scale(0.95);
    background: rgba(74, 210, 226, 0.1);
  }

  .mobile-menu ul .nav-link:hover {
    color: rgba(74, 210, 226, 1);
  }

  /* ======================
      RESPONSIVE
  ====================== */
  @media (max-width: 767px) {
    .liyas-navbar {
      padding: 1rem 1rem;
    }
    
    .liyas-navbar.scrolled {
      padding: 0.8rem 1rem;
    }

    .nav-items-placeholder {
      display: none !important;
    }

    .navbar-toggler {
      width: 40px;
      height: 40px;
      min-width: 40px;
      min-height: 40px;
    }

    .hamburger-icon,
    .hamburger-icon::before,
    .hamburger-icon::after {
      width: 24px;
      height: 2.5px;
    }

    .mobile-menu ul li {
      margin: 1.25rem 0;
    }

    .mobile-menu ul .nav-link {
      padding: 0.625rem 1.25rem;
      font-size: 1.35rem;
      margin: 0;
    }
    .mobile-menu ul .cart-icon{
        font-weight: 600;
    }
  }

  /* Extra small devices */
  @media (max-width: 375px) {
    .liyas-navbar {
      padding: 0.875rem 0.875rem;
    }
    
    .liyas-navbar.scrolled {
      padding: 0.75rem 0.875rem;
    }
  }

  /* Prevent body scroll when menu is open */
  body.no-scroll {
    overflow: hidden;
    position: fixed;
    width: 100%;
  }
</style>

<script>
  // Mobile menu toggle logic
  document.addEventListener('DOMContentLoaded', function () {
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    const navbar = document.querySelector('.liyas-navbar');

    // Toggle mobile menu
    if (mobileBtn && mobileMenu) {
      mobileBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        mobileBtn.classList.toggle('active');
        mobileMenu.classList.toggle('active');
        document.body.classList.toggle('no-scroll');
      });

      // Close when clicking any link
      document.querySelectorAll('.mobile-menu .nav-link').forEach(link => {
        link.addEventListener('click', () => {
          mobileBtn.classList.remove('active');
          mobileMenu.classList.remove('active');
          document.body.classList.remove('no-scroll');
        });
      });

      // Close menu when clicking outside
      document.addEventListener('click', (e) => {
        if (mobileMenu.classList.contains('active') && 
            !mobileMenu.contains(e.target) && 
            !mobileBtn.contains(e.target)) {
          mobileBtn.classList.remove('active');
          mobileMenu.classList.remove('active');
          document.body.classList.remove('no-scroll');
        }
      });

      // Close menu on escape key
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && mobileMenu.classList.contains('active')) {
          mobileBtn.classList.remove('active');
          mobileMenu.classList.remove('active');
          document.body.classList.remove('no-scroll');
        }
      });
    }

    // Navbar scroll effect
    if (navbar) {
      let lastScroll = 0;
      window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
          navbar.classList.add('scrolled');
        } else {
          navbar.classList.remove('scrolled');
        }
        
        lastScroll = currentScroll;
      });
    }

    // Handle smooth scrolling for anchor links (same page)
    document.querySelectorAll('.nav-link[href^="#"]').forEach(link => {
      link.addEventListener('click', function(e) {
        const href = this.getAttribute('href');
        if (href && href.startsWith('#')) {
          const targetId = href.substring(1);
          const targetElement = document.getElementById(targetId);
          
          if (targetElement) {
            e.preventDefault();
            targetElement.scrollIntoView({ 
              behavior: 'smooth', 
              block: 'start' 
            });
            
            // Close mobile menu if open
            if (mobileMenu && mobileMenu.classList.contains('active')) {
              mobileBtn.classList.remove('active');
              mobileMenu.classList.remove('active');
              document.body.classList.remove('no-scroll');
            }
          }
        }
      });
    });

    // Handle anchor scrolling after page load (for cross-page navigation)
    if (window.location.hash) {
      const hash = window.location.hash.substring(1);
      setTimeout(() => {
        const targetElement = document.getElementById(hash);
        if (targetElement) {
          targetElement.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
          });
        }
      }, 100);
    }
  });
</script>
