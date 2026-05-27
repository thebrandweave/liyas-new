<?php
require_once 'config/config.php';

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
    <title>LIYAS Mineral Water - Premium Quality Water</title>
    <meta name="description" content="LIYAS Mineral Water - Premium quality mineral water for a healthy lifestyle. Pure, refreshing, and naturally sourced.">
    <meta name="keywords" content="mineral water, premium water, healthy water, LIYAS, pure water">
    
  <link rel="icon" type="image/jpeg"  sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="shortcut icon" type="image/jpeg"  sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="apple-touch-icon"  type="image/jpeg"  sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="icon" type="image/jpeg" sizes="38x38" href="https://liyasinternational.com/assets/images/logo/logol.png">
    <link rel="icon" type="image/jpeg" sizes="16x16" href="https://liyasinternational.com/assets/images/logo/logol.png">
    
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/about.css">
    <link rel="stylesheet" href="assets/css/product.css">

    <link rel="stylesheet" href="assets/css/cart.css">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
    
    <meta name="theme-color" content="#4ad2e2">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="LIYAS Water">
    
    <meta property="og:title" content="LIYAS Mineral Water - Premium Quality Water">
    <meta property="og:description" content="Premium quality mineral water for a healthy lifestyle. Pure, refreshing, and naturally sourced.">
    <meta property="og:image" content="assets/images/logo/logo.png">
    <meta property="og:url" content="https://liyas-water.com">
    <meta property="og:type" content="website">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="LIYAS Mineral Water - Premium Quality Water">
    <meta name="twitter:description" content="Premium quality mineral water for a healthy lifestyle. Pure, refreshing, and naturally sourced.">
    <meta name="twitter:image" content="assets/images/logo/logo.png">
    
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "LIYAS Mineral Water",
        "description": "Premium quality mineral water for a healthy lifestyle",
        "url": "https://liyas-water.com",
        "logo": "assets/images/logo/logo.png",
        "contactPoint": {
            "@type": "ContactPoint",
            "telephone": "+1-234-567-8900",
            "contactType": "customer service"
        }
    }
    </script>
    <script>
        const BASE_URL = '<?php echo BASE_URL; ?>';
    </script>
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
        }


        /* Logo container in the center (GSAP will manage the move, so no CSS transition) */
        /*.logo-text {*/
        /*    position: fixed;*/
        /*    top: 50%;*/
        /*    left: 50%;*/
            /* The initial centering (translate) is managed by GSAP.set() in JS */
        /*    font-size: clamp(2rem, 8vw, 4rem);*/
        /*    font-weight: 700;*/
        /*    color: rgba(74, 210, 226, 0.71);*/
            /*z-index: 10000; */
            /* Increased z-index to ensure visibility during splash fade 
        /*    text-shadow: 2px 2px 4px rgba(0,0,0,0.1);*/
        /*    pointer-events: none;*/
        /*    font-family: 'Poppins', sans-serif;*/
            /* REMOVED: transition: all 1s ease; */
        /*}*/

        /* Final state after GSAP animation completes. This ensures responsiveness. */
        /* Final locked position for top-left logo */
        /*.logo-text.move-top-left {*/
        /*    top: 20px !important;*/
        /*    left: 20px !important;*/
        /*    right: auto !important;*/
        /*    transform: none !important;*/
        /*    font-size: clamp(1rem, 4vw, 1.5rem);*/
        /*}*/

        /* Responsive Logo Image */
        /*.logo-text img {*/
        /*    width: clamp(60px, 15vw, 100px);*/
        /*    height: clamp(60px, 15vw, 100px);*/
        /*}*/

        /* Mobile adjustments for logo to avoid overlap */
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

        /* --- Existing Animations Below --- */
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

        /* Hero layout padding to avoid overlap with scroll control */
        .hero { 
            position: relative; 
            /* padding-bottom: 120px; space reserved for scroll control */
        }

        @media (min-width: 768px) {
            .hero {
                 /* padding-bottom: 140px;  */
                }
        }

        .wave-svg {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: auto;
            pointer-events: none;
        }

        @media (max-width: 767px) {
            .wave-svg {
                top: 790px; /* keep wave touching the next section */
            }
        }

        /* Hero Scroll Down Button - Circular Text Design */
        .hero-scroll-down {
            position: absolute;
            bottom: 20%;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
            cursor: pointer;
            width: 150px;
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-scroll-down svg {
            width: 150px;
            height: 150px;
            transition: transform 1s cubic-bezier(0.65, 0, 0.35, 1);
        }

        .hero-scroll-down:hover svg .textcircle {
            transform: scale(1.2) rotate(90deg);
        }

        .hero-scroll-down .textcircle {
            transition: transform 1s cubic-bezier(0.65, 0, 0.35, 1);
            transform-origin: 250px 250px;
        }

        .hero-scroll-down text {
            font-size: 28px;
            font-family: "Poppins", sans-serif;
            font-weight: 700;
            text-transform: uppercase;
            fill: rgba(74, 210, 226, 1);
            animation: rotate 25s linear infinite;
            transform-origin: 250px 250px;
        }

        .hero-scroll-down .center-arrow {
            transform-origin: 250px 250px;
            transition: transform 0.3s ease;
        }

        .hero-scroll-down:hover .center-arrow {
            transform: translateY(5px);
        }

        @keyframes rotate {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-10px);
            }
            60% {
                transform: translateY(-5px);
            }
        }

        .hero-scroll-down .center-arrow {
            animation: bounce 2s infinite;
        }

        @media (max-width: 767px) {
            .hero-scroll-down {
                bottom: 12px; /* a bit lower on small screens */
                width: 120px;
                height: 120px;
            }
            
            .hero-scroll-down svg {
                width: 120px;
                height: 120px;
            }

            .hero-scroll-down text {
                font-size: 20px;
            }
        }



        .about-images {
            position: relative;
        }


        /* Hero image placement rules */
        .hero-image-mobile {
            display: none;
            margin: var(--space-md, 1.5rem) 0;
            text-align: center;
        }

        .hero-image-desktop {
            display: block;
        }

        @media (max-width: 767px) {
            .hero-image-mobile { display: block; }
            .hero-image-desktop { display: none; }
        }

        @media (min-width: 768px) {
            .hero-image-mobile { display: none; }
            .hero-image-desktop { display: block; }
        }

        @media (max-width: 767px) {
            .hero-image-mobile img {
                max-width: clamp(180px, 62vw, 260px);
                margin-inline: auto;
            }
        }

        /* Social Sidebar - match circular white buttons look */
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
            color: #0f172a; /* dark icon color */
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

        @media (max-width: 767px) {
            .social-sidebar { display: none; }
        }

/* Top-right Login Button - aligned with Back to Top button */
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

.top-login-btn:active {
  transform: translateY(-1px) scale(0.98);
}

/* SVG Icon inside the circle */
.top-login-btn .login-svg {
  width: 24px;
  height: 24px;
  stroke-width: 2;
  transition: stroke 0.3s ease;
  display: block;
}

.top-login-btn:hover .login-svg {
  stroke: rgba(74, 210, 226, 1);
}

/* Tablet adjustments */
@media (min-width: 768px) and (max-width: 991px) {
  .top-login-btn {
    top: 28px;
    right: 18px;
    width: 76px;
    height: 48px;
    min-width: 48px;
    min-height: 42px;
  }
  
  .top-login-btn .login-svg {
    width: 22px;
    height: 22px;
  }
  .login-button{
      display: block;
  }
}

/* Responsive adjustments (mobile view) */
@media screen and (max-width: 767px) {
  .top-login-btn {
      display: none;
    /*width: 80px !important;*/
    /*height: 48px !important;*/
    /*min-width: 48px !important;*/
    /*min-height: 48px !important;*/
    /*top: 16px !important;*/
    /*right: 16px !important;*/
    /*left: auto !important;*/
    /*bottom: auto !important;*/
    /*box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;*/
    z-index: 1101 !important; /* Above navbar but below mobile menu when open */
    /*background: rgba(255, 255, 255, 1) !important;*/
    /*border: 1px solid rgba(0, 0, 0, 0.1) !important;*/
    /* Ensure visibility */
    /*opacity: 1 !important;*/
    /*visibility: visible !important;*/
    /*pointer-events: auto !important;*/
    /*display: flex !important;*/
    /*position: fixed !important;*/
    /*transform: none !important;*/
  }
  
  .top-login-btn .login-svg {
    width: 22px !important;
    height: 22px !important;
    stroke-width: 2.2 !important;
    display: block !important;
    flex-shrink: 0 !important;
  }

  /* Adjust position when navbar is scrolled */
  .liyas-navbar.scrolled ~ .top-login-btn {
    top: 14px !important;
  }
  
  /* Ensure button is clickable */
  .top-login-btn:active {
    transform: scale(0.95) !important;
  }
}

/* Extra small devices */
@media screen and (max-width: 480px) {
  .top-login-btn {
    width: 61px !important;
    height: 46px !important;
    min-width: 46px !important;
    min-height: 46px !important;
    top: 15px !important;
    right: 124px !important;
    left: auto !important;
    position: fixed !important;
  }
  
  .top-login-btn .login-svg {
    width: 20px !important;
    height: 20px !important;
  }
}

@media screen and (max-width: 375px) {
  .top-login-btn {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    min-height: 44px !important;
    top: 12px !important;
    right: 12px !important;
    left: auto !important;
    position: fixed !important;
  }
  
  .top-login-btn .login-svg {
    width: 18px !important;
    height: 18px !important;
  }
}

/* Hide login button when mobile menu is open to avoid overlap */
@media screen and (max-width: 767px) {
  body.no-scroll .top-login-btn {
    opacity: 0 !important;
    pointer-events: none !important;
    transform: scale(0.8) !important;
    visibility: hidden !important;
  }
}



    </style>

    <?php include 'components/navbar.php' ?>

    <div id="splash-screen" aria-hidden="true">
        <div class="splash-layer" aria-hidden="true"></div>
        <!--<div class="logo-text"> -->
        <!--    <div class="row">-->
        <!--    <img style="width: 180px; height: 180px; margin-top:-60px;" src="assets/images/logo/logo.png" alt="Liyas">-->
        <!--    </div>-->
        <!--</div>-->
        <!--<div class="math-particles" aria-hidden="true" id="mathParticles"></div>-->
    </div>


<!--<?php if (isset($_SESSION['user_id'])): ?>-->
<!--  <a href="logout.php" class="top-login-btn" title="Logout">-->
<!--    <img src="logout.png" alt="Logout" class="login-svg" style="width: 24px; height: 22px;padding:2px;"> <span style="font-size:15px;"> Logout</span>-->
<!--</a>-->
<!--<?php else: ?>-->
<!--    <a href="login.php" class="top-login-btn" title="Login">-->
<!--         <img src="user.png" alt="Logout" class="login-svg" style="width: 24px; height: 22px;padding:0px;"> <span style="font-size:15px;"> </span>-->
<!--    </a>-->
<!--<?php endif; ?>-->



    <div class="social-sidebar">
        <?php foreach ($social_links as $link): ?>
            <a href="<?= htmlspecialchars($link['url']) ?>" class="social-icon" target="_blank" aria-label="<?= htmlspecialchars($link['platform']) ?>">
                <i class="<?= htmlspecialchars($link['icon_class']) ?>"></i>
            </a>
        <?php endforeach; ?>
    </div>
    
<button id="backToTop" title="Go to top">
    <img style="width:20px;" src="/up-arrow.png" alt="Top" class="flaticon-img">
</button>

    <section class="hero" id="home" data-aos="fade-up">
        <div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <div class="hero-content" data-aos="fade-right" data-aos-delay="50">
                        <div class="hero-subtitle">PURE WATER</div>
                        <h1><span class="text-primary">REFRESH</span> <span class="text-primary">YOUR SOUL</span></h1>
                        <div class="hero-image-mobile" aria-hidden="false">
                            <div class="hero-bottle-container">
                                <img src="assets/images/b.png" alt="Liyas Mineral Water Bottle" class="img-fluid h animated-image">
                            </div>
                        </div>
                        <p style="color:#cecece;">
                        Meet Liyas — hydration redefined for today's lifestyle.
Crafted for clarity, freshness, and a touch of fun.
Because great water isn't just a choice — it's a vibe.
                        </p>
                        <div class="hero-buttons">
                            <a href="products.php" class="btn-primary">Shop Now</a>
                            <a href="about.php" class="btn-secondary">Explore</a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="hero-image hero-image-desktop" data-aos="fade-left" data-aos-delay="100">
                        <div class="hero-bottle-container">
                            <img src="assets/images/b.png" alt="Liyas Mineral Water Bottle" class="img-fluid animated-image">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="hero-scroll-down" id="heroScrollDown" title="Scroll to next section">
            <svg xmlns="http://www.w3.org/2000/svg" xml:lang="en" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 500 500">
                <defs>
                    <path id="textcirclenew" d="M250,400 a150,150 0 0,1 0,-300a150,150 0 0,1 0,300Z" 
                            transform="rotate(12,250,250)"/>
                </defs>
                <g class="textcircle">
                    <text style="font-size: 55px;">
                        <textPath 
                                     xlink:href="#textcirclenew" 
                                     aria-label="Scroll Down" 
                                     textLength="880">
                            Scroll Down
                        </textPath>
                    </text>
                </g>
                <g class="center-arrow">
                    <path d="M250,200 L250,280 M250,280 L230,260 M250,280 L270,260" 
                            fill="none" 
                            stroke="rgba(74, 210, 226, 1)" 
                            stroke-width="3"
                            stroke-linecap="round"
                            stroke-linejoin="round"/>
                </g>
            </svg>
        </div>
        
        <svg class="wave-svg" width="100%" height="100%"  id="svg" viewBox="0 0 1440 390" xmlns="http://www.w3.org/2000/svg" class="transition duration-300 ease-in-out delay-150">
            <style>
                .path-0{
                    animation:pathAnim-0 4s;
                    animation-timing-function: linear;
                    animation-iteration-count: infinite;
                }
                @keyframes pathAnim-0{
                    0%{
                        d: path("M 0,400 L 0,150 C 94.73076923076925,117.93589743589743 189.4615384615385,85.87179487179488 277,76 C 364.5384615384615,66.12820512820512 444.88461538461536,78.44871794871794 507,92 C 569.1153846153846,105.55128205128206 613.0000000000001,120.33333333333333 694,122 C 774.9999999999999,123.66666666666667 893.1153846153843,112.21794871794873 986,128 C 1078.8846153846157,143.78205128205127 1146.5384615384617,186.7948717948718 1218,195 C 1289.4615384615383,203.2051282051282 1364.730769230769,176.6025641025641 1440,150 L 1440,400 L 0,400 Z");
                    }
                    25%{
                        d: path("M 0,400 L 0,150 C 75.4897435897436,175.26666666666665 150.9794871794872,200.53333333333333 243,182 C 335.0205128205128,163.46666666666667 443.57179487179485,101.13333333333334 521,90 C 598.4282051282051,78.86666666666666 644.7333333333333,118.93333333333334 714,139 C 783.2666666666667,159.06666666666666 875.4948717948719,159.13333333333333 955,153 C 1034.5051282051281,146.86666666666667 1101.2871794871794,134.53333333333333 1180,133 C 1258.7128205128206,131.46666666666667 1349.3564102564103,140.73333333333335 1440,150 L 1440,400 L 0,400 Z");
                    }
                    50%{
                        d: path("M 0,400 L 0,150 C 62.51794871794871,169.57179487179485 125.03589743589743,189.14358974358973 204,184 C 282.9641025641026,178.85641025641027 378.374358974359,148.99743589743588 461,152 C 543.625641025641,155.00256410256412 613.4666666666667,190.8666666666667 695,172 C 776.5333333333333,153.1333333333333 869.7589743589742,79.53589743589743 947,78 C 1024.2410256410258,76.46410256410257 1085.497435897436,146.9897435897436 1165,171 C 1244.502564102564,195.0102564102564 1342.251282051282,172.5051282051282 1440,150 L 1440,400 L 0,400 Z");
                    }
                    75%{
                        d: path("M 0,400 L 0,150 C 65.39743589743588,127.30000000000001 130.79487179487177,104.60000000000001 219,110 C 307.20512820512823,115.39999999999999 418.2179487179486,148.9 502,166 C 585.7820512820514,183.1 642.3333333333335,183.79999999999998 725,186 C 807.6666666666665,188.20000000000002 916.448717948718,191.9 991,199 C 1065.551282051282,206.1 1105.871794871795,216.6 1175,209 C 1244.128205128205,201.4 1342.0641025641025,175.7 1440,150 L 1440,400 L 0,400 Z");
                    }
                    100%{
                        d: path("M 0,400 L 0,150 C 94.73076923076925,117.93589743589743 189.4615384615385,85.87179487179488 277,76 C 364.5384615384615,66.12820512820512 444.88461538461536,78.44871794871794 507,92 C 569.1153846153846,105.55128205128206 613.0000000000001,120.33333333333333 694,122 C 774.9999999999999,123.66666666666667 893.1153846153843,112.21794871794873 986,128 C 1078.8846153846157,143.78205128205127 1146.5384615384617,186.7948717948718 1218,195 C 1289.4615384615383,203.2051282051282 1364.730769230769,176.6025641025641 1440,150 L 1440,400 L 0,400 Z");
                    }
                }
            </style>
            <path d="M 0,400 L 0,150 C 94.73076923076925,117.93589743589743 189.4615384615385,85.87179487179488 277,76 C 364.5384615384615,66.12820512820512 444.88461538461536,78.44871794871794 507,92 C 569.1153846153846,105.55128205128206 613.0000000000001,120.33333333333333 694,122 C 774.9999999999999,123.66666666666667 893.1153846153843,112.21794871794873 986,128 C 1078.8846153846157,143.78205128205127 1146.5384615384617,186.7948717948718 1218,195 C 1289.4615384615383,203.2051282051282 1364.730769230769,176.6025641025641 1440,150 L 1440,400 L 0,400 Z" stroke="none" stroke-width="0" fill="#ffffff" fill-opacity="1" class="transition-all duration-300 ease-in-out delay-150 path-0"></path>
        </svg>
    </section>


    <?php include 'components/about.php'; ?>

    <?php include 'components/whychooseus.php'; ?>
    <?php include 'components/products.php'; ?>


    <?php include 'components/contact.php'; ?>
    <?php include 'components/sustainability.php'; ?>
    <?php include 'components/cta.php'; ?>

    <?php include 'components/faq.php'; ?>



    <?php include 'components/footer.php'; ?>


    <script>

    // Splash screen functionality
    document.addEventListener('DOMContentLoaded', function() {
        const splash = document.getElementById('splash-screen');
        // const mathParticles = document.getElementById('mathParticles');

        // 1. GSAP Initialization: Set the initial state for the logo (Centered)
        // This ensures GSAP knows the starting point, applying the CSS transform.
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

    // Time after the circle animation ends (2.8s total duration for circleDropExpand)
    // const delayBeforeLogoMove = 0.5; // Start moving 0.5s after the main splash circle effect

    // Ensure the logo is outside the splash so it won't be hidden when splash disappears
    // if (splash.contains(logo)) {
    //     document.body.appendChild(logo);
    // }

    // Animate logo to top-left corner with responsive positioning
    // const isMobile = window.innerWidth <= 767;
    // const isSmallMobile = window.innerWidth <= 375;
    
    // const logoTop = isSmallMobile ? 12 : (isMobile ? 14 : 20);
    // const logoLeft = isSmallMobile ? 12 : (isMobile ? 14 : 20);
    
    // gsap.to(logo, {
    //     duration: 1.2,
    //     delay: delayBeforeLogoMove,
    //     top: logoTop,       // Responsive distance from top
    //     left: logoLeft,    // Responsive distance from left
    //     x: 0,               // Reset transforms
    //     y: 0,
    //     scale: isMobile ? 0.75 : 0.85,  // Smaller scale on mobile
    //     ease: "power2.inOut",
    //     onStart: () => {
    //         // Fade out splash concurrently
    //         splash.classList.add('splash-fadeout');
    //         setTimeout(() => {
    //             splash.style.display = 'none';
    //             document.body.style.overflow = '';
    //         }, 850);
    //     },
    //     onComplete: () => {
    //         // Lock final responsive position
    //         logo.classList.add('move-top-left');
    //         gsap.set(logo, { clearProps: "left, top, x, y, scale" });
    //     }
    // });
// }
});

    // });
    
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

    // Hero Scroll Down Button
    const heroScrollDown = document.getElementById("heroScrollDown");
    heroScrollDown.addEventListener("click", () => {
        const aboutSection = document.getElementById("about");
        aboutSection.scrollIntoView({ behavior: "smooth" });
    });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>