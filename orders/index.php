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

 .contact-submit {
            background: linear-gradient(135deg, #3bb6c4, #4ad2e2);
            color: #fff;
            border-radius: 50px;
            padding: 14px 44px;
            font-weight: 600;
            transition: .3s;
            border: none;
            text-decoration:none;
        }

        .contact-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(74,210,226,.35);
            color: #fff;
        }
        /* Order status badges */
        .order-status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
            border: 1px solid transparent;
            line-height: 1.2;
        }

        .order-status-pending {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }

        .order-status-processing {
            background: #dbeafe;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .order-status-shipped {
            background: #f3e8ff;
            color: #7e22ce;
            border-color: #d8b4fe;
        }

        .order-status-delivered {
            background: #dcfce7;
            color: #15803d;
            border-color: #86efac;
        }

        .order-status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        .order-status-returned {
            background: #ede9fe;
            color: #5b21b6;
            border-color: #c4b5fd;
        }

        .order-status-default {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }

        /* Payment Status Badges */
        .payment-status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .payment-status-paid {
            background: #dcfce7;
            color: #15803d;
            border-color: #86efac;
        }
        .payment-status-pending {
            background: #fef3c7;
            color: #92400e;
            border-color: #fcd34d;
        }
        .payment-status-failed {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }
        .payment-status-refunded {
            background: #ede9fe;
            color: #6d28d9;
            border-color: #c4b5fd;
        }
        .payment-status-default {
            background: #f1f5f9;
            color: #475569;
            border-color: #cbd5e1;
        }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="container py-5">
        <h2 class="page-title mb-4" >My <span  style="color:#4ad2e2;font-size:2.5rem; font-weight:800;">Orders</span></h2>



        <?php if(count($orders) > 0): ?>
            <div id="ordersContainer">
            <?php foreach($orders as $order): ?>
                <?php
                    $status = strtolower(trim((string)($order['status'] ?? '')));

                    switch ($status) {
                        case 'pending':
                            $dotColor    = '#f59e0b';
                            $statusClass = 'order-status-pending';
                            $statusLabel = 'Pending';
                            break;
                        case 'processing':
                            $dotColor    = '#2563eb';
                            $statusClass = 'order-status-processing';
                            $statusLabel = 'Processing';
                            break;
                        case 'shipped':
                            $dotColor    = '#9333ea';
                            $statusClass = 'order-status-shipped';
                            $statusLabel = 'Shipped';
                            break;
                        case 'delivered':
                            $dotColor    = '#16a34a';
                            $statusClass = 'order-status-delivered';
                            $statusLabel = 'Delivered';
                            break;
                        case 'cancelled':
                            $dotColor    = '#dc2626';
                            $statusClass = 'order-status-cancelled';
                            $statusLabel = 'Cancelled';
                            break;
                        case 'returned':
                            $dotColor    = '#7c3aed';
                            $statusClass = 'order-status-returned';
                            $statusLabel = 'Returned';
                            break;
                        default:
                            $dotColor    = '#64748b';
                            $statusClass = 'order-status-default';
                            $statusLabel = !empty($status) ? ucfirst($status) : 'Pending';
                            break;
                    }

                    $paymentStatus = strtolower(trim((string)($order['payment_status'] ?? '')));
                    switch ($paymentStatus) {
                        case 'paid':
                            $paymentBadgeClass = 'payment-status-paid';
                            $paymentLabel      = 'Paid';
                            break;
                        case 'pending':
                            $paymentBadgeClass = 'payment-status-pending';
                            $paymentLabel      = 'Pending';
                            break;
                        case 'failed':
                            $paymentBadgeClass = 'payment-status-failed';
                            $paymentLabel      = 'Failed';
                            break;
                        case 'refunded':
                            $paymentBadgeClass = 'payment-status-refunded';
                            $paymentLabel      = 'Refunded';
                            break;
                        default:
                            $paymentBadgeClass = 'payment-status-default';
                            $paymentLabel      = !empty($paymentStatus) ? ucfirst($paymentStatus) : 'Pending';
                            break;
                    }
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
                                <span class="status-dot" style="background: <?= $dotColor ?>;"></span>
                                <span class="order-status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <div style="font-size: 12px; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 5px;">
                                <span>Payment:</span>
                                <span class="payment-status-badge <?= $paymentBadgeClass ?>">
                                    <?= htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
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
                <img src="../assets/images/shopping-trolley.png" width="60" class="mb-4" alt="No orders">
                <h3>No Orders Yet</h3>
                <p class="text-muted">You haven't placed any orders yet.</p>
                <a href="/products/" class="contact-submit"
    >Browse Products</a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>