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

// Warehouse totals
$total_available_stock = 0;
$total_in_transit = 0;
$total_delivered = 0;
foreach ($products as $p) {
    $cStock = (int)($p['case_stock'] ?: $p['stock']);
    $inTransit = (int)$p['cases_in_transit'];
    $delivered = (int)$p['cases_delivered'];
    $total_available_stock += $cStock;
    $total_in_transit += $inTransit;
    $total_delivered += $delivered;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <title>Products Inventory - Liyas Website</title>
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .table-card table tbody td { font-size: 14px; color: #334155; }
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
                                    <th>In Transit</th>
                                    <th>Delivered</th>
                                    <th>Stock Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 2.5rem; color: #64748b;">
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

                                        $stockClass = 'badge-stock-healthy';
                                        $statusText = 'In Stock';
                                        if ($caseStock === 0) {
                                            $stockClass = 'badge-stock-out';
                                            $statusText = 'Out of Stock';
                                        } elseif ($caseStock < 20) {
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
                                            <span style="font-weight: 700; font-size: 15px; color: #0f172a;"><?= number_format($caseStock) ?></span> Cases
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
                                                <?= $statusText ?> (<?= number_format($caseStock) ?>)
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <a href="edit.php?id=<?= $product['product_id'] ?>" class="btn-action" style="padding: 5px 10px; background: #e0f2fe; color: #0284c7; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;">
                                                    <i class='bx bx-edit'></i> Edit
                                                </a>
                                                <a href="index.php?delete=<?= $product['product_id'] ?>" onclick="return confirm('Are you sure you want to delete <?= addslashes($displayName) ?>?');" class="btn-action" style="padding: 5px 10px; background: #fee2e2; color: #dc2626; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;">
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
</body>
</html>