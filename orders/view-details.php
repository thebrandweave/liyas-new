<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userId = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| 1. FETCH ORDER DATA
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE order_id = ?
    AND user_id = ?
");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found");
}

/*
|--------------------------------------------------------------------------
| 2. FETCH ORDER ITEMS JOINED WITH PRODUCTS
|--------------------------------------------------------------------------
*/
$itemStmt = $pdo->prepare("
    SELECT 
        oi.*, 
        p.name AS product_name, 
        p.image AS product_image,
        p.description AS product_desc,
        p.price AS listing_price
    FROM order_items oi
    INNER JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
");
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 3. FETCH SHIPPING ADDRESS
|--------------------------------------------------------------------------
*/
$address = [];
if (!empty($order['shipping_address_id'])) {
    $addressStmt = $pdo->prepare("
        SELECT *
        FROM shipping_addresses
        WHERE address_id = ?
    ");
    $addressStmt->execute([$order['shipping_address_id']]);
    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate simple mathematical summaries dynamically
$totalListingPrice = 0;
foreach ($items as $item) {
    $totalListingPrice += ($item['listing_price'] * $item['quantity']);
}
$discountValue = $totalListingPrice - $order['total_amount'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order Details - #<?= $order['order_id'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/product.css">
    \     <link rel="stylesheet" href="../assets/css/cart.css">
    <style>
        body { background-color: #f1f5f9; font-family: system-ui, -apple-system, sans-serif; }
        .order-stepper { position: relative; padding-left: 30px; }
        .order-stepper::before {
            content: ""; position: absolute; left: 9px; top: 10px; bottom: 10px; width: 3px;
            background-color: #198754;
        }
        .step-item { position: relative; padding-bottom: 25px; }
        .step-item:last-child { padding-bottom: 0; }
        .step-dot {
            position: absolute; left: -29px; top: 4px; width: 12px; height: 12px;
            background-color: #198754; border-radius: 50%; z-index: 2;
        }
        .stars-container i { font-size: 1.5rem; color: #ccc; cursor: pointer; margin-right: 5px; }
    </style>
</head>
<body>
    <?php include '../components/navbar.php' ?>
<div class="container py-4">
    <div class="row g-4">
        
        <div class="col-lg-8">
            
            <?php foreach ($items as $item): ?>
                <div class="card border-0 shadow-sm rounded-3 p-4 mb-4">
                    <div class="row align-items-start mb-4">
                        <div class="col">
                            <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($item['product_name']) ?></h5>
                            <p class="text-muted small mb-1"><?= htmlspecialchars($item['product_desc']) ?></p>
                            <p class="text-muted small mb-2">Qty: <?= (int)$item['quantity'] ?></p>
                            <h4 class="fw-bold">₹<?= number_format($item['price_at_purchase'], 2) ?></h4>
                        </div>
                        <div class="col-auto">
                            <?php 
                                $imagePath = !empty($item['product_image']) ? '../uploads/' . $item['product_image'] : '../assets/images/sample-product.png';
                            ?>
                            <img src="<?= $imagePath ?>" alt="Product Image" class="img-fluid rounded" style="width: 80px; height: 80px; object-fit: cover;">
                        </div>
                    </div>

                    <hr class="text-muted opacity-25">

                    <div class="order-stepper my-4">
                        <div class="step-item">
                            <span class="step-dot"></span>
                            <p class="mb-0 fw-semibold">Ordered Confirmed, <?= date('d M Y', strtotime($order['created_at'])) ?></p>
                        </div>
                        <?php if ($order['status'] === 'processing' || $order['status'] === 'shipped' || $order['status'] === 'delivered'): ?>
                            <div class="step-item">
                                <span class="step-dot"></span>
                                <p class="mb-0 fw-semibold"><?= ucfirst($order['status']) ?> Status Updated, <?= date('d M Y', strtotime($order['updated_at'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <a href="#" class="text-decoration-none fw-bold small d-inline-flex align-items-center">
                        See All Updates <i class="bi bi-chevron-right ms-1 small"></i>
                    </a>
                </div>
            <?php endforeach; ?>

            <div class="card border-0 shadow-sm rounded-3 p-3 mb-4 text-center">
                <a href="#" class="text-decoration-none text-dark fw-semibold d-inline-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-chat-text fs-5"></i> Chat with us
                </a>
            </div>

            <div class="card border-0 shadow-sm rounded-3 p-4">
                <h6 class="fw-bold mb-3">Rate your experience</h6>
                <div class="bg-light p-3 rounded-3">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-box-seam text-muted"></i>
                        <span class="small text-muted">Rate the product</span>
                    </div>
                    <div class="stars-container text-center py-2">
                        <i class="bi bi-star"></i><i class="bi bi-star"></i><i class="bi bi-star"></i><i class="bi bi-star"></i><i class="bi bi-star"></i>
                    </div>
                </div>
            </div>

            <p class="text-muted small mt-3 px-2 d-flex align-items-center gap-2">
                Order #<?= htmlspecialchars($order['order_id']) ?> <i class="bi bi-copy text-primary" style="cursor: pointer;"></i>
            </p>
        </div>

        <div class="col-lg-4">
            
            <div class="card border-0 shadow-sm rounded-3 p-4 mb-4">
                <h6 class="fw-bold mb-3">Delivery details</h6>
                <div class="d-flex gap-3 mb-3 align-items-start">
                    <i class="bi bi-house-door text-muted fs-5"></i>
                    <div>
                        <span class="fw-bold small d-block mb-1">Shipping Address</span>
                        <span class="text-muted small d-block leading-sm">
                            <?= htmlspecialchars($address['address_line_1'] ?? 'No address registered') ?>
                            <?= htmlspecialchars($address['address_line_2'] ?? '') ?>, 
                            <?= htmlspecialchars($address['city'] ?? '') ?>, 
                            <?= htmlspecialchars($address['state'] ?? '') ?> - 
                            <?= htmlspecialchars($address['zip_code'] ?? '') ?>
                        </span>
                    </div>
                </div>
                <div class="d-flex gap-3 align-items-start">
                    <i class="bi bi-person text-muted fs-5"></i>
                    <div>
                        <span class="fw-bold small d-block mb-1">
                            <?= htmlspecialchars($address['full_name'] ?? 'Guest Name') ?>
                        </span>
                        <span class="text-muted small d-block">
                            <?= htmlspecialchars($address['phone_number'] ?? 'No Phone') ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-3 p-4">
                <h6 class="fw-bold mb-3">Price details</h6>
                
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Listing price</span>
                    <span class="text-decoration-line-through text-muted">₹<?= number_format($totalListingPrice, 2) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Special price</span>
                    <span class="fw-semibold">₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Delivery fees</span>
                    <span class="text-success fw-semibold">FREE</span>
                </div>
                <?php if ($discountValue > 0): ?>
                    <div class="d-flex justify-content-between mb-3 small">
                        <span class="text-muted">Discount value</span>
                        <span class="text-success fw-semibold">-₹<?= number_format($discountValue, 2) ?></span>
                    </div>
                <?php endif; ?>

                <hr class="text-muted opacity-25">

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <span class="fw-bold">Total amount</span>
                    <span class="fw-bold fs-5">₹<?= number_format($order['total_amount'], 2) ?></span>
                </div>

                <div class="bg-light p-2 rounded-3 d-flex justify-content-between align-items-center mb-4 small">
                    <span class="text-muted">Paid By</span>
                    <span class="fw-semibold"><i class="bi bi-cash-coin me-1"></i> <?= strtoupper($order['payment_method']) ?></span>
                </div>

                <button class="btn btn-outline-dark w-100 rounded-3 py-2 fw-semibold small d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-download"></i> Download Invoice
                </button>
            </div>

        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>