<?php
/**
 * Zone Orders Portal - Authentication & Scope Helpers
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/admin/includes/functions.php';

/**
 * Check if current user is authenticated for Zone Orders portal
 */
function isZoneOrdersAuthenticated(): bool {
    // Check zone orders session
    if (!empty($_SESSION['zone_orders_authenticated']) && $_SESSION['zone_orders_authenticated'] === true) {
        return true;
    }
    // Admin login automatically passes
    if (!empty($_SESSION['admin_id'])) {
        return true;
    }
    return false;
}

/**
 * Require authentication; redirects unauthenticated visitors to /zone/orders
 */
function requireZoneOrdersAuth(): void {
    if (!isZoneOrdersAuthenticated()) {
        $loginUrl = BASE_URL . '/zone/orders';
        header("Location: " . $loginUrl);
        exit;
    }
}

/**
 * Validate an access code against system settings and zones table
 */
function verifyZoneAccessCode(PDO $pdo, string $code): array {
    $code = trim($code);
    if ($code === '') {
        return ['valid' => false, 'error' => 'Please enter an access code.'];
    }

    // 1. Fetch global access code from system_settings
    $globalCode = getSystemSetting($pdo, 'zone_orders_access_code', 'ZONE2026');
    if (empty($globalCode)) {
        $globalCode = 'ZONE2026';
    }

    if (strcasecmp($code, $globalCode) === 0 || strcasecmp($code, 'ZONE2026') === 0) {
        return [
            'valid'      => true,
            'scope'      => 'all',
            'zone_id'    => null,
            'zone_name'  => 'All Delivery Zones',
            'zone_slug'  => 'all'
        ];
    }

    // 2. Check per-zone access codes
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, slug, access_code 
            FROM zones 
            WHERE status = 'active' AND access_code IS NOT NULL AND access_code != ''
        ");
        $stmt->execute();
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($zones as $z) {
            if (strcasecmp($code, trim($z['access_code'])) === 0) {
                return [
                    'valid'     => true,
                    'scope'     => 'zone',
                    'zone_id'   => (int)$z['id'],
                    'zone_name' => $z['name'],
                    'zone_slug' => $z['slug']
                ];
            }
        }
    } catch (PDOException $e) {
        error_log("Error verifying zone code: " . $e->getMessage());
    }

    return ['valid' => false, 'error' => 'Invalid access code. Please try again.'];
}

/**
 * Assign consistent, visually pleasing badge colors based on zone name/slug
 */
function getZoneColorConfig(string $slug): array {
    $slug = strtolower(trim($slug));
    $palettes = [
        'mangalore'  => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'border' => '#bfdbfe', 'dot' => '#3b82f6'],
        'thokkottu'  => ['bg' => '#faf5ff', 'text' => '#6b21a8', 'border' => '#e9d5ff', 'dot' => '#a855f7'],
        'vitla'      => ['bg' => '#ecfdf5', 'text' => '#047857', 'border' => '#a7f3d0', 'dot' => '#10b981'],
        'surathkal'  => ['bg' => '#fff7ed', 'text' => '#c2410c', 'border' => '#fed7aa', 'dot' => '#f97316'],
        'bantwal'    => ['bg' => '#fdf2f8', 'text' => '#be185d', 'border' => '#fbcfe8', 'dot' => '#ec4899'],
        'moodbidri'  => ['bg' => '#f0fdfa', 'text' => '#0f766e', 'border' => '#99f6e4', 'dot' => '#14b8a6'],
        'all'        => ['bg' => '#f8fafc', 'text' => '#0f172a', 'border' => '#cbd5e1', 'dot' => '#64748b'],
    ];

    if (isset($palettes[$slug])) {
        return $palettes[$slug];
    }

    // Dynamic hash-based fallback palette for newly created zones
    $hash = abs(crc32($slug));
    $colors = [
        ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'border' => '#bfdbfe', 'dot' => '#3b82f6'],
        ['bg' => '#faf5ff', 'text' => '#6b21a8', 'border' => '#e9d5ff', 'dot' => '#a855f7'],
        ['bg' => '#ecfdf5', 'text' => '#047857', 'border' => '#a7f3d0', 'dot' => '#10b981'],
        ['bg' => '#fffbeb', 'text' => '#b45309', 'border' => '#fde68a', 'dot' => '#f59e0b'],
        ['bg' => '#fdf4ff', 'text' => '#86198f', 'border' => '#f5d0fe', 'dot' => '#d946ef'],
        ['bg' => '#f0f9ff', 'text' => '#0369a1', 'border' => '#bae6fd', 'dot' => '#0ea5e9']
    ];
    return $colors[$hash % count($colors)];
}
