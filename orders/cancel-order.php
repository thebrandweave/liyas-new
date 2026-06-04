<?php
require_once '../config/config.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userId  = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

if ($orderId > 0 && !empty($reason)) {
    // Update status, reasons, and timestamps
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

// Fixed: Redirects using absolute pathing to prevent "Order not found" routing errors
echo "
<script>
alert('Order cancelled successfully');
window.location.href = '/orders/view-details.php?id=" . $orderId . "';
</script>
";
exit();