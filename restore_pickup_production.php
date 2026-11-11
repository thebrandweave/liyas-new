<?php
/**
 * LIYAS PRODUCTION EMERGENCY RESTORE SCRIPT
 * Restores Vehicle #13 (Pickup), all 222 Vehicle Loads, 53 Orders, 78 Order Items, and 53 Receipts.
 * 
 * Instructions for Live Server:
 * 1. Upload this file to the root of your live website (https://liyasinternational.com/)
 * 2. Visit: https://liyasinternational.com/restore_pickup_production.php
 * 3. Review the green success confirmation.
 * 4. Delete this file immediately from the live server for security.
 */

// Basic security token or confirmation parameter
$run = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';

require_once __DIR__ . '/config/config.php';
$pdo = getDB();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Restore Pickup Vehicle & Orders - Live Production</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; padding: 30px; }
        .card { max-width: 850px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 32px; }
        h1 { font-size: 24px; margin-top: 0; color: #1e293b; display: flex; align-items: center; gap: 10px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 700; background: #e0f2fe; color: #0369a1; }
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 24px 0; }
        .stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; }
        .stat-num { font-size: 28px; font-weight: 800; color: #2563eb; }
        .stat-label { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-top: 4px; }
        .btn { display: inline-block; padding: 12px 24px; font-size: 15px; font-weight: 700; color: #fff; background: #2563eb; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #1d4ed8; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .success-box { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 16px; border-radius: 8px; margin: 20px 0; }
        .warning-box { background: #fefce8; border: 1px solid #fef08a; color: #854d0e; padding: 16px; border-radius: 8px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; font-weight: 700; color: #334155; }
    </style>
</head>
<body>
<div class="card">
    <h1>
        <span>🚚</span> Restore Pickup Vehicle & Orders
        <span class="badge">Live Production</span>
    </h1>
    <p style="color: #64748b; font-size: 14px;">
        This tool restores <strong>Vehicle #13 (Pickup - KA19AF9203)</strong>, its product load inventory, and re-assigns all <strong>53 orders</strong> in Zone 20 that were deleted or unassigned.
    </p>

<?php if (!$run): ?>
    <div class="warning-box">
        <strong>Ready to Restore:</strong>
        <p style="margin: 6px 0 0;">
            Clicking the button below will execute the recovery sequence on the database. Existing records will be safely updated without overwriting valid new data (using <code>ON DUPLICATE KEY UPDATE</code>).
        </p>
    </div>

    <div style="text-align: center; margin: 30px 0;">
        <a href="?confirm=yes" class="btn" style="padding: 14px 32px; font-size: 16px;">▶ Confirm & Restore Now</a>
    </div>

<?php else: ?>
    <?php
    $sqlFile = __DIR__ . '/RESTORE_PICKUP_PRODUCTION.sql';
    if (!file_exists($sqlFile)) {
        echo "<div class='warning-box'><strong>Error:</strong> <code>RESTORE_PICKUP_PRODUCTION.sql</code> not found in the same folder. Please upload it alongside this script.</div>";
    } else {
        try {
            $sqlContent = file_get_contents($sqlFile);
            $pdo->exec($sqlContent);

            // Fetch verification stats
            $vCheck = $pdo->query("SELECT * FROM vehicles WHERE id = 13")->fetch(PDO::FETCH_ASSOC);
            $loadCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_loads WHERE vehicle_id = 13")->fetchColumn();
            $orderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE vehicle_id = 13")->fetchColumn();
            $zoneOrderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE zone_id = 20")->fetchColumn();
            $receiptCount = (int)$pdo->query("SELECT COUNT(*) FROM receipts r JOIN orders o ON r.order_id = o.order_id WHERE o.vehicle_id = 13")->fetchColumn();

            echo "<div class='success-box'>";
            echo "<strong>✅ RESTORATION SUCCESSFUL!</strong>";
            echo "<p style='margin: 6px 0 0;'>Vehicle #13 (Pickup), all loads, orders, order items, and receipts have been completely restored.</p>";
            echo "</div>";

            echo "<div class='stat-grid'>";
            echo "<div class='stat-box'><div class='stat-num'>" . ($vCheck ? 'Active' : 'Missing') . "</div><div class='stat-label'>Vehicle Status</div></div>";
            echo "<div class='stat-box'><div class='stat-num'>{$loadCount}</div><div class='stat-label'>Loads Restored</div></div>";
            echo "<div class='stat-box'><div class='stat-num'>{$orderCount}</div><div class='stat-label'>Orders Restored</div></div>";
            echo "<div class='stat-box'><div class='stat-num'>{$receiptCount}</div><div class='stat-label'>Receipts Restored</div></div>";
            echo "</div>";

            echo "<div style='background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 14px; border-radius: 8px; margin-top: 24px; font-size: 14px;'>";
            echo "<strong>⚠️ Security Reminder:</strong> Please delete <code>restore_pickup_production.php</code> and <code>RESTORE_PICKUP_PRODUCTION.sql</code> from your live server now that restoration is complete.";
            echo "</div>";

        } catch (Exception $e) {
            echo "<div class='warning-box' style='background: #fef2f2; border-color: #fecaca; color: #991b1b;'>";
            echo "<strong>Restoration Error:</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
    }
    ?>
<?php endif; ?>

    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">
    <div style="font-size: 13px; color: #64748b;">
        <strong>Vehicle Profile:</strong> KA19AF9203 (Pickup) &bull; Drivers: Rouf, Ashraf (7899562794) &bull; Zone: 20 (BC -Adyar-Mangalore)
    </div>
</div>
</body>
</html>
