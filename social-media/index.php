<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
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
      font-family: Arial, Helvetica, sans-serif;
      background: linear-gradient(135deg, #f8f9fa, #eef1f5);
      padding: 20px;
    }

    .social-container {
      width: 100%;
      max-width: 650px;
      background: #fff;
      padding: 45px 30px;
      border-radius: 24px;
      text-align: center;
      box-shadow: 0 15px 45px rgba(0, 0, 0, 0.1);
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
      transform: translateY(-8px);
      box-shadow: 0 14px 30px rgba(0, 0, 0, 0.14);
    }

    .social-link svg {
      width: 52px;
      height: 52px;
    }

    .social-link span {
      font-size: 16px;
      font-weight: 700;
    }

    /* Instagram */
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

    /* Website */
    .website {
      color: #fff;
      background: linear-gradient(135deg, #111827, #374151);
    }

    /* Google Review */
    .google-review {
      color: #fff;
      background: linear-gradient(135deg, #4285f4, #34a853);
    }

    @media (max-width: 600px) {
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
    }
  </style>
</head>

<body>

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
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M7.75 2h8.5A5.76 5.76 0 0 1 22 7.75v8.5A5.76 5.76 0 0 1 16.25 22h-8.5A5.76 5.76 0 0 1 2 16.25v-8.5A5.76 5.76 0 0 1 7.75 2Zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5A3.75 3.75 0 0 0 20 16.25v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5Zm9.5 1.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
        </svg>

        <span>Instagram</span>
      </a>


      <!-- Website -->
      <a
        href="https://www.liyasinternational.com/"
        target="_blank"
        rel="noopener noreferrer"
        class="social-link website"
        aria-label="Visit Website"
      >

        <!-- Globe Icon -->
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

          <path
            d="M12 2
               a15.3 15.3 0 0 1 4 10
               a15.3 15.3 0 0 1-4 10
               a15.3 15.3 0 0 1-4-10
               a15.3 15.3 0 0 1 4-10z"
          ></path>
        </svg>

        <span>Website</span>
      </a>


      <!-- Google Review -->
      <a
        href="https://search.google.com/local/writereview?placeid=ChIJ9yVC4RdxuzsR9a1DVIzID60"
        target="_blank"
        rel="noopener noreferrer"
        class="social-link google-review"
        aria-label="Google Review"
      >

        <!-- Star Review Icon -->
        <svg
          viewBox="0 0 24 24"
          fill="currentColor"
          aria-hidden="true"
        >
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