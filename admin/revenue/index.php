<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "revenue";
$page_title   = "Revenue & Multi-Zone Sales";
$admin_name   = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');

// Handle quick order status update from this table
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    $allowed    = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    if ($order_id > 0 && in_array($new_status, $allowed)) {
        try {
            $curr = $pdo->prepare("SELECT status FROM orders WHERE order_id = ?");
            $curr->execute([$order_id]);
            $old_status = $curr->fetchColumn() ?: '';

            $upStmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
            $upStmt->execute([$new_status, $order_id]);

            handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);

            if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
                $oData = $pdo->prepare("SELECT shop_name, customer_name, phone FROM orders WHERE order_id = ?");
                $oData->execute([$order_id]);
                $ord = $oData->fetch(PDO::FETCH_ASSOC);
                if ($ord) {
                    $sName = !empty($ord['shop_name']) ? $ord['shop_name'] : (!empty($ord['customer_name']) ? $ord['customer_name'] : '');
                    if ($sName !== '') {
                        updateShopRewardProgress($pdo, $sName, $ord['phone'] ?? '');
                    }
                }
            }

            quickLog($pdo, 'update_status', 'order', $order_id, "Updated order #{$order_id} status to {$new_status} via Revenue module");
            $success_msg = "Order #{$order_id} status updated to " . ucfirst($new_status);
        } catch (PDOException $e) {
            $error_msg = "Error updating order: " . $e->getMessage();
        }
    }
}

// Handle clear / settle outstanding due balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_due_balance'])) {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $settle_mode = $_POST['settle_mode'] ?? 'cash';
    if ($order_id > 0) {
        try {
            $rStmt = $pdo->prepare("SELECT id, due_amount, cash_amount, credited_amount FROM receipts WHERE order_id = ?");
            $rStmt->execute([$order_id]);
            $rec = $rStmt->fetch(PDO::FETCH_ASSOC);

            if ($rec && (float)$rec['due_amount'] > 0) {
                $dueCleared = (float)$rec['due_amount'];
                if ($settle_mode === 'credit') {
                    $upRec = $pdo->prepare("
                        UPDATE receipts 
                        SET credited_amount = credited_amount + due_amount,
                            due_amount = 0,
                            payment_type = CASE WHEN cash_amount > 0 THEN 'Cash + Credited' ELSE 'Credited' END,
                            notes = CONCAT(COALESCE(notes, ''), ' | Due of ₹{$dueCleared} settled via Credit on ', NOW())
                        WHERE id = ?
                    ");
                } else {
                    $upRec = $pdo->prepare("
                        UPDATE receipts 
                        SET cash_amount = cash_amount + due_amount,
                            due_amount = 0,
                            payment_type = CASE WHEN credited_amount > 0 THEN 'Cash + Credited' ELSE 'Cash' END,
                            notes = CONCAT(COALESCE(notes, ''), ' | Due of ₹{$dueCleared} settled via Cash on ', NOW())
                        WHERE id = ?
                    ");
                }
                $upRec->execute([$rec['id']]);

                quickLog($pdo, 'settle_due', 'receipt', $rec['id'], "Cleared due balance of ₹{$dueCleared} for order #{$order_id} via Revenue module");
                $success_msg = "Outstanding due of ₹" . number_format($dueCleared, 2) . " for order #{$order_id} was successfully cleared and added to Delivered Sales!";
            } else {
                $error_msg = "No outstanding due balance found for this order.";
            }
        } catch (PDOException $e) {
            $error_msg = "Error clearing due: " . $e->getMessage();
        }
    }
}

// Filters from GET request
$zone_filter    = $_GET['zone'] ?? 'all';
$payment_filter = $_GET['payment_status'] ?? 'all';
$status_filter  = $_GET['status'] ?? 'all';
$period_filter  = $_GET['period'] ?? 'all';
$search         = trim($_GET['search'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 25;
$offset         = ($page - 1) * $per_page;

// Fetch all active zones for dropdown
$all_zones = $pdo->query("SELECT id, name, slug, status FROM zones ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Date condition
$date_clauses = [];
if ($period_filter === 'today') {
    $date_clauses[] = "DATE(o.created_at) = CURDATE()";
} elseif ($period_filter === 'week') {
    $date_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($period_filter === 'month') {
    $date_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

// Build query conditions for orders table
$where_clauses = ["1=1"];
$params = [];

if (!empty($date_clauses)) {
    $where_clauses[] = implode(" AND ", $date_clauses);
}

// Zone filter
if ($zone_filter !== 'all' && !empty($zone_filter)) {
    if (is_numeric($zone_filter)) {
        $where_clauses[] = "o.zone_id = :zone_id";
        $params[':zone_id'] = (int)$zone_filter;
    } else {
        $where_clauses[] = "z.slug = :zone_slug";
        $params[':zone_slug'] = $zone_filter;
    }
}

// Delivery Status filter
if ($status_filter !== 'all' && in_array($status_filter, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
    $where_clauses[] = "o.status = :o_status";
    $params[':o_status'] = $status_filter;
}

// Payment Status filter
if ($payment_filter === 'paid') {
    $where_clauses[] = "r.id IS NOT NULL AND (r.due_amount <= 0 OR r.due_amount IS NULL)";
} elseif ($payment_filter === 'due') {
    $where_clauses[] = "r.due_amount > 0";
} elseif ($payment_filter === 'cash') {
    $where_clauses[] = "r.cash_amount > 0";
    if ($status_filter === 'all') {
        $where_clauses[] = "o.status = 'delivered'";
    }
} elseif ($payment_filter === 'credit') {
    $where_clauses[] = "r.credited_amount > 0";
} elseif ($payment_filter === 'unbilled') {
    $where_clauses[] = "r.id IS NULL";
}

// Search
if (!empty($search)) {
    $where_clauses[] = "(
        o.shop_name LIKE :search 
        OR o.customer_name LIKE :search 
        OR o.phone LIKE :search 
        OR o.location LIKE :search 
        OR o.order_number LIKE :search 
        OR r.bill_number LIKE :search
    )";
    $params[':search'] = "%$search%";
}

$where_sql = implode(" AND ", $where_clauses);

// ============================================
// EXPORT DATASET (CSV & EXCEL FORMATS)
// Exactly 10 clean columns:
// 1) Order Number, 2) Bill No, 3) Date, 4) Zone, 5) Customer/Shopname,
// 6) Product/Qty, 7) Total Amount, 8) Cash Paid, 9) Credited, 10) Due Balance
// ============================================
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel'])) {
    $exp_format = $_GET['export'];
    $export_sql = "
        SELECT 
            o.*,
            z.name as zone_name,
            z.slug as zone_slug,
            p.product_name,
            p.name as fallback_product_name,
            r.id as receipt_id,
            r.bill_number,
            r.delivered_quantity,
            r.cash_amount as receipt_cash,
            r.credited_amount as receipt_credit,
            r.due_amount as receipt_due,
            r.payment_type as receipt_pay_type,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
            (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs)') SEPARATOR ', ') 
             FROM order_items oi2 
             JOIN products p2 ON oi2.product_id = p2.product_id 
             WHERE oi2.order_id = o.order_id) AS items_summary
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN products p ON o.product_id = p.product_id
        LEFT JOIN receipts r ON o.order_id = r.order_id
        WHERE $where_sql
        ORDER BY o.created_at DESC
    ";
    $exp_stmt = $pdo->prepare($export_sql);
    foreach ($params as $k => $v) {
        if ($k === ':zone_id') {
            $exp_stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $exp_stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
    }
    $exp_stmt->execute();
    $export_rows = $exp_stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename_base = "Liyas_Revenue_Report_" . date('Y-m-d_His');

    if ($exp_format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // Output UTF-8 Byte Order Mark (BOM) so Excel opens UTF-8 properly
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        $headers = [
            'Order Number',
            'Bill No',
            'Date',
            'Zone',
            'Customer/Shopname',
            'Product/Qty',
            'Total Amount',
            'Cash Paid',
            'Credited',
            'Due Balance'
        ];
        fputcsv($out, $headers);

        foreach ($export_rows as $row) {
            $ordNum = $row['order_number'] ?: ('#' . $row['order_id']);
            $billNum = $row['bill_number'] ?: 'Unbilled';
            $date = date('d M Y, h:i A', strtotime($row['created_at']));
            $zone = $row['zone_name'] ?: 'Zone';
            $cust = $row['shop_name'] ?: ($row['customer_name'] ?: 'Customer');

            if ((int)($row['items_count'] ?? 0) > 1 && !empty($row['items_summary'])) {
                $prodQty = $row['items_summary'];
            } else {
                $pName = $row['product_name'] ?: ($row['fallback_product_name'] ?: 'Water');
                $prodQty = $pName . ' (' . (int)$row['quantity'] . ' cs)';
            }

            $status = strtolower($row['status'] ?? '');
            $totAmt = ($status === 'cancelled') ? 0.00 : (float)$row['total_amount'];
            $cash = ($status === 'cancelled') ? 0.00 : (float)($row['receipt_cash'] ?? 0);
            $credit = ($status === 'cancelled') ? 0.00 : (float)($row['receipt_credit'] ?? 0);
            $due = ($status === 'cancelled') ? 0.00 : (float)($row['receipt_due'] ?? 0);

            fputcsv($out, [
                $ordNum,
                $billNum,
                $date,
                $zone,
                $cust,
                $prodQty,
                number_format($totAmt, 2, '.', ''),
                number_format($cash, 2, '.', ''),
                number_format($credit, 2, '.', ''),
                number_format($due, 2, '.', '')
            ]);
        }
        fclose($out);
        exit;
    } elseif ($exp_format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename_base . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
        echo '<style>
            table { border-collapse: collapse; width: 100%; font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
            th { background-color: #f1f5f9; color: #1e293b; font-weight: bold; border: 1px solid #cbd5e1; padding: 8px 12px; }
            td { border: 1px solid #e2e8f0; padding: 6px 10px; }
            .col-due-hdr { background-color: #fee2e2 !important; color: #991b1b !important; font-weight: bold; }
            .col-due-cell { background-color: #fef2f2 !important; color: #b91c1c !important; font-weight: bold; }
            .num { text-align: right; }
        </style></head><body>';
        echo '<table border="1">';
        echo '<thead><tr>';
        echo '<th>Order Number</th>';
        echo '<th>Bill No</th>';
        echo '<th>Date</th>';
        echo '<th>Zone</th>';
        echo '<th>Customer/Shopname</th>';
        echo '<th>Product/Qty</th>';
        echo '<th class="num">Total Amount</th>';
        echo '<th class="num">Cash Paid</th>';
        echo '<th class="num">Credited</th>';
        echo '<th class="num col-due-hdr" style="background-color: #fee2e2; color: #991b1b;">Due Balance</th>';
        echo '</tr></thead><tbody>';

        foreach ($export_rows as $row) {
            $ordNum = htmlspecialchars($row['order_number'] ?: ('#' . $row['order_id']));
            $billNum = htmlspecialchars($row['bill_number'] ?: 'Unbilled');
            $date = date('d M Y, h:i A', strtotime($row['created_at']));
            $zone = htmlspecialchars($row['zone_name'] ?: 'Zone');
            $cust = htmlspecialchars($row['shop_name'] ?: ($row['customer_name'] ?: 'Customer'));

            if ((int)($row['items_count'] ?? 0) > 1 && !empty($row['items_summary'])) {
                $prodQty = htmlspecialchars($row['items_summary']);
            } else {
                $pName = $row['product_name'] ?: ($row['fallback_product_name'] ?: 'Water');
                $prodQty = htmlspecialchars($pName . ' (' . (int)$row['quantity'] . ' cs)');
            }

            $status = strtolower($row['status'] ?? '');
            $totAmt = ($status === 'cancelled') ? 0.00 : (float)$row['total_amount'];
            $cash = ($status === 'cancelled') ? 0.00 : (float)($row['receipt_cash'] ?? 0);
            $credit = ($status === 'cancelled') ? 0.00 : (float)($row['receipt_credit'] ?? 0);
            $due = ($status === 'cancelled') ? 0.00 : (float)($row['receipt_due'] ?? 0);

            echo '<tr>';
            echo '<td>' . $ordNum . '</td>';
            echo '<td>' . $billNum . '</td>';
            echo '<td>' . $date . '</td>';
            echo '<td>' . $zone . '</td>';
            echo '<td>' . $cust . '</td>';
            echo '<td>' . $prodQty . '</td>';
            echo '<td class="num">' . number_format($totAmt, 2) . '</td>';
            echo '<td class="num">' . number_format($cash, 2) . '</td>';
            echo '<td class="num">' . number_format($credit, 2) . '</td>';
            echo '<td class="num col-due-cell" style="background-color: #fef2f2; color: #b91c1c;">' . number_format($due, 2) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }
}

// 1. Overall Revenue KPIs
$kpi_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT o.order_id) as total_orders_count,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders_count,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN COALESCE(r.total_amount, o.total_amount, 0) ELSE 0 END), 0) as total_delivered_sales,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN r.cash_amount ELSE 0 END), 0) as total_cash_collected,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN r.credited_amount ELSE 0 END), 0) as total_credited_amount,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' THEN r.due_amount ELSE 0 END), 0) as total_outstanding_due
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE $where_sql
");
$kpi_stmt->execute($params);
$kpis = $kpi_stmt->fetch(PDO::FETCH_ASSOC);

// 2. Zone-by-Zone Financial Breakdown (respects period filter if specified)
$zone_date_clause = !empty($date_clauses) ? (" AND (" . implode(" AND ", $date_clauses) . ")") : "";
$zone_breakdown_sql = "
    SELECT 
        z.id,
        z.name as zone_name,
        z.slug as zone_slug,
        z.status as zone_status,
        COUNT(o.order_id) as total_orders,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN COALESCE(r.total_amount, o.total_amount, 0) ELSE 0 END), 0) as total_sales,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN r.cash_amount ELSE 0 END), 0) as total_cash,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN r.credited_amount ELSE 0 END), 0) as total_credit,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' THEN r.due_amount ELSE 0 END), 0) as total_due
    FROM zones z
    LEFT JOIN orders o ON z.id = o.zone_id {$zone_date_clause}
    LEFT JOIN receipts r ON o.order_id = r.order_id
    GROUP BY z.id
    ORDER BY total_sales DESC, z.name ASC
";
$zone_breakdown_stmt = $pdo->query($zone_breakdown_sql);
$zone_breakdowns = $zone_breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);

// All-Zones Master Aggregation
$all_zones_summary = [
    'total_orders'     => 0,
    'delivered_orders' => 0,
    'total_sales'      => 0.0,
    'total_cash'       => 0.0,
    'total_credit'     => 0.0,
    'total_due'        => 0.0,
];
$selected_zone_obj = null;
foreach ($zone_breakdowns as $zb) {
    $all_zones_summary['total_orders']     += (int)$zb['total_orders'];
    $all_zones_summary['delivered_orders'] += (int)$zb['delivered_orders'];
    $all_zones_summary['total_sales']      += (float)$zb['total_sales'];
    $all_zones_summary['total_cash']       += (float)$zb['total_cash'];
    $all_zones_summary['total_credit']     += (float)$zb['total_credit'];
    $all_zones_summary['total_due']        += (float)$zb['total_due'];

    if ($zone_filter !== 'all' && ($zone_filter == $zb['id'] || $zone_filter === $zb['zone_slug'])) {
        $selected_zone_obj = $zb;
    }
}

// 3. Count matching orders for pagination
$count_stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT o.order_id)
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE $where_sql
");
$count_stmt->execute($params);
$total_matching_orders = (int)$count_stmt->fetchColumn();
$total_pages = ceil($total_matching_orders / $per_page);

// 4. Fetch orders listing with complete financial details
$orders_sql = "
    SELECT 
        o.*,
        z.name as zone_name,
        z.slug as zone_slug,
        p.product_name,
        p.name as fallback_product_name,
        r.id as receipt_id,
        r.bill_number,
        r.delivered_quantity,
        r.cash_amount as receipt_cash,
        r.credited_amount as receipt_credit,
        r.due_amount as receipt_due,
        r.payment_type as receipt_pay_type,
        r.created_at as receipt_date,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs)') SEPARATOR ', ') 
         FROM order_items oi2 
         JOIN products p2 ON oi2.product_id = p2.product_id 
         WHERE oi2.order_id = o.order_id) AS items_summary
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    WHERE $where_sql
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset
";
$orders_stmt = $pdo->prepare($orders_sql);
foreach ($params as $k => $v) {
    if ($k === ':zone_id') {
        $orders_stmt->bindValue($k, $v, PDO::PARAM_INT);
    } else {
        $orders_stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
}
$orders_stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$orders_stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$orders_stmt->execute();
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for WhatsApp URL
function makeWhatsAppLink($phone, $shop, $orderNum, $amount) {
    $clean = preg_replace('/[^0-9]/', '', $phone ?? '');
    if (empty($clean)) return '';
    if (strlen($clean) === 10) $clean = '91' . $clean;
    $msg = "Hello {$shop}, regarding Liyas Water Order #{$orderNum} (Total: {$amount}).";
    return "https://wa.me/{$clean}?text=" . rawurlencode($msg);
}

// URL builder helper for 1-tap card filters & micro-drill downs
function buildFilterUrl($overrides = [], $anchor = 'orders-register') {
    $params = $_GET;
    unset($params['page']); // Reset pagination when applying a new filter
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $query = http_build_query($params);
    $url = 'index.php' . ($query ? '?' . $query : '');
    if ($anchor) {
        $url .= '#' . $anchor;
    }
    return $url;
}

$cleanBaseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue &amp; Multi-Zone Sales - Liyas Website</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }

        html { scroll-behavior: smooth; }

        /* KPI Summary Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .kpi-card {
            background: #ffffff;
            border: 1.5px solid var(--border-light);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
            border-color: #93c5fd;
        }

        .kpi-card.is-kpi-active {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 8px 22px rgba(37, 99, 235, 0.15), 0 0 0 1px #2563eb;
        }

        .kpi-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .kpi-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
        }

        .kpi-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: transform 0.2s;
        }

        .kpi-card:hover .kpi-icon-box {
            transform: scale(1.08);
        }

        .kpi-val {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }

        .kpi-subtext {
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .kpi-filter-hint {
            font-size: 11px;
            font-weight: 600;
            color: #94a3b8;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #e2e8f0;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.15s;
        }

        .kpi-card:hover .kpi-filter-hint {
            color: #2563eb;
        }

        .kpi-card.is-kpi-active .kpi-filter-hint {
            color: #1d4ed8;
            font-weight: 700;
        }

        /* KPI Colors */
        .kpi-sales .kpi-icon-box { background: #eff6ff; color: #2563eb; }
        .kpi-sales .kpi-val { color: #1e40af; }
        
        .kpi-cash .kpi-icon-box { background: #ecfdf5; color: #059669; }
        .kpi-cash .kpi-val { color: #047857; }

        .kpi-credit .kpi-icon-box { background: #f5f3ff; color: #7c3aed; }
        .kpi-credit .kpi-val { color: #6d28d9; }

        .kpi-due .kpi-icon-box { background: #fef2f2; color: #dc2626; }
        .kpi-due .kpi-val { color: #b91c1c; }

        .kpi-orders .kpi-icon-box { background: #fffbeb; color: #d97706; }
        .kpi-orders .kpi-val { color: #b45309; }

        /* Performance by Delivery Zone Section */
        .zone-section {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .zone-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 1.25rem;
            padding-bottom: 0.85rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .zone-header-title-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .zone-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .zone-header-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .zone-header-sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }

        .zone-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .zone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }

        .zone-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 12px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            user-select: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .zone-card:hover {
            background: #ffffff;
            border-color: #93c5fd;
            transform: translateY(-3px);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
        }

        .zone-card.is-active {
            background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%);
            border-color: #2563eb;
            box-shadow: 0 10px 25px -4px rgba(37, 99, 235, 0.16), 0 0 0 1px #2563eb;
        }

        .zone-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
        }

        .zone-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .zone-avatar {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #e2e8f0;
            color: #334155;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .zone-card:hover .zone-avatar,
        .zone-card.is-active .zone-avatar {
            background: #2563eb;
            color: #ffffff;
        }

        .zone-avatar.master-avatar {
            background: #2563eb;
            color: #ffffff;
        }

        .zone-meta-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }

        .zone-meta-sub {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            margin-top: 3px;
        }

        .zone-top-badges {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .badge-active-filter {
            background: #2563eb;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
        }

        .btn-zone-portal {
            font-size: 11px;
            font-weight: 700;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 3px 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: all 0.15s;
        }

        .btn-zone-portal:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }

        /* Sales Box inside Card */
        .zone-sales-box {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 10px;
            padding: 10px 14px;
        }

        .zone-card.is-active .zone-sales-box {
            border-color: #bfdbfe;
            background: rgba(255, 255, 255, 0.95);
        }

        .zone-sales-caption {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
        }

        .zone-sales-value {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            margin-top: 2px;
        }

        /* Orders Fulfillment Progress */
        .zone-orders-progress {
            margin-top: 4px;
        }

        .zone-progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #475569;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .zone-bar-track {
            height: 6px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .zone-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #2563eb, #10b981);
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        /* Submetrics micro-grid */
        .zone-submetrics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .zone-micro-pill {
            padding: 7px 10px;
            border-radius: 8px;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .zone-micro-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 6px rgba(0,0,0,0.05);
        }

        .pill-cash {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .pill-cash:hover {
            background: #d1fae5;
            border-color: #6ee7b7;
        }

        .pill-credit {
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            color: #5b21b6;
        }
        .pill-credit:hover {
            background: #ede9fe;
            border-color: #c4b5fd;
        }

        /* Due Status Banner */
        .zone-due-banner {
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.15s;
        }

        .zone-due-danger {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            color: #991b1b;
        }

        .zone-due-danger:hover {
            background: #fee2e2;
            border-color: #f87171;
            transform: translateY(-1px);
        }

        .zone-due-clean {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            cursor: default;
        }

        /* Card Action Bottom */
        .zone-card-bottom {
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #2563eb;
        }

        .zone-card.is-active .zone-card-bottom {
            color: #1e40af;
        }

        /* Active Filter Chips Bar (Above Table) */
        .active-filters-bar {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .active-filters-label {
            font-size: 12px;
            font-weight: 700;
            color: #1e40af;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .filter-chip {
            background: #ffffff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.15s;
        }

        .filter-chip:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c;
        }

        .filter-chip .chip-close {
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
        }

        .btn-clear-all-filters {
            font-size: 11px;
            font-weight: 700;
            color: #dc2626;
            background: transparent;
            text-decoration: underline;
            margin-left: auto;
            cursor: pointer;
        }

        #orders-register {
            scroll-margin-top: 24px;
        }

        /* Filter Panel */
        .filter-panel {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .filter-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: flex-end;
        }

        .form-group-filter {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-label-filter {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .select-filter, .input-filter {
            width: 100%;
            padding: 8px 12px;
            border: 1.5px solid var(--border-medium);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
        }

        .select-filter:focus, .input-filter:focus {
            outline: none;
            border-color: #2563eb;
        }

        .btn-filter-submit {
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 13px;
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 38px;
        }

        .btn-filter-reset {
            background: #f1f5f9;
            color: #475569;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid var(--border-medium);
            padding: 9px 14px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            min-height: 38px;
        }

        /* Orders Table Card */
        .table-card-custom {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.03);
            margin-bottom: 2rem;
        }

        .table-header-custom {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .rev-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .rev-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 12px 14px;
            border-bottom: 2px solid var(--border-light);
            white-space: nowrap;
        }

        .rev-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #1e293b;
        }

        .rev-table tr:hover { background: #f8fafc; }

        /* Due Balance Column Light Red Highlighting */
        .rev-table th.col-due {
            background: #fee2e2 !important;
            color: #991b1b !important;
            border-left: 1.5px solid #fecaca;
            border-right: 1.5px solid #fecaca;
            font-weight: 800;
        }

        .rev-table td.col-due {
            background: #fff5f5 !important;
            border-left: 1.5px solid #fecaca !important;
            border-right: 1.5px solid #fecaca !important;
        }

        .rev-table tr:hover td.col-due {
            background: #fee2e2 !important;
        }

        .badge-zone-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 700;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 6px;
            text-decoration: none;
        }

        .badge-due-danger {
            background: #fee2e2;
            color: #dc2626;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 12px;
            display: inline-block;
        }

        .badge-settled {
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            display: inline-block;
        }

        .badge-unbilled {
            background: #f1f5f9;
            color: #64748b;
            font-weight: 600;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
        }

        .tbl-status-select {
            padding: 4px 6px;
            border-radius: 6px;
            border: 1px solid var(--border-medium);
            font-size: 11px;
            font-weight: 700;
            background: #ffffff;
            cursor: pointer;
        }

        .btn-tbl {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: 0.15s;
        }

        .btn-tbl-view { background: #f1f5f9; color: #334155; border-color: var(--border-medium); }
        .btn-tbl-view:hover { background: #e2e8f0; }

        .btn-tbl-bill { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .btn-tbl-bill:hover { background: #dbeafe; }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            background: #ffffff;
            border-top: 1px solid var(--border-light);
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-numbers { display: flex; gap: 6px; }

        .btn-page {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-medium);
            background: #ffffff;
            color: #334155;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-page.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <i class='bx bx-line-chart'></i>
                    <span>Revenue &amp; Multi-Zone Financials</span>
                </div>
                <div class="header-actions">
                    <button onclick="window.print()" class="btn-filter-reset" title="Print report">
                        <i class='bx bx-printer'></i> Print
                    </button>
                    <!-- <a href="<?= htmlspecialchars(buildFilterUrl(['export' => 'csv'], '')) ?>" id="exportCsvBtn" class="btn-filter-submit" style="background: #059669; text-decoration: none;" title="Export 10 clean columns as CSV">
                        <i class='bx bx-download'></i> Export CSV
                    </a> -->
                    <a href="<?= htmlspecialchars(buildFilterUrl(['export' => 'excel'], '')) ?>" id="exportExcelBtn" class="btn-filter-submit" style="background: #0284c7; text-decoration: none;" title="Export Excel (with light red Due Balance column)">
                        <i class='bx bxs-file-export'></i> Export Excel
                    </a>
                </div>
            </div>
            
            <div class="content-area">

                <?php if (!empty($success_msg)): ?>
                    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        <i class='bx bx-check-circle' style="font-size: 20px;"></i>
                        <span><?= htmlspecialchars($success_msg) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_msg)): ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                        <span><?= htmlspecialchars($error_msg) ?></span>
                    </div>
                <?php endif; ?>

                <!-- 1. TOP FINANCIAL KPI SUMMARY -->
                <div class="kpi-grid">
                    <?php $isSalesActive = ($status_filter === 'delivered'); ?>
                    <div class="kpi-card kpi-sales <?= $isSalesActive ? 'is-kpi-active' : '' ?>"
                         tabindex="0"
                         role="button"
                         onclick="window.location.href='<?= htmlspecialchars(buildFilterUrl(['status' => 'delivered'])) ?>'"
                         onkeydown="if(event.key==='Enter'||event.key===' '){ window.location.href='<?= htmlspecialchars(buildFilterUrl(['status' => 'delivered'])) ?>'; }"
                         title="Click to view all delivered orders">
                        <div class="kpi-card-header">
                            <span class="kpi-label">Delivered Sales</span>
                            <div class="kpi-icon-box">
                                <i class='bx bx-dollar-circle'></i>
                            </div>
                        </div>
                        <div>
                            <div class="kpi-val"><?= formatCurrency($kpis['total_delivered_sales']) ?></div>
                            <div class="kpi-subtext" style="color: #2563eb;">
                                <span><?= (int)$kpis['delivered_orders_count'] ?> orders delivered (0 cancelled)</span>
                            </div>
                            <div class="kpi-filter-hint">
                                <i class='bx bx-filter-alt'></i> <?= $isSalesActive ? 'Active Filter: Delivered' : 'Click to filter delivered' ?>
                            </div>
                        </div>
                    </div>

                    <?php $isCashActive = ($payment_filter === 'cash'); ?>
                    <div class="kpi-card kpi-cash <?= $isCashActive ? 'is-kpi-active' : '' ?>"
                         tabindex="0"
                         role="button"
                         onclick="window.location.href='<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'cash', 'status' => 'delivered'])) ?>'"
                         onkeydown="if(event.key==='Enter'||event.key===' '){ window.location.href='<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'cash', 'status' => 'delivered'])) ?>'; }"
                         title="Click to view all delivered cash collected orders">
                        <div class="kpi-card-header">
                            <span class="kpi-label">In-Hand Cash</span>
                            <div class="kpi-icon-box">
                                <i class='bx bx-money'></i>
                            </div>
                        </div>
                        <div>
                            <div class="kpi-val"><?= formatCurrency($kpis['total_cash_collected']) ?></div>
                            <div class="kpi-subtext" style="color: #059669;">
                                <span>Delivered cash only (0 cancelled)</span>
                            </div>
                            <div class="kpi-filter-hint">
                                <i class='bx bx-filter-alt'></i> <?= $isCashActive ? 'Active Filter: Delivered Cash' : 'Click to filter delivered cash' ?>
                            </div>
                        </div>
                    </div>

                    <?php $isCreditActive = ($payment_filter === 'credit'); ?>
                    <div class="kpi-card kpi-credit <?= $isCreditActive ? 'is-kpi-active' : '' ?>"
                         tabindex="0"
                         role="button"
                         onclick="window.location.href='<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'credit'])) ?>'"
                         onkeydown="if(event.key==='Enter'||event.key===' '){ window.location.href='<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'credit'])) ?>'; }"
                         title="Click to view all credited orders">
                        <div class="kpi-card-header">
                            <span class="kpi-label">Credited Sales</span>
                            <div class="kpi-icon-box">
                                <i class='bx bx-credit-card'></i>
                            </div>
                        </div>
                        <div>
                            <div class="kpi-val"><?= formatCurrency($kpis['total_credited_amount']) ?></div>
                            <div class="kpi-subtext" style="color: #7c3aed;">
                                <!-- <span>Formal credit ledger</span> -->
                            </div>
                            <div class="kpi-filter-hint">
                                <i class='bx bx-filter-alt'></i> <?= $isCreditActive ? 'Active Filter: Credited' : 'Click to filter credited' ?>
                            </div>
                        </div>
                    </div>

                    <?php $isDueActive = ($payment_filter === 'due'); ?>
                    <div class="kpi-card kpi-due <?= $isDueActive ? 'is-kpi-active' : '' ?>"
                         tabindex="0"
                         role="button"
                         onclick="window.location.href='<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'due'])) ?>'"
                         onkeydown="if(event.key==='Enter'||event.key===' '){ window.location.href='<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'due'])) ?>'; }"
                         title="Click to view all orders with outstanding due">
                        <div class="kpi-card-header">
                            <span class="kpi-label">Outstanding Due</span>
                            <div class="kpi-icon-box">
                                <i class='bx bx-error-circle'></i>
                            </div>
                        </div>
                        <div>
                            <div class="kpi-val" style="color: <?= ((float)$kpis['total_outstanding_due'] > 0) ? '#dc2626' : '#64748b' ?>;">
                                <?= formatCurrency($kpis['total_outstanding_due']) ?>
                            </div>
                            <div class="kpi-subtext" style="color: #ef4444;">
                                <span>Pending receivables</span>
                            </div>
                            <div class="kpi-filter-hint">
                                <i class='bx bx-filter-alt'></i> <?= $isDueActive ? 'Active Filter: Due Orders' : 'Click to filter due orders' ?>
                            </div>
                        </div>
                    </div>

                    <?php $isAllMatchActive = ($status_filter === 'all' && $payment_filter === 'all' && $zone_filter === 'all'); ?>
                    <div class="kpi-card kpi-orders <?= $isAllMatchActive ? 'is-kpi-active' : '' ?>"
                         tabindex="0"
                         role="button"
                         onclick="window.location.href='<?= htmlspecialchars(buildFilterUrl(['status' => 'all', 'payment_status' => 'all', 'zone' => 'all'])) ?>'"
                         onkeydown="if(event.key==='Enter'||event.key===' '){ window.location.href='<?= htmlspecialchars(buildFilterUrl(['status' => 'all', 'payment_status' => 'all', 'zone' => 'all'])) ?>'; }"
                         title="Click to reset filters and view all matching orders">
                        <div class="kpi-card-header">
                            <span class="kpi-label">Matching Orders</span>
                            <div class="kpi-icon-box">
                                <i class='bx bx-cart'></i>
                            </div>
                        </div>
                        <div>
                            <div class="kpi-val"><?= (int)$kpis['total_orders_count'] ?></div>
                            <div class="kpi-subtext" style="color: #d97706;">
                                <span>Filtered total orders</span>
                            </div>
                            <div class="kpi-filter-hint">
                                <i class='bx bx-filter-alt'></i> Click to reset status &amp; pay
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. ZONE-BY-ZONE REVENUE SUMMARY -->
                

                <!-- 3. COMPREHENSIVE FILTER TOOLBAR -->
                <div class="filter-panel">
                    <form action="index.php" method="GET" class="filter-form-grid">
                        <!-- Zone -->
                        <div class="form-group-filter">
                            <label class="form-label-filter" for="f_zone">Delivery Zone</label>
                            <select name="zone" id="f_zone" class="select-filter">
                                <option value="all" <?= ($zone_filter === 'all') ? 'selected' : '' ?>>All Delivery Zones</option>
                                <?php foreach ($all_zones as $z): ?>
                                    <option value="<?= htmlspecialchars($z['slug']) ?>" <?= ($zone_filter === $z['slug'] || (int)$zone_filter === (int)$z['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($z['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Payment Status -->
                        <div class="form-group-filter">
                            <label class="form-label-filter" for="f_pay">Payment Status</label>
                            <select name="payment_status" id="f_pay" class="select-filter">
                                <option value="all" <?= ($payment_filter === 'all') ? 'selected' : '' ?>>All Payment States</option>
                                <option value="paid" <?= ($payment_filter === 'paid') ? 'selected' : '' ?>>✅ Fully Paid (No Due)</option>
                                <option value="due" <?= ($payment_filter === 'due') ? 'selected' : '' ?>>⚠️ Has Outstanding Due</option>
                                <option value="cash" <?= ($payment_filter === 'cash') ? 'selected' : '' ?>>💵 Cash Collected</option>
                                <option value="credit" <?= ($payment_filter === 'credit') ? 'selected' : '' ?>>💳 Credited Amount</option>
                                <option value="unbilled" <?= ($payment_filter === 'unbilled') ? 'selected' : '' ?>>📄 Unbilled / Pending Receipt</option>
                            </select>
                        </div>

                        <!-- Delivery Status -->
                        <div class="form-group-filter">
                            <label class="form-label-filter" for="f_status">Order Status</label>
                            <select name="status" id="f_status" class="select-filter">
                                <option value="all" <?= ($status_filter === 'all') ? 'selected' : '' ?>>All Order Statuses</option>
                                <option value="delivered" <?= ($status_filter === 'delivered') ? 'selected' : '' ?>>Delivered</option>
                                <option value="pending" <?= ($status_filter === 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="processing" <?= ($status_filter === 'processing') ? 'selected' : '' ?>>Processing</option>
                                <option value="shipped" <?= ($status_filter === 'shipped') ? 'selected' : '' ?>>Shipped</option>
                                <option value="cancelled" <?= ($status_filter === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>

                        <!-- Time Period -->
                        <div class="form-group-filter">
                            <label class="form-label-filter" for="f_period">Time Range</label>
                            <select name="period" id="f_period" class="select-filter">
                                <option value="all" <?= ($period_filter === 'all') ? 'selected' : '' ?>>All Time</option>
                                <option value="today" <?= ($period_filter === 'today') ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= ($period_filter === 'week') ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="month" <?= ($period_filter === 'month') ? 'selected' : '' ?>>Last 30 Days</option>
                            </select>
                        </div>

                        <!-- Search input -->
                        <div class="form-group-filter">
                            <label class="form-label-filter" for="f_search">Search</label>
                            <input type="text" name="search" id="f_search" class="input-filter" placeholder="Shop, bill #, phone..." value="<?= htmlspecialchars($search) ?>">
                        </div>

                        <!-- Buttons -->
                        <div style="display: flex; gap: 8px;">
                            <button type="submit" class="btn-filter-submit">
                                <i class='bx bx-filter-alt'></i> Apply
                            </button>
                            <a href="index.php" class="btn-filter-reset" title="Reset all filters">
                                <i class='bx bx-reset'></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- ACTIVE FILTER CHIPS BAR (WHEN FILTERS ARE APPLIED) -->
                <?php
                $active_filters_list = [];
                if ($zone_filter !== 'all' && !empty($zone_filter)) {
                    $zoneDispName = !empty($selected_zone_obj['zone_name']) ? $selected_zone_obj['zone_name'] : ucfirst($zone_filter);
                    $active_filters_list[] = [
                        'label'      => 'Zone: ' . $zoneDispName,
                        'remove_url' => buildFilterUrl(['zone' => 'all']),
                        'icon'       => 'bx-map-pin'
                    ];
                }
                if ($payment_filter !== 'all' && !empty($payment_filter)) {
                    $payMap = [
                        'paid'     => 'Fully Paid (₹0 Due)',
                        'due'      => 'Has Outstanding Due',
                        'cash'     => 'Cash Collected',
                        'credit'   => 'Credited Sales',
                        'unbilled' => 'Unbilled / Pending Receipt'
                    ];
                    $active_filters_list[] = [
                        'label'      => 'Payment: ' . ($payMap[$payment_filter] ?? ucfirst($payment_filter)),
                        'remove_url' => buildFilterUrl(['payment_status' => 'all']),
                        'icon'       => 'bx-credit-card'
                    ];
                }
                if ($status_filter !== 'all' && !empty($status_filter)) {
                    $active_filters_list[] = [
                        'label'      => 'Status: ' . ucfirst($status_filter),
                        'remove_url' => buildFilterUrl(['status' => 'all']),
                        'icon'       => 'bx-check-circle'
                    ];
                }
                if ($period_filter !== 'all' && !empty($period_filter)) {
                    $timeMap = ['today' => 'Today', 'week' => 'Last 7 Days', 'month' => 'Last 30 Days'];
                    $active_filters_list[] = [
                        'label'      => 'Time: ' . ($timeMap[$period_filter] ?? ucfirst($period_filter)),
                        'remove_url' => buildFilterUrl(['period' => 'all']),
                        'icon'       => 'bx-calendar'
                    ];
                }
                if (!empty($search)) {
                    $active_filters_list[] = [
                        'label'      => 'Search: "' . htmlspecialchars($search) . '"',
                        'remove_url' => buildFilterUrl(['search' => null]),
                        'icon'       => 'bx-search'
                    ];
                }
                ?>

                <?php if (!empty($active_filters_list)): ?>
                    <div class="active-filters-bar">
                        <span class="active-filters-label"><i class='bx bx-filter-alt'></i> Filtered By:</span>
                        <?php foreach ($active_filters_list as $af): ?>
                            <a href="<?= htmlspecialchars($af['remove_url']) ?>" class="filter-chip" title="Click to remove this filter">
                                <i class='bx <?= $af['icon'] ?>'></i> <?= htmlspecialchars($af['label']) ?>
                                <span class="chip-close">&times;</span>
                            </a>
                        <?php endforeach; ?>
                        <a href="index.php" class="btn-clear-all-filters" title="Reset all filters to default">Reset All Filters</a>
                    </div>
                <?php endif; ?>

                <!-- 4. ORDERS REVENUE & SETTLEMENT REGISTER TABLE -->
                <div class="table-card-custom" id="orders-register">
                    <div class="table-header-custom">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <h2 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0;">Orders &amp; Financial Register</h2>
                                <?php if ($zone_filter !== 'all' && !empty($selected_zone_obj['zone_name'])): ?>
                                    <span class="badge-active-filter" style="font-size: 11px;">
                                        <i class='bx bx-map-pin'></i> <?= htmlspecialchars($selected_zone_obj['zone_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                Showing <?= min($per_page, count($orders)) ?> of <?= $total_matching_orders ?> matching records
                            </div>
                        </div>
                    </div>

                    <div style="width: 100%; overflow-x: auto;">
                        <table class="rev-table" id="revenueDataTable">
                            <thead>
                                <tr>
                                    <th>Order &amp; Bill #</th>
                                    <th>Zone</th>
                                    <th>Customer / Shop</th>
                                    <th>Product &amp; Qty</th>
                                    <th style="text-align: right;">Total Amount</th>
                                    <th style="text-align: right;">Cash Paid</th>
                                    <th style="text-align: right;">Credited</th>
                                    <th class="col-due" style="text-align: right;">Due Balance</th>
                                    <th>Delivery Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; padding: 48px 20px; color: #94a3b8;">
                                            <i class='bx bx-folder-open' style="font-size: 40px; margin-bottom: 8px;"></i>
                                            <div style="font-weight: 700; font-size: 15px; color: #475569;">No records found</div>
                                            <div style="font-size: 13px;">No orders match the current filter criteria.</div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $o): 
                                        $ordId   = $o['order_id'];
                                        $ordNum  = $o['order_number'] ?: ('#' . $ordId);
                                        $billNum = $o['bill_number'];
                                        $shop    = htmlspecialchars($o['shop_name'] ?: ($o['customer_name'] ?: 'Customer'));
                                        $phone   = $o['phone'] ?? '';
                                        $zSlug   = $o['zone_slug'] ?: 'testing';
                                        $zName   = htmlspecialchars($o['zone_name'] ?: 'Zone');
                                        $prod    = htmlspecialchars($o['product_name'] ?: ($o['fallback_product_name'] ?: 'Water'));
                                        $qty     = (int)$o['quantity'];
                                        $totAmt  = (float)$o['total_amount'];
                                        $cash    = (float)$o['receipt_cash'];
                                        $credit  = (float)$o['receipt_credit'];
                                        $due     = (float)$o['receipt_due'];
                                        $status  = strtolower($o['status']);
                                        $waLink  = makeWhatsAppLink($phone, $shop, $ordNum, formatCurrency($totAmt));
                                    ?>
                                    <tr>
                                        <!-- Order & Bill -->
                                        <td>
                                            <div style="font-weight: 700; color: #2563eb; font-size: 13px;">
                                                <?= htmlspecialchars($ordNum) ?>
                                            </div>
                                            <?php if (!empty($billNum)): ?>
                                                <div style="font-size: 12px; font-weight: 700; color: #059669; margin-top: 2px;">
                                                    <i class='bx bx-receipt'></i> <?= htmlspecialchars($billNum) ?>
                                                </div>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'unbilled'])) ?>" class="badge-unbilled" style="text-decoration: none;" title="Filter unbilled orders">Unbilled</a>
                                            <?php endif; ?>
                                            <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">
                                                <?= date('d M Y, h:i A', strtotime($o['created_at'])) ?>
                                            </div>
                                        </td>

                                        <!-- Zone -->
                                        <td>
                                            <a href="<?= htmlspecialchars(buildFilterUrl(['zone' => $zSlug])) ?>" class="badge-zone-pill" title="Filter orders for <?= $zName ?>">
                                                <i class='bx bx-map-pin'></i> <?= $zName ?>
                                            </a>
                                        </td>

                                        <!-- Customer / Shop -->
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a;"><?= $shop ?></div>
                                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                                <i class='bx bx-map'></i> <?= htmlspecialchars($o['location'] ?: 'Area') ?>
                                            </div>
                                            <?php if (!empty($phone)): ?>
                                                <div style="display: flex; gap: 8px; margin-top: 4px; font-size: 12px;">
                                                    <a href="tel:<?= htmlspecialchars($phone) ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;">
                                                        <i class='bx bx-phone'></i> <?= htmlspecialchars($phone) ?>
                                                    </a>
                                                    <?php if (!empty($waLink)): ?>
                                                        <a href="<?= $waLink ?>" target="_blank" style="color: #059669; text-decoration: none; font-weight: 700;">
                                                            <i class='bx bxl-whatsapp'></i> WA
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Product & Quantity -->
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                <span><?= $prod ?></span>
                                                <?php if ((int)($o['items_count'] ?? 0) > 1): ?>
                                                    <span style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 11px; padding: 1px 6px; border-radius: 4px; font-weight: 700;" title="<?= htmlspecialchars($o['items_summary'] ?? '') ?>">
                                                        +<?= ((int)$o['items_count'] - 1) ?> more
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ((int)($o['items_count'] ?? 0) > 1 && !empty($o['items_summary'])): ?>
                                                <div style="font-size: 11px; color: #64748b; margin-top: 2px;" title="<?= htmlspecialchars($o['items_summary']) ?>">
                                                    <?= htmlspecialchars($o['items_summary']) ?>
                                                </div>
                                                <div style="font-size: 12px; color: #475569; margin-top: 2px;">
                                                    <strong><?= $qty ?></strong> Cases total
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                                    <strong><?= $qty ?></strong> Cases &bull; @<?= formatCurrency($o['unit_price']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Total Amount -->
                                        <td style="text-align: right;">
                                            <?php if ($status === 'cancelled'): ?>
                                                <div style="font-size: 15px; font-weight: 700; color: #94a3b8; text-decoration: line-through;">
                                                    <?= formatCurrency($totAmt) ?>
                                                </div>
                                                <div style="font-size: 11px; color: #ef4444; font-weight: 600;">Cancelled</div>
                                            <?php else: ?>
                                                <div style="font-size: 15px; font-weight: 700; color: #0f172a;">
                                                    <?= formatCurrency($totAmt) ?>
                                                </div>
                                                <?php if ((float)$o['discount'] > 0): ?>
                                                    <div style="font-size: 11px; color: #dc2626;">-<?= formatCurrency($o['discount']) ?> disc</div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Cash Paid -->
                                        <td style="text-align: right;">
                                            <?php if ($status === 'cancelled'): ?>
                                                <?php if ($cash > 0): ?>
                                                    <span style="color: #94a3b8; text-decoration: line-through; font-size: 12px;" title="Cancelled order — cash voided">
                                                        <?= formatCurrency($cash) ?>
                                                    </span>
                                                    <div style="font-size: 10px; color: #ef4444; font-weight: 600;">Voided</div>
                                                <?php else: ?>
                                                    <span style="color: #cbd5e1;">&mdash;</span>
                                                <?php endif; ?>
                                            <?php elseif ($cash > 0): ?>
                                                <a href="<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'cash', 'status' => 'delivered'])) ?>" style="text-decoration: none; font-weight: 700; color: #047857;" title="Filter delivered cash orders">
                                                    <?= formatCurrency($cash) ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #cbd5e1;">&mdash;</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Credited -->
                                        <td style="text-align: right;">
                                            <?php if ($status === 'cancelled'): ?>
                                                <?php if ($credit > 0): ?>
                                                    <span style="color: #94a3b8; text-decoration: line-through; font-size: 12px;" title="Cancelled order — credit voided">
                                                        <?= formatCurrency($credit) ?>
                                                    </span>
                                                    <div style="font-size: 10px; color: #ef4444; font-weight: 600;">Voided</div>
                                                <?php else: ?>
                                                    <span style="color: #cbd5e1;">&mdash;</span>
                                                <?php endif; ?>
                                            <?php elseif ($credit > 0): ?>
                                                <a href="<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'credit', 'status' => 'delivered'])) ?>" style="text-decoration: none; font-weight: 700; color: #6d28d9;" title="Filter delivered credited orders">
                                                    <?= formatCurrency($credit) ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #cbd5e1;">&mdash;</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Due Balance -->
                                        <td class="col-due" style="text-align: right;">
                                            <?php if ($status === 'cancelled'): ?>
                                                <span style="color: #94a3b8; font-size: 11px;">Voided</span>
                                            <?php elseif ($due > 0): ?>
                                                <div>
                                                    <a href="<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'due'])) ?>" class="badge-due-danger" style="text-decoration: none;" title="Filter orders with due balance">
                                                        <?= formatCurrency($due) ?>
                                                    </a>
                                                </div>
                                                <form action="index.php" method="POST" style="margin-top: 4px;" onsubmit="return confirm('Clear due balance of <?= formatCurrency($due) ?> for order <?= htmlspecialchars($ordNum) ?> via Cash?\n\nThis will transfer the due amount into Cash and add <?= formatCurrency($due) ?> directly to Delivered Sales.');">
                                                    <input type="hidden" name="settle_due_balance" value="1">
                                                    <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                                    <input type="hidden" name="settle_mode" value="cash">
                                                    <button type="submit" class="btn-tbl" style="padding: 2px 6px; font-size: 11px; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; cursor: pointer; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; font-weight: 700; white-space: nowrap;" title="Clear due and add to Delivered Sales">
                                                        <i class='bx bx-check-circle'></i> Clear Due
                                                    </button>
                                                </form>
                                            <?php elseif (!empty($billNum)): ?>
                                                <span class="badge-settled">Settled (₹0)</span>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars(buildFilterUrl(['payment_status' => 'unbilled'])) ?>" style="text-decoration: none; color: #94a3b8; font-size: 12px;" title="Filter unbilled orders">
                                                    Pending
                                                </a>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Order Status & Inline Updater -->
                                        <td>
                                            <form action="index.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="update_order_status" value="1">
                                                <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                                <select name="status" onchange="this.form.submit()" class="tbl-status-select" aria-label="Update status">
                                                    <option value="pending" <?= ($status === 'pending') ? 'selected' : '' ?>>⏳ Pending</option>
                                                    <option value="processing" <?= ($status === 'processing') ? 'selected' : '' ?>>⚙️ Processing</option>
                                                    <option value="shipped" <?= ($status === 'shipped') ? 'selected' : '' ?>>🚚 Shipped</option>
                                                    <option value="delivered" <?= ($status === 'delivered') ? 'selected' : '' ?>>✅ Delivered</option>
                                                    <option value="cancelled" <?= ($status === 'cancelled') ? 'selected' : '' ?>>❌ Cancelled</option>
                                                </select>
                                            </form>
                                        </td>

                                        <!-- Actions -->
                                        <td>
                                            <div style="display: flex; gap: 6px; align-items: center;">
                                                <a href="../orders/view.php?id=<?= $ordId ?>" class="btn-tbl btn-tbl-view" title="View Order in Admin">
                                                    <i class='bx bx-show'></i>
                                                </a>

                                                <?php if (!empty($billNum)): ?>
                                                    <a href="<?= $cleanBaseUrl ?>/<?= $zSlug ?>/receipt?id=<?= $ordId ?>" target="_blank" class="btn-tbl btn-tbl-bill" title="Print/View Official Receipt">
                                                        <i class='bx bx-printer'></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= $cleanBaseUrl ?>/<?= $zSlug ?>/generate-receipt?order_id=<?= $ordId ?>" target="_blank" class="btn-tbl btn-tbl-bill" style="color: #059669; border-color: #a7f3d0; background: #ecfdf5;" title="Generate Receipt">
                                                        <i class='bx bx-receipt'></i>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($due > 0): ?>
                                                    <form action="index.php" method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Settle due balance of <?= formatCurrency($due) ?> via Cash?');">
                                                        <input type="hidden" name="settle_due_balance" value="1">
                                                        <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                                        <input type="hidden" name="settle_mode" value="cash">
                                                        <button type="submit" class="btn-tbl" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; cursor: pointer;" title="Settle Due (<?= formatCurrency($due) ?>)">
                                                            <i class='bx bx-money'></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <a href="<?= $cleanBaseUrl ?>/<?= $zSlug ?>/order?id=<?= $ordId ?>" target="_blank" class="btn-tbl btn-tbl-view" title="Open in Zone Portal">
                                                    <i class='bx bx-link-external'></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 5. PAGINATION -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-bar">
                            <div style="font-size: 13px; color: #64748b;">
                                Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong>
                            </div>

                            <div class="page-numbers">
                                <?php 
                                    $queryParams = $_GET;
                                    if ($page > 1): 
                                        $queryParams['page'] = $page - 1;
                                ?>
                                    <a href="?<?= http_build_query($queryParams) ?>" class="btn-page">&laquo; Prev</a>
                                <?php endif; ?>

                                <?php 
                                    $startPage = max(1, $page - 2);
                                    $endPage   = min($total_pages, $page + 2);
                                    for ($p = $startPage; $p <= $endPage; $p++): 
                                        $queryParams['page'] = $p;
                                ?>
                                    <a href="?<?= http_build_query($queryParams) ?>" class="btn-page <?= ($p == $page) ? 'active' : '' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endfor; ?>

                                <?php 
                                    if ($page < $total_pages): 
                                        $queryParams['page'] = $page + 1;
                                ?>
                                    <a href="?<?= http_build_query($queryParams) ?>" class="btn-page">Next &raquo;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- CLIENT-SIDE CSV EXPORT & INTERACTION SCRIPT -->
    <script>
    // Smooth scroll to table if navigating with #orders-register or after filter click
    window.addEventListener('DOMContentLoaded', function() {
        if (window.location.hash === '#orders-register') {
            const target = document.getElementById('orders-register');
            if (target) {
                setTimeout(function() {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 80);
            }
        }
    });


    </script>
</body>
</html>
