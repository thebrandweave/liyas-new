<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

/* Optional: Store cancellation reason before deleting */
$stmt = $pdo->prepare("
    UPDATE orders
    SET
        status = 'cancelled',
        cancellation_reason = ?,
        cancelled_at = NOW()
    WHERE order_id = ?
    AND user_id = ?
");
$stmt->execute([
    $reason,
    $orderId,
    $userId
]);

/* Delete order items */
$stmt = $pdo->prepare("
    DELETE FROM order_items
    WHERE order_id = ?
");
$stmt->execute([$orderId]);

/* Delete order */
$stmt = $pdo->prepare("
    DELETE FROM orders
    WHERE order_id = ?
    AND user_id = ?
");
$stmt->execute([
    $orderId,
    $userId
]);

header("Location: index.php");
exit;