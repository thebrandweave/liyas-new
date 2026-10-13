<?php
/**
 * admin/vehicles/index.php
 * Fleet Vehicles Management, Case Loading, and Zone Breakdown Hub.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/activity_logger.php';

date_default_timezone_set('Asia/Kolkata');

$admin_name   = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "vehicles";
$page_title   = "Vehicles & Loading Hub";

// --- FLASH MESSAGES ---
$success_msg = $_SESSION['success_message'] ?? null;
$error_msg   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- POST ACTIONS (Form Submissions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // 1. ADD NEW VEHICLE
    if ($action === 'add_vehicle') {
        $res = addVehicleRecord($pdo, [
            'vehicle_number' => $_POST['vehicle_number'] ?? '',
            'vehicle_name'   => $_POST['vehicle_name'] ?? '',
            'driver_name'    => $_POST['driver_name'] ?? '',
            'driver_phone'   => $_POST['driver_phone'] ?? '',
            'zone_id'        => !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null,
            'capacity_cases' => !empty($_POST['capacity_cases']) ? (int)$_POST['capacity_cases'] : 100,
            'status'         => $_POST['status'] ?? 'active'
        ]);

        if ($res['success']) {
            quickLog($pdo, 'create', 'vehicle', $res['vehicle_id'], "Registered vehicle " . strtoupper($_POST['vehicle_number']));
            $_SESSION['success_message'] = $res['message'] ?? "Vehicle registered successfully.";
        } else {
            $_SESSION['error_message'] = $res['error'] ?? "Failed to add vehicle.";
        }
        header("Location: index.php");
        exit;
    }

    // 2. LOAD CASES ONTO VEHICLE
    if ($action === 'load_cases') {
        $vId      = (int)($_POST['vehicle_id'] ?? 0);
        $zId      = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $cases    = (int)($_POST['cases'] ?? 0);
        $shopName = trim($_POST['shop_name'] ?? '');
        $notes    = trim($_POST['notes'] ?? 'Manual Load');

        if ($vId <= 0 || $cases <= 0) {
            $_SESSION['error_message'] = "Please select a valid vehicle and positive number of cases.";
        } else {
            $ok = recordVehicleLoad($pdo, $vId, $zId, $cases, null, $notes, $shopName);
            if ($ok) {
                quickLog($pdo, 'update', 'vehicle', $vId, "Loaded {$cases} cases onto vehicle #{$vId}" . ($shopName ? " for {$shopName}" : ''));
                $_SESSION['success_message'] = "Successfully loaded {$cases} cases onto the vehicle" . ($shopName ? " for " . htmlspecialchars($shopName) : '') . ".";
            } else {
                $_SESSION['error_message'] = "Could not record vehicle load entry.";
            }
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 3. UPDATE STATUS (Active / Loading / Dispatched / Inactive)
    if ($action === 'update_status') {
        $vId    = (int)($_POST['vehicle_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $allowed = ['active', 'loading', 'dispatched', 'inactive'];

        if ($vId > 0 && in_array($status, $allowed)) {
            $stmt = $pdo->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
            $stmt->execute([$status, $vId]);
            quickLog($pdo, 'update', 'vehicle', $vId, "Updated vehicle status to {$status}");
            $_SESSION['success_message'] = "Vehicle status updated to " . ucfirst($status) . ".";
        } else {
            $_SESSION['error_message'] = "Invalid status update.";
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 4. RESET LOAD (Clear active trip load, but NEVER reset lifetime deliveries and load history!)
    if ($action === 'reset_load') {
        $vId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vId > 0) {
            resetVehicleLoadRecord($pdo, $vId);
            quickLog($pdo, 'update', 'vehicle', $vId, "Cleared active trip load for vehicle #{$vId} (lifetime stats preserved)");
            $_SESSION['success_message'] = "Vehicle current load cleared for next trip. Lifetime delivery and load stats preserved.";
        }
        $redirectZone = $_GET['zone'] ?? 'all';
        header("Location: index.php?zone=" . urlencode($redirectZone));
        exit;
    }

    // 5. EDIT VEHICLE DETAILS
    if ($action === 'edit_vehicle') {
        $vId        = (int)($_POST['vehicle_id'] ?? 0);
        $vNumber    = strtoupper(trim($_POST['vehicle_number'] ?? ''));
        $vName      = trim($_POST['vehicle_name'] ?? '');
        $driverName = trim($_POST['driver_name'] ?? '');
        $phone      = trim($_POST['driver_phone'] ?? '');
        $zId        = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $capacity   = !empty($_POST['capacity_cases']) ? (int)$_POST['capacity_cases'] : 0;

        if ($vId > 0 && !empty($vNumber)) {
            $chk = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? AND id != ? LIMIT 1");
            $chk->execute([$vNumber, $vId]);
            if ($chk->fetch()) {
                $_SESSION['error_message'] = "Vehicle number '{$vNumber}' is already in use by another vehicle.";
            } else {
                $upd = $pdo->prepare("
                    UPDATE vehicles 
                    SET vehicle_number = ?, vehicle_name = ?, driver_name = ?, driver_phone = ?, zone_id = ?, capacity_cases = ? 
                    WHERE id = ?
                ");
                $upd->execute([$vNumber, $vName, $driverName, $phone, $zId, $capacity, $vId]);
                quickLog($pdo, 'update', 'vehicle', $vId, "Updated vehicle details for {$vNumber}");
                $_SESSION['success_message'] = "Vehicle details updated successfully.";
            }
        }
        header("Location: index.php");
        exit;
    }

    // 6. DELETE VEHICLE
    if ($action === 'delete_vehicle') {
        $vId = (int)($_POST['vehicle_id'] ?? 0);
        if ($vId > 0) {
            $pdo->prepare("DELETE FROM vehicle_loads WHERE vehicle_id = ?")->execute([$vId]);
            $pdo->prepare("UPDATE orders SET vehicle_id = NULL WHERE vehicle_id = ?")->execute([$vId]);
            $pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$vId]);
            quickLog($pdo, 'delete', 'vehicle', $vId, "Deleted vehicle #{$vId}");
            $_SESSION['success_message'] = "Vehicle removed from fleet.";
        }
        header("Location: index.php");
        exit;
    }
}

// --- FILTERS & FETCH ---
$filterZone   = trim($_GET['zone'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all');
$searchQuery  = trim($_GET['search'] ?? '');
$viewMode     = trim($_GET['view'] ?? 'cards'); // 'cards' or 'table'

$fleetData = getAllVehiclesWithLoads($pdo, $filterZone);
$vehicles  = $fleetData['vehicles'] ?? [];
$zonesList = $fleetData['zones'] ?? [];
$summary   = $fleetData['summary'] ?? [];

// Fetch distinct shop names across orders for the loading dropdown
$allShopsList = function_exists('getAllShopNamesWithZones') ? getAllShopNamesWithZones($pdo) : [];

// Calculate per-zone case totals across all vehicles
$zoneCaseTotals = [];
foreach ($zonesList as $z) {
    $zoneCaseTotals[(int)$z['id']] = 0;
}
foreach ($vehicles as $v) {
    if (!empty($v['zone_breakdown'])) {
        foreach ($v['zone_breakdown'] as $zb) {
            $zId = (int)$zb['zone_id'];
            $zoneCaseTotals[$zId] = ($zoneCaseTotals[$zId] ?? 0) + (int)$zb['cases'];
        }
    }
}

// Fleet-wide cumulative delivery & loads statistics (never reset)
$fleetDeliveriesDone = 0;
$fleetLoadsDelivered = 0;
foreach ($fleetData['vehicles'] as $fv) {
    $fleetDeliveriesDone += (int)($fv['deliveries_done'] ?? 0);
    $fleetLoadsDelivered += (int)($fv['loads_taken_to_delivery'] ?? 0);
}

// Apply client search & status filters
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

if ($filterStatus !== 'all') {
    $vehicles = array_filter($vehicles, function($v) use ($filterStatus) {
        return ($v['status'] ?? '') === $filterStatus;
    });
}

// Status Breakdown counts
$countActive     = 0;
$countLoading    = 0;
$countDispatched = 0;
$countInactive   = 0;
foreach ($fleetData['vehicles'] as $v) {
    $st = $v['status'] ?? 'active';
    if ($st === 'active') $countActive++;
    elseif ($st === 'loading') $countLoading++;
    elseif ($st === 'dispatched') $countDispatched++;
    elseif ($st === 'inactive') $countInactive++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Liyas International</title>
    <link rel="icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', 'Poppins', sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }

        /* Top Action Bar */
        .v-page-header {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .v-page-title-box h1 {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .v-page-title-box p {
            font-size: 13px;
            color: #64748b;
            margin: 3px 0 0 0;
        }
        .v-header-btns {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Primary Action Buttons */
        .btn-v-primary {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s ease;
        }
        .btn-v-primary:hover { background: #1d4ed8; color: #ffffff; }

        .btn-v-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-v-secondary:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; }

        /* KPI Cards Grid */
        .v-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .v-stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.15s ease;
        }
        .v-stat-card:hover { transform: translateY(-2px); }
        .v-stat-num {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }
        .v-stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-top: 4px;
        }
        .v-stat-icon-wrap {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .v-icon-blue { background: #eff6ff; color: #2563eb; }
        .v-icon-emerald { background: #ecfdf5; color: #059669; }
        .v-icon-amber { background: #fffbeb; color: #d97706; }
        .v-icon-purple { background: #faf5ff; color: #7c3aed; }

        /* Filter & Navigation Bar */
        .v-filter-toolbar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .v-zone-tabs {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .v-zone-tab {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .v-zone-tab:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .v-zone-tab.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            font-weight: 700;
        }
        .v-zone-tab .badge {
            background: rgba(0,0,0,0.08);
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 11px;
        }
        .v-zone-tab.active .badge {
            background: rgba(255,255,255,0.25);
            color: #ffffff;
        }

        .v-filter-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .v-search-box {
            position: relative;
            display: flex;
            align-items: center;
        }
        .v-search-box input {
            padding: 7px 12px 7px 32px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            width: 200px;
            background: #ffffff;
            transition: all 0.15s ease;
        }
        .v-search-box input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37,99,235,0.15);
            width: 240px;
        }
        .v-search-box i {
            position: absolute;
            left: 10px;
            color: #94a3b8;
            font-size: 16px;
        }

        /* Vehicle Cards Grid */
        .v-fleet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .v-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            position: relative;
        }
        .v-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px -4px rgba(0,0,0,0.08);
            border-color: #cbd5e1;
        }

        .v-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 12px;
        }
        .v-plate-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #0f172a;
            color: #f8fafc;
            font-weight: 800;
            font-size: 14px;
            padding: 5px 12px;
            border-radius: 8px;
            letter-spacing: 0.04em;
        }
        .v-model-name {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-top: 4px;
        }
        .v-home-zone {
            font-size: 11px;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-top: 2px;
        }

        /* Status Pills */
        .v-status-pill {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .v-pill-active { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .v-pill-loading { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .v-pill-dispatched { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .v-pill-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* Driver info row */
        .v-driver-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .v-driver-row a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Lifetime Persistent Vehicle Stats (Never Reset) */
        .v-lifetime-stats-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 12px;
        }
        .v-stat-pill-box {
            border-radius: 8px;
            padding: 8px 10px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .v-stat-pill-box.green {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
        }
        .v-stat-pill-box.blue {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
        }
        .v-stat-pill-lbl {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .v-stat-pill-box.green .v-stat-pill-lbl { color: #065f46; }
        .v-stat-pill-box.blue .v-stat-pill-lbl { color: #1e40af; }
        .v-stat-pill-val {
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
        }
        .v-stat-pill-box.green .v-stat-pill-val { color: #047857; }
        .v-stat-pill-box.blue .v-stat-pill-val { color: #1d4ed8; }
        .v-stat-pill-sub {
            font-size: 10px;
            font-weight: 600;
            color: #64748b;
            margin-top: 2px;
        }

        /* Loading metric block */
        .v-load-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .v-load-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .v-load-primary {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }
        .v-load-cap {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        .v-load-zone-focus {
            font-size: 12px;
            color: #2563eb;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Progress Bar */
        .v-progress-track {
            height: 9px;
            background: #e2e8f0;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 6px;
        }
        .v-progress-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.3s ease;
        }
        .fill-green { background: linear-gradient(90deg, #10b981, #059669); }
        .fill-amber { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .fill-red { background: linear-gradient(90deg, #ef4444, #dc2626); }

        /* Zones Breakdown Chips */
        .v-breakdown-sec {
            margin-bottom: 14px;
        }
        .v-breakdown-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.03em;
            margin-bottom: 6px;
        }
        .v-zone-chips-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .v-chip {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Card Actions */
        .v-card-footer {
            border-top: 1px solid #f1f5f9;
            padding-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
        }
        .v-action-btn {
            padding: 6px 10px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .v-action-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .btn-act-load {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }
        .btn-act-load:hover {
            background: #1d4ed8;
            color: #ffffff;
        }
        .btn-act-dispatch {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }
        .btn-act-dispatch:hover { background: #d1fae5; }
        .btn-act-reset {
            color: #dc2626;
            background: #fef2f2;
            border-color: #fecaca;
        }
        .btn-act-reset:hover { background: #fee2e2; }

        /* Modal Styles */
        .v-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(2px);
            z-index: 10050;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .v-modal-backdrop.open {
            display: flex;
            animation: fadeIn 0.15s ease;
        }
        .v-modal-dialog {
            background: #ffffff;
            border-radius: 16px;
            max-width: 480px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
        }
        .v-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
        }
        .v-modal-title {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .v-modal-close {
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
        .v-modal-close:hover { background: #e2e8f0; color: #0f172a; }

        .form-group-v {
            margin-bottom: 12px;
        }
        .form-group-v label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 4px;
        }
        .form-input-v, .form-select-v {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
            box-sizing: border-box;
            font-family: inherit;
        }
        .form-input-v:focus, .form-select-v:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .modal-btns-row {
            display: flex;
            gap: 8px;
            margin-top: 18px;
        }
        .modal-btns-row button {
            flex: 1;
            padding: 10px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
        }

        /* Flash Alerts */
        .v-alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .v-alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .v-alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        @media (max-width: 768px) {
            .v-fleet-grid { grid-template-columns: 1fr; }
            .v-search-box input { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Main Sidebar -->
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <div class="main-content">
            <!-- Breadcrumbs -->
            <div class="header">
                <div class="breadcrumb">
                    <a href="../dashboard/index.php" style="color: inherit; text-decoration: none;">
                        <i class='bx bx-home'></i> Admin
                    </a>
                    <i class='bx bx-chevron-right'></i>
                    <span>Vehicles Fleet & Loading</span>
                </div>
                <div class="header-actions">
             
              
                </div>
            </div>

            <div class="content-area" style="padding-top: 1rem;">
                <!-- Flash Notification Messages -->
                <?php if ($success_msg): ?>
                    <div class="v-alert v-alert-success">
                        <i class='bx bx-check-circle' style="font-size: 18px;"></i>
                        <span><?= htmlspecialchars($success_msg) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="v-alert v-alert-error">
                        <i class='bx bx-error-circle' style="font-size: 18px;"></i>
                        <span><?= htmlspecialchars($error_msg) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Page Header Hero Banner -->
                <div class="v-page-header">
                    <div class="v-page-title-box">
                        <h1>
                            <i class='bx bxs-truck' style="color: #2563eb; font-size: 26px;"></i>
                            Vehicles & Case Loading Hub
                        </h1>
                    
                    </div>
                    <div class="v-header-btns">
                        <a href="index.php" class="btn-v-secondary" title="Reload Fleet Data">
                            <i class='bx bx-refresh'></i> Refresh
                        </a>
                        <button type="button" class="btn-v-primary" onclick="openAddVehicleModal()">
                            <i class='bx bx-plus'></i> Add New Vehicle
                        </button>
                    </div>
                </div>

                <!-- 1. KPI Metric Summary Cards -->
                <div class="v-kpi-grid">
                    <!-- Total Vehicles -->
                    <div class="v-stat-card">
                        <div>
                            <div class="v-stat-num"><?= (int)($summary['total_vehicles'] ?? count($vehicles)) ?></div>
                            <div class="v-stat-label">Total Fleet Vehicles</div>
                        </div>
                        <div class="v-stat-icon-wrap v-icon-blue">
                            <i class='bx bxs-truck'></i>
                        </div>
                    </div>

                    <!-- Deliveries Done (Lifetime - Never Reset) -->
                    <div class="v-stat-card">
                        <div>
                            <div class="v-stat-num" style="color: #047857;"><?= number_format($fleetDeliveriesDone) ?></div>
                            <div class="v-stat-label">Total Deliveries Done</div>
                        </div>
                        <div class="v-stat-icon-wrap v-icon-emerald">
                            <i class='bx bx-check-double'></i>
                        </div>
                    </div>

                    <!-- Loads Taken to Delivery (Lifetime Cases - Never Reset) -->
                    <div class="v-stat-card">
                        <div>
                            <div class="v-stat-num" style="color: #1d4ed8;">
                                <?= number_format($fleetLoadsDelivered) ?> cs
                            </div>
                            <div class="v-stat-label">Total Loads Taken to Delivery</div>
                        </div>
                        <div class="v-stat-icon-wrap v-icon-blue">
                            <i class='bx bx-layer'></i>
                        </div>
                    </div>

                    <!-- Current Active Cases Loaded -->
                    <div class="v-stat-card">
                        <div>
                            <div class="v-stat-num" style="color: #d97706;">
                                <?= number_format($summary['grand_total_cases'] ?? 0) ?> cs
                            </div>
                            <div class="v-stat-label">Current Active Load (In Yard)</div>
                        </div>
                        <div class="v-stat-icon-wrap v-icon-amber">
                            <i class='bx bx-package'></i>
                        </div>
                    </div>
                </div>

                <!-- 2. Zone Filter Toolbar & Search Bar -->
                <div class="v-filter-toolbar">
                    <!-- Zone Selector Tabs -->
                    <div class="v-zone-tabs">
                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-right: 4px;">
                            <i class='bx bx-map-pin'></i> Loading Zone:
                        </span>

                        <a href="index.php?zone=all<?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?><?= $filterStatus !== 'all' ? '&status=' . urlencode($filterStatus) : '' ?>" 
                           class="v-zone-tab <?= ($filterZone === 'all') ? 'active' : '' ?>">
                            All Zones <span class="badge"><?= number_format($summary['grand_total_cases'] ?? 0) ?> cs</span>
                        </a>

                        <?php foreach ($zonesList as $z): ?>
                            <?php 
                                $zId = (int)$z['id'];
                                $zCases = $zoneCaseTotals[$zId] ?? 0;
                                $isZActive = ($filterZone === (string)$zId || $filterZone === $z['slug']);
                            ?>
                            <a href="index.php?zone=<?= urlencode($z['slug']) ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?><?= $filterStatus !== 'all' ? '&status=' . urlencode($filterStatus) : '' ?>" 
                               class="v-zone-tab <?= $isZActive ? 'active' : '' ?>" title="<?= htmlspecialchars($z['name']) ?>">
                                <?= htmlspecialchars($z['name']) ?>
                                <span class="badge"><?= number_format($zCases) ?> cs</span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Search & Status Filter -->
                    <div class="v-filter-right">
                        <form method="GET" action="index.php" style="display: flex; gap: 8px;">
                            <input type="hidden" name="zone" value="<?= htmlspecialchars($filterZone) ?>">
                            
                            <select name="status" class="form-select-v" style="padding: 7px 10px; font-size: 12px; width: 120px;" onchange="this.form.submit()">
                                <option value="all" <?= ($filterStatus === 'all') ? 'selected' : '' ?>>All Status</option>
                                <option value="active" <?= ($filterStatus === 'active') ? 'selected' : '' ?>>Ready (<?= $countActive ?>)</option>
                                <option value="loading" <?= ($filterStatus === 'loading') ? 'selected' : '' ?>>Loading (<?= $countLoading ?>)</option>
                                <option value="dispatched" <?= ($filterStatus === 'dispatched') ? 'selected' : '' ?>>Dispatched (<?= $countDispatched ?>)</option>
                                <option value="inactive" <?= ($filterStatus === 'inactive') ? 'selected' : '' ?>>Inactive (<?= $countInactive ?>)</option>
                            </select>

                            <div class="v-search-box">
                                <i class='bx bx-search'></i>
                                <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search plate / driver...">
                            </div>

                            <?php if ($searchQuery || $filterStatus !== 'all'): ?>
                                <a href="index.php?zone=<?= urlencode($filterZone) ?>" class="btn-v-secondary" style="padding: 7px 10px;" title="Clear Search">
                                    <i class='bx bx-x'></i>
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- 3. Vehicles Fleet Grid: Every Vehicle Details, Amount of Cases Loaded & Zones -->
                <?php if (empty($vehicles)): ?>
                    <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 14px; padding: 50px 20px; text-align: center; color: #64748b; margin-bottom: 2rem;">
                        <i class='bx bxs-truck' style="font-size: 46px; color: #cbd5e1; margin-bottom: 12px;"></i>
                        <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">No Vehicles Found</h3>
                        <p style="font-size: 13px; max-width: 420px; margin: 0 auto 16px auto;">
                            <?= !empty($searchQuery) ? 'No vehicles match your search criteria. Try clearing the filter.' : 'No vehicles are registered yet. Register your first delivery vehicle to start tracking loading cases.' ?>
                        </p>
                        <button type="button" class="btn-v-primary" onclick="openAddVehicleModal()">
                            <i class='bx bx-plus-circle'></i> Register New Vehicle
                        </button>
                    </div>
                <?php else: ?>
                    <div class="v-fleet-grid">
                        <?php foreach ($vehicles as $v): ?>
                            <?php
                                $vId        = (int)$v['id'];
                                $vNum       = htmlspecialchars($v['vehicle_number'] ?? 'VEHICLE');
                                $vName      = htmlspecialchars($v['vehicle_name'] ?? 'Delivery Van');
                                $driverName = htmlspecialchars($v['driver_name'] ?? 'Driver Unassigned');
                                $driverPhone= htmlspecialchars($v['driver_phone'] ?? '');
                                $status     = $v['status'] ?? 'active';
                                $totalLoaded= (int)($v['total_cases_loaded'] ?? 0);
                                $zoneCases  = (int)($v['current_zone_cases'] ?? 0);
                                $ordersCount= (int)($v['assigned_orders_count'] ?? 0);
                                $homeZone   = htmlspecialchars($v['home_zone_name'] ?? 'Central');
                                $deliveriesDone = (int)($v['deliveries_done'] ?? 0);
                                $loadsTaken     = (int)($v['loads_taken_to_delivery'] ?? 0);

                                // Status styling
                                $statusClass = 'v-pill-active';
                                $statusText  = 'Ready in Yard';
                                if ($status === 'loading') {
                                    $statusClass = 'v-pill-loading';
                                    $statusText  = 'Loading';
                                } elseif ($status === 'dispatched') {
                                    $statusClass = 'v-pill-dispatched';
                                    $statusText  = 'Dispatched';
                                } elseif ($status === 'inactive') {
                                    $statusClass = 'v-pill-inactive';
                                    $statusText  = 'Inactive';
                                }

                             // Calculate vehicle load percentage safely
$capacity = (int)($v['capacity_cases'] ?? 100);
$capacity = max(1, $capacity); // Prevent division by zero

$percent = min(100, ($totalLoaded / $capacity) * 100);

// Progress fill color
$fillClass = 'fill-green';

if ($percent > 85) {
    $fillClass = 'fill-red';
} elseif ($percent > 60) {
    $fillClass = 'fill-amber';
}
                            ?>
                            <div class="v-card" id="card_vehicle_<?= $vId ?>">
                                <div>
                                    <!-- Header: Plate Number, Model & Status Badge -->
                                    <div class="v-card-top">
                                        <div>
                                            <div class="v-plate-tag">
                                                <i class='bx bxs-truck'></i> <?= $vNum ?>
                                            </div>
                                            <div class="v-model-name"><?= $vName ?></div>
                                            <div class="v-home-zone">
                                                <i class='bx bx-map-pin'></i> Base Zone: <strong><?= $homeZone ?></strong>
                                            </div>
                                        </div>
                                        <span class="v-status-pill <?= $statusClass ?>">
                                            <?= $statusText ?>
                                        </span>
                                    </div>

                                    <!-- Driver Information -->
                                    <div class="v-driver-row">
                                        <span>
                                            <i class='bx bx-user' style="color: #64748b;"></i> 
                                            <strong><?= $driverName ?></strong>
                                        </span>
                                        <?php if (!empty($driverPhone)): ?>
                                            <a href="tel:<?= $driverPhone ?>" title="Call Driver">
                                                <i class='bx bx-phone'></i> <?= $driverPhone ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 11px; color: #94a3b8;">No phone</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Lifetime Persistent Metrics (Deliveries Done & Loads Taken to Delivery - Never Reset) -->
                                    <div class="v-lifetime-stats-box">
                                        <div class="v-stat-pill-box green" title="Total delivered orders completed by this vehicle (Never reset)">
                                            <div class="v-stat-pill-lbl"><i class='bx bx-check-circle'></i> Deliveries Done</div>
                                            <div class="v-stat-pill-val"><?= number_format($deliveriesDone) ?></div>
                                            <!-- <div class="v-stat-pill-sub">Completed Deliveries</div> -->
                                        </div>
                                        <div class="v-stat-pill-box blue" title="Total cumulative load cases taken out to delivery (Never reset)">
                                            <div class="v-stat-pill-lbl"><i class='bx bx-layer'></i> Loads Delivered</div>
                                            <div class="v-stat-pill-val"><?= number_format($loadsTaken) ?> cs</div>
                                            <!-- <div class="v-stat-pill-sub">Cases to Delivery</div> -->
                                        </div>
                                    </div>

                                    <!-- Zone-Specific Case Count -->
                                    <div class="v-load-zone-focus">
                                        <i class='bx bx-info-circle'></i>
                                        <?php if ($filterZone === 'all'): ?>
                                            <span>Total <?= $totalLoaded ?> cases loaded across all zones</span>
                                        <?php else: ?>
                                            <span>From Selected Zone: <strong><?= $zoneCases ?> cases</strong></span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Zones Breakdown: Exact Cases Loaded for Each Zone -->
                                    <div class="v-breakdown-sec">
                                        <div class="v-breakdown-title">
                                            <i class='bx bx-map-alt'></i> Zones Breakdown:
                                        </div>
                                        <div class="v-zone-chips-wrap">
                                            <?php if (!empty($v['zone_breakdown'])): ?>
                                                <?php foreach ($v['zone_breakdown'] as $zb): ?>
                                                    <span class="v-chip" title="Zone: <?= htmlspecialchars($zb['zone_name']) ?>">
                                                        <i class='bx bx-map-pin'></i> 
                                                        <?= htmlspecialchars($zb['zone_name']) ?>: 
                                                        <strong><?= (int)$zb['cases'] ?> cs</strong>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span style="font-size: 12px; color: #94a3b8; font-style: italic;">
                                                    No cases loaded yet &bull; Vehicle empty
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                             
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- ========================================================
         MODAL 1: REGISTER NEW VEHICLE
         ======================================================== -->
    <div id="modalAddVehicle" class="v-modal-backdrop" onclick="if(event.target===this) closeAddVehicleModal()">
        <div class="v-modal-dialog">
            <div class="v-modal-header">
                <h3 class="v-modal-title">
                    <i class='bx bxs-truck' style="color: #2563eb;"></i> Register Vehicle to Fleet
                </h3>
                <button type="button" class="v-modal-close" onclick="closeAddVehicleModal()">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="add_vehicle">
                
                <div class="form-group-v">
                    <label>Vehicle Number / License Plate *</label>
                    <input type="text" name="vehicle_number" class="form-input-v" placeholder="e.g. KA 19 AB 1234 / TN 38 XY 5678" required style="text-transform: uppercase;">
                </div>

                <div class="form-group-v">
                    <label>Vehicle Model / Type</label>
                    <input type="text" name="vehicle_name" class="form-input-v" placeholder="e.g. Tata Ace / Bolero">
                </div>

                <div class="form-grid-2">
                    <div class="form-group-v">
                        <label>Driver Name</label>
                        <input type="text" name="driver_name" class="form-input-v" placeholder="Driver full name">
                    </div>
                    <div class="form-group-v">
                        <label>Driver Phone Number</label>
                        <input type="tel" name="driver_phone" class="form-input-v" placeholder="10-digit mobile">
                    </div>
                </div>

                <div class="form-group-v">
                    <label>Base / Home Zone</label>
                    <select name="zone_id" class="form-select-v">
                        <option value="">-- All / Central Warehouse --</option>
                        <?php foreach ($zonesList as $z): ?>
                            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-v-primary" style="justify-content: center;">
                        <i class='bx bx-check'></i> Register Vehicle
                    </button>
                    <button type="button" class="btn-v-secondary" onclick="closeAddVehicleModal()" style="justify-content: center;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 2: QUICK LOAD CASES ONTO VEHICLE
         ======================================================== -->
    <div id="modalLoadCases" class="v-modal-backdrop" onclick="if(event.target===this) closeLoadCasesModal()">
        <div class="v-modal-dialog">
            <div class="v-modal-header">
                <h3 class="v-modal-title">
                    <i class='bx bx-package' style="color: #2563eb;"></i> 
                    <span id="loadModalTitle">Load Cases onto Vehicle</span>
                </h3>
                <button type="button" class="v-modal-close" onclick="closeLoadCasesModal()">&times;</button>
            </div>
            <form method="POST" action="index.php?zone=<?= urlencode($filterZone) ?>">
                <input type="hidden" name="action" value="load_cases">
                <input type="hidden" name="vehicle_id" id="loadVehicleId" value="">

                <div class="form-group-v">
                    <label>Source / Dispatch Zone *</label>
                    <select name="zone_id" id="loadZoneSelect" class="form-select-v" required>
                        <?php foreach ($zonesList as $z): ?>
                            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group-v">
                    <label>Number of Cases to Load *</label>
                    <input type="number" name="cases" id="loadCasesInput" class="form-input-v" placeholder="e.g. 50" min="1" required autofocus>
                </div>

                <div class="form-group-v">
                    <label><i class='bx bx-store'></i> Shop Name (Fetched from Orders)</label>
                    <input type="text" name="shop_name" id="loadShopNameInput" list="loadShopNamesList" class="form-input-v" placeholder="Select or type shop name..." autocomplete="off">
                    <datalist id="loadShopNamesList">
                        <?php foreach ($allShopsList as $s): ?>
                            <option value="<?= htmlspecialchars($s['shop_name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group-v">
                    <label>Loading Notes (Optional)</label>
                    <input type="text" name="notes" class="form-input-v" placeholder="e.g. Route 1 Morning Dispatch">
                </div>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-v-primary" style="justify-content: center;">
                        <i class='bx bx-check'></i> Confirm Load Cases
                    </button>
                    <button type="button" class="btn-v-secondary" onclick="closeLoadCasesModal()" style="justify-content: center;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================
         MODAL 3: EDIT VEHICLE DETAILS
         ======================================================== -->
    <div id="modalEditVehicle" class="v-modal-backdrop" onclick="if(event.target===this) closeEditVehicleModal()">
        <div class="v-modal-dialog">
            <div class="v-modal-header">
                <h3 class="v-modal-title">
                    <i class='bx bx-edit' style="color: #2563eb;"></i> Edit Vehicle Details
                </h3>
                <button type="button" class="v-modal-close" onclick="closeEditVehicleModal()">&times;</button>
            </div>
            <form method="POST" action="index.php">
                <input type="hidden" name="action" value="edit_vehicle">
                <input type="hidden" name="vehicle_id" id="editVehicleId" value="">

                <div class="form-group-v">
                    <label>Vehicle Number / License Plate *</label>
                    <input type="text" name="vehicle_number" id="editVehicleNumber" class="form-input-v" required style="text-transform: uppercase;">
                </div>

                <input type="hidden" name="capacity_cases" id="editVehicleCapacity" value="0">
                <div class="form-group-v">
                    <label>Vehicle Model / Type</label>
                    <input type="text" name="vehicle_name" id="editVehicleName" class="form-input-v">
                </div>

                <div class="form-grid-2">
                    <div class="form-group-v">
                        <label>Driver Name</label>
                        <input type="text" name="driver_name" id="editDriverName" class="form-input-v">
                    </div>
                    <div class="form-group-v">
                        <label>Driver Phone Number</label>
                        <input type="tel" name="driver_phone" id="editDriverPhone" class="form-input-v">
                    </div>
                </div>

                <div class="form-group-v">
                    <label>Base / Home Zone</label>
                    <select name="zone_id" id="editZoneId" class="form-select-v">
                        <option value="">-- All / Central Warehouse --</option>
                        <?php foreach ($zonesList as $z): ?>
                            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-btns-row">
                    <button type="submit" class="btn-v-primary" style="justify-content: center;">
                        <i class='bx bx-check'></i> Save Changes
                    </button>
                    <button type="button" class="btn-v-secondary" onclick="closeEditVehicleModal()" style="justify-content: center;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Vehicles Modal Interaction Scripts -->
    <script>
        function openAddVehicleModal() {
            const m = document.getElementById('modalAddVehicle');
            if (m) m.classList.add('open');
        }
        function closeAddVehicleModal() {
            const m = document.getElementById('modalAddVehicle');
            if (m) m.classList.remove('open');
        }

        function openLoadCasesModal(vId, vNumber) {
            const m = document.getElementById('modalLoadCases');
            const vIdIn = document.getElementById('loadVehicleId');
            const title = document.getElementById('loadModalTitle');
            const casesIn = document.getElementById('loadCasesInput');
            const shopIn = document.getElementById('loadShopNameInput');
            const notesIn = document.querySelector('#modalLoadCases input[name="notes"]');

            if (vIdIn) vIdIn.value = vId;
            if (title) title.textContent = 'Load Cases: ' + vNumber;
            if (shopIn) shopIn.value = '';
            if (notesIn) notesIn.value = '';
            if (casesIn) {
                casesIn.value = '';
                setTimeout(() => casesIn.focus(), 100);
            }
            if (m) m.classList.add('open');
        }
        function closeLoadCasesModal() {
            const m = document.getElementById('modalLoadCases');
            if (m) m.classList.remove('open');
        }

        function openEditVehicleModal(vData) {
            const m = document.getElementById('modalEditVehicle');
            document.getElementById('editVehicleId').value = vData.id || '';
            document.getElementById('editVehicleNumber').value = vData.vehicle_number || '';
            document.getElementById('editVehicleName').value = vData.vehicle_name || '';
            document.getElementById('editDriverName').value = vData.driver_name || '';
            document.getElementById('editDriverPhone').value = vData.driver_phone || '';
            document.getElementById('editVehicleCapacity').value = vData.capacity_cases || 100;
            document.getElementById('editZoneId').value = vData.zone_id || '';

            if (m) m.classList.add('open');
        }
        function closeEditVehicleModal() {
            const m = document.getElementById('modalEditVehicle');
            if (m) m.classList.remove('open');
        }

        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddVehicleModal();
                closeLoadCasesModal();
                closeEditVehicleModal();
            }
        });
    </script>
</body>
</html>
