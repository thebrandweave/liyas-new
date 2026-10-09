<?php
/**
 * Real-time New Orders Polling & Acknowledgment API for Zone Portal
 */
require_once __DIR__ . '/zone_context.php';

header('Content-Type: application/json');

// Handle single order acknowledgment / mark-as-read via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $order_id = (int)($_POST['order_id'] ?? 0);

    if ($action === 'mark_read' && $order_id > 0) {
        $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE order_id = ? AND zone_id = ?");
        $stmt->execute([$order_id, $zone_id]);
        echo json_encode([
            'success' => true,
            'order_id' => $order_id,
            'message' => 'Order marked as checked'
        ]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE zone_id = ? AND is_zone_read = 0");
        $stmt->execute([$zone_id]);
        echo json_encode([
            'success' => true,
            'message' => 'All orders marked as checked'
        ]);
        exit;
    }
}

// Fetch all active unread orders for this zone
try {
    $stmt = $pdo->prepare("
        SELECT 
            o.order_id,
            o.order_number,
            o.shop_name,
            o.customer_name,
            o.location,
            o.phone,
            o.quantity,
            o.total_amount,
            o.created_at,
            COALESCE(p.product_name, p.name, 'Drinking Water') AS product_name
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.product_id
        WHERE o.zone_id = ? AND o.is_zone_read = 0 AND o.status != 'cancelled'
        ORDER BY o.order_id DESC
    ");
    $stmt->execute([$zone_id]);
    $unread_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $latest_order_id = 0;
    if (!empty($unread_orders)) {
        $latest_order_id = (int)$unread_orders[0]['order_id'];
    }

    echo json_encode([
        'success'         => true,
        'zone_id'         => $zone_id,
        'zone_slug'       => $zone_slug,
        'zone_name'       => $zone_name,
        'unread_count'    => count($unread_orders),
        'latest_order_id' => $latest_order_id,
        'orders'          => $unread_orders
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
