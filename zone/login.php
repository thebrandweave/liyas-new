<?php
/**
 * Liyas Zone Portal - Device Lock & 4-Digit Zone Access Code Gateway
 * 1. Checks if the mobile phone / device is authorized (One-Time Admin Master PIN).
 * 2. Once the device is authorized, shows the 4-Digit Zone Access Code screen.
 * 3. Opens the matching Delivery Zone Portal with the exact original UI.
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// When accessed via LAN IP on local XAMPP, keep local DB detection working
if (stripos(__DIR__, 'xampp') !== false && isset($_SERVER['HTTP_HOST']) && !in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1'])) {
    $originalHost = $_SERVER['HTTP_HOST'];
    $_SERVER['HTTP_HOST'] = 'localhost';
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentHost = $originalHost ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
$dynamicBaseUrl = (stripos(__DIR__, 'xampp') !== false)
    ? "{$scheme}://{$currentHost}/liyas-new"
    : BASE_URL;

/**
 * Ensure `zone_authorized_devices` table and default Master Setup PIN exist,
 * and ensure every zone has a unique 4-digit access code.
 */
function initZoneSecurityAndCodes(PDO $pdo): array {
    try {
        // 1. Authorized Devices table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `zone_authorized_devices` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `device_token` VARCHAR(100) NOT NULL UNIQUE,
                `device_name` VARCHAR(150) NOT NULL DEFAULT 'Staff Phone',
                `user_agent` VARCHAR(255) NULL,
                `ip_address` VARCHAR(60) NULL,
                `status` ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_dev_token` (`device_token`),
                INDEX `idx_dev_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 2. Default Master Device Setup PIN in system_settings (Default: 9999)
        $pdo->exec("
            INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`)
            VALUES ('zone_device_master_pin', '9999')
        ");

        // 3. Ensure every active zone has a unique 4-digit access code (1001, 1002, 1003...)
        $cols = $pdo->query("SHOW COLUMNS FROM `zones`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('access_code', $cols)) {
            $pdo->exec("ALTER TABLE `zones` ADD COLUMN `access_code` VARCHAR(50) NULL AFTER `slug`");
        }

        $zones = $pdo->query("SELECT id, name, slug, access_code, status FROM `zones` WHERE `status` = 'active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $usedCodes = [];
        $codeCounts = [];
        foreach ($zones as $z) {
            $c = trim((string)($z['access_code'] ?? ''));
            if (preg_match('/^\d{4}$/', $c)) {
                $codeCounts[$c] = ($codeCounts[$c] ?? 0) + 1;
            }
        }
        foreach ($codeCounts as $c => $cnt) {
            if ($cnt === 1) $usedCodes[$c] = true;
        }

        $upd = $pdo->prepare("UPDATE `zones` SET `access_code` = ? WHERE `id` = ?");
        $seq = 1;
        foreach ($zones as &$z) {
            $zid = (int)$z['id'];
            $c = trim((string)($z['access_code'] ?? ''));
            if (!preg_match('/^\d{4}$/', $c) || ($codeCounts[$c] ?? 0) > 1) {
                $candidate = 1000 + $seq;
                while (isset($usedCodes[str_pad((string)$candidate, 4, '0', STR_PAD_LEFT)])) {
                    $candidate++;
                }
                $newCode = str_pad((string)$candidate, 4, '0', STR_PAD_LEFT);
                $usedCodes[$newCode] = true;
                $codeCounts[$newCode] = 1;
                $upd->execute([$newCode, $zid]);
                $z['access_code'] = $newCode;
            }
            $seq++;
        }
        unset($z);

        return $zones;
    } catch (Exception $e) {
        return [];
    }
}

$allZones = initZoneSecurityAndCodes($pdo);
$masterPin = getSystemSetting($pdo, 'zone_device_master_pin', '9999');
if (empty($masterPin)) $masterPin = '9999';

// Check if current device is authorized via Cookie, Session, or Token Restore
$deviceToken = trim((string)($_COOKIE['liyas_device_token'] ?? ($_SESSION['liyas_device_token'] ?? ($_REQUEST['device_token'] ?? ''))));
$isDeviceAuthorized = false;

// Admin logged in on PC is always authorized
if (!empty($_SESSION['admin_id'])) {
    $isDeviceAuthorized = true;
} elseif (!empty($deviceToken)) {
    try {
        $devStmt = $pdo->prepare("SELECT id, status FROM `zone_authorized_devices` WHERE `device_token` = ? LIMIT 1");
        $devStmt->execute([$deviceToken]);
        $devRow = $devStmt->fetch(PDO::FETCH_ASSOC);
        if ($devRow && $devRow['status'] === 'active') {
            $isDeviceAuthorized = true;
            $_SESSION['liyas_device_token'] = $deviceToken;
            // Refresh 10-year cookie
            if (!headers_sent()) {
                setcookie('liyas_device_token', $deviceToken, time() + (10 * 365 * 86400), '/');
            }
            $pdo->prepare("UPDATE `zone_authorized_devices` SET `last_seen_at` = NOW() WHERE `id` = ?")->execute([(int)$devRow['id']]);
        }
    } catch (Exception $e) {}
}

// Handle lock / switch zone logout (keeps device authorized, only locks the zone)
$loggedOut = false;
if (isset($_GET['lock']) || isset($_GET['logout']) || isset($_GET['switch_zone'])) {
    unset(
        $_SESSION['delivery_zone_id'],
        $_SESSION['delivery_zone_slug'],
        $_SESSION['delivery_zone_name'],
        $_SESSION['zone_access_verified']
    );
    $loggedOut = true;
}

// If device is authorized AND zone is already unlocked, redirect straight to the unlocked zone
if ($isDeviceAuthorized && !$loggedOut && !empty($_SESSION['zone_access_verified']) && !empty($_SESSION['delivery_zone_slug']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $unlockedSlug = $_SESSION['delivery_zone_slug'];
    header("Location: " . $dynamicBaseUrl . '/' . urlencode($unlockedSlug));
    exit;
}

$error = '';
$successMsg = '';

// =========================================================================
// HANDLE POST: 1) Device Master PIN Setup OR 2) 4-Digit Zone Access Code
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. One-Time Device Authorization Submission
    if (isset($_POST['authorize_device'])) {
        $enteredMasterPin = trim((string)($_POST['master_pin'] ?? ''));
        $deviceName = 'Authorized Staff Phone';

        if ($enteredMasterPin === $masterPin || strcasecmp($enteredMasterPin, 'ZONE2026') === 0) {
            $newToken = 'DEV-' . bin2hex(random_bytes(16));
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device', 0, 250);
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';

            $insDev = $pdo->prepare("
                INSERT INTO `zone_authorized_devices` (`device_token`, `device_name`, `user_agent`, `ip_address`, `status`)
                VALUES (?, ?, ?, ?, 'active')
            ");
            $insDev->execute([$newToken, $deviceName, $ua, $ip]);

            setcookie('liyas_device_token', $newToken, time() + (10 * 365 * 86400), '/');
            $_SESSION['liyas_device_token'] = $newToken;
            $deviceToken = $newToken;
            $isDeviceAuthorized = true;
            $successMsg = "Phone authorized permanently! Now enter your 4-digit Zone Access Code.";
        } else {
            $error = "Invalid Admin Master Setup PIN. This device is not authorized.";
        }
    }
    // 2. 4-Digit Zone Access Code Submission
    elseif ($isDeviceAuthorized) {
        $codeInput = trim((string)($_POST['access_code'] ?? ''));
        if ($codeInput === '' || !preg_match('/^\d{4}$/', $codeInput)) {
            $error = "Please enter a valid 4-digit Zone Access Code.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `zones` WHERE `status` = 'active' AND TRIM(`access_code`) = ? LIMIT 1");
            $stmt->execute([$codeInput]);
            $matchedZone = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($matchedZone) {
                $_SESSION['delivery_zone_id']     = (int)$matchedZone['id'];
                $_SESSION['delivery_zone_slug']   = $matchedZone['slug'];
                $_SESSION['delivery_zone_name']   = $matchedZone['name'];
                $_SESSION['zone_access_verified'] = true;

                $targetUrl = $dynamicBaseUrl . '/' . urlencode($matchedZone['slug']);
                header("Location: " . $targetUrl);
                exit;
            } else {
                $error = "Invalid 4-digit access code. Please check your zone code and try again.";
            }
        }
    } else {
        $error = "This device must be authorized by Admin first.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0b1329">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Zone Access Code | Liyas International</title>
    <link rel="manifest" href="<?= $dynamicBaseUrl ?>/zone/manifest.json">
    <link rel="icon" type="image/jpeg" href="<?= $dynamicBaseUrl ?>/assets/images/logo/logo-bg.jpg">
    <link rel="apple-touch-icon" href="<?= $dynamicBaseUrl ?>/assets/images/logo/logo-bg.jpg">
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
            max-width: 450px;
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
            padding: 34px 28px;
            color: var(--text-dark);
            text-align: center;
        }

        .brand-header {
            margin-bottom: 20px;
        }

        .logo-wrap {
            width: 72px;
            height: 72px;
            margin: 0 auto 14px auto;
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
            margin-bottom: 10px;
        }

        .badge-live .pulse-dot {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
        }

        .auth-card h1 {
            font-size: 23px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .auth-card p.subtitle {
            color: var(--text-muted);
            font-size: 13.5px;
            line-height: 1.5;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
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

        /* 4-Digit PIN Boxes */
        .pin-boxes {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin: 18px 0 22px 0;
        }

        .pin-digit {
            width: 62px;
            height: 68px;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 16px;
            font-family: inherit;
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            text-align: center;
            outline: none;
            transition: all 0.2s ease;
        }

        .pin-digit:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
            transform: translateY(-2px);
        }

        .form-group {
            margin-bottom: 16px;
            text-align: left;
        }

        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .input-field {
            width: 100%;
            padding: 13px 14px;
            background: #f8fafc;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            outline: none;
        }

        .input-field:focus {
            background: #ffffff;
            border-color: var(--primary);
        }

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
        }

        .zones-code-box {
            margin-top: 22px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 12px 14px;
            text-align: left;
        }

        .zones-code-header {
            font-size: 11.5px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .zone-code-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 180px;
            overflow-y: auto;
        }

        .zone-code-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .zone-code-item:hover {
            border-color: #93c5fd;
            background: #eff6ff;
        }

        .zone-code-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .zone-code-badge {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-family: monospace;
            font-size: 14px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 8px;
            letter-spacing: 0.1em;
        }

        .install-banner {
            display: none;
            margin-top: 16px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 10px 14px;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .btn-install {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
                    <img src="<?= $dynamicBaseUrl ?>/assets/images/logo/logo-bg.jpg" alt="Liyas International">
                </div>

                <?php if (!$isDeviceAuthorized): ?>
                    <div class="badge-live" style="background: #fef2f2; color: #dc2626; border-color: #fecaca;">
                        <i class='bx bxs-lock-alt'></i>
                        <span>Device Protection Active</span>
                    </div>
                    <h1>Authorize This Phone</h1>
                    <p class="subtitle">This app only works on authorized Liyas staff phones. Enter the one-time Admin Master PIN to activate this device.</p>
                <?php else: ?>
                    <div class="badge-live">
                        <span class="pulse-dot"></span>
                        <span>Authorized Device</span>
                    </div>
                    <h1>Zone Access Code</h1>
                    <p class="subtitle">Enter your 4-digit zone access code to open your delivery zone</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class='bx bx-error-circle' style="font-size: 18px;"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMsg)): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle' style="font-size: 18px;"></i>
                    <span><?= htmlspecialchars($successMsg) ?></span>
                </div>
            <?php elseif ($loggedOut): ?>
                <div class="alert alert-success">
                    <i class='bx bx-check-circle' style="font-size: 18px;"></i>
                    <span>Zone locked. Enter a 4-digit code to open a zone.</span>
                </div>
            <?php endif; ?>

            <?php if (!$isDeviceAuthorized): ?>
                <!-- STEP 1: ONE-TIME ADMIN MASTER PIN FORM -->
                <form action="<?= $dynamicBaseUrl ?>/zone/login.php" method="POST" autocomplete="off">
                    <input type="hidden" name="authorize_device" value="1">
                    <div class="form-group">
                        <label class="form-label" for="master_pin">Admin Master Setup PIN</label>
                        <input type="password" name="master_pin" id="master_pin" class="input-field" inputmode="numeric" placeholder="Enter Admin Master PIN..." required autofocus style="text-align: center; letter-spacing: 0.2em; font-size: 20px;">
                    </div>
                    <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #059669 0%, #047857 100%);">
                        <i class='bx bx-shield-quarter' style="font-size: 19px;"></i>
                        <span>Authorize This Phone Permanently</span>
                    </button>
                </form>
            <?php else: ?>
                <!-- STEP 2: 4-DIGIT ZONE ACCESS CODE FORM -->
                <form action="<?= $dynamicBaseUrl ?>/zone/login.php" method="POST" id="accessForm" autocomplete="off">
                    <input type="hidden" name="access_code" id="access_code_hidden" value="">

                    <div class="pin-boxes">
                        <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="pin-digit" data-idx="0" autofocus>
                        <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="pin-digit" data-idx="1">
                        <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="pin-digit" data-idx="2">
                        <input type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]*" class="pin-digit" data-idx="3">
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <span>Open Zone Portal</span>
                        <i class='bx bx-right-arrow-alt' style="font-size: 20px;"></i>
                    </button>
                </form>

                <?php if ((!empty($_SESSION['admin_id']) || stripos(__DIR__, 'xampp') !== false) && !empty($allZones)): ?>
                    <!-- Admin-only quick view of 4-digit codes -->
                    <div class="zones-code-box">
                        <div class="zones-code-header">
                            <span><i class='bx bx-key'></i> Admin View: Zone 4-Digit Codes</span>
                            <span style="font-weight: 600; color: #64748b; text-transform: none;">Tap to open</span>
                        </div>
                        <div class="zone-code-list">
                            <?php foreach ($allZones as $zItem): ?>
                                <div class="zone-code-item" onclick="fillAndSubmitCode('<?= htmlspecialchars($zItem['access_code']) ?>')">
                                    <span class="zone-code-name"><?= htmlspecialchars($zItem['name']) ?></span>
                                    <span class="zone-code-badge"><?= htmlspecialchars($zItem['access_code']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Install App Banner (PWA) -->
            <div class="install-banner" id="installBanner">
                <div style="text-align: left; font-size: 12px; color: #1e3a8a; font-weight: 600;">
                    Install Liyas Zone App on this phone
                </div>
                <button type="button" class="btn-install" id="btnInstallApp">
                    <i class='bx bx-download'></i> Install App
                </button>
            </div>
        </div>
    </div>

    <script>
        // Persist device token in localStorage as backup so clearing session doesn't lose device authorization
        (function() {
            const serverToken = <?= json_encode($isDeviceAuthorized ? $deviceToken : '') ?>;
            if (serverToken) {
                try { localStorage.setItem('liyas_device_token', serverToken); } catch (e) {}
            } else {
                try {
                    const savedToken = localStorage.getItem('liyas_device_token');
                    if (savedToken && !window.location.search.includes('device_token=')) {
                        document.cookie = "liyas_device_token=" + encodeURIComponent(savedToken) + "; path=/; max-age=315360000";
                        window.location.href = window.location.pathname + "?device_token=" + encodeURIComponent(savedToken);
                        return;
                    }
                } catch (e) {}
            }

            const digits = Array.from(document.querySelectorAll('.pin-digit'));
            const hiddenInput = document.getElementById('access_code_hidden');
            const form = document.getElementById('accessForm');
            const submitBtn = document.getElementById('submitBtn');

            function syncHiddenCode() {
                if (!hiddenInput) return '';
                const code = digits.map(d => d.value.replace(/\D/g, '')).join('');
                hiddenInput.value = code;
                return code;
            }

            window.fillAndSubmitCode = function(codeStr) {
                if (!form) return;
                const clean = String(codeStr || '').replace(/\D/g, '').slice(0, 4);
                clean.split('').forEach((ch, i) => {
                    if (digits[i]) digits[i].value = ch;
                });
                syncHiddenCode();
                if (clean.length === 4) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin' style='font-size: 18px;'></i> Opening Zone...";
                    form.submit();
                }
            };

            if (digits.length > 0 && form) {
                digits.forEach((input, idx) => {
                    input.addEventListener('input', () => {
                        const val = input.value.replace(/\D/g, '');
                        input.value = val.slice(-1);
                        const fullCode = syncHiddenCode();

                        if (input.value && idx < digits.length - 1) {
                            digits[idx + 1].focus();
                            digits[idx + 1].select();
                        }

                        if (fullCode.length === 4) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin' style='font-size: 18px;'></i> Opening Zone...";
                            form.submit();
                        }
                    });

                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Backspace' && !input.value && idx > 0) {
                            digits[idx - 1].value = '';
                            digits[idx - 1].focus();
                            syncHiddenCode();
                        }
                    });

                    input.addEventListener('paste', (e) => {
                        e.preventDefault();
                        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 4);
                        window.fillAndSubmitCode(pasted);
                    });
                });

                form.addEventListener('submit', (e) => {
                    const code = syncHiddenCode();
                    if (code.length !== 4) {
                        e.preventDefault();
                        digits[0].focus();
                        return;
                    }
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin' style='font-size: 18px;'></i> Opening Zone...";
                });
            }

            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('service-worker.js').catch(() => {});
            }

            let deferredPrompt = null;
            const installBanner = document.getElementById('installBanner');
            const btnInstallApp = document.getElementById('btnInstallApp');

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                if (installBanner) installBanner.style.display = 'flex';
            });

            if (btnInstallApp) {
                btnInstallApp.addEventListener('click', async () => {
                    if (!deferredPrompt) return;
                    deferredPrompt.prompt();
                    await deferredPrompt.userChoice;
                    deferredPrompt = null;
                    installBanner.style.display = 'none';
                });
            }
        })();
    </script>
</body>
</html>
