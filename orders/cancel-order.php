<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

$stmt = $pdo->prepare("
    UPDATE orders
    SET status = 'cancelled'
    WHERE order_id = ?
    AND user_id = ?
    AND status IN ('pending','processing')
");

$stmt->execute([
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