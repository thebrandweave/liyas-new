<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once '../config/config.php';

// Prevent browser caching
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
<html>
<head>
<meta charset="UTF-8">
<title>My Orders</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    background:#f8fafc;
}

.page-title{
    font-weight:700;
    margin-bottom:30px;
}

.order-card{
    background:#fff;
    border-radius:16px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 5px 20px rgba(0,0,0,.06);
}

.order-id{
    color:#0ea5e9;
    font-weight:600;
}

.status-badge{
    padding:6px 12px;
    border-radius:20px;
    font-size:13px;
    font-weight:600;
}

.pending{background:#fff3cd;color:#856404;}
.processing{background:#dbeafe;color:#1e40af;}
.shipped{background:#e0f2fe;color:#0369a1;}
.delivered{background:#dcfce7;color:#166534;}
.cancelled{background:#fee2e2;color:#991b1b;}
.returned{background:#f3e8ff;color:#6b21a8;}

.empty-orders{
    text-align:center;
    padding:80px 20px;
}
</style>
</head>
<body>

<?php include __DIR__ . '/includes/navbar.php'; ?>

<div class="container py-5">

    <h2 class="page-title">My Orders</h2>

  <?php if(count($orders) > 0): ?>

     <?php foreach($orders as $order): ?>

            <div class="order-card">

                <div class="row align-items-center">

                    <div class="col-md-3">
                        <div class="order-id">
                            Order #<?= $order['order_id']; ?>
                        </div>

                        <small class="text-muted">
                            <?= date('d M Y h:i A', strtotime($order['order_date'])); ?>
                        </small>
                    </div>

                    <div class="col-md-2">
                        <strong><?= $order['total_items']; ?></strong><br>
                        <small>Items</small>
                    </div>

                    <div class="col-md-2">
                        ₹<?= number_format($order['total_amount'],2); ?>
                    </div>

                    <div class="col-md-2">
                        <span class="status-badge <?= strtolower($order['status']); ?>">
                            <?= ucfirst($order['status']); ?>
                        </span>
                    </div>

                    <div class="col-md-3 text-md-end mt-3 mt-md-0">
                        <a href="order-details.php?id=<?= $order['order_id']; ?>"
                           class="btn btn-outline-primary">
                            View Details
                        </a>
                    </div>

                </div>

            </div>

    <?php endforeach; ?>

    <?php else: ?>

        <div class="empty-orders">

            <h3>No Orders Yet</h3>

            <p class="text-muted">
                You haven't placed any orders yet.
            </p>

            <a href="/products/" class="btn btn-primary">
                Browse Products
            </a>

        </div>

    <?php endif; ?>

</div>

</body>
</html>