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
| ORDER
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
| ORDER ITEMS
|--------------------------------------------------------------------------
*/

$itemStmt = $pdo->prepare("
SELECT *
FROM order_items
WHERE order_id = ?
");

$itemStmt->execute([$orderId]);

$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| SHIPPING ADDRESS
|--------------------------------------------------------------------------
*/

$address = [];

if (!empty($order['shipping_address_id'])) {

    $addressStmt = $pdo->prepare("
        SELECT *
        FROM addresses
        WHERE address_id = ?
    ");

    $addressStmt->execute([$order['shipping_address_id']]);

    $address = $addressStmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="card border-0 shadow-sm rounded-4 mb-4">

    <div class="card-body">

        <h5 class="fw-bold mb-3">
            Delivery Details
        </h5>

        <p class="mb-2">
            <strong>
                <?= htmlspecialchars($address['full_name'] ?? '') ?>
            </strong>
        </p>

        <p class="text-muted mb-2">
            <?= htmlspecialchars($address['address_line_1'] ?? '') ?>
            <?= htmlspecialchars($address['address_line_2'] ?? '') ?>
        </p>

        <p class="text-muted mb-2">
            <?= htmlspecialchars($address['city'] ?? '') ?>,
            <?= htmlspecialchars($address['state'] ?? '') ?>
            <?= htmlspecialchars($address['pincode'] ?? '') ?>
        </p>

        <p>
            <?= htmlspecialchars($address['phone'] ?? '') ?>
        </p>

    </div>

</div>