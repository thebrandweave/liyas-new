<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT order_number FROM orders WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ord) {
            // Delete order payments, receipts, order items
            $pdo->prepare("DELETE FROM order_payments WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("DELETE FROM receipts WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("DELETE FROM orders WHERE order_id = ?")->execute([$order_id]);

            quickLog($pdo, 'delete', 'order', $order_id, "Deleted order " . ($ord['order_number'] ?: ('#' . $order_id)));
            $_SESSION['success_message'] = "Order deleted successfully.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting order: " . $e->getMessage();
    }
}

header("Location: index.php");
exit;
