<?php
echo "METHOD = " . $_SERVER['REQUEST_METHOD'];
exit;
echo "<pre>";
print_r($_POST);
echo "</pre>";
exit;

require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    exit('Unauthorized');
}

$userId  = (int)$_SESSION['user_id'];
$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = trim($_POST['reason'] ?? '');

echo "<pre>";

echo "METHOD = " . $_SERVER['REQUEST_METHOD'] . "\n\n";

print_r($_POST);

echo "</pre>";
exit;

try {

    $stmt = $pdo->prepare("
        UPDATE orders
        SET
            status = 'cancelled',
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
    echo "<pre>";
echo "Order ID: ".$orderId."\n";
echo "User ID: ".$userId."\n";
echo "Rows Updated: ".$stmt->rowCount()."\n";
print_r($stmt->errorInfo());
echo "</pre>";
exit;

    if ($stmt->rowCount() > 0) {

        echo "
        <script>
        alert('Order cancelled successfully');
        window.location.href='view-details?id={$orderId}';
        </script>";
    } else {

        echo "
        <script>
        alert('Order could not be cancelled.');
        window.location.href='view-details?id={$orderId}';
        </script>";
    }

} catch(PDOException $e) {

    die($e->getMessage());

}