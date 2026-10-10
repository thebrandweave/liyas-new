<?php
/**
 * /zone/orders/dashboard/products/index.php
 * Zone Orders Portal - Product Inventory Management
 * Provides live stock monitoring, transit allocations, and product management.
 */

require_once dirname(__DIR__, 2) . '/auth_helper.php';

// Enforce Zone Orders Authentication
requireZoneOrdersAuth();

// Optional activity logger
$logger_file = dirname(__DIR__, 4) . '/admin/includes/activity_logger.php';
if (file_exists($logger_file)) {
    require_once $logger_file;
}

// Flash messages
$success_message = '';
$error_message = '';
if (isset($_SESSION['error_message'])) { 
    $error_message = $_SESSION['error_message']; 
    unset($_SESSION['error_message']); 
}
if (isset($_SESSION['success_message'])) { 
    $success_message = $_SESSION['success_message']; 
    unset($_SESSION['success_message']); 
}
if (isset($_GET['added']) && $_GET['added'] == '1') { 
    $success_message = "Product added successfully!"; 
}
if (isset($_GET['updated']) && $_GET['updated'] == '1') { 
    $success_message = "Product updated successfully!"; 
}

// Inline Delete Action
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    try {
        // Protect products with associated orders
        $chkOrder = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE product_id = ?");
        $chkOrder->execute([$del_id]);
        $orderCount = (int)$chkOrder->fetchColumn();

        if ($orderCount > 0) {
            $error_message = "Cannot delete this product because it has {$orderCount} order(s) associated with it. You can set its status to Inactive instead.";
        } else {
            $checkStmt = $pdo->prepare("SELECT name, product_name, image FROM products WHERE product_id = ?");
            $checkStmt->execute([$del_id]);
            $product_data = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product_data) {
                if (!empty($product_data['image'])) {
                    $upload_dir = dirname(__DIR__, 4) . '/admin/uploads/products/';
                    $full_path = $upload_dir . $product_data['image'];
                    if (file_exists($full_path)) {
                        @unlink($full_path);
                    }
                }
                $delStmt = $pdo->prepare("DELETE FROM products WHERE product_id = ?");
                $delStmt->execute([$del_id]);

                if (function_exists('quickLog')) {
                    quickLog($pdo, 'delete', 'product', $del_id, "Deleted product via Zone Portal: " . ($product_data['product_name'] ?: $product_data['name']));
                }
                $success_message = "Product deleted successfully!";
            } else {
                $error_message = "Product not found.";
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Search & Pagination
$search = trim($_GET['search'] ?? '');
$searchTerm = "%$search%";
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$count_sql = "SELECT COUNT(*) FROM products WHERE name LIKE ? OR product_name LIKE ? OR description LIKE ?";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
$total_records = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));

// Fetch products with stock allocation across zones
$query = "
    SELECT 
        p.*,
        COALESCE(SUM(CASE WHEN o.status != 'cancelled' THEN o.quantity ELSE 0 END), 0) AS total_cases_in_zones,
        COALESCE(SUM(CASE WHEN o.status = 'delivered' THEN o.quantity ELSE 0 END), 0) AS cases_delivered,
        COALESCE(SUM(CASE WHEN o.status IN ('pending', 'processing', 'shipped') THEN o.quantity ELSE 0 END), 0) AS cases_in_transit
    FROM products p
    LEFT JOIN orders o ON p.product_id = o.product_id
    WHERE p.name LIKE :search1 OR p.product_name LIKE :search2 OR p.description LIKE :search3
    GROUP BY p.product_id
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

// Overall Totals for Stats Bar
$totalsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_skus,
        COALESCE(SUM(COALESCE(case_stock, stock, 0)), 0) as total_stock
    FROM products
");
$overall = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_skus' => 0, 'total_stock' => 0];

$ordersSummaryStmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN status IN ('pending', 'processing', 'shipped') THEN quantity ELSE 0 END), 0) as active_in_transit,
        COALESCE(SUM(CASE WHEN status = 'delivered' THEN quantity ELSE 0 END), 0) as total_delivered
    FROM orders
");
$orderTotals = $ordersSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['active_in_transit' => 0, 'total_delivered' => 0];

$total_available_stock = (int)$overall['total_stock'];
$total_in_transit = (int)$orderTotals['active_in_transit'];
$total_delivered = (int)$orderTotals['total_delivered'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Products &amp; Inventory - Zone Orders Portal</title>
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
            --info: #0284c7;
            --purple: #7c3aed;
            --body-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-sub: #475569;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --shadow-subtle: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
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

        /* Fixed / Sticky Header */
        .portal-header {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
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
            width: 44px;
            height: 44px;
            border-radius: 12px;
            object-fit: cover;
            border: 1.5px solid var(--border-color);
            box-shadow: var(--shadow-subtle);
        }

        .brand-info h1 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
            line-height: 1.2;
        }

        .brand-info p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .badge-portal {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            border: 1px solid #bfdbfe;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
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
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-sub);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-header:hover {
            background: #f1f5f9;
            color: var(--text-main);
            border-color: #cbd5e1;
        }

        .btn-header-primary {
            background: var(--primary);
            color: #ffffff !important;
            border-color: var(--primary-dark);
        }

        .btn-header-primary:hover {
            background: var(--primary-dark);
            color: #ffffff !important;
        }

        .btn-logout {
            color: #dc2626;
            background: #fef2f2;
            border-color: #fecaca;
        }

        .btn-logout:hover {
            background: #fee2e2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        /* Container */
        .portal-container {
            max-width: 1400px;
            margin: 24px auto 0;
            padding: 0 20px;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            box-shadow: var(--shadow-subtle);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-card);
        }

        .metric-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }

        .m-blue::before { background: var(--primary); }
        .m-emerald::before { background: var(--success); }
        .m-amber::before { background: var(--warning); }
        .m-purple::before { background: var(--purple); }

        .metric-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .metric-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
        }

        .metric-icon {
            font-size: 20px;
            color: var(--text-muted);
        }

        .metric-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .metric-sub {
            font-size: 12px;
            color: var(--text-sub);
        }

        /* Controls Row */
        .table-controls-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-grow: 1;
            max-width: 460px;
        }

        .search-input-wrap {
            position: relative;
            width: 100%;
        }

        .search-input-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
        }

        .search-input {
            width: 100%;
            padding: 9px 12px 9px 38px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            outline: none;
            background: #f8fafc;
            transition: all 0.15s ease;
        }

        .search-input:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .btn-search {
            padding: 9px 16px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-add-product {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #059669;
            color: #ffffff;
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-add-product:hover {
            background: #047857;
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
        }

        /* Products Table Card */
        .products-table-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-subtle);
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .products-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 14px 16px;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        .products-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #1e293b;
        }

        .products-table tr:hover {
            background: #f8fafc;
        }

        .product-meta-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .product-thumb {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border-color);
            background: #f1f5f9;
            flex-shrink: 0;
        }

        .product-thumb-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            border: 1px solid #bfdbfe;
            flex-shrink: 0;
        }

        .product-title {
            font-weight: 700;
            color: var(--text-main);
            font-size: 14px;
        }

        .product-desc {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            max-width: 260px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .price-badge {
            font-weight: 800;
            font-size: 14px;
            color: #0284c7;
        }

        .badge-stock {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
        }
        .badge-stock-healthy { background: #dcfce7; color: #15803d; }
        .badge-stock-low { background: #fef3c7; color: #b45309; }
        .badge-stock-out { background: #fee2e2; color: #b91c1c; }

        .transit-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
        }

        .delivered-badge {
            font-weight: 700;
            color: #2563eb;
            font-size: 13px;
        }

        .action-btns {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-act {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-act-edit {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }
        .btn-act-edit:hover {
            background: #dbeafe;
        }

        .btn-act-del {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .btn-act-del:hover {
            background: #fee2e2;
        }

        /* Pagination */
        .pagination-bar {
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border-top: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-muted);
        }

        .page-links {
            display: flex;
            gap: 4px;
        }

        .page-link {
            padding: 5px 10px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: #fff;
            color: var(--text-main);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .page-link.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <!-- Top Fixed Portal Header -->
    <header class="portal-header">
        <div class="header-inner">
            <a href="index.php" class="brand-block">
            
                <div class="brand-info">
                    <h1>
                        <span>Product Inventory</span>
        
                    </h1>
                    <p>Stock Management</p>
                </div>
            </a>

            <div class="header-actions">
                <a href="<?= BASE_URL ?>/zone/orders/dashboard/" class="btn-header" title="Go back to Live Multi-Zone Orders Hub">
                    <i class='bx bx-shopping-bag'></i>
                    <span>Orders Dashboard</span>
                </a>
                <a href="add.php" class="btn-header btn-header-primary" title="Add New Product SKU">
                    <i class='bx bx-plus-circle'></i>
                    <span>+ Add Product</span>
                </a>
                <a href="<?= BASE_URL ?>/zone/orders/logout.php" class="btn-header btn-logout" title="Lock and logout">
                    <i class='bx bx-lock-alt'></i>
                    <span>Lock / Logout</span>
                </a>
            </div>
        </div>
    </header>

    <main class="portal-container">
        <!-- Messages -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle' style="font-size: 20px;"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Key Metrics Cards -->
        <section class="metrics-grid">
            <div class="metric-card m-blue">
                <div class="metric-header">
                    <span class="metric-label">Active SKUs</span>
                    <i class='bx bx-package metric-icon'></i>
                </div>
                <div class="metric-value"><?= (int)$overall['total_skus'] ?></div>
                <div class="metric-sub">Catalog Products</div>
            </div>

            <div class="metric-card m-emerald">
                <div class="metric-header">
                    <span class="metric-label">Warehouse Stock</span>
                    <i class='bx bx-box metric-icon'></i>
                </div>
                <div class="metric-value"><?= number_format($total_available_stock) ?></div>
                <div class="metric-sub">Available Cases</div>
            </div>

            <div class="metric-card m-amber">
                <div class="metric-header">
                    <span class="metric-label">In Transit / Routes</span>
                    <i class='bx bx-cycling metric-icon'></i>
                </div>
                <div class="metric-value"><?= number_format($total_in_transit) ?></div>
                <div class="metric-sub">Pending / Processing Cases</div>
            </div>

            <div class="metric-card m-purple">
                <div class="metric-header">
                    <span class="metric-label">Delivered Cases</span>
                    <i class='bx bx-check-circle metric-icon'></i>
                </div>
                <div class="metric-value"><?= number_format($total_delivered) ?></div>
                <div class="metric-sub">Fulfilled Across Zones</div>
            </div>
        </section>

        <!-- Controls Row -->
        <div class="table-controls-card">
            <form action="index.php" method="GET" class="search-form">
                <div class="search-input-wrap">
                    <i class='bx bx-search'></i>
                    <input type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search product name, description..." class="search-input">
                </div>
                <button type="submit" class="btn-search">Search</button>
                <?php if (!empty($search)): ?>
                    <a href="index.php" class="btn-header" style="padding: 9px 12px;" title="Reset filter">Clear</a>
                <?php endif; ?>
            </form>

            <a href="add.php" class="btn-add-product">
                <i class='bx bx-plus-circle' style="font-size: 17px;"></i>
                <span>Add Product</span>
            </a>
        </div>

        <!-- Products Table Card -->
        <div class="products-table-card">
            <div class="table-responsive">
                <table class="products-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Net Content</th>
                            <th>Unit</th>
                            <th>Case Price</th>
                            <th>Available Stock</th>
                            <th>In Transit</th>
                            <th>Delivered</th>
                            <th>Stock Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 48px 20px; color: #94a3b8;">
                                    <i class='bx bx-cube' style="font-size: 40px; margin-bottom: 8px; display: block; color: #cbd5e1;"></i>
                                    <div style="font-weight: 700; font-size: 15px; color: #475569;">No products found</div>
                                    <div style="font-size: 13px; margin-top: 4px;">
                                        <?= !empty($search) ? 'No results matched your search query.' : 'Add your first warehouse product SKU.' ?>
                                    </div>
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

                                $stockClass = 'badge-stock-healthy';
                                $statusText = 'In Stock';
                                if ($caseStock === 0) {
                                    $stockClass = 'badge-stock-out';
                                    $statusText = 'Out of Stock';
                                } elseif ($caseStock < 20) {
                                    $stockClass = 'badge-stock-low';
                                    $statusText = 'Low Stock';
                                }

                                $imgFile = $product['image'];
                                $hasImg = !empty($imgFile) && file_exists(dirname(__DIR__, 4) . '/admin/uploads/products/' . $imgFile);
                            ?>
                            <tr>
                                <td>
                                    <div class="product-meta-cell">
                                        <?php if ($hasImg): ?>
                                            <img src="<?= BASE_URL ?>/admin/uploads/products/<?= htmlspecialchars($imgFile) ?>" alt="<?= $displayName ?>" class="product-thumb">
                                        <?php else: ?>
                                            <div class="product-thumb-placeholder">
                                                <i class='bx bx-cube'></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="product-title"><?= $displayName ?></div>
                                            <?php if (!empty($product['description'])): ?>
                                                <div class="product-desc" title="<?= htmlspecialchars($product['description']) ?>">
                                                    <?= htmlspecialchars($product['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= $netContent ?></strong>
                                </td>
                                <td>
                                    <span style="font-weight: 600; color: #475569;"><?= $unit ?></span>
                                </td>
                                <td>
                                    <span class="price-badge"><?= formatCurrency($casePrice) ?></span>
                                    <span style="font-size: 11px; color: #64748b;">/ cs</span>
                                </td>
                                <td>
                                    <span style="font-weight: 800; font-size: 14px; color: #0f172a;"><?= number_format($caseStock) ?></span>
                                    <span style="font-size: 12px; color: #64748b;">cs</span>
                                </td>
                                <td>
                                    <?php if ($inTransit > 0): ?>
                                        <span class="transit-badge">
                                            <i class='bx bx-car'></i> <?= number_format($inTransit) ?> cs
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($delivered > 0): ?>
                                        <span class="delivered-badge"><?= number_format($delivered) ?> cs</span>
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-stock <?= $stockClass ?>">
                                        <?= $statusText ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns" style="justify-content: flex-end;">
                                        <a href="edit.php?id=<?= $product['product_id'] ?>" class="btn-act btn-act-edit" title="Edit Product Details">
                                            <i class='bx bx-edit'></i> Edit
                                        </a>
                                        <a href="index.php?delete=<?= $product['product_id'] ?>" onclick="return confirm('Are you sure you want to delete <?= addslashes($displayName) ?>?');" class="btn-act btn-act-del" title="Delete Product">
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

            <?php if ($total_pages > 1): ?>
                <div class="pagination-bar">
                    <div>
                        Showing <?= count($products) ?> of <?= $total_records ?> products
                    </div>
                    <div class="page-links">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="index.php?page=<?= $p ?>&search=<?= urlencode($search) ?>" class="page-link <?= ($p == $page) ? 'active' : '' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
