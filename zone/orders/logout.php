<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(dirname(__DIR__)) . '/config/config.php';

// If full admin logout is requested
if (isset($_GET['admin']) && $_GET['admin'] == '1') {
    session_unset();
    session_destroy();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    header("Location: " . BASE_URL . '/zone/orders/?logged_out=1');
    exit;
}

// Clear all zone orders portal session variables
unset(
    $_SESSION['zone_orders_authenticated'],
    $_SESSION['zone_orders_scope'],
    $_SESSION['zone_orders_zone_id'],
    $_SESSION['zone_orders_zone_name'],
    $_SESSION['zone_orders_zone_slug'],
    $_SESSION['zone_orders_auth_time'],
    $_SESSION['zone_orders_code']
);

// Explicitly lock the zone portal so admin session does not auto-bypass without entering access code
$_SESSION['zone_orders_locked'] = true;

$redirectUrl = BASE_URL . '/zone/orders/?logged_out=1';
header("Location: " . $redirectUrl);
exit;
