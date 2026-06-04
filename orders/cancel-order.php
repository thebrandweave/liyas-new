<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId  = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

if ($orderId > 0 && !empty($reason)) {
    // Perform update check
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'cancelled',
            cancellation_reason = ?,
            cancelled_at = NOW(),
            updated_at = NOW()
        WHERE order_id = ?
          AND user_id = ?
          AND status IN ('pending', 'processing')
    ");

    $stmt->execute([
        $reason,
        $orderId,
        $userId
    ]);
}

// Redirect using custom clean routing fallback parameters
echo "
<script>
alert('Order cancelled successfully');
window.location.href = 'view-details?id=" . $orderId . "';
</script>
";
exit();