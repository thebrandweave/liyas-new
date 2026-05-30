<?php
session_start();
require_once __DIR__ . '/config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/login/index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$cart_items = [];
$shipping_addresses = [];
$total_amount = 0;
// Sync guest cart if cookie exists
if (isset($_COOKIE['liyas_guest_cart'])) {
    $guest_cart = json_decode($_COOKIE['liyas_guest_cart'], true);
    if (is_array($guest_cart) && !empty($guest_cart)) {
        try {
            $pdo->beginTransaction();
            foreach ($guest_cart as $item) {
                $product_id = (int) ($item['product_id'] ?? $item['id'] ?? 0);
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                if ($product_id <= 0) continue;

                // Check if product exists in database cart for this user
                $stmt = $pdo->prepare("SELECT * FROM cart_items WHERE user_id = ? AND product_id = ?");
                $stmt->execute([$user_id, $product_id]);
                $existing_item = $stmt->fetch();

                if ($existing_item) {
                    $newQty = (int) $existing_item['quantity'] + $quantity;
                    $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?");
                    $stmt->execute([$newQty, $existing_item['cart_item_id']]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
                    $stmt->execute([$user_id, $product_id, $quantity]);
                }
            }
            $pdo->commit();
            
            // Clear the cookie
            setcookie('liyas_guest_cart', '', time() - 3600, '/');
            unset($_COOKIE['liyas_guest_cart']);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Failed to sync guest cart: " . $e->getMessage());
        }
    }
}

// Fetch cart items
try {
    $stmt = $pdo->prepare("SELECT ci.*, p.name, p.price, p.image FROM cart_items ci JOIN products p ON ci.product_id = p.product_id WHERE ci.user_id = ?");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cart_items)) {
        header("Location: " . rtrim(BASE_URL, '/') . "/products/"); // Redirect to products if cart is empty
        exit;
    }

    foreach ($cart_items as $item) {
        $total_amount += ($item['quantity'] * $item['price']);
    }

    // Fetch user's shipping addresses
    $stmt = $pdo->prepare("SELECT * FROM shipping_addresses WHERE user_id = ? ORDER BY is_default DESC, address_id DESC");
    $stmt->execute([$user_id]);
    $shipping_addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Checkout page error: " . $e->getMessage());
    $error = "A database error occurred. Please try again later.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $selected_address_id = filter_var($_POST['shipping_address_id'] ?? '', FILTER_VALIDATE_INT);
    $payment_method = trim($_POST['payment_method'] ?? '');

    // Handle new address submission if the form was active
    $is_new_address_submission = !empty($_POST['new_full_name']);
    if ($is_new_address_submission) {
        $new_full_name = trim($_POST['new_full_name'] ?? '');
        $new_address_line_1 = trim($_POST['new_address_line_1'] ?? '');
        $new_address_line_2 = trim($_POST['new_address_line_2'] ?? '');
        $new_city = trim($_POST['new_city'] ?? '');
        $new_state = trim($_POST['new_state'] ?? '');
        $new_zip_code = trim($_POST['new_zip_code'] ?? '');
        $new_country = trim($_POST['new_country'] ?? '');
        $new_phone_number = trim($_POST['new_phone_number'] ?? '');
        $set_as_default_address = isset($_POST['set_as_default_address']) ? 1 : 0;

        // Server-side validation for new address fields
        if (empty($new_full_name)) { $error = "Full Name is required for new address."; }
        if (empty($new_address_line_1)) { $error = "Address Line 1 is required for new address."; }
        if (empty($new_city)) { $error = "City is required for new address."; }
        if (empty($new_state)) { $error = "State is required for new address."; }
        if (empty($new_zip_code)) { $error = "Zip Code is required for new address."; }
        if (empty($new_country)) { $error = "Country is required for new address."; }
        if (empty($new_phone_number)) { $error = "Phone Number is required for new address."; }
        // Basic phone number format check (can be more sophisticated)
        if (!empty($new_phone_number) && !preg_match('/^[0-9+\s()-]+$/', $new_phone_number)) { $error = "Invalid phone number format for new address."; }

        if (empty($error)) { // Proceed to save address only if no validation errors
            try {
                $pdo->beginTransaction();

                // If setting as default, clear previous default for this user
                if ($set_as_default_address) {
                    $stmt_clear_default = $pdo->prepare("UPDATE shipping_addresses SET is_default = FALSE WHERE user_id = ?");
                    $stmt_clear_default->execute([$user_id]);
                }

                $stmt_insert_address = $pdo->prepare("INSERT INTO shipping_addresses (user_id, full_name, address_line_1, address_line_2, city, state, zip_code, country, phone_number, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_insert_address->execute([
                    $user_id, $new_full_name, $new_address_line_1, $new_address_line_2, $new_city, $new_state, $new_zip_code, $new_country, $new_phone_number, $set_as_default_address
                ]);
                $selected_address_id = $pdo->lastInsertId();
                $pdo->commit();

                // After successfully saving, refresh the page to show the new address selected
                // This prevents issues with the newly added address not appearing or being selected for the current order
                header("Location: " . BASE_URL . "/checkout.php"); 
                exit;

            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Failed to save new address: " . $e->getMessage());
                $error = "Failed to save new address. Please try again. (DB Error)";
            }
        }
    }
    
    if (!$selected_address_id && !$is_new_address_submission) {
        $error = "Please select an existing shipping address or add a new one.";
    } elseif (empty($payment_method)) {
        $error = "Please select a payment method.";
    } 
    
    if (empty($error)) { // Proceed only if no errors from address saving
        try {
            $pdo->beginTransaction();

            // 1. Insert into orders table
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, order_date, total_amount, status, shipping_address_id, payment_method) VALUES (?, NOW(), ?, ?, ?, ?)");
            $stmt->execute([$user_id, $total_amount, 'pending', $selected_address_id, $payment_method]);
            $order_id = $pdo->lastInsertId();

            // 2. Insert into order_items table and clear cart items
            $stmt_order_item = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase) VALUES (?, ?, ?, ?)");
            $stmt_delete_cart = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");

            foreach ($cart_items as $item) {
                $stmt_order_item->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);
                $stmt_delete_cart->execute([$user_id, $item['product_id']]);
            }

            $pdo->commit();
            header("Location: " . BASE_URL . "/order_confirmation.php?order_id=" . $order_id);
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Order placement error: " . $e->getMessage());
            $error = "Failed to place order. Please try again.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Checkout | Liyas Mineral Water</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="assets/images/logo/logo-bg.jpg">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/cart.css">
    <link rel="stylesheet" href="assets/css/checkout.css">
    
</head>
<body>
    <div class="page-container">
        <h1>Proceed to Checkout</h1>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="checkout-grid">
                <!-- Left Column (Shipping Information) -->
                <div>
                    <div class="card-section">
                        <h2 class="section-title">Shipping Information</h2>
                        <?php if (!empty($shipping_addresses)): ?>
                            <div class="address-list">
                                <?php foreach ($shipping_addresses as $address): ?>
                                    <div class="address-item <?= $address['is_default'] ? 'selected' : '' ?>">
                                        <label>
                                            <input type="radio" name="shipping_address_id" value="<?= $address['address_id'] ?>" <?= $address['is_default'] ? 'checked' : '' ?> required>
                                            <div style="flex:1;">
                                                <p><strong><?= htmlspecialchars($address['full_name']) ?></strong></p>
                                                <p><?= htmlspecialchars($address['address_line_1']) ?><?php echo !empty($address['address_line_2']) ? ', ' . htmlspecialchars($address['address_line_2']) : ''; ?></p>
                                                <p><?= htmlspecialchars($address['city']) ?>, <?= htmlspecialchars($address['state']) ?> - <?= htmlspecialchars($address['zip_code']) ?></p>
                                                <p><?= htmlspecialchars($address['country']) ?></p>
                                                <p>Phone: <?= htmlspecialchars($address['phone_number']) ?></p>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info"><i class="fas fa-info-circle"></i> No saved addresses. Please add a new one below.</div>
                        <?php endif; ?>

                        <button type="button" class="add-address-btn" onclick="toggleNewAddressForm()">Add New Address</button>
                        
                        <div id="newAddressForm" class="add-new-address-form">
                            <h3>Add New Shipping Address</h3>
                            <div class="form-group"><label for="new_full_name">Full Name</label><input type="text" id="new_full_name" name="new_full_name" value="<?= htmlspecialchars($_POST['new_full_name'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_address_line_1">Address Line 1</label><input type="text" id="new_address_line_1" name="new_address_line_1" value="<?= htmlspecialchars($_POST['new_address_line_1'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_address_line_2">Address Line 2 (Optional)</label><input type="text" id="new_address_line_2" name="new_address_line_2" value="<?= htmlspecialchars($_POST['new_address_line_2'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_city">City</label><input type="text" id="new_city" name="new_city" value="<?= htmlspecialchars($_POST['new_city'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_state">State</label><input type="text" id="new_state" name="new_state" value="<?= htmlspecialchars($_POST['new_state'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_zip_code">Zip Code</label><input type="text" id="new_zip_code" name="new_zip_code" value="<?= htmlspecialchars($_POST['new_zip_code'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_country">Country</label><input type="text" id="new_country" name="new_country" value="<?= htmlspecialchars($_POST['new_country'] ?? '') ?>"></div>
                            <div class="form-group"><label for="new_phone_number">Phone Number</label><input type="tel" id="new_phone_number" name="new_phone_number" value="<?= htmlspecialchars($_POST['new_phone_number'] ?? '') ?>"></div>
                            <div class="form-group"><label><input type="checkbox" name="set_as_default_address" <?= isset($_POST['set_as_default_address']) ? 'checked' : '' ?>> Set as default address</label></div>
                        </div>
                    </div>
                </div>

                <!-- Right Column (Order Summary, Payment, Place Order) -->
                <div>
                    <!-- Order Summary -->
                    <div class="card-section">
                        <h2 class="section-title">Order Summary</h2>
                        <?php foreach ($cart_items as $item): ?>
                            <div class="cart-item">
                                <img src="admin/uploads/products/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="cart-item-img">
                                <div class="cart-item-details">
                                    <h5 class="cart-item-title"><?= htmlspecialchars($item['name']) ?></h5>
                                    <p class="cart-item-quantity">Quantity: <?= htmlspecialchars($item['quantity']) ?></p>
                                    <p class="cart-item-price">₹<?= number_format($item['price'] * $item['quantity'], 2) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="final-total">
                            <span>Subtotal:</span>
                            <span>₹<?= number_format($total_amount, 2) ?></span>
                        </div>
                    </div>

                    <!-- Payment Method -->
                    <div class="card-section">
                        <h2 class="section-title">Payment Method</h2>
                        <div class="payment-option">
                            <label><input type="radio" name="payment_method" value="cod" checked required> Cash on Delivery (COD)</label>
                        </div>
                        <!-- Add more payment options here if needed -->
                    </div>

                    <!-- Place Order -->
                    <div class="card-section">
                        <button type="submit" name="place_order" class="place-order-btn">Place Order</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const addressItems = document.querySelectorAll('.address-item');
            addressItems.forEach(item => {
                item.addEventListener('click', () => {
                    addressItems.forEach(addr => addr.classList.remove('selected'));
                    item.classList.add('selected');
                    item.querySelector('input[type="radio"]').checked = true;
                });
            });

            // Handle new address form toggle
            window.toggleNewAddressForm = function() {
                const newAddressForm = document.getElementById('newAddressForm');
                newAddressForm.classList.toggle('active');
                
                // If the form is activated, ensure all fields are required
                // If deactivated, remove required.
                const inputs = newAddressForm.querySelectorAll('input[type="text"], input[type="tel"]');
                inputs.forEach(input => {
                    // Only apply 'required' if the form is now active and the input is not address_line_2 (optional)
                    if (newAddressForm.classList.contains('active') && input.id !== 'new_address_line_2') {
                        input.setAttribute('required', 'required');
                    } else {
                        input.removeAttribute('required');
                    }
                });

                // If adding new address, deselect any previously selected existing address
                if (newAddressForm.classList.contains('active')) {
                    document.querySelectorAll('input[name="shipping_address_id"]').forEach(radio => {
                        radio.checked = false;
                        radio.closest('.address-item')?.classList.remove('selected');
                    });
                }
            };

            // Re-check the form state after page reload (e.g., after new address save)
            // If any of the new address fields have values (meaning there was a POST and error occurred), open the form
            const newAddressForm = document.getElementById('newAddressForm');
            const newFullNameInput = document.getElementById('new_full_name');
            if (newFullNameInput && newFullNameInput.value !== '') {
                newAddressForm.classList.add('active');
                const inputs = newAddressForm.querySelectorAll('input[type="text"], input[type="tel"]');
                inputs.forEach(input => {
                    if (input.id !== 'new_address_line_2') {
                        input.setAttribute('required', 'required');
                    }
                });
            }
        });
    </script>
</body>
</html>