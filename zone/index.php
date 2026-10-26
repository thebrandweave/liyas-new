<?php
require_once __DIR__ . '/zone_context.php';

// Handle quick status update from zone portal
$msg = '';
$msg_type = 'success';

if (!empty($_SESSION['zone_portal_flash'])) {
    $msg = $_SESSION['zone_portal_flash']['msg'] ?? '';
    $msg_type = $_SESSION['zone_portal_flash']['msg_type'] ?? 'success';
    unset($_SESSION['zone_portal_flash']);
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    if ($order_id > 0 && in_array($new_status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
        // Enforce zone isolation in query
        $oldStmt = $pdo->prepare("SELECT status FROM orders WHERE order_id = ? AND zone_id = ?");
        $oldStmt->execute([$order_id, $zone_id]);
        $old_status = $oldStmt->fetchColumn() ?: '';

        $up = $pdo->prepare("UPDATE orders SET status = ?, is_zone_read = 1, zone_read_at = COALESCE(zone_read_at, NOW()), updated_at = NOW() WHERE order_id = ? AND zone_id = ?");
        $up->execute([$new_status, $order_id, $zone_id]);
        if ($up->rowCount() > 0) {
            handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);
            $msg = "Order status updated to " . ucfirst($new_status);
            if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
                $oStmt = $pdo->prepare("SELECT shop_name, customer_name, phone FROM orders WHERE order_id = ?");
                $oStmt->execute([$order_id]);
                $oData = $oStmt->fetch(PDO::FETCH_ASSOC);
                if ($oData) {
                    $sName = $oData['shop_name'] ?: ($oData['customer_name'] ?: '');
                    if ($sName !== '') {
                        updateShopRewardProgress($pdo, $sName, $oData['phone'] ?? '');
                    }
                }
            }
        }
    }
    if (!$isAjax) {
        $_SESSION['zone_portal_flash'] = ['msg' => $msg, 'msg_type' => $msg_type];
        $redirectUrl = zone_url($zone_slug) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: " . $redirectUrl);
        exit;
    }
}

// Handle quick vehicle assignment from zone portal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_vehicle'])) {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    if ($order_id > 0) {
        if ($vehicle_id > 0) {
            $res = assignOrderToVehicle($pdo, $order_id, $vehicle_id);
            if (!empty($res['success'])) {
                $msg = "Vehicle assigned successfully.";
            } else {
                $msg = $res['error'] ?? "Failed to assign vehicle.";
                $msg_type = 'error';
            }
        } else {
            unassignOrderFromVehicle($pdo, $order_id);
            $msg = "Vehicle unassigned from order.";
        }
    }
    if (!$isAjax) {
        $_SESSION['zone_portal_flash'] = ['msg' => $msg, 'msg_type' => $msg_type];
        $redirectUrl = zone_url($zone_slug) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: " . $redirectUrl);
        exit;
    }
}

// Handle quick recording of damaged items on a vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_vehicle_damaged'])) {
    $vId = (int)($_POST['vehicle_id'] ?? 0);
    $pId = (int)($_POST['product_id'] ?? 0);
    $cases = (int)($_POST['damaged_cases'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $reportedBy = 'Zone Portal (' . ($current_zone['name'] ?? 'Zone') . ')';

    if ($vId > 0 && $pId > 0 && $cases > 0) {
        $res = recordVehicleDamagedItem($pdo, $vId, $pId, $cases, $zone_id, $notes, $reportedBy, true);
        if (!empty($res['success'])) {
            $msg = "Damaged stock ({$cases} cases) recorded successfully for vehicle.";
            $msg_type = 'success';
        } else {
            $msg = $res['error'] ?? "Failed to record damaged item.";
            $msg_type = 'error';
        }
    } else {
        $msg = "Please select vehicle, product, and specify valid damaged cases (> 0).";
        $msg_type = 'error';
    }

    if (!$isAjax) {
        $_SESSION['zone_portal_flash'] = ['msg' => $msg, 'msg_type' => $msg_type];
        $redirectUrl = zone_url($zone_slug) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        header("Location: " . $redirectUrl);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => ($msg_type === 'success'), 'message' => $msg, 'error' => ($msg_type !== 'success' ? $msg : null)]);
        exit;
    }
}

// Mark single unread order as read/checked
if (isset($_GET['mark_read_id']) && (int)$_GET['mark_read_id'] > 0) {
    $markSingle = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE zone_id = ? AND order_id = ?");
    $markSingle->execute([$zone_id, (int)$_GET['mark_read_id']]);
    header("Location: " . zone_url($zone_slug));
    exit;
}

// Mark all unread notifications as read
if (isset($_GET['mark_all_read']) && $_GET['mark_all_read'] == '1') {
    $markAll = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE zone_id = ? AND is_zone_read = 0");
    $markAll->execute([$zone_id]);
    header("Location: " . zone_url($zone_slug));
    exit;
}

// 1. Unread notifications for this zone
$unread_stmt = $pdo->prepare("
    SELECT o.*, p.product_name, p.name as fallback_name,
           COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1)) AS resolved_vehicle_id,
           veh.vehicle_number AS assigned_vehicle_number,
           veh.vehicle_name AS assigned_vehicle_name,
           (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs', IF(oi2.is_extra = 1 OR oi2.price_at_purchase = 0, ' - Extra', ''), ')') SEPARATOR ', ') 
            FROM order_items oi2 
            JOIN products p2 ON oi2.product_id = p2.product_id 
            WHERE oi2.order_id = o.order_id) AS items_summary,
           (SELECT COALESCE(SUM(r2.due_amount), 0)
            FROM receipts r2
            JOIN orders o2 ON r2.order_id = o2.order_id
            WHERE r2.due_amount > 0
              AND o2.status != 'cancelled'
              AND o2.order_id != o.order_id
              AND (
                  (TRIM(LOWER(o2.shop_name)) = TRIM(LOWER(o.shop_name)) AND TRIM(o.shop_name) != '')
                  OR (TRIM(LOWER(o2.customer_name)) = TRIM(LOWER(o.customer_name)) AND TRIM(o.customer_name) != '')
                  OR (o.phone != '' AND LENGTH(o.phone) >= 7 AND RIGHT(REGEXP_REPLACE(o2.phone, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 10))
              )
           ) AS prior_due_amount
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN vehicles veh ON veh.id = COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1))
    WHERE o.zone_id = ? AND o.is_zone_read = 0 AND o.status != 'cancelled'
    ORDER BY o.created_at DESC
");
$unread_stmt->execute([$zone_id]);
$unread_notifications = $unread_stmt->fetchAll(PDO::FETCH_ASSOC);

// Date filter parameters (Default to 'today' for daily reset)
$date_preset = strtolower(trim($_GET['date_preset'] ?? 'today'));
$date_from = trim($_GET['date_from'] ?? ($_GET['start_date'] ?? ''));
$date_to = trim($_GET['date_to'] ?? ($_GET['end_date'] ?? ''));

$validPresets = ['today', 'yesterday', 'month', 'all', 'custom'];
if (!in_array($date_preset, $validPresets)) {
    $date_preset = 'today';
}

$dateCondition = "";
$dateParams = [];

if ($date_preset === 'today') {
    $dateCondition = "DATE(o.created_at) = CURDATE()";
    $dateLabel = "Today (" . date('d M Y') . ")";
} elseif ($date_preset === 'yesterday') {
    $dateCondition = "DATE(o.created_at) = SUBDATE(CURDATE(), 1)";
    $dateLabel = "Yesterday (" . date('d M Y', strtotime('-1 day')) . ")";
} elseif ($date_preset === 'month') {
    $dateCondition = "o.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01 00:00:00') AND o.created_at <= NOW()";
    $dateLabel = "This Month (" . date('M Y') . ")";
} elseif ($date_preset === 'custom') {
    if (!empty($date_from) && !empty($date_to)) {
        $dateCondition = "DATE(o.created_at) BETWEEN :date_from AND :date_to";
        $dateParams[':date_from'] = $date_from;
        $dateParams[':date_to'] = $date_to;
        $dateLabel = date('d M Y', strtotime($date_from)) . " - " . date('d M Y', strtotime($date_to));
    } elseif (!empty($date_from)) {
        $dateCondition = "DATE(o.created_at) >= :date_from";
        $dateParams[':date_from'] = $date_from;
        $dateLabel = "From " . date('d M Y', strtotime($date_from));
    } else {
        $dateCondition = "DATE(o.created_at) = CURDATE()";
        $dateLabel = "Today (" . date('d M Y') . ")";
        $date_preset = 'today';
    }
} else { // 'all'
    $dateCondition = "";
    $dateLabel = "All Time";
}

$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

if (!function_exists('zone_filter_url')) {
    function zone_filter_url($slug, $extraParams = []) {
        global $filter, $date_preset, $date_from, $date_to, $search;
        $params = [
            'filter' => $filter,
            'date_preset' => $date_preset,
        ];
        if ($date_preset === 'custom') {
            if (!empty($date_from)) $params['date_from'] = $date_from;
            if (!empty($date_to)) $params['date_to'] = $date_to;
        }
        if (!empty($search)) {
            $params['search'] = $search;
        }
        $merged = array_merge($params, $extraParams);
        return zone_url($slug, '', array_filter($merged, fn($v) => $v !== null && $v !== ''));
    }
}

// 2. Status counts for filter chips
// Operational counts (total, pending, processing, shipped, delivered, cancelled, credit, cash) reset daily with date filter
$countsWhere = ["o.zone_id = :c_zid"];
$countsParams = [':c_zid' => $zone_id];
if (!empty($dateCondition)) {
    $countsWhere[] = str_replace([':date_from', ':date_to'], [':c_dfrom', ':c_dto'], $dateCondition);
    if (isset($dateParams[':date_from'])) $countsParams[':c_dfrom'] = $dateParams[':date_from'];
    if (isset($dateParams[':date_to'])) $countsParams[':c_dto'] = $dateParams[':date_to'];
}
$countsWhereSql = implode(" AND ", $countsWhere);

$counts_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.order_id) as total,
        SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN o.status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN r.credited_amount > 0 THEN 1 ELSE 0 END) as credit_count,
        SUM(CASE WHEN r.cash_amount > 0 THEN 1 ELSE 0 END) as cash_count
    FROM orders o
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE {$countsWhereSql}
");
$counts_stmt->execute($countsParams);
$status_counts = $counts_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0, 'pending' => 0, 'processing' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0,
    'credit_count' => 0, 'cash_count' => 0
];

// Outstanding Due Count (Cumulative, DOES NOT reset daily; includes orders with receipt due OR shop prior dues)
$dueCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT o.order_id)
    FROM orders o
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE o.zone_id = :zone_id
      AND o.status != 'cancelled'
      AND (
          r.due_amount > 0
          OR EXISTS (
              SELECT 1 FROM receipts r2
              JOIN orders o2 ON r2.order_id = o2.order_id
              WHERE r2.due_amount > 0
                AND o2.status != 'cancelled'
                AND o2.order_id != o.order_id
                AND (
                    (TRIM(LOWER(o2.shop_name)) = TRIM(LOWER(o.shop_name)) AND TRIM(o.shop_name) != '')
                    OR (TRIM(LOWER(o2.customer_name)) = TRIM(LOWER(o.customer_name)) AND TRIM(o.customer_name) != '')
                    OR (o.phone != '' AND LENGTH(o.phone) >= 7 AND RIGHT(REGEXP_REPLACE(o2.phone, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 10))
                )
          )
      )
");
$dueCountStmt->execute([':zone_id' => $zone_id]);
$status_counts['due_count'] = (int)$dueCountStmt->fetchColumn();

// 3. Zone Orders (strictly filtered by zone_id)
$where_clauses = ["o.zone_id = :zone_id"];
$params = [':zone_id' => $zone_id];

// Dynamic filter matching
if ($filter === 'due') {
    // Outstanding Due does NOT reset daily - show all active orders that have due or prior due
    $where_clauses[] = "o.status != 'cancelled' AND (
        r.due_amount > 0
        OR EXISTS (
            SELECT 1 FROM receipts r2
            JOIN orders o2 ON r2.order_id = o2.order_id
            WHERE r2.due_amount > 0
              AND o2.status != 'cancelled'
              AND o2.order_id != o.order_id
              AND (
                  (TRIM(LOWER(o2.shop_name)) = TRIM(LOWER(o.shop_name)) AND TRIM(o.shop_name) != '')
                  OR (TRIM(LOWER(o2.customer_name)) = TRIM(LOWER(o.customer_name)) AND TRIM(o.customer_name) != '')
                  OR (o.phone != '' AND LENGTH(o.phone) >= 7 AND RIGHT(REGEXP_REPLACE(o2.phone, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 10))
              )
        )
    )";
} else {
    // Other filters respect the active date condition (Daily Reset by default)
    if (!empty($dateCondition)) {
        $where_clauses[] = $dateCondition;
        $params = array_merge($params, $dateParams);
    }

    if ($filter === 'credit') {
        $where_clauses[] = "r.credited_amount > 0";
    } elseif ($filter === 'cash') {
        $where_clauses[] = "r.cash_amount > 0";
    } elseif (in_array($filter, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
        $where_clauses[] = "o.status = :status";
        $params[':status'] = $filter;
    }
}

if (!empty($search)) {
    $where_clauses[] = "(o.shop_name LIKE :search OR o.customer_name LIKE :search OR o.phone LIKE :search OR o.location LIKE :search OR o.order_number LIKE :search OR r.bill_number LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_sql = implode(" AND ", $where_clauses);

$orders_stmt = $pdo->prepare("
    SELECT 
        o.*,
        p.product_name,
        p.name as fallback_name,
        p.case_price,
        p.net_content,
        p.net_content_unit,
        r.id as receipt_id,
        r.bill_number,
        r.delivered_quantity,
        r.cash_amount as receipt_cash,
        r.credited_amount as receipt_credit,
        r.due_amount as receipt_due,
        r.payment_type as receipt_pay_type,
        COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1)) AS resolved_vehicle_id,
        veh.vehicle_number AS assigned_vehicle_number,
        veh.vehicle_name AS assigned_vehicle_name,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs', IF(oi2.is_extra = 1 OR oi2.price_at_purchase = 0, ' - Extra', ''), ')') SEPARATOR ', ') 
         FROM order_items oi2 
         JOIN products p2 ON oi2.product_id = p2.product_id 
         WHERE oi2.order_id = o.order_id) AS items_summary,
        (SELECT COALESCE(SUM(r2.due_amount), 0)
         FROM receipts r2
         JOIN orders o2 ON r2.order_id = o2.order_id
         WHERE r2.due_amount > 0
           AND o2.status != 'cancelled'
           AND o2.order_id != o.order_id
           AND (
               (TRIM(LOWER(o2.shop_name)) = TRIM(LOWER(o.shop_name)) AND TRIM(o.shop_name) != '')
               OR (TRIM(LOWER(o2.customer_name)) = TRIM(LOWER(o.customer_name)) AND TRIM(o.customer_name) != '')
               OR (o.phone != '' AND LENGTH(o.phone) >= 7 AND RIGHT(REGEXP_REPLACE(o2.phone, '[^0-9]', ''), 10) = RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 10))
           )
        ) AS prior_due_amount
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN vehicles veh ON veh.id = COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1))
    WHERE $where_sql
    ORDER BY o.created_at DESC
");
$orders_stmt->execute($params);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Zone Summary Metrics
// Operational metrics filtered by active date range (daily reset)
$metricsWhere = ["o.zone_id = :m_zid"];
$metricsParams = [':m_zid' => $zone_id];
if (!empty($dateCondition)) {
    $metricsWhere[] = str_replace([':date_from', ':date_to'], [':m_dfrom', ':m_dto'], $dateCondition);
    if (isset($dateParams[':date_from'])) $metricsParams[':m_dfrom'] = $dateParams[':date_from'];
    if (isset($dateParams[':date_to'])) $metricsParams[':m_dto'] = $dateParams[':date_to'];
}
$metricsWhereSql = implode(" AND ", $metricsWhere);

$metrics_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
        COALESCE(SUM(r.cash_amount), 0) as total_cash_collected,
        COALESCE(SUM(r.credited_amount), 0) as total_credit
    FROM orders o
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE {$metricsWhereSql}
");
$metrics_stmt->execute($metricsParams);
$metrics = $metrics_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_orders' => 0, 'pending_count' => 0, 'delivered_count' => 0,
    'total_cash_collected' => 0, 'total_credit' => 0
];

// All-Time Cumulative Outstanding Due (DOES NOT reset daily)
$dueTotalStmt = $pdo->prepare("
    SELECT COALESCE(SUM(r.due_amount), 0) as total_due
    FROM orders o
    JOIN receipts r ON o.order_id = r.order_id
    WHERE o.zone_id = :zone_id
      AND o.status != 'cancelled'
      AND r.due_amount > 0
");
$dueTotalStmt->execute([':zone_id' => $zone_id]);
$metrics['total_due'] = (float)$dueTotalStmt->fetchColumn();

// 5. Active Vehicles operating in this zone & load remaining
$zoneVehiclesStmt = $pdo->prepare("
    SELECT DISTINCT v.id, v.vehicle_number, v.vehicle_name, v.driver_name, v.driver_phone, v.capacity_cases, v.status
    FROM vehicles v
    WHERE v.status != 'inactive' 
      AND (
          v.zone_id = ? 
          OR v.id IN (SELECT vehicle_id FROM vehicle_loads WHERE zone_id = ? AND is_active = 1)
          OR v.id IN (SELECT vehicle_id FROM orders WHERE zone_id = ? AND vehicle_id IS NOT NULL AND status NOT IN ('delivered', 'cancelled'))
      )
    ORDER BY v.vehicle_number ASC
");
$zoneVehiclesStmt->execute([$zone_id, $zone_id, $zone_id]);
$zoneVehicles = $zoneVehiclesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$vehiclesLoadMap = [];
foreach ($zoneVehicles as $zv) {
    $vId = (int)$zv['id'];
    $loadedProducts = getVehicleLoadedProductsWithCounts($pdo, $vId);
    $totalCases = array_sum(array_column($loadedProducts, 'loaded_cases'));
    $storageCases = array_sum(array_column($loadedProducts, 'storage_cases'));
    $orderCases = array_sum(array_column($loadedProducts, 'order_cases'));

    $breakdownParts = [];
    foreach ($loadedProducts as $lp) {
        if (!empty($lp['loaded_cases']) && $lp['loaded_cases'] > 0) {
            $breakdownParts[] = htmlspecialchars($lp['product_name']) . ': ' . (int)$lp['loaded_cases'] . ' cs';
        }
    }
    $breakdownText = !empty($breakdownParts) ? implode(', ', $breakdownParts) : '0 cs';

    $vehiclesLoadMap[$vId] = [
        'vehicle'         => $zv,
        'loaded_products' => $loadedProducts,
        'total_cases'     => $totalCases,
        'storage_cases'   => $storageCases,
        'order_cases'     => $orderCases,
        'breakdown_text'  => $breakdownText
    ];
}

// Ensure any vehicle assigned to listed orders is pre-computed in $vehiclesLoadMap
foreach ($orders as $ord) {
    $ovId = (int)($ord['resolved_vehicle_id'] ?? ($ord['vehicle_id'] ?? 0));
    if ($ovId > 0 && !isset($vehiclesLoadMap[$ovId])) {
        $loadedProducts = getVehicleLoadedProductsWithCounts($pdo, $ovId);
        $totalCases = array_sum(array_column($loadedProducts, 'loaded_cases'));
        $breakdownParts = [];
        foreach ($loadedProducts as $lp) {
            if (!empty($lp['loaded_cases']) && $lp['loaded_cases'] > 0) {
                $breakdownParts[] = htmlspecialchars($lp['product_name']) . ': ' . (int)$lp['loaded_cases'] . ' cs';
            }
        }
        $vehiclesLoadMap[$ovId] = [
            'vehicle'         => [
                'id'             => $ovId,
                'vehicle_number' => $ord['assigned_vehicle_number'] ?? '',
                'vehicle_name'   => $ord['assigned_vehicle_name'] ?? '',
                'driver_name'    => '',
                'driver_phone'   => '',
                'capacity_cases' => 0
            ],
            'loaded_products' => $loadedProducts,
            'total_cases'     => $totalCases,
            'storage_cases'   => array_sum(array_column($loadedProducts, 'storage_cases')),
            'order_cases'     => array_sum(array_column($loadedProducts, 'order_cases')),
            'breakdown_text'  => !empty($breakdownParts) ? implode(', ', $breakdownParts) : '0 cs'
        ];
    }
}

// Active catalog products for damaged items reporting
$allProductsList = $pdo->query("SELECT product_id, COALESCE(product_name, name) as name, case_price, net_content, net_content_unit FROM products WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
// Active damaged items summary for vehicles in this zone
$zoneDamagedSummary = getZoneVehicleDamagedSummary($pdo, $zone_id);

if (!function_exists('buildWhatsAppLink')) {
    function buildWhatsAppLink($phone, $shop, $orderNum, $amount, $itemsText = '') {
        $clean = preg_replace('/[^0-9]/', '', $phone ?? '');
        if (empty($clean)) return '';
        if (strlen($clean) === 10) {
            $clean = '91' . $clean;
        }
        $itemDesc = !empty($itemsText) ? " [{$itemsText}]" : "";
        $text = "Hello {$shop}, this is Liyas Water Delivery regarding Order #{$orderNum}{$itemDesc} (Total: {$amount}).";
        return "https://wa.me/{$clean}?text=" . rawurlencode($text);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($zone_name) ?> Delivery Portal - Liyas International</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --primary-border: #bfdbfe;
            --success: #059669;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --warning-border: #fde68a;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --info: #0284c7;
            --info-bg: #f0f9ff;
            --purple: #7c3aed;
            --purple-bg: #f5f3ff;
            --purple-border: #ddd6fe;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-subtle: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
            --shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -4px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--body-bg);
            color: var(--text-main);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 90px;
        }

        /* Top Navigation Bar */
        .portal-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }

        .header-inner {
            max-width: 1240px;
            margin: 0 auto;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .brand-cluster {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 24px;
            box-shadow: 0 4px 10px rgba(37,99,235,0.25);
            flex-shrink: 0;
        }

        .brand-title {
            font-weight: 800;
            font-size: 17px;
            line-height: 1.2;
            letter-spacing: -0.02em;
            color: #0f172a;
        }

        .brand-subtitle {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .zone-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #dbeafe;
            color: #1e40af;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 20px;
            border: 1px solid #bfdbfe;
            white-space: nowrap;
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .btn-header-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 10px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .btn-new-bill-header {
            background: var(--success);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(5,150,105,0.25);
        }

        .btn-new-bill-header:hover, .btn-new-bill-header:active {
            background: #047857;
            transform: translateY(-1px);
        }

        .btn-header-vehicles {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            box-shadow: 0 1px 2px rgba(37,99,235,0.08);
            font-weight: 700;
        }

        .btn-header-vehicles:hover, .btn-header-vehicles:active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(37,99,235,0.25);
        }

        .btn-manage-vehicles {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #1d4ed8;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 6px 12px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(37,99,235,0.06);
            white-space: nowrap;
        }

        .btn-manage-vehicles:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(37,99,235,0.22);
        }

        .btn-manage-vehicles i {
            font-size: 15px;
        }

        .btn-admin-back {
            background: #f1f5f9;
            color: var(--text-sub);
            border: 1px solid var(--border-color);
        }

        /* Main Container */
        .portal-container {
            max-width: 1240px;
            margin: 0 auto;
            padding: 16px;
        }

        /* Flash Message */
        .flash-alert {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: #065f46;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-subtle);
        }

        /* Incoming New Order Notification Banner */
        .notif-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1.5px solid #93c5fd;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(37,99,235,0.08);
            position: relative;
            overflow: hidden;
        }

        .notif-banner::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: #2563eb;
        }

        .notif-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .notif-badge-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notif-bell-icon {
            font-size: 22px;
            color: #ef4444;
            animation: ring-bell 2s infinite ease-in-out;
            transform-origin: top center;
        }

        @keyframes ring-bell {
            0%, 100% { transform: rotate(0deg); }
            10%, 30% { transform: rotate(14deg); }
            20%, 40% { transform: rotate(-14deg); }
            50% { transform: rotate(0deg); }
        }

        .notif-title {
            font-size: 15px;
            font-weight: 800;
            color: #1e3a8a;
        }

        .notif-count-pill {
            background: #ef4444;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .btn-mark-all-read {
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            text-decoration: none;
            background: #ffffff;
            padding: 4px 10px;
            border-radius: 8px;
            border: 1px solid #bfdbfe;
        }

        .notif-item {
            background: #ffffff;
            border: 1px solid #bfdbfe;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .notif-item:last-child {
            margin-bottom: 0;
        }

        .notif-shop {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
        }

        .notif-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .notif-actions {
            display: flex;
            gap: 8px;
            width: 100%;
            justify-content: flex-end;
        }

        .btn-ack-check {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-ack-check:hover {
            background: #d1fae5;
            transform: translateY(-1px);
        }

        .btn-sound-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-sound-toggle:hover {
            background: #dbeafe;
        }
        .btn-sound-toggle.playing {
            animation: sound-pulse 0.6s infinite alternate;
        }
        @keyframes sound-pulse {
            from { transform: scale(1); background: #dbeafe; }
            to { transform: scale(1.06); background: #93c5fd; }
        }

        .badge-unread-chip {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            padding: 2px 7px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            animation: pulse-unread 2s infinite ease-in-out;
        }
        @keyframes pulse-unread {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        /* Toast notifications container */
        .zone-toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
            max-width: 360px;
            width: calc(100% - 40px);
        }
        .zone-toast {
            pointer-events: auto;
            background: #0f172a;
            color: #ffffff;
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: toast-in 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            border-left: 5px solid #2563eb;
        }
        .zone-toast.closing {
            animation: toast-out 0.3s forwards ease;
        }
        @keyframes toast-in {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes toast-out {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }
        .zone-toast-icon {
            font-size: 24px;
            color: #60a5fa;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .zone-toast-content {
            flex: 1;
        }
        .zone-toast-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 2px;
        }
        .zone-toast-body {
            font-size: 12px;
            color: #cbd5e1;
            line-height: 1.4;
        }
        .zone-toast-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            margin-left: 4px;
        }
        .zone-toast-close:hover { color: #ffffff; }

        /* Audio unlock prompt */
        .audio-unlock-banner {
            background: #eff6ff;
            border: 1.5px dashed #60a5fa;
            border-radius: 10px;
            padding: 10px 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            animation: bounce-gentle 2s infinite ease-in-out;
        }
        @keyframes bounce-gentle {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        /* Metrics Grid (5 cards: Pending, Delivered, In-Hand Cash, Credited, Outstanding Due) */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        @media (max-width: 640px) {
            .metrics-grid {
                grid-template-columns: repeat(1, 1fr);
            }

           
           
        }
 
        @media (min-width: 640px) {
             .metric-card{
                /* display:block; */
                 flex-direction:row;
            }
}


        @media (min-width: 1024px) {
            .metrics-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 14px;
            }


        }

        .metric-card {
            background: var(--card-bg);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px;
            box-shadow: var(--shadow-subtle);
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
            cursor: pointer;
            user-select: none;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        /* Active selected state for filter cards */
        .metric-card.active-card {
            border-width: 2px;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.18), 0 8px 16px rgba(0,0,0,0.06);
            transform: translateY(-2px);
        }

        .metric-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .metric-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .metric-label {
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .metric-val {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }

        .metric-hint {
            font-size: 11px;
            font-weight: 700;
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Thematic Card Colors */
        .metric-pending .metric-icon-box { background: var(--warning-bg); color: var(--warning); }
        .metric-pending .metric-val { color: #b45309; }
        .metric-pending .metric-hint { color: #d97706; }
        .metric-pending.active-card { border-color: #f59e0b; background: #fffbeb; }

        .metric-delivered .metric-icon-box { background: var(--success-bg); color: var(--success); }
        .metric-delivered .metric-val { color: #047857; }
        .metric-delivered .metric-hint { color: #059669; }
        .metric-delivered.active-card { border-color: #10b981; background: #ecfdf5; }

        .metric-cash .metric-icon-box { background: #eff6ff; color: #2563eb; }
        .metric-cash .metric-val { color: #1e40af; }
        .metric-cash .metric-hint { color: #2563eb; }
        .metric-cash.active-card { border-color: #2563eb; background: #eff6ff; }

        .metric-credit .metric-icon-box { background: var(--purple-bg); color: var(--purple); }
        .metric-credit .metric-val { color: #6d28d9; }
        .metric-credit .metric-hint { color: #7c3aed; }
        .metric-credit.active-card { border-color: #7c3aed; background: #f5f3ff; }

        .metric-due .metric-icon-box { background: var(--danger-bg); color: var(--danger); }
        .metric-due .metric-val { color: #dc2626; }
        .metric-due .metric-hint { color: #ef4444; }
        .metric-due.active-card { border-color: #ef4444; background: #fef2f2; }

        /* Date Filter & Daily Reset Bar */
        .date-filter-section {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 12px 8px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-subtle);
        }

        .date-filter-header {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        @media (min-width: 768px) {
            .date-filter-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .date-filter-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .date-scope-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
        }

        .date-scope-indicator i {
            color: var(--primary);
            font-size: 18px;
        }

        .daily-reset-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #047857;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 2px 8px;
            border-radius: 12px;
        }

        .btn-reset-today {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 4px 10px;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-reset-today:hover {
            background: #2563eb;
            color: #ffffff;
        }

        .date-preset-pills-row {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .date-preset-pills-row::-webkit-scrollbar {
            display: none;
        }

        .date-preset-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #f8fafc;
            color: var(--text-sub);
            border: 1px solid var(--border-color);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.15s ease;
            cursor: pointer;
            font-family: inherit;
        }

        .date-preset-pill:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .date-preset-pill.active {
            background: #1e293b;
            color: #ffffff;
            border-color: #1e293b;
            box-shadow: 0 2px 5px rgba(15, 23, 42, 0.2);
        }

        .custom-date-picker-box {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed var(--border-color);
        }

        .custom-date-form .custom-date-inputs {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .date-input-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-sub);
        }

        .date-field {
            padding: 6px 10px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-family: inherit;
            color: var(--text-main);
            background: #f8fafc;
            outline: none;
        }

        .date-field:focus {
            border-color: var(--primary);
            background: #ffffff;
        }

        .custom-date-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-apply-date {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 14px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .btn-apply-date:hover {
            background: #1d4ed8;
        }

        .btn-cancel-date {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            background: none;
            border: none;
            cursor: pointer;
        }

        .btn-cancel-date:hover {
            color: #0f172a;
        }

        /* Filter Chips and Search Control Bar */
        .controls-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 10px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-subtle);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .controls-panel {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        /* Horizontal Scrollable Filter Tabs */
        .filter-scroll-container {
            display: flex;
            gap: 2px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .filter-scroll-container::-webkit-scrollbar {
            display: none;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            color: var(--text-sub);
            border: 1px solid var(--border-color);
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.15s ease;
            cursor: pointer;
        }

        .filter-pill:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .filter-pill.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 2px 6px rgba(37,99,235,0.25);
        }

        .filter-counter {
            font-size: 11px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.08);
            color: inherit;
        }

        .filter-pill.active .filter-counter {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* Search Input */
        .search-box {
            position: relative;
            min-width: 240px;
            flex-shrink: 0;
        }

        .search-input {
            width: 100%;
            padding: 9px 36px 9px 38px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            background: #f8fafc;
            color: var(--text-main);
            transition: all 0.15s ease;
        }

        .search-input:focus {
            outline: none;
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
            pointer-events: none;
        }

        .search-clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 16px;
            cursor: pointer;
            padding: 2px;
            display: none;
        }

        .search-clear-btn.visible {
            display: block;
        }

        /* Vehicle Load Remaining Widget Styles */
        .vehicle-load-panel {
            margin-bottom: 20px;
        }

        .vehicle-load-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1.5px solid #cbd5e1;
            border-radius: var(--radius-lg);
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }

        .vehicle-load-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            /* border-bottom: 1px solid #e2e8f0; */
            padding-bottom: 6px;
            /* margin-bottom: 12px; */
        }

        .vehicle-load-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .vehicle-truck-icon-box {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 22px;
            box-shadow: 0 3px 8px rgba(37,99,235,0.25);
            flex-shrink: 0;
        }

        .vehicle-load-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }

        .vehicle-load-subtitle {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .vehicle-cards-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        @media (min-width: 768px) {
            .vehicle-cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            }
        }

        .vehicle-item-box {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: var(--radius-md);
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .vehicle-item-box:hover {
            border-color: #93c5fd;
            box-shadow: 0 4px 10px rgba(37,99,235,0.06);
        }

        .vehicle-item-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .vehicle-name-info {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .veh-num-badge {
            background: #0f172a;
            color: #ffffff;
            font-size: 12px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            letter-spacing: 0.03em;
        }

        .veh-name-text {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            text-transform: capitalize;
        }

        .veh-driver-tag {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #f1f5f9;
            padding: 2px 7px;
            border-radius: 5px;
        }

        .vehicle-load-counter {
            display: flex;
            align-items: baseline;
            gap: 4px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 3px 10px;
            border-radius: 8px;
        }

        .vehicle-load-counter.counter-empty {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .vehicle-load-counter .counter-num {
            font-size: 18px;
            font-weight: 800;
            color: #047857;
            line-height: 1;
        }

        .vehicle-load-counter.counter-empty .counter-num {
            color: #64748b;
        }

        .vehicle-load-counter .counter-unit {
            font-size: 11px;
            font-weight: 700;
            color: #059669;
            text-transform: uppercase;
        }

        .vehicle-load-counter.counter-empty .counter-unit {
            color: #64748b;
        }

        .veh-progress-wrap {
            width: 100%;
            height: 5px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .veh-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .veh-breakdown-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .veh-prod-pill {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            color: #1e293b;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .veh-prod-pill .pill-qty {
            font-weight: 800;
            color: #2563eb;
        }

        .order-veh-badge {
            background: #0f172a;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .veh-cases-chip {
            background: #ffffff;
            color: #070809;
            padding: 1px 6px;
            /* border-radius: 4px; */
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        /* Vehicle Damaged Items Styling */
        .vehicle-damaged-counter {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 3px 8px;
            border-radius: 8px;
            color: #dc2626;
            font-size: 11px;
            font-weight: 700;
        }
        .vehicle-damaged-counter .counter-num {
            font-size: 15px;
            font-weight: 800;
            color: #dc2626;
            line-height: 1;
        }
        .vehicle-damaged-counter .counter-unit {
            font-size: 10px;
            font-weight: 700;
            color: #b91c1c;
            text-transform: uppercase;
        }
        .btn-add-damaged {
            background: #ffffff;
            border: 1px solid #fca5a5;
            color: #dc2626;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 9px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .btn-add-damaged:hover {
            background: #fee2e2;
            border-color: #ef4444;
            color: #b91c1c;
        }
        .veh-damaged-breakdown-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            background: #fff5f5;
            border: 1px dashed #fca5a5;
            padding: 6px 10px;
            border-radius: 8px;
            margin-top: 4px;
        }
        .veh-damaged-label {
            font-size: 11px;
            font-weight: 700;
            color: #dc2626;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .veh-damaged-pill {
            background: #ffffff;
            border: 1px solid #fecaca;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 11px;
            color: #7f1d1d;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .veh-damaged-pill .pill-damaged-qty {
            font-weight: 800;
            color: #dc2626;
        }
        .veh-damaged-pill .pill-damaged-note {
            font-size: 10px;
            color: #64748b;
            font-style: italic;
        }

        /* Order Cards Grid / List */
        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .order-card {
            background: var(--card-bg);
            /* border: 1px solid #e2e8f0; */
            /* border-bottom: 3px solid #0f1215; */
            border: 2px solid #0f1215;
            border-radius: 16px;
            padding: 16px;
            box-shadow: var(--shadow-subtle);
            transition: all 0.2s ease;
            position: relative;
        }

        .order-card:hover {
            /* border-color: #cbd5e1; */
            box-shadow: var(--shadow-hover);
            transform: translateY(-1px);
        }

        /* Card Top Header */
        .order-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
            margin-bottom: 12px;
        }

        .shop-name-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.3;
            letter-spacing: -0.01em;
        }

        .order-meta-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .order-id-tag {
            font-weight: 700;
            color: #2563eb;
            background: #eff6ff;
            padding: 2px 6px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-pending { background: #fef3c7; color: #b45309; }
        .status-pending .status-dot { background: #d97706; }

        .status-processing { background: #e0f2fe; color: #0369a1; }
        .status-processing .status-dot { background: #0284c7; }

        .status-shipped { background: #ede9fe; color: #6d28d9; }
        .status-shipped .status-dot { background: #7c3aed; }

        .status-delivered { background: #dcfce7; color: #15803d; }
        .status-delivered .status-dot { background: #16a34a; }

        .status-cancelled { background: #fee2e2; color: #b91c1c; }
        .status-cancelled .status-dot { background: #dc2626; }

        /* Card Details Grid */
        .order-card-body {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        @media (min-width: 640px) {
            .order-card-body {
                grid-template-columns: 3fr 2fr;
                gap: 16px;
            }
        }

        /* Customer Contact & Location Block */
        .contact-box {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .product-highlight {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .qty-pill {
            background: #f1f5f9;
            color: #0f172a;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
        }

        .contact-actions-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
            font-size: 13px;
        }

        .action-chip-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .chip-map {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .chip-map:hover { background: #fee2e2; }

        .chip-call {
            font-size:22px;
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .chip-call:hover { background: #dbeafe; }

        .chip-whatsapp {
            font-size:12px;
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .chip-whatsapp:hover { background: #d1fae5; }

        /* Price & Bill Summary Block */
        .price-summary-box {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 10px 14px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 4px;
        }

        @media (min-width: 640px) {
            .price-summary-box {
                text-align: right;
                align-items: flex-end;
            }
        }

        .total-amount-display {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        .discount-pill {
            font-size: 11px;
            font-weight: 700;
            color: #dc2626;
            background: #fee2e2;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
        }

        .bill-info-tag {
            font-size: 12px;
            font-weight: 700;
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        @media (min-width: 640px) {
            .bill-info-tag {
                justify-content: flex-end;
            }
        }

        .bill-number-badge {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            padding: 2px 6px;
            border-radius: 6px;
        }

        .due-badge {
            background: #fee2e2;
            color: #dc2626;
            padding: 2px 6px;
            border-radius: 6px;
            font-weight: 700;
        }

        .credit-badge {
            background: #f5f3ff;
            color: #7c3aed;
            padding: 2px 6px;
            border-radius: 6px;
            font-weight: 700;
        }

        .badge-prior-due-alert {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            font-size: 11px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .badge-prior-due-alert i {
            font-size: 13px;
        }

        /* Order Actions Footer */
        .order-actions-bar {
            border-top: 1px solid #f1f5f9;
            padding-top: 12px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }

        @media (min-width: 600px) {
            .order-actions-bar {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
            }
        }

        .btn-card-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            min-height: 42px;
            transition: all 0.15s ease;
            text-align: center;
        }

        .btn-deliver-primary {
            background: #059669;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(5,150,105,0.25);
            flex: 1;
        }

        .btn-deliver-primary:hover, .btn-deliver-primary:active {
            background: #047857;
            transform: translateY(-1px);
        }

        .btn-receipt-view {
            background: #1e40af;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(30,64,175,0.25);
            flex: 1;
        }

        .btn-receipt-view:hover, .btn-receipt-view:active {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .btn-card-secondary {
            background: #f8fafc;
            color: #334155;
            border: 1px solid var(--border-color);
        }

        .btn-card-secondary:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .btn-discount-tag {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .btn-card-reorder {
            background: #f5f3ff;
            color: #7c3aed;
            border: 1px solid #ddd6fe;
        }

        .btn-card-reorder:hover, .btn-card-reorder:active {
            background: #ede9fe;
            color: #6d28d9;
            transform: translateY(-1px);
        }
/* Metrics Container & Toggle Button */
.metrics-dropdown-wrapper {
    margin-bottom: 20px;
}

.btn-metrics-toggle {
    display: none; /* Hidden on tablets & desktop */
    width: 100%;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 700;
    color: var(--text-main);
    cursor: pointer;
    font-family: inherit;
    box-shadow: var(--shadow-subtle);
    transition: all 0.2s ease;
}

.toggle-btn-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.toggle-hamburger-icon {
    font-size: 20px;
    color: var(--primary);
}

.toggle-chevron {
    font-size: 20px;
    color: var(--text-muted);
    transition: transform 0.25s ease;
}

.btn-metrics-toggle.is-open .toggle-chevron {
    transform: rotate(180deg);
}

/* Default Desktop Grid Layout */
.metrics-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

@media (min-width: 1024px) {
    .metrics-grid {
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
    }
}

/* Mobile Dropdown Behavior (< 768px) */
@media (max-width: 767px) {
    .btn-metrics-toggle {
        display: flex;
    }

    .metrics-grid {
        display: none; /* Initially collapsed */
        grid-template-columns: 1fr;
        gap: 10px;
        margin-top: 10px;
        animation: drop-down 0.25s ease forwards;
    }

    .metrics-grid.show-mobile {
        display: grid;
    }
}

@keyframes drop-down {
    from {
        opacity: 0;
        transform: translateY(-8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
        .btn-discount-tag:hover {
            background: #dbeafe;
        }

        /* Status Select Form */
        .status-select-form {
            display: inline-flex;
            margin: 0;
        }

        .status-picker {
            padding: 9px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            border: 1.5px solid var(--border-color);
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
            font-family: inherit;
            min-height: 42px;
            transition: border-color 0.15s;
        }

        .status-picker:focus {
            outline: none;
            border-color: #2563eb;
        }

        /* Empty State */
        .empty-state-box {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 48px 24px;
            text-align: center;
            color: var(--text-muted);
            box-shadow: var(--shadow-subtle);
        }

        .empty-state-icon {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 12px;
        }

        .empty-state-title {
            font-size: 17px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 6px;
        }

        /* Floating Mobile Action Bar */
        .mobile-bottom-bar {
            display: none;
        }
        @media (max-width: 768px) {
    .order-actions-bar {
        display: flex;
        flex-direction: column;
        gap: 8px;
        width: 100%;
    }

    /* Keep the action buttons well-spaced */
    .action-btn-group {
        display: flex;
        flex-direction: row;
        gap: 6px;
        width: 100%;
        flex-wrap: wrap;
    }

    /* Distribute space evenly across the buttons */
    .action-btn-group .btn-card-action {
        flex: 1 1 calc(25% - 6px);
        min-width: 65px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 4px;
        font-size: 12.5px;
        white-space: nowrap;
        text-align: center;
    }

    .action-btn-group .btn-card-action i {
        margin-right: 4px;
    }

    @media (max-width: 480px) {
        .action-btn-group .btn-card-action {
            flex: 1 1 calc(50% - 6px);
            padding: 8px 6px;
            font-size: 12px;
        }
    }

    /* Make the dropdown take full width underneath */
    .status-select-form,
    .status-picker {
        width: 100%;
    }
}
@media (max-width: 768px) {
    .filter-pill.hide-mobile {
        display: none !important;
    }
}
        @media (max-width: 640px) {
            .mobile-bottom-bar {
                display: flex;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(12px);
                border-top: 1px solid var(--border-color);
                padding: 10px 16px;
                box-shadow: 0 -4px 12px rgba(0,0,0,0.06);
                z-index: 99;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }

            .mobile-fab-btn {
                flex: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: #059669;
                color: #ffffff;
                text-decoration: none;
                padding: 12px 18px;
                border-radius: 12px;
                font-size: 14px;
                font-weight: 800;
                box-shadow: 0 4px 10px rgba(5,150,105,0.3);
            }

            .mobile-refresh-btn {
                width: 44px;
                height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
                background: #f1f5f9;
                color: #334155;
                text-decoration: none;
                font-size: 20px;
                border: 1px solid var(--border-color);
                flex-shrink: 0;
            }
        }

        @media (max-width: 640px) {
    /* Stack the whole notification item */
    .notif-item {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }

    /* Force the meta info to utilize space better */
    .notif-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 6px;
    }

    /* Restructure the action buttons */
    .notif-actions {
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 8px;
        width: 100%;
    }

    /* Make secondary buttons share the row (50/50 split minus the gap) */
    .notif-actions .action-chip-link,
    .notif-actions .btn-ack-check,
    .notif-actions .btn-card-secondary {
        flex: 1 1 calc(50% - 8px);
        justify-content: center;
        text-align: center;
        padding: 8px 12px; /* Bigger touch target */
    }

    /* Force the primary action to command the full bottom row */
    .notif-actions .btn-deliver-primary {
        flex: 1 1 100%;
        padding: 12px;
        font-size: 14px;
    }
}
    </style>
</head>
<body>

    <!-- Notification Audio Element (C:\xampp\htdocs\liyas-new\assets\videos\notify.wav) -->
    <audio id="orderNotificationAudio" preload="auto">
        <source src="<?= BASE_URL ?>/assets/videos/notify.wav" type="audio/wav">
    </audio>

    <!-- Real-time Toast Notifications Container -->
    <div id="zoneToastContainer" class="zone-toast-container"></div>

    <!-- Top Sticky Header -->
    <header class="portal-header">
        <div class="header-inner">
            <a href="<?= zone_url($zone_slug) ?>" class="brand-cluster">
                <div>
                    <div class="brand-title">Liyas Delivery</div>
                </div>
            </a>

            <div class="header-actions">
                <!-- Vehicle Management Link -->
                <a href="<?= BASE_URL ?>/zone/orders/dashboard/vehicles/?zone=<?= urlencode($zone_slug) ?>&return_url=<?= urlencode(zone_filter_url($zone_slug)) ?>" class="btn-header-action btn-header-vehicles" title="Vehicle Stock & Fleet Management">
                    <i class='bx bxs-truck' style="font-size: 16px;"></i>
                    <span>Vehicles</span>
                </a>

       

                <!-- Quick New Delivery/Receipt -->
                <a href="<?= zone_url($zone_slug, 'generate-receipt') ?>" class="btn-header-action btn-new-bill-header" title="Create Direct Bill / Spot Delivery">
                    <i class='bx bx-plus-circle' style="font-size: 16px;"></i>
                    <span style=" @media(min-width: 480px){display: inline;}">Add Order</span>
                </a>
            </div>
        </div>
    </header>

    <main class="portal-container">

        <!-- Flash messages -->
        <?php if (!empty($msg)): ?>
            <div class="flash-alert">
                <i class='bx bx-check-circle' style="font-size: 20px; color: #059669;"></i>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['receipt_generated'])): ?>
            <div class="flash-alert">
                <i class='bx bxs-party' style="font-size: 20px; color: #059669;"></i>
                <span>Delivery receipt generated successfully! Order has been marked as Delivered.</span>
            </div>
        <?php endif; ?>

        <!-- INCOMING NEW ORDERS NOTIFICATION BANNER -->
        <?php if (!empty($unread_notifications)): ?>
            <section class="notif-banner" aria-label="New Orders">
                <div class="notif-header">
                    <div class="notif-badge-group">
                        <i class='bx bxs-bell-ring notif-bell-icon'></i>
                        <h2 class="notif-title">New Order Alerts</h2>
                        <span class="notif-count-pill"><?= count($unread_notifications) ?> ACTION REQUIRED</span>
                    </div>
                    <a href="<?= zone_url($zone_slug, '', ['mark_all_read' => 1]) ?>" class="btn-mark-all-read">
                        <i class='bx bx-check-double'></i> Mark all read
                    </a>
                </div>

                <?php foreach ($unread_notifications as $notif): 
                    $nShop = htmlspecialchars($notif['shop_name'] ?: ($notif['customer_name'] ?: 'Customer'));
                    $nProd = htmlspecialchars($notif['product_name'] ?: ($notif['fallback_name'] ?: 'Drinking Water'));
                    $nQty  = (int)$notif['quantity'];
                    $nAmt  = formatCurrency($notif['total_amount']);
                    $nOrdId = $notif['order_id'];
                    $nOrdNum = $notif['order_number'] ?: ('#' . $nOrdId);
                    $nPhone = $notif['phone'] ?? '';
                    $nHasNoProducts = empty($notif['items_summary']) && (empty($notif['product_id']) || (int)$notif['product_id'] <= 0);
                    $nSummary = !empty($notif['items_summary']) ? $notif['items_summary'] : (!$nHasNoProducts ? ($nProd . ' &times; ' . $nQty . ' Cases') : 'No products selected (Line Sale)');
                    $waLink = buildWhatsAppLink($nPhone, $nShop, $nOrdNum, $nAmt, $notif['items_summary'] ?? '');
                ?>
                <div class="notif-item" id="notif-card-<?= $nOrdId ?>">
                    <div>
                        <div class="notif-shop">
                            <?= $nShop ?> &bull; <span style="color: #2563eb; font-weight: 700;"><?= htmlspecialchars($nSummary) ?></span>
                            <?php if ((float)($notif['prior_due_amount'] ?? 0) > 0): ?>
                                <span class="badge-prior-due-alert" style="margin-left: 6px;" title="Shop has prior pending due from earlier deliveries">
                                    <i class='bx bxs-error-circle'></i> Prior Due: <?= formatCurrency($notif['prior_due_amount']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($notif['assigned_vehicle_number'])): 
                                $nvId = (int)($notif['resolved_vehicle_id'] ?? ($notif['vehicle_id'] ?? 0));
                                $nvLoad = $vehiclesLoadMap[$nvId] ?? null;
                                $nvCases = $nvLoad ? (int)$nvLoad['total_cases'] : null;
                                $nvBreakdown = $nvLoad ? $nvLoad['breakdown_text'] : '';
                            ?>
                                <span class="order-veh-badge" style="font-size: 10px; padding: 2px 6px; border-radius: 5px; margin-left: 6px;" title="Vehicle: <?= htmlspecialchars($notif['assigned_vehicle_number']) ?><?= !empty($notif['assigned_vehicle_name']) ? ' (' . htmlspecialchars($notif['assigned_vehicle_name']) . ')' : '' ?> | Load Remaining: <?= $nvCases !== null ? $nvCases . ' cs (' . htmlspecialchars($nvBreakdown) . ')' : '0 cs' ?>">
                                    <i class='bx bxs-truck' style="color: #60a5fa;"></i> 
                                    <span><?= htmlspecialchars($notif['assigned_vehicle_number']) ?></span>
                                    <?php if ($nvCases !== null): ?>
                                        <span class="veh-cases-chip"><?= $nvCases ?> cs left</span>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="order-veh-badge badge-veh-unassigned" style="font-size: 10px; padding: 2px 6px; border-radius: 5px; margin-left: 6px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 700;" title="No vehicle assigned to this order yet">
                                    <i class='bx bx-error-circle' style="color: #ef4444;"></i> Vehicle is not assigned
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="notif-meta">
                            <span><i class='bx bx-map-pin' style="color: #ef4444;"></i> <?= htmlspecialchars($notif['location'] ?: 'Zone Area') ?></span>
                            <?php if (!empty($nPhone)): ?>
                                <span><i class='bx bx-phone'></i> <a href="tel:<?= htmlspecialchars($nPhone) ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;"><?= htmlspecialchars($nPhone) ?></a></span>
                            <?php endif; ?>
                            <span>Total: <strong style="color: #0f172a;"><?= $nAmt ?></strong></span>
                        </div>
                    </div>

                    <div class="notif-actions">
                        <?php if (!empty($waLink)): ?>
                            <a href="<?= $waLink ?>" target="_blank" class="action-chip-link chip-whatsapp" title="Send WhatsApp">
                                <i class='bx bxl-whatsapp'></i> Chat
                            </a>
                        <?php endif; ?>
                        <button type="button" class="btn-ack-check" onclick="acknowledgeOrder(<?= $nOrdId ?>, this)" title="Mark as checked by zone staff">
                            <i class='bx bx-check-double'></i> Check
                        </button>
                        <a href="<?= zone_url($zone_slug, 'order', ['id' => $nOrdId]) ?>" class="btn-card-action btn-card-secondary" style="padding: 6px 12px; font-size: 12px; min-height: 36px;">
                            View Order
                        </a>
                        <?php if (empty($notif['assigned_vehicle_number'])): ?>
                            <button type="button" class="btn-card-action btn-card-unassigned-veh" onclick="openAssignVehicleModalZone(<?= $nOrdId ?>, <?= htmlspecialchars(json_encode($nShop), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$nOrdNum), ENT_QUOTES, 'UTF-8') ?>)" style="padding: 6px 14px; font-size: 12px; min-height: 36px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 700;" title="Assign Vehicle to Bill">
                                <i class='bx bxs-truck'></i> Assign Vehicle
                            </button>
                        <?php else: ?>
                            <a href="<?= zone_url($zone_slug, 'generate-receipt', ['order_id' => $nOrdId]) ?>" class="btn-card-action btn-deliver-primary" style="padding: 6px 14px; font-size: 12px; min-height: 36px;">
                                <i class='bx bx-receipt'></i> Deliver &amp; Bill
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- KEY METRICS OVERVIEW (Clickable Quick Filters) -->
        <!-- METRICS WRAPPER WITH HAMBURGER TOGGLE FOR MOBILE -->
        <div class="metrics-dropdown-wrapper">
            <!-- Toggle Button visible only on mobile/small screens -->
            <button type="button" class="btn-metrics-toggle" id="toggleMetricsBtn" aria-expanded="false" aria-controls="metricsGrid">
                <div class="toggle-btn-left">
                    <i class='bx bx-menu-alt-left toggle-hamburger-icon'></i>
                    <span>Dashboard</span>
                </div>
                <i class='bx bx-chevron-down toggle-chevron' id="toggleMetricsChevron"></i>
            </button>

            <!-- KEY METRICS OVERVIEW (Collapsed by default on mobile) -->
            <section class="metrics-grid" id="metricsGrid">
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'pending']) ?>" class="metric-card metric-pending <?= ($filter === 'pending') ? 'active-card' : '' ?>" title="Click to show pending deliveries">
                    <div class="metric-card-top">
                        <span class="metric-label">Pending</span>
                        <div class="metric-icon-box">
                            <i class='bx bx-time-five'></i>
                        </div>
                    </div>
                    <div>
                        <div class="metric-val"><?= (int)$metrics['pending_count'] ?></div>
                        <div class="metric-hint">
                            <span>Orders to deliver</span>
                            <i class='bx bx-chevron-right'></i>
                        </div>
                    </div>
                </a>

                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'delivered']) ?>" class="metric-card metric-delivered <?= ($filter === 'delivered') ? 'active-card' : '' ?>" title="Click to show delivered orders">
                    <div class="metric-card-top">
                        <span class="metric-label">Delivered</span>
                        <div class="metric-icon-box">
                            <i class='bx bx-check-circle'></i>
                        </div>
                    </div>
                    <div>
                        <div class="metric-val"><?= (int)$metrics['delivered_count'] ?></div>
                        <div class="metric-hint">
                            <span>Delivered orders</span>
                            <i class='bx bx-chevron-right'></i>
                        </div>
                    </div>
                </a>

                <!-- In-Hand Cash Collected -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'cash']) ?>" class="metric-card metric-cash <?= ($filter === 'cash') ? 'active-card' : '' ?>" title="Click to show orders with cash collected">
                    <div class="metric-card-top">
                        <span class="metric-label">In-Hand Cash</span>
                        <div class="metric-icon-box">
                            <i class='bx bx-money'></i>
                        </div>
                    </div>
                    <div>
                        <div class="metric-val"><?= formatCurrency($metrics['total_cash_collected']) ?></div>
                        <div class="metric-hint">
                            <span>Cash collected</span>
                            <i class='bx bx-chevron-right'></i>
                        </div>
                    </div>
                </a>

                <!-- Total Credited Amount -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'credit']) ?>" class="metric-card metric-credit <?= ($filter === 'credit') ? 'active-card' : '' ?>" title="Click to show orders with credit">
                    <div class="metric-card-top">
                        <span class="metric-label">Total Credited</span>
                        <div class="metric-icon-box">
                            <i class='bx bx-credit-card'></i>
                        </div>
                    </div>
                    <div>
                        <div class="metric-val"><?= formatCurrency($metrics['total_credit']) ?></div>
                        <div class="metric-hint">
                            <span>Credit amount</span>
                            <i class='bx bx-chevron-right'></i>
                        </div>
                    </div>
                </a>

                <!-- Outstanding Due (Cumulative, NOT reset daily) -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'due']) ?>" class="metric-card metric-due <?= ($filter === 'due') ? 'active-card' : '' ?>" title="Click to show all orders with pending due balance or prior dues">
                    <div class="metric-card-top">
                        <span class="metric-label">Outstanding Due</span>
                        <div class="metric-icon-box">
                            <i class='bx bx-error-circle'></i>
                        </div>
                    </div>
                    <div>
                        <div class="metric-val" style="color: <?= ((float)$metrics['total_due'] > 0) ? '#dc2626' : '#64748b' ?>;">
                            <?= formatCurrency($metrics['total_due']) ?>
                        </div>
                        <div class="metric-hint" style="color: <?= ((float)$metrics['total_due'] > 0) ? '#dc2626' : '#64748b' ?>;">
                            <span>All-Time Outstanding</span>
                            <i class='bx bx-chevron-right'></i>
                        </div>
                    </div>
                </a>
            </section>
        </div>

        <!-- CURRENT VEHICLE LOAD STATUS WIDGET -->
        <div class="vehicle-load-panel" id="vehicleLoadPanel">
            <?php if (!empty($zoneVehicles)): ?>
                <div class="vehicle-load-card">
                    <div class="vehicle-load-header">
                        <div class="vehicle-load-title-group">
                            <!-- <span class="vehicle-truck-icon-box">
                                <i class='bx bxs-truck'></i>
                            </span> -->
                            <!-- <div>
                                <div class="vehicle-load-title">Vehicle Stock &amp; Remaining Load</div>
                             
                            </div> -->
                              <div class="vehicle-load-badge-group">
                                    <span class="zone-badge">
                    <span class="pulse-dot"></span>
                    <span><?= htmlspecialchars($zone_name) ?></span>
                </span>
                           
                        </div>
                        </div>
                      
                    </div>

                    <div class="vehicle-cards-grid">
                        <?php foreach ($zoneVehicles as $zv): 
                            $vId = (int)$zv['id'];
                            $vData = $vehiclesLoadMap[$vId] ?? null;
                            $totCases = $vData ? (int)$vData['total_cases'] : 0;
                            $storageCases = $vData ? (int)$vData['storage_cases'] : 0;
                            $orderCases = $vData ? (int)$vData['order_cases'] : 0;
                            $loadedProds = $vData['loaded_products'] ?? [];
                            $cap = (int)($zv['capacity_cases'] ?? 0);
                            $pct = ($cap > 0) ? min(100, round(($totCases / $cap) * 100)) : null;
                            $vDamaged = $zoneDamagedSummary[$vId] ?? null;
                            $vDamagedCases = $vDamaged ? (int)$vDamaged['total_damaged_cases'] : 0;
                            $vDamagedItems = $vDamaged['items'] ?? [];
                        ?>
                        <div class="vehicle-item-box">
                            <div class="vehicle-item-top">
                                <div class="vehicle-name-info">
                                    <span class="veh-num-badge">
                                        <i class='bx bxs-truck' style="color: #60a5fa;"></i> <?= htmlspecialchars($zv['vehicle_number']) ?>
                                    </span>
                                    <!-- <?php if (!empty($zv['vehicle_name'])): ?>
                                        <span class="veh-name-text"><?= htmlspecialchars($zv['vehicle_name']) ?></span>
                                    <?php endif; ?> -->
                                    <?php if (!empty($zv['driver_name'])): ?>
                                        <span class="veh-driver-tag" title="Assigned Driver">
                                            <i class='bx bx-user'></i> <?= htmlspecialchars($zv['driver_name']) ?>
                                            <?php if (!empty($zv['driver_phone'])): ?>
                                                <a href="tel:<?= htmlspecialchars($zv['driver_phone']) ?>" style="color: #2563eb; text-decoration: none; margin-left: 2px;" title="Call Driver">
                                                    <i class='bx bx-phone'></i>
                                                </a>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <?php if ($vDamagedCases > 0): ?>
                                        <div class="vehicle-damaged-counter" title="Reported damaged items on this vehicle">
                                            <i class='bx bx-error-circle'></i>
                                            <span class="counter-num"><?= $vDamagedCases ?></span>
                                            <span class="counter-unit">Damaged cs</span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="vehicle-load-counter <?= ($totCases <= 0) ? 'counter-empty' : '' ?>" title="Remaining load cases in this vehicle">
                                        <span class="counter-num"><?= $totCases ?></span>
                                        <span class="counter-unit">Cases Left</span>
                                    </div>

                                    <?php
                                    $loadedSummary = [];
                                    if (!empty($loadedProds)) {
                                        foreach ($loadedProds as $lp) {
                                            if (!empty($lp['product_id'])) {
                                                $loadedSummary[] = [
                                                    'id'    => (int)$lp['product_id'],
                                                    'name'  => (string)$lp['product_name'],
                                                    'cases' => (int)($lp['loaded_cases'] ?? 0)
                                                ];
                                            }
                                        }
                                    }
                                    ?>
                                    <button type="button" 
                                            class="btn-add-damaged" 
                                            data-vehicle-id="<?= $vId ?>" 
                                            data-vehicle-number="<?= htmlspecialchars($zv['vehicle_number'], ENT_QUOTES, 'UTF-8') ?>" 
                                            data-loaded-json="<?= htmlspecialchars(json_encode($loadedSummary, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>" 
                                            onclick="openVehicleDamagedModalFromBtn(this)" 
                                            title="Report damaged items on this vehicle">
                                        <i class='bx bx-plus-circle'></i> Add Damaged
                                    </button>
                                </div>
                            </div>

                        

                            <div class="veh-breakdown-row">
                                <?php if (!empty($loadedProds)): ?>
                                    <?php foreach ($loadedProds as $lp): 
                                        if (empty($lp['loaded_cases'])) continue;
                                    ?>
                                        <span class="veh-prod-pill">
                                            <strong><?= htmlspecialchars($lp['product_name']) ?></strong>: 
                                            <span class="pill-qty"><?= (int)$lp['loaded_cases'] ?> cs</span>
                                         
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: #94a3b8; font-style: italic;">
                                        <i class='bx bx-info-circle'></i> Vehicle is currently empty (0 cases loaded)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($vDamagedItems)): ?>
                                <div class="veh-damaged-breakdown-row">
                                    <span class="veh-damaged-label"><i class='bx bx-shield-x'></i> Damaged Items:</span>
                                    <?php foreach ($vDamagedItems as $vdi): ?>
                                        <span class="veh-damaged-pill" title="Reported: <?= date('d M Y, h:i A', strtotime($vdi['created_at'])) ?><?= !empty($vdi['notes']) ? ' &bull; Note: ' . htmlspecialchars($vdi['notes']) : '' ?>">
                                            <strong><?= htmlspecialchars($vdi['product_name']) ?></strong>: 
                                            <span class="pill-damaged-qty"><?= (int)$vdi['damaged_cases'] ?> cs</span>
                                            <?php if (!empty($vdi['notes'])): ?>
                                                <span class="pill-damaged-note">(<?= htmlspecialchars($vdi['notes']) ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="vehicle-load-card" style="padding: 18px 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #ffffff; border: 1.5px dashed #cbd5e1; border-radius: var(--radius-lg);">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span class="vehicle-truck-icon-box" style="background: #f1f5f9; color: #64748b; box-shadow: none;">
                            <i class='bx bxs-truck'></i>
                        </span>
                        <div>
                            <div style="font-size: 14px; font-weight: 700; color: #1e293b;">No vehicles assigned to <?= htmlspecialchars($zone_name) ?></div>
                            <div style="font-size: 12px; color: #64748b;">Manage vehicles and line stock allocations for this zone.</div>
                        </div>
                    </div>
                    <a href="<?= BASE_URL ?>/zone/orders/dashboard/vehicles/?zone=<?= urlencode($zone_slug) ?>&return_url=<?= urlencode(zone_filter_url($zone_slug)) ?>" class="btn-manage-vehicles" style="background: #2563eb; color: #fff; border-color: #2563eb;">
                        <i class='bx bxs-truck'></i>
                        <span>Vehicles Dashboard</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- DATE FILTER & DAILY RESET TOOLBAR -->
        <section class="date-filter-section" id="dateFilterSection">
            <div class="date-filter-header">
                <div class="date-filter-title-row">
                    <div class="date-scope-indicator">
                        <i class='bx bx-calendar-event'></i>
                        <span><strong><?= htmlspecialchars($dateLabel) ?></strong></span>
                        <!-- <?php if ($date_preset === 'today'): ?>
                            <span class="daily-reset-tag" title="Operational delivery counts reset daily"><i class='bx bx-refresh'></i> Daily Reset</span>
                        <?php endif; ?> -->
                    </div>
                    <!-- <?php if ($date_preset !== 'today'): ?>
                        <a href="<?= zone_filter_url($zone_slug, ['date_preset' => 'today', 'date_from' => null, 'date_to' => null]) ?>" class="btn-reset-today" title="Reset view back to Today">
                            <i class='bx bx-redo'></i> Switch to Today (Daily)
                        </a>
                    <?php endif; ?> -->
                </div>

                <div class="date-preset-pills-row">
                    <a href="<?= zone_filter_url($zone_slug, ['date_preset' => 'today', 'date_from' => null, 'date_to' => null]) ?>" class="date-preset-pill <?= ($date_preset === 'today') ? 'active' : '' ?>">
                        <i class='bx bx-calendar-check'></i> Today
                    </a>
                    <a href="<?= zone_filter_url($zone_slug, ['date_preset' => 'yesterday', 'date_from' => null, 'date_to' => null]) ?>" class="date-preset-pill <?= ($date_preset === 'yesterday') ? 'active' : '' ?>">
                        <i class='bx bx-history'></i> Yesterday
                    </a>
                
                   
                    <button type="button" class="date-preset-pill <?= ($date_preset === 'custom') ? 'active' : '' ?>" onclick="toggleCustomDatePicker()" id="customDateToggleBtn">
                        <i class='bx bx-calendar-edit'></i> Custom Range <?= ($date_preset === 'custom') ? '▼' : '...' ?>
                    </button>
                </div>
            </div>

            <!-- Custom Date Picker Dropdown / Form -->
            <div class="custom-date-picker-box" id="customDatePickerBox" style="<?= ($date_preset === 'custom') ? 'display: block;' : 'display: none;' ?>">
                <form action="<?= zone_url($zone_slug) ?>" method="GET" class="custom-date-form">
                    <input type="hidden" name="date_preset" value="custom">
                    <?php if (!empty($filter) && $filter !== 'all'): ?>
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <?php endif; ?>
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>

                    <div class="custom-date-inputs">
                        <div class="date-input-group">
                            <label for="date_from"><i class='bx bx-calendar'></i> From:</label>
                            <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from ?: date('Y-m-d')) ?>" class="date-field" required>
                        </div>
                        <div class="date-input-group">
                            <label for="date_to"><i class='bx bx-calendar'></i> To:</label>
                            <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to ?: date('Y-m-d')) ?>" class="date-field" required>
                        </div>
                        <div class="custom-date-actions">
                            <button type="submit" class="btn-apply-date">
                                <i class='bx bx-filter-alt'></i> Apply Date
                            </button>
                            <button type="button" onclick="toggleCustomDatePicker()" class="btn-cancel-date">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        

        <!-- CONTROLS: FILTER CHIPS & LIVE SEARCH -->
        <section class="controls-panel">
            <div class="filter-scroll-container">
                <!-- Keep on Mobile: All Orders -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'all']) ?>" class="filter-pill <?= ($filter === 'all') ? 'active' : '' ?>">
                    <span>All Orders</span>
                    <span class="filter-counter"><?= (int)$status_counts['total'] ?></span>
                </a>

                <!-- Keep on Mobile: Due (Cumulative, includes pending prior dues) -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'due']) ?>" class="filter-pill <?= ($filter === 'due') ? 'active' : '' ?>" style="border-color: #fecaca; background: <?= ($filter === 'due') ? '#dc2626' : '#fef2f2' ?>; color: <?= ($filter === 'due') ? '#fff' : '#dc2626' ?>;" title="All-time active dues and prior unpaid dues">
                    <i class='bx bx-error-circle'></i>
                    <span>Due</span>
                    <span class="filter-counter"><?= (int)$status_counts['due_count'] ?></span>
                </a>

                <!-- Hide on Mobile: Pending -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'pending']) ?>" class="filter-pill hide-mobile <?= ($filter === 'pending') ? 'active' : '' ?>">
                    <span>Pending</span>
                    <span class="filter-counter"><?= (int)$status_counts['pending'] ?></span>
                </a>

                <!-- Hide on Mobile: Delivered -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'delivered']) ?>" class="filter-pill hide-mobile <?= ($filter === 'delivered') ? 'active' : '' ?>">
                    <span>Delivered</span>
                    <span class="filter-counter"><?= (int)$status_counts['delivered'] ?></span>
                </a>

                <!-- Keep on Mobile: Cash Paid -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'cash']) ?>" class="filter-pill <?= ($filter === 'cash') ? 'active' : '' ?>">
                    <span>Cash Paid</span>
                    <span class="filter-counter"><?= (int)$status_counts['cash_count'] ?></span>
                </a>

                <!-- Hide on Mobile: Credited -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'credit']) ?>" class="filter-pill hide-mobile <?= ($filter === 'credit') ? 'active' : '' ?>">
                    <span>Credited</span>
                    <span class="filter-counter"><?= (int)$status_counts['credit_count'] ?></span>
                </a>

                <!-- Hide on Mobile: Processing -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'processing']) ?>" class="filter-pill hide-mobile <?= ($filter === 'processing') ? 'active' : '' ?>">
                    <span>Processing</span>
                    <span class="filter-counter"><?= (int)$status_counts['processing'] ?></span>
                </a>

                <!-- Hide on Mobile: Shipped -->
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'shipped']) ?>" class="filter-pill hide-mobile <?= ($filter === 'shipped') ? 'active' : '' ?>">
                    <span>Shipped</span>
                    <span class="filter-counter"><?= (int)$status_counts['shipped'] ?></span>
                </a>
            </div>

            <div class="search-box">
                <i class='bx bx-search search-icon'></i>
                <input type="text" id="liveSearchInput" placeholder="Filter shop, phone, bill..." class="search-input" value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                <button type="button" id="clearSearchBtn" class="search-clear-btn" title="Clear search">
                    <i class='bx bx-x'></i>
                </button>
            </div>
        </section>

        <!-- Current Filter Indicator Message -->
        <?php if ($filter !== 'all'): ?>
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding: 8px 14px; background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 13px;">
                <span>
                    Filtered by: <strong><?= ucfirst($filter === 'due' ? 'Outstanding Due (Including Prior Dues)' : ($filter === 'credit' ? 'Credited Orders' : ($filter === 'cash' ? 'Cash Collected' : $filter))) ?></strong> 
                    (<?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?> found)
                </span>
                <a href="<?= zone_filter_url($zone_slug, ['filter' => 'all']) ?>" style="color: #2563eb; text-decoration: none; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                    <i class='bx bx-x'></i> Clear filter
                </a>
            </div>
        <?php endif; ?>

        <!-- ORDER CARDS LISTING -->
        <div class="orders-list" id="ordersContainer">
            <?php if (empty($orders)): ?>
                <div class="empty-state-box">
                    <i class='bx bx-box empty-state-icon'></i>
                    <h3 class="empty-state-title">No orders found</h3>
                    <p style="font-size: 14px; margin-bottom: 16px;">
                        <?php if ($filter === 'all' && $date_preset === 'today'): ?>
                            There are currently no deliveries placed today (<?= date('d M Y') ?>) in <?= htmlspecialchars($zone_name) ?>.
                            <?php if (!empty($status_counts['due_count'])): ?>
                                <br><strong style="color: #dc2626;">There are <?= (int)$status_counts['due_count'] ?> pending unpaid dues to collect across earlier orders.</strong>
                            <?php endif; ?>
                        <?php else: ?>
                            There are currently no orders in <?= htmlspecialchars($zone_name) ?> matching the filter "<strong><?= htmlspecialchars($filter) ?></strong>" for <?= htmlspecialchars($dateLabel) ?>.
                        <?php endif; ?>
                    </p>
                    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                        <?php if (!empty($status_counts['due_count']) && $filter !== 'due'): ?>
                            <a href="<?= zone_filter_url($zone_slug, ['filter' => 'due']) ?>" class="btn-card-action" style="font-size: 13px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 700;">
                                <i class='bx bx-error-circle'></i> View Outstanding Dues (<?= (int)$status_counts['due_count'] ?>)
                            </a>
                        <?php endif; ?>
                        <?php if ($date_preset !== 'all'): ?>
                            <a href="<?= zone_filter_url($zone_slug, ['date_preset' => 'all', 'filter' => 'all']) ?>" class="btn-card-action btn-card-secondary" style="font-size: 13px;">
                                <i class='bx bx-history'></i> View All Time
                            </a>
                        <?php endif; ?>
                        <a href="<?= zone_filter_url($zone_slug, ['date_preset' => 'today', 'filter' => 'all']) ?>" class="btn-card-action btn-card-secondary" style="font-size: 13px;">
                            Reset Filters
                        </a>
                        <a href="<?= zone_url($zone_slug, 'generate-receipt') ?>" class="btn-card-action btn-deliver-primary" style="font-size: 13px;">
                            <i class='bx bx-plus-circle'></i> Create Spot Delivery
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $order): 
                    $ordId = $order['order_id'];
                    $ordNum = $order['order_number'] ?: ('#' . $ordId);
                    $shop = htmlspecialchars($order['shop_name'] ?: ($order['customer_name'] ?: 'Customer'));
                    $loc = htmlspecialchars($order['location'] ?: 'Area / Shop');
                    $prod = htmlspecialchars($order['product_name'] ?: ($order['fallback_name'] ?: 'Liyas Mineral Water'));
                    $hasNoProducts = empty($order['items_summary']) && (empty($order['product_id']) || (int)$order['product_id'] <= 0);
                    $itemsSummary = !empty($order['items_summary']) ? htmlspecialchars($order['items_summary']) : (!$hasNoProducts ? $prod : 'No products selected (Line Sale)');
                    $qty = (int)$order['quantity'];
                    $amount = formatCurrency($order['total_amount']);
                    $status = strtolower($order['status']);
                    $phone = $order['phone'] ?? '';
                    $hasReceipt = !empty($order['bill_number']);
                    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($loc . ' ' . $zone_name);
                    $waLink = buildWhatsAppLink($phone, $shop, $ordNum, $amount, $itemsSummary);
                    $createdDate = date('d M Y, h:i A', strtotime($order['created_at']));
                    $searchData = strtolower($shop . ' ' . $ordNum . ' ' . $loc . ' ' . $phone . ' ' . ($order['bill_number'] ?? ''));
                ?>
                <article class="order-card" data-search="<?= htmlspecialchars($searchData) ?>">
                    <!-- Card Header -->
                    <div class="order-card-header">
                        <div>
                            <h3 class="shop-name-title"><?= $shop ?></h3>
                            <div class="order-meta-row">
                                <span class="order-id-tag" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= htmlspecialchars($ordNum) ?>')" title="Click to copy">
                                    <i class='bx bx-copy-alt' style="font-size: 12px;"></i>
                                    <?= htmlspecialchars($ordNum) ?>
                                </span>
                                <?php if (!empty($order['is_reorder'])): ?>
                                    <span style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px; letter-spacing: 0.02em;" title="<?= !empty($order['parent_order_id']) ? 'Reordered from Order #' . $order['parent_order_id'] : 'Reorder' ?>">
                                        🔁 REORDER
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($order['assigned_vehicle_number'])): 
                                    $ovId = (int)($order['resolved_vehicle_id'] ?? ($order['vehicle_id'] ?? 0));
                                    $ovLoad = $vehiclesLoadMap[$ovId] ?? null;
                                    $ovCases = $ovLoad ? (int)$ovLoad['total_cases'] : null;
                                    $ovBreakdown = $ovLoad ? $ovLoad['breakdown_text'] : '';
                                ?>
                                    <span class="order-veh-badge" title="Vehicle: <?= htmlspecialchars($order['assigned_vehicle_number']) ?><?= !empty($order['assigned_vehicle_name']) ? ' (' . htmlspecialchars($order['assigned_vehicle_name']) . ')' : '' ?> | Load Remaining: <?= $ovCases !== null ? $ovCases . ' cs (' . htmlspecialchars($ovBreakdown) . ')' : '0 cs' ?>">
                                        <i class='bx bxs-truck' style="color: #60a5fa;"></i> 
                                        <span><?= htmlspecialchars($order['assigned_vehicle_number']) ?></span>
                                        <?php if ($ovCases !== null): ?>
                                            <span class="veh-cases-chip"><?= $ovCases ?> cs left</span>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="order-veh-badge badge-veh-unassigned" style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 700;" title="No vehicle assigned to this order yet">
                                        <i class='bx bx-error-circle' style="color: #ef4444;"></i>
                                        <span>Vehicle is not assigned</span>
                                    </span>
                                <?php endif; ?>
                                <?php if ((int)$order['is_zone_read'] === 0): ?>
                                    <span class="badge-unread-chip" id="unread-tag-<?= $ordId ?>" title="Not yet checked by zone staff">
                                        <i class='bx bxs-bell-ring'></i> New
                                    </span>
                                <?php endif; ?>
                                <?php if ((float)($order['prior_due_amount'] ?? 0) > 0): ?>
                                    <span class="badge-prior-due-alert" title="Shop has prior pending due from earlier deliveries">
                                        <i class='bx bxs-error-circle'></i> Prior Due: <?= formatCurrency($order['prior_due_amount']) ?>
                                    </span>
                                <?php endif; ?>
                                <span>&bull;</span>
                                <span><?= $createdDate ?></span>
                            </div>
                        </div>

                        <div>
                            <span class="status-badge status-<?= $status ?>">
                                <span class="status-dot"></span>
                                <?= ucfirst($status) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="order-card-body">
                        <!-- Left Details: Product & Contacts -->
                        <div class="contact-box">
                            <div class="product-highlight">
                                <span><?= $itemsSummary ?></span>
                                <span class="qty-pill"><?= $qty ?> Cases</span>
                            </div>

                            <div class="contact-actions-row">
                                <a href="<?= $mapsUrl ?>" target="_blank" class="action-chip-link chip-map" title="Open Map Navigation">
                                    <i class='bx bx-map-pin'></i> <?= $loc ?>
                                </a>

                                <?php if (!empty($phone)): ?>
                                    <a href="tel:<?= htmlspecialchars($phone) ?>" class="action-chip-link chip-call" title="Call Customer">
                                        <i class='bx bx-phone'></i>
                                    </a>

                                    <?php if (!empty($waLink)): ?>
                                        <a href="<?= $waLink ?>" target="_blank" class="action-chip-link chip-whatsapp" title="Send WhatsApp">
                                            <i class='bx bxl-whatsapp'></i>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right Details: Pricing & Bill status -->
                        <div class="price-summary-box">
                            <div class="total-amount-display"><?= $amount ?></div>
                            <?php if ((float)$order['discount'] > 0): ?>
                                <div>
                                    <span class="discount-pill">- <?= formatCurrency($order['discount']) ?> discount</span>
                                </div>
                            <?php endif; ?>

                            <div class="bill-info-tag">
                                <?php 
                                $ordReceiptCash = (float)($order['receipt_cash'] ?? 0);
                                $ordReceiptCredit = (float)($order['receipt_credit'] ?? 0);
                                $ordReceiptDue = (float)($order['receipt_due'] ?? 0);
                                $ordPriorDue = (float)($order['prior_due_amount'] ?? 0);
                                $ordTotalDue = $ordReceiptDue + $ordPriorDue;
                                ?>
                                <?php if ($hasReceipt): ?>
                                    <span class="bill-number-badge">
                                        <i class='bx bx-check'></i> Bill: <?= htmlspecialchars($order['bill_number']) ?>
                                    </span>
                                    <?php if ($ordReceiptCash > 0): ?>
                                        <span style="font-size: 11px; color: #047857; font-weight: 700;">Cash: <?= formatCurrency($ordReceiptCash) ?></span>
                                    <?php endif; ?>
                                    <?php if ($ordReceiptCredit > 0): ?>
                                        <span class="credit-badge">Credit: <?= formatCurrency($ordReceiptCredit) ?></span>
                                    <?php endif; ?>
                                    <?php if ($ordTotalDue > 0): ?>
                                        <span class="due-badge" title="Total Due: <?= formatCurrency($ordTotalDue) ?><?= ($ordPriorDue > 0 && $ordReceiptDue > 0) ? ' (Current Order: ' . formatCurrency($ordReceiptDue) . ' + Prior Unpaid: ' . formatCurrency($ordPriorDue) . ')' : '' ?>">
                                            <i class='bx bx-error-circle'></i> Due: <?= formatCurrency($ordTotalDue) ?>
                                            <?php if ($ordPriorDue > 0 && $ordReceiptDue > 0): ?>
                                                <span style="font-size: 10px; font-weight: 600; opacity: 0.95;">(Incl. Prior: <?= formatCurrency($ordPriorDue) ?>)</span>
                                            <?php elseif ($ordPriorDue > 0 && $ordReceiptDue <= 0): ?>
                                                <span style="font-size: 10px; font-weight: 600; opacity: 0.95;">(Prior Due)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #d97706; font-size: 11px;">
                                        <i class='bx bx-info-circle'></i> Unbilled
                                    </span>
                                    <?php if ($ordTotalDue > 0): ?>
                                        <span class="due-badge" title="Total Prior Due: <?= formatCurrency($ordTotalDue) ?>">
                                            <i class='bx bx-error-circle'></i> Due: <?= formatCurrency($ordTotalDue) ?>
                                            <span style="font-size: 10px; font-weight: 600; opacity: 0.95;">(Prior Due)</span>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Touch Actions Bar -->
                 <div class="order-actions-bar">
    <!-- Row of action buttons: (Bill/Receipt), Details, Discount, and Reorder -->
    <div class="action-btn-group">
        <?php if (!$hasReceipt): ?>
            <a href="<?= zone_url($zone_slug, 'generate-receipt', ['order_id' => $ordId]) ?>" class="btn-card-action btn-deliver-primary" title="Deliver &amp; Bill Order">
                <i class='bx bx-receipt' style="font-size: 18px;"></i> 
                <span>Bill</span>
            </a>
        <?php else: ?>
            <a href="<?= zone_url($zone_slug, 'receipt', ['id' => $ordId]) ?>" target="_blank" class="btn-card-action btn-receipt-view" title="View Delivery Receipt">
                <i class='bx bx-printer' style="font-size: 17px;"></i> 
                <span>Receipt</span>
            </a>
        <?php endif; ?>

        <a href="<?= zone_url($zone_slug, 'order', ['id' => $ordId]) ?>" class="btn-card-action btn-card-secondary" title="View Order Details">
            <i class='bx bx-show'></i> 
            <span>Details</span>
        </a>

        <a href="<?= zone_url($zone_slug, 'edit-order', ['id' => $ordId]) ?>" class="btn-card-action btn-discount-tag" title="Edit Discount">
            <i class='bx bx-purchase-tag'></i> 
            <span>Discount</span>
        </a>

        <a href="<?= zone_url($zone_slug, 'generate-receipt', ['reorder_id' => $ordId]) ?>" class="btn-card-action btn-card-reorder" title="Place Reorder for <?= htmlspecialchars($shop) ?>">
            <i class='bx bx-refresh'></i> 
            <span>Reorder</span>
        </a>
    </div>

    <?php if ((int)$order['is_zone_read'] === 0): ?>
        <button type="button" class="btn-card-action btn-ack-check" onclick="acknowledgeOrder(<?= $ordId ?>, this)" title="Mark as checked by zone staff">
            <i class='bx bx-check-double'></i> Check
        </button>
    <?php endif; ?>

    <!-- Quick status updater dropdown -->
    <form action="<?= zone_url($zone_slug) ?>" method="POST" class="status-select-form">
        <input type="hidden" name="update_status" value="1">
        <input type="hidden" name="order_id" value="<?= $ordId ?>">
        <select name="status" onchange="this.form.submit()" class="status-picker" aria-label="Change status">
            <option value="pending" <?= ($status === 'pending') ? 'selected' : '' ?>>⏳ Pending</option>
            <option value="processing" <?= ($status === 'processing') ? 'selected' : '' ?>>⚙️ Processing</option>
            <option value="shipped" <?= ($status === 'shipped') ? 'selected' : '' ?>>🚚 Shipped</option>
            <option value="delivered" <?= ($status === 'delivered') ? 'selected' : '' ?>>✅ Delivered</option>
            <option value="cancelled" <?= ($status === 'cancelled') ? 'selected' : '' ?>>❌ Cancelled</option>
        </select>
    </form>
</div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Floating Mobile Bottom Bar for High Ergonomics -->
    <div class="mobile-bottom-bar">
        <a href="<?= zone_url($zone_slug, 'generate-receipt') ?>" class="mobile-fab-btn">
            <i class='bx bx-plus-circle' style="font-size: 20px;"></i>
            <span>New Delivery / Bill</span>
        </a>
        <a href="<?= zone_url($zone_slug) ?>" class="mobile-refresh-btn" title="Refresh portal">
            <i class='bx bx-refresh'></i>
        </a>
    </div>

    <!-- Live Instant Client-Side Filter Script -->
    <script>
        (function() {
            const searchInput = document.getElementById('liveSearchInput');
            const clearBtn = document.getElementById('clearSearchBtn');

            if (!searchInput) return;

            function doFilter() {
                const term = searchInput.value.trim().toLowerCase();
                if (term.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }

                const cards = document.querySelectorAll('.order-card');
                cards.forEach(card => {
                    const data = card.getAttribute('data-search') || '';
                    if (!term || data.includes(term)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }

            window.doFilter = doFilter;

            searchInput.addEventListener('input', doFilter);

            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                doFilter();
                searchInput.focus();
            });

            // If initialized with search query from server
            if (searchInput.value) {
                clearBtn.classList.add('visible');
            }
        })();
    </script>

    <!-- Real-time Order Alerts & Audio Notification Script -->
    <script>
        (function() {
            const notifAudio = document.getElementById('orderNotificationAudio');
            const btnSoundToggle = document.getElementById('btnSoundToggle');
            const soundIcon = document.getElementById('soundIcon');
            const soundLabel = document.getElementById('soundLabel');
            const toastContainer = document.getElementById('zoneToastContainer');
            const zoneSlug = <?= json_encode($zone_slug) ?>;
            const pollUrl = '<?= BASE_URL ?>/zone/check_new_orders.php?zone=' + encodeURIComponent(zoneSlug);

            let audioUnlocked = false;
            let soundEnabled = true;
            let lastAudioPlayTime = 0;

            // Set of known unread order IDs on this client
            const knownUnreadIds = new Set(<?= json_encode(array_values(array_map(fn($o) => (int)$o['order_id'], $unread_notifications))) ?>);

            // Attempt to prime / unlock audio
            function unlockAudioContext() {
                if (audioUnlocked || !notifAudio) return;
                notifAudio.play().then(() => {
                    notifAudio.pause();
                    notifAudio.currentTime = 0;
                    audioUnlocked = true;
                    if (soundLabel) soundLabel.textContent = 'Sound Active';
                    const prompt = document.getElementById('audioUnlockBanner');
                    if (prompt) prompt.remove();
                }).catch(() => {
                    // Waiting for user gesture
                });
            }

            document.addEventListener('click', unlockAudioContext, { once: false });
            document.addEventListener('touchstart', unlockAudioContext, { once: false });

            // Play notification sound (notify.wav)
            function playNotificationSound(force = false) {
                if (!notifAudio || (!soundEnabled && !force)) return;
                const now = Date.now();
                if (!force && (now - lastAudioPlayTime < 2500)) {
                    return; // Throttle sound playback
                }
                lastAudioPlayTime = now;

                try {
                    notifAudio.currentTime = 0;
                    const p = notifAudio.play();
                    if (p !== undefined) {
                        p.then(() => {
                            audioUnlocked = true;
                            if (btnSoundToggle) {
                                btnSoundToggle.classList.add('playing');
                                setTimeout(() => btnSoundToggle.classList.remove('playing'), 1200);
                            }
                        }).catch(err => {
                            console.log('Audio autoplay requires user interaction:', err);
                            showAudioUnlockBanner();
                        });
                    }
                } catch (e) {
                    console.error('Audio play exception:', e);
                }
            }

            // Banner prompt if browser blocked autoplay
            function showAudioUnlockBanner() {
                if (document.getElementById('audioUnlockBanner')) return;
                const banner = document.createElement('div');
                banner.id = 'audioUnlockBanner';
                banner.className = 'audio-unlock-banner';
                banner.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #1e40af;">
                        <i class='bx bxs-volume-full' style="font-size: 18px;"></i>
                        <span>Click anywhere to enable loud notification sound for incoming orders</span>
                    </div>
                    <span style="font-size: 11px; background: #2563eb; color: #fff; padding: 4px 10px; border-radius: 6px; font-weight: 700;">Enable Sound</span>
                `;
                banner.addEventListener('click', () => {
                    unlockAudioContext();
                    playNotificationSound(true);
                });
                const container = document.querySelector('.portal-container');
                if (container && container.firstChild) {
                    container.insertBefore(banner, container.firstChild);
                }
            }

            // Sound toggle / test button handler
            if (btnSoundToggle) {
                btnSoundToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    unlockAudioContext();
                    playNotificationSound(true);
                    showToast('Sound Alert Test', 'notify.wav played successfully! Volume is active.', null, 'bx-volume-full');
                });
            }

            // Toast notification display
            function showToast(title, body, link, iconClass = 'bxs-bell-ring') {
                if (!toastContainer) return;
                const toast = document.createElement('div');
                toast.className = 'zone-toast';
                toast.innerHTML = `
                    <i class='bx ${iconClass} zone-toast-icon'></i>
                    <div class="zone-toast-content">
                        <div class="zone-toast-title">${title}</div>
                        <div class="zone-toast-body">${body}</div>
                        ${link ? `<a href="${link}" style="display: inline-block; margin-top: 6px; color: #93c5fd; font-weight: 700; text-decoration: underline; font-size: 12px;">View Order &rarr;</a>` : ''}
                    </div>
                    <button type="button" class="zone-toast-close" onclick="this.closest('.zone-toast').remove()">&times;</button>
                `;
                toastContainer.appendChild(toast);
                setTimeout(() => {
                    toast.classList.add('closing');
                    setTimeout(() => toast.remove(), 350);
                }, 9000);
            }

            // Real-time AJAX refresh function (runs every 3 seconds)
            let isRefreshing = false;
            function refreshZonePage() {
                if (isRefreshing) return;

                // If user is actively typing in an input or selecting a dropdown inside orders, defer replacing that block
                const activeEl = document.activeElement;
                const isFormActive = activeEl && (activeEl.tagName === 'SELECT' || activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA');

                isRefreshing = true;
                fetch(window.location.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => {
                    if (!r.ok) throw new Error('Network error: ' + r.status);
                    return r.text();
                })
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    // 1. Detect any newly arrived unread orders
                    let hasNewIncoming = false;
                    doc.querySelectorAll('.notif-item').forEach(item => {
                        const idMatch = item.id && item.id.match(/notif-card-(\d+)/);
                        if (idMatch) {
                            const ordId = parseInt(idMatch[1]);
                            if (!knownUnreadIds.has(ordId)) {
                                knownUnreadIds.add(ordId);
                                hasNewIncoming = true;
                                const shop = item.querySelector('.notif-shop')?.innerText?.trim() || 'New Order';
                                const meta = item.querySelector('.notif-meta')?.innerText?.trim() || '';
                                showToast('🔔 New Order Alert', `<strong>${shop}</strong><br><small>${meta}</small>`);
                            }
                        }
                    });

                    // Check newly marked unread cards in orders listing
                    doc.querySelectorAll('#ordersContainer .badge-unread-chip').forEach(badge => {
                        const idMatch = badge.id && badge.id.match(/unread-tag-(\d+)/);
                        if (idMatch) {
                            const ordId = parseInt(idMatch[1]);
                            if (!knownUnreadIds.has(ordId)) {
                                knownUnreadIds.add(ordId);
                                hasNewIncoming = true;
                            }
                        }
                    });

                    if (hasNewIncoming) {
                        playNotificationSound();
                        if (navigator.vibrate) {
                            navigator.vibrate([250, 100, 250]);
                        }
                    }

                    // 2. Update window title
                    if (doc.title) {
                        document.title = doc.title;
                    }

                    // 3. Update Notification Banner
                    const currentBanner = document.querySelector('.notif-banner');
                    const newBanner = doc.querySelector('.notif-banner');
                    const mainContainer = document.querySelector('.portal-container');

                    if (newBanner) {
                        if (currentBanner) {
                            currentBanner.innerHTML = newBanner.innerHTML;
                        } else if (mainContainer) {
                            const dropdownWrapper = mainContainer.querySelector('.metrics-dropdown-wrapper');
                            if (dropdownWrapper) {
                                mainContainer.insertBefore(newBanner, dropdownWrapper);
                            } else {
                                mainContainer.prepend(newBanner);
                            }
                        }
                    } else if (currentBanner) {
                        currentBanner.remove();
                    }

                    // 3.1 Update Vehicle Load Status Panel
                    const currentVehPanel = document.getElementById('vehicleLoadPanel');
                    const newVehPanel = doc.getElementById('vehicleLoadPanel');
                    if (currentVehPanel && newVehPanel) {
                        currentVehPanel.innerHTML = newVehPanel.innerHTML;
                    }

                    // 3.2 Update Date Filter Section (while preserving custom date picker open state)
                    const currentDateFilter = document.getElementById('dateFilterSection');
                    const newDateFilter = doc.getElementById('dateFilterSection');
                    if (currentDateFilter && newDateFilter) {
                        const customBox = document.getElementById('customDatePickerBox');
                        const isCustomOpen = customBox && customBox.style.display !== 'none';
                        currentDateFilter.innerHTML = newDateFilter.innerHTML;
                        if (isCustomOpen) {
                            const reloadedBox = document.getElementById('customDatePickerBox');
                            if (reloadedBox) reloadedBox.style.display = 'block';
                        }
                    }

                    // 4. Update Metrics Grid while preserving mobile accordion open state
                    const currentMetrics = document.getElementById('metricsGrid');
                    const newMetrics = doc.getElementById('metricsGrid');
                    if (currentMetrics && newMetrics) {
                        const isMobileOpen = currentMetrics.classList.contains('show-mobile');
                        currentMetrics.innerHTML = newMetrics.innerHTML;
                        if (isMobileOpen) {
                            currentMetrics.classList.add('show-mobile');
                        }
                    }

                    // 5. Update Status Filter Pills & Counters
                    const currentFilterScroll = document.querySelector('.filter-scroll-container');
                    const newFilterScroll = doc.querySelector('.filter-scroll-container');
                    if (currentFilterScroll && newFilterScroll) {
                        currentFilterScroll.innerHTML = newFilterScroll.innerHTML;
                    }

                    // 6. Update Orders Listing Container
                    const currentOrders = document.getElementById('ordersContainer');
                    const newOrders = doc.getElementById('ordersContainer');
                    if (currentOrders && newOrders) {
                        const isOrdersFocused = isFormActive && currentOrders.contains(activeEl);
                        if (!isOrdersFocused) {
                            currentOrders.innerHTML = newOrders.innerHTML;
                            if (typeof window.doFilter === 'function') {
                                window.doFilter();
                            }
                        }
                    }
                })
                .catch(err => console.debug('Zone page AJAX refresh error:', err))
                .finally(() => {
                    isRefreshing = false;
                });
            }

            // Start real-time AJAX refresh every 3 seconds
            setInterval(refreshZonePage, 3000);
            window.refreshZonePage = refreshZonePage;

            // Acknowledge order handler via AJAX
            window.acknowledgeOrder = function(orderId, btn) {
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i>";
                }

                const fd = new FormData();
                fd.append('action', 'mark_read');
                fd.append('order_id', orderId);

                fetch(pollUrl, {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        knownUnreadIds.delete(parseInt(orderId));

                        // Smoothly remove from notif banner
                        const card = document.getElementById('notif-card-' + orderId) || (btn ? btn.closest('.notif-item') : null);
                        if (card) {
                            card.style.transition = 'all 0.3s ease';
                            card.style.opacity = '0';
                            card.style.transform = 'translateY(-10px)';
                            setTimeout(() => card.remove(), 300);
                        }

                        // Remove unread tag on regular card
                        const unreadTag = document.getElementById('unread-tag-' + orderId);
                        if (unreadTag) unreadTag.remove();

                        // If btn was inside regular card, hide it
                        if (btn && btn.closest('.order-actions-bar')) {
                            btn.remove();
                        }

                        // Update pill count
                        const pill = document.querySelector('.notif-count-pill');
                        if (pill) {
                            const newCount = Math.max(0, parseInt(pill.textContent) - 1);
                            if (newCount > 0) {
                                pill.textContent = newCount + ' ACTION REQUIRED';
                            } else {
                                const banner = document.querySelector('.notif-banner');
                                if (banner) banner.remove();
                            }
                        }

                        showToast('Order Checked', `Order #${orderId} marked as checked. Admin panel notified!`, null, 'bx-check-double');

                        // Immediately trigger page refresh to synchronize server state
                        setTimeout(refreshZonePage, 400);
                    }
                })
                .catch(e => {
                    console.error('Ack error:', e);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = "<i class='bx bx-check-double'></i> Check";
                    }
                });
            };

            window.toggleCustomDatePicker = function() {
                const box = document.getElementById('customDatePickerBox');
                if (!box) return;
                if (box.style.display === 'none' || !box.style.display) {
                    box.style.display = 'block';
                    const fromInput = document.getElementById('date_from');
                    if (fromInput) fromInput.focus();
                } else {
                    box.style.display = 'none';
                }
            };

            window.openAssignVehicleModalZone = function(orderId, shopName, orderNum) {
                const modal = document.getElementById('modalAssignVehicleZone');
                const ordInput = document.getElementById('modalVehOrderId');
                const infoDiv = document.getElementById('modalVehOrderInfo');
                const select = document.getElementById('modalVehSelect');
                if (!modal) return;
                ordInput.value = orderId;
                infoDiv.textContent = `${orderNum ? orderNum : '#' + orderId} — ${shopName}`;
                if (select) select.value = '';
                modal.style.display = 'flex';
            };

            window.closeAssignVehicleModalZone = function() {
                const modal = document.getElementById('modalAssignVehicleZone');
                if (modal) modal.style.display = 'none';
            };

            window.submitAssignVehicleZone = function(e) {
                e.preventDefault();
                const orderId = document.getElementById('modalVehOrderId').value;
                const vehicleId = document.getElementById('modalVehSelect').value;
                const btn = document.getElementById('btnSubmitAssignVeh');
                if (!orderId || !vehicleId) {
                    alert('Please select a vehicle.');
                    return;
                }
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Assigning...";
                }
                const fd = new FormData();
                fd.append('action', 'assign_vehicle');
                fd.append('order_id', orderId);
                fd.append('vehicle_id', vehicleId);

                fetch(pollUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = "<i class='bx bxs-truck'></i> Assign Vehicle";
                        }
                        if (res.success) {
                            closeAssignVehicleModalZone();
                            showToast('Vehicle Assigned', res.message || 'Vehicle assigned successfully.', null, 'bxs-truck');
                            setTimeout(refreshZonePage, 300);
                        } else {
                            alert(res.error || 'Failed to assign vehicle.');
                        }
                    })
                    .catch(err => {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = "<i class='bx bxs-truck'></i> Assign Vehicle";
                        }
                        console.error('Assign vehicle error:', err);
                        alert('Could not assign vehicle. Please try again.');
                    });
            };
        })();

        // Vehicle Damaged Items Controller
        window.allCatalogProducts = <?= json_encode($allProductsList) ?>;

        window.openVehicleDamagedModalFromBtn = function(btn) {
            if (!btn) return;
            const vehicleId = btn.getAttribute('data-vehicle-id');
            const vehicleNumber = btn.getAttribute('data-vehicle-number') || ('Vehicle #' + vehicleId);
            let loadedProducts = [];
            try {
                const raw = btn.getAttribute('data-loaded-json');
                if (raw) loadedProducts = JSON.parse(raw);
            } catch (e) {
                console.error("Error parsing loaded products JSON:", e);
            }
            openVehicleDamagedModal(vehicleId, vehicleNumber, loadedProducts);
        };

        window.openVehicleDamagedModal = function(vehicleId, vehicleNumber, loadedProducts) {
            const vIdField = document.getElementById('modalDamagedVehId');
            const vNumField = document.getElementById('modalDamagedVehNum');
            const casesField = document.getElementById('modalDamagedCases');
            const notesField = document.getElementById('modalDamagedNotes');
            const select = document.getElementById('modalDamagedProdSelect');

            if (vIdField) vIdField.value = vehicleId;
            if (vNumField) vNumField.textContent = vehicleNumber;
            if (casesField) casesField.value = '1';
            if (notesField) notesField.value = '';

            if (select) {
                select.innerHTML = '<option value="">-- Choose Damaged Product --</option>';

                // Show only products currently loaded on this vehicle (removes products catalog)
                if (Array.isArray(loadedProducts) && loadedProducts.length > 0) {
                    loadedProducts.forEach(p => {
                        if (p && p.id) {
                            const opt = document.createElement('option');
                            opt.value = p.id;
                            opt.textContent = (p.name || 'Product') + ' (' + (p.cases || 0) + ' cs loaded)';
                            opt.setAttribute('data-loaded-cases', p.cases || 0);
                            select.appendChild(opt);
                        }
                    });

                    // If exactly one loaded product, auto select it
                    if (loadedProducts.length === 1 && loadedProducts[0] && loadedProducts[0].id) {
                        select.value = loadedProducts[0].id;
                    }
                } else {
                    const noOpt = document.createElement('option');
                    noOpt.value = '';
                    noOpt.disabled = true;
                    noOpt.selected = true;
                    noOpt.textContent = '-- No products loaded on this vehicle --';
                    select.appendChild(noOpt);
                }
            }

            const modal = document.getElementById('modalVehicleDamaged');
            if (modal) modal.style.display = 'flex';
        };

        window.closeVehicleDamagedModal = function() {
            const modal = document.getElementById('modalVehicleDamaged');
            if (modal) modal.style.display = 'none';
        };

        window.submitVehicleDamaged = function(e) {
            const btn = document.getElementById('btnSubmitDamaged');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving...";
            }
        };

        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('toggleMetricsBtn');
            const metricsGrid = document.getElementById('metricsGrid');

            if (toggleBtn && metricsGrid) {
                toggleBtn.addEventListener('click', function() {
                    const isOpen = metricsGrid.classList.toggle('show-mobile');
                    toggleBtn.classList.toggle('is-open', isOpen);
                    toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
            }
        });
    </script>

    <!-- Modal: Quick Vehicle Assignment -->
    <div id="modalAssignVehicleZone" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 10000; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 440px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;">
            <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                <div style="font-weight: 800; font-size: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class='bx bxs-truck' style="color: #2563eb; font-size: 20px;"></i>
                    <span>Assign Vehicle to Order</span>
                </div>
                <button type="button" onclick="closeAssignVehicleModalZone()" style="background: none; border: none; font-size: 24px; color: #64748b; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            <form id="formAssignVehicleZone" onsubmit="submitAssignVehicleZone(event)" style="padding: 20px;">
                <input type="hidden" name="order_id" id="modalVehOrderId" value="">
                <div style="margin-bottom: 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px;">
                    <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #1e40af;">Order Details</div>
                    <div id="modalVehOrderInfo" style="font-weight: 700; color: #1e293b; font-size: 14px; margin-top: 2px;"></div>
                </div>
                <div style="margin-bottom: 18px;">
                    <label for="modalVehSelect" style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Select Delivery Vehicle <span style="color: #ef4444;">*</span>
                    </label>
                    <select name="vehicle_id" id="modalVehSelect" required style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 14px; font-weight: 600; background: #fff;">
                        <option value="">-- Choose a Vehicle --</option>
                        <?php foreach ($zoneVehicles as $zv): 
                            $zvLoad = $vehiclesLoadMap[(int)$zv['id']]['total_cases'] ?? 0;
                        ?>
                            <option value="<?= (int)$zv['id'] ?>">
                                <?= htmlspecialchars($zv['vehicle_number']) ?><?= !empty($zv['vehicle_name']) ? ' (' . htmlspecialchars($zv['vehicle_name']) . ')' : '' ?> &bull; <?= (int)$zvLoad ?> cs loaded
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeAssignVehicleModalZone()" style="padding: 9px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-weight: 700; font-size: 13px; cursor: pointer;">Cancel</button>
                    <button type="submit" id="btnSubmitAssignVeh" style="padding: 9px 18px; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <i class='bx bxs-truck'></i> Assign Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Record Vehicle Damaged Items -->
    <div id="modalVehicleDamaged" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 10001; align-items: center; justify-content: center; padding: 16px;">
        <div style="background: #ffffff; border-radius: 16px; width: 100%; max-width: 480px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;">
            <div style="padding: 16px 20px; background: #fef2f2; border-bottom: 1px solid #fee2e2; display: flex; align-items: center; justify-content: space-between;">
                <div style="font-weight: 800; font-size: 16px; color: #991b1b; display: flex; align-items: center; gap: 8px;">
                    <i class='bx bx-error-circle' style="color: #dc2626; font-size: 22px;"></i>
                    <span>Add Damaged Items</span>
                </div>
                <button type="button" onclick="closeVehicleDamagedModal()" style="background: none; border: none; font-size: 24px; color: #94a3b8; cursor: pointer; line-height: 1;">&times;</button>
            </div>
            <form id="formVehicleDamaged" method="POST" action="" onsubmit="submitVehicleDamaged(event)" style="padding: 20px;">
                <input type="hidden" name="record_vehicle_damaged" value="1">
                <input type="hidden" name="vehicle_id" id="modalDamagedVehId" value="">

                <div style="margin-bottom: 14px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px 14px;  align-items: center; justify-content: space-between;">
                    <div>
                       
                        <div id="modalDamagedVehNum" style="font-weight: 800; color: #0f172a; font-size: 15px; margin-top: 2px;"></div>
                    </div>
                    <span style=" color: #1d4ed8; font-size: 12px; font-weight: 700;  ">
                        <?= htmlspecialchars($zone_name) ?>
                    </span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label for="modalDamagedProdSelect" style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Select Damaged Product <span style="color: #ef4444;">*</span>
                    </label>
                    <select name="product_id" id="modalDamagedProdSelect" required style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 14px; font-weight: 600; background: #fff;">
                        <option value="">-- Choose Damaged Product --</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label for="modalDamagedCases" style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Number of Damaged Cases <span style="color: #ef4444;">*</span>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="text" name="damaged_cases" id="modalDamagedCases" min="1" max="9999" value="1" required style="width: 120px; padding: 10px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 15px; font-weight: 700; color: #0f172a;">
                        <span style="font-size: 13px; font-weight: 600; color: #64748b;">Cases (cartons/crates)</span>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="modalDamagedNotes" style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Reason / Notes (Optional)
                    </label>
                    <input type="text" name="notes" id="modalDamagedNotes" placeholder="e.g. Broken seal, bottle leak, crushed during transit" style="width: 100%; padding: 10px 12px; border-radius: 8px; border: 1.5px solid #cbd5e1; font-size: 13px; color: #334155;">
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeVehicleDamagedModal()" style="padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #475569; font-weight: 700; font-size: 13px; cursor: pointer;">Cancel</button>
                    <button type="submit" id="btnSubmitDamaged" style="padding: 10px 18px; border-radius: 8px; border: none; background: #dc2626; color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 4px 6px -1px rgba(220, 38, 38, 0.3);">
                        <i class='bx bx-check-circle'></i> Save Damaged Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>