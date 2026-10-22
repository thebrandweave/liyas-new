<?php
/**
 * /zone/orders/dashboard/vehicles/index.php
 * Zone Orders Portal - Fleet Vehicles & Vehicle Products CRUD Management Hub
 * Dedicated module under Zone Orders for tracking available vehicles, driver contacts,
 * and performing full CRUD operations on vehicle products/loads.
 */

require_once dirname(__DIR__, 2) . '/auth_helper.php';

// Enforce Zone Orders Authentication
requireZoneOrdersAuth();

// Optional activity logger
$logger_file = dirname(__DIR__, 4) . '/admin/includes/activity_logger.php';
if (file_exists($logger_file)) {
    require_once $logger_file;
}

date_default_timezone_set('Asia/Kolkata');

// --- FLASH MESSAGES ---
$success_msg = $_SESSION['success_message'] ?? null;
$error_msg   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- POST ACTIONS (CRUD for Vehicles & Vehicle Products) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // 1. ADD / LOAD PRODUCT(S) ONTO VEHICLE (Vehicle Product Create)
    if ($action === 'load_product') {
        $vId       = (int)($_POST['vehicle_id'] ?? 0);
        $zId       = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $notes     = trim($_POST['notes'] ?? 'Vehicle Buffer Stock');
        $shopName  = trim($_POST['shop_name'] ?? '');

        // Collect product items from POST (supports multi-product addition and legacy single)
        $itemsToLoad = [];
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $it) {
                $pId   = (int)($it['product_id'] ?? 0);
                $cases = (int)($it['cases'] ?? 0);
                if ($pId > 0 && $cases > 0) {
                    $itemsToLoad[] = ['product_id' => $pId, 'cases' => $cases];
                }
            }
        } elseif (!empty($_POST['product_ids']) && is_array($_POST['product_ids'])) {
            $casesArr = $_POST['product_cases'] ?? [];
            foreach ($_POST['product_ids'] as $idx => $rawPid) {
                $pId   = (int)$rawPid;
                $cases = (int)($casesArr[$idx] ?? 0);
                if ($pId > 0 && $cases > 0) {
                    $itemsToLoad[] = ['product_id' => $pId, 'cases' => $cases];
                }
            }
        } elseif (!empty($_POST['product_id'])) {
            $pId   = (int)$_POST['product_id'];
            $cases = (int)($_POST['cases'] ?? 0);
            if ($pId > 0 && $cases > 0) {
                $itemsToLoad[] = ['product_id' => $pId, 'cases' => $cases];
            }
        }

        if ($vId <= 0) {
            $_SESSION['error_message'] = "Please select a target delivery vehicle.";
        } elseif (empty($itemsToLoad)) {
            $_SESSION['error_message'] = "Please select at least one product with a valid number of cases (minimum 1 case).";
        } else {
            // Check vehicle exists
            $vStmt = $pdo->prepare("SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE id = ?");
            $vStmt->execute([$vId]);
            $vehInfo = $vStmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehInfo) {
                $_SESSION['error_message'] = "Target vehicle not found.";
            } else {
                $vehLabel = $vehInfo['vehicle_number'] . ($vehInfo['vehicle_name'] ? " (" . $vehInfo['vehicle_name'] . ")" : "");

                $ins = $pdo->prepare("
                    INSERT INTO vehicle_loads (vehicle_id, zone_id, product_id, order_id, cases_loaded, shop_name, is_active, notes, created_at)
                    VALUES (?, ?, ?, NULL, ?, ?, 1, ?, NOW())
                ");

                $loadedSummary = [];
                $totalCasesLoaded = 0;

                foreach ($itemsToLoad as $item) {
                    $pId   = $item['product_id'];
                    $cases = $item['cases'];

                    $pStmt = $pdo->prepare("SELECT product_id, COALESCE(product_name, name) as p_name FROM products WHERE product_id = ?");
                    $pStmt->execute([$pId]);
                    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                    $pName = $pRow ? $pRow['p_name'] : "Product #{$pId}";

                    $ins->execute([
                        $vId,
                        $zId,
                        $pId,
                        $cases,
                        $shopName ?: null,
                        $notes ?: 'Vehicle Storage'
                    ]);

                    $loadedSummary[] = "{$cases} cs of {$pName}";
                    $totalCasesLoaded += $cases;

                    if (function_exists('quickLog')) {
                        quickLog($pdo, 'create', 'vehicle_load', $vId, "Loaded {$cases} cs of {$pName} onto vehicle {$vehLabel}");
                    }
                }

                // Update vehicle status to loading if currently active
                $pdo->prepare("UPDATE vehicles SET status = 'loading', updated_at = NOW() WHERE id = ? AND status = 'active'")->execute([$vId]);

                $_SESSION['success_message'] = "Successfully loaded " . count($itemsToLoad) . " product(s) (total {$totalCasesLoaded} cases) onto vehicle " . htmlspecialchars($vehLabel) . " (" . implode(', ', $loadedSummary) . ")!";
            }
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: " . ($_SERVER['REQUEST_URI'] ?: ("index.php?zone=" . urlencode($redirectZone))));
        exit;
    }

    // 2. EDIT VEHICLE PRODUCT LOAD (Vehicle Product Update)
    if ($action === 'edit_product_load') {
        $loadId = (int)($_POST['load_id'] ?? 0);
        $cases  = (int)($_POST['cases'] ?? 0);
        $zId    = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $notes  = trim($_POST['notes'] ?? '');

        if ($loadId <= 0 || $cases <= 0) {
            $_SESSION['error_message'] = "Please provide a valid load entry and positive case count.";
        } else {
            $chk = $pdo->prepare("SELECT id, vehicle_id, product_id FROM vehicle_loads WHERE id = ? AND is_active = 1");
            $chk->execute([$loadId]);
            $loadRow = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$loadRow) {
                $_SESSION['error_message'] = "Active vehicle product load entry not found.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE vehicle_loads 
                    SET cases_loaded = ?, zone_id = ?, notes = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $upd->execute([$cases, $zId, $notes, $loadId]);

                if (function_exists('quickLog')) {
                    quickLog($pdo, 'update', 'vehicle_load', $loadRow['vehicle_id'], "Updated vehicle load #{$loadId} to {$cases} cases");
                }
                $_SESSION['success_message'] = "Vehicle product load updated to {$cases} cases.";
            }
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 3. DELETE / UNLOAD VEHICLE PRODUCT (Vehicle Product Delete)
    if ($action === 'delete_product_load') {
        $loadId = (int)($_POST['load_id'] ?? 0);

        if ($loadId <= 0) {
            $_SESSION['error_message'] = "Invalid vehicle product load ID.";
        } else {
            $chk = $pdo->prepare("
                SELECT vl.id, vl.vehicle_id, vl.cases_loaded, COALESCE(p.product_name, p.name) as p_name 
                FROM vehicle_loads vl
                LEFT JOIN products p ON vl.product_id = p.product_id
                WHERE vl.id = ?
            ");
            $chk->execute([$loadId]);
            $loadRow = $chk->fetch(PDO::FETCH_ASSOC);

            if ($loadRow) {
                // Deactivate load entry
                $del = $pdo->prepare("UPDATE vehicle_loads SET is_active = 0, updated_at = NOW() WHERE id = ?");
                $del->execute([$loadId]);

                if (function_exists('quickLog')) {
                    quickLog($pdo, 'delete', 'vehicle_load', $loadRow['vehicle_id'], "Unloaded {$loadRow['cases_loaded']} cs of {$loadRow['p_name']} from vehicle #{$loadRow['vehicle_id']}");
                }
                $_SESSION['success_message'] = "Successfully removed " . htmlspecialchars($loadRow['p_name'] ?: 'product') . " ({$loadRow['cases_loaded']} cs) from vehicle.";
            } else {
                $_SESSION['error_message'] = "Product load entry not found.";
            }
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 4. ADD NEW VEHICLE (Vehicle Create)
    if ($action === 'add_vehicle') {
        $vNum       = strtoupper(trim($_POST['vehicle_number'] ?? ''));
        $vName      = trim($_POST['vehicle_name'] ?? '');
        $driverName = trim($_POST['driver_name'] ?? '');
        $phone      = trim($_POST['driver_phone'] ?? '');
        $zId        = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $capacity   = !empty($_POST['capacity_cases']) ? (int)$_POST['capacity_cases'] : 100;
        $status     = $_POST['status'] ?? 'active';

        if (empty($vNum)) {
            $_SESSION['error_message'] = "Vehicle Number / License Plate is required.";
        } else {
            $chk = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? LIMIT 1");
            $chk->execute([$vNum]);
            if ($chk->fetch()) {
                $_SESSION['error_message'] = "Vehicle plate '{$vNum}' is already registered in fleet.";
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO vehicles (vehicle_number, vehicle_name, driver_name, driver_phone, zone_id, capacity_cases, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $ins->execute([$vNum, $vName, $driverName, $phone, $zId, $capacity, $status]);
                $newVId = (int)$pdo->lastInsertId();

                if (function_exists('quickLog')) {
                    quickLog($pdo, 'create', 'vehicle', $newVId, "Registered vehicle {$vNum} via Zone Portal");
                }
                $_SESSION['success_message'] = "Vehicle {$vNum} registered successfully!";
            }
        }
        header("Location: index.php");
        exit;
    }

    // 5. EDIT VEHICLE DETAILS (Vehicle Update)
    if ($action === 'edit_vehicle') {
        $vId        = (int)($_POST['vehicle_id'] ?? 0);
        $vNum       = strtoupper(trim($_POST['vehicle_number'] ?? ''));
        $vName      = trim($_POST['vehicle_name'] ?? '');
        $driverName = trim($_POST['driver_name'] ?? '');
        $phone      = trim($_POST['driver_phone'] ?? '');
        $zId        = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $status     = $_POST['status'] ?? 'active';

        if ($vId <= 0 || empty($vNum)) {
            $_SESSION['error_message'] = "Vehicle ID and Plate Number are required.";
        } else {
            $chk = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? AND id != ? LIMIT 1");
            $chk->execute([$vNum, $vId]);
            if ($chk->fetch()) {
                $_SESSION['error_message'] = "Vehicle number '{$vNum}' is already in use by another vehicle.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE vehicles 
                    SET vehicle_number = ?, vehicle_name = ?, driver_name = ?, driver_phone = ?, zone_id = ?, status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $upd->execute([$vNum, $vName, $driverName, $phone, $zId, $status, $vId]);

                if (function_exists('quickLog')) {
                    quickLog($pdo, 'update', 'vehicle', $vId, "Updated vehicle details for {$vNum}");
                }
                $_SESSION['success_message'] = "Vehicle {$vNum} details updated successfully.";
            }
        }
        header("Location: index.php");
        exit;
    }

    // 6. RESET VEHICLE LOAD (Trip Reset)
    if ($action === 'reset_vehicle_load') {
        $vId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vId > 0) {
            resetVehicleLoadRecord($pdo, $vId);
            if (function_exists('quickLog')) {
                quickLog($pdo, 'update', 'vehicle', $vId, "Reset active load for vehicle #{$vId} via Zone Portal");
            }
            $_SESSION['success_message'] = "Current vehicle load cleared for next trip. Delivery history preserved.";
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 7. UNASSIGN ORDER FROM VEHICLE
    if ($action === 'unassign_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId > 0 && function_exists('unassignOrderFromVehicle')) {
            unassignOrderFromVehicle($pdo, $orderId);
            $_SESSION['success_message'] = "Order #{$orderId} unassigned from vehicle.";
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 8. ASSIGN ORDER TO VEHICLE
    if ($action === 'assign_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $vId = (int)($_POST['vehicle_id'] ?? 0);
        if ($orderId > 0 && $vId > 0 && function_exists('assignOrderToVehicle')) {
            $res = assignOrderToVehicle($pdo, $orderId, $vId);
            if ($res['success']) {
                $_SESSION['success_message'] = $res['message'] ?? "Order assigned to vehicle.";
            } else {
                $_SESSION['error_message'] = $res['error'] ?? "Failed to assign order.";
            }
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 9. DELETE VEHICLE (Vehicle Delete)
    if ($action === 'delete_vehicle') {
        $vId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vId > 0) {
            $vStmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
            $vStmt->execute([$vId]);
            $vNum = $vStmt->fetchColumn() ?: "#{$vId}";

            // 1. Clear vehicle buffer loads
            $pdo->prepare("DELETE FROM vehicle_loads WHERE vehicle_id = ?")->execute([$vId]);
            // 2. Unassign any assigned orders so they remain safe in unassigned pool
            $pdo->prepare("UPDATE orders SET vehicle_id = NULL WHERE vehicle_id = ?")->execute([$vId]);
            // 3. Delete vehicle from vehicles table
            $pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$vId]);

            if (function_exists('quickLog')) {
                quickLog($pdo, 'delete', 'vehicle', $vId, "Deleted vehicle {$vNum} via Zone Portal");
            }
            $_SESSION['success_message'] = "Vehicle {$vNum} successfully deleted from fleet.";
        } else {
            $_SESSION['error_message'] = "Invalid vehicle ID for deletion.";
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }
}

// --- FETCH DATA FOR DASHBOARD ---
$filterZone   = trim($_GET['zone'] ?? 'all');
$searchQuery  = trim($_GET['search'] ?? '');

// 1. Fetch all zones
$zonesStmt = $pdo->query("SELECT id, name, slug FROM zones ORDER BY name ASC");
$zonesList = $zonesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Resolve Back-to-Particular-Zone URL and Name
$returnUrl = trim($_GET['return_url'] ?? '');
$zoneBackUrl = '';
$zoneBackName = '';

if (!empty($returnUrl)) {
    // Validate return_url to prevent external redirects
    $isSafe = false;
    if (strpos($returnUrl, 'http://') === 0 || strpos($returnUrl, 'https://') === 0) {
        $pHost = parse_url($returnUrl, PHP_URL_HOST);
        $bHost = parse_url(BASE_URL, PHP_URL_HOST);
        if ($pHost === $bHost) {
            $isSafe = true;
        }
    } elseif (strpos($returnUrl, '/') === 0) {
        $isSafe = true;
    }
    if ($isSafe) {
        $zoneBackUrl = $returnUrl;
    }
}

if (empty($zoneBackUrl)) {
    $targetZoneSlug = '';
    if (!empty($filterZone) && $filterZone !== 'all') {
        foreach ($zonesList as $z) {
            if ($filterZone === (string)$z['id'] || strtolower($filterZone) === strtolower($z['slug'])) {
                $targetZoneSlug = $z['slug'];
                $zoneBackName = $z['name'];
                break;
            }
        }
    }
    if (empty($targetZoneSlug) && !empty($_SESSION['delivery_zone_id'])) {
        foreach ($zonesList as $z) {
            if ((int)$_SESSION['delivery_zone_id'] === (int)$z['id']) {
                $targetZoneSlug = $z['slug'];
                $zoneBackName = $z['name'];
                break;
            }
        }
    }
    if (empty($targetZoneSlug)) {
        $targetZoneSlug = !empty($zonesList[0]['slug']) ? $zonesList[0]['slug'] : 'mangalore';
        $zoneBackName = !empty($zonesList[0]['name']) ? $zonesList[0]['name'] : 'Mangalore';
    }
    $zoneBackUrl = BASE_URL . '/' . $targetZoneSlug;
}

if (empty($zoneBackName)) {
    foreach ($zonesList as $z) {
        if (strpos($zoneBackUrl, '/' . $z['slug']) !== false) {
            $zoneBackName = $z['name'];
            break;
        }
    }
    if (empty($zoneBackName)) {
        $zoneBackName = 'Zone';
    }
}
$zoneBackName = ucfirst($zoneBackName);

// 2. Fetch catalog products for Add Product modal with live warehouse available stock
$catalogProducts = [];
try {
    $catalogProducts = $pdo->query("
        SELECT 
            p.product_id, 
            p.name, 
            p.product_name, 
            p.case_price, 
            p.price, 
            p.net_content, 
            p.net_content_unit, 
            p.case_stock, 
            p.stock,
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
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($catalogProducts as &$cp) {
        $cStock = (int)($cp['case_stock'] !== null ? $cp['case_stock'] : ($cp['stock'] ?? 0));
        $loadedVehicle = (int)($cp['cases_loaded_vehicle'] ?? 0);
        $inTransit = (int)($cp['cases_in_transit'] ?? 0);
        $availStock = max(0, $cStock - $loadedVehicle);
        $cp['raw_stock'] = $cStock;
        $cp['cases_loaded_vehicle'] = $loadedVehicle;
        $cp['cases_in_transit'] = $inTransit;
        $cp['case_stock'] = $availStock;
        $cp['stock'] = $availStock;
        $cp['available_stock'] = $availStock;
    }
    unset($cp);
} catch (Exception $e) {}

// 2b. Fetch all active vehicles in fleet for modal vehicle selector
$allFleetVehicles = $pdo->query("
    SELECT 
        v.id, 
        v.vehicle_number, 
        v.vehicle_name, 
        v.driver_name,
        v.capacity_cases, 
        v.status,
        COALESCE((
            SELECT SUM(vl.cases_loaded) 
            FROM vehicle_loads vl 
            WHERE vl.vehicle_id = v.id AND vl.is_active = 1
        ), 0) AS total_cases_loaded
    FROM vehicles v 
    WHERE v.status != 'deleted' 
    ORDER BY v.vehicle_number ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 3. Fetch active unassigned orders for Assign Order modal
$unassignedOrdersStmt = $pdo->query("
    SELECT 
        o.order_id, 
        o.order_number, 
        o.shop_name, 
        o.customer_name, 
        o.total_amount,
        COALESCE((SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id), o.quantity, 1) as total_cases,
        z.name as zone_name
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    WHERE (o.vehicle_id IS NULL OR o.vehicle_id = 0)
      AND o.status NOT IN ('delivered', 'cancelled')
    ORDER BY o.created_at DESC
");
$unassignedOrdersList = $unassignedOrdersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 4. Fetch Vehicles with Detailed Load Information
$whereZoneClause = "";
$queryParams = [];
if ($filterZone !== 'all') {
    // If integer zone ID
    if (is_numeric($filterZone)) {
        $whereZoneClause = "AND (v.zone_id = :zid OR v.id IN (SELECT vehicle_id FROM vehicle_loads WHERE zone_id = :zid2 AND is_active = 1))";
        $queryParams[':zid'] = (int)$filterZone;
        $queryParams[':zid2'] = (int)$filterZone;
    } else {
        $zFind = $pdo->prepare("SELECT id FROM zones WHERE slug = ? LIMIT 1");
        $zFind->execute([$filterZone]);
        $foundZid = (int)$zFind->fetchColumn();
        if ($foundZid > 0) {
            $whereZoneClause = "AND (v.zone_id = :zid OR v.id IN (SELECT vehicle_id FROM vehicle_loads WHERE zone_id = :zid2 AND is_active = 1))";
            $queryParams[':zid'] = $foundZid;
            $queryParams[':zid2'] = $foundZid;
        }
    }
}

$vehSql = "
    SELECT 
        v.*,
        z.name as home_zone_name,
        z.slug as home_zone_slug
    FROM vehicles v
    LEFT JOIN zones z ON v.zone_id = z.id
    WHERE v.status != 'deleted' $whereZoneClause
    ORDER BY (v.status != 'inactive') DESC, v.vehicle_number ASC
";
$vStmt = $pdo->prepare($vehSql);
$vStmt->execute($queryParams);
$vehiclesRaw = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Fetch detailed product loads & assigned client orders for each vehicle
$vehicles = [];
$totalFleetVehicles = count($vehiclesRaw);
$activeVehiclesCount = 0;
$totalFleetCasesLoaded = 0;

foreach ($vehiclesRaw as $veh) {
    $vId = (int)$veh['id'];

    // 1. Fetch Assigned Client Orders for this vehicle with detailed item breakdown
    $assignedOrdersStmt = $pdo->prepare("
        SELECT 
            o.order_id,
            o.order_number,
            o.shop_name,
            o.customer_name,
            o.phone,
            o.location,
            o.address,
            o.status,
            o.total_amount,
            o.created_at,
            z.name as zone_name,
            z.slug as zone_slug
        FROM orders o
        LEFT JOIN zones z ON o.zone_id = z.id
        WHERE o.vehicle_id = ? 
          AND o.status NOT IN ('delivered', 'cancelled')
        ORDER BY o.created_at DESC
    ");
    $assignedOrdersStmt->execute([$vId]);
    $assignedOrders = $assignedOrdersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $assignedOrdersCases = 0;
    foreach ($assignedOrders as &$ord) {
        $oId = (int)$ord['order_id'];
        $itemsStmt = $pdo->prepare("
            SELECT 
                oi.order_item_id,
                oi.product_id,
                oi.quantity,
                oi.price_at_purchase,
                oi.is_extra,
                COALESCE(p.product_name, p.name, CONCAT('Product #', oi.product_id)) as product_name,
                p.net_content,
                p.net_content_unit,
                COALESCE(p.case_price, p.price, 0) as catalog_price
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = ?
            ORDER BY oi.order_item_id ASC
        ");
        $itemsStmt->execute([$oId]);
        $ordItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fallback if order_items has no rows
        if (empty($ordItems)) {
            $fbStmt = $pdo->prepare("
                SELECT 
                    o.product_id,
                    o.quantity,
                    o.unit_price as price_at_purchase,
                    0 as is_extra,
                    COALESCE(p.product_name, p.name) as product_name,
                    p.net_content,
                    p.net_content_unit,
                    COALESCE(p.case_price, p.price, 0) as catalog_price
                FROM orders o
                LEFT JOIN products p ON o.product_id = p.product_id
                WHERE o.order_id = ? AND o.product_id IS NOT NULL AND o.product_id > 0
            ");
            $fbStmt->execute([$oId]);
            $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
            if ($fbRow && (int)$fbRow['quantity'] > 0) {
                $ordItems[] = $fbRow;
            }
        }

        $ordCases = 0;
        foreach ($ordItems as $oi) {
            $ordCases += (int)$oi['quantity'];
        }
        $ord['items'] = $ordItems;
        $ord['total_cases'] = $ordCases;
        $assignedOrdersCases += $ordCases;
    }
    unset($ord);

    // 2. Fetch direct line-sale / storage buffer loads (where order_id IS NULL)
    $loadRowsStmt = $pdo->prepare("
        SELECT 
            vl.id as load_id,
            vl.vehicle_id,
            vl.zone_id,
            vl.product_id,
            vl.order_id,
            vl.cases_loaded,
            vl.shop_name,
            vl.notes,
            vl.created_at,
            COALESCE(p.product_name, p.name, CONCAT('Product #', vl.product_id)) AS product_name,
            COALESCE(p.case_price, p.price, 0) AS case_price,
            COALESCE(p.case_stock, p.stock, 0) AS case_stock,
            p.net_content,
            p.net_content_unit,
            z.name AS zone_name
        FROM vehicle_loads vl
        LEFT JOIN products p ON vl.product_id = p.product_id
        LEFT JOIN zones z ON vl.zone_id = z.id
        WHERE vl.vehicle_id = ? 
          AND vl.is_active = 1 
          AND vl.product_id IS NOT NULL 
          AND vl.order_id IS NULL
        ORDER BY vl.created_at DESC
    ");
    $loadRowsStmt->execute([$vId]);
    $individualLoads = $loadRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bufferCases = 0;
    foreach ($individualLoads as $il) {
        $bufferCases += (int)$il['cases_loaded'];
    }

    // 3. Total cargo on vehicle = Assigned Client Orders Cases + Direct Line-Sale Cases
    $totalVehicleCases = $assignedOrdersCases + $bufferCases;
    $totalFleetCasesLoaded += $totalVehicleCases;

    if ($totalVehicleCases > 0 || $veh['status'] === 'active' || $veh['status'] === 'loading') {
        $activeVehiclesCount++;
    }

    // 4. Combined Product Cargo Breakdown (Orders + Line-Sale buffer)
    $combinedCargo = [];
    foreach ($individualLoads as $bl) {
        $pid = (int)($bl['product_id'] ?? 0);
        $pName = $bl['product_name'];
        $qty = (int)$bl['cases_loaded'];
        if (!isset($combinedCargo[$pid])) {
            $combinedCargo[$pid] = [
                'product_id' => $pid,
                'name' => $pName,
                'total_cases' => 0,
                'order_cases' => 0,
                'buffer_cases' => 0,
                'net_content' => $bl['net_content'],
                'net_content_unit' => $bl['net_content_unit']
            ];
        }
        $combinedCargo[$pid]['total_cases'] += $qty;
        $combinedCargo[$pid]['buffer_cases'] += $qty;
    }
    foreach ($assignedOrders as $ao) {
        foreach ($ao['items'] as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $pName = $it['product_name'];
            $qty = (int)$it['quantity'];
            if (!isset($combinedCargo[$pid])) {
                $combinedCargo[$pid] = [
                    'product_id' => $pid,
                    'name' => $pName,
                    'total_cases' => 0,
                    'order_cases' => 0,
                    'buffer_cases' => 0,
                    'net_content' => $it['net_content'] ?? '',
                    'net_content_unit' => $it['net_content_unit'] ?? ''
                ];
            }
            $combinedCargo[$pid]['total_cases'] += $qty;
            $combinedCargo[$pid]['order_cases'] += $qty;
        }
    }

    $veh['total_cases_loaded']   = $totalVehicleCases;
    $veh['assigned_order_cases'] = $assignedOrdersCases;
    $veh['buffer_cases']         = $bufferCases;
    $veh['assigned_orders']      = $assignedOrders;
    $veh['individual_loads']     = $individualLoads;
    $veh['combined_cargo']       = array_values($combinedCargo);
    $vehicles[] = $veh;
}

// Search Filter
if (!empty($searchQuery)) {
    $sq = strtolower($searchQuery);
    $vehicles = array_filter($vehicles, function($v) use ($sq) {
        return (
            strpos(strtolower($v['vehicle_number'] ?? ''), $sq) !== false ||
            strpos(strtolower($v['vehicle_name'] ?? ''), $sq) !== false ||
            strpos(strtolower($v['driver_name'] ?? ''), $sq) !== false ||
            strpos(strtolower($v['driver_phone'] ?? ''), $sq) !== false ||
            strpos(strtolower($v['home_zone_name'] ?? ''), $sq) !== false
        );
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone Vehicles &amp; Product Loads - Liyas International</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>/assets/images/logo/logo-bg.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --primary-border: #bfdbfe;
            --success: #059669;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --warning: #d97706;
            --warning-bg: #fffbeb;
            --warning-border: #fde68a;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
            --shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
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
            min-height: 100vh;
            padding-bottom: 80px;
        }

        /* Top Header */
        .portal-header {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }

        .header-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            object-fit: cover;
            border: 1.5px solid var(--border-color);
        }

        .brand-title h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-title p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .btn-back-zone {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            color: #1e40af;
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(37,99,235,0.06);
            white-space: nowrap;
        }

        .btn-back-zone:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            transform: translateX(-2px);
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
        }

        .btn-back-zone i {
            font-size: 18px;
            transition: transform 0.2s ease;
        }

        .btn-back-zone:hover i {
            transform: translateX(-3px);
        }

        .btn-header {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-sub);
            background: #ffffff;
            border: 1.5px solid var(--border-color);
            transition: all 0.15s ease;
            cursor: pointer;
        }

        .btn-header:hover {
            background: #f1f5f9;
            color: var(--text-main);
            border-color: #cbd5e1;
        }

        .btn-header-primary {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 700;
        }

        .btn-header-primary:hover {
            background: var(--primary-dark);
            color: #ffffff;
            border-color: var(--primary-dark);
        }

        .btn-header-success {
            background: var(--success);
            color: #ffffff;
            border-color: var(--success);
            font-weight: 700;
        }

        .btn-header-success:hover {
            background: #047857;
            color: #ffffff;
        }

        /* Container */
        .portal-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.2s ease;
        }
        .alert-success { background: var(--success-bg); color: #065f46; border: 1px solid var(--success-border); }
        .alert-error { background: var(--danger-bg); color: #991b1b; border: 1px solid var(--danger-border); }

        /* Metrics Summary Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
        }
        .m-blue::before { background: var(--primary); }
        .m-green::before { background: var(--success); }
        .m-amber::before { background: var(--warning); }

        .metric-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .metric-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
        }

        .metric-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .m-blue .metric-icon-box { background: var(--primary-light); color: var(--primary); }
        .m-green .metric-icon-box { background: var(--success-bg); color: var(--success); }
        .m-amber .metric-icon-box { background: var(--warning-bg); color: var(--warning); }

        .metric-value {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.1;
            color: var(--text-main);
        }

        .metric-desc {
            font-size: 12px;
            color: var(--text-sub);
            margin-top: 4px;
        }

        /* Toolbar & Filter Bar */
        .toolbar-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 14px 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: var(--shadow-card);
        }

        .zone-tabs-list {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .zone-tab-pill {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-sub);
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .zone-tab-pill:hover {
            background: #e2e8f0;
            color: var(--text-main);
        }

        .zone-tab-pill.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            font-weight: 700;
        }

        .search-box-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box-wrap input {
            padding: 7px 12px 7px 32px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            width: 220px;
            background: #f8fafc;
            transition: all 0.15s ease;
        }

        .search-box-wrap input:focus {
            background: #ffffff;
            border-color: var(--primary);
            width: 260px;
        }

        .search-box-wrap i {
            position: absolute;
            left: 10px;
            color: var(--text-muted);
            font-size: 16px;
        }

        /* Fleet Cards Grid */
        .fleet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 20px;
        }

        .vehicle-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            box-shadow: var(--shadow-card);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            border-color: #cbd5e1;
        }

        /* Vehicle Card Top Header */
        .veh-top-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .plate-badge {
            background: #0f172a;
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
            padding: 5px 12px;
            border-radius: 8px;
            letter-spacing: 0.04em;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .veh-model-title {
            font-size: 14px;
            font-weight: 700;
            color: white;
            /* margin-top: 4px; */
        }

        .veh-home-zone-chip {
            font-size: 11px;
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-top: 6px;
        }

        /* Status Badge */
        .status-badge {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .st-active { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .st-loading { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .st-dispatched { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .st-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* Driver Contact Bar */
        .driver-box {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 10px 6px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
        }

        .driver-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .driver-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-driver-call {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-driver-call:hover { background: #dbeafe; }

        .btn-driver-wa {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-driver-wa:hover { background: #d1fae5; }

        /* Load Overview Pill Box */
        .load-summary-bar {
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .load-total-num {
            font-size: 20px;
            font-weight: 800;
            color: #1e40af;
            line-height: 1;
        }

        .load-total-sub {
            font-size: 11px;
            font-weight: 700;
            /* text-transform: uppercase; */
            color: #3b82f6;
            margin-top: 2px;
        }

        .btn-load-prod-plus {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 2px 4px rgba(37,99,235,0.2);
            transition: all 0.15s ease;
        }
        .btn-load-prod-plus:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        /* Products Table inside Vehicle Card */
        .veh-products-section {
            margin-bottom: 16px;
        }

        .veh-prods-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.04em;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .veh-prods-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .veh-prod-row {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            transition: all 0.15s ease;
        }

        .veh-prod-row:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .veh-prod-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 0;
        }

        .prod-tag-icon {
            width: 28px;
            height: 28px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .prod-name-text {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .prod-cases-badge {
            background: #dbeafe;
            color: #1e40af;
            font-size: 12px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .prod-crud-btns {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-crud-mini {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: var(--text-sub);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-crud-mini:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }
        .btn-crud-edit:hover {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }
        .btn-crud-del:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        /* Empty Product List */
        .empty-prods-msg {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 14px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Card Bottom Actions */
        .veh-card-footer {
            border-top: 1px solid #f1f5f9;
            padding-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .btn-footer-action {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-sub);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }

        .btn-footer-action:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }

        /* Modal Styles */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(3px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-backdrop.open {
            display: flex;
            animation: fadeIn 0.15s ease;
        }

        .modal-dialog {
            background: #ffffff;
            border-radius: 16px;
            max-width: 480px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
        }
        .modal-dialog.modal-dialog-lg {
            max-width: 760px;
            width: 95%;
            max-height: 92vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
        }

        .modal-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            font-size: 20px;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover { background: #e2e8f0; color: #0f172a; }

        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-sub);
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .modal-btns-row {
            display: flex;
            gap: 8px;
            margin-top: 20px;
        }
        .modal-btns-row button {
            flex: 1;
            padding: 10px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Header actions and compact fleet-stats hamburger menu */
        .header-inner { position: relative; }
        .header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        .stats-menu-wrap { position: relative; }
        .stats-menu-toggle {
            min-width: 42px;
            min-height: 40px;
            justify-content: center;
            padding: 8px 11px;
            font-size: 20px;
        }
        .stats-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: min(360px, calc(100vw - 24px));
            padding: 12px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
            z-index: 300;
        }
        .stats-dropdown.open { display: block; }
        .stats-dropdown-title {
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 2px 4px 10px;
        }
        .stats-dropdown .metrics-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin: 0;
        }
        .stats-dropdown .metric-card { padding: 12px 14px; border-radius: 10px; }
        .stats-dropdown .metric-top { margin-bottom: 5px; }
        .stats-dropdown .metric-icon-box { width: 30px; height: 30px; font-size: 17px; }
        .stats-dropdown .metric-value { font-size: 24px; }
        .stats-dropdown .metric-desc { font-size: 11px; }

        /* Responsive layout safeguards */
        img, video, iframe { max-width: 100%; }
        input, select, textarea, button { max-width: 100%; }
        .portal-container { min-width: 0; }
        .fleet-grid { grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr)); }
        .vehicle-card { min-width: 0; }
        .veh-card-footer, .driver-box { flex-wrap: wrap; }
        .veh-products-section { min-width: 0; }
        .veh-prod-row { min-width: 0; }
        .modal-backdrop { overflow-y: auto; }
        .modal-dialog { max-height: calc(100dvh - 32px); overflow-y: auto; }
        .modal-dialog.modal-dialog-lg { width: 100%; }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        @media (max-width: 768px) {
            body { padding-bottom: 24px; }
            .header-inner { flex-direction: row; align-items: center; flex-wrap: wrap; padding: 10px 12px; gap: 10px; }
            .header-inner > div:first-child { flex: 1 1 auto; min-width: 0; gap: 8px !important; }
            .brand-title h1 { font-size: 15px; }
            .brand-title p { display: none; }
            .brand-logo { width: 34px; height: 34px; }
            .btn-back-zone { padding: 7px 9px; font-size: 12px; }
            .header-actions { flex: 1 0 100%; justify-content: space-between; gap: 6px; }
            .header-actions > a, .header-actions > button { flex: 1 1 auto; justify-content: center; padding: 8px 9px; font-size: 12px; }
            .stats-menu-wrap { flex: 0 0 auto; }
            .stats-menu-toggle { flex: 0 0 42px !important; }
            .stats-dropdown { right: 0; }
            .portal-container { padding: 12px; }
            .metrics-grid { grid-template-columns: 1fr; }
            .fleet-grid { grid-template-columns: minmax(0, 1fr); gap: 12px; }
            .vehicle-card { padding: 14px; }
            .veh-top-header { flex-wrap: wrap; }
            .plate-badge { font-size: 12px; }
            .driver-box { align-items: flex-start; gap: 8px; }
            .driver-info { min-width: 0; flex-wrap: wrap; }
            .driver-actions { flex-wrap: wrap; }
            .load-summary-bar { gap: 10px; padding: 10px; }
            .veh-prod-row { padding: 8px; gap: 6px; }
            .prod-name-text { white-space: normal; overflow-wrap: anywhere; }
            .veh-card-footer { align-items: stretch; }
            .veh-card-footer > * { flex: 1 1 auto; justify-content: center; }
            .toolbar-card { flex-direction: column; align-items: stretch; padding: 12px; }
            .zone-tabs-list { flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; }
            .zone-tab-pill { flex: 0 0 auto; }
            .search-box-wrap, .search-box-wrap input { width: 100%; }
            .search-box-wrap input:focus { width: 100%; }
            .modal-backdrop { padding: 8px; align-items: flex-start; }
            .modal-dialog, .modal-dialog.modal-dialog-lg { width: 100%; max-width: 100%; padding: 16px; margin: 8px 0; max-height: calc(100dvh - 32px); }
            .grid-2 { grid-template-columns: minmax(0, 1fr); }
            #aopItemsTableBody, #aopItemsTableBody tr, #aopItemsTableBody td { max-width: 100%; }
        }
        @media (max-width: 420px) {
            .brand-title h1 { font-size: 14px; }
            .btn-back-zone span { display: none; }
            .header-actions > a span, .header-actions > button span { font-size: 11px; }
            .header-actions > a, .header-actions > button { gap: 4px; padding: 8px 6px; }
            .metric-label { font-size: 11px; }
            .veh-card-footer { flex-direction: column; }
            .modal-btns-row { flex-direction: column; }
        }

        
    </style>
</head>
<body>

    <!-- Sticky Header Navigation -->
    <header class="portal-header">
        <div class="header-inner">
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <a href="<?= htmlspecialchars($zoneBackUrl) ?>" class="btn-back-zone" title="Back to <?= htmlspecialchars($zoneBackName) ?>">
                    <i class='bx bx-arrow-back'></i>
                    <span>Back</span>
                </a>
                <div class="brand-title">
                    <h1>Vehicle Management</h1>
                </div>
            </div>

            <div class="header-actions">
               
                <a href="<?= BASE_URL ?>/zone/orders/dashboard/products/" class="btn-header" title="Catalog & Inventory">
                    <i class='bx bx-cube' style="color: #059669;"></i>
                    <span>Products</span>
                </a>
                <button type="button" class="btn-header btn-header-success" onclick="openLoadProductModal()" title="Load products onto a vehicle">
                    <i class='bx bx-plus-circle'></i>
                    <span> Load Product</span>
                </button>
                <button type="button" class="btn-header btn-header-primary" onclick="openAddVehicleModal()" title="Register a new delivery vehicle">
                    <i class='bx bxs-truck'></i>
                    <span> Add Vehicle</span>
                </button>
                <div class="stats-menu-wrap">
                <button type="button" class="btn-header stats-menu-toggle" id="statsMenuToggle"
                        aria-label="Open fleet statistics" aria-expanded="false" aria-controls="statsDropdown"
                        title="Fleet statistics">
                    <i class='bx bx-menu'></i>
                </button>
                <div class="stats-dropdown" id="statsDropdown" role="region" aria-label="Fleet statistics">
                    <div class="stats-dropdown-title"><i class='bx bx-bar-chart-alt-2'></i> Fleet Overview</div>
                    <section class="metrics-grid">
            <div class="metric-card m-blue">
                <div class="metric-top">
                    <span class="metric-label">Total Vehicles</span>
                    <div class="metric-icon-box"><i class='bx bxs-truck'></i></div>
                </div>
                <div class="metric-value"><?= (int)$totalFleetVehicles ?></div>
                <div class="metric-desc">Registered delivery vehicles</div>
            </div>

            <div class="metric-card m-green">
                <div class="metric-top">
                    <span class="metric-label">Active on Road / Yard</span>
                    <div class="metric-icon-box"><i class='bx bx-check-double'></i></div>
                </div>
                <div class="metric-value"><?= (int)$activeVehiclesCount ?></div>
                <div class="metric-desc">Vehicles loaded or ready</div>
            </div>

            <div class="metric-card m-amber">
                <div class="metric-top">
                    <span class="metric-label">Total Cases Loaded</span>
                    <div class="metric-icon-box"><i class='bx bx-package'></i></div>
                </div>
                <div class="metric-value"><?= number_format($totalFleetCasesLoaded) ?> <span style="font-size: 16px;">cs</span></div>
                <div class="metric-desc">Current on-road linesale load</div>
            </div>
                            </section>
                </div>
                </div>
            </div>
        </div>
    </header>

    <main class="portal-container">
        <!-- Flash Messages -->
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle' style="font-size: 20px;"></i>
                <span><?= htmlspecialchars($success_msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
        <?php endif; ?>

     

        <!-- 3. Vehicles Grid & Vehicle Products Management -->
        <?php if (empty($vehicles)): ?>
            <div style="background: #ffffff; border: 1.5px dashed #cbd5e1; border-radius: 16px; padding: 60px 20px; text-align: center; color: var(--text-muted);">
                <i class='bx bxs-truck' style="font-size: 54px; color: #cbd5e1; margin-bottom: 12px;"></i>
                <h3 style="font-size: 18px; font-weight: 800; color: var(--text-main); margin-bottom: 6px;">No Vehicles Found</h3>
                <p style="font-size: 13px; max-width: 440px; margin: 0 auto 16px auto;">
                    <?= !empty($searchQuery) ? 'No vehicles match your search filter.' : 'No vehicles found in this zone. Click below to add a vehicle to the fleet.' ?>
                </p>
                <button type="button" class="btn-header btn-header-primary" onclick="openAddVehicleModal()">
                    <i class='bx bx-plus-circle'></i> Add First Vehicle
                </button>
            </div>
        <?php else: ?>
            <div class="fleet-grid">
                <?php foreach ($vehicles as $v): ?>
                    <?php
                        $vId         = (int)$v['id'];
                        $vNum        = htmlspecialchars($v['vehicle_number']);
                        $vName       = htmlspecialchars($v['vehicle_name'] ?: 'Delivery Vehicle');
                        $driverName  = htmlspecialchars($v['driver_name'] ?: 'Unassigned Driver');
                        $driverPhone = htmlspecialchars($v['driver_phone'] ?: '');
                        $homeZone    = htmlspecialchars($v['home_zone_name'] ?: 'Central Warehouse');
                        $status      = $v['status'] ?: 'active';
                        $totalCases  = (int)$v['total_cases_loaded'];
                        $loads       = $v['individual_loads'] ?? [];
                        $prodsSummary= $v['loaded_products'] ?? [];

                        // Status badge style
                        $stClass = 'st-active';
                        $stLabel = 'Ready in Yard';
                        if ($status === 'loading') {
                            $stClass = 'st-loading';
                            $stLabel = 'Loading';
                        } elseif ($status === 'dispatched') {
                            $stClass = 'st-dispatched';
                            $stLabel = 'Dispatched';
                        } elseif ($status === 'inactive') {
                            $stClass = 'st-inactive';
                            $stLabel = 'Inactive';
                        }

                        // WhatsApp link for driver
                        $cleanPhone = preg_replace('/[^0-9]/', '', $driverPhone);
                        if (strlen($cleanPhone) === 10) $cleanPhone = '91' . $cleanPhone;
                        $waLink = !empty($cleanPhone) ? "https://wa.me/{$cleanPhone}?text=" . rawurlencode("Hello {$driverName}, from Liyas Water Logistics regarding vehicle {$vNum}.") : '';
                    ?>
                    <article class="vehicle-card" id="card_veh_<?= $vId ?>">
                        <div>
                            <!-- Header: Vehicle Number & Status -->
                            <div class="veh-top-header">
                                <div>
                                    <div class="plate-badge">
                                        <i class='bx bxs-truck' style="color: #60a5fa;"></i>
                                        <span><?= $vNum ?></span> /
                                         <div class="veh-model-title"><?= $vName ?></div>
                                    </div>
                                   
                                   
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div class="veh-home-zone-chip">
                                        <i class='bx bx-map-pin' style="color: #ef4444;"></i> Zone: <strong><?= $homeZone ?></strong>
                                    </div>
                                   
                                </div>
                            </div>

                            <!-- Driver Contact Information -->
                            <div class="driver-box">
                                <div class="driver-info">
                                    <i class='bx bx-user' style="color: #2563eb; font-size: 16px;"></i>
                                    <div>
                                        <strong><?= $driverName ?></strong>
                                        <?php if (!empty($driverPhone)): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);"><?= $driverPhone ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="driver-actions">
                                    <?php if (!empty($driverPhone)): ?>
                                        <a href="tel:<?= $driverPhone ?>" class="btn-driver-call" title="Call Driver">
                                            <i class='bx bx-phone'></i> Call
                                        </a>
                                    
                                    <?php else: ?>
                                        <span style="font-size: 11px; color: var(--text-muted); font-style: italic;">No Phone</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php 
                                $capacityCases = (int)($v['capacity_cases'] ?? 100);
                                $assignedOrderCases = (int)($v['assigned_order_cases'] ?? 0);
                                $bufferCases = (int)($v['buffer_cases'] ?? 0);
                                $assignedOrders = $v['assigned_orders'] ?? [];
                                $combinedCargo = $v['combined_cargo'] ?? [];
                            ?>
                            <!-- Total Cases Loaded Bar & Quick Action -->
                            <div class="load-summary-bar">
                                <div>
                                    <div class="load-total-num">
                                        <?= $totalCases ?> <span style="font-size: 14px; font-weight: 700;">Cases</span>
                                        <?php if ($capacityCases > 0): ?>
                                            <span style="font-size: 12px; font-weight: 600; color: #64748b;"> </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="load-total-sub">
                                        <span style=" color: #1e40af; font-weight: 700;"><?= $assignedOrderCases ?> cs Orders</span>
                                        
                                     
                                    </div>
                                </div>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap;justify-content: flex-end;">
                                    
                                    <button type="button" class="btn-load-prod-plus" onclick="openLoadProductModal(<?= $vId ?>, '<?= htmlspecialchars(addslashes($vNum)) ?>')" title="Add line-sale product to this vehicle">
                                        <i class='bx bx-plus'></i> Load Product
                                    </button>
                                </div>
                            </div>

                            <!-- Total On-Board Combined Cargo Breakdown -->
                            <?php if (!empty($combinedCargo)): ?>
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; margin-bottom: 14px;">
                                    <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 6px; display: flex; align-items: center; gap: 4px;">
                                        <i class='bx bx-layer'></i> Vehicle Storage (<?= count($combinedCargo) ?> Products):
                                    </div>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php foreach ($combinedCargo as $cc): ?>
                                            <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 8px; font-size: 12px; display: flex; align-items: center; gap: 6px;">
                                                <strong style="color: #0f172a;"><?= htmlspecialchars($cc['name']) ?></strong>
                                                <span style="background: #eff6ff; color: #1e40af; font-weight: 800; padding: 1px 6px; border-radius: 4px; font-size: 11px;">
                                                    <?= (int)$cc['total_cases'] ?> cs
                                                </span>
                                                <?php if ($cc['order_cases'] > 0 && $cc['buffer_cases'] > 0): ?>
                                                    <small style="color: #64748b; font-size: 10px;">(<?= $cc['order_cases'] ?> ord + <?= $cc['buffer_cases'] ?> line)</small>
                                                <?php elseif ($cc['order_cases'] > 0): ?>
                                                    <small style="color: #2563eb; font-size: 10px;">(Orders)</small>
                                                <?php else: ?>
                                                    <small style="color: #059669; font-size: 12px;">(Extra)</small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                          

                 
                        </div>

                        <!-- Card Footer Operations: Edit Vehicle, Reset & Delete -->
                        <div class="veh-card-footer">
                            <button type="button" class="btn-footer-action" onclick="openEditVehicleModal(<?= htmlspecialchars(json_encode($v)) ?>)" title="Edit vehicle details">
                                <i class='bx bx-edit-alt'></i> Edit Vehicle
                            </button>

                            <button type="button" class="btn-footer-action" style="color: #d97706;" onclick="confirmResetLoad(<?= $vId ?>, '<?= htmlspecialchars(addslashes($vNum)) ?>', <?= $totalCases ?>)" title="Clear vehicle load for next trip">
                                <i class='bx bx-reset'></i> Reset Load
                            </button>

                            <button type="button" class="btn-footer-action" style="color: #dc2626;" onclick="confirmDeleteVehicle(<?= $vId ?>, '<?= htmlspecialchars(addslashes($vNum)) ?>', <?= $totalCases ?>, <?= count($v['assigned_orders'] ?? []) ?>)" title="Delete vehicle from fleet">
                                <i class='bx bx-trash'></i> Delete
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- ========================================================
         MODAL 1: LOAD PRODUCT ONTO VEHICLE (CREATE)
         ======================================================== -->
    <!-- ========================================================
         MODAL 1: LOAD PRODUCTS ONTO VEHICLE (CREATE / MULTI-LOAD)
         ======================================================== -->
    <div id="modalLoadProduct" class="modal-backdrop" onclick="if(event.target===this) closeLoadProductModal()">
        <div class="modal-dialog modal-dialog-lg">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title">
                        <i class='bx bx-plus-circle' style="color: #059669;"></i>
                        <span id="loadProductModalTitle">Load Products onto Vehicle</span>
                    </h3>
                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;"></div>
                </div>
                <button type="button" class="modal-close" onclick="closeLoadProductModal()">&times;</button>
            </div>
            <form method="POST" action="" onsubmit="return validateLoadProductForm(this);">
                <input type="hidden" name="action" value="load_product">

                <!-- Vehicle Selector -->
                <div class="form-group">
                    
                    <select name="vehicle_id" id="modalLoadVehicleSelect" class="form-control" required onchange="updateVehicleCapacityHint()">
                        <option value="">-- Select Delivery Vehicle --</option>
                        <?php foreach ($allFleetVehicles as $v): ?>
                            <option value="<?= (int)$v['id'] ?>" 
                                    data-capacity="<?= (int)($v['capacity_cases'] ?? 100) ?>" 
                                    data-loaded="<?= (int)($v['total_cases_loaded'] ?? 0) ?>"
                                    data-plate="<?= htmlspecialchars($v['vehicle_number']) ?>"
                                    data-name="<?= htmlspecialchars($v['vehicle_name']) ?>">
                                <?= htmlspecialchars($v['vehicle_number']) ?> &bull; <?= htmlspecialchars($v['vehicle_name']) ?> (Loaded: <?= (int)($v['total_cases_loaded'] ?? 0) ?> / <?= (int)($v['capacity_cases'] ?? 100) ?> cs)
                            </option>
                        <?php endforeach; ?>
                    </select>
              
                </div>

               

                <!-- Multi-Product Table -->
                <div style="margin-top: 8px; margin-bottom: 14px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <label class="form-label" style="margin-bottom: 0;">
                            <i class='bx bx-package' style="color: #059669; font-size: 14px;"></i> Products to Load *
                        </label>
                        <!-- <span style="font-size: 11px; color: #64748b;">Add one or multiple catalog products below</span> -->
                    </div>

                    <div style="max-height: 280px; overflow-y: auto; overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff;">
                        <table style="width: 100%; border-collapse: collapse; min-width: 580px;">
                            <thead>
                                <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; background: #f8fafc;">
                                    <th style="padding: 8px 10px;">Product</th>
                                    <!-- <th style="padding: 8px 10px; width: 120px;">Whse Stock</th> -->
                                    <th style="padding: 8px 10px; width: 95px;">Rate (₹)</th>
                                    <th style="padding: 8px 10px; width: 125px;">Cases *</th>
                                    <th style="padding: 8px 10px; width: 105px; text-align: right;">Subtotal</th>
                                    <th style="padding: 8px 6px; width: 36px; text-align: center;"></th>
                                </tr>
                            </thead>
                            <tbody id="loadProductTableBody">
                                <!-- Dynamic rows inserted by JavaScript -->
                            </tbody>
                        </table>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; flex-wrap: wrap; gap: 8px;">
                        <button type="button" class="btn-card-action" onclick="addLoadProductRow()" style="font-size: 12px; font-weight: 700; color: #059669; background: #ecfdf5; border: 1px solid #a7f3d0; cursor: pointer; padding: 6px 14px; border-radius: 8px; display: inline-flex; align-items: center; gap: 4px;">
                            <i class='bx bx-plus'></i> Add Another Product
                        </button>
                        <div style="font-size: 12px; color: #64748b;">
                            Total Products: <strong id="loadTotalProductsCount" style="color: #0f172a;">0</strong>
                        </div>
                    </div>
                </div>

                <!-- Live Load Summary & Warnings -->
                <div id="loadSummaryBox" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b;">Total Cases to Load</div>
                            <div style="font-size: 18px; font-weight: 800; color: #0f172a; margin-top: 2px;">
                                <span id="loadTotalCasesSum">0</span> <span style="font-size: 12px; font-weight: 600; color: #64748b;">Cases</span>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b;">Estimated Value</div>
                            <div id="loadTotalValueSum" style="font-size: 18px; font-weight: 800; color: #059669; margin-top: 2px;">₹0.00</div>
                        </div>
                    </div>

                 
                    <div id="loadVehicleOverloadNotice" style="display: none; margin-top: 8px; font-size: 12px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 6px 10px;">
                        <i class='bx bx-error'></i> <span id="loadVehicleOverloadText">Notice: Exceeds remaining vehicle capacity.</span>
                    </div>
                </div>

                <div class="modal-btns-row">
                    <button type="submit" id="btnSubmitLoadProduct" class="btn-header btn-header-success" style="border: none; padding: 9px 24px; font-weight: 700;">
                        <i class='bx bx-check'></i> Confirm &amp; Load Products
                    </button>
                    <button type="button" class="btn-header" onclick="closeLoadProductModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 2: EDIT VEHICLE PRODUCT LOAD (UPDATE)
         ======================================================== -->
    <div id="modalEditProductLoad" class="modal-backdrop" onclick="if(event.target===this) closeEditProductLoadModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-edit' style="color: #2563eb;"></i>
                    <span id="editProductModalTitle">Edit Vehicle Product Load</span>
                </h3>
                <button type="button" class="modal-close" onclick="closeEditProductLoadModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_product_load">
                <input type="hidden" name="load_id" id="editLoadId" value="">

                <div class="form-group">
                    <label class="form-label">Product</label>
                    <input type="text" id="editProductNameDisplay" class="form-control" readonly style="background: #f1f5f9; font-weight: 700;">
                    <div id="editProductStockHint" style="display: none; margin-top: 6px; font-size: 12px; color: #475569; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 10px;"></div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Number of Cases *</label>
                        <input type="text" name="cases" id="editLoadCasesInput" class="form-control" min="1" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Operating Zone</label>
                        <select name="zone_id" id="editLoadZoneSelect" class="form-control">
                            <option value="">-- None / All Zones --</option>
                            <?php foreach ($zonesList as $z): ?>
                                <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" id="editLoadNotesInput" class="form-control" placeholder="e.g. Linesale extra stock">
                </div>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-header btn-header-primary" style="border: none;">
                        <i class='bx bx-check'></i> Update Quantity
                    </button>
                    <button type="button" class="btn-header" onclick="closeEditProductLoadModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 3: DELETE PRODUCT LOAD CONFIRMATION (DELETE)
         ======================================================== -->
    <div id="modalDeleteProductLoad" class="modal-backdrop" onclick="if(event.target===this) closeDeleteProductLoadModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #dc2626;">
                    <i class='bx bx-trash'></i> Remove Product from Vehicle
                </h3>
                <button type="button" class="modal-close" onclick="closeDeleteProductLoadModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_product_load">
                <input type="hidden" name="load_id" id="deleteLoadId" value="">

                <p style="font-size: 14px; margin-bottom: 16px;">
                    Are you sure you want to unload <strong id="deleteProductNameText"></strong> (<span id="deleteProductCasesText"></span> cases) from this vehicle?
                </p>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">
                    This product will be removed from the vehicle's remaining linesale load immediately.
                </p>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-header" style="background: #dc2626; color: #fff; border-color: #dc2626;">
                        <i class='bx bx-trash'></i> Yes, Remove from Vehicle
                    </button>
                    <button type="button" class="btn-header" onclick="closeDeleteProductLoadModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 4: ADD VEHICLE (CREATE)
         ======================================================== -->
    <div id="modalAddVehicle" class="modal-backdrop" onclick="if(event.target===this) closeAddVehicleModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bxs-truck' style="color: #2563eb;"></i> Register Vehicle to Fleet
                </h3>
                <button type="button" class="modal-close" onclick="closeAddVehicleModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_vehicle">

                <div class="form-group">
                    <label class="form-label">Vehicle Number / Plate *</label>
                    <input type="text" name="vehicle_number" class="form-control" placeholder="e.g. KA 19 HS 4567" required style="text-transform: uppercase;">
                </div>

                <div class="form-group">
                    <label class="form-label">Vehicle Model / Type</label>
                    <input type="text" name="vehicle_name" class="form-control" placeholder="e.g. Tata Ace / Bolero Maxi">
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Driver Name</label>
                        <input type="text" name="driver_name" class="form-control" placeholder="Driver full name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Driver Phone Number</label>
                        <input type="tel" name="driver_phone" class="form-control" placeholder="10-digit mobile">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Base Operating Zone</label>
                        <select name="zone_id" class="form-control">
                            <option value="">-- Central / All Zones --</option>
                            <?php foreach ($zonesList as $z): ?>
                                <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Initial Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active (Ready)</option>
                            <option value="loading">Loading</option>
                            <option value="dispatched">Dispatched</option>
                        </select>
                    </div>
                </div>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-header btn-header-primary" style="border: none;">
                        <i class='bx bx-check'></i> Register Vehicle
                    </button>
                    <button type="button" class="btn-header" onclick="closeAddVehicleModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 5: EDIT VEHICLE (UPDATE)
         ======================================================== -->
    <div id="modalEditVehicle" class="modal-backdrop" onclick="if(event.target===this) closeEditVehicleModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-edit' style="color: #2563eb;"></i> Edit Vehicle Details
                </h3>
                <button type="button" class="modal-close" onclick="closeEditVehicleModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_vehicle">
                <input type="hidden" name="vehicle_id" id="editVehId" value="">

                <div class="form-group">
                    <label class="form-label">Vehicle Number / Plate *</label>
                    <input type="text" name="vehicle_number" id="editVehNumber" class="form-control" required style="text-transform: uppercase;">
                </div>

                <div class="form-group">
                    <label class="form-label">Vehicle Model / Type</label>
                    <input type="text" name="vehicle_name" id="editVehName" class="form-control">
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Driver Name</label>
                        <input type="text" name="driver_name" id="editVehDriver" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Driver Phone Number</label>
                        <input type="tel" name="driver_phone" id="editVehPhone" class="form-control">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Base Operating Zone</label>
                        <select name="zone_id" id="editVehZone" class="form-control">
                            <option value="">-- Central / All Zones --</option>
                            <?php foreach ($zonesList as $z): ?>
                                <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="editVehStatus" class="form-control">
                            <option value="active">Active (Ready)</option>
                            <option value="loading">Loading</option>
                            <option value="dispatched">Dispatched</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="modal-btns-row" style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <button type="button" class="btn-header" style="color: #dc2626; border-color: #fecaca; background: #fef2f2;" onclick="closeEditVehicleModal(); confirmDeleteVehicle(document.getElementById('editVehId').value, document.getElementById('editVehNumber').value);">
                        <i class='bx bx-trash'></i> Delete Vehicle
                    </button>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-header" onclick="closeEditVehicleModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn-header btn-header-primary" style="border: none;">
                            <i class='bx bx-check'></i> Save Vehicle Details
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 6: RESET LOAD CONFIRMATION
         ======================================================== -->
    <div id="modalResetLoad" class="modal-backdrop" onclick="if(event.target===this) closeResetLoadModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #dc2626;">
                    <i class='bx bx-reset'></i> Reset Vehicle Load
                </h3>
                <button type="button" class="modal-close" onclick="closeResetLoadModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="reset_vehicle_load">
                <input type="hidden" name="vehicle_id" id="resetVehId" value="">

                <p style="font-size: 14px; margin-bottom: 12px;">
                    Are you sure you want to reset current trip load for vehicle <strong id="resetVehNumText"></strong>?
                </p>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 20px;">
                    This will clear all <span id="resetVehCasesText" style="font-weight: 700;"></span> active loaded cases for the next trip. All completed delivery history and lifetime stats will be preserved.
                </p>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-header" style="background: #dc2626; color: #fff; border-color: #dc2626;">
                        <i class='bx bx-reset'></i> Reset Vehicle Load
                    </button>
                    <button type="button" class="btn-header" onclick="closeResetLoadModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 6B: DELETE VEHICLE CONFIRMATION
         ======================================================== -->
    <div id="modalDeleteVehicle" class="modal-backdrop" onclick="if(event.target===this) closeDeleteVehicleModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #dc2626;">
                    <i class='bx bx-trash'></i> Delete Vehicle
                </h3>
                <button type="button" class="modal-close" onclick="closeDeleteVehicleModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_vehicle">
                <input type="hidden" name="vehicle_id" id="deleteVehId" value="">

                <p style="font-size: 14px; margin-bottom: 12px; color: var(--text-main);">
                    Are you sure you want to permanently delete vehicle <strong id="deleteVehNumText"></strong> ?
                </p>

                <div id="deleteVehWarningWrap" style="display: none; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 10px 12px; font-size: 12px; color: #9f1239; margin-bottom: 14px;">
                    <i class='bx bx-error-circle'></i> <span id="deleteVehWarningText"></span>
                </div>


                <div class="modal-btns-row">
                    <button type="submit" class="btn-header" style="background: #dc2626; color: #fff; border-color: #dc2626;">
                        <i class='bx bx-trash'></i> Yes, Delete Vehicle
                    </button>
                    <button type="button" class="btn-header" onclick="closeDeleteVehicleModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Interactive JavaScript Modals & Handlers -->
    <script>
        const BASE_URL = <?= json_encode(BASE_URL) ?>;
        const apiBase = BASE_URL.replace(/^https?:\/\/[^\/]+/i, window.location.origin);
        const pollApiUrl = apiBase + '/zone/orders/dashboard/poll.php?zone=<?= urlencode($filterZone) ?>';
        const AOP_PRODUCTS = <?= json_encode($catalogProducts) ?>;

        let loadProductRowCounter = 0;

        function getLoadProductOptionsHtml(selectedPid = 0) {
            let html = '<option value="">-- Choose Product --</option>';
            AOP_PRODUCTS.forEach(p => {
                const pid = parseInt(p.product_id);
                const pName = p.product_name || p.name || 'Product';
                const rate = parseFloat(p.case_price || p.price || 0);
                const content = p.net_content ? (parseFloat(p.net_content) + ' ' + (p.net_content_unit || '')) : '';
                const displayName = pName + (content ? ` (${content})` : '');
                const stock = Math.max(0, parseInt(p.available_stock !== null && p.available_stock !== undefined ? p.available_stock : (p.case_stock || p.stock || 0)));
                const sel = (pid === parseInt(selectedPid)) ? 'selected' : '';

                if (stock <= 0) {
                    html += `<option value="${pid}" data-rate="${rate}" data-stock="0" data-name="${displayName}" ${sel} style="color: #94a3b8;">${displayName} &bull; ₹${rate.toFixed(2)}/cs (0 cs)</option>`;
                } else {
                    html += `<option value="${pid}" data-rate="${rate}" data-stock="${stock}" data-name="${displayName}" ${sel}>${displayName} &bull; ₹${rate.toFixed(2)}/cs (${stock} cs)</option>`;
                }
            });
            return html;
        }

        function addLoadProductRow(selectedPid = 0, defaultCases = '') {
            const tbody = document.getElementById('loadProductTableBody');
            if (!tbody) return;

            loadProductRowCounter++;
            const rowId = loadProductRowCounter;

            const tr = document.createElement('tr');
            tr.className = 'load-prod-row';
            tr.setAttribute('data-row-id', rowId);
            tr.style.borderBottom = '1px solid #f1f5f9';
            tr.innerHTML = `
                <td style="padding: 6px 2px;width:10%; ">
                    <select name="items[${rowId}][product_id]" class="form-control load-prod-select" required onchange="handleLoadProdChange(this)" style="font-size: 13px; padding: 6px 8px;">
                        ${getLoadProductOptionsHtml(selectedPid)}
                    </select>
                </td>
          
                <td style="padding: 6px 8px; vertical-align: middle;">
                    <span class="load-prod-rate" style="font-size: 12px; font-weight: 600; color: #334155;">₹0.00</span>
                </td>
                <td style="padding: 6px 8px; vertical-align: middle;">
                    <div style="display: flex; gap: 4px; align-items: center;">
                        <input
    type="text"
    name="items[${rowId}][cases]"
    class="form-control load-prod-cases"
    inputmode="numeric"
    maxlength="4"
    pattern="[0-9]{1,4}"
    placeholder="Add Count"
    value="${defaultCases}"
    required
    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4); calculateLoadTotals()"
    style="width: 102px; padding: 5px 4px; font-size: 11px; font-weight: 700;"
>
                       
                    </div>
                </td>
                <td style="padding: 6px 8px; text-align: right; vertical-align: middle; font-weight: 700; color: #0f172a; font-size: 13px;">
                    <span class="load-prod-subtotal">₹0.00</span>
                </td>
                <td style="padding: 6px 4px; text-align: center; vertical-align: middle;">
                    <button type="button" class="btn-crud-mini btn-crud-del" onclick="removeLoadProductRow(this)" title="Remove item" style="background: none; border: 1px solid #fee2e2; color: #ef4444; border-radius: 6px; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer;">
                        <i class='bx bx-trash' style="font-size: 14px;"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);

            const sel = tr.querySelector('.load-prod-select');
            if (sel && selectedPid) {
                handleLoadProdChange(sel);
            }
            calculateLoadTotals();
        }

        function removeLoadProductRow(btn) {
            const row = btn.closest('tr');
            const tbody = document.getElementById('loadProductTableBody');
            if (row) row.remove();
            if (tbody && tbody.children.length === 0) {
                addLoadProductRow();
            }
            calculateLoadTotals();
        }

        function handleLoadProdChange(selectElem) {
            const row = selectElem.closest('tr');
            if (!row) return;

            const opt = selectElem.options[selectElem.selectedIndex];
            const badge = row.querySelector('.load-prod-stock-badge');
            const rateSpan = row.querySelector('.load-prod-rate');
            const btnMax = row.querySelector('.btn-fill-max');

            if (!opt || !opt.value) {
                if (badge) {
                    badge.textContent = '--';
                    badge.style.background = '#f1f5f9';
                    badge.style.color = '#64748b';
                    badge.style.border = 'none';
                }
                if (rateSpan) rateSpan.textContent = '₹0.00';
                if (btnMax) btnMax.style.display = 'none';
                calculateLoadTotals();
                return;
            }

            const stock = parseInt(opt.getAttribute('data-stock') || '0', 10);
            const rate = parseFloat(opt.getAttribute('data-rate') || '0');

            if (rateSpan) rateSpan.textContent = `₹${rate.toFixed(2)}`;

            if (badge) {
                if (stock > 20) {
                    badge.textContent = `${stock} cs`;
                    badge.style.background = '#ecfdf5';
                    badge.style.color = '#065f46';
                    badge.style.border = '1px solid #a7f3d0';
                } else if (stock > 0) {
                    badge.textContent = `${stock} cs`;
                    badge.style.background = '#fffbeb';
                    badge.style.color = '#92400e';
                    badge.style.border = '1px solid #fde68a';
                } else {
                    badge.textContent = 'Out (0)';
                    badge.style.background = '#fef2f2';
                    badge.style.color = '#991b1b';
                    badge.style.border = '1px solid #fecaca';
                }
            }

            if (btnMax) {
                btnMax.style.display = (stock > 0) ? 'inline-block' : 'none';
            }

            calculateLoadTotals();
        }

        function fillRowMaxStock(btn) {
            const row = btn.closest('tr');
            if (!row) return;
            const sel = row.querySelector('.load-prod-select');
            const casesInput = row.querySelector('.load-prod-cases');
            if (!sel || !casesInput) return;

            const opt = sel.options[sel.selectedIndex];
            if (opt && opt.value) {
                const stock = parseInt(opt.getAttribute('data-stock') || '0', 10);
                if (stock > 0) {
                    casesInput.value = stock;
                    calculateLoadTotals();
                }
            }
        }

        function calculateLoadTotals() {
            const tbody = document.getElementById('loadProductTableBody');
            const vSel = document.getElementById('modalLoadVehicleSelect');
            const totalCasesSpan = document.getElementById('loadTotalCasesSum');
            const totalValSpan = document.getElementById('loadTotalValueSum');
            const totalCountSpan = document.getElementById('loadTotalProductsCount');
            const warnStockBox = document.getElementById('loadStockWarningNotice');
            const warnStockText = document.getElementById('loadStockWarningText');
            const warnOverloadBox = document.getElementById('loadVehicleOverloadNotice');
            const warnOverloadText = document.getElementById('loadVehicleOverloadText');

            let grandCases = 0;
            let grandValue = 0;
            let validItemsCount = 0;
            let productTotals = {};

            if (tbody) {
                const rows = tbody.querySelectorAll('tr.load-prod-row');
                rows.forEach(r => {
                    const sel = r.querySelector('.load-prod-select');
                    const casesInput = r.querySelector('.load-prod-cases');
                    const subtotalSpan = r.querySelector('.load-prod-subtotal');

                    const pid = parseInt(sel ? sel.value : 0);
                    const cases = parseInt(casesInput ? casesInput.value : 0) || 0;
                    const opt = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;

                    if (pid > 0 && opt) {
                        const rate = parseFloat(opt.getAttribute('data-rate') || 0);
                        const stock = parseInt(opt.getAttribute('data-stock') || 0);
                        const prodName = opt.getAttribute('data-name') || ('Product #' + pid);
                        const lineTotal = cases * rate;

                        if (subtotalSpan) {
                            subtotalSpan.textContent = `₹${lineTotal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                        }

                        if (cases > 0) {
                            grandCases += cases;
                            grandValue += lineTotal;
                            validItemsCount++;

                            if (!productTotals[pid]) {
                                productTotals[pid] = { name: prodName, cases: 0, stock: stock };
                            }
                            productTotals[pid].cases += cases;
                        }
                    } else {
                        if (subtotalSpan) subtotalSpan.textContent = '₹0.00';
                    }
                });
            }

            if (totalCasesSpan) totalCasesSpan.textContent = grandCases.toLocaleString();
            if (totalValSpan) totalValSpan.textContent = `₹${grandValue.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            if (totalCountSpan) totalCountSpan.textContent = validItemsCount;

            // Check warehouse stock overage
            let stockOverageMsgs = [];
            for (let pid in productTotals) {
                const pt = productTotals[pid];
                if (pt.cases > pt.stock) {
                    stockOverageMsgs.push(`${pt.name} (${pt.cases} cs requested vs ${pt.stock} cs available)`);
                }
            }

            if (warnStockBox && warnStockText) {
                if (stockOverageMsgs.length > 0) {
                    warnStockText.innerHTML = ``;
                    warnStockBox.style.display = 'block';
                } else {
                    warnStockBox.style.display = 'none';
                }
            }

            // Check vehicle fleet capacity
            if (warnOverloadBox && warnOverloadText && vSel) {
                const opt = vSel.options[vSel.selectedIndex];
                if (opt && opt.value) {
                    const cap = parseInt(opt.getAttribute('data-capacity') || '100', 10);
                    const loaded = parseInt(opt.getAttribute('data-loaded') || '0', 10);
                    const rem = Math.max(0, cap - loaded);
                    if (grandCases > rem) {
                        warnOverloadText.innerHTML = `<strong>Stock Warning:</strong> No ${grandCases} cases Available, Remaining (${rem} cs) `;
                        warnOverloadBox.style.display = 'block';
                    } else {
                        warnOverloadBox.style.display = 'none';
                    }
                } else {
                    warnOverloadBox.style.display = 'none';
                }
            }
        }

        function validateLoadProductForm(form) {
            const vSel = document.getElementById('modalLoadVehicleSelect');
            const vId = parseInt(vSel ? vSel.value : 0);

            if (!vId || vId <= 0) {
                alert('Please select a target delivery vehicle from the dropdown.');
                if (vSel) vSel.focus();
                return false;
            }

            const tbody = document.getElementById('loadProductTableBody');
            if (!tbody) return false;

            const rows = tbody.querySelectorAll('tr.load-prod-row');
            if (rows.length === 0) {
                alert('Please add at least one product to load.');
                return false;
            }

            let validCount = 0;
            let hasInvalidRow = false;

            rows.forEach(r => {
                const sel = r.querySelector('.load-prod-select');
                const casesInput = r.querySelector('.load-prod-cases');
                const pid = parseInt(sel ? sel.value : 0);
                const cases = parseInt(casesInput ? casesInput.value : 0);

                if (pid > 0 && cases > 0) {
                    validCount++;
                } else if (pid > 0 && (!cases || cases <= 0)) {
                    alert('Please enter a positive number of cases (at least 1 case) for all selected products.');
                    if (casesInput) casesInput.focus();
                    hasInvalidRow = true;
                    return false;
                } else if (pid <= 0 && cases > 0) {
                    alert('Please choose a product from the catalog for all entered rows.');
                    if (sel) sel.focus();
                    hasInvalidRow = true;
                    return false;
                }
            });

            if (hasInvalidRow) return false;

            if (validCount === 0) {
                alert('Please add at least one product with a valid number of cases (minimum 1 case).');
                return false;
            }

            const warnStockBox = document.getElementById('loadStockWarningNotice');
            if (warnStockBox && warnStockBox.style.display !== 'none') {
                if (!confirm("Notice: One or more selected items exceed warehouse available stock. Do you still want to proceed with loading?")) {
                    return false;
                }
            }

            return true;
        }

        // Modal 1: Load Products
        function openLoadProductModal(vId = null, vNum = null) {
            const m = document.getElementById('modalLoadProduct');
            const sel = document.getElementById('modalLoadVehicleSelect');
            const title = document.getElementById('loadProductModalTitle');

            if (vId && sel) {
                const parsedVid = parseInt(vId);
                let opt = sel.querySelector(`option[value="${parsedVid}"]`);
                if (!opt) {
                    opt = document.createElement('option');
                    opt.value = parsedVid;
                    opt.textContent = vNum ? (vNum + ' (Selected Vehicle)') : ('Vehicle #' + parsedVid);
                    sel.appendChild(opt);
                }
                sel.value = parsedVid;
                if (title && vNum) title.textContent = `Load Products onto ${vNum}`;
            } else if (title) {
                title.textContent = 'Load Products onto Vehicle';
            }

            const tbody = document.getElementById('loadProductTableBody');
            if (tbody && tbody.children.length === 0) {
                addLoadProductRow();
            }

            updateVehicleCapacityHint();
            calculateLoadTotals();
            if (m) m.classList.add('open');
        }

        function closeLoadProductModal() {
            const m = document.getElementById('modalLoadProduct');
            if (m) m.classList.remove('open');
        }

        function updateVehicleCapacityHint() {
            const sel = document.getElementById('modalLoadVehicleSelect');
            const box = document.getElementById('vehCapacityInfo');
            if (!sel || !box) return;

            const opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) {
                box.style.display = 'none';
                calculateLoadTotals();
                return;
            }

            const cap = parseInt(opt.getAttribute('data-capacity') || '100', 10);
            const loaded = parseInt(opt.getAttribute('data-loaded') || '0', 10);
            const rem = Math.max(0, cap - loaded);

            box.style.display = 'block';
            box.innerHTML = `<strong>Vehicle Fleet Capacity:</strong> Limit: <strong>${cap} cs</strong> &bull; Loaded: <strong>${loaded} cs</strong> &bull; Remaining Space: <strong style="color: ${rem > 0 ? '#059669' : '#dc2626'}">${rem} cs</strong>`;
            calculateLoadTotals();
        }

        // Modal 2: Edit Product Load
        function openEditProductLoadModal(loadId, prodName, cases, zoneId, notes, currentStock = null) {
            document.getElementById('editLoadId').value = loadId;
            document.getElementById('editProductNameDisplay').value = prodName;
            document.getElementById('editLoadCasesInput').value = cases;
            document.getElementById('editLoadZoneSelect').value = zoneId || '';
            document.getElementById('editLoadNotesInput').value = notes || '';
            document.getElementById('editProductModalTitle').textContent = `Edit Load: ${prodName}`;

            const hint = document.getElementById('editProductStockHint');
            if (hint) {
                if (currentStock !== null && currentStock !== undefined) {
                    hint.style.display = 'flex';
                    hint.innerHTML = `<i class='bx bx-archive' style="color: #2563eb; font-size: 15px;"></i> Warehouse Available Stock: <strong style="color: #0f172a; margin-left: 4px;">${currentStock} Cases</strong>`;
                } else {
                    hint.style.display = 'none';
                }
            }

            const m = document.getElementById('modalEditProductLoad');
            if (m) m.classList.add('open');
        }
        function closeEditProductLoadModal() {
            const m = document.getElementById('modalEditProductLoad');
            if (m) m.classList.remove('open');
        }

        // Modal 3: Delete Product Load
        function confirmDeleteProductLoad(loadId, prodName, cases) {
            document.getElementById('deleteLoadId').value = loadId;
            document.getElementById('deleteProductNameText').textContent = prodName;
            document.getElementById('deleteProductCasesText').textContent = cases;

            const m = document.getElementById('modalDeleteProductLoad');
            if (m) m.classList.add('open');
        }
        function closeDeleteProductLoadModal() {
            const m = document.getElementById('modalDeleteProductLoad');
            if (m) m.classList.remove('open');
        }

        // Modal 4: Add Vehicle
        function openAddVehicleModal() {
            const m = document.getElementById('modalAddVehicle');
            if (m) m.classList.add('open');
        }
        function closeAddVehicleModal() {
            const m = document.getElementById('modalAddVehicle');
            if (m) m.classList.remove('open');
        }

        // Modal 5: Edit Vehicle
        function openEditVehicleModal(vData) {
            document.getElementById('editVehId').value = vData.id || '';
            document.getElementById('editVehNumber').value = vData.vehicle_number || '';
            document.getElementById('editVehName').value = vData.vehicle_name || '';
            document.getElementById('editVehDriver').value = vData.driver_name || '';
            document.getElementById('editVehPhone').value = vData.driver_phone || '';
            document.getElementById('editVehZone').value = vData.zone_id || '';
            document.getElementById('editVehStatus').value = vData.status || 'active';

            const m = document.getElementById('modalEditVehicle');
            if (m) m.classList.add('open');
        }
        function closeEditVehicleModal() {
            const m = document.getElementById('modalEditVehicle');
            if (m) m.classList.remove('open');
        }

        // Modal 6: Reset Load
        function confirmResetLoad(vId, vNum, cases) {
            document.getElementById('resetVehId').value = vId;
            document.getElementById('resetVehNumText').textContent = vNum;
            document.getElementById('resetVehCasesText').textContent = cases;

            const m = document.getElementById('modalResetLoad');
            if (m) m.classList.add('open');
        }
        function closeResetLoadModal() {
            const m = document.getElementById('modalResetLoad');
            if (m) m.classList.remove('open');
        }

        // Modal 6B: Delete Vehicle
        function confirmDeleteVehicle(vId, vNum, cases = 0, orderCount = 0) {
            document.getElementById('deleteVehId').value = vId;
            document.getElementById('deleteVehNumText').textContent = vNum;
            const warnWrap = document.getElementById('deleteVehWarningWrap');
            const warnText = document.getElementById('deleteVehWarningText');
            if (cases > 0 || orderCount > 0) {
                let parts = [];
                if (cases > 0) parts.push(`${cases} cases loaded`);
                if (orderCount > 0) parts.push(`${orderCount} assigned order(s)`);
                warnText.textContent = `Warning: This vehicle currently has ${parts.join(' and ')}. Proceeding will clear all loads and unassign these orders safely.`;
                warnWrap.style.display = 'block';
            } else {
                warnWrap.style.display = 'none';
            }
            const m = document.getElementById('modalDeleteVehicle');
            if (m) m.classList.add('open');
        }
        function closeDeleteVehicleModal() {
            const m = document.getElementById('modalDeleteVehicle');
            if (m) m.classList.remove('open');
        }

        // ========================================================
        // MODAL 7 & 8: ASSIGN ORDER & EDIT ORDER PRODUCTS
        // ========================================================
        // Constants BASE_URL, apiBase, pollApiUrl, and AOP_PRODUCTS are declared at the top of script scope

        // Modal 7: Assign Order to Vehicle
        function openAssignOrderModal(vId, vPlate) {
            document.getElementById('assignOrderVehicleId').value = vId;
            document.getElementById('assignOrderVehiclePlateDisplay').value = vPlate;
            document.getElementById('assignOrderModalTitle').textContent = `Assign Order to ${vPlate}`;
            const m = document.getElementById('modalAssignOrder');
            if (m) m.classList.add('open');
        }
        function closeAssignOrderModal() {
            const m = document.getElementById('modalAssignOrder');
            if (m) m.classList.remove('open');
        }

        // Modal 8: Add / Edit Order Products
        function getAopProductOptionsHtml(selectedPid = 0, extraStockAllowance = 0) {
            let html = '<option value="">-- Choose Product Item --</option>';
            AOP_PRODUCTS.forEach(p => {
                const pid = parseInt(p.product_id);
                const pName = p.product_name || p.name || 'Product';
                const rate = parseFloat(p.case_price || p.price || 0);
                let stock = Math.max(0, parseInt(p.case_stock !== null && p.case_stock !== undefined ? p.case_stock : (p.stock || 0)));
                if (pid === parseInt(selectedPid)) {
                    stock += parseInt(extraStockAllowance || 0);
                }
                const sel = (pid === parseInt(selectedPid)) ? 'selected' : '';
                if (stock <= 0 && pid !== parseInt(selectedPid)) {
                    html += `<option value="${pid}" data-rate="${rate}" data-stock="0" disabled style="color: #94a3b8;">${pName} (₹${rate.toFixed(2)}/cs - Out of stock)</option>`;
                } else {
                    html += `<option value="${pid}" data-rate="${rate}" data-stock="${stock}" ${sel}>${pName} (₹${rate.toFixed(2)}/cs, Avail: ${stock} cs)</option>`;
                }
            });
            return html;
        }

        function openAddOrderProductsModal(orderId, orderNum, shopName) {
            const parsedId = parseInt(orderId || 0);
            if (!parsedId || parsedId <= 0) {
                alert('Invalid order ID. Cannot open products editor.');
                return;
            }
            const backdrop = document.getElementById('modalAddOrderProductsBackdrop');
            if (!backdrop) return;

            document.getElementById('aopOrderId').value = parsedId;
            document.getElementById('aopModalTitle').textContent = `Add / Edit Products: #${orderNum || parsedId}`;
            document.getElementById('aopModalSubtitle').textContent = `Shop: ${shopName || 'Customer'}`;
            
            const errBox = document.getElementById('aopErrorMsg');
            if (errBox) {
                errBox.textContent = '';
                errBox.style.display = 'none';
            }

            const tbody = document.getElementById('aopItemsTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; padding: 24px; color: #64748b;">
                        <i class='bx bx-loader-alt bx-spin' style="font-size: 22px; color: #2563eb; vertical-align: middle; margin-right: 6px;"></i>
                        Loading existing order products...
                    </td>
                </tr>
            `;

            backdrop.classList.add('open');

            fetch(pollApiUrl + '&action=get_order_details&order_id=' + parsedId)
                .then(r => r.json())
                .then(res => {
                    tbody.innerHTML = '';
                    if (res.success && res.order) {
                        if (Array.isArray(res.order.products) && res.order.products.length > 0) {
                            AOP_PRODUCTS.length = 0;
                            AOP_PRODUCTS.push(...res.order.products);
                        }
                        if (Array.isArray(res.order.items) && res.order.items.length > 0) {
                            res.order.items.forEach(it => {
                                const pid = parseInt(it.product_id || 0);
                                const qty = parseInt(it.quantity || 1);
                                const rate = (it.price_at_purchase !== null && it.price_at_purchase !== undefined) ? parseFloat(it.price_at_purchase) : '';
                                const isExtra = parseInt(it.is_extra || 0) === 1;
                                addAopRow(pid, qty, rate, isExtra, qty);
                            });
                            addAopRow();
                        } else {
                            addAopRow();
                        }
                    } else {
                        addAopRow();
                    }
                    calculateAopTotals();
                })
                .catch(err => {
                    console.error('Failed to load order products:', err);
                    tbody.innerHTML = '';
                    addAopRow();
                    calculateAopTotals();
                });
        }

        function closeAddOrderProductsModal() {
            const backdrop = document.getElementById('modalAddOrderProductsBackdrop');
            if (backdrop) backdrop.classList.remove('open');
        }

        function addAopRow(selectedPid = 0, defaultQty = 1, defaultRate = '', isExtra = false, existingQty = 0) {
            const tbody = document.getElementById('aopItemsTableBody');
            if (!tbody) return;

            const tr = document.createElement('tr');
            tr.className = 'aop-row';
            tr.style.borderBottom = '1px solid #f1f5f9';
            tr.innerHTML = `
                <td style="padding: 6px 8px;">
                    <select class="form-control aop-prod-select" style="width: 100%; font-size: 13px; padding: 6px 8px;" onchange="handleAopProductChange(this)">
                        ${getAopProductOptionsHtml(selectedPid, existingQty)}
                    </select>
                </td>
                <td style="padding: 6px 8px;">
                    <input type="text" step="0.01" min="0" class="form-control aop-rate-input" style="width: 100%; font-size: 13px; padding: 6px 8px;" placeholder="0.00" value="${defaultRate !== '' ? defaultRate : ''}" oninput="calculateAopTotals()">
                </td>
                <td style="padding: 6px 8px;">
                    <input type="text" min="1" max="9999" class="form-control aop-qty-input" style="width: 100%; font-size: 13px; padding: 6px 8px;" value="${defaultQty}" oninput="handleAopQtyInput(this)">
                    <div class="aop-stock-hint" style="font-size: 10px; color: #64748b; margin-top: 2px; font-weight: 600;"></div>
                </td>
                <td style="padding: 6px 8px; text-align: center;">
                    <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px; color: #475569; font-weight: 600;">
                        <input type="checkbox" class="aop-extra-checkbox" ${isExtra ? 'checked' : ''} onchange="handleAopExtraChange(this)"> Extra
                    </label>
                </td>
                <td style="padding: 6px 8px; text-align: right; font-weight: 700; color: #0f172a; font-size: 13px;">
                    <span class="aop-line-total">₹0.00</span>
                </td>
                <td style="padding: 6px 4px; text-align: center;">
                    <button type="button" class="btn-crud-mini btn-crud-del" onclick="removeAopRow(this)" title="Remove item">
                        <i class='bx bx-trash'></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);

            const sel = tr.querySelector('.aop-prod-select');
            if (sel && selectedPid) {
                handleAopProductChange(sel, defaultRate !== '');
            }
        }

        function handleAopExtraChange(chkElem) {
            const row = chkElem.closest('tr');
            if (!row) return;
            const rateInput = row.querySelector('.aop-rate-input');
            if (rateInput) {
                if (chkElem.checked) {
                    rateInput.dataset.prevRate = rateInput.value;
                    rateInput.value = '0.00';
                    rateInput.readOnly = true;
                    rateInput.style.backgroundColor = '#f1f5f9';
                } else {
                    rateInput.readOnly = false;
                    rateInput.value = rateInput.dataset.prevRate || '';
                    rateInput.style.backgroundColor = '#ffffff';
                }
            }
            calculateAopTotals();
        }

        function removeAopRow(btn) {
            const tbody = document.getElementById('aopItemsTableBody');
            const row = btn.closest('tr');
            if (row) row.remove();
            if (tbody && tbody.children.length === 0) {
                addAopRow();
            }
            calculateAopTotals();
        }

        function handleAopProductChange(selectElem, preserveRate = false) {
            const row = selectElem.closest('tr');
            if (!row) return;
            const opt = selectElem.options[selectElem.selectedIndex];
            const rateInput = row.querySelector('.aop-rate-input');
            const qtyInput = row.querySelector('.aop-qty-input');
            const stockHint = row.querySelector('.aop-stock-hint');

            const pid = parseInt(selectElem.value || 0);
            if (!pid || !opt) {
                if (rateInput) rateInput.value = '';
                if (qtyInput) {
                    qtyInput.removeAttribute('max');
                    qtyInput.disabled = false;
                }
                if (stockHint) stockHint.textContent = '';
                calculateAopTotals();
                return;
            }

            const rate = parseFloat(opt.dataset.rate || 0);
            const stock = parseInt(opt.dataset.stock || 0);

            if (rateInput && (!preserveRate || rateInput.value === '')) {
                rateInput.value = rate.toFixed(2);
            }

            if (qtyInput) {
                if (stock <= 0) {
                    qtyInput.max = 0;
                    qtyInput.value = 0;
                    qtyInput.disabled = true;
                    if (stockHint) {
                        stockHint.textContent = 'Out of stock (0 cs)';
                        stockHint.style.color = '#dc2626';
                    }
                } else {
                    qtyInput.disabled = false;
                    qtyInput.max = stock;
                    let curVal = parseInt(qtyInput.value || 1);
                    if (curVal > stock) {
                        qtyInput.value = stock;
                    } else if (curVal < 1) {
                        qtyInput.value = 1;
                    }
                    if (stockHint) {
                        stockHint.textContent = `Max: ${stock} cs`;
                        stockHint.style.color = '#059669';
                    }
                }
            }

            calculateAopTotals();
        }

        function handleAopQtyInput(qtyInput) {
            const row = qtyInput.closest('tr');
            if (!row) return;
            const sel = row.querySelector('.aop-prod-select');
            const stockHint = row.querySelector('.aop-stock-hint');
            const opt = sel ? sel.options[sel.selectedIndex] : null;

            if (opt && opt.value) {
                const stock = parseInt(opt.dataset.stock || 0);
                let val = parseInt(qtyInput.value);

                if (stock <= 0) {
                    qtyInput.value = 0;
                    if (stockHint) {
                        stockHint.textContent = 'Out of stock (0 cs)';
                        stockHint.style.color = '#dc2626';
                    }
                } else if (val > stock) {
                    qtyInput.value = stock;
                    if (stockHint) {
                        stockHint.textContent = `Cannot exceed stock! Capped to ${stock} cs`;
                        stockHint.style.color = '#dc2626';
                    }
                } else if (val < 1 && qtyInput.value !== '') {
                    qtyInput.value = 1;
                    if (stockHint) {
                        stockHint.textContent = `Max: ${stock} cs`;
                        stockHint.style.color = '#059669';
                    }
                } else {
                    if (stockHint) {
                        stockHint.textContent = `Max: ${stock} cs`;
                        stockHint.style.color = '#059669';
                    }
                }
            }

            calculateAopTotals();
        }

        function calculateAopTotals() {
            let grandTotal = 0;
            const productUsage = {};
            let stockExceededError = '';

            document.querySelectorAll('.aop-row').forEach(row => {
                const sel = row.querySelector('.aop-prod-select');
                const rateIn = row.querySelector('.aop-rate-input');
                const qtyIn = row.querySelector('.aop-qty-input');
                const extraIn = row.querySelector('.aop-extra-checkbox');
                const totalSpan = row.querySelector('.aop-line-total');
                const opt = sel ? sel.options[sel.selectedIndex] : null;

                const pid = parseInt(sel ? sel.value : 0);
                const stock = opt ? parseInt(opt.dataset.stock || 0) : 0;
                const rate = parseFloat(rateIn ? rateIn.value : 0) || 0;
                let qty = parseInt(qtyIn ? qtyIn.value : 0) || 0;
                const isExtra = extraIn ? extraIn.checked : false;

                if (pid > 0) {
                    if (stock > 0 && qty > stock) {
                        qty = stock;
                        if (qtyIn) qtyIn.value = stock;
                    }

                    productUsage[pid] = (productUsage[pid] || 0) + qty;
                    if (stock > 0 && productUsage[pid] > stock) {
                        const prodName = opt ? (opt.text.split('(')[0].trim()) : 'Product';
                        stockExceededError = `Total quantity for "${prodName}" across items (${productUsage[pid]}) exceeds available stock (${stock} cs).`;
                        if (qtyIn) qtyIn.style.borderColor = '#dc2626';
                    } else {
                        if (qtyIn) qtyIn.style.borderColor = '#cbd5e1';
                    }
                }

                let lineTotal = 0;
                if (pid > 0 && qty > 0) {
                    lineTotal = rate * qty;
                }

                if (totalSpan) {
                    if (isExtra && pid > 0 && qty > 0) {
                        totalSpan.innerHTML = `<span style="text-decoration:line-through; color:#94a3b8; font-size:11px; margin-right:4px;">₹${lineTotal.toFixed(2)}</span>₹0.00 <span style="font-size:10px; color:#059669; font-weight:700;">(Extra)</span>`;
                    } else {
                        totalSpan.textContent = '₹' + lineTotal.toFixed(2);
                    }
                }
                if (!isExtra) grandTotal += lineTotal;
            });

            const gtSpan = document.getElementById('aopGrandTotal');
            if (gtSpan) gtSpan.textContent = '₹' + grandTotal.toFixed(2);

            const errBox = document.getElementById('aopErrorMsg');
            const submitBtn = document.getElementById('btnAopSubmit');
            if (stockExceededError) {
                if (errBox) {
                    errBox.textContent = stockExceededError;
                    errBox.style.display = 'block';
                }
                if (submitBtn) submitBtn.disabled = true;
            } else {
                if (errBox && errBox.textContent.includes('exceeds available stock')) {
                    errBox.textContent = '';
                    errBox.style.display = 'none';
                }
                if (submitBtn) submitBtn.disabled = false;
            }
        }

        function submitAddOrderProducts() {
            const orderId = parseInt(document.getElementById('aopOrderId')?.value || 0);
            const errBox = document.getElementById('aopErrorMsg');
            const submitBtn = document.getElementById('btnAopSubmit');

            if (errBox) {
                errBox.textContent = '';
                errBox.style.display = 'none';
            }

            if (!orderId || isNaN(orderId) || orderId <= 0) {
                if (errBox) {
                    errBox.textContent = 'Invalid order ID. Please re-open the product editor.';
                    errBox.style.display = 'block';
                } else {
                    alert('Invalid order ID.');
                }
                return;
            }

            const rows = document.querySelectorAll('.aop-row');
            const items = [];
            let hasError = false;

            rows.forEach((row, idx) => {
                const sel = row.querySelector('.aop-prod-select');
                const rateIn = row.querySelector('.aop-rate-input');
                const qtyIn = row.querySelector('.aop-qty-input');
                const extraIn = row.querySelector('.aop-extra-checkbox');

                const pid = parseInt(sel ? sel.value : 0);
                const qty = parseInt(qtyIn ? qtyIn.value : 0);
                const rate = parseFloat(rateIn ? rateIn.value : 0);
                const isExtra = extraIn ? (extraIn.checked ? 1 : 0) : 0;

                if (pid > 0) {
                    if (qty <= 0) {
                        hasError = true;
                        if (errBox) {
                            errBox.textContent = `Row #${idx + 1}: Please enter a valid quantity of 1 or more.`;
                            errBox.style.display = 'block';
                        }
                        return;
                    }

                    items.push({
                        product_id: pid,
                        rate: isNaN(rate) ? 0 : rate,
                        quantity: qty,
                        is_extra: isExtra
                    });
                }
            });

            if (hasError) return;

            if (items.length === 0) {
                if (errBox) {
                    errBox.textContent = 'Please select at least one product with quantity.';
                    errBox.style.display = 'block';
                }
                return;
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving...";
            }

            const fd = new FormData();
            fd.append('action', 'add_order_products');
            fd.append('order_id', orderId);
            items.forEach((it, i) => {
                fd.append(`items[${i}][product_id]`, it.product_id);
                fd.append(`items[${i}][rate]`, it.rate);
                fd.append(`items[${i}][quantity]`, it.quantity);
                fd.append(`items[${i}][is_extra]`, it.is_extra);
            });

            fetch(pollApiUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = "<i class='bx bx-check-circle'></i> Save &amp; Update Order";
                    }
                    if (res.success) {
                        closeAddOrderProductsModal();
                        window.location.reload();
                    } else {
                        if (errBox) {
                            errBox.textContent = res.error || 'Failed to save products.';
                            errBox.style.display = 'block';
                        } else {
                            alert(res.error || 'Failed to save products.');
                        }
                    }
                })
                .catch(e => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = "<i class='bx bx-check-circle'></i> Save &amp; Update Order";
                    }
                    console.error('Failed to add products:', e);
                    if (errBox) {
                        errBox.textContent = 'Network or server error occurred while adding products.';
                        errBox.style.display = 'block';
                    }
                });
        }

        // Close all modals on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeLoadProductModal();
                closeEditProductLoadModal();
                closeDeleteProductLoadModal();
                closeAddVehicleModal();
                closeEditVehicleModal();
                closeResetLoadModal();
                closeAssignOrderModal();
                closeAddOrderProductsModal();
            }
        });
    </script>

    <!-- ========================================================
         MODAL 7: ASSIGN CLIENT ORDER TO VEHICLE
         ======================================================== -->
    <div id="modalAssignOrder" class="modal-backdrop" onclick="if(event.target===this) closeAssignOrderModal()">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class='bx bx-plus-circle' style="color: #059669;"></i>
                    <span id="assignOrderModalTitle">Assign Client Order to Vehicle</span>
                </h3>
                <button type="button" class="modal-close" onclick="closeAssignOrderModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="assign_order">
                <input type="hidden" name="vehicle_id" id="assignOrderVehicleId" value="">

                <div class="form-group">
                    <label class="form-label">Selected Delivery Vehicle</label>
                    <input type="text" id="assignOrderVehiclePlateDisplay" class="form-control" readonly style="background: #f1f5f9; font-weight: 700;">
                </div>

                <div class="form-group">
                    <label class="form-label">Select Active Unassigned Order *</label>
                    <select name="order_id" id="assignOrderSelect" class="form-control" required>
                        <option value="">-- Choose Order to Assign --</option>
                        <?php if (empty($unassignedOrdersList)): ?>
                            <option value="" disabled>No unassigned orders available</option>
                        <?php else: ?>
                            <?php foreach ($unassignedOrdersList as $uo): 
                                $uId = (int)$uo['order_id'];
                                $uNum = $uo['order_number'] ?: ('#' . $uId);
                                $uShop = $uo['shop_name'] ?: ($uo['customer_name'] ?: 'Customer');
                                $uCases = (int)$uo['total_cases'];
                                $uZone = $uo['zone_name'] ?: 'Zone';
                            ?>
                                <option value="<?= $uId ?>">
                                    #<?= htmlspecialchars($uNum) ?> &bull; <?= htmlspecialchars($uShop) ?> (<?= htmlspecialchars($uZone) ?> &bull; <?= $uCases ?> cs)
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-header btn-header-success" style="border: none;">
                        <i class='bx bx-check'></i> Assign to Vehicle
                    </button>
                    <button type="button" class="btn-header" onclick="closeAssignOrderModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 8: ADD / EDIT ORDER PRODUCTS MODAL
         ======================================================== -->
    <div class="modal-backdrop" id="modalAddOrderProductsBackdrop" style="z-index: 1060;" onclick="if(event.target===this) closeAddOrderProductsModal()">
        <div class="modal-dialog" style="max-width: 720px; width: 95%;">
            <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 14px;">
                <div>
                    <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class='bx bx-cart-add' style="color: #059669; font-size: 22px;"></i>
                        <span id="aopModalTitle">Add Products &amp; Quantities</span>
                    </h3>
                    <div style="font-size: 12px; color: #64748b; margin-top: 2px;" id="aopModalSubtitle">Specify products for this order</div>
                </div>
                <button type="button" class="modal-close" onclick="closeAddOrderProductsModal()">&times;</button>
            </div>
            <div style="padding: 18px 0 0 0;">
                <form id="formAddOrderProducts" onsubmit="event.preventDefault(); submitAddOrderProducts();">
                    <input type="hidden" id="aopOrderId" name="order_id" value="">
                    
                    <div style="margin-bottom: 14px; max-height: 280px; overflow-y: auto; overflow-x: auto;">
                        <table style="width: 100%; min-width: 600px; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700;">
                                    <th style="padding: 6px 8px;">Product</th>
                                    <th style="padding: 6px 8px; width: 95px;">Rate (₹)</th>
                                    <th style="padding: 6px 8px; width: 95px;">Qty (cs)</th>
                                    <th style="padding: 6px 8px; width: 90px; text-align: center;">Extra Cases</th>
                                    <th style="padding: 6px 8px; width: 95px; text-align: right;">Total</th>
                                    <th style="padding: 6px 4px; width: 36px;"></th>
                                </tr>
                            </thead>
                            <tbody id="aopItemsTableBody">
                                <!-- Dynamic product rows -->
                            </tbody>
                        </table>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                        <button type="button" class="btn-card-action" onclick="addAopRow()" style="font-size: 12px; font-weight: 700; color: #2563eb; background: #eff6ff; border-color: #bfdbfe; cursor: pointer; padding: 6px 12px; border-radius: 8px;">
                            <i class='bx bx-plus'></i> Add Another Product
                        </button>
                        <div style="text-align: right;">
                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">Total Order Amount: </span>
                            <span id="aopGrandTotal" style="font-size: 18px; font-weight: 800; color: #059669;">₹0.00</span>
                        </div>
                    </div>

                    <div id="aopErrorMsg" style="display: none; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #b91c1c; font-size: 13px; margin-bottom: 14px;"></div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="btn-header" onclick="closeAddOrderProductsModal()">Cancel</button>
                        <button type="submit" id="btnAopSubmit" class="btn-header btn-header-success" style="padding: 8px 22px; font-weight: 700;">
                            <i class='bx bx-check-circle'></i> Save &amp; Update Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
(function () {
    const toggle = document.getElementById('statsMenuToggle');
    const dropdown = document.getElementById('statsDropdown');
    if (!toggle || !dropdown) return;

    function closeStatsMenu() {
        dropdown.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open fleet statistics');
        const icon = toggle.querySelector('i');
        if (icon) icon.className = 'bx bx-menu';
    }

    toggle.addEventListener('click', function (event) {
        event.stopPropagation();
        const isOpen = dropdown.classList.toggle('open');
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Close fleet statistics' : 'Open fleet statistics');
        const icon = toggle.querySelector('i');
        if (icon) icon.className = isOpen ? 'bx bx-x' : 'bx bx-menu';
    });

    dropdown.addEventListener('click', function (event) { event.stopPropagation(); });
    document.addEventListener('click', closeStatsMenu);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeStatsMenu();
    });
})();
</script>

</body>
</html>
