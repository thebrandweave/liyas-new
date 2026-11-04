<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "orders";
$page_title   = "Edit Order";
$error = '';

// Live AJAX Stock API endpoint for real-time stock sync with Warehouse Inventory (admin/products/index.php)
if (isset($_GET['action']) && $_GET['action'] === 'get_live_stock') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT product_id, name, product_name, case_price, price, case_stock, stock, net_content, net_content_unit, status FROM products WHERE status != 'inactive' OR status IS NULL ORDER BY case_price ASC, name ASC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($list as $p) {
            $cPrice = (float)($p['case_price'] ?: $p['price']);
            $cStock = (int)($p['case_stock'] ?: $p['stock']);
            $pName = $p['product_name'] ?: $p['name'];
            $map[(int)$p['product_id']] = [
                'id'          => (int)$p['product_id'],
                'name'        => $pName,
                'price'       => $cPrice,
                'stock'       => $cStock,
                'net_content' => $p['net_content'],
                'unit'        => $p['net_content_unit']
            ];
        }
        echo json_encode(['success' => true, 'products' => $map, 'timestamp' => time()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: index.php");
    exit;
}

// Load active zones
$zones = $pdo->query("SELECT id, name, slug FROM zones ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Load Vehicless
$all_vehicles = $pdo->query("
    SELECT v.id, v.vehicle_number, v.vehicle_name, v.zone_id, z.name as zone_name
    FROM vehicles v 
    LEFT JOIN zones z ON v.zone_id = z.id 
    WHERE v.status != 'deleted' 
    ORDER BY v.vehicle_number ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Map zone_id => vehicle_id
$zone_vehicle_map = [];
foreach ($all_vehicles as $veh) {
    if (!empty($veh['zone_id']) && !isset($zone_vehicle_map[(int)$veh['zone_id']])) {
        $zone_vehicle_map[(int)$veh['zone_id']] = (int)$veh['id'];
    }
}

// Load products matching Website Product Inventory (admin/products/index.php)
$products = $pdo->query("SELECT product_id, name, product_name, case_price, price, case_stock, stock, net_content, net_content_unit FROM products WHERE status != 'inactive' OR status IS NULL ORDER BY case_price ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build structured products map for JS lookup
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

// Load existing order items
$existing_items = getOrderItems($pdo, $order_id);
$form_items = [];

// Load registered shops for autocomplete & searchable dropdown
$allShops = [];
try {
    $zoneNameMap = [];
    foreach ($zones as $z) {
        $zoneNameMap[(int)$z['id']] = $z['name'];
    }

    $seenShops = [];
    $shRows = $pdo->query("
        SELECT s.id, s.shop_name, s.location, s.phone, s.zone_id, s.vehicle_id, z.name as zone_name
        FROM shops s
        LEFT JOIN zones z ON s.zone_id = z.id
        ORDER BY s.shop_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($shRows as $s) {
        $nameTrim = trim($s['shop_name'] ?? '');
        if ($nameTrim !== '') {
            $key = strtolower($nameTrim);
            if (!isset($seenShops[$key])) {
                $seenShops[$key] = true;
                $allShops[] = [
                    'id'         => (int)$s['id'],
                    'shop_name'  => $nameTrim,
                    'location'   => trim($s['location'] ?? ''),
                    'phone'      => trim($s['phone'] ?? ''),
                    'zone_id'    => (int)($s['zone_id'] ?? 0),
                    'zone_name'  => trim($s['zone_name'] ?? ($zoneNameMap[(int)($s['zone_id'] ?? 0)] ?? '')),
                    'vehicle_id' => (int)($s['vehicle_id'] ?? 0)
                ];
            }
        }
    }

    $orderShops = $pdo->query("
        SELECT 
            DISTINCT TRIM(COALESCE(NULLIF(shop_name, ''), customer_name)) as shop_name,
            MAX(location) as location,
            MAX(phone) as phone,
            MAX(zone_id) as zone_id,
            MAX(vehicle_id) as vehicle_id
        FROM orders
        WHERE (shop_name IS NOT NULL AND TRIM(shop_name) != '')
           OR (customer_name IS NOT NULL AND TRIM(customer_name) != '')
        GROUP BY TRIM(COALESCE(NULLIF(shop_name, ''), customer_name))
        ORDER BY shop_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orderShops as $os) {
        $nameTrim = trim($os['shop_name'] ?? '');
        if ($nameTrim !== '') {
            $key = strtolower($nameTrim);
            if (!isset($seenShops[$key])) {
                $seenShops[$key] = true;
                $zid = (int)($os['zone_id'] ?? 0);
                $allShops[] = [
                    'id'         => 0,
                    'shop_name'  => $nameTrim,
                    'location'   => trim($os['location'] ?? ''),
                    'phone'      => trim($os['phone'] ?? ''),
                    'zone_id'    => $zid,
                    'zone_name'  => $zoneNameMap[$zid] ?? '',
                    'vehicle_id' => (int)($os['vehicle_id'] ?? 0)
                ];
            }
        }
    }

    usort($allShops, function($a, $b) {
        return strcasecmp($a['shop_name'], $b['shop_name']);
    });
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    $shop_name     = trim($_POST['shop_name'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $location      = trim($_POST['location'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $zone_id       = (int)($_POST['zone_id'] ?? 0);
    $vehicle_id    = isset($_POST['vehicle_id']) && $_POST['vehicle_id'] !== '' ? (int)$_POST['vehicle_id'] : 0;

    // Sync shop phone, location, and match assigned vehicle & zone from master shops table
    $shopSync = syncShopOrderDetails($pdo, $shop_name, $phone, $location, $zone_id, $vehicle_id);
    if (!empty($shopSync['vehicle_id']) && $vehicle_id <= 0) {
        $vehicle_id = (int)$shopSync['vehicle_id'];
    }
    if (!empty($shopSync['zone_id']) && $zone_id <= 0) {
        $zone_id = (int)$shopSync['zone_id'];
    }
    if (!empty($shopSync['phone']) && empty($phone)) {
        $phone = $shopSync['phone'];
    }
    if (!empty($shopSync['location']) && empty($location)) {
        $location = $shopSync['location'];
    }

    $discount      = (float)($_POST['discount'] ?? 0);
    $status        = $_POST['status'] ?? $order['status'];

    // Parse multi-product items
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

    if (empty($shop_name)) {
        $error = "Shop / Customer Name is required.";
    } elseif ($zone_id <= 0) {
        $error = "Please select a Delivery Zone.";
    } elseif (empty($parsed_items)) {
        $error = "Please select at least one product with quantity of 1 or more.";
    } elseif ($discount < 0) {
        $error = "Discount cannot be negative.";
    } else {
        // Fetch prices from database
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
                $error = "One or more selected products are invalid.";
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
            if ($discount > $base_total) $discount = $base_total;
            $total_amount = max(0, $base_total - $discount);

            try {
                $pdo->beginTransaction();

                // Stock Adjustment between Old State and New State:
                $old_status = strtolower($order['status']);
                $new_status = strtolower($status);

                // If old order had deducted stock, restore old items
                if ($old_status !== 'cancelled') {
                    foreach ($existing_items as $oldItem) {
                        $oldPid = (int)($oldItem['product_id'] ?? 0);
                        $oldQty = (int)($oldItem['quantity'] ?? 0);
                        if ($oldPid > 0 && $oldQty > 0) {
                            adjustProductStock($pdo, $oldPid, +$oldQty, "Order #{$order_id} edited: restored old items");
                        }
                    }
                }

                // If new order holds stock, deduct new items
                if ($new_status !== 'cancelled') {
                    foreach ($valid_order_items as $newItem) {
                        $newPid = (int)$newItem['product_id'];
                        $newQty = (int)$newItem['quantity'];
                        if ($newPid > 0 && $newQty > 0) {
                            adjustProductStock($pdo, $newPid, -$newQty, "Order #{$order_id} edited: applied new items");
                        }
                    }
                }

                // Sync shop phone, location, and match assigned vehicle from master shops table
                $shopSync = syncShopOrderDetails($pdo, $shop_name, $phone, $location, $zone_id, $vehicle_id);
                if (!empty($shopSync['vehicle_id']) && $vehicle_id <= 0) {
                    $vehicle_id = (int)$shopSync['vehicle_id'];
                }
                if (!empty($shopSync['phone']) && empty($phone)) {
                    $phone = $shopSync['phone'];
                }
                if (!empty($shopSync['location']) && empty($location)) {
                    $location = $shopSync['location'];
                }
                if (!empty($shopSync['zone_id']) && $zone_id <= 0) {
                    $zone_id = (int)$shopSync['zone_id'];
                }

                // Update orders table
                $primary_pid = $valid_order_items[0]['product_id'];
                $primary_unit_price = $valid_order_items[0]['price_at_purchase'];

                $up = $pdo->prepare("
                    UPDATE orders SET
                        shop_name = ?, customer_name = ?, location = ?, phone = ?, 
                        address = ?, zone_id = ?, vehicle_id = ?, product_id = ?, quantity = ?, 
                        unit_price = ?, discount = ?, total_amount = ?, status = ?, 
                        updated_at = NOW()
                    WHERE order_id = ?
                ");
                $up->execute([
                    $shop_name,
                    $customer_name ?: $shop_name,
                    $location,
                    $phone,
                    $address,
                    $zone_id,
                    ($vehicle_id > 0 ? $vehicle_id : null),
                    $primary_pid,
                    $total_cases,
                    $primary_unit_price,
                    $discount,
                    $total_amount,
                    $status,
                    $order_id
                ]);

                // Replace order_items rows
                $delItems = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
                $delItems->execute([$order_id]);

                $insItem = $pdo->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, quantity, price_at_purchase, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?, NOW(), NOW()
                    )
                ");

                foreach ($valid_order_items as $voi) {
                    $insItem->execute([
                        $order_id,
                        $voi['product_id'],
                        $voi['quantity'],
                        $voi['price_at_purchase']
                    ]);
                }

                // Sync vehicle load
                if ($vehicle_id > 0) {
                    assignOrderToVehicle($pdo, $order_id, $vehicle_id);
                } else {
                    unassignOrderFromVehicle($pdo, $order_id);
                }

                if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
                    $sName = !empty($shop_name) ? $shop_name : (!empty($customer_name) ? $customer_name : '');
                    if ($sName !== '') {
                        updateShopRewardProgress($pdo, $sName, $phone);
                    }
                    if (!empty($order['shop_name']) && $order['shop_name'] !== $sName) {
                        updateShopRewardProgress($pdo, $order['shop_name'], $order['phone'] ?? '');
                    }
                }

                $pdo->commit();

                quickLog($pdo, 'update', 'order', $order_id, "Updated order #{$order_id} with " . count($valid_order_items) . " products ({$total_cases} cases, ₹{$total_amount})");

                header("Location: view.php?id={$order_id}&updated=1");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error updating order: " . $e->getMessage();
            }
        }
    }
} else {
    // Populate form with existing items
    if (!empty($existing_items)) {
        foreach ($existing_items as $ei) {
            $form_items[] = [
                'product_id' => $ei['product_id'],
                'quantity'   => $ei['quantity'],
                'rate'       => $ei['price_at_purchase']
            ];
        }
    } else {
        $form_items = [[
            'product_id' => $order['product_id'],
            'quantity'   => $order['quantity'],
            'rate'       => $order['unit_price']
        ]];
    }
}

$displayOrderNum = $order['order_number'] ?: ('#' . $order['order_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Order <?= htmlspecialchars($displayOrderNum) ?> - Liyas Admin</title>
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
            padding: 0.65rem 0.15rem;
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

        /* Searchable Shop Dropdown (Combobox) */
        .searchable-combobox-wrap {
            position: relative;
            width: 100%;
        }
        .shop-search-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 12px;
            box-shadow: 0 14px 30px -4px rgba(15, 23, 42, 0.18), 0 4px 10px -2px rgba(15, 23, 42, 0.08);
            z-index: 99999;
            max-height: 330px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: fadeInSlideDown 0.15s ease-out;
        }
        @keyframes fadeInSlideDown {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .shop-dropdown-search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .shop-dropdown-search-box input {
            flex: 1;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12.5px;
            outline: none;
            background: #ffffff;
            color: #0f172a;
        }
        .shop-dropdown-search-box input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37,99,235,0.1);
        }
        .shop-count-badge {
            font-size: 11px;
            font-weight: 700;
            background: #e2e8f0;
            color: #475569;
            padding: 2px 7px;
            border-radius: 6px;
            white-space: nowrap;
        }
        .shop-dropdown-list {
            overflow-y: auto;
            max-height: 270px;
            padding: 4px 0;
        }
        .shop-dropdown-item {
            padding: 10px 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.12s ease;
        }
        .shop-dropdown-item:last-child {
            border-bottom: none;
        }
        .shop-dropdown-item:hover, .shop-dropdown-item.active {
            background: #eff6ff;
        }
        .shop-item-info {
            flex: 1;
            min-width: 0;
        }
        .shop-item-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
        }
        .shop-item-meta {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .shop-item-meta span {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .shop-item-badges {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }
        .shop-zone-pill {
            font-size: 10px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            background: #e0f2fe;
            color: #0369a1;
            text-transform: uppercase;
        }
        .shop-dropdown-empty {
            padding: 16px 14px;
            text-align: center;
            color: #64748b;
            font-size: 12.5px;
        }
        .shop-dropdown-new-option {
            padding: 10px 14px;
            background: #f0fdf4;
            border-bottom: 1.5px solid #bbf7d0;
            color: #15803d;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .shop-dropdown-new-option:hover {
            background: #dcfce7;
        }

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
                    <a href="view.php?id=<?= $order_id ?>" style="color: inherit; text-decoration: none;"><?= htmlspecialchars($displayOrderNum) ?></a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Edit Order</span>
                </div>
                <div class="header-actions">
                    <a href="view.php?id=<?= $order_id ?>" class="header-btn" style="text-decoration: none;">
                        <i class='bx bx-arrow-back'></i> Back to Details
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
                    <h2 style="font-size: 21px; font-weight: 700; margin-bottom: 0.4rem; color: #111827;">Edit Order <?= htmlspecialchars($displayOrderNum) ?></h2>
             

                    <form action="edit.php?id=<?= $order_id ?>" method="POST" id="editOrderForm">
                        <!-- Shop / Customer Information -->
                        <div class="form-section-title">
                            <i class='bx bx-store-alt' style="color: #2563eb; font-size: 20px;"></i>
                            Shop &amp; Customer Details
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label" for="shop_name" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>Shop Name / Customer Name <span style="color: #ef4444;">*</span></span>
                                    <span class="shop-badge-hint" onclick="toggleAdminEditShopDropdown()" style="font-size: 11px; font-weight: 700; color: #2563eb; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Click to browse registered shops">
                                        <i class='bx bx-list-ul'></i> Browse (<span id="adminEditShopsTotalCount"><?= count($allShops) ?></span>)
                                    </span>
                                </label>
                                <div class="searchable-combobox-wrap" style="position: relative;">
                                    <input type="text" name="shop_name" id="shop_name" class="form-control" placeholder="Type or click to search shop..." required value="<?= htmlspecialchars($_POST['shop_name'] ?? $order['shop_name']) ?>" autocomplete="off" style="padding-right: 60px;">
                                    <div style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); display: flex; align-items: center; gap: 4px; z-index: 5;">
                                        <button type="button" id="btnClearAdminEditShop" onclick="clearAdminEditShopSelection()" style="display: <?= !empty($_POST['shop_name'] ?? $order['shop_name']) ? 'inline-flex' : 'none' ?>; background: #e2e8f0; border: none; border-radius: 50%; width: 20px; height: 20px; color: #475569; font-size: 13px; font-weight: bold; cursor: pointer; align-items: center; justify-content: center; line-height: 1;" title="Clear">&times;</button>
                                        <button type="button" id="btnToggleAdminEditShop" onclick="toggleAdminEditShopDropdown()" style="background: none; border: none; color: #64748b; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 2px;" title="Select from dropdown">
                                            <i class='bx bx-chevron-down' id="adminEditShopChevron"></i>
                                        </button>
                                    </div>

                                    <!-- Searchable Dropdown Popup -->
                                    <div id="adminEditShopSearchDropdown" class="shop-search-dropdown" style="display: none;">
                                        <div class="shop-dropdown-search-box">
                                            <i class='bx bx-search' style="color: #64748b; font-size: 16px;"></i>
                                            <input type="text" id="adminEditShopFilterInput" placeholder="Search shop name, area or phone..." autocomplete="off">
                                            <span id="adminEditShopFilteredCount" class="shop-count-badge"><?= count($allShops) ?> shops</span>
                                        </div>
                                        <div id="adminEditShopDropdownList" class="shop-dropdown-list">
                                            <!-- Dynamically filled by JS -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="location">Location / Area <span style="color: #ef4444;">*</span></label>
                                <input type="text" name="location" id="location" class="form-control" required value="<?= htmlspecialchars($_POST['location'] ?? $order['location']) ?>">
                            </div>
                        </div>

                        <div class="grid-2">
                         
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number <span style="color: #ef4444;">*</span></label>
                                <input type="tel" name="phone" id="phone" class="form-control" required value="<?= htmlspecialchars($_POST['phone'] ?? $order['phone']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="zone_id">Delivery Zone <span style="color: #ef4444;">*</span></label>
                                <select name="zone_id" id="zone_id" class="form-control" required onchange="handleZoneChange(this.value)">
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?= $zone['id'] ?>" <?= ((int)($_POST['zone_id'] ?? $order['zone_id']) === (int)$zone['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($zone['name']) ?> (/<?= htmlspecialchars($zone['slug']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            
                            <div class="form-group">
                                <label class="form-label" for="vehicle_id" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span><i class='bx bxs-truck' style="color: #2563eb;"></i> Assigned Delivery Vehicle</span>
                                  
                                </label>
                                <select name="vehicle_id" id="vehicle_id" class="form-control">
                                    <option value="" data-zone-id="">-- None / Unassigned --</option>
                                    <?php 
                                    $currentVehVal = (int)($_POST['vehicle_id'] ?? ($order['vehicle_id'] ?? 0));
                                    foreach ($all_vehicles as $veh): 
                                        $vPlate = htmlspecialchars($veh['vehicle_number']);
                                        $vModel = !empty($veh['vehicle_name']) ? ' (' . htmlspecialchars($veh['vehicle_name']) . ')' : '';
                                        $vFixedZone = !empty($veh['zone_name']) ? ' — ' . htmlspecialchars($veh['zone_name']) : '';
                                        $isSel = ($currentVehVal === (int)$veh['id']);
                                    ?>
                                        <option value="<?= $veh['id'] ?>" data-zone-id="<?= (int)($veh['zone_id'] ?? 0) ?>" <?= $isSel ? 'selected' : '' ?>>
                                            <?= $vPlate ?><?= $vModel ?><?= $vFixedZone ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small id="vehicle-zone-hint" style="color: #64748b; font-size: 11px; display: block; margin-top: 3px;">
                                    Orders will be placed directly in this vehicle's load list
                                </small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="status">Order Status</label>
                                <select name="status" id="status" class="form-control">
                                    <option value="pending" <?= ((($_POST['status'] ?? $order['status'])) === 'pending') ? 'selected' : '' ?>>Pending</option>
                                    <option value="processing" <?= ((($_POST['status'] ?? $order['status'])) === 'processing') ? 'selected' : '' ?>>Processing</option>
                                    <option value="shipped" <?= ((($_POST['status'] ?? $order['status'])) === 'shipped') ? 'selected' : '' ?>>Shipped</option>
                                    <option value="delivered" <?= ((($_POST['status'] ?? $order['status'])) === 'delivered') ? 'selected' : '' ?>>Delivered</option>
                                    <option value="cancelled" <?= ((($_POST['status'] ?? $order['status'])) === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid-2">
                            
                       
                        </div>

                        <!-- Multi-Product Repeater Section -->
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; margin-top: 1.75rem; margin-bottom: 0.5rem; gap: 10px;">
                            <div class="form-section-title" style="margin: 0;">
                                <i class='bx bx-shopping-bag' style="color: #059669; font-size: 20px;"></i>
                                Order Products &amp; Quantities
                            </div>
                          
                        </div>

                        <div class="items-table-card">
                            <div style="overflow-x: hidden;">
                                <table class="items-table" id="itemsTable">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 280px;">Product Item <span style="color: #ef4444;">*</span></th>
                                            <th style="min-width: 150px;">Available Stock</th>
                                            <th style="min-width: 130px; text-align: right;">Rate / Case (₹) <span style="color: #ef4444;">*</span></th>
                                            <th style="min-width: 120px; text-align: center;">Qty (Cases) <span style="color: #ef4444;">*</span></th>
                                            <th style="min-width: 130px; text-align: right;">Total (₹)</th>
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
                                                            $stockText = $cStock > 0 ? (number_format($cStock) . ' Cases in Stock') : 'Out of Stock (0)';
                                                        ?>
                                                            <option value="<?= $prod['product_id'] ?>" 
                                                                    data-price="<?= $cPrice ?>" 
                                                                    data-stock="<?= $cStock ?>"
                                                                    <?= $isSelected ? 'selected' : '' ?>>
                                                                <?= $pName ?> (₹<?= number_format($cPrice, 2) ?>/cs) &mdash; <?= $stockText ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <?php if ($fPid > 0 && isset($products_map[$fPid])): 
                                                        $stk = (int)$products_map[$fPid]['stock'];
                                                        if ($stk <= 0): ?>
                                                            <span class="badge-stock out-of-stock stock-display"><i class='bx bx-x-circle'></i> Out of Stock (0)</span>
                                                        <?php elseif ($stk < 20): ?>
                                                            <span class="badge-stock low-stock stock-display"><i class='bx bx-time'></i> Low Stock: <?= $stk ?> cs</span>
                                                        <?php else: ?>
                                                            <span class="badge-stock in-stock stock-display"><i class='bx bx-check-circle'></i> In Stock: <?= $stk ?> cs</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge-stock stock-display">-- Select Product --</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: right;">
                                                    <input type="text" step="0.01" min="0" name="items[<?= $idx ?>][rate]" class="form-control item-rate-input" oninput="handleRateOrQtyChange(this)" style="width: 115px; text-align: right; font-weight: 600;" placeholder="0.00" value="<?= ($fRate !== '') ? number_format((float)$fRate, 2, '.', '') : '' ?>" required>
                                                </td>
                                                <td style="text-align: center;">
                                                    <input type="text" min="1" name="items[<?= $idx ?>][quantity]" class="form-control item-qty-input" oninput="handleRateOrQtyChange(this)" required value="<?= htmlspecialchars($fItem['quantity'] ?? '1') ?>" style="width: 90px; text-align: center; margin: 0 auto; font-weight: 600;">
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
                                <input type="text" step="0.01" min="0" name="discount" id="discount" class="form-control" oninput="recalculateAll()" value="<?= htmlspecialchars($_POST['discount'] ?? $order['discount']) ?>">
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
                            <button type="submit" name="update_order" class="btn-primary" style="padding: 0.75rem 2rem; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                                <i class='bx bx-check-circle'></i> Save Changes
                            </button>
                            <a href="view.php?id=<?= $order_id ?>" style="padding: 0.75rem 1.5rem; background: #f1f5f9; color: #475569; text-decoration: none; border-radius: 8px; font-weight: 500; display: inline-flex; align-items: center;">
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

        // Helper to format stock badge HTML matching warehouse inventory rules
        function formatStockBadgeHtml(stock) {
            if (stock === null || stock === undefined || isNaN(stock)) {
                return '<span class="badge-stock stock-display">-- Select Product --</span>';
            }
            const s = parseInt(stock);
            if (s <= 0) {
                return `<span class="badge-stock out-of-stock stock-display"><i class='bx bx-x-circle'></i> Out of Stock (0)</span>`;
            } else if (s < 20) {
                return `<span class="badge-stock low-stock stock-display"><i class='bx bx-time'></i> Low Stock: ${s} cs</span>`;
            } else {
                return `<span class="badge-stock in-stock stock-display"><i class='bx bx-check-circle'></i> In Stock: ${s} cs</span>`;
            }
        }

        // Helper to update stock badge cell of a row
        function updateStockBadge(row, stock) {
            if (!row) return;
            const td = row.querySelector('td:nth-child(2)');
            if (td) {
                td.innerHTML = formatStockBadgeHtml(stock);
            }
        }

        // Generate options HTML dynamically from PRODUCTS_MAP with stock display
        function getProductOptionsHtml(selectedId = 0) {
            let html = '<option value="">-- Select Product --</option>';
            for (const pid in PRODUCTS_MAP) {
                const p = PRODUCTS_MAP[pid];
                const sel = (parseInt(selectedId) === parseInt(p.id)) ? 'selected' : '';
                const stockVal = parseInt(p.stock) || 0;
                const stockText = stockVal > 0 ? `${Number(stockVal).toLocaleString()} Cases in Stock` : 'Out of Stock (0)';
                html += `<option value="${p.id}" data-price="${p.price}" data-stock="${stockVal}" ${sel}>${p.name} (₹${parseFloat(p.price).toFixed(2)}/cs) &mdash; ${stockText}</option>`;
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
                updateStockBadge(row, stock);
            } else {
                updateStockBadge(row, null);
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

        // Calculate single row Total and check stock availability warning
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

            // Inline stock warning if entered qty exceeds available warehouse stock
            let stockWarn = row.querySelector('.stock-warning-note');
            let availableStock = null;
            if (pid > 0 && PRODUCTS_MAP[pid]) {
                availableStock = parseInt(PRODUCTS_MAP[pid].stock);
            }
            if (pid > 0 && availableStock !== null && !isNaN(availableStock) && qty > availableStock) {
                if (!stockWarn) {
                    stockWarn = document.createElement('div');
                    stockWarn.className = 'stock-warning-note';
                    stockWarn.style.cssText = 'font-size: 11px; color: #dc2626; font-weight: 600; margin-top: 4px; display: flex; align-items: center; gap: 3px; justify-content: center;';
                    qtyInput.parentNode.appendChild(stockWarn);
                }
                stockWarn.innerHTML = `<i class='bx bx-error-circle'></i> Exceeds stock (${availableStock} cs)`;
            } else if (stockWarn) {
                stockWarn.remove();
            }

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
                updateStockBadge(row, null);
                row.querySelector('.linetotal-display').textContent = '₹0.00';
                const stockWarn = row.querySelector('.stock-warning-note');
                if (stockWarn) stockWarn.remove();
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
                    <span class="badge-stock stock-display">-- Select Product --</span>
                </td>
                <td style="text-align: right;">
                    <input type="text" step="0.01" min="0" name="items[${nextRowIndex}][rate]" class="form-control item-rate-input" oninput="handleRateOrQtyChange(this)" style="width: 115px; text-align: right; font-weight: 600;" placeholder="0.00" required>
                </td>
                <td style="text-align: center;">
                    <input type="text" min="1" name="items[${nextRowIndex}][quantity]" class="form-control item-qty-input" oninput="handleRateOrQtyChange(this)" required value="1" style="width: 90px; text-align: center; margin: 0 auto; font-weight: 600;">
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

        // Live Stock Fetching from Website Inventory
        async function refreshLiveStocks(manual = false) {
            const icon = document.getElementById('refreshStockIcon');
            const indicator = document.getElementById('stockSyncIndicator');
            if (icon && manual) icon.classList.add('bx-spin');

            try {
                const response = await fetch('edit.php?id=<?= $order_id ?>&action=get_live_stock&t=' + Date.now(), {
                    headers: { 'Cache-Control': 'no-cache' }
                });
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                if (data.success && data.products) {
                    // Update PRODUCTS_MAP
                    for (const pid in data.products) {
                        PRODUCTS_MAP[pid] = data.products[pid];
                    }

                    // Update existing dropdowns while preserving current selections
                    const rows = itemsTableBody.querySelectorAll('.item-row');
                    rows.forEach(row => {
                        const select = row.querySelector('.item-product-select');
                        if (select) {
                            const currentVal = select.value;
                            select.innerHTML = getProductOptionsHtml(currentVal);
                            select.value = currentVal;
                        }
                        const pid = select && select.value ? parseInt(select.value) : 0;
                        if (pid > 0 && PRODUCTS_MAP[pid]) {
                            updateStockBadge(row, PRODUCTS_MAP[pid].stock);
                        } else if (pid === 0) {
                            updateStockBadge(row, null);
                        }
                        updateRowLineTotal(row);
                    });

                    if (indicator) {
                        const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        indicator.innerHTML = `<i class='bx bx-check-circle'></i> Stock Synced (${timeStr})`;
                        indicator.style.color = '#059669';
                    }
                }
            } catch (err) {
                console.warn('Live stock sync failed:', err);
                if (indicator && manual) {
                    indicator.innerHTML = `<i class='bx bx-error'></i> Sync failed`;
                    indicator.style.color = '#dc2626';
                }
            } finally {
                if (icon && manual) {
                    setTimeout(() => icon.classList.remove('bx-spin'), 500);
                }
            }
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

        // Dedicated Zone -> Vehicle Auto Selection
        function handleZoneChange(zoneId) {
            const vehicleSelect = document.getElementById('vehicle_id');
            const autoLinkTag = document.getElementById('vehicle-autolink-tag');
            const hint = document.getElementById('vehicle-zone-hint');
            if (!vehicleSelect) return;

            zoneId = parseInt(zoneId) || 0;
            if (!zoneId) {
                if (autoLinkTag) autoLinkTag.style.display = 'none';
                if (hint) {
                    hint.textContent = "Orders will be placed directly in this vehicle's load list";
                    hint.style.color = '#64748b';
                }
                return;
            }

            // Find option matching data-zone-id == zoneId
            let matchedOption = null;
            for (let i = 0; i < vehicleSelect.options.length; i++) {
                const opt = vehicleSelect.options[i];
                const optZid = parseInt(opt.getAttribute('data-zone-id')) || 0;
                if (optZid === zoneId) {
                    matchedOption = opt;
                    break;
                }
            }

            if (matchedOption) {
                vehicleSelect.value = matchedOption.value;
                if (autoLinkTag) autoLinkTag.style.display = 'inline-block';
                if (hint) {
                    const cleanLabel = matchedOption.text.split('—')[0].trim();
                    hint.innerHTML = `<i class='bx bx-check-circle' style="color: #059669;"></i> Auto-assigned to dedicated vehicle: <strong>${cleanLabel}</strong>`;
                    hint.style.color = '#059669';
                }
            } else {
                if (autoLinkTag) autoLinkTag.style.display = 'none';
                if (hint) {
                    hint.textContent = "No fixed vehicle for this zone. You may assign any Vehicles or leave unassigned.";
                    hint.style.color = '#64748b';
                }
            }
        }

        // When user manually alters vehicle selection
        const vehSelectEl = document.getElementById('vehicle_id');
        if (vehSelectEl) {
            vehSelectEl.addEventListener('change', function() {
                const zoneSelect = document.getElementById('zone_id');
                const autoLinkTag = document.getElementById('vehicle-autolink-tag');
                const hint = document.getElementById('vehicle-zone-hint');
                const currentZoneId = parseInt(zoneSelect?.value) || 0;
                const selectedOpt = this.options[this.selectedIndex];
                const optZid = parseInt(selectedOpt?.getAttribute('data-zone-id')) || 0;

                if (this.value && optZid > 0 && optZid === currentZoneId) {
                    if (autoLinkTag) autoLinkTag.style.display = 'inline-block';
                    if (hint) {
                        const cleanLabel = selectedOpt.text.split('—')[0].trim();
                        hint.innerHTML = `<i class='bx bx-check-circle' style="color: #059669;"></i> Fixed vehicle for this zone: <strong>${cleanLabel}</strong>`;
                        hint.style.color = '#059669';
                    }
                } else if (this.value) {
                    if (autoLinkTag) autoLinkTag.style.display = 'none';
                    if (hint) {
                        const cleanLabel = selectedOpt.text.split('—')[0].trim();
                        hint.innerHTML = `<i class='bx bxs-truck' style="color: #2563eb;"></i> Manually assigned: <strong>${cleanLabel}</strong>`;
                        hint.style.color = '#2563eb';
                    }
                } else {
                    if (autoLinkTag) autoLinkTag.style.display = 'none';
                    if (hint) {
                        hint.textContent = "Orders will be saved without vehicle assignment.";
                        hint.style.color = '#64748b';
                    }
                }
            });
        }

        // --- SEARCHABLE SHOP DROPDOWN ENGINE ---
        const registeredAdminEditShops = <?= json_encode($allShops) ?>;
        let activeAdminEditShopIndex = -1;

        function renderAdminEditShopDropdown(filter = '') {
            const listEl = document.getElementById('adminEditShopDropdownList');
            const countEl = document.getElementById('adminEditShopFilteredCount');
            if (!listEl) return;

            const q = filter.trim().toLowerCase();
            let matches = registeredAdminEditShops;

            if (q !== '') {
                matches = registeredAdminEditShops.filter(s => {
                    const name = (s.shop_name || '').toLowerCase();
                    const loc = (s.location || '').toLowerCase();
                    const ph = (s.phone || '').toLowerCase();
                    const zn = (s.zone_name || '').toLowerCase();
                    return name.includes(q) || loc.includes(q) || ph.includes(q) || zn.includes(q);
                });
            }

            if (countEl) {
                countEl.textContent = matches.length + (matches.length === 1 ? ' shop' : ' shops');
            }

            let html = '';

            // If user typed something and it's not an exact match, show "Use [typed] as new shop" option
            const hasExact = matches.some(s => (s.shop_name || '').toLowerCase() === q);
            if (q !== '' && !hasExact) {
                html += `
                    <div class="shop-dropdown-new-option" onclick="selectAdminEditShopCustom('${escapeAdminAttr(filter.trim())}')">
                        <i class='bx bx-plus-circle' style="font-size: 16px;"></i>
                        <span>Use "<strong>${escapeAdminHtml(filter.trim())}</strong>" as New Shop</span>
                    </div>
                `;
            }

            if (matches.length === 0) {
                if (!html) {
                    html = `<div class="shop-dropdown-empty">No shops found matching "${escapeAdminHtml(filter)}". Type to enter custom shop.</div>`;
                }
            } else {
                const displayShops = matches.slice(0, 100);
                displayShops.forEach((s, idx) => {
                    const currentVal = (document.getElementById('shop_name')?.value || '').trim().toLowerCase();
                    const isExactMatch = (s.shop_name || '').toLowerCase() === currentVal;
                    html += `
                        <div class="shop-dropdown-item ${isExactMatch ? 'active' : ''}" data-index="${idx}" onclick="selectAdminEditShopByIndex(${idx})">
                            <div class="shop-item-info">
                                <div class="shop-item-name">${highlightMatch(s.shop_name, q)}</div>
                                <div class="shop-item-meta">
                                    ${s.location ? `<span><i class='bx bx-map-pin' style="color:#2563eb;"></i> ${escapeAdminHtml(s.location)}</span>` : ''}
                                    ${s.phone ? `<span><i class='bx bx-phone' style="color:#059669;"></i> ${escapeAdminHtml(s.phone)}</span>` : ''}
                                </div>
                            </div>
                            <div class="shop-item-badges">
                                ${s.zone_name ? `<span class="shop-zone-pill">${escapeAdminHtml(s.zone_name)}</span>` : ''}
                            </div>
                        </div>
                    `;
                });
            }

            listEl.innerHTML = html;
            window._currentFilteredAdminEditShops = matches.slice(0, 100);
            activeAdminEditShopIndex = -1;
        }

        function highlightMatch(text, query) {
            if (!text) return '';
            if (!query) return escapeAdminHtml(text);
            const idx = text.toLowerCase().indexOf(query.toLowerCase());
            if (idx === -1) return escapeAdminHtml(text);
            const before = escapeAdminHtml(text.substring(0, idx));
            const match = escapeAdminHtml(text.substring(idx, idx + query.length));
            const after = escapeAdminHtml(text.substring(idx + query.length));
            return `${before}<mark style="background:#fef08a; padding: 1px 3px; border-radius: 3px; font-weight:800;">${match}</mark>${after}`;
        }

        function escapeAdminHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function escapeAdminAttr(str) {
            if (!str) return '';
            return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
        }

        function toggleAdminEditShopDropdown() {
            const dropdown = document.getElementById('adminEditShopSearchDropdown');
            const icon = document.getElementById('adminEditShopChevron');
            if (!dropdown) return;
            const isHidden = dropdown.style.display === 'none';
            if (isHidden) {
                dropdown.style.display = 'flex';
                if (icon) icon.className = 'bx bx-chevron-up';
                const mainVal = document.getElementById('shop_name')?.value || '';
                const searchInp = document.getElementById('adminEditShopFilterInput');
                if (searchInp) {
                    searchInp.value = mainVal;
                    renderAdminEditShopDropdown(mainVal);
                    setTimeout(() => searchInp.focus(), 60);
                } else {
                    renderAdminEditShopDropdown(mainVal);
                }
            } else {
                closeAdminEditShopDropdown();
            }
        }

        function closeAdminEditShopDropdown() {
            const dropdown = document.getElementById('adminEditShopSearchDropdown');
            const icon = document.getElementById('adminEditShopChevron');
            if (dropdown) dropdown.style.display = 'none';
            if (icon) icon.className = 'bx bx-chevron-down';
        }

        function selectAdminEditShopByIndex(idx) {
            const list = window._currentFilteredAdminEditShops || registeredAdminEditShops;
            const shop = list[idx];
            if (!shop) return;

            const shopInput = document.getElementById('shop_name');
            const locInput = document.getElementById('location');
            const phoneInput = document.getElementById('phone');
            const zoneSel = document.getElementById('zone_id');
            const vehSel = document.getElementById('vehicle_id');
            const clearBtn = document.getElementById('btnClearAdminEditShop');

            if (shopInput) shopInput.value = shop.shop_name;
            if (locInput && shop.location) locInput.value = shop.location;
            if (phoneInput && shop.phone) phoneInput.value = shop.phone;
            if (clearBtn) clearBtn.style.display = 'inline-flex';

            if (zoneSel && shop.zone_id && parseInt(shop.zone_id) > 0) {
                zoneSel.value = shop.zone_id;
                handleZoneChange(shop.zone_id);
            }
            if (vehSel && shop.vehicle_id && parseInt(shop.vehicle_id) > 0) {
                setTimeout(() => { vehSel.value = shop.vehicle_id; }, 100);
            }

            closeAdminEditShopDropdown();
        }

        function selectAdminEditShopCustom(customName) {
            const shopInput = document.getElementById('shop_name');
            const clearBtn = document.getElementById('btnClearAdminEditShop');
            if (shopInput) shopInput.value = customName;
            if (clearBtn) clearBtn.style.display = 'inline-flex';
            closeAdminEditShopDropdown();
        }

        function clearAdminEditShopSelection() {
            const shopInput = document.getElementById('shop_name');
            const locInput = document.getElementById('location');
            const phoneInput = document.getElementById('phone');
            const clearBtn = document.getElementById('btnClearAdminEditShop');

            if (shopInput) {
                shopInput.value = '';
                shopInput.focus();
            }
            if (locInput) locInput.value = '';
            if (phoneInput) phoneInput.value = '';
            if (clearBtn) clearBtn.style.display = 'none';

            renderAdminEditShopDropdown('');
        }

        // Connect Input Event Listeners
        const shopInp = document.getElementById('shop_name');
        if (shopInp) {
            shopInp.addEventListener('focus', function() {
                const dropdown = document.getElementById('adminEditShopSearchDropdown');
                if (dropdown && dropdown.style.display === 'none') {
                    renderAdminEditShopDropdown(this.value);
                    dropdown.style.display = 'flex';
                    const icon = document.getElementById('adminEditShopChevron');
                    if (icon) icon.className = 'bx bx-chevron-up';
                }
            });

            shopInp.addEventListener('input', function() {
                const val = this.value;
                const clearBtn = document.getElementById('btnClearAdminEditShop');
                if (clearBtn) clearBtn.style.display = val ? 'inline-flex' : 'none';

                const dropdown = document.getElementById('adminEditShopSearchDropdown');
                if (dropdown) {
                    dropdown.style.display = 'flex';
                    const icon = document.getElementById('adminEditShopChevron');
                    if (icon) icon.className = 'bx bx-chevron-up';
                }
                const filterInp = document.getElementById('adminEditShopFilterInput');
                if (filterInp) filterInp.value = val;
                renderAdminEditShopDropdown(val);

                // Auto match on exact full typing
                const match = registeredAdminEditShops.find(s => (s.shop_name || '').toLowerCase() === val.trim().toLowerCase());
                if (match) {
                    const locInp = document.getElementById('location');
                    const phoneInp = document.getElementById('phone');
                    const zoneSel = document.getElementById('zone_id');
                    const vehSel = document.getElementById('vehicle_id');

                    if (locInp && match.location) locInp.value = match.location;
                    if (phoneInp && match.phone && !phoneInp.value) phoneInp.value = match.phone;
                    if (zoneSel && match.zone_id) {
                        zoneSel.value = match.zone_id;
                        handleZoneChange(match.zone_id);
                    }
                    if (vehSel && match.vehicle_id) {
                        setTimeout(() => { vehSel.value = match.vehicle_id; }, 100);
                    }
                }
            });

            // Keyboard navigation (Down, Up, Enter, Escape)
            shopInp.addEventListener('keydown', handleAdminEditDropdownKeydown);
        }

        const filterInp = document.getElementById('adminEditShopFilterInput');
        if (filterInp) {
            filterInp.addEventListener('input', function() {
                renderAdminEditShopDropdown(this.value);
            });
            filterInp.addEventListener('keydown', handleAdminEditDropdownKeydown);
        }

        function handleAdminEditDropdownKeydown(e) {
            const dropdown = document.getElementById('adminEditShopSearchDropdown');
            if (!dropdown || dropdown.style.display === 'none') return;
            const items = dropdown.querySelectorAll('.shop-dropdown-item');
            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeAdminEditShopIndex = (activeAdminEditShopIndex + 1) % items.length;
                updateActiveAdminEditItem(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeAdminEditShopIndex = (activeAdminEditShopIndex - 1 + items.length) % items.length;
                updateActiveAdminEditItem(items);
            } else if (e.key === 'Enter') {
                if (activeAdminEditShopIndex >= 0 && activeAdminEditShopIndex < items.length) {
                    e.preventDefault();
                    items[activeAdminEditShopIndex].click();
                }
            } else if (e.key === 'Escape') {
                closeAdminEditShopDropdown();
            }
        }

        function updateActiveAdminEditItem(items) {
            items.forEach((it, i) => {
                if (i === activeAdminEditShopIndex) {
                    it.classList.add('active');
                    it.scrollIntoView({ block: 'nearest' });
                } else {
                    it.classList.remove('active');
                }
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const wrap = document.querySelector('.searchable-combobox-wrap');
            if (wrap && !wrap.contains(e.target)) {
                closeAdminEditShopDropdown();
            }
        });

        // Run initialization immediately on load
        function initPage() {
            itemsTableBody.querySelectorAll('.item-row').forEach(row => {
                const select = row.querySelector('.item-product-select');
                if (select && select.value) {
                    const pid = parseInt(select.value) || 0;
                    if (pid > 0 && PRODUCTS_MAP[pid]) {
                        updateStockBadge(row, PRODUCTS_MAP[pid].stock);
                    }
                    const rateInput = row.querySelector('.item-rate-input');
                    const selectedOption = select.options[select.selectedIndex];
                    if (!rateInput.value && selectedOption && selectedOption.getAttribute('data-price')) {
                        rateInput.value = parseFloat(selectedOption.getAttribute('data-price')).toFixed(2);
                    }
                }
                updateRowLineTotal(row);
            });
            recalculateAll();

            // Check initial vehicle linkage
            const initZoneSelect = document.getElementById('zone_id');
            const initVehSelect = document.getElementById('vehicle_id');
            if (initZoneSelect && initZoneSelect.value && initVehSelect && initVehSelect.value) {
                const curOpt = initVehSelect.options[initVehSelect.selectedIndex];
                const curZid = parseInt(curOpt?.getAttribute('data-zone-id')) || 0;
                if (curZid === parseInt(initZoneSelect.value)) {
                    const autoLinkTag = document.getElementById('vehicle-autolink-tag');
                    const hint = document.getElementById('vehicle-zone-hint');
                    if (autoLinkTag) autoLinkTag.style.display = 'inline-block';
                    if (hint) {
                        const cleanLabel = curOpt.text.split('—')[0].trim();
                        hint.innerHTML = `<i class='bx bx-check-circle' style="color: #059669;"></i> Dedicated vehicle for this zone: <strong>${cleanLabel}</strong>`;
                        hint.style.color = '#059669';
                    }
                }
            }

            // Background stock auto-refresh every 15 seconds
            setInterval(() => {
                refreshLiveStocks(false);
            }, 15000);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPage);
        } else {
            initPage();
        }
    </script>
</body>
</html>
