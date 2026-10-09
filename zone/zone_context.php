<?php
/**
 * Zone Context Resolver for Multi-Zone Delivery Portals
 * Automatically detects and validates zone slug from URL
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/admin/includes/functions.php';

// Determine zone slug
$zone_slug = trim($_GET['zone'] ?? '');

if (empty($zone_slug)) {
    // Fallback: Parse from REQUEST_URI
    $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $uriParts = array_values(array_filter(explode('/', trim($requestUri, '/'))));

    // Remove known folder names like 'liyas-new' or 'zone'
    $cleanedParts = [];
    foreach ($uriParts as $part) {
        if (!in_array($part, ['liyas-new', 'zone', 'public'])) {
            $cleanedParts[] = $part;
        }
    }

    if (!empty($cleanedParts)) {
        $zone_slug = $cleanedParts[0];
    }
}

// Sanitize slug
$zone_slug = strtolower(trim(preg_replace('/[^a-z0-9_-]/', '', $zone_slug)));

if ($zone_slug === 'index-temp') {
    include dirname(__DIR__) . '/index-temp.php';
    exit;
}

if (empty($zone_slug)) {
    http_response_code(404);
    echo renderZoneNotFound("No delivery zone specified.");
    exit;
}

// Fetch zone from database
try {
    $stmt = $pdo->prepare("SELECT * FROM zones WHERE slug = ?");
    $stmt->execute([$zone_slug]);
    $current_zone = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_zone) {
        // Fallback 1: check if a root script exists (e.g. /logout -> logout.php, /checkout -> checkout.php)
        $rootScript = dirname(__DIR__) . '/' . $zone_slug . '.php';
        if (file_exists($rootScript)) {
            include $rootScript;
            exit;
        }

        // Fallback 2: check if a folder index exists (e.g. /orders -> orders/index.php, /about -> about/index.php)
        $folderIndex = dirname(__DIR__) . '/' . $zone_slug . '/index.php';
        if (file_exists($folderIndex)) {
            include $folderIndex;
            exit;
        }

        http_response_code(404);
        echo renderZoneNotFound("Delivery Zone '/{$zone_slug}' does not exist.");
        exit;
    }

    if ($current_zone['status'] !== 'active') {
        http_response_code(403);
        echo renderZoneNotFound("Delivery Zone '{$current_zone['name']}' is currently inactive.");
        exit;
    }

    $zone_id = (int)$current_zone['id'];
    $zone_name = $current_zone['name'];

    // Security Check: Zone isolation
    // If user is logged in as delivery staff for a different zone, strictly block!
    if (isset($_SESSION['delivery_zone_id']) && (int)$_SESSION['delivery_zone_id'] !== $zone_id && !isset($_SESSION['admin_id'])) {
        http_response_code(403);
        die("<h3>Access Denied</h3><p>You are logged in for another delivery zone and cannot access orders for {$current_zone['name']}.</p>");
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

/**
 * Helper to build clean zone portal URLs
 */
function zone_url($slug, $action = '', $query = []) {
    $url = BASE_URL . '/' . $slug;
    if (!empty($action)) {
        $url .= '/' . ltrim($action, '/');
    }
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/**
 * 404 Zone Not Found Template
 */
function renderZoneNotFound($msg) {
    $homeUrl = BASE_URL;
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zone Not Found - Liyas International</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; color: #0f172a; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; text-align: center; }
        .box { background: #fff; padding: 40px 32px; border-radius: 20px; border: 1px solid #e2e8f0; max-width: 460px; width: 100%; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); }
        .icon-circle { width: 64px; height: 64px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 16px auto; }
        h1 { font-size: 22px; font-weight: 800; color: #0f172a; margin-bottom: 8px; }
        p { color: #64748b; font-size: 14px; margin-bottom: 24px; line-height: 1.5; }
        a { display: inline-flex; align-items: center; gap: 6px; background: #2563eb; color: #fff; padding: 12px 24px; border-radius: 12px; text-decoration: none; font-weight: 700; font-size: 14px; box-shadow: 0 4px 10px rgba(37,99,235,0.25); }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon-circle"><i class='bx bx-error-alt'></i></div>
        <h1>Zone Not Found</h1>
        <p>{$msg}</p>
        <a href="{$homeUrl}/admin/dashboard/index.php"><i class='bx bx-arrow-back'></i> Go to Admin Dashboard</a>
    </div>
</body>
</html>
HTML;
}
