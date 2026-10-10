<?php
require_once dirname(__DIR__) . '/config/config.php';

try {
    // 1. Add access_code column to zones if not exists
    $cols = $pdo->query("SHOW COLUMNS FROM `zones`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('access_code', $cols)) {
        $pdo->exec("ALTER TABLE `zones` ADD COLUMN `access_code` VARCHAR(50) NULL AFTER `slug`");
        echo "Added access_code column to zones table.\n";
    } else {
        echo "access_code column already exists in zones.\n";
    }

    // Set sample/default zone access codes if null
    $pdo->exec("UPDATE `zones` SET `access_code` = 'ZONE2026' WHERE `access_code` IS NULL OR `access_code` = ''");

    // 2. Insert or update default zone_orders_access_code in system_settings
    $stmt = $pdo->prepare("SELECT setting_value FROM `system_settings` WHERE `setting_key` = 'zone_orders_access_code'");
    $stmt->execute();
    $currentVal = $stmt->fetchColumn();

    if ($currentVal === false) {
        $ins = $pdo->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES ('zone_orders_access_code', 'ZONE2026')");
        $ins->execute();
        echo "Inserted default zone_orders_access_code = ZONE2026 in system_settings.\n";
    } else {
        echo "zone_orders_access_code currently set to: " . $currentVal . "\n";
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
