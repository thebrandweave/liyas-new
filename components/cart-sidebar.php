<div id="cart-overlay" class="cart-overlay"></div>

<div id="cart-sidebar" class="liyas-cart-drawer">

    <div class="cart-header">
        <h2>Your Cart</h2>
        <span id="cart-drawer-count"></span>

        <button id="close-cart-btn" type="button">
            ×
        </button>
    </div>

    <div class="cart-body">
        <div class="cart-empty">
            Loading...
        </div>
    </div>

    <div class="cart-footer">

        <div class="cart-subtotal-row">
            <span>Subtotal</span>
            <strong id="subtotal-price">₹0.00</strong>
        </div>

        <p class="cart-footer-note">
            Shipping calculated at checkout
        </p>

        <a href="<?php echo BASE_URL; ?>/cart/"
           class="checkout-btn btn-view-cart">
            View Cart
        </a>

        <a href="<?php echo BASE_URL; ?>/checkout.php"
           class="checkout-btn btn-checkout-primary">
            Checkout
        </a>

    </div>

</div>