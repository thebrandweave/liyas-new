<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "orders";
$page_title   = "Create Order";
$error = '';

// Load active zones dynamically
$zones_stmt = $pdo->query("SELECT id, name, slug FROM zones WHERE status = 'active' ORDER BY name ASC");
$zones = $zones_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load active products dynamically
$products_stmt = $pdo->query("SELECT product_id, name, product_name, case_price, price, case_stock, stock, net_content, net_content_unit FROM products WHERE status = 'active' ORDER BY name ASC");
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build structured products lookup map for fast, reliable JS lookup
$products_map = [];
foreach ($products as $p) {
    $cPrice = (float)($p['case_price'] ?: $p['price']);
    $cStock = (int)($p['case_stock'] ?: $p['stock']);
    $pName = $p['product_name'] ?: $p['name'];
    $products_map[(int)$p['product_id']] = [
        'id'          => (int)$p['product_id'],
        'name'        => $pName,
        'price'       => $cPrice,
        'stock'       => $cStock,
        'net_content' => $p['net_content'],
        'unit'        => $p['net_content_unit']
    ];
}

// Initial items state for form rendering
$form_items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_order'])) {
    $shop_name     = trim($_POST['shop_name'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $location      = trim($_POST['location'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $zone_id       = (int)($_POST['zone_id'] ?? 0);
    $discount      = (float)($_POST['discount'] ?? 0);

    // Parse multi-product items array
    $raw_items = $_POST['items'] ?? [];
    $parsed_items = [];

    if (is_array($raw_items) && !empty($raw_items)) {
        foreach ($raw_items as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (int)($row['quantity'] ?? 0);
            $rate = (isset($row['rate']) && is_numeric($row['rate']) && (float)$row['rate'] >= 0) ? (float)$row['rate'] : null;

            if ($pid > 0 && $qty > 0) {
                if (isset($parsed_items[$pid])) {
                    $parsed_items[$pid]['quantity'] += $qty;
                    if ($rate !== null) {
                        $parsed_items[$pid]['rate'] = $rate;
                    }
                } else {
                    $parsed_items[$pid] = [
                        'product_id' => $pid,
                        'quantity'   => $qty,
                        'rate'       => $rate
                    ];
                }
                $form_items[] = ['product_id' => $pid, 'quantity' => $qty, 'rate' => $rate];
            }
        }
    } elseif (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0) {
        $pid = (int)$_POST['product_id'];
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $rate = (isset($_POST['rate']) && is_numeric($_POST['rate']) && (float)$_POST['rate'] >= 0) ? (float)$_POST['rate'] : null;
        $parsed_items[$pid] = ['product_id' => $pid, 'quantity' => $qty, 'rate' => $rate];
        $form_items[] = ['product_id' => $pid, 'quantity' => $qty, 'rate' => $rate];
    }

    // Validation
    if (empty($shop_name)) {
        $error = "Shop / Customer Name is required.";
    } elseif ($zone_id <= 0) {
        $error = "Please select a Delivery Zone.";
    } elseif (empty($parsed_items)) {
        $error = "Please select at least one product with quantity of 1 or more.";
    } elseif ($discount < 0) {
        $error = "Discount cannot be negative.";
    } else {
        $product_ids = array_keys($parsed_items);
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $pStmt = $pdo->prepare("SELECT product_id, name, product_name, case_price, price, case_stock, stock, net_content, net_content_unit FROM products WHERE product_id IN ($placeholders)");
        $pStmt->execute($product_ids);
        $dbProducts = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $dbProductsMap = [];
        foreach ($dbProducts as $dp) {
            $dbProductsMap[(int)$dp['product_id']] = $dp;
        }

        $base_total = 0.0;
        $total_cases = 0;
        $valid_order_items = [];

        foreach ($parsed_items as $pid => $itemData) {
            if (!isset($dbProductsMap[$pid])) {
                $error = "One or more selected products are invalid or no longer available.";
                break;
            }
            $dp = $dbProductsMap[$pid];
            $defaultPrice = (float)($dp['case_price'] ?: $dp['price']);
            $price = ($itemData['rate'] !== null && (float)$itemData['rate'] > 0) ? (float)$itemData['rate'] : $defaultPrice;
            $qty = (int)$itemData['quantity'];
            $line_total = $price * $qty;
            $base_total += $line_total;
            $total_cases += $qty;

            $valid_order_items[] = [
                'product_id'        => $pid,
                'quantity'          => $qty,
                'price_at_purchase' => $price,
                'line_total'        => $line_total,
                'name'              => $dp['product_name'] ?: $dp['name']
            ];
        }

        if (empty($error)) {
            if ($discount > $base_total) {
                $discount = $base_total;
            }
            $total_amount = max(0, $base_total - $discount);

            // Generate unique order number
            $maxStmt = $pdo->query("SELECT MAX(order_id) FROM orders");
            $maxId = (int)$maxStmt->fetchColumn() + 1;
            $order_number = '' . str_pad($maxId, 3, '0', STR_PAD_LEFT);

            try {
                $pdo->beginTransaction();

                $primary_pid = $valid_order_items[0]['product_id'];
                $primary_unit_price = $valid_order_items[0]['price_at_purchase'];

                $stmt = $pdo->prepare("
                    INSERT INTO orders (
                        order_number, zone_id, shop_name, customer_name, 
                        location, phone, address, product_id, 
                        quantity, unit_price, discount, total_amount, 
                        status, is_zone_read, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, 
                        ?, ?, ?, ?, 
                        ?, ?, ?, ?, 
                        'pending', 0, NOW(), NOW()
                    )
                ");

                $stmt->execute([
                    $order_number,
                    $zone_id,
                    $shop_name,
                    $customer_name ?: $shop_name,
                    $location,
                    $phone,
                    $address,
                    $primary_pid,
                    $total_cases,
                    $primary_unit_price,
                    $discount,
                    $total_amount
                ]);

                $new_order_id = (int)$pdo->lastInsertId();

                // Insert line items
                $insItem = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, quantity, price_at_purchase, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, NOW(), NOW()
                    )
                ");

                foreach ($valid_order_items as $voi) {
                    $insItem->execute([
                        $new_order_id,
                        $voi['product_id'],
                        $voi['quantity'],
                        $voi['price_at_purchase']
                    ]);

                    // Deduct warehouse stock for each product
                    adjustProductStock($pdo, $voi['product_id'], -$voi['quantity'], "New order #{$order_number} created");
                }

                $pdo->commit();

                $itemsSummary = count($valid_order_items) . " products (" . $total_cases . " cases)";
                quickLog($pdo, 'create', 'order', $new_order_id, "Created order #{$order_number} for {$shop_name} [{$itemsSummary}, Zone ID: {$zone_id}, Amount: ₹{$total_amount}]");

                header("Location: index.php?added=1&highlight=" . $new_order_id);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error creating order: " . $e->getMessage();
            }
        }
    }
}

// Initial default row
if (empty($form_items)) {
    $form_items = [['product_id' => '', 'quantity' => 1, 'rate' => '']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Order - Liyas Central Warehouse</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .order-form-container {
            max-width: 980px;
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 14px;
            padding: 2.25rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .form-section-title {
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.25rem;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
        }
        .form-control {
            width: 100%;
            padding: 0.65rem 0.85rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        @media (max-width: 640px) {
            .grid-2 { grid-template-columns: 1fr; }
            .order-form-container { padding: 1.25rem; }
        }

        /* Items Repeater Table Styles */
        .items-table-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-bottom: 0.5rem;
        }
        .items-table th {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            padding: 0 10px 6px 10px;
            border: none;
            text-align: left;
        }
        .items-table td {
            background: #ffffff;
            padding: 10px;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .items-table tr td:first-child {
            border-left: 1px solid #e2e8f0;
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }
        .items-table tr td:last-child {
            border-right: 1px solid #e2e8f0;
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }

        .badge-stock {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
            white-space: nowrap;
        }
        .badge-stock.in-stock { background: #dcfce7; color: #15803d; }
        .badge-stock.low-stock { background: #fef3c7; color: #b45309; }
        .badge-stock.out-of-stock { background: #fee2e2; color: #b91c1c; }

        .btn-add-product-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #eff6ff;
            color: #2563eb;
            border: 1.5px dashed #93c5fd;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-add-product-row:hover {
            background: #dbeafe;
            border-color: #2563eb;
            transform: translateY(-1px);
        }

        .btn-remove-row {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 6px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-remove-row:hover {
            background: #fecaca;
        }

        .price-summary-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.25rem;
            margin-top: 1.5rem;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            font-size: 14px;
        }
        .summary-row.total {
            border-top: 2px solid #e2e8f0;
            margin-top: 8px;
            padding-top: 10px;
            font-size: 19px;
            font-weight: 700;
            color: #0f172a;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <a href="index.php" style="color: inherit; text-decoration: none;">Orders</a>
                    <i class='bx bx-chevron-right'></i>
                    <span>+ Add Order (Multi-Product)</span>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back to Orders
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem; max-width: 980px;">
                        <i class='bx bx-error-circle'></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div class="order-form-container">
                    <h2 style="font-size: 21px; font-weight: 700; margin-bottom: 0.4rem; color: #111827;">Create New Warehouse Order</h2>
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 1.75rem;">
                        Add single or multiple products in one order. Real-time rates, live warehouse stock, and automated totals calculation.
                    </p>

                    <form action="create.php" method="POST" id="orderForm">
                        <!-- Shop / Customer Information -->
                        <div class="form-section-title">
                            <i class='bx bx-store-alt' style="color: #2563eb; font-size: 20px;"></i>
                            Shop &amp; Customer Details
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="shop_name">Shop Name / Customer Name <span style="color: #ef4444;">*</span></label>
                                <input type="text" name="shop_name" id="shop_name" class="form-control" placeholder="e.g. ABC Supermarket" required value="<?= htmlspecialchars($_POST['shop_name'] ?? '') ?>" autofocus>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="location">Location / Area <span style="color: #ef4444;">*</span></label>
                                <input type="text" name="location" id="location" class="form-control" placeholder="e.g. Hampankatta, Mangalore" required value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="customer_name">Contact Person Name</label>
                                <input type="text" name="customer_name" id="customer_name" class="form-control" placeholder="e.g. Rajesh Kumar" value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number <span style="color: #ef4444;">*</span></label>
                                <input type="tel" name="phone" id="phone" class="form-control" placeholder="e.g. 9876543210" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="zone_id">Delivery Zone <span style="color: #ef4444;">*</span></label>
                                <select name="zone_id" id="zone_id" class="form-control" required>
                                    <option value="">-- Select Delivery Zone --</option>
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?= $zone['id'] ?>" <?= ((int)($_POST['zone_id'] ?? 0) === (int)$zone['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($zone['name']) ?> (/<?= htmlspecialchars($zone['slug']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="color: #94a3b8; font-size: 12px;">Order alerts will be sent to this delivery zone immediately</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="address">Full Address</label>
                                <textarea name="address" id="address" rows="2" class="form-control" placeholder="Street, Building, Landmark..."><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- Multi-Product Repeater Section -->
                        <div class="form-section-title" style="margin-top: 1.75rem;">
                            <i class='bx bx-shopping-bag' style="color: #059669; font-size: 20px;"></i>
                            Order Products &amp; Quantities
                        </div>

                        <div class="items-table-card">
                            <div style="overflow-x: auto;">
                                <table class="items-table" id="itemsTable">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 250px;">Product Item <span style="color: #ef4444;">*</span></th>
                                            <th style="min-width: 140px;">Available Stock</th>
                                            <th style="min-width: 130px; text-align: right;">Rate / Case (₹) <span style="color: #ef4444;">*</span></th>
                                            <th style="min-width: 110px; text-align: center;">Qty (Cases) <span style="color: #ef4444;">*</span></th>
                                            <th style="min-width: 130px; text-align: right;">Line Total (₹)</th>
                                            <th style="width: 50px; text-align: center;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody">
                                        <?php foreach ($form_items as $idx => $fItem): 
                                            $fPid = (int)($fItem['product_id'] ?? 0);
                                            $fRate = isset($fItem['rate']) && $fItem['rate'] !== '' ? (float)$fItem['rate'] : ($fPid && isset($products_map[$fPid]) ? $products_map[$fPid]['price'] : '');
                                        ?>
                                            <tr class="item-row" data-index="<?= $idx ?>">
                                                <td>
                                                    <select name="items[<?= $idx ?>][product_id]" class="form-control item-product-select" onchange="handleProductChange(this)" oninput="handleProductChange(this)" required>
                                                        <option value="">-- Select Product --</option>
                                                        <?php foreach ($products as $prod): 
                                                            $cPrice = (float)($prod['case_price'] ?: $prod['price']);
                                                            $cStock = (int)($prod['case_stock'] ?: $prod['stock']);
                                                            $pName = htmlspecialchars($prod['product_name'] ?: $prod['name']);
                                                            $isSelected = ($fPid === (int)$prod['product_id']);
                                                        ?>
                                                            <option value="<?= $prod['product_id'] ?>" 
                                                                    data-price="<?= $cPrice ?>" 
                                                                    data-stock="<?= $cStock ?>"
                                                                    <?= $isSelected ? 'selected' : '' ?>>
                                                                <?= $pName ?> (₹<?= number_format($cPrice, 2) ?>/cs)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <span class="badge-stock stock-display">--</span>
                                                </td>
                                                <td style="text-align: right;">
                                                    <input type="number" step="0.01" min="0" name="items[<?= $idx ?>][rate]" class="form-control item-rate-input" oninput="handleRateOrQtyChange(this)" style="width: 115px; text-align: right; font-weight: 600;" placeholder="0.00" value="<?= ($fRate !== '') ? number_format((float)$fRate, 2, '.', '') : '' ?>" required>
                                                </td>
                                                <td style="text-align: center;">
                                                    <input type="number" min="1" name="items[<?= $idx ?>][quantity]" class="form-control item-qty-input" oninput="handleRateOrQtyChange(this)" required value="<?= htmlspecialchars($fItem['quantity'] ?? '1') ?>" style="width: 90px; text-align: center; margin: 0 auto; font-weight: 600;">
                                                </td>
                                                <td style="text-align: right;">
                                                    <span class="linetotal-display" style="font-weight: 700; color: #0f172a; font-size: 15px;">₹0.00</span>
                                                </td>
                                                <td style="text-align: center;">
                                                    <button type="button" class="btn-remove-row" onclick="removeProductRow(this)" title="Remove Product Row">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Single, prominent Add Another Product button -->
                            <div style="margin-top: 10px;">
                                <button type="button" class="btn-add-product-row" id="btnAddRowBtn">
                                    <i class='bx bx-plus-circle' style="font-size: 18px;"></i> + Add Another Product
                                </button>
                            </div>
                        </div>

                        <!-- Discount & Pricing Summary -->
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="discount">Special Discount (₹)</label>
                                <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" placeholder="0.00" oninput="recalculateAll()" value="<?= htmlspecialchars($_POST['discount'] ?? '0.00') ?>">
                                <small style="color: #64748b; font-size: 12px;">Applies a flat deduction on the total order amount.</small>
                            </div>

                            <!-- Dynamic Price Summary Box -->
                            <div class="price-summary-box">
                                <div class="summary-row">
                                    <span style="color: #64748b;">Total Products &amp; Cases:</span>
                                    <span id="displayCasesCount" style="font-weight: 600; color: #1e293b;">0 Products &bull; 0 Cases</span>
                                </div>
                                <div class="summary-row">
                                    <span style="color: #64748b;">Subtotal (Base Amount):</span>
                                    <span id="displayBaseAmount" style="font-weight: 600; color: #1e293b;">₹0.00</span>
                                </div>
                                <div class="summary-row">
                                    <span style="color: #64748b;">Discount Applied:</span>
                                    <span id="displayDiscount" style="color: #ef4444; font-weight: 600;">- ₹0.00</span>
                                </div>
                                <div class="summary-row total">
                                    <span>Grand Total Amount:</span>
                                    <span id="displayTotalAmount" style="color: #2563eb;">₹0.00</span>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 2rem;">
                            <button type="submit" name="save_order" class="btn-primary" style="padding: 0.75rem 2rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <i class='bx bx-check-circle'></i> Create Order
                            </button>
                            <a href="index.php" style="padding: 0.75rem 1.5rem; background: #f1f5f9; color: #475569; text-decoration: none; border-radius: 8px; font-weight: 500; display: inline-flex; align-items: center;">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Products data map rendered from database
        const PRODUCTS_MAP = <?= json_encode($products_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        const itemsTableBody    = document.getElementById('itemsTableBody');
        const btnAddRowBtn      = document.getElementById('btnAddRowBtn');
        const discountInput     = document.getElementById('discount');

        const displayCasesCount = document.getElementById('displayCasesCount');
        const displayBaseAmount = document.getElementById('displayBaseAmount');
        const displayDiscount   = document.getElementById('displayDiscount');
        const displayTotalAmount = document.getElementById('displayTotalAmount');

        let nextRowIndex = <?= count($form_items) ?>;

        // Generate options HTML dynamically from PRODUCTS_MAP
        function getProductOptionsHtml(selectedId = 0) {
            let html = '<option value="">-- Select Product --</option>';
            for (const pid in PRODUCTS_MAP) {
                const p = PRODUCTS_MAP[pid];
                const sel = (parseInt(selectedId) === parseInt(p.id)) ? 'selected' : '';
                html += `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}" ${sel}>${p.name} (₹${parseFloat(p.price).toFixed(2)}/cs)</option>`;
            }
            return html;
        }

        // Direct Product Change handler - fires immediately on select change/input
        function handleProductChange(select) {
            if (!select) return;
            const row = select.closest('.item-row');
            if (!row) return;

            // Clear custom rate typing flag so new product rate is populated
            delete row.dataset.userTypingRate;

            const rateInput = row.querySelector('.item-rate-input');
            const stockDisplay = row.querySelector('.stock-display');
            const selectedOption = select.options[select.selectedIndex];
            const pid = select.value ? parseInt(select.value) : 0;

            let price = null;
            let stock = null;

            if (selectedOption && selectedOption.value) {
                if (selectedOption.hasAttribute('data-price')) {
                    price = parseFloat(selectedOption.getAttribute('data-price'));
                }
                if (selectedOption.hasAttribute('data-stock')) {
                    stock = parseInt(selectedOption.getAttribute('data-stock'));
                }
            }

            if ((price === null || isNaN(price)) && PRODUCTS_MAP[pid]) {
                price = parseFloat(PRODUCTS_MAP[pid].price);
            }
            if ((stock === null || isNaN(stock)) && PRODUCTS_MAP[pid]) {
                stock = parseInt(PRODUCTS_MAP[pid].stock);
            }

            // Update stock badge
            if (pid > 0 && stock !== null && !isNaN(stock)) {
                if (stock <= 0) {
                    stockDisplay.textContent = 'Out of Stock (0)';
                    stockDisplay.className = 'badge-stock out-of-stock';
                } else if (stock < 10) {
                    stockDisplay.textContent = stock + ' Cases (Low)';
                    stockDisplay.className = 'badge-stock low-stock';
                } else {
                    stockDisplay.textContent = stock + ' Cases';
                    stockDisplay.className = 'badge-stock in-stock';
                }
            } else {
                stockDisplay.textContent = '--';
                stockDisplay.className = 'badge-stock';
            }

            // Update rate
            if (pid > 0 && price !== null && !isNaN(price)) {
                rateInput.value = price.toFixed(2);
            } else if (pid === 0) {
                rateInput.value = '';
            }

            updateRowLineTotal(row);
            recalculateAll();
        }

        // Direct Rate or Quantity handler - fires immediately as user types
        function handleRateOrQtyChange(input) {
            if (!input) return;
            const row = input.closest('.item-row');
            if (!row) return;

            if (input.classList.contains('item-rate-input')) {
                row.dataset.userTypingRate = '1';
            }

            updateRowLineTotal(row);
            recalculateAll();
        }

        // Calculate single row line total
        function updateRowLineTotal(row) {
            if (!row) return { valid: false, qty: 0, rate: 0, lineTotal: 0 };
            const select = row.querySelector('.item-product-select');
            const rateInput = row.querySelector('.item-rate-input');
            const qtyInput = row.querySelector('.item-qty-input');
            const lineTotalDisplay = row.querySelector('.linetotal-display');

            const pid = select && select.value ? parseInt(select.value) : 0;
            const rate = parseFloat(rateInput.value) || 0;
            const qty = Math.max(0, parseInt(qtyInput.value) || 0);
            const lineTotal = rate * qty;

            lineTotalDisplay.textContent = '₹' + lineTotal.toFixed(2);

            return {
                valid: pid > 0,
                qty: qty,
                rate: rate,
                lineTotal: lineTotal
            };
        }

        // Remove row handler
        function removeProductRow(btn) {
            const row = btn.closest('.item-row');
            if (!row) return;

            const allRows = itemsTableBody.querySelectorAll('.item-row');
            if (allRows.length <= 1) {
                const select = row.querySelector('.item-product-select');
                select.value = '';
                const rateInput = row.querySelector('.item-rate-input');
                rateInput.value = '';
                delete row.dataset.userTypingRate;
                row.querySelector('.item-qty-input').value = '1';
                row.querySelector('.stock-display').textContent = '--';
                row.querySelector('.stock-display').className = 'badge-stock';
                row.querySelector('.linetotal-display').textContent = '₹0.00';
                recalculateAll();
            } else {
                row.remove();
                recalculateAll();
            }
        }

        // Add dynamic row
        function addNewRow() {
            const tr = document.createElement('tr');
            tr.className = 'item-row';
            tr.dataset.index = nextRowIndex;

            tr.innerHTML = `
                <td>
                    <select name="items[${nextRowIndex}][product_id]" class="form-control item-product-select" onchange="handleProductChange(this)" oninput="handleProductChange(this)" required>
                        ${getProductOptionsHtml()}
                    </select>
                </td>
                <td>
                    <span class="badge-stock stock-display">--</span>
                </td>
                <td style="text-align: right;">
                    <input type="number" step="0.01" min="0" name="items[${nextRowIndex}][rate]" class="form-control item-rate-input" oninput="handleRateOrQtyChange(this)" style="width: 115px; text-align: right; font-weight: 600;" placeholder="0.00" required>
                </td>
                <td style="text-align: center;">
                    <input type="number" min="1" name="items[${nextRowIndex}][quantity]" class="form-control item-qty-input" oninput="handleRateOrQtyChange(this)" required value="1" style="width: 90px; text-align: center; margin: 0 auto; font-weight: 600;">
                </td>
                <td style="text-align: right;">
                    <span class="linetotal-display" style="font-weight: 700; color: #0f172a; font-size: 15px;">₹0.00</span>
                </td>
                <td style="text-align: center;">
                    <button type="button" class="btn-remove-row" onclick="removeProductRow(this)" title="Remove Product Row">
                        <i class='bx bx-trash'></i>
                    </button>
                </td>
            `;

            itemsTableBody.appendChild(tr);
            nextRowIndex++;
            recalculateAll();
        }

        // Recalculate entire order: subtotal, cases count, discount, and grand total
        function recalculateAll() {
            const rows = itemsTableBody.querySelectorAll('.item-row');
            let totalBase = 0;
            let totalCases = 0;
            let validProducts = 0;

            rows.forEach(row => {
                const res = updateRowLineTotal(row);
                if (res.valid) {
                    validProducts++;
                    totalCases += res.qty;
                    totalBase += res.lineTotal;
                }
            });

            let discount = parseFloat(discountInput.value) || 0;
            if (discount < 0) discount = 0;
            if (discount > totalBase) {
                discount = totalBase;
            }
            const grandTotal = Math.max(0, totalBase - discount);

            displayCasesCount.textContent = `${validProducts} Product${validProducts === 1 ? '' : 's'} • ${totalCases} Case${totalCases === 1 ? '' : 's'}`;
            displayBaseAmount.textContent = '₹' + totalBase.toFixed(2);
            displayDiscount.textContent = '- ₹' + discount.toFixed(2);
            displayTotalAmount.textContent = '₹' + grandTotal.toFixed(2);
        }

        // Delegated event fallbacks on table body
        itemsTableBody.addEventListener('change', function(e) {
            const select = e.target.closest('.item-product-select');
            if (select) {
                handleProductChange(select);
                return;
            }
            const input = e.target.closest('.item-rate-input, .item-qty-input');
            if (input) {
                handleRateOrQtyChange(input);
            }
        });

        itemsTableBody.addEventListener('input', function(e) {
            const select = e.target.closest('.item-product-select');
            if (select) {
                handleProductChange(select);
                return;
            }
            const input = e.target.closest('.item-rate-input, .item-qty-input');
            if (input) {
                handleRateOrQtyChange(input);
            }
        });

        btnAddRowBtn.addEventListener('click', addNewRow);

        // Run initialization immediately on load
        function initPage() {
            itemsTableBody.querySelectorAll('.item-row').forEach(row => {
                const select = row.querySelector('.item-product-select');
                if (select && select.value) {
                    const selectedOption = select.options[select.selectedIndex];
                    const pid = parseInt(select.value) || 0;
                    let stock = selectedOption ? selectedOption.getAttribute('data-stock') : null;
                    if (stock === null && PRODUCTS_MAP[pid]) stock = PRODUCTS_MAP[pid].stock;
                    const stockDisplay = row.querySelector('.stock-display');
                    if (stock !== null && !isNaN(stock)) {
                        stock = parseInt(stock);
                        if (stock <= 0) {
                            stockDisplay.textContent = 'Out of Stock (0)';
                            stockDisplay.className = 'badge-stock out-of-stock';
                        } else if (stock < 10) {
                            stockDisplay.textContent = stock + ' Cases (Low)';
                            stockDisplay.className = 'badge-stock low-stock';
                        } else {
                            stockDisplay.textContent = stock + ' Cases';
                            stockDisplay.className = 'badge-stock in-stock';
                        }
                    }
                    const rateInput = row.querySelector('.item-rate-input');
                    if (!rateInput.value && selectedOption && selectedOption.getAttribute('data-price')) {
                        rateInput.value = parseFloat(selectedOption.getAttribute('data-price')).toFixed(2);
                    }
                }
                updateRowLineTotal(row);
            });
            recalculateAll();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPage);
        } else {
            initPage();
        }
    </script>
</body>
</html>
