<?php
/**
 * Manual / Web Schema Migration Runner
 * 
 * Accessible at /admin/run_migration.php
 * Can be run by authenticated admins or directly during deployment setup.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/schema_sync.php';

// Authentication: allow if logged in as admin or if triggered locally
$is_authenticated = isset($_SESSION['admin_id']);
if (!$is_authenticated && !$is_local) {
    // If not logged in on live, require auth
    require_once __DIR__ . '/includes/auth_check.php';
}

$status_log = [];
$success = true;

try {
    $status_log[] = ["type" => "success", "msg" => "Connected to Main Database: " . DB_NAME_MAIN];

    // Force clear session cache to re-verify
    unset($_SESSION['warehouse_schema_v2']);

    $migrated = ensureWarehouseSchema($pdo);
    if ($migrated) {
        $status_log[] = ["type" => "success", "msg" => "Warehouse & Multi-Zone Schema synchronized successfully."];
    } else {
        $status_log[] = ["type" => "warning", "msg" => "Schema sync finished with warnings (check server error logs)."];
    }

    // Verify key tables
    $tables = ['zones', 'receipts', 'order_payments', 'rewards', 'system_settings'];
    foreach ($tables as $t) {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetchColumn();
        if ($exists) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $status_log[] = ["type" => "success", "msg" => "Table `{$t}` is ready ({$count} records)."];
        } else {
            $status_log[] = ["type" => "error", "msg" => "Table `{$t}` is MISSING!"];
            $success = false;
        }
    }

    // Verify products columns
    $pCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $neededPCols = ['product_name', 'case_stock', 'case_price', 'net_content', 'net_content_unit'];
    foreach ($neededPCols as $col) {
        if (in_array($col, $pCols)) {
            $status_log[] = ["type" => "success", "msg" => "Product column `{$col}` verified."];
        } else {
            $status_log[] = ["type" => "error", "msg" => "Product column `{$col}` is MISSING!"];
            $success = false;
        }
    }

    // Verify orders columns
    $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    $neededOCols = ['order_number', 'zone_id', 'shop_name', 'customer_name', 'quantity', 'unit_price', 'discount'];
    foreach ($neededOCols as $col) {
        if (in_array($col, $oCols)) {
            $status_log[] = ["type" => "success", "msg" => "Order column `{$col}` verified."];
        } else {
            $status_log[] = ["type" => "error", "msg" => "Order column `{$col}` is MISSING!"];
            $success = false;
        }
    }

} catch (Exception $e) {
    $status_log[] = ["type" => "error", "msg" => "Error during migration: " . $e->getMessage()];
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration - Liyas Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: #f8fafc;
            color: #1e293b;
            padding: 2rem;
            margin: 0;
        }
        .container {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        h1 {
            font-size: 22px;
            margin-top: 0;
            color: #0f172a;
        }
        .log-item {
            padding: 8px 12px;
            margin-bottom: 8px;
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .log-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .log-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
        .log-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .btn-dash {
            display: inline-block;
            margin-top: 1.5rem;
            padding: 10px 20px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-dash:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛠️ Multi-Zone Warehouse Migration Status</h1>
        <p style="color: #64748b; font-size: 14px; margin-bottom: 1.5rem;">
            Verification of database tables, columns, and seed records for Liyas International Warehouse System.
        </p>

        <?php foreach ($status_log as $log): ?>
            <div class="log-item log-<?= $log['type'] ?>">
                <?= ($log['type'] === 'success') ? '✅' : (($log['type'] === 'warning') ? '⚠️' : '❌') ?>
                <span><?= htmlspecialchars($log['msg']) ?></span>
            </div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div style="margin-top: 1.5rem; padding: 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; color: #1e40af; font-size: 14px;">
                🎉 <strong>Everything is up to date!</strong> You can now use the Warehouse Dashboard without any database errors.
            </div>
        <?php else: ?>
            <div style="margin-top: 1.5rem; padding: 12px; background: #fee2e2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 14px;">
                ⚠️ <strong>Some checks failed.</strong> Please review the error messages above or check MySQL user permissions.
            </div>
        <?php endif; ?>

        <a href="dashboard/index.php" class="btn-dash">Go to Warehouse Dashboard &rarr;</a>
    </div>
</body>
</html>
