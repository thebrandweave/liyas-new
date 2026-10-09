<?php
require_once __DIR__ . '/zone_context.php';

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("
    SELECT o.*, p.product_name, p.name as fallback_name, p.case_price
    FROM orders o
    LEFT JOIN products p ON o.product_id = p.product_id
    WHERE o.order_id = ? AND o.zone_id = ?
");
$stmt->execute([$order_id, $zone_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo renderZoneNotFound("Order not found in this zone.");
    exit;
}

if ((int)$order['is_zone_read'] === 0) {
    $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE order_id = ?")->execute([$order_id]);
    $order['is_zone_read'] = 1;
}

$error = '';
$order_items = getOrderItems($pdo, $order_id);
$itemsBaseTotal = 0.0;
foreach ($order_items as $oi) {
    $itemsBaseTotal += (float)$oi['line_total'];
}
if ($itemsBaseTotal <= 0 && (float)$order['total_amount'] > 0) {
    $itemsBaseTotal = (float)$order['total_amount'] + (float)$order['discount'];
}
if ($itemsBaseTotal <= 0) {
    $itemsBaseTotal = ($order['unit_price'] ?: 0) * ($order['quantity'] ?: 1);
}
$baseAmount = $itemsBaseTotal;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_discount'])) {
    $discount = (float)($_POST['discount'] ?? 0);

    if ($discount < 0) {
        $error = "Discount cannot be negative.";
    } elseif ($discount > $baseAmount) {
        $error = "Discount cannot exceed the base order amount (" . formatCurrency($baseAmount) . ").";
    } else {
        $total_amount = max(0, $baseAmount - $discount);
        try {
            $up = $pdo->prepare("UPDATE orders SET discount = ?, total_amount = ?, updated_at = NOW() WHERE order_id = ? AND zone_id = ?");
            $up->execute([$discount, $total_amount, $order_id, $zone_id]);

            header("Location: " . zone_url($zone_slug, 'order', ['id' => $order_id, 'updated' => 1]));
            exit;
        } catch (PDOException $e) {
            $error = "Error updating discount: " . $e->getMessage();
        }
    }
}

$displayOrderNum = $order['order_number'] ?: ('#' . $order['order_id']);
$shopName = htmlspecialchars($order['shop_name'] ?: ($order['customer_name'] ?: 'Customer'));
$prodName = htmlspecialchars($order['product_name'] ?: ($order['fallback_name'] ?: 'Water'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Edit Discount - Order <?= htmlspecialchars($displayOrderNum) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--body-bg);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 60px;
        }

        .edit-nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 12px 16px;
        }

        .nav-inner {
            max-width: 580px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .back-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 8px;
            background: #eff6ff;
        }

        .container {
            max-width: 580px;
            margin: 20px auto;
            padding: 0 16px;
        }

        .card-main {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 22px;
            box-shadow: var(--shadow-card);
        }

        .card-header-title {
            font-size: 19px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }

        .card-header-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 18px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 14px;
            border-bottom: 1px dashed #f1f5f9;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .form-group {
            margin-top: 20px;
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .input-group {
            position: relative;
        }

        .input-currency-prefix {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            font-weight: 700;
            color: #64748b;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px 12px 34px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            font-family: inherit;
            color: #0f172a;
            transition: all 0.15s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        /* Preset buttons */
        .preset-grid {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .btn-preset {
            padding: 6px 12px;
            border-radius: 20px;
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-preset:hover, .btn-preset:active {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }

        /* New Total Panel */
        .total-preview-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 14px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 16px;
            font-weight: 800;
        }

        .btn-save {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            background: #2563eb;
            color: #ffffff;
            font-size: 15px;
            font-weight: 800;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(37,99,235,0.25);
            transition: all 0.15s ease;
        }

        .btn-save:hover, .btn-save:active {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

    <nav class="edit-nav">
        <div class="nav-inner">
            <a href="<?= zone_url($zone_slug, 'order', ['id' => $order_id]) ?>" class="back-link">
                <i class='bx bx-arrow-back'></i> Order
            </a>
            <span style="font-size: 13px; font-weight: 700; color: #64748b;">
                <?= htmlspecialchars($displayOrderNum) ?>
            </span>
        </div>
    </nav>

    <main class="container">
        <?php if (!empty($error)): ?>
            <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="card-main">
            <h1 class="card-header-title">
                <i class='bx bx-purchase-tag' style="color: #2563eb;"></i>
                Edit Order Discount
            </h1>
            <p class="card-header-desc">
                Adjust order pricing discount for <?= $shopName ?>.
            </p>

            <div class="info-row">
                <span style="color: var(--text-muted);">Shop / Customer:</span>
                <span style="font-weight: 700; color: #0f172a;"><?= $shopName ?></span>
            </div>
            <div class="info-row">
                <span style="color: var(--text-muted);">Product:</span>
                <span style="font-weight: 700; color: #0f172a;"><?= $prodName ?> (<?= (int)$order['quantity'] ?> Cases)</span>
            </div>
            <div class="info-row">
                <span style="color: var(--text-muted);">Base Amount:</span>
                <span style="font-weight: 700; color: #0f172a;"><?= formatCurrency($baseAmount) ?></span>
            </div>

            <form action="<?= zone_url($zone_slug, 'edit-order', ['id' => $order_id]) ?>" method="POST" id="discountForm">
                <div class="form-group">
                    <label class="form-label" for="discount">Discount Amount (₹)</label>
                    <div class="input-group">
                        <span class="input-currency-prefix">₹</span>
                        <input type="number" step="0.01" min="0" max="<?= $baseAmount ?>" name="discount" id="discount" class="form-control" value="<?= htmlspecialchars($_POST['discount'] ?? $order['discount']) ?>" autofocus required>
                    </div>

                    <!-- Preset buttons for rapid field use -->
                    <div class="preset-grid">
                        <button type="button" class="btn-preset" onclick="setDiscount(0)">₹0 (Clear)</button>
                        <button type="button" class="btn-preset" onclick="setDiscount(20)">₹20</button>
                        <button type="button" class="btn-preset" onclick="setDiscount(50)">₹50</button>
                        <button type="button" class="btn-preset" onclick="setDiscount(100)">₹100</button>
                        <button type="button" class="btn-preset" onclick="setDiscount(200)">₹200</button>
                    </div>
                </div>

                <div class="total-preview-box">
                    <div class="total-row">
                        <span style="color: #1e3a8a;">New Net Order Total:</span>
                        <span id="displayNewTotal" style="color: #2563eb; font-size: 20px;"><?= formatCurrency($order['total_amount']) ?></span>
                    </div>
                </div>

                <button type="submit" name="save_discount" class="btn-save">
                    <i class='bx bx-check'></i> Save &amp; Apply Discount
                </button>
            </form>
        </div>
    </main>

    <script>
        const baseAmount = <?= (float)$baseAmount ?>;
        const discountInput = document.getElementById('discount');
        const displayNewTotal = document.getElementById('displayNewTotal');

        function setDiscount(val) {
            discountInput.value = val;
            updateTotal();
        }

        function updateTotal() {
            let disc = parseFloat(discountInput.value) || 0;
            if (disc < 0) disc = 0;
            if (disc > baseAmount) disc = baseAmount;
            const net = Math.max(0, baseAmount - disc);
            displayNewTotal.textContent = '₹' + net.toFixed(2);
        }

        discountInput.addEventListener('input', updateTotal);
        updateTotal();
    </script>
</body>
</html>
