<?php
require_once __DIR__ . '/zone_context.php';

// Quick AJAX endpoint to check prior pending dues for a shop or phone
if (isset($_GET['action']) && $_GET['action'] === 'check_prior_due') {
    header('Content-Type: application/json');
    $checkShop = trim($_GET['shop'] ?? '');
    $checkPhone = trim($_GET['phone'] ?? '');
    $exId = (int)($_GET['exclude_id'] ?? 0);
    $dues = getShopPendingDueDetails($pdo, $checkShop, $checkPhone, $exId, $zone_id);
    echo json_encode($dues);
    exit;
}

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0);
$reorder_id = isset($_GET['reorder_id']) ? (int)$_GET['reorder_id'] : (isset($_POST['reorder_id']) ? (int)$_POST['reorder_id'] : 0);
$is_reorder = ($reorder_id > 0 || !empty($_POST['is_reorder'])) ? 1 : 0;

// Load existing order or reorder source
$order = null;
$order_items = [];
$reorder_source = null;

if ($reorder_id > 0) {
    // Reorder mode: Load source order to prefill all details, but do not set $order
    // so billing creates a brand new order record in the database
    $rStmt = $pdo->prepare("
        SELECT o.*, p.product_name, p.name as fallback_product_name, p.case_price, p.price, p.net_content, p.net_content_unit
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.product_id
        WHERE o.order_id = ?
    ");
    $rStmt->execute([$reorder_id]);
    $reorder_source = $rStmt->fetch(PDO::FETCH_ASSOC);

    if ($reorder_source) {
        $order = null;
        $order_id = 0; // Fresh order id to be created on submit
        $order_items = []; // Do not prefill earlier products for reorder
    }
} elseif ($order_id > 0) {
    $stmt = $pdo->prepare("
        SELECT o.*, p.product_name, p.name as fallback_product_name, p.case_price, p.price, p.net_content, p.net_content_unit
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.product_id
        WHERE o.order_id = ? AND o.zone_id = ?
    ");
    $stmt->execute([$order_id, $zone_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    // If receipt already exists, redirect directly to receipt view
    if ($order) {
        if ((int)$order['is_zone_read'] === 0) {
            $pdo->prepare("UPDATE orders SET is_zone_read = 1, zone_read_at = NOW() WHERE order_id = ?")->execute([$order_id]);
            $order['is_zone_read'] = 1;
        }
        $chkRec = $pdo->prepare("SELECT id FROM receipts WHERE order_id = ?");
        $chkRec->execute([$order_id]);
        $existingRec = $chkRec->fetch();
        if ($existingRec) {
            header("Location: " . zone_url($zone_slug, 'receipt', ['id' => $order_id]));
            exit;
        }

        // Fetch all order items
        $order_items = getOrderItems($pdo, $order_id);
    }
}

// Load active products for selection
$products = $pdo->query("
    SELECT product_id, name, product_name, case_price, price, net_content, net_content_unit 
    FROM products 
    WHERE status = 'active' 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Map products by product_id for quick lookup
$productsById = [];
foreach ($products as $p) {
    $productsById[(int)$p['product_id']] = $p;
}

// Fallback: If no order items found for existing order (not reorder), construct from order row ONLY if valid product_id > 0 and quantity > 0
$checkOrd = $order;
if ($checkOrd && empty($order_items) && !empty($checkOrd['product_id']) && (int)$checkOrd['product_id'] > 0 && (int)$checkOrd['quantity'] > 0) {
    $pid = (int)$checkOrd['product_id'];
    $pRate = (float)($checkOrd['unit_price'] ?: ($checkOrd['case_price'] ?: $checkOrd['price']));
    $pQty = (int)$checkOrd['quantity'];
    $order_items[] = [
        'order_item_id' => 0,
        'order_id' => $order_id,
        'product_id' => $pid,
        'quantity' => $pQty,
        'price_at_purchase' => $pRate,
        'line_total' => $pQty * $pRate,
        'name' => $checkOrd['product_name'] ?: ($checkOrd['fallback_product_name'] ?: 'Liyas Water'),
        'product_name' => $checkOrd['product_name'] ?: ($checkOrd['fallback_product_name'] ?: 'Liyas Water'),
        'net_content' => $checkOrd['net_content'],
        'net_content_unit' => $checkOrd['net_content_unit'],
        'is_extra' => 0
    ];
}

// Determine assigned vehicle
$assignedVehicleId = (int)($order['vehicle_id'] ?? ($reorder_source['vehicle_id'] ?? 0));
if ($assignedVehicleId <= 0 && $order_id > 0) {
    $vChk = $pdo->prepare("SELECT vehicle_id FROM vehicle_loads WHERE order_id = ? AND is_active = 1 LIMIT 1");
    $vChk->execute([$order_id]);
    $assignedVehicleId = (int)$vChk->fetchColumn();
}
if ($assignedVehicleId <= 0 && $reorder_source && !empty($reorder_source['vehicle_id'])) {
    $assignedVehicleId = (int)$reorder_source['vehicle_id'];
}
if ($assignedVehicleId <= 0) {
    $assignedVehicleId = (int)($_GET['vehicle_id'] ?? ($_POST['vehicle_id'] ?? 0));
}

// Available active vehicles operating in this zone
$vZoneStmt = $pdo->prepare("
    SELECT id, vehicle_number, vehicle_name, driver_name, driver_phone 
    FROM vehicles 
    WHERE status != 'inactive' 
      AND (id IN (SELECT vehicle_id FROM vehicle_loads WHERE zone_id = ? AND is_active = 1) OR zone_id = ?) 
    ORDER BY (id IN (SELECT vehicle_id FROM vehicle_loads WHERE zone_id = ? AND is_active = 1)) DESC, id DESC
");
$vZoneStmt->execute([$zone_id, $zone_id, $zone_id]);
$availVehicles = $vZoneStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Only auto-pick first vehicle if this is a new spot delivery without prior order
if ($assignedVehicleId <= 0 && $order_id <= 0 && !empty($availVehicles)) {
    $assignedVehicleId = (int)$availVehicles[0]['id'];
}
$assignedVehicle = null;
if ($assignedVehicleId > 0) {
    $vStmt = $pdo->prepare("SELECT id, vehicle_number, vehicle_name, driver_name, driver_phone FROM vehicles WHERE id = ?");
    $vStmt->execute([$assignedVehicleId]);
    $assignedVehicle = $vStmt->fetch(PDO::FETCH_ASSOC);
}

// Build list of products currently loaded in this vehicle
$vehicle_products = [];

// 1. Extra cases attached to this order in order_items (is_extra = 1) if editing an existing order
if ($order_id > 0) {
    foreach ($order_items as $oi) {
        if (!empty($oi['is_extra'])) {
            $pid = (int)$oi['product_id'];
            $qty = (int)$oi['quantity'];
            $pInfo = $productsById[$pid] ?? null;
            $pName = $pInfo ? ($pInfo['product_name'] ?: $pInfo['name']) : ($oi['product_name'] ?: ($oi['name'] ?: 'Product #' . $pid));
            $pPrice = $pInfo ? (float)($pInfo['case_price'] ?: $pInfo['price']) : (float)($oi['price_at_purchase'] ?: ($oi['case_price'] ?: $oi['price'] ?? 0));
            if ($pPrice <= 0 && $pInfo) {
                $pPrice = (float)($pInfo['case_price'] ?: $pInfo['price']);
            }
            if (!isset($vehicle_products[$pid])) {
                $vehicle_products[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $pName,
                    'available_cases' => 0,
                    'price' => $pPrice,
                    'net_content' => $pInfo['net_content'] ?? ($oi['net_content'] ?? ''),
                    'net_content_unit' => $pInfo['net_content_unit'] ?? ($oi['net_content_unit'] ?? '')
                ];
            }
            $vehicle_products[$pid]['available_cases'] += $qty;
        }
    }
}

// 2. Active storage cases currently in the assigned vehicle from vehicle_loads
// Note: Only include unreserved vehicle storage (order_id IS NULL or order_id = 0), NOT cases assigned to other customer orders
if ($assignedVehicleId > 0) {
    $vlStmt = $pdo->prepare("
        SELECT vl.product_id, SUM(vl.cases_loaded) as cases_loaded, p.product_name, p.name, p.case_price, p.price, p.net_content, p.net_content_unit
        FROM vehicle_loads vl
        LEFT JOIN products p ON vl.product_id = p.product_id
        WHERE vl.vehicle_id = ? 
          AND vl.is_active = 1 
          AND vl.product_id IS NOT NULL 
          AND (vl.order_id IS NULL OR vl.order_id = 0)
        GROUP BY vl.product_id
        HAVING SUM(vl.cases_loaded) > 0
    ");
    $vlStmt->execute([$assignedVehicleId]);
    $vlRows = $vlStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($vlRows as $vr) {
        $pid = (int)$vr['product_id'];
        $qty = (int)$vr['cases_loaded'];
        if ($qty > 0) {
            $pName = $vr['product_name'] ?: ($vr['name'] ?: 'Product #' . $pid);
            $pPrice = (float)($vr['case_price'] ?: $vr['price']);
            if (!isset($vehicle_products[$pid])) {
                $vehicle_products[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $pName,
                    'available_cases' => 0,
                    'price' => $pPrice,
                    'net_content' => $vr['net_content'],
                    'net_content_unit' => $vr['net_content_unit']
                ];
            }
            $vehicle_products[$pid]['available_cases'] += $qty;
        }
    }
}

// Keep $vehicle_extra_products alias for backwards compatibility
$vehicle_extra_products = $vehicle_products;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_receipt'])) {
    $shop_name       = trim($_POST['shop_name'] ?? '');
    $customer_name   = trim($_POST['customer_name'] ?? '');
    $place           = trim($_POST['place'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');

    $enable_cash     = isset($_POST['enable_cash']);
    $cash_amount     = $enable_cash ? (float)($_POST['cash_amount'] ?? 0) : 0.00;

    $enable_credit   = isset($_POST['enable_credit']);
    $credited_amount = $enable_credit ? (float)($_POST['credited_amount'] ?? 0) : 0.00;

    // Process submitted delivered items: $_POST['items']
    $processed_items = [];
    $total_delivered_cases = 0;
    $base_subtotal = 0.0;

    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $itemData) {
            $pid = (int)($itemData['product_id'] ?? 0);
            $qty = (int)($itemData['quantity'] ?? 0);
            $is_extra = !empty($itemData['is_extra']) ? 1 : 0;
            $submittedRate = isset($itemData['rate']) ? (float)$itemData['rate'] : -1;
            $submittedOrigRate = isset($itemData['original_rate']) ? (float)$itemData['original_rate'] : 0.0;

            if ($pid > 0 && $qty > 0) {
                $pStmt = $pdo->prepare("SELECT product_id, name, product_name, case_price, price, net_content, net_content_unit FROM products WHERE product_id = ?");
                $pStmt->execute([$pid]);
                $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

                if ($pRow) {
                    $catalogPrice = (float)($pRow['case_price'] ?: $pRow['price']);
                    $origRate = $submittedOrigRate > 0 ? $submittedOrigRate : $catalogPrice;

                    // Extra cases are billed products (NOT free)
                    if ($submittedRate < 0) {
                        $rate = $catalogPrice;
                    } else {
                        $rate = $submittedRate;
                    }
                    $lineTotal = $qty * $rate;

                    $processed_items[] = [
                        'product_id' => $pid,
                        'quantity' => $qty,
                        'rate' => $rate,
                        'original_rate' => $origRate,
                        'is_extra' => $is_extra,
                        'line_total' => $lineTotal,
                        'product_name' => $pRow['product_name'] ?: $pRow['name'],
                        'net_content' => $pRow['net_content'],
                        'net_content_unit' => $pRow['net_content_unit']
                    ];
                    $total_delivered_cases += $qty;
                    $base_subtotal += $lineTotal;
                }
            }
        }
    }

    // Fallback for single product dropdown if items array was omitted
    if (empty($processed_items) && isset($_POST['product_id'])) {
        $single_pid = (int)$_POST['product_id'];
        $single_qty = max(1, (int)($_POST['cases_delivered'] ?? 1));
        if ($single_pid > 0) {
            $pStmt = $pdo->prepare("SELECT product_id, name, product_name, case_price, price, net_content, net_content_unit FROM products WHERE product_id = ?");
            $pStmt->execute([$single_pid]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $rate = (float)($pRow['case_price'] ?: $pRow['price']);
                $lineTotal = $single_qty * $rate;
                $processed_items[] = [
                    'product_id' => $single_pid,
                    'quantity' => $single_qty,
                    'rate' => $rate,
                    'line_total' => $lineTotal,
                    'product_name' => $pRow['product_name'] ?: $pRow['name'],
                    'net_content' => $pRow['net_content'],
                    'net_content_unit' => $pRow['net_content_unit']
                ];
                $total_delivered_cases = $single_qty;
                $base_subtotal = $lineTotal;
            }
        }
    }

    // Validation
    if (empty($shop_name)) {
        $error = "Shop / Customer Name is required.";
    } elseif (empty($processed_items) || $total_delivered_cases <= 0) {
        $error = "Please specify at least one delivered item with quantity of 1 or more.";
    } elseif ($cash_amount < 0 || $credited_amount < 0) {
        $error = "Payment amounts cannot be negative.";
    } else {
        $discount = $order ? (float)$order['discount'] : (float)($_POST['discount'] ?? ($reorder_source['discount'] ?? 0.00));
        if ($discount > $base_subtotal) {
            $discount = $base_subtotal;
        }
        $total_amount = max(0, $base_subtotal - $discount);

        // Validation: Cash + Credit cannot exceed Total
        if (($cash_amount + $credited_amount) > ($total_amount + 0.01)) {
            $error = "Payment error: Cash (" . formatCurrency($cash_amount) . ") + Credited (" . formatCurrency($credited_amount) . ") exceeds Total Amount (" . formatCurrency($total_amount) . ").";
        } else {
            $due_amount = max(0, $total_amount - $cash_amount - $credited_amount);

            // Determine payment type
            if ($cash_amount > 0 && $credited_amount > 0) {
                $payment_type = 'Cash + Credited';
            } elseif ($cash_amount > 0) {
                $payment_type = 'Cash';
            } elseif ($credited_amount > 0) {
                $payment_type = 'Credited';
            } else {
                $payment_type = 'Pending';
            }

            try {
                $pdo->beginTransaction();

                $primary_pid = $processed_items[0]['product_id'];
                $primary_rate = $processed_items[0]['rate'];
                foreach ($processed_items as $pi) {
                    if (empty($pi['is_extra']) && $pi['rate'] > 0) {
                        $primary_pid = $pi['product_id'];
                        $primary_rate = $pi['rate'];
                        break;
                    }
                }

                if ($order) {
                    // 1. Stock adjustments based on diff between previously booked items and delivered items (including vehicle extras)
                    $oldItems = getOrderItems($pdo, $order_id);
                    $oldQtyByPid = [];
                    $loadedExtraByPid = [];
                    foreach ($oldItems as $oi) {
                        $p_id = (int)$oi['product_id'];
                        if ($p_id > 0) {
                            $oldQtyByPid[$p_id] = ($oldQtyByPid[$p_id] ?? 0) + (int)$oi['quantity'];
                        }
                        if (!empty($oi['is_extra'])) {
                            $loadedExtraByPid[$p_id] = ($loadedExtraByPid[$p_id] ?? 0) + (int)$oi['quantity'];
                        }
                    }

                    $newQtyByPid = [];
                    $deliveredExtraByPid = [];
                    foreach ($processed_items as $pi) {
                        $p_id = (int)$pi['product_id'];
                        if ($p_id > 0) {
                            $newQtyByPid[$p_id] = ($newQtyByPid[$p_id] ?? 0) + (int)$pi['quantity'];
                        }
                        if (!empty($pi['is_extra'])) {
                            $deliveredExtraByPid[$p_id] = ($deliveredExtraByPid[$p_id] ?? 0) + (int)$pi['quantity'];
                        }
                    }

                    $allProductIds = array_unique(array_merge(array_keys($oldQtyByPid), array_keys($newQtyByPid)));
                    foreach ($allProductIds as $apid) {
                        $oldQ = $oldQtyByPid[$apid] ?? 0;
                        $newQ = $newQtyByPid[$apid] ?? 0;
                        $diff = $newQ - $oldQ;
                        if ($diff !== 0) {
                            adjustProductStock($pdo, $apid, -$diff, "Order #{$order_id} delivered (including vehicle extras)");
                        }
                    }

                    // Determine assigned vehicle ID
                    $assignedVehicleId = (int)($order['vehicle_id'] ?? 0);
                    if ($assignedVehicleId <= 0) {
                        $vChk = $pdo->prepare("SELECT vehicle_id FROM vehicle_loads WHERE order_id = ? AND is_active = 1 LIMIT 1");
                        $vChk->execute([$order_id]);
                        $assignedVehicleId = (int)$vChk->fetchColumn();
                    }
                    if ($assignedVehicleId <= 0) {
                        $assignedVehicleId = (int)($_POST['vehicle_id'] ?? 0);
                    }
                    if ($assignedVehicleId <= 0) {
                        throw new Exception("Please assign a delivery vehicle before generating the delivery receipt.");
                    } else {
                        assignOrderToVehicle($pdo, $order_id, $assignedVehicleId);
                    }

                    // 2. Update orders record
                    $upOrd = $pdo->prepare("
                        UPDATE orders SET 
                            shop_name = ?, customer_name = ?, location = ?, 
                            phone = ?, product_id = ?, quantity = ?, 
                            unit_price = ?, total_amount = ?, status = 'delivered', 
                            vehicle_id = COALESCE(NULLIF(?, 0), vehicle_id),
                            is_zone_read = 1, zone_read_at = COALESCE(zone_read_at, NOW()), updated_at = NOW() 
                        WHERE order_id = ? AND zone_id = ?
                    ");
                    $upOrd->execute([
                        $shop_name,
                        $customer_name ?: $shop_name,
                        $place,
                        $phone,
                        $primary_pid,
                        $total_delivered_cases,
                        $primary_rate,
                        $total_amount,
                        $assignedVehicleId,
                        $order_id,
                        $zone_id
                    ]);

                    // 3. Sync order_items table with delivered items and rates
                    $delOi = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
                    $delOi->execute([$order_id]);

                    $insOi = $pdo->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    foreach ($processed_items as $pi) {
                        $insOi->execute([$order_id, $pi['product_id'], $pi['quantity'], $pi['rate'], $pi['is_extra']]);
                    }
                    $order_number = $order['order_number'] ?: ('#' . $order_id);

                } else {
                    // New direct spot delivery without prior order OR Reorder
                    $assignedVehicleId = (int)($_POST['vehicle_id'] ?? 0);
                    if ($assignedVehicleId <= 0 && !empty($availVehicles)) {
                        $assignedVehicleId = (int)$availVehicles[0]['id'];
                    }

                    $maxOrdStmt = $pdo->query("SELECT MAX(order_id) FROM orders");
                    $nextOrdId = (int)$maxOrdStmt->fetchColumn() + 1;
                    $order_number = '' . str_pad($nextOrdId, 3, '0', STR_PAD_LEFT);

                    $is_reorder_post = ($reorder_id > 0 || !empty($_POST['is_reorder'])) ? 1 : 0;
                    $parent_order_id_post = ($reorder_id > 0) ? $reorder_id : (!empty($_POST['parent_order_id']) ? (int)$_POST['parent_order_id'] : null);

                    $insOrd = $pdo->prepare("
                        INSERT INTO orders (
                            order_number, zone_id, vehicle_id, shop_name, customer_name, location, 
                            phone, product_id, quantity, unit_price, discount, 
                            total_amount, status, is_zone_read, is_reorder, parent_order_id, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, 
                            ?, 'delivered', 1, ?, ?, NOW(), NOW()
                        )
                    ");
                    $insOrd->execute([
                        $order_number,
                        $zone_id,
                        $assignedVehicleId > 0 ? $assignedVehicleId : null,
                        $shop_name,
                        $customer_name ?: $shop_name,
                        $place,
                        $phone,
                        $primary_pid,
                        $total_delivered_cases,
                        $primary_rate,
                        $discount,
                        $total_amount,
                        $is_reorder_post,
                        $parent_order_id_post
                    ]);
                    $order_id = (int)$pdo->lastInsertId();

                    // Insert into order_items & deduct warehouse stock
                    $insOi = $pdo->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $deliveredExtraByPid = [];
                    foreach ($processed_items as $pi) {
                        adjustProductStock($pdo, $pi['product_id'], -$pi['quantity'], ($is_reorder_post ? "Reorder #{$order_number}" : "Direct delivery #{$order_number}"));
                        $insOi->execute([$order_id, $pi['product_id'], $pi['quantity'], $pi['rate'], $pi['is_extra']]);
                        $p_id = (int)$pi['product_id'];
                        // In direct spot delivery, delivered products from vehicle storage are tracked for vehicle deduction
                        $deliveredExtraByPid[$p_id] = ($deliveredExtraByPid[$p_id] ?? 0) + (int)$pi['quantity'];
                    }
                }

                // 4. Retain any unbilled / remaining extra loaded cases on vehicle as active storage
                if ($assignedVehicleId > 0) {
                    // Deactivate previous order load entries so delivered regular cases leave the vehicle
                    $pdo->prepare("UPDATE vehicle_loads SET is_active = 0 WHERE order_id = ?")->execute([$order_id]);

                    // Reconcile pre-existing vehicle storage for products that were available in vehicle
                    $prevVl = $pdo->prepare("
                        SELECT product_id, SUM(cases_loaded) as qty 
                        FROM vehicle_loads 
                        WHERE vehicle_id = ? 
                          AND is_active = 1 
                          AND product_id IS NOT NULL 
                          AND (order_id IS NULL OR order_id != ?) 
                        GROUP BY product_id
                    ");
                    $prevVl->execute([$assignedVehicleId, $order_id]);
                    $prevVlRows = $prevVl->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $loadedStorageByPid = [];
                    foreach ($prevVlRows as $pvr) {
                        $pid = (int)$pvr['product_id'];
                        $loadedStorageByPid[$pid] = (int)$pvr['qty'];
                    }

                    if ($order && !empty($loadedExtraByPid)) {
                        foreach ($loadedExtraByPid as $pid => $qty) {
                            $loadedStorageByPid[$pid] = ($loadedStorageByPid[$pid] ?? 0) + $qty;
                        }
                    }

                    $productsToReconcile = array_unique(array_merge(array_keys($loadedStorageByPid), array_keys($deliveredExtraByPid)));

                    foreach ($productsToReconcile as $epid) {
                        $loadedCases = $loadedStorageByPid[$epid] ?? 0;
                        $deliveredCases = (int)($deliveredExtraByPid[$epid] ?? 0);
                        if ($loadedCases <= 0 && $deliveredCases <= 0) continue;

                        $remainingCases = max(0, $loadedCases - $deliveredCases);

                        // Deactivate current active storage loads for this product on this vehicle
                        $deactStmt = $pdo->prepare("
                            UPDATE vehicle_loads 
                            SET is_active = 0 
                            WHERE vehicle_id = ? 
                              AND is_active = 1 
                              AND product_id = ? 
                              AND (order_id IS NULL OR order_id != ?)
                        ");
                        $deactStmt->execute([$assignedVehicleId, $epid, $order_id]);

                        // Retain remaining cases in vehicle storage
                        if ($remainingCases > 0) {
                            $insStorage = $pdo->prepare("
                                INSERT INTO vehicle_loads (vehicle_id, zone_id, product_id, order_id, cases_loaded, shop_name, is_active, notes)
                                VALUES (?, ?, ?, NULL, ?, ?, 1, ?)
                            ");
                            $insStorage->execute([
                                $assignedVehicleId,
                                $zone_id,
                                $epid,
                                $remainingCases,
                                $shop_name,
                                "Vehicle Storage: Remaining extra cases after delivery to " . $shop_name . " (Order #" . $order_number . ")"
                            ]);
                        }
                    }

                    // Update vehicle delivery stats
                    $pdo->prepare("UPDATE vehicles SET total_deliveries_done = total_deliveries_done + 1, updated_at = NOW() WHERE id = ?")->execute([$assignedVehicleId]);
                }

                // Generate Bill Number
                $bill_number = generateBillNumber($pdo);

                // Handle clearance of prior pending dues if selected
                $collect_prior_due = isset($_POST['collect_prior_due']);
                $prior_due_amount  = $collect_prior_due ? (float)($_POST['prior_due_amount'] ?? 0) : 0.00;
                $prior_due_mode    = trim($_POST['prior_due_mode'] ?? 'cash');

                $settleResult = null;
                if ($collect_prior_due && $prior_due_amount > 0) {
                    $settleResult = settleShopPriorDuesFifo(
                        $pdo,
                        $shop_name,
                        $phone,
                        $prior_due_amount,
                        $prior_due_mode,
                        "Cleared during delivery of Bill #{$bill_number} (Order #{$order_number})",
                        $order_id,
                        $zone_id
                    );
                }

                $receipt_notes = trim($_POST['receipt_notes'] ?? '');
                if (!empty($settleResult['total_cleared']) && $settleResult['total_cleared'] > 0) {
                    $clearedSummary = "Cleared ₹" . number_format($settleResult['total_cleared'], 2) . " earlier due (" . ucfirst($prior_due_mode) . ") from " . implode(', ', array_map(fn($c) => 'Bill #' . $c['bill_number'], $settleResult['cleared_receipts']));
                    $receipt_notes = !empty($receipt_notes) ? ($receipt_notes . " | " . $clearedSummary) : $clearedSummary;
                }

                // Insert into receipts table
                $insReceipt = $pdo->prepare("
                    INSERT INTO receipts (
                        order_id, bill_number, delivered_quantity, total_amount, 
                        cash_amount, credited_amount, due_amount, payment_type, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insReceipt->execute([
                    $order_id,
                    $bill_number,
                    $total_delivered_cases,
                    $total_amount,
                    $cash_amount,
                    $credited_amount,
                    $due_amount,
                    $payment_type,
                    $receipt_notes ?: null
                ]);

                // Insert into order_payments table
                $insPay = $pdo->prepare("
                    INSERT INTO order_payments (
                        order_id, cash_amount, credited_amount, due_amount, payment_type, created_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $insPay->execute([
                    $order_id,
                    $cash_amount,
                    $credited_amount,
                    $due_amount,
                    strtolower(str_replace(' + ', '_', $payment_type))
                ]);

                // Update Shop Reward Progress
                updateShopRewardProgress($pdo, $shop_name, $phone);

                $pdo->commit();

                $redirectParams = ['id' => $order_id, 'new' => 1];
                if (!empty($settleResult['total_cleared']) && $settleResult['total_cleared'] > 0) {
                    $redirectParams['prior_cleared'] = $settleResult['total_cleared'];
                }

                header("Location: " . zone_url($zone_slug, 'receipt', $redirectParams));
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error generating receipt: " . $e->getMessage();
            }
        }
    }
}

// Prefill form values
$def_shop       = $order['shop_name'] ?? ($reorder_source['shop_name'] ?? '');
$def_customer   = $order['customer_name'] ?? ($reorder_source['customer_name'] ?? '');
$def_place      = $order['location'] ?? ($reorder_source['location'] ?? '');
$def_phone      = $order['phone'] ?? ($reorder_source['phone'] ?? '');
$def_discount   = ($reorder_id > 0) ? 0.00 : (float)($order['discount'] ?? 0);

// Load prior pending dues for this shop/phone
$priorDues = getShopPendingDueDetails($pdo, $def_shop ?: $def_customer, $def_phone, $order_id, $zone_id);

// Categorize items into regular billed items
$regular_items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $it) {
        $pId = (int)($it['product_id'] ?? 0);
        $pQty = (int)($it['quantity'] ?? 0);
        $isEx = !empty($it['is_extra']) ? 1 : 0;
        $prodInfo = $productsById[$pId] ?? null;
        $pName = $prodInfo ? ($prodInfo['product_name'] ?: $prodInfo['name']) : 'Product #' . $pId;
        $catPrice = $prodInfo ? (float)($prodInfo['case_price'] ?: $prodInfo['price']) : 0.0;
        $origRate = isset($it['original_rate']) && (float)$it['original_rate'] > 0 ? (float)$it['original_rate'] : $catPrice;
        $rate = (isset($it['rate']) && (float)$it['rate'] >= 0) ? (float)$it['rate'] : $catPrice;

        $rowItem = [
            'order_item_id' => 0,
            'order_id' => $order_id,
            'product_id' => $pId,
            'quantity' => $pQty,
            'price_at_purchase' => $rate,
            'original_rate' => $origRate,
            'is_extra' => $isEx,
            'max_cases' => (int)($it['max_cases'] ?? ($vehicle_products[$pId]['available_cases'] ?? 999)),
            'line_total' => $pQty * $rate,
            'name' => $pName,
            'product_name' => $pName,
            'net_content' => $prodInfo['net_content'] ?? null,
            'net_content_unit' => $prodInfo['net_content_unit'] ?? null,
        ];
        $regular_items[] = $rowItem;
    }
} else {
    // For reorders, earlier products should NOT be shown; user selects from vehicle dropdown
    if ($reorder_id > 0) {
        $regular_items = [];
    } else {
        foreach ($order_items as $it) {
            $pid = (int)$it['product_id'];
            $catPrice = isset($productsById[$pid]) ? (float)($productsById[$pid]['case_price'] ?: $productsById[$pid]['price']) : (float)($it['case_price'] ?: $it['price']);
            $isEx = !empty($it['is_extra']) ? 1 : 0;
            
            // On initial load, extra cases loaded in vehicle are available via the vehicle dropdown, NOT pre-added to the customer bill!
            if ($isEx) {
                continue;
            }

            $origRate = isset($it['original_rate']) && (float)$it['original_rate'] > 0 ? (float)$it['original_rate'] : $catPrice;
            if ($origRate <= 0 && (float)$it['price_at_purchase'] > 0) $origRate = (float)$it['price_at_purchase'];
            $rate = (float)($it['price_at_purchase'] > 0 ? $it['price_at_purchase'] : $origRate);
            
            $rowItem = $it;
            $rowItem['price_at_purchase'] = $rate;
            $rowItem['original_rate'] = $origRate;
            $rowItem['is_extra'] = 0;
            $rowItem['line_total'] = (int)$it['quantity'] * $rate;
            $regular_items[] = $rowItem;
        }
    }
}

// Collect IDs of products already ordered / present in the delivery bill
$ordered_product_ids = [];
foreach ($regular_items as $ri) {
    if (!empty($ri['product_id'])) {
        $ordered_product_ids[(int)$ri['product_id']] = true;
    }
}

// Filter dropdown products: ONLY loaded products in the vehicle that are NOT already in the ordered items
$dropdown_vehicle_products = [];
foreach ($vehicle_products as $pid => $vp) {
    if (!isset($ordered_product_ids[$pid])) {
        $dropdown_vehicle_products[$pid] = $vp;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Generate Delivery Receipt - <?= htmlspecialchars($zone_name) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #059669;
            --success-dark: #047857;
            --warning: #d97706;
            --danger: #dc2626;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
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
            padding-bottom: 90px;
        }

        .receipt-nav {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 12px 16px;
        }

        .nav-inner {
            max-width: 680px;
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
            max-width: 680px;
            margin: 16px auto;
            padding: 0 16px;
        }

        .card-main {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            box-shadow: var(--shadow-card);
        }

        .form-header {
            margin-bottom: 18px;
        }

        .form-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .section-hdr {
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 20px 0 12px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.15s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 540px) {
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* Delivered Item Card */
        .item-delivery-card {
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px;
            transition: border-color 0.2s;
        }
        .item-delivery-card:hover {
            border-color: #cbd5e1;
        }

        /* Quantity Stepper for Touch */
        .stepper-box {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-stepper {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            color: #1e293b;
            font-size: 18px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            user-select: none;
            flex-shrink: 0;
            transition: all 0.1s;
        }

        .btn-stepper:active {
            background: #e2e8f0;
            transform: scale(0.95);
        }

        .stepper-input {
            text-align: center;
            font-weight: 800;
            font-size: 16px;
            width: 65px;
            padding: 6px;
        }

        /* Fast Payment Selectors */
        .fast-pay-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }

        .btn-fast-pay {
            padding: 10px 6px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 800;
            border: 1.5px solid var(--border-color);
            background: #ffffff;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s ease;
        }

        .btn-fast-cash { color: #047857; }
        .btn-fast-cash:hover { background: #ecfdf5; border-color: #a7f3d0; }

        .btn-fast-credit { color: #b45309; }
        .btn-fast-credit:hover { background: #fffbeb; border-color: #fde68a; }

        .btn-fast-due { color: #dc2626; }
        .btn-fast-due:hover { background: #fef2f2; border-color: #fecaca; }

        /* Payment Toggle Cards */
        .payment-card {
            background: #f8fafc;
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }

        .payment-card.active {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .chk-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .chk-input {
            width: 20px;
            height: 20px;
            accent-color: #2563eb;
            cursor: pointer;
        }

        .amount-input-box {
            margin-top: 10px;
            display: none;
        }

        .amount-input-box.visible {
            display: block;
        }

        /* Live Calculation Box */
        .calc-panel {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            padding: 4px 0;
        }

        .calc-row.total {
            border-top: 2px solid var(--border-color);
            margin-top: 8px;
            padding-top: 10px;
            font-size: 16px;
            font-weight: 800;
        }

        .calc-row.due {
            border-top: 1px dashed var(--border-color);
            margin-top: 6px;
            padding-top: 10px;
            font-size: 17px;
            font-weight: 800;
            color: #dc2626;
        }

        .btn-submit-bill {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 15px;
            background: var(--success);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(5,150,105,0.25);
            transition: all 0.15s ease;
        }

        .btn-submit-bill:hover, .btn-submit-bill:active {
            background: var(--success-dark);
            transform: translateY(-1px);
        }

        /* Sticky Bottom on Mobile */
        @media (max-width: 640px) {
            .mobile-sticky-submit {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(10px);
                border-top: 1px solid var(--border-color);
                padding: 10px 16px;
                box-shadow: 0 -4px 12px rgba(0,0,0,0.06);
                z-index: 99;
            }
        }
    </style>
</head>
<body>

    <nav class="receipt-nav">
        <div class="nav-inner">
            <a href="<?= zone_url($zone_slug) ?>" class="back-link">
                <i class='bx bx-arrow-back'></i> Portal
            </a>
            <span style="font-size: 13px; font-weight: 700; color: #64748b;">
                <?= htmlspecialchars($zone_name) ?> Zone
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
            <div class="form-header">
                <h1 class="form-title">
                    <?php if ($reorder_id > 0 && !empty($reorder_source)): ?>
                        <i class='bx bx-refresh' style="color: #2563eb; "></i> <span style="font-size: 17px;">Place Reorder Delivery Receipt</span>
                    <?php else: ?>
                        <i class='bx bx-receipt' style="color: #059669;"></i>
                        <?= ($order_id > 0) ? 'Generate Delivery Receipt' : 'New Spot Delivery Receipt' ?>
                    <?php endif; ?>
                </h1>
               
            </div>

            <form action="<?= zone_url($zone_slug, 'generate-receipt') ?>" method="POST" id="receiptForm">
                <?php if ($reorder_id > 0): ?>
                    <input type="hidden" name="reorder_id" value="<?= $reorder_id ?>">
                    <input type="hidden" name="is_reorder" value="1">
                    <input type="hidden" name="parent_order_id" value="<?= $reorder_id ?>">
                <?php elseif ($order_id > 0): ?>
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <?php endif; ?>
                <input type="hidden" name="vehicle_id" id="assignedVehicleIdInput" value="<?= $assignedVehicleId ?>">

                <?php if ($reorder_id > 0 && !empty($reorder_source)): ?>
                    <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 12px; padding: 8px 10px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: #2563eb; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                                <i class='bx bx-refresh'></i>
                            </div>
                            <div>
                                <div style="font-weight: 800; font-size: 14px; color: #1e40af;">
                                    Reordering from Order #<?= htmlspecialchars($reorder_source['order_number'] ?: ('#' . $reorder_id)) ?>
                                </div>
                              
                            </div>
                        </div>
                    
                    </div>
                <?php endif; ?>

                <!-- Shop & Customer Details -->
                <div class="section-hdr">
                    <i class='bx bx-store-alt' style="color: #2563eb;"></i> Shop &amp; Delivery Details
                </div>

                <?php if ($order_id > 0 && empty($assignedVehicle)): ?>
                    <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px;">
                        <div style="font-weight: 800; font-size: 14px; color: #b91c1c; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                            <i class='bx bx-error-circle' style="font-size: 20px; color: #ef4444;"></i> Vehicle is not assigned to this order
                        </div>
                        <p style="font-size: 12px; color: #7f1d1d; margin: 0 0 10px 0;">
                            Please choose a delivery vehicle below to assign it before generating this delivery receipt.
                        </p>
                        <select name="vehicle_id" id="vehicleSelect" class="form-control" required style="border-color: #f87171; font-weight: 700;" onchange="document.getElementById('assignedVehicleIdInput').value = this.value; window.location.href = '<?= zone_url($zone_slug, 'generate-receipt', ['order_id' => $order_id]) ?>&vehicle_id=' + this.value;">
                            <option value="">-- Select Delivery Vehicle * --</option>
                            <?php foreach ($availVehicles as $av): ?>
                                <option value="<?= $av['id'] ?>" <?= ($assignedVehicleId === (int)$av['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($av['vehicle_number']) ?><?= !empty($av['vehicle_name']) ? ' - ' . htmlspecialchars($av['vehicle_name']) : '' ?><?= !empty($av['driver_name']) ? ' (' . htmlspecialchars($av['driver_name']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($order_id <= 0 && count($availVehicles) > 1): ?>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="vehicleSelect">
                            <i class='bx bxs-truck' style="color: #2563eb;"></i> Delivering Vehicle
                        </label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-control" onchange="window.location.href = '<?= zone_url($zone_slug, 'generate-receipt') ?>?' + <?= json_encode($reorder_id > 0 ? ('reorder_id=' . $reorder_id . '&') : '') ?> + 'vehicle_id=' + this.value;">
                            <?php foreach ($availVehicles as $av): ?>
                                <option value="<?= $av['id'] ?>" <?= ($assignedVehicleId === (int)$av['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($av['vehicle_number']) ?><?= !empty($av['vehicle_name']) ? ' - ' . htmlspecialchars($av['vehicle_name']) : '' ?><?= !empty($av['driver_name']) ? ' (' . htmlspecialchars($av['driver_name']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif (!empty($assignedVehicle)): ?>
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <div style="font-size: 13px; font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 6px;">
                            <i class='bx bxs-truck' style="font-size: 18px; color: #2563eb;"></i>
                            <span>Delivering Vehicle: <strong><?= htmlspecialchars($assignedVehicle['vehicle_number']) ?></strong><?= !empty($assignedVehicle['vehicle_name']) ? ' (' . htmlspecialchars($assignedVehicle['vehicle_name']) . ')' : '' ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label" for="shop_name">Shop / Customer Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="shop_name" id="shop_name" class="form-control" required value="<?= htmlspecialchars($_POST['shop_name'] ?? $def_shop) ?>" placeholder="e.g. Metro Supermarket">
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="place">Delivery Location / Place <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="place" id="place" class="form-control" required value="<?= htmlspecialchars($_POST['place'] ?? $def_place) ?>" placeholder="e.g. Central Market">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="phone">Customer Phone Number <span style="color: #ef4444;">*</span></label>
                        <input type="tel" name="phone" id="phone" class="form-control" required value="<?= htmlspecialchars($_POST['phone'] ?? $def_phone) ?>" placeholder="e.g. 9876543210">
                    </div>
                </div>

                <!-- <div class="form-group">
                    <label class="form-label" for="customer_name">Contact Person (Optional)</label>
                    <input type="text" name="customer_name" id="customer_name" class="form-control" value="<?= htmlspecialchars($_POST['customer_name'] ?? $def_customer) ?>" placeholder="e.g. Ramesh">
                </div> -->

                <!-- Product & Quantity -->
                <div class="section-hdr">
                    <i class='bx bx-box' style="color: #059669;"></i> Delivered Items &amp; Quantity (<span id="regularCountDisplay"><?= count($regular_items) ?> <?= count($regular_items) === 1 ? 'Product' : 'Products' ?></span>)
                </div>

                <!-- Add Products from Vehicle Section -->
                <div class="vehicle-product-selector-box" style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px;">
                    <div style="font-size: 13px; font-weight: 800; color: #1e40af; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px;">
                        <span style="display: flex; align-items: center; font-size: 11px; gap: 6px;">
                            <i class='bx bxs-truck' style="font-size: 18px; color: #2563eb;"></i>
                            <?= !empty($vehicle_products) ? 'Add Products from Vehicle' : 'Add Products to Delivery' ?>
                            <?= !empty($assignedVehicle['vehicle_number']) ? ' (' . htmlspecialchars($assignedVehicle['vehicle_number']) . (!empty($assignedVehicle['vehicle_name']) ? ' - ' . htmlspecialchars($assignedVehicle['vehicle_name']) : '') . ')' : '' ?>
                        </span>
                        <!-- <?php if (!empty($vehicle_products)): ?>
                            <span style="font-size: 11px; font-weight: 700; color: #047857; background: #d1fae5; padding: 2px 8px; border-radius: 6px;">
                                <?= count($vehicle_products) ?> Products Loaded in Vehicle
                            </span>
                        <?php endif; ?> -->
                    </div>

                    <?php if (!empty($dropdown_vehicle_products)): ?>
                    <!-- Quick-add pill buttons for fast one-tap selection (only loaded products, excluding ordered items) -->
                    <div id="quickAddPillsBox" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
                        <?php foreach ($dropdown_vehicle_products as $vp): ?>
                            <button type="button" class="btn-quick-prod" data-product-id="<?= $vp['product_id'] ?>" onclick="quickAddProduct(<?= $vp['product_id'] ?>)" 
                                    style="background: #ffffff; border: 1.5px solid #93c5fd; color: #1e40af; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); transition: all 0.15s ease;"
                                    onmouseover="this.style.background='#eff6ff'; this.style.borderColor='#2563eb';"
                                    onmouseout="this.style.background='#ffffff'; this.style.borderColor='#93c5fd';"
                                    title="Click to add <?= htmlspecialchars($vp['product_name']) ?> to delivery">
                                <i class='bx bx-plus-circle' style="color: #2563eb; font-size: 15px;"></i>
                                <span><?= htmlspecialchars($vp['product_name']) ?></span>
                                <span style="background: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 800;"><?= (int)$vp['available_cases'] ?> cs</span>
                                <span style="color: #059669; font-weight: 800;">₹<?= number_format($vp['price'], 2) ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Dropdown + Quantity Input + Add Product Button -->
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <select id="vehicleProductSelect" class="form-control" style="flex: 1; min-width: 220px;">
                            <?php if (!empty($dropdown_vehicle_products)): ?>
                                <option value="">-- Select Product from Vehicle --</option>
                                <?php foreach ($dropdown_vehicle_products as $vp): ?>
                                    <option value="<?= $vp['product_id'] ?>" 
                                            data-name="<?= htmlspecialchars($vp['product_name']) ?>" 
                                            data-price="<?= (float)$vp['price'] ?>" 
                                            data-max="<?= (int)$vp['available_cases'] ?>"
                                            data-content="<?= htmlspecialchars(($vp['net_content'] ? (float)$vp['net_content'] . ' ' . $vp['net_content_unit'] : '')) ?>"
                                            data-in-vehicle="1">
                                        <?= htmlspecialchars($vp['product_name']) ?> (<?= (int)$vp['available_cases'] ?> cs in vehicle) &bull; <?= formatCurrency($vp['price']) ?>/case
                                    </option>
                                <?php endforeach; ?>
                            <?php elseif (empty($vehicle_products)): ?>
                                <option value="" disabled selected>-- No products loaded in vehicle --</option>
                            <?php else: ?>
                                <option value="" disabled selected>-- All loaded vehicle products already in bill --</option>
                            <?php endif; ?>
                        </select>

                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" id="addQtyInput" min="1" value="1" class="form-control" style="width: 70px; text-align: center; font-weight: 700; padding: 10px 4px;" placeholder="Qty" title="Number of cases to add">
                            <button type="button" id="btnAddProductFromVehicle" onclick="addProductFromDropdown()" <?= empty($dropdown_vehicle_products) ? 'disabled' : '' ?> style="background: #2563eb; color: #ffffff; border: none; padding: 11px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; box-shadow: 0 2px 4px rgba(37,99,235,0.25);">
                                <i class='bx bx-plus-circle' style="font-size: 17px;"></i> + Add Product
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Interactive Item Delivery Cards -->
                <div id="itemsContainer" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px;">
                    <div id="noItemsPlaceholder" style="<?= !empty($regular_items) ? 'display: none;' : '' ?> text-align: center; padding: 22px 16px; background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 12px; color: #64748b;">
                        <i class='bx bx-package' style="font-size: 32px; color: #94a3b8; display: block; margin-bottom: 6px;"></i>
                        <div style="font-weight: 700; font-size: 14px; color: #334155;">No products added to delivery yet</div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 3px;">
                            Select products from the vehicle dropdown above to add items to this delivery.
                        </div>
                    </div>
                    <?php foreach ($regular_items as $idx => $it): 
                        $pid = (int)$it['product_id'];
                        $pName = htmlspecialchars($it['product_name'] ?: ($it['name'] ?: 'Liyas Water'));
                        $qty = (int)$it['quantity'];
                        $isExtra = !empty($it['is_extra']);
                        $rate = (float)$it['price_at_purchase'];
                        if ($rate <= 0 && isset($productsById[$pid])) {
                            $rate = (float)($productsById[$pid]['case_price'] ?: $productsById[$pid]['price']);
                        }
                        $lineTotal = $qty * $rate;
                        $netContentStr = !empty($it['net_content']) ? (float)$it['net_content'] . ' ' . htmlspecialchars($it['net_content_unit'] ?? '') : '';
                        $maxCases = (int)($it['max_cases'] ?? ($vehicle_products[$pid]['available_cases'] ?? 999));
                    ?>
                    <div class="item-delivery-card item-regular-card <?= $isExtra ? 'item-vehicle-extra-card' : '' ?>" id="itemRow_<?= $idx ?>" data-idx="<?= $idx ?>" style="<?= $isExtra ? 'border-left: 4px solid #2563eb;' : '' ?>">
                        <input type="hidden" name="items[<?= $idx ?>][product_id]" value="<?= $pid ?>" class="item-pid">
                        <input type="hidden" name="items[<?= $idx ?>][original_rate]" value="<?= $rate ?>" class="item-original-rate">
                        <input type="hidden" name="items[<?= $idx ?>][is_extra]" value="<?= $isExtra ? '1' : '0' ?>" class="item-is-extra">
                        <?php if ($isExtra): ?>
                            <input type="hidden" name="items[<?= $idx ?>][max_cases]" value="<?= $maxCases ?>" class="item-max-cases">
                        <?php endif; ?>
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                    <strong style="font-size: 15px; color: #0f172a;"><?= $pName ?></strong>
                                    <?php if ($isExtra): ?>
                                        <span style="font-size: 11px; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 7px; border-radius: 4px;">VEHICLE STOCK</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isExtra): ?>
                                    <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                        Loaded in vehicle: <strong><?= $maxCases ?> cases</strong>
                                    </div>
                                <?php endif; ?>
                                <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; margin-top: 4px;">
                                    <span>Rate: ₹</span>
                                    <span style="font-weight: 700; color: #0f172a;">
                                        <?= htmlspecialchars(number_format((float)$rate, 2), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <input type="hidden" name="items[<?= $idx ?>][rate]" value="<?= htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8') ?>" class="item-rate">
                                    <span>/ case</span>
                                </div>
                                <div class="item-calc-text" style="font-size: 12px; color: #64748b; font-weight: 600; margin-top: 3px;">
                                    Rate × Qty: ₹<span class="calc-rate-val"><?= number_format($rate, 2) ?></span> × <span class="calc-qty-val"><?= $qty ?></span> cs = ₹<span class="calc-sub-val"><?= number_format($lineTotal, 2) ?></span>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div class="item-line-total" style="font-size: 16px; font-weight: 800; color: #0f172a;"><?= formatCurrency($lineTotal) ?></div>
                                <button type="button" onclick="removeItemRow(this)" style="background: none; border: none; color: #dc2626; font-size: 12px; cursor: pointer; padding: 3px 0; display: inline-flex; align-items: center; gap: 2px;" title="Remove this product from delivery">
                                    <i class='bx bx-trash'></i> Remove
                                </button>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8fafc; padding: 8px 12px; border-radius: 8px;">
                            <span style="font-size: 11px; font-weight: 600; color: #475569;">Delivered Cases:</span>
                            <div class="stepper-box">
                                <button type="button" class="btn-stepper" onclick="stepItemQty(this, -1)" aria-label="Decrease">&minus;</button>
                                <input type="text" min="0" <?= $isExtra ? 'max="' . $maxCases . '"' : '' ?> name="items[<?= $idx ?>][quantity]" value="<?= $qty ?>" class="form-control stepper-input item-qty-input" required oninput="checkMaxCases(this); recalculate()">
                                <button type="button" class="btn-stepper" onclick="stepItemQty(this, 1)" aria-label="Increase">&plus;</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Earlier Due Balance Section -->
                <div id="priorDueSection" data-total-due="<?= (float)($priorDues['total_due'] ?? 0) ?>" style="<?= !empty($priorDues['has_due']) ? '' : 'display: none;' ?> background: #fffaf0; border: 2px solid #fecaca; border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class='bx bx-error-circle' style="color: #dc2626; font-size: 22px;"></i>
                            <span style="font-size: 15px; font-weight: 800; color: #b91c1c;">
                                Earlier Due Pending: <span id="dispPriorDueTotal"><?= formatCurrency($priorDues['total_due'] ?? 0) ?></span>
                            </span>
                        </div>
                        <span id="dispPriorDueCount" style="background: #fee2e2; color: #dc2626; font-size: 11px; font-weight: 800; padding: 3px 8px; border-radius: 6px;">
                            <?= ($priorDues['due_count'] ?? 0) ?> <?= (($priorDues['due_count'] ?? 0) === 1) ? 'Unpaid Bill' : 'Unpaid Bills' ?>
                        </span>
                    </div>

                    <!-- Bills Breakdown Table -->
                    <div id="priorDueBillsTableBox" style="background: #ffffff; border: 1px solid #fed7d7; border-radius: 8px; overflow-x: auto; margin-bottom: 12px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="background: #fef2f2; border-bottom: 1px solid #fee2e2; text-align: left; color: #991b1b;">
                                    <th style="padding: 6px 10px;">Bill #</th>
                                    <th style="padding: 6px 10px;">Date</th>
                                    <th style="padding: 6px 10px; text-align: right;">Bill Total</th>
                                    <th style="padding: 6px 10px; text-align: right;">Outstanding Due</th>
                                </tr>
                            </thead>
                            <tbody id="priorDueTableBody">
                                <?php if (!empty($priorDues['receipts'])): ?>
                                    <?php foreach ($priorDues['receipts'] as $pr): ?>
                                    <tr style="border-bottom: 1px solid #f8fafc;">
                                        <td style="padding: 6px 10px; font-weight: 700; color: #0f172a;">
                                            Bill #<?= htmlspecialchars($pr['bill_number']) ?>
                                            <span style="font-size: 10px; color: #64748b; font-weight: normal;">(Order #<?= htmlspecialchars($pr['order_number']) ?>)</span>
                                        </td>
                                        <td style="padding: 6px 10px; color: #64748b;">
                                            <?= date('d M Y', strtotime($pr['receipt_date'])) ?>
                                        </td>
                                        <td style="padding: 6px 10px; text-align: right; color: #475569;">
                                            <?= formatCurrency($pr['total_amount']) ?>
                                        </td>
                                        <td style="padding: 6px 10px; text-align: right; font-weight: 800; color: #dc2626;">
                                            <?= formatCurrency($pr['due_amount']) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Interactive Settlement Options -->
                    <div style="background: #ffffff; border: 1.5px solid #fed7d7; border-radius: 8px; padding: 12px;">
                        <label class="chk-label" for="collect_prior_due" style="color: #0f172a;">
                            <input type="checkbox" name="collect_prior_due" id="collect_prior_due" class="chk-input" <?= isset($_POST['collect_prior_due']) ? 'checked' : '' ?> onchange="togglePriorDueBox(this.checked)">
                            <span>Collect / Clear Earlier Due with this Delivery</span>
                        </label>

                        <div id="priorDueInputFields" style="<?= isset($_POST['collect_prior_due']) ? 'display: block;' : 'display: none;' ?> margin-top: 10px; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
                            <div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
                                <div>
                                    <label class="form-label" style="font-size: 12px; margin-bottom: 4px;">Amount of Earlier Due to Clear (₹):</label>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <input
    type="text"
    step="0.01"
    min="0"
    max="<?= (float)($priorDues['total_due'] ?? 0) ?>"
    name="prior_due_amount"
    id="prior_due_amount"
    class="form-control"
    style="font-weight: 700; font-size: 15px;"
    value="<?= htmlspecialchars($_POST['prior_due_amount'] ?? ($priorDues['total_due'] ?? 0)) ?>"
    oninput="recalculate()"
>
                                        <button type="button" onclick="setPriorDueFull()" style="background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap;">
                                            Full Due
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label class="form-label" style="font-size: 12px; margin-bottom: 4px;">Payment Mode for Earlier Due:</label>
                                    <div style="display: flex; gap: 16px; font-size: 13px; font-weight: 700;">
                                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                            <input type="radio" name="prior_due_mode" value="cash" checked style="accent-color: #2563eb;">
                                            💵 In-Hand Cash
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                            <input type="radio" name="prior_due_mode" value="upi" style="accent-color: #2563eb;">
                                            📱 UPI / Online Payment
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Settlement -->
                <div class="section-hdr">
                    <i class='bx bx-wallet' style="color: #d97706;"></i> Payment Collection
                </div>

                <!-- 1-Tap Fast Settlement Shortcuts -->
                <div class="fast-pay-buttons">
                    <button type="button" class="btn-fast-pay btn-fast-cash" id="btnFastCash">
                        💵 Cash
                    </button>
                    <button type="button" class="btn-fast-pay btn-fast-credit" id="btnFastCredit">
                        💳 Credit
                    </button>
                    <button type="button" class="btn-fast-pay btn-fast-due" id="btnFastDue">
                        ⏳ Unpaid
                    </button>
                </div>

                <!-- Cash Card -->
                <div class="payment-card" id="cardCash">
                    <label class="chk-label" for="enable_cash">
                        <input type="checkbox" name="enable_cash" id="enable_cash" class="chk-input" <?= (isset($_POST['enable_cash']) || empty($_POST)) ? 'checked' : '' ?>>
                        <span>Cash Received</span>
                    </label>
                    <div class="amount-input-box visible" id="boxCash">
                        <label class="form-label" style="font-size: 12px; margin-top: 8px;">Cash Amount (₹):</label>
                        <input type="text" step="0.01" min="0" name="cash_amount" id="cash_amount" class="form-control" placeholder="Enter cash collected" value="<?= htmlspecialchars($_POST['cash_amount'] ?? '') ?>">
                    </div>
                </div>

                <!-- Credit Card -->
                <div class="payment-card" id="cardCredit">
                    <label class="chk-label" for="enable_credit">
                        <input type="checkbox" name="enable_credit" id="enable_credit" class="chk-input" <?= isset($_POST['enable_credit']) ? 'checked' : '' ?>>
                        <span>Credited Amount</span>
                    </label>
                    <div class="amount-input-box" id="boxCredit">
                        <label class="form-label" style="font-size: 12px; margin-top: 8px;">Credited Amount (₹):</label>
                        <input type="text" step="0.01" min="0" name="credited_amount" id="credited_amount" class="form-control" placeholder="Enter credit amount" value="<?= htmlspecialchars($_POST['credited_amount'] ?? '') ?>">
                    </div>
                </div>

              

                <!-- Live Calculation Panel -->
                <div class="calc-panel">
                    <div class="calc-row">
                        <span style="color: var(--text-muted);">Total Delivered Cases:</span>
                        <span id="dispTotalCases" style="font-weight: 700; color: #0f172a;">0 Cases</span>
                    </div>
                    <div class="calc-row">
                        <span style="color: var(--text-muted);">Delivered Subtotal:</span>
                        <span id="dispSubtotal" style="font-weight: 600;">₹0.00</span>
                    </div>
                    <?php if ($def_discount > 0): ?>
                    <div class="calc-row">
                        <span style="color: var(--text-muted);">Order Discount:</span>
                        <span id="dispDiscount" style="color: #dc2626; font-weight: 700;">- <?= formatCurrency($def_discount) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="calc-row total">
                        <span>Current Order Payable:</span>
                        <span id="dispTotal" style="color: #0f172a;">₹0.00</span>
                    </div>
                    <div class="calc-row" id="rowPriorDueCollected" style="display: none; color: #b91c1c;">
                        <span style="font-weight: 600;">+ Earlier Due Collected:</span>
                        <span id="dispPriorDueCollected" style="font-weight: 700;">₹0.00</span>
                    </div>
                    <div class="calc-row" id="rowGrandTotal" style="display: none; border-top: 1.5px solid #cbd5e1; padding-top: 6px; font-size: 15px; font-weight: 800; color: #1e40af;">
                        <span>Total Collectible Today:</span>
                        <span id="dispGrandTotal">₹0.00</span>
                    </div>
                    <div class="calc-row">
                        <span style="color: #059669;">&minus; Cash Collected (Current Bill):</span>
                        <span id="dispCash" style="color: #059669; font-weight: 700;">₹0.00</span>
                    </div>
                    <div class="calc-row">
                        <span style="color: #d97706;">&minus; Credited Amount (Current Bill):</span>
                        <span id="dispCredit" style="color: #d97706; font-weight: 700;">₹0.00</span>
                    </div>
                    <div class="calc-row due">
                        <span>Current Bill Balance / Due:</span>
                        <span id="dispDue">₹0.00</span>
                    </div>
                    <div class="calc-row" id="rowRemainingPriorDue" style="display: none; color: #991b1b; font-size: 13px; font-weight: 700;">
                        <span>Remaining Earlier Due:</span>
                        <span id="dispRemainingPriorDue">₹0.00</span>
                    </div>
                </div>

                <!-- Validation error feedback -->
                <div id="liveValidationError" style="display: none; background: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; font-weight: 700;">
                    Payment error: Cash + Credit cannot exceed Total Payable!
                </div>

                <!-- Submit Button -->
                <div class="mobile-sticky-submit">
                    <button type="submit" name="generate_receipt" id="submitBtn" class="btn-submit-bill">
                        <i class='bx bx-check-circle' style="font-size: 20px;"></i>
                        <span><?= ($reorder_id > 0) ? 'Generate Reorder Bill &amp; Complete Delivery' : 'Generate Bill &amp; Complete Delivery' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        let nextItemIdx = <?= count($regular_items) + count($vehicle_extra_products) + 10 ?>;
        const discountAmount = <?= (float)$def_discount ?>;

        const itemsContainer = document.getElementById('itemsContainer');
        const regularCountDisplay = document.getElementById('regularCountDisplay');

        const enableCash = document.getElementById('enable_cash');
        const cashInput = document.getElementById('cash_amount');
        const boxCash = document.getElementById('boxCash');
        const cardCash = document.getElementById('cardCash');

        const enableCredit = document.getElementById('enable_credit');
        const creditInput = document.getElementById('credited_amount');
        const boxCredit = document.getElementById('boxCredit');
        const cardCredit = document.getElementById('cardCredit');

        const btnFastCash = document.getElementById('btnFastCash');
        const btnFastCredit = document.getElementById('btnFastCredit');
        const btnFastDue = document.getElementById('btnFastDue');

        const dispTotalCases = document.getElementById('dispTotalCases');
        const dispSubtotal = document.getElementById('dispSubtotal');
        const dispTotal = document.getElementById('dispTotal');
        const dispCash = document.getElementById('dispCash');
        const dispCredit = document.getElementById('dispCredit');
        const dispDue = document.getElementById('dispDue');
        const liveError = document.getElementById('liveValidationError');
        const submitBtn = document.getElementById('submitBtn');

        function stepItemQty(btn, change) {
            const card = btn.closest('.item-delivery-card');
            if (!card) return;
            const input = card.querySelector('.item-qty-input');
            if (!input) return;
            const maxVal = input.hasAttribute('max') && input.getAttribute('max') !== '' ? parseInt(input.getAttribute('max')) : Infinity;
            let current = parseInt(input.value) || 0;
            let nextVal = Math.max(0, current + change);
            if (nextVal > maxVal) {
                alert(`Maximum cases available in vehicle is ${maxVal}.`);
                nextVal = maxVal;
            }
            input.value = nextVal;
            recalculate();
        }

        function checkMaxCases(input) {
            if (input.hasAttribute('max') && input.getAttribute('max') !== '') {
                const maxVal = parseInt(input.getAttribute('max'));
                let val = parseInt(input.value) || 0;
                if (val > maxVal) {
                    alert(`Maximum cases available in vehicle is ${maxVal}.`);
                    input.value = maxVal;
                }
            }
        }

        function syncDropdownWithOptions() {
            const sel = document.getElementById('vehicleProductSelect');
            const addBtn = document.getElementById('btnAddProductFromVehicle');
            if (!sel) return;

            // Collect all product IDs currently in the bill cards
            const cardPids = new Set();
            const allCards = itemsContainer.querySelectorAll('.item-delivery-card');
            allCards.forEach(card => {
                const pidInput = card.querySelector('.item-pid');
                if (pidInput && pidInput.value) {
                    cardPids.add(String(pidInput.value));
                }
            });

            let visibleCount = 0;
            const validOptions = Array.from(sel.options).filter(o => o.value !== '');
            validOptions.forEach(opt => {
                const pid = String(opt.value);
                const inBill = cardPids.has(pid);
                opt.hidden = inBill;
                opt.disabled = inBill;
                if (!inBill) {
                    visibleCount++;
                }

                // Sync matching quick-add pill
                const pill = document.querySelector(`.btn-quick-prod[data-product-id="${pid}"]`);
                if (pill) {
                    pill.style.display = inBill ? 'none' : 'inline-flex';
                }
            });

            // Update first option text
            const firstOpt = sel.options[0];
            if (firstOpt) {
                if (validOptions.length === 0) {
                    firstOpt.textContent = '-- No products loaded in vehicle --';
                } else if (visibleCount === 0) {
                    firstOpt.textContent = '-- All loaded vehicle products already in bill --';
                    sel.value = '';
                } else {
                    firstOpt.textContent = '-- Select Product from Vehicle --';
                }
            }

            if (addBtn) {
                if (visibleCount === 0) {
                    addBtn.disabled = true;
                    addBtn.style.opacity = '0.5';
                    addBtn.style.cursor = 'not-allowed';
                } else {
                    addBtn.disabled = false;
                    addBtn.style.opacity = '1';
                    addBtn.style.cursor = 'pointer';
                }
            }
        }

        function removeItemRow(btn) {
            const card = btn.closest('.item-delivery-card');
            if (!card) return;
            card.remove();
            recalculate();
            syncDropdownWithOptions();
        }

        function addProductFromDropdown() {
            const sel = document.getElementById('vehicleProductSelect');
            if (!sel || !sel.value) {
                alert('Please select a product from the dropdown.');
                return;
            }
            const opt = sel.options[sel.selectedIndex];
            if (!opt) return;

            const qtyInputEl = document.getElementById('addQtyInput');
            const addQty = Math.max(1, parseInt(qtyInputEl ? qtyInputEl.value : 1) || 1);

            const pid = sel.value;
            const name = opt.getAttribute('data-name');
            const price = parseFloat(opt.getAttribute('data-price')) || 0;
            const maxCases = parseInt(opt.getAttribute('data-max')) || 999;
            const content = opt.getAttribute('data-content') || '';
            const inVehicle = opt.getAttribute('data-in-vehicle') === '1';

            // Check if product already exists in items list
            const existingCard = itemsContainer.querySelector(`.item-pid[value="${pid}"]`);
            if (existingCard) {
                const card = existingCard.closest('.item-delivery-card');
                const qtyInput = card.querySelector('.item-qty-input');
                let currentQty = parseInt(qtyInput.value) || 0;
                let newQty = currentQty + addQty;
                if (inVehicle && newQty > maxCases) {
                    alert(`Only ${maxCases} cases of ${name} are loaded in this vehicle (currently added: ${currentQty}).`);
                    newQty = maxCases;
                }
                qtyInput.value = newQty;
                sel.value = '';
                if (qtyInputEl) qtyInputEl.value = '1';
                recalculate();
                syncDropdownWithOptions();
                return;
            }

            if (inVehicle && addQty > maxCases) {
                alert(`Notice: Only ${maxCases} cases of ${name} are loaded in this vehicle.`);
            }

            const idx = nextItemIdx++;
            const contentHtml = content ? `<span style="font-size: 11px; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">${content}</span>` : '';

            const div = document.createElement('div');
            div.className = 'item-delivery-card item-regular-card' + (inVehicle ? ' item-vehicle-extra-card' : '');
            div.id = 'itemRow_' + idx;
            div.setAttribute('data-idx', idx);
            if (inVehicle) {
                div.style.borderLeft = '4px solid #2563eb';
            }
            div.innerHTML = `
                <input type="hidden" name="items[${idx}][product_id]" value="${pid}" class="item-pid">
                <input type="hidden" name="items[${idx}][original_rate]" value="${price}" class="item-original-rate">
                <input type="hidden" name="items[${idx}][is_extra]" value="${inVehicle ? '1' : '0'}" class="item-is-extra">
                <input type="hidden" name="items[${idx}][max_cases]" value="${maxCases}" class="item-max-cases">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <strong style="font-size: 15px; color: #0f172a;">${name}</strong>
                            ${inVehicle ? `<span style="font-size: 11px; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 7px; border-radius: 4px;">VEHICLE STOCK</span>` : ''}
                            ${contentHtml}
                        </div>
                        ${inVehicle ? `<div style="font-size: 11px; color: #64748b; margin-top: 2px;">Loaded in vehicle: <strong>${maxCases} cases</strong></div>` : ''}
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; margin-top: 4px;">
                            <span>Rate: ₹</span>
                            <span style="font-weight: 700; color: #0f172a;">${price.toFixed(2)}</span>
                            <input type="hidden" name="items[${idx}][rate]" value="${price.toFixed(2)}" class="item-rate">
                            <span>/ case</span>
                        </div>
                        <div class="item-calc-text" style="font-size: 12px; color: #64748b; font-weight: 600; margin-top: 3px;">
                            Rate × Qty: ₹<span class="calc-rate-val">${price.toFixed(2)}</span> × <span class="calc-qty-val">${addQty}</span> cs = ₹<span class="calc-sub-val">${(price * addQty).toFixed(2)}</span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="item-line-total" style="font-size: 16px; font-weight: 800; color: #0f172a;">₹${(price * addQty).toFixed(2)}</div>
                        <button type="button" onclick="removeItemRow(this)" style="background: none; border: none; color: #dc2626; font-size: 12px; cursor: pointer; padding: 3px 0; display: inline-flex; align-items: center; gap: 2px;" title="Remove this product from delivery">
                            <i class='bx bx-trash'></i> Remove
                        </button>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8fafc; padding: 8px 12px; border-radius: 8px;">
                    <span style="font-size: 11px; font-weight: 600; color: #475569;">Delivered Cases:</span>
                    <div class="stepper-box">
                        <button type="button" class="btn-stepper" onclick="stepItemQty(this, -1)" aria-label="Decrease">&minus;</button>
                        <input type="text" min="0" ${inVehicle ? `max="${maxCases}"` : ''} name="items[${idx}][quantity]" value="${addQty}" class="form-control stepper-input item-qty-input" required oninput="checkMaxCases(this); recalculate()">
                        <button type="button" class="btn-stepper" onclick="stepItemQty(this, 1)" aria-label="Increase">&plus;</button>
                    </div>
                </div>
            `;

            itemsContainer.appendChild(div);
            sel.value = '';
            if (qtyInputEl) qtyInputEl.value = '1';
            recalculate();
            syncDropdownWithOptions();
        }

        function quickAddProduct(pid) {
            const sel = document.getElementById('vehicleProductSelect');
            if (!sel) return;
            sel.value = pid;
            addProductFromDropdown();
        }

        // Backward compatibility helpers
        function addNewProductItem() {
            addProductFromDropdown();
        }

        function addExtraCaseFromVehicle() {
            addProductFromDropdown();
        }

        function quickAddVehicleExtra(pid) {
            quickAddProduct(pid);
        }

        function addAllVehicleExtras() {
            const sel = document.getElementById('vehicleProductSelect');
            if (!sel) return;
            const options = Array.from(sel.options).filter(o => o.value !== '' && o.dataset.inVehicle === '1');
            options.forEach(opt => {
                sel.value = opt.value;
                addProductFromDropdown();
            });
        }

        function updateCheckboxes() {
            if (enableCash.checked) {
                boxCash.classList.add('visible');
                cardCash.classList.add('active');
            } else {
                boxCash.classList.remove('visible');
                cardCash.classList.remove('active');
                cashInput.value = '';
            }

            if (enableCredit.checked) {
                boxCredit.classList.add('visible');
                cardCredit.classList.add('active');
            } else {
                boxCredit.classList.remove('visible');
                cardCredit.classList.remove('active');
                creditInput.value = '';
            }
        }

        function calculateValues() {
            let totalCases = 0;
            let regularCases = 0;
            let extraCases = 0;
            let subtotal = 0;

            const allCards = itemsContainer.querySelectorAll('.item-delivery-card');
            allCards.forEach(card => {
                const qtyInput = card.querySelector('.item-qty-input');
                const rateInput = card.querySelector('.item-rate');
                const isExtraInput = card.querySelector('.item-is-extra');
                const lineTotalDisplay = card.querySelector('.item-line-total');
                const calcRateVal = card.querySelector('.calc-rate-val');
                const calcQtyVal = card.querySelector('.calc-qty-val');
                const calcSubVal = card.querySelector('.calc-sub-val');

                const qty = Math.max(0, parseInt(qtyInput ? qtyInput.value : 0) || 0);
                const isExtra = (isExtraInput && isExtraInput.value === '1') || card.classList.contains('item-vehicle-extra-card');
                const rate = parseFloat(rateInput ? rateInput.value : 0) || 0;
                const lineTotal = qty * rate;

                if (calcRateVal) calcRateVal.textContent = rate.toFixed(2);
                if (calcQtyVal) calcQtyVal.textContent = qty;
                if (calcSubVal) calcSubVal.textContent = lineTotal.toFixed(2);
                if (lineTotalDisplay) lineTotalDisplay.textContent = '₹' + lineTotal.toFixed(2);

                if (isExtra) {
                    extraCases += qty;
                } else {
                    regularCases += qty;
                }
                subtotal += lineTotal;
            });

            totalCases = regularCases + extraCases;
            const discount = Math.min(subtotal, discountAmount);
            const total = Math.max(0, subtotal - discount);

            return { totalCases, regularCases, extraCases, subtotal, discount, total };
        }

        function recalculate() {
            updateCheckboxes();
            const { totalCases, regularCases, extraCases, subtotal, discount, total } = calculateValues();

            const cashVal = enableCash.checked ? (parseFloat(cashInput.value) || 0) : 0;
            const creditVal = enableCredit.checked ? (parseFloat(creditInput.value) || 0) : 0;
            const paidSum = cashVal + creditVal;
            const due = Math.max(0, total - paidSum);

            if (dispTotalCases) {
                if (extraCases > 0) {
                    dispTotalCases.textContent = `${totalCases} Cases (${regularCases} Ordered + ${extraCases} Extra)`;
                } else {
                    dispTotalCases.textContent = `${totalCases} Cases`;
                }
            }
            if (regularCountDisplay) {
                const regCount = itemsContainer.querySelectorAll('.item-delivery-card').length;
                regularCountDisplay.textContent = `${regCount} ${regCount === 1 ? 'Product' : 'Products'}`;
            }

            const placeholder = document.getElementById('noItemsPlaceholder');
            if (placeholder) {
                const cardCount = itemsContainer.querySelectorAll('.item-delivery-card').length;
                placeholder.style.display = (cardCount === 0) ? 'block' : 'none';
            }

            if (dispSubtotal) dispSubtotal.textContent = '₹' + subtotal.toFixed(2);
            if (dispTotal) dispTotal.textContent = '₹' + total.toFixed(2);
            if (dispCash) dispCash.textContent = '₹' + cashVal.toFixed(2);
            if (dispCredit) dispCredit.textContent = '₹' + creditVal.toFixed(2);
            if (dispDue) dispDue.textContent = '₹' + due.toFixed(2);

            // Prior due calculations
            const sectionPrior = document.getElementById('priorDueSection');
            const collectPriorDue = document.getElementById('collect_prior_due');
            const priorDueInput = document.getElementById('prior_due_amount');
            const totalPriorDue = sectionPrior ? (parseFloat(sectionPrior.dataset.totalDue) || 0) : 0;
            const isCollectingPrior = collectPriorDue ? collectPriorDue.checked : false;
            const priorCleared = isCollectingPrior ? Math.min(totalPriorDue, Math.max(0, parseFloat(priorDueInput ? priorDueInput.value : 0) || 0)) : 0;
            const remainingPrior = Math.max(0, totalPriorDue - priorCleared);
            const grandTotal = total + priorCleared;

            const rowPrior = document.getElementById('rowPriorDueCollected');
            const rowGrand = document.getElementById('rowGrandTotal');
            const rowRemaining = document.getElementById('rowRemainingPriorDue');
            const dispPriorCollected = document.getElementById('dispPriorDueCollected');
            const dispGrand = document.getElementById('dispGrandTotal');
            const dispRemainingPrior = document.getElementById('dispRemainingPriorDue');

            if (rowPrior && dispPriorCollected) {
                if (priorCleared > 0) {
                    rowPrior.style.display = 'flex';
                    dispPriorCollected.textContent = '+ ₹' + priorCleared.toFixed(2);
                } else {
                    rowPrior.style.display = 'none';
                }
            }

            if (rowGrand && dispGrand) {
                if (priorCleared > 0) {
                    rowGrand.style.display = 'flex';
                    dispGrand.textContent = '₹' + grandTotal.toFixed(2);
                } else {
                    rowGrand.style.display = 'none';
                }
            }

            if (rowRemaining && dispRemainingPrior) {
                if (totalPriorDue > 0) {
                    rowRemaining.style.display = 'flex';
                    if (remainingPrior === 0) {
                        dispRemainingPrior.style.color = '#059669';
                        dispRemainingPrior.textContent = '₹0.00 (Fully Cleared)';
                    } else {
                        dispRemainingPrior.style.color = '#dc2626';
                        dispRemainingPrior.textContent = '₹' + remainingPrior.toFixed(2);
                    }
                } else {
                    rowRemaining.style.display = 'none';
                }
            }

            if (due === 0 && total > 0) {
                dispDue.style.color = '#059669';
            } else {
                dispDue.style.color = '#dc2626';
            }

            // Payment Sum Validation
            if (paidSum > total + 0.01) {
                liveError.style.display = 'block';
                liveError.textContent = `Payment Error: Cash (₹${cashVal.toFixed(2)}) + Credit (₹${creditVal.toFixed(2)}) exceeds Net Total (₹${total.toFixed(2)})!`;
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            } else if (totalCases <= 0) {
                liveError.style.display = 'block';
                liveError.textContent = `Please specify at least 1 delivered case.`;
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            } else {
                liveError.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            }
        }

       function togglePriorDueBox(checked) {
    const box = document.getElementById('priorDueInputFields');
    const amountInput = document.getElementById('prior_due_amount');

    if (box) {
        box.style.display = checked ? 'block' : 'none';
    }

    if (amountInput) {
        amountInput.disabled = !checked;
        amountInput.required = checked;
    }

    recalculate();
}

        function setPriorDueFull() {
            const sectionPrior = document.getElementById('priorDueSection');
            const totalPriorDue = sectionPrior ? (parseFloat(sectionPrior.dataset.totalDue) || 0) : 0;
            const pInput = document.getElementById('prior_due_amount');
            if (pInput) {
                pInput.value = totalPriorDue.toFixed(2);
                recalculate();
            }
        }

        function checkShopDuesAjax() {
            const shopVal = (document.getElementById('shop_name')?.value || '').trim();
            const phoneVal = (document.getElementById('phone')?.value || '').trim();
            if (!shopVal && !phoneVal) return;

            const url = '<?= zone_url($zone_slug, 'generate-receipt') ?>?action=check_prior_due&shop=' + encodeURIComponent(shopVal) + '&phone=' + encodeURIComponent(phoneVal) + '&exclude_id=<?= (int)$order_id ?>';
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    const section = document.getElementById('priorDueSection');
                    if (!section) return;
                    if (data && data.has_due && data.total_due > 0) {
                        section.style.display = 'block';
                        section.dataset.totalDue = data.total_due;
                        document.getElementById('dispPriorDueTotal').textContent = '₹' + data.total_due.toFixed(2);
                        document.getElementById('dispPriorDueCount').textContent = data.due_count + (data.due_count === 1 ? ' Unpaid Bill' : ' Unpaid Bills');
                        
                        const tbody = document.getElementById('priorDueTableBody');
                        if (tbody && data.receipts) {
                            tbody.innerHTML = data.receipts.map(r => `
                                <tr style="border-bottom: 1px solid #f8fafc;">
                                    <td style="padding: 6px 10px; font-weight: 700; color: #0f172a;">Bill #${r.bill_number} <span style="font-size: 10px; color: #64748b;">(Order #${r.order_number})</span></td>
                                    <td style="padding: 6px 10px; color: #64748b;">${r.receipt_date ? r.receipt_date.substring(0, 10) : ''}</td>
                                    <td style="padding: 6px 10px; text-align: right; color: #475569;">₹${parseFloat(r.total_amount).toFixed(2)}</td>
                                    <td style="padding: 6px 10px; text-align: right; font-weight: 800; color: #dc2626;">₹${parseFloat(r.due_amount).toFixed(2)}</td>
                                </tr>
                            `).join('');
                        }
                        const pInput = document.getElementById('prior_due_amount');
                        if (pInput) {
                            pInput.max = data.total_due;
                            if (!pInput.value || parseFloat(pInput.value) <= 0) {
                                pInput.value = data.total_due.toFixed(2);
                            }
                        }
                    } else {
                        section.style.display = 'none';
                        section.dataset.totalDue = 0;
                    }
                    recalculate();
                })
                .catch(err => console.error(err));
        }

        document.getElementById('shop_name')?.addEventListener('blur', checkShopDuesAjax);
        document.getElementById('phone')?.addEventListener('blur', checkShopDuesAjax);

        // Fast pay shortcut buttons
        btnFastCash.addEventListener('click', function() {
            const { total } = calculateValues();
            enableCash.checked = true;
            cashInput.value = total.toFixed(2);
            enableCredit.checked = false;
            creditInput.value = '';
            recalculate();
        });

        btnFastCredit.addEventListener('click', function() {
            const { total } = calculateValues();
            enableCredit.checked = true;
            creditInput.value = total.toFixed(2);
            enableCash.checked = false;
            cashInput.value = '';
            recalculate();
        });

        btnFastDue.addEventListener('click', function() {
            enableCash.checked = false;
            cashInput.value = '';
            enableCredit.checked = false;
            creditInput.value = '';
            recalculate();
        });

        enableCash.addEventListener('change', function() {
            if (this.checked && !cashInput.value) {
                const { total } = calculateValues();
                const creditVal = enableCredit.checked ? (parseFloat(creditInput.value) || 0) : 0;
                cashInput.value = Math.max(0, total - creditVal).toFixed(2);
            }
            recalculate();
        });

        enableCredit.addEventListener('change', function() {
            if (this.checked && !creditInput.value) {
                const { total } = calculateValues();
                const cashVal = enableCash.checked ? (parseFloat(cashInput.value) || 0) : 0;
                creditInput.value = Math.max(0, total - cashVal).toFixed(2);
            }
            recalculate();
        });

        cashInput.addEventListener('input', recalculate);
        creditInput.addEventListener('input', recalculate);
// Keep prior due validation synchronized with visibility on page load
const collectPriorCheckbox = document.getElementById('collect_prior_due');
const priorDueAmountInput = document.getElementById('prior_due_amount');
const priorDueFields = document.getElementById('priorDueInputFields');

if (collectPriorCheckbox && priorDueAmountInput && priorDueFields) {
    priorDueAmountInput.disabled = !collectPriorCheckbox.checked;
    priorDueAmountInput.required = collectPriorCheckbox.checked;

    priorDueFields.style.display =
        collectPriorCheckbox.checked ? 'block' : 'none';
}
        // Initial calculation
        if (enableCash.checked && !cashInput.value) {
            const { total } = calculateValues();
            cashInput.value = total.toFixed(2);
        }
        recalculate();
        syncDropdownWithOptions();
    </script>
</body>
</html>