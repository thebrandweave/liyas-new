<?php
/**
 * /zone/orders - Access Code Authentication Gateway
 * Directs authorized delivery and logistics staff into the live multi-zone orders dashboard.
 */
require_once __DIR__ . '/auth_helper.php';

// If already authenticated, redirect straight to the dashboard
if (isZoneOrdersAuthenticated()) {
    header("Location: " . BASE_URL . '/zone/orders/dashboard/');
    exit;
}

$error = '';
$loggedOut = isset($_GET['logged_out']) && $_GET['logged_out'] == '1';

// Handle access code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accessCode = trim($_POST['access_code'] ?? '');
    
    if (empty($accessCode)) {
        $error = "Please enter your authorization access code.";
    } else {
        $verification = verifyZoneAccessCode($pdo, $accessCode);
        
        if ($verification['valid'] === true) {
            // Set session authorization
            $_SESSION['zone_orders_authenticated'] = true;
            $_SESSION['zone_orders_scope']          = $verification['scope'];
            $_SESSION['zone_orders_zone_id']        = $verification['zone_id'];
            $_SESSION['zone_orders_zone_name']      = $verification['zone_name'];
            $_SESSION['zone_orders_zone_slug']      = $verification['zone_slug'] ?? 'all';
            $_SESSION['zone_orders_auth_time']      = time();
            $_SESSION['zone_orders_code']           = $accessCode;
            
            // Redirect to the dashboard
            header("Location: " . BASE_URL . '/zone/orders/dashboard/');
            exit;
        } else {
            $error = $verification['error'] ?? "Invalid access code. Please check and try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone Orders Portal - Access Authentication | Liyas International</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --primary-border: #bfdbfe;
            --body-bg: #0b1329;
            --card-bg: rgba(255, 255, 255, 0.98);
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-xl: 24px;
            --radius-lg: 16px;
            --shadow-glow: 0 20px 40px -15px rgba(37, 99, 235, 0.35);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: radial-gradient(circle at 50% 15%, #1e293b 0%, #0f172a 50%, #030712 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            color: #f8fafc;
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient background glow circles */
        .ambient-glow {
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.22;
            pointer-events: none;
            z-index: 0;
        }
        .glow-1 {
            width: 450px;
            height: 450px;
            background: #2563eb;
            top: -100px;
            left: 50%;
            transform: translateX(-50%);
        }
        .glow-2 {
            width: 350px;
            height: 350px;
            background: #06b6d4;
            bottom: -50px;
            right: 10%;
        }

        .auth-container {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        .auth-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45), var(--shadow-glow);
            padding: 40px 32px;
            color: var(--text-dark);
            text-align: center;
            transition: transform 0.25s ease;
        }

        .brand-header {
            margin-bottom: 24px;
        }

        .logo-wrap {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px auto;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 8px 20px -4px rgba(37, 99, 235, 0.2);
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #e2e8f0;
        }

        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 12px;
        }

        .badge-live {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border: 1px solid #bfdbfe;
            margin-bottom: 12px;
        }

        .badge-live .pulse-dot {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }
            70% {
                transform: scale(1);
                box-shadow: 0 0 0 8px rgba(16, 185, 129, 0);
            }
            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        .auth-card h1 {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .auth-card p.subtitle {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            text-align: left;
            animation: shake 0.35s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        /* Form Controls */
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-left {
            position: absolute;
            left: 14px;
            font-size: 20px;
            color: #94a3b8;
            pointer-events: none;
        }

        .input-field {
            width: 100%;
            padding: 14px 44px 14px 44px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-family: inherit;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: 0.1em;
            color: #0f172a;
            outline: none;
            transition: all 0.2s ease;
            text-align: center;
        }

        .input-field::placeholder {
            font-size: 14px;
            letter-spacing: normal;
            font-weight: 500;
            color: #94a3b8;
        }

        .input-field:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        }

        .toggle-pwd {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            font-size: 20px;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease;
        }

        .toggle-pwd:hover {
            color: #334155;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: none;
            border-radius: 14px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1px);
            box-shadow: 0 12px 24px -5px rgba(37, 99, 235, 0.5);
        }

        .btn-submit:active {
            transform: translateY(1px);
        }

        /* Quick Demo / Access helper pill */
        .demo-pill {
            margin-top: 24px;
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 12px;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .demo-pill code {
            background: #e2e8f0;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: 0.05em;
        }

        .btn-copy-code {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-copy-code:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .footer-links {
            margin-top: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            font-size: 13px;
        }

        .footer-links a {
            color: #94a3b8;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color 0.15s ease;
        }

        .footer-links a:hover {
            color: #ffffff;
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 32px 20px;
            }
            .auth-card h1 {
                font-size: 21px;
            }
        }
    </style>
</head>
<body>
    <div class="ambient-glow glow-1"></div>
    <div class="ambient-glow glow-2"></div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="brand-header">
                <div class="logo-wrap">
                    <img src="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg" alt="Liyas International">
                </div>
              
                <h1>Zone Orders Login</h1>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class='bx bx-error-circle' style="font-size: 18px;"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($loggedOut): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle' style="font-size: 18px;"></i>
                    <span>You have been safely logged out.</span>
                </div>
            <?php endif; ?>

            <form action="<?= BASE_URL ?>/zone/orders/" method="POST" id="accessForm" autocomplete="off">
                <div class="form-group">
                    <label class="form-label" for="access_code">Enter Access Code</label>
                    <div class="input-wrap">
                        <i class='bx bx-key input-icon-left'></i>
                        <input 
                            type="password" 
                            id="access_code" 
                            name="access_code" 
                            class="input-field" 
                            placeholder="Enter access code..." 
                            required 
                            autofocus
                            value=""
                        >
                        <button type="button" class="toggle-pwd" id="togglePwd" title="Show / Hide Code">
                            <i class='bx bx-show' id="pwdIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <span>Login</span>
                    <i class='bx bx-right-arrow-alt' style="font-size: 20px;"></i>
                </button>
            </form>

            
        </div>

    
    </div>

    <script>
        (function() {
            const input = document.getElementById('access_code');
            const toggle = document.getElementById('togglePwd');
            const icon = document.getElementById('pwdIcon');
            const fillBtn = document.getElementById('btnFillCode');
            const demoCode = document.getElementById('demoCode');

            // Show/Hide password toggle
            if (toggle && input && icon) {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('bx-show');
                        icon.classList.add('bx-hide');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('bx-hide');
                        icon.classList.add('bx-show');
                    }
                    input.focus();
                });
            }

            // Quick auto-fill button
            if (fillBtn && input && demoCode) {
                fillBtn.addEventListener('click', function() {
                    input.value = demoCode.textContent.trim();
                    input.type = 'text';
                    if (icon) {
                        icon.classList.remove('bx-show');
                        icon.classList.add('bx-hide');
                    }
                    input.focus();
                });
            }

            // Button loading feedback on submit
            const form = document.getElementById('accessForm');
            const submitBtn = document.getElementById('submitBtn');
            if (form && submitBtn) {
                form.addEventListener('submit', function() {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin' style='font-size: 18px;'></i> Authenticating...";
                });
            }
        })();
    </script>
</body>
</html>
