<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/config.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$user_id = $_SESSION['user_id'];

try {

    $stmt = $pdo->prepare("
        SELECT 
            p.product_id,
            p.name,
            p.price,
            p.image,
            ci.quantity
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.product_id
        WHERE ci.user_id = ?
    ");

    $stmt->execute([$user_id]);

    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Cart</title>

    <style>

        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
            font-family:Arial, sans-serif;
        }

        body{
            background:#f5f5f5;
            padding:30px;
        }

        .cart-container{
            max-width:1100px;
            margin:auto;
            background:#fff;
            padding:25px;
            border-radius:10px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
        }

        .cart-title{
            font-size:32px;
            margin-bottom:25px;
            font-weight:bold;
        }

        .cart-item{
            display:flex;
            align-items:center;
            justify-content:space-between;
            border-bottom:1px solid #ddd;
            padding:20px 0;
            gap:20px;
        }

        .cart-left{
            display:flex;
            align-items:center;
            gap:20px;
            flex:1;
        }

        .cart-image{
            width:120px;
            height:120px;
            object-fit:cover;
            border-radius:8px;
            border:1px solid #ddd;
        }

        .cart-details h3{
            font-size:22px;
            margin-bottom:10px;
        }

        .cart-details p{
            margin-bottom:8px;
            color:#555;
        }

        .cart-right{
            text-align:right;
        }

        .quantity{
            margin-bottom:10px;
            font-size:18px;
        }

        .price{
            font-size:22px;
            font-weight:bold;
            color:#000;
        }

        .empty-cart{
            text-align:center;
            padding:80px 20px;
            font-size:24px;
            color:#666;
        }

        .cart-total{
            text-align:right;
            margin-top:30px;
        }

        .cart-total h2{
            font-size:30px;
            margin-bottom:20px;
        }

        .checkout-btn{
            display:inline-block;
            padding:15px 30px;
            background:#000;
            color:#fff;
            text-decoration:none;
            border-radius:6px;
            font-size:18px;
            transition:0.3s;
        }

        .checkout-btn:hover{
            opacity:0.85;
        }

        @media(max-width:768px){

            body{
                padding:15px;
            }

            .cart-item{
                flex-direction:column;
                align-items:flex-start;
            }

            .cart-left{
                flex-direction:column;
                align-items:flex-start;
            }

            .cart-image{
                width:100%;
                height:250px;
            }

            .cart-right{
                width:100%;
                text-align:left;
            }

        }

    </style>

</head>
<body>

<div class="cart-container">

    <h1 class="cart-title">My Cart</h1>

    <?php if(count($cart_items) > 0): ?>

        <?php
            $grand_total = 0;
        ?>

        <?php foreach($cart_items as $item): ?>

            <?php
                $total = $item['price'] * $item['quantity'];
                $grand_total += $total;

                $imagePath = BASE_URL . '/admin/uploads/products/' . $item['image'];
            ?>

            <div class="cart-item">

                <div class="cart-left">

                    <img 
                        src="<?= htmlspecialchars($imagePath) ?>" 
                        class="cart-image"
                        alt="<?= htmlspecialchars($item['name']) ?>"
                    >

                    <div class="cart-details">

                        <h3><?= htmlspecialchars($item['name']) ?></h3>

                        <p>Product ID: <?= $item['product_id'] ?></p>

                    </div>

                </div>

                <div class="cart-right">

                    <div class="quantity">
                        Quantity: <?= $item['quantity'] ?>
                    </div>

                    <div class="price">
                        ₹<?= number_format($total, 2) ?>
                    </div>

                </div>

            </div>

        <?php endforeach; ?>

        <div class="cart-total">

            <h2>
                Total: ₹<?= number_format($grand_total, 2) ?>
            </h2>

            <a href="checkout" class="checkout-btn">
                Proceed to Checkout
            </a>

        </div>

    <?php else: ?>

        <div class="empty-cart">
            Your cart is empty
        </div>

    <?php endif; ?>

</div>

</body>
</html>