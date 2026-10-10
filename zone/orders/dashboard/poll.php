<?php
/**
 * Real-time Polling & Order Action API for Zone Orders Live Dashboard
 * Responds with JSON data for incoming orders, status changes, and metrics
 */
require_once dirname(__DIR__) . '/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure request is authorized
if (!isZoneOrdersAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'unauthenticated' => true, 'error' => 'Session expired. Please log in again.']);
    exit;
}

$action = $_REQUEST['action'] ?? 'poll';
$selectedZone = trim($_REQUEST['zone'] ?? 'all'); // 'all' or zone_id or zone_slug

try {
    // ----------------------------------------------------
    // Action 1: Mark Single Order as Checked / Read
    // ----------------------------------------------------
    if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id > 0) {
            $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE order_id = ?");
            $stmt->execute([$order_id]);
            echo json_encode(['success' => true, 'order_id' => $order_id, 'message' => "Order #{$order_id} marked as checked."]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
        exit;
    }

    // ----------------------------------------------------
    // Action 2: Mark All Orders as Read
    // ----------------------------------------------------
    if ($action === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($selectedZone !== 'all' && !empty($selectedZone)) {
            if (is_numeric($selectedZone)) {
                $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE zone_id = ? AND is_zone_read = 0");
                $stmt->execute([(int)$selectedZone]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE orders o
                    JOIN zones z ON o.zone_id = z.id
                    SET o.is_zone_read = 1, o.zone_read_at = NOW()
                    WHERE z.slug = ? AND o.is_zone_read = 0
                ");
                $stmt->execute([$selectedZone]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE is_zone_read = 0");
            $stmt->execute();
        }
        echo json_encode(['success' => true, 'message' => 'All pending orders marked as checked.']);
        exit;
    }

    // ----------------------------------------------------
    // Action 3: Quick Status Update
    // ----------------------------------------------------
    if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');
        $allowed = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

        if ($order_id > 0 && in_array($new_status, $allowed)) {
            $oldStmt = $pdo->prepare("SELECT status, shop_name, customer_name, phone FROM orders WHERE order_id = ?");
            $oldStmt->execute([$order_id]);
            $oData = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if (!$oData) {
                echo json_encode(['success' => false, 'error' => 'Order not found.']);
                exit;
            }

            $old_status = $oData['status'] ?: 'pending';

            $upStmt = $pdo->prepare("
                UPDATE orders 
                SET status = ?, is_zone_read = 1, zone_read_at = COALESCE(zone_read_at, NOW()), updated_at = NOW() 
                WHERE order_id = ?
            ");
            $upStmt->execute([$new_status, $order_id]);

            // Adjust product inventory stock automatically
            handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);

            // Update shop reward progress if delivered or cancelled
            if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
                $shopName = $oData['shop_name'] ?: ($oData['customer_name'] ?: '');
                if ($shopName !== '') {
                    updateShopRewardProgress($pdo, $shopName, $oData['phone'] ?? '');
                }
            }

            // Return fresh metrics
            $freshMetrics = getLiveMetrics($pdo, $selectedZone);

            echo json_encode([
                'success'     => true,
                'order_id'    => $order_id,
                'old_status'  => $old_status,
                'new_status'  => $new_status,
                'badge_class' => getStatusBadgeClass($new_status),
                'message'     => "Order #{$order_id} status updated to " . ucfirst($new_status),
                'metrics'     => $freshMetrics
            ]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Invalid status or order ID.']);
        exit;
    }

    // ----------------------------------------------------
    // Action 4: Get Full Order Details (for Quick Modal)
    // ----------------------------------------------------
    if ($action === 'get_order_details') {
        $order_id = (int)($_GET['order_id'] ?? 0);
        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT 
                o.*,
                z.name AS zone_name,
                z.slug AS zone_slug,
                p.product_name,
                p.name AS fallback_product_name,
                p.case_price,
                p.net_content,
                p.net_content_unit,
                r.id AS receipt_id,
                r.bill_number,
                r.delivered_quantity,
                r.cash_amount AS receipt_cash,
                r.credited_amount AS receipt_credit,
                r.due_amount AS receipt_due,
                r.payment_type AS receipt_pay_type,
                r.notes AS receipt_notes
            FROM orders o
            LEFT JOIN zones z ON o.zone_id = z.id
            LEFT JOIN products p ON o.product_id = p.product_id
            LEFT JOIN receipts r ON o.order_id = r.order_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        $items = getOrderItems($pdo, $order_id);
        $order['items'] = $items;
        $order['formatted_date'] = formatIST($order['created_at']);
        $order['badge_class'] = getStatusBadgeClass($order['status']);

        echo json_encode(['success' => true, 'order' => $order]);
        exit;
    }

    // ----------------------------------------------------
    // Default Action: Real-Time Poll for New Orders & Metrics
    // ----------------------------------------------------
    $zoneWhere = "";
    $zoneParams = [];

    if ($selectedZone !== 'all' && !empty($selectedZone)) {
        if (is_numeric($selectedZone)) {
            $zoneWhere = "AND o.zone_id = :zid";
            $zoneParams[':zid'] = (int)$selectedZone;
        } else {
            $zoneWhere = "AND z.slug = :zslug";
            $zoneParams[':zslug'] = $selectedZone;
        }
    }

    // Fetch unread incoming orders
    $sqlUnread = "
        SELECT 
            o.order_id,
            o.order_number,
            o.zone_id,
            o.shop_name,
            o.customer_name,
            o.location,
            o.phone,
            o.quantity,
            o.total_amount,
            o.status,
            o.is_zone_read,
            o.created_at,
            z.name AS zone_name,
            z.slug AS zone_slug,
            COALESCE(p.product_name, p.name, 'Drinking Water') AS product_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
            (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs)') SEPARATOR ', ') 
             FROM order_items oi2 
             JOIN products p2 ON oi2.product_id = p2.product_id 
             WHERE oi2.order_id = o.order_id) AS items_summary
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN products p ON o.product_id = p.product_id
        WHERE o.is_zone_read = 0 AND o.status != 'cancelled' $zoneWhere
        ORDER BY o.order_id DESC
    ";

    $stmtUnread = $pdo->prepare($sqlUnread);
    $stmtUnread->execute($zoneParams);
    $unreadOrders = $stmtUnread->fetchAll(PDO::FETCH_ASSOC);

    // Format fields for frontend
    foreach ($unreadOrders as &$uo) {
        $uo['formatted_date'] = formatIST($uo['created_at']);
        $uo['color_config'] = getZoneColorConfig($uo['zone_slug'] ?? '');
        $uo['badge_class'] = getStatusBadgeClass($uo['status']);
    }
    unset($uo);

    // Latest order ID across selected zone
    $sqlLatest = "
        SELECT MAX(o.order_id) 
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        WHERE 1=1 $zoneWhere
    ";
    $stmtLatest = $pdo->prepare($sqlLatest);
    $stmtLatest->execute($zoneParams);
    $latestOrderId = (int)$stmtLatest->fetchColumn();

    $metrics = getLiveMetrics($pdo, $selectedZone);

    echo json_encode([
        'success'         => true,
        'zone'            => $selectedZone,
        'unread_count'    => count($unreadOrders),
        'latest_order_id' => $latestOrderId,
        'orders'          => $unreadOrders,
        'metrics'         => $metrics,
        'timestamp'       => time()
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

/**
 * Helper to fetch aggregated metrics for zone dashboard
 */
function getLiveMetrics(PDO $pdo, string $selectedZone = 'all'): array {
    $where = "WHERE 1=1";
    $params = [];

    if ($selectedZone !== 'all' && !empty($selectedZone)) {
        if (is_numeric($selectedZone)) {
            $where .= " AND o.zone_id = :zid";
            $params[':zid'] = (int)$selectedZone;
        } else {
            $where .= " AND z.slug = :zslug";
            $params[':zslug'] = $selectedZone;
        }
    }

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.order_id) AS total_orders,
            SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN o.status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) AS shipped_count,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.is_zone_read = 0 AND o.status != 'cancelled' THEN 1 ELSE 0 END) AS unread_count,
            COALESCE(SUM(o.total_amount), 0) AS total_order_value,
            COALESCE(SUM(r.cash_amount), 0) AS total_cash,
            COALESCE(SUM(r.credited_amount), 0) AS total_credit,
            COALESCE(SUM(r.due_amount), 0) AS total_due
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN receipts r ON o.order_id = r.order_id
        $where
    ");
    $stmt->execute($params);
    $res = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_orders'      => (int)($res['total_orders'] ?? 0),
        'pending_count'     => (int)($res['pending_count'] ?? 0),
        'processing_count'  => (int)($res['processing_count'] ?? 0),
        'shipped_count'     => (int)($res['shipped_count'] ?? 0),
        'delivered_count'   => (int)($res['delivered_count'] ?? 0),
        'cancelled_count'   => (int)($res['cancelled_count'] ?? 0),
        'unread_count'      => (int)($res['unread_count'] ?? 0),
        'total_order_value' => (float)($res['total_order_value'] ?? 0),
        'total_cash'        => (float)($res['total_cash'] ?? 0),
        'total_credit'      => (float)($res['total_credit'] ?? 0),
        'total_due'         => (float)($res['total_due'] ?? 0)
    ];
}
