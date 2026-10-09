<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId  = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

if ($orderId > 0 && !empty($reason)) {
    try {
        // Fetch order details before updating to know which product and quantity to restore
        $chk = $pdo->prepare("SELECT product_id, quantity FROM orders WHERE order_id = ? AND user_id = ? AND status IN ('pending', 'processing')");
        $chk->execute([$orderId, $userId]);
        $ordData = $chk->fetch(PDO::FETCH_ASSOC);

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

        if ($stmt->rowCount() > 0) {
            // Restore stock for cancelled order
            if ($ordData && !empty($ordData['product_id'])) {
                adjustProductStock($pdo, (int)$ordData['product_id'], +(int)$ordData['quantity'], "Customer cancelled order #{$orderId}");
            }
            $itemStmt = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$orderId]);
            while ($it = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
                if (!$ordData || (int)$it['product_id'] !== (int)$ordData['product_id']) {
                    adjustProductStock($pdo, (int)$it['product_id'], +(int)$it['quantity'], "Customer cancelled order #{$orderId}");
                }
            }

            echo "
            <script>
            alert('Order cancelled successfully');
            window.location.href = 'view-details?id=" . $orderId . "';
            </script>
            ";
        } else {
            echo "
            <script>
            alert('Order could not be cancelled. It may already be shipped, delivered, or already cancelled.');
            window.location.href = 'view-details?id=" . $orderId . "';
            </script>
            ";
        }
    } catch (PDOException $e) {
        die("Database Error: " . htmlspecialchars($e->getMessage()));
    }
} else {
    echo "
    <script>
    alert('Invalid order ID or cancellation reason.');
    window.location.href = '/orders/';
    </script>
    ";
}
exit();