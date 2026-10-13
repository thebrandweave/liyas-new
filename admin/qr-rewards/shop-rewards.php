<?php
/**
 * admin/qr-rewards/shop-rewards.php
 * Shop Rewards Management Hub & AJAX status updater.
 */
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';
require_once '../includes/activity_logger.php';

// Set timezone
date_default_timezone_set('Asia/Kolkata');

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "qr-rewards";
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
$tabFilter = trim($_GET['tab'] ?? 'all');
$search = trim($_GET['search'] ?? '');

// Fetch all distinct shops with delivery orders stats
$shopsQuery = "
    SELECT 
        TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name)) AS shop_name,
        MAX(o.phone) AS customer_phone,
        MAX(o.location) AS location,
        MAX(z.name) AS zone_name,
        MAX(z.slug) AS zone_slug,
        SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS completed_orders,
        COUNT(o.order_id) AS total_orders,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN COALESCE(r.delivered_quantity, o.quantity) ELSE 0 END), 0) AS total_cases_delivered,
        MAX(rew.reward_status) AS db_reward_status,
        MAX(rew.id) AS reward_id
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN rewards rew ON TRIM(rew.shop_name) = TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name))
    WHERE (o.shop_name IS NOT NULL AND TRIM(o.shop_name) != '')
       OR (o.customer_name IS NOT NULL AND TRIM(o.customer_name) != '')
    GROUP BY TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name))
    ORDER BY completed_orders DESC, shop_name ASC
";
$allShops = $pdo->query($shopsQuery)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$eligibleCount = 0;
$inProgressCount = 0;
$claimedCount = 0;

$filteredShops = [];
foreach ($allShops as $s) {
    $comp = (int)$s['completed_orders'];
    $dbStat = $s['db_reward_status'];

    if ($dbStat === 'claimed') {
        $status = 'claimed';
        $claimedCount++;
    } elseif ($comp >= $rewardThreshold) {
        $status = 'eligible';
        $eligibleCount++;
    } else {
        $status = 'in_progress';
        $inProgressCount++;
    }

    $s['computed_status'] = $status;
    $s['percent'] = min(100, round(($comp / $rewardThreshold) * 100));
    $s['remaining'] = max(0, $rewardThreshold - $comp);

    // Apply Filter & Search
    $matchTab = ($tabFilter === 'all') || ($status === $tabFilter);
    $matchSearch = empty($search) || (stripos($s['shop_name'], $search) !== false) || (stripos($s['customer_phone'] ?? '', $search) !== false);

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
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
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
    </style>
</head>
<body>
    <div class="container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="breadcrumb">
                    <a href="../dashboard/index.php">Dashboard</a>
                    <span>/</span>
                    <a href="index.php">Rewards</a>
                    <span>/</span>
                    <span>Shop Reward Status</span>
                </div>

                <div class="page-header-actions">
                    <button type="button" class="btn-rewards-toggle" onclick="toggleRewardsSidebar(true)">
                        <i class='bx bxs-gift'></i>
                        <span>Open Rewards Sidebar</span>
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class='bx bx-qr'></i> QR Codes
                    </a>
                </div>
            </div>

            <div class="content-wrapper">
                <div style="margin-bottom: 20px;">
                    <h1 style="font-size: 22px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">Shop Reward Status & Loyalty Tracker</h1>
                    <p style="font-size: 13px; color: #64748b;">
                        Shops unlock the promotional reward upon completing <strong><?= $rewardThreshold ?> delivered orders</strong>. Track every shop's progress and reward claims.
                    </p>
                </div>

                <!-- KPI Cards -->
                <div class="rewards-stats-grid">
                    <div class="rew-stat-card">
                        <div class="rew-stat-icon" style="background: #eff6ff; color: #2563eb;">
                            <i class='bx bx-store-alt'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num"><?= $totalShopsCount ?></div>
                            <div class="rew-stat-label">Total Outlets & Shops</div>
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
                        <div class="rew-stat-icon" style="background: #fffbeb; color: #d97706;">
                            <i class='bx bx-time-five'></i>
                        </div>
                        <div>
                            <div class="rew-stat-num"><?= $inProgressCount ?></div>
                            <div class="rew-stat-label">⏳ In Progress (&lt; <?= $rewardThreshold ?> orders)</div>
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

                <!-- Filter & Search Bar -->
                <div class="rewards-filter-bar">
                    <div class="rewards-tabs-row">
                        <a href="?tab=all&search=<?= urlencode($search) ?>" class="tab-pill <?= ($tabFilter === 'all') ? 'active' : '' ?>">
                            All Shops (<?= $totalShopsCount ?>)
                        </a>
                        <a href="?tab=eligible&search=<?= urlencode($search) ?>" class="tab-pill tab-eligible <?= ($tabFilter === 'eligible') ? 'active' : '' ?>">
                            🎉 Eligible (<?= $eligibleCount ?>)
                        </a>
                        <a href="?tab=in_progress&search=<?= urlencode($search) ?>" class="tab-pill <?= ($tabFilter === 'in_progress') ? 'active' : '' ?>">
                            ⏳ In Progress (<?= $inProgressCount ?>)
                        </a>
                        <a href="?tab=claimed&search=<?= urlencode($search) ?>" class="tab-pill tab-claimed <?= ($tabFilter === 'claimed') ? 'active' : '' ?>">
                            ✅ Claimed (<?= $claimedCount ?>)
                        </a>
                    </div>

                    <form action="" method="GET">
                        <input type="hidden" name="tab" value="<?= htmlspecialchars($tabFilter) ?>">
                        <div class="search-box-wrap">
                            <i class='bx bx-search' style="color: #94a3b8;"></i>
                            <input type="search" name="search" placeholder="Search shop name or phone..." value="<?= htmlspecialchars($search) ?>">
                        </div>
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
                                    $completed = (int)$shop['completed_orders'];
                                    $status = $shop['computed_status'];
                                    $percent = (int)$shop['percent'];
                                    $phone = $shop['customer_phone'];
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($sName) ?></div>
                                            <?php if (!empty($phone)): ?>
                                                <div style="font-size: 12px; color: #64748b;">
                                                    <i class='bx bx-phone'></i> <?= htmlspecialchars($phone) ?>
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
                                            <span style="font-weight: 700; color: #0f172a; font-size: 14px;"><?= $completed ?></span>
                                            <span style="font-size: 12px; color: #64748b;"> / <?= $rewardThreshold ?> required</span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; color: #2563eb;"><?= (int)$shop['total_cases_delivered'] ?></span> Cases
                                        </td>
                                        <td style="min-width: 140px;">
                                            <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 4px; font-weight: 600;">
                                                <span><?= $completed ?> / <?= $rewardThreshold ?></span>
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
    <?php include '../../components/rewards-sidebar.php'; ?>
</body>
</html>
