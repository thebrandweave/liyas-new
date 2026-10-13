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
 * Update shop reward progress when an order is completed, cancelled, or updated
 * Threshold defaults to 10 completed orders
 */
function updateShopRewardProgress($pdo, $shop_name, $phone = '') {
	$shop_name = trim($shop_name ?? '');
	if (empty($shop_name)) return;

	try {
		$threshold = (int)getSystemSetting($pdo, 'reward_threshold', 10);
		if ($threshold <= 0) $threshold = 10;

		// Count delivered orders for this shop (matching shop_name or customer_name fallback)
		$stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE (TRIM(shop_name) = ? OR (COALESCE(TRIM(shop_name), '') = '' AND TRIM(customer_name) = ?)) AND status = 'delivered'");
		$stmt->execute([$shop_name, $shop_name]);
		$completed = (int)$stmt->fetchColumn();

		$reward_status = ($completed >= $threshold) ? 'eligible' : 'in_progress';

		$chk = $pdo->prepare("SELECT id, reward_status FROM rewards WHERE TRIM(shop_name) = ?");
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
 * Get shop reward information (with auto-reconciliation to live delivered count)
 */
function getShopRewardInfo($pdo, $shop_name) {
	$shop_name = trim($shop_name ?? '');
	if (empty($shop_name)) return null;

	$threshold = (int)getSystemSetting($pdo, 'reward_threshold', 10);
	if ($threshold <= 0) $threshold = 10;

	try {
		// Live count actual delivered orders
		$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE (TRIM(shop_name) = ? OR (COALESCE(TRIM(shop_name), '') = '' AND TRIM(customer_name) = ?)) AND status = 'delivered'");
		$cntStmt->execute([$shop_name, $shop_name]);
		$completed = (int)$cntStmt->fetchColumn();

		$stmt = $pdo->prepare("SELECT * FROM rewards WHERE TRIM(shop_name) = ?");
		$stmt->execute([$shop_name]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			return [
				'shop_name' => $shop_name,
				'completed_orders' => $completed,
				'reward_threshold' => $threshold,
				'reward_status' => ($completed >= $threshold) ? 'eligible' : 'in_progress',
				'remaining_orders' => max(0, $threshold - $completed)
			];
		}

		// Reconcile if live count or threshold differs from cached row
		if ((int)$row['completed_orders'] !== $completed || (int)$row['reward_threshold'] !== $threshold) {
			$newStatus = ($row['reward_status'] === 'claimed') ? 'claimed' : (($completed >= $threshold) ? 'eligible' : 'in_progress');
			$up = $pdo->prepare("UPDATE rewards SET completed_orders = ?, reward_threshold = ?, reward_status = ?, updated_at = NOW() WHERE id = ?");
			$up->execute([$completed, $threshold, $newStatus, $row['id']]);
			$row['completed_orders'] = $completed;
			$row['reward_threshold'] = $threshold;
			$row['reward_status'] = $newStatus;
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

/**
 * Get delivered cases breakdown for a specific shop / customer:
 * Returns:
 * - previous_cases: delivered cases from previous/initial orders (is_reorder = 0)
 * - reorder_cases: delivered cases from reorders (is_reorder = 1)
 * - total_cases: total delivered cases (previous + reorder)
 * - previous_orders_count
 * - reorders_count
 * - total_delivered_orders
 * - total_all_orders
 * - total_amount
 * - total_due
 * - last_order_date
 */
function getShopDeliveredCasesSummary(PDO $pdo, string $shop_name): array {
	$shop_name = trim($shop_name);
	if ($shop_name === '') {
		return [
			'shop_name'              => '',
			'previous_cases'         => 0,
			'reorder_cases'          => 0,
			'total_cases'            => 0,
			'previous_orders_count'  => 0,
			'reorders_count'         => 0,
			'total_delivered_orders' => 0,
			'total_all_orders'       => 0,
			'total_amount'           => 0.0,
			'total_due'              => 0.0,
			'last_order_date'        => null
		];
	}

	try {
		$stmt = $pdo->prepare("
			SELECT 
				COUNT(*) as total_delivered_orders,
				SUM(CASE WHEN COALESCE(o.is_reorder, 0) = 0 THEN 1 ELSE 0 END) as previous_orders_count,
				SUM(CASE WHEN COALESCE(o.is_reorder, 0) = 1 THEN 1 ELSE 0 END) as reorders_count,
				COALESCE(SUM(CASE WHEN COALESCE(o.is_reorder, 0) = 0 THEN COALESCE(r.delivered_quantity, o.quantity) ELSE 0 END), 0) as previous_cases,
				COALESCE(SUM(CASE WHEN COALESCE(o.is_reorder, 0) = 1 THEN COALESCE(r.delivered_quantity, o.quantity) ELSE 0 END), 0) as reorder_cases,
				COALESCE(SUM(COALESCE(r.delivered_quantity, o.quantity)), 0) as total_cases,
				COALESCE(SUM(o.total_amount), 0) as total_amount,
				COALESCE(SUM(r.due_amount), 0) as total_due,
				MAX(o.created_at) as last_order_date
			FROM orders o
			LEFT JOIN receipts r ON o.order_id = r.order_id
			WHERE (TRIM(o.shop_name) = ? OR (COALESCE(TRIM(o.shop_name), '') = '' AND TRIM(o.customer_name) = ?))
			  AND o.status = 'delivered'
		");
		$stmt->execute([$shop_name, $shop_name]);
		$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

		// Count all orders regardless of status
		$allStmt = $pdo->prepare("
			SELECT COUNT(*) 
			FROM orders 
			WHERE TRIM(shop_name) = ? OR (COALESCE(TRIM(shop_name), '') = '' AND TRIM(customer_name) = ?)
		");
		$allStmt->execute([$shop_name, $shop_name]);
		$allCount = (int)$allStmt->fetchColumn();

		return [
			'shop_name'              => $shop_name,
			'previous_cases'         => (int)($data['previous_cases'] ?? 0),
			'reorder_cases'          => (int)($data['reorder_cases'] ?? 0),
			'total_cases'            => (int)($data['total_cases'] ?? 0),
			'previous_orders_count'  => (int)($data['previous_orders_count'] ?? 0),
			'reorders_count'         => (int)($data['reorders_count'] ?? 0),
			'total_delivered_orders' => (int)($data['total_delivered_orders'] ?? 0),
			'total_all_orders'       => $allCount,
			'total_amount'           => (float)($data['total_amount'] ?? 0.0),
			'total_due'              => (float)($data['total_due'] ?? 0.0),
			'last_order_date'        => $data['last_order_date'] ?? null
		];
	} catch (Exception $e) {
		error_log("Error in getShopDeliveredCasesSummary: " . $e->getMessage());
		return [
			'shop_name'              => $shop_name,
			'previous_cases'         => 0,
			'reorder_cases'          => 0,
			'total_cases'            => 0,
			'previous_orders_count'  => 0,
			'reorders_count'         => 0,
			'total_delivered_orders' => 0,
			'total_all_orders'       => 0,
			'total_amount'           => 0.0,
			'total_due'              => 0.0,
			'last_order_date'        => null
		];
	}
}

/**
 * Fetch distinct list of active shops from orders
 */
function getAllShopsList(PDO $pdo): array {
	try {
		$stmt = $pdo->query("
			SELECT 
				DISTINCT TRIM(COALESCE(NULLIF(shop_name, ''), customer_name)) as shop_name,
				MAX(phone) as phone,
				COUNT(*) as order_count
			FROM orders 
			WHERE (shop_name IS NOT NULL AND TRIM(shop_name) != '') 
			   OR (customer_name IS NOT NULL AND TRIM(customer_name) != '')
			GROUP BY TRIM(COALESCE(NULLIF(shop_name, ''), customer_name))
			ORDER BY shop_name ASC
		");
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (Exception $e) {
		return [];
	}
}

/**
 * Fetch all vehicles with loaded cases breakdown per zone and grand total
 * @param PDO $pdo
 * @param mixed $filterZone Zone ID (int) or Zone slug (string) or 'all'
 * @return array
 */
function getAllVehiclesWithLoads(PDO $pdo, $filterZone = null): array {
	try {
		// Resolve filter zone ID if slug passed
		$filterZoneId = null;
		if (!empty($filterZone) && $filterZone !== 'all') {
			if (is_numeric($filterZone)) {
				$filterZoneId = (int)$filterZone;
			} else {
				$zStmt = $pdo->prepare("SELECT id FROM zones WHERE slug = ? LIMIT 1");
				$zStmt->execute([$filterZone]);
				$filterZoneId = (int)$zStmt->fetchColumn() ?: null;
			}
		}

		// Fetch all zones map
		$zonesStmt = $pdo->query("SELECT id, name, slug FROM zones ORDER BY name ASC");
		$zonesList = $zonesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$zonesMap = [];
		foreach ($zonesList as $z) {
			$zonesMap[(int)$z['id']] = $z;
		}

		// Fetch vehicles
		$vehStmt = $pdo->query("
			SELECT 
				v.*,
				z.name AS home_zone_name,
				z.slug AS home_zone_slug
			FROM vehicles v
			LEFT JOIN zones z ON v.zone_id = z.id
			ORDER BY v.status = 'dispatched' ASC, v.id DESC
		");
		$vehicles = $vehStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		// Fetch active load entries grouped by vehicle and zone (standalone storage & manual loads)
		$loadsStmt = $pdo->query("
			SELECT 
				vl.vehicle_id,
				vl.zone_id,
				SUM(vl.cases_loaded) AS total_cases
			FROM vehicle_loads vl
			WHERE vl.is_active = 1 
			  AND (vl.order_id IS NULL OR vl.order_id NOT IN (SELECT order_id FROM orders WHERE status NOT IN ('delivered', 'cancelled')))
			GROUP BY vl.vehicle_id, vl.zone_id
		");
		$rawLoads = $loadsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$loadsByVehicle = [];
		foreach ($rawLoads as $rl) {
			$vId = (int)$rl['vehicle_id'];
			$zId = (int)$rl['zone_id'];
			$cases = (int)$rl['total_cases'];
			if (!isset($loadsByVehicle[$vId])) {
				$loadsByVehicle[$vId] = [];
			}
			$loadsByVehicle[$vId][$zId] = $cases;
		}

		// Also incorporate active assigned orders into zone loads
		$activeOrdersStmt = $pdo->query("
			SELECT 
				o.vehicle_id,
				o.zone_id,
				SUM(COALESCE(
					(SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id),
					o.quantity,
					1
				)) AS total_cases
			FROM orders o
			WHERE o.vehicle_id IS NOT NULL 
			  AND o.status NOT IN ('delivered', 'cancelled')
			GROUP BY o.vehicle_id, o.zone_id
		");
		$rawOrderLoads = $activeOrdersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		foreach ($rawOrderLoads as $rol) {
			$vId = (int)$rol['vehicle_id'];
			$zId = (int)$rol['zone_id'];
			$cases = (int)$rol['total_cases'];
			if (!isset($loadsByVehicle[$vId])) {
				$loadsByVehicle[$vId] = [];
			}
			$loadsByVehicle[$vId][$zId] = ($loadsByVehicle[$vId][$zId] ?? 0) + $cases;
		}

		// Count delivered orders and calculate total cases delivered per vehicle from authoritative orders & order_items
		$delivStatsStmt = $pdo->query("
			SELECT 
				o.vehicle_id,
				COUNT(DISTINCT o.order_id) AS delivered_count,
				COALESCE(SUM(
					COALESCE(
						(SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id),
						o.quantity,
						1
					)
				), 0) AS delivered_cases
			FROM orders o
			WHERE o.vehicle_id IS NOT NULL AND o.status = 'delivered'
			GROUP BY o.vehicle_id
		");
		$delivStatsRows = $delivStatsStmt ? ($delivStatsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
		$delivOrdersMap = [];
		$delivCasesMap  = [];
		foreach ($delivStatsRows as $dsr) {
			$vid = (int)$dsr['vehicle_id'];
			$delivOrdersMap[$vid] = (int)$dsr['delivered_count'];
			$delivCasesMap[$vid]  = (int)$dsr['delivered_cases'];
		}

		// Count assigned active orders per vehicle
		$ordersCountStmt = $pdo->query("
			SELECT vehicle_id, COUNT(*) as assigned_count 
			FROM orders 
			WHERE vehicle_id IS NOT NULL AND status != 'cancelled'
			GROUP BY vehicle_id
		");
		$ordersCountMap = $ordersCountStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

		$currentZoneTotalCases = 0;
		$grandTotalCases = 0;
		$activeVehiclesCount = 0;

		foreach ($vehicles as &$v) {
			$vId = (int)$v['id'];
			$vLoads = $loadsByVehicle[$vId] ?? [];

			$totalLoaded = 0;
			$zoneBreakdown = [];

			foreach ($vLoads as $zId => $cases) {
				$totalLoaded += $cases;
				$zName = $zonesMap[$zId]['name'] ?? ('Zone #' . $zId);
				$zSlug = $zonesMap[$zId]['slug'] ?? '';
				$zoneBreakdown[] = [
					'zone_id'   => $zId,
					'zone_name' => $zName,
					'zone_slug' => $zSlug,
					'cases'     => $cases
				];
			}

			// Detailed loaded products and storage breakdown
			$vLoadedProducts = array_values(getVehicleLoadedProductsWithCounts($pdo, $vId));
			$vStorageCases = 0;
			$vStorageItems = [];
			$vOrderItems = [];

			foreach ($vLoadedProducts as $lp) {
				if (!empty($lp['storage_cases'])) {
					$vStorageCases += (int)$lp['storage_cases'];
					$vStorageItems[] = [
						'product_name' => $lp['product_name'],
						'cases' => (int)$lp['storage_cases']
					];
				}
				if (!empty($lp['order_cases'])) {
					$vOrderItems[] = [
						'product_name' => $lp['product_name'],
						'cases' => (int)$lp['order_cases']
					];
				}
			}

			$actualProductCases = array_sum(array_column($vLoadedProducts, 'loaded_cases'));
			if ($actualProductCases > $totalLoaded) {
				$totalLoaded = $actualProductCases;
			}

			// Cases loaded from the selected filter zone
			$currentZoneCases = 0;
			if ($filterZoneId !== null) {
				$currentZoneCases = (int)($vLoads[$filterZoneId] ?? 0);
				$currentZoneTotalCases += $currentZoneCases;
			} else {
				$currentZoneCases = $totalLoaded;
				$currentZoneTotalCases += $totalLoaded;
			}

			$grandTotalCases += $totalLoaded;
			if (in_array($v['status'], ['active', 'loading'])) {
				$activeVehiclesCount++;
			}

			// Live delivery statistics from completed delivered orders (with fallback to vehicle lifetime counters)
			$deliveriesDone = isset($delivOrdersMap[$vId]) ? $delivOrdersMap[$vId] : (int)($v['total_deliveries_done'] ?? 0);
			$loadsDelivered = isset($delivCasesMap[$vId]) ? $delivCasesMap[$vId] : (int)($v['total_loads_delivered'] ?? 0);

			$v['total_cases_loaded']        = $totalLoaded;
			$v['current_zone_cases']        = $currentZoneCases;
			$v['zone_breakdown']            = $zoneBreakdown;
			$v['loaded_products']           = $vLoadedProducts;
			$v['storage_cases']             = $vStorageCases;
			$v['storage_items']             = $vStorageItems;
			$v['order_items']               = $vOrderItems;
			$v['load_percent']              = 0;
			$v['assigned_orders_count']     = (int)($ordersCountMap[$vId] ?? 0);
			$v['deliveries_done']           = $deliveriesDone;
			$v['loads_delivered']           = $loadsDelivered;
			$v['loads_taken_to_delivery']   = $loadsDelivered;
		}
		unset($v);

		return [
			'vehicles' => $vehicles,
			'zones'    => $zonesList,
			'summary'  => [
				'total_vehicles'           => count($vehicles),
				'active_vehicles'          => $activeVehiclesCount,
				'current_zone_cases_total' => $currentZoneTotalCases,
				'grand_total_cases'        => $grandTotalCases,
				'filter_zone_id'           => $filterZoneId
			]
		];
	} catch (Exception $e) {
		error_log("Error in getAllVehiclesWithLoads: " . $e->getMessage());
		return [
			'vehicles' => [],
			'zones'    => [],
			'summary'  => [
				'total_vehicles'           => 0,
				'active_vehicles'          => 0,
				'current_zone_cases_total' => 0,
				'grand_total_cases'        => 0,
				'filter_zone_id'           => null
			]
		];
	}
}

/**
 * Fetch all products and case counts currently loaded in a vehicle
 * Combines active vehicle storage loads and assigned active/pending orders
 */
function getVehicleLoadedProductsWithCounts(PDO $pdo, int $vehicleId): array {
	if ($vehicleId <= 0) return [];
	try {
		$result = [];

		// 1. Active Vehicle Storage loads (remaining extra cases & buffer stock in vehicle)
		$stmtStorage = $pdo->prepare("
			SELECT 
				vl.product_id,
				COALESCE(p.product_name, p.name, CONCAT('Product #', vl.product_id)) AS product_name,
				p.case_price,
				p.price,
				p.net_content,
				p.net_content_unit,
				SUM(vl.cases_loaded) AS loaded_cases
			FROM vehicle_loads vl
			LEFT JOIN products p ON vl.product_id = p.product_id
			WHERE vl.vehicle_id = ? 
			  AND vl.is_active = 1 
			  AND vl.product_id IS NOT NULL
			  AND (vl.order_id IS NULL OR vl.order_id NOT IN (SELECT order_id FROM orders WHERE status NOT IN ('delivered', 'cancelled')))
			GROUP BY vl.product_id
		");
		$stmtStorage->execute([$vehicleId]);
		$storageRows = $stmtStorage->fetchAll(PDO::FETCH_ASSOC) ?: [];

		foreach ($storageRows as $r) {
			$pid = (int)($r['product_id'] ?? 0);
			if ($pid > 0) {
				$qty = (int)($r['loaded_cases'] ?? 0);
				$result[$pid] = [
					'product_id' => $pid,
					'product_name' => $r['product_name'] ?: 'Product #' . $pid,
					'case_price' => (float)($r['case_price'] ?: $r['price']),
					'price' => (float)($r['case_price'] ?: $r['price']),
					'net_content' => $r['net_content'],
					'net_content_unit' => $r['net_content_unit'],
					'loaded_cases' => $qty,
					'storage_cases' => $qty,
					'order_cases' => 0,
					'is_storage' => true
				];
			}
		}

		// 2. Active Orders assigned to this vehicle (undelivered)
		$stmtOrders = $pdo->prepare("
			SELECT 
				COALESCE(oi.product_id, o.product_id) AS product_id,
				COALESCE(p.product_name, p.name) AS product_name,
				p.case_price,
				p.price,
				p.net_content,
				p.net_content_unit,
				SUM(COALESCE(oi.quantity, o.quantity, 1)) AS loaded_cases,
				oi.is_extra
			FROM orders o
			LEFT JOIN order_items oi ON o.order_id = oi.order_id
			LEFT JOIN products p ON COALESCE(oi.product_id, o.product_id) = p.product_id
			WHERE o.vehicle_id = ? 
			  AND o.status NOT IN ('delivered', 'cancelled')
			GROUP BY COALESCE(oi.product_id, o.product_id), oi.is_extra
		");
		$stmtOrders->execute([$vehicleId]);
		$orderRows = $stmtOrders->fetchAll(PDO::FETCH_ASSOC) ?: [];

		foreach ($orderRows as $r) {
			$pid = (int)($r['product_id'] ?? 0);
			if ($pid > 0) {
				$qty = (int)($r['loaded_cases'] ?? 0);
				$isExtra = !empty($r['is_extra']);
				if (isset($result[$pid])) {
					$result[$pid]['loaded_cases'] += $qty;
					if ($isExtra) {
						$result[$pid]['storage_cases'] = ($result[$pid]['storage_cases'] ?? 0) + $qty;
					} else {
						$result[$pid]['order_cases'] = ($result[$pid]['order_cases'] ?? 0) + $qty;
					}
				} else {
					$result[$pid] = [
						'product_id' => $pid,
						'product_name' => $r['product_name'] ?: 'Product #' . $pid,
						'case_price' => (float)($r['case_price'] ?: $r['price']),
						'price' => (float)($r['case_price'] ?: $r['price']),
						'net_content' => $r['net_content'],
						'net_content_unit' => $r['net_content_unit'],
						'loaded_cases' => $qty,
						'storage_cases' => $isExtra ? $qty : 0,
						'order_cases' => $isExtra ? 0 : $qty,
						'is_storage' => $isExtra
					];
				}
			}
		}

		// 3. Fallback: check manual vehicle_loads without product_id
		$stmtManual = $pdo->prepare("
			SELECT SUM(cases_loaded) as unassigned_cases
			FROM vehicle_loads
			WHERE vehicle_id = ? AND is_active = 1 AND product_id IS NULL AND order_id IS NULL
		");
		$stmtManual->execute([$vehicleId]);
		$unassignedCases = (int)$stmtManual->fetchColumn();
		if ($unassignedCases > 0) {
			$result[0] = [
				'product_id' => 0,
				'product_name' => 'General Cargo',
				'case_price' => 0,
				'price' => 0,
				'net_content' => null,
				'net_content_unit' => null,
				'loaded_cases' => $unassignedCases,
				'storage_cases' => $unassignedCases,
				'order_cases' => 0,
				'is_storage' => true
			];
		}

		return $result;
	} catch (Exception $e) {
		error_log("Error in getVehicleLoadedProductsWithCounts: " . $e->getMessage());
		return [];
	}
}

/**
 * Add a new vehicle to the database (No capacity ceiling required)
 */
function addVehicleRecord(PDO $pdo, array $data): array {
	$vehicleNumber = strtoupper(trim($data['vehicle_number'] ?? ''));
	if (empty($vehicleNumber)) {
		return ['success' => false, 'error' => 'Vehicle Number / Plate is required.'];
	}

	// Check if already exists
	$chkStmt = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? LIMIT 1");
	$chkStmt->execute([$vehicleNumber]);
	if ($chkStmt->fetchColumn()) {
		return ['success' => false, 'error' => "Vehicle number '{$vehicleNumber}' is already registered."];
	}

	$vehicleName   = trim($data['vehicle_name'] ?? '');
	$driverName    = trim($data['driver_name'] ?? '');
	$driverPhone   = trim($data['driver_phone'] ?? '');
	$zoneId        = !empty($data['zone_id']) ? (int)$data['zone_id'] : null;
	$capacityCases = !empty($data['capacity_cases']) ? (int)$data['capacity_cases'] : 0;
	$status        = in_array($data['status'] ?? '', ['active', 'loading', 'dispatched', 'inactive']) ? $data['status'] : 'active';

	try {
		$stmt = $pdo->prepare("
			INSERT INTO vehicles (vehicle_number, vehicle_name, driver_name, driver_phone, zone_id, capacity_cases, status)
			VALUES (:vehicle_number, :vehicle_name, :driver_name, :driver_phone, :zone_id, :capacity_cases, :status)
		");
		$stmt->execute([
			':vehicle_number' => $vehicleNumber,
			':vehicle_name'   => $vehicleName,
			':driver_name'    => $driverName,
			':driver_phone'   => $driverPhone,
			':zone_id'        => $zoneId,
			':capacity_cases' => $capacityCases,
			':status'         => $status
		]);
		return [
			'success'    => true,
			'vehicle_id' => (int)$pdo->lastInsertId(),
			'message'    => "Vehicle {$vehicleNumber} registered successfully."
		];
	} catch (Exception $e) {
		return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
	}
}

/**
 * Record cases loaded onto a vehicle (manual, storage, or order)
 */
function recordVehicleLoad(PDO $pdo, int $vehicleId, ?int $zoneId, int $cases, ?int $orderId = null, ?string $notes = null, ?string $shopName = null, ?int $productId = null): bool {
	if ($cases <= 0 && $orderId === null) return false;
	try {
		$stmt = $pdo->prepare("
			INSERT INTO vehicle_loads (vehicle_id, zone_id, product_id, order_id, cases_loaded, shop_name, is_active, notes)
			VALUES (:vehicle_id, :zone_id, :product_id, :order_id, :cases_loaded, :shop_name, 1, :notes)
		");
		$stmt->execute([
			':vehicle_id'    => $vehicleId,
			':zone_id'       => $zoneId,
			':product_id'    => $productId,
			':order_id'      => $orderId,
			':cases_loaded'  => $cases,
			':shop_name'     => !empty($shopName) ? trim($shopName) : null,
			':notes'         => $notes
		]);
		// Auto update vehicle status to loading if currently active
		$pdo->prepare("UPDATE vehicles SET status = 'loading' WHERE id = ? AND status = 'active'")->execute([$vehicleId]);
		return true;
	} catch (Exception $e) {
		error_log("Error in recordVehicleLoad: " . $e->getMessage());
		return false;
	}
}

/**
 * Reset vehicle loading trip safely: clears active cases for next trip
 * but PRESERVES all lifetime statistics (deliveries done and loads delivered)!
 */
function resetVehicleLoadRecord(PDO $pdo, int $vehicleId): bool {
	try {
		// 1. Mark active loads as completed/cleared for current trip (do NOT delete history)
		$pdo->prepare("UPDATE vehicle_loads SET is_active = 0 WHERE vehicle_id = ? AND is_active = 1")->execute([$vehicleId]);

		// 2. Unassign only orders that were not yet delivered (delivered orders permanently retain vehicle_id)
		$pdo->prepare("UPDATE orders SET vehicle_id = NULL WHERE vehicle_id = ? AND status != 'delivered'")->execute([$vehicleId]);

		// 3. Set vehicle status back to active (in yard)
		$pdo->prepare("UPDATE vehicles SET status = 'active' WHERE id = ?")->execute([$vehicleId]);

		return true;
	} catch (Exception $e) {
		error_log("Error in resetVehicleLoadRecord: " . $e->getMessage());
		return false;
	}
}

/**
 * Fetch all distinct shop names and their zones from orders
 */
function getAllShopNamesWithZones(PDO $pdo): array {
	try {
		$stmt = $pdo->query("
			SELECT DISTINCT 
				TRIM(COALESCE(NULLIF(o.shop_name, ''), o.customer_name)) AS shop_name,
				o.zone_id,
				z.name AS zone_name
			FROM orders o
			LEFT JOIN zones z ON o.zone_id = z.id
			WHERE (o.shop_name IS NOT NULL AND TRIM(o.shop_name) != '')
			   OR (o.customer_name IS NOT NULL AND TRIM(o.customer_name) != '')
			ORDER BY shop_name ASC
		");
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (Exception $e) {
		return [];
	}
}

/**
 * Synchronize order items to vehicle_loads table so each product loaded for this order is tracked
 */
function syncOrderVehicleLoads(PDO $pdo, int $orderId, ?int $vehicleId = null): void {
	try {
		if ($vehicleId === null) {
			$stmt = $pdo->prepare("SELECT vehicle_id FROM orders WHERE order_id = ?");
			$stmt->execute([$orderId]);
			$vehicleId = (int)$stmt->fetchColumn();
		}

		// Delete existing vehicle_loads for this order
		$pdo->prepare("DELETE FROM vehicle_loads WHERE order_id = ?")->execute([$orderId]);

		if ($vehicleId <= 0) return;

		// Fetch order details
		$oStmt = $pdo->prepare("SELECT zone_id, order_number, shop_name, customer_name, product_id, quantity FROM orders WHERE order_id = ?");
		$oStmt->execute([$orderId]);
		$order = $oStmt->fetch(PDO::FETCH_ASSOC);
		if (!$order) return;

		$zoneId = !empty($order['zone_id']) ? (int)$order['zone_id'] : null;
		$shop = $order['shop_name'] ?: ($order['customer_name'] ?: 'Order');
		$notes = "Order #" . ($order['order_number'] ?: $orderId);

		// Fetch all items from order_items
		$iStmt = $pdo->prepare("SELECT product_id, quantity, is_extra FROM order_items WHERE order_id = ?");
		$iStmt->execute([$orderId]);
		$items = $iStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$ins = $pdo->prepare("
			INSERT INTO vehicle_loads (vehicle_id, zone_id, product_id, order_id, cases_loaded, shop_name, is_active, notes, created_at)
			VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())
		");

		if (!empty($items)) {
			foreach ($items as $it) {
				$pid = (int)($it['product_id'] ?? 0);
				$qty = (int)($it['quantity'] ?? 0);
				if ($qty > 0) {
					$extraTag = !empty($it['is_extra']) ? ' (Extra)' : '';
					$ins->execute([$vehicleId, $zoneId, $pid ?: null, $orderId, $qty, $shop, $notes . $extraTag]);
				}
			}
		} elseif (!empty($order['product_id']) && (int)$order['quantity'] > 0) {
			$ins->execute([$vehicleId, $zoneId, (int)$order['product_id'], $orderId, (int)$order['quantity'], $shop, $notes]);
		} else {
			$cases = max(1, (int)$order['quantity']);
			$ins->execute([$vehicleId, $zoneId, null, $orderId, $cases, $shop, $notes]);
		}
	} catch (Exception $e) {
		error_log("Error in syncOrderVehicleLoads: " . $e->getMessage());
	}
}

/**
 * Assign an order to a vehicle and record its loaded cases
 */
function assignOrderToVehicle(PDO $pdo, int $orderId, int $vehicleId): array {
	try {
		// Fetch order details
		$oStmt = $pdo->prepare("
			SELECT 
				o.order_id, 
				o.zone_id, 
				o.quantity,
				o.order_number,
				o.shop_name,
				o.customer_name,
				COALESCE(
					(SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id),
					o.quantity,
					1
				) AS total_cases
			FROM orders o 
			WHERE o.order_id = ?
		");
		$oStmt->execute([$orderId]);
		$order = $oStmt->fetch(PDO::FETCH_ASSOC);

		if (!$order) {
			return ['success' => false, 'error' => 'Order not found.'];
		}

		$cases = max(1, (int)$order['total_cases']);

		// 1. Update order vehicle_id
		$updStmt = $pdo->prepare("UPDATE orders SET vehicle_id = ? WHERE order_id = ?");
		$updStmt->execute([$vehicleId, $orderId]);

		// 2. Synchronize itemized vehicle_loads
		syncOrderVehicleLoads($pdo, $orderId, $vehicleId);

		// 3. Update vehicle status to 'loading'
		$pdo->prepare("UPDATE vehicles SET status = 'loading' WHERE id = ? AND status = 'active'")->execute([$vehicleId]);

		return ['success' => true, 'cases_loaded' => $cases, 'message' => "Order assigned. {$cases} cases loaded onto vehicle."];
	} catch (Exception $e) {
		error_log("Error in assignOrderToVehicle: " . $e->getMessage());
		return ['success' => false, 'error' => $e->getMessage()];
	}
}

/**
 * Unassign an order from a vehicle
 */
function unassignOrderFromVehicle(PDO $pdo, int $orderId): array {
	try {
		$pdo->prepare("UPDATE orders SET vehicle_id = NULL WHERE order_id = ?")->execute([$orderId]);
		$pdo->prepare("DELETE FROM vehicle_loads WHERE order_id = ?")->execute([$orderId]);
		return ['success' => true, 'message' => 'Order removed from vehicle.'];
	} catch (Exception $e) {
		return ['success' => false, 'error' => $e->getMessage()];
	}
}

