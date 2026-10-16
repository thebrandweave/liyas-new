<?php
/**
 * admin/shop-rewards/index.php
 * Shop Rewards Management Hub & AJAX status updater.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

// Set timezone
date_default_timezone_set('Asia/Kolkata');

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "shop-rewards";
$page_title = "Shop Rewards Status";

// AJAX Handler for updating reward status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_shop_reward_status')) {
    header('Content-Type: application/json; charset=UTF-8');
    $shopName = trim($_POST['shop_name'] ?? '');
    $newStatus = trim($_POST['reward_status'] ?? 'eligible');

    if (empty($shopName)) {
        echo json_encode(['success' => false, 'error' => 'Shop name is required.']);
        exit;
    }

    try {
        // Find existing reward record
        $chk = $pdo->prepare("SELECT id FROM rewards WHERE TRIM(shop_name) = ? LIMIT 1");
        $chk->execute([$shopName]);
        $rewardId = $chk->fetchColumn();

        if ($rewardId) {
            $stmt = $pdo->prepare("UPDATE rewards SET reward_status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $rewardId]);
        } else {
            // Fetch phone from latest order
            $pStmt = $pdo->prepare("
                SELECT phone 
                FROM orders 
                WHERE TRIM(shop_name) = ? OR TRIM(customer_name) = ? 
                ORDER BY order_id DESC LIMIT 1
            ");
            $pStmt->execute([$shopName, $shopName]);
            $phone = $pStmt->fetchColumn() ?: '';

            $ins = $pdo->prepare("
                INSERT INTO rewards (shop_name, customer_phone, completed_orders, reward_threshold, reward_status)
                VALUES (?, ?, 0, 10, ?)
            ");
            $ins->execute([$shopName, $phone, $newStatus]);
            $rewardId = (int)$pdo->lastInsertId();
        }

        quickLog($pdo, 'update', 'reward', (int)$rewardId, "Updated reward status to '{$newStatus}' for shop: {$shopName}");
        echo json_encode(['success' => true, 'reward_id' => $rewardId]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Fetch Reward Threshold
$rewardThreshold = (int)getSystemSetting($pdo, 'reward_threshold', 10);
if ($rewardThreshold <= 0) $rewardThreshold = 10;

// Filters
$tabFilter  = trim($_GET['tab'] ?? 'all');
$search     = trim($_GET['search'] ?? '');
$filterDate = trim($_GET['date'] ?? 'all');
$fromDate   = trim($_GET['from_date'] ?? '');
$toDate     = trim($_GET['to_date'] ?? '');

$validDate = function ($date) {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (($fromDate !== '' && $validDate($fromDate)) || ($toDate !== '' && $validDate($toDate))) {
    $filterDate = 'custom';
}
if (!in_array($filterDate, ['all', 'today', 'yesterday', 'weekly', 'this_week', 'monthly', 'this_month', 'custom'])) {
    $filterDate = 'all';
}

if ($filterDate === 'custom') {
    if (!empty($fromDate) && !empty($toDate)) {
        $currentDateLabel = date('d M Y', strtotime($fromDate)) . ' to ' . date('d M Y', strtotime($toDate));
    } elseif (!empty($fromDate)) {
        $currentDateLabel = 'From ' . date('d M Y', strtotime($fromDate));
    } else {
        $currentDateLabel = 'Up to ' . date('d M Y', strtotime($toDate));
    }
} else {
    $dateLabels = [
        'all'        => 'All Time',
        'today'      => 'Today (Daily)',
        'yesterday'  => 'Yesterday (Daily)',
        'weekly'     => 'This Week',
        'this_week'  => 'This Week',
        'monthly'    => 'This Month (Monthly)',
        'this_month' => 'This Month (Monthly)'
    ];
    $currentDateLabel = $dateLabels[$filterDate] ?? 'All Time';
}

// Function to generate period date condition with unique parameter names to prevent PDO reuse errors
function buildPeriodDateCondition(string $filterDate, string $fromDate, string $toDate, string $suffix, array &$params): string {
    if ($filterDate === 'custom' || (!empty($fromDate) || !empty($toDate))) {
        if (!empty($fromDate) && !empty($toDate)) {
            $params[":from_{$suffix}"] = $fromDate;
            $params[":to_{$suffix}"]   = $toDate;
            return "DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) >= :from_{$suffix} AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) <= :to_{$suffix}";
        } elseif (!empty($fromDate)) {
            $params[":from_{$suffix}"] = $fromDate;
            return "DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) >= :from_{$suffix}";
        } elseif (!empty($toDate)) {
            $params[":to_{$suffix}"] = $toDate;
            return "DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) <= :to_{$suffix}";
        }
    } elseif ($filterDate === 'today') {
        return "DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) = CURDATE()";
    } elseif ($filterDate === 'yesterday') {
        return "DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) = SUBDATE(CURDATE(), 1)";
    } elseif ($filterDate === 'weekly' || $filterDate === 'this_week') {
        return "YEARWEEK(COALESCE(r.created_at, o.updated_at, o.created_at), 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($filterDate === 'monthly' || $filterDate === 'this_month') {
        return "YEAR(COALESCE(r.created_at, o.updated_at, o.created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(r.created_at, o.updated_at, o.created_at)) = MONTH(CURDATE())";
    }
    return "1 = 1";
}

$queryParams = [];
$condDeliv = buildPeriodDateCondition($filterDate, $fromDate, $toDate, 'deliv', $queryParams);
$condTotal = buildPeriodDateCondition($filterDate, $fromDate, $toDate, 'total', $queryParams);
$condCases = buildPeriodDateCondition($filterDate, $fromDate, $toDate, 'cases', $queryParams);

// Helper for maintaining filter state across URLs
function buildRewardsUrl(array $overrides = []): string {
    $params = [
        'tab'       => $_GET['tab'] ?? 'all',
        'search'    => $_GET['search'] ?? '',
        'date'      => $_GET['date'] ?? 'all',
        'from_date' => $_GET['from_date'] ?? '',
        'to_date'   => $_GET['to_date'] ?? ''
    ];
    $merged = array_merge($params, $overrides);
    if (isset($overrides['date']) && $overrides['date'] !== 'custom') {
        $merged['from_date'] = '';
        $merged['to_date']   = '';
    }
    if (!empty($merged['from_date']) || !empty($merged['to_date'])) {
        $merged['date'] = 'custom';
    }
    $queryParts = [];
    foreach ($merged as $k => $v) {
        if ($v !== '' && $v !== 'all' && $v !== null) {
            $queryParts[$k] = $v;
        }
    }
    return 'index.php' . (!empty($queryParts) ? '?' . http_build_query($queryParts) : '');
}

// Fetch all distinct shops with delivery orders stats (both period activity and lifetime progress)
$shopsQuery = "
    SELECT 
        TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name)) AS shop_name,
        MAX(o.phone) AS customer_phone,
        MAX(o.location) AS location,
        MAX(z.name) AS zone_name,
        MAX(z.slug) AS zone_slug,

        -- Period counts for selected date filter
        SUM(CASE WHEN o.status = 'delivered' AND ({$condDeliv}) THEN 1 ELSE 0 END) AS period_completed_orders,
        SUM(CASE WHEN ({$condTotal}) THEN 1 ELSE 0 END) AS period_total_orders,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' AND ({$condCases}) THEN COALESCE(r.delivered_quantity, o.quantity) ELSE 0 END), 0) AS period_cases_delivered,

        -- Lifetime cumulative counts
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS lifetime_completed_orders,
        COUNT(o.order_id) AS lifetime_total_orders,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN COALESCE(r.delivered_quantity, o.quantity) ELSE 0 END), 0) AS lifetime_cases_delivered,

        MAX(rew.reward_status) AS db_reward_status,
        MAX(rew.id) AS reward_id
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN rewards rew ON TRIM(rew.shop_name) = TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name))
    WHERE (o.shop_name IS NOT NULL AND TRIM(o.shop_name) != '')
       OR (o.customer_name IS NOT NULL AND TRIM(o.customer_name) != '')
    GROUP BY TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name))
    ORDER BY " . ($filterDate !== 'all' ? "period_completed_orders DESC, period_total_orders DESC, " : "") . "lifetime_completed_orders DESC, shop_name ASC
";

$stmt = $pdo->prepare($shopsQuery);
$stmt->execute($queryParams);
$allShops = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$eligibleCount = 0;
$inProgressCount = 0;
$claimedCount = 0;
$totalPeriodCompletedOrders = 0;
$totalPeriodCasesDelivered = 0;
$totalPeriodActiveShops = 0;
$lifetimeTotalDeliveredOrders = 0;
$lifetimeTotalCasesDelivered = 0;

foreach ($allShops as $s) {
    $comp = (int)$s['lifetime_completed_orders'];
    $periodComp = (int)$s['period_completed_orders'];
    $periodCases = (int)$s['period_cases_delivered'];
    $periodTot = (int)$s['period_total_orders'];

    if ($periodTot > 0 || $periodComp > 0) {
        $totalPeriodActiveShops++;
    }
    $totalPeriodCompletedOrders += $periodComp;
    $totalPeriodCasesDelivered += $periodCases;
    $lifetimeTotalDeliveredOrders += $comp;
    $lifetimeTotalCasesDelivered += (int)$s['lifetime_cases_delivered'];

    $dbStat = $s['db_reward_status'];
    if ($dbStat === 'claimed') {
        $claimedCount++;
    } elseif ($comp >= $rewardThreshold) {
        $eligibleCount++;
    } else {
        $inProgressCount++;
    }
}

$filteredShops = [];
foreach ($allShops as $s) {
    $comp = (int)$s['lifetime_completed_orders'];
    $dbStat = $s['db_reward_status'];

    if ($dbStat === 'claimed') {
        $status = 'claimed';
    } elseif ($comp >= $rewardThreshold) {
        $status = 'eligible';
    } else {
        $status = 'in_progress';
    }

    $s['computed_status'] = $status;
    $s['percent'] = min(100, round(($comp / $rewardThreshold) * 100));
    $s['remaining'] = max(0, $rewardThreshold - $comp);

    // Apply Tab & Search Filters
    $matchTab = ($tabFilter === 'all') 
        || ($status === $tabFilter)
        || ($tabFilter === 'active_period' && ((int)$s['period_total_orders'] > 0 || (int)$s['period_completed_orders'] > 0));

    $matchSearch = empty($search) 
        || (stripos($s['shop_name'], $search) !== false) 
        || (stripos($s['customer_phone'] ?? '', $search) !== false);

    if ($matchTab && $matchSearch) {
        $filteredShops[] = $s;
    }
}
$totalShopsCount = count($allShops);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <title>Shop Reward Status - Liyas Admin</title>

    <style>
        .page-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-rewards-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #ffffff;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
            transition: all 0.2s ease;
        }
        .btn-rewards-toggle:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.35);
        }

        /* KPI Cards Grid */
        .rewards-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .rew-stat-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .rew-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .rew-stat-num {
            font-size: 24px;
            font-weight: 700;
            line-height: 1.1;
            color: var(--text-main);
        }
        .rew-stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            font-weight: 500;
        }

        /* Tabs and Filter Bar */
        .rewards-filter-bar {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .rewards-tabs-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .tab-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-secondary);
            background: #f1f5f9;
            border: 1px solid transparent;
            transition: all 0.15s ease;
        }
        .tab-pill.active {
            background: #2563eb;
            color: #ffffff;
        }
        .tab-pill.tab-eligible.active { background: #059669; }
        .tab-pill.tab-claimed.active { background: #64748b; }

        .search-box-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 12px;
            min-width: 260px;
        }
        .search-box-wrap input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 13px;
            width: 100%;
        }

        /* Shops Table */
        .shops-table-container {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .shops-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }
        .shops-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #000000;
            border-bottom: 1px solid var(--border-light);
        }
        .shops-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
        }
        .shops-table tr:hover td {
            background: #f8fafc;
        }

        .badge-rew {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .badge-rew-eligible { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-rew-progress { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .badge-rew-claimed { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .btn-table-action {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            transition: all 0.15s ease;
        }
        .btn-table-action:hover { background: #f1f5f9; color: #0f172a; }
        .btn-claim { background: #ecfdf5; color: #047857; border-color: #a7f3d0; font-weight: 700; }
        .btn-claim:hover { background: #d1fae5; }
        .btn-unclaim { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .btn-unclaim:hover { background: #fee2e2; }

        /* Top Action Bar / Page Header matching Vehicles page */
        .v-page-header {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .v-page-title-box h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .v-page-title-box p {
            font-size: 13px;
            color: #64748b;
            margin: 3px 0 0 0;
        }
        .v-header-btns {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-v-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-v-secondary:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; }

        .btn-v-primary {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s ease;
        }
        .btn-v-primary:hover { background: #1d4ed8; color: #ffffff; }

        .date-preset-pill {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .date-preset-pill:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
        .date-preset-pill.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            font-weight: 700;
        }

        .rew-date-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .rew-date-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .rew-date-group input[type="date"] {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
        }
        .rew-date-group input[type="date"]:focus {
            border-color: #2563eb;
            background: #ffffff;
        }

        .rew-active-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            padding: 8px 12px;
            background: #f1f5f9;
            border-radius: 8px;
            font-size: 12px;
            color: #334155;
            margin-top: 10px;
            width: 100%;
            box-sizing: border-box;
        }
        .rew-filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="breadcrumb">
                    <a href="../dashboard/index.php" style="color: inherit; text-decoration: none;">
                        <i class='bx bx-home'></i> Admin
                    </a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Shop Rewards & Loyalty Tracker</span>
                </div>
                <div class="header-actions">
                </div>
            </div>

            <div class="content-area">
                <!-- Page Header Hero Banner -->
                <div class="v-page-header">
                    <div class="v-page-title-box">
                        <h1>
                            <i class='bx bxs-gift' style="color: #2563eb; font-size: 26px;"></i>
                            Shop Reward Status & Loyalty Tracker
                        </h1>
                        <p>
                            Shops unlock the promotional reward upon completing <strong><?= $rewardThreshold ?> delivered orders</strong>. Track every shop's progress and reward claims.
                        </p>
                    </div>
                    <div class="v-header-btns">
                        <a href="index.php" class="btn-v-secondary" title="Reload Rewards Data">
                            <i class='bx bx-refresh'></i> Refresh
                        </a>
                    </div>
                </div>

                <!-- KPI Cards -->
                <div class="rewards-stats-grid">
                    <div class="rew-stat-card">
                        <div class="rew-stat-icon" style="background: #eff6ff; color: #2563eb;">
                            <i class='bx bx-store-alt'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num">
                                <?= ($filterDate !== 'all') ? "{$totalPeriodActiveShops} <span style='font-size:14px; font-weight:600; color:#64748b;'>/ {$totalShopsCount}</span>" : $totalShopsCount ?>
                            </div>
                            <div class="rew-stat-label"><?= ($filterDate !== 'all') ? "Active Shops in Period" : "Total Outlets & Shops" ?></div>
                        </div>
                    </div>

                    <div class="rew-stat-card" style="border-color: #a7f3d0; background: #f0fdf4;">
                        <div class="rew-stat-icon" style="background: #dcfce7; color: #15803d;">
                            <i class='bx bx-check-circle'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num" style="color: #15803d;">
                                <?= ($filterDate !== 'all') ? number_format($totalPeriodCompletedOrders) : number_format($lifetimeTotalDeliveredOrders) ?>
                            </div>
                            <div class="rew-stat-label" style="color: #166534;">
                                <?= ($filterDate !== 'all') ? "Orders Delivered (Period)" : "Lifetime Delivered Orders" ?>
                            </div>
                        </div>
                    </div>

                    <div class="rew-stat-card" style="border-color: #bfdbfe; background: #eff6ff;">
                        <div class="rew-stat-icon" style="background: #dbeafe; color: #1d4ed8;">
                            <i class='bx bx-layer'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num" style="color: #1d4ed8;">
                                <?= ($filterDate !== 'all') ? number_format($totalPeriodCasesDelivered) : number_format($lifetimeTotalCasesDelivered) ?> cs
                            </div>
                            <div class="rew-stat-label" style="color: #1e40af;">
                                <?= ($filterDate !== 'all') ? "Cases Delivered (Period)" : "Total Delivered Cases" ?>
                            </div>
                        </div>
                    </div>

                    <div class="rew-stat-card">
                        <div class="rew-stat-icon" style="background: #ecfdf5; color: #059669;">
                            <i class='bx bxs-party'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num"><?= $eligibleCount ?></div>
                            <div class="rew-stat-label">🎉 Eligible for Reward</div>
                        </div>
                    </div>

                    <div class="rew-stat-card">
                        <div class="rew-stat-icon" style="background: #f1f5f9; color: #64748b;">
                            <i class='bx bx-check-double'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num"><?= $claimedCount ?></div>
                            <div class="rew-stat-label">✅ Rewards Claimed</div>
                        </div>
                    </div>
                </div>

                <!-- Filter & Search Bar with Date Presets & Custom Range -->
                <div class="rewards-filter-bar" style="flex-direction: column; align-items: stretch; gap: 14px;">
                    <!-- Top Row: Status Tabs & Search -->
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div class="rewards-tabs-row">
                            <a href="<?= buildRewardsUrl(['tab' => 'all']) ?>" class="tab-pill <?= ($tabFilter === 'all') ? 'active' : '' ?>">
                                All Shops (<?= $totalShopsCount ?>)
                            </a>
                            <?php if ($filterDate !== 'all'): ?>
                                <a href="<?= buildRewardsUrl(['tab' => 'active_period']) ?>" class="tab-pill <?= ($tabFilter === 'active_period') ? 'active' : '' ?>" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                                    <i class='bx bx-trending-up'></i> Active in Period (<?= $totalPeriodActiveShops ?>)
                                </a>
                            <?php endif; ?>
                            <a href="<?= buildRewardsUrl(['tab' => 'eligible']) ?>" class="tab-pill tab-eligible <?= ($tabFilter === 'eligible') ? 'active' : '' ?>">
                                🎉 Eligible (<?= $eligibleCount ?>)
                            </a>
                            <a href="<?= buildRewardsUrl(['tab' => 'in_progress']) ?>" class="tab-pill <?= ($tabFilter === 'in_progress') ? 'active' : '' ?>">
                                ⏳ In Progress (<?= $inProgressCount ?>)
                            </a>
                            <a href="<?= buildRewardsUrl(['tab' => 'claimed']) ?>" class="tab-pill tab-claimed <?= ($tabFilter === 'claimed') ? 'active' : '' ?>">
                                ✅ Claimed (<?= $claimedCount ?>)
                            </a>
                        </div>

                        <!-- Search Form -->
                        <form action="" method="GET" style="margin: 0;">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($tabFilter) ?>">
                            <input type="hidden" name="date" value="<?= htmlspecialchars($filterDate) ?>">
                            <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                            <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                            <div class="search-box-wrap">
                                <i class='bx bx-search' style="color: #94a3b8;"></i>
                                <input type="search" name="search" placeholder="Search shop name or phone..." value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </form>
                    </div>

                    <!-- Bottom Row: Date Presets & Custom Date Range Filter -->
                    <form action="" method="GET" style="display: flex; flex-direction: column; gap: 10px; padding-top: 10px; border-top: 1px dashed #e2e8f0; margin: 0;">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tabFilter) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <!-- Presets Row -->
                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="font-size: 12px; font-weight: 700; color: #475569; margin-right: 4px;">Period:</span>
                                <a href="<?= buildRewardsUrl(['date' => 'all']) ?>" class="date-preset-pill <?= ($filterDate === 'all') ? 'active' : '' ?>">
                                    All Time
                                </a>
                                <a href="<?= buildRewardsUrl(['date' => 'today']) ?>" class="date-preset-pill <?= ($filterDate === 'today') ? 'active' : '' ?>">
                                    <i class='bx bx-sun'></i> Today
                                </a>
                                <a href="<?= buildRewardsUrl(['date' => 'yesterday']) ?>" class="date-preset-pill <?= ($filterDate === 'yesterday') ? 'active' : '' ?>">
                                    Yesterday
                                </a>
                                <a href="<?= buildRewardsUrl(['date' => 'this_week']) ?>" class="date-preset-pill <?= in_array($filterDate, ['weekly', 'this_week']) ? 'active' : '' ?>">
                                    This Week
                                </a>
                                <a href="<?= buildRewardsUrl(['date' => 'this_month']) ?>" class="date-preset-pill <?= in_array($filterDate, ['monthly', 'this_month']) ? 'active' : '' ?>">
                                    <i class='bx bx-calendar'></i> This Month
                                </a>
                            </div>

                            <!-- Custom Date Inputs -->
                            <div class="rew-date-inputs">
                                <div class="rew-date-group">
                                    <span>From:</span>
                                    <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                                </div>
                                <div class="rew-date-group">
                                    <span>To:</span>
                                    <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                                </div>
                                <button type="submit" class="btn-v-primary" style="padding: 6px 14px; font-size: 12px;">
                                    <i class='bx bx-filter-alt'></i> Filter Dates
                                </button>
                                <?php if ($filterDate !== 'all' || !empty($search) || !empty($fromDate) || !empty($toDate)): ?>
                                    <a href="index.php" class="btn-v-secondary" style="padding: 6px 12px; font-size: 12px;" title="Reset all filters">
                                        <i class='bx bx-reset'></i> Reset
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Active Filter Notification Strip -->
                        <?php if ($filterDate !== 'all' || !empty($search)): ?>
                            <div class="rew-active-strip">
                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <span style="font-weight: 700; color: #0f172a;">Active Filters:</span>
                                    <?php if ($filterDate !== 'all'): ?>
                                        <span class="rew-filter-tag">
                                            <i class='bx bx-calendar'></i> Date Period: "<strong><?= htmlspecialchars($currentDateLabel) ?></strong>"
                                            <a href="<?= buildRewardsUrl(['date' => 'all']) ?>" style="color: #ef4444; text-decoration: none; font-weight: 800; margin-left: 2px;">&times;</a>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($search)): ?>
                                        <span class="rew-filter-tag">
                                            <i class='bx bx-search'></i> Search: "<strong><?= htmlspecialchars($search) ?></strong>"
                                            <a href="<?= buildRewardsUrl(['search' => '']) ?>" style="color: #ef4444; text-decoration: none; font-weight: 800; margin-left: 2px;">&times;</a>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-weight: 700; color: #2563eb;">
                                    <?= count($filteredShops) ?> matching shop<?= count($filteredShops) === 1 ? '' : 's' ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Shops Table -->
                <div class="shops-table-container">
                    <?php if (empty($filteredShops)): ?>
                        <div style="text-align: center; padding: 50px 20px; color: #64748b;">
                            <i class='bx bx-store' style="font-size: 40px; color: #cbd5e1; margin-bottom: 8px;"></i>
                            <h3 style="font-size: 16px; font-weight: 700; color: #0f172a;">No Shops Found</h3>
                            <p style="font-size: 13px;">No shop records match your current filter or search criteria.</p>
                        </div>
                    <?php else: ?>
                        <table class="shops-table">
                            <thead>
                                <tr>
                                    <th>Shop / Outlet Name</th>
                                    <th>Zone & Location</th>
                                    <th>Delivered Orders</th>
                                    <th>Delivered Cases</th>
                                    <th>Reward Progress</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredShops as $shop): 
                                    $sName = $shop['shop_name'];
                                    $lifetimeCompleted = (int)$shop['lifetime_completed_orders'];
                                    $periodCompleted = (int)$shop['period_completed_orders'];
                                    $periodCases = (int)$shop['period_cases_delivered'];
                                    $lifetimeCases = (int)$shop['lifetime_cases_delivered'];
                                    $status = $shop['computed_status'];
                                    $percent = (int)$shop['percent'];
                                    $phone = $shop['customer_phone'];
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a; font-size: 14px;"><?= htmlspecialchars($sName) ?></div>
                                            <?php if (!empty($phone)): ?>
                                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                                    <a href="tel:<?= htmlspecialchars($phone) ?>" style="color: #2563eb; text-decoration: none; font-weight: 600;">
                                                        <i class='bx bx-phone'></i> <?= htmlspecialchars($phone) ?>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #334155;">
                                                <?= htmlspecialchars($shop['zone_name'] ?: 'All Zones') ?>
                                            </div>
                                            <?php if (!empty($shop['location'])): ?>
                                                <div style="font-size: 12px; color: #64748b;">
                                                    <?= nl2br(htmlspecialchars(wordwrap($shop['location'], 30, "\n", true))) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($filterDate !== 'all'): ?>
                                                <div style="display: flex; align-items: baseline; gap: 6px;">
                                                    <span style="font-weight: 800; color: #2563eb; font-size: 16px;"><?= $periodCompleted ?></span>
                                                    <span style="font-size: 11px; font-weight: 700; color: #15803d; background: #dcfce7; padding: 2px 6px; border-radius: 4px; border: 1px solid #86efac;">
                                                        Delivered (<?= htmlspecialchars($currentDateLabel) ?>)
                                                    </span>
                                                </div>
                                                <div style="font-size: 11px; color: #64748b; margin-top: 3px;">
                                                    Lifetime: <strong><?= $lifetimeCompleted ?></strong> / <?= $rewardThreshold ?> required
                                                </div>
                                            <?php else: ?>
                                                <span style="font-weight: 700; color: #0f172a; font-size: 14px;"><?= $lifetimeCompleted ?></span>
                                                <span style="font-size: 12px; color: #64748b;"> / <?= $rewardThreshold ?> required</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($filterDate !== 'all'): ?>
                                                <div style="font-weight: 800; color: #047857; font-size: 15px;">
                                                    <?= $periodCases ?> <span style="font-size: 12px; font-weight: 600; color: #059669;">cs in period</span>
                                                </div>
                                                <div style="font-size: 11px; color: #64748b; margin-top: 3px;">
                                                    Lifetime: <?= $lifetimeCases ?> cs
                                                </div>
                                            <?php else: ?>
                                                <span style="font-weight: 700; color: #2563eb;"><?= $lifetimeCases ?></span> Cases
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width: 140px;">
                                            <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 4px; font-weight: 600;">
                                                <span><?= $lifetimeCompleted ?> / <?= $rewardThreshold ?></span>
                                                <span><?= $percent ?>%</span>
                                            </div>
                                            <div style="height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden;">
                                                <div style="height: 100%; width: <?= $percent ?>%; background: <?= ($percent >= 100) ? '#059669' : '#2563eb' ?>;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($status === 'eligible'): ?>
                                                <span class="badge-rew badge-rew-eligible">
                                                    <i class='bx bxs-party'></i> Eligible
                                                </span>
                                            <?php elseif ($status === 'claimed'): ?>
                                                <span class="badge-rew badge-rew-claimed">
                                                    <i class='bx bx-check-circle'></i> Claimed
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-rew badge-rew-progress">
                                                    <i class='bx bx-time'></i> <?= $shop['remaining'] ?> left
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <?php if ($status === 'eligible'): ?>
                                                    <button type="button" class="btn-table-action btn-claim" onclick="claimShopReward('<?= htmlspecialchars(addslashes($sName)) ?>', this)">
                                                        <i class='bx bx-gift'></i> Claim
                                                    </button>
                                                <?php elseif ($status === 'claimed'): ?>
                                                    <button type="button" class="btn-table-action btn-unclaim" onclick="unclaimShopReward('<?= htmlspecialchars(addslashes($sName)) ?>', this)">
                                                        <i class='bx bx-undo'></i> Reset
                                                    </button>
                                                <?php endif; ?>

                                                <a href="../orders/index.php?shop=<?= urlencode($sName) ?>" class="btn-table-action" title="View Orders">
                                                    <i class='bx bx-list-ul'></i> Orders
                                                </a>
                                                <a href="../orders/create.php?shop_name=<?= urlencode($sName) ?>" class="btn-table-action" title="New Reorder" style="color: #2563eb;">
                                                    <i class='bx bx-refresh'></i> Reorder
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Slide-Out Rewards Drawer Component -->
    <?php include __DIR__ . '/../../components/rewards-sidebar.php'; ?>
</body>
</html>
