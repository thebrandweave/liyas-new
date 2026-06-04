<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    die("User not logged in");
}

echo "<pre>";
echo "REQUEST METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n\n";
print_r($_POST);
echo "</pre>";

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

$stmt = $pdo->prepare("
    UPDATE orders
    SET status = 'cancelled',
        cancellation_reason = ?,
        cancelled_at = NOW()
    WHERE order_id = ?
    AND user_id = ?
    AND status IN ('pending','processing')
");

$stmt->execute([
    $reason,
    $orderId,
    $userId
]);

echo "<h3>Rows Updated: " . $stmt->rowCount() . "</h3>";