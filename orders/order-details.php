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

/* Top-right Login Button - aligned with Back to Top button */
.top-login-btn {
  position: fixed;
  top: 24px;
  right: 20px;
  width: 98px;
  height: 45px;
  border-radius: 7%;
  background: rgba(255, 255, 255, 0.95);
  color: #000000;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
  border: 1px solid rgba(0, 0, 0, 0.08);
  text-decoration: none;
  z-index: 1100;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  cursor: pointer;
  /* Better touch target */
  min-width: 52px;
  min-height: 45px;
  /* Ensure it's always visible */
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  
}

.top-login-btn:hover {
  transform: translateY(-3px) scale(1.05);
  box-shadow: 0 6px 20px rgba(74, 210, 226, 0.25);
  background: rgba(255, 255, 255, 1);
  border-color: rgba(74, 210, 226, 0.2);
}

.top-login-btn:active {
  transform: translateY(-1px) scale(0.98);
}

/* SVG Icon inside the circle */
.top-login-btn .login-svg {
  width: 24px;
  height: 24px;
  stroke-width: 2;
  transition: stroke 0.3s ease;
  display: block;
}

.top-login-btn:hover .login-svg {
  stroke: rgba(74, 210, 226, 1);
}

/* Tablet adjustments */
@media (min-width: 768px) and (max-width: 991px) {
  .top-login-btn {
    top: 28px;
    right: 18px;
    width: 76px;
    height: 48px;
    min-width: 48px;
    min-height: 42px;
  }
  
  .top-login-btn .login-svg {
    width: 22px;
    height: 22px;
  }
  .login-button{
      display: block;
  }
}

/* Responsive adjustments (mobile view) */
@media screen and (max-width: 767px) {
  .top-login-btn {
      display: none;
    /*width: 80px !important;*/
    /*height: 48px !important;*/
    /*min-width: 48px !important;*/
    /*min-height: 48px !important;*/
    /*top: 16px !important;*/
    /*right: 16px !important;*/
    /*left: auto !important;*/
    /*bottom: auto !important;*/
    /*box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important;*/
    z-index: 1101 !important; /* Above navbar but below mobile menu when open */
    /*background: rgba(255, 255, 255, 1) !important;*/
    /*border: 1px solid rgba(0, 0, 0, 0.1) !important;*/
    /* Ensure visibility */
    /*opacity: 1 !important;*/
    /*visibility: visible !important;*/
    /*pointer-events: auto !important;*/
    /*display: flex !important;*/
    /*position: fixed !important;*/
    /*transform: none !important;*/
  }
  
  .top-login-btn .login-svg {
    width: 22px !important;
    height: 22px !important;
    stroke-width: 2.2 !important;
    display: block !important;
    flex-shrink: 0 !important;
  }

  /* Adjust position when navbar is scrolled */
  .liyas-navbar.scrolled ~ .top-login-btn {
    top: 14px !important;
  }
  
  /* Ensure button is clickable */
  .top-login-btn:active {
    transform: scale(0.95) !important;
  }
}

/* Extra small devices */
@media screen and (max-width: 480px) {
  .top-login-btn {
    width: 61px !important;
    height: 46px !important;
    min-width: 46px !important;
    min-height: 46px !important;
    top: 15px !important;
    right: 124px !important;
    left: auto !important;
    position: fixed !important;
  }
  
  .top-login-btn .login-svg {
    width: 20px !important;
    height: 20px !important;
  }
}

@media screen and (max-width: 375px) {
  .top-login-btn {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    min-height: 44px !important;
    top: 12px !important;
    right: 12px !important;
    left: auto !important;
    position: fixed !important;
  }
  
  .top-login-btn .login-svg {
    width: 18px !important;
    height: 18px !important;
  }
}

/* Hide login button when mobile menu is open to avoid overlap */
@media screen and (max-width: 767px) {
  body.no-scroll .top-login-btn {
    opacity: 0 !important;
    pointer-events: none !important;
    transform: scale(0.8) !important;
    visibility: hidden !important;
  }
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