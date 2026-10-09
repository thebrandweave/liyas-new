<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name, slug FROM zones WHERE id = ?");
        $stmt->execute([$id]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($zone) {
            // Check if zone has orders
            $orderCheck = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE zone_id = ?");
            $orderCheck->execute([$id]);
            $orderCount = (int)$orderCheck->fetchColumn();

            if ($orderCount > 0) {
                $_SESSION['error_message'] = "Cannot delete '{$zone['name']}' because it has {$orderCount} orders associated with it. You can set its status to Inactive instead.";
            } else {
                $del = $pdo->prepare("DELETE FROM zones WHERE id = ?");
                $del->execute([$id]);

                quickLog($pdo, 'delete', 'zone', $id, "Deleted zone: {$zone['name']} (/{$zone['slug']})");
                $_SESSION['success_message'] = "Zone '{$zone['name']}' deleted successfully.";
            }
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting zone: " . $e->getMessage();
    }
}

header("Location: index.php");
exit;
