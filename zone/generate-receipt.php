<?php
require_once __DIR__ . '/zone_context.php';

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0);

// Load existing order if order_id provided
$order = null;
$order_items = [];
if ($order_id > 0) {
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

// Fallback: If no order items found for existing order, construct from order row ONLY if valid product_id > 0 and quantity > 0
if ($order && empty($order_items) && !empty($order['product_id']) && (int)$order['product_id'] > 0 && (int)$order['quantity'] > 0) {
    $pid = (int)$order['product_id'];
    $pRate = (float)($order['unit_price'] ?: ($order['case_price'] ?: $order['price']));
    $pQty = (int)$order['quantity'];
    $order_items[] = [
        'order_item_id' => 0,
        'order_id' => $order_id,
        'product_id' => $pid,
        'quantity' => $pQty,
        'price_at_purchase' => $pRate,
        'line_total' => $pQty * $pRate,
        'name' => $order['product_name'] ?: ($order['fallback_product_name'] ?: 'Liyas Water'),
        'product_name' => $order['product_name'] ?: ($order['fallback_product_name'] ?: 'Liyas Water'),
        'net_content' => $order['net_content'],
        'net_content_unit' => $order['net_content_unit'],
        'is_extra' => 0
    ];
}

// Determine assigned vehicle
$assignedVehicleId = (int)($order['vehicle_id'] ?? 0);
if ($assignedVehicleId <= 0 && $order_id > 0) {
    $vChk = $pdo->prepare("SELECT vehicle_id FROM vehicle_loads WHERE order_id = ? AND is_active = 1 LIMIT 1");
    $vChk->execute([$order_id]);
    $assignedVehicleId = (int)$vChk->fetchColumn();
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

if ($assignedVehicleId <= 0 && !empty($availVehicles)) {
    $assignedVehicleId = (int)$availVehicles[0]['id'];
}
$assignedVehicle = null;
if ($assignedVehicleId > 0) {
    $vStmt = $pdo->prepare("SELECT id, vehicle_number, vehicle_name, driver_name, driver_phone FROM vehicles WHERE id = ?");
    $vStmt->execute([$assignedVehicleId]);
    $assignedVehicle = $vStmt->fetch(PDO::FETCH_ASSOC);
}

// Build list of extra cases loaded in this vehicle
$vehicle_extra_products = [];
// 1. Extra cases attached to this order in order_items (is_extra = 1)
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
            if (!isset($vehicle_extra_products[$pid])) {
                $vehicle_extra_products[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $pName,
                    'available_cases' => 0,
                    'price' => $pPrice,
                    'net_content' => $pInfo['net_content'] ?? ($oi['net_content'] ?? ''),
                    'net_content_unit' => $pInfo['net_content_unit'] ?? ($oi['net_content_unit'] ?? '')
                ];
            }
            $vehicle_extra_products[$pid]['available_cases'] += $qty;
        }
    }
}

// 2. Extra storage cases currently in the assigned vehicle from vehicle_loads
if ($assignedVehicleId > 0) {
    $vlStmt = $pdo->prepare("
        SELECT vl.product_id, vl.cases_loaded, p.product_name, p.name, p.case_price, p.price, p.net_content, p.net_content_unit
        FROM vehicle_loads vl
        LEFT JOIN products p ON vl.product_id = p.product_id
        WHERE vl.vehicle_id = ? 
          AND vl.is_active = 1 
          AND vl.product_id IS NOT NULL 
          AND (vl.order_id IS NULL OR vl.order_id != ?)
    ");
    $vlStmt->execute([$assignedVehicleId, $order_id]);
    $vlRows = $vlStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($vlRows as $vr) {
        $pid = (int)$vr['product_id'];
        $qty = (int)$vr['cases_loaded'];
        if ($qty > 0) {
            $pName = $vr['product_name'] ?: ($vr['name'] ?: 'Product #' . $pid);
            $pPrice = (float)($vr['case_price'] ?: $vr['price']);
            if (!isset($vehicle_extra_products[$pid])) {
                $vehicle_extra_products[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $pName,
                    'available_cases' => 0,
                    'price' => $pPrice,
                    'net_content' => $vr['net_content'],
                    'net_content_unit' => $vr['net_content_unit']
                ];
            }
            $vehicle_extra_products[$pid]['available_cases'] += $qty;
        }
    }
}

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
        $discount = $order ? (float)$order['discount'] : 0.00;
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
                    if ($assignedVehicleId <= 0 && !empty($availVehicles)) {
                        $assignedVehicleId = (int)$availVehicles[0]['id'];
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
                    // New direct spot delivery without prior order
                    $assignedVehicleId = (int)($_POST['vehicle_id'] ?? 0);
                    if ($assignedVehicleId <= 0 && !empty($availVehicles)) {
                        $assignedVehicleId = (int)$availVehicles[0]['id'];
                    }

                    $maxOrdStmt = $pdo->query("SELECT MAX(order_id) FROM orders");
                    $nextOrdId = (int)$maxOrdStmt->fetchColumn() + 1;
                    $order_number = '' . str_pad($nextOrdId, 3, '0', STR_PAD_LEFT);

                    $insOrd = $pdo->prepare("
                        INSERT INTO orders (
                            order_number, zone_id, vehicle_id, shop_name, customer_name, location, 
                            phone, product_id, quantity, unit_price, discount, 
                            total_amount, status, is_zone_read, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, 0, 
                            ?, 'delivered', 1, NOW(), NOW()
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
                        $total_amount
                    ]);
                    $order_id = (int)$pdo->lastInsertId();

                    // Insert into order_items & deduct warehouse stock
                    $insOi = $pdo->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $deliveredExtraByPid = [];
                    foreach ($processed_items as $pi) {
                        adjustProductStock($pdo, $pi['product_id'], -$pi['quantity'], "Direct delivery #{$order_number}");
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

                // Insert into receipts table
                $insReceipt = $pdo->prepare("
                    INSERT INTO receipts (
                        order_id, bill_number, delivered_quantity, total_amount, 
                        cash_amount, credited_amount, due_amount, payment_type, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insReceipt->execute([
                    $order_id,
                    $bill_number,
                    $total_delivered_cases,
                    $total_amount,
                    $cash_amount,
                    $credited_amount,
                    $due_amount,
                    $payment_type
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

                header("Location: " . zone_url($zone_slug, 'receipt', ['id' => $order_id, 'new' => 1]));
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
$def_shop       = $order['shop_name'] ?? '';
$def_customer   = $order['customer_name'] ?? '';
$def_place      = $order['location'] ?? '';
$def_phone      = $order['phone'] ?? '';
$def_discount   = (float)($order['discount'] ?? 0);

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
            'max_cases' => (int)($it['max_cases'] ?? ($vehicle_extra_products[$pId]['available_cases'] ?? 999)),
            'line_total' => $pQty * $rate,
            'name' => $pName,
            'product_name' => $pName,
            'net_content' => $prodInfo['net_content'] ?? null,
            'net_content_unit' => $prodInfo['net_content_unit'] ?? null,
        ];
        $regular_items[] = $rowItem;
    }
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
                    <i class='bx bx-receipt' style="color: #059669;"></i>
                    <?= ($order_id > 0) ? 'Generate Delivery Receipt' : 'New Spot Delivery Receipt' ?>
                </h1>
               
            </div>

            <form action="<?= zone_url($zone_slug, 'generate-receipt') ?>" method="POST" id="receiptForm">
                <?php if ($order_id > 0): ?>
                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                <?php endif; ?>
                <input type="hidden" name="vehicle_id" id="assignedVehicleIdInput" value="<?= $assignedVehicleId ?>">

                <!-- Shop & Customer Details -->
                <div class="section-hdr">
                    <i class='bx bx-store-alt' style="color: #2563eb;"></i> Shop &amp; Delivery Details
                </div>

                <?php if ($order_id <= 0 && count($availVehicles) > 1): ?>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="vehicleSelect">
                        <i class='bx bxs-truck' style="color: #2563eb;"></i> Delivering Vehicle
                    </label>
                    <select name="vehicle_id" id="vehicleSelect" class="form-control" onchange="window.location.href = '<?= zone_url($zone_slug, 'generate-receipt') ?>?vehicle_id=' + this.value;">
                        <?php foreach ($availVehicles as $av): ?>
                            <option value="<?= $av['id'] ?>" <?= ($assignedVehicleId === (int)$av['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($av['vehicle_number']) ?><?= !empty($av['vehicle_name']) ? ' - ' . htmlspecialchars($av['vehicle_name']) : '' ?><?= !empty($av['driver_name']) ? ' (' . htmlspecialchars($av['driver_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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

                <!-- Interactive Item Delivery Cards -->
                <div id="itemsContainer" style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 16px;">
                    <div id="noItemsPlaceholder" style="<?= !empty($regular_items) ? 'display: none;' : '' ?> text-align: center; padding: 22px 16px; background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 12px; color: #64748b;">
                        <i class='bx bx-package' style="font-size: 32px; color: #94a3b8; display: block; margin-bottom: 6px;"></i>
                        <div style="font-weight: 700; font-size: 14px; color: #334155;">No products added to bill yet</div>
                        <div style="font-size: 12px; color: #64748b; margin-top: 3px;">
                            <?= !empty($vehicle_extra_products) ? 'Click the extra products loaded in the vehicle below or catalog products to add to this delivery.' : 'Add catalog products below to begin delivery.' ?>
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
                        $maxCases = (int)($it['max_cases'] ?? ($vehicle_extra_products[$pid]['available_cases'] ?? 999));
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
                                        <span style="font-size: 11px; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 7px; border-radius: 4px;">EXTRA VEHICLE CASE</span>
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

    <input
        type="hidden"
        name="items[<?= $idx ?>][rate]"
        value="<?= htmlspecialchars((string)$rate, ENT_QUOTES, 'UTF-8') ?>"
        class="item-rate"
    >

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
                            <span style="font-size: 13px; font-weight: 600; color: #475569;">Delivered Cases:</span>
                            <div class="stepper-box">
                                <button type="button" class="btn-stepper" onclick="stepItemQty(this, -1)" aria-label="Decrease">&minus;</button>
                                <input type="number" min="0" <?= $isExtra ? 'max="' . $maxCases . '"' : '' ?> name="items[<?= $idx ?>][quantity]" value="<?= $qty ?>" class="form-control stepper-input item-qty-input" required oninput="checkMaxCases(this); recalculate()">
                                <button type="button" class="btn-stepper" onclick="stepItemQty(this, 1)" aria-label="Increase">&plus;</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($vehicle_extra_products)): ?>
                <!-- Add from Extra Cases Loaded in Vehicle Dropdown -->
                <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: var(--radius-md); padding: 4px; margin-bottom: 16px;">
                  

                    <!-- One-click Quick Add Pills -->
                    <!-- <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
                        <?php foreach ($vehicle_extra_products as $vep): ?>
                            <button type="button" class="btn-quick-extra" onclick="quickAddVehicleExtra(<?= $vep['product_id'] ?>)" 
                                    style="background: #ffffff; border: 1.5px solid #93c5fd; color: #1e40af; padding: 7px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.15s ease;"
                                    onmouseover="this.style.background='#eff6ff'; this.style.borderColor='#2563eb';"
                                    onmouseout="this.style.background='#ffffff'; this.style.borderColor='#93c5fd';"
                                    title="Click to add <?= htmlspecialchars($vep['product_name']) ?> to bill">
                                <i class='bx bx-plus-circle' style="color: #2563eb; font-size: 16px;"></i>
                                <span><?= htmlspecialchars($vep['product_name']) ?></span>
                                <span style="background: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-weight: 800;"><?= (int)$vep['available_cases'] ?> cs</span>
                                <span style="color: #059669; font-weight: 800;">₹<?= number_format($vep['price'], 2) ?></span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (count($vehicle_extra_products) > 1): ?>
                            <button type="button" onclick="addAllVehicleExtras()" 
                                    style="background: #2563eb; border: 1.5px solid #2563eb; color: #ffffff; padding: 7px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.15s ease;"
                                    onmouseover="this.style.background='#1d4ed8';"
                                    onmouseout="this.style.background='#2563eb';"
                                    title="Add all loaded products from vehicle to bill">
                                <i class='bx bx-check-double' style="font-size: 16px;"></i>
                                <span>+ Add All to Bill</span>
                            </button>
                        <?php endif; ?>
                    </div> -->

                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <select id="extraVehicleSelect" class="form-control" style="flex: 1; min-width: 200px;">
                            <option value="">- Select extra cases from vehicle -</option>
                            <?php foreach ($vehicle_extra_products as $vep): ?>
                                <option value="<?= $vep['product_id'] ?>" 
                                        data-name="<?= htmlspecialchars($vep['product_name']) ?>" 
                                        data-price="<?= (float)$vep['price'] ?>" 
                                        data-max="<?= (int)$vep['available_cases'] ?>"
                                        data-content="<?= htmlspecialchars(($vep['net_content'] ? (float)$vep['net_content'] . ' ' . $vep['net_content_unit'] : '')) ?>">
                                    <?= htmlspecialchars($vep['product_name']) ?> (<?= (int)$vep['available_cases'] ?> cs in vehicle) &bull; <?= formatCurrency($vep['price']) ?>/case
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="addExtraCaseFromVehicle()" style="background: #2563eb; color: #ffffff; border: none; padding: 9px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <i class='bx bx-plus-circle'></i> Add Extra to Bill
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Add Regular Product Row -->
                <!-- <div style="background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: var(--radius-md); padding: 14px; margin-bottom: 20px;">
                    <div style="font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 6px; display: flex; align-items: center; gap: 4px;">
                        <i class='bx bx-plus-circle' style="color: #475569;"></i> Add another catalog product to bill
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <select id="addProductSelect" class="form-control" style="flex: 1; min-width: 200px;">
                            <option value="">-- Add another product to bill --</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['product_id'] ?>" data-name="<?= htmlspecialchars($p['product_name'] ?: $p['name']) ?>" data-price="<?= (float)($p['case_price'] ?: $p['price']) ?>" data-content="<?= htmlspecialchars(($p['net_content'] ? (float)$p['net_content'] . ' ' . $p['net_content_unit'] : '')) ?>">
                                    <?= htmlspecialchars($p['product_name'] ?: $p['name']) ?> &bull; <?= formatCurrency($p['case_price'] ?: $p['price']) ?>/case
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="addNewProductItem()" style="background: #475569; color: #ffffff; border: none; padding: 9px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                            <i class='bx bx-plus'></i> Add to Bill
                        </button>
                    </div>
                </div> -->

                <!-- Payment Settlement -->
                <div class="section-hdr">
                    <i class='bx bx-wallet' style="color: #d97706;"></i> Payment Collection
                </div>

                <!-- 1-Tap Fast Settlement Shortcuts -->
                <div class="fast-pay-buttons">
                    <button type="button" class="btn-fast-pay btn-fast-cash" id="btnFastCash">
                        💵 100% Full Cash
                    </button>
                    <button type="button" class="btn-fast-pay btn-fast-credit" id="btnFastCredit">
                        💳 100% Credit
                    </button>
                    <button type="button" class="btn-fast-pay btn-fast-due" id="btnFastDue">
                        ⏳ Unpaid (Due)
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
                        <input type="number" step="0.01" min="0" name="cash_amount" id="cash_amount" class="form-control" placeholder="Enter cash collected" value="<?= htmlspecialchars($_POST['cash_amount'] ?? '') ?>">
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
                        <input type="number" step="0.01" min="0" name="credited_amount" id="credited_amount" class="form-control" placeholder="Enter credit amount" value="<?= htmlspecialchars($_POST['credited_amount'] ?? '') ?>">
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
                        <span>Net Total Payable:</span>
                        <span id="dispTotal" style="color: #0f172a;">₹0.00</span>
                    </div>
                    <div class="calc-row">
                        <span style="color: #059669;">&minus; Cash Collected:</span>
                        <span id="dispCash" style="color: #059669; font-weight: 700;">₹0.00</span>
                    </div>
                    <div class="calc-row">
                        <span style="color: #d97706;">&minus; Credited Amount:</span>
                        <span id="dispCredit" style="color: #d97706; font-weight: 700;">₹0.00</span>
                    </div>
                    <div class="calc-row due">
                        <span>Remaining Balance / Due:</span>
                        <span id="dispDue">₹0.00</span>
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
                        <span>Generate Bill &amp; Complete Delivery</span>
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

        function removeItemRow(btn) {
            const card = btn.closest('.item-delivery-card');
            if (!card) return;
            card.remove();
            recalculate();
        }

        function addNewProductItem() {
            const sel = document.getElementById('addProductSelect');
            const opt = sel.options[sel.selectedIndex];
            if (!sel.value || !opt) return;

            const pid = sel.value;
            const name = opt.getAttribute('data-name');
            const price = parseFloat(opt.getAttribute('data-price')) || 0;
            const content = opt.getAttribute('data-content') || '';

            // Check if product already exists in regular list, if so increment qty
            const existingCard = itemsContainer.querySelector(`.item-pid[value="${pid}"]`);
            if (existingCard) {
                const card = existingCard.closest('.item-delivery-card');
                const qtyInput = card.querySelector('.item-qty-input');
                qtyInput.value = (parseInt(qtyInput.value) || 0) + 1;
                sel.value = '';
                recalculate();
                return;
            }

            const idx = nextItemIdx++;
            const contentHtml = content ? `<span style="font-size: 11px; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">${content}</span>` : '';

            const div = document.createElement('div');
            div.className = 'item-delivery-card item-regular-card';
            div.id = 'itemRow_' + idx;
            div.setAttribute('data-idx', idx);
            div.innerHTML = `
                <input type="hidden" name="items[${idx}][product_id]" value="${pid}" class="item-pid">
                <input type="hidden" name="items[${idx}][original_rate]" value="${price}" class="item-original-rate">
                <input type="hidden" name="items[${idx}][is_extra]" value="0" class="item-is-extra">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <strong style="font-size: 15px; color: #0f172a;">${name}</strong>
                        ${contentHtml}
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; margin-top: 4px;">
                            <span>Rate: ₹</span>
                            <input type="number" step="0.01" min="0" name="items[${idx}][rate]" value="${price.toFixed(2)}" class="form-control item-rate" style="width: 85px; height: 28px; padding: 2px 6px; font-size: 12px; font-weight: 700; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #ffffff;" oninput="recalculate()" title="Edit rate if needed">
                            <span> / case</span>
                        </div>
                        <div class="item-calc-text" style="font-size: 12px; color: #64748b; font-weight: 600; margin-top: 3px;">
                            Rate × Qty: ₹<span class="calc-rate-val">${price.toFixed(2)}</span> × <span class="calc-qty-val">1</span> cs = ₹<span class="calc-sub-val">${price.toFixed(2)}</span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="item-line-total" style="font-size: 16px; font-weight: 800; color: #0f172a;">₹${price.toFixed(2)}</div>
                        <button type="button" onclick="removeItemRow(this)" style="background: none; border: none; color: #dc2626; font-size: 12px; cursor: pointer; padding: 3px 0; display: inline-flex; align-items: center; gap: 2px;" title="Remove this product">
                            <i class='bx bx-trash'></i> Remove
                        </button>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8fafc; padding: 8px 12px; border-radius: 8px;">
                    <span style="font-size: 13px; font-weight: 600; color: #475569;">Delivered Cases:</span>
                    <div class="stepper-box">
                        <button type="button" class="btn-stepper" onclick="stepItemQty(this, -1)" aria-label="Decrease">&minus;</button>
                        <input type="number" min="0" name="items[${idx}][quantity]" value="1" class="form-control stepper-input item-qty-input" required oninput="recalculate()">
                        <button type="button" class="btn-stepper" onclick="stepItemQty(this, 1)" aria-label="Increase">&plus;</button>
                    </div>
                </div>
            `;

            itemsContainer.appendChild(div);
            sel.value = '';
            recalculate();
        }

        function addExtraCaseFromVehicle() {
            const sel = document.getElementById('extraVehicleSelect');
            if (!sel || !sel.value) return;
            const opt = sel.options[sel.selectedIndex];
            if (!opt) return;

            const pid = sel.value;
            const name = opt.getAttribute('data-name');
            const price = parseFloat(opt.getAttribute('data-price')) || 0;
            const maxCases = parseInt(opt.getAttribute('data-max')) || 999;
            const content = opt.getAttribute('data-content') || '';

            // Check if product already exists in items list
            const existingCard = itemsContainer.querySelector(`.item-pid[value="${pid}"]`);
            if (existingCard) {
                const card = existingCard.closest('.item-delivery-card');
                const qtyInput = card.querySelector('.item-qty-input');
                let currentQty = parseInt(qtyInput.value) || 0;
                if (currentQty >= maxCases) {
                    alert(`Only ${maxCases} cases of ${name} are loaded in this vehicle.`);
                    return;
                }
                qtyInput.value = currentQty + 1;
                sel.value = '';
                recalculate();
                return;
            }

            const idx = nextItemIdx++;
            const contentHtml = content ? `<span style="font-size: 11px; color: #64748b; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">${content}</span>` : '';

            const div = document.createElement('div');
            div.className = 'item-delivery-card item-regular-card item-vehicle-extra-card';
            div.id = 'itemRow_' + idx;
            div.setAttribute('data-idx', idx);
            div.style.borderLeft = '4px solid #2563eb';
            div.innerHTML = `
                <input type="hidden" name="items[${idx}][product_id]" value="${pid}" class="item-pid">
                <input type="hidden" name="items[${idx}][original_rate]" value="${price}" class="item-original-rate">
                <input type="hidden" name="items[${idx}][is_extra]" value="1" class="item-is-extra">
                <input type="hidden" name="items[${idx}][max_cases]" value="${maxCases}" class="item-max-cases">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <strong style="font-size: 15px; color: #0f172a;">${name}</strong>
                            <span style="font-size: 11px; font-weight: 700; color: #1e40af; background: #dbeafe; padding: 2px 7px; border-radius: 4px;">EXTRA VEHICLE CASE</span>
                            ${contentHtml}
                        </div>
                        <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                            Loaded in vehicle: <strong>${maxCases} cases</strong>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #475569; margin-top: 4px;">
                            <span>Rate: ₹</span>
                            <input type="number" step="0.01" min="0" name="items[${idx}][rate]" value="${price.toFixed(2)}" class="form-control item-rate" style="width: 85px; height: 28px; padding: 2px 6px; font-size: 12px; font-weight: 700; border: 1.5px solid #cbd5e1; border-radius: 6px; background: #ffffff;" oninput="recalculate()" title="Edit rate if needed">
                            <span>/ case</span>
                        </div>
                        <div class="item-calc-text" style="font-size: 12px; color: #64748b; font-weight: 600; margin-top: 3px;">
                            Rate × Qty: ₹<span class="calc-rate-val">${price.toFixed(2)}</span> × <span class="calc-qty-val">1</span> cs = ₹<span class="calc-sub-val">${price.toFixed(2)}</span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div class="item-line-total" style="font-size: 16px; font-weight: 800; color: #0f172a;">₹${price.toFixed(2)}</div>
                        <button type="button" onclick="removeItemRow(this)" style="background: none; border: none; color: #dc2626; font-size: 12px; cursor: pointer; padding: 3px 0; display: inline-flex; align-items: center; gap: 2px;" title="Remove this extra product">
                            <i class='bx bx-trash'></i> Remove
                        </button>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #f8fafc; padding: 8px 12px; border-radius: 8px;">
                    <span style="font-size: 13px; font-weight: 600; color: #475569;">Delivered Cases:</span>
                    <div class="stepper-box">
                        <button type="button" class="btn-stepper" onclick="stepItemQty(this, -1)" aria-label="Decrease">&minus;</button>
                        <input type="number" min="0" max="${maxCases}" name="items[${idx}][quantity]" value="1" class="form-control stepper-input item-qty-input" required oninput="checkMaxCases(this); recalculate()">
                        <button type="button" class="btn-stepper" onclick="stepItemQty(this, 1)" aria-label="Increase">&plus;</button>
                    </div>
                </div>
            `;

            itemsContainer.appendChild(div);
            sel.value = '';
            recalculate();
        }

        function quickAddVehicleExtra(pid) {
            const sel = document.getElementById('extraVehicleSelect');
            if (!sel) return;
            sel.value = pid;
            addExtraCaseFromVehicle();
        }

        function addAllVehicleExtras() {
            const sel = document.getElementById('extraVehicleSelect');
            if (!sel) return;
            const options = Array.from(sel.options).filter(o => o.value !== '');
            options.forEach(opt => {
                sel.value = opt.value;
                addExtraCaseFromVehicle();
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

        // Initial calculation
        if (enableCash.checked && !cashInput.value) {
            const { total } = calculateValues();
            cashInput.value = total.toFixed(2);
        }
        recalculate();
    </script>
</body>
</html>