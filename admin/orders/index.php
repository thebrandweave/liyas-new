<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = "orders";
$page_title   = "Warehouse Orders";

// Filters from request
$status_filter = $_GET['filter'] ?? 'all';
$zone_filter   = $_GET['zone'] ?? 'all';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 25;
$offset        = ($page - 1) * $per_page;

// Handle status update
$success_message = '';
$error_message   = '';
if (isset($_GET['added'])) { $success_message = "Order created successfully!"; }
if (isset($_GET['updated'])) { $success_message = "Order updated successfully!"; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    $allowed    = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];
    $is_ajax    = (isset($_POST['ajax']) && $_POST['ajax'] === '1') || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if ($order_id > 0 && in_array($new_status, $allowed)) {
        try {
            $oldStmt = $pdo->prepare("SELECT status FROM orders WHERE order_id = ?");
            $oldStmt->execute([$order_id]);
            $old_status = (string)$oldStmt->fetchColumn();

            $upStmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
            $upStmt->execute([$new_status, $order_id]);

            // Adjust stock if cancelled / un-cancelled
            handleOrderStatusStockChange($pdo, $order_id, $old_status, $new_status);

            // Update reward progress if delivered, cancelled, or un-delivered
            if ($new_status === 'delivered' || $old_status === 'delivered' || $new_status === 'cancelled') {
                $oData = $pdo->prepare("SELECT shop_name, customer_name, phone FROM orders WHERE order_id = ?");
                $oData->execute([$order_id]);
                $ord = $oData->fetch(PDO::FETCH_ASSOC);
                if ($ord) {
                    $sName = !empty($ord['shop_name']) ? $ord['shop_name'] : (!empty($ord['customer_name']) ? $ord['customer_name'] : '');
                    if ($sName !== '') {
                        updateShopRewardProgress($pdo, $sName, $ord['phone'] ?? '');
                    }
                }
            }

            quickLog($pdo, 'update_status', 'order', $order_id, "Updated order #{$order_id} status to {$new_status}");
            $success_message = "Order status updated to " . ucfirst($new_status);

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $success_message]);
                exit;
            }
        } catch (PDOException $e) {
            $error_message = "Error updating status: " . $e->getMessage();
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $error_message]);
                exit;
            }
        }
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid order ID or status']);
            exit;
        }
    }
}

// Load zones for filter dropdown
$zones_list = $pdo->query("SELECT id, name, slug FROM zones ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$where_params = [];
$where_clauses = [];

if ($status_filter === 'unchecked') {
    $where_clauses[] = "o.zone_id IS NOT NULL AND o.zone_id > 0 AND o.is_zone_read = 0 AND o.status != 'cancelled'";
} elseif ($status_filter !== 'all' && in_array($status_filter, ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'])) {
    $where_clauses[] = "o.status = :status";
    $where_params[':status'] = $status_filter;
}

if ($zone_filter !== 'all' && !empty($zone_filter)) {
    if (is_numeric($zone_filter)) {
        $where_clauses[] = "o.zone_id = :zone_id";
        $where_params[':zone_id'] = (int)$zone_filter;
    } else {
        $where_clauses[] = "z.slug = :zone_slug";
        $where_params[':zone_slug'] = $zone_filter;
    }
}

if (!empty($search)) {
    $where_clauses[] = "(
        o.shop_name LIKE :search 
        OR o.customer_name LIKE :search 
        OR o.phone LIKE :search 
        OR o.location LIKE :search 
        OR o.order_number LIKE :search 
        OR o.order_id = :order_id_search
    )";
    $where_params[':search'] = "%$search%";
    $where_params[':order_id_search'] = is_numeric($search) ? (int)$search : -1;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Count total matching
$count_query = "
    SELECT COUNT(*) 
    FROM orders o 
    LEFT JOIN zones z ON o.zone_id = z.id
    $where_sql
";
$cntStmt = $pdo->prepare($count_query);
foreach ($where_params as $k => $v) {
    if ($k === ':order_id_search' || $k === ':zone_id') {
        $cntStmt->bindValue($k, $v, PDO::PARAM_INT);
    } else {
        $cntStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
}
$cntStmt->execute();
$total_orders_count = (int)$cntStmt->fetchColumn();
$total_pages = ceil($total_orders_count / $per_page);

// Fetch orders with zone and product details
$orders_query = "
    SELECT 
        o.*,
        z.name as zone_name,
        z.slug as zone_slug,
        p.product_name,
        p.name as fallback_product_name,
        p.net_content,
        p.net_content_unit,
        u.name as web_user_name,
        r.bill_number,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS items_count,
        (SELECT GROUP_CONCAT(CONCAT(COALESCE(p2.product_name, p2.name), ' (', oi2.quantity, 'cs)') SEPARATOR ', ') 
         FROM order_items oi2 
         JOIN products p2 ON oi2.product_id = p2.product_id 
         WHERE oi2.order_id = o.order_id) AS items_summary
    FROM orders o
    LEFT JOIN zones z ON o.zone_id = z.id
    LEFT JOIN products p ON o.product_id = p.product_id
    LEFT JOIN users u ON o.user_id = u.user_id
    LEFT JOIN receipts r ON o.order_id = r.order_id
    $where_sql
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($orders_query);
foreach ($where_params as $k => $v) {
    if ($k === ':order_id_search' || $k === ':zone_id') {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Global status breakdown counts
$counts_data = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM orders
")->fetch(PDO::FETCH_ASSOC);

// Count active orders not yet checked by delivery zones
$unchecked_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM orders 
    WHERE zone_id IS NOT NULL AND zone_id > 0 AND is_zone_read = 0 AND status != 'cancelled'
")->fetchColumn();

// Helper to render table body rows consistently for initial page load and AJAX refreshes
function renderOrdersTbodyRows(array $orders, string $status_filter = 'all', string $zone_filter = 'all'): string {
    ob_start();
    if (empty($orders)) {
        ?>
        <tr>
            <td colspan="9" style="text-align: center; padding: 3rem; color: #64748b;">
                No orders matching your criteria. <a href="create.php" style="color: var(--blue);">Create an order</a>
            </td>
        </tr>
        <?php
    } else {
        foreach ($orders as $order) {
            $displayOrderNum = $order['order_number'] ?: ('#' . $order['order_id']);
            $shopName = htmlspecialchars($order['shop_name'] ?: ($order['customer_name'] ?: ($order['web_user_name'] ?: 'Customer #' . $order['user_id'])));
            $prodName = htmlspecialchars($order['product_name'] ?: ($order['fallback_product_name'] ?: 'Liyas Water'));
            $zoneDisplay = htmlspecialchars($order['zone_name'] ?: 'Unassigned');
            $zoneSlug = htmlspecialchars($order['zone_slug'] ?? '');
            ?>
            <tr data-order-id="<?= (int)$order['order_id'] ?>">
                <td>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <a href="view.php?id=<?= (int)$order['order_id'] ?>" style="font-weight: 600; color: #2563eb; text-decoration: none;">
                            <?= htmlspecialchars($displayOrderNum) ?>
                        </a>
                        <?php if (!empty($order['zone_name'])): ?>
                            <?php if ((int)$order['is_zone_read'] === 1): ?>
                                <i class='bx bx-check-double' id="zone-check-icon-<?= (int)$order['order_id'] ?>" style="color: #059669; font-size: 16px;" title="<?= !empty($order['zone_read_at']) ? 'Checked by ' . $zoneDisplay . ' on ' . date('d M, h:i A', strtotime($order['zone_read_at'])) : 'Checked by ' . $zoneDisplay ?>"></i>
                            <?php else: ?>
                                <span class="order-num-status-dot unread" id="zone-check-icon-<?= (int)$order['order_id'] ?>" title="Waiting for <?= $zoneDisplay ?> to check"></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($order['bill_number'])): ?>
                        <div style="font-size: 11px; color: #059669; font-weight: 500;">
                            <i class='bx bx-receipt'></i> <?= htmlspecialchars($order['bill_number']) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="font-weight: 600; color: #1e293b;">
                        <?= $shopName ?>
                    </div>
                    <?php if (!empty($order['location'])): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <i class='bx bx-map-pin' style="font-size: 11px;"></i> <?= htmlspecialchars($order['location']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['phone'])): ?>
                        <div style="font-size: 12px; color: #64748b;">
                            <i class='bx bx-phone' style="font-size: 11px;"></i> <?= htmlspecialchars($order['phone']) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($order['zone_name'])): ?>
                        <span class="order-zone-tag">
                            <?= $zoneDisplay ?>
                        </span>
                        <div style="margin-top: 4px;">
                            <?php if ((int)$order['is_zone_read'] === 1): ?>
                                <span class="zone-seen-badge seen" id="zone-status-badge-<?= (int)$order['order_id'] ?>" title="<?= !empty($order['zone_read_at']) ? 'Checked by ' . $zoneDisplay . ' on ' . date('d M Y, h:i A', strtotime($order['zone_read_at'])) : 'Checked by ' . $zoneDisplay ?>">
                                    <i class='bx bx-check-double'></i> Checked
                                </span>
                            <?php else: ?>
                                <span class="zone-seen-badge unread" id="zone-status-badge-<?= (int)$order['order_id'] ?>" title="Waiting for <?= $zoneDisplay ?> to open / check">
                                    <i class='bx bx-bell'></i> Unchecked
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="order-zone-tag none">Central</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                        <span><?= $prodName ?></span>
                        <?php if ((int)($order['items_count'] ?? 0) > 1): ?>
                            <span style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 11px; padding: 1px 6px; border-radius: 4px; font-weight: 700;" title="<?= htmlspecialchars($order['items_summary'] ?? '') ?>">
                                +<?= ((int)$order['items_count'] - 1) ?> more
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ((int)($order['items_count'] ?? 0) > 1 && !empty($order['items_summary'])): ?>
                        <div style="font-size: 11px; color: #64748b; margin-top: 2px;" title="<?= htmlspecialchars($order['items_summary']) ?>">
                            <?= htmlspecialchars($order['items_summary']) ?>
                        </div>
                    <?php elseif (!empty($order['net_content'])): ?>
                        <div style="font-size: 11px; color: #64748b;">
                            <?= (float)$order['net_content'] ?> <?= htmlspecialchars($order['net_content_unit'] ?? '') ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <strong style="font-size: 14px;"><?= (int)$order['quantity'] ?></strong> <span style="font-size: 11px; color: #64748b;">Cases total</span>
                </td>
                <td>
                    <div style="font-weight: 600; color: #0f172a; font-size: 14px;">
                        <?= formatCurrency($order['total_amount']) ?>
                    </div>
                    <?php if ((float)$order['discount'] > 0): ?>
                        <div style="font-size: 11px; color: #ef4444;">
                            - <?= formatCurrency($order['discount']) ?> off
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <!-- Inline Quick Status Updater -->
                    <?php
                        $st = strtolower(trim((string)$order['status']));
                        $statusClasses = [
                            'pending'    => 'status-pending',
                            'processing' => 'status-processing',
                            'shipped'    => 'status-shipped',
                            'delivered'  => 'status-delivered',
                            'cancelled'  => 'status-cancelled',
                            'returned'   => 'status-returned'
                        ];
                        $currentStatusClass = $statusClasses[$st] ?? 'status-pending';
                    ?>
                    <form action="index.php?filter=<?= urlencode($status_filter) ?>&zone=<?= urlencode($zone_filter) ?>" method="POST" style="margin: 0;" class="status-update-form" data-order-id="<?= (int)$order['order_id'] ?>">
                        <input type="hidden" name="update_status" value="1">
                        <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
                        <select name="status" class="status-select-input <?= $currentStatusClass ?>" onchange="handleOrderStatusChange(this, <?= (int)$order['order_id'] ?>)">
                            <option value="pending" <?= ($st === 'pending') ? 'selected' : '' ?>>Pending</option>
                            <option value="processing" <?= ($st === 'processing') ? 'selected' : '' ?>>Processing</option>
                            <option value="shipped" <?= ($st === 'shipped') ? 'selected' : '' ?>>Shipped</option>
                            <option value="delivered" <?= ($st === 'delivered') ? 'selected' : '' ?>>Delivered</option>
                            <option value="cancelled" <?= ($st === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                            <option value="returned" <?= ($st === 'returned') ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </form>
                </td>

                <td>
                    <div style="font-size: 13px; color: #475569;">
                        <?= date('d M Y', strtotime($order['created_at'])) ?>
                    </div>
                    <div style="font-size: 11px; color: #94a3b8;">
                        <?= date('H:i A', strtotime($order['created_at'])) ?>
                    </div>
                </td>
                <td>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <a href="view.php?id=<?= (int)$order['order_id'] ?>" class="btn-action" style="padding: 5px 8px; background: #f1f5f9; color: #334155; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;" title="View Order">
                            <i class='bx bx-show'></i> View
                        </a>
                        <a href="edit.php?id=<?= (int)$order['order_id'] ?>" class="btn-action" style="padding: 5px 8px; background: #e0f2fe; color: #0284c7; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;" title="Edit Order">
                            <i class='bx bx-edit'></i> Edit
                        </a>
                        <a href="delete.php?id=<?= (int)$order['order_id'] ?>" onclick="return confirm('Are you sure you want to delete order <?= addslashes($displayOrderNum) ?>?');" class="btn-action" style="padding: 5px 8px; background: #fee2e2; color: #dc2626; border-radius: 6px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 3px;" title="Delete Order">
                            <i class='bx bx-trash'></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php
        }
    }
    return ob_get_clean();
}

// Helper to render pagination HTML
function renderOrdersPagination(int $page, int $total_pages, string $status_filter = 'all', string $zone_filter = 'all', string $search = ''): string {
    if ($total_pages <= 1) {
        return '';
    }
    ob_start();
    ?>
    <div style="padding: 1.25rem; display: flex; justify-content: center; gap: 6px; border-top: 1px solid var(--border-light);">
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="index.php?page=<?= $i ?>&filter=<?= urlencode($status_filter) ?>&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" 
               style="padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; <?= ($i === $page) ? 'background: #2563eb; color: #fff;' : 'background: #f1f5f9; color: #334155;' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php
    return ob_get_clean();
}

// Handle AJAX Polling Request
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'            => true,
        'total_orders_count' => (int)$total_orders_count,
        'total_formatted'    => number_format($total_orders_count),
        'counts'             => [
            'total'      => (int)($counts_data['total'] ?? 0),
            'unchecked'  => (int)$unchecked_count,
            'pending'    => (int)($counts_data['pending'] ?? 0),
            'processing' => (int)($counts_data['processing'] ?? 0),
            'shipped'    => (int)($counts_data['shipped'] ?? 0),
            'delivered'  => (int)($counts_data['delivered'] ?? 0),
            'cancelled'  => (int)($counts_data['cancelled'] ?? 0),
        ],
        'tbody_html'         => renderOrdersTbodyRows($orders, $status_filter, $zone_filter),
        'pagination_html'    => renderOrdersPagination($page, $total_pages, $status_filter, $zone_filter, $search),
        'max_order_id'       => !empty($orders) ? (int)$orders[0]['order_id'] : 0,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Liyas Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../assets/css/prody-admin.css">
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }
        .status-pill-group {
            display: inline-flex;
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            padding: 3px;
            overflow-x: auto;
            max-width: 100%;
        }
        .status-pill {
            padding: 6px 14px;
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-decoration: none;
            border-radius: 6px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: 0.15s;
        }
        .status-pill:hover {
            color: #1e293b;
            background: #f8fafc;
        }
        .status-pill.active {
            background: #2563eb;
            color: #fff;
            font-weight: 600;
        }
        .badge-count-pill {
            background: rgba(0,0,0,0.1);
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 11px;
            transition: all 0.2s;
        }
        .status-pill.active .badge-count-pill {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }
        .zone-select-filter {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
        }
        /* Inline Status Dropdown Select */
        .status-select-input {
            padding: 5px 24px 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            border: 1.5px solid transparent;
            cursor: pointer;
            outline: none;
            transition: all 0.2s ease;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 11px;
            display: inline-block;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            line-height: 1.3;
        }

        .status-select-input:hover {
            filter: brightness(0.96);
            transform: translateY(-0.5px);
        }

        .status-select-input:focus {
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
        }

        .status-select-input.status-pending {
            background-color: #fef3c7 !important;
            color: #92400e !important;
            border-color: #fcd34d !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2392400e'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
        }

        .status-select-input.status-processing {
            background-color: #dbeafe !important;
            color: #1d4ed8 !important;
            border-color: #93c5fd !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%231d4ed8'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
        }

        .status-select-input.status-shipped {
            background-color: #f3e8ff !important;
            color: #7e22ce !important;
            border-color: #d8b4fe !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%237e22ce'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
        }

        .status-select-input.status-delivered {
            background-color: #dcfce7 !important;
            color: #15803d !important;
            border-color: #86efac !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2315803d'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
        }

        .status-select-input.status-cancelled {
            background-color: #fee2e2 !important;
            color: #b91c1c !important;
            border-color: #fca5a5 !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23b91c1c'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
        }

        .status-select-input.status-returned {
            background-color: #ede9fe !important;
            color: #6d28d9 !important;
            border-color: #c4b5fd !important;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%236d28d9'%3E%3Cpath d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
        }

        .status-select-input option {
            background: #ffffff;
            color: #1e293b;
            font-weight: 500;
        }
        .order-zone-tag {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        .order-zone-tag.none {
            background: #f1f5f9;
            color: #64748b;
            border-color: #e2e8f0;
        }

        .zone-seen-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 6px;
            white-space: nowrap;
            transition: all 0.3s ease;
        }
        .zone-seen-badge.seen {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        .zone-seen-badge.unread {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        .order-num-status-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            margin-left: 4px;
            vertical-align: middle;
        }
        .order-num-status-dot.unread {
            background: #f59e0b;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.25);
            animation: pulse-dot 1.5s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.3); }
        }
        @keyframes status-highlight {
            0% { background: #bbf7d0; transform: scale(1.1); }
            100% { background: #ecfdf5; transform: scale(1); }
        }

        /* Live 3s Refresh Indicator */
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 600;
            color: #059669;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            padding: 3px 9px;
            border-radius: 14px;
            transition: all 0.25s ease;
        }
        .live-indicator.fetching {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }
        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #10b981;
            display: inline-block;
            transition: background-color 0.25s;
        }
        .live-indicator.fetching .live-dot {
            background: #3b82f6;
            animation: live-pulse 0.5s infinite alternate;
        }
        @keyframes live-pulse {
            0% { transform: scale(0.8); opacity: 0.7; }
            100% { transform: scale(1.3); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>
        
        <div class="main-content">
            <div class="header">
                <div class="breadcrumb"><i class='bx bx-cart'></i> <span>Warehouse Orders</span></div>
                <div class="header-actions">
                    <a href="create.php" class="btn-action btn-add noselect" style="text-decoration: none; padding: 0.5rem 1rem;">
                        <span class="text">+ Add Order</span>
                    </a>
                </div>
            </div>
            
            <div class="content-area">
                <?php if (!empty($success_message)): ?><div class="alert alert-success" style="padding: 12px 16px; background: #d1fae5; color: #065f46; border-radius: 8px; margin-bottom: 1.5rem;"><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
                <?php if (!empty($error_message)): ?><div class="alert alert-error" style="padding: 12px 16px; background: #fee2e2; color: #991b1b; border-radius: 8px; margin-bottom: 1.5rem;"><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

                <!-- Filters Bar -->
                <div class="filter-bar">
                    <!-- Status Filter Pills -->
                    <div class="status-pill-group">
                        <a href="index.php?filter=all&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" class="status-pill <?= ($status_filter === 'all') ? 'active' : '' ?>">
                            All Orders <span class="badge-count-pill" id="badge-count-all"><?= (int)($counts_data['total'] ?? 0) ?></span>
                        </a>
                        <a href="index.php?filter=unchecked&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" id="pill-unchecked" class="status-pill <?= ($status_filter === 'unchecked') ? 'active' : '' ?>" style="<?= ($unchecked_count > 0 && $status_filter !== 'unchecked') ? 'border: 1px solid #fde68a; background: #fffbeb; color: #b45309;' : '' ?>" title="Orders assigned to zones that have not yet been checked by delivery staff">
                            <i class='bx bx-bell' style="font-size: 14px;"></i> Unchecked <span class="badge-count-pill" id="badge-count-unchecked" style="<?= ($unchecked_count > 0 && $status_filter !== 'unchecked') ? 'background: #d97706; color: #fff;' : '' ?>"><?= $unchecked_count ?></span>
                        </a>
                        <a href="index.php?filter=pending&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" class="status-pill <?= ($status_filter === 'pending') ? 'active' : '' ?>">
                            Pending <span class="badge-count-pill" id="badge-count-pending"><?= (int)($counts_data['pending'] ?? 0) ?></span>
                        </a>
                        <a href="index.php?filter=processing&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" class="status-pill <?= ($status_filter === 'processing') ? 'active' : '' ?>">
                            Processing <span class="badge-count-pill" id="badge-count-processing"><?= (int)($counts_data['processing'] ?? 0) ?></span>
                        </a>
                        <a href="index.php?filter=shipped&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" class="status-pill <?= ($status_filter === 'shipped') ? 'active' : '' ?>">
                            Shipped <span class="badge-count-pill" id="badge-count-shipped"><?= (int)($counts_data['shipped'] ?? 0) ?></span>
                        </a>
                        <a href="index.php?filter=delivered&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" class="status-pill <?= ($status_filter === 'delivered') ? 'active' : '' ?>">
                            Delivered <span class="badge-count-pill" id="badge-count-delivered"><?= (int)($counts_data['delivered'] ?? 0) ?></span>
                        </a>
                        <a href="index.php?filter=cancelled&zone=<?= urlencode($zone_filter) ?>&search=<?= urlencode($search) ?>" class="status-pill <?= ($status_filter === 'cancelled') ? 'active' : '' ?>">
                            Cancelled <span class="badge-count-pill" id="badge-count-cancelled"><?= (int)($counts_data['cancelled'] ?? 0) ?></span>
                        </a>
                    </div>

                    <!-- Zone Filter & Search -->
                    <form action="index.php" method="GET" style="display: flex; gap: 8px; align-items: center;">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($status_filter) ?>">
                        
                        <select name="zone" class="zone-select-filter" onchange="this.form.submit()">
                            <option value="all" <?= ($zone_filter === 'all') ? 'selected' : '' ?>>All Zones</option>
                            <?php foreach ($zones_list as $z): ?>
                                <option value="<?= htmlspecialchars($z['slug']) ?>" <?= ($zone_filter === $z['slug']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($z['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="search" name="search" placeholder="Search orders..." value="<?= htmlspecialchars($search) ?>" class="form-input" style="width: 200px;">
                        <button type="submit" class="header-btn" style="padding: 0.5rem;"><i class='bx bx-search'></i></button>
                    </form>
                </div>

                <!-- Orders Table Card -->
                <div class="table-card">
                    <div class="table-header" style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; flex-wrap: wrap; gap: 10px;">
                        <div class="table-title" style="font-size: 17px; font-weight: 600; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span>Orders (<span id="orders-total-count"><?= number_format($total_orders_count) ?></span> total)</span>
                            <?php if ($zone_filter !== 'all'): ?>
                                <span style="font-size: 13px; font-weight: normal; color: #2563eb;">— Filtered by Zone</span>
                            <?php endif; ?>
                            <span class="live-indicator" id="live-indicator" title="Auto-refreshing every 3 seconds">
                                <span class="live-dot"></span>
                                <span id="live-text">Live 3s</span>
                            </span>
                        </div>
                        <div class="table-actions">
                            <a href="create.php" class="btn-action btn-add noselect" style="text-decoration: none;">
                                <span class="text">+ Add Order</span>
                            </a>
                        </div>
                    </div>
                    
                    <div class="table-responsive-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Shop / Customer</th>
                                    <th>Zone</th>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="orders-table-body">
                                <?= renderOrdersTbodyRows($orders, $status_filter, $zone_filter) ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div id="orders-pagination-wrapper">
                        <?= renderOrdersPagination($page, $total_pages, $status_filter, $zone_filter, $search) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Sound Element -->
    <audio id="orderNotifyAudio" preload="auto">
        <source src="<?= BASE_URL ?>/assets/videos/notify.wav" type="audio/wav">
    </audio>

    <!-- Real-time 3-Second AJAX Poller & Handlers -->
    <script>
        (function() {
            const REFRESH_INTERVAL = 3000; // 3 seconds
            let isPolling = false;
            let autoRefreshPaused = false;
            let lastKnownMaxOrderId = <?= !empty($orders) ? (int)$orders[0]['order_id'] : 0 ?>;
            let lastKnownUncheckedCount = <?= (int)$unchecked_count ?>;

            // Unlock audio on first user click anywhere
            document.addEventListener('click', function unlockAudio() {
                const audio = document.getElementById('orderNotifyAudio');
                if (audio) {
                    audio.play().then(() => {
                        audio.pause();
                        audio.currentTime = 0;
                    }).catch(() => {});
                }
                document.removeEventListener('click', unlockAudio);
            }, { once: true });

            function playSound() {
                const audio = document.getElementById('orderNotifyAudio');
                if (audio) {
                    audio.currentTime = 0;
                    audio.play().catch(e => console.debug('Audio deferred until user interacts:', e));
                }
            }

            function setLiveState(isFetching) {
                const ind = document.getElementById('live-indicator');
                if (!ind) return;
                if (isFetching) {
                    ind.classList.add('fetching');
                } else {
                    ind.classList.remove('fetching');
                }
            }

            function updateBadge(id, count) {
                const el = document.getElementById(id);
                if (el) el.textContent = count;
            }

            function updateBadgesAndCounts(data) {
                if (!data || !data.counts) return;
                const c = data.counts;
                updateBadge('badge-count-all', c.total);
                updateBadge('badge-count-unchecked', c.unchecked);
                updateBadge('badge-count-pending', c.pending);
                updateBadge('badge-count-processing', c.processing);
                updateBadge('badge-count-shipped', c.shipped);
                updateBadge('badge-count-delivered', c.delivered);
                updateBadge('badge-count-cancelled', c.cancelled);

                const totalEl = document.getElementById('orders-total-count');
                if (totalEl && data.total_formatted) totalEl.textContent = data.total_formatted;

                // Unchecked pill highlight styling
                const unchPill = document.getElementById('pill-unchecked');
                const unchBadge = document.getElementById('badge-count-unchecked');
                if (unchPill && !unchPill.classList.contains('active')) {
                    if (c.unchecked > 0) {
                        unchPill.style.border = '1px solid #fde68a';
                        unchPill.style.background = '#fffbeb';
                        unchPill.style.color = '#b45309';
                        if (unchBadge) {
                            unchBadge.style.background = '#d97706';
                            unchBadge.style.color = '#fff';
                        }
                    } else {
                        unchPill.style.border = '';
                        unchPill.style.background = '';
                        unchPill.style.color = '';
                        if (unchBadge) {
                            unchBadge.style.background = '';
                            unchBadge.style.color = '';
                        }
                    }
                }
            }

            // Helper to style status select element based on selected status
            window.updateStatusSelectStyle = function(selectEl, status) {
                if (!selectEl) return;
                const s = (status || selectEl.value || '').toLowerCase().trim();
                selectEl.classList.remove(
                    'status-pending',
                    'status-processing',
                    'status-shipped',
                    'status-delivered',
                    'status-cancelled',
                    'status-returned'
                );
                if (s) {
                    selectEl.classList.add('status-' + s);
                }
            };

            window.handleOrderStatusChange = function(selectEl, orderId) {
                const form = selectEl.closest('form');
                if (!form) return;

                const newStatus = selectEl.value;

                // 1. Immediately update visual background/colors
                updateStatusSelectStyle(selectEl, newStatus);

                // 2. Prepare FormData explicitly (never rely on disabled form elements)
                const fd = new FormData();
                fd.append('update_status', '1');
                fd.append('order_id', orderId);
                fd.append('status', newStatus);
                fd.append('ajax', '1');

                autoRefreshPaused = true;
                selectEl.disabled = true;
                selectEl.style.opacity = '0.7';

                fetch('index.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        selectEl.style.opacity = '1';
                        updateStatusSelectStyle(selectEl, newStatus);
                        // Blur select so pollOrders activeElement check doesn't block the refresh
                        selectEl.blur();
                        // Trigger immediate refresh after status change
                        pollOrders();
                    } else {
                        alert(res.message || 'Error updating status');
                        pollOrders();
                    }
                })
                .catch(err => {
                    console.error('AJAX status update error, submitting form normally:', err);
                    form.submit();
                })
                .finally(() => {
                    selectEl.disabled = false;
                    selectEl.style.opacity = '1';
                    autoRefreshPaused = false;
                });
            };

            function pollOrders() {
                if (autoRefreshPaused || isPolling) return;

                // Do not overwrite table while user is interacting with an input or select
                const activeEl = document.activeElement;
                if (activeEl && (activeEl.tagName === 'SELECT' || activeEl.tagName === 'INPUT')) {
                    return;
                }

                isPolling = true;
                setLiveState(true);

                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('ajax', '1');

                fetch(currentUrl.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (!data || !data.success) return;

                    // Detect new incoming orders or new unchecked alerts to play notification
                    if (lastKnownMaxOrderId > 0 && data.max_order_id > lastKnownMaxOrderId) {
                        playSound();
                    } else if (data.counts && data.counts.unchecked > lastKnownUncheckedCount) {
                        playSound();
                    }

                    if (data.max_order_id) {
                        lastKnownMaxOrderId = Math.max(lastKnownMaxOrderId, data.max_order_id);
                    }
                    if (data.counts) {
                        lastKnownUncheckedCount = data.counts.unchecked;
                    }

                    // Update badge counts and pill styles
                    updateBadgesAndCounts(data);

                    // Update table body
                    const tbody = document.getElementById('orders-table-body');
                    if (tbody && data.tbody_html !== undefined) {
                        tbody.innerHTML = data.tbody_html;
                        // Ensure all rendered status selects have matching background colors
                        tbody.querySelectorAll('.status-select-input').forEach(function(sel) {
                            updateStatusSelectStyle(sel, sel.value);
                        });
                    }

                    // Update pagination
                    const pagWrap = document.getElementById('orders-pagination-wrapper');
                    if (pagWrap && data.pagination_html !== undefined) {
                        pagWrap.innerHTML = data.pagination_html;
                    }
                })
                .catch(err => {
                    console.debug('Orders auto-refresh poll error:', err);
                })
                .finally(() => {
                    isPolling = false;
                    setLiveState(false);
                });
            }

            // Initial style pass on load
            document.querySelectorAll('.status-select-input').forEach(function(sel) {
                updateStatusSelectStyle(sel, sel.value);
            });

            // Schedule 3-second auto-refresh
            setInterval(pollOrders, REFRESH_INTERVAL);
        })();
    </script>
</body>
</html>