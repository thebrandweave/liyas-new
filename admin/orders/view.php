<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "orders";
$page_title   = "Order Details";

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("
    SELECT 
        o.*,
        z.name as zone_name,
        z.slug as zone_slug,
        p.product_name,
        p.name as fallback_product_name,
        p.net_content,
        p.net_content_unit,
        p.case_price,
        u.name as web_user_name,
        u.email as web_user_email,
        r.id as receipt_id,
        r.bill_number,
        r.delivered_quantity,
        r.cash_amount as receipt_cash,
        r.credited_amount as receipt_credit,
        r.due_amount as receipt_due,
        r.created_at as receipt_date,
        COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1)) AS resolved_vehicle_id,
        veh.vehicle_number AS assigned_vehicle_number,
        veh.vehicle_name AS assigned_vehicle_name
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN users u ON o.user_id = u.user_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    LEFT JOIN vehicles veh ON veh.id = COALESCE(o.vehicle_id, (SELECT vl.vehicle_id FROM vehicle_loads vl WHERE vl.order_id = o.order_id AND vl.is_active = 1 LIMIT 1))
    WHERE o.order_id = ?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: index.php");
    exit;
}

// Check reward progress for shop
$shopName = $order['shop_name'] ?: ($order['customer_name'] ?: 'Customer');
$rewardInfo = getShopRewardInfo($pdo, $shopName);
$shopDeliverySummary = getShopDeliveredCasesSummary($pdo, $shopName);

// Update status if submitted
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'] ?? '';
    if (in_array($new_status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
        $old_status = $order['status'];
        $up = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
        $up->execute([$new_status, $order_id]);

        // Adjust stock if cancelled / un-cancelled
        handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);
        $order['status'] = $new_status;

        if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
            updateShopRewardProgress($pdo, $shopName, $order['phone']);
            $rewardInfo = getShopRewardInfo($pdo, $shopName);
        }

        quickLog($pdo, 'update_status', 'order', $order_id, "Status changed to {$new_status}");
        $msg = "Order status updated successfully!";
    }
}

// Custom Due Clearance with Inputs (Amount, Payment Mode, Ref No, Notes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_due_balance'])) {
    $rStmt = $pdo->prepare("SELECT id, due_amount, cash_amount, credited_amount, total_amount, payment_type FROM receipts WHERE order_id = ?");
    $rStmt->execute([$order_id]);
    $rec = $rStmt->fetch(PDO::FETCH_ASSOC);

    if ($rec && (float)$rec['due_amount'] > 0) {
        $currentDue = (float)$rec['due_amount'];
        $inputAmount = isset($_POST['clear_amount']) && is_numeric($_POST['clear_amount']) ? (float)$_POST['clear_amount'] : $currentDue;
        
        // Validate amount between 0.01 and current outstanding due
        $dueCleared = min($currentDue, max(0.01, $inputAmount));
        $newDue = max(0, $currentDue - $dueCleared);
        $settle_mode = trim($_POST['settle_mode'] ?? 'cash');
        $ref_no = trim($_POST['ref_no'] ?? '');
        $clear_notes = trim($_POST['clear_notes'] ?? '');

        $auditNote = " | Due of ₹" . number_format($dueCleared, 2) . " settled via " . ucfirst($settle_mode);
        if (!empty($ref_no)) { $auditNote .= " (Ref: {$ref_no})"; }
        if (!empty($clear_notes)) { $auditNote .= " - Note: {$clear_notes}"; }
        $auditNote .= " on " . date('d-m-Y H:i:s');

        $isOnlineOrCredit = in_array(strtolower($settle_mode), ['upi', 'bank', 'credit', 'online']);

        if ($isOnlineOrCredit) {
            $upRec = $pdo->prepare("
                UPDATE receipts 
                SET credited_amount = credited_amount + ?,
                    due_amount = ?,
                    payment_type = CASE WHEN cash_amount > 0 THEN 'Cash + Credited' ELSE 'Credited' END,
                    notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE id = ?
            ");
            $upRec->execute([$dueCleared, $newDue, $auditNote, $rec['id']]);
        } else {
            $upRec = $pdo->prepare("
                UPDATE receipts 
                SET cash_amount = cash_amount + ?,
                    due_amount = ?,
                    payment_type = CASE WHEN credited_amount > 0 THEN 'Cash + Credited' ELSE 'Cash' END,
                    notes = CONCAT(COALESCE(notes, ''), ?)
                WHERE id = ?
            ");
            $upRec->execute([$dueCleared, $newDue, $auditNote, $rec['id']]);
        }

        // Record in order_payments audit table
        try {
            $pPay = $pdo->prepare("
                INSERT INTO order_payments (order_id, cash_amount, credited_amount, due_amount, payment_type, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $pCash = $isOnlineOrCredit ? 0.00 : $dueCleared;
            $pCredit = $isOnlineOrCredit ? $dueCleared : 0.00;
            $pPayType = $settle_mode;
            $pPay->execute([$order_id, $pCash, $pCredit, $newDue, $pPayType]);
        } catch (Exception $e) {}

        quickLog($pdo, 'settle_due', 'receipt', $rec['id'], "Cleared ₹{$dueCleared} due for order #{$order_id} via {$settle_mode}. Remaining due: ₹{$newDue}");
        header("Location: view.php?id={$order_id}&due_cleared=" . urlencode(number_format($dueCleared, 2)) . "&remaining=" . urlencode(number_format($newDue, 2)));
        exit;
    }
}

if (isset($_GET['due_cleared'])) {
    $clearedVal = htmlspecialchars($_GET['due_cleared']);
    $remVal = htmlspecialchars($_GET['remaining'] ?? '0.00');
    $msg = "Outstanding due of ₹{$clearedVal} settled successfully! Remaining due balance: ₹{$remVal}.";
}

$displayOrderNum = $order['order_number'] ?: ('#' . $order['order_id']);
$order_items = getOrderItems($pdo, $order_id);
$totalCases = 0;
$itemsBaseTotal = 0.0;
foreach ($order_items as $oi) {
    $totalCases += (int)$oi['quantity'];
    $itemsBaseTotal += (float)$oi['line_total'];
}
if ($itemsBaseTotal <= 0 && (float)$order['total_amount'] > 0) {
    $itemsBaseTotal = (float)$order['total_amount'] + (float)$order['discount'];
}
$baseAmount = $itemsBaseTotal;
$paymentHistory = getOrderPaymentHistory($pdo, $order_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order <?= htmlspecialchars($displayOrderNum) ?> - Liyas Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .details-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 860px) {
            .details-grid { grid-template-columns: 1fr; }
        }
        .info-card {
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-heading {
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 13px;
            border-bottom: 1px dashed #f1f5f9;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; font-weight: 500; }
        .info-val { color: #1e293b; font-weight: 600; text-align: right; }
        .reward-banner {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .reward-badge-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #22c55e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }
        
/* Better column width distribution */
.payment-history-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}

.payment-history-table th:nth-child(1),
.payment-history-table td:nth-child(1) {
    width: 4%;
}

.payment-history-table th:nth-child(2),
.payment-history-table td:nth-child(2) {
    width: 16%;
}

.payment-history-table th:nth-child(3),
.payment-history-table td:nth-child(3) {
    width: 17%;
}

.payment-history-table th:nth-child(4),
.payment-history-table td:nth-child(4) {
    width: 14%;
}

.payment-history-table th:nth-child(5),
.payment-history-table td:nth-child(5) {
    width: 15%;
}

.payment-history-table th:nth-child(6),
.payment-history-table td:nth-child(6) {
    width: 34%;
}

.payment-history-table th,
.payment-history-table td {
    padding: 10px;
    vertical-align: top;
    overflow-wrap: anywhere;
    white-space: normal;
}

.payment-history-table td:nth-child(2) {
    font-size: 11px;
}

.payment-history-table td:nth-child(6) {
    line-height: 1.6;
}

        
/* Fix overflow in Payment History table */
.payment-history-table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}

.payment-history-table th,
.payment-history-table td {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: normal;
    vertical-align: top;
}

.payment-history-table .audit-notes-cell {
    width: 28%;
    max-width: 280px;
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.6;
}

.payment-history-wrapper {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <a href="index.php" style="color: inherit; text-decoration: none;">Orders</a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Order <?= htmlspecialchars($displayOrderNum) ?></span>
                    <?php if ((int)($order['is_reorder'] ?? 0) === 1): ?>
                        <span style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-left: 6px;">
                            🔁 Reorder
                        </span>
                    <?php endif; ?>
                </div>
                <div class="header-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="create.php?reorder_id=<?= $order_id ?>" class="header-btn" style="text-decoration: none; background: #2563eb; color: #fff; font-weight: 600;">
                        <i class='bx bx-refresh'></i> Place Reorder
                    </a>
                    <a href="edit.php?id=<?= $order_id ?>" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-edit'></i> Edit Order
                    </a>
                    <a href="index.php" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($msg)): ?>
                    <div class="alert alert-success" style="padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 1.5rem;">
                        <?= htmlspecialchars($msg) ?>
                    </div>
                <?php endif; ?>

                <!-- Shop Historical Delivery Cases Summary Banner -->
                <?php if (!empty($shopDeliverySummary) && $shopDeliverySummary['shop_name'] !== ''): ?>
                    <div style="background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); color: #fff; padding: 14px 20px; border-radius: 10px; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.15);">
                        <div>
                            <div style="font-size: 13px; font-weight: 700; color: #93c5fd; text-transform: uppercase; letter-spacing: 0.03em; display: flex; align-items: center; gap: 6px;">
                                <span>🏪</span> Shop Historical Delivery Cases (<?= htmlspecialchars($shopName) ?>)
                            </div>
                            <div style="font-size: 12px; color: #bfdbfe; margin-top: 3px;">
                                Fulfilled Delivered Orders: <strong><?= (int)$shopDeliverySummary['total_delivered_orders'] ?></strong> of <?= (int)$shopDeliverySummary['total_all_orders'] ?> total orders
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <div style="background: rgba(255,255,255,0.12); padding: 6px 12px; border-radius: 6px; text-align: center; min-width: 95px;">
                                <div style="font-size: 10px; text-transform: uppercase; color: #93c5fd; font-weight: 600;">Previous Orders</div>
                                <div style="font-size: 16px; font-weight: 800;"><?= number_format($shopDeliverySummary['previous_cases']) ?> <span style="font-size: 10px;">cases</span></div>
                                <div style="font-size: 9px; color: #bfdbfe;"><?= (int)$shopDeliverySummary['previous_orders_count'] ?> order(s)</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.12); padding: 6px 12px; border-radius: 6px; text-align: center; min-width: 95px;">
                                <div style="font-size: 10px; text-transform: uppercase; color: #a7f3d0; font-weight: 600;">🔁 Reorders</div>
                                <div style="font-size: 16px; font-weight: 800; color: #6ee7b7;"><?= number_format($shopDeliverySummary['reorder_cases']) ?> <span style="font-size: 10px;">cases</span></div>
                                <div style="font-size: 9px; color: #bfdbfe;"><?= (int)$shopDeliverySummary['reorders_count'] ?> reorder(s)</div>
                            </div>
                            <div style="background: rgba(16, 185, 129, 0.3); border: 1.5px solid rgba(16, 185, 129, 0.5); padding: 6px 14px; border-radius: 6px; text-align: center; min-width: 110px;">
                                <div style="font-size: 10px; text-transform: uppercase; color: #a7f3d0; font-weight: 700;">Total Delivered</div>
                                <div style="font-size: 18px; font-weight: 800; color: #fff;"><?= number_format($shopDeliverySummary['total_cases']) ?> <span style="font-size: 11px;">Cases</span></div>
                                <div style="font-size: 9px; color: #d1fae5;">Previous + Reorders</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Shop Reward Tracker Card -->
                <?php if ($rewardInfo): ?>
                    <div class="reward-banner">
                        <div class="reward-badge-icon">
                            <i class='bx bx-gift'></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 14px; font-weight: 600; color: #166534;">
                                Shop Reward Status: <?= htmlspecialchars($shopName) ?>
                            </div>
                            <div style="font-size: 13px; color: #15803d; margin-top: 2px;">
                                Completed Delivered Orders: <strong><?= (int)$rewardInfo['completed_orders'] ?> / <?= (int)$rewardInfo['reward_threshold'] ?></strong>
                                <?php if ($rewardInfo['reward_status'] === 'eligible' || $rewardInfo['completed_orders'] >= $rewardInfo['reward_threshold']): ?>
                                    — <span style="background: #22c55e; color: #fff; padding: 2px 8px; border-radius: 10px; font-weight: 600;">Reward Eligible 🎉</span>
                                <?php else: ?>
                                    — <em><?= (int)$rewardInfo['remaining_orders'] ?> more orders to unlock reward</em>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="details-grid">
                    <div>
                        <!-- Order Items Card -->
                        <div class="info-card">
                            <div class="card-heading">
                                <span>Order Items & Pricing</span>
                                <span class="badge <?= getStatusBadgeClass($order['status']) ?>">
                                    <?= ucfirst($order['status']) ?>
                                </span>
                            </div>

                            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: center; color: #64748b;">
                                        <th style="padding: 8px 4px; text-align: center;">Product</th>
                                        <th style="padding: 8px 4px; text-align: center;">Qty (Cases)</th>
                                        <th style="padding: 8px 4px; text-align: center;">Case Price</th>
                                        <th style="padding: 8px 4px; text-align: center;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($order_items as $item): 
                                        $iName = htmlspecialchars($item['product_name'] ?: ($item['name'] ?: 'Liyas Water'));
                                        $iQty = (int)$item['quantity'];
                                        $iPrice = (float)$item['price_at_purchase'];
                                        $iLineTotal = (float)$item['line_total'];
                                    ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 12px 4px; text-align: center;">
                                            <strong style="color: #1e293b;"><?= $iName ?></strong>
                                            <?php if (!empty($item['net_content'])): ?>
                                              
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px 4px; text-align: center; font-weight: 600;">
                                            <?= $iQty ?>
                                        </td>
                                        <td style="padding: 12px 4px; text-align: center;">
                                            <?= formatCurrency($iPrice) ?>
                                        </td>
                                        <td style="padding: 12px 4px; text-align: center; font-weight: 600;">
                                            <?= formatCurrency($iLineTotal) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div style="margin-top: 1.25rem; border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                                <div class="info-row">
                                    <span class="info-label">Base Order Amount:</span>
                                    <span class="info-val"><?= formatCurrency($baseAmount) ?></span>
                                </div>
                                <?php if ((float)$order['discount'] > 0): ?>
                                <div class="info-row">
                                    <span class="info-label">Discount:</span>
                                    <span class="info-val" style="color: #ef4444;">- <?= formatCurrency($order['discount']) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="info-row" style="font-size: 16px; font-weight: 600; color: #0f172a; padding-top: 8px;">
                                    <span>Net Total:</span>
                                    <span style="color: #2563eb;"><?= formatCurrency($order['total_amount']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Details Card -->
                        <div class="info-card">
                            <div class="card-heading">
                                <span>Shop & Customer Information</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Shop / Customer:</span>
                                <span class="info-val"><?= htmlspecialchars($order['shop_name'] ?: 'N/A') ?></span>
                            </div>
                          
                            <div class="info-row">
                                <span class="info-label">Phone Number:</span>
                                <span class="info-val">
                                    <a href="tel:<?= htmlspecialchars($order['phone']) ?>" style="color: #2563eb; text-decoration: none;">
                                        <?= htmlspecialchars($order['phone'] ?: 'N/A') ?>
                                    </a>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Location / Area:</span>
                                <span class="info-val"><?= htmlspecialchars($order['location'] ?: 'N/A') ?></span>
                            </div>
                          
                        </div>

                        <!-- Payment & Due Clearance Audit Ledger Card -->
                        <div class="info-card">
                            <div class="card-heading" style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="display: inline-flex; align-items: center; gap: 6px;">
                                    <i class='bx bx-history' style="font-size: 18px; color: #7c3aed;"></i> Payment &amp; Due Clearance History
                                </span>
                                <?php if (!empty($paymentHistory['is_settled'])): ?>
                                    <span style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                        ✅ Fully Settled (₹0 Due)
                                    </span>
                                <?php elseif (!empty($paymentHistory['current_due']) && $paymentHistory['current_due'] > 0): ?>
                                    <span style="background: #fee2e2; color: #dc2626; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px;">
                                        ⚠️ Due: <?= formatCurrency($paymentHistory['current_due']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Financial Summary Bar -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 1.25rem;">
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 12px;">
                                    <div style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Billed</div>
                                    <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                                        <?= formatCurrency($paymentHistory['total_order_amount'] ?? 0) ?>
                                    </div>
                                </div>
                                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 12px;">
                                    <div style="font-size: 11px; color: #166534; font-weight: 600; text-transform: uppercase;">Total Paid</div>
                                    <div style="font-size: 16px; font-weight: 700; color: #15803d; margin-top: 2px;">
                                        <?= formatCurrency($paymentHistory['total_paid'] ?? 0) ?>
                                    </div>
                                    <div style="font-size: 10px; color: #166534; margin-top: 1px;">
                                        Cash: <?= formatCurrency($paymentHistory['total_paid_cash'] ?? 0) ?> &bull; Online: <?= formatCurrency($paymentHistory['total_paid_credit'] ?? 0) ?>
                                    </div>
                                </div>
                                <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 10px 12px;">
                                    <div style="font-size: 11px; color: #9f1239; font-weight: 600; text-transform: uppercase;">Due Balance</div>
                                    <div style="font-size: 16px; font-weight: 700; color: <?= (($paymentHistory['current_due'] ?? 0) > 0) ? '#e11d48' : '#15803d' ?>; margin-top: 2px;">
                                        <?= formatCurrency($paymentHistory['current_due'] ?? 0) ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Ledger Table -->
                            <?php if (empty($paymentHistory['ledger'])): ?>
                                <div style="text-align: center; padding: 24px 10px; color: #94a3b8; font-size: 13px;">
                                    <i class='bx bx-time' style="font-size: 28px; margin-bottom: 4px;"></i>
                                    <div>No payment or delivery receipts recorded for this order yet.</div>
                                </div>
                            <?php else: ?>
<div class="payment-history-wrapper"
     style="border: 1px solid #e2e8f0; border-radius: 8px;">                              
<table class="payment-history-table"
       style="font-size: 12px; text-align: left;">    
                                           <thead>
                                            <tr style="background: #f8fafc; border-bottom: 1.5px solid #e2e8f0; color: #475569;">
                                                <th style="padding: 8px 10px; font-weight: 700;">#</th>
                                                <th style="padding: 8px 10px; font-weight: 700;">Date &amp; Time</th>
                                                <th style="padding: 8px 10px; font-weight: 700;">Event / Stage</th>
                                                <th style="padding: 8px 10px; font-weight: 700;">Amount Paid</th>
                                                <th style="padding: 8px 10px; font-weight: 700;">Remaining Due</th>
                                                <th style="padding: 8px 10px; font-weight: 700;">Audit Notes / Ref</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($paymentHistory['ledger'] as $idx => $leg): 
                                                $isInit = ($leg['type'] === 'initial_bill');
                                                $m = strtolower($leg['payment_mode'] ?? 'cash');
                                                $paidAmt = (float)$leg['amount_cleared'];
                                                $remDue = (float)$leg['remaining_due'];
                                            ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 10px; color: #64748b; font-weight: 600;"><?= $idx + 1 ?></td>
                                                <td style="padding: 10px; font-weight: 600; color: #0f172a; white-space: nowrap;">
                                                    <i class='bx bx-time-five' style="color: #94a3b8;"></i> <?= htmlspecialchars($leg['formatted_date']) ?>
                                                </td>
                                                <td style="padding: 10px;">
                                                    <?php if ($isInit): ?>
                                                        <span style="background: #eff6ff; color: #1d4ed8; font-weight: 700; padding: 2px 6px; border-radius: 4px; font-size: 11px;">
                                                            <i class='bx bx-receipt'></i> Initial Delivery Bill
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="background: #ecfdf5; color: #047857; font-weight: 700; padding: 2px 6px; border-radius: 4px; font-size: 11px;">
                                                            <i class='bx bx-check-shield'></i> Due Clearance #<?= $idx ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 10px;">
                                                    <div style="font-weight: 700; color: #047857;">
                                                        <?= formatCurrency($paidAmt) ?>
                                                    </div>
                                                    <div style="margin-top: 2px;">
                                                        <?php if ($m === 'cash'): ?>
                                                            <span style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-size: 10px; padding: 1px 5px; border-radius: 3px; font-weight: 600;">💵 Cash</span>
                                                        <?php elseif ($m === 'upi'): ?>
                                                            <span style="background: #faf5ff; color: #7e22ce; border: 1px solid #e9d5ff; font-size: 10px; padding: 1px 5px; border-radius: 3px; font-weight: 600;">📱 UPI</span>
                                                        <?php elseif ($m === 'bank'): ?>
                                                            <span style="background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; font-size: 10px; padding: 1px 5px; border-radius: 3px; font-weight: 600;">🏦 Bank</span>
                                                        <?php elseif ($m === 'credit'): ?>
                                                            <span style="background: #fdf4ff; color: #a21caf; border: 1px solid #f5d0fe; font-size: 10px; padding: 1px 5px; border-radius: 3px; font-weight: 600;">📑 Credit</span>
                                                        <?php else: ?>
                                                            <span style="background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; font-size: 10px; padding: 1px 5px; border-radius: 3px; font-weight: 600;"><?= strtoupper($m) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td style="padding: 10px; font-weight: 700; color: <?= ($remDue > 0) ? '#dc2626' : '#15803d' ?>;">
                                                    <?= ($remDue > 0) ? formatCurrency($remDue) : '₹0.00 (Cleared)' ?>
                                                </td>
                                               <td class="audit-notes-cell"
    style="padding: 10px; color: #475569; font-size: 11px;">
    <?= htmlspecialchars($leg['note'] ?: '-') ?>
</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($paymentHistory['audit_notes'])): ?>
                                <div style="margin-top: 12px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 10px 12px;">
                                    <div style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 4px;">
                                        Settlement Notes Log:
                                    </div>
                                    <ul style="margin: 0; padding-left: 14px; font-size: 11px; color: #334155;">
                                        <?php foreach ($paymentHistory['audit_notes'] as $an): ?>
                                            <li style="margin-bottom: 3px;"><?= htmlspecialchars($an) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Sidebar Panels -->
                    <div>
                        <!-- Status & Action Card -->
                        <div class="info-card">
                            <div class="card-heading">
                                <span>Order Workflow</span>
                            </div>


                            <div class="info-row">
                                <span class="info-label">Order Number:</span>
                                <span class="info-val"><?= htmlspecialchars($displayOrderNum) ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Assigned Zone:</span>
                                <span class="info-val">
                                    <?php if (!empty($order['zone_name'])): ?>
                                        <a href="<?= BASE_URL ?>/<?= htmlspecialchars($order['zone_slug']) ?>" target="_blank" style="color: #2563eb; text-decoration: none;">
                                            <?= htmlspecialchars($order['zone_name']) ?> <i class='bx bx-external-link'></i>
                                        </a>
                                    <?php else: ?>
                                        Website
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Zone Check Status:</span>
                                <span class="info-val">
                                    <?php if (!empty($order['zone_name'])): ?>
                                        <?php if ((int)$order['is_zone_read'] === 1): ?>
                                            <span style="color: #059669; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class='bx bx-check-double' style="font-size: 16px;"></i> Checked by Zone <?= !empty($order['zone_read_at']) ? ' (' . date('d M, h:i A', strtotime($order['zone_read_at'])) . ')' : '' ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #d97706; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class='bx bx-bell' style="font-size: 15px;"></i> Unchecked by Zone (Pending delivery team view)
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;">Central Order</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Assigned Vehicle:</span>
                                <span class="info-val">
                                    <?php if (!empty($order['assigned_vehicle_number'])): ?>
                                        <span style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 6px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class='bx bxs-truck' style="color: #2563eb;"></i> <?= htmlspecialchars($order['assigned_vehicle_number']) ?><?= !empty($order['assigned_vehicle_name']) ? ' (' . htmlspecialchars($order['assigned_vehicle_name']) . ')' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class='bx bx-car'></i> No Vehicle Assigned
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Created At:</span>
                                <span class="info-val"><?= date('d M Y, H:i A', strtotime($order['created_at'])) ?></span>
                            </div>
                        </div>

                        <!-- Receipt & Financials Card -->
                        <div class="info-card">
                            <div class="card-heading">
                                <span>Delivery Receipt & Payment</span>
                            </div>

                            <?php if (!empty($order['bill_number'])): ?>
                                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px; margin-bottom: 1rem; text-align: center;">
                                    <div style="font-size: 12px; color: #166534; font-weight: 600;">RECEIPT GENERATED</div>
                                    <div style="font-size: 18px; font-weight: 700; color: #15803d; margin: 4px 0;">
                                        <?= htmlspecialchars($order['bill_number']) ?>
                                    </div>
                                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($order['zone_slug'] ?: 'zone') ?>/receipt?id=<?= $order_id ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; background: #166534; color: #fff; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-top: 6px;">
                                        <i class='bx bx-printer'></i> View / Print Receipt
                                    </a>
                                </div>

                                <div class="info-row">
                                    <span class="info-label">Delivered Cases:</span>
                                    <span class="info-val"><?= (int)$order['delivered_quantity'] ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Cash Received:</span>
                                    <span class="info-val" style="color: #059669;"><?= formatCurrency($order['receipt_cash']) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Credited Amount:</span>
                                    <span class="info-val" style="color: #d97706;"><?= formatCurrency($order['receipt_credit']) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Outstanding Due:</span>
                                    <span class="info-val" style="color: <?= ((float)$order['receipt_due'] > 0) ? '#ef4444' : '#059669' ?>;">
                                        <?= formatCurrency($order['receipt_due']) ?>
                                    </span>
                                </div>

                                <?php if ((float)$order['receipt_due'] > 0): ?>
                                    <div style="margin-top: 14px; padding: 14px; background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 10px;">
                                        <div style="font-size: 13px; font-weight: 700; color: #991b1b; margin-bottom: 4px; display: flex; align-items: center; gap: 5px;">
                                            <i class='bx bx-money-withdraw' style="font-size: 16px;"></i> Custom Due Clearance
                                        </div>
                                        <div style="font-size: 11px; color: #64748b; margin-bottom: 12px;">
                                            Current outstanding due: <strong style="color: #b91c1c;"><?= formatCurrency($order['receipt_due']) ?></strong>. Enter custom partial or full amount to clear.
                                        </div>
                                        <form action="view.php?id=<?= $order_id ?>" method="POST" onsubmit="return confirm('Confirm clearing custom due amount?');">
                                            <input type="hidden" name="settle_due_balance" value="1">
                                            
                                            <div style="margin-bottom: 8px;">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #374151; margin-bottom: 3px;">
                                                    Amount to Clear (₹) <span style="color: #ef4444;">*</span>
                                                </label>
                                                <div style="display: flex; gap: 6px;">
                                                    <input type="text" step="0.01" min="0.01" max="<?= (float)$order['receipt_due'] ?>" name="clear_amount" id="clear_amount" value="<?= number_format((float)$order['receipt_due'], 2, '.', '') ?>" required style="flex: 1; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; font-weight: 700; color: #0f172a; box-sizing: border-box;">
                                                    <button type="button" onclick="document.getElementById('clear_amount').value='<?= number_format((float)$order['receipt_due'], 2, '.', '') ?>'" style="padding: 4px 8px; font-size: 11px; font-weight: 600; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; border-radius: 6px; cursor: pointer; white-space: nowrap;">
                                                        Full Due
                                                    </button>
                                                </div>
                                            </div>

                                            <div style="margin-bottom: 8px;">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #374151; margin-bottom: 3px;">
                                                    Payment Mode <span style="color: #ef4444;">*</span>
                                                </label>
                                                <select name="settle_mode" required style="width: 100%; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; background: #fff; box-sizing: border-box;">
                                                    <option value="cash" selected>💵 Cash</option>
                                                    <option value="upi">📱 UPI / Online Transfer</option>
                                                    <option value="bank">🏦 Bank Transfer / Cheque</option>
                                                    <option value="credit">📑 Credit Adjustment</option>
                                                </select>
                                            </div>

                                            <div style="margin-bottom: 8px;">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #374151; margin-bottom: 3px;">
                                                    Reference / Transaction No. (Optional)
                                                </label>
                                                <input type="text" name="ref_no" placeholder="e.g. UPI-123456 / Chq #890" style="width: 100%; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                                            </div>

                                            <div style="margin-bottom: 12px;">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #374151; margin-bottom: 3px;">
                                                    Settlement Notes / Remarks
                                                </label>
                                                <input type="text" name="clear_notes" placeholder="e.g. Cleared by shop owner" style="width: 100%; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; box-sizing: border-box;">
                                            </div>

                                            <button type="submit" style="width: 100%; padding: 8px 12px; background: #059669; color: #fff; border: none; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                                <i class='bx bx-check-shield'></i> Confirm Due Clearance
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 1.5rem 0; color: #64748b; font-size: 13px;">
                                    No receipt generated yet.
                                    <div style="margin-top: 10px;">
                                        <?php if (!empty($order['zone_slug'])): ?>
                                            <a href="<?= BASE_URL ?>/<?= htmlspecialchars($order['zone_slug']) ?>/generate-receipt?order_id=<?= $order_id ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; background: #2563eb; color: #fff; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600;">
                                                <i class='bx bx-receipt'></i> Generate Receipt Now
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>