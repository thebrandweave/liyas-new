<?php
require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php'; // Include the new functions file

// Read filter/search/page from request
$filter = $_GET['filter'] ?? $_POST['filter'] ?? 'all';
$search = $_GET['search'] ?? $_POST['search'] ?? '';
$page   = isset($_GET['page']) ? (int)$_GET['page'] : (isset($_POST['page']) ? (int)$_POST['page'] : 1);
$per_page = 50;
$offset   = ($page - 1) * $per_page;

// Build where + params once and reuse everywhere
$params = [];
$where_clause = buildOrderFilterWhereClause($filter, $search, $params); // Use the function from includes/functions.php

// CSV EXPORT
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
	try {
		$query = "SELECT 
					o.order_id, 
					u.name AS customer_name, 
					u.email AS customer_email, 
					sa.phone_number AS customer_phone,
					CONCAT(sa.address_line_1, ', ', sa.city, ', ', sa.state, ' - ', sa.zip_code) AS shipping_address_full,
					o.total_amount, 
					o.status, 
					o.created_at, 
					o.updated_at
				  FROM orders o
				  JOIN users u ON o.user_id = u.user_id
				  JOIN shipping_addresses sa ON o.shipping_address_id = sa.address_id
				  $where_clause
				  ORDER BY o.created_at DESC";

		$stmt = $pdo->prepare($query);
		if (!empty($params)) {
			bindFilterParams($stmt, $params); // Use the function from includes/functions.php
		}
		$stmt->execute();
		$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		die("Error fetching orders: " . $e->getMessage());
	}

	if (empty($orders)) {
		die("No orders found to export with the current filters.");
	}

	$filter_name = ucfirst($filter);
	$filename = "Orders_{$filter_name}_" . date('Y-m-d_His') . ".csv";

	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Pragma: no-cache');
	header('Expires: 0');

	$output = fopen('php://output', 'w');
	fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

	$headers = [
		'Order ID',
		'Customer Name',
		'Email',
		'Phone',
		'Shipping Address',
		'Total Amount',
		'Status',
		'Created At',
		'Updated At'
	];
	fputcsv($output, $headers);

	foreach ($orders as $order) {
		$row = [
			$order['order_id'],
			$order['customer_name'],
			$order['customer_email'] ?? '',
			$order['customer_phone'] ?? '',
			$order['shipping_address_full'],
			$order['total_amount'],
			ucfirst($order['status']),
			formatIST($order['created_at']), // Use the function from includes/functions.php
			formatIST($order['updated_at'])  // Use the function from includes/functions.php
		];
		fputcsv($output, $row);
	}

	fclose($output);
	exit;
}

// Handle status update
$update_message = '';
$update_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
	$order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
	$new_status = isset($_POST['status']) ? $_POST['status'] : '';

	if ($order_id > 0 && in_array($new_status, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
		try {
			$updateStmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
			$updateStmt->execute([$new_status, $order_id]);
			$updated_count = $updateStmt->rowCount();

			if ($updated_count > 0) {
				header("Location: " . buildOrderRedirectUrl($filter, $search, $page, $order_id)); // Use the function from includes/functions.php
				exit;
			} else {
				$update_message = "Order not found or already has this status.";
				$update_type = 'error';
			}
		} catch (PDOException $e) {
			$update_message = "Error updating order status: " . $e->getMessage();
			$update_type = 'error';
			error_log("Status update error: " . $e->getMessage());
		}
	} else {
		$update_message = 'Invalid order ID or status.';
		$update_type = 'error';
	}
}

// Message from redirect
if (isset($_GET['updated'])) {
	$update_message = "Order status updated successfully!";
	$update_type = 'success';
}

// Stats (global)
try {
	$stats_query = "SELECT 
		COUNT(*) as total_orders,
		SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
		SUM(CASE WHEN o.status = 'processing' THEN 1 ELSE 0 END) as processing_orders,
		SUM(CASE WHEN o.status = 'shipped' THEN 1 ELSE 0 END) as shipped_orders,
		SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
		SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
		SUM(o.total_amount) as total_revenue
	FROM orders o"; // Added alias 'o' for consistency with joined queries
	$stats_result = $pdo->query($stats_query)->fetch(PDO::FETCH_ASSOC);
	$total_orders = (int)($stats_result['total_orders'] ?? 0);
	$pending_orders = (int)($stats_result['pending_orders'] ?? 0);
	$processing_orders = (int)($stats_result['processing_orders'] ?? 0);
	$shipped_orders = (int)($stats_result['shipped_orders'] ?? 0);
	$delivered_orders = (int)($stats_result['delivered_orders'] ?? 0);
	$cancelled_orders = (int)($stats_result['cancelled_orders'] ?? 0);
	$total_revenue = (float)($stats_result['total_revenue'] ?? 0);
} catch (PDOException $e) {
	$total_orders = $pending_orders = $processing_orders = $shipped_orders = $delivered_orders = $cancelled_orders = 0;
	$total_revenue = 0;
	error_log("Statistics query error: " . $e->getMessage());
}

// Count filtered records (for pagination)
try {
	$count_query = "SELECT COUNT(o.order_id) FROM orders o
					JOIN users u ON o.user_id = u.user_id
					JOIN shipping_addresses sa ON o.shipping_address_id = sa.address_id
					$where_clause";
	$count_stmt = $pdo->prepare($count_query);
	if (!empty($params)) {
		bindFilterParams($count_stmt, $params); // Use the function from includes/functions.php
	}
	$count_stmt->execute();
	$total_records = (int)$count_stmt->fetchColumn();
	$total_pages = $per_page > 0 ? ceil($total_records / $per_page) : 1;
} catch (PDOException $e) {
	$total_records = 0;
	$total_pages = 0;
	error_log("Order count error: " . $e->getMessage());
}

// Fetch paginated orders
try {
	$query = "SELECT 
					o.order_id, 
					u.name AS customer_name, 
					u.email AS customer_email, 
					sa.phone_number AS customer_phone,
					CONCAT(sa.address_line_1, ', ', sa.city, ', ', sa.state, ' - ', sa.zip_code) AS shipping_address_full,
					o.total_amount, 
					o.status, 
					o.created_at, 
					o.updated_at
			  FROM orders o
			  JOIN users u ON o.user_id = u.user_id
			  JOIN shipping_addresses sa ON o.shipping_address_id = sa.address_id
			  $where_clause
			  ORDER BY o.created_at DESC
			  LIMIT :limit OFFSET :offset";
	$stmt = $pdo->prepare($query);
	if (!empty($params)) {
		bindFilterParams($stmt, $params); // Use the function from includes/functions.php
	}
	$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
	$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
	$stmt->execute();
	$orders = $stmt->fetchAll();
} catch (PDOException $e) {
	$orders = [];
	error_log("Orders fetch error: " . $e->getMessage());
}

// Basic setup
$admin_name   = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "orders";
$page_title   = "Orders";
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	
	<!-- Favicon -->
	<link rel="icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="shortcut icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="apple-touch-icon" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="icon" type="image/jpeg" sizes="32x32" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="icon" type="image/jpeg" sizes="16x16" href="../../assets/images/logo/logo-bg.jpg">
	
	<!-- Google Font: Poppins -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
	<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
	<link rel="stylesheet" href="../assets/css/prody-admin.css">
	<title>Orders - Liyas Admin</title>
	<style>
		.modal-overlay {
			display: none;
			position: fixed;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			background: rgba(0, 0, 0, 0.45);
			backdrop-filter: blur(5px);
			-webkit-backdrop-filter: blur(5px);
			z-index: 10000;
			align-items: center;
			justify-content: center;
			animation: fadeIn 0.2s ease-out;
		}

		.badge{
    padding:8px 14px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
    letter-spacing:.3px;
}

.badge-pending{
    background:#fff7e6;
    color:#d97706;
}

.badge-processing{
    background:#eff6ff;
    color:#2563eb;
}

.badge-shipped{
    background:#eef2ff;
    color:#4f46e5;
}

.badge-delivered{
    background:#ecfdf5;
    color:#059669;
}

.badge-cancelled{
    background:#fef2f2;
    color:#dc2626;
}
		.modal-overlay.active { display: flex; }
		@keyframes fadeIn {
			from { opacity: 0; }
			to   { opacity: 1; }
		}
		.modal-dialog {
			background: white;
			border-radius: 16px;
			box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
			max-width: 480px;
			width: 90%;
			max-height: 90vh;
			overflow-y: auto;
			animation: slideUp 0.25s ease-out;
			position: relative;
			z-index: 10001;
		}
		@keyframes slideUp {
			from { opacity: 0; transform: translateY(30px) scale(0.95); }
			to   { opacity: 1; transform: translateY(0) scale(1); }
		}
		.modal-header {
			padding: 1.25rem 1.5rem;
			border-bottom: 1px solid var(--grey);
			display: flex;
			align-items: center;
			justify-content: space-between;
		}
		.modal-header h3 {
			font-size: 1.1rem;
			font-weight: 600;
			color: var(--dark);
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}
		.modal-header .close-btn {
			background: none;
			border: none;
			font-size: 1.5rem;
			color: var(--dark-grey);
			cursor: pointer;
			width: 32px;
			height: 32px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 8px;
			transition: all 0.15s;
		}
		.modal-header .close-btn:hover {
			background: var(--grey);
			color: var(--dark);
		}
		.modal-body {
			padding: 1.25rem 1.5rem 0.75rem;
			text-align: center;
		}
		.modal-body p {
			color: var(--dark);
			font-size: 0.95rem;
			line-height: 1.5;
			margin-bottom: 0.75rem;
		}
		.modal-footer {
			padding: 0.9rem 1.5rem 1.25rem;
			border-top: 1px solid var(--grey);
			display: flex;
			gap: 0.75rem;
			justify-content: flex-end;
		}
		.modal-btn {
			padding: 0.6rem 1.3rem;
			border: none;
			border-radius: 999px;
			font-size: 0.9rem;
			font-weight: 600;
			cursor: pointer;
			transition: all 0.15s;
			font-family: var(--opensans);
			display: inline-flex;
			align-items: center;
			gap: 0.45rem;
		}
		.modal-btn-cancel {
			background: var(--grey);
			color: var(--dark);
		}
		.modal-btn-cancel:hover {
			background: var(--dark-grey);
			color: #fff;
		}
		.modal-btn-primary {
			background: var(--blue);
			color: white;
		}
		.modal-btn-primary:hover {
			background: #2563eb;
			transform: translateY(-1px);
			box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
		}
		body.modal-active {
			overflow: hidden;
		}
		body.modal-active #content {
			filter: blur(2px);
			transition: filter 0.2s;
		}
		.alert {
			margin: 1rem 0;
			padding: 0.75rem 1rem;
			border-radius: 8px;
			font-size: 0.95rem;
			display: flex;
			align-items: center;
			gap: 0.5rem;
		}
		.alert-success {
			background: #dcfce7;
			color: #166534;
			border: 1px solid #bbf7d0;
		}
		.alert-error {
			background: #fee2e2;
			color: #b91c1c;
			border: 1px solid #fecaca;
		}
		.status {
			padding: 0.4rem 0.8rem;
			border-radius: 999px;
			font-size: 0.85rem;
			font-weight: 600;
			display: inline-block;
		}
		.status.pending,
		.badge-pending { background: var(--yellow-light); color: #92400e; }
		.status.processing,
		.badge-processing { background: var(--blue-light); color: var(--blue-dark); }
		.status.shipped,
		.badge-shipped { background: #e0e7ff; color: #3730a3; }
		.status.delivered,
		.badge-completed { background: var(--green-light); color: #065f46; }
		.status.cancelled,
		.badge-cancelled { background: #fee2e2; color: #991b1b; }
		
		.table-responsive-wrapper {
			width: 100%;
			overflow-x: auto;
			overflow-y: visible;
			-webkit-overflow-scrolling: touch;
			position: relative;
		}
		
		.table-responsive-wrapper table {
			min-width: 900px;
			width: 100%;
		}
		
		/* Mobile optimizations */
		@media (max-width: 768px) {
			.table-responsive-wrapper {
				overflow-x: scroll;
				-webkit-overflow-scrolling: touch;
				scrollbar-width: thin;
				scrollbar-color: var(--border-medium) transparent;
			}
			
			.table-responsive-wrapper::-webkit-scrollbar {
				height: 8px;
			}
			
			.table-responsive-wrapper::-webkit-scrollbar-track {
				background: var(--bg-main);
				border-radius: 4px;
			}
			
			.table-responsive-wrapper::-webkit-scrollbar-thumb {
				background: var(--border-medium);
				border-radius: 4px;
			}
			
			.table-responsive-wrapper::-webkit-scrollbar-thumb:hover {
				background: var(--text-secondary);
			}
			
			.table-responsive-wrapper table {
				min-width: 1000px;
			}
			
			.table-responsive-wrapper table th,
			.table-responsive-wrapper table td {
				padding: 0.75rem 1rem;
				font-size: 13px;
			}
			
			.table-responsive-wrapper table th:first-child,
			.table-responsive-wrapper table td:first-child {
				position: sticky;
				left: 0;
				background: var(--bg-white);
				z-index: 10;
				box-shadow: 2px 0 4px rgba(0,0,0,0.05);
			}
			
			.table-responsive-wrapper table th:last-child,
			.table-responsive-wrapper table td:last-child {
				min-width: 180px;
			}
		}
		
		@media (max-width: 480px) {
			.table-responsive-wrapper table {
				min-width: 1100px;
			}
			
			.table-responsive-wrapper table th,
			.table-responsive-wrapper table td {
				padding: 0.5rem 0.75rem;
				font-size: 12px;
			}
		}
	</style>
</head>
<body>
	<div class="container">
		<?php include '../includes/sidebar.php'; ?>
		
		<div class="main-content">
			<div class="header">
				<div class="breadcrumb">
					<i class='bx bx-home'></i>
					<span>Orders</span>
				</div>
				<div class="header-actions">
					<form action="index.php" method="GET" style="display: flex; align-items: center; gap: 0.5rem;">
						<input type="search" name="search" placeholder="Search orders..." value="<?= htmlspecialchars($search) ?>" style="padding: 0.5rem 0.75rem; border: 1px solid var(--border-light); border-radius: 6px; font-size: 14px; font-family: inherit;">
						<button type="submit" class="header-btn" style="padding: 0.5rem;">
							<i class='bx bx-search'></i>
						</button>
					</form>
				</div>
			</div>
			
			<div class="content-area">

				<?php if (!empty($update_message)): ?>
					<div class="alert <?= $update_type === 'success' ? 'alert-success' : 'alert-error' ?>">
						<?= htmlspecialchars($update_message) ?>
					</div>
				<?php endif; ?>

				<div class="table-card" style="margin-bottom: 1.5rem;">
					<div class="table-header">
						<div class="table-title">Filter Orders</div>
						<div class="table-actions">
							<form method="GET" action="" id="filterForm" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
								<input type="hidden" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>">
								<select name="filter" id="filterSelect" class="form-select" style="padding: 0.5rem 0.75rem; border: 1px solid var(--border-light); border-radius: 6px; font-size: 14px; font-family: inherit;">
									<option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Orders</option>
									<option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Pending</option>
									<option value="processing" <?= $filter === 'processing' ? 'selected' : '' ?>>Processing</option>
									<option value="shipped" <?= $filter === 'shipped' ? 'selected' : '' ?>>Shipped</option>
									<option value="delivered" <?= $filter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
									<option value="cancelled" <?= $filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
								</select>
								<button type="submit" class="table-btn">
									<i class='bx bx-filter'></i> Apply
								</button>
								<button type="button" id="exportBtn" class="table-btn" style="background: var(--green); color: white; border-color: var(--green);">
									<i class='bx bx-download'></i> Export
								</button>
								<?php if ($filter !== 'all' || !empty($search)): ?>
									<a href="index.php" class="table-btn">
										<i class='bx bx-x'></i> Clear
									</a>
								<?php endif; ?>
							</form>
						</div>
					</div>
				</div>

				<div class="table-card">
					<div class="table-header">
						<div class="table-title">
							All Orders
							<!-- <i class='bx bx-chevron-down'></i> -->
						</div>
					</div>
					
					<?php if (empty($orders)): ?>
						<div style="padding: 3rem; text-align: center;">
							<i class='bx bx-cart' style="font-size: 4rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
							<p style="color: var(--text-secondary); font-size: 1.1rem;">No orders found</p>
						</div>
					<?php else: ?>
						<div class="table-responsive-wrapper">
							<table>
								<thead>
									<tr>
										<th>Customer</th>
										<th>Contact</th>
										<th>Address</th>
										<th>Amount</th>
										<th>Status</th>
										<th>Created</th>
										<th>Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($orders as $order): ?>
									<tr>
										<td>
											<strong><?= htmlspecialchars($order['customer_name']) ?></strong>
											<?php if ($order['customer_email']): ?>
												<br><span style="color: var(--text-muted); font-size: 12px;"><?= htmlspecialchars($order['customer_email']) ?></span>
											<?php endif; ?>
										</td>
										<td><?= htmlspecialchars($order['customer_phone'] ?? '—') ?></td>
										<td>
<span style="
display:block;
max-width:350px;
white-space:normal;
overflow-wrap:anywhere;
font-size:13px;
color:var(--text-secondary);
">												<?= htmlspecialchars($order['shipping_address_full']) ?>
											</span>
										</td>
										<td><strong><?= formatCurrency($order['total_amount']) ?></strong></td>
										<td>
											<span class="badge badge-<?= htmlspecialchars($order['status']) ?>">
												<?= ucfirst($order['status']) ?>
											</span>
										</td>
										<td>
											<span style="font-size: 13px;"><?= formatIST($order['created_at']) ?></span>
											<br><small style="color: var(--text-muted);">IST</small>
										</td>
										<td>
											<a href="view.php?id=<?= $order['order_id'] ?>" class="btn-action btn-view">
												<i class='bx bx-show'></i> View
											</a>
											<a href="javascript:void(0);" onclick="openStatusModal(<?= $order['order_id'] ?>, '<?= htmlspecialchars($order['status'], ENT_QUOTES) ?>')" class="btn-action btn-edit">
												<i class='bx bx-edit'></i> Status
											</a>
										</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

						<?php if ($total_pages > 1): ?>
							<div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-light); display: flex; justify-content: center; gap: 0.75rem; align-items: center;">
								<?php if ($page > 1): ?>
									<a href="?page=<?= $page - 1 ?>&filter=<?= $filter ?>&search=<?= urlencode($search) ?>" class="table-btn">
										<i class='bx bx-chevron-left'></i> Previous
									</a>
								<?php endif; ?>
								
								<span style="padding: 0.5rem 1rem; color: var(--text-secondary);">
									Page <?= $page ?> of <?= $total_pages ?>
								</span>
								
								<?php if ($page < $total_pages): ?>
									<a href="?page=<?= $page + 1 ?>&filter=<?= $filter ?>&search=<?= urlencode($search) ?>" class="table-btn">
										Next <i class='bx bx-chevron-right'></i>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Status Update Modal -->
	<div id="statusModal" class="modal-overlay">
		<div class="modal-dialog">
			<div class="modal-header">
				<h3>
					<i class='bx bx-edit' style="color: var(--blue);"></i>
					Update Order Status
				</h3>
				<button type="button" class="close-btn" onclick="closeStatusModal()">
					<i class='bx bx-x'></i>
				</button>
			</div>
			<div class="modal-body">
				<form method="POST" action="" id="statusForm">
					<input type="hidden" name="order_id" id="statusOrderId">
					<input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
					<input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
					<input type="hidden" name="page" value="<?= $page ?>">
					
					<label for="statusSelect" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: var(--dark);">Select Status:</label>
					<select name="status" id="statusSelect" style="width: 100%; padding: 0.75rem; border: 1px solid var(--grey); border-radius: 8px; font-family: var(--opensans); font-size: 0.95rem; margin-bottom: 1rem;">
						<option value="pending">Pending</option>
						<option value="processing">Processing</option>
						<option value="shipped">Shipped</option>
						<option value="delivered">Delivered</option>
						<option value="cancelled">Cancelled</option>
					</select>
					
					<p style="font-size: 0.85rem; color: #6b7280; margin-top: 0.5rem;">
						This will update the order status immediately.
					</p>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="modal-btn modal-btn-cancel" onclick="closeStatusModal()">
					<i class='bx bx-x'></i> Cancel
				</button>
				<button type="button" class="modal-btn modal-btn-primary" onclick="submitStatusForm()">
					<i class='bx bx-check'></i> Update Status
				</button>
			</div>
		</div>
	</div>
	
	<script>
		document.getElementById('exportBtn').addEventListener('click', function(e) {
			e.preventDefault();
			const filterSelect = document.getElementById('filterSelect');
			const searchInput = document.getElementById('searchInput');
			let filter = filterSelect ? filterSelect.value : 'all';
			let search = searchInput ? searchInput.value : '';
			let exportUrl = '?export=csv&filter=' + encodeURIComponent(filter);
			if (search) {
				exportUrl += '&search=' + encodeURIComponent(search);
			}
			window.location.href = exportUrl;
		});

		function openStatusModal(orderId, currentStatus) {
			const modal = document.getElementById('statusModal');
			const orderIdInput = document.getElementById('statusOrderId');
			const statusSelect = document.getElementById('statusSelect');
			
			if (orderIdInput) orderIdInput.value = orderId;
			if (statusSelect) statusSelect.value = currentStatus;
			if (modal) {
				modal.classList.add('active');
				document.body.classList.add('modal-active');
			}
		}

		function closeStatusModal() {
			const modal = document.getElementById('statusModal');
			if (modal) {
				modal.classList.remove('active');
				document.body.classList.remove('modal-active');
			}
		}

		function submitStatusForm() {
			const form = document.getElementById('statusForm');
			if (form) {
				const statusInput = document.createElement('input');
				statusInput.type = 'hidden';
				statusInput.name = 'update_status';
				statusInput.value = '1';
				form.appendChild(statusInput);
				form.submit();
			}
		}

		const statusModalOverlay = document.getElementById('statusModal');
		if (statusModalOverlay) {
			statusModalOverlay.addEventListener('click', function(e) {
				if (e.target === statusModalOverlay) {
					closeStatusModal();
				}
			});
		}

		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				const statusModal = document.getElementById('statusModal');
				if (statusModal && statusModal.classList.contains('active')) {
					closeStatusModal();
				}
			}
		});
	</script>
</body>
</html>