-- Migration for Liyas International Multi-Zone Warehouse Management System

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

-- Initial Zones
INSERT INTO `zones` (`name`, `slug`, `status`)
VALUES 
    ('Mangalore Zone', 'mangalore', 'active'),
    ('Thokkottu Zone', 'thokkottu', 'active'),
    ('Vitla Zone', 'vitla', 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Modify products table
ALTER TABLE `products` 
    ADD COLUMN IF NOT EXISTS `product_name` VARCHAR(150) NULL AFTER `product_id`,
    ADD COLUMN IF NOT EXISTS `case_stock` INT NOT NULL DEFAULT 0 AFTER `product_name`,
    ADD COLUMN IF NOT EXISTS `case_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `case_stock`,
    ADD COLUMN IF NOT EXISTS `net_content` DECIMAL(10,2) NULL AFTER `case_price`,
    ADD COLUMN IF NOT EXISTS `net_content_unit` VARCHAR(10) NOT NULL DEFAULT 'ML' AFTER `net_content`;

-- Sync existing products column values
UPDATE `products` SET 
    `product_name` = COALESCE(NULLIF(`product_name`, ''), `name`),
    `case_stock` = CASE WHEN `case_stock` = 0 THEN COALESCE(`stock`, 0) ELSE `case_stock` END,
    `case_price` = CASE WHEN `case_price` = 0.00 THEN COALESCE(`price`, 0.00) ELSE `case_price` END
WHERE `product_name` IS NULL OR `product_name` = '';

-- Make category_id nullable
ALTER TABLE `products` MODIFY COLUMN `category_id` INT NULL;

-- Insert / Update standard initial products
INSERT INTO `products` (`name`, `product_name`, `case_stock`, `case_price`, `net_content`, `net_content_unit`, `price`, `stock`, `status`)
VALUES 
    ('250ml', '250ml', 100, 250.00, 250, 'ML', 250.00, 100, 'active'),
    ('500ml', '500ml', 80, 300.00, 500, 'ML', 300.00, 80, 'active'),
    ('1 Litre', '1 Litre', 60, 350.00, 1, 'L', 350.00, 60, 'active'),
    ('2 Litre', '2 Litre', 40, 400.00, 2, 'L', 400.00, 40, 'active')
ON DUPLICATE KEY UPDATE 
    `case_stock` = VALUES(`case_stock`),
    `case_price` = VALUES(`case_price`),
    `net_content` = VALUES(`net_content`),
    `net_content_unit` = VALUES(`net_content_unit`);

-- Modify orders table to support warehouse order flow
ALTER TABLE `orders` 
    MODIFY COLUMN `user_id` INT NULL,
    MODIFY COLUMN `shipping_address_id` INT NULL,
    ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(50) NULL AFTER `order_id`,
    ADD COLUMN IF NOT EXISTS `zone_id` INT NULL AFTER `order_number`,
    ADD COLUMN IF NOT EXISTS `shop_name` VARCHAR(255) NULL AFTER `zone_id`,
    ADD COLUMN IF NOT EXISTS `customer_name` VARCHAR(255) NULL AFTER `shop_name`,
    ADD COLUMN IF NOT EXISTS `location` VARCHAR(255) NULL AFTER `customer_name`,
    ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) NULL AFTER `location`,
    ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone`,
    ADD COLUMN IF NOT EXISTS `product_id` INT NULL AFTER `address`,
    ADD COLUMN IF NOT EXISTS `quantity` INT NOT NULL DEFAULT 1 AFTER `product_id`,
    ADD COLUMN IF NOT EXISTS `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `quantity`,
    ADD COLUMN IF NOT EXISTS `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`,
    ADD COLUMN IF NOT EXISTS `is_zone_read` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- Order indexes
ALTER TABLE `orders` 
    ADD INDEX IF NOT EXISTS `idx_orders_zone_id` (`zone_id`),
    ADD INDEX IF NOT EXISTS `idx_orders_status` (`status`),
    ADD INDEX IF NOT EXISTS `idx_orders_created_at` (`created_at`),
    ADD INDEX IF NOT EXISTS `idx_orders_product_id` (`product_id`);

-- Order payments table
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

-- Receipts table
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

-- Rewards table
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

-- System settings table
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('reward_threshold', '10'),
    ('company_name', 'Liyas International'),
    ('company_gstin', '29ABCDE1234F1Z5'),
    ('company_phone', '+91 63663 78967'),
    ('company_website', 'liyasinternational.com'),
    ('company_address', 'Central Warehouse, Mangalore, Karnataka')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
