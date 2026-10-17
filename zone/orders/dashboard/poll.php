<?php
/**
 * Real-time Polling & Order Action API for Zone Orders Live Dashboard
 * Responds with JSON data for incoming orders, status changes, and metrics
 */
require_once dirname(__DIR__) . '/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure request is authorized
if (!isZoneOrdersAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'unauthenticated' => true, 'error' => 'Session expired. Please log in again.']);
    exit;
}

$action = $_REQUEST['action'] ?? 'poll';
$selectedZone = trim($_REQUEST['zone'] ?? 'all'); // 'all' or zone_id or zone_slug

/**
 * Robust helper to safely extract and validate order_id from various request methods,
 * superglobals, JSON payloads, or string formats (#123, ORD-123).
 */
function getRequestedOrderId(PDO $pdo): int {
    $raw = $_POST['order_id'] ?? $_GET['order_id'] ?? $_REQUEST['order_id'] ?? $_POST['id'] ?? $_GET['id'] ?? $_REQUEST['id'] ?? $_POST['orderId'] ?? $_GET['orderId'] ?? null;

    if ($raw === null || $raw === '') {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $json = json_decode($input, true);
            if (is_array($json)) {
                $raw = $json['order_id'] ?? $json['id'] ?? $json['orderId'] ?? null;
            }
        }
    }

    if ($raw !== null && $raw !== '') {
        $id = (int)$raw;
        if ($id > 0) {
            return $id;
        }

        // Handle string formats like "#112", "ORD-112", etc.
        $trimmed = trim((string)$raw);
        $cleanDigits = preg_replace('/[^0-9]/', '', $trimmed);
        if (!empty($cleanDigits)) {
            $numericId = (int)$cleanDigits;
            if ($numericId > 0) {
                $chk = $pdo->prepare("SELECT order_id FROM orders WHERE order_id = ?");
                $chk->execute([$numericId]);
                if ($chk->fetchColumn()) {
                    return $numericId;
                }
            }
        }

        // Try lookup by order_number
        $numChk = $pdo->prepare("SELECT order_id FROM orders WHERE order_number = ? LIMIT 1");
        $numChk->execute([$trimmed]);
        $foundId = (int)$numChk->fetchColumn();
        if ($foundId > 0) {
            return $foundId;
        }
    }

    return 0;
}

try {
    // ----------------------------------------------------
    // Action 1: Mark Single Order as Checked / Read
    // ----------------------------------------------------
    if ($action === 'mark_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = getRequestedOrderId($pdo);
        if ($order_id > 0) {
            $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE order_id = ?");
            $stmt->execute([$order_id]);
            echo json_encode(['success' => true, 'order_id' => $order_id, 'message' => "Order #{$order_id} marked as checked."]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
        exit;
    }

    // ----------------------------------------------------
    // Action 2: Mark All Orders as Read
    // ----------------------------------------------------
    if ($action === 'mark_all_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($selectedZone !== 'all' && !empty($selectedZone)) {
            if (is_numeric($selectedZone)) {
                $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE zone_id = ? AND is_zone_read = 0");
                $stmt->execute([(int)$selectedZone]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE orders o
                    JOIN zones z ON o.zone_id = z.id
                    SET o.is_zone_read = 1, o.zone_read_at = NOW()
                    WHERE z.slug = ? AND o.is_zone_read = 0
                ");
                $stmt->execute([$selectedZone]);
            }
        } else {
            $stmt = $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE is_zone_read = 0");
            $stmt->execute();
        }
        echo json_encode(['success' => true, 'message' => 'All pending orders marked as checked.']);
        exit;
    }

    // ----------------------------------------------------
    // Action 3: Quick Status Update
    // ----------------------------------------------------
    if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = getRequestedOrderId($pdo);
        $new_status = trim($_POST['status'] ?? ($_REQUEST['status'] ?? ''));
        $allowed = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        if (!in_array($new_status, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid status value.']);
            exit;
        }

        $oldStmt = $pdo->prepare("SELECT status, shop_name, customer_name, phone FROM orders WHERE order_id = ?");
        $oldStmt->execute([$order_id]);
        $oData = $oldStmt->fetch(PDO::FETCH_ASSOC);

        if (!$oData) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        $old_status = $oData['status'] ?: 'pending';

        $upStmt = $pdo->prepare("
            UPDATE orders 
            SET status = ?, is_zone_read = 1, zone_read_at = COALESCE(zone_read_at, NOW()), updated_at = NOW() 
            WHERE order_id = ?
        ");
        $upStmt->execute([$new_status, $order_id]);

        // Adjust product inventory stock automatically
        handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);

        // Update shop reward progress if delivered or cancelled
        if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
            $shopName = $oData['shop_name'] ?: ($oData['customer_name'] ?: '');
            if ($shopName !== '') {
                updateShopRewardProgress($pdo, $shopName, $oData['phone'] ?? '');
            }
        }

        // If delivered or cancelled, auto-reset assigned vehicle & order loads while preserving lifetime delivery count
        $vehicleReset = false;
        if ($new_status === 'delivered') {
            $vStmt = $pdo->prepare("SELECT vehicle_id FROM orders WHERE order_id = ?");
            $vStmt->execute([$order_id]);
            $currVehId = (int)$vStmt->fetchColumn();
            if ($currVehId > 0) {
                $pdo->prepare("UPDATE vehicles SET total_deliveries_done = total_deliveries_done + 1 WHERE id = ?")->execute([$currVehId]);
                $pdo->prepare("UPDATE orders SET vehicle_id = NULL WHERE order_id = ?")->execute([$order_id]);
                $pdo->prepare("DELETE FROM vehicle_loads WHERE order_id = ?")->execute([$order_id]);
                $vehicleReset = true;
            }
        } elseif ($new_status === 'cancelled') {
            $pdo->prepare("UPDATE orders SET vehicle_id = NULL WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("DELETE FROM vehicle_loads WHERE order_id = ?")->execute([$order_id]);
            $vehicleReset = true;
        }

        // Return fresh metrics
        $freshMetrics = getLiveMetrics($pdo, $selectedZone);

        echo json_encode([
            'success'       => true,
            'order_id'      => $order_id,
            'old_status'    => $old_status,
            'new_status'    => $new_status,
            'badge_class'   => getStatusBadgeClass($new_status),
            'vehicle_reset' => $vehicleReset,
            'message'       => "Order #{$order_id} status updated to " . ucfirst($new_status),
            'metrics'       => $freshMetrics
        ]);
        exit;
    }

    // ----------------------------------------------------
    // Action 3b: Toggle In-Yard Status for Order
    // ----------------------------------------------------
    if ($action === 'toggle_in_yard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = getRequestedOrderId($pdo);
        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        $curStmt = $pdo->prepare("SELECT in_yard, vehicle_id FROM orders WHERE order_id = ?");
        $curStmt->execute([$order_id]);
        $row = $curStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        $newInYard = ((int)$row['in_yard'] === 1) ? 0 : 1;
        $pdo->prepare("UPDATE orders SET in_yard = ?, updated_at = NOW() WHERE order_id = ?")->execute([$newInYard, $order_id]);

        // Synchronize vehicle status if vehicle is assigned
        $vehId = (int)($row['vehicle_id'] ?? 0);
        if ($vehId > 0) {
            $newVehStatus = ($newInYard === 1) ? 'active' : 'dispatched';
            $pdo->prepare("UPDATE vehicles SET status = ? WHERE id = ?")->execute([$newVehStatus, $vehId]);
        }

        echo json_encode([
            'success'  => true,
            'order_id' => $order_id,
            'in_yard'  => $newInYard,
            'label'    => ($newInYard === 1 ? 'In Yard' : 'Not in Yard')
        ]);
        exit;
    }

    // ----------------------------------------------------
    // Action 3c: Add Products & Quantities for Order
    // ----------------------------------------------------
    if ($action === 'add_order_products' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = getRequestedOrderId($pdo);
        $raw_items = $_POST['items'] ?? [];

        if (empty($raw_items)) {
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $json = json_decode($input, true);
                if (is_array($json) && !empty($json['items'])) {
                    $raw_items = $json['items'];
                }
            }
        }

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        if (!is_array($raw_items) || empty($raw_items)) {
            echo json_encode(['success' => false, 'error' => 'Please select at least one product with quantity of 1 or more.']);
            exit;
        }

        $oStmt = $pdo->prepare("SELECT order_id, order_number, shop_name, customer_name, discount, status FROM orders WHERE order_id = ?");
        $oStmt->execute([$order_id]);
        $orderInfo = $oStmt->fetch(PDO::FETCH_ASSOC);
        if (!$orderInfo) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        // Fetch existing items for this order to manage stock diff and allowances
        $existing_items = getOrderItems($pdo, $order_id);
        $existingQtyMap = [];
        foreach ($existing_items as $ei) {
            $ep = (int)$ei['product_id'];
            $existingQtyMap[$ep] = ($existingQtyMap[$ep] ?? 0) + (int)$ei['quantity'];
        }

        $parsed_items = [];
        foreach ($raw_items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            $rate = (isset($it['rate']) && is_numeric($it['rate']) && (float)$it['rate'] >= 0) ? (float)$it['rate'] : null;
            $is_extra = (!empty($it['is_extra']) && (string)$it['is_extra'] !== '0' && (string)$it['is_extra'] !== 'false') ? 1 : 0;

            if ($pid > 0 && $qty > 0) {
                // Key by product_id and is_extra so regular and extra items don't collide
                $itemKey = $pid . '_' . $is_extra;
                if (isset($parsed_items[$itemKey])) {
                    $parsed_items[$itemKey]['quantity'] += $qty;
                    if ($rate !== null) $parsed_items[$itemKey]['rate'] = $rate;
                } else {
                    $parsed_items[$itemKey] = [
                        'product_id' => $pid,
                        'quantity'   => $qty,
                        'rate'       => $rate,
                        'is_extra'   => $is_extra
                    ];
                }
            }
        }

        if (empty($parsed_items)) {
            echo json_encode(['success' => false, 'error' => 'Please select at least one valid product and quantity.']);
            exit;
        }

        $pids = array_unique(array_column($parsed_items, 'product_id'));
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $pStmt = $pdo->prepare("
            SELECT 
                p.product_id, p.name, p.product_name, p.case_price, p.price, p.case_stock, p.stock,
                (
                    COALESCE((
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        JOIN orders ord ON oi.order_id = ord.order_id
                        WHERE oi.product_id = p.product_id AND ord.status IN ('pending', 'processing', 'shipped')
                    ), 0) + COALESCE((
                        SELECT SUM(ord2.quantity)
                        FROM orders ord2
                        WHERE ord2.product_id = p.product_id 
                          AND ord2.status IN ('pending', 'processing', 'shipped')
                          AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
                    ), 0) + COALESCE((
                        SELECT SUM(vl.cases_loaded)
                        FROM vehicle_loads vl
                        WHERE vl.product_id = p.product_id AND vl.is_active = 1 AND vl.order_id IS NULL
                    ), 0)
                ) AS cases_in_transit,
                COALESCE((
                    SELECT SUM(vl.cases_loaded)
                    FROM vehicle_loads vl
                    WHERE vl.product_id = p.product_id AND vl.is_active = 1 AND vl.order_id IS NULL
                ), 0) AS cases_loaded_vehicle
            FROM products p 
            WHERE p.product_id IN ($placeholders)
        ");
        $pStmt->execute(array_values($pids));
        $dbProds = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        $dbProdsMap = [];
        foreach ($dbProds as $dp) {
            $cStock = (int)($dp['case_stock'] !== null ? $dp['case_stock'] : ($dp['stock'] ?? 0));
            $loadedVehicle = (int)($dp['cases_loaded_vehicle'] ?? 0);
            $inTransit = (int)($dp['cases_in_transit'] ?? 0);
            $dp['available_stock'] = max(0, $cStock - $loadedVehicle);
            $dbProdsMap[(int)$dp['product_id']] = $dp;
        }

        // Validate that combined quantity across all rows (regular + extra) does not exceed available stock + already reserved stock
        $totalReqQty = [];
        foreach ($parsed_items as $itemData) {
            $p = $itemData['product_id'];
            $totalReqQty[$p] = ($totalReqQty[$p] ?? 0) + $itemData['quantity'];
        }
        foreach ($totalReqQty as $p => $reqQty) {
            if (!isset($dbProdsMap[$p])) continue;
            $dp = $dbProdsMap[$p];
            $pName = $dp['product_name'] ?: $dp['name'];
            $availStock = (int)$dp['available_stock'];
            $effectiveAvailStock = $availStock + ($existingQtyMap[$p] ?? 0);
            if ($reqQty > $effectiveAvailStock) {
                echo json_encode([
                    'success' => false,
                    'error'   => "Quantity for '{$pName}' ({$reqQty} cases) exceeds available stock of {$effectiveAvailStock} cases."
                ]);
                exit;
            }
        }

        $base_total = 0.0;
        $total_cases = 0;
        $valid_order_items = [];

        foreach ($parsed_items as $itemData) {
            $pid = (int)$itemData['product_id'];
            $qty = (int)$itemData['quantity'];
            if (!isset($dbProdsMap[$pid]) || $qty <= 0) continue;

            $dp = $dbProdsMap[$pid];
            $pName = $dp['product_name'] ?: $dp['name'];
            $defaultPrice = (float)($dp['case_price'] ?: $dp['price']);
            $isExtra = (int)$itemData['is_extra'];
            
            if ($isExtra === 1) {
                $price = 0.00;
                $line_total = 0.00;
            } else {
                $price = ($itemData['rate'] !== null && (float)$itemData['rate'] >= 0) ? (float)$itemData['rate'] : $defaultPrice;
                $line_total = $price * $qty;
                $base_total += $line_total;
            }
            $total_cases += $qty;

            $valid_order_items[] = [
                'product_id'        => $pid,
                'quantity'          => $qty,
                'price_at_purchase' => $price,
                'is_extra'          => $isExtra,
                'line_total'        => $line_total,
                'name'              => $pName
            ];
        }

        if (empty($valid_order_items)) {
            echo json_encode(['success' => false, 'error' => 'Selected products could not be resolved.']);
            exit;
        }

        $discount = (float)($orderInfo['discount'] ?? 0);
        if ($discount > $base_total) $discount = $base_total;
        $total_amount = max(0, $base_total - $discount);

        try {
            $pdo->beginTransaction();

            // 1. Manage Stock Adjustments (Restore old, Deduct new)
            $orderStatus = strtolower($orderInfo['status'] ?? 'pending');
            if ($orderStatus !== 'cancelled') {
                foreach ($existing_items as $oldItem) {
                    $oldPid = (int)($oldItem['product_id'] ?? 0);
                    $oldQty = (int)($oldItem['quantity'] ?? 0);
                    if ($oldPid > 0 && $oldQty > 0) {
                        adjustProductStock($pdo, $oldPid, +$oldQty, "Order #{$order_id} update: restored previous items");
                    }
                }
                foreach ($valid_order_items as $newItem) {
                    $newPid = (int)$newItem['product_id'];
                    $newQty = (int)$newItem['quantity'];
                    if ($newPid > 0 && $newQty > 0) {
                        adjustProductStock($pdo, $newPid, -$newQty, "Order #{$order_id} update: applied items");
                    }
                }
            }

            // 2. Replace order items
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);

            // Check if is_extra column exists in order_items table
            $hasIsExtraCol = false;
            try {
                $chkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'is_extra'")->fetch();
                if ($chkCol) $hasIsExtraCol = true;
            } catch (Exception $e) {}

            if ($hasIsExtraCol) {
                $insItem = $pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                foreach ($valid_order_items as $voi) {
                    $insItem->execute([
                        $order_id,
                        $voi['product_id'],
                        $voi['quantity'],
                        $voi['price_at_purchase'],
                        $voi['is_extra']
                    ]);
                }
            } else {
                $insItem = $pdo->prepare("
                    INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                foreach ($valid_order_items as $voi) {
                    $insItem->execute([
                        $order_id,
                        $voi['product_id'],
                        $voi['quantity'],
                        $voi['price_at_purchase']
                    ]);
                }
            }

            // 3. Update orders table
            // Pick primary non-extra item if available
            $primaryItem = $valid_order_items[0];
            foreach ($valid_order_items as $voi) {
                if (empty($voi['is_extra'])) {
                    $primaryItem = $voi;
                    break;
                }
            }
            $primary_pid = $primaryItem['product_id'];
            $primary_rate = $primaryItem['price_at_purchase'];

            $upOrder = $pdo->prepare("
                UPDATE orders 
                SET product_id = ?, quantity = ?, unit_price = ?, total_amount = ?, updated_at = NOW() 
                WHERE order_id = ?
            ");
            $upOrder->execute([$primary_pid, $total_cases, $primary_rate, $total_amount, $order_id]);

            $pdo->commit();

            // Sync vehicle loads if order is assigned to a vehicle
            $vCheckStmt = $pdo->prepare("SELECT vehicle_id FROM orders WHERE order_id = ?");
            $vCheckStmt->execute([$order_id]);
            $assignedVehId = (int)$vCheckStmt->fetchColumn();
            if ($assignedVehId > 0 && function_exists('syncOrderVehicleLoads')) {
                syncOrderVehicleLoads($pdo, $order_id, $assignedVehId);
            }

            $itemsSummaryArr = [];
            foreach ($valid_order_items as $voi) {
                $extraTag = !empty($voi['is_extra']) ? ' [Extra]' : '';
                $itemsSummaryArr[] = "{$voi['name']} ({$voi['quantity']}cs{$extraTag})";
            }
            $itemsSummary = implode(', ', $itemsSummaryArr);

            echo json_encode([
                'success'         => true,
                'order_id'        => $order_id,
                'items_summary'   => $itemsSummary,
                'total_cases'     => $total_cases,
                'total_amount'    => number_format($total_amount, 2, '.', ''),
                'formatted_total' => '₹' . number_format($total_amount, 2),
                'formatted_amt'   => '₹' . number_format($total_amount, 2),
                'message'         => "Products & quantities updated successfully for Order #{$order_id}."
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }

    // ----------------------------------------------------
    // Action 3d: Assign or Unassign Vehicle for Order
    // ----------------------------------------------------
    if (in_array($action, ['assign_vehicle', 'assign_order', 'unassign_order']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $order_id = getRequestedOrderId($pdo);
        $vehicle_id = (int)($_POST['vehicle_id'] ?? ($_REQUEST['vehicle_id'] ?? 0));
        if ($action === 'unassign_order') {
            $vehicle_id = 0;
        }

        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        if ($vehicle_id > 0) {
            $res = assignOrderToVehicle($pdo, $order_id, $vehicle_id);
            if ($res['success']) {
                $vStmt = $pdo->prepare("SELECT vehicle_number, vehicle_name FROM vehicles WHERE id = ?");
                $vStmt->execute([$vehicle_id]);
                $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
                $vPlate = $vRow ? $vRow['vehicle_number'] : "Vehicle #{$vehicle_id}";

                echo json_encode([
                    'success'        => true,
                    'order_id'       => $order_id,
                    'vehicle_id'     => $vehicle_id,
                    'vehicle_number' => $vRow['vehicle_number'] ?? '',
                    'vehicle_name'   => $vRow['vehicle_name'] ?? '',
                    'cases_loaded'   => $res['cases_loaded'] ?? 0,
                    'message'        => "Order assigned to {$vPlate}."
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Failed to assign vehicle.']);
            }
        } else {
            $res = unassignOrderFromVehicle($pdo, $order_id);
            echo json_encode([
                'success'    => true,
                'order_id'   => $order_id,
                'vehicle_id' => null,
                'message'    => 'Vehicle unassigned from order.'
            ]);
        }
        exit;
    }

    // ----------------------------------------------------
    // Action 3e: Create Reorder in Zone Portal
    // ----------------------------------------------------
    if ($action === 'create_reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $parent_order_id = !empty($_POST['parent_order_id']) ? (int)$_POST['parent_order_id'] : null;
        $shop_name       = trim($_POST['shop_name'] ?? '');
        $customer_name   = trim($_POST['customer_name'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $location        = trim($_POST['location'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $zone_id         = (int)($_POST['zone_id'] ?? 0);
        $discount        = (float)($_POST['discount'] ?? 0);
        $raw_items       = $_POST['items'] ?? [];

        if (empty($raw_items)) {
            $input = file_get_contents('php://input');
            if (!empty($input)) {
                $json = json_decode($input, true);
                if (is_array($json)) {
                    $raw_items = $json['items'] ?? [];
                    if (empty($shop_name)) $shop_name = trim($json['shop_name'] ?? '');
                    if (empty($customer_name)) $customer_name = trim($json['customer_name'] ?? '');
                    if (empty($phone)) $phone = trim($json['phone'] ?? '');
                    if (empty($location)) $location = trim($json['location'] ?? '');
                    if (empty($address)) $address = trim($json['address'] ?? '');
                    if ($zone_id <= 0) $zone_id = (int)($json['zone_id'] ?? 0);
                    if ($discount <= 0) $discount = (float)($json['discount'] ?? 0);
                    if (!$parent_order_id && !empty($json['parent_order_id'])) $parent_order_id = (int)$json['parent_order_id'];
                }
            }
        }

        if (empty($shop_name)) {
            echo json_encode(['success' => false, 'error' => 'Shop / Customer Name is required.']);
            exit;
        }

        if ($zone_id <= 0 && $parent_order_id > 0) {
            $zStmt = $pdo->prepare("SELECT zone_id FROM orders WHERE order_id = ?");
            $zStmt->execute([$parent_order_id]);
            $zone_id = (int)$zStmt->fetchColumn();
        }

        if ($zone_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Please select a delivery zone.']);
            exit;
        }

        if (!is_array($raw_items) || empty($raw_items)) {
            echo json_encode(['success' => false, 'error' => 'Please select at least one product for the reorder.']);
            exit;
        }

        $parsed_items = [];
        foreach ($raw_items as $row) {
            $pid = (int)($row['product_id'] ?? 0);
            $qty = (int)($row['quantity'] ?? 0);
            $rate = (isset($row['rate']) && is_numeric($row['rate']) && (float)$row['rate'] >= 0) ? (float)$row['rate'] : null;
            $isExtra = !empty($row['is_extra']) ? 1 : 0;

            if ($pid > 0 && $qty > 0) {
                $parsed_items[] = [
                    'product_id' => $pid,
                    'quantity'   => $qty,
                    'rate'       => $rate,
                    'is_extra'   => $isExtra
                ];
            }
        }

        if (empty($parsed_items)) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid quantity of 1 or more for each product.']);
            exit;
        }

        // Fetch product details
        $pids = array_unique(array_column($parsed_items, 'product_id'));
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $pStmt = $pdo->prepare("SELECT product_id, name, product_name, case_price, price, case_stock, stock FROM products WHERE product_id IN ($placeholders)");
        $pStmt->execute($pids);
        $dbProducts = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $dbMap = [];
        foreach ($dbProducts as $dp) {
            $dbMap[(int)$dp['product_id']] = $dp;
        }

        $base_total = 0.0;
        $total_cases = 0;
        $valid_order_items = [];

        foreach ($parsed_items as $item) {
            $pid = $item['product_id'];
            if (!isset($dbMap[$pid])) {
                echo json_encode(['success' => false, 'error' => "Selected product #{$pid} is not found."]);
                exit;
            }
            $dp = $dbMap[$pid];
            $defaultPrice = (float)($dp['case_price'] ?: $dp['price']);
            $price = ($item['is_extra'] == 1) ? 0.00 : (($item['rate'] !== null && (float)$item['rate'] >= 0) ? (float)$item['rate'] : $defaultPrice);
            $qty = $item['quantity'];
            $line_total = $price * $qty;

            $base_total += $line_total;
            $total_cases += $qty;

            $valid_order_items[] = [
                'product_id'        => $pid,
                'quantity'          => $qty,
                'price_at_purchase' => $price,
                'line_total'        => $line_total,
                'is_extra'          => $item['is_extra'],
                'name'              => $dp['product_name'] ?: $dp['name']
            ];
        }

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

            $primaryItem = $valid_order_items[0];
            foreach ($valid_order_items as $voi) {
                if (empty($voi['is_extra'])) {
                    $primaryItem = $voi;
                    break;
                }
            }
            $primary_pid = $primaryItem['product_id'];
            $primary_unit_price = $primaryItem['price_at_purchase'];

            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    order_number, zone_id, shop_name, customer_name,
                    location, phone, address, product_id,
                    quantity, unit_price, discount, total_amount,
                    status, is_zone_read, is_reorder, parent_order_id,
                    payment_method, payment_status, order_date, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    'pending', 0, 1, ?,
                    'cod', 'pending', NOW(), NOW(), NOW()
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
                $total_amount,
                $parent_order_id ?: null
            ]);

            $new_order_id = (int)$pdo->lastInsertId();

            // Insert line items
            $insItem = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, NOW(), NOW()
                )
            ");

            foreach ($valid_order_items as $voi) {
                $insItem->execute([
                    $new_order_id,
                    $voi['product_id'],
                    $voi['quantity'],
                    $voi['price_at_purchase'],
                    $voi['is_extra']
                ]);

                // Adjust warehouse product stock
                adjustProductStock($pdo, $voi['product_id'], -$voi['quantity'], "Reorder #{$order_number} created in Zone Portal");
            }

            $pdo->commit();

            if (function_exists('quickLog')) {
                quickLog($pdo, 'create', 'order', $new_order_id, "Created Reorder #{$order_number} for {$shop_name} via Zone Orders Hub [{$total_cases} cases, ₹{$total_amount}]");
            }

            echo json_encode([
                'success'      => true,
                'message'      => "Reorder #{$order_number} placed successfully!",
                'order_id'     => $new_order_id,
                'order_number' => $order_number
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'error' => 'Database error placing reorder: ' . $e->getMessage()]);
            exit;
        }
    }

    // ----------------------------------------------------
    // Action 4: Get Full Order Details (for Quick Modal)
    // ----------------------------------------------------
    if ($action === 'get_order_details') {
        $order_id = getRequestedOrderId($pdo);
        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT 
                o.*,
                z.name AS zone_name,
                z.slug AS zone_slug,
                p.product_name,
                p.name AS fallback_product_name,
                p.case_price,
                p.net_content,
                p.net_content_unit,
                r.id AS receipt_id,
                r.bill_number,
                r.delivered_quantity,
                r.cash_amount AS receipt_cash,
                r.credited_amount AS receipt_credit,
                r.due_amount AS receipt_due,
                r.payment_type AS receipt_pay_type,
                r.notes AS receipt_notes
            FROM orders o
            LEFT JOIN zones z ON o.zone_id = z.id
            LEFT JOIN products p ON o.product_id = p.product_id
            LEFT JOIN receipts r ON o.order_id = r.order_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        $items = getOrderItems($pdo, $order_id);
        $order['items'] = $items;
        $order['formatted_date'] = formatIST($order['created_at']);
        $order['badge_class'] = getStatusBadgeClass($order['status']);

        // Fetch assigned vehicle & any remaining storage
        $order['vehicle_number'] = '';
        $order['vehicle_name'] = '';
        $order['vehicle_storage'] = [];

        $vId = (int)($order['vehicle_id'] ?? 0);
        if ($vId <= 0) {
            $vChk = $pdo->prepare("SELECT vehicle_id FROM vehicle_loads WHERE order_id = ? LIMIT 1");
            $vChk->execute([$order_id]);
            $vId = (int)$vChk->fetchColumn();
        }

        if ($vId > 0) {
            $vStmt = $pdo->prepare("SELECT vehicle_number, vehicle_name FROM vehicles WHERE id = ?");
            $vStmt->execute([$vId]);
            $vehInfo = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($vehInfo) {
                $order['vehicle_number'] = $vehInfo['vehicle_number'];
                $order['vehicle_name'] = $vehInfo['vehicle_name'];
            }
            $stStmt = $pdo->prepare("
                SELECT vl.cases_loaded, COALESCE(p.product_name, p.name) as product_name 
                FROM vehicle_loads vl
                LEFT JOIN products p ON vl.product_id = p.product_id
                WHERE vl.vehicle_id = ? AND vl.is_active = 1 AND vl.product_id IS NOT NULL AND vl.order_id IS NULL
            ");
            $stStmt->execute([$vId]);
            $order['vehicle_storage'] = $stStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Fetch live catalog products with live warehouse available stock
        try {
            $productsStmt = $pdo->query("
                SELECT 
                    p.product_id,
                    p.name,
                    p.product_name,
                    p.case_price,
                    p.price,
                    p.case_stock,
                    p.stock,
                    p.net_content,
                    p.net_content_unit,
                    (
                        COALESCE((
                            SELECT SUM(oi.quantity)
                            FROM order_items oi
                            JOIN orders ord ON oi.order_id = ord.order_id
                            WHERE oi.product_id = p.product_id AND ord.status IN ('pending', 'processing', 'shipped')
                        ), 0) + COALESCE((
                            SELECT SUM(ord2.quantity)
                            FROM orders ord2
                            WHERE ord2.product_id = p.product_id 
                              AND ord2.status IN ('pending', 'processing', 'shipped')
                              AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
                        ), 0) + COALESCE((
                            SELECT SUM(vl.cases_loaded)
                            FROM vehicle_loads vl
                            WHERE vl.product_id = p.product_id AND vl.is_active = 1 AND vl.order_id IS NULL
                        ), 0)
                    ) AS cases_in_transit,
                    COALESCE((
                        SELECT SUM(vl.cases_loaded)
                        FROM vehicle_loads vl
                        WHERE vl.product_id = p.product_id AND vl.is_active = 1 AND vl.order_id IS NULL
                    ), 0) AS cases_loaded_vehicle
                FROM products p
                WHERE p.status != 'inactive' OR p.status IS NULL
                ORDER BY p.case_price ASC, p.name ASC
            ");
            $liveProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($liveProducts as &$lp) {
                $cStock = (int)($lp['case_stock'] !== null ? $lp['case_stock'] : ($lp['stock'] ?? 0));
                $loadedVehicle = (int)($lp['cases_loaded_vehicle'] ?? 0);
                $inTransit = (int)($lp['cases_in_transit'] ?? 0);
                $availStock = max(0, $cStock - $loadedVehicle);
                $lp['raw_stock'] = $cStock;
                $lp['cases_loaded_vehicle'] = $loadedVehicle;
                $lp['cases_in_transit'] = $inTransit;
                $lp['case_stock'] = $availStock;
                $lp['stock'] = $availStock;
                $lp['available_stock'] = $availStock;
            }
            unset($lp);
            $order['products'] = $liveProducts;
        } catch (Exception $e) {
            $order['products'] = [];
        }

        echo json_encode(['success' => true, 'order' => $order]);
        exit;
    }

    // ----------------------------------------------------
    // Action 3e: Fetch Live Products Catalog with Available Stock
    // ----------------------------------------------------
    if ($action === 'get_live_products') {
        try {
            $productsStmt = $pdo->query("
                SELECT 
                    p.product_id,
                    p.name,
                    p.product_name,
                    p.case_price,
                    p.price,
                    p.case_stock,
                    p.stock,
                    p.net_content,
                    p.net_content_unit,
                    (
                        COALESCE((
                            SELECT SUM(oi.quantity)
                            FROM order_items oi
                            JOIN orders ord ON oi.order_id = ord.order_id
                            WHERE oi.product_id = p.product_id AND ord.status IN ('pending', 'processing', 'shipped')
                        ), 0) + COALESCE((
                            SELECT SUM(ord2.quantity)
                            FROM orders ord2
                            WHERE ord2.product_id = p.product_id 
                              AND ord2.status IN ('pending', 'processing', 'shipped')
                              AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
                        ), 0) + COALESCE((
                            SELECT SUM(vl.cases_loaded)
                            FROM vehicle_loads vl
                            WHERE vl.product_id = p.product_id AND vl.is_active = 1 AND vl.order_id IS NULL
                        ), 0)
                    ) AS cases_in_transit,
                    COALESCE((
                        SELECT SUM(vl.cases_loaded)
                        FROM vehicle_loads vl
                        WHERE vl.product_id = p.product_id AND vl.is_active = 1 AND vl.order_id IS NULL
                    ), 0) AS cases_loaded_vehicle
                FROM products p
                WHERE p.status != 'inactive' OR p.status IS NULL
                ORDER BY p.case_price ASC, p.name ASC
            ");
            $liveProducts = $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($liveProducts as &$lp) {
                $cStock = (int)($lp['case_stock'] !== null ? $lp['case_stock'] : ($lp['stock'] ?? 0));
                $loadedVehicle = (int)($lp['cases_loaded_vehicle'] ?? 0);
                $inTransit = (int)($lp['cases_in_transit'] ?? 0);
                $availStock = max(0, $cStock - $loadedVehicle);
                $lp['raw_stock'] = $cStock;
                $lp['cases_loaded_vehicle'] = $loadedVehicle;
                $lp['cases_in_transit'] = $inTransit;
                $lp['case_stock'] = $availStock;
                $lp['stock'] = $availStock;
                $lp['available_stock'] = $availStock;
            }
            unset($lp);
            echo json_encode(['success' => true, 'products' => $liveProducts]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ----------------------------------------------------
    // Default Action: Real-Time Poll for New Orders & Metrics
    // ----------------------------------------------------
    $zoneWhere = "";
    $zoneParams = [];

    if ($selectedZone !== 'all' && !empty($selectedZone)) {
        if (is_numeric($selectedZone)) {
            $zoneWhere = "AND o.zone_id = :zid";
            $zoneParams[':zid'] = (int)$selectedZone;
        } else {
            $zoneWhere = "AND z.slug = :zslug";
            $zoneParams[':zslug'] = $selectedZone;
        }
    }

    // Fetch unread incoming orders
    $sqlUnread = "
        SELECT 
            o.order_id,
            o.order_number,
            o.zone_id,
            o.shop_name,
            o.customer_name,
            o.location,
            o.phone,
            o.quantity,
            o.total_amount,
            o.status,
            o.is_zone_read,
            o.created_at,
            z.name AS zone_name,
            z.slug AS zone_slug,
            COALESCE(p.product_name, p.name, 'Drinking Water') AS product_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
            (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs)') SEPARATOR ', ') 
             FROM order_items oi2 
             JOIN products p2 ON oi2.product_id = p2.product_id 
             WHERE oi2.order_id = o.order_id) AS items_summary
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN products p ON o.product_id = p.product_id
        WHERE o.is_zone_read = 0 AND o.status != 'cancelled' $zoneWhere
        ORDER BY o.order_id DESC
    ";

    $stmtUnread = $pdo->prepare($sqlUnread);
    $stmtUnread->execute($zoneParams);
    $unreadOrders = $stmtUnread->fetchAll(PDO::FETCH_ASSOC);

    // Format fields for frontend
    foreach ($unreadOrders as &$uo) {
        $uo['formatted_date'] = formatIST($uo['created_at']);
        $uo['color_config'] = getZoneColorConfig($uo['zone_slug'] ?? '');
        $uo['badge_class'] = getStatusBadgeClass($uo['status']);
    }
    unset($uo);

    // Latest order ID across selected zone
    $sqlLatest = "
        SELECT MAX(o.order_id) 
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        WHERE 1=1 $zoneWhere
    ";
    $stmtLatest = $pdo->prepare($sqlLatest);
    $stmtLatest->execute($zoneParams);
    $latestOrderId = (int)$stmtLatest->fetchColumn();

    $metrics = getLiveMetrics($pdo, $selectedZone);

    echo json_encode([
        'success'         => true,
        'zone'            => $selectedZone,
        'unread_count'    => count($unreadOrders),
        'latest_order_id' => $latestOrderId,
        'orders'          => $unreadOrders,
        'metrics'         => $metrics,
        'timestamp'       => time()
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

/**
 * Helper to fetch aggregated metrics for zone dashboard
 */
function getLiveMetrics(PDO $pdo, string $selectedZone = 'all'): array {
    $where = "WHERE 1=1";
    $params = [];

    if ($selectedZone !== 'all' && !empty($selectedZone)) {
        if (is_numeric($selectedZone)) {
            $where .= " AND o.zone_id = :zid";
            $params[':zid'] = (int)$selectedZone;
        } else {
            $where .= " AND z.slug = :zslug";
            $params[':zslug'] = $selectedZone;
        }
    }

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.order_id) AS total_orders,
            SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN o.status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) AS shipped_count,
            SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN o.is_zone_read = 0 AND o.status != 'cancelled' THEN 1 ELSE 0 END) AS unread_count,
            COALESCE(SUM(o.total_amount), 0) AS total_order_value,
            COALESCE(SUM(r.cash_amount), 0) AS total_cash,
            COALESCE(SUM(r.credited_amount), 0) AS total_credit,
            COALESCE(SUM(r.due_amount), 0) AS total_due
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        LEFT JOIN receipts r ON o.order_id = r.order_id
        $where
    ");
    $stmt->execute($params);
    $res = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_orders'      => (int)($res['total_orders'] ?? 0),
        'pending_count'     => (int)($res['pending_count'] ?? 0),
        'processing_count'  => (int)($res['processing_count'] ?? 0),
        'shipped_count'     => (int)($res['shipped_count'] ?? 0),
        'delivered_count'   => (int)($res['delivered_count'] ?? 0),
        'cancelled_count'   => (int)($res['cancelled_count'] ?? 0),
        'unread_count'      => (int)($res['unread_count'] ?? 0),
        'total_order_value' => (float)($res['total_order_value'] ?? 0),
        'total_cash'        => (float)($res['total_cash'] ?? 0),
        'total_credit'      => (float)($res['total_credit'] ?? 0),
        'total_due'         => (float)($res['total_due'] ?? 0)
    ];
}
