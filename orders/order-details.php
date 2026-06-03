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
     <link rel="stylesheet" href="../assets/css/cart.css">
         <link rel="stylesheet" href="../assets/css/product.css">

<style>
body{
    background:#f5f7fa;
}

.timeline{
    margin-top:25px;
}

.timeline-item{
    position:relative;
    padding-left:35px;
    margin-bottom:18px;
    font-weight:500;
}

.timeline-item .circle{
    width:18px;
    height:18px;
    border-radius:50%;
    background:#d1d5db;
    position:absolute;
    left:0;
    top:2px;
}

.timeline-item.completed .circle{
    background:#16a34a;
}

.timeline-item::before{
    content:'';
    position:absolute;
    left:8px;
    top:20px;
    width:2px;
    height:28px;
    background:#d1d5db;
}

.timeline-item:last-child::before{
    display:none;
}

.card{
    overflow:hidden;
}

.btn-outline-primary{
    border-color:#4ad2e2;
    color:#4ad2e2;
}

.btn-outline-primary:hover{
    background:#4ad2e2;
    border-color:#4ad2e2;
}

</style>
</head>

<body>

    <?php include '../components/navbar.php' ?>

<div class="container py-5">

<div class="row g-4">

    <!-- LEFT SIDE -->
    <div class="col-lg-8">

        <div class="card border-0 shadow-sm rounded-4 mb-4">

            <?php foreach($items as $item): ?>

            <div class="card-body p-4">

                <div class="row">

                    <div class="col-md-9">

                        <h4 class="fw-bold mb-2">
                            <?= htmlspecialchars($item['product_name']) ?>
                        </h4>

                        <div class="text-muted mb-3">
                            Qty : <?= $item['quantity'] ?>
                        </div>

                        <h3 class="fw-bold text-dark">
                            ₹<?= number_format($item['price'],2) ?>
                        </h3>

                    </div>

                    <div class="col-md-3 text-center">

                        <img
                            src="<?= htmlspecialchars($item['product_image'] ?? '/assets/images/no-image.png') ?>"
                            class="img-fluid rounded"
                            style="max-height:120px;"
                        >

                    </div>

                </div>

                <hr>

                <!-- STATUS TIMELINE -->

                <div class="timeline">

                    <div class="timeline-item completed">
                        <span class="circle"></span>
                        Order Placed
                    </div>

                    <div class="timeline-item completed">
                        <span class="circle"></span>
                        Confirmed
                    </div>

                    <?php if(
                        in_array(strtolower($order['status']),
                        ['processing','shipped','delivered'])
                    ): ?>

                    <div class="timeline-item completed">
                        <span class="circle"></span>
                        Processing
                    </div>

                    <?php endif; ?>

                    <?php if(
                        in_array(strtolower($order['status']),
                        ['shipped','delivered'])
                    ): ?>

                    <div class="timeline-item completed">
                        <span class="circle"></span>
                        Shipped
                    </div>

                    <?php endif; ?>

                    <?php if(
                        strtolower($order['status']) == 'delivered'
                    ): ?>

                    <div class="timeline-item completed">
                        <span class="circle"></span>
                        Delivered
                    </div>

                    <?php endif; ?>

                </div>

            </div>

            <?php endforeach; ?>

        </div>

    </div>

    <!-- RIGHT SIDE -->

    <div class="col-lg-4">

        <!-- DELIVERY -->

        <div class="card border-0 shadow-sm rounded-4 mb-4">

            <div class="card-body">

                <h5 class="fw-bold mb-3">
                    Delivery Details
                </h5>

                <p class="mb-2">
                    <strong>
                        <?= htmlspecialchars($order['customer_name'] ?? 'Customer') ?>
                    </strong>
                </p>

                <p class="text-muted">
                    <?= htmlspecialchars($order['shipping_address'] ?? '-') ?>
                </p>

                <p>
                    <?= htmlspecialchars($order['phone'] ?? '-') ?>
                </p>

            </div>

        </div>

        <!-- PRICE DETAILS -->

        <div class="card border-0 shadow-sm rounded-4">

            <div class="card-body">

                <h5 class="fw-bold mb-3">
                    Price Details
                </h5>

                <div class="d-flex justify-content-between mb-2">
                    <span>Items Total</span>
                    <span>
                        ₹<?= number_format($order['total_amount'],2) ?>
                    </span>
                </div>

                <div class="d-flex justify-content-between mb-2">
                    <span>Shipping</span>
                    <span class="text-success">
                        FREE
                    </span>
                </div>

                <hr>

                <div class="d-flex justify-content-between fw-bold fs-5">
                    <span>Total</span>
                    <span>
                        ₹<?= number_format($order['total_amount'],2) ?>
                    </span>
                </div>

                <div class="mt-4">

                    <button class="btn btn-outline-primary w-100">
                        Download Invoice
                    </button>

                </div>

            </div>

        </div>

    </div>

</div>

</div>

</body>
</html>