<?php
/**
 * admin/vehicles/view.php
 * Dedicated Full-Page View for Vehicle Delivery Performance, Delivered Orders, and Financial Breakdowns.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/activity_logger.php';

date_default_timezone_set('Asia/Kolkata');

$admin_name   = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "vehicles";

$vehicleId = (int)($_GET['id'] ?? $_GET['vehicle_id'] ?? 0);
if ($vehicleId <= 0) {
    header("Location: index.php");
    exit;
}

// --- FLASH MESSAGES ---
$success_msg = $_SESSION['success_message'] ?? null;
$error_msg   = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- POST ACTIONS (Form Submissions) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // 1. LOAD CASES ONTO THIS VEHICLE
    if ($action === 'load_cases') {
        $vId      = (int)($_POST['vehicle_id'] ?? $vehicleId);
        $zId      = !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null;
        $cases    = (int)($_POST['cases'] ?? 0);
        $shopName = trim($_POST['shop_name'] ?? '');
        $notes    = trim($_POST['notes'] ?? 'Manual Load');

        if ($vId <= 0) {
            $_SESSION['error_message'] = "Invalid vehicle ID.";
        } elseif ($cases <= 0) {
            $_SESSION['error_message'] = "Please enter a positive number of cases (at least 1 case).";
        } else {
            $ok = recordVehicleLoad($pdo, $vId, $zId, $cases, null, $notes, $shopName);
            if ($ok) {
                quickLog($pdo, 'update', 'vehicle', $vId, "Loaded {$cases} cases onto vehicle #{$vId}" . ($shopName ? " for {$shopName}" : ''));
                $_SESSION['success_message'] = "Successfully loaded {$cases} cases onto the vehicle" . ($shopName ? " for " . htmlspecialchars($shopName) : '') . ".";
            } else {
                $_SESSION['error_message'] = "Could not record vehicle load entry.";
            }
        }
        header("Location: view.php?" . http_build_query($_GET));
        exit;
    }

    // 2. UPDATE VEHICLE DETAILS
    if ($action === 'edit_vehicle') {
        $vId        = (int)($_POST['vehicle_id'] ?? $vehicleId);
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
                $_SESSION['success_message'] = "Vehicle profile updated successfully.";
            }
        }
        header("Location: view.php?" . http_build_query($_GET));
        exit;
    }

    // 3. UPDATE VEHICLE STATUS
    if ($action === 'update_status') {
        $vId    = (int)($_POST['vehicle_id'] ?? $vehicleId);
        $status = trim($_POST['status'] ?? '');
        $allowed = ['active', 'loading', 'dispatched', 'inactive'];

        if ($vId > 0 && in_array($status, $allowed)) {
            $stmt = $pdo->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
            $stmt->execute([$status, $vId]);
            quickLog($pdo, 'update', 'vehicle', $vId, "Updated vehicle status to {$status}");
            $_SESSION['success_message'] = "Vehicle status updated to " . ucfirst($status) . ".";
        }
        header("Location: view.php?" . http_build_query($_GET));
        exit;
    }

    // 4. RESET TRIP LOAD
    if ($action === 'reset_load') {
        $vId = (int)($_POST['vehicle_id'] ?? $vehicleId);
        if ($vId > 0) {
            resetVehicleLoadRecord($pdo, $vId);
            quickLog($pdo, 'update', 'vehicle', $vId, "Cleared active trip load for vehicle #{$vId} (lifetime stats preserved)");
            $_SESSION['success_message'] = "Active trip load cleared for next trip. Delivery and load history preserved.";
        }
        header("Location: view.php?" . http_build_query($_GET));
        exit;
    }
}

// --- FILTERS & FETCH ---
$filterDate  = trim($_GET['date'] ?? 'all');
$fromDate    = trim($_GET['from_date'] ?? '');
$toDate      = trim($_GET['to_date'] ?? '');
$searchQuery = trim($_GET['search'] ?? '');

$validDate = function ($date) {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (($fromDate !== '' && $validDate($fromDate)) || ($toDate !== '' && $validDate($toDate))) {
    $filterDate = 'custom';
}

if (!in_array($filterDate, ['all', 'today', 'yesterday', 'weekly', 'monthly', 'this_week', 'this_month', 'custom'])) {
    $filterDate = 'all';
}

if ($filterDate === 'custom') {
    if (!empty($fromDate) && !empty($toDate)) {
        $currentDateLabel = date('d M Y', strtotime($fromDate)) . ' to ' . date('d M Y', strtotime($toDate));
    } elseif (!empty($fromDate)) {
        $currentDateLabel = 'From ' . date('d M Y', strtotime($fromDate));
    } else {
        $currentDateLabel = 'Up to ' . date('d M Y', strtotime($toDate));
    }
} else {
    $dateLabels = [
        'all'        => 'All Time',
        'today'      => 'Today (Daily)',
        'yesterday'  => 'Yesterday (Daily)',
        'weekly'     => 'This Week',
        'this_week'  => 'This Week',
        'monthly'    => 'This Month (Monthly)',
        'this_month' => 'This Month (Monthly)'
    ];
    $currentDateLabel = $dateLabels[$filterDate] ?? 'All Time';
}

// Fetch comprehensive vehicle delivery & financial details
$detailsData = getVehicleDeliveredOrdersDetails($pdo, $vehicleId, $filterDate, $fromDate, $toDate, $searchQuery);

if (!$detailsData['success'] || empty($detailsData['vehicle'])) {
    $_SESSION['error_message'] = "Vehicle record not found.";
    header("Location: index.php");
    exit;
}

$vehicle = $detailsData['vehicle'];
$summary = $detailsData['summary'];
$orders  = $detailsData['orders'];

$page_title = "Vehicle " . ($vehicle['vehicle_number'] ?: "Details");

// Zones list for modals
$zonesStmt = $pdo->query("SELECT id, name, slug FROM zones ORDER BY name ASC");
$zonesList = $zonesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Distinct shops list for loading modal
$allShopsList = function_exists('getAllShopNamesWithZones') ? getAllShopNamesWithZones($pdo) : [];

// Active trip load cases & yard zone breakdown for this vehicle
$activeLoadsStmt = $pdo->prepare("
    SELECT vl.zone_id, z.name as zone_name, SUM(vl.cases_loaded) as cases
    FROM vehicle_loads vl
    LEFT JOIN zones z ON vl.zone_id = z.id
    WHERE vl.vehicle_id = ? AND vl.is_active = 1
    GROUP BY vl.zone_id
");
$activeLoadsStmt->execute([$vehicleId]);
$activeZoneBreakdown = $activeLoadsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$activeLoadedCases = 0;
foreach ($activeZoneBreakdown as $zb) {
    $activeLoadedCases += (int)$zb['cases'];
}

$capacity = (int)($vehicle['capacity_cases'] ?? 100);
$capacityPercent = $capacity > 0 ? min(100, round(($activeLoadedCases / $capacity) * 100)) : 0;

// URL builder helper for maintaining filter state
function buildViewFilterUrl(array $overrides = []): string {
    global $vehicleId;
    $params = [
        'id'        => $vehicleId,
        'date'      => $_GET['date'] ?? 'all',
        'from_date' => $_GET['from_date'] ?? '',
        'to_date'   => $_GET['to_date'] ?? '',
        'search'    => $_GET['search'] ?? ''
    ];
    $merged = array_merge($params, $overrides);
    // If setting a preset date, clear custom dates
    if (isset($overrides['date']) && $overrides['date'] !== 'custom') {
        $merged['from_date'] = '';
        $merged['to_date']   = '';
    }
    if (!empty($merged['from_date']) || !empty($merged['to_date'])) {
        $merged['date'] = 'custom';
    }
    $queryParts = [];
    foreach ($merged as $k => $v) {
        if ($v !== '' && $v !== null && !($k === 'date' && $v === 'all')) {
            $queryParts[$k] = $v;
        }
    }
    return 'view.php?' . http_build_query($queryParts);
}

// Clean phone for whatsapp & call
$cleanPhone = preg_replace('/[^0-9]/', '', $vehicle['driver_phone'] ?? '');
if (strlen($cleanPhone) === 10) {
    $whatsappPhone = '91' . $cleanPhone;
} else {
    $whatsappPhone = $cleanPhone;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($vehicle['vehicle_number']) ?> - Delivery Details - Liyas Admin</title>
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

        /* Top Breadcrumb & Actions Bar */
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
        .v-breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .v-breadcrumb-nav a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }
        .v-breadcrumb-nav a:hover {
            text-decoration: underline;
        }
        .v-page-title-box h1 {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .v-header-btns {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Buttons */
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
        .btn-v-secondary:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #0f172a;
        }

        .btn-v-danger {
            background: #fff;
            color: #dc2626;
            border: 1px solid #fca5a5;
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
        .btn-v-danger:hover {
            background: #fee2e2;
            border-color: #f87171;
            color: #b91c1c;
        }

        /* Vehicle Profile Hero Card */
        .v-hero-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 24px;
            align-items: center;
        }
        @media (max-width: 900px) {
            .v-hero-card {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }
        .v-plate-badge-lg {
            background: #0f172a;
            color: #f8fafc;
            border: 2px solid #334155;
            padding: 12px 20px;
            border-radius: 10px;
            font-family: monospace, sans-serif;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 2px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.4);
            white-space: nowrap;
        }
        .v-hero-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .v-hero-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .v-hero-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 13px;
            color: #475569;
        }
        .v-hero-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .v-hero-meta-item strong {
            color: #0f172a;
        }

        /* Status Pills */
        .v-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .v-pill-active { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .v-pill-loading { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .v-pill-dispatched { background: #e0e7ff; color: #4338ca; border: 1px solid #c7d2fe; }
        .v-pill-inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

        /* KPI Summary Grid */
        .v-kpi-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 1200px) {
            .v-kpi-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .v-kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .v-kpi-grid { grid-template-columns: 1fr; }
        }

        .v-kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.15rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .v-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .v-kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .v-kpi-lbl {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }
        .v-kpi-icon {
            font-size: 18px;
            color: #94a3b8;
        }
        .v-kpi-val {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .v-kpi-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
            font-weight: 600;
        }

        /* Specialized KPI Themes */
        .v-kpi-cash { border-color: #a7f3d0; background: #ecfdf5; }
        .v-kpi-cash .v-kpi-lbl { color: #065f46; }
        .v-kpi-cash .v-kpi-val { color: #047857; }
        .v-kpi-cash .v-kpi-sub { color: #059669; }
        .v-kpi-cash .v-kpi-icon { color: #10b981; }

        .v-kpi-credit { border-color: #ddd6fe; background: #f5f3ff; }
        .v-kpi-credit .v-kpi-lbl { color: #5b21b6; }
        .v-kpi-credit .v-kpi-val { color: #6d28d9; }
        .v-kpi-credit .v-kpi-sub { color: #7c3aed; }
        .v-kpi-credit .v-kpi-icon { color: #8b5cf6; }

        .v-kpi-due { border-color: #fecaca; background: #fef2f2; }
        .v-kpi-due .v-kpi-lbl { color: #991b1b; }
        .v-kpi-due .v-kpi-val { color: #b91c1c; }
        .v-kpi-due .v-kpi-sub { color: #dc2626; }
        .v-kpi-due .v-kpi-icon { color: #ef4444; }

        .v-kpi-sales { border-color: #bfdbfe; background: #eff6ff; }
        .v-kpi-sales .v-kpi-lbl { color: #1e40af; }
        .v-kpi-sales .v-kpi-val { color: #2563eb; }
        .v-kpi-sales .v-kpi-sub { color: #3b82f6; }
        .v-kpi-sales .v-kpi-icon { color: #3b82f6; }

        /* Filter Toolbar Card */
        .v-filter-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .v-filter-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .v-filter-row-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .v-search-box {
            position: relative;
            flex: 1;
            min-width: 280px;
            max-width: 520px;
        }
        .v-search-box input {
            width: 100%;
            padding: 10px 38px 10px 38px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13px;
            background: #f8fafc;
            color: #0f172a;
            box-sizing: border-box;
            outline: none;
            transition: all 0.15s ease;
        }
        .v-search-box input:focus {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .v-search-box .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
            pointer-events: none;
        }
        .v-search-box .clear-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
        }
        .v-search-box .clear-icon:hover { color: #ef4444; }

        /* Filter Presets Row */
        .v-preset-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .v-preset-pill {
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .v-preset-pill:hover {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #94a3b8;
        }
        .v-preset-pill.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            font-weight: 700;
        }

        /* Custom Date Picker Inputs */
        .v-date-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .v-date-input-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .v-date-input-group input[type="date"] {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
        }
        .v-date-input-group input[type="date"]:focus {
            border-color: #2563eb;
            background: #ffffff;
        }

        /* Active Filters Notification Bar */
        .v-active-filter-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            padding: 8px 12px;
            background: #f1f5f9;
            border-radius: 8px;
            font-size: 12px;
            color: #334155;
            margin-top: 4px;
        }
        .v-filter-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        /* Orders Table Section Card */
        .v-orders-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .v-orders-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 10px;
        }
        .v-orders-card-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .v-count-badge {
            background: #2563eb;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
        }

        /* Data Table */
        .v-table-wrap {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
        }
        .v-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            text-align: left;
        }
        .v-data-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.04em;
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .v-data-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }
        .v-data-table tr:hover td {
            background: #f8fafc;
        }
        .v-data-table tfoot td {
            background: #f8fafc;
            font-weight: 800;
            border-top: 2px solid #cbd5e1;
            border-bottom: none;
            color: #0f172a;
        }

        .bill-badge {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 3px;
        }
        .due-badge-alert {
            display: inline-block;
            color: #b91c1c;
            font-weight: 700;
            background: #fee2e2;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #fca5a5;
        }

        /* Yard Load / Active Trip Card */
        .v-yard-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .v-chip {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* Modal Styles */
        .v-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .v-modal-overlay.open { display: flex; }
        .v-modal-card {
            background: #ffffff;
            border-radius: 16px;
            max-width: 520px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }
        .v-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
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
            background: none;
            border: none;
            font-size: 24px;
            line-height: 1;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
        }
        .v-modal-close:hover { color: #dc2626; }
        .v-form-group {
            margin-bottom: 14px;
        }
        .v-form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .v-form-group input, .v-form-group select, .v-form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            background: #f8fafc;
            color: #0f172a;
            box-sizing: border-box;
            outline: none;
        }
        .v-form-group input:focus, .v-form-group select:focus, .v-form-group textarea:focus {
            border-color: #2563eb;
            background: #ffffff;
        }
        .v-modal-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Main Sidebar -->
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <div class="main-content">
            <!-- Toast / Flash Messages -->
            <?php if (!empty($success_msg)): ?>
                <div style="background: #dcfce7; border: 1px solid #86efac; color: #15803d; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600;">
                    <i class='bx bx-check-circle' style="font-size: 20px;"></i>
                    <?= htmlspecialchars($success_msg) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_msg)): ?>
                <div style="background: #fee2e2; border: 1px solid #fca5a5; color: #b91c1c; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600;">
                    <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <!-- Header & Navigation Bar -->
            <div class="v-page-header">
                <div>
                    <div class="v-breadcrumb-nav">
                        <a href="../dashboard.php">Dashboard</a>
                        <i class='bx bx-chevron-right'></i>
                        <a href="index.php">Fleet Vehicles</a>
                        <i class='bx bx-chevron-right'></i>
                        <span><?= htmlspecialchars($vehicle['vehicle_number']) ?></span>
                    </div>
                    <div class="v-page-title-box">
                        <h1>
                            <i class='bx bxs-truck' style="color: #2563eb;"></i>
                            Vehicle Delivery Performance & Details
                        </h1>
                    </div>
                </div>
                <div class="v-header-btns">
                    <a href="index.php" class="btn-v-secondary" title="Return to fleet overview">
                        <i class='bx bx-left-arrow-alt'></i> Back to Fleet Hub
                    </a>
                    <button type="button" class="btn-v-primary" onclick="openLoadCasesModal()" title="Load cases onto this vehicle">
                        <i class='bx bx-package'></i> Load Cases
                    </button>
                    <button type="button" class="btn-v-secondary" onclick="openEditVehicleModal()" title="Edit vehicle registration & driver">
                        <i class='bx bx-edit'></i> Edit Vehicle
                    </button>
                </div>
            </div>

            <!-- Vehicle Identity Hero Banner -->
            <div class="v-hero-card">
                <div>
                    <div class="v-plate-badge-lg">
                        <i class='bx bxs-truck'></i> <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                    </div>
                </div>
                <div class="v-hero-info">
                    <div class="v-hero-title">
                        <span><?= htmlspecialchars($vehicle['vehicle_name'] ?: 'Delivery Vehicle') ?></span>
                        <?php
                            $st = strtolower($vehicle['status'] ?? 'active');
                            $statusClass = $st === 'loading' ? 'v-pill-loading' : ($st === 'dispatched' ? 'v-pill-dispatched' : ($st === 'inactive' ? 'v-pill-inactive' : 'v-pill-active'));
                        ?>
                        <span class="v-status-pill <?= $statusClass ?>">
                            <?= ucfirst($st) ?>
                        </span>
                    </div>
                    <div class="v-hero-meta">
                        <div class="v-hero-meta-item">
                            <i class='bx bx-user' style="color: #64748b; font-size: 16px;"></i>
                            <span>Driver: <strong><?= htmlspecialchars($vehicle['driver_name'] ?: 'Unassigned') ?></strong></span>
                        </div>
                        <?php if (!empty($vehicle['driver_phone'])): ?>
                            <div class="v-hero-meta-item">
                                <i class='bx bx-phone' style="color: #2563eb; font-size: 16px;"></i>
                                <a href="tel:<?= htmlspecialchars($vehicle['driver_phone']) ?>" style="color: #2563eb; text-decoration: none; font-weight: 700;">
                                    <?= htmlspecialchars($vehicle['driver_phone']) ?>
                                </a>
                                <?php if (!empty($whatsappPhone)): ?>
                                    <a href="https://wa.me/<?= $whatsappPhone ?>" target="_blank" style="color: #10b981; text-decoration: none; font-size: 16px; margin-left: 2px;" title="Chat on WhatsApp">
                                        <i class='bx bxl-whatsapp'></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="v-hero-meta-item" style="color: #94a3b8;">
                                <i class='bx bx-phone-off'></i> No phone
                            </div>
                        <?php endif; ?>
                        <div class="v-hero-meta-item">
                            <i class='bx bx-map-pin' style="color: #ea580c; font-size: 16px;"></i>
                            <span>Base Zone: <strong><?= htmlspecialchars($vehicle['home_zone_name'] ?: 'All Central') ?></strong></span>
                        </div>
                        <div class="v-hero-meta-item">
                            <i class='bx bx-box' style="color: #64748b; font-size: 16px;"></i>
                            <span>Capacity: <strong><?= $capacity ?> cases</strong></span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em;">
                        Trip Yard Load
                    </div>
                    <div style="font-size: 18px; font-weight: 800; color: #0f172a;">
                        <?= $activeLoadedCases ?> <span style="font-size: 13px; font-weight: 600; color: #64748b;">/ <?= $capacity ?> cs</span>
                    </div>
                    <?php if ($activeLoadedCases > 0): ?>
                        <form method="POST" onsubmit="return confirm('Clear active yard trip load for this vehicle? Lifetime deliveries and past receipts are preserved.');" style="margin: 0;">
                            <input type="hidden" name="action" value="reset_load">
                            <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
                            <button type="submit" class="btn-v-danger" style="padding: 4px 8px; font-size: 11px;" title="Reset active trip load">
                                <i class='bx bx-reset'></i> Clear Trip Load
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Summary Cards Grid (Authoritative for selected date range) -->
            <div class="v-kpi-grid">
                <!-- 1. Orders Delivered -->
                <div class="v-kpi-card">
                    <div class="v-kpi-header">
                        <span class="v-kpi-lbl">Orders Delivered</span>
                        <i class='bx bx-check-double v-kpi-icon'></i>
                    </div>
                    <div class="v-kpi-val"><?= number_format($summary['total_orders']) ?></div>
                    <div class="v-kpi-sub"><?= number_format($summary['total_cases']) ?> cases completed</div>
                </div>

                <!-- 2. Cash Collected -->
                <div class="v-kpi-card v-kpi-cash">
                    <div class="v-kpi-header">
                        <span class="v-kpi-lbl">Cash Collected</span>
                        <i class='bx bx-money v-kpi-icon'></i>
                    </div>
                    <div class="v-kpi-val"><?= $summary['formatted_cash'] ?></div>
                    <div class="v-kpi-sub">Direct Cash Received</div>
                </div>

                <!-- 3. Credited Amount -->
                <div class="v-kpi-card v-kpi-credit">
                    <div class="v-kpi-header">
                        <span class="v-kpi-lbl">Credited Amount</span>
                        <i class='bx bx-credit-card v-kpi-icon'></i>
                    </div>
                    <div class="v-kpi-val"><?= $summary['formatted_credit'] ?></div>
                    <div class="v-kpi-sub">Ledger / Store Credit</div>
                </div>

                <!-- 4. Outstanding Due -->
                <div class="v-kpi-card v-kpi-due">
                    <div class="v-kpi-header">
                        <span class="v-kpi-lbl">Outstanding Due</span>
                        <i class='bx bx-error-circle v-kpi-icon'></i>
                    </div>
                    <div class="v-kpi-val"><?= $summary['formatted_due'] ?></div>
                    <div class="v-kpi-sub">Pending Payment Collection</div>
                </div>

                <!-- 5. Total Order Value -->
                <div class="v-kpi-card v-kpi-sales">
                    <div class="v-kpi-header">
                        <span class="v-kpi-lbl">Total Delivered Value</span>
                        <i class='bx bx-receipt v-kpi-icon'></i>
                    </div>
                    <div class="v-kpi-val"><?= $summary['formatted_sales'] ?></div>
                    <div class="v-kpi-sub">Gross Billed Revenue</div>
                </div>
            </div>

            <!-- Comprehensive Filter Toolbar (Shop Name / Phone Search + Daily/Monthly/Custom Date) -->
            <div class="v-filter-card">
                <form method="GET" action="view.php" class="v-filter-form">
                    <input type="hidden" name="id" value="<?= $vehicleId ?>">

                    <div class="v-filter-row-top">
                        <!-- Search Box for Shop Name or Phone Number -->
                        <div class="v-search-box">
                            <i class='bx bx-search search-icon'></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search shop name, phone number, customer, or bill #..." autocomplete="off">
                            <?php if (!empty($searchQuery)): ?>
                                <a href="<?= buildViewFilterUrl(['search' => '']) ?>" class="clear-icon" title="Clear search">&times;</a>
                            <?php endif; ?>
                        </div>

                        <!-- Date Preset Buttons -->
                        <div class="v-preset-row">
                            <a href="<?= buildViewFilterUrl(['date' => 'all']) ?>" class="v-preset-pill <?= $filterDate === 'all' ? 'active' : '' ?>">
                                All Time
                            </a>
                            <a href="<?= buildViewFilterUrl(['date' => 'today']) ?>" class="v-preset-pill <?= $filterDate === 'today' ? 'active' : '' ?>">
                                <i class='bx bx-sun'></i> Today
                            </a>
                            <a href="<?= buildViewFilterUrl(['date' => 'yesterday']) ?>" class="v-preset-pill <?= $filterDate === 'yesterday' ? 'active' : '' ?>">
                                Yesterday
                            </a>
                            <a href="<?= buildViewFilterUrl(['date' => 'this_week']) ?>" class="v-preset-pill <?= in_array($filterDate, ['weekly', 'this_week']) ? 'active' : '' ?>">
                                This Week
                            </a>
                            <a href="<?= buildViewFilterUrl(['date' => 'this_month']) ?>" class="v-preset-pill <?= in_array($filterDate, ['monthly', 'this_month']) ? 'active' : '' ?>">
                                <i class='bx bx-calendar'></i> This Month
                            </a>
                        </div>
                    </div>

                    <!-- Custom Date Range & Action Buttons -->
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding-top: 6px; border-top: 1px dashed #e2e8f0;">
                        <div class="v-date-inputs">
                            <span style="font-size: 12px; font-weight: 700; color: #475569; display: flex; align-items: center; gap: 4px;">
                                <i class='bx bx-calendar-event' style="font-size: 16px; color: #2563eb;"></i> Custom Date Range:
                            </span>
                            <div class="v-date-input-group">
                                <span>From:</span>
                                <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                            </div>
                            <div class="v-date-input-group">
                                <span>To:</span>
                                <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>">
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="submit" class="btn-v-primary">
                                <i class='bx bx-filter-alt'></i> Apply Filters
                            </button>
                            <?php if ($filterDate !== 'all' || !empty($searchQuery) || !empty($fromDate) || !empty($toDate)): ?>
                                <a href="view.php?id=<?= $vehicleId ?>" class="btn-v-secondary" title="Reset all search & date filters">
                                    <i class='bx bx-reset'></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Active Filters Notification Strip -->
                    <?php if ($filterDate !== 'all' || !empty($searchQuery)): ?>
                        <div class="v-active-filter-strip">
                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="font-weight: 700; color: #0f172a;">Active Filters:</span>
                                <?php if (!empty($searchQuery)): ?>
                                    <span class="v-filter-tag">
                                        <i class='bx bx-search'></i> Search: "<strong><?= htmlspecialchars($searchQuery) ?></strong>"
                                        <a href="<?= buildViewFilterUrl(['search' => '']) ?>" style="color: #ef4444; text-decoration: none; font-weight: 800; margin-left: 2px;">&times;</a>
                                    </span>
                                <?php endif; ?>
                                <?php if ($filterDate !== 'all'): ?>
                                    <span class="v-filter-tag">
                                        <i class='bx bx-calendar'></i> Period: "<strong><?= htmlspecialchars($currentDateLabel) ?></strong>"
                                        <a href="<?= buildViewFilterUrl(['date' => 'all']) ?>" style="color: #ef4444; text-decoration: none; font-weight: 800; margin-left: 2px;">&times;</a>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="font-weight: 700; color: #2563eb;">
                                <?= count($orders) ?> matching order<?= count($orders) === 1 ? '' : 's' ?> found
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Delivered Orders Data Table -->
            <div class="v-orders-card">
                <div class="v-orders-card-header">
                    <div class="v-orders-card-title">
                        <i class='bx bx-list-check' style="color: #2563eb; font-size: 20px;"></i>
                        <span>Delivered Orders from <?= htmlspecialchars($vehicle['vehicle_number']) ?></span>
                        <span class="v-count-badge"><?= count($orders) ?> orders</span>
                    </div>
                    <div style="font-size: 13px; font-weight: 700; color: #475569;">
                        Total Value: <strong style="color: #0f172a; font-size: 15px;"><?= $summary['formatted_sales'] ?></strong>
                    </div>
                </div>

                <div class="v-table-wrap">
                    <table class="v-data-table">
                        <thead>
                            <tr>
                                <th># Order / Bill</th>
                                <th>Delivered Date & Time</th>
                                <th>Shop & Customer</th>
                                <th>Phone</th>
                                <th>Location / Zone</th>
                                <th>Items & Cases</th>
                                <th style="text-align: right;">Total Amount</th>
                                <th style="text-align: right;">Cash</th>
                                <th style="text-align: right;">Credit</th>
                                <th style="text-align: right;">Due</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; padding: 48px 16px; color: #64748b;">
                                        <i class='bx bx-package' style="font-size: 42px; color: #cbd5e1; display: block; margin-bottom: 8px;"></i>
                                        <strong style="color: #0f172a; font-size: 15px;">No Delivered Orders Found</strong>
                                        <div style="font-size: 13px; margin-top: 4px; max-width: 480px; margin-left: auto; margin-right: auto;">
                                            No completed deliveries match your filter criteria for 
                                            <strong><?= htmlspecialchars($vehicle['vehicle_number']) ?></strong> in period 
                                            "<strong><?= htmlspecialchars($currentDateLabel) ?></strong>"
                                            <?php if (!empty($searchQuery)): ?>
                                                matching search "<strong><?= htmlspecialchars($searchQuery) ?></strong>"
                                            <?php endif; ?>.
                                        </div>
                                        <?php if ($filterDate !== 'all' || !empty($searchQuery)): ?>
                                            <div style="margin-top: 14px;">
                                                <a href="view.php?id=<?= $vehicleId ?>" class="btn-v-secondary">
                                                    <i class='bx bx-reset'></i> Clear All Filters
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($orders as $ord): ?>
                                    <?php
                                        $dueVal = (float)$ord['due_amount'];
                                        $ordPhone = preg_replace('/[^0-9]/', '', $ord['phone'] ?? '');
                                        $ordWaPhone = strlen($ordPhone) === 10 ? ('91' . $ordPhone) : $ordPhone;
                                    ?>
                                    <tr>
                                        <!-- Order # & Bill Badge -->
                                        <td>
                                            <a href="../orders/view.php?id=<?= urlencode($ord['order_id']) ?>" target="_blank" style="color: #2563eb; font-weight: 700; text-decoration: none;" title="Open order #<?= htmlspecialchars($ord['order_number'] ?: $ord['order_id']) ?>">
                                                #<?= htmlspecialchars($ord['order_number'] ?: $ord['order_id']) ?>
                                            </a>
                                            <?php if (!empty($ord['bill_number'])): ?>
                                                <div>
                                                    <span class="bill-badge">Bill #<?= htmlspecialchars($ord['bill_number']) ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Delivered Date -->
                                        <td style="white-space: nowrap; color: #475569;">
                                            <i class='bx bx-time-five' style="color: #94a3b8;"></i>
                                            <?= htmlspecialchars($ord['formatted_date']) ?>
                                        </td>

                                        <!-- Shop & Customer -->
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a; font-size: 13px;">
                                                <?= htmlspecialchars($ord['shop_name'] ?: ($ord['customer_name'] ?: 'Retail Shop')) ?>
                                            </div>
                                            <?php if (!empty($ord['shop_name']) && !empty($ord['customer_name'])): ?>
                                                <div style="font-size: 11px; color: #64748b;">
                                                    Attn: <?= htmlspecialchars($ord['customer_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Phone -->
                                        <td style="white-space: nowrap;">
                                            <?php if (!empty($ord['phone'])): ?>
                                                <a href="tel:<?= htmlspecialchars($ord['phone']) ?>" style="color: #2563eb; font-weight: 600; text-decoration: none;" title="Call customer">
                                                    <i class='bx bx-phone'></i> <?= htmlspecialchars($ord['phone']) ?>
                                                </a>
                                                <?php if (!empty($ordWaPhone)): ?>
                                                    <a href="https://wa.me/<?= $ordWaPhone ?>" target="_blank" style="color: #10b981; font-size: 15px; margin-left: 4px;" title="Chat on WhatsApp">
                                                        <i class='bx bxl-whatsapp'></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 11px;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Location / Zone -->
                                        <td>
                                            <div style="color: #334155; font-weight: 500;">
                                                <?= htmlspecialchars($ord['location'] ?: ($ord['address'] ?: '-')) ?>
                                            </div>
                                            <?php if (!empty($ord['zone_name'])): ?>
                                                <span style="font-size: 10px; background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0; display: inline-block; margin-top: 2px;">
                                                    <i class='bx bx-map-pin'></i> <?= htmlspecialchars($ord['zone_name']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Items & Cases Breakdown -->
                                        <td>
                                            <div style="color: #1e40af; font-weight: 600;">
                                                <?= htmlspecialchars($ord['items_detail'] ?: ($ord['total_cases'] . ' cs')) ?>
                                            </div>
                                            <div style="font-size: 11px; color: #64748b;">
                                                <strong><?= (int)$ord['total_cases'] ?> cs</strong> total
                                            </div>
                                        </td>

                                        <!-- Total Amount -->
                                        <td style="text-align: right; font-weight: 800; color: #0f172a; font-size: 13px;">
                                            <?= $ord['formatted_total'] ?>
                                        </td>

                                        <!-- Cash -->
                                        <td style="text-align: right; font-weight: 700; color: #047857;">
                                            <?= $ord['formatted_cash'] ?>
                                        </td>

                                        <!-- Credit -->
                                        <td style="text-align: right; font-weight: 700; color: #6d28d9;">
                                            <?= $ord['formatted_credit'] ?>
                                        </td>

                                        <!-- Due -->
                                        <td style="text-align: right;">
                                            <?php if ($dueVal > 0): ?>
                                                <span class="due-badge-alert" title="Pending Due Balance">
                                                    <?= $ord['formatted_due'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #64748b;">₹0.00</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Action -->
                                        <td style="text-align: center; white-space: nowrap;">
                                            <a href="../orders/view.php?id=<?= urlencode($ord['order_id']) ?>" target="_blank" class="btn-v-secondary" style="padding: 4px 10px; font-size: 11px;" title="View Order #<?= htmlspecialchars($ord['order_number'] ?: $ord['order_id']) ?>">
                                                <i class='bx bx-show'></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($orders)): ?>
                            <tfoot>
                                <tr>
                                    <td colspan="5" style="text-align: right; padding-right: 14px;">
                                        Total for Selected Orders (<?= count($orders) ?> orders):
                                    </td>
                                    <td>
                                        <strong><?= number_format($summary['total_cases']) ?> cs</strong>
                                    </td>
                                    <td style="text-align: right;">
                                        <?= $summary['formatted_sales'] ?>
                                    </td>
                                    <td style="text-align: right; color: #047857;">
                                        <?= $summary['formatted_cash'] ?>
                                    </td>
                                    <td style="text-align: right; color: #6d28d9;">
                                        <?= $summary['formatted_credit'] ?>
                                    </td>
                                    <td style="text-align: right; color: #b91c1c;">
                                        <?= $summary['formatted_due'] ?>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Yard Trip Load & Zone Breakdown Card -->
            <div class="v-yard-card">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; flex-wrap: wrap; gap: 10px;">
                    <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <i class='bx bx-map-alt' style="color: #2563eb;"></i>
                        Active Trip Yard Load: <?= $activeLoadedCases ?> cases loaded
                    </h3>
                    <button type="button" class="btn-v-primary" onclick="openLoadCasesModal()" style="font-size: 12px; padding: 6px 12px;">
                        <i class='bx bx-plus'></i> Load More Cases
                    </button>
                </div>

                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <?php if (!empty($activeZoneBreakdown)): ?>
                        <?php foreach ($activeZoneBreakdown as $zb): ?>
                            <span class="v-chip">
                                <i class='bx bx-map-pin' style="color: #2563eb;"></i>
                                <?= htmlspecialchars($zb['zone_name'] ?: 'Zone #' . $zb['zone_id']) ?>: 
                                <strong style="color: #0f172a; margin-left: 2px;"><?= (int)$zb['cases'] ?> cs</strong>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size: 12px; color: #94a3b8; font-style: italic;">
                            Vehicle currently has no active yard trip cases loaded.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Load Cases -->
    <div id="modalLoadCases" class="v-modal-overlay">
        <div class="v-modal-card">
            <div class="v-modal-header">
                <h3 class="v-modal-title">
                    <i class='bx bx-package' style="color: #2563eb;"></i>
                    Load Cases: <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                </h3>
                <button type="button" class="v-modal-close" onclick="closeLoadCasesModal()">&times;</button>
            </div>
            <form method="POST" action="view.php?<?= http_build_query($_GET) ?>">
                <input type="hidden" name="action" value="load_cases">
                <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">

                <div class="v-form-group">
                    <label>Destination Zone (Optional):</label>
                    <select name="zone_id">
                        <option value="">-- General / Multi-Zone Load --</option>
                        <?php foreach ($zonesList as $z): ?>
                            <option value="<?= $z['id'] ?>" <?= ($vehicle['zone_id'] == $z['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($z['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="v-form-group">
                    <label>Target Shop Name (Optional):</label>
                    <input type="text" name="shop_name" list="shopsList" placeholder="e.g. Al Madeena Supermarket" autocomplete="off">
                    <datalist id="shopsList">
                        <?php foreach ($allShopsList as $s): ?>
                            <option value="<?= htmlspecialchars($s['shop_name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="v-form-group">
                    <label>Number of Cases to Load: <span style="color: #dc2626;">*</span></label>
                    <input type="number" id="loadCasesInput" name="cases" min="1" max="1000" placeholder="e.g. 50" required>
                </div>

                <div class="v-form-group">
                    <label>Trip / Loading Notes (Optional):</label>
                    <input type="text" name="notes" placeholder="e.g. Morning run, Batch A">
                </div>

                <div class="v-modal-actions">
                    <button type="button" class="btn-v-secondary" onclick="closeLoadCasesModal()">Cancel</button>
                    <button type="submit" class="btn-v-primary">Confirm & Load</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Vehicle Details -->
    <div id="modalEditVehicle" class="v-modal-overlay">
        <div class="v-modal-card">
            <div class="v-modal-header">
                <h3 class="v-modal-title">
                    <i class='bx bx-edit' style="color: #2563eb;"></i>
                    Edit Vehicle Profile
                </h3>
                <button type="button" class="v-modal-close" onclick="closeEditVehicleModal()">&times;</button>
            </div>
            <form method="POST" action="view.php?<?= http_build_query($_GET) ?>">
                <input type="hidden" name="action" value="edit_vehicle">
                <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">

                <div class="v-form-group">
                    <label>Vehicle Plate Number: <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="vehicle_number" value="<?= htmlspecialchars($vehicle['vehicle_number']) ?>" required>
                </div>

                <div class="v-form-group">
                    <label>Model / Vehicle Name:</label>
                    <input type="text" name="vehicle_name" value="<?= htmlspecialchars($vehicle['vehicle_name']) ?>" placeholder="e.g. Tata Ace Gold">
                </div>

                <div class="v-form-group">
                    <label>Driver Full Name:</label>
                    <input type="text" name="driver_name" value="<?= htmlspecialchars($vehicle['driver_name']) ?>">
                </div>

                <div class="v-form-group">
                    <label>Driver Phone Number:</label>
                    <input type="text" name="driver_phone" value="<?= htmlspecialchars($vehicle['driver_phone']) ?>" placeholder="10-digit mobile number">
                </div>

                <div class="v-form-group">
                    <label>Base Zone:</label>
                    <select name="zone_id">
                        <option value="">-- Unassigned Base Zone --</option>
                        <?php foreach ($zonesList as $z): ?>
                            <option value="<?= $z['id'] ?>" <?= ($vehicle['zone_id'] == $z['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($z['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="v-form-group">
                    <label>Capacity (in Cases):</label>
                    <input type="number" name="capacity_cases" value="<?= (int)($vehicle['capacity_cases'] ?? 100) ?>" min="1" max="5000">
                </div>

                <div class="v-modal-actions">
                    <button type="button" class="btn-v-secondary" onclick="closeEditVehicleModal()">Cancel</button>
                    <button type="submit" class="btn-v-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        function openLoadCasesModal() {
            const m = document.getElementById('modalLoadCases');
            const inpt = document.getElementById('loadCasesInput');
            if (m) m.classList.add('open');
            if (inpt) setTimeout(() => inpt.focus(), 100);
        }
        function closeLoadCasesModal() {
            const m = document.getElementById('modalLoadCases');
            if (m) m.classList.remove('open');
        }

        function openEditVehicleModal() {
            const m = document.getElementById('modalEditVehicle');
            if (m) m.classList.add('open');
        }
        function closeEditVehicleModal() {
            const m = document.getElementById('modalEditVehicle');
            if (m) m.classList.remove('open');
        }

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeLoadCasesModal();
                closeEditVehicleModal();
            }
        });
    </script>
</body>
</html>
