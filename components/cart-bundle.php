<?php
/**
 * Cart drawer + scripts (included from navbar, not footer).
 * Set $load_product_modal = true before navbar on product pages.
 * Set $hide_cart = true to skip cart entirely (admin pages, etc.).
 */
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}

if (!empty($hide_cart)) {
    return;
}

include __DIR__ . '/cart-sidebar.php';
?>
<script>
    // Safely assign to the global window object without re-declaring variables
    window.userIsLoggedIn = <?php echo json_encode(isset($_SESSION['user_id'])); ?>;
</script>
<?php if (!empty($load_product_modal)): ?>
<script src="<?php echo BASE_URL; ?>/assets/js/product-modal.js?v=2"></script>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/cart.js?v=1.0.1"></script>

<script src="<?= BASE_URL ?>/assets/js/cart.js?t=<?= time(); ?>"></script>
