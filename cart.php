<?php
// Start session to tracking logging data safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure configuration definitions are loaded
require_once __DIR__ . '/config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - Liyas International</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body {
            background-color: #f8fafc;
            color: #334155;
            font-family: system-ui, -apple-system, sans-serif;
            padding-top: 100px; /* Spacing accounts for fixed navbar height */
        }
        
        .cart-page-title {
            color: #0b2e4e;
            font-weight: 700;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            position: sticky;
            top: 110px; /* Keeps the pricing card floating smoothly when scrolling items */
        }

        .summary-title {
            color: #0b2e4e;
            font-size: 1.25rem;
            font-weight: 700;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 1rem;
        }

        .checkout-btn {
            display: block;
            width: 100%;
            text-align: center;
            background: #4ad2e2;
            color: #fff;
            padding: 14px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            transition: background 0.2s ease, transform 0.1s ease;
        }

        .checkout-btn:hover {
            background: #3bc0d0;
            color: #fff;
        }

        .checkout-btn:active {
            transform: scale(0.98);
        }
    </style>
</head>
<body>

    <?php include __DIR__ . '/components/navbar.php'; ?>

    <div class="container my-5">
        <h1 class="cart-page-title">Shopping Cart</h1>
        
        <div class="row g-4">
            <div class="col-lg-8">
                <div id="cart-page-items">
                    </div>
            </div>

            <div class="col-lg-4">
                <div class="summary-card">
                    <div class="summary-title">Order Summary</div>
                    
                    <div class="summary-row">
                        <span style="color: #64748b; font-weight: 500;">Subtotal</span>
                        <span id="cart-page-total" style="color: #0b2e4e; font-weight: 700; font-size: 1.15rem;">₹0.00</span>
                    </div>

                    <div class="summary-row" style="border-top: 1px dashed #e2e8f0; padding-top: 1rem; margin-top: 1rem;">
                        <span style="color: #0b2e4e; font-weight: 700; font-size: 1.1rem;">Estimated Total</span>
                        <span style="color: #4ad2e2; font-weight: 800; font-size: 1.3rem;" class="total-mirror-price">
                            <script>
                                // Automatically sync total-mirror-price content with the core layout total element
                                document.addEventListener('DOMContentLoaded', () => {
                                    const targetNode = document.getElementById('cart-page-total');
                                    const mirrorNode = document.querySelector('.total-mirror-price');
                                    if(targetNode && mirrorNode) {
                                        const observer = new MutationObserver(() => { mirrorNode.textContent = targetNode.textContent; });
                                        observer.observe(targetNode, { childList: true, characterData: true, subtree: true });
                                    }
                                });
                            </script>
                        </span>
                    </div>

                    <div class="mt-4">
                        <a href="<?= BASE_URL ?>/checkout.php" id="cart-page-checkout" class="checkout-btn">
                            Proceed to Checkout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>