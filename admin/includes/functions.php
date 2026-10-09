<?php

// Set timezone for consistent date/time handling across the admin panel
date_default_timezone_set('Asia/Kolkata');

/**
 * Convert database timestamp to IST format
 */
function formatIST($dbTimestamp) {
	if (empty($dbTimestamp)) return '';
	try {
		$dt = new DateTime($dbTimestamp);
		$dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
		return $dt->format('d-m-Y H:i:s');
	} catch (Exception $e) {
		return date('d-m-Y H:i:s', strtotime($dbTimestamp));
	}
}

/**
 * Format currency
 */
function formatCurrency($amount) {
	return '₹' . number_format((float)$amount, 2);
}

/**
 * Map order status to badge class
 */
function getStatusBadgeClass($status) {
	$status = strtolower($status ?? '');
	$badgeMap = [
		'pending' => 'badge-pending',
		'processing' => 'badge-processing',
		'shipped' => 'badge-processing',
		'delivered' => 'badge-completed',
		'cancelled' => 'badge-cancelled'
	];
	return $badgeMap[$status] ?? 'badge-pending';
}

/**
 * Sanitize zone name to a clean URL-safe slug
 * E.g., "Mangalore Zone" -> "mangalore", "North Mangalore" -> "north-mangalore"
 */
function sanitizeZoneSlug($name) {
	$name = trim($name);
	// Optionally strip "zone" suffix if whole word
	$clean = preg_replace('/\bzone\b/i', '', $name);
	$clean = trim($clean);
	if (empty($clean)) {
		$clean = $name;
	}
	$slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $clean), '-'));
	return $slug ?: 'zone-' . time();
}

/**
 * Get setting value from system_settings
 */
function getSystemSetting($pdo, $key, $default = '') {
	try {
		$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
		$stmt->execute([$key]);
		$val = $stmt->fetchColumn();
		return ($val !== false) ? $val : $default;
	} catch (Exception $e) {
		return $default;
	}
}

/**
 * Generate sequential unique bill number: e.g. LI-000001
 */
function generateBillNumber($pdo) {
	try {
		$stmt = $pdo->query("SELECT MAX(id) FROM receipts");
		$maxId = (int)$stmt->fetchColumn();
		$nextId = $maxId + 1;
		$billNum = '' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

		// Ensure uniqueness
		$chk = $pdo->prepare("SELECT id FROM receipts WHERE bill_number = ?");
		$chk->execute([$billNum]);
		while ($chk->fetch()) {
			$nextId++;
			$billNum = 'LI-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
			$chk->execute([$billNum]);
		}
		return $billNum;
	} catch (Exception $e) {
		return 'LI-' . date('Ymd') . '-' . rand(1000, 9999);
	}
}

/**
 * Update shop reward progress when an order is completed
 * Threshold defaults to 10 completed orders
 */
function updateShopRewardProgress($pdo, $shop_name, $phone = '') {
	$shop_name = trim($shop_name ?? '');
	if (empty($shop_name)) return;

	try {
		$threshold = (int)getSystemSetting($pdo, 'reward_threshold', 10);
		if ($threshold <= 0) $threshold = 10;

		// Count delivered orders for this shop
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE shop_name = ? AND status = 'delivered'");
		$stmt->execute([$shop_name]);
		$completed = (int)$stmt->fetchColumn();

		$reward_status = ($completed >= $threshold) ? 'eligible' : 'in_progress';

		$chk = $pdo->prepare("SELECT id, reward_status FROM rewards WHERE shop_name = ?");
		$chk->execute([$shop_name]);
		$row = $chk->fetch(PDO::FETCH_ASSOC);

		if ($row) {
			$newStatus = ($row['reward_status'] === 'claimed') ? 'claimed' : $reward_status;
			$up = $pdo->prepare("UPDATE rewards SET completed_orders = ?, reward_threshold = ?, reward_status = ?, customer_phone = COALESCE(NULLIF(?, ''), customer_phone), updated_at = NOW() WHERE id = ?");
			$up->execute([$completed, $threshold, $newStatus, $phone, $row['id']]);
		} else {
			$ins = $pdo->prepare("INSERT INTO rewards (shop_name, customer_phone, completed_orders, reward_threshold, reward_status) VALUES (?, ?, ?, ?, ?)");
			$ins->execute([$shop_name, $phone, $completed, $threshold, $reward_status]);
		}
	} catch (Exception $e) {
		error_log("Error updating reward: " . $e->getMessage());
	}
}

/**
 * Get shop reward information
 */
function getShopRewardInfo($pdo, $shop_name) {
	$shop_name = trim($shop_name ?? '');
	if (empty($shop_name)) return null;

	$threshold = (int)getSystemSetting($pdo, 'reward_threshold', 10);
	if ($threshold <= 0) $threshold = 10;

	try {
		$stmt = $pdo->prepare("SELECT * FROM rewards WHERE shop_name = ?");
		$stmt->execute([$shop_name]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE shop_name = ? AND status = 'delivered'");
			$cntStmt->execute([$shop_name]);
			$completed = (int)$cntStmt->fetchColumn();
			return [
				'shop_name' => $shop_name,
				'completed_orders' => $completed,
				'reward_threshold' => $threshold,
				'reward_status' => ($completed >= $threshold) ? 'eligible' : 'in_progress',
				'remaining_orders' => max(0, $threshold - $completed)
			];
		}

		$row['remaining_orders'] = max(0, (int)$row['reward_threshold'] - (int)$row['completed_orders']);
		return $row;
	} catch (Exception $e) {
		return null;
	}
}

/**
 * Build WHERE clause + params for orders based on status filter, zone filter, and search
 */
function buildOrderFilterWhereClause($filter, $search, &$params, $zone_filter = 'all') {
	$where_conditions = [];
	$params = [];

	if ($filter !== 'all' && in_array($filter, ['pending', 'processing', 'shipped', 'delivered', 'cancelled'])) {
		$where_conditions[] = "o.status = :status";
		$params[':status'] = $filter;
	}

	if ($zone_filter !== 'all' && !empty($zone_filter)) {
		if (is_numeric($zone_filter)) {
			$where_conditions[] = "o.zone_id = :zone_id";
			$params[':zone_id'] = (int)$zone_filter;
		} else {
			$where_conditions[] = "z.slug = :zone_slug";
			$params[':zone_slug'] = $zone_filter;
		}
	}

	if (!empty($search)) {
		$where_conditions[] = "(
			o.shop_name LIKE :search 
			OR o.customer_name LIKE :search 
			OR o.phone LIKE :search 
			OR o.location LIKE :search 
			OR o.order_number LIKE :search 
			OR o.order_id = :order_id_search
		)";
		$params[':search'] = "%$search%";
		$params[':order_id_search'] = is_numeric($search) ? (int)$search : -1;
	}

	return !empty($where_conditions)
		? "WHERE " . implode(" AND ", $where_conditions)
		: "";
}

/**
 * Bind params to prepared statement
 */
function bindFilterParams(PDOStatement $stmt, array $params) {
	foreach ($params as $key => $value) {
		if ($key === ':order_id_search' && is_int($value)) {
			$stmt->bindValue($key, $value, PDO::PARAM_INT);
		} elseif ($key === ':zone_id' && is_int($value)) {
			$stmt->bindValue($key, $value, PDO::PARAM_INT);
		} else {
			$stmt->bindValue($key, $value);
		}
	}
}

/**
 * Build redirect URL preserving filter/zone/search/page
 */
function buildOrderRedirectUrl($filter, $search, $page = 1, $updated = null, $zone_filter = 'all') {
	$redirect_url = "index.php?filter=" . urlencode($filter);
	if (!empty($zone_filter) && $zone_filter !== 'all') {
		$redirect_url .= "&zone=" . urlencode($zone_filter);
	}
	if (!empty($search)) {
		$redirect_url .= "&search=" . urlencode($search);
	}
	if ($page > 1) {
		$redirect_url .= "&page=" . (int)$page;
	}
	if ($updated !== null) {
		$redirect_url .= "&updated=" . (int)$updated;
	}
	return $redirect_url;
}