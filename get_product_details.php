<?php
// Set content type to JSON
header('Content-Type: application/json');

// Include database configuration
require_once 'config/config.php';

// --- Response Structure ---
$response = [
    'success' => false,
    'message' => 'An error occurred.',
    'data' => null
];

// --- Input Validation ---
if (!isset($_GET['id']) || !filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $response['message'] = 'Invalid or missing product ID.';
    echo json_encode($response);
    exit;
}
$product_id = (int)$_GET['id'];

try {
    // --- Fetch Product Details ---
    $product_stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $product_stmt->execute([$product_id]);
    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $response['message'] = 'Product not found.';
        echo json_encode($response);
        exit;
    }
    
    // Ensure image path is complete if it's not null, otherwise provide a default
    if (!empty($product['image'])) {
        $product['image_url'] = BASE_URL . '/admin/uploads/products/' . $product['image'];
    } else {
        // You can replace this with a path to a standard placeholder image
        $product['image_url'] = BASE_URL . '/assets/images/liyas-bottle1.png';
    }


    // --- Fetch Approved Reviews ---
    $reviews_stmt = $pdo->prepare("
        SELECT r.rating, r.review_text, r.created_at, u.name AS full_name
        FROM reviews r
        JOIN users u ON r.user_id = u.user_id
        WHERE r.product_id = ? AND r.status = 'approved'
        ORDER BY r.created_at DESC
    ");
    $reviews_stmt->execute([$product_id]);
    $reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Combine Data ---
    $data = [
        'product' => $product,
        'reviews' => $reviews
    ];

    // --- Final Response ---
    $response['success'] = true;
    $response['message'] = 'Data fetched successfully.';
    $response['data'] = $data;

} catch (PDOException $e) {
    // In a production environment, you should log this error instead of exposing it.
    $response['message'] = 'A database error occurred.';
    // $response['message'] = 'Database error: ' . $e->getMessage(); // For debugging only
}

echo json_encode($response);
?>
