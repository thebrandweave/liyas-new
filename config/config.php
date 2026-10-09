<?php

define('ROOT_PATH', dirname(__DIR__)); 

// ✅ Manual Firebase JWT include
require_once __DIR__ . '/../admin/includes/php-jwt/JWTExceptionWithPayloadInterface.php';
require_once __DIR__ . '/../admin/includes/php-jwt/BeforeValidException.php';
require_once __DIR__ . '/../admin/includes/php-jwt/ExpiredException.php';
require_once __DIR__ . '/../admin/includes/php-jwt/SignatureInvalidException.php';
require_once __DIR__ . '/../admin/includes/php-jwt/Key.php';
require_once __DIR__ . '/../admin/includes/php-jwt/JWT.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ============================================
// 1. DATABASE CONFIGURATION
// ============================================

// Auto-detect environment (local vs live production)
$is_local = (
    (isset($_SERVER['HTTP_HOST']) && (
        in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1']) || 
        strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0
    )) ||
    (php_sapi_name() === 'cli' && (strpos(__DIR__, 'xampp') !== false || strpos(__DIR__, 'htdocs') !== false))
);
$is_live = !$is_local;

if ($is_live) {
    // -------------------
    // LIVE PRODUCTION
    // -------------------
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3306);

    // Main Database Credentials
    define('DB_USER_MAIN', 'u232955123_liyas');
    define('DB_PASS_MAIN', 'Brandweave@24');
    define('DB_NAME_MAIN', 'u232955123_liyas_inter');

    // Campaign Database Credentials
    define('DB_USER_CAMPAIGN', 'u232955123_campaign');
    define('DB_PASS_CAMPAIGN', 'Brandweave@24');
    define('DB_NAME_CAMPAIGN', 'u232955123_liyas_campaign');

} else {
    // -------------------
    // LOCAL DEVELOPMENT (XAMPP)
    // -------------------
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3306);

    // Main Database (Fixed naming to match connection logic)
    define('DB_USER_MAIN', 'root');
    define('DB_PASS_MAIN', '');
    define('DB_NAME_MAIN', 'liyas');

    // Campaign Database (Added to prevent errors locally)
    define('DB_USER_CAMPAIGN', 'root');
    define('DB_PASS_CAMPAIGN', '');
    define('DB_NAME_CAMPAIGN', 'liyas_camp');
}

// ============================================
// 2. PDO CONNECTION LOGIC
// ============================================
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

try {
    // 1. Connect to Main Website Database (Admins, Products, Users)
    // Using DB_USER_MAIN and DB_PASS_MAIN
    $dsn_main = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME_MAIN . ";charset=utf8mb4";
    $pdo = new PDO($dsn_main, DB_USER_MAIN, DB_PASS_MAIN, $options);
   
    // 2. Connect to Campaign Database (Contests, Submissions)
    // Using DB_USER_CAMPAIGN and DB_PASS_CAMPAIGN
    $dsn_campaign = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME_CAMPAIGN . ";charset=utf8mb4";
    $pdo_campaign = new PDO($dsn_campaign, DB_USER_CAMPAIGN, DB_PASS_CAMPAIGN, $options);
   
    // Global Timezone Settings
    date_default_timezone_set('Asia/Kolkata');
    $pdo->exec("SET time_zone = '+05:30'");
    $pdo_campaign->exec("SET time_zone = '+05:30'");

    // Auto-sync warehouse schema if needed
    require_once __DIR__ . '/schema_sync.php';
    ensureWarehouseSchema($pdo);

} catch (PDOException $e) {
    // Log error internally
    error_log("Database Connection Failed: " . $e->getMessage());
   
if ($is_live) {
    error_log("Database Connection Failed: " . $e->getMessage());

    http_response_code(500);

    include ROOT_PATH . '/error-pages/db-error.php';
    exit();
}
}

// ============================================
// 3. JWT & PATH CONFIGURATION
// ============================================
$JWT_SECRET = "super_secure_secret_987654321";
$JWT_EXPIRE = 3600;

if ($is_live) {
    define('BASE_URL', 'https://liyasinternational.com');
} else {
    define('BASE_URL', 'http://localhost/liyas-new');
}

$ROOT_PATH = dirname(__DIR__);
define('UPLOAD_DIR', '/uploads/');
define('UPLOAD_DIR_SERVER', $ROOT_PATH . '/uploads/');

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// ============================================
// 4. HELPER FUNCTIONS
// ============================================

/**
 * Returns the Main Website PDO Instance
 */
function getDB() {
    global $pdo;
    return $pdo;
}

/**
 * Returns the Campaign/Contest PDO Instance
 */
function getCampaignDB() {
    global $pdo_campaign;
    return $pdo_campaign;
}

/**
 * Verification helper for Admin Authentication
 * Checks if the Admin exists in the MAIN DB
 */
function verifyAdminSession() {
    if (!isset($_SESSION['admin_id'])) {
        return false;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT admin_id FROM admins WHERE admin_id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ? true : false;
}

/**
 * MYSQLI Fallback (Main DB only)
 */
function getMysqliConnection() {
    // Note: This relies on constants defined in the config section above
    $mysqli = new mysqli(DB_HOST, DB_USER_MAIN, DB_PASS_MAIN, DB_NAME_MAIN, DB_PORT);
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

/**
 * Adjust product warehouse stock (case_stock and stock)
 * @param PDO $pdo
 * @param int $productId
 * @param int $delta Negative to reduce (e.g. order placed), positive to restore (e.g. order cancelled)
 * @param string $reason Optional description
 * @return bool
 */
function adjustProductStock(PDO $pdo, int $productId, int $delta, string $reason = ''): bool {
    if ($productId <= 0 || $delta === 0) {
        return false;
    }
    try {
        if ($delta > 0) {
            $stmt = $pdo->prepare("
                UPDATE products 
                SET case_stock = case_stock + ?,
                    stock = stock + ?,
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            return $stmt->execute([$delta, $delta, $productId]);
        } else {
            $reduceBy = abs($delta);
            $stmt = $pdo->prepare("
                UPDATE products 
                SET case_stock = GREATEST(0, case_stock - ?),
                    stock = GREATEST(0, stock - ?),
                    updated_at = NOW()
                WHERE product_id = ?
            ");
            return $stmt->execute([$reduceBy, $reduceBy, $productId]);
        }
    } catch (PDOException $e) {
        error_log("adjustProductStock error for product {$productId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all order items for an order with full product details.
 * Falls back to orders table record if order_items has not been populated.
 * @param PDO $pdo
 * @param int $orderId
 * @return array
 */
function getOrderItems(PDO $pdo, int $orderId): array {
    if ($orderId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT 
            oi.order_item_id,
            oi.order_id,
            oi.product_id,
            oi.quantity,
            oi.price_at_purchase,
            (oi.quantity * oi.price_at_purchase) AS line_total,
            p.name,
            p.product_name,
            p.net_content,
            p.net_content_unit,
            p.case_price,
            p.price,
            p.case_stock,
            p.stock
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback for legacy single-item orders
    if (empty($items)) {
        $fallbackStmt = $pdo->prepare("
            SELECT 
                0 AS order_item_id,
                o.order_id,
                o.product_id,
                o.quantity,
                COALESCE(o.unit_price, p.case_price, p.price, 0) AS price_at_purchase,
                (o.quantity * COALESCE(o.unit_price, p.case_price, p.price, 0)) AS line_total,
                p.name,
                p.product_name,
                p.net_content,
                p.net_content_unit,
                p.case_price,
                p.price,
                p.case_stock,
                p.stock
            FROM orders o
            LEFT JOIN products p ON o.product_id = p.product_id
            WHERE o.order_id = ? AND o.product_id IS NOT NULL AND o.product_id > 0
        ");
        $fallbackStmt->execute([$orderId]);
        $fb = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
        if ($fb) {
            $items[] = $fb;
        }
    }

    return $items;
}

/**
 * Handle stock adjustments when an order's status changes:
 * - If order is cancelled: restore stock (+quantity for each product item)
 * - If order is un-cancelled (restored): deduct stock (-quantity for each product item)
 */
function handleOrderStatusStockChange(PDO $pdo, int $orderId, string $oldStatus, string $newStatus): void {
    if ($orderId <= 0 || empty($oldStatus) || empty($newStatus) || strtolower($oldStatus) === strtolower($newStatus)) {
        return;
    }
    $oldStatus = strtolower(trim($oldStatus));
    $newStatus = strtolower(trim($newStatus));

    // Fetch all items belonging to this order
    $items = getOrderItems($pdo, $orderId);

    if (empty($items)) {
        return;
    }

    // 1. Moving to cancelled from an active status -> RESTORE stock
    if ($oldStatus !== 'cancelled' && $newStatus === 'cancelled') {
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                adjustProductStock($pdo, $pid, +$qty, "Order #{$orderId} cancelled");
            }
        }
    }
    // 2. Moving from cancelled to an active status -> RE-DEDUCT stock
    elseif ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);
            if ($pid > 0 && $qty > 0) {
                adjustProductStock($pdo, $pid, -$qty, "Order #{$orderId} un-cancelled to {$newStatus}");
            }
        }
    }
}
?>
