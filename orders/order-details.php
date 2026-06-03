<?php

require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
SELECT *
FROM orders
WHERE order_id = ?
AND user_id = ?
");

$stmt->execute([$orderId,$userId]);

$order = $stmt->fetch();

if(!$order){
    die("Order not found");
}

$itemStmt = $pdo->prepare("
SELECT *
FROM order_items
WHERE order_id = ?
");

$itemStmt->execute([$orderId]);

$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

?>


<!DOCTYPE html>
<html>
<head>
<title>Order Details</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f8fafc;
}

.order-wrapper{
    max-width:1000px;
    margin:auto;
}

.order-box{
    background:#fff;
    border-radius:24px;
    padding:30px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
}

.order-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:30px;
}

.order-number{
    color:#4ad2e2;
    font-size:28px;
    font-weight:700;
}

.item-card{
    border:1px solid #eef2f7;
    border-radius:16px;
    padding:15px;
    margin-bottom:15px;
}

.total-box{
    background:#f1fdff;
    border-radius:16px;
    padding:20px;
    text-align:right;
    font-size:24px;
    font-weight:700;
    color:#0f172a;
}

</style>
</head>

<body>

<?php include '../components/navbar.php'; ?>

<div class="container py-5">

<div class="order-wrapper">

<div class="order-box">

<div class="order-header">

<div>
<div class="order-number">
Order #<?= $order['order_id']; ?>
</div>

<div>
<?= date('d M Y h:i A', strtotime($order['order_date'])); ?>
</div>
</div>

<div>
<span class="status-badge <?= strtolower($order['status']) ?>">
<?= ucfirst($order['status']) ?>
</span>
</div>

</div>

<h4 class="mb-4">Order Items</h4>

<?php foreach($items as $item): ?>

<div class="item-card">

<div class="row">

<div class="col-md-6">
<strong><?= htmlspecialchars($item['product_name']) ?></strong>
</div>

<div class="col-md-3">
Qty: <?= $item['quantity'] ?>
</div>

<div class="col-md-3 text-end">
₹<?= number_format($item['price'],2) ?>
</div>

</div>

</div>

<?php endforeach; ?>

<div class="total-box mt-4">
Total : ₹<?= number_format($order['total_amount'],2) ?>
</div>

</div>

</div>

</div>

</body>
</html>