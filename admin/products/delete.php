<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        $chkOrder = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE product_id = ?");
        $chkOrder->execute([$id]);
        $orderCount = (int)$chkOrder->fetchColumn();

        if ($orderCount > 0) {
            $_SESSION['error_message'] = "Cannot delete this product because it has {$orderCount} orders associated with it. You can mark it as Inactive instead.";
        } else {
            $checkStmt = $pdo->prepare("SELECT name, product_name, image FROM products WHERE product_id = ?");
            $checkStmt->execute([$id]);
            $product_data = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($product_data) {
                if (!empty($product_data['image'])) {
                    $full_path = __DIR__ . '/../uploads/products/' . $product_data['image'];
                    if (file_exists($full_path)) unlink($full_path);
                }
                $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]);
                quickLog($pdo, 'delete', 'product', $id, "Deleted product: " . ($product_data['product_name'] ?: $product_data['name']));
                $_SESSION['success_message'] = "Product deleted successfully!";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting product: " . $e->getMessage();
    }
}

header("Location: index.php");
exit;
