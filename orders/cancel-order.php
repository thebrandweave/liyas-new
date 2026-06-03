<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$userId = $_SESSION['user_id'];

$orderId = (int)($_POST['order_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

echo "Order ID: ".$orderId."<br>";
echo "Reason: ".$reason."<br>";

$stmt = $pdo->prepare("
    UPDATE orders
    SET
        status = 'cancelled',
        cancellation_reason = ?,
        cancelled_at = NOW()
    WHERE order_id = ?
    AND user_id = ?
    AND status IN ('pending','processing')
");

$stmt->execute([$reason, $orderId, $userId]);

echo "Affected Rows: ".$stmt->rowCount();
exit;