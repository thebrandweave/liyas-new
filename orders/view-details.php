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

// Calculate summaries dynamically
$totalListingPrice = 0;
foreach ($items as $item) {
    // Falls back to historic listing price if price_at_purchase isn't matching
    $price = $item['price_at_purchase'] ?? $item['listing_price'];
    $totalListingPrice += ($price * $item['quantity']);
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
    <link rel="stylesheet" href="../assets/css/cart.css">
    <style>
        body { background-color: #f1f5f9; font-family: system-ui, -apple-system, sans-serif; }
        .order-stepper { position: relative; padding-left: 30px; }
        .order-stepper::before {
            content: ""; position: absolute; left: 6px; top: 10px; bottom: 10px; width: 3px;
            background-color: #198754;
        }
        .step-item { position: relative; padding-bottom: 25px; }
        .step-item:last-child { padding-bottom: 0; }
        .step-dot {
            position: absolute; left: -29px; top: 4px; width: 12px; height: 12px;
            background-color: #198754; border-radius: 50%; z-index: 2;
        }
        .step-item, .step-item:hover, .step-item:focus, .step-item:active {
            background: transparent !important; box-shadow: none !important; transform: none !important; cursor: default !important;
        }
        .step-item *:hover { color: inherit !important; }
    </style>
</head>
<body>
    <?php include '../components/navbar.php' ?>
<div class="container py-4">
    <div class="row g-4">
        
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 p-4 mb-4">
                <h5 class="fw-bold mb-4">Order Summary (#<?= $order['order_id'] ?>)</h5>
                
                <?php foreach ($items as $index => $item): ?>
                    <div class="row align-items-start <?= $index > 0 ? 'pt-4 border-top' : '' ?> mb-4">
                        <div class="col">
                            <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($item['product_name']) ?></h5>
                            <p class="text-muted small mb-1"><?= htmlspecialchars($item['product_desc']) ?></p>
                            <p class="text-muted small mb-2">Qty: <?= (int)$item['quantity'] ?></p>
                            <h4 class="fw-bold">₹<?= number_format($item['price_at_purchase'] ?? $item['listing_price'], 2) ?></h4>
                        </div>
                        <div class="col-auto">
                            <?php
                            $imagePath = !empty($item['product_image'])
                                ? BASE_URL . '/admin/uploads/products/' . $item['product_image']
                                : BASE_URL . '/assets/images/sample-product.png';
                            ?>
                            <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" class="img-fluid rounded" style="max-height:120px; width: 120px; object-fit: cover;">
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if(in_array(strtolower($order['status']), ['pending','processing'])): ?>
                    <div class="mt-3">
                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                            Cancel Order
                        </button>
                    </div>
                <?php endif; ?>

                <?php if(strtolower($order['status']) == 'cancelled'): ?>
                    <div class="alert alert-danger mt-3">
                        <strong>Order Cancelled</strong><br>
                        <strong>Reason:</strong> <?= htmlspecialchars($order['cancellation_reason'] ?? 'No reason provided') ?>
                        <?php if(!empty($order['cancelled_at'])): ?>
                            <br><small>Cancelled on: <?= date('d M Y h:i A', strtotime($order['cancelled_at'])) ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <hr class="text-muted opacity-25">

                <div class="order-stepper my-4">
                    <div class="step-item">
                        <span class="step-dot"></span>
                        <p class="mb-0 fw-semibold">Order Confirmed, <?= date('d M Y', strtotime($order['order_date'] ?? $order['created_at'])) ?></p>
                    </div>
                    <?php if (in_array(strtolower($order['status']), ['processing', 'shipped', 'delivered'])): ?>
                        <div class="step-item">
                            <span class="step-dot"></span>
                            <p class="mb-0 fw-semibold"><?= ucfirst($order['status']) ?> Status Updated, <?= date('d M Y', strtotime($order['updated_at'] ?? $order['order_date'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            
            <div class="card border-0 shadow-sm rounded-3 p-4 mb-4">
                <h6 class="fw-bold mb-3">Delivery details</h6>
                <div class="d-flex gap-3 mb-3 align-items-start">
                    <i class="bi bi-house-door text-muted fs-5"></i>
                    <div>
                        <span class="fw-bold small d-block mb-1">Shipping Address</span>
                        <span class="text-muted small d-block leading-sm">
                            <?= htmlspecialchars($address['address_line_1'] ?? 'No address registered') ?><br>
                            <?= !empty($address['address_line_2']) ? htmlspecialchars($address['address_line_2']) . ', ' : '' ?>
                            <?= htmlspecialchars($address['city'] ?? '') ?>, <?= htmlspecialchars($address['state'] ?? '') ?> - <?= htmlspecialchars($address['zip_code'] ?? '') ?>
                        </span>
                    </div>
                </div>
                <div class="d-flex gap-3 align-items-start">
                    <i class="bi bi-person text-muted fs-5"></i>
                    <div>
                        <span class="fw-bold small d-block mb-1"><?= htmlspecialchars($address['full_name'] ?? 'Guest Name') ?></span>
                        <span class="text-muted small d-block"><?= htmlspecialchars($address['phone_number'] ?? 'No Phone Contact') ?></span>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-3 p-4">
                <h6 class="fw-bold mb-3">Price details</h6>
                
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Item price</span>
                    <span class="fw-semibold">₹<?= number_format($totalListingPrice, 2) ?></span>
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
                    <span class="fw-semibold">
                        <i class="bi bi-cash-coin me-1"></i> 
                        <?= strtoupper($order['payment_method'] ?? $order['payment_status'] ?? 'UNKNOWN') ?>
                    </span>
                </div>

                <button class="btn btn-outline-dark w-100 rounded-3 py-2 fw-semibold small d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-download"></i> Download Invoice
                </button>
            </div>

        </div>

    </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="cancel-order.php" method="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Order</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
                    <label class="form-label">Reason for cancellation</label>
                    <textarea name="reason" class="form-control" rows="4" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary text-dark" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-danger">Confirm Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bundle.min.js"></script>
</body>
</html>