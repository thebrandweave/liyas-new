<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "products";
$page_title = "Product Inventory Management";

// --- MESSAGES ---
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_GET['added']) && $_GET['added'] == '1') { $success_message = "Product added successfully!"; }
if (isset($_GET['updated']) && $_GET['updated'] == '1') { $success_message = "Product updated successfully!"; }

// --- DELETE LOGIC ---
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $product_id = (int)$_GET['delete'];
    try {
        // Check if orders exist for this product
        $chkOrder = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE product_id = ?");
        $chkOrder->execute([$product_id]);
        $orderCount = (int)$chkOrder->fetchColumn();

        if ($orderCount > 0) {
            $error_message = "Cannot delete this product because it has {$orderCount} orders associated with it. You can mark it as Inactive instead.";
        } else {
            $checkStmt = $pdo->prepare("SELECT name, product_name, image FROM products WHERE product_id = ?");
            $checkStmt->execute([$product_id]);
            $product_data = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product_data) {
                if (!empty($product_data['image'])) {
                    $full_path = __DIR__ . '/../uploads/products/' . $product_data['image'];
                    if (file_exists($full_path)) unlink($full_path);
                }
                $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$product_id]);
                quickLog($pdo, 'delete', 'product', $product_id, "Deleted product: " . ($product_data['product_name'] ?: $product_data['name']));
                $success_message = "Product deleted successfully!";
            }
        }
    } catch (PDOException $e) { 
        $error_message = "Error: " . $e->getMessage(); 
    }
}

// --- AJAX GET PRODUCT DATA & DAMAGED ITEMS ---
if (isset($_GET['action']) && $_GET['action'] === 'get_product_data') {
    $pid = (int)($_GET['id'] ?? 0);
    $pStmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $pStmt->execute([$pid]);
    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pRow) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }
    $damagedList = getProductDamagedItems($pdo, $pid, 'reported');
    $vStmt = $pdo->query("SELECT id, vehicle_number, name FROM vehicles WHERE status != 'inactive' ORDER BY vehicle_number ASC");
    $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Calculate cases in transit
    $inTransitStmt = $pdo->prepare("
        SELECT (
            COALESCE((
                SELECT SUM(oi.quantity)
                FROM order_items oi
                JOIN orders ord ON oi.order_id = ord.order_id
                WHERE oi.product_id = ? AND ord.status IN ('pending', 'processing', 'shipped')
            ), 0) + COALESCE((
                SELECT SUM(ord2.quantity)
                FROM orders ord2
                WHERE ord2.product_id = ? 
                  AND ord2.status IN ('pending', 'processing', 'shipped')
                  AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
            ), 0) + COALESCE((
                SELECT SUM(vl.cases_loaded)
                FROM vehicle_loads vl
                WHERE vl.product_id = ? AND vl.is_active = 1 AND vl.order_id IS NULL
            ), 0)
        )
    ");
    $inTransitStmt->execute([$pid, $pid, $pid]);
    $pRow['cases_in_transit'] = (int)$inTransitStmt->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'product' => $pRow,
        'damaged_items' => $damagedList,
        'vehicles' => $vehicles
    ]);
    exit;
}

// --- RECORD DAMAGED ITEM FROM MODAL (DEDUCTS STOCK) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['record_damaged_item'])) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);
    $vId = (int)($_POST['vehicle_id'] ?? 0);
    $pId = (int)($_POST['product_id'] ?? 0);
    $cases = (int)($_POST['damaged_cases'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $reportedBy = 'Admin (' . htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') . ')';

    if ($vId > 0 && $pId > 0 && $cases > 0) {
        $res = recordVehicleDamagedItem($pdo, $vId, $pId, $cases, null, $notes, $reportedBy, true);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        }
        if (!empty($res['success'])) {
            $_SESSION['success_message'] = "Recorded {$cases} damaged cases and deducted from stock.";
        } else {
            $_SESSION['error_message'] = $res['error'] ?? "Failed to record damaged item.";
        }
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Please select a vehicle and enter valid damaged cases (> 0).']);
            exit;
        }
        $_SESSION['error_message'] = "Please select a vehicle and enter valid damaged cases (> 0).";
    }
    header("Location: index.php");
    exit;
}

// --- RESOLVE / REMOVE DAMAGED ITEMS ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['resolve_damaged_item'])) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);
    $damageId = (int)($_POST['damaged_id'] ?? 0);
    $action = trim($_POST['resolution_action'] ?? 'resolved'); // 'corrected', 'transferred', 'resolved'
    $restoreStock = !empty($_POST['restore_to_warehouse']);
    $notes = trim($_POST['resolution_notes'] ?? '');
    $resolvedBy = 'Admin (' . htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') . ')';

    $res = resolveDamagedItem($pdo, $damageId, $action, $restoreStock, $notes, $resolvedBy);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }

    if (!empty($res['success'])) {
        $actionLabel = ($action === 'transferred') ? 'Transferred' : (($action === 'corrected') ? 'Corrected' : 'Removed');
        $_SESSION['success_message'] = "Damaged item marked as {$actionLabel}" . ($restoreStock ? " and cases restored to warehouse stock." : ".");
    } else {
        $_SESSION['error_message'] = $res['error'] ?? "Failed to update damaged item.";
    }
    header("Location: index.php");
    exit;
}

// --- QUICK UPDATE PRODUCT FROM MODAL ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_product_modal'])) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);
    $pid = (int)($_POST['product_id'] ?? 0);
    $product_name = trim($_POST['product_name'] ?? '');
    $case_stock = (int)($_POST['case_stock'] ?? 0);
    $case_price = (float)($_POST['case_price'] ?? 0);
    $net_content = !empty($_POST['net_content']) ? (float)$_POST['net_content'] : null;
    $net_content_unit = in_array($_POST['net_content_unit'] ?? '', ['ML', 'L']) ? $_POST['net_content_unit'] : 'ML';
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $description = trim($_POST['description'] ?? '');

    if ($pid > 0 && $product_name !== '' && $case_price > 0 && $case_stock >= 0) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $product_name), '-'));
        $upStmt = $pdo->prepare("
            UPDATE products 
            SET name = ?, product_name = ?, url_slug = ?, description = ?, 
                price = ?, case_price = ?, stock = ?, case_stock = ?, 
                net_content = ?, net_content_unit = ?, status = ?, 
                updated_at = NOW()
            WHERE product_id = ?
        ");
        $upStmt->execute([
            $product_name, $product_name, $slug, $description,
            $case_price, $case_price, $case_stock, $case_stock,
            $net_content, $net_content_unit, $status,
            $pid
        ]);
        quickLog($pdo, 'update', 'product', $pid, "Updated product via modal: {$product_name}");
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Product '{$product_name}' updated successfully."]);
            exit;
        }
        $_SESSION['success_message'] = "Product '{$product_name}' updated successfully.";
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "Please provide valid product name, price, and non-negative stock."]);
            exit;
        }
        $_SESSION['error_message'] = "Please provide valid product name, price, and non-negative stock.";
    }
    header("Location: index.php");
    exit;
}

// --- SEARCH & PAGINATION ---
$search = trim($_GET['search'] ?? '');
$searchTerm = "%$search%";
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM products WHERE name LIKE ? OR product_name LIKE ? OR description LIKE ?";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Fetch products with accurate stock allocation across zones, order items, and vehicle loads
$query = "
    SELECT 
        p.*,
        COALESCE((
            SELECT SUM(vdi.damaged_cases)
            FROM vehicle_damaged_items vdi
            WHERE vdi.product_id = p.product_id AND vdi.status = 'reported'
        ), 0) AS cases_damaged,
        (
            COALESCE((
                SELECT SUM(oi.quantity)
                FROM order_items oi
                JOIN orders ord ON oi.order_id = ord.order_id
                WHERE oi.product_id = p.product_id AND ord.status = 'delivered'
            ), 0) + COALESCE((
                SELECT SUM(ord2.quantity)
                FROM orders ord2
                WHERE ord2.product_id = p.product_id 
                  AND ord2.status = 'delivered'
                  AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
            ), 0)
        ) AS cases_delivered,
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
        ) AS cases_in_transit
    FROM products p
    WHERE p.name LIKE :search1 OR p.product_name LIKE :search2 OR p.description LIKE :search3
    ORDER BY p.case_price ASC, p.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($query);
$stmt->bindValue(':search1', $searchTerm, PDO::PARAM_STR);
$stmt->bindValue(':search2', $searchTerm, PDO::PARAM_STR);
$stmt->bindValue(':search3', $searchTerm, PDO::PARAM_STR);
$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Warehouse totals
$total_available_stock = 0;
$total_in_transit = 0;
$total_delivered = 0;
$total_damaged = 0;

foreach ($products as $p) {
    // Original warehouse stock
    $cStock = (int)($p['case_stock'] ?? $p['stock'] ?? 0);

    // Cases currently in transit
    $inTransit = (int)($p['cases_in_transit'] ?? 0);

    // Cases delivered
    $delivered = (int)($p['cases_delivered'] ?? 0);

    // Cases damaged
    $damaged = (int)($p['cases_damaged'] ?? 0);

    // Available warehouse stock (reduced by cases currently in transit on delivery routes)
    $availableStock = max(0, $cStock - $inTransit);

    // Calculate totals
    $total_available_stock += $availableStock;
    $total_in_transit += $inTransit;
    $total_delivered += $delivered;
    $total_damaged += $damaged;
}

// If pagination exists across multiple pages and no search query, ensure overall totals reflect the entire inventory
if ($total_records > count($products) && empty($search)) {
    try {
        $overallStmt = $pdo->query("
            SELECT 
                COALESCE(SUM(COALESCE(p.case_stock, p.stock, 0)), 0) as total_stock,
                COALESCE((
                    SELECT SUM(vdi.damaged_cases)
                    FROM vehicle_damaged_items vdi
                    WHERE vdi.status = 'reported'
                ), 0) as total_damaged,
                (
                    COALESCE((
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        JOIN orders ord ON oi.order_id = ord.order_id
                        WHERE ord.status IN ('pending', 'processing', 'shipped')
                    ), 0) + COALESCE((
                        SELECT SUM(ord2.quantity)
                        FROM orders ord2
                        WHERE ord2.status IN ('pending', 'processing', 'shipped')
                          AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
                    ), 0) + COALESCE((
                        SELECT SUM(vl.cases_loaded)
                        FROM vehicle_loads vl
                        WHERE vl.is_active = 1 AND vl.order_id IS NULL
                    ), 0)
                ) as total_in_transit,
                (
                    COALESCE((
                        SELECT SUM(oi.quantity)
                        FROM order_items oi
                        JOIN orders ord ON oi.order_id = ord.order_id
                        WHERE ord.status = 'delivered'
                    ), 0) + COALESCE((
                        SELECT SUM(ord2.quantity)
                        FROM orders ord2
                        WHERE ord2.status = 'delivered'
                          AND NOT EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = ord2.order_id)
                    ), 0)
                ) as total_delivered
            FROM products p
        ");
        if ($overallData = $overallStmt->fetch(PDO::FETCH_ASSOC)) {
            $total_in_transit = (int)$overallData['total_in_transit'];
            $total_delivered = (int)$overallData['total_delivered'];
            $total_available_stock = max(0, (int)$overallData['total_stock'] - $total_in_transit);
            $total_damaged = (int)$overallData['total_damaged'];
        }
    } catch (Exception $e) {
        // Fallback to loop totals on error
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/prody-admin.css') ?>">
    <title>Products Inventory - Liyas Website</title>
    <style>
        body { font-family: 'Poppins', sans-serif; }
        table th, thead th, th {
            color: #000000 !important;
            font-weight: 700 !important;
        }
        .table-card table tbody td { font-size: 14px; color: #334155; border:1px solid #cac8c8 }
        .text-muted-custom { font-size: 13px; color: #94a3b8; }
        .price-bold { color: #0284c7; font-weight: 600; font-size: 15px; }
        .badge-stock {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
        }
        .badge-stock-healthy { background: #dcfce7; color: #15803d; }
        .badge-stock-low { background: #fef3c7; color: #b45309; }
        .badge-stock-out { background: #fee2e2; color: #b91c1c; }
        .stock-pill-zone { background: #eff6ff; color: #1d4ed8; padding: 2px 7px; border-radius: 4px; font-size: 12px; }
        .stats-mini-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .mini-stat-card {
            background: #fff;
            padding: 1.25rem;
            border-radius: 12px;
            border: 1px solid var(--border-light);
        }
        /* Modal & Damaged items styles */
        .modal-overlay-custom {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-container-custom {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 840px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .modal-header-custom {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-body-custom {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-tab-nav {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }
        .modal-tab-btn {
            background: none;
            border: none;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .modal-tab-btn.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
        }
        .modal-tab-btn .tab-count-badge {
            background: #fee2e2;
            color: #dc2626;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 12px;
            font-weight: 800;
        }
        .damaged-table-box {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .damaged-table-box th {
            background: #f8fafc;
            padding: 10px 12px;
            text-align: left;
            font-size: 12px;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        .damaged-table-box td {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .btn-resolve {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s ease;
        }
        .btn-resolve-corrected {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        .btn-resolve-corrected:hover {
            background: #10b981;
            color: #ffffff;
        }
        .btn-resolve-transfer {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }
        .btn-resolve-transfer:hover {
            background: #2563eb;
            color: #ffffff;
        }
        .btn-resolve-remove {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .btn-resolve-remove:hover {
            background: #dc2626;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb"><i class='bx bx-shopping-bag'></i> <span>Warehouse Products</span></div>
                <div class="header-actions">
                    <form action="index.php" method="GET" style="display: flex; gap: 0.5rem;">
                        <input type="search" name="search" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>" class="form-input" style="width: 220px;">
                        <button type="submit" class="header-btn" style="padding: 0.5rem;"><i class='bx bx-search'></i></button>
                    </form>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (isset($success_message)): ?><div class="alert alert-success" style="padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 1.5rem;"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
                <?php if (isset($error_message)): ?><div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem;"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

                <!-- Stock Summary Bar -->
                <div class="stats-mini-bar">
                    <div class="mini-stat-card">
                        <div style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Active Products</div>
                        <div style="font-size: 22px; font-weight: 600; color: #1e293b; margin-top: 4px;"><?= count($products) ?> SKUs</div>
                    </div>
                    <div class="mini-stat-card">
                        <div style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Available Warehouse Stock</div>
                        <div style="font-size: 22px; font-weight: 600; color: #059669; margin-top: 4px;"><?= number_format($total_available_stock) ?> Cases</div>
                    </div>
                    <div class="mini-stat-card">
                        <div style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Damaged on Vehicles</div>
                        <div style="font-size: 22px; font-weight: 600; color: #dc2626; margin-top: 4px;"><?= number_format($total_damaged) ?> Cases</div>
                    </div>
                    <div class="mini-stat-card">
                        <div style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Active in Transit / Routes</div>
                        <div style="font-size: 22px; font-weight: 600; color: #d97706; margin-top: 4px;"><?= number_format($total_in_transit) ?> Cases</div>
                    </div>
                    <div class="mini-stat-card">
                        <div style="font-size: 12px; color: var(--text-secondary); text-transform: uppercase; font-weight: 600;">Total Delivered Cases</div>
                        <div style="font-size: 22px; font-weight: 600; color: #2563eb; margin-top: 4px;"><?= number_format($total_delivered) ?> Cases</div>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem;">
                        <div>
                            <div class="table-title" style="font-size: 18px; font-weight: 600;">Warehouse Product Inventory</div>
                            <div style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                                Central stock levels, active cases on delivery routes, and fulfilled orders.
                            </div>
                        </div>
                        <div class="table-actions">
                            <a href="add.php" class="btn-action btn-add noselect" style="text-decoration: none;">
                                <span class="text">+ Add Product</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="table-responsive-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Net Content</th>
                                    <th>Unit</th>
                                    <th>Case Price</th>
                                    <th>Available Stock</th>
                                    <th>Damaged</th>
                                    <th>In Transit</th>
                                    <th>Delivered</th>
                                    <th>Stock Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; padding: 2.5rem; color: #64748b;">
                                             No products found. <a href="add.php" style="color: var(--blue);">Add a product</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): 
                                        $displayName = htmlspecialchars($product['product_name'] ?: $product['name']);
                                        $caseStock = (int)($product['case_stock'] ?: $product['stock']);
                                        $casePrice = (float)($product['case_price'] ?: $product['price']);
                                        $netContent = $product['net_content'] ? (float)$product['net_content'] : '-';
                                        $unit = htmlspecialchars($product['net_content_unit'] ?: 'ML');
                                        $inTransit = (int)$product['cases_in_transit'];
                                        $delivered = (int)$product['cases_delivered'];
                                        $damagedCases = (int)($product['cases_damaged'] ?? 0);

                                        // Available warehouse stock (reduced by cases currently in transit on delivery routes)
                                        $availableStock = max(0, $caseStock - $inTransit);

                                        $stockClass = 'badge-stock-healthy';
                                        $statusText = 'In Stock';
                                        if ($availableStock === 0) {
                                            $stockClass = 'badge-stock-out';
                                            $statusText = 'Out of Stock';
                                        } elseif ($availableStock < 20) {
                                            $stockClass = 'badge-stock-low';
                                            $statusText = 'Low Stock';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 14px;">
                                                <?= $displayName ?>
                                            </div>
                                            <?php if (!empty($product['description'])): ?>
                                                <div class="text-muted-custom" style="max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?= htmlspecialchars($product['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= $netContent ?></strong>
                                        </td>
                                        <td>
                                            <span style="font-weight: 500; color: #475569;"><?= $unit ?></span>
                                        </td>
                                        <td>
                                            <span class="price-bold"><?= formatCurrency($casePrice) ?></span>
                                            <span style="font-size: 11px; color: #64748b;">/ case</span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; font-size: 15px; color: <?= ($availableStock > 0) ? '#0f172a' : '#dc2626' ?>;"><?= number_format($availableStock) ?></span> Cases
                                            <?php if ($inTransit > 0): ?>
                                                <div style="font-size: 11px; color: #d97706; margin-top: 3px; font-weight: 500;">
                                                    <i class='bx bx-car'></i> <?= number_format($inTransit) ?> in transit
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($damagedCases > 0): ?>
                                                <button type="button" class="badge-stock badge-stock-out" onclick="openProductEditModal(<?= $product['product_id'] ?>, 'damaged')" style="border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; font-weight: 700; background: #fee2e2; color: #dc2626;" title="Click to view and remove/resolve damaged items">
                                                    <i class='bx bx-error-circle'></i> <?= number_format($damagedCases) ?> Cases
                                                </button>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 13px;">0 Cases</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($inTransit > 0): ?>
                                                <span class="stock-pill-zone" style="background: #fffbeb; color: #b45309; border: 1px solid #fef3c7;">
                                                    <i class='bx bx-car'></i> <?= number_format($inTransit) ?> Cases
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 13px;">0 Cases</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: #2563eb; font-size: 13px;">
                                                <?= number_format($delivered) ?> Cases
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-stock <?= $stockClass ?>">
                                                <?= $statusText ?> (<?= number_format($availableStock) ?>)
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 6px; align-items: center;">
                                                <button type="button" onclick="openProductEditModal(<?= $product['product_id'] ?>)" class="btn-action" style="padding: 5px 10px; background: #e0f2fe; color: #0284c7; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 3px; font-weight: 600;" title="Edit Product & Manage Damaged Stock">
                                                    <i class='bx bx-edit'></i> Edit
                                                </button>
                                                <a href="edit.php?id=<?= $product['product_id'] ?>" class="btn-action" title="Full Page Edit" style="padding: 5px 7px; background: #f1f5f9; color: #475569; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center;">
                                                    <i class='bx bx-link-external'></i>
                                                </a>
                                                <a href="index.php?delete=<?= $product['product_id'] ?>" onclick="return confirm('Are you sure you want to delete <?= addslashes($displayName) ?>?');" class="btn-action" style="padding: 5px 8px; background: #fee2e2; color: #dc2626; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center;" title="Delete Product">
                                                    <i class='bx bx-trash'></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Product Edit & Damaged Items Resolution -->
    <div id="editProductModal" class="modal-overlay-custom" style="display: none;">
        <div class="modal-container-custom">
            <!-- Modal Header -->
            <div class="modal-header-custom">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="background: #eff6ff; color: #2563eb; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                        <i class='bx bx-cube-alt'></i>
                    </div>
                    <div>
                        <div id="modalProductTitle" style="font-size: 16px; font-weight: 800; color: #0f172a;">Product Details</div>
                        <div id="modalProductSubtitle" style="font-size: 12px; color: #64748b;">Manage damaged vehicle stock and update product details</div>
                    </div>
                </div>
                <button type="button" onclick="closeProductEditModal()" style="background: none; border: none; font-size: 26px; color: #94a3b8; cursor: pointer; line-height: 1;">&times;</button>
            </div>

            <!-- Modal Tab Navigation -->
            <div style="padding: 0 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <div class="modal-tab-nav" style="border-bottom: none; margin-bottom: 0;">
                    <button type="button" class="modal-tab-btn active" id="tabBtnDamaged" onclick="switchModalTab('damaged')">
                        <i class='bx bx-error-circle'></i> Damaged Items on Vehicles
                        <span class="tab-count-badge" id="modalDamagedCountBadge">0 cs</span>
                    </button>
                    <button type="button" class="modal-tab-btn" id="tabBtnDetails" onclick="switchModalTab('details')">
                        <i class='bx bx-edit-alt'></i> Edit Product Info
                    </button>
                </div>
            </div>

            <!-- Modal Body -->
            <div class="modal-body-custom">
                <!-- TAB 1: DAMAGED ITEMS -->
                <div id="tabContentDamaged">
                    <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px;">
                        <i class='bx bx-info-circle' style="font-size: 20px; color: #d97706; margin-top: 1px;"></i>
                        <div style="font-size: 12px; color: #92400e; line-height: 1.5;">
                            <strong>Damaged Stock Policy:</strong> Damaged items are automatically deducted from the warehouse Available Stock.
                            <br>&bull; <strong>Corrected:</strong> Mistaken report or repaired packaging — restores cases back to available stock.
                            <br>&bull; <strong>Transferred:</strong> Returned to central warehouse — restores cases back to warehouse inventory.
                            <br>&bull; <strong>Remove:</strong> Discarded or written off as loss — removes record, stock remains deducted.
                        </div>
                    </div>

                    <!-- Action Bar to Report Damaged -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <div style="font-size: 13px; font-weight: 700; color: #1e293b;">
                            Active Damaged Reports on Delivery Vehicles
                        </div>
                        <button type="button" onclick="toggleReportDamagedSection()" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                            <i class='bx bx-plus-circle'></i> <span id="btnReportToggleText">+ Report Damaged Cases</span>
                        </button>
                    </div>

                    <!-- Collapsible Report Damaged Form -->
                    <div id="reportDamagedFormBox" style="display: none; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px; margin-bottom: 16px;">
                        <form id="formRecordDamaged" onsubmit="submitRecordDamaged(event)">
                            <input type="hidden" name="record_damaged_item" value="1">
                            <input type="hidden" name="product_id" id="reportDamageModalProductId" value="">
                            <div style="display: grid; grid-template-columns: 2fr 1fr 2fr auto; gap: 10px; align-items: flex-end;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Delivery Vehicle <span style="color:#ef4444;">*</span></label>
                                    <select name="vehicle_id" id="reportDamageVehicleSelect" required class="form-input" style="width: 100%; padding: 7px 10px; border-radius: 6px; font-size: 12px; border: 1px solid #cbd5e1; background: #fff;">
                                        <option value="">-- Select Delivery Vehicle --</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Damaged Cases <span style="color:#ef4444;">*</span></label>
                                    <input type="number" name="damaged_cases" id="reportDamageCasesInput" min="1" value="1" required class="form-input" style="width: 100%; padding: 7px 10px; border-radius: 6px; font-size: 12px; border: 1px solid #cbd5e1; background: #fff;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px;">Reason / Notes</label>
                                    <input type="text" name="notes" id="reportDamageNotesInput" placeholder="e.g. Broken in transit" class="form-input" style="width: 100%; padding: 7px 10px; border-radius: 6px; font-size: 12px; border: 1px solid #cbd5e1; background: #fff;">
                                </div>
                                <div>
                                    <button type="submit" id="btnSubmitDamagedModal" style="background: #dc2626; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class='bx bx-check'></i> Save &amp; Deduct Stock
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div id="damagedItemsContainer">
                        <!-- Dynamically filled with damaged items table -->
                    </div>
                </div>

                <!-- TAB 2: PRODUCT EDIT FORM -->
                <div id="tabContentDetails" style="display: none;">
                    <form id="formProductModal" method="POST" action="index.php" onsubmit="submitProductModalForm(event)">
                        <input type="hidden" name="update_product_modal" value="1">
                        <input type="hidden" name="product_id" id="editModalProductId" value="">

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Product Name <span style="color:#ef4444;">*</span></label>
                                <input type="text" name="product_name" id="editModalProductName" required class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Case Price (₹) <span style="color:#ef4444;">*</span></label>
                                <input type="number" step="0.01" name="case_price" id="editModalCasePrice" required class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px;">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Warehouse Case Stock <span style="color:#ef4444;">*</span></label>
                                <input type="number" name="case_stock" id="editModalCaseStock" min="0" required class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Net Content</label>
                                <input type="number" step="any" name="net_content" id="editModalNetContent" placeholder="e.g. 500" class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Unit</label>
                                <select name="net_content_unit" id="editModalNetUnit" class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; background: #fff;">
                                    <option value="ML">ML</option>
                                    <option value="L">L</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 14px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Catalog Status</label>
                                <select name="status" id="editModalStatus" class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; background: #fff;">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 5px;">Description</label>
                                <input type="text" name="description" id="editModalDescription" placeholder="Short description..." class="form-input" style="width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px;">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 10px;">
                            <button type="button" onclick="closeProductEditModal()" style="padding: 10px 16px; border: 1px solid #cbd5e1; background: #fff; color: #475569; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;">Cancel</button>
                            <button type="submit" id="btnSaveProductModal" style="padding: 10px 20px; border: none; background: #2563eb; color: #fff; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                                <i class='bx bx-check'></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    let currentProductModalData = null;

    function switchModalTab(tab) {
        const tabDamaged = document.getElementById('tabContentDamaged');
        const tabDetails = document.getElementById('tabContentDetails');
        const btnDamaged = document.getElementById('tabBtnDamaged');
        const btnDetails = document.getElementById('tabBtnDetails');

        if (tab === 'damaged') {
            tabDamaged.style.display = 'block';
            tabDetails.style.display = 'none';
            btnDamaged.classList.add('active');
            btnDetails.classList.remove('active');
        } else {
            tabDamaged.style.display = 'none';
            tabDetails.style.display = 'block';
            btnDamaged.classList.remove('active');
            btnDetails.classList.add('active');
        }
    }

    function openProductEditModal(productId, defaultTab = 'damaged') {
        const modal = document.getElementById('editProductModal');
        if (!modal) return;

        modal.style.display = 'flex';
        document.getElementById('modalProductTitle').textContent = 'Loading Product #' + productId + '...';
        document.getElementById('damagedItemsContainer').innerHTML = '<div style="text-align: center; padding: 30px; color: #64748b;"><i class="bx bx-loader-alt bx-spin" style="font-size: 26px;"></i><br>Fetching damaged items and stock details...</div>';

        fetch('index.php?action=get_product_data&id=' + productId)
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    alert(res.error || 'Failed to load product details.');
                    closeProductEditModal();
                    return;
                }
                currentProductModalData = res;
                renderProductModal(res, defaultTab);
            })
            .catch(err => {
                console.error('Error fetching product data:', err);
                alert('Failed to connect to server. Please try again.');
                closeProductEditModal();
            });
    }

    function closeProductEditModal() {
        const modal = document.getElementById('editProductModal');
        if (modal) modal.style.display = 'none';
        currentProductModalData = null;
    }

    function toggleReportDamagedSection() {
        const box = document.getElementById('reportDamagedFormBox');
        const txt = document.getElementById('btnReportToggleText');
        if (!box) return;
        if (box.style.display === 'none' || !box.style.display) {
            box.style.display = 'block';
            if (txt) txt.textContent = 'Cancel';
        } else {
            box.style.display = 'none';
            if (txt) txt.textContent = '+ Report Damaged Cases';
        }
    }

    function submitRecordDamaged(e) {
        e.preventDefault();
        const form = document.getElementById('formRecordDamaged');
        const btn = document.getElementById('btnSubmitDamagedModal');
        if (!form) return;

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Deducting...";

        const fd = new FormData(form);
        fd.append('ajax', '1');

        fetch('index.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerHTML = "<i class='bx bx-check'></i> Save &amp; Deduct Stock";
                if (res.success) {
                    window.location.reload();
                } else {
                    alert(res.error || 'Failed to record damaged item.');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = "<i class='bx bx-check'></i> Save &amp; Deduct Stock";
                console.error(err);
                alert('Server error while recording damaged item.');
            });
    }

    function renderProductModal(data, defaultTab) {
        const p = data.product;
        const damaged = data.damaged_items || [];
        const vehicles = data.vehicles || [];

        const pName = p.product_name || p.name;
        document.getElementById('modalProductTitle').textContent = pName;

        // Update tab badge & subtitle
        const totalDamagedCases = damaged.reduce((acc, row) => acc + parseInt(row.damaged_cases || 0), 0);
        document.getElementById('modalDamagedCountBadge').textContent = totalDamagedCases + ' cs';

        const inTransitCases = parseInt(p.cases_in_transit || 0);
        const cStock = parseInt(p.case_stock || p.stock || 0);
        const availStock = Math.max(0, cStock - inTransitCases);

        let subtitle = 'SKU #' + p.product_id + ' | Available Stock: ' + availStock + ' Cases';
        if (inTransitCases > 0) {
            subtitle += ' (' + inTransitCases + ' cs in transit)';
        }
        if (totalDamagedCases > 0) {
            subtitle += ' (' + totalDamagedCases + ' cs damaged deducted)';
        }
        document.getElementById('modalProductSubtitle').textContent = subtitle;

        // Populate report damaged form
        const reportPid = document.getElementById('reportDamageModalProductId');
        if (reportPid) reportPid.value = p.product_id;
        const vSelect = document.getElementById('reportDamageVehicleSelect');
        if (vSelect) {
            let vHtml = '<option value="">-- Select Delivery Vehicle --</option>';
            vehicles.forEach(veh => {
                const vNum = veh.vehicle_number || ('Vehicle #' + veh.id);
                const vName = veh.name ? ` (${veh.name})` : '';
                vHtml += `<option value="${veh.id}">${vNum}${vName}</option>`;
            });
            vSelect.innerHTML = vHtml;
        }
        const formBox = document.getElementById('reportDamagedFormBox');
        if (formBox) formBox.style.display = 'none';
        const txt = document.getElementById('btnReportToggleText');
        if (txt) txt.textContent = '+ Report Damaged Cases';

        // Populate details form
        document.getElementById('editModalProductId').value = p.product_id;
        document.getElementById('editModalProductName').value = pName;
        document.getElementById('editModalCasePrice').value = p.case_price || p.price || '';
        document.getElementById('editModalCaseStock').value = p.case_stock || p.stock || 0;
        document.getElementById('editModalNetContent').value = p.net_content || '';
        document.getElementById('editModalNetUnit').value = p.net_content_unit || 'ML';
        document.getElementById('editModalStatus').value = p.status || 'active';
        document.getElementById('editModalDescription').value = p.description || '';

        // Render damaged items table
        const container = document.getElementById('damagedItemsContainer');
        if (damaged.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 36px 20px; background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 12px; color: #64748b;">
                    <i class='bx bx-check-shield' style="font-size: 40px; color: #10b981; margin-bottom: 8px;"></i>
                    <div style="font-weight: 700; color: #1e293b; font-size: 15px;">No Reported Damaged Stock</div>
                    <div style="font-size: 13px; color: #64748b; margin-top: 4px;">There are currently no active damaged items reported on any delivery vehicle for this product.</div>
                </div>
            `;
        } else {
            let html = `
                <table class="damaged-table-box">
                    <thead>
                        <tr>
                            <th>Vehicle &amp; Zone</th>
                            <th>Damaged</th>
                            <th>Reported Info &amp; Notes</th>
                            <th style="text-align: right; min-width: 250px;">Resolution Action</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            damaged.forEach(item => {
                const vNum = item.vehicle_number || ('Vehicle #' + item.vehicle_id);
                const vName = item.vehicle_name ? ` (${item.vehicle_name})` : '';
                const driver = item.driver_name ? `<div style="font-size: 11px; color: #64748b;"><i class='bx bx-user'></i> ${item.driver_name}</div>` : '';
                const zone = item.zone_name ? `<span style="background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">${item.zone_name}</span>` : '';
                const dateStr = item.created_at ? new Date(item.created_at).toLocaleString('en-IN', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '';
                const notes = item.notes ? `<div style="font-size: 12px; color: #b91c1c; margin-top: 3px;"><i class='bx bx-note'></i> ${item.notes}</div>` : '';

                html += `
                    <tr id="damaged-row-${item.id}">
                        <td>
                            <div style="font-weight: 700; color: #0f172a; font-size: 13px;">${vNum}${vName}</div>
                            <div style="margin-top: 3px; display: flex; align-items: center; gap: 6px;">
                                ${zone}
                                ${driver}
                            </div>
                        </td>
                        <td>
                            <span style="background: #fee2e2; color: #dc2626; font-weight: 800; font-size: 13px; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 3px;">
                                <i class='bx bx-error-circle'></i> ${item.damaged_cases} cs
                            </span>
                        </td>
                        <td>
                            <div style="font-size: 11px; color: #64748b;">${dateStr} &bull; ${item.reported_by || 'Zone'}</div>
                            ${notes}
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                <button type="button" class="btn-resolve btn-resolve-corrected" onclick="handleResolveDamaged(${item.id}, 'corrected', 1, '${item.damaged_cases}')" title="Mark item corrected (restores cases back to available stock)">
                                    <i class='bx bx-check-circle'></i> Corrected
                                </button>
                                <button type="button" class="btn-resolve btn-resolve-transfer" onclick="handleResolveDamaged(${item.id}, 'transferred', 1, '${item.damaged_cases}')" title="Transfer cases back to warehouse inventory (restores stock)">
                                    <i class='bx bx-transfer'></i> Transferred
                                </button>
                                <button type="button" class="btn-resolve btn-resolve-remove" onclick="handleResolveDamaged(${item.id}, 'resolved', 0, '${item.damaged_cases}')" title="Remove and write-off damaged record (damaged stock remains deducted)">
                                    <i class='bx bx-trash'></i> Remove
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;
            container.innerHTML = html;
        }

        switchModalTab(defaultTab || (damaged.length > 0 ? 'damaged' : 'details'));
    }

    function handleResolveDamaged(damagedId, action, restoreStock, cases) {
        let confirmMsg = '';
        if (action === 'corrected') {
            confirmMsg = `Mark ${cases} damaged cases as CORRECTED? This will restore ${cases} cases back to available product stock.`;
        } else if (action === 'transferred') {
            confirmMsg = `Mark ${cases} damaged cases as TRANSFERRED? This will restore ${cases} cases back to warehouse stock.`;
        } else {
            confirmMsg = `Are you sure you want to REMOVE / WRITE-OFF ${cases} damaged cases? (Record will be archived; stock remains deducted as loss)`;
        }

        if (!confirm(confirmMsg)) return;

        const fd = new FormData();
        fd.append('resolve_damaged_item', '1');
        fd.append('damaged_id', damagedId);
        fd.append('resolution_action', action);
        fd.append('restore_to_warehouse', restoreStock ? '1' : '0');
        fd.append('ajax', '1');

        fetch('index.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    // Refresh current page so damaged column in the main table updates immediately
                    window.location.reload();
                } else {
                    alert(res.error || 'Failed to resolve damaged item.');
                }
            })
            .catch(err => {
                console.error('Resolution error:', err);
                alert('Server error while resolving item.');
            });
    }

    function submitProductModalForm(e) {
        const btn = document.getElementById('btnSaveProductModal');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving...";
        }
    }
    </script>
</body>
</html>