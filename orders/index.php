<?php
require_once '../config/config.php';

// Prevent browser caching completely
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userId = (int)$_SESSION['user_id'];

$sql = "
SELECT
    o.order_id,
    o.order_date,
    o.total_amount,
    o.status,
    o.payment_status,
    COUNT(oi.order_item_id) AS total_items
FROM orders o
LEFT JOIN order_items oi ON oi.order_id = o.order_id
WHERE o.user_id = ?
GROUP BY o.order_id
ORDER BY o.order_date DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Orders</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/cart.css">
    <link rel="stylesheet" href="../assets/css/product.css">
    <style>
        body{ background:#f1f5f9; }
        .page-title{ font-size:2.5rem; font-weight:800; color:#0f172a; }
        .order-box{ background:#fff; border-radius:16px; padding:25px; border:1px solid #e5e7eb; transition:.3s; }
        .order-box:hover{ box-shadow:0 10px 30px rgba(0,0,0,.08); }
        .order-price{ font-size:24px; font-weight:700; color:#111827; }
        .status-line{ display:flex; align-items:center; gap:10px; margin-bottom:5px; }
        .status-dot{ width:10px; height:10px; border-radius:50%; }
        .view-btn{ background:#4ad2e2; border:none; padding:10px 20px; border-radius:10px; font-weight:600; color:#fff; text-decoration:none; }
        .view-btn:hover{ background:#2dc4d5; color:#fff; }
        .empty-orders{ text-align:center; padding:80px 20px; }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="container py-5">
        <h2 class="page-title mb-4">My Orders</h2>

        <div class="mb-4">
            <input type="text" id="searchOrders" class="form-control form-control-lg" placeholder="Search your orders...">
        </div>

        <?php if(count($orders) > 0): ?>
            <div id="ordersContainer">
            <?php foreach($orders as $order): ?>
                <?php
                    $status = strtolower(trim($order['status']));
                    $dotColor = '#22c55e'; // default green

                    if($status == 'pending')   $dotColor = '#f59e0b';
                    if($status == 'cancelled') $dotColor = '#ef4444';
                    if($status == 'returned')  $dotColor = '#8b5cf6';
                ?>

                <div class="order-box mb-4">
                    <div class="row align-items-center">
                        <div class="col-md-5">
                            <h5 class="mb-2">Order #<?= $order['order_id']; ?></h5>
                            <div class="text-muted"><?= date('d M Y, h:i A', strtotime($order['order_date'])); ?></div>
                            <div class="mt-2"><strong><?= $order['total_items']; ?></strong> Item(s)</div>
                        </div>

                        <div class="col-md-2">
                            <div class="order-price">₹<?= number_format($order['total_amount'],2); ?></div>
                        </div>

                        <div class="col-md-3">
                            <div class="status-line">
                                <span class="status-dot" style="background:<?= $dotColor ?>"></span>
                                <strong><?= ucfirst($order['status']); ?></strong>
                            </div>
                            <small class="text-muted">Payment: <?= ucfirst($order['payment_status']); ?></small>
                        </div>

                        <div class="col-md-2 text-md-end mt-3 mt-md-0">
                            <a href="/orders/view-details.php?id=<?= $order['order_id']; ?>" class="btn view-btn">
                                View Details
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-orders">
                <img src="../assets/images/empty-order.svg" width="180" class="mb-4" alt="No orders">
                <h3>No Orders Yet</h3>
                <p class="text-muted">You haven't placed any orders yet.</p>
                <a href="/products/" class="btn btn-primary">Browse Products</a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>