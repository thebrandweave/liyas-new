<?php
require_once __DIR__ . '/zone_context.php';

// Handle quick status update from zone portal
$msg = '';
$msg_type = 'success';

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
            if ($new_status === 'delivered') {
                $oStmt = $pdo->prepare("SELECT shop_name, customer_name, phone FROM orders WHERE order_id = ?");
                $oStmt->execute([$order_id]);
                $oData = $oStmt->fetch(PDO::FETCH_ASSOC);
                if ($oData) {
                    $sName = $oData['shop_name'] ?: ($oData['customer_name'] ?: '');
                    updateShopRewardProgress($pdo, $sName, $oData['phone']);
                }
            }
        }
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
    SELECT o.*, p.product_name, p.name as fallback_name
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.product_id
    WHERE o.zone_id = ? AND o.is_zone_read = 0 AND o.status != 'cancelled'
    ORDER BY o.created_at DESC
");
$unread_stmt->execute([$zone_id]);
$unread_notifications = $unread_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Status counts for filter chips (including due, credit, cash counts)
$counts_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.order_id) as total,
        SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN o.status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN r.due_amount > 0 THEN 1 ELSE 0 END) as due_count,
        SUM(CASE WHEN r.credited_amount > 0 THEN 1 ELSE 0 END) as credit_count,
        SUM(CASE WHEN r.cash_amount > 0 THEN 1 ELSE 0 END) as cash_count
    FROM orders o
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE o.zone_id = ?
");
$counts_stmt->execute([$zone_id]);
$status_counts = $counts_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0, 'pending' => 0, 'processing' => 0, 'shipped' => 0, 'delivered' => 0, 'cancelled' => 0,
    'due_count' => 0, 'credit_count' => 0, 'cash_count' => 0
];

// 3. Zone Orders (strictly filtered by zone_id)
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where_clauses = ["o.zone_id = :zone_id"];
$params = [':zone_id' => $zone_id];

// Dynamic filter matching
if ($filter === 'due') {
    $where_clauses[] = "r.due_amount > 0";
} elseif ($filter === 'credit') {
    $where_clauses[] = "r.credited_amount > 0";
} elseif ($filter === 'cash') {
    $where_clauses[] = "r.cash_amount > 0";
} elseif (in_array($filter, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
    $where_clauses[] = "o.status = :status";
    $params[':status'] = $filter;
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
        r.payment_type as receipt_pay_type
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE $where_sql
    ORDER BY o.created_at DESC
");
$orders_stmt->execute($params);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Zone Summary Metrics
$metrics_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
        COALESCE(SUM(r.cash_amount), 0) as total_cash_collected,
        COALESCE(SUM(r.credited_amount), 0) as total_credit,
        COALESCE(SUM(r.due_amount), 0) as total_due
    FROM orders o
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE o.zone_id = ?
");
$metrics_stmt->execute([$zone_id]);
$metrics = $metrics_stmt->fetch(PDO::FETCH_ASSOC);

// Helper function for WhatsApp link
function buildWhatsAppLink($phone, $shop, $orderNum, $amount) {
    $clean = preg_replace('/[^0-9]/', '', $phone ?? '');
    if (empty($clean)) return '';
    if (strlen($clean) === 10) {
        $clean = '91' . $clean;
    }
    $text = "Hello {$shop}, this is Liyas Water Delivery regarding Order #{$orderNum} (Total: {$amount}).";
    return "https://wa.me/{$clean}?text=" . rawurlencode($text);
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
            padding: 6px 12px;
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

        @media (min-width: 640px) {
            .metrics-grid {
                grid-template-columns: repeat(3, 1fr);
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

        /* Filter Chips and Search Control Bar */
        .controls-panel {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 12px;
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
            gap: 6px;
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
            padding: 8px 14px;
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

        /* Order Cards Grid / List */
        .orders-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .order-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px;
            box-shadow: var(--shadow-subtle);
            transition: all 0.2s ease;
            position: relative;
        }

        .order-card:hover {
            border-color: #cbd5e1;
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
            font-size: 12px;
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
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .chip-call:hover { background: #dbeafe; }

        .chip-whatsapp {
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
                <div class="brand-icon">
                    <i class='bx bx-water'></i>
                </div>
                <div>
                    <div class="brand-title">Liyas Delivery</div>
                    <div class="brand-subtitle">
                        <span>Zone Portal</span>
                        <span>&bull;</span>
                        <span style="color: #2563eb;"><?= htmlspecialchars($zone_name) ?></span>
                    </div>
                </div>
            </a>

            <div class="header-actions">
                <!-- Sound Alerts Toggle / Test Button -->
                <button type="button" id="btnSoundToggle" class="btn-sound-toggle" title="Sound alerts enabled. Click to test notify.wav sound">
                    <i class='bx bxs-volume-full' id="soundIcon"></i>
                    <span id="soundLabel">Sound Active</span>
                </button>

                <span class="zone-badge">
                    <span class="pulse-dot"></span>
                    <span><?= htmlspecialchars($zone_name) ?></span>
                </span>

                <!-- Quick New Delivery/Receipt -->
                <a href="<?= zone_url($zone_slug, 'generate-receipt') ?>" class="btn-header-action btn-new-bill-header" title="Create Direct Bill / Spot Delivery">
                    <i class='bx bx-plus-circle' style="font-size: 16px;"></i>
                    <span style="display: none; @media(min-width: 480px){display: inline;}">+ New Delivery</span>
                </a>

                <?php if (isset($_SESSION['admin_id'])): ?>
                    <a href="<?= BASE_URL ?>/admin/dashboard/index.php" class="btn-header-action btn-admin-back" title="Return to Admin Panel">
                        <i class='bx bx-arrow-back'></i>
                        <span style="display: none; @media(min-width: 540px){display: inline;}">Admin</span>
                    </a>
                <?php endif; ?>
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
                    $waLink = buildWhatsAppLink($nPhone, $nShop, $nOrdNum, $nAmt);
                ?>
                <div class="notif-item" id="notif-card-<?= $nOrdId ?>">
                    <div>
                        <div class="notif-shop">
                            <?= $nShop ?> &bull; <span style="color: #2563eb; font-weight: 700;"><?= $nProd ?> &times; <?= $nQty ?> Cases</span>
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
                        <a href="<?= zone_url($zone_slug, 'generate-receipt', ['order_id' => $nOrdId]) ?>" class="btn-card-action btn-deliver-primary" style="padding: 6px 14px; font-size: 12px; min-height: 36px;">
                            <i class='bx bx-receipt'></i> Deliver &amp; Bill
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <!-- KEY METRICS OVERVIEW (Clickable Quick Filters) -->
        <!-- Added Total Credited next to Cash Collected + Instant Click-to-Filter -->
        <section class="metrics-grid">
            <a href="<?= zone_url($zone_slug, '', ['filter' => 'pending']) ?>" class="metric-card metric-pending <?= ($filter === 'pending') ? 'active-card' : '' ?>" title="Click to show pending deliveries">
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

            <a href="<?= zone_url($zone_slug, '', ['filter' => 'delivered']) ?>" class="metric-card metric-delivered <?= ($filter === 'delivered') ? 'active-card' : '' ?>" title="Click to show delivered orders">
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
            <a href="<?= zone_url($zone_slug, '', ['filter' => 'cash']) ?>" class="metric-card metric-cash <?= ($filter === 'cash') ? 'active-card' : '' ?>" title="Click to show orders with cash collected">
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

            <!-- Total Credited Amount (Next to In-Hand Cash) -->
            <a href="<?= zone_url($zone_slug, '', ['filter' => 'credit']) ?>" class="metric-card metric-credit <?= ($filter === 'credit') ? 'active-card' : '' ?>" title="Click to show orders with credit">
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

            <!-- Outstanding Due (Clickable: shows orders with due) -->
            <a href="<?= zone_url($zone_slug, '', ['filter' => 'due']) ?>" class="metric-card metric-due <?= ($filter === 'due') ? 'active-card' : '' ?>" title="Click to show orders with pending due balance">
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
                        <span>Pending due balance</span>
                        <i class='bx bx-chevron-right'></i>
                    </div>
                </div>
            </a>
        </section>

        <!-- CONTROLS: FILTER CHIPS & LIVE SEARCH -->
        <section class="controls-panel">
            <div class="filter-scroll-container">
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'all']) ?>" class="filter-pill <?= ($filter === 'all') ? 'active' : '' ?>">
                    <span>All Orders</span>
                    <span class="filter-counter"><?= (int)$status_counts['total'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'due']) ?>" class="filter-pill <?= ($filter === 'due') ? 'active' : '' ?>" style="color: #dc2626; border-color: #fecaca; background: <?= ($filter === 'due') ? '#dc2626' : '#fef2f2' ?>; color: <?= ($filter === 'due') ? '#fff' : '#dc2626' ?>;">
                    <i class='bx bx-error-circle'></i>
                    <span>Has Due</span>
                    <span class="filter-counter"><?= (int)$status_counts['due_count'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'pending']) ?>" class="filter-pill <?= ($filter === 'pending') ? 'active' : '' ?>">
                    <span>Pending</span>
                    <span class="filter-counter"><?= (int)$status_counts['pending'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'delivered']) ?>" class="filter-pill <?= ($filter === 'delivered') ? 'active' : '' ?>">
                    <span>Delivered</span>
                    <span class="filter-counter"><?= (int)$status_counts['delivered'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'cash']) ?>" class="filter-pill <?= ($filter === 'cash') ? 'active' : '' ?>">
                    <span>Cash Paid</span>
                    <span class="filter-counter"><?= (int)$status_counts['cash_count'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'credit']) ?>" class="filter-pill <?= ($filter === 'credit') ? 'active' : '' ?>">
                    <span>Credited</span>
                    <span class="filter-counter"><?= (int)$status_counts['credit_count'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'processing']) ?>" class="filter-pill <?= ($filter === 'processing') ? 'active' : '' ?>">
                    <span>Processing</span>
                    <span class="filter-counter"><?= (int)$status_counts['processing'] ?></span>
                </a>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'shipped']) ?>" class="filter-pill <?= ($filter === 'shipped') ? 'active' : '' ?>">
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
                    Filtered by: <strong><?= ucfirst($filter === 'due' ? 'Outstanding Due' : ($filter === 'credit' ? 'Credited Orders' : ($filter === 'cash' ? 'Cash Collected' : $filter))) ?></strong> 
                    (<?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?> found)
                </span>
                <a href="<?= zone_url($zone_slug, '', ['filter' => 'all']) ?>" style="color: #2563eb; text-decoration: none; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
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
                        There are currently no orders in <?= htmlspecialchars($zone_name) ?> matching the filter "<strong><?= htmlspecialchars($filter) ?></strong>".
                    </p>
                    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                        <a href="<?= zone_url($zone_slug) ?>" class="btn-card-action btn-card-secondary" style="font-size: 13px;">
                            Clear Filters
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
                    $qty = (int)$order['quantity'];
                    $amount = formatCurrency($order['total_amount']);
                    $status = strtolower($order['status']);
                    $phone = $order['phone'] ?? '';
                    $hasReceipt = !empty($order['bill_number']);
                    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($loc . ' ' . $zone_name);
                    $waLink = buildWhatsAppLink($phone, $shop, $ordNum, $amount);
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
                                <?php if ((int)$order['is_zone_read'] === 0): ?>
                                    <span class="badge-unread-chip" id="unread-tag-<?= $ordId ?>" title="Not yet checked by zone staff">
                                        <i class='bx bxs-bell-ring'></i> New
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
                                <span><?= $prod ?></span>
                                <span class="qty-pill"><?= $qty ?> Cases</span>
                            </div>

                            <div class="contact-actions-row">
                                <a href="<?= $mapsUrl ?>" target="_blank" class="action-chip-link chip-map" title="Open Map Navigation">
                                    <i class='bx bx-map-pin'></i> <?= $loc ?>
                                </a>

                                <?php if (!empty($phone)): ?>
                                    <a href="tel:<?= htmlspecialchars($phone) ?>" class="action-chip-link chip-call" title="Call Customer">
                                        <i class='bx bx-phone'></i> Call
                                    </a>

                                    <?php if (!empty($waLink)): ?>
                                        <a href="<?= $waLink ?>" target="_blank" class="action-chip-link chip-whatsapp" title="Send WhatsApp">
                                            <i class='bx bxl-whatsapp'></i> WhatsApp
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
                                <?php if ($hasReceipt): ?>
                                    <span class="bill-number-badge">
                                        <i class='bx bx-check'></i> Bill: <?= htmlspecialchars($order['bill_number']) ?>
                                    </span>
                                    <?php if ((float)$order['receipt_cash'] > 0): ?>
                                        <span style="font-size: 11px; color: #047857; font-weight: 700;">Cash: <?= formatCurrency($order['receipt_cash']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((float)$order['receipt_credit'] > 0): ?>
                                        <span class="credit-badge">Credit: <?= formatCurrency($order['receipt_credit']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((float)$order['receipt_due'] > 0): ?>
                                        <span class="due-badge">Due: <?= formatCurrency($order['receipt_due']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #d97706; font-size: 11px;">
                                        <i class='bx bx-info-circle'></i> Unbilled
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Touch Actions Bar -->
                    <div class="order-actions-bar">
                        <?php if (!$hasReceipt): ?>
                            <a href="<?= zone_url($zone_slug, 'generate-receipt', ['order_id' => $ordId]) ?>" class="btn-card-action btn-deliver-primary">
                                <i class='bx bx-receipt' style="font-size: 18px;"></i> Deliver &amp; Bill
                            </a>
                        <?php else: ?>
                            <a href="<?= zone_url($zone_slug, 'receipt', ['id' => $ordId]) ?>" target="_blank" class="btn-card-action btn-receipt-view">
                                <i class='bx bx-printer' style="font-size: 17px;"></i> View Receipt
                            </a>
                        <?php endif; ?>

                        <?php if ((int)$order['is_zone_read'] === 0): ?>
                            <button type="button" class="btn-card-action btn-ack-check" onclick="acknowledgeOrder(<?= $ordId ?>, this)" title="Mark as checked by zone staff">
                                <i class='bx bx-check-double'></i> Check
                            </button>
                        <?php endif; ?>

                        <a href="<?= zone_url($zone_slug, 'order', ['id' => $ordId]) ?>" class="btn-card-action btn-card-secondary">
                            <i class='bx bx-show'></i> Details
                        </a>

                        <a href="<?= zone_url($zone_slug, 'edit-order', ['id' => $ordId]) ?>" class="btn-card-action btn-discount-tag" title="Edit Discount">
                            <i class='bx bx-purchase-tag'></i> Discount
                        </a>

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
            const cards = document.querySelectorAll('.order-card');

            if (!searchInput) return;

            function doFilter() {
                const term = searchInput.value.trim().toLowerCase();
                if (term.length > 0) {
                    clearBtn.classList.add('visible');
                } else {
                    clearBtn.classList.remove('visible');
                }

                cards.forEach(card => {
                    const data = card.getAttribute('data-search') || '';
                    if (!term || data.includes(term)) {
                        card.style.display = '';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }

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

            // Real-time polling function
            function pollForNewOrders() {
                fetch(pollUrl)
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success || !Array.isArray(data.orders)) return;

                        let hasNewIncoming = false;

                        data.orders.forEach(ord => {
                            const ordId = parseInt(ord.order_id);
                            if (!knownUnreadIds.has(ordId)) {
                                // NEW ORDER DETECTED!
                                knownUnreadIds.add(ordId);
                                hasNewIncoming = true;

                                const shopName = ord.shop_name || ord.customer_name || 'Customer';
                                const qty = ord.quantity || 1;
                                const amt = '₹' + parseFloat(ord.total_amount).toFixed(2);
                                const ordNum = ord.order_number || ('#' + ordId);
                                const orderUrl = '<?= zone_url($zone_slug, 'order') ?>?id=' + ordId;

                                showToast(
                                    `🔔 New Order from Admin: ${ordNum}`,
                                    `<strong>${shopName}</strong> • ${ord.product_name} &times; ${qty} Cases (${amt})`,
                                    orderUrl
                                );
                            }
                        });

                        if (hasNewIncoming) {
                            playNotificationSound();
                            if (navigator.vibrate) {
                                navigator.vibrate([250, 100, 250]);
                            }

                            // Update window title
                            document.title = `(${data.unread_count}) 🔔 New Order! - Liyas Delivery`;

                            // Refresh page after a brief delay if banner was empty so full UI updates
                            const existingBanner = document.querySelector('.notif-banner');
                            if (!existingBanner && data.unread_count > 0) {
                                setTimeout(() => window.location.reload(), 2000);
                            } else {
                                const pill = document.querySelector('.notif-count-pill');
                                if (pill) {
                                    pill.textContent = data.unread_count + ' ACTION REQUIRED';
                                }
                            }
                        }
                    })
                    .catch(err => console.debug('Order poll error:', err));
            }

            // Start polling every 6 seconds
            setInterval(pollForNewOrders, 6000);

            // If there were already unread orders waiting when this page opened, play sound on load
            <?php if (!empty($unread_notifications)): ?>
                setTimeout(() => {
                    playNotificationSound();
                }, 600);
            <?php endif; ?>

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
        })();
    </script>
</body>
</html>
