<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth_check.php';

if (isset($_SESSION['admin_role']) && strtolower($_SESSION['admin_role']) === 'staff') {
    header("Location: orders/index.php");
    exit;
}

header("Location: dashboard/index.php");
exit;