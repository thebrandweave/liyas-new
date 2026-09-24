<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Follow Us</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

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
      max-width: 520px;
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
      gap: 22px;
      flex-wrap: wrap;
    }

    .social-link {
      width: 145px;
      height: 145px;
      border-radius: 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
      text-decoration: none;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .social-link:hover {
      transform: translateY(-8px);
      box-shadow: 0 14px 30px rgba(0, 0, 0, 0.14);
    }

    .social-link svg {
      width: 55px;
      height: 55px;
    }

    .social-link span {
      font-size: 16px;
      font-weight: 700;
    }

    .instagram {
      color: #fff;
      background: linear-gradient(135deg, #feda75, #fa7e1e, #d62976, #962fbf, #4f5bd5);
    }

    .facebook {
      color: #fff;
      background: #1877f2;
    }

    @media (max-width: 480px) {
      .social-container { padding: 35px 18px; }
      .social-container h1 { font-size: 26px; }
      .social-links { gap: 15px; }
      .social-link { width: 125px; height: 125px; }
      .social-link svg { width: 48px; height: 48px; }
    }
  </style>
</head>
<body>
  <div class="social-container">
    <h1>Follow Us</h1>
    <p>Connect with us on Instagram and Facebook.</p>

    <div class="social-links">
      <a href="https://www.instagram.com/liyasinternational?stkn=ZHA1bWp0bGtkOW5n"
         target="_blank"
         rel="noopener noreferrer"
         class="social-link instagram"
         aria-label="Instagram">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M7.75 2h8.5A5.76 5.76 0 0 1 22 7.75v8.5A5.76 5.76 0 0 1 16.25 22h-8.5A5.76 5.76 0 0 1 2 16.25v-8.5A5.76 5.76 0 0 1 7.75 2Zm0 2A3.75 3.75 0 0 0 4 7.75v8.5A3.75 3.75 0 0 0 7.75 20h8.5A3.75 3.75 0 0 0 20 16.25v-8.5A3.75 3.75 0 0 0 16.25 4h-8.5Zm9.5 1.5a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>
        </svg>
        <span>Instagram</span>
      </a>

      <a href="https://www.facebook.com/"
         target="_blank"
         rel="noopener noreferrer"
         class="social-link facebook"
         aria-label="Facebook">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path d="M13.5 22v-9h3l.5-3h-3.5V8.1c0-.87.24-1.46 1.52-1.46H17V4a26.5 26.5 0 0 0-2.9-.15c-2.87 0-4.84 1.75-4.84 4.97V10H6v3h3.26v9h4.24Z"/>
        </svg>
        <span>Facebook</span>
      </a>
    </div>
  </div>
</body>
</html>
