<?php

// Set timezone for consistent date/time handling across the admin panel
date_default_timezone_set('Asia/Kolkata');

if (file_exists(__DIR__ . '/activity_logger.php')) {
	require_once __DIR__ . '/activity_logger.php';
}

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
function getAllVehiclesWithLoads(PDO $pdo, $filterZone = null, $dateFilter = 'all', $fromDate = '', $toDate = ''): array {
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

		// Date filter condition for delivered orders
		$dateWhere = "";
		$dateParams = [];
		if ($dateFilter === 'custom' || (!empty($fromDate) || !empty($toDate))) {
			if (!empty($fromDate)) {
				$dateWhere .= " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) >= :from_date";
				$dateParams[':from_date'] = $fromDate;
			}
			if (!empty($toDate)) {
				$dateWhere .= " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) <= :to_date";
				$dateParams[':to_date'] = $toDate;
			}
		} elseif ($dateFilter === 'today') {
			$dateWhere = " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) = CURDATE()";
		} elseif ($dateFilter === 'yesterday') {
			$dateWhere = " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) = SUBDATE(CURDATE(), 1)";
		} elseif ($dateFilter === 'weekly' || $dateFilter === 'this_week') {
			$dateWhere = " AND YEARWEEK(COALESCE(r.created_at, o.updated_at, o.created_at), 1) = YEARWEEK(CURDATE(), 1)";
		} elseif ($dateFilter === 'monthly' || $dateFilter === 'this_month') {
			$dateWhere = " AND YEAR(COALESCE(r.created_at, o.updated_at, o.created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(r.created_at, o.updated_at, o.created_at)) = MONTH(CURDATE())";
		}

		// Count delivered orders and calculate financials per vehicle
		$delivStatsSql = "
			SELECT 
				o.vehicle_id,
				COUNT(DISTINCT o.order_id) AS delivered_count,
				COALESCE(SUM(
					COALESCE(
						(SELECT SUM(quantity) FROM order_items WHERE order_id = o.order_id),
						o.quantity,
						1
					)
				), 0) AS delivered_cases,
				COALESCE(SUM(COALESCE(r.total_amount, o.total_amount, 0)), 0) AS delivered_amount,
				COALESCE(SUM(r.cash_amount), 0) AS total_cash,
				COALESCE(SUM(r.credited_amount), 0) AS total_credit,
				COALESCE(SUM(r.due_amount), 0) AS total_due
			FROM orders o
			LEFT JOIN receipts r ON o.order_id = r.order_id
			WHERE o.vehicle_id IS NOT NULL 
			  AND o.status = 'delivered'
			  {$dateWhere}
			GROUP BY o.vehicle_id
		";
		$delivStatsStmt = $pdo->prepare($delivStatsSql);
		$delivStatsStmt->execute($dateParams);
		$delivStatsRows = $delivStatsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$delivOrdersMap = [];
		$delivCasesMap  = [];
		$delivAmountMap = [];
		$delivCashMap   = [];
		$delivCreditMap = [];
		$delivDueMap    = [];

		foreach ($delivStatsRows as $dsr) {
			$vid = (int)$dsr['vehicle_id'];
			$delivOrdersMap[$vid] = (int)$dsr['delivered_count'];
			$delivCasesMap[$vid]  = (int)$dsr['delivered_cases'];
			$delivAmountMap[$vid] = (float)$dsr['delivered_amount'];
			$delivCashMap[$vid]   = (float)$dsr['total_cash'];
			$delivCreditMap[$vid] = (float)$dsr['total_credit'];
			$delivDueMap[$vid]    = (float)$dsr['total_due'];
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
		$fleetTotalCash = 0.0;
		$fleetTotalCredit = 0.0;
		$fleetTotalDue = 0.0;
		$fleetDeliveredAmount = 0.0;
		$fleetDeliveriesDone = 0;
		$fleetLoadsDelivered = 0;

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

			// Delivery stats and financial totals (live, authoritative)
			$deliveriesDone   = (int)($delivOrdersMap[$vId] ?? 0);
			$loadsDelivered   = (int)($delivCasesMap[$vId] ?? 0);
			$totalDelivAmount = (float)($delivAmountMap[$vId] ?? 0.0);
			$totalCash        = (float)($delivCashMap[$vId] ?? 0.0);
			$totalCredit      = (float)($delivCreditMap[$vId] ?? 0.0);
			$totalDue         = (float)($delivDueMap[$vId] ?? 0.0);

			$fleetDeliveriesDone  += $deliveriesDone;
			$fleetLoadsDelivered  += $loadsDelivered;
			$fleetTotalCash       += $totalCash;
			$fleetTotalCredit     += $totalCredit;
			$fleetTotalDue        += $totalDue;
			$fleetDeliveredAmount += $totalDelivAmount;

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
			$v['total_delivered_amount']    = $totalDelivAmount;
			$v['total_cash']                = $totalCash;
			$v['total_credit']              = $totalCredit;
			$v['total_due']                 = $totalDue;
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
				'filter_zone_id'           => $filterZoneId,
				'fleet_deliveries_done'    => $fleetDeliveriesDone,
				'fleet_loads_delivered'    => $fleetLoadsDelivered,
				'fleet_total_cash'         => $fleetTotalCash,
				'fleet_total_credit'       => $fleetTotalCredit,
				'fleet_total_due'          => $fleetTotalDue,
				'fleet_delivered_amount'   => $fleetDeliveredAmount
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
				'filter_zone_id'           => null,
				'fleet_deliveries_done'    => 0,
				'fleet_loads_delivered'    => 0,
				'fleet_total_cash'         => 0,
				'fleet_total_credit'       => 0,
				'fleet_total_due'          => 0,
				'fleet_delivered_amount'   => 0
			]
		];
	}
}

/**
 * Retrieve comprehensive delivery history and financials for a single vehicle.
 *
 * @param PDO $pdo
 * @param int $vehicleId
 * @param string $dateFilter 'all', 'today', 'yesterday', 'weekly', 'monthly', 'custom'
 * @param string $fromDate YYYY-MM-DD
 * @param string $toDate YYYY-MM-DD
 * @param string $search Shop name, customer name, phone, bill number, or order number
 * @return array
 */
function getVehicleDeliveredOrdersDetails(PDO $pdo, int $vehicleId, string $dateFilter = 'all', string $fromDate = '', string $toDate = '', string $search = ''): array {
	try {
		$vStmt = $pdo->prepare("
			SELECT v.*, z.name AS home_zone_name, z.slug AS home_zone_slug
			FROM vehicles v
			LEFT JOIN zones z ON v.zone_id = z.id
			WHERE v.id = ?
			LIMIT 1
		");
		$vStmt->execute([$vehicleId]);
		$vehicle = $vStmt->fetch(PDO::FETCH_ASSOC);
		if (!$vehicle) {
			return ['success' => false, 'error' => 'Vehicle not found'];
		}

		$dateWhere = "";
		$params = [':vehicle_id' => $vehicleId];

		if ($dateFilter === 'custom' || (!empty($fromDate) || !empty($toDate))) {
			if (!empty($fromDate)) {
				$dateWhere .= " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) >= :from_date";
				$params[':from_date'] = $fromDate;
			}
			if (!empty($toDate)) {
				$dateWhere .= " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) <= :to_date";
				$params[':to_date'] = $toDate;
			}
		} elseif ($dateFilter === 'today') {
			$dateWhere = " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) = CURDATE()";
		} elseif ($dateFilter === 'yesterday') {
			$dateWhere = " AND DATE(COALESCE(r.created_at, o.updated_at, o.created_at)) = SUBDATE(CURDATE(), 1)";
		} elseif ($dateFilter === 'weekly' || $dateFilter === 'this_week') {
			$dateWhere = " AND YEARWEEK(COALESCE(r.created_at, o.updated_at, o.created_at), 1) = YEARWEEK(CURDATE(), 1)";
		} elseif ($dateFilter === 'monthly' || $dateFilter === 'this_month') {
			$dateWhere = " AND YEAR(COALESCE(r.created_at, o.updated_at, o.created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(r.created_at, o.updated_at, o.created_at)) = MONTH(CURDATE())";
		}

		$search = trim($search);
		if ($search !== '') {
			$dateWhere .= " AND (o.shop_name LIKE :search1 OR o.customer_name LIKE :search2 OR o.phone LIKE :search3 OR r.bill_number LIKE :search4 OR o.order_number LIKE :search5)";
			$term = '%' . $search . '%';
			$params[':search1'] = $term;
			$params[':search2'] = $term;
			$params[':search3'] = $term;
			$params[':search4'] = $term;
			$params[':search5'] = $term;
		}

		$ordStmt = $pdo->prepare("
			SELECT 
				o.order_id,
				o.order_number,
				o.shop_name,
				o.customer_name,
				o.phone,
				o.location,
				o.address,
				o.total_amount,
				o.payment_method,
				o.payment_status,
				o.created_at as order_created_at,
				o.updated_at as order_updated_at,
				z.name as zone_name,
				r.bill_number,
				COALESCE(r.cash_amount, 0) as cash_amount,
				COALESCE(r.credited_amount, 0) as credited_amount,
				COALESCE(r.due_amount, 0) as due_amount,
				r.payment_type,
				COALESCE(r.created_at, o.updated_at, o.created_at) as delivered_at,
				(SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) as items_count,
				COALESCE(
					(SELECT SUM(quantity) FROM order_items oi WHERE oi.order_id = o.order_id),
					o.quantity,
					1
				) as total_cases,
				(SELECT GROUP_CONCAT(CONCAT(COALESCE(p.product_name, p.name), ' (', oi.quantity, ' cs)') SEPARATOR ', ')
				 FROM order_items oi
				 JOIN products p ON oi.product_id = p.product_id
				 WHERE oi.order_id = o.order_id) as items_detail
			FROM orders o
			LEFT JOIN receipts r ON o.order_id = r.order_id
			LEFT JOIN zones z ON o.zone_id = z.id
			WHERE o.vehicle_id = :vehicle_id 
			  AND o.status = 'delivered'
			  {$dateWhere}
			ORDER BY COALESCE(r.created_at, o.updated_at, o.created_at) DESC
		");
		$ordStmt->execute($params);
		$orders = $ordStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$totalCases  = 0;
		$totalSales  = 0.0;
		$totalCash   = 0.0;
		$totalCredit = 0.0;
		$totalDue    = 0.0;

		foreach ($orders as &$ord) {
			$c = (int)$ord['total_cases'];
			$s = (float)$ord['total_amount'];
			$ca = (float)$ord['cash_amount'];
			$cr = (float)$ord['credited_amount'];
			$d = (float)$ord['due_amount'];

			$totalCases  += $c;
			$totalSales  += $s;
			$totalCash   += $ca;
			$totalCredit += $cr;
			$totalDue    += $d;

			$ord['formatted_date']   = date('d M Y, h:i A', strtotime($ord['delivered_at']));
			$ord['formatted_total']  = '₹' . number_format($s, 2);
			$ord['formatted_cash']   = '₹' . number_format($ca, 2);
			$ord['formatted_credit'] = '₹' . number_format($cr, 2);
			$ord['formatted_due']    = '₹' . number_format($d, 2);
		}
		unset($ord);

		return [
			'success' => true,
			'vehicle' => $vehicle,
			'summary' => [
				'total_orders'     => count($orders),
				'total_cases'      => $totalCases,
				'total_sales'      => $totalSales,
				'total_cash'       => $totalCash,
				'total_credit'     => $totalCredit,
				'total_due'        => $totalDue,
				'formatted_sales'  => '₹' . number_format($totalSales, 2),
				'formatted_cash'   => '₹' . number_format($totalCash, 2),
				'formatted_credit' => '₹' . number_format($totalCredit, 2),
				'formatted_due'    => '₹' . number_format($totalDue, 2),
			],
			'orders' => $orders
		];
	} catch (Exception $e) {
		error_log("Error in getVehicleDeliveredOrdersDetails: " . $e->getMessage());
		return ['success' => false, 'error' => $e->getMessage()];
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

/**
 * Retrieve unpaid prior dues/receipts for a shop
 */
function getShopPendingDueDetails(PDO $pdo, string $shopName, string $phone = '', int $excludeOrderId = 0, ?int $zoneId = null): array {
	$cleanShop = trim(strtolower($shopName));
	$cleanPhone = preg_replace('/[^0-9]/', '', $phone);
	$phone10 = strlen($cleanPhone) >= 10 ? substr($cleanPhone, -10) : $cleanPhone;
	$phoneLike = '%' . $phone10;

	if (empty($cleanShop) && empty($phone10)) {
		return ['has_due' => false, 'total_due' => 0.0, 'due_count' => 0, 'receipts' => []];
	}

	try {
		$zoneSql = ($zoneId !== null && $zoneId > 0) ? " AND o.zone_id = " . (int)$zoneId : "";
		$sql = "
			SELECT 
				r.id as receipt_id,
				r.order_id,
				r.bill_number,
				r.due_amount,
				r.cash_amount,
				r.credited_amount,
				r.total_amount,
				r.payment_type,
				r.notes,
				r.created_at as receipt_date,
				o.order_number,
				o.shop_name,
				o.customer_name,
				o.phone,
				o.zone_id,
				z.name as zone_name
			FROM receipts r
			JOIN orders o ON r.order_id = o.order_id
			LEFT JOIN zones z ON o.zone_id = z.id
			WHERE r.due_amount > 0
			  AND o.status != 'cancelled'
			  AND o.order_id != ?
			  {$zoneSql}
			  AND (
				  (? != '' AND (TRIM(LOWER(o.shop_name)) = ? OR TRIM(LOWER(o.customer_name)) = ?))
				  OR (? != '' AND LENGTH(?) >= 7 AND (
					  REPLACE(REPLACE(REPLACE(REPLACE(o.phone, ' ', ''), '-', ''), '+91', ''), '+', '') LIKE ?
					  OR RIGHT(REGEXP_REPLACE(o.phone, '[^0-9]', ''), 10) = ?
				  ))
			  )
			ORDER BY r.id ASC
		";

		$stmt = $pdo->prepare($sql);
		$stmt->execute([
			$excludeOrderId,
			$cleanShop, $cleanShop, $cleanShop,
			$phone10, $phone10, $phoneLike, $phone10
		]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$totalDue = 0.0;
		foreach ($rows as &$r) {
			$r['due_amount'] = (float)$r['due_amount'];
			$r['total_amount'] = (float)$r['total_amount'];
			$r['cash_amount'] = (float)$r['cash_amount'];
			$r['credited_amount'] = (float)$r['credited_amount'];
			$totalDue += $r['due_amount'];
		}
		unset($r);

		return [
			'has_due' => ($totalDue > 0),
			'total_due' => $totalDue,
			'due_count' => count($rows),
			'receipts' => $rows
		];
	} catch (Exception $e) {
		error_log("Error in getShopPendingDueDetails: " . $e->getMessage());
		return ['has_due' => false, 'total_due' => 0.0, 'due_count' => 0, 'receipts' => []];
	}
}

/**
 * Settle a shop's prior pending dues FIFO across unpaid receipts
 */
function settleShopPriorDuesFifo(PDO $pdo, string $shopName, string $phone, float $amountToClear, string $mode = 'cash', string $referenceNote = '', int $excludeOrderId = 0, ?int $zoneId = null): array {
	$amountToClear = (float)$amountToClear;
	if ($amountToClear <= 0) {
		return ['success' => false, 'total_cleared' => 0.0, 'remaining_balance' => 0.0, 'cleared_receipts' => []];
	}

	$dues = getShopPendingDueDetails($pdo, $shopName, $phone, $excludeOrderId, $zoneId);
	if (empty($dues['receipts'])) {
		return ['success' => false, 'total_cleared' => 0.0, 'remaining_balance' => 0.0, 'cleared_receipts' => []];
	}

	$isOnlineOrCredit = in_array(strtolower($mode), ['upi', 'bank', 'credit', 'online']);
	$remainingToClear = min($amountToClear, $dues['total_due']);
	$totalCleared = 0.0;
	$clearedReceipts = [];

	foreach ($dues['receipts'] as $rec) {
		if ($remainingToClear <= 0) break;
		$currentDue = (float)$rec['due_amount'];
		if ($currentDue <= 0) continue;

		$clearThis = min($currentDue, $remainingToClear);
		$newDue = max(0, $currentDue - $clearThis);

		$auditNote = " | Prior due of ₹" . number_format($clearThis, 2) . " settled via " . ucfirst($mode);
		if (!empty($referenceNote)) {
			$auditNote .= " ({$referenceNote})";
		}
		$auditNote .= " on " . date('d-m-Y H:i:s');

		if ($isOnlineOrCredit) {
			$upRec = $pdo->prepare("
				UPDATE receipts 
				SET credited_amount = credited_amount + ?,
					due_amount = ?,
					payment_type = CASE WHEN cash_amount > 0 THEN 'Cash + Credited' ELSE 'Credited' END,
					notes = CONCAT(COALESCE(notes, ''), ?)
				WHERE id = ?
			");
			$upRec->execute([$clearThis, $newDue, $auditNote, $rec['receipt_id']]);
		} else {
			$upRec = $pdo->prepare("
				UPDATE receipts 
				SET cash_amount = cash_amount + ?,
					due_amount = ?,
					payment_type = CASE WHEN credited_amount > 0 THEN 'Cash + Credited' ELSE 'Cash' END,
					notes = CONCAT(COALESCE(notes, ''), ?)
				WHERE id = ?
			");
			$upRec->execute([$clearThis, $newDue, $auditNote, $rec['receipt_id']]);
		}

		// Insert into order_payments
		try {
			$pPay = $pdo->prepare("
				INSERT INTO order_payments (order_id, cash_amount, credited_amount, due_amount, payment_type, created_at)
				VALUES (?, ?, ?, ?, ?, NOW())
			");
			$pCash = $isOnlineOrCredit ? 0.00 : $clearThis;
			$pCredit = $isOnlineOrCredit ? $clearThis : 0.00;
			$pPayType = strtolower($mode);
			$pPay->execute([$rec['order_id'], $pCash, $pCredit, $newDue, $pPayType]);
		} catch (Exception $e) {
			error_log("Error inserting order_payments during FIFO due settlement: " . $e->getMessage());
		}

		if (function_exists('quickLog')) {
			quickLog($pdo, 'settle_due_fifo', 'receipt', $rec['receipt_id'], "Cleared ₹{$clearThis} prior due for order #{$rec['order_id']} via {$mode}. Remaining due: ₹{$newDue}");
		}

		$clearedReceipts[] = [
			'receipt_id' => $rec['receipt_id'],
			'order_id' => $rec['order_id'],
			'bill_number' => $rec['bill_number'],
			'order_number' => $rec['order_number'],
			'cleared_amount' => $clearThis,
			'remaining_due' => $newDue
		];

		$totalCleared += $clearThis;
		$remainingToClear -= $clearThis;
	}

	$remainingBalance = max(0, $dues['total_due'] - $totalCleared);

	return [
		'success' => true,
		'total_cleared' => $totalCleared,
		'remaining_balance' => $remainingBalance,
		'cleared_receipts' => $clearedReceipts,
		'mode' => $mode
	];
}

/**
 * Get comprehensive payment & due clearance audit history for an order
 * Includes initial receipt generation and all subsequent payment installments / clearances
 */
function getOrderPaymentHistory(PDO $pdo, int $orderId): array {
	$orderId = (int)$orderId;
	if ($orderId <= 0) {
		return ['success' => false, 'error' => 'Invalid order ID'];
	}

	try {
		// Fetch order details
		$oStmt = $pdo->prepare("
			SELECT o.order_id, o.order_number, o.shop_name, o.customer_name, o.phone, o.location, o.address,
			       o.total_amount, o.discount, o.status, o.created_at as order_date,
			       z.name as zone_name, z.slug as zone_slug
			FROM orders o
			LEFT JOIN zones z ON o.zone_id = z.id
			WHERE o.order_id = ?
		");
		$oStmt->execute([$orderId]);
		$order = $oStmt->fetch(PDO::FETCH_ASSOC);
		if (!$order) {
			return ['success' => false, 'error' => 'Order not found'];
		}

		// Fetch receipt details
		$rStmt = $pdo->prepare("
			SELECT id as receipt_id, bill_number, delivered_quantity, total_amount as receipt_total,
			       cash_amount as receipt_cash, credited_amount as receipt_credit,
			       due_amount as receipt_due, payment_type as receipt_pay_type,
			       notes, created_at as receipt_date
			FROM receipts
			WHERE order_id = ?
		");
		$rStmt->execute([$orderId]);
		$receipt = $rStmt->fetch(PDO::FETCH_ASSOC);

		// Fetch all order_payments records
		$pStmt = $pdo->prepare("
			SELECT id, order_id, cash_amount, credited_amount, due_amount, payment_type, created_at
			FROM order_payments
			WHERE order_id = ?
			ORDER BY id ASC
		");
		$pStmt->execute([$orderId]);
		$payments = $pStmt->fetchAll(PDO::FETCH_ASSOC);

		// Parse audit notes from receipts.notes
		$auditNotes = [];
		if (!empty($receipt['notes'])) {
			$rawNotes = explode(' | ', $receipt['notes']);
			foreach ($rawNotes as $rn) {
				$rn = trim($rn);
				if (empty($rn)) continue;
				$auditNotes[] = $rn;
			}
		}

		// Build chronological timeline ledger
		$ledger = [];
		$totalOrderAmount = (float)$order['total_amount'];
		$currentOutstandingDue = $receipt ? (float)$receipt['receipt_due'] : $totalOrderAmount;
		$totalPaidCash = $receipt ? (float)$receipt['receipt_cash'] : 0.0;
		$totalPaidCredit = $receipt ? (float)$receipt['receipt_credit'] : 0.0;
		$totalPaid = $totalPaidCash + $totalPaidCredit;

		if (!empty($payments)) {
			foreach ($payments as $idx => $p) {
				$pCash = (float)$p['cash_amount'];
				$pCredit = (float)$p['credited_amount'];
				$pDue = (float)$p['due_amount'];
				$pPaidThis = $pCash + $pCredit;
				$pMode = strtolower(trim($p['payment_type'] ?? ''));
				if (empty($pMode)) {
					$pMode = ($pCash > 0 && $pCredit > 0) ? 'split' : (($pCredit > 0) ? 'credit' : 'cash');
				}

				$isInitial = ($idx === 0);

				$matchedNote = '';
				if ($isInitial) {
					$matchedNote = 'Initial order delivery & billing receipt generated';
				} else {
					$noteIndex = $idx - 1;
					if (isset($auditNotes[$noteIndex])) {
						$matchedNote = $auditNotes[$noteIndex];
					} else {
						$pTimeStr = date('d-m-Y', strtotime($p['created_at']));
						foreach ($auditNotes as $an) {
							if (strpos($an, $pTimeStr) !== false && (
								strpos($an, number_format($pPaidThis, 2)) !== false ||
								strpos($an, number_format($pCash, 2)) !== false ||
								strpos($an, number_format($pCredit, 2)) !== false
							)) {
								$matchedNote = $an;
								break;
							}
						}
					}
				}

				$ledger[] = [
					'payment_id' => (int)$p['id'],
					'type' => $isInitial ? 'initial_bill' : 'due_clearance',
					'title' => $isInitial ? 'Initial Order Delivery & Billing' : ('Due Clearance Installment #' . $idx),
					'timestamp' => $p['created_at'],
					'formatted_date' => date('d M Y, h:i A', strtotime($p['created_at'])),
					'cash_amount' => $pCash,
					'credited_amount' => $pCredit,
					'amount_cleared' => $pPaidThis,
					'remaining_due' => $pDue,
					'payment_mode' => $pMode,
					'note' => $matchedNote ?: ($isInitial ? 'Order delivered & billed' : 'Due clearance installment')
				];
			}
		} elseif ($receipt) {
			// Receipt exists but no order_payments rows yet
			$ledger[] = [
				'payment_id' => 0,
				'type' => 'initial_bill',
				'title' => 'Initial Order Delivery & Billing',
				'timestamp' => $receipt['receipt_date'],
				'formatted_date' => date('d M Y, h:i A', strtotime($receipt['receipt_date'])),
				'cash_amount' => (float)$receipt['receipt_cash'],
				'credited_amount' => (float)$receipt['receipt_credit'],
				'amount_cleared' => (float)$receipt['receipt_cash'] + (float)$receipt['receipt_credit'],
				'remaining_due' => (float)$receipt['receipt_due'],
				'payment_mode' => strtolower($receipt['receipt_pay_type'] ?? 'cash'),
				'note' => $receipt['notes'] ?: 'Receipt generated'
			];
		}

		return [
			'success' => true,
			'order' => $order,
			'receipt' => $receipt,
			'has_receipt' => !empty($receipt),
			'total_order_amount' => $totalOrderAmount,
			'total_paid' => $totalPaid,
			'total_paid_cash' => $totalPaidCash,
			'total_paid_credit' => $totalPaidCredit,
			'current_due' => $currentOutstandingDue,
			'is_settled' => ($currentOutstandingDue <= 0.001),
			'clearance_count' => max(0, count($payments) - 1),
			'ledger' => $ledger,
			'audit_notes' => $auditNotes
		];
	} catch (Exception $e) {
		error_log("Error in getOrderPaymentHistory: " . $e->getMessage());
		return ['success' => false, 'error' => $e->getMessage()];
	}
}

/**
 * Get active damaged items recorded for a specific vehicle
 */
function getVehicleDamagedItems(PDO $pdo, int $vehicleId, ?string $status = 'reported'): array {
	if ($vehicleId <= 0) return [];
	try {
		$sql = "
			SELECT 
				vdi.*,
				COALESCE(p.product_name, p.name) as product_name,
				p.net_content,
				p.net_content_unit,
				p.case_price
			FROM vehicle_damaged_items vdi
			JOIN products p ON vdi.product_id = p.product_id
			WHERE vdi.vehicle_id = ?
		";
		$params = [$vehicleId];
		if ($status !== null) {
			$sql .= " AND vdi.status = ?";
			$params[] = $status;
		}
		$sql .= " ORDER BY vdi.created_at DESC";
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (Exception $e) {
		error_log("Error in getVehicleDamagedItems: " . $e->getMessage());
		return [];
	}
}

/**
 * Get active damaged items recorded for a specific product across vehicles
 */
function getProductDamagedItems(PDO $pdo, int $productId, ?string $status = 'reported'): array {
	if ($productId <= 0) return [];
	try {
		$sql = "
			SELECT 
				vdi.*,
				COALESCE(p.product_name, p.name) as product_name,
				p.net_content,
				p.net_content_unit,
				p.case_price,
				v.vehicle_number,
				v.vehicle_name,
				v.driver_name,
				z.name as zone_name,
				z.slug as zone_slug
			FROM vehicle_damaged_items vdi
			JOIN products p ON vdi.product_id = p.product_id
			LEFT JOIN vehicles v ON vdi.vehicle_id = v.id
			LEFT JOIN zones z ON vdi.zone_id = z.id
			WHERE vdi.product_id = ?
		";
		$params = [$productId];
		if ($status !== null) {
			$sql .= " AND vdi.status = ?";
			$params[] = $status;
		}
		$sql .= " ORDER BY vdi.created_at DESC";
		$stmt = $pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
	} catch (Exception $e) {
		error_log("Error in getProductDamagedItems: " . $e->getMessage());
		return [];
	}
}

/**
 * Get vehicle damaged items summary for all vehicles operating in a zone
 */
function getZoneVehicleDamagedSummary(PDO $pdo, int $zoneId): array {
	try {
		$stmt = $pdo->prepare("
			SELECT 
				vdi.*,
				COALESCE(p.product_name, p.name) as product_name,
				p.net_content,
				p.net_content_unit
			FROM vehicle_damaged_items vdi
			JOIN products p ON vdi.product_id = p.product_id
			WHERE (vdi.zone_id = ? OR vdi.vehicle_id IN (SELECT id FROM vehicles WHERE zone_id = ?))
			  AND vdi.status = 'reported'
			ORDER BY vdi.created_at DESC
		");
		$stmt->execute([$zoneId, $zoneId]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

		$map = [];
		foreach ($rows as $r) {
			$vid = (int)$r['vehicle_id'];
			if (!isset($map[$vid])) {
				$map[$vid] = [
					'total_damaged_cases' => 0,
					'items' => []
				];
			}
			$map[$vid]['total_damaged_cases'] += (int)$r['damaged_cases'];
			$map[$vid]['items'][] = $r;
		}
		return $map;
	} catch (Exception $e) {
		error_log("Error in getZoneVehicleDamagedSummary: " . $e->getMessage());
		return [];
	}
}

/**
 * Record a damaged item for a vehicle
 */
function recordVehicleDamagedItem(PDO $pdo, int $vehicleId, int $productId, int $cases, ?int $zoneId = null, string $notes = '', string $reportedBy = '', bool $deductVehicleLoad = true): array {
	$cases = max(1, $cases);
	if ($vehicleId <= 0 || $productId <= 0) {
		return ['success' => false, 'error' => 'Invalid vehicle or product specified.'];
	}

	try {
		$pdo->beginTransaction();

		// 1. Insert into vehicle_damaged_items (marked as stock_deducted = 1)
		$ins = $pdo->prepare("
			INSERT INTO vehicle_damaged_items (
				vehicle_id, zone_id, product_id, damaged_cases, stock_deducted,
				status, notes, reported_by, created_at
			) VALUES (?, ?, ?, ?, 1, 'reported', ?, ?, NOW())
		");
		$ins->execute([
			$vehicleId,
			$zoneId,
			$productId,
			$cases,
			$notes ?: null,
			$reportedBy ?: 'Zone User'
		]);
		$damageId = (int)$pdo->lastInsertId();

		// 2. Optionally deduct from vehicle's active storage load so damaged stock cannot be delivered
		$deductedCases = 0;
		if ($deductVehicleLoad) {
			$vlStmt = $pdo->prepare("
				SELECT id, cases_loaded 
				FROM vehicle_loads 
				WHERE vehicle_id = ? 
				  AND product_id = ? 
				  AND is_active = 1 
				  AND order_id IS NULL
				ORDER BY id DESC
				LIMIT 1
			");
			$vlStmt->execute([$vehicleId, $productId]);
			$vlRow = $vlStmt->fetch(PDO::FETCH_ASSOC);

			if ($vlRow) {
				$currLoaded = (int)$vlRow['cases_loaded'];
				$deductedCases = min($currLoaded, $cases);
				$newLoaded = max(0, $currLoaded - $deductedCases);
				if ($newLoaded === 0) {
					$pdo->prepare("UPDATE vehicle_loads SET cases_loaded = 0, is_active = 0 WHERE id = ?")->execute([$vlRow['id']]);
				} else {
					$pdo->prepare("UPDATE vehicle_loads SET cases_loaded = ? WHERE id = ?")->execute([$newLoaded, $vlRow['id']]);
				}
			}
		}

		// 3. Deduct damaged cases from warehouse product available stock
		adjustProductStock($pdo, $productId, -$cases, "Damaged stock reported on vehicle #{$vehicleId}");

		$pdo->commit();

		// Quick log
		$vNum = $pdo->query("SELECT vehicle_number FROM vehicles WHERE id = {$vehicleId}")->fetchColumn() ?: ('Vehicle #' . $vehicleId);
		$pName = $pdo->query("SELECT COALESCE(product_name, name) FROM products WHERE product_id = {$productId}")->fetchColumn() ?: ('Product #' . $productId);
		if (function_exists('quickLog')) {
			quickLog($pdo, 'report_damaged', 'vehicle', $vehicleId, "Reported {$cases} damaged cases of {$pName} on {$vNum} (deducted from product stock)" . ($notes ? " (Note: {$notes})" : ""));
		}

		return [
			'success' => true,
			'id' => $damageId,
			'deducted_from_load' => $deductedCases,
			'deducted_from_stock' => $cases
		];
	} catch (Exception $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		error_log("Error in recordVehicleDamagedItem: " . $e->getMessage());
		return ['success' => false, 'error' => $e->getMessage()];
	}
}

/**
 * Resolve/Remove damaged items (when corrected, transferred, or resolved)
 */
function resolveDamagedItem(PDO $pdo, int $damagedId, string $action = 'transferred', bool $restoreToWarehouse = false, string $notes = '', string $resolvedBy = 'Admin'): array {
	if ($damagedId <= 0) {
		return ['success' => false, 'error' => 'Invalid damaged record ID.'];
	}

	$allowedActions = ['corrected', 'transferred', 'resolved'];
	if (!in_array($action, $allowedActions)) {
		$action = 'resolved';
	}

	try {
		$stmt = $pdo->prepare("SELECT * FROM vehicle_damaged_items WHERE id = ?");
		$stmt->execute([$damagedId]);
		$rec = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$rec) {
			return ['success' => false, 'error' => 'Damaged record not found.'];
		}

		$pid = (int)$rec['product_id'];
		$cases = (int)$rec['damaged_cases'];
		$wasDeducted = !empty($rec['stock_deducted']);
		$shouldRestore = $restoreToWarehouse || in_array($action, ['transferred', 'corrected']);
		$newStockDeducted = ($shouldRestore && $wasDeducted) ? 0 : ($wasDeducted ? 1 : 0);

		$pdo->beginTransaction();

		$upStmt = $pdo->prepare("
			UPDATE vehicle_damaged_items 
			SET status = ?,
				resolution_action = ?,
				resolution_notes = ?,
				resolved_by = ?,
				stock_deducted = ?,
				resolved_at = NOW()
			WHERE id = ?
		");
		$upStmt->execute([
			$action,
			$action,
			$notes ?: null,
			$resolvedBy ?: 'Admin',
			$newStockDeducted,
			$damagedId
		]);

		// Restore stock if action is transferred or corrected, or explicitly requested
		if ($shouldRestore && $pid > 0 && $cases > 0 && $wasDeducted) {
			adjustProductStock($pdo, $pid, +$cases, "Damaged stock restored to inventory ({$action})");
		}

		$pdo->commit();

		if (function_exists('quickLog')) {
			quickLog($pdo, 'resolve_damaged', 'product', $pid, "Resolved {$cases} damaged cases as {$action} (Record #{$damagedId})" . ($shouldRestore ? " & restored to stock" : " (stock remains deducted)"));
		}

		return ['success' => true, 'cases' => $cases, 'action' => $action, 'restored_stock' => $shouldRestore];
	} catch (Exception $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		error_log("Error in resolveDamagedItem: " . $e->getMessage());
		return ['success' => false, 'error' => $e->getMessage()];
	}
}




