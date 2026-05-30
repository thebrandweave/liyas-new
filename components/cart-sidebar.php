<?php
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}
$cartBase = rtrim(BASE_URL, '/');
?>
<div id="cart-overlay" class="cart-overlay" aria-hidden="true"></div>

<aside id="cart-sidebar" class="liyas-cart-drawer" aria-label="Shopping cart">
    <div class="cart-header">
        <h2>My Cart <span id="cart-drawer-count" class="cart-drawer-count"></span></h2>
        <button type="button" id="close-cart-btn" class="close-cart-btn" aria-label="Close cart">&times;</button>
    </div>

    <div class="cart-body">
        <p class="cart-empty-message">Your cart is empty.</p>
    </div>

    <div class="cart-footer">
        <div class="cart-subtotal-row">
            <span>Subtotal</span>
            <strong id="subtotal-price">₹0.00</strong>
        </div>
        <p class="cart-footer-note">Taxes and shipping calculated at checkout.</p>
        <a href="<?php echo $cartBase; ?>/checkout.php" class="checkout-btn btn-checkout-primary">Proceed to checkout</a>
    </div>
</aside>
