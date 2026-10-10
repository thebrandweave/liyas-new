<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "zones";
$page_title = "Zones Management";

// --- MESSAGES ---
if (isset($_SESSION['error_message'])) { $error_message = $_SESSION['error_message']; unset($_SESSION['error_message']); }
if (isset($_SESSION['success_message'])) { $success_message = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_GET['added']) && $_GET['added'] == '1') { $success_message = "Zone created successfully!"; }
if (isset($_GET['updated']) && $_GET['updated'] == '1') { $success_message = "Zone updated successfully!"; }

// Search
$search = trim($_GET['search'] ?? '');
$searchTerm = "%$search%";

try {
    if (!empty($search)) {
        $stmt = $pdo->prepare("
            SELECT z.*, 
                   COUNT(o.order_id) as total_orders,
                   SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                   SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders
            FROM zones z
            LEFT JOIN orders o ON z.id = o.zone_id
            WHERE z.name LIKE ? OR z.slug LIKE ?
            GROUP BY z.id
            ORDER BY z.name ASC
        ");
        $stmt->execute([$searchTerm, $searchTerm]);
    } else {
        $stmt = $pdo->query("
            SELECT z.*, 
                   COUNT(o.order_id) as total_orders,
                   SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                   SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders
            FROM zones z
            LEFT JOIN orders o ON z.id = o.zone_id
            GROUP BY z.id
            ORDER BY z.name ASC
        ");
    }
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $zones = [];
    $error_message = "Error loading zones: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Zones - Liyas Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .zone-path-badge {
            background: #eff6ff;
            color: #2563eb;
            font-family: monospace;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #bfdbfe;
        }
        .zone-path-badge:hover {
            background: #dbeafe;
        }
        .status-badge-active {
            background: #d1fae5;
            color: #065f46;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge-inactive {
            background: #fee2e2;
            color: #991b1b;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        .btn-view-portal {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
        }
        .btn-view-portal:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb">
                    <i class='bx bx-map-pin'></i>
                    <span>Delivery Zones</span>
                </div>
                <div class="header-actions">
                    <form action="index.php" method="GET" style="display: flex; gap: 0.5rem;">
                        <input type="search" name="search" placeholder="Search zones..." value="<?= htmlspecialchars($search) ?>" class="form-input" style="width: 220px;">
                        <button type="submit" class="header-btn" style="padding: 0.5rem;"><i class='bx bx-search'></i></button>
                    </form>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success" style="padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 1.5rem;">
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem;">
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <div class="table-card">
                    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem;">
                        <div>
                            <div class="table-title" style="font-size: 18px; font-weight: 600;">All Delivery Zones</div>
                            <div style="font-size: 13px; color: var(--text-secondary); margin-top: 2px;">
                                Central warehouse distributes orders to these dynamic zones.
                            </div>
                        </div>
                        <div class="table-actions" style="display: flex; gap: 8px;">
                            <a href="<?= BASE_URL ?>/zone/orders/dashboard/" target="_blank" class="btn-action noselect" style="text-decoration: none; background: #2563eb; color: #fff; padding: 6px 12px; border-radius: 6px; font-size: 13px; display: inline-flex; align-items: center; gap: 5px;">
                                <i class='bx bx-broadcast'></i>
                                <span>Zone Orders Hub</span>
                            </a>
                            <a href="create.php" class="btn-action btn-add noselect" style="text-decoration: none;">
                                <span class="text">+ Add Zone</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="table-responsive-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Zone Name</th>
                                    <th>Portal Path</th>
                                    <th>Access Code</th>
                                    <th>Orders</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($zones)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2.5rem; color: #64748b;">
                                            No delivery zones found. <a href="create.php" style="color: var(--blue);">Add your first zone</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($zones as $zone): 
                                        $portalUrl = BASE_URL . '/' . htmlspecialchars($zone['slug']);
                                        $zoneCode = !empty($zone['access_code']) ? htmlspecialchars($zone['access_code']) : 'ZONE2026';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 14px;">
                                                <?= htmlspecialchars($zone['name']) ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b;">
                                                Created: <?= date('d M Y', strtotime($zone['created_at'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="<?= $portalUrl ?>" target="_blank" class="zone-path-badge" title="Open Delivery Portal">
                                                /<?= htmlspecialchars($zone['slug']) ?>
                                                <i class='bx bx-external-link' style="font-size: 14px;"></i>
                                            </a>
                                        </td>
                                        <td>
                                            <code style="background: #f1f5f9; padding: 3px 8px; border-radius: 6px; font-weight: 700; color: #1e293b; font-size: 12px; border: 1px solid #cbd5e1;"><?= $zoneCode ?></code>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <strong><?= (int)$zone['total_orders'] ?></strong> total
                                                <?php if ((int)$zone['pending_orders'] > 0): ?>
                                                    <span style="color: #ef4444; font-weight: 600;">(<?= (int)$zone['pending_orders'] ?> pending)</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 12px; color: #10b981;">
                                                <?= (int)$zone['delivered_orders'] ?> delivered
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($zone['status'] === 'active'): ?>
                                                <span class="status-badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <a href="<?= $portalUrl ?>" target="_blank" class="btn-view-portal" title="Open Portal">
                                                    <i class='bx bx-door-open'></i> Portal
                                                </a>
                                                <a href="edit.php?id=<?= $zone['id'] ?>" class="btn-action" style="padding: 5px 10px; background: #e0f2fe; color: #0284c7; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;">
                                                    <i class='bx bx-edit'></i> Edit
                                                </a>
                                                <a href="delete.php?id=<?= $zone['id'] ?>" onclick="return confirm('Are you sure you want to delete this zone?');" class="btn-action" style="padding: 5px 10px; background: #fee2e2; color: #dc2626; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;">
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
