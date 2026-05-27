<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/login/?redirect=' . urlencode('/cart/'));
    exit;
}

$cartBase = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart | LIYAS Mineral Water</title>
    <link rel="stylesheet" href="<?php echo $cartBase; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo $cartBase; ?>/assets/css/cart.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>const BASE_URL = '<?php echo BASE_URL; ?>';</script>
</head>
<body>

<?php include __DIR__ . '/../components/navbar.php'; ?>

<div class="cart-page-wrap">
    <div class="cart-page-grid">
        <div class="cart-page-main">
            <h1 class="cart-page-title">Shopping Cart</h1>
            <div id="cart-page-items">
                <p style="padding:2rem;text-align:center;color:#64748b;">Loading cart…</p>
            </div>
        </div>
        <aside class="cart-page-summary">
            <h3>Order Summary</h3>
            <div class="row"><span>Subtotal</span><span id="cart-page-total">₹0.00</span></div>
            <div class="row total"><span>Total</span><span id="cart-page-total-duplicate">₹0.00</span></div>
            <a id="cart-page-checkout" href="<?php echo $cartBase; ?>/checkout.php" class="checkout-btn btn-checkout-primary" style="margin-top:1rem;">Proceed to checkout</a>
            <a href="<?php echo $cartBase; ?>/products/" class="checkout-btn btn-view-cart" style="margin-top:0.5rem;">Continue shopping</a>
        </aside>
    </div>
</div>

<?php include __DIR__ . '/../components/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var syncTotal = function () {
        var t = document.getElementById('cart-page-total');
        var d = document.getElementById('cart-page-total-duplicate');
        if (t && d) { d.textContent = t.textContent; }
    };
    var obs = new MutationObserver(syncTotal);
    var t = document.getElementById('cart-page-total');
    if (t) { obs.observe(t, { childList: true, characterData: true, subtree: true }); }
});
</script>
</body>
</html>
