<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userId  = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

if ($orderId > 0 && !empty($reason)) {
    // Update database status, reason, and cancellation timestamp
    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = 'cancelled',
            cancellation_reason = ?,
            cancelled_at = NOW(),
            updated_at = NOW()
        WHERE order_id = ?
          AND user_id = ?
          AND status IN ('pending','processing')
    ");

    $stmt->execute([
        $reason,
        $orderId,
        $userId
    ]);
}

// Redirect back to the details view page so they instantly see the dynamic state change
echo "
<script>
alert('Order cancelled successfully');
window.location.href = 'view-details.php?id=" . $orderId . "';
</script>
";
exit();