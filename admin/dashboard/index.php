<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "dashboard";
$page_title   = "Warehouse Dashboard";

// Time filter: 'today', 'week', 'month', 'all'
$period = $_GET['period'] ?? 'all';

// Build date condition based on period
$date_condition = "";
$date_params = [];
switch ($period) {
    case 'today':
        $date_condition = "AND DATE(o.created_at) = CURDATE()";
        $period_label = "Today's Report";
        break;
    case 'week':
        $date_condition = "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $period_label = "Weekly Report (Last 7 Days)";
        break;
    case 'month':
        $date_condition = "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $period_label = "Monthly Report (Last 30 Days)";
        break;
    case 'all':
    default:
        $date_condition = "";
        $period_label = "All Time Overview";
        break;
}

// 1. Total Products
$total_products = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();

// 2. Total Orders & Pending Orders (in the period)
$order_counts_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders
    FROM orders o
    WHERE 1=1 $date_condition
");
$order_counts_stmt->execute();
$counts = $order_counts_stmt->fetch(PDO::FETCH_ASSOC);
$total_orders = (int)($counts['total_orders'] ?? 0);
$pending_orders = (int)($counts['pending_orders'] ?? 0);
$delivered_orders = (int)($counts['delivered_orders'] ?? 0);

// Global pending orders across all time for notification card
$all_time_pending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

// 3. Total Revenue: calculated from delivered orders
$rev_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) 
    FROM orders o 
    WHERE status = 'delivered' $date_condition
");
$rev_stmt->execute();
$total_revenue = (float)$rev_stmt->fetchColumn();

// 4. Warehouse Stocks overview
$stock_data = $pdo->query("
    SELECT 
        COALESCE(SUM(case_stock), 0) as total_central_stock,
        (SELECT COALESCE(SUM(quantity), 0) FROM orders WHERE status != 'cancelled') as total_dispatched_cases
    FROM products
    WHERE status = 'active'
")->fetch(PDO::FETCH_ASSOC);
$total_central_cases = (int)($stock_data['total_central_stock'] ?? 0);
$total_dispatched_cases = (int)($stock_data['total_dispatched_cases'] ?? 0);
$total_remaining_cases = max(0, $total_central_cases - $total_dispatched_cases);

// 5. Zone Financial Summary Grid:
// | Zone | Cash | Credited | Due | (calculated from orders and delivery receipts)
$zone_financial_query = "
    SELECT 
        z.id,
        z.name as zone_name,
        z.slug as zone_slug,
        z.status as zone_status,
        COUNT(o.order_id) as total_zone_orders,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_zone_orders,
        SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_zone_orders,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.total_amount ELSE 0 END), 0) as total_sales,
        COALESCE(SUM(r.cash_amount), 0) as total_cash,
        COALESCE(SUM(r.credited_amount), 0) as total_credited,
        COALESCE(SUM(r.due_amount), 0) as total_due
    FROM zones z
    LEFT JOIN orders o ON z.id = o.zone_id " . ($date_condition ? str_replace('AND', 'AND', $date_condition) : '') . "
    LEFT JOIN receipts r ON o.order_id = r.order_id
    GROUP BY z.id
    ORDER BY z.name ASC
";
$zone_financials = $pdo->query($zone_financial_query)->fetchAll(PDO::FETCH_ASSOC);

// Totals across all zones
$sum_cash = 0;
$sum_credited = 0;
$sum_due = 0;
foreach ($zone_financials as $zf) {
    $sum_cash += (float)$zf['total_cash'];
    $sum_credited += (float)$zf['total_credited'];
    $sum_due += (float)$zf['total_due'];
}

// 6. Recent Orders
$recent_stmt = $pdo->query("
    SELECT 
        o.order_id, 
        o.order_number, 
        o.shop_name, 
        o.customer_name, 
        o.phone, 
        o.total_amount, 
        o.status, 
        o.created_at,
        z.name as zone_name,
        z.slug as zone_slug,
        r.bill_number
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    ORDER BY o.created_at DESC
    LIMIT 8
");
$recent_orders = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// 7. Product Inventory Stock distribution
$prod_stocks = $pdo->query("
    SELECT 
        p.product_id,
        p.product_name,
        p.name,
        p.case_stock,
        p.case_price,
        p.net_content,
        p.net_content_unit,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' THEN o.quantity ELSE 0 END), 0) as in_zones
    FROM products p
    LEFT JOIN orders o ON p.product_id = o.product_id
    WHERE p.status = 'active'
    GROUP BY p.product_id
    ORDER BY p.case_price ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Dashboard - Liyas International</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .period-selector {
            display: inline-flex;
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 3px;
        }
        .period-btn {
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-decoration: none;
            transition: 0.15s;
        }
        .period-btn:hover { color: #1e293b; background: #f8fafc; }
        .period-btn.active {
            background: #2563eb;
            color: #fff;
            font-weight: 600;
        }
        .stats-grid-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .stat-card-custom {
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 14px;
            padding: 1.5rem;
            position: relative;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .stat-card-custom .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 1rem;
        }
        .icon-blue { background: #dbeafe; color: #1d4ed8; }
        .icon-green { background: #d1fae5; color: #047857; }
        .icon-amber { background: #fef3c7; color: #b45309; }
        .icon-purple { background: #ede9fe; color: #6d28d9; }
        .stat-card-val {
            font-size: 26px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        .stat-card-lbl {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
            font-weight: 500;
        }
        .combined-orders-box {
            display: flex;
            align-items: baseline;
            gap: 14px;
        }
        .pending-pill-alert {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .financial-table th {
            background: #f8fafc;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            padding: 12px 16px;
        }
        .financial-table td {
            font-size: 14px;
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .financial-table tr.total-row td {
            background: #f8fafc;
            font-weight: 700;
            color: #0f172a;
            border-top: 2px solid #cbd5e1;
            font-size: 15px;
        }
        .grid-dashboard-2 {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 992px) {
            .grid-dashboard-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <i class='bx bx-home'></i>
                    <span>Central Warehouse Dashboard</span>
                </div>
                <div class="header-actions">
                    <!-- Period Filter Tabs -->
                    <div class="period-selector">
                        <a href="index.php?period=today" class="period-btn <?= ($period === 'today') ? 'active' : '' ?>">Daily (Today)</a>
                        <a href="index.php?period=week" class="period-btn <?= ($period === 'week') ? 'active' : '' ?>">Weekly</a>
                        <a href="index.php?period=month" class="period-btn <?= ($period === 'month') ? 'active' : '' ?>">Monthly</a>
                        <a href="index.php?period=all" class="period-btn <?= ($period === 'all') ? 'active' : '' ?>">All Time</a>
                    </div>
                </div>
            </div>
            
            <div class="content-area">
                <!-- Welcome & Report Banner -->
                <div style="background: #fff; border: 1px solid var(--border-light); border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h1 style="font-size: 20px; font-weight: 700; color: #1e293b; margin: 0;">Liyas International Warehouse Overview</h1>
                        <p style="font-size: 13px; color: #64748b; margin: 2px 0 0 0;">
                            Multi-Zone Order, Delivery & Financial Summary &bull; <strong><?= $period_label ?></strong>
                        </p>
                    </div>
                    <div>
                        <a href="../orders/create.php" class="btn-action btn-add noselect" style="text-decoration: none; padding: 0.5rem 1rem;">
                            <span class="text">+ Add New Order</span>
                        </a>
                    </div>
                </div>

                <!-- 1. DASHBOARD CARDS -->
                <div class="stats-grid-dashboard">
                    <!-- Total Products Card -->
                    <div class="stat-card-custom">
                        <div class="stat-icon icon-blue">
                            <i class='bx bx-shopping-bag'></i>
                        </div>
                        <div class="stat-card-val"><?= number_format($total_products) ?></div>
                        <div class="stat-card-lbl">Active Products</div>
                        <div style="margin-top: 0.75rem; font-size: 12px;">
                            <a href="../products/index.php" style="color: #2563eb; text-decoration: none; font-weight: 500;">
                                Manage Products &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Total Orders & Pending Orders Card (Combined in same grid section) -->
                    <div class="stat-card-custom">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div class="stat-icon icon-amber">
                                <i class='bx bx-cart'></i>
                            </div>
                            <?php if ($pending_orders > 0): ?>
                                <span class="pending-pill-alert">
                                    🔴 <?= $pending_orders ?> Pending
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="combined-orders-box">
                            <div class="stat-card-val"><?= number_format($total_orders) ?></div>
                            <div style="font-size: 15px; color: #64748b; font-weight: 500;">
                                Total Orders
                            </div>
                        </div>
                        <div class="stat-card-lbl" style="display: flex; gap: 8px; margin-top: 6px;">
                            <span style="color: #b45309; font-weight: 600;"><?= $pending_orders ?> Pending</span> &bull; 
                            <span style="color: #047857; font-weight: 600;"><?= $delivered_orders ?> Delivered</span>
                        </div>
                        <div style="margin-top: 0.75rem; font-size: 12px;">
                            <a href="../orders/index.php?filter=pending" style="color: #b45309; text-decoration: none; font-weight: 500;">
                                View Pending Orders &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Total Revenue Card (Delivered Orders) -->
                    <div class="stat-card-custom">
                        <div class="stat-icon icon-green">
                            <i class='bx bx-rupee'></i>
                        </div>
                        <div class="stat-card-val"><?= formatCurrency($total_revenue) ?></div>
                        <div class="stat-card-lbl">Delivered Revenue</div>
                        <div style="margin-top: 0.75rem; font-size: 12px; color: #64748b;">
                            <?= $period_label ?>
                        </div>
                    </div>

                    <!-- Warehouse Cases Stock Card -->
                    <div class="stat-card-custom">
                        <div class="stat-icon icon-purple">
                            <i class='bx bx-box'></i>
                        </div>
                        <div class="stat-card-val"><?= number_format($total_remaining_cases) ?> <span style="font-size: 14px; font-weight: 500; color: #64748b;">Cases</span></div>
                        <div class="stat-card-lbl">Remaining Available Stock</div>
                        <div style="margin-top: 0.75rem; font-size: 12px; color: #64748b;">
                            <?= number_format($total_dispatched_cases) ?> cases in zones / in transit
                        </div>
                    </div>
                </div>

                <!-- 2. ZONE FINANCIAL SUMMARY GRID -->
                <div class="table-card" style="margin-bottom: 1.5rem;">
                    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem;">
                        <div>
                            <div class="table-title" style="font-size: 17px; font-weight: 700; color: #0f172a;">
                                <i class='bx bx-map-pin' style="color: #2563eb;"></i> Zone Financial Summary
                            </div>
                            <div style="font-size: 13px; color: #64748b; margin-top: 2px;">
                                Dynamic financial tracking across delivery zones &bull; <?= $period_label ?>
                            </div>
                        </div>
                        <div>
                            <a href="../zones/index.php" style="font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 500;">
                                Manage Zones &rarr;
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive-wrapper">
                        <table class="financial-table">
                            <thead>
                                <tr>
                                    <th>Zone</th>
                                    <th>Portal Route</th>
                                    <th style="text-align: right;">Cash Received</th>
                                    <th style="text-align: right;">Credited Amount</th>
                                    <th style="text-align: right;">Outstanding Due</th>
                                    <th style="text-align: right;">Delivered Revenue</th>
                                    <th style="text-align: center;">Orders (Pending)</th>
                                    <th style="text-align: center;">Delivery Portal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($zone_financials)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2.5rem; color: #64748b;">
                                            No delivery zones configured. <a href="../zones/create.php">Create initial zone</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($zone_financials as $zf): 
                                        $portalUrl = BASE_URL . '/' . htmlspecialchars($zf['zone_slug']);
                                        $cash = (float)$zf['total_cash'];
                                        $credit = (float)$zf['total_credited'];
                                        $due = (float)$zf['total_due'];
                                        $sales = (float)$zf['total_sales'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 14px;">
                                                <?= htmlspecialchars($zf['zone_name']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 12px; color: #2563eb;">
                                                /<?= htmlspecialchars($zf['zone_slug']) ?>
                                            </code>
                                        </td>
                                        <td style="text-align: right; font-weight: 600; color: #047857;">
                                            <?= formatCurrency($cash) ?>
                                        </td>
                                        <td style="text-align: right; font-weight: 600; color: #b45309;">
                                            <?= formatCurrency($credit) ?>
                                        </td>
                                        <td style="text-align: right; font-weight: 600; color: <?= ($due > 0) ? '#dc2626' : '#64748b' ?>;">
                                            <?= formatCurrency($due) ?>
                                        </td>
                                        <td style="text-align: right; font-weight: 700; color: #1e293b;">
                                            <?= formatCurrency($sales) ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <strong><?= (int)$zf['total_zone_orders'] ?></strong>
                                            <?php if ((int)$zf['pending_zone_orders'] > 0): ?>
                                                <span style="color: #ef4444; font-weight: 600; font-size: 12px;">(<?= (int)$zf['pending_zone_orders'] ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <a href="<?= $portalUrl ?>" target="_blank" class="btn-action" style="padding: 4px 10px; background: #e0f2fe; color: #0284c7; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class='bx bx-door-open'></i> Open Portal
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <!-- Summary Total Row -->
                                    <tr class="total-row">
                                        <td colspan="2">TOTAL WAREHOUSE ALL ZONES</td>
                                        <td style="text-align: right; color: #047857;"><?= formatCurrency($sum_cash) ?></td>
                                        <td style="text-align: right; color: #b45309;"><?= formatCurrency($sum_credited) ?></td>
                                        <td style="text-align: right; color: <?= ($sum_due > 0) ? '#dc2626' : '#64748b' ?>;"><?= formatCurrency($sum_due) ?></td>
                                        <td style="text-align: right; color: #1e293b;"><?= formatCurrency($total_revenue) ?></td>
                                        <td style="text-align: center;"><?= number_format($total_orders) ?></td>
                                        <td></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid-dashboard-2">
                    <!-- Recent Orders Table -->
                    <div class="table-card">
                        <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem;">
                            <div class="table-title" style="font-size: 16px; font-weight: 600;">Recent Orders</div>
                            <a href="../orders/index.php" style="font-size: 13px; color: #2563eb; text-decoration: none;">View All Orders &rarr;</a>
                        </div>
                        <div class="table-responsive-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Shop</th>
                                        <th>Zone</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_orders)): ?>
                                        <tr><td colspan="6" style="text-align: center; padding: 2rem; color: #64748b;">No recent orders.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_orders as $ord): 
                                            $ordNum = $ord['order_number'] ?: ('#' . $ord['order_id']);
                                            $sName = htmlspecialchars($ord['shop_name'] ?: ($ord['customer_name'] ?: 'Customer'));
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="../orders/view.php?id=<?= $ord['order_id'] ?>" style="font-weight: 600; color: #2563eb; text-decoration: none;">
                                                    <?= htmlspecialchars($ordNum) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div style="font-weight: 500; color: #1e293b;"><?= $sName ?></div>
                                            </td>
                                            <td>
                                                <span style="font-size: 12px; color: #475569;"><?= htmlspecialchars($ord['zone_name'] ?: 'Central') ?></span>
                                            </td>
                                            <td>
                                                <strong><?= formatCurrency($ord['total_amount']) ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge <?= getStatusBadgeClass($ord['status']) ?>">
                                                    <?= ucfirst($ord['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="../orders/view.php?id=<?= $ord['order_id'] ?>" class="btn-action" style="padding: 4px 8px; background: #f1f5f9; color: #334155; border-radius: 6px; text-decoration: none; font-size: 12px;">
                                                    <i class='bx bx-show'></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Warehouse Stock Breakdown Card -->
                    <div class="table-card">
                        <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem;">
                            <div class="table-title" style="font-size: 16px; font-weight: 600;">Stock Breakdown</div>
                            <a href="../products/index.php" style="font-size: 13px; color: #2563eb; text-decoration: none;">Manage &rarr;</a>
                        </div>
                        <div style="padding: 1rem 1.5rem;">
                            <?php foreach ($prod_stocks as $ps): 
                                $pName = htmlspecialchars($ps['product_name'] ?: $ps['name']);
                                $cStock = (int)$ps['case_stock'];
                                $inZones = (int)$ps['in_zones'];
                                $rem = max(0, $cStock - $inZones);
                                $pct = ($cStock > 0) ? min(100, round(($rem / $cStock) * 100)) : 0;
                            ?>
                            <div style="margin-bottom: 1.25rem;">
                                <div style="display: flex; justify-content: space-between; font-size: 13px; font-weight: 500; margin-bottom: 4px;">
                                    <span style="color: #1e293b;"><?= $pName ?></span>
                                    <span style="color: #2563eb; font-weight: 600;"><?= $rem ?> / <?= $cStock ?> Cases</span>
                                </div>
                                <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                    <div style="width: <?= $pct ?>%; height: 100%; background: <?= ($pct < 25) ? '#ef4444' : '#22c55e' ?>; border-radius: 4px;"></div>
                                </div>
                                <div style="display: flex; justify-content: space-between; font-size: 11px; color: #64748b; margin-top: 3px;">
                                    <span><?= $inZones ?> cases in zones</span>
                                    <span>₹<?= number_format($ps['case_price'], 2) ?>/case</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</body>
</html>
