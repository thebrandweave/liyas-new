<?php
/**
 * /zone/orders/dashboard/ - Live Multi-Zone Orders Hub
 * Receives, monitors, and manages all zone delivery orders with real-time audio and visual alerts.
 */
require_once dirname(__DIR__) . '/auth_helper.php';

// Enforce authentication
requireZoneOrdersAuth();

// Fetch active zones for selector and filter chips
$zonesStmt = $pdo->query("SELECT * FROM zones WHERE status = 'active' ORDER BY name ASC");
$allZones = $zonesStmt->fetchAll(PDO::FETCH_ASSOC);

// Map zones by slug and id for rapid lookup
$zoneSlugMap = [];
$zoneIdMap = [];
foreach ($allZones as $z) {
    $zoneSlugMap[$z['slug']] = $z;
    $zoneIdMap[(int)$z['id']] = $z;
}

// Active filters
$selectedZone = trim($_GET['zone'] ?? 'all');
$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$shopFilter = trim($_GET['shop'] ?? '');
$dateFilter = trim($_GET['date'] ?? 'all');

// Build SQL conditions
$whereClauses = ["1=1"];
$params = [];

// 1. Zone filter
if ($selectedZone !== 'all' && !empty($selectedZone)) {
    if (is_numeric($selectedZone)) {
        $whereClauses[] = "o.zone_id = :filter_zone_id";
        $params[':filter_zone_id'] = (int)$selectedZone;
    } else {
        $whereClauses[] = "z.slug = :filter_zone_slug";
        $params[':filter_zone_slug'] = $selectedZone;
    }
}

// 2. Status & Payment filters
if (in_array($filter, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
    $whereClauses[] = "o.status = :status";
    $params[':status'] = $filter;
} elseif ($filter === 'due') {
    $whereClauses[] = "r.due_amount > 0";
} elseif ($filter === 'credit') {
    $whereClauses[] = "r.credited_amount > 0";
} elseif ($filter === 'cash') {
    $whereClauses[] = "r.cash_amount > 0";
}

// 3. Search filter
if (!empty($search)) {
    $whereClauses[] = "(
        o.shop_name LIKE :search 
        OR o.customer_name LIKE :search 
        OR o.phone LIKE :search 
        OR o.location LIKE :search 
        OR o.address LIKE :search 
        OR o.order_number LIKE :search 
        OR r.bill_number LIKE :search
    )";
    $params[':search'] = "%{$search}%";
}

// 4. Shop filter
if (!empty($shopFilter)) {
    $whereClauses[] = "(TRIM(o.shop_name) = :shop_filter OR (COALESCE(TRIM(o.shop_name), '') = '' AND TRIM(o.customer_name) = :shop_filter))";
    $params[':shop_filter'] = $shopFilter;
}

// 5. Date filter
if ($dateFilter === 'today') {
    $whereClauses[] = "DATE(o.created_at) = CURDATE()";
} elseif ($dateFilter === 'yesterday') {
    $whereClauses[] = "DATE(o.created_at) = SUBDATE(CURDATE(), 1)";
} elseif ($dateFilter === 'this_week') {
    $whereClauses[] = "YEARWEEK(o.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'this_month') {
    $whereClauses[] = "YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())";
}

$whereSql = implode(" AND ", $whereClauses);

// Fetch all zone orders with vehicle details
$ordersSql = "
    SELECT 
        o.*,
        z.name AS zone_name,
        z.slug AS zone_slug,
        p.product_name,
        p.name AS fallback_name,
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
        veh.vehicle_number AS assigned_vehicle_number,
        veh.vehicle_name AS assigned_vehicle_name,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs', IF(oi2.price_at_purchase = 0, ' - Extra', ''), ')') SEPARATOR ', ') 
         FROM order_items oi2 
         JOIN products p2 ON oi2.product_id = p2.product_id 
         WHERE oi2.order_id = o.order_id) AS items_summary,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(p_buf.product_name, p_buf.name), ' (', vl_buf.cases_loaded, 'cs)') SEPARATOR ', ')
         FROM vehicle_loads vl_buf
         JOIN products p_buf ON vl_buf.product_id = p_buf.product_id
         WHERE vl_buf.vehicle_id = o.vehicle_id 
           AND vl_buf.is_active = 1 
           AND vl_buf.product_id IS NOT NULL 
           AND vl_buf.order_id IS NULL) AS vehicle_buffer_stock
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN vehicles veh ON o.vehicle_id = veh.id
    WHERE {$whereSql}
    ORDER BY o.created_at DESC
";
$orders = [];
try {
    $stmtOrders = $pdo->prepare($ordersSql);
    $stmtOrders->execute($params);
    $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("Primary dashboard orders query failed: " . $e->getMessage());
    if (function_exists('ensureWarehouseSchema')) {
        ensureWarehouseSchema($pdo);
    }
    try {
        $fallbackSql = "
            SELECT 
                o.*,
                z.name AS zone_name,
                z.slug AS zone_slug,
                p.product_name,
                p.name AS fallback_name,
                p.case_price,
                p.net_content,
                p.net_content_unit,
                veh.vehicle_number AS assigned_vehicle_number,
                veh.vehicle_name AS assigned_vehicle_name
            FROM orders o
            LEFT JOIN zones z ON o.zone_id = z.id
            LEFT JOIN products p ON o.product_id = p.product_id
            LEFT JOIN vehicles veh ON o.vehicle_id = veh.id
            WHERE {$whereSql}
            ORDER BY o.created_at DESC
        ";
        $fallbackStmt = $pdo->prepare($fallbackSql);
        $fallbackStmt->execute($params);
        $orders = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e2) {
        error_log("Fallback dashboard orders query failed: " . $e2->getMessage());
        try {
            $basicSql = "SELECT o.* FROM orders o WHERE {$whereSql} ORDER BY o.created_at DESC";
            $basicStmt = $pdo->prepare($basicSql);
            $basicStmt->execute($params);
            $orders = $basicStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e3) {
            error_log("Basic dashboard orders query failed: " . $e3->getMessage());
            $orders = [];
        }
    }
}

// Shop delivery statistics summary for delivery breakdown
$shopSummary = null;
if (!empty($shopFilter)) {
    $shopSummary = getShopDeliveredCasesSummary($pdo, $shopFilter);
} elseif (!empty($search)) {
    $shopSummary = getShopDeliveredCasesSummary($pdo, $search);
}

// Fetch all distinct shops for filter dropdown
$allShopsList = getAllShopsList($pdo);

// Fetch all active vehicles with current load counts for assigning orders
$allActiveVehicles = [];
try {
    $allActiveVehicles = $pdo->query("
        SELECT 
            v.id, 
            v.vehicle_number, 
            v.vehicle_name, 
            v.driver_name,
            v.capacity_cases,
            COALESCE((
                SELECT SUM(vl.cases_loaded) 
                FROM vehicle_loads vl 
                WHERE vl.vehicle_id = v.id AND vl.is_active = 1
            ), 0) AS total_cases_loaded
        FROM vehicles v 
        WHERE v.status != 'inactive' 
        ORDER BY v.vehicle_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    try {
        $allActiveVehicles = $pdo->query("SELECT id, vehicle_number, vehicle_name, driver_name, capacity_cases, 0 as total_cases_loaded FROM vehicles WHERE status != 'inactive'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e2) {}
}

// Fetch all active products for the Add Products modal
$allProductsList = [];
try {
    $allProductsList = $pdo->query("SELECT product_id, name, product_name, case_price, price, case_stock, stock, net_content, net_content_unit FROM products WHERE status != 'inactive' OR status IS NULL ORDER BY case_price ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Fetch unread incoming orders for initial notification list
$unreadNotifications = [];
try {
    $unreadStmt = $pdo->query("
        SELECT o.*, z.name as zone_name, z.slug as zone_slug, p.product_name, p.name as fallback_name
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN products p ON o.product_id = p.product_id
        WHERE o.is_zone_read = 0 AND o.status != 'cancelled'
        ORDER BY o.created_at DESC
    ");
    $unreadNotifications = $unreadStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Fetch metrics (respecting selected zone)
$metricsWhere = "WHERE 1=1";
$metricsParams = [];
if ($selectedZone !== 'all' && !empty($selectedZone)) {
    if (is_numeric($selectedZone)) {
        $metricsWhere .= " AND o.zone_id = :m_zid";
        $metricsParams[':m_zid'] = (int)$selectedZone;
    } else {
        $metricsWhere .= " AND z.slug = :m_zslug";
        $metricsParams[':m_zslug'] = $selectedZone;
    }
}

$metrics = [
    'total_orders' => 0, 'pending_count' => 0, 'processing_count' => 0,
    'shipped_count' => 0, 'delivered_count' => 0, 'cancelled_count' => 0,
    'unread_count' => 0, 'total_cash' => 0, 'total_credit' => 0, 'total_due' => 0
];
try {
    $metricsStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.order_id) AS total_orders,
            SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN o.status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) AS shipped_count,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.is_zone_read = 0 AND o.status != 'cancelled' THEN 1 ELSE 0 END) AS unread_count,
            COALESCE(SUM(r.cash_amount), 0) AS total_cash,
            COALESCE(SUM(r.credited_amount), 0) AS total_credit,
            COALESCE(SUM(r.due_amount), 0) AS total_due
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN receipts r ON o.order_id = r.order_id
        {$metricsWhere}
    ");
    $metricsStmt->execute($metricsParams);
    $rowM = $metricsStmt->fetch(PDO::FETCH_ASSOC);
    if ($rowM) $metrics = $rowM;
} catch (Exception $e) {
    try {
        $mBasic = $pdo->prepare("SELECT COUNT(*) as total_orders FROM orders o {$metricsWhere}");
        $mBasic->execute($metricsParams);
        $metrics['total_orders'] = (int)$mBasic->fetchColumn();
    } catch (Exception $e2) {}
}

// Zone order counts for the zone pills
$zoneCountsRaw = [];
try {
    $zoneCountsStmt = $pdo->query("
        SELECT o.zone_id, COUNT(*) as count 
        FROM orders o 
        GROUP BY o.zone_id
    ");
    $zoneCountsRaw = $zoneCountsStmt ? ($zoneCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];
} catch (Exception $e) {}

// Helper for WhatsApp
function getZoneWhatsAppLink($phone, $shop, $orderNum, $amount, $zoneName = '') {
    $clean = preg_replace('/[^0-9]/', '', $phone ?? '');
    if (empty($clean)) return '';
    if (strlen($clean) === 10) {
        $clean = '91' . $clean;
    }
    $zoneText = !empty($zoneName) ? " [{$zoneName}]" : "";
    $text = "Hello {$shop}, this is Liyas Delivery Team regarding Order #{$orderNum}{$zoneText} (Amount: ₹" . number_format((float)$amount, 2) . ").";
    return "https://wa.me/{$clean}?text=" . rawurlencode($text);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone Orders Live Dashboard - Liyas International</title>
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
            --purple: #7c3aed;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-subtle: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
            --shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
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

        /* Fixed / Sticky Header */
        .portal-header {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        .header-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .brand-logo {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            object-fit: cover;
            border: 1.5px solid var(--border-color);
            box-shadow: var(--shadow-subtle);
        }

        .brand-info h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.2;
        }

        .brand-info p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .badge-live-stream {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ecfdf5;
            color: #059669;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            border: 1px solid #a7f3d0;
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-header {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-sub);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-header:hover {
            background: #f1f5f9;
            color: var(--text-main);
            border-color: #cbd5e1;
        }

        .btn-header.sound-active {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }

        .btn-logout {
            color: #dc2626;
            background: #fef2f2;
            border-color: #fecaca;
        }
        .btn-logout:hover {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        /* Main Container */
        .portal-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }

        /* Unread Notification Banner */
        .notif-banner {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: #ffffff;
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px -5px rgba(30, 58, 138, 0.35);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .notif-banner-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .notif-icon-wrap {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.18);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fde047;
            animation: ring 2s infinite ease-in-out;
        }

        @keyframes ring {
            0% { transform: rotate(0); }
            10% { transform: rotate(14deg); }
            20% { transform: rotate(-14deg); }
            30% { transform: rotate(10deg); }
            40% { transform: rotate(-10deg); }
            50% { transform: rotate(0); }
            100% { transform: rotate(0); }
        }

        .notif-title {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .notif-desc {
            font-size: 12px;
            color: #bfdbfe;
            margin-top: 2px;
        }

        .btn-mark-all {
            background: rgba(255, 255, 255, 0.95);
            color: #1e3a8a;
            border: none;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .btn-mark-all:hover {
            background: #ffffff;
            transform: translateY(-1px);
        }

        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 18px;
            box-shadow: var(--shadow-subtle);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-card);
        }

        .metric-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #cbd5e1;
        }

        .metric-card.m-blue::before { background: #2563eb; }
        .metric-card.m-amber::before { background: #d97706; }
        .metric-card.m-purple::before { background: #7c3aed; }
        .metric-card.m-green::before { background: #059669; }
        .metric-card.m-emerald::before { background: #10b981; }
        .metric-card.m-rose::before { background: #e11d48; }

        .metric-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .metric-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
        }

        .metric-icon {
            font-size: 20px;
            color: #94a3b8;
        }

        .metric-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.1;
        }

        .metric-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Controls Section: Zone Switcher & Search Bar */
        .controls-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-subtle);
        }

        /* Zone Selector Pills */
        .zone-selector-wrap {
            margin-bottom: 16px;
        }

        .filter-section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .zone-pills-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .zone-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            color: #334155;
            transition: all 0.15s ease;
        }

        .zone-pill:hover {
            border-color: #cbd5e1;
            background: #ffffff;
            transform: translateY(-1px);
        }

        .zone-pill.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
        }

        .zone-pill-count {
            background: rgba(0, 0, 0, 0.08);
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 10px;
        }

        .zone-pill.active .zone-pill-count {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* Status & Search Row */
        .filter-search-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .status-chips-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .chip-filter {
            display: inline-flex;
            align-items: center;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }

        .chip-filter:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .chip-filter.active {
            background: #0f172a;
            color: #ffffff;
            font-weight: 700;
        }

        .chip-due.active { background: #dc2626; color: #fff; }
        .chip-credit.active { background: #d97706; color: #fff; }
        .chip-cash.active { background: #059669; color: #fff; }

        .search-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-grow: 1;
            max-width: 420px;
        }

        .search-input-wrap {
            position: relative;
            width: 100%;
        }

        .search-input-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 17px;
        }

        .search-input {
            width: 100%;
            padding: 8px 12px 8px 26px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            background: #f8fafc;
            transition: all 0.15s ease;
        }

        .search-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-search {
            padding: 8px 14px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        /* Order Cards Grid */
        .orders-feed {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 16px;
        }

        .order-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            box-shadow: var(--shadow-subtle);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .order-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
            border-color: #cbd5e1;
        }

        .order-card.unread {
            border-left: 4px solid #2563eb;
            background: linear-gradient(to right, #f8faff, #ffffff);
        }

        .order-card.newly-arrived {
            animation: highlight-pulse 2.5s ease;
        }

        @keyframes highlight-pulse {
            0% { background-color: #dbeafe; transform: scale(1.02); }
            50% { background-color: #eff6ff; }
            100% { background-color: #ffffff; transform: scale(1); }
        }

        .card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .order-num-block {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .order-num {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.01em;
        }

        /* Distinct Zone Badges */
        .zone-badge-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid;
            letter-spacing: 0.02em;
        }

        .zone-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .badge-unread {
            background: #fee2e2;
            color: #dc2626;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .order-date-text {
            font-size: 11px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        /* Customer / Shop Info */
        .customer-block {
            margin-bottom: 14px;
        }

        .shop-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .location-text {
            font-size: 12px;
            color: var(--text-sub);
            display: flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 6px;
        }

        .phone-links {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-phone {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f1f5f9;
            color: #334155;
            padding: 4px 9px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            transition: background 0.15s ease;
        }

        .btn-phone:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-whatsapp {
            background: #dcfce7;
            color: #15803d;
            border-color: #bbf7d0;
        }
        .btn-whatsapp:hover {
            background: #bbf7d0;
            color: #166534;
        }

        /* Order Items & Total */
        .items-summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .items-desc {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            line-height: 1.4;
        }

        .order-total-amount {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            white-space: nowrap;
        }

        /* Status Dropdown & Action Buttons */
        .card-actions-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }

        .status-select {
            padding: 6px 2px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            font-family: inherit;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
            outline: none;
            transition: all 0.15s ease;
        }

        .status-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-processing { background: #dbeafe; color: #1d4ed8; }
        .badge-shipped { background: #ede9fe; color: #6d28d9; }
        .badge-delivered, .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        .btn-card-action {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-card-action:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-card-primary {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
        .btn-card-primary:hover {
            background: #1d4ed8;
            color: #ffffff;
        }

        .btn-yard-toggle {
            transition: all 0.2s ease;
            font-size: 11px;
            padding: 4px 9px;
            cursor: pointer;
        }
        .btn-yard-toggle.yard-in {
            background: #ecfdf5;
            color: #065f46;
            border-color: #a7f3d0;
            font-weight: 700;
        }
        .btn-yard-toggle.yard-in:hover {
            background: #d1fae5;
        }
        .btn-yard-toggle.yard-out {
            background: #f8fafc;
            color: #64748b;
            border-color: #cbd5e1;
            font-weight: 600;
        }
        .btn-yard-toggle.yard-out:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        /* Empty state */
        .empty-state {
            grid-column: 1 / -1;
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: var(--radius-lg);
            padding: 60px 20px;
            text-align: center;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 48px;
            color: #94a3b8;
            margin-bottom: 12px;
        }

        /* Floating Toast Notifications Container */
        #toastContainer {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 380px;
            width: 100%;
            pointer-events: none;
        }

        .portal-toast {
            pointer-events: auto;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            color: #ffffff;
            padding: 14px 16px;
            border-radius: 14px;
            box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            transition: all 0.25s ease;
        }

        .portal-toast.closing {
            opacity: 0;
            transform: translateY(15px);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .toast-icon {
            font-size: 22px;
            color: #fde047;
            margin-top: 2px;
        }

        .toast-content {
            flex-grow: 1;
        }

        .toast-title {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .toast-body {
            font-size: 12px;
            color: #cbd5e1;
            line-height: 1.4;
        }

        .toast-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 18px;
            cursor: pointer;
            padding: 0 4px;
        }

        /* Order Details Modal */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: var(--radius-xl);
            max-width: 580px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            padding: 24px;
            position: relative;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-close {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #64748b;
        }

        .modal-close:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        @media (max-width: 768px) {
            .orders-feed {
                grid-template-columns: 1fr;
            }
            .header-inner {
                padding: 10px 14px;
            }
            .portal-container {
                padding: 0 14px;
            }
            .search-form {
                max-width: 100%;
            }
        }

        /* Container styling */
.metrics-accordion {
    width: 100%;
    margin-bottom: 1.5rem;
}

/* Hide toggle button on desktop */
.metrics-toggle-btn {
    display: none;
    width: 100%;
    padding: 14px 18px;
    background-color: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    transition: background-color 0.2s ease, border-color 0.2s ease;
}

.metrics-toggle-btn .toggle-title {
    display: flex;
    align-items: center;
    gap: 8px;
}

.metrics-toggle-btn .toggle-icon {
    font-size: 1.25rem;
    transition: transform 0.3s ease;
}

/* Mobile Specific Responsive Rules */
@media (max-width: 767px) {
    .metrics-toggle-btn {
        display: flex; /* Show dropdown trigger button on mobile */
    }

    .metrics-grid {
        display: none; /* Collapse menu by default on mobile */
        grid-template-columns: 1fr; /* Single column layout inside dropdown */
        gap: 12px;
        margin-top: 10px;
        padding: 12px;
        background-color: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
    }

    /* Class added via JS when opened */
    .metrics-grid.is-open {
        display: grid;
    }

    .metrics-toggle-btn.is-active .toggle-icon {
        transform: rotate(180deg);
    }
}

@media (max-width: 576px) {
    /* Hide button text on mobile phones, keeping only icons to save space */
    .btn-header span {
        display: block;
         font-size: 10px;
    }
    
    .btn-header {
        padding: 3px;
        /* border-radius: 50%; Circle icons for mobile header */
        width:80px;
        height: 36px;
    }

    .btn-header i {
       
        margin: 0;
    }
    .lock{
        display:none;
    }
}
    </style>
</head>
<body>
    <!-- Top Fixed Portal Header -->
    <header class="portal-header">
        <div class="header-inner">
       
              
                <div class="brand-info">
                    <h1>
                        <span>Zone Orders Management</span>
                       
                    </h1>
                    <p>All Delivery Zones</p>
                </div>
         

            <div class="header-actions">
             

               
                <a href="<?= BASE_URL ?>/zone/orders/dashboard/vehicles/" class="btn-header" style="color: #1e40af; background: #eff6ff; border-color: #bfdbfe; font-weight: 700;" title="Zone Vehicles & Vehicle Products Hub">
                    <i class='bx bxs-truck' style="color: #2563eb; font-size: 16px;"></i>
                    <span>Vehicles</span>
                </a>

                <button type="button" class="btn-header" onclick="toggleRewardsSidebar(true)" style="color: #6b21a8; background: #faf5ff; border-color: #e9d5ff; font-weight: 700;" title="Shop Reward Status">
                    <i class='bx bxs-gift' style="color: #7c3aed; font-size: 16px;"></i>
                    <span>Rewards</span>
                </button>

                <!-- <a href="<?= BASE_URL ?>/admin/orders/create.php" class="btn-header" style="color: #047857; background: #ecfdf5; border-color: #a7f3d0; font-weight: 700;" title="Place New Order or Reorder">
                    <i class='bx bx-plus-circle' style="color: #059669; font-size: 16px;"></i>
                    <span> New Order</span>
                </a> -->

                <a href="<?= BASE_URL ?>/zone/orders/dashboard/products/" class="btn-header" title="Products & Inventory Management">
                    <i class='bx bx-cube'></i>
                    <span>Products</span>
                </a>
                <a href="<?= BASE_URL ?>/zone/orders/logout.php" class="btn-header btn-logout" title="Lock and logout">
                    <i class='bx bx-lock-alt lock'></i>
                    <span>Lock / Logout</span>
                </a>
            </div>
        </div>
    </header>

    <main class="portal-container">
        <!-- New Incoming Orders Alert Banner (if unread orders exist) -->
        <?php if (!empty($unreadNotifications)): ?>
            <div class="notif-banner" id="unreadNotifBanner">
                <div class="notif-banner-left">
                    <div class="notif-icon-wrap">
                        <i class='bx bxs-bell-ring'></i>
                    </div>
                    <div>
                        <div class="notif-title"><?= count($unreadNotifications) ?> Action Required: New Incoming Zone Orders</div>
                        <div class="notif-desc">Orders received from customers awaiting dispatch verification across zones.</div>
                    </div>
                </div>
                <button type="button" class="btn-mark-all" onclick="markAllOrdersChecked()">
                    <i class='bx bx-check-double'></i> Mark All Checked
                </button>
            </div>
        <?php endif; ?>

     <!-- Dashboard Metrics Accordion Wrapper -->
<div class="metrics-accordion">
    <!-- Collapsible Header (Visible on Mobile) -->
    <button type="button" class="metrics-toggle-btn" id="metricsToggleBtn" aria-expanded="false" onclick="toggleMetrics()">
        <span class="toggle-title">
            <i class='bx bx-bar-chart-alt-2'></i> Dashboard Metrics
        </span>
        <i class='bx bx-chevron-down toggle-icon' id="metricsToggleIcon"></i>
    </button>

    <!-- Key Metrics Cards -->
    <section class="metrics-grid" id="metricsGrid">
        <div class="metric-card m-blue">
            <div class="metric-header">
                <span class="metric-label">Total Orders</span>
                <i class='bx bx-shopping-bag metric-icon'></i>
            </div>
            <div class="metric-value" id="valTotalOrders"><?= (int)$metrics['total_orders'] ?></div>
            <div class="metric-sub"><?= ($selectedZone === 'all') ? 'Across all zones' : htmlspecialchars($selectedZone) ?></div>
        </div>

        <div class="metric-card m-amber">
            <div class="metric-header">
                <span class="metric-label">Pending</span>
                <i class='bx bx-time-five metric-icon'></i>
            </div>
            <div class="metric-value" id="valPending"><?= (int)$metrics['pending_count'] ?></div>
            <div class="metric-sub">Awaiting dispatch</div>
        </div>

        <div class="metric-card m-purple">
            <div class="metric-header">
                <span class="metric-label">In Transit</span>
                <i class='bx bx-cycling metric-icon'></i>
            </div>
            <div class="metric-value" id="valProcessing"><?= (int)$metrics['processing_count'] + (int)$metrics['shipped_count'] ?></div>
            <div class="metric-sub">Processing & shipped</div>
        </div>

        <div class="metric-card m-green">
            <div class="metric-header">
                <span class="metric-label">Delivered</span>
                <i class='bx bx-check-circle metric-icon'></i>
            </div>
            <div class="metric-value" id="valDelivered"><?= (int)$metrics['delivered_count'] ?></div>
            <div class="metric-sub">Successfully fulfilled</div>
        </div>

        <div class="metric-card m-emerald">
            <div class="metric-header">
                <span class="metric-label">Cash Collected</span>
                <i class='bx bx-wallet metric-icon'></i>
            </div>
            <div class="metric-value" id="valCash">₹<?= number_format((float)$metrics['total_cash'], 2) ?></div>
            <div class="metric-sub">Cash received</div>
        </div>

        <div class="metric-card m-rose">
            <div class="metric-header">
                <span class="metric-label">Due / Credit</span>
                <i class='bx bx-credit-card metric-icon'></i>
            </div>
            <div class="metric-value" id="valDue">₹<?= number_format((float)$metrics['total_due'] + (float)$metrics['total_credit'], 2) ?></div>
            <div class="metric-sub">Outstanding balance</div>
        </div>
    </section>
</div>

        <!-- Controls: Zone Pills & Filter Chips -->
        <section class="controls-card">
            <!-- Zone Switcher Pills -->
            <div class="zone-selector-wrap">
                <div class="filter-section-title">
                    <i class='bx bx-map-pin'></i>
                    <span>Delivery Zones:</span>
                </div>
                <div class="zone-pills-row">
                    <a href="?zone=all&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="zone-pill <?= ($selectedZone === 'all') ? 'active' : '' ?>">
                        <span>All Zones</span>
                        <span class="zone-pill-count"><?= array_sum($zoneCountsRaw) ?></span>
                    </a>

                    <?php foreach ($allZones as $z): 
                        $zSlug = $z['slug'];
                        $zCount = $zoneCountsRaw[(int)$z['id']] ?? 0;
                        $isActiveZ = ($selectedZone === $zSlug || $selectedZone === (string)$z['id']);
                        $zColor = getZoneColorConfig($zSlug);
                    ?>
                        <a href="?zone=<?= urlencode($zSlug) ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                           class="zone-pill <?= $isActiveZ ? 'active' : '' ?>">
                            <span class="zone-dot" style="background: <?= $zColor['dot'] ?>;"></span>
                            <span><?= htmlspecialchars($z['name']) ?></span>
                            <span class="zone-pill-count"><?= $zCount ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Status Filter Chips and Search Form -->
            <div class="filter-search-row">
                <div class="status-chips-wrap">
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=all&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter <?= ($filter === 'all') ? 'active' : '' ?>">All Status</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=pending&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter <?= ($filter === 'pending') ? 'active' : '' ?>">Pending (<?= (int)$metrics['pending_count'] ?>)</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=processing&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter <?= ($filter === 'processing') ? 'active' : '' ?>">Processing</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=shipped&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter <?= ($filter === 'shipped') ? 'active' : '' ?>">Shipped</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=delivered&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter <?= ($filter === 'delivered') ? 'active' : '' ?>">Delivered</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=cancelled&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter <?= ($filter === 'cancelled') ? 'active' : '' ?>">Cancelled</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=cash&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter chip-cash <?= ($filter === 'cash') ? 'active' : '' ?>">Cash</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=credit&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter chip-credit <?= ($filter === 'credit') ? 'active' : '' ?>">Credit</a>
                    <a href="?zone=<?= urlencode($selectedZone) ?>&filter=due&search=<?= urlencode($search) ?>&date=<?= urlencode($dateFilter) ?>" 
                       class="chip-filter chip-due <?= ($filter === 'due') ? 'active' : '' ?>">Due</a>
                </div>

                <form action="" method="GET" class="search-form" style="display: flex; gap: 8px; ">
                    <input type="hidden" name="zone" value="<?= htmlspecialchars($selectedZone) ?>">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($dateFilter) ?>">

                    <select name="shop" class="search-input" style="max-width: 170px; cursor: pointer;" onchange="this.form.submit()">
                        <option value="">Filter by Shop</option>
                        <?php foreach ($allShopsList as $sh): ?>
                            <option value="<?= htmlspecialchars($sh['shop_name']) ?>" <?= ($shopFilter === $sh['shop_name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sh['shop_name']) ?> (<?= $sh['order_count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="search-input-wrap">
                        <i class='bx bx-search'></i>
                        <input 
                            type="search" 
                            name="search" 
                            class="search-input" 
                            placeholder="Search shop, phone, bill, order #..." 
                            value="<?= htmlspecialchars($search) ?>"
                        >
                    </div>
                    <button type="submit" class="btn-search">Search</button>
                    <?php if (!empty($search) || !empty($shopFilter)): ?>
                        <a href="?zone=<?= urlencode($selectedZone) ?>&filter=<?= urlencode($filter) ?>" class="btn-card-action" title="Clear Search & Shop Filter">
                            <i class='bx bx-x'></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <!-- Shop Delivery Breakdown Banner (When filtered or searched) -->
        <?php if ($shopSummary && ($shopSummary['total_all_orders'] > 0)): ?>
            <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff; border-radius: var(--radius-lg); padding: 18px 22px; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25); border: 1px solid rgba(255, 255, 255, 0.1);">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 14px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 44px; height: 44px; background: rgba(37, 99, 235, 0.25); border: 1px solid rgba(96, 165, 250, 0.3); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; color: #60a5fa;">
                            <i class='bx bx-store-alt'></i>
                        </div>
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <h3 style="font-size: 17px; font-weight: 800; color: #ffffff; margin: 0;"><?= htmlspecialchars($shopSummary['shop_name']) ?></h3>
                                <span style="background: #2563eb; color: #ffffff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 12px;">Delivery History</span>
                            </div>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                Total Orders: <strong><?= $shopSummary['total_all_orders'] ?></strong> (<?= $shopSummary['total_delivered_orders'] ?> Delivered) 
                                <?= $shopSummary['last_order_date'] ? '• Last Order: ' . date('d M Y', strtotime($shopSummary['last_order_date'])) : '' ?>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <a href="<?= BASE_URL ?>/admin/orders/create.php?reorder_id=<?= !empty($orders[0]['order_id']) ? $orders[0]['order_id'] : '' ?>&shop_name=<?= urlencode($shopSummary['shop_name']) ?>" 
                           class="btn-header" style="background: #2563eb; color: #ffffff; border: none; font-weight: 700; padding: 8px 16px;">
                            <i class='bx bx-refresh'></i> Place Reorder
                        </a>
                        <button type="button" class="btn-header" onclick="openVehiclesSidebar('<?= htmlspecialchars($selectedZone) ?>')" style="background: rgba(255, 255, 255, 0.1); color: #60a5fa; border-color: rgba(96, 165, 250, 0.3);">
                            <i class='bx bxs-truck'></i> Check Vehicle Load
                        </button>
                        <?php if (!empty($shopFilter) || !empty($search)): ?>
                            <a href="?zone=<?= urlencode($selectedZone) ?>&filter=<?= urlencode($filter) ?>" 
                               class="btn-header" style="background: rgba(255, 255, 255, 0.1); color: #e2e8f0; border-color: rgba(255, 255, 255, 0.2);" title="Clear Filter">
                                <i class='bx bx-x'></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Cases Delivered Metric Grid: Previous vs Reorder vs Total -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; background: rgba(255, 255, 255, 0.06); padding: 14px 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.08);">
                    <div style="border-left: 3px solid #3b82f6; padding-left: 10px;">
                        <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #93c5fd; letter-spacing: 0.04em;">Previous Order Cases</div>
                        <div style="font-size: 22px; font-weight: 800; color: #ffffff; margin-top: 2px;">
                            <?= number_format($shopSummary['previous_cases']) ?> <span style="font-size: 12px; font-weight: 500; color: #94a3b8;">Cases</span>
                        </div>
                        <div style="font-size: 11px; color: #94a3b8;"><?= $shopSummary['previous_orders_count'] ?> initial orders</div>
                    </div>

                    <div style="border-left: 3px solid #10b981; padding-left: 10px;">
                        <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #6ee7b7; letter-spacing: 0.04em;">🔁 Reorder Cases</div>
                        <div style="font-size: 22px; font-weight: 800; color: #ffffff; margin-top: 2px;">
                            <?= number_format($shopSummary['reorder_cases']) ?> <span style="font-size: 12px; font-weight: 500; color: #94a3b8;">Cases</span>
                        </div>
                        <div style="font-size: 11px; color: #94a3b8;"><?= $shopSummary['reorders_count'] ?> reorders</div>
                    </div>

                    <div style="border-left: 3px solid #f59e0b; padding-left: 10px; background: rgba(245, 158, 11, 0.1); border-radius: 0 8px 8px 0;">
                        <div style="font-size: 11px; text-transform: uppercase; font-weight: 800; color: #fde68a; letter-spacing: 0.04em;">Total Cases Delivered</div>
                        <div style="font-size: 24px; font-weight: 900; color: #fbbf24; margin-top: 2px;">
                            <?= number_format($shopSummary['total_cases']) ?> <span style="font-size: 12px; font-weight: 600; color: #fde68a;">Cases</span>
                        </div>
                        <div style="font-size: 11px; color: #e2e8f0;">Lifetime fulfillment</div>
                    </div>

                    <div style="border-left: 3px solid #ef4444; padding-left: 10px;">
                        <div style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: #fca5a5; letter-spacing: 0.04em;">Outstanding Due</div>
                        <div style="font-size: 22px; font-weight: 800; color: <?= $shopSummary['total_due'] > 0 ? '#f87171' : '#ffffff' ?>; margin-top: 2px;">
                            ₹<?= number_format($shopSummary['total_due'], 2) ?>
                        </div>
                        <div style="font-size: 11px; color: #94a3b8;">Total Rev: ₹<?= number_format($shopSummary['total_amount'], 2) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Orders Stream & Cards Feed -->
        <section class="orders-feed" id="ordersFeed">
            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class='bx bx-package'></i>
                    <h3 style="font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">No Zone Orders Found</h3>
                    <p style="font-size: 13px;">No orders match the current filter or zone criteria. Incoming orders will appear here automatically.</p>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $order): 
                    $orderId = (int)($order['order_id'] ?? ($order['id'] ?? 0));
                    if ($orderId <= 0) continue;
                    $orderNum = !empty($order['order_number']) ? $order['order_number'] : ('#' . $orderId);
                    $shopName = $order['shop_name'] ?: ($order['customer_name'] ?: 'Customer');
                    $isUnread = ((int)$order['is_zone_read'] === 0 && $order['status'] !== 'cancelled');
                    $zoneSlug = $order['zone_slug'] ?: 'central';
                    $zoneName = $order['zone_name'] ?: 'Website';
                    $zColor = getZoneColorConfig($zoneSlug);
                    $totalAmt = (float)$order['total_amount'];
                    $badgeClass = getStatusBadgeClass($order['status']);
                    $hasNoProducts = ((int)($order['items_count'] ?? 0) === 0 && (empty($order['product_id']) || (int)$order['quantity'] === 0));
                    $itemsText = !empty($order['items_summary']) ? $order['items_summary'] : ($order['product_name'] ?: 'Drinking Water');
                    $waLink = getZoneWhatsAppLink($order['phone'], $shopName, $orderNum, $totalAmt, $zoneName);
                ?>
                    <article class="order-card <?= $isUnread ? 'unread' : '' ?>" id="orderCard_<?= $orderId ?>" data-order-id="<?= $orderId ?>">
                        <!-- Card Header -->
                        <div class="card-top">
                            <div>
                                <div class="order-num-block">
                                    <span class="order-num">#<?= htmlspecialchars($orderNum) ?></span>
                                    <span class="zone-badge-tag" style="background: <?= $zColor['bg'] ?>; color: <?= $zColor['text'] ?>; border-color: <?= $zColor['border'] ?>;">
                                        <span class="zone-dot" style="background: <?= $zColor['dot'] ?>;"></span>
                                        <?= htmlspecialchars($zoneName) ?>
                                    </span>
                                    <?php if (!empty($order['is_reorder'])): ?>
                                        <span style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px; letter-spacing: 0.02em;">🔁 REORDER</span>
                                    <?php endif; ?>
                                    <?php if (!empty($order['assigned_vehicle_number'])): ?>
                                        <span style="background: #0f172a; color: #ffffff; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px; display: inline-flex; align-items: center; gap: 3px;" title="Assigned Delivery Vehicle">
                                            <i class='bx bxs-truck' style="color: #60a5fa;"></i> <?= htmlspecialchars($order['assigned_vehicle_number']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($isUnread): ?>
                                        <span class="badge-unread" id="unreadBadge_<?= $orderId ?>">NEW</span>
                                    <?php endif; ?>
                                </div>
                                <div class="order-date-text">
                                    <i class='bx bx-time'></i> <?= formatIST($order['created_at']) ?>
                                </div>
                            </div>

                            <span class="status-badge <?= $badgeClass ?>" id="statusBadge_<?= $orderId ?>">
                                <?= htmlspecialchars(ucfirst($order['status'])) ?>
                            </span>
                        </div>

                        <!-- Customer & Shop Details -->
                        <div class="customer-block">
                            <div class="shop-name">
                                <i class='bx bx-store-alt' style="color: #2563eb;"></i>
                                <span><?= htmlspecialchars($shopName) ?></span>
                            </div>
                            <?php if (!empty($order['location']) || !empty($order['address'])): ?>
                                <div class="location-text">
                                    <i class='bx bx-map-pin' style="color: #64748b;"></i>
                                    <span><?= htmlspecialchars($order['location'] ?: $order['address']) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($order['phone'])): ?>
                                <div class="phone-links">
                                    <a href="tel:<?= htmlspecialchars($order['phone']) ?>" class="btn-phone">
                                        <i class='bx bx-phone'></i> <?= htmlspecialchars($order['phone']) ?>
                                    </a>
                                    <?php if (!empty($waLink)): ?>
                                        <a href="<?= $waLink ?>" target="_blank" class="btn-phone btn-whatsapp" title="Chat on WhatsApp">
                                            <i class='bx bxl-whatsapp'></i> WhatsApp
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Ordered Items Breakdown -->
                        <div class="items-summary-box" id="itemsSummaryBox_<?= $orderId ?>">
                            <?php if ($hasNoProducts): ?>
                                <div>
                                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #b45309; font-weight: 700;">Items</div>
                                    <div class="items-desc" style="color: #b45309; font-weight: 600; display: flex; align-items: center; gap: 4px;">
                                        <i class='bx bx-error-circle'></i> No products &amp; quantities added
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <button type="button" class="btn-card-action" style="background: #059669; color: #ffffff; border-color: #059669; font-weight: 700; padding: 4px 10px; font-size: 12px;" onclick="openAddOrderProductsModal(<?= $orderId ?>, <?= htmlspecialchars(json_encode((string)$orderNum), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$shopName), ENT_QUOTES, 'UTF-8') ?>)">
                                        <i class='bx bx-plus-circle'></i> Add Products
                                    </button>
                                </div>
                            <?php else: ?>
                                <div>
                                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; font-weight: 700;">Items</div>
                                    <div class="items-desc"><?= htmlspecialchars($itemsText) ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; font-weight: 700;">Total</div>
                                    <div class="order-total-amount">₹<?= number_format($totalAmt, 2) ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Assigned Vehicle & On-Truck Line-Sale Stock Indicator -->
                        <?php if (!empty($order['assigned_vehicle_number'])): ?>
                            <div class="assigned-veh-info-strip" style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; font-size: 12px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                    <div style="font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 6px;">
                                        <i class='bx bxs-truck' style="color: #2563eb; font-size: 16px;"></i>
                                        <span>Assigned Vehicle: <strong><?= htmlspecialchars($order['assigned_vehicle_number']) ?></strong> <?= !empty($order['assigned_vehicle_name']) ? '(' . htmlspecialchars($order['assigned_vehicle_name']) . ')' : '' ?></span>
                                    </div>
                                    <a href="<?= BASE_URL ?>/zone/orders/dashboard/vehicles/?search=<?= urlencode($order['assigned_vehicle_number']) ?>" target="_blank" style="font-size: 11px; color: #2563eb; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 2px;">
                                        <span>View Vehicle</span> <i class='bx bx-right-arrow-alt'></i>
                                    </a>
                                </div>
                                <?php if (!empty($order['vehicle_buffer_stock'])): ?>
                                    <div style="margin-top: 6px; font-size: 11px; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; padding: 4px 8px; border-radius: 6px; display: flex; align-items: center; gap: 5px;">
                                        <i class='bx bx-archive' style="font-size: 13px;"></i>
                                        <div><strong>On-Board Line-Sale Stock:</strong> <?= htmlspecialchars($order['vehicle_buffer_stock']) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Card Action Buttons -->
                        <div class="card-actions-row" style="flex-wrap: wrap; gap: 8px;">
                            <!-- Quick Status Update Dropdown -->
                            <select class="status-select" onchange="updateOrderStatus(<?= $orderId ?>, this.value, this)">
                                <option value="pending" <?= ($order['status'] === 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="processing" <?= ($order['status'] === 'processing') ? 'selected' : '' ?>>Processing</option>
                                <option value="shipped" <?= ($order['status'] === 'shipped') ? 'selected' : '' ?>>Shipped</option>
                                <option value="delivered" <?= ($order['status'] === 'delivered') ? 'selected' : '' ?>>Delivered</option>
                                <option value="cancelled" <?= ($order['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            </select>

                            <!-- Quick Vehicle Assignment Selector -->
                            <select class="status-select order-vehicle-select" id="orderVehSelect_<?= $orderId ?>" style="max-width: 160px; font-size: 11px; border-color: #cbd5e1;" onchange="assignOrderToVehicleFromCard(<?= $orderId ?>, this.value)" title="Assign to Vehicle">
                                <option value="">🚚 Assign Vehicle</option>
                                <?php foreach ($allActiveVehicles as $veh): ?>
                                    <option value="<?= $veh['id'] ?>" <?= ($order['status'] !== 'delivered' && (int)($order['vehicle_id'] ?? 0) === (int)$veh['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($veh['vehicle_number']) ?> (<?= (int)$veh['total_cases_loaded'] ?> cs)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- In Yard Check / Uncheck Toggle Button
                            <?php $isInYard = !empty($order['in_yard']); ?>
                            <button type="button" 
                                    class="btn-card-action btn-yard-toggle <?= $isInYard ? 'yard-in' : 'yard-out' ?>" 
                                    id="btnInYard_<?= $orderId ?>" 
                                    onclick="toggleOrderInYard(<?= $orderId ?>, this)" 
                                    title="Check/Uncheck whether order is in yard">
                                <i class='bx <?= $isInYard ? 'bx-checkbox-checked' : 'bx-checkbox' ?>'></i>
                                <span class="yard-text"><?= $isInYard ? 'In Yard' : 'Not in Yard' ?></span>
                            </button> -->

                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                              
                                    <button type="button" class="btn-card-action" style="background: #ecfdf5; color: #047857; border-color: #a7f3d0; font-weight: 700;" onclick="openAddOrderProductsModal(<?= $orderId ?>, <?= htmlspecialchars(json_encode((string)$orderNum), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$shopName), ENT_QUOTES, 'UTF-8') ?>)" title="Add Products & Quantities">
                                        <i class='bx bx-plus-circle'></i> Add Products
                                    </button>
                               

                                <a href="<?= BASE_URL ?>/admin/orders/create.php?reorder_id=<?= $orderId ?>" 
                                   class="btn-card-action" style="color: #2563eb; background: #eff6ff; border-color: #bfdbfe; font-weight: 700;" title="Place Reorder for this Shop">
                                    <i class='bx bx-refresh'></i> Reorder
                                </a>

                                <!-- <button type="button" class="btn-card-action" onclick="viewOrderModal(<?= $orderId ?>)" title="View Order Details">
                                    <i class='bx bx-show'></i> Details
                                </button> -->

                                <?php if (!empty($order['zone_slug'])): ?>
                                    <a href="<?= BASE_URL ?>/zone/generate-receipt.php?zone=<?= urlencode($order['zone_slug']) ?>&order_id=<?= $orderId ?>" 
                                       target="_blank" class="btn-card-action btn-card-primary" title="Receipt">
                                        <i class='bx bx-receipt'></i> Bill
                                    </a>
                                <?php endif; ?>

                                <?php if ($isUnread): ?>
                                    <button type="button" class="btn-card-action" id="btnAck_<?= $orderId ?>" onclick="acknowledgeOrder(<?= $orderId ?>)" title="Mark checked">
                                        <i class='bx bx-check'></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <!-- Floating Audio Notification -->
    <audio id="notifAudio" preload="auto">
        <source src="<?= BASE_URL ?>/assets/videos/notify.wav" type="audio/wav">
    </audio>

    <!-- Toast Notification Container -->
    <div id="toastContainer"></div>

    <!-- Order Details Modal -->
    <div class="modal-backdrop" id="orderModalBackdrop">
        <div class="modal-box" id="orderModalBox">
            <div class="modal-header">
                <div>
                    <h3 style="font-size: 18px; font-weight: 800; color: #0f172a;" id="modalOrderTitle">Order Details</h3>
                    <div style="font-size: 12px; color: #64748b;" id="modalOrderSubtitle">Loading...</div>
                </div>
                <button type="button" class="modal-close" onclick="closeOrderModal()">&times;</button>
            </div>
            <div id="modalOrderBody">
                <div style="text-align: center; padding: 40px 0;">
                    <i class='bx bx-loader-alt bx-spin' style="font-size: 32px; color: #2563eb;"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Products to Order Modal (when created without products) -->
    <div class="modal-backdrop" id="modalAddOrderProductsBackdrop" style="z-index: 1050;">
        <div class="modal-box" style="max-width: 700px; width: 95%;">
            <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 14px;">
                <div>
                    <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class='bx bx-cart-add' style="color: #059669; font-size: 22px;"></i>
                        <span id="aopModalTitle">Add Products &amp; Quantities</span>
                    </h3>
                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;" id="aopModalSubtitle">Specify products for this order</div>
                </div>
                <button type="button" class="modal-close" onclick="closeAddOrderProductsModal()">&times;</button>
            </div>
            <div style="padding: 18px 0 0 0;">
                <form id="formAddOrderProducts" onsubmit="event.preventDefault(); submitAddOrderProducts();">
                    <input type="hidden" id="aopOrderId" name="order_id" value="">
                    
                    <div style="margin-bottom: 14px; max-height: 280px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700;">
                                    <th style="padding: 6px 8px;">Product</th>
                                    <th style="padding: 6px 8px; width: 95px;">Rate (₹)</th>
                                    <th style="padding: 6px 8px; width: 95px;">Qty (cs)</th>
                                    <th style="padding: 6px 8px; width: 90px; text-align: center;">Extra Cases</th>
                                    <th style="padding: 6px 8px; width: 95px; text-align: right;">Total</th>
                                    <th style="padding: 6px 4px; width: 36px;"></th>
                                </tr>
                            </thead>
                            <tbody id="aopItemsTableBody">
                                <!-- Dynamic product rows -->
                            </tbody>
                        </table>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                        <button type="button" class="btn-card-action" onclick="addAopRow()" style="font-size: 12px; font-weight: 700; color: #2563eb; background: #eff6ff; border-color: #bfdbfe;">
                            <i class='bx bx-plus'></i> Add Another Product
                        </button>
                        <div style="text-align: right;">
                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">Total Order Amount: </span>
                            <span id="aopGrandTotal" style="font-size: 18px; font-weight: 800; color: #059669;">₹0.00</span>
                        </div>
                    </div>

                    <div id="aopErrorMsg" style="display: none; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #b91c1c; font-size: 13px; margin-bottom: 14px;"></div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="btn-card-action" onclick="closeAddOrderProductsModal()" style="padding: 8px 18px;">Cancel</button>
                        <button type="submit" id="btnAopSubmit" class="btn-card-action btn-card-primary" style="padding: 8px 22px; font-weight: 700; background: #059669; border-color: #059669;">
                            <i class='bx bx-check-circle'></i> Save &amp; Update Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Engine for Real-Time Polling & Interactivity -->
    <script>
        (function() {
            const BASE_URL = <?= json_encode(BASE_URL) ?>;
            const currentZone = <?= json_encode($selectedZone) ?>;
            const apiBase = BASE_URL.replace(/^https?:\/\/[^\/]+/i, window.location.origin);
            const pollApiUrl = apiBase + '/zone/orders/dashboard/poll.php?zone=' + encodeURIComponent(currentZone);
            const notifAudio = document.getElementById('notifAudio');
            const toastContainer = document.getElementById('toastContainer');
            const btnSoundToggle = document.getElementById('btnSoundToggle');
            const soundIcon = document.getElementById('soundIcon');
            const soundLabel = document.getElementById('soundLabel');

            let soundEnabled = true;
            let audioUnlocked = false;
            let lastAudioPlayTime = 0;

            // Set of known order IDs currently displayed on this client
            const knownOrderIds = new Set(<?= json_encode(array_values(array_map(fn($o) => (int)$o['order_id'], $orders))) ?>);

            // Attempt to unlock browser audio context
            function unlockAudioContext() {
                if (audioUnlocked || !notifAudio) return;
                notifAudio.play().then(() => {
                    notifAudio.pause();
                    notifAudio.currentTime = 0;
                    audioUnlocked = true;
                }).catch(() => {});
            }

            // Play notification chime
            function playChime() {
                if (!soundEnabled) return;
                const now = Date.now();
                if (now - lastAudioPlayTime < 2500) return; // rate limit chime
                lastAudioPlayTime = now;

                if (notifAudio) {
                    notifAudio.currentTime = 0;
                    notifAudio.play().then(() => {
                        audioUnlocked = true;
                    }).catch(() => {
                        // Web Audio API synthesizer fallback if audio element fails
                        try {
                            const ctx = new (window.AudioContext || window.webkitAudioContext)();
                            const osc = ctx.createOscillator();
                            const gain = ctx.createGain();
                            osc.type = 'sine';
                            osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
                            osc.frequency.exponentialRampToValueAtTime(880, ctx.currentTime + 0.15); // A5
                            gain.gain.setValueAtTime(0.3, ctx.currentTime);
                            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
                            osc.connect(gain);
                            gain.connect(ctx.destination);
                            osc.start();
                            osc.stop(ctx.currentTime + 0.4);
                        } catch(e) {}
                    });
                }
            }

            // Sound Toggle Handler
            if (btnSoundToggle) {
                btnSoundToggle.addEventListener('click', function() {
                    unlockAudioContext();
                    soundEnabled = !soundEnabled;
                    if (soundEnabled) {
                        btnSoundToggle.classList.add('sound-active');
                        soundIcon.className = 'bx bxs-volume-full';
                        soundLabel.textContent = 'Sound ON';
                        playChime();
                        showToast('🔔 Sound Active', 'Real-time alert chime is enabled for incoming zone orders.');
                    } else {
                        btnSoundToggle.classList.remove('sound-active');
                        soundIcon.className = 'bx bxs-volume-mute';
                        soundLabel.textContent = 'Sound MUTED';
                        showToast('🔕 Sound Muted', 'Audio alerts are now muted.');
                    }
                });
            }

            // Toast notification display
            function showToast(title, body, actionLink = null) {
                if (!toastContainer) return;
                const toast = document.createElement('div');
                toast.className = 'portal-toast';
                toast.innerHTML = `
                    <i class='bx bxs-bell-ring toast-icon'></i>
                    <div class="toast-content">
                        <div class="toast-title">${title}</div>
                        <div class="toast-body">${body}</div>
                        ${actionLink ? `<a href="${actionLink}" style="display:inline-block; margin-top:6px; color:#93c5fd; font-weight:700; font-size:12px; text-decoration:underline;">View Details &rarr;</a>` : ''}
                    </div>
                    <button type="button" class="toast-close" onclick="this.closest('.portal-toast').remove()">&times;</button>
                `;
                toastContainer.appendChild(toast);
                setTimeout(() => {
                    toast.classList.add('closing');
                    setTimeout(() => toast.remove(), 350);
                }, 8000);
            }

            // Real-time AJAX refresh function (runs every 3 seconds)
            let isRefreshing = false;
            function pollOrders() {
                if (isRefreshing) return;

                // Check if either modal is currently open
                const modalBackdrop = document.getElementById('orderModalBackdrop');
                const aopModalBackdrop = document.getElementById('modalAddOrderProductsBackdrop');
                const isModalOpen = (modalBackdrop && modalBackdrop.classList.contains('open')) ||
                                    (aopModalBackdrop && aopModalBackdrop.classList.contains('open'));

                // Check if user is actively interacting with an input or select
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

                    // 1. Detect any newly arrived incoming orders
                    const newCards = doc.querySelectorAll('#ordersFeed .order-card');
                    let newIncoming = false;

                    newCards.forEach(card => {
                        const ordId = parseInt(card.getAttribute('data-order-id'));
                        if (ordId && !knownOrderIds.has(ordId)) {
                            knownOrderIds.add(ordId);
                            if (card.classList.contains('unread') || card.querySelector('.badge-unread')) {
                                newIncoming = true;
                                const shop = card.querySelector('.shop-name span')?.innerText?.trim() || 'Customer';
                                const ordNum = card.querySelector('.order-num')?.innerText?.trim() || ('#' + ordId);
                                const amt = card.querySelector('.order-total-amount')?.innerText?.trim() || '';
                                showToast(`🔔 New Order Received: ${ordNum}`, `<strong>${shop}</strong> • ${amt}`);
                            }
                        }
                    });

                    if (newIncoming) {
                        playChime();
                        if (navigator.vibrate) {
                            navigator.vibrate([200, 100, 200]);
                        }
                    }

                    // 2. Update window title
                    if (doc.title) {
                        document.title = doc.title;
                    }

                    // 3. Update Unread Notification Banner
                    const currentBanner = document.getElementById('unreadNotifBanner');
                    const newBanner = doc.getElementById('unreadNotifBanner');
                    const mainContainer = document.querySelector('.portal-container');

                    if (newBanner) {
                        if (currentBanner) {
                            currentBanner.innerHTML = newBanner.innerHTML;
                        } else if (mainContainer) {
                            const accordion = mainContainer.querySelector('.metrics-accordion');
                            if (accordion) {
                                mainContainer.insertBefore(newBanner, accordion);
                            } else {
                                mainContainer.prepend(newBanner);
                            }
                        }
                    } else if (currentBanner) {
                        currentBanner.remove();
                    }

                    // 4. Update Metrics Grid
                    const currentMetrics = document.getElementById('metricsGrid');
                    const newMetrics = doc.getElementById('metricsGrid');
                    if (currentMetrics && newMetrics) {
                        const isOpen = currentMetrics.classList.contains('is-open');
                        currentMetrics.innerHTML = newMetrics.innerHTML;
                        if (isOpen) {
                            currentMetrics.classList.add('is-open');
                        }
                    }

                    // 5. Update Zone Pills & Filter Chips
                    const currentZonePills = document.querySelector('.zone-pills-row');
                    const newZonePills = doc.querySelector('.zone-pills-row');
                    if (currentZonePills && newZonePills) {
                        currentZonePills.innerHTML = newZonePills.innerHTML;
                    }

                    const currentStatusChips = document.querySelector('.status-chips-wrap');
                    const newStatusChips = doc.querySelector('.status-chips-wrap');
                    if (currentStatusChips && newStatusChips) {
                        currentStatusChips.innerHTML = newStatusChips.innerHTML;
                    }

                    // 6. Update Orders Feed (if modal is not open and no active select inside feed)
                    const currentFeed = document.getElementById('ordersFeed');
                    const newFeed = doc.getElementById('ordersFeed');
                    if (currentFeed && newFeed && !isModalOpen) {
                        const isFeedFocused = isFormActive && currentFeed.contains(activeEl);
                        if (!isFeedFocused) {
                            currentFeed.innerHTML = newFeed.innerHTML;
                        }
                    }
                })
                .catch(err => console.debug('Dashboard AJAX refresh error:', err))
                .finally(() => {
                    isRefreshing = false;
                });
            }

            // Start polling timer every 3 seconds
            setInterval(pollOrders, 3000);
            window.pollOrders = pollOrders;

            // Unlock audio on first user touch / click
            document.addEventListener('click', unlockAudioContext, { once: true });

            // ----------------------------------------------------
            // Global Order Actions
            // ----------------------------------------------------

            // 1. Quick Status Update via AJAX
            window.updateOrderStatus = function(orderId, newStatus, selectElem) {
                const parsedId = parseInt(orderId || 0);
                if (!parsedId || parsedId <= 0) {
                    alert('Invalid order ID.');
                    return;
                }
                if (selectElem) selectElem.disabled = true;

                const fd = new FormData();
                fd.append('action', 'update_status');
                fd.append('order_id', parsedId);
                fd.append('status', newStatus);

                fetch(pollApiUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (selectElem) selectElem.disabled = false;
                        if (res.success) {
                            const badge = document.getElementById('statusBadge_' + parsedId);
                            if (badge) {
                                badge.className = 'status-badge ' + res.badge_class;
                                badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                            }
                            const unread = document.getElementById('unreadBadge_' + parsedId);
                            if (unread) unread.remove();

                            // Auto reset vehicle selection dropdown if status is delivered
                            if (newStatus === 'delivered' || res.vehicle_reset) {
                                const vs = document.getElementById('orderVehSelect_' + parsedId);
                                if (vs) vs.value = '';
                            }

                            showToast('Status Updated', res.message);
                            if (typeof refreshVehiclesData === 'function') refreshVehiclesData();
                            setTimeout(pollOrders, 300);
                        } else {
                            alert(res.error || 'Failed to update status.');
                        }
                    })
                    .catch(e => {
                        if (selectElem) selectElem.disabled = false;
                        console.error('Status update failed:', e);
                    });
            };

            // 1b. Assign / Unassign Order to Vehicle via AJAX
            window.assignOrderToVehicleFromCard = function(orderId, vehicleId) {
                const parsedId = parseInt(orderId || 0);
                if (!parsedId || parsedId <= 0) {
                    alert('Invalid order ID.');
                    return;
                }
                const selectElem = document.getElementById('orderVehSelect_' + parsedId);
                if (selectElem) selectElem.disabled = true;

                const fd = new FormData();
                fd.append('order_id', parsedId);
                if (!vehicleId) {
                    fd.append('action', 'unassign_order');
                } else {
                    fd.append('action', 'assign_vehicle');
                    fd.append('vehicle_id', vehicleId);
                }

                fetch(pollApiUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (selectElem) selectElem.disabled = false;
                        if (res.success) {
                            showToast('Vehicle Assigned', res.message || 'Vehicle assignment updated successfully.');
                            if (typeof refreshVehiclesData === 'function') refreshVehiclesData();
                            setTimeout(pollOrders, 300);
                        } else {
                            alert(res.error || 'Failed to update vehicle assignment.');
                            setTimeout(pollOrders, 300);
                        }
                    })
                    .catch(e => {
                        if (selectElem) selectElem.disabled = false;
                        console.error('Vehicle assignment failed:', e);
                    });
            };

            // 2. Acknowledge / Mark Single Order as Checked
            window.acknowledgeOrder = function(orderId) {
                const parsedId = parseInt(orderId || 0);
                if (!parsedId || parsedId <= 0) return;
                const btn = document.getElementById('btnAck_' + parsedId);
                if (btn) btn.disabled = true;

                const fd = new FormData();
                fd.append('action', 'mark_read');
                fd.append('order_id', parsedId);

                fetch(pollApiUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            const card = document.getElementById('orderCard_' + parsedId);
                            if (card) card.classList.remove('unread');
                            const unread = document.getElementById('unreadBadge_' + parsedId);
                            if (unread) unread.remove();
                            if (btn) btn.remove();
                            showToast('Order Checked', res.message);
                            setTimeout(pollOrders, 300);
                        }
                    });
            };

            // 3. Mark All Orders as Checked
            window.markAllOrdersChecked = function() {
                const fd = new FormData();
                fd.append('action', 'mark_all_read');

                fetch(pollApiUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            const banner = document.getElementById('unreadNotifBanner');
                            if (banner) banner.remove();
                            document.querySelectorAll('.badge-unread').forEach(b => b.remove());
                            document.querySelectorAll('.order-card.unread').forEach(c => c.classList.remove('unread'));
                            showToast('Orders Checked', 'All pending orders marked as checked.');
                            setTimeout(pollOrders, 300);
                        }
                    });
            };

            // 4. View Order Details Modal
            window.viewOrderModal = function(orderId) {
                const parsedId = parseInt(orderId || 0);
                if (!parsedId || parsedId <= 0) {
                    alert('Invalid order ID.');
                    return;
                }
                const backdrop = document.getElementById('orderModalBackdrop');
                const title = document.getElementById('modalOrderTitle');
                const subtitle = document.getElementById('modalOrderSubtitle');
                const body = document.getElementById('modalOrderBody');

                backdrop.classList.add('open');
                title.textContent = 'Loading Order #' + parsedId + '...';
                subtitle.textContent = 'Fetching details from warehouse database';
                body.innerHTML = "<div style='text-align:center; padding: 40px 0;'><i class='bx bx-loader-alt bx-spin' style='font-size: 32px; color: #2563eb;'></i></div>";

                fetch(pollApiUrl + '&action=get_order_details&order_id=' + parsedId)
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success || !res.order) {
                            body.innerHTML = "<div style='color:#dc2626; padding:20px 0;'>Failed to load order details.</div>";
                            return;
                        }

                        const o = res.order;
                        title.textContent = 'Order #' + (o.order_number || o.order_id);
                        subtitle.textContent = (o.zone_name || 'Zone') + ' • Created ' + o.formatted_date;

                        let itemsHtml = '';
                        if (Array.isArray(o.items) && o.items.length > 0) {
                            o.items.forEach(it => {
                                const pName = it.product_name || it.name || 'Drinking Water';
                                const q = it.quantity || 1;
                                const isExtra = parseInt(it.is_extra || 0) === 1;
                                const rate = parseFloat(it.price_at_purchase || 0).toFixed(2);
                                const lTot = parseFloat(it.line_total || 0).toFixed(2);
                                const extraBadge = isExtra 
                                    ? `<span style="font-size:10px; color:#059669; font-weight:800; background:#dcfce7; border:1px solid #bbf7d0; padding:1px 5px; border-radius:4px; margin-left:4px;">EXTRA</span>`
                                    : '';
                                const rateText = isExtra 
                                    ? '<span style="color:#059669; font-weight:700;"></span>' 
                                    : `₹${lTot} <span style="color:#64748b; font-size:11px;">(@ ₹${rate})</span>`;
                                itemsHtml += `
                                    <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f1f5f9; font-size:13px;">
                                        <div><strong>${pName}</strong> &times; ${q} Cases ${extraBadge}</div>
                                        <div>${rateText}</div>
                                    </div>
                                `;
                            });
                        } else {
                            itemsHtml = `<div style="padding:8px 0; font-size:13px; color:#475569;">${o.product_name || 'Drinking Water'} (${o.quantity} Cases)</div>`;
                        }

                        body.innerHTML = `
                            <div style="margin-bottom: 16px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                    <span class="status-badge ${o.badge_class}" style="font-size:13px;">${o.status.toUpperCase()}</span>
                                    <span style="font-size:18px; font-weight:800; color:#0f172a;">Total: ₹${parseFloat(o.total_amount).toFixed(2)}</span>
                                </div>
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; margin-bottom:16px;">
                                    <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b;">Customer / Outlet</div>
                                    <div style="font-size:15px; font-weight:700; color:#0f172a; margin-top:2px;">${o.shop_name || o.customer_name || 'Customer'}</div>
                                    ${o.location ? `<div style="font-size:12px; color:#475569; margin-top:4px;"><i class='bx bx-map-pin'></i> ${o.location}</div>` : ''}
                                    ${o.address ? `<div style="font-size:12px; color:#475569; margin-top:2px;">${o.address}</div>` : ''}
                                    ${o.phone ? `<div style="font-size:12px; margin-top:6px;"><i class='bx bx-phone'></i> <a href="tel:${o.phone}" style="color:#2563eb; font-weight:600;">${o.phone}</a></div>` : ''}
                                </div>

                                <div style="margin-bottom:16px;">
                                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:6px;">Items Breakdown</div>
                                    ${itemsHtml}
                                </div>

                                ${o.vehicle_number ? `
                                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; margin-bottom:16px;">
                                        <div style="font-size:12px; font-weight:700; color:#334155; display:flex; align-items:center; gap:6px;">
                                            <i class='bx bxs-truck' style="color:#2563eb;"></i> Assigned Vehicle: <strong>${o.vehicle_number}</strong> ${o.vehicle_name ? '(' + o.vehicle_name + ')' : ''}
                                        </div>
                                        ${Array.isArray(o.vehicle_storage) && o.vehicle_storage.length > 0 ? `
                                            <div style="margin-top:6px; font-size:12px; color:#b45309; background:#fef3c7; border:1px solid #fde68a; padding:6px 10px; border-radius:6px;">
                                                <i class='bx bx-archive'></i> <strong>In Vehicle Storage:</strong>
                                                ${o.vehicle_storage.map(s => s.product_name + ' (' + s.cases_loaded + ' cs)').join(', ')}
                                            </div>
                                        ` : ''}
                                    </div>
                                ` : ''}

                                ${o.bill_number ? `
                                    <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:10px; padding:10px 14px; margin-bottom:16px; font-size:13px; color:#065f46;">
                                        <strong>Receipt Generated:</strong> Bill #${o.bill_number} (Cash: ₹${parseFloat(o.receipt_cash || 0).toFixed(2)}, Due: ₹${parseFloat(o.receipt_due || 0).toFixed(2)})
                                    </div>
                                ` : ''}

                                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                                    ${o.zone_slug ? `
                                        <a href="${BASE_URL}/zone/generate-receipt.php?zone=${encodeURIComponent(o.zone_slug)}&order_id=${o.order_id}" target="_blank" class="btn-card-action btn-card-primary" style="padding:8px 16px;">
                                            <i class='bx bx-receipt'></i> Generate / View Receipt
                                        </a>
                                    ` : ''}
                                    <button type="button" class="btn-card-action" onclick="closeOrderModal()" style="padding:8px 16px;">Close</button>
                                </div>
                            </div>
                        `;
                    });
            };

            window.closeOrderModal = function() {
                const backdrop = document.getElementById('orderModalBackdrop');
                if (backdrop) backdrop.classList.remove('open');
            };

            // 5. Toggle In Yard Status for Order
            window.toggleOrderInYard = function(orderId, btn) {
                const parsedId = parseInt(orderId || 0);
                if (!parsedId || parsedId <= 0) {
                    alert('Invalid order ID.');
                    return;
                }
                if (!btn) btn = document.getElementById('btnInYard_' + parsedId);
                if (btn) btn.disabled = true;

                const fd = new FormData();
                fd.append('action', 'toggle_in_yard');
                fd.append('order_id', parsedId);

                fetch(pollApiUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (btn) btn.disabled = false;
                        if (res.success) {
                            const inYard = (res.in_yard === 1);
                            if (inYard) {
                                btn.className = 'btn-card-action btn-yard-toggle yard-in';
                                btn.innerHTML = "<i class='bx bx-checkbox-checked'></i> <span class='yard-text'>In Yard</span>";
                            } else {
                                btn.className = 'btn-card-action btn-yard-toggle yard-out';
                                btn.innerHTML = "<i class='bx bx-checkbox'></i> <span class='yard-text'>Not in Yard</span>";
                            }
                            showToast('Yard Status Updated', `Order #${parsedId} marked as ${res.label}.`);
                        } else {
                            alert(res.error || 'Failed to update yard status.');
                        }
                    })
                    .catch(e => {
                        if (btn) btn.disabled = false;
                        console.error('Yard toggle failed:', e);
                    });
            };

            // 6. Add Products Modal Controls & Logic (When Order has no items)
            const AOP_PRODUCTS = <?= json_encode($allProductsList) ?>;

            function getAopProductOptionsHtml(selectedPid = 0, extraStockAllowance = 0) {
                let html = '<option value="">-- Choose Product Item --</option>';
                AOP_PRODUCTS.forEach(p => {
                    const pid = parseInt(p.product_id);
                    const pName = p.product_name || p.name || 'Product';
                    const rate = parseFloat(p.case_price || p.price || 0);
                    let stock = Math.max(0, parseInt(p.case_stock !== null && p.case_stock !== undefined ? p.case_stock : (p.stock || 0)));
                    if (pid === parseInt(selectedPid)) {
                        stock += parseInt(extraStockAllowance || 0);
                    }
                    const sel = (pid === parseInt(selectedPid)) ? 'selected' : '';
                    if (stock <= 0 && pid !== parseInt(selectedPid)) {
                        html += `<option value="${pid}" data-rate="${rate}" data-stock="0" disabled style="color: #94a3b8;">${pName} (₹${rate.toFixed(2)}/cs - Out of stock)</option>`;
                    } else {
                        html += `<option value="${pid}" data-rate="${rate}" data-stock="${stock}" ${sel}>${pName} (₹${rate.toFixed(2)}/cs, Stock: ${stock})</option>`;
                    }
                });
                return html;
            }

            window.openAddOrderProductsModal = function(orderId, orderNum, shopName) {
                const backdrop = document.getElementById('modalAddOrderProductsBackdrop');
                if (!backdrop) return;

                const parsedId = parseInt(orderId || 0);
                if (!parsedId || parsedId <= 0) {
                    alert('Invalid order ID. Cannot open products editor.');
                    return;
                }

                document.getElementById('aopOrderId').value = parsedId;
                document.getElementById('aopModalTitle').textContent = `Add / Edit Products: #${orderNum || parsedId}`;
                document.getElementById('aopModalSubtitle').textContent = `Shop: ${shopName || 'Customer'}`;
                
                const errBox = document.getElementById('aopErrorMsg');
                if (errBox) {
                    errBox.textContent = '';
                    errBox.style.display = 'none';
                }

                const tbody = document.getElementById('aopItemsTableBody');
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 24px; color: #64748b;">
                            <i class='bx bx-loader-alt bx-spin' style="font-size: 22px; color: #2563eb; vertical-align: middle; margin-right: 6px;"></i>
                            Loading existing order products...
                        </td>
                    </tr>
                `;

                backdrop.classList.add('open');

                // Fetch existing order items so previous products are NEVER lost or erased!
                fetch(pollApiUrl + '&action=get_order_details&order_id=' + parsedId)
                    .then(r => r.json())
                    .then(res => {
                        tbody.innerHTML = '';
                        if (res.success && res.order) {
                            if (Array.isArray(res.order.products) && res.order.products.length > 0) {
                                AOP_PRODUCTS.length = 0;
                                AOP_PRODUCTS.push(...res.order.products);
                            }
                            if (Array.isArray(res.order.items) && res.order.items.length > 0) {
                                // Populate existing items
                                res.order.items.forEach(it => {
                                    const pid = parseInt(it.product_id || 0);
                                    const qty = parseInt(it.quantity || 1);
                                    const rate = (it.price_at_purchase !== null && it.price_at_purchase !== undefined) ? parseFloat(it.price_at_purchase) : '';
                                    const isExtra = parseInt(it.is_extra || 0) === 1;
                                    addAopRow(pid, qty, rate, isExtra, qty);
                                });
                                // Add an additional empty row so user can immediately add another product
                                addAopRow();
                            } else {
                                // No existing products on order, start with 1 blank row
                                addAopRow();
                            }
                            calculateAopTotals();
                        }
                    })
                    .catch(err => {
                        console.error('Failed to load order products:', err);
                        tbody.innerHTML = '';
                        addAopRow();
                        calculateAopTotals();
                    });
            };

            window.closeAddOrderProductsModal = function() {
                const backdrop = document.getElementById('modalAddOrderProductsBackdrop');
                if (backdrop) backdrop.classList.remove('open');
            };

            window.addAopRow = function(selectedPid = 0, defaultQty = 1, defaultRate = '', isExtra = false, existingQty = 0) {
                const tbody = document.getElementById('aopItemsTableBody');
                if (!tbody) return;

                const tr = document.createElement('tr');
                tr.className = 'aop-row';
                tr.style.borderBottom = '1px solid #f1f5f9';
                tr.innerHTML = `
                    <td style="padding: 6px 8px;">
                        <select class="form-input-v aop-prod-select" style="width: 100%; font-size: 13px; padding: 6px 8px; border-radius: 6px; border: 1px solid #cbd5e1;" onchange="handleAopProductChange(this)">
                            ${getAopProductOptionsHtml(selectedPid, existingQty)}
                        </select>
                    </td>
                    <td style="padding: 6px 8px;">
                        <input type="text" step="0.01" min="0" class="form-input-v aop-rate-input" style="width: 100%; font-size: 13px; padding: 6px 8px; border-radius: 6px; border: 1px solid #cbd5e1;" placeholder="0.00" value="${defaultRate !== '' ? defaultRate : ''}" oninput="calculateAopTotals()">
                    </td>
                    <td style="padding: 6px 8px;">
                        <input type="text" min="1" max="9999" class="form-input-v aop-qty-input" style="width: 100%; font-size: 13px; padding: 6px 8px; border-radius: 6px; border: 1px solid #cbd5e1;" value="${defaultQty}" oninput="handleAopQtyInput(this)">
                        <div class="aop-stock-hint" style="font-size: 10px; color: #64748b; margin-top: 2px; font-weight: 600;"></div>
                    </td>
                    <td style="padding: 6px 8px; text-align: center;">
                        <label style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; cursor: pointer; font-size: 11px; font-weight: 600; color: #475569; user-select: none;">
                            <input type="checkbox" class="aop-extra-checkbox" ${isExtra ? 'checked' : ''} onchange="handleAopExtraChange(this)" style="width: 16px; height: 16px; accent-color: #059669; cursor: pointer;">
                            <span class="aop-extra-label" style="${isExtra ? 'color:#059669; font-weight:700;' : ''}">Extra</span>
                        </label>
                    </td>
                    <td style="padding: 6px 8px; text-align: right;">
                        <span class="aop-line-total" style="font-weight: 700; color: #0f172a; font-size: 13px;">₹0.00</span>
                    </td>
                    <td style="padding: 6px 4px; text-align: center;">
                        <button type="button" class="btn-card-action" onclick="removeAopRow(this)" style="color: #ef4444; border-color: #fca5a5; padding: 4px 6px; border-radius: 6px;" title="Remove row">
                            <i class='bx bx-trash'></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);

                const sel = tr.querySelector('.aop-prod-select');
                if (sel && sel.value) {
                    const rateInput = tr.querySelector('.aop-rate-input');
                    if (defaultRate !== '' && rateInput) {
                        rateInput.value = parseFloat(defaultRate).toFixed(2);
                    }
                    handleAopProductChange(sel, defaultRate !== '');
                } else {
                    calculateAopTotals();
                }
            };

            window.handleAopExtraChange = function(checkboxElem) {
                const row = checkboxElem.closest('tr');
                if (!row) return;
                const label = row.querySelector('.aop-extra-label');
                const rateInput = row.querySelector('.aop-rate-input');
                if (checkboxElem.checked) {
                    if (label) {
                        label.style.color = '#059669';
                        label.style.fontWeight = '700';
                    }
                    if (rateInput) {
                        rateInput.style.backgroundColor = '#f8fafc';
                    }
                } else {
                    if (label) {
                        label.style.color = '#475569';
                        label.style.fontWeight = '600';
                    }
                    if (rateInput) {
                        rateInput.style.backgroundColor = '#ffffff';
                    }
                }
                calculateAopTotals();
            };

            window.removeAopRow = function(btn) {
                const tbody = document.getElementById('aopItemsTableBody');
                const row = btn.closest('tr');
                if (row) row.remove();
                if (tbody && tbody.children.length === 0) {
                    addAopRow();
                }
                calculateAopTotals();
            };

            window.handleAopProductChange = function(selectElem, preserveRate = false) {
                const row = selectElem.closest('tr');
                if (!row) return;
                const opt = selectElem.options[selectElem.selectedIndex];
                const rateInput = row.querySelector('.aop-rate-input');
                const qtyInput = row.querySelector('.aop-qty-input');
                const stockHint = row.querySelector('.aop-stock-hint');

                const pid = parseInt(selectElem.value || 0);
                if (!pid || !opt) {
                    if (rateInput) rateInput.value = '';
                    if (qtyInput) {
                        qtyInput.removeAttribute('max');
                        qtyInput.disabled = false;
                    }
                    if (stockHint) stockHint.textContent = '';
                    calculateAopTotals();
                    return;
                }

                const rate = parseFloat(opt.dataset.rate || 0);
                const stock = parseInt(opt.dataset.stock || 0);

                if (rateInput && (!preserveRate || rateInput.value === '')) {
                    rateInput.value = rate.toFixed(2);
                }

                if (qtyInput) {
                    if (stock <= 0) {
                        qtyInput.max = 0;
                        qtyInput.value = 0;
                        qtyInput.disabled = true;
                        if (stockHint) {
                            stockHint.textContent = 'Out of stock (0 cs)';
                            stockHint.style.color = '#dc2626';
                        }
                    } else {
                        qtyInput.disabled = false;
                        qtyInput.max = stock;
                        let curVal = parseInt(qtyInput.value || 1);
                        if (curVal > stock) {
                            qtyInput.value = stock;
                        } else if (curVal < 1) {
                            qtyInput.value = 1;
                        }
                        if (stockHint) {
                            stockHint.textContent = `Max: ${stock} cs`;
                            stockHint.style.color = '#059669';
                        }
                    }
                }

                calculateAopTotals();
            };

            window.handleAopQtyInput = function(qtyInput) {
                const row = qtyInput.closest('tr');
                if (!row) return;
                const sel = row.querySelector('.aop-prod-select');
                const stockHint = row.querySelector('.aop-stock-hint');
                const opt = sel ? sel.options[sel.selectedIndex] : null;

                if (opt && opt.value) {
                    const stock = parseInt(opt.dataset.stock || 0);
                    let val = parseInt(qtyInput.value);

                    if (stock <= 0) {
                        qtyInput.value = 0;
                        if (stockHint) {
                            stockHint.textContent = 'Out of stock (0 cs)';
                            stockHint.style.color = '#dc2626';
                        }
                    } else if (val > stock) {
                        // Strictly clamp quantity to available stock!
                        qtyInput.value = stock;
                        if (stockHint) {
                            stockHint.textContent = `Cannot exceed stock! Capped to ${stock} cs`;
                            stockHint.style.color = '#dc2626';
                        }
                    } else if (val < 1 && qtyInput.value !== '') {
                        qtyInput.value = 1;
                        if (stockHint) {
                            stockHint.textContent = `Max: ${stock} cs`;
                            stockHint.style.color = '#059669';
                        }
                    } else {
                        if (stockHint) {
                            stockHint.textContent = `Max: ${stock} cs`;
                            stockHint.style.color = '#059669';
                        }
                    }
                }

                calculateAopTotals();
            };

            window.calculateAopTotals = function() {
                let grandTotal = 0;
                const productUsage = {};
                let stockExceededError = '';

                document.querySelectorAll('.aop-row').forEach(row => {
                    const sel = row.querySelector('.aop-prod-select');
                    const rateIn = row.querySelector('.aop-rate-input');
                    const qtyIn = row.querySelector('.aop-qty-input');
                    const extraIn = row.querySelector('.aop-extra-checkbox');
                    const totalSpan = row.querySelector('.aop-line-total');
                    const opt = sel ? sel.options[sel.selectedIndex] : null;

                    const pid = parseInt(sel ? sel.value : 0);
                    const stock = opt ? parseInt(opt.dataset.stock || 0) : 0;
                    const rate = parseFloat(rateIn ? rateIn.value : 0) || 0;
                    let qty = parseInt(qtyIn ? qtyIn.value : 0) || 0;
                    const isExtra = extraIn ? extraIn.checked : false;

                    if (pid > 0) {
                        if (stock > 0 && qty > stock) {
                            qty = stock;
                            if (qtyIn) qtyIn.value = stock;
                        }

                        productUsage[pid] = (productUsage[pid] || 0) + qty;
                        if (stock > 0 && productUsage[pid] > stock) {
                            const prodName = opt ? (opt.text.split('(')[0].trim()) : 'Product';
                            stockExceededError = `Total quantity for "${prodName}" across items (${productUsage[pid]}) exceeds available stock (${stock} cs).`;
                            if (qtyIn) qtyIn.style.borderColor = '#dc2626';
                        } else {
                            if (qtyIn) qtyIn.style.borderColor = '#cbd5e1';
                        }
                    }

                    let lineTotal = 0;
                    if (pid > 0 && qty > 0) {
                        lineTotal = rate * qty;
                    }

                    if (totalSpan) {
                        if (isExtra && pid > 0 && qty > 0) {
                            totalSpan.innerHTML = `<span style="text-decoration:line-through; color:#94a3b8; font-size:11px; margin-right:4px;">₹${lineTotal.toFixed(2)}</span>₹0.00 <span style="font-size:10px; color:#059669; font-weight:700;">(Extra)</span>`;
                        } else {
                            totalSpan.textContent = '₹' + lineTotal.toFixed(2);
                        }
                    }
                    if (!isExtra) grandTotal += lineTotal;
                });

                const gtSpan = document.getElementById('aopGrandTotal');
                if (gtSpan) gtSpan.textContent = '₹' + grandTotal.toFixed(2);

                const errBox = document.getElementById('aopErrorMsg');
                const submitBtn = document.getElementById('btnAopSubmit');
                if (stockExceededError) {
                    if (errBox) {
                        errBox.textContent = stockExceededError;
                        errBox.style.display = 'block';
                    }
                    if (submitBtn) submitBtn.disabled = true;
                } else {
                    if (errBox && errBox.textContent.includes('exceeds available stock')) {
                        errBox.textContent = '';
                        errBox.style.display = 'none';
                    }
                    if (submitBtn) submitBtn.disabled = false;
                }
            };

            window.submitAddOrderProducts = function() {
                const orderId = parseInt(document.getElementById('aopOrderId')?.value || 0);
                const errBox = document.getElementById('aopErrorMsg');
                const submitBtn = document.getElementById('btnAopSubmit');

                if (errBox) {
                    errBox.textContent = '';
                    errBox.style.display = 'none';
                }

                if (!orderId || isNaN(orderId) || orderId <= 0) {
                    if (errBox) {
                        errBox.textContent = 'Invalid order ID. Please re-open the product editor.';
                        errBox.style.display = 'block';
                    } else {
                        alert('Invalid order ID.');
                    }
                    return;
                }

                const rows = document.querySelectorAll('.aop-row');
                const items = [];
                const productTotals = {};
                let hasError = false;

                rows.forEach((row, idx) => {
                    const sel = row.querySelector('.aop-prod-select');
                    const rateIn = row.querySelector('.aop-rate-input');
                    const qtyIn = row.querySelector('.aop-qty-input');
                    const extraIn = row.querySelector('.aop-extra-checkbox');
                    const opt = sel ? sel.options[sel.selectedIndex] : null;

                    const pid = parseInt(sel ? sel.value : 0);
                    const rate = parseFloat(rateIn ? rateIn.value : 0);
                    const qty = parseInt(qtyIn ? qtyIn.value : 0);
                    const isExtra = extraIn ? (extraIn.checked ? 1 : 0) : 0;
                    const stock = opt ? parseInt(opt.dataset.stock || 0) : 0;
                    const pName = opt ? (opt.text.split('(')[0].trim()) : 'Product';

                    if (pid > 0) {
                        if (stock <= 0) {
                            hasError = true;
                            if (errBox) {
                                errBox.textContent = `"${pName}" is currently out of stock. Cannot add to order.`;
                                errBox.style.display = 'block';
                            }
                            return;
                        }

                        if (qty <= 0) {
                            hasError = true;
                            if (errBox) {
                                errBox.textContent = 'Please enter a valid quantity of 1 or more for each selected product.';
                                errBox.style.display = 'block';
                            }
                            return;
                        }

                        if (qty > stock) {
                            hasError = true;
                            if (errBox) {
                                errBox.textContent = `Quantity for "${pName}" (${qty} cs) exceeds available stock (${stock} cs).`;
                                errBox.style.display = 'block';
                            }
                            if (qtyIn) {
                                qtyIn.value = stock;
                                qtyIn.focus();
                            }
                            return;
                        }

                        productTotals[pid] = (productTotals[pid] || 0) + qty;
                        if (productTotals[pid] > stock) {
                            hasError = true;
                            if (errBox) {
                                errBox.textContent = `Total quantity for "${pName}" across rows (${productTotals[pid]} cs) exceeds available stock (${stock} cs).`;
                                errBox.style.display = 'block';
                            }
                            return;
                        }

                        items.push({
                            product_id: pid,
                            rate: isNaN(rate) ? 0 : rate,
                            quantity: qty,
                            is_extra: isExtra
                        });
                    }
                });

                if (hasError) return;

                if (items.length === 0) {
                    if (errBox) {
                        errBox.textContent = 'Please select at least one product with quantity.';
                        errBox.style.display = 'block';
                    }
                    return;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving...";
                }

                const fd = new FormData();
                fd.append('action', 'add_order_products');
                fd.append('order_id', orderId);
                items.forEach((it, i) => {
                    fd.append(`items[${i}][product_id]`, it.product_id);
                    fd.append(`items[${i}][rate]`, it.rate);
                    fd.append(`items[${i}][quantity]`, it.quantity);
                    fd.append(`items[${i}][is_extra]`, it.is_extra);
                });

                fetch(pollApiUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = "<i class='bx bx-check-circle'></i> Save &amp; Update Order";
                        }
                        if (res.success) {
                            closeAddOrderProductsModal();
                            showToast('Products Added', res.message || 'Products successfully added to order.');

                            // Update card DOM directly
                            const card = document.getElementById('orderCard_' + orderId);
                            if (card) {
                                const summaryBox = document.getElementById('itemsSummaryBox_' + orderId);
                                if (summaryBox) {
                                    summaryBox.innerHTML = `
                                        <div>
                                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; font-weight: 700;">Items</div>
                                            <div class="items-desc">${res.items_summary || ''}</div>
                                        </div>
                                        <div style="text-align: right;">
                                            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; font-weight: 700;">Total</div>
                                            <div class="order-total-amount">${res.formatted_total || ('₹' + parseFloat(res.total_amount).toFixed(2))}</div>
                                        </div>
                                    `;
                                }
                            }
                            setTimeout(pollOrders, 500);
                        } else {
                            if (errBox) {
                                errBox.textContent = res.error || 'Failed to save products.';
                                errBox.style.display = 'block';
                            } else {
                                alert(res.error || 'Failed to save products.');
                            }
                        }
                    })
                    .catch(e => {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = "<i class='bx bx-check-circle'></i> Save &amp; Update Order";
                        }
                        console.error('Failed to add products:', e);
                        if (errBox) {
                            errBox.textContent = 'Network or server error occurred while adding products.';
                            errBox.style.display = 'block';
                        }
                    });
            };

            // Close modals on escape or clicking outside
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeOrderModal();
                    closeAddOrderProductsModal();
                }
            });
            const backdrop = document.getElementById('orderModalBackdrop');
            if (backdrop) {
                backdrop.addEventListener('click', function(e) {
                    if (e.target === backdrop) closeOrderModal();
                });
            }
            const aopBackdrop = document.getElementById('modalAddOrderProductsBackdrop');
            if (aopBackdrop) {
                aopBackdrop.addEventListener('click', function(e) {
                    if (e.target === aopBackdrop) closeAddOrderProductsModal();
                });
            }
        })();

        function toggleMetrics() {
    const grid = document.getElementById('metricsGrid');
    const btn = document.getElementById('metricsToggleBtn');
    
    const isOpen = grid.classList.toggle('is-open');
    btn.classList.toggle('is-active', isOpen);
    btn.setAttribute('aria-expanded', isOpen);
}
    </script>

    <!-- Slide-Out Vehicles Sidebar Drawer -->
    <?php include dirname(dirname(dirname(__DIR__))) . '/components/vehicles-sidebar.php'; ?>

    <!-- Slide-Out Shop Rewards Sidebar Drawer -->
    <?php include dirname(dirname(dirname(__DIR__))) . '/components/rewards-sidebar.php'; ?>
</body>
</html>
