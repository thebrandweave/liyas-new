<?php
/**
 * Cart drawer skeletal containers + scripts.
 * Set $load_product_modal = true before navbar on product pages.
 * Set $hide_cart = true to skip cart entirely (admin pages, etc.).
 */
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

if (!empty($hide_cart)) {
    return;
}
?>

<div id="cart-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1090; display: none;"></div>

<div id="cart-sidebar" style="position: fixed; top: 0; right: -400px; width: 100%; max-width: 400px; height: 100vh; background: #fff; z-index: 1095; box-shadow: -5px 0 25px rgba(0,0,0,0.15); transition: right 0.3s ease; display: flex; flex-direction: column;">
    
    <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid #f1f5f9;">
        <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #0b2e4e;">
            My Cart <span id="cart-drawer-count" style="font-size: 0.9rem; font-weight: 400; color: #64748b;"></span>
        </h3>
        <button type="button" id="close-cart-btn" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; padding: 0; line-height: 1;">&times;</button>
    </div>

    <div class="cart-body" style="flex: 1; overflow-y: auto; padding: 0 1.5rem;"></div>

    <div class="cart-footer" style="padding: 1.5rem; border-top: 1px solid #f1f5f9; background: #f8fafc; display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <span style="font-size: 1rem; font-weight: 600; color: #64748b;">Subtotal:</span>
            <span id="subtotal-price" style="font-size: 1.25rem; font-weight: 700; color: #0b2e4e;">₹0.00</span>
        </div>
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <a href="<?= BASE_URL ?>/cart.php" class="btn-view-cart" style="display: block; text-align: center; border: 2px solid #e2e8f0; background: #fff; color: #0b2e4e; padding: 12px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 0.95rem;">View Full Cart</a>
            <a href="<?= BASE_URL ?>/checkout.php" class="btn-checkout-primary" style="display: block; text-align: center; background: #4ad2e2; color: #fff; padding: 12px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 0.95rem;">Proceed to Checkout</a>
        </div>
    </div>
</div>

<script>
    const userIsLoggedIn = <?php echo json_encode(isset($_SESSION['user_id'])); ?>;
</script>

<?php if (!empty($load_product_modal)): ?>
<script src="<?php echo BASE_URL; ?>/assets/js/product-modal.js?v=2"></script>
<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/cart.js?t=<?= time(); ?>"></script>