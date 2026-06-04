<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

// Fixed: Added cancellation_reason and cancelled_at fields to the query
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

echo "
<script>
alert('Order cancelled successfully');
window.location.href='index.php';
</script>
";
exit;