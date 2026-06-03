<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId = $_SESSION['user_id'];

$orderId = (int)($_POST['order_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

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

$stmt->execute([
    $reason,
    $orderId,
    $userId
]);

header("Location: index.php?id=".$orderId);
exit;