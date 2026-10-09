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

    // Skip if already confirmed in session
    if (!empty($_SESSION['warehouse_schema_v2'])) {
        return true;
    }

    try {
        // Quick verification: check if zones table and products.case_stock exist
        $hasZones = false;
        try {
            $hasZones = (bool)$pdo->query("SHOW TABLES LIKE 'zones'")->fetchColumn();
        } catch (Exception $e) {}

        $hasCaseStock = false;
        if ($hasZones) {
            try {
                $hasCaseStock = (bool)$pdo->query("SHOW COLUMNS FROM products LIKE 'case_stock'")->fetchColumn();
            } catch (Exception $e) {}
        }

        // If both exist, check orders.zone_id as well
        $hasZoneId = false;
        if ($hasCaseStock) {
            try {
                $hasZoneId = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'zone_id'")->fetchColumn();
            } catch (Exception $e) {}
        }

        if ($hasZones && $hasCaseStock && $hasZoneId) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['warehouse_schema_v2'] = true;
            }
            return true;
        }

        // ============================================
        // 1. ZONES TABLE
        // ============================================
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `zones` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL,
                `slug` VARCHAR(150) NOT NULL UNIQUE,
                `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_zones_slug` (`slug`),
                INDEX `idx_zones_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed initial zones if none exist
        $zoneCount = (int)$pdo->query("SELECT COUNT(*) FROM zones")->fetchColumn();
        if ($zoneCount === 0) {
            $pdo->exec("
                INSERT IGNORE INTO `zones` (`name`, `slug`, `status`) VALUES 
                    ('Mangalore Zone', 'mangalore', 'active'),
                    ('Thokkottu Zone', 'thokkottu', 'active'),
                    ('Vitla Zone', 'vitla', 'active');
            ");
        }

        // ============================================
        // 2. PRODUCTS TABLE COLUMNS
        // ============================================
        $prodCols = $pdo->query("SHOW COLUMNS FROM `products`")->fetchAll(PDO::FETCH_COLUMN);
        $prodColsMap = array_flip($prodCols);

        $prodAlters = [];
        if (!isset($prodColsMap['product_name'])) {
            $prodAlters[] = "ADD COLUMN `product_name` VARCHAR(150) NULL AFTER `product_id`";
        }
        if (!isset($prodColsMap['case_stock'])) {
            $prodAlters[] = "ADD COLUMN `case_stock` INT NOT NULL DEFAULT 0 AFTER `product_name`";
        }
        if (!isset($prodColsMap['case_price'])) {
            $prodAlters[] = "ADD COLUMN `case_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `case_stock`";
        }
        if (!isset($prodColsMap['net_content'])) {
            $prodAlters[] = "ADD COLUMN `net_content` DECIMAL(10,2) NULL AFTER `case_price`";
        }
        if (!isset($prodColsMap['net_content_unit'])) {
            $prodAlters[] = "ADD COLUMN `net_content_unit` VARCHAR(10) NOT NULL DEFAULT 'ML' AFTER `net_content`";
        }

        if (!empty($prodAlters)) {
            $pdo->exec("ALTER TABLE `products` " . implode(", ", $prodAlters));
            try {
                $pdo->exec("UPDATE `products` SET 
                    `product_name` = COALESCE(NULLIF(`product_name`, ''), `name`),
                    `case_stock` = CASE WHEN `case_stock` = 0 THEN COALESCE(`stock`, 0) ELSE `case_stock` END,
                    `case_price` = CASE WHEN `case_price` = 0.00 THEN COALESCE(`price`, 0.00) ELSE `case_price` END
                    WHERE `product_name` IS NULL OR `product_name` = ''
                ");
            } catch (Exception $e) {}
        }

        // Make category_id nullable if it exists
        if (isset($prodColsMap['category_id'])) {
            try {
                $pdo->exec("ALTER TABLE `products` MODIFY COLUMN `category_id` INT NULL");
            } catch (Exception $e) {}
        }

        // ============================================
        // 3. ORDERS TABLE COLUMNS
        // ============================================
        $orderCols = $pdo->query("SHOW COLUMNS FROM `orders`")->fetchAll(PDO::FETCH_COLUMN);
        $orderColsMap = array_flip($orderCols);

        $orderAlters = [];
        if (!isset($orderColsMap['order_number'])) {
            $orderAlters[] = "ADD COLUMN `order_number` VARCHAR(50) NULL AFTER `order_id`";
        }
        if (!isset($orderColsMap['zone_id'])) {
            $orderAlters[] = "ADD COLUMN `zone_id` INT NULL AFTER `order_number`";
        }
        if (!isset($orderColsMap['shop_name'])) {
            $orderAlters[] = "ADD COLUMN `shop_name` VARCHAR(255) NULL AFTER `zone_id`";
        }
        if (!isset($orderColsMap['customer_name'])) {
            $orderAlters[] = "ADD COLUMN `customer_name` VARCHAR(255) NULL AFTER `shop_name`";
        }
        if (!isset($orderColsMap['location'])) {
            $orderAlters[] = "ADD COLUMN `location` VARCHAR(255) NULL AFTER `customer_name`";
        }
        if (!isset($orderColsMap['phone'])) {
            $orderAlters[] = "ADD COLUMN `phone` VARCHAR(20) NULL AFTER `location`";
        }
        if (!isset($orderColsMap['address'])) {
            $orderAlters[] = "ADD COLUMN `address` TEXT NULL AFTER `phone`";
        }
        if (!isset($orderColsMap['product_id'])) {
            $orderAlters[] = "ADD COLUMN `product_id` INT NULL AFTER `address`";
        }
        if (!isset($orderColsMap['quantity'])) {
            $orderAlters[] = "ADD COLUMN `quantity` INT NOT NULL DEFAULT 1 AFTER `product_id`";
        }
        if (!isset($orderColsMap['unit_price'])) {
            $orderAlters[] = "ADD COLUMN `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `quantity`";
        }
        if (!isset($orderColsMap['discount'])) {
            $orderAlters[] = "ADD COLUMN `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`";
        }
        if (!isset($orderColsMap['is_zone_read'])) {
            $orderAlters[] = "ADD COLUMN `is_zone_read` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`";
        }
        if (!isset($orderColsMap['zone_read_at'])) {
            $orderAlters[] = "ADD COLUMN `zone_read_at` DATETIME NULL AFTER `is_zone_read`";
        }

        if (!empty($orderAlters)) {
            $pdo->exec("ALTER TABLE `orders` " . implode(", ", $orderAlters));
        }

        // Allow null for user_id and shipping_address_id
        if (isset($orderColsMap['user_id'])) {
            try { $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `user_id` INT NULL"); } catch (Exception $e) {}
        }
        if (isset($orderColsMap['shipping_address_id'])) {
            try { $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `shipping_address_id` INT NULL"); } catch (Exception $e) {}
        }

        // ============================================
        // 4. RECEIPTS TABLE
        // ============================================
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

        // ============================================
        // 5. ORDER PAYMENTS TABLE
        // ============================================
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

        // ============================================
        // 6. REWARDS TABLE
        // ============================================
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

        // ============================================
        // 7. SYSTEM SETTINGS TABLE
        // ============================================
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
                ('company_address', 'Central Warehouse, Mangalore, Karnataka');
        ");

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['warehouse_schema_v2'] = true;
        }

        return true;
    } catch (PDOException $e) {
        error_log("Warehouse schema sync failed: " . $e->getMessage());
        return false;
    }
}
