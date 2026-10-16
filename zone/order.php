<?php
require_once __DIR__ . '/zone_context.php';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Enforce zone isolation: WHERE o.order_id = ? AND o.zone_id = ?
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        p.product_name,
        p.name as fallback_product_name,
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
        r.created_at as receipt_date,
        COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1)) AS resolved_vehicle_id,
        veh.vehicle_number AS assigned_vehicle_number,
        veh.vehicle_name AS assigned_vehicle_name
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN vehicles veh ON veh.id = COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1))
    WHERE o.order_id = ? AND o.zone_id = ?
");
$stmt->execute([$order_id, $zone_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo renderZoneNotFound("Order not found in " . htmlspecialchars($zone_name) . ".");
    exit;
}

// Available active vehicles operating in this zone
$vZoneStmt = $pdo->prepare("
    SELECT DISTINCT v.id, v.vehicle_number, v.vehicle_name, v.driver_name, v.driver_phone 
    FROM vehicles v
    WHERE v.status != 'inactive' 
      AND (v.zone_id = ? 
          OR v.id IN (SELECT vehicle_id FROM vehicle_loads WHERE zone_id = ? AND is_active = 1) 
          OR v.id IN (SELECT vehicle_id FROM orders WHERE zone_id = ? AND vehicle_id IS NOT NULL))
    ORDER BY v.vehicle_number ASC
");
$vZoneStmt->execute([$zone_id, $zone_id, $zone_id]);
$availVehicles = $vZoneStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Mark order as read/checked for zone notification system
if ((int)$order['is_zone_read'] === 0) {
    $markRead = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE order_id = ?");
    $markRead->execute([$order_id]);
}

$shopName = $order['shop_name'] ?: ($order['customer_name'] ?: 'Customer');
$rewardInfo = getShopRewardInfo($pdo, $shopName);
$priorDues = getShopPendingDueDetails($pdo, $shopName, $order['phone'] ?? '', $order_id, $zone_id);

$msg = '';
$error_msg = '';

if (!empty($_SESSION['zone_order_flash'])) {
    $msg = $_SESSION['zone_order_flash']['msg'] ?? '';
    $error_msg = $_SESSION['zone_order_flash']['error_msg'] ?? '';
    unset($_SESSION['zone_order_flash']);
}

$redirectCurrentZoneOrder = function() use ($zone_slug, $order_id) {
    header("Location: " . zone_url($zone_slug, 'order', ['id' => $order_id]));
    exit;
};

// Handle vehicle assignment from order view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_vehicle'])) {
    $newVehId = (int)($_POST['vehicle_id'] ?? 0);
    if ($newVehId > 0) {
        $res = assignOrderToVehicle($pdo, $order_id, $newVehId);
        if (!empty($res['success'])) {
            $_SESSION['zone_order_flash'] = ['msg' => "Vehicle assigned successfully.", 'error_msg' => ''];
        } else {
            $_SESSION['zone_order_flash'] = ['msg' => '', 'error_msg' => $res['error'] ?? "Failed to assign vehicle."];
        }
    } else {
        unassignOrderFromVehicle($pdo, $order_id);
        $_SESSION['zone_order_flash'] = ['msg' => "Vehicle unassigned from order.", 'error_msg' => ''];
    }
    $redirectCurrentZoneOrder();
}

// Handle quick settlement of prior dues directly from order view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_prior_due'])) {
    $clearAmount = (float)($_POST['prior_clear_amount'] ?? 0);
    $clearMode = trim($_POST['prior_clear_mode'] ?? 'cash');
    $clearNotes = trim($_POST['prior_clear_notes'] ?? '');
    if ($clearAmount > 0) {
        $refNote = "Direct settlement from Order #" . ($order['order_number'] ?: $order_id);
        if (!empty($clearNotes)) {
            $refNote .= " - Note: " . $clearNotes;
        }
        $settleRes = settleShopPriorDuesFifo($pdo, $shopName, $order['phone'] ?? '', $clearAmount, $clearMode, $refNote, $order_id, $zone_id);
        if (!empty($settleRes['success'])) {
            $_SESSION['zone_order_flash'] = [
                'msg' => "Successfully cleared ₹" . number_format($settleRes['total_cleared'], 2) . " of earlier dues via " . ucfirst($clearMode) . "! Remaining prior due: ₹" . number_format($settleRes['remaining_balance'], 2),
                'error_msg' => ''
            ];
        } else {
            $_SESSION['zone_order_flash'] = ['msg' => '', 'error_msg' => "Could not settle prior due balance."];
        }
    }
    $redirectCurrentZoneOrder();
}

// Update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'] ?? '';
    if (in_array($new_status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
        $old_status = $order['status'];
        $up = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ? AND zone_id = ?");
        $up->execute([$new_status, $order_id, $zone_id]);
        handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);
        $order['status'] = $new_status;

        if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
            updateShopRewardProgress($pdo, $shopName, $order['phone']);
        }
        $_SESSION['zone_order_flash'] = ['msg' => "Order status updated to " . ucfirst($new_status), 'error_msg' => ''];
    }
    $redirectCurrentZoneOrder();
}

$displayOrderNum = $order['order_number'] ?: ('#' . $order['order_id']);
$order_items = getOrderItems($pdo, $order_id);
$totalCases = 0;
$itemsBaseTotal = 0.0;
$itemsListStr = [];
foreach ($order_items as $oi) {
    $totalCases += (int)$oi['quantity'];
    $itemsBaseTotal += (float)$oi['line_total'];
    $pShort = $oi['product_name'] ?: ($oi['name'] ?: 'Water');
    $itemsListStr[] = "{$pShort} ({$oi['quantity']} cs)";
}
if ($itemsBaseTotal <= 0 && (float)$order['total_amount'] > 0) {
    $itemsBaseTotal = (float)$order['total_amount'] + (float)$order['discount'];
}
$baseAmount = $itemsBaseTotal;
$prodDisplayName = !empty($itemsListStr) ? implode(', ', $itemsListStr) : ($order['product_name'] ?: ($order['fallback_product_name'] ?: 'Liyas Mineral Water'));
$currentStatus = strtolower($order['status']);

// Helper for WhatsApp
$cleanPhone = preg_replace('/[^0-9]/', '', $order['phone'] ?? '');
if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;
$waMessage = "Hello {$shopName}, this is Liyas Water Delivery regarding Order #{$displayOrderNum} [{$prodDisplayName}] (Total: " . formatCurrency($order['total_amount']) . ").";
$waUrl = !empty($cleanPhone) ? "https://wa.me/{$cleanPhone}?text=" . rawurlencode($waMessage) : '';
$mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode(($order['location'] ?: 'Area') . ' ' . $zone_name);

// Status index for progress bar
$statusSteps = ['pending' => 1, 'processing' => 2, 'shipped' => 3, 'delivered' => 4];
$stepLevel = $statusSteps[$currentStatus] ?? 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Order <?= htmlspecialchars($displayOrderNum) ?> - <?= htmlspecialchars($zone_name) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #059669;
            --warning: #d97706;
            --danger: #dc2626;
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
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
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
            padding-bottom: 80px;
        }

        /* Top Nav */
        .order-nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 12px 16px;
        }

        .nav-inner {
            max-width: 720px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .back-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 8px;
            background: #eff6ff;
        }

        .back-link:hover {
            background: #dbeafe;
        }

        .container {
            max-width: 720px;
            margin: 16px auto;
            padding: 0 16px;
        }

        /* Flash Message */
        .flash-msg {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Cards */
        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-subtle);
        }

        .card-title {
            font-size: 15px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 8px;
        }

        /* Order Main Title Header */
        .order-title-box {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }

        .shop-heading {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }

        .order-subheading {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .status-pending { background: #fef3c7; color: #b45309; }
        .status-processing { background: #e0f2fe; color: #0369a1; }
        .status-shipped { background: #ede9fe; color: #6d28d9; }
        .status-delivered { background: #dcfce7; color: #15803d; }
        .status-cancelled { background: #fee2e2; color: #b91c1c; }

        /* Stepper */
        .stepper-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            margin: 20px 0 10px 0;
            padding: 0 8px;
        }

        .stepper-progress-bg {
            position: absolute;
            top: 15px;
            left: 20px;
            right: 20px;
            height: 4px;
            background: #e2e8f0;
            z-index: 1;
        }

        .stepper-progress-fill {
            position: absolute;
            top: 15px;
            left: 20px;
            height: 4px;
            background: #2563eb;
            z-index: 2;
            transition: width 0.3s ease;
        }

        .step-node {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #ffffff;
            border: 2px solid #cbd5e1;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            transition: all 0.2s ease;
        }

        .step-node.active .step-circle {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
            box-shadow: 0 0 0 4px rgba(37,99,235,0.15);
        }

        .step-node.completed .step-circle {
            background: #059669;
            border-color: #059669;
            color: #ffffff;
        }

        .step-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .step-node.active .step-label {
            color: #2563eb;
        }

        /* Contact Action Buttons */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 12px;
        }

        .btn-contact {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 10px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            text-align: center;
        }

        .btn-contact-call { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .btn-contact-call:hover { background: #dbeafe; }

        .btn-contact-wa { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
        .btn-contact-wa:hover { background: #d1fae5; }

        .btn-contact-map { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .btn-contact-map:hover { background: #fee2e2; }

        /* Item Row */
        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
            border-bottom: 1px dashed #f1f5f9;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .lbl { color: var(--text-muted); font-weight: 500; }
        .val { color: #0f172a; font-weight: 700; text-align: right; }

        /* Reward Banner */
        .reward-card {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #86efac;
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .reward-icon-box {
            width: 48px;
            height: 48px;
            background: #16a34a;
            color: #fff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }

        .progress-bar-bg {
            background: rgba(0, 0, 0, 0.08);
            border-radius: 8px;
            height: 8px;
            margin-top: 6px;
            overflow: hidden;
        }

        .progress-bar-fill {
            background: #16a34a;
            height: 100%;
            border-radius: 8px;
        }

        /* Large Primary Action Button */
        .btn-action-large {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            border: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
            transition: all 0.15s ease;
        }

        .btn-primary-green {
            background: #059669;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(5,150,105,0.25);
        }

        .btn-primary-green:hover { background: #047857; transform: translateY(-1px); }

        .btn-primary-blue {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
        }

        .btn-primary-blue:hover { background: #1d4ed8; transform: translateY(-1px); }

        /* Status Update Form */
        .status-form-group {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .status-select-control {
            flex: 1;
            padding: 11px 14px;
            border-radius: 10px;
            border: 1.5px solid var(--border-color);
            font-size: 14px;
            font-weight: 700;
            background: #ffffff;
            font-family: inherit;
        }

        .status-select-control:focus {
            outline: none;
            border-color: #2563eb;
        }

        .btn-update-status {
            padding: 11px 20px;
            border-radius: 10px;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <nav class="order-nav">
        <div class="nav-inner">
            <a href="<?= zone_url($zone_slug) ?>" class="back-link">
                <i class='bx bx-arrow-back'></i> Portal
            </a>
            <span style="font-size: 13px; font-weight: 700; color: #2563eb;">
                <?= htmlspecialchars($displayOrderNum) ?>
            </span>
        </div>
    </nav>

    <main class="container">
        <?php if (!empty($msg)): ?>
            <div class="flash-msg">
                <i class='bx bx-check-circle' style="font-size: 20px; color: #059669;"></i>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="flash-msg" style="background: #fee2e2; border-color: #fecaca; color: #991b1b;">
                <i class='bx bx-error-circle' style="font-size: 20px; color: #dc2626;"></i>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- Order Header & Live Stepper -->
        <section class="info-card">
            <div class="order-title-box">
                <div>
                    <h1 class="shop-heading"><?= htmlspecialchars($shopName) ?></h1>
                    <div class="order-subheading">
                        <span style="color: #2563eb; font-weight: 700;"><?= htmlspecialchars($displayOrderNum) ?></span>
                        <span>&bull;</span>
                        <span><?= date('d M Y, h:i A', strtotime($order['created_at'])) ?></span>
                    </div>
                </div>
                <div>
                    <span class="status-badge status-<?= $currentStatus ?>">
                        <?= ucfirst($currentStatus) ?>
                    </span>
                </div>
            </div>

            <!-- Delivery Progress Stepper -->
            <?php 
                $fillPercent = ($stepLevel - 1) * 33.33; 
                if ($currentStatus === 'cancelled') $fillPercent = 0;
            ?>
            <div class="stepper-container">
                <div class="stepper-progress-bg"></div>
                <div class="stepper-progress-fill" style="width: calc(<?= $fillPercent ?>% * 0.88);"></div>

                <div class="step-node <?= ($stepLevel >= 1) ? (($stepLevel > 1) ? 'completed' : 'active') : '' ?>">
                    <div class="step-circle"><i class='bx bx-check'></i></div>
                    <span class="step-label">Placed</span>
                </div>
                <div class="step-node <?= ($stepLevel >= 2) ? (($stepLevel > 2) ? 'completed' : 'active') : '' ?>">
                    <div class="step-circle"><i class='bx bx-cog'></i></div>
                    <span class="step-label">Packed</span>
                </div>
                <div class="step-node <?= ($stepLevel >= 3) ? (($stepLevel > 3) ? 'completed' : 'active') : '' ?>">
                    <div class="step-circle"><i class='bx bx-archive-out'></i></div>
                    <span class="step-label">Shipped</span>
                </div>
                <div class="step-node <?= ($stepLevel >= 4) ? 'completed' : '' ?>">
                    <div class="step-circle"><i class='bx bx-package'></i></div>
                    <span class="step-label">Delivered</span>
                </div>
            </div>
        </section>

        <!-- Customer & Contact Details Card -->
        <section class="info-card">
            <h2 class="card-title">
                <i class='bx bx-user-pin' style="color: #2563eb; font-size: 18px;"></i> Customer &amp; Location
            </h2>

            <div class="item-row">
                <span class="lbl">Shop / Client:</span>
                <span class="val"><?= htmlspecialchars($shopName) ?></span>
            </div>
            <?php if (!empty($order['customer_name']) && $order['customer_name'] !== $order['shop_name']): ?>
            <div class="item-row">
                <span class="lbl">Contact Person:</span>
                <span class="val"><?= htmlspecialchars($order['customer_name']) ?></span>
            </div>
            <?php endif; ?>
            <div class="item-row">
                <span class="lbl">Location / Area:</span>
                <span class="val"><?= htmlspecialchars($order['location'] ?: 'Zone Area') ?></span>
            </div>
            <div class="item-row">
                <span class="lbl">Phone Number:</span>
                <span class="val"><?= htmlspecialchars($order['phone'] ?: 'N/A') ?></span>
            </div>
            <?php if (!empty($order['address'])): ?>
            <div class="item-row">
                <span class="lbl">Detailed Address:</span>
                <span class="val"><?= nl2br(htmlspecialchars($order['address'])) ?></span>
            </div>
            <?php endif; ?>

            <div class="item-row" style="align-items: center;">
                <span class="lbl">Delivery Vehicle:</span>
                <span class="val">
                    <?php if (!empty($order['assigned_vehicle_number'])): ?>
                        <span style="font-weight: 700; color: #1e40af; display: inline-flex; align-items: center; gap: 5px;">
                            <i class='bx bxs-truck' style="color: #2563eb; font-size: 16px;"></i>
                            <?= htmlspecialchars($order['assigned_vehicle_number']) ?><?= !empty($order['assigned_vehicle_name']) ? ' (' . htmlspecialchars($order['assigned_vehicle_name']) . ')' : '' ?>
                        </span>
                    <?php else: ?>
                        <span style="background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                            <i class='bx bx-error-circle' style="color: #ef4444; font-size: 15px;"></i> Vehicle is not assigned
                        </span>
                    <?php endif; ?>
                </span>
            </div>

            <!-- Action shortcuts: Call, WhatsApp, Maps -->
            <div class="contact-grid">
                <?php if (!empty($order['phone'])): ?>
                    <a href="tel:<?= htmlspecialchars($order['phone']) ?>" class="btn-contact btn-contact-call">
                        <i class='bx bx-phone' style="font-size: 18px;"></i>
                        <span>Call</span>
                    </a>
                    <?php if (!empty($waUrl)): ?>
                        <a href="<?= $waUrl ?>" target="_blank" class="btn-contact btn-contact-wa">
                            <i class='bx bxl-whatsapp' style="font-size: 18px;"></i>
                            <span>WhatsApp</span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="<?= $mapsUrl ?>" target="_blank" class="btn-contact btn-contact-map">
                    <i class='bx bx-map' style="font-size: 18px;"></i>
                    <span>Directions</span>
                </a>
            </div>

            <!-- Vehicle Quick Assignment Form -->
            <form action="<?= zone_url($zone_slug, 'order', ['id' => $order_id]) ?>" method="POST" style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="assign_vehicle" value="1">
                <select name="vehicle_id" id="vehSelectOrder" class="status-select-control" style="flex: 1; min-width: 180px; font-size: 13px; padding: 8px 12px;">
                    <option value="">-- Assign Delivery Vehicle --</option>
                    <?php foreach ($availVehicles as $av): ?>
                        <option value="<?= $av['id'] ?>" <?= ((int)($order['resolved_vehicle_id'] ?? ($order['vehicle_id'] ?? 0)) === (int)$av['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($av['vehicle_number']) ?><?= !empty($av['vehicle_name']) ? ' (' . htmlspecialchars($av['vehicle_name']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-update-status" style="padding: 8px 16px; font-size: 13px;">
                    <?= !empty($order['assigned_vehicle_number']) ? 'Change Vehicle' : 'Assign Vehicle' ?>
                </button>
            </form>
        </section>

        <!-- Prior Unpaid Dues Card -->
        <?php if (!empty($priorDues['has_due'])): ?>
            <section class="info-card" style="border: 2px solid #fecaca; background: #fffaf0;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                    <h2 class="card-title" style="margin-bottom: 0; color: #b91c1c; font-size: 16px;">
                        <i class='bx bx-error-circle' style="color: #dc2626; font-size: 20px;"></i>
                        Earlier Due Pending: <?= formatCurrency($priorDues['total_due']) ?>
                    </h2>
                    <span style="background: #fee2e2; color: #dc2626; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px; text-transform: uppercase;">
                        <?= $priorDues['due_count'] ?> <?= $priorDues['due_count'] === 1 ? 'Previous Bill Unpaid' : 'Previous Bills Unpaid' ?>
                    </span>
                </div>
                <p style="font-size: 13px; color: #7f1d1d; margin-bottom: 12px; line-height: 1.4;">
                    This shop has unpaid dues from previous deliveries. If the shopkeeper is paying now, you can record clearance below or collect it while generating the delivery receipt.
                </p>
                <div style="background: #ffffff; border: 1px solid #fed7d7; border-radius: 8px; overflow-x: auto; margin-bottom: 14px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #fef2f2; border-bottom: 1px solid #fee2e2; text-align: left; color: #991b1b; font-size: 12px;">
                                <th style="padding: 8px 10px;">Bill #</th>
                                <th style="padding: 8px 10px;">Date</th>
                                <th style="padding: 8px 10px; text-align: right;">Bill Total</th>
                                <th style="padding: 8px 10px; text-align: right;">Outstanding Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priorDues['receipts'] as $pr): ?>
                            <tr style="border-bottom: 1px solid #f8fafc;">
                                <td style="padding: 8px 10px; font-weight: 700; color: #0f172a;">
                                    Bill #<?= htmlspecialchars($pr['bill_number']) ?>
                                    <span style="font-size: 11px; color: #64748b; font-weight: normal;">(Order #<?= htmlspecialchars($pr['order_number']) ?>)</span>
                                </td>
                                <td style="padding: 8px 10px; color: #64748b; font-size: 12px;">
                                    <?= date('d M Y', strtotime($pr['receipt_date'])) ?>
                                </td>
                                <td style="padding: 8px 10px; text-align: right; color: #475569;">
                                    <?= formatCurrency($pr['total_amount']) ?>
                                </td>
                                <td style="padding: 8px 10px; text-align: right; font-weight: 800; color: #dc2626;">
                                    <?= formatCurrency($pr['due_amount']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Quick Settlement Accordion / Form -->
                <details style="background: #ffffff; border: 1px solid #fed7d7; border-radius: 8px; padding: 12px;">
                    <summary style="font-size: 13px; font-weight: 700; color: #2563eb; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <i class='bx bx-check-shield'></i> Clear Earlier Due Directly
                    </summary>
                    <form action="<?= zone_url($zone_slug, 'order', ['id' => $order_id]) ?>" method="POST" style="margin-top: 12px;">
                        <input type="hidden" name="settle_prior_due" value="1">
                        <div style="margin-bottom: 10px;">
                            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Amount to Clear (₹):</label>
                            <input type="number" step="0.01" min="0.01" max="<?= $priorDues['total_due'] ?>" name="prior_clear_amount" value="<?= $priorDues['total_due'] ?>" required style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; font-weight: 700; box-sizing: border-box;">
                        </div>
                        <div style="margin-bottom: 10px;">
                            <label style="font-size: 12px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Payment Mode:</label>
                            <div style="display: flex; gap: 12px; font-size: 13px; font-weight: 600;">
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="radio" name="prior_clear_mode" value="cash" checked style="accent-color: #2563eb;">
                                    💵 In-Hand Cash
                                </label>
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                    <input type="radio" name="prior_clear_mode" value="upi" style="accent-color: #2563eb;">
                                    📱 UPI / Online
                                </label>
                            </div>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <input type="text" name="prior_clear_notes" placeholder="Optional reference note / remarks" style="width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                        </div>
                        <button type="submit" style="background: #059669; color: #fff; border: none; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <i class='bx bx-check'></i> Confirm Clearance
                        </button>
                    </form>
                </details>
            </section>
        <?php endif; ?>

        <!-- Shop Loyalty Reward Card -->
        <?php if ($rewardInfo): 
            $completedOrders = (int)$rewardInfo['completed_orders'];
            $threshold = (int)($rewardInfo['reward_threshold'] ?: 10);
            $rewardPercent = min(100, round(($completedOrders / $threshold) * 100));
            $isEligible = ($rewardInfo['reward_status'] === 'eligible' || $completedOrders >= $threshold);
        ?>
            <section class="reward-card">
                <div class="reward-icon-box">
                    <i class='bx bx-gift'></i>
                </div>
                <div style="flex: 1;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                        <span style="font-size: 14px; font-weight: 800; color: #166534;">Loyalty Rewards: <?= htmlspecialchars($shopName) ?></span>
                        <span style="font-size: 12px; font-weight: 700; color: #15803d;"><?= $completedOrders ?> / <?= $threshold ?> Orders</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" style="width: <?= $rewardPercent ?>%;"></div>
                    </div>
                    <div style="font-size: 12px; color: #166534; margin-top: 4px; font-weight: 600;">
                        <?php if ($isEligible): ?>
                            🎉 Shop is eligible for loyalty reward benefit!
                        <?php else: ?>
                            <?= (int)$rewardInfo['remaining_orders'] ?> more deliveries to unlock reward.
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Product & Price Breakdown -->
        <section class="info-card">
            <h2 class="card-title">
                <i class='bx bx-box' style="color: #059669; font-size: 18px;"></i> Items &amp; Pricing (<?= count($order_items) ?> <?= count($order_items) === 1 ? 'Product' : 'Products' ?>)
            </h2>

            <div style="width: 100%; overflow-x: auto; margin-bottom: 12px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">
                            <th style="padding: 8px 4px;">Item</th>
                            <th style="padding: 8px 4px; text-align: center;">Qty</th>
                            <th style="padding: 8px 4px; text-align: right;">Rate</th>
                            <th style="padding: 8px 4px; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_items as $it): 
                            $itName = htmlspecialchars($it['product_name'] ?: ($it['name'] ?: 'Liyas Water'));
                            $itQty = (int)$it['quantity'];
                            $itRate = (float)$it['price_at_purchase'];
                            $itTotal = (float)$it['line_total'];
                            $isExtra = !empty($it['is_extra']) || ($itRate == 0 && $itTotal == 0);
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 10px 4px;">
                                <strong style="color: #0f172a;"><?= $itName ?></strong>
                                <?php if ($isExtra): ?>
                                    <span style="font-size: 10px; font-weight: 800; color: #059669; background: #dcfce7; border: 1px solid #bbf7d0; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">EXTRA CASE</span>
                                <?php endif; ?>
                                <?php if (!empty($it['net_content'])): ?>
                                    <div style="font-size: 11px; color: #64748b;">
                                        <?= (float)$it['net_content'] ?> <?= htmlspecialchars($it['net_content_unit'] ?? '') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 4px; text-align: center; font-weight: 700;">
                                <?= $itQty ?> <span style="font-size: 11px; color: #64748b; font-weight: normal;">cs</span>
                            </td>
                            <td style="padding: 10px 4px; text-align: right; color: #475569;">
                                <?php if ($isExtra): ?>
                                    <span style="color: #059669; font-weight: 700;">₹0.00 (FREE)</span>
                                <?php else: ?>
                                    <?= formatCurrency($itRate) ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px 4px; text-align: right; font-weight: 700; color: #0f172a;">
                                <?php if ($isExtra): ?>
                                    <span style="color: #059669; font-weight: 700;">₹0.00</span>
                                <?php else: ?>
                                    <?= formatCurrency($itTotal) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="item-row">
                <span class="lbl">Total Ordered Cases:</span>
                <span class="val"><?= $totalCases ?> Cases</span>
            </div>
            <div class="item-row">
                <span class="lbl">Subtotal (Base Amount):</span>
                <span class="val"><?= formatCurrency($baseAmount) ?></span>
            </div>
            <?php if ((float)$order['discount'] > 0): ?>
            <div class="item-row">
                <span class="lbl">Discount Applied:</span>
                <span class="val" style="color: #dc2626;">- <?= formatCurrency($order['discount']) ?></span>
            </div>
            <?php endif; ?>
            <div class="item-row" style="border-top: 2px solid #e2e8f0; padding-top: 10px; font-size: 16px;">
                <span class="lbl" style="color: #0f172a; font-weight: 800;">Net Total Payable:</span>
                <span class="val" style="color: #2563eb; font-weight: 800;"><?= formatCurrency($order['total_amount']) ?></span>
            </div>

            <div style="margin-top: 12px; text-align: right;">
                <a href="<?= zone_url($zone_slug, 'edit-order', ['id' => $order_id]) ?>" style="font-size: 13px; color: #2563eb; text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                    <i class='bx bx-purchase-tag'></i> Edit Order Discount &rarr;
                </a>
            </div>
        </section>

        <!-- Official Delivery Receipt Section -->
        <section class="info-card">
            <h2 class="card-title">
                <i class='bx bx-receipt' style="color: #d97706; font-size: 18px;"></i> Delivery &amp; Receipt Status
            </h2>

            <?php if (!empty($order['bill_number'])): ?>
                <div style="text-align: center; padding: 8px 0;">
                    <div style="font-size: 12px; font-weight: 800; color: #059669; letter-spacing: 0.05em; text-transform: uppercase;">
                        RECEIPT GENERATED
                    </div>
                    <div style="font-size: 22px; font-weight: 800; color: #0f172a; margin: 4px 0;">
                        Bill #: <?= htmlspecialchars($order['bill_number']) ?>
                    </div>
                    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                        Delivered: <strong><?= (int)$order['delivered_quantity'] ?> cases</strong> &bull; 
                        Cash: <strong><?= formatCurrency($order['receipt_cash']) ?></strong> &bull; 
                        Due: <strong style="color: <?= ((float)$order['receipt_due'] > 0) ? '#dc2626' : '#059669' ?>;"><?= formatCurrency($order['receipt_due']) ?></strong>
                    </div>

                    <div style="display: flex; gap: 10px; flex-direction:column;">
                        <a href="<?= zone_url($zone_slug, 'receipt', ['id' => $order_id]) ?>" target="_blank" class="btn-action-large btn-primary-blue">
                            <i class='bx bx-printer' style="font-size: 20px;"></i> View &amp; Print Receipt
                        </a>
                        <?php if (!empty($waUrl)): 
                            $billMsg = "Hello {$shopName}, here is your official Liyas Water Delivery Receipt for Bill #{$order['bill_number']}.\nTotal: " . formatCurrency($order['total_amount']) . "\nDelivered: {$order['delivered_quantity']} cases\nCash Received: " . formatCurrency($order['receipt_cash']) . "\nBalance Due: " . formatCurrency($order['receipt_due']) . "\nView digital bill: " . zone_url($zone_slug, 'receipt', ['id' => $order_id]);
                            $billWa = "https://wa.me/{$cleanPhone}?text=" . rawurlencode($billMsg);
                        ?>
                            <a href="<?= $billWa ?>" target="_blank" class="btn-action-large" style="background: #059669; color: #fff;">
                                <i class='bx bxl-whatsapp' style="font-size: 20px;"></i> Send Receipt via WhatsApp
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 8px 0;">
                    <?php if (empty($order['assigned_vehicle_number']) && empty($order['resolved_vehicle_id'])): ?>
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px; margin-bottom: 12px; color: #b91c1c; font-weight: 700; text-align: center;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 14px; margin-bottom: 4px;">
                                <i class='bx bx-error-circle' style="font-size: 18px; color: #ef4444;"></i> Vehicle is not assigned
                            </div>
                            <div style="font-size: 12px; color: #7f1d1d; font-weight: 500;">
                                Please assign a vehicle to this order before generating the delivery receipt.
                            </div>
                        </div>
                        <button type="button" class="btn-action-large" style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; cursor: pointer;" onclick="const s = document.getElementById('vehSelectOrder'); if(s){ s.focus(); s.scrollIntoView({behavior:'smooth'}); s.style.boxShadow='0 0 0 4px rgba(239,68,68,0.3)'; setTimeout(()=>s.style.boxShadow='', 2000); }">
                            <i class='bx bxs-truck' style="font-size: 20px;"></i> Assign Vehicle to Bill
                        </button>
                    <?php else: ?>
                        <a href="<?= zone_url($zone_slug, 'generate-receipt', ['order_id' => $order_id]) ?>" class="btn-action-large btn-primary-green">
                            <i class='bx bx-check-shield' style="font-size: 20px;"></i> Generate Delivery Receipt
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Change Status Quick Form -->
        <section class="info-card">
            <h2 class="card-title">
                <i class='bx bx-sync' style="color: #6366f1; font-size: 18px;"></i> Update Delivery Status
            </h2>
            <form action="<?= zone_url($zone_slug, 'order', ['id' => $order_id]) ?>" method="POST">
                <input type="hidden" name="update_status" value="1">
                <div class="status-form-group">
                    <select name="status" class="status-select-control" aria-label="Order status">
                        <option value="pending" <?= ($order['status'] === 'pending') ? 'selected' : '' ?>>⏳ Pending</option>
                        <option value="processing" <?= ($order['status'] === 'processing') ? 'selected' : '' ?>>⚙️ Processing</option>
                        <option value="shipped" <?= ($order['status'] === 'shipped') ? 'selected' : '' ?>>🚚 Shipped</option>
                        <option value="delivered" <?= ($order['status'] === 'delivered') ? 'selected' : '' ?>>✅ Delivered</option>
                        <option value="cancelled" <?= ($order['status'] === 'cancelled') ? 'selected' : '' ?>>❌ Cancelled</option>
                    </select>
                    <button type="submit" class="btn-update-status">
                        Update
                    </button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
