<?php
/**
 * admin/shops/index.php
 * Master Shop Directory Management & Routing Center
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/activity_logger.php';

date_default_timezone_set('Asia/Kolkata');

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "shops";
$page_title = "Shop Directory & Routes";

// Fetch all active vehicles with their zones
$vehiclesList = [];
try {
    $vStmt = $pdo->query("
        SELECT v.id as vehicle_id, v.vehicle_number, v.vehicle_name, v.driver_name, v.zone_id, z.name as zone_name 
        FROM vehicles v 
        LEFT JOIN zones z ON v.zone_id = z.id 
        WHERE v.status != 'inactive' 
        ORDER BY v.vehicle_name ASC, v.vehicle_number ASC
    ");
    $vehiclesList = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- POST HANDLERS (Add, Edit, Delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $shop_name = trim($_POST['shop_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $pending_amount = (isset($_POST['pending_amount']) && is_numeric($_POST['pending_amount']) && (float)$_POST['pending_amount'] >= 0) ? (float)$_POST['pending_amount'] : 0.00;
        $vehicle_id = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;

        // Find matching zone_id from vehicle
        $zone_id = null;
        if ($vehicle_id) {
            foreach ($vehiclesList as $v) {
                if ((int)$v['vehicle_id'] === $vehicle_id) {
                    $zone_id = !empty($v['zone_id']) ? (int)$v['zone_id'] : null;
                    break;
                }
            }
        }

        if (empty($shop_name)) {
            $_SESSION['error_message'] = "Shop name cannot be empty.";
        } else {
            try {
                $ins = $pdo->prepare("
                    INSERT INTO shops (shop_name, location, phone, zone_id, vehicle_id, pending_amount) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$shop_name, $location, $phone, $zone_id, $vehicle_id, $pending_amount]);
                $newId = (int)$pdo->lastInsertId();
                quickLog($pdo, 'create', 'shop', $newId, "Added new shop: {$shop_name} ({$location})");
                $_SESSION['success_message'] = "Shop '{$shop_name}' added successfully!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Failed to add shop: " . $e->getMessage();
            }
        }
        header("Location: index.php");
        exit;
    }

    if ($action === 'edit') {
        $shop_id = (int)($_POST['shop_id'] ?? 0);
        $shop_name = trim($_POST['shop_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $pending_amount = (isset($_POST['pending_amount']) && is_numeric($_POST['pending_amount']) && (float)$_POST['pending_amount'] >= 0) ? (float)$_POST['pending_amount'] : 0.00;
        $vehicle_id = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;

        $zone_id = null;
        if ($vehicle_id) {
            foreach ($vehiclesList as $v) {
                if ((int)$v['vehicle_id'] === $vehicle_id) {
                    $zone_id = !empty($v['zone_id']) ? (int)$v['zone_id'] : null;
                    break;
                }
            }
        }

        if ($shop_id <= 0 || empty($shop_name)) {
            $_SESSION['error_message'] = "Invalid shop details.";
        } else {
            try {
                $upd = $pdo->prepare("
                    UPDATE shops 
                    SET shop_name = ?, location = ?, phone = ?, zone_id = ?, vehicle_id = ?, pending_amount = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $upd->execute([$shop_name, $location, $phone, $zone_id, $vehicle_id, $pending_amount, $shop_id]);
                quickLog($pdo, 'update', 'shop', $shop_id, "Updated shop ID {$shop_id}: {$shop_name}");
                $_SESSION['success_message'] = "Shop '{$shop_name}' updated successfully!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Failed to update shop: " . $e->getMessage();
            }
        }
        header("Location: index.php");
        exit;
    }

    if ($action === 'delete') {
        $shop_id = (int)($_POST['shop_id'] ?? 0);
        if ($shop_id > 0) {
            try {
                $del = $pdo->prepare("DELETE FROM shops WHERE id = ?");
                $del->execute([$shop_id]);
                quickLog($pdo, 'delete', 'shop', $shop_id, "Deleted shop ID {$shop_id}");
                $_SESSION['success_message'] = "Shop deleted successfully!";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Failed to delete shop: " . $e->getMessage();
            }
        }
        header("Location: index.php");
        exit;
    }
}

// --- FILTER & SEARCH ---
$search = trim($_GET['search'] ?? '');
$vehicleFilter = isset($_GET['vehicle_id']) && $_GET['vehicle_id'] !== '' ? $_GET['vehicle_id'] : 'all';

$params = [];
$whereClauses = ["1=1"];

if (!empty($search)) {
    $whereClauses[] = "(s.shop_name LIKE ? OR s.location LIKE ? OR s.phone LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($vehicleFilter !== 'all') {
    if ($vehicleFilter === 'unassigned') {
        $whereClauses[] = "(s.vehicle_id IS NULL OR s.vehicle_id = 0)";
    } else {
        $whereClauses[] = "s.vehicle_id = ?";
        $params[] = (int)$vehicleFilter;
    }
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch Shops
$shops = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               v.vehicle_number, v.vehicle_name, v.driver_name,
               z.name as zone_name
        FROM shops s
        LEFT JOIN vehicles v ON s.vehicle_id = v.id
        LEFT JOIN zones z ON s.zone_id = z.id
        WHERE {$whereSql}
        ORDER BY s.id DESC
    ");
    $stmt->execute($params);
    $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stats
$totalShops = 0;
$totalPendingDues = 0.0;
$shopsWithDuesCount = 0;
$vehicleStats = [];
try {
    $totalShops = (int)$pdo->query("SELECT COUNT(*) FROM shops")->fetchColumn();
    $totalPendingDues = (float)$pdo->query("SELECT COALESCE(SUM(pending_amount), 0) FROM shops")->fetchColumn();
    $shopsWithDuesCount = (int)$pdo->query("SELECT COUNT(*) FROM shops WHERE pending_amount > 0")->fetchColumn();
    $vStatsStmt = $pdo->query("
        SELECT vehicle_id, COUNT(*) as cnt 
        FROM shops 
        GROUP BY vehicle_id
    ");
    while ($row = $vStatsStmt->fetch(PDO::FETCH_ASSOC)) {
        $vId = $row['vehicle_id'] ? (int)$row['vehicle_id'] : 0;
        $vehicleStats[$vId] = (int)$row['cnt'];
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Admin Panel</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        .shop-hero-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .shop-hero-title h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .shop-hero-title p {
            font-size: 13px;
            color: #64748b;
            margin: 4px 0 0 0;
        }
        .shop-hero-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-act-primary {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-act-primary:hover { background: #1d4ed8; color: #fff; }

        .btn-act-import {
            background: #10b981;
            color: #ffffff;
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(16,185,129,0.2);
            transition: background 0.15s ease;
        }
        .btn-act-import:hover { background: #059669; color: #fff; }

        .btn-act-outline {
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
            text-decoration: none;
            cursor: pointer;
        }
        .btn-act-outline:hover { background: #f8fafc; border-color: #94a3b8; }

        /* Stats Cards Row */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .stat-card-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.15s, border-color 0.15s;
        }
        .stat-card-box:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        .stat-num {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }

        /* Filter Row */
        .shop-filter-bar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .search-pill-input {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 12px;
            min-width: 280px;
        }
        .search-pill-input input {
            border: none;
            background: transparent;
            outline: none;
            font-size: 13px;
            width: 100%;
        }

        /* Table */
        .shops-card-table {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .table-responsive {
            overflow-x: auto;
        }
        table.s-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }
        table.s-table th {
            background: #f8fafc;
            padding: 12px 16px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        table.s-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        table.s-table tr:hover td {
            background: #f8fafc;
        }

        .badge-veh {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-veh-ace { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-veh-intra { background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; }
        .badge-veh-pickup { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .badge-veh-unassigned { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* Modal */
        .s-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .s-modal.active { display: flex; }
        .s-modal-box {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            overflow: hidden;
            animation: popIn 0.2s ease;
        }
        @keyframes popIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .s-modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .s-modal-header h3 {
            font-size: 16px;
            font-weight: 800;
            margin: 0;
            color: #0f172a;
        }
        .s-modal-body {
            padding: 20px;
        }
        .s-form-group {
            margin-bottom: 16px;
        }
        .s-form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .s-form-input, .s-form-select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            color: #0f172a;
            box-sizing: border-box;
            outline: none;
        }
        .s-form-input:focus, .s-form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .s-modal-footer {
            padding: 14px 20px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
                    <span>Shops Master Directory</span>
                </div>
            </div>

            <div class="content-area">
                <!-- Notifications -->
                <?php if (!empty($success_message)): ?>
                    <div style="background: #ecfdf5; border-left: 4px solid #10b981; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-check-circle' style="font-size: 20px;"></i>
                            <span><?= htmlspecialchars($success_message) ?></span>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #065f46;">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div style="background: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class='bx bxs-error-circle' style="font-size: 20px;"></i>
                            <span><?= htmlspecialchars($error_message) ?></span>
                        </div>
                        <button type="button" onclick="this.parentElement.remove()" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #991b1b;">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Hero Section -->
                <div class="shop-hero-card">
                    <div class="shop-hero-title">
                        <h1>
                            <i class='bx bxs-store-alt' style="color: #2563eb; font-size: 26px;"></i>
                            Shops & Routes Directory
                        </h1>
                        <p>Manage customer shops, assigned delivery vehicles, and territorial routes.</p>
                    </div>
                    <div class="shop-hero-actions">
                        <a href="import.php" class="btn-act-import" title="Upload shop list with auto zone detection">
                            <i class='bx bx-cloud-upload' style="font-size: 18px;"></i> Import CSV / Excel
                        </a>
                        <button type="button" class="btn-act-primary" onclick="openAddModal()">
                            <i class='bx bx-plus' style="font-size: 18px;"></i> Add Shop
                        </button>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <a href="index.php" class="stat-card-box" style="<?= $vehicleFilter === 'all' ? 'border-color: #2563eb; background: #f8fafc;' : '' ?>">
                        <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">
                            <i class='bx bxs-store'></i>
                        </div>
                        <div>
                            <div class="stat-num"><?= $totalShops ?></div>
                            <div class="stat-label">Total Registered Shops</div>
                        </div>
                    </a>

                    <!-- Outstanding Creditors / Pending Dues Summary Card -->
                    <div class="stat-card-box" style="border-color: #fecaca; background: #fff5f5;" title="Total unpaid pending amounts across all registered shops">
                        <div class="stat-icon" style="background: #fee2e2; color: #dc2626;">
                            <i class='bx bx-error-circle'></i>
                        </div>
                        <div>
                            <div class="stat-num" style="color: #b91c1c;">₹<?= number_format($totalPendingDues, 2) ?></div>
                            <div class="stat-label">Pending Dues (<?= $shopsWithDuesCount ?> shops)</div>
                        </div>
                    </div>

                    <?php foreach ($vehiclesList as $v): 
                        $vId = (int)$v['vehicle_id'];
                        $vCount = $vehicleStats[$vId] ?? 0;
                        $colorBg = '#eff6ff'; $colorFg = '#1d4ed8';
                        if (stripos($v['vehicle_name'], 'intra') !== false) {
                            $colorBg = '#faf5ff'; $colorFg = '#7e22ce';
                        } elseif (stripos($v['vehicle_name'], 'pickup') !== false) {
                            $colorBg = '#ecfdf5'; $colorFg = '#047857';
                        }
                    ?>
                        <a href="index.php?vehicle_id=<?= $vId ?>" class="stat-card-box" style="<?= $vehicleFilter == $vId ? 'border-color: #2563eb; background: #f8fafc;' : '' ?>">
                            <div class="stat-icon" style="background: <?= $colorBg ?>; color: <?= $colorFg ?>;">
                                <i class='bx bxs-truck'></i>
                            </div>
                            <div>
                                <div class="stat-num"><?= $vCount ?></div>
                                <div class="stat-label"><?= htmlspecialchars($v['vehicle_name']) ?> (<?= htmlspecialchars($v['vehicle_number']) ?>)</div>
                            </div>
                        </a>
                    <?php endforeach; ?>

                    <?php 
                    $unassignedCount = $vehicleStats[0] ?? 0;
                    if ($unassignedCount > 0): ?>
                        <a href="index.php?vehicle_id=unassigned" class="stat-card-box" style="<?= $vehicleFilter === 'unassigned' ? 'border-color: #ef4444; background: #fff5f5;' : '' ?>">
                            <div class="stat-icon" style="background: #fef2f2; color: #ef4444;">
                                <i class='bx bx-question-mark'></i>
                            </div>
                            <div>
                                <div class="stat-num" style="color: #ef4444;"><?= $unassignedCount ?></div>
                                <div class="stat-label">Unassigned Vehicle</div>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Filter & Search Bar -->
                <div class="shop-filter-bar">
                    <form method="GET" action="index.php" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%; justify-content: space-between; align-items: center;">
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                            <div class="search-pill-input">
                                <i class='bx bx-search' style="color: #64748b; font-size: 16px;"></i>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search shop name, location, phone...">
                            </div>

                            <select name="vehicle_id" onchange="this.form.submit()" class="s-form-select" style="width: auto; padding: 7px 12px;">
                                <option value="all" <?= $vehicleFilter === 'all' ? 'selected' : '' ?>>All Vehicles & Routes</option>
                                <?php foreach ($vehiclesList as $v): ?>
                                    <option value="<?= $v['vehicle_id'] ?>" <?= $vehicleFilter == $v['vehicle_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($v['vehicle_name']) ?> (<?= htmlspecialchars($v['vehicle_number']) ?>) - <?= htmlspecialchars($v['zone_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="unassigned" <?= $vehicleFilter === 'unassigned' ? 'selected' : '' ?>>⚠️ Unassigned Route</option>
                            </select>

                            <button type="submit" class="btn-act-outline">
                                Filter
                            </button>

                            <?php if (!empty($search) || $vehicleFilter !== 'all'): ?>
                                <a href="index.php" class="btn-act-outline" style="color: #ef4444;" title="Reset filters">
                                    <i class='bx bx-x'></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>

                        <div style="font-size: 12px; font-weight: 700; color: #64748b;">
                            Showing <?= count($shops) ?> of <?= $totalShops ?> shops
                        </div>
                    </form>
                </div>

                <!-- Shops Table -->
                <div class="shops-card-table">
                    <div class="table-responsive">
                        <table class="s-table">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>Shop Name</th>
                                    <th>Location / Area</th>
                                    <th>Phone</th>
                                    <th>Assigned Vehicle</th>
                                    <th>Territory / Route</th>
                                    <th style="text-align: right; width: 135px;">Pending Due</th>
                                    <th style="text-align: right; width: 110px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($shops)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 40px 20px; color: #64748b;">
                                            <i class='bx bx-store-alt' style="font-size: 40px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                            <strong>No shops found matching your criteria.</strong>
                                            <div style="margin-top: 8px;">
                                                <a href="import.php" class="btn-act-import" style="font-size: 12px; padding: 6px 12px;">Import from CSV/Excel</a>
                                                or 
                                                <button type="button" class="btn-act-primary" onclick="openAddModal()" style="font-size: 12px; padding: 6px 12px;">Add Shop Manually</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($shops as $idx => $s): 
                                        $vName = $s['vehicle_name'] ?: '';
                                        $vNum = $s['vehicle_number'] ?: '';
                                        $zName = $s['zone_name'] ?: 'Unassigned Zone';

                                        $badgeClass = 'badge-veh-unassigned';
                                        if (stripos($vName, 'ace') !== false) {
                                            $badgeClass = 'badge-veh-ace';
                                        } elseif (stripos($vName, 'intra') !== false) {
                                            $badgeClass = 'badge-veh-intra';
                                        } elseif (stripos($vName, 'pickup') !== false) {
                                            $badgeClass = 'badge-veh-pickup';
                                        }
                                    ?>
                                        <tr>
                                            <td style="color: #94a3b8; font-weight: 600;"><?= $idx + 1 ?></td>
                                            <td>
                                                <strong style="color: #0f172a; font-size: 13.5px;"><?= htmlspecialchars($s['shop_name']) ?></strong>
                                            </td>
                                            <td>
                                                <span style="display: inline-flex; align-items: center; gap: 4px; color: #334155; font-weight: 500;">
                                                    <i class='bx bx-map-pin' style="color: #64748b;"></i>
                                                    <?= htmlspecialchars($s['location'] ?: '—') ?>
                                                </span>
                                            </td>
                                            <td style="color: #475569;">
                                                <?= htmlspecialchars($s['phone'] ?: '—') ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($vName)): ?>
                                                    <span class="badge-veh <?= $badgeClass ?>">
                                                        <i class='bx bxs-truck'></i>
                                                        <?= htmlspecialchars($vName) ?> (<?= htmlspecialchars($vNum) ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-veh badge-veh-unassigned">
                                                        <i class='bx bx-error-circle'></i> Unassigned
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span style="font-size: 12px; color: #64748b;">
                                                    <?= htmlspecialchars($zName) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right;">
                                                <?php if ((float)($s['pending_amount'] ?? 0) > 0): ?>
                                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; background: #fef2f2; color: #b91c1c; font-weight: 800; font-size: 12.5px; border: 1px solid #fecaca;" title="Unpaid pending due">
                                                        <i class='bx bxs-error-circle'></i> ₹<?= number_format((float)$s['pending_amount'], 2) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8; font-size: 12px; font-weight: 500;">₹0.00</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <button type="button" 
                                                        class="btn-act-outline" 
                                                        style="padding: 4px 8px; font-size: 12px;"
                                                        onclick='openEditModal(<?= json_encode($s) ?>)'
                                                        title="Edit Shop">
                                                    <i class='bx bx-edit-alt'></i>
                                                </button>

                                                <form method="POST" action="index.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete \'<?= htmlspecialchars(addslashes($s['shop_name'])) ?>\'?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="shop_id" value="<?= $s['id'] ?>">
                                                    <button type="submit" class="btn-act-outline" style="padding: 4px 8px; font-size: 12px; color: #ef4444;" title="Delete Shop">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Modal: Add Shop -->
    <div class="s-modal" id="addShopModal">
        <div class="s-modal-box">
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="add">
                <div class="s-modal-header">
                    <h3><i class='bx bx-plus-circle' style="color: #2563eb; vertical-align: middle;"></i> Add New Shop</h3>
                    <button type="button" onclick="closeModal('addShopModal')" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="s-modal-body">
                    <div class="s-form-group">
                        <label>Shop / Customer Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="shop_name" class="s-form-input" required placeholder="e.g. Madeena Super Bazar">
                    </div>
                    <div class="s-form-group">
                        <label>Location / Area</label>
                        <input type="text" name="location" id="add_location" class="s-form-input" placeholder="e.g. Yenepoya, Thokkotu, Mudipu">
                    </div>
                    <div class="s-form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" class="s-form-input" placeholder="e.g. 9876543210">
                    </div>
                    <div class="s-form-group">
                        <label>Pending / Due Amount (₹)</label>
                        <input type="number" step="0.01" min="0" name="pending_amount" class="s-form-input" placeholder="0.00" value="0.00">
                    </div>
                    <div class="s-form-group">
                        <label>Assigned Delivery Vehicle & Route</label>
                        <select name="vehicle_id" id="add_vehicle_id" class="s-form-select">
                            <option value="">-- Choose Vehicle / Route --</option>
                            <?php foreach ($vehiclesList as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>">
                                    <?= htmlspecialchars($v['vehicle_name']) ?> (<?= htmlspecialchars($v['vehicle_number']) ?>) - <?= htmlspecialchars($v['zone_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="s-modal-footer">
                    <button type="button" class="btn-act-outline" onclick="closeModal('addShopModal')">Cancel</button>
                    <button type="submit" class="btn-act-primary">Save Shop</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Shop -->
    <div class="s-modal" id="editShopModal">
        <div class="s-modal-box">
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="shop_id" id="edit_shop_id">
                <div class="s-modal-header">
                    <h3><i class='bx bx-edit-alt' style="color: #2563eb; vertical-align: middle;"></i> Edit Shop</h3>
                    <button type="button" onclick="closeModal('editShopModal')" style="background: none; border: none; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                <div class="s-modal-body">
                    <div class="s-form-group">
                        <label>Shop / Customer Name <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="shop_name" id="edit_shop_name" class="s-form-input" required>
                    </div>
                    <div class="s-form-group">
                        <label>Location / Area</label>
                        <input type="text" name="location" id="edit_location" class="s-form-input">
                    </div>
                    <div class="s-form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="edit_phone" class="s-form-input">
                    </div>
                    <div class="s-form-group">
                        <label>Pending / Due Amount (₹)</label>
                        <input type="number" step="0.01" min="0" name="pending_amount" id="edit_pending_amount" class="s-form-input" placeholder="0.00">
                    </div>
                    <div class="s-form-group">
                        <label>Assigned Delivery Vehicle & Route</label>
                        <select name="vehicle_id" id="edit_vehicle_id" class="s-form-select">
                            <option value="">-- Choose Vehicle / Route --</option>
                            <?php foreach ($vehiclesList as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>">
                                    <?= htmlspecialchars($v['vehicle_name']) ?> (<?= htmlspecialchars($v['vehicle_number']) ?>) - <?= htmlspecialchars($v['zone_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="s-modal-footer">
                    <button type="button" class="btn-act-outline" onclick="closeModal('editShopModal')">Cancel</button>
                    <button type="submit" class="btn-act-primary">Update Shop</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('addShopModal').classList.add('active');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        function openEditModal(shop) {
            document.getElementById('edit_shop_id').value = shop.id;
            document.getElementById('edit_shop_name').value = shop.shop_name || '';
            document.getElementById('edit_location').value = shop.location || '';
            document.getElementById('edit_phone').value = shop.phone || '';
            document.getElementById('edit_pending_amount').value = shop.pending_amount || '0.00';
            document.getElementById('edit_vehicle_id').value = shop.vehicle_id || '';
            document.getElementById('editShopModal').classList.add('active');
        }
        window.onclick = function(e) {
            if (e.target.classList.contains('s-modal')) {
                e.target.classList.remove('active');
            }
        };
    </script>
</body>
</html>
