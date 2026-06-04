<?php
require_once '../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login/");
    exit();
}

$userId = (int)$_SESSION['user_id'];

// Safe check: handle both clean rewriting (/view-details?id=11) and classic formats
$orderId = 0;
if (isset($_GET['id'])) {
    $orderId = (int)$_GET['id'];
} elseif (isset($_REQUEST['id'])) {
    $orderId = (int)$_REQUEST['id'];
}

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
    die("Order not found for ID: " . htmlspecialchars($orderId));
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

// Dynamic mathematical price summaries
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
            background: transparent !important;
            box-shadow: none !important;
            transform: none !important;
            cursor: default !important;
        }
        .step-item *:hover { color: inherit !important; }
        
        .top-login-btn {
            position: fixed; top: 24px; right: 20px; width: 98px; height: 45px; border-radius: 7%;
            background: rgba(255, 255, 255, 0.95); color: #000000; display: flex; align-items: center;
            justify-content: center; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12); border: 1px solid rgba(0, 0, 0, 0.08);
            text-decoration: none; z-index: 1100; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); cursor: pointer; min-width: 52px; min-height: 45px;
        }
        .top-login-btn:hover { transform: translateY(-3px) scale(1.05); background: rgba(255, 255, 255, 1); }
        @media screen and (max-width: 767px) { .top-login-btn { display: none !important; } }
    </style>
</head>
<body>
    <?php include '../components/navbar.php'; ?>
    
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
                                $imagePath = !empty($item['product_image'])
                                    ? BASE_URL . '/admin/uploads/products/' . $item['product_image']
                                    : BASE_URL . '/assets/images/sample-product.png';
                                ?>
                                <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" class="img-fluid rounded" style="max-height:120px;">
                            </div>
                        </div>
                        
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
                                    <br>
                                    <small>Cancelled on <?= date('d M Y h:i A', strtotime($order['cancelled_at'])) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <hr class="text-muted opacity-25">

                        <div class="order-stepper my-4">
                            <div class="step-item">
                                <span class="step-dot"></span>
                                <p class="mb-0 fw-semibold">Order Confirmed, <?= date('d M Y', strtotime($order['created_at'])) ?></p>
                            </div>
                            <?php if (in_array(strtolower($order['status']), ['processing', 'shipped', 'delivered'])): ?>
                                <div class="step-item">
                                    <span class="step-dot"></span>
                                    <p class="mb-0 fw-semibold"><?= ucfirst($order['status']) ?> Status Updated, <?= date('d M Y', strtotime($order['updated_at'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
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
                            <span class="fw-bold small d-block mb-1"><?= htmlspecialchars($address['full_name'] ?? 'Guest Name') ?></span>
                            <span class="text-muted small d-block"><?= htmlspecialchars($address['phone_number'] ?? 'No Phone') ?></span>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-3 p-4">
                    <h6 class="fw-bold mb-3">Price details</h6>
                    <div class="d-flex justify-content-between mb-2 small">
                        <span class="text-muted">Item price</span>
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

 <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
       <form action="/orders/cancel-order.php" method="POST">
            <input type="hidden" name="order_id" value="<?= (int)$orderId; ?>">
            
            <div class="modal-content border-0 shadow-lg rounded-3">
                <div class="modal-header border-bottom-0 pt-4 px-4">
                    <h5 class="modal-title fw-bold text-dark">Cancel Order #<?= (int)$orderId; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4">
                    <p class="text-muted small">Please provide a reason for cancelling this order. This status update will automatically reflect across all employee and administration dashboards immediately.</p>
                    <label class="form-label fw-semibold text-secondary small">Reason for cancellation</label>
                    <textarea name="reason" class="form-control border-2 rounded-3" rows="4" placeholder="Type your cancellation reasoning here..." required></textarea>
                </div>
                <div class="modal-footer border-top-0 pb-4 px-4 gap-2">
                    <button type="button" class="btn btn-light fw-semibold text-muted px-3 py-2 rounded-3" data-bs-dismiss="modal">Go Back</button>
<button type="submit"
        name="cancel_order"
        value="1"
        class="btn btn-danger fw-semibold px-4 py-2 rounded-3 shadow-sm">
    Confirm Cancellation
</button>            
    </div>
            </div>
        </form>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>