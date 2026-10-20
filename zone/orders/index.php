<?php
/**
 * /zone/orders - Multi-Zone Orders Gateway
 * Direct redirect to the live multi-zone orders dashboard.
 * Access code / login authentication requirement has been removed.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';

// Direct redirect to the dashboard without requiring an access code
header("Location: " . BASE_URL . '/zone/orders/dashboard/');
exit;
