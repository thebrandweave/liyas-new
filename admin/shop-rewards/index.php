<?php
/**
 * admin/shop-rewards/index.php
 * Shop Rewards Management Hub & AJAX status/orders updater.
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

// AJAX Handler: Update Delivered Orders count / Reward Progress
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_shop_delivered_orders')) {
    header('Content-Type: application/json; charset=UTF-8');
    $shopName = trim($_POST['shop_name'] ?? '');
    $completedOrdersInput = isset($_POST['completed_orders']) ? trim($_POST['completed_orders']) : '';
    $threshold = isset($_POST['reward_threshold']) ? (int)$_POST['reward_threshold'] : 10;
    if ($threshold <= 0) $threshold = 10;
    $statusOverride = trim($_POST['reward_status'] ?? 'auto');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $resetToActual = !empty($_POST['reset_to_actual']);

    if (empty($shopName)) {
        echo json_encode(['success' => false, 'error' => 'Shop name is required.']);
        exit;
    }

    try {
        // Ensure manual_completed_orders column exists in rewards
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM rewards LIKE 'manual_completed_orders'")->fetch();
            if (!$colCheck) {
                $pdo->exec("ALTER TABLE rewards ADD COLUMN manual_completed_orders INT DEFAULT NULL AFTER completed_orders");
            }
        } catch (Exception $e) {}

        // Find existing reward record
        $chk = $pdo->prepare("SELECT id, reward_status FROM rewards WHERE TRIM(shop_name) = ? LIMIT 1");
        $chk->execute([$shopName]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if ($resetToActual) {
            // Fetch actual delivered count from orders
            $actStmt = $pdo->prepare("
                SELECT COUNT(*) FROM orders 
                WHERE (TRIM(shop_name) = ? OR TRIM(customer_name) = ?) 
                  AND status = 'delivered'
            ");
            $actStmt->execute([$shopName, $shopName]);
            $effectiveDelivered = (int)$actStmt->fetchColumn();
            $manualValue = null;
        } else {
            $effectiveDelivered = max(0, (int)$completedOrdersInput);
            $manualValue = $effectiveDelivered;
        }

        // Determine final reward status
        if ($statusOverride === 'claimed') {
            $finalStatus = 'claimed';
        } elseif ($statusOverride === 'eligible') {
            $finalStatus = 'eligible';
        } elseif ($statusOverride === 'in_progress') {
            $finalStatus = 'in_progress';
        } else {
            // auto
            $curr = $existing['reward_status'] ?? 'in_progress';
            if ($curr === 'claimed') {
                $finalStatus = 'claimed';
            } else {
                $finalStatus = ($effectiveDelivered >= $threshold) ? 'eligible' : 'in_progress';
            }
        }

        if ($existing) {
            $rewardId = (int)$existing['id'];
            $stmt = $pdo->prepare("
                UPDATE rewards 
                SET completed_orders = ?, 
                    manual_completed_orders = ?, 
                    reward_threshold = ?, 
                    reward_status = ?, 
                    customer_phone = COALESCE(NULLIF(?, ''), customer_phone),
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$effectiveDelivered, $manualValue, $threshold, $finalStatus, $customerPhone, $rewardId]);
        } else {
            // If phone empty, fetch from latest order
            if (empty($customerPhone)) {
                $pStmt = $pdo->prepare("
                    SELECT phone FROM orders 
                    WHERE TRIM(shop_name) = ? OR TRIM(customer_name) = ? 
                    ORDER BY order_id DESC LIMIT 1
                ");
                $pStmt->execute([$shopName, $shopName]);
                $customerPhone = $pStmt->fetchColumn() ?: '';
            }

            $ins = $pdo->prepare("
                INSERT INTO rewards (shop_name, customer_phone, completed_orders, manual_completed_orders, reward_threshold, reward_status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$shopName, $customerPhone, $effectiveDelivered, $manualValue, $threshold, $finalStatus]);
            $rewardId = (int)$pdo->lastInsertId();
        }

        // Also sync customer phone to master shops table if provided
        if (!empty($customerPhone)) {
            try {
                $sSync = $pdo->prepare("
                    UPDATE shops 
                    SET phone = ? 
                    WHERE TRIM(shop_name) = ? AND (phone IS NULL OR phone = '')
                ");
                $sSync->execute([$customerPhone, $shopName]);
            } catch (Exception $e) {}
        }

        quickLog($pdo, 'update', 'reward', $rewardId, "Updated shop '{$shopName}' delivered orders to {$effectiveDelivered} (threshold: {$threshold}, status: '{$finalStatus}')");

        echo json_encode([
            'success'          => true,
            'reward_id'        => $rewardId,
            'shop_name'        => $shopName,
            'completed_orders' => $effectiveDelivered,
            'threshold'        => $threshold,
            'reward_status'    => $finalStatus,
            'percent'          => min(100, round(($effectiveDelivered / $threshold) * 100))
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// AJAX Handler: Update reward status (Claim / Reset)
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
            // Fetch phone and actual delivered count from orders
            $pStmt = $pdo->prepare("
                SELECT phone, 
                       (SELECT COUNT(*) FROM orders o2 WHERE (TRIM(o2.shop_name) = TRIM(o1.shop_name) OR TRIM(o2.customer_name) = TRIM(o1.shop_name)) AND o2.status = 'delivered') as deliv_count
                FROM orders o1
                WHERE TRIM(shop_name) = ? OR TRIM(customer_name) = ? 
                ORDER BY order_id DESC LIMIT 1
            ");
            $pStmt->execute([$shopName, $shopName]);
            $oInfo = $pStmt->fetch(PDO::FETCH_ASSOC);
            $phone = $oInfo['phone'] ?? '';
            $delivCount = (int)($oInfo['deliv_count'] ?? 0);

            $ins = $pdo->prepare("
                INSERT INTO rewards (shop_name, customer_phone, completed_orders, reward_threshold, reward_status)
                VALUES (?, ?, ?, 10, ?)
            ");
            $ins->execute([$shopName, $phone, $delivCount, $newStatus]);
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

// Fetch Reward Threshold from system settings
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
        MAX(COALESCE(NULLIF(rew.customer_phone, ''), o.phone)) AS customer_phone,
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
        MAX(rew.manual_completed_orders) AS db_manual_completed_orders,
        MAX(rew.completed_orders) AS db_completed_orders,
        MAX(rew.reward_threshold) AS db_reward_threshold,
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
    $actualComp = (int)$s['lifetime_completed_orders'];
    $manualComp = ($s['db_manual_completed_orders'] !== null && $s['db_manual_completed_orders'] !== '') ? (int)$s['db_manual_completed_orders'] : null;
    $comp = ($manualComp !== null) ? $manualComp : $actualComp;

    $shopThreshold = !empty($s['db_reward_threshold']) ? (int)$s['db_reward_threshold'] : $rewardThreshold;
    if ($shopThreshold <= 0) $shopThreshold = $rewardThreshold;

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
    } elseif ($comp >= $shopThreshold) {
        $eligibleCount++;
    } else {
        $inProgressCount++;
    }
}

$filteredShops = [];
foreach ($allShops as $s) {
    $actualComp = (int)$s['lifetime_completed_orders'];
    $manualComp = ($s['db_manual_completed_orders'] !== null && $s['db_manual_completed_orders'] !== '') ? (int)$s['db_manual_completed_orders'] : null;
    $comp = ($manualComp !== null) ? $manualComp : $actualComp;

    $shopThreshold = !empty($s['db_reward_threshold']) ? (int)$s['db_reward_threshold'] : $rewardThreshold;
    if ($shopThreshold <= 0) $shopThreshold = $rewardThreshold;

    $dbStat = $s['db_reward_status'];

    if ($dbStat === 'claimed') {
        $status = 'claimed';
    } elseif ($comp >= $shopThreshold) {
        $status = 'eligible';
    } else {
        $status = 'in_progress';
    }

    $s['effective_completed_orders'] = $comp;
    $s['actual_completed_orders'] = $actualComp;
    $s['shop_threshold'] = $shopThreshold;
    $s['is_manually_edited'] = ($manualComp !== null && $manualComp !== $actualComp);
    $s['computed_status'] = $status;
    $s['percent'] = min(100, round(($comp / $shopThreshold) * 100));
    $s['remaining'] = max(0, $shopThreshold - $comp);

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
        .btn-edit-reward { 
            background: #eff6ff; 
            color: #2563eb; 
            border-color: #bfdbfe; 
            font-weight: 700; 
        }
        .btn-edit-reward:hover { 
            background: #dbeafe; 
            color: #1d4ed8; 
            border-color: #93c5fd; 
        }
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

        /* Edit Modal Styles */
        .rew-modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .rew-modal-backdrop.active {
            display: flex;
        }
        .rew-modal-box {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            animation: rewModalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes rewModalIn {
            from { opacity: 0; transform: scale(0.96) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .rew-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .rew-modal-icon {
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
        .rew-modal-title {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }
        .rew-modal-subtitle {
            margin: 2px 0 0 0;
            font-size: 12px;
            color: #64748b;
        }
        .rew-modal-close {
            background: transparent;
            border: none;
            font-size: 24px;
            color: #94a3b8;
            cursor: pointer;
            line-height: 1;
            padding: 4px;
            transition: color 0.15s ease;
        }
        .rew-modal-close:hover {
            color: #0f172a;
        }
        .rew-modal-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            max-height: calc(85vh - 130px);
            overflow-y: auto;
        }
        .rew-modal-shop-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
        }
        .rew-form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .rew-form-label {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }
        .rew-form-control {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            transition: all 0.15s ease;
            box-sizing: border-box;
            width: 100%;
        }
        .rew-form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .rew-btn-reset-actual {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            border-radius: 8px;
            padding: 0 12px;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .rew-btn-reset-actual:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .rew-form-hint {
            margin: 0;
            font-size: 11px;
            color: #64748b;
        }
        .rew-preview-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .rew-modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 20px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .rew-btn-cancel {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .rew-btn-cancel:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .rew-btn-save {
            background: #2563eb;
            border: none;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s ease;
        }
        .rew-btn-save:hover {
            background: #1d4ed8;
        }
        .rew-btn-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
                            Shop and Reward Status
                        </h1>
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
                            <div class="rew-stat-label"><?= ($filterDate !== 'all') ? "Active Shops in Period" : "Total Shops" ?></div>
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
                            <div class="rew-stat-label"> Eligible for Reward</div>
                        </div>
                    </div>

                    <div class="rew-stat-card">
                        <div class="rew-stat-icon" style="background: #f1f5f9; color: #64748b;">
                            <i class='bx bx-check-double'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num"><?= $claimedCount ?></div>
                            <div class="rew-stat-label"> Rewards Claimed</div>
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
                                Eligible (<?= $eligibleCount ?>)
                            </a>
                            <a href="<?= buildRewardsUrl(['tab' => 'in_progress']) ?>" class="tab-pill <?= ($tabFilter === 'in_progress') ? 'active' : '' ?>">
                                 In Progress (<?= $inProgressCount ?>)
                            </a>
                            <a href="<?= buildRewardsUrl(['tab' => 'claimed']) ?>" class="tab-pill tab-claimed <?= ($tabFilter === 'claimed') ? 'active' : '' ?>">
                                 Claimed (<?= $claimedCount ?>)
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
                                    $comp = (int)$shop['effective_completed_orders'];
                                    $actualDelivered = (int)$shop['actual_completed_orders'];
                                    $shopThreshold = (int)$shop['shop_threshold'];
                                    $periodCompleted = (int)$shop['period_completed_orders'];
                                    $periodCases = (int)$shop['period_cases_delivered'];
                                    $lifetimeCases = (int)$shop['lifetime_cases_delivered'];
                                    $status = $shop['computed_status'];
                                    $percent = (int)$shop['percent'];
                                    $phone = $shop['customer_phone'];
                                    $isEdited = !empty($shop['is_manually_edited']);
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
                                                    Lifetime: <strong><?= $comp ?></strong> / <?= $shopThreshold ?> required
                                                    <?php if ($isEdited): ?>
                                                        <span style="color: #d97706; font-size: 10px; font-weight: 700; display: block; margin-top: 2px;">
                                                            <i class='bx bx-pencil'></i> Adjusted (Actual: <?= $actualDelivered ?>)
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-weight: 700; color: #0f172a; font-size: 14px;"><?= $comp ?></span>
                                                <span style="font-size: 12px; color: #64748b;"> / <?= $shopThreshold ?> required</span>
                                                <?php if ($isEdited): ?>
                                                    <div style="font-size: 10.5px; color: #d97706; font-weight: 700; margin-top: 2px;">
                                                        <i class='bx bx-pencil'></i> Adjusted (Actual: <?= $actualDelivered ?>)
                                                    </div>
                                                <?php endif; ?>
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
                                                <span><?= $comp ?> / <?= $shopThreshold ?></span>
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
                                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                <!-- Edit Delivered Orders Button -->
                                                <button type="button" 
                                                        class="btn-table-action btn-edit-reward" 
                                                        onclick="openEditOrdersModal({
                                                            shop_name: '<?= htmlspecialchars(addslashes($sName), ENT_QUOTES) ?>',
                                                            completed_orders: <?= (int)$comp ?>,
                                                            actual_orders: <?= (int)$actualDelivered ?>,
                                                            threshold: <?= (int)$shopThreshold ?>,
                                                            status: '<?= htmlspecialchars(addslashes($status), ENT_QUOTES) ?>',
                                                            phone: '<?= htmlspecialchars(addslashes($phone ?? ''), ENT_QUOTES) ?>',
                                                            zone: '<?= htmlspecialchars(addslashes($shop['zone_name'] ?? ''), ENT_QUOTES) ?>',
                                                            is_edited: <?= $isEdited ? 'true' : 'false' ?>
                                                        })" 
                                                        title="Edit delivered orders count & reward progress">
                                                    <i class='bx bx-edit-alt'></i> Edit
                                                </button>

                                                <?php if ($status === 'eligible'): ?>
                                                    <button type="button" class="btn-table-action btn-claim" onclick="claimShopReward('<?= htmlspecialchars(addslashes($sName), ENT_QUOTES) ?>', this)">
                                                        <i class='bx bx-gift'></i> Claim
                                                    </button>
                                                <?php elseif ($status === 'claimed'): ?>
                                                    <button type="button" class="btn-table-action btn-unclaim" onclick="unclaimShopReward('<?= htmlspecialchars(addslashes($sName), ENT_QUOTES) ?>', this)">
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

    <!-- Modal: Edit Delivered Orders & Reward Progress -->
    <div id="editOrdersModal" class="rew-modal-backdrop" onclick="handleEditModalBackdrop(event)">
        <div class="rew-modal-box">
            <div class="rew-modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div class="rew-modal-icon">
                        <i class='bx bxs-gift'></i>
                    </div>
                    <div>
                        <h3 class="rew-modal-title">Edit Reward Progress</h3>
                        <p class="rew-modal-subtitle" id="editModalShopSub">Update delivered orders for shop</p>
                    </div>
                </div>
                <button type="button" class="rew-modal-close" onclick="closeEditOrdersModal()">&times;</button>
            </div>

            <form id="editOrdersForm" onsubmit="submitEditOrdersForm(event)">
                <input type="hidden" id="editShopName" name="shop_name">
                <input type="hidden" id="editResetToActual" name="reset_to_actual" value="0">

                <div class="rew-modal-body">
                    <!-- Shop Details Strip -->
                    <div class="rew-modal-shop-strip">
                        <div>
                            <div style="font-size: 15px; font-weight: 800; color: #0f172a;" id="editShopNameDisplay">Shop Name</div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 2px;" id="editShopZoneDisplay">All Central Zone</div>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 11px; font-weight: 700; color: #475569; background: #f1f5f9; padding: 4px 8px; border-radius: 6px;" id="editActualOrdersDisplay">
                                Actual System Orders: <strong>0</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Delivered Orders Input -->
                    <div class="rew-form-group">
                        <label class="rew-form-label" for="editCompletedOrders">
                            Delivered Orders Count <span style="color: #dc2626;">*</span>
                        </label>
                        <div style="display: flex; gap: 8px;">
                            <input type="number" 
                                   id="editCompletedOrders" 
                                   name="completed_orders" 
                                   min="0" 
                                   max="9999" 
                                   class="rew-form-control" 
                                   required 
                                   oninput="updateModalPreview()">
                            <button type="button" 
                                    class="rew-btn-reset-actual" 
                                    onclick="resetModalToActualOrders()" 
                                    title="Reset count to actual delivered orders from system orders">
                                <i class='bx bx-reset'></i> Reset to Actual
                            </button>
                        </div>
                        <p class="rew-form-hint">Directly edits the completed orders tally used to calculate milestone progress.</p>
                    </div>

                    <!-- Live Progress Preview Card -->
                    <div class="rew-preview-box">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 12px; font-weight: 700; color: #334155;">Reward Progress Preview:</span>
                            <span id="previewPercentText" style="font-size: 12px; font-weight: 800; color: #2563eb;">0 / 10 (0%)</span>
                        </div>
                        <div style="height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-bottom: 8px;">
                            <div id="previewProgressBar" style="height: 100%; width: 0%; background: #2563eb; transition: width 0.25s ease, background 0.25s ease;"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span id="previewStatusBadge" class="badge-rew badge-rew-progress">
                                <i class='bx bx-time'></i> 10 left
                            </span>
                            <span style="font-size: 11px; color: #64748b;" id="previewHint">Milestone at 10 orders</span>
                        </div>
                    </div>

                    <!-- Two-Column Row: Threshold & Status -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="rew-form-group">
                            <label class="rew-form-label" for="editRewardThreshold">Target Threshold</label>
                            <input type="number" 
                                   id="editRewardThreshold" 
                                   name="reward_threshold" 
                                   min="1" 
                                   max="999" 
                                   class="rew-form-control" 
                                   value="10" 
                                   oninput="updateModalPreview()">
                            <p class="rew-form-hint">Orders needed to unlock reward.</p>
                        </div>

                        <div class="rew-form-group">
                            <label class="rew-form-label" for="editRewardStatus">Reward Status</label>
                            <select id="editRewardStatus" name="reward_status" class="rew-form-control" onchange="updateModalPreview()">
                                <option value="auto">✨ Auto (by delivered count)</option>
                                <option value="in_progress">⏳ In Progress</option>
                                <option value="eligible">🎉 Eligible for Reward</option>
                                <option value="claimed">✅ Claimed</option>
                            </select>
                            <p class="rew-form-hint">Auto switches to eligible on reaching threshold.</p>
                        </div>
                    </div>

                    <!-- Customer Phone -->
                    <div class="rew-form-group">
                        <label class="rew-form-label" for="editCustomerPhone">Customer Phone (Optional)</label>
                        <input type="tel" 
                               id="editCustomerPhone" 
                               name="customer_phone" 
                               class="rew-form-control" 
                               placeholder="e.g. 9876543210">
                    </div>
                </div>

                <div class="rew-modal-footer">
                    <button type="button" class="rew-btn-cancel" onclick="closeEditOrdersModal()">Cancel</button>
                    <button type="submit" id="btnSaveEditOrders" class="rew-btn-save">
                        <i class='bx bx-save'></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Slide-Out Rewards Drawer Component -->
    <?php include __DIR__ . '/../../components/rewards-sidebar.php'; ?>

    <script>
    let currentModalActualOrders = 0;

    function openEditOrdersModal(data) {
        document.getElementById('editShopName').value = data.shop_name;
        document.getElementById('editShopNameDisplay').textContent = data.shop_name;
        document.getElementById('editModalShopSub').textContent = 'Update delivered orders for ' + data.shop_name;
        document.getElementById('editShopZoneDisplay').textContent = data.zone ? data.zone : 'All Zones';
        
        currentModalActualOrders = parseInt(data.actual_orders, 10) || 0;
        document.getElementById('editActualOrdersDisplay').innerHTML = 'Actual System Orders: <strong>' + currentModalActualOrders + '</strong>';
        
        document.getElementById('editCompletedOrders').value = parseInt(data.completed_orders, 10) || 0;
        document.getElementById('editRewardThreshold').value = parseInt(data.threshold, 10) || 10;
        document.getElementById('editCustomerPhone').value = data.phone || '';
        document.getElementById('editResetToActual').value = '0';

        const statusSelect = document.getElementById('editRewardStatus');
        if (data.status === 'claimed') {
            statusSelect.value = 'claimed';
        } else {
            statusSelect.value = 'auto';
        }

        updateModalPreview();

        const modal = document.getElementById('editOrdersModal');
        modal.classList.add('active');
        setTimeout(() => {
            document.getElementById('editCompletedOrders').focus();
            document.getElementById('editCompletedOrders').select();
        }, 100);
    }

    function closeEditOrdersModal() {
        const modal = document.getElementById('editOrdersModal');
        if (modal) modal.classList.remove('active');
    }

    function handleEditModalBackdrop(event) {
        if (event.target === document.getElementById('editOrdersModal')) {
            closeEditOrdersModal();
        }
    }

    function resetModalToActualOrders() {
        document.getElementById('editCompletedOrders').value = currentModalActualOrders;
        document.getElementById('editResetToActual').value = '1';
        updateModalPreview();
    }

    function updateModalPreview() {
        const compInput = document.getElementById('editCompletedOrders');
        const threshInput = document.getElementById('editRewardThreshold');
        const statusSelect = document.getElementById('editRewardStatus');
        
        const comp = Math.max(0, parseInt(compInput.value, 10) || 0);
        const thresh = Math.max(1, parseInt(threshInput.value, 10) || 10);
        const percent = Math.min(100, Math.round((comp / thresh) * 100));
        
        document.getElementById('previewPercentText').textContent = comp + ' / ' + thresh + ' (' + percent + '%)';
        
        const bar = document.getElementById('previewProgressBar');
        bar.style.width = percent + '%';
        bar.style.background = (percent >= 100) ? '#059669' : '#2563eb';

        const badge = document.getElementById('previewStatusBadge');
        const selectedStatus = statusSelect.value;
        
        if (selectedStatus === 'claimed') {
            badge.className = 'badge-rew badge-rew-claimed';
            badge.innerHTML = "<i class='bx bx-check-circle'></i> Claimed";
            document.getElementById('previewHint').textContent = "Reward marked as claimed";
        } else if (comp >= thresh || selectedStatus === 'eligible') {
            badge.className = 'badge-rew badge-rew-eligible';
            badge.innerHTML = "<i class='bx bxs-party'></i> Eligible";
            document.getElementById('previewHint').textContent = "Threshold reached! Ready to claim.";
        } else {
            const left = Math.max(0, thresh - comp);
            badge.className = 'badge-rew badge-rew-progress';
            badge.innerHTML = "<i class='bx bx-time'></i> " + left + " left";
            document.getElementById('previewHint').textContent = left + " more delivered orders needed";
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeEditOrdersModal();
        }
    });

    function submitEditOrdersForm(event) {
        event.preventDefault();
        const btn = document.getElementById('btnSaveEditOrders');
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving...";

        const formData = new FormData(document.getElementById('editOrdersForm'));
        formData.append('action', 'update_shop_delivered_orders');

        fetch('index.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            if (data.success) {
                closeEditOrdersModal();
                window.location.reload();
            } else {
                alert('Error updating reward: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('Request failed: ' + err.message);
        });
    }

    function claimShopReward(shopName, btn) {
        if (!confirm('Mark reward as Claimed for shop: ' + shopName + '?')) return;
        updateShopRewardStatusAjax(shopName, 'claimed', btn);
    }

    function unclaimShopReward(shopName, btn) {
        if (!confirm('Reset reward status for shop: ' + shopName + '?')) return;
        updateShopRewardStatusAjax(shopName, 'eligible', btn);
    }

    function updateShopRewardStatusAjax(shopName, status, btn) {
        if (btn) btn.disabled = true;
        const form = new FormData();
        form.append('action', 'update_shop_reward_status');
        form.append('shop_name', shopName);
        form.append('reward_status', status);

        fetch('index.php', {
            method: 'POST',
            body: form
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to update reward status'));
                if (btn) btn.disabled = false;
            }
        })
        .catch(err => {
            alert('Network error: ' + err.message);
            if (btn) btn.disabled = false;
        });
    }
    </script>
</body>
</html>
