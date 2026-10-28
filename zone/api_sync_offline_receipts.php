<?php
/**
 * Offline Receipts Synchronization API
 * Safely converts offline-generated delivery receipts into official database records
 */
require_once __DIR__ . '/zone_context.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!isset($data['receipts']) || !is_array($data['receipts']) || empty($data['receipts'])) {
    echo json_encode(['success' => true, 'synced_count' => 0, 'results' => []]);
    exit;
}

$results = [];
$syncedCount = 0;

foreach ($data['receipts'] as $rec) {
    $offlineId = trim($rec['offline_id'] ?? '');
    if (empty($offlineId)) {
        continue;
    }

    try {
        // Idempotency Check: Prevent duplicate sync if already processed earlier
        $dupChk = $pdo->prepare("SELECT id, bill_number, order_id FROM receipts WHERE notes LIKE ? LIMIT 1");
        $dupChk->execute(['%' . $offlineId . '%']);
        $existing = $dupChk->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $results[] = [
                'offline_id'    => $offlineId,
                'status'        => 'already_synced',
                'bill_number'   => $existing['bill_number'],
                'order_id'      => (int)$existing['order_id']
            ];
            $syncedCount++;
            continue;
        }

        $pdo->beginTransaction();

        $shopName       = trim($rec['shop_name'] ?? 'Valued Customer');
        $customerName   = trim($rec['customer_name'] ?? $shopName);
        $place          = trim($rec['location'] ?? 'Mangalore');
        $phone          = trim($rec['phone'] ?? '');
        $assignedVehicleId = (int)($rec['vehicle_id'] ?? 0);
        $totalAmount    = (float)($rec['total_amount'] ?? 0);
        $cashAmount     = (float)($rec['cash_amount'] ?? 0);
        $creditAmount   = (float)($rec['credited_amount'] ?? 0);
        $dueAmount      = (float)($rec['due_amount'] ?? 0);
        $paymentType    = trim($rec['payment_type'] ?? 'Cash');
        $receiptDate    = !empty($rec['created_at']) ? $rec['created_at'] : date('Y-m-d H:i:s');
        $existingOrderId = (int)($rec['order_id'] ?? 0);

        $items = $rec['items'] ?? [];
        if (empty($items)) {
            throw new Exception("No items specified for receipt {$offlineId}");
        }

        $totalCases = 0;
        $primaryPid = (int)($items[0]['product_id'] ?? 1);
        $primaryRate = (float)($items[0]['rate'] ?? 0);
        foreach ($items as $it) {
            $totalCases += (int)($it['quantity'] ?? 0);
        }

        // 1. Process Order (Update existing booked order OR create new direct delivery order)
        if ($existingOrderId > 0) {
            $order_id = $existingOrderId;
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
                $shopName, $customerName, $place, $phone,
                $primaryPid, $totalCases, $primaryRate, $totalAmount,
                $assignedVehicleId, $order_id, $zone_id
            ]);

            // Sync order_items
            $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$order_id]);
            $insOi = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            foreach ($items as $it) {
                $insOi->execute([
                    $order_id, (int)$it['product_id'], (int)$it['quantity'], 
                    (float)$it['rate'], !empty($it['is_extra']) ? 1 : 0, $receiptDate
                ]);
                adjustProductStock($pdo, (int)$it['product_id'], -(int)$it['quantity'], "Offline Order #{$order_id} delivered");
            }

            $ordNumStmt = $pdo->prepare("SELECT order_number FROM orders WHERE order_id = ?");
            $ordNumStmt->execute([$order_id]);
            $order_number = $ordNumStmt->fetchColumn() ?: ('#' . $order_id);

        } else {
            // New Direct Spot Delivery / Reorder
            $maxOrdStmt = $pdo->query("SELECT MAX(order_id) FROM orders");
            $nextOrdId = (int)$maxOrdStmt->fetchColumn() + 1;
            $order_number = '' . str_pad($nextOrdId, 3, '0', STR_PAD_LEFT);

            $insOrd = $pdo->prepare("
                INSERT INTO orders (
                    order_number, zone_id, vehicle_id, shop_name, customer_name, location, 
                    phone, product_id, quantity, unit_price, discount, 
                    total_amount, status, is_zone_read, is_reorder, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, 0.00, 
                    ?, 'delivered', 1, 1, ?, NOW()
                )
            ");
            $insOrd->execute([
                $order_number, $zone_id, $assignedVehicleId > 0 ? $assignedVehicleId : null,
                $shopName, $customerName, $place, $phone,
                $primaryPid, $totalCases, $primaryRate,
                $totalAmount, $receiptDate
            ]);
            $order_id = (int)$pdo->lastInsertId();

            $insOi = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, price_at_purchase, is_extra, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            foreach ($items as $it) {
                $insOi->execute([
                    $order_id, (int)$it['product_id'], (int)$it['quantity'], 
                    (float)$it['rate'], !empty($it['is_extra']) ? 1 : 0, $receiptDate
                ]);
                adjustProductStock($pdo, (int)$it['product_id'], -(int)$it['quantity'], "Offline Direct Delivery #{$order_number}");
            }
        }

        // 2. Vehicle Storage Reconciliation
        if ($assignedVehicleId > 0) {
            $pdo->prepare("UPDATE vehicle_loads SET is_active = 0 WHERE order_id = ?")->execute([$order_id]);
            $pdo->prepare("UPDATE vehicles SET total_deliveries_done = total_deliveries_done + 1, updated_at = NOW() WHERE id = ?")->execute([$assignedVehicleId]);
        }

        // 3. Generate Official Sequential Bill Number
        $officialBillNumber = generateBillNumber($pdo);

        $notes = "Offline Sync [ID: {$offlineId}]";
        if (!empty($rec['notes'])) {
            $notes .= " | " . trim($rec['notes']);
        }

        // 4. Insert into receipts table
        $insReceipt = $pdo->prepare("
            INSERT INTO receipts (
                order_id, bill_number, delivered_quantity, total_amount, 
                cash_amount, credited_amount, due_amount, payment_type, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insReceipt->execute([
            $order_id, $officialBillNumber, $totalCases, $totalAmount,
            $cashAmount, $creditAmount, $dueAmount, $paymentType, $notes, $receiptDate
        ]);

        // 5. Insert into order_payments table
        $insPay = $pdo->prepare("
            INSERT INTO order_payments (
                order_id, cash_amount, credited_amount, due_amount, payment_type, created_at
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insPay->execute([
            $order_id, $cashAmount, $creditAmount, $dueAmount,
            strtolower(str_replace(' + ', '_', $paymentType)), $receiptDate
        ]);

        // 6. Update Shop Rewards
        updateShopRewardProgress($pdo, $shopName, $phone);

        $pdo->commit();

        $results[] = [
            'offline_id'    => $offlineId,
            'status'        => 'synced',
            'bill_number'   => $officialBillNumber,
            'order_id'      => $order_id
        ];
        $syncedCount++;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $results[] = [
            'offline_id' => $offlineId,
            'status'     => 'error',
            'error'      => $e->getMessage()
        ];
    }
}

echo json_encode([
    'success'      => true,
    'synced_count' => $syncedCount,
    'results'      => $results
]);
