
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
  <title>Connect With Us</title>

  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
       font-family: "Montserrat", sans-serif;
  font-optical-sizing: auto;
  font-weight: 400;
  font-style: normal;
      background: linear-gradient(
        135deg,
        #f8f9fa,
        #eef1f5,
        #f5f0ff,
        #eef7ff
      );
      background-size: 400% 400%;
      animation: gradientMove 12s ease infinite;
      padding: 20px;
      overflow: hidden;
      position: relative;
    }

    /* Animated gradient */
    @keyframes gradientMove {
      0% {
        background-position: 0% 50%;
      }

      50% {
        background-position: 100% 50%;
      }

      100% {
        background-position: 0% 50%;
      }
    }

    /* Floating background shapes */
    .bg-shape {
      position: absolute;
      border-radius: 50%;
      filter: blur(10px);
      opacity: 0.35;
      pointer-events: none;
    }

    .shape-1 {
      width: 260px;
      height: 260px;
      background: #d62976;
      top: -80px;
      left: -60px;
      animation: floatOne 9s ease-in-out infinite;
    }

    .shape-2 {
      width: 320px;
      height: 320px;
      background: #4285f4;
      right: -120px;
      bottom: -100px;
      animation: floatTwo 11s ease-in-out infinite;
    }

    .shape-3 {
      width: 180px;
      height: 180px;
      background: #34a853;
      right: 12%;
      top: 8%;
      opacity: 0.18;
      animation: floatThree 8s ease-in-out infinite;
    }

    @keyframes floatOne {
      0%, 100% {
        transform: translate(0, 0) scale(1);
      }

      50% {
        transform: translate(60px, 70px) scale(1.12);
      }
    }

    @keyframes floatTwo {
      0%, 100% {
        transform: translate(0, 0) scale(1);
      }

      50% {
        transform: translate(-70px, -60px) scale(1.1);
      }
    }

    @keyframes floatThree {
      0%, 100% {
        transform: translateY(0) translateX(0);
      }

      50% {
        transform: translateY(80px) translateX(-50px);
      }
    }

    .social-container {
      width: 100%;
      max-width: 650px;
      background: rgba(255, 255, 255, 0.82);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      padding: 45px 30px;
      border-radius: 24px;
      text-align: center;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.65);
      position: relative;
      z-index: 10;
    }

    .social-container h1 {
      font-size: 32px;
      color: #1f2937;
      margin-bottom: 10px;
    }

    .social-container p {
      color: #6b7280;
      margin-bottom: 32px;
      font-size: 15px;
      line-height: 1.6;
    }

    .social-links {
      display: flex;
      justify-content: center;
      gap: 18px;
      flex-wrap: wrap;
    }

    .social-link {
      width: 160px;
      height: 145px;
      border-radius: 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
      text-decoration: none;
      transition:
        transform 0.3s ease,
        box-shadow 0.3s ease;
    }

    .social-link:hover {
      transform: translateY(-8px) scale(1.03);
      box-shadow: 0 14px 30px rgba(0, 0, 0, 0.16);
    }

    .social-link svg {
      width: 52px;
      height: 52px;
    }

    .social-link span {
      font-size: 16px;
      font-weight: 700;
    }

    .instagram {
      color: #fff;
      background: linear-gradient(
        135deg,
        #feda75,
        #fa7e1e,
        #d62976,
        #962fbf,
        #4f5bd5
      );
    }

    .website {
      color: #fff;
      background: linear-gradient(135deg, #111827, #374151);
    }

    .google-review {
      color: #fff;
      background: linear-gradient(135deg, #4285f4, #34a853);
    }

    @media (max-width: 600px) {
      body {
        overflow-y: auto;
      }

      .social-container {
        padding: 35px 18px;
      }

      .social-container h1 {
        font-size: 26px;
      }

      .social-links {
        gap: 14px;
      }

      .social-link {
        width: 100%;
        height: 110px;
        flex-direction: row;
      }

      .social-link svg {
        width: 44px;
        height: 44px;
      }

      .shape-1 {
        width: 180px;
        height: 180px;
      }

      .shape-2 {
        width: 220px;
        height: 220px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      body,
      .bg-shape {
        animation: none;
      }
    }
  </style>
</head>

<body>

  <!-- Animated Background -->
  <div class="bg-shape shape-1"></div>
  <div class="bg-shape shape-2"></div>
  <div class="bg-shape shape-3"></div>

  <div class="social-container">

    <h1>Connect With Us</h1>

    <p>
      Follow us on Instagram, visit our website, or share your experience
      with us on Google.
    </p>

    <div class="social-links">

      <!-- Instagram -->
      <a
        href="https://www.instagram.com/liyasinternational?stkn=ZHA1bWp0bGtkOW5n"
        target="_blank"
        rel="noopener noreferrer"
        class="social-link instagram"
        aria-label="Instagram"
      >
        <svg viewBox="0 0 24 24" fill="currentColor">
          <path d="M7.75 2h8.5A5.76 5.76 0 0 1 22 7.75v8.5A5.76 5.76 0 0 1 16.25 22h-8.5A5.76 5.76 0 0 1 2 16.25v-8.5A5.76 5.76 0 0 1 7.75 2Zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5A3.75 3.75 0 0 0 20 16.25v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5Zm9.5 1.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
        </svg>

        <span>Instagram</span>
      </a>

      <!-- Website -->
      <a
        href="https://liyasinternational.com/"
        target="_blank"
        rel="noopener noreferrer"
        class="social-link website"
        aria-label="Website"
      >
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="2" y1="12" x2="22" y2="12"></line>
          <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
        </svg>

        <span>Website</span>
      </a>

      <!-- Google Review -->
      <a
        href="YOUR_GOOGLE_REVIEW_LINK"
        target="_blank"
        rel="noopener noreferrer"
        class="social-link google-review"
        aria-label="Google Review"
      >
        <svg viewBox="0 0 24 24" fill="currentColor">
          <path
            d="M12 2.5
            14.9 8.4
            21.4 9.35
            16.7 13.9
            17.8 20.3
            12 17.25
            6.2 20.3
            7.3 13.9
            2.6 9.35
            9.1 8.4
            Z"
          />
        </svg>

        <span>Google Review</span>
      </a>

    </div>
  </div>

</body>
</html>
