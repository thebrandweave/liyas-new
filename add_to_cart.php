<?php
session_start();
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'User not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$product_id = (int) ($_POST['product_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$user_id = (int) $_SESSION['user_id'];

if ($product_id <= 0) {
    echo json_encode(['error' => 'Invalid product']);
    exit;
}

try {
        $stmt = $pdo->prepare("SELECT * FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$user_id, $product_id]);
        $existing_item = $stmt->fetch();

        if ($existing_item) {
            $newQty = (int) $existing_item['quantity'] + $quantity;
            $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?");
            $stmt->execute([$newQty, $existing_item['cart_item_id']]);
        } else {
            // Insert new item
            $stmt = $pdo->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $product_id, $quantity]);
        }
} catch (PDOException $e) {
    error_log("Cart Error: " . $e->getMessage());
    echo json_encode(['error' => 'A database error occurred.']);
    exit;
}

echo json_encode(['success' => true]);
