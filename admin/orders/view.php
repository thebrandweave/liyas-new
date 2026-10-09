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
        r.created_at as receipt_date
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN users u ON o.user_id = u.user_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
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

        if ($new_status === 'delivered') {
            updateShopRewardProgress($pdo, $shopName, $order['phone']);
            $rewardInfo = getShopRewardInfo($pdo, $shopName);
        }

        quickLog($pdo, 'update_status', 'order', $order_id, "Status changed to {$new_status}");
        $msg = "Order status updated successfully!";
    }
}

// Settle / Clear Due Balance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settle_due_balance'])) {
    $rStmt = $pdo->prepare("SELECT id, due_amount, cash_amount, credited_amount FROM receipts WHERE order_id = ?");
    $rStmt->execute([$order_id]);
    $rec = $rStmt->fetch(PDO::FETCH_ASSOC);

    if ($rec && (float)$rec['due_amount'] > 0) {
        $dueCleared = (float)$rec['due_amount'];
        $settle_mode = $_POST['settle_mode'] ?? 'cash';

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

        quickLog($pdo, 'settle_due', 'receipt', $rec['id'], "Cleared due balance of ₹{$dueCleared} for order #{$order_id}");
        header("Location: view.php?id={$order_id}&due_cleared=1");
        exit;
    }
}

if (isset($_GET['due_cleared'])) {
    $msg = "Outstanding due has been successfully cleared and added to Delivered Sales!";
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
                </div>
                <div class="header-actions" style="display: flex; gap: 8px;">
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
                                    <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">
                                        <th style="padding: 8px 4px;">Product</th>
                                        <th style="padding: 8px 4px; text-align: center;">Qty (Cases)</th>
                                        <th style="padding: 8px 4px; text-align: right;">Case Price</th>
                                        <th style="padding: 8px 4px; text-align: right;">Total</th>
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
                                        <td style="padding: 12px 4px;">
                                            <strong style="color: #1e293b;"><?= $iName ?></strong>
                                            <?php if (!empty($item['net_content'])): ?>
                                                <div style="font-size: 12px; color: #64748b;">
                                                    Net: <?= (float)$item['net_content'] ?> <?= htmlspecialchars($item['net_content_unit'] ?? '') ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 12px 4px; text-align: center; font-weight: 600;">
                                            <?= $iQty ?>
                                        </td>
                                        <td style="padding: 12px 4px; text-align: right;">
                                            <?= formatCurrency($iPrice) ?>
                                        </td>
                                        <td style="padding: 12px 4px; text-align: right; font-weight: 600;">
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
                                <span class="info-label">Contact Person:</span>
                                <span class="info-val"><?= htmlspecialchars($order['customer_name'] ?: 'N/A') ?></span>
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
                            <div class="info-row">
                                <span class="info-label">Delivery Address:</span>
                                <span class="info-val"><?= nl2br(htmlspecialchars($order['address'] ?: 'N/A')) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Panels -->
                    <div>
                        <!-- Status & Action Card -->
                        <div class="info-card">
                            <div class="card-heading">
                                <span>Order Workflow</span>
                            </div>

                            <form action="view.php?id=<?= $order_id ?>" method="POST" style="margin-bottom: 1.5rem;">
                                <div style="margin-bottom: 0.75rem;">
                                    <label style="display: block; font-size: 12px; color: #64748b; font-weight: 500; margin-bottom: 4px;">Update Status:</label>
                                    <select name="status" class="form-control" style="width: 100%;">
                                        <option value="pending" <?= ($order['status'] === 'pending') ? 'selected' : '' ?>>Pending</option>
                                        <option value="processing" <?= ($order['status'] === 'processing') ? 'selected' : '' ?>>Processing</option>
                                        <option value="shipped" <?= ($order['status'] === 'shipped') ? 'selected' : '' ?>>Shipped</option>
                                        <option value="delivered" <?= ($order['status'] === 'delivered') ? 'selected' : '' ?>>Delivered</option>
                                        <option value="cancelled" <?= ($order['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                                <button type="submit" name="update_status" class="btn-primary" style="width: 100%; padding: 0.5rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                                    Update Status
                                </button>
                            </form>

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
                                        Central Warehouse
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
                                    <div style="margin-top: 12px; padding: 12px; background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 8px;">
                                        <div style="font-size: 12px; font-weight: 700; color: #991b1b; margin-bottom: 6px;">
                                            <i class='bx bx-info-circle'></i> Pending Receivables: <?= formatCurrency($order['receipt_due']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: #64748b; margin-bottom: 8px;">
                                            Once settled, this amount is added to Delivered Sales.
                                        </div>
                                        <form action="view.php?id=<?= $order_id ?>" method="POST" onsubmit="return confirm('Settle outstanding due of <?= formatCurrency($order['receipt_due']) ?> via Cash?');">
                                            <input type="hidden" name="settle_due_balance" value="1">
                                            <input type="hidden" name="settle_mode" value="cash">
                                            <button type="submit" style="width: 100%; padding: 7px 12px; background: #059669; color: #fff; border: none; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;">
                                                <i class='bx bx-check-shield'></i> Settle Due via Cash (+<?= formatCurrency($order['receipt_due']) ?>)
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