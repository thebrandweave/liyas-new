<?php
/**
 * admin/qr-rewards/shop-rewards.php
 * Permanent redirect to dedicated /admin/shop-rewards/index.php
 */
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: ../shop-rewards/index.php" . $query, true, 301);
exit;
