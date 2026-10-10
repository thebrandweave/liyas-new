<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once dirname(dirname(__DIR__)) . '/config/config.php';

unset(
    $_SESSION['zone_orders_authenticated'],
    $_SESSION['zone_orders_scope'],
    $_SESSION['zone_orders_zone_id'],
    $_SESSION['zone_orders_zone_name'],
    $_SESSION['zone_orders_zone_slug'],
    $_SESSION['zone_orders_auth_time'],
    $_SESSION['zone_orders_code']
);

$redirectUrl = BASE_URL . '/zone/orders?logged_out=1';
header("Location: " . $redirectUrl);
exit;
