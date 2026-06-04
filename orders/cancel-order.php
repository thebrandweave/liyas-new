<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';

echo $_SERVER['REQUEST_METHOD'];
exit;

echo "Session User ID: ";
echo $_SESSION['user_id'] ?? 'NOT SET';

echo "<br><br>";

echo "POST Data:<br>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

$userId = (int)($_SESSION['user_id'] ?? 0);
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

echo "Order ID: $orderId <br>";
echo "User ID: $userId <br>";
echo "Reason: $reason <br><br>";

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