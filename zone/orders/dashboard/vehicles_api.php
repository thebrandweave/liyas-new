<?php
/**
 * zone/orders/dashboard/vehicles_api.php
 * Real-time REST/AJAX API for Vehicle Loading, Zone Dispatch Hub, and Fleet Management.
 */
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__) . '/auth_helper.php';

// Authentication check
if (!isZoneOrdersAuthenticated() && empty($_SESSION['delivery_zone_id']) && empty($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please login.']);
    exit;
}

$action = trim($_REQUEST['action'] ?? '');

/**
 * Robust helper to safely extract and validate order_id
 */
if (!function_exists('getRequestedOrderId')) {
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

            $numChk = $pdo->prepare("SELECT order_id FROM orders WHERE order_number = ? LIMIT 1");
            $numChk->execute([$trimmed]);
            $foundId = (int)$numChk->fetchColumn();
            if ($foundId > 0) {
                return $foundId;
            }
        }

        return 0;
    }
}

switch ($action) {
    case 'get_vehicles':
        $zone = trim($_GET['zone'] ?? 'all');
        $data = getAllVehiclesWithLoads($pdo, $zone);
        echo json_encode(array_merge(['success' => true], $data));
        exit;

    case 'get_vehicle_loaded_products':
        $vehicleId = (int)($_GET['vehicle_id'] ?? ($_POST['vehicle_id'] ?? 0));
        $loadedProducts = getVehicleLoadedProductsWithCounts($pdo, $vehicleId);
        $vStmt = $pdo->prepare("SELECT id, vehicle_number, vehicle_name, driver_name FROM vehicles WHERE id = ?");
        $vStmt->execute([$vehicleId]);
        $veh = $vStmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'vehicle' => $veh,
            'loaded_products' => array_values($loadedProducts)
        ]);
        exit;

    case 'add_vehicle':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $res = addVehicleRecord($pdo, [
            'vehicle_number' => $_POST['vehicle_number'] ?? '',
            'vehicle_name'   => $_POST['vehicle_name'] ?? '',
            'driver_name'    => $_POST['driver_name'] ?? '',
            'driver_phone'   => $_POST['driver_phone'] ?? '',
            'zone_id'        => !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null,
            'capacity_cases' => !empty($_POST['capacity_cases']) ? (int)$_POST['capacity_cases'] : 0,
            'status'         => $_POST['status'] ?? 'active'
        ]);
        echo json_encode($res);
        exit;

    case 'load_cases':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $zoneId = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $productId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
        $cases = (int)($_POST['cases'] ?? 0);
        $shopName = trim($_POST['shop_name'] ?? '');
        $notes = trim($_POST['notes'] ?? 'Manual Zone Dispatch Load');

        if ($vehicleId <= 0 || $cases <= 0) {
            echo json_encode(['success' => false, 'error' => 'Valid vehicle and positive number of cases are required.']);
            exit;
        }

        $ok = recordVehicleLoad($pdo, $vehicleId, $zoneId, $cases, null, $notes, $shopName, $productId);
        if ($ok) {
            echo json_encode(['success' => true, 'message' => "Successfully loaded {$cases} cases onto vehicle."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to record load.']);
        }
        exit;

    case 'assign_vehicle':
    case 'assign_order':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $orderId = getRequestedOrderId($pdo);
        $vehicleId = (int)($_POST['vehicle_id'] ?? ($_REQUEST['vehicle_id'] ?? 0));

        if ($orderId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        if ($vehicleId <= 0) {
            $res = unassignOrderFromVehicle($pdo, $orderId);
            echo json_encode($res);
            exit;
        }

        $res = assignOrderToVehicle($pdo, $orderId, $vehicleId);
        echo json_encode($res);
        exit;

    case 'unassign_order':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $orderId = getRequestedOrderId($pdo);
        if ($orderId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
            exit;
        }

        $res = unassignOrderFromVehicle($pdo, $orderId);
        echo json_encode($res);
        exit;

    case 'update_vehicle_status':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $allowed = ['active', 'loading', 'dispatched', 'inactive'];

        if ($vehicleId <= 0 || !in_array($status, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid vehicle ID or status.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
            $stmt->execute([$status, $vehicleId]);
            echo json_encode(['success' => true, 'message' => "Vehicle status updated to {$status}."]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;

    case 'reset_vehicle_load':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
            exit;
        }

        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vehicleId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid vehicle ID.']);
            exit;
        }

        $ok = resetVehicleLoadRecord($pdo, $vehicleId);
        if ($ok) {
            echo json_encode(['success' => true, 'message' => 'Vehicle loading reset successfully. History preserved.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to reset vehicle load.']);
        }
        exit;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action parameter.']);
        exit;
}
