
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

// Toggle this variable to switch environments
$is_live = false;

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
    define('DB_NAME_MAIN', 'liyas_inter');

    // Campaign Database (Added to prevent errors locally)
    define('DB_USER_CAMPAIGN', 'root');
    define('DB_PASS_CAMPAIGN', '');
    define('DB_NAME_CAMPAIGN', 'liyas_campaign');
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

} catch (PDOException $e) {
    // Log error internally
    error_log("Database Connection Failed: " . $e->getMessage());
   
    // If live, show generic message. If local, show details.
    if ($is_live) {
        die("System Error: Unable to connect to the database. Please try again later.");
    } else {
        die("Database Error: " . $e->getMessage());
    }
}

// ============================================
// 3. JWT & PATH CONFIGURATION
// ============================================
$JWT_SECRET = "super_secure_secret_987654321";
$JWT_EXPIRE = 3600;

if ($is_live) {
    define('BASE_URL', 'https://liyasinternational.com/');
} else {
    define('BASE_URL', 'http://localhost/liyas-mineral-water');
}

$ROOT_PATH = dirname(__DIR__);
define('UPLOAD_DIR', '/uploads/');
define('UPLOAD_DIR_SERVER', $ROOT_PATH . '/uploads/');

if (session_status() === PHP_SESSION_NONE) {
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
?>
