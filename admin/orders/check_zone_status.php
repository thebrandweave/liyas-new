<?php
/**
 * Real-time Zone Order Status Check Endpoint for Admin Orders Dashboard
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json');

$order_ids_raw = $_GET['order_ids'] ?? ($_POST['order_ids'] ?? '');
$order_ids = [];

if (is_string($order_ids_raw)) {
    $parts = explode(',', $order_ids_raw);
    foreach ($parts as $p) {
        $id = (int)trim($p);
        if ($id > 0) {
            $order_ids[] = $id;
        }
    }
} elseif (is_array($order_ids_raw)) {
    foreach ($order_ids_raw as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $order_ids[] = $id;
        }
    }
}

$statuses = [];

if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT 
            o.order_id,
            o.order_number,
            o.is_zone_read,
            o.zone_read_at,
            o.status,
            z.name as zone_name,
            z.slug as zone_slug
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        WHERE o.order_id IN ($placeholders)
    ");
    $stmt->execute($order_ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $formattedDate = '';
        if (!empty($r['zone_read_at'])) {
            $formattedDate = date('d M, h:i A', strtotime($r['zone_read_at']));
        }
        $statuses[(int)$r['order_id']] = [
            'order_id'       => (int)$r['order_id'],
            'order_number'   => $r['order_number'] ?: ('#' . $r['order_id']),
            'is_zone_read'   => (int)$r['is_zone_read'],
            'zone_read_at'   => $r['zone_read_at'],
            'zone_read_text' => $formattedDate,
            'zone_name'      => $r['zone_name'] ?: 'Unassigned',
            'status'         => $r['status']
        ];
    }
}

// Global total unchecked by zones (active orders)
$totalUncheckedStmt = $pdo->query("
    SELECT COUNT(*) 
    FROM orders
    WHERE zone_id IS NOT NULL AND zone_id > 0 AND is_zone_read = 0 AND status != 'cancelled'
");
$totalUnchecked = (int)$totalUncheckedStmt->fetchColumn();

echo json_encode([
    'success'         => true,
    'statuses'        => $statuses,
    'total_unchecked' => $totalUnchecked
]);
