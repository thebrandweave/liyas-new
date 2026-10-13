<?php
/**
 * Warehouse & Multi-Zone Database Auto-Migrator
 * 
 * Ensures all required tables and columns for the multi-zone warehouse
 * management system exist and are properly configured without manual SQL intervention.
 */

function ensureWarehouseSchema(PDO $pdo): bool {
    static $alreadyRun = false;
    if ($alreadyRun) {
        return true;
    }
    $alreadyRun = true;

    try {
        // ============================================
        // 1. ZONES TABLE
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `zones` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(150) NOT NULL,
                    `slug` VARCHAR(150) NOT NULL UNIQUE,
                    `access_code` VARCHAR(50) NULL,
                    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_zones_slug` (`slug`),
                    INDEX `idx_zones_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // Ensure access_code column exists in zones
            $zoneCols = $pdo->query("SHOW COLUMNS FROM `zones`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('access_code', $zoneCols)) {
                $pdo->exec("ALTER TABLE `zones` ADD COLUMN `access_code` VARCHAR(50) NULL AFTER `slug`");
            }

            // Seed initial zones if none exist
            $zoneCount = (int)$pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
            if ($zoneCount === 0) {
                $pdo->exec("
                    INSERT IGNORE INTO `zones` (`name`, `slug`, `access_code`, `status`) VALUES 
                        ('Mangalore Zone', 'mangalore', 'ZONE2026', 'active'),
                        ('Thokkottu Zone', 'thokkottu', 'ZONE2026', 'active'),
                        ('Vitla Zone', 'vitla', 'ZONE2026', 'active');
                ");
            }
        } catch (Exception $e) {
            error_log("Schema sync zones error: " . $e->getMessage());
        }

        // ============================================
        // 2. PRODUCTS TABLE COLUMNS
        // ============================================
        try {
            $prodCols = $pdo->query("SHOW COLUMNS FROM `products`")->fetchAll(PDO::FETCH_COLUMN);
            $prodColsMap = array_flip($prodCols);

            if (!isset($prodColsMap['product_name'])) {
                try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `product_name` VARCHAR(150) NULL AFTER `product_id`"); } catch (Exception $e) {}
            }
            if (!isset($prodColsMap['case_stock'])) {
                try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `case_stock` INT NOT NULL DEFAULT 0 AFTER `product_name`"); } catch (Exception $e) {}
            }
            if (!isset($prodColsMap['case_price'])) {
                try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `case_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `case_stock`"); } catch (Exception $e) {}
            }
            if (!isset($prodColsMap['net_content'])) {
                try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `net_content` DECIMAL(10,2) NULL AFTER `case_price`"); } catch (Exception $e) {}
            }
            if (!isset($prodColsMap['net_content_unit'])) {
                try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `net_content_unit` VARCHAR(10) NOT NULL DEFAULT 'ML' AFTER `net_content`"); } catch (Exception $e) {}
            }
            if (isset($prodColsMap['category_id'])) {
                try { $pdo->exec("ALTER TABLE `products` MODIFY COLUMN `category_id` INT NULL"); } catch (Exception $e) {}
            }

            try {
                $pdo->exec("UPDATE `products` SET 
                    `product_name` = COALESCE(NULLIF(`product_name`, ''), `name`),
                    `case_stock` = CASE WHEN `case_stock` = 0 THEN COALESCE(`stock`, 0) ELSE `case_stock` END,
                    `case_price` = CASE WHEN `case_price` = 0.00 THEN COALESCE(`price`, 0.00) ELSE `case_price` END
                    WHERE `product_name` IS NULL OR `product_name` = ''
                ");
            } catch (Exception $e) {}
        } catch (Exception $e) {
            error_log("Schema sync products error: " . $e->getMessage());
        }

        // ============================================
        // 3. ORDERS TABLE COLUMNS
        // ============================================
        try {
            $orderCols = $pdo->query("SHOW COLUMNS FROM `orders`")->fetchAll(PDO::FETCH_COLUMN);
            $orderColsMap = array_flip($orderCols);

            $orderColsToAdd = [
                'order_number'    => "VARCHAR(50) NULL AFTER `order_id`",
                'zone_id'         => "INT NULL AFTER `order_number`",
                'shop_name'       => "VARCHAR(255) NULL AFTER `zone_id`",
                'customer_name'   => "VARCHAR(255) NULL AFTER `shop_name`",
                'location'        => "VARCHAR(255) NULL AFTER `customer_name`",
                'phone'           => "VARCHAR(20) NULL AFTER `location`",
                'address'         => "TEXT NULL AFTER `phone`",
                'product_id'      => "INT NULL AFTER `address`",
                'quantity'        => "INT NOT NULL DEFAULT 1 AFTER `product_id`",
                'unit_price'      => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `quantity`",
                'discount'        => "DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`",
                'is_zone_read'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`",
                'zone_read_at'    => "DATETIME NULL AFTER `is_zone_read`",
                'is_reorder'      => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `zone_read_at`",
                'parent_order_id' => "INT NULL AFTER `is_reorder`",
                'vehicle_id'      => "INT NULL AFTER `parent_order_id`",
                'in_yard'         => "TINYINT(1) NOT NULL DEFAULT 1 AFTER `vehicle_id`"
            ];

            foreach ($orderColsToAdd as $colName => $colDef) {
                if (!isset($orderColsMap[$colName])) {
                    try {
                        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `{$colName}` {$colDef}");
                    } catch (Exception $e) {}
                }
            }

            if (isset($orderColsMap['user_id'])) {
                try { $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `user_id` INT NULL"); } catch (Exception $e) {}
            }
            if (isset($orderColsMap['shipping_address_id'])) {
                try { $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `shipping_address_id` INT NULL"); } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            error_log("Schema sync orders error: " . $e->getMessage());
        }

        // ============================================
        // 4. ORDER ITEMS TABLE (Guaranteed multi-product items)
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `order_items` (
                    `order_item_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `order_id` INT NOT NULL,
                    `product_id` INT NOT NULL,
                    `quantity` INT NOT NULL DEFAULT 1,
                    `price_at_purchase` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `is_extra` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_oi_order` (`order_id`),
                    INDEX `idx_oi_product` (`product_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $oiCols = $pdo->query("SHOW COLUMNS FROM `order_items`")->fetchAll(PDO::FETCH_COLUMN);
            $oiColsMap = array_flip($oiCols);
            if (!isset($oiColsMap['is_extra'])) {
                try { $pdo->exec("ALTER TABLE `order_items` ADD COLUMN `is_extra` TINYINT(1) NOT NULL DEFAULT 0 AFTER `price_at_purchase`"); } catch (Exception $e) {}
            }
            if (!isset($oiColsMap['price_at_purchase'])) {
                try { $pdo->exec("ALTER TABLE `order_items` ADD COLUMN `price_at_purchase` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `quantity`"); } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            error_log("Schema sync order_items error: " . $e->getMessage());
        }

        // ============================================
        // 5. RECEIPTS TABLE
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `receipts` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `order_id` INT NOT NULL,
                    `bill_number` VARCHAR(50) NOT NULL UNIQUE,
                    `delivered_quantity` INT NOT NULL DEFAULT 1,
                    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `cash_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `credited_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `due_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `payment_type` VARCHAR(50) NOT NULL DEFAULT 'cash',
                    `notes` TEXT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_receipts_order_id` (`order_id`),
                    INDEX `idx_receipts_bill_number` (`bill_number`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {
            error_log("Schema sync receipts error: " . $e->getMessage());
        }

        // ============================================
        // 6. ORDER PAYMENTS TABLE
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `order_payments` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `order_id` INT NOT NULL,
                    `cash_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `credited_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `due_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `payment_type` ENUM('cash', 'credit', 'split') NOT NULL DEFAULT 'cash',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_order_payments_order_id` (`order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {
            error_log("Schema sync order_payments error: " . $e->getMessage());
        }

        // ============================================
        // 7. REWARDS TABLE
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `rewards` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `shop_name` VARCHAR(255) NOT NULL,
                    `customer_phone` VARCHAR(20) NULL,
                    `completed_orders` INT NOT NULL DEFAULT 0,
                    `reward_threshold` INT NOT NULL DEFAULT 10,
                    `reward_status` ENUM('in_progress', 'eligible', 'claimed') NOT NULL DEFAULT 'in_progress',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_rewards_shop_name` (`shop_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {
            error_log("Schema sync rewards error: " . $e->getMessage());
        }

        // ============================================
        // 8. SYSTEM SETTINGS TABLE
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `system_settings` (
                    `setting_key` VARCHAR(100) PRIMARY KEY,
                    `setting_value` TEXT NULL,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $pdo->exec("
                INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
                    ('reward_threshold', '10'),
                    ('company_name', 'Liyas International'),
                    ('company_gstin', '29ABCDE1234F1Z5'),
                    ('company_phone', '+91 63663 78967'),
                    ('company_website', 'liyasinternational.com'),
                    ('company_address', 'Website, Mangalore, Karnataka'),
                    ('zone_orders_access_code', 'ZONE2026');
            ");
        } catch (Exception $e) {
            error_log("Schema sync system_settings error: " . $e->getMessage());
        }

        // ============================================
        // 9. VEHICLES TABLE
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `vehicles` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `vehicle_number` VARCHAR(50) NOT NULL UNIQUE,
                    `vehicle_name` VARCHAR(100) NULL,
                    `driver_name` VARCHAR(100) NULL,
                    `driver_phone` VARCHAR(20) NULL,
                    `zone_id` INT NULL,
                    `status` ENUM('active', 'loading', 'dispatched', 'inactive') NOT NULL DEFAULT 'active',
                    `capacity_cases` INT NOT NULL DEFAULT 0,
                    `total_deliveries_done` INT NOT NULL DEFAULT 0,
                    `total_loads_delivered` INT NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_vehicles_zone` (`zone_id`),
                    INDEX `idx_vehicles_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $vCols = $pdo->query("SHOW COLUMNS FROM `vehicles`")->fetchAll(PDO::FETCH_COLUMN);
            $vColsMap = array_flip($vCols);
            if (!isset($vColsMap['total_deliveries_done'])) {
                try { $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `total_deliveries_done` INT NOT NULL DEFAULT 0 AFTER `capacity_cases`"); } catch (Exception $e) {}
            }
            if (!isset($vColsMap['total_loads_delivered'])) {
                try { $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `total_loads_delivered` INT NOT NULL DEFAULT 0 AFTER `total_deliveries_done`"); } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            error_log("Schema sync vehicles error: " . $e->getMessage());
        }

        // ============================================
        // 10. VEHICLE LOADS TABLE (Guaranteed product_id column)
        // ============================================
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `vehicle_loads` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `vehicle_id` INT NOT NULL,
                    `zone_id` INT NULL,
                    `product_id` INT NULL,
                    `order_id` INT NULL,
                    `cases_loaded` INT NOT NULL DEFAULT 0,
                    `shop_name` VARCHAR(255) NULL,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `notes` VARCHAR(255) NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX `idx_vl_vehicle` (`vehicle_id`),
                    INDEX `idx_vl_zone` (`zone_id`),
                    INDEX `idx_vl_order` (`order_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $vlCols = $pdo->query("SHOW COLUMNS FROM `vehicle_loads`")->fetchAll(PDO::FETCH_COLUMN);
            $vlColsMap = array_flip($vlCols);
            if (!isset($vlColsMap['product_id'])) {
                try { $pdo->exec("ALTER TABLE `vehicle_loads` ADD COLUMN `product_id` INT NULL AFTER `zone_id`"); } catch (Exception $e) {}
            }
            if (!isset($vlColsMap['shop_name'])) {
                try { $pdo->exec("ALTER TABLE `vehicle_loads` ADD COLUMN `shop_name` VARCHAR(255) NULL AFTER `cases_loaded`"); } catch (Exception $e) {}
            }
            if (!isset($vlColsMap['is_active'])) {
                try { $pdo->exec("ALTER TABLE `vehicle_loads` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `shop_name`"); } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            error_log("Schema sync vehicle_loads error: " . $e->getMessage());
        }

        // ============================================
        // 11. PRIMARY KEY INTEGRITY CHECKER
        // Fixes phpMyAdmin "Current selection does not contain a unique column" warning
        // by ensuring all tables in the database have a valid PRIMARY KEY.
        // ============================================
        try {
            $allDbTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $knownPkMap = [
                'orders'                   => 'order_id',
                'order_items'              => 'order_item_id',
                'vehicle_loads'            => 'id',
                'vehicles'                 => 'id',
                'zones'                    => 'id',
                'receipts'                 => 'id',
                'order_payments'           => 'id',
                'rewards'                  => 'id',
                'products'                 => 'product_id',
                'categories'               => 'category_id',
                'cart_items'               => 'cart_item_id',
                'admins'                   => 'admin_id',
                'users'                    => 'user_id',
                'advertisements'           => 'ad_id',
                'notifications'            => 'notification_id',
                'reviews'                  => 'review_id',
                'social_links'             => 'social_id',
                'activity_logs'            => 'activity_id',
                'newsletter_subscriptions' => 'newsletter_id',
                'product_attributes'       => 'attribute_id',
                'products_media'           => 'media_id',
                'shipping_addresses'       => 'address_id',
                'admin_tokens'             => 'id',
                'codes'                    => 'id',
                'history'                  => 'id',
                'system_settings'          => 'setting_key'
            ];

            foreach ($allDbTables as $tbl) {
                try {
                    $pkExists = $pdo->query("SHOW KEYS FROM `{$tbl}` WHERE Key_name = 'PRIMARY'")->fetchAll();
                    if (empty($pkExists)) {
                        $cols = $pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN);
                        $targetCol = $knownPkMap[$tbl] ?? ($tbl . '_id');

                        if ($tbl === 'system_settings' && in_array('setting_key', $cols)) {
                            $pdo->exec("ALTER TABLE `{$tbl}` ADD PRIMARY KEY (`setting_key`)");
                        } elseif (in_array($targetCol, $cols)) {
                            $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `{$targetCol}` INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
                        } elseif (in_array('id', $cols)) {
                            $pdo->exec("ALTER TABLE `{$tbl}` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
                        } else {
                            $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                        }
                    }
                } catch (Exception $e) {
                    error_log("Schema sync PK fix error on table {$tbl}: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("Schema sync PK integrity error: " . $e->getMessage());
        }

        return true;
    } catch (Exception $e) {
        error_log("Warehouse schema sync failed: " . $e->getMessage());
        return false;
    }
}
