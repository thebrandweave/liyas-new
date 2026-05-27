<?php
// Ensure BASE_URL is defined
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}
?>
<!-- CART SIDEBAR -->
<div id="cart-sidebar">

    <div class="cart-header">
        <h2>Your Cart</h2>

        <button id="close-cart-btn">
            ×
        </button>
    </div>

    <div class="cart-body">

        <p class="cart-empty-message">
            Your cart is empty.
        </p>

    </div>

    <div class="cart-footer">

        <h3>
            Subtotal:
            <span id="subtotal-price">₹0.00</span>
        </h3>

        <a href="<?php echo rtrim(BASE_URL, '/') . '/cart/'; ?>" class="checkout-btn">
            View Cart
        </a>

    </div>

</div>
