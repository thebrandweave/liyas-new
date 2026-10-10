<?php
/**
 * /zone/orders/dashboard/products/delete.php
 * Zone Orders Portal - Delete Product Endpoint
 */

require_once dirname(__DIR__, 2) . '/auth_helper.php';

// Enforce authentication
requireZoneOrdersAuth();

// Optional activity logger
$logger_file = dirname(__DIR__, 4) . '/admin/includes/activity_logger.php';
if (file_exists($logger_file)) {
    require_once $logger_file;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        $chkOrder = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE product_id = ?");
        $chkOrder->execute([$id]);
        $orderCount = (int)$chkOrder->fetchColumn();

        if ($orderCount > 0) {
            $_SESSION['error_message'] = "Cannot delete this product because it has {$orderCount} order(s) associated with it. You can mark it as Inactive instead.";
        } else {
            $checkStmt = $pdo->prepare("SELECT name, product_name, image FROM products WHERE product_id = ?");
            $checkStmt->execute([$id]);
            $product_data = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($product_data) {
                if (!empty($product_data['image'])) {
                    $upload_dir = dirname(__DIR__, 4) . '/admin/uploads/products/';
                    $full_path = $upload_dir . $product_data['image'];
                    if (file_exists($full_path)) {
                        @unlink($full_path);
                    }
                }
                $delStmt = $pdo->prepare("DELETE FROM products WHERE product_id = ?");
                $delStmt->execute([$id]);

                if (function_exists('quickLog')) {
                    quickLog($pdo, 'delete', 'product', $id, "Deleted product via Zone Portal: " . ($product_data['product_name'] ?: $product_data['name']));
                }
                $_SESSION['success_message'] = "Product deleted successfully!";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting product: " . $e->getMessage();
    }
}

header("Location: index.php");
exit;
