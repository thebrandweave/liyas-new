<?php
require_once '../config/config.php';

// Prevent browser caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Fetch social links
$social_links_stmt = $pdo->query("SELECT * FROM social_links WHERE status = 'active' ORDER BY sort_order ASC");
$social_links = $social_links_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Products | LIYAS Mineral Water</title>
    <meta name="description" content="Browse our premium collection of LIYAS Mineral Water products. Pure, refreshing, and naturally sourced mineral water for a healthy lifestyle.">
    <meta name="keywords" content="mineral water, premium water, healthy water, LIYAS, pure water, products">
    
  <link rel="icon" type="image/jpeg"  sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="shortcut icon" type="image/jpeg"  sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="apple-touch-icon"  type="image/jpeg"  sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="icon" type="image/jpeg" sizes="38x38" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="icon" type="image/jpeg" sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/product.css">
    <link rel="stylesheet" href="../assets/css/cart.css">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">
    
    
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
    
    <meta name="theme-color" content="#4ad2e2">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="LIYAS Water">
    
    <meta property="og:title" content="Products | LIYAS Mineral Water">
    <meta property="og:description" content="Browse our premium collection of LIYAS Mineral Water products.">
    <meta property="og:image" content="../assets/images/logo/logo.png">
    <meta property="og:url" content="https://liyas-water.com/products">
    <meta property="og:type" content="website">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Products | LIYAS Mineral Water">
    <meta name="twitter:description" content="Browse our premium collection of LIYAS Mineral Water products.">
    <meta name="twitter:image" content="../assets/images/logo/logo.png">
    
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "CollectionPage",
        "name": "LIYAS Mineral Water Products",
        "description": "Premium quality mineral water products",
        "url": "https://liyas-water.com/products"
    }
    </script>
    <script>
        const BASE_URL = '<?php echo BASE_URL; ?>';
    </script>
    <style>
    .modal-backdrop {
        z-index: 1090 !important;
    }
    #productDetailModal {
        z-index: 1100 !important; /* Ensure it is above everything */
    }

    /* Product Modal Customizations */
    #productDetailModal .modal-content {
        border-radius: 24px;
        border: none;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.1);
        font-family: 'Poppins', sans-serif;
    }

    #productDetailModal .modal-header {
        border-bottom: none;
        padding: 1.5rem 2rem 0;
    }

    #productDetailModal .modal-title {
        font-family: 'Oswald', sans-serif;
        font-weight: 700;
        font-size: 2rem;
        color: #0f172a;
    }

    #productDetailModal .modal-body {
        padding: 1rem 2rem 2rem;
    }

    #productDetailModal .modal-body img {
        border-radius: 16px;
        max-height: 400px;
        object-fit: contain;
        width: 100%;
    }

    #productDetailModal .modal-body h3 {
        font-family: 'Oswald', sans-serif;
        font-weight: 700;
        font-size: 2.5rem;
        color: #0f172a;
        line-height: 1.2;
        margin-bottom: 0.5rem;
    }

    #productDetailModal .modal-body .price {
        color: #4ad2e2;
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    #productDetailModal .modal-body p {
        color: #64748b;
        font-size: 0.95rem;
    }

    #productDetailModal .modal-body h5 {
        font-family: 'Oswald', sans-serif;
        font-weight: 600;
        font-size: 1.5rem;
        margin-top: 1.5rem;
        color: #0f172a;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 0.75rem;
        margin-bottom: 1.5rem;
    }

    /* Reviews List */
    #productDetailModal .reviews-list li {
        padding-bottom: 1rem;
        margin-bottom: 1rem;
        border-bottom: 1px solid #f1f5f9;
    }
    #productDetailModal .reviews-list li:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    #productDetailModal .reviews-list .stars {
        color: #facc15;
    }
    #productDetailModal .reviews-list strong {
        color: #1e293b;
    }

    /* Review Form */
    #productDetailModal #reviewForm .form-control,
    #productDetailModal #reviewForm .form-select {
        border-radius: 12px;
        padding: 12px 18px;
        border: 1px solid #e2e8f0;
        background-color: #f8fafc;
    }
    #productDetailModal #reviewForm .form-control:focus,
    #productDetailModal #reviewForm .form-select:focus {
        border-color: #4ad2e2;
        box-shadow: 0 0 0 3px rgba(74, 210, 226, 0.2);
        background-color: #fff;
    }
    #productDetailModal #reviewForm .btn-primary {
      background: #4ad2e2;
      color: #fff;
      border: none;
      padding: 12px 28px;
      border-radius: 30px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    #productDetailModal #reviewForm .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(74, 210, 226, 0.2);
    }
    </style>

</head>
<body>
    <style>
        /* Splash Screen Styles */
        #splash-screen {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100vh;
            z-index: 9999;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background:transparent;
            pointer-events: none;
        }

        .splash-layer {
            position: absolute;
            top: 0%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 0;
            height: 0;
            border-radius: 50%;
            background:rgba(74, 210, 226, 0.22);
            will-change: width, height, transform;
            animation: circleDropExpand 2.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            pointer-events: none;
        }

        /* Logo container in the center */
        /*.logo-text {*/
        /*    position: fixed;*/
        /*    top: 50%;*/
        /*    left: 50%;*/
        /*    font-size: clamp(2rem, 8vw, 4rem);*/
        /*    font-weight: 700;*/
        /*    color: rgba(74, 210, 226, 0.71);*/
        /*    z-index: 10000;*/
        /*    text-shadow: 2px 2px 4px rgba(0,0,0,0.1);*/
        /*    pointer-events: none;*/
        /*    font-family: 'Poppins', sans-serif;*/
        /*}*/

        /*.logo-text.move-top-left {*/
        /*    top: 20px !important;*/
        /*    left: 20px !important;*/
        /*    right: auto !important;*/
        /*    transform: none !important;*/
        /*    font-size: clamp(1rem, 4vw, 1.5rem);*/
        /*}*/

        /*.logo-text img {*/
        /*    width: clamp(60px, 15vw, 100px);*/
        /*    height: clamp(60px, 15vw, 100px);*/
        /*}*/

        @media (max-width: 767px) {
            .logo-text.move-top-left {
                top: 14px !important;
                left: 14px !important;
            }
            
            .logo-text img {
                width: clamp(50px, 12vw, 80px);
                height: clamp(50px, 12vw, 80px);
            }
        }

        @media (max-width: 375px) {
            .logo-text.move-top-left {
                top: 12px !important;
                left: 12px !important;
            }
        }

        .logo-text span {
            display: inline-block;
            position: relative;
            top: 0;
            transition: top 0.2s ease;
        }

        .math-particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
            pointer-events: none;
            perspective: 1000px;
        }

        @keyframes circleDropExpand {
            0% { width: 0; height: 0; border-radius: 50%; transform: translate(-50%, -80%); opacity: .98; }
            60% { width: 60vmax; height: 60vmax; border-radius: 50%; transform: translate(-50%, -60%); }
            100% { width: 160vmax; height: 160vmax; border-radius: 50%; transform: translate(-50%, -50%); }
        }

        .splash-fadeout {
            animation: splashFadeOut 0.8s cubic-bezier(0.215, 0.610, 0.355, 1.000) forwards;
            pointer-events: none;
        }
        
        @keyframes splashFadeOut {
            from { opacity: 1; transform: scale(1); }
            to { opacity: 0; visibility: hidden; transform: scale(1.05); }
        }

        /* Page Header Design */
        .page-header {
            background: linear-gradient(135deg, rgba(74, 210, 226, 0.05) 0%, rgba(240, 249, 255, 0.3) 50%, rgba(255, 255, 255, 0.9) 100%);
            padding: 140px 0 80px;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.8) 0%, rgba(240, 249, 255, 0.3) 50%, rgba(255, 255, 255, 0.95) 100%);
            z-index: 1;
        }

        .page-header .container {
            position: relative;
            z-index: 2;
        }

        .page-header-content {
            max-width: 800px;
            margin: 0 auto;
            text-align: center;
        }

        .page-breadcrumb {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .page-breadcrumb a {
            color: #4ad2e2;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .page-breadcrumb a:hover {
            color: #2cbac9;
        }

        .page-breadcrumb span {
            color: #94a3b8;
        }

        .page-title {
            font-size: clamp(2.5rem, 6vw, 4.5rem);
            color: #0f172a;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            font-weight: 800;
        }

        .page-title .text-primary {
            color: #4ad2e2 !important;
        }



        @media (max-width: 767px) {
            .page-header {
                padding: 120px 0 60px;
            }

            .page-breadcrumb {
                font-size: 0.8rem;
            }
        }

        /* Social Sidebar */
        .social-sidebar {
            position: fixed;
            top: 50%;
            left: 16px;
            transform: translateY(-50%);
            display: flex;
            flex-direction: column;
            gap: 18px;
            z-index: 1000;
            pointer-events: none;
            width: 52px;
        }

        .social-sidebar .social-icon {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: #ffffff;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(2, 8, 23, 0.12);
            border: 1px solid rgba(2, 8, 23, 0.06);
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease, color .2s ease;
            backdrop-filter: blur(6px);
        }

        .social-sidebar .social-icon i { font-size: 20px; }

        .social-sidebar .social-icon:hover {
            background: var(--primary);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(74, 210, 226, 0.25);
            border-color: rgba(74, 210, 226, 0.35);
        }

    

    .top-login-btn {
  position: fixed;
  top: 24px;
  right: 20px;
  width: 98px;
  height: 45px;
  border-radius: 7%;
  background: rgba(255, 255, 255, 0.95);
  color: #000000;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
  border: 1px solid rgba(0, 0, 0, 0.08);
  text-decoration: none;
  z-index: 1100;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  cursor: pointer;
  /* Better touch target */
  min-width: 52px;
  min-height: 45px;
  /* Ensure it's always visible */
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}
        .top-login-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 6px 20px rgba(74, 210, 226, 0.25);
            background: rgba(255, 255, 255, 1);
            border-color: rgba(74, 210, 226, 0.2);
        }

        .top-login-btn .login-svg {
            width: 24px;
            height: 24px;
            stroke-width: 2;
            transition: stroke 0.3s ease;
        }

        .top-login-btn:hover .login-svg {
            stroke: rgba(74, 210, 226, 1);
        }

        @media (max-width: 767px) {
            .top-login-btn {
                width: 48px !important;
                height: 48px !important;
                top: 16px !important;
                right: 16px !important;
            }
            
            .top-login-btn .login-svg {
                width: 22px !important;
                height: 22px !important;
            }
        }
        
            @media (max-width: 767px) {
            .social-sidebar { display: none; }
               .top-login-btn{
        display: none;
    }
        }
    </style>

    <?php include '../components/navbar.php' ?>

    <div id="splash-screen" aria-hidden="true">
        <div class="splash-layer" aria-hidden="true"></div>
        <!--<div class="logo-text"> -->
        <!--    <div class="row">-->
        <!--        <img style="width: 180px; height: 180px; margin-top:-60px;" src="../assets/images/logo/logo.png" alt="Liyas">-->
        <!--    </div>-->
        <!--</div>-->
        <!--<div class="math-particles" aria-hidden="true" id="mathParticles"></div>-->
    </div>




    <div class="social-sidebar">
        <?php foreach ($social_links as $link): ?>
            <a href="<?= htmlspecialchars($link['url']) ?>" class="social-icon" target="_blank" aria-label="<?= htmlspecialchars($link['platform']) ?>">
                <i class="<?= htmlspecialchars($link['icon_class']) ?>"></i>
            </a>
        <?php endforeach; ?>
    </div>
    
    <button id="backToTop" title="Go to top"><i class="fas fa-arrow-up"></i></button>

    <section class="page-header" id="home">
        <div class="container">
            <div class="page-header-content">
                <div class="page-breadcrumb">
                    <a href="../">Home</a> <span>/</span> <span>Products</span>
                </div>
                <h1 class="page-title">Our <span class="text-primary">Products</span></h1>

            </div>
        </div>
    </section>

    <?php include '../components/products.php'; ?>

    <?php include '../components/whychooseus.php'; ?>

    <?php include '../components/cta.php'; ?>

    <?php include '../components/faq.php'; ?>

    <?php include '../components/footer.php'; ?>

    <!-- Product Detail Modal -->
    <div class="modal fade" id="productDetailModal" tabindex="-1" aria-labelledby="productDetailModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header border-0">
            <h5 class="modal-title" id="productDetailModalLabel">Product Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <!-- Content will be loaded here dynamically -->
          </div>
        </div>
      </div>
    </div>


    <script src="../assets/js/product-modal.js"></script>



    
    <script>
        // Splash screen functionality
        document.addEventListener('DOMContentLoaded', function() {
        const splash = document.getElementById('splash-screen');
        // const mathParticles = document.getElementById('mathParticles');
        // const logo = document.querySelector('.logo-text'); 

        // // 1. GSAP Initialization: Set the initial state for the logo (Centered)
        // // This ensures GSAP knows the starting point, applying the CSS transform.
        // gsap.set(logo, { x: '-50%', y: '-50%' });

        // createMathParticles();

        // function createMathParticles() {
        //     const particleCount = 24;
        //     for (let i = 0; i < particleCount; i++) {
        //         const particle = document.createElement('div');
        //         particle.className = 'math-particle';
        //         const x = Math.random() * 100;
        //         const y = Math.random() * 100;
        //         const delay = Math.random() * 2;
        //         const xOffset = Math.random() * 200 - 100;
        //         const zOffset = Math.random() * 300;
        //         particle.style.left = `${x}%`;
        //         particle.style.top = `${y}%`;
        //         particle.style.animationDelay = `${delay}s`;
        //         particle.style.setProperty('--x-offset', `${xOffset}px`);
        //         particle.style.setProperty('--z-offset', `${zOffset}px`);
        //         mathParticles.appendChild(particle);
        //     }
        // }

        document.body.style.overflow = 'hidden';
        const layer = document.querySelector('.splash-layer');
        const fallbackTimeout = setTimeout(finishSplash, 500); 
        layer.addEventListener('animationend', finishSplash);
        
        
function finishSplash() {
    clearTimeout(fallbackTimeout);
    layer.removeEventListener('animationend', finishSplash);

    if (!splash) return;

    splash.classList.add('splash-fadeout');

    setTimeout(() => {
        splash.style.display = 'none';
        document.body.style.overflow = '';
    }, 800);
}

// function finishSplash() {
//     clearTimeout(fallbackTimeout);
//     layer.removeEventListener('animationend', finishSplash);

//     // Time after the circle animation ends (2.8s total duration for circleDropExpand)
//     const delayBeforeLogoMove = 0.5; // Start moving 0.5s after the main splash circle effect

//     // Ensure the logo is outside the splash so it won't be hidden when splash disappears
//     if (splash.contains(logo)) {
//         document.body.appendChild(logo);
//     }

//     // Animate logo to top-left corner with responsive positioning
//     const isMobile = window.innerWidth <= 767;
//     const isSmallMobile = window.innerWidth <= 375;
    
//     const logoTop = isSmallMobile ? 12 : (isMobile ? 14 : 20);
//     const logoLeft = isSmallMobile ? 12 : (isMobile ? 14 : 20);
    
//     gsap.to(logo, {
//         duration: 1.2,
//         delay: delayBeforeLogoMove,
//         top: logoTop,       // Responsive distance from top
//         left: logoLeft,    // Responsive distance from left
//         x: 0,               // Reset transforms
//         y: 0,
//         scale: isMobile ? 0.75 : 0.85,  // Smaller scale on mobile
//         ease: "power2.inOut",
//         onStart: () => {
//             // Fade out splash concurrently
//             splash.classList.add('splash-fadeout');
//             setTimeout(() => {
//                 splash.style.display = 'none';
//                 document.body.style.overflow = '';
//             }, 850);
//         },
//         onComplete: () => {
//             // Lock final responsive position
//             logo.classList.add('move-top-left');
//             gsap.set(logo, { clearProps: "left, top, x, y, scale" });
//         }
//     });
// }


    });
    
    // AOS init with custom premium easing and responsive settings
    document.addEventListener("DOMContentLoaded", function() {
        const isMobile = window.innerWidth <= 768;

        // Define a custom premium easing curve for smooth deceleration
        const premiumEasing = 'cubic-bezier(0.23, 1, 0.320, 1)'; 

        if (isMobile) {
            // Mobile: faster, simpler animations for performance
            AOS.init({ 
                duration: 800, 
                easing: 'ease-out', 
                once: true, 
                offset: 50 
            });
        } else {
            // Tablet/Desktop: longer duration and custom easing for a premium feel
            AOS.init({ 
                duration: 1200, 
                easing: premiumEasing, 
                once: true, 
                offset: 120, // Wait slightly longer for elements to enter view
            });
        }
        
        // Performance optimization for mobile
        if (isMobile) {
            // Disable some animations on mobile for better performance
            document.querySelectorAll('.floating-element').forEach(el => {
                el.style.display = 'none';
            });
            
            // Reduce particle count on mobile
            document.querySelectorAll('.particle').forEach(el => {
                el.style.display = 'none';
            });
        }
        
        // Touch-friendly interactions
        if ('ontouchstart' in window) {
            // Add touch-friendly classes
            document.body.classList.add('touch-device');
            
            // Improve touch scrolling
            document.body.style.webkitOverflowScrolling = 'touch';
        }
        
        // Responsive form handling
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            const inputs = form.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                // Ensure touch-friendly input sizes
                if (input.type === 'text' || input.type === 'email' || input.type === 'tel') {
                    input.style.minHeight = '44px';
                    input.style.fontSize = '16px'; // Prevents zoom on iOS
                }
            });
        });
    });

    // Back to Top Button
    const backToTop = document.getElementById("backToTop");
    window.addEventListener("scroll", () => {
        backToTop.style.display = window.scrollY > 300 ? "flex" : "none";
    });
    backToTop.addEventListener("click", () => window.scrollTo({ top: 0, behavior: "smooth" }));

    </script>
    <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


