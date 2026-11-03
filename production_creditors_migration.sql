-- =====================================================================
-- LIYAS PRODUCTION DATABASE MIGRATION - SHOP CREDITORS PENDING AMOUNT
-- Total Creditor Shops: 147
-- Total Pending Amount: Rs. 357,847.00
-- Generated on: 2026-11-03 09:57:28
-- =====================================================================

-- STEP 1: Add pending_amount column & index if not already existing
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shops' AND column_name = 'pending_amount');
SET @sql_cmd = IF(@col_exists = 0, 'ALTER TABLE `shops` ADD COLUMN `pending_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `phone`', 'SELECT 1');
PREPARE stmt FROM @sql_cmd; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'shops' AND index_name = 'idx_shops_pending');
SET @sql_idx = IF(@idx_exists = 0, 'ALTER TABLE `shops` ADD INDEX `idx_shops_pending` (`pending_amount`)', 'SELECT 1');
PREPARE stmt FROM @sql_idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- STEP 2: Update existing shops or insert new creditor shops

-- 7 Bakes Deralakatte (Rs. 550.00)
UPDATE `shops` SET `pending_amount` = 550.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('7 Bakes Deralakatte'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT '7 Bakes Deralakatte', 'Deralakatte', '', 550.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('7 Bakes Deralakatte')));

-- 7 Bakes Mudipu (Rs. 260.00)
UPDATE `shops` SET `pending_amount` = 260.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('7 Bakes Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT '7 Bakes Mudipu', 'Mudipu', '', 260.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('7 Bakes Mudipu')));

-- A H Sweets (Rs. 2490.00)
UPDATE `shops` SET `pending_amount` = 2490.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A H Sweets'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'A H Sweets', 'Madaka', '', 2490.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A H Sweets')));

-- A R Hotel (Rs. 3730.00)
UPDATE `shops` SET `pending_amount` = 3730.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A R Hotel'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'A R Hotel', 'Ullal', '', 3730.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A R Hotel')));

-- A.N.A Super Bazar (Rs. 3880.00)
UPDATE `shops` SET `pending_amount` = 3880.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A.N.A Super Bazar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'A.N.A Super Bazar', 'Manjanady', '', 3880.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A.N.A Super Bazar')));

-- Abbobakkar shop montepadav (Rs. 110.00)
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Abbobakkar shop montepadav'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Abbobakkar shop montepadav', 'Montepadav', '', 110.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Abbobakkar shop montepadav')));

-- Aishwarya Bakery kallapu (Rs. 1850.00)
UPDATE `shops` SET `pending_amount` = 1850.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Aishwarya Bakery kallapu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Aishwarya Bakery kallapu', 'Kallapu', '', 1850.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Aishwarya Bakery kallapu')));

-- Ashfaq store kasaba (Rs. 600.00)
UPDATE `shops` SET `pending_amount` = 600.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashfaq store kasaba'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ashfaq store kasaba', 'Kasaba bengere', '', 600.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashfaq store kasaba')));

-- Ashraf Liyas (Rs. 1150.00)
UPDATE `shops` SET `pending_amount` = 1150.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf Liyas'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ashraf Liyas', '', '', 1150.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf Liyas')));

-- Ashraf store (Rs. 1700.00)
UPDATE `shops` SET `pending_amount` = 1700.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ashraf store', 'Kudroli', '', 1700.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf store')));

-- Ayyangar Bakery Kankanady (Rs. 400.00)
UPDATE `shops` SET `pending_amount` = 400.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ayyangar Bakery Kankanady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ayyangar Bakery Kankanady', 'Kankanady', '', 400.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ayyangar Bakery Kankanady')));

-- Babas Resto (Rs. 540.00)
UPDATE `shops` SET `pending_amount` = 540.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Babas Resto'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Babas Resto', 'Manjeshwar', '', 540.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Babas Resto')));

-- Bake Berry Café (Rs. 260.00)
UPDATE `shops` SET `pending_amount` = 260.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bake Berry Café'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Bake Berry Café', 'Thokkottu', '', 260.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bake Berry Café')));

-- Bhagavathi general store (Rs. 550.00)
UPDATE `shops` SET `pending_amount` = 550.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bhagavathi general store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Bhagavathi general store', 'Talapady', '', 550.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bhagavathi general store')));

-- Bharath Bakery (Rs. 5670.00)
UPDATE `shops` SET `pending_amount` = 5670.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Bharath Bakery', 'Benkye Sajipa', '', 5670.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Bakery')));

-- Bharath Super Bazar Manjeshwar (Rs. 110.00)
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Super Bazar Manjeshwar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Bharath Super Bazar Manjeshwar', 'Manjeshwar', '', 110.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Super Bazar Manjeshwar')));

-- Break time (Rs. 18000.00)
UPDATE `shops` SET `pending_amount` = 18000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Break time'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Break time', 'Puthur', '', 18000.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Break time')));

-- Buns Hotel K C Road (Rs. 390.00)
UPDATE `shops` SET `pending_amount` = 390.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Buns Hotel K C Road'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Buns Hotel K C Road', 'K C Road', '', 390.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Buns Hotel K C Road')));

-- C.M Bakery Mudipu (Rs. 770.00)
UPDATE `shops` SET `pending_amount` = 770.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C.M Bakery Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'C.M Bakery Mudipu', 'Mudipu', '', 770.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C.M Bakery Mudipu')));

-- C.M Store Gerukatte (Rs. 1260.00)
UPDATE `shops` SET `pending_amount` = 1260.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C.M Store Gerukatte'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'C.M Store Gerukatte', 'Gerukatte', '', 1260.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C.M Store Gerukatte')));

-- C/O Bathish (Rs. 1200.00)
UPDATE `shops` SET `pending_amount` = 1200.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C/O Bathish'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'C/O Bathish', '', '', 1200.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C/O Bathish')));

-- Café Just Bake Ullal (Rs. 650.00)
UPDATE `shops` SET `pending_amount` = 650.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Café Just Bake Ullal'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Café Just Bake Ullal', 'Ullal', '', 650.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Café Just Bake Ullal')));

-- Café Point Pumpwell (Rs. 2500.00)
UPDATE `shops` SET `pending_amount` = 2500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Café Point Pumpwell'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Café Point Pumpwell', 'Pumpwell', '', 2500.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Café Point Pumpwell')));

-- Charmuri Point Adyar (Rs. 1200.00)
UPDATE `shops` SET `pending_amount` = 1200.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuri Point Adyar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Charmuri Point Adyar', 'Adyar', '', 1200.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuri Point Adyar')));

-- Charmuri Point Nitte (Rs. 270.00)
UPDATE `shops` SET `pending_amount` = 270.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuri Point Nitte'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Charmuri Point Nitte', 'Nitte', '', 270.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuri Point Nitte')));

-- Charmuru point Kanachur (Rs. 1105.00)
UPDATE `shops` SET `pending_amount` = 1105.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuru point Kanachur'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Charmuru point Kanachur', 'Kanachur', '', 1105.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuru point Kanachur')));

-- Chat N Chill (Rs. 2965.00)
UPDATE `shops` SET `pending_amount` = 2965.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Chat N Chill'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Chat N Chill', 'Hosangady', '', 2965.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Chat N Chill')));

-- Chatti Kanji (Rs. 1275.00)
UPDATE `shops` SET `pending_amount` = 1275.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Chatti Kanji'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Chatti Kanji', 'Golden spoon', '', 1275.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Chatti Kanji')));

-- Cream Cafe (Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Cream Cafe'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Cream Cafe', 'Mudipu', '', 250.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Cream Cafe')));

-- Crunchys Café (Rs. 720.00)
UPDATE `shops` SET `pending_amount` = 720.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Crunchys Café'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Crunchys Café', 'Vittla', '', 720.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Crunchys Café')));

-- Davood Mudipu (Rs. 3132.00)
UPDATE `shops` SET `pending_amount` = 3132.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Davood Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Davood Mudipu', 'Mudipu', '', 3132.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Davood Mudipu')));

-- Day Fresh (Rs. 6440.00)
UPDATE `shops` SET `pending_amount` = 6440.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Day Fresh'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Day Fresh', 'Salethur', '', 6440.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Day Fresh')));

-- Dream Sports Academy (Rs. 2965.00)
UPDATE `shops` SET `pending_amount` = 2965.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Dream Sports Academy'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Dream Sports Academy', 'Vittal', '', 2965.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Dream Sports Academy')));

-- Eat Bay Manjeshwar (Rs. 6030.00)
UPDATE `shops` SET `pending_amount` = 6030.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Eat Bay Manjeshwar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Eat Bay Manjeshwar', 'Manjeshwar', '', 6030.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Eat Bay Manjeshwar')));

-- F F store (Rs. 520.00)
UPDATE `shops` SET `pending_amount` = 520.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('F F store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'F F store', 'Kasaba bengere', '', 520.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('F F store')));

-- F S sweets (Rs. 5860.00)
UPDATE `shops` SET `pending_amount` = 5860.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('F S sweets'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'F S sweets', 'Kasaba bengere', '', 5860.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('F S sweets')));

-- Famous Bakery (Rs. 5000.00)
UPDATE `shops` SET `pending_amount` = 5000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Famous Bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Famous Bakery', 'Pumpwell', '', 5000.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Famous Bakery')));

-- Famous Cafe (Rs. 1425.00)
UPDATE `shops` SET `pending_amount` = 1425.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Famous Cafe'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Famous Cafe', 'Kanachur', '', 1425.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Famous Cafe')));

-- Fathima mini supermarket (Rs. 1415.00)
UPDATE `shops` SET `pending_amount` = 1415.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fathima mini supermarket'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Fathima mini supermarket', 'Harekala', '', 1415.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fathima mini supermarket')));

-- Fayaz Customer (Rs. 8090.00)
UPDATE `shops` SET `pending_amount` = 8090.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fayaz Customer'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Fayaz Customer', '', '', 8090.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fayaz Customer')));

-- Feel leban (Rs. 1585.00)
UPDATE `shops` SET `pending_amount` = 1585.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel leban'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Feel leban', 'Yenepoya', '', 1585.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel leban')));

-- Feel Leban Hosangady (Rs. 1955.00)
UPDATE `shops` SET `pending_amount` = 1955.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel Leban Hosangady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Feel Leban Hosangady', 'Hosangady', '', 1955.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel Leban Hosangady')));

-- Fidha Bakes Hosangady (Rs. 1100.00)
UPDATE `shops` SET `pending_amount` = 1100.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fidha Bakes Hosangady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Fidha Bakes Hosangady', 'Hosangady', '', 1100.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fidha Bakes Hosangady')));

-- Fish Land (Rs. 2040.00)
UPDATE `shops` SET `pending_amount` = 2040.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fish Land'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Fish Land', 'Kankandy', '', 2040.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fish Land')));

-- Fish World (Rs. 2025.00)
UPDATE `shops` SET `pending_amount` = 2025.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fish World'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Fish World', 'Kodialbail Mangalore', '', 2025.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fish World')));

-- food catters ullal (Rs. 9200.00)
UPDATE `shops` SET `pending_amount` = 9200.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('food catters ullal'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'food catters ullal', 'Ullal', '', 9200.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('food catters ullal')));

-- Food Court (Rs. 1190.00)
UPDATE `shops` SET `pending_amount` = 1190.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Food Court'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Food Court', 'Kankanady', '', 1190.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Food Court')));

-- Food point (Rs. 1280.00)
UPDATE `shops` SET `pending_amount` = 1280.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Food point'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Food point', 'Kanachur', '', 1280.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Food point')));

-- Foodies kitchen Sajipa (Rs. 290.00)
UPDATE `shops` SET `pending_amount` = 290.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Foodies kitchen Sajipa'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Foodies kitchen Sajipa', 'Sajipa', '', 290.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Foodies kitchen Sajipa')));

-- Fruits Shope Majbooth (Rs. 780.00)
UPDATE `shops` SET `pending_amount` = 780.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fruits Shope Majbooth'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Fruits Shope Majbooth', 'Yenepoya', '', 780.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fruits Shope Majbooth')));

-- Ganesh store (Rs. 1590.00)
UPDATE `shops` SET `pending_amount` = 1590.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ganesh store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ganesh store', 'Yenepoya', '', 1590.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ganesh store')));

-- Garden shop (Rs. 740.00)
UPDATE `shops` SET `pending_amount` = 740.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Garden shop'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Garden shop', 'Thokkotu', '', 740.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Garden shop')));

-- Gray Lane Kitchen Talapady (Rs. 2110.00)
UPDATE `shops` SET `pending_amount` = 2110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Gray Lane Kitchen Talapady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Gray Lane Kitchen Talapady', 'Talapady', '', 2110.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Gray Lane Kitchen Talapady')));

-- H Z Store (Rs. 560.00)
UPDATE `shops` SET `pending_amount` = 560.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('H Z Store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'H Z Store', 'K C Road', '', 560.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('H Z Store')));

-- Hameed Liyas (Rs. 1440.00)
UPDATE `shops` SET `pending_amount` = 1440.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hameed Liyas'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Hameed Liyas', 'Ira', '', 1440.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hameed Liyas')));

-- Hashtag restaurant (Rs. 795.00)
UPDATE `shops` SET `pending_amount` = 795.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hashtag restaurant'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Hashtag restaurant', 'Kanachur', '', 795.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hashtag restaurant')));

-- Herfi Restaurant (Rs. 915.00)
UPDATE `shops` SET `pending_amount` = 915.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Herfi Restaurant'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Herfi Restaurant', 'Thumbe', '', 915.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Herfi Restaurant')));

-- HM Book Hive (Rs. 5810.00)
UPDATE `shops` SET `pending_amount` = 5810.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('HM Book Hive'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'HM Book Hive', 'Nitte', '', 5810.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('HM Book Hive')));

-- Hotel Darbar (Rs. 1950.00)
UPDATE `shops` SET `pending_amount` = 1950.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Darbar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Hotel Darbar', 'Kallapu', '', 1950.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Darbar')));

-- Hotel Hira (Rs. 4500.00)
UPDATE `shops` SET `pending_amount` = 4500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Hira'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Hotel Hira', 'Deralakatte', '', 4500.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Hira')));

-- Hotel Swagath & General store (Rs. 1450.00)
UPDATE `shops` SET `pending_amount` = 1450.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Swagath & General store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Hotel Swagath & General store', 'Inoli', '', 1450.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Swagath & General store')));

-- Imthiyaz Customer (Rs. 2124.00)
UPDATE `shops` SET `pending_amount` = 2124.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Imthiyaz Customer'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Imthiyaz Customer', 'Ukkuda Masjid', '', 2124.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Imthiyaz Customer')));

-- Juice corner (Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Juice corner'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Juice corner', 'Thokkotu', '', 500.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Juice corner')));

-- Junaid Customer (Rs. 1753.00)
UPDATE `shops` SET `pending_amount` = 1753.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Junaid Customer'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Junaid Customer', '', '', 1753.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Junaid Customer')));

-- K G N Mart (Rs. 1253.00)
UPDATE `shops` SET `pending_amount` = 1253.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('K G N Mart'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'K G N Mart', 'Mudipu', '', 1253.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('K G N Mart')));

-- K M Dine Hotel (Rs. 1500.00)
UPDATE `shops` SET `pending_amount` = 1500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('K M Dine Hotel'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'K M Dine Hotel', 'K C Road', '', 1500.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('K M Dine Hotel')));

-- Kallapu Medical (Rs. 1199.00)
UPDATE `shops` SET `pending_amount` = 1199.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Kallapu Medical'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Kallapu Medical', 'Kallapu', '', 1199.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Kallapu Medical')));

-- Karavali Bkery Malar (Rs. 2756.00)
UPDATE `shops` SET `pending_amount` = 2756.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Karavali Bkery Malar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Karavali Bkery Malar', 'Malar', '', 2756.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Karavali Bkery Malar')));

-- Khadaraka Canteen Kuthar (Rs. 490.00)
UPDATE `shops` SET `pending_amount` = 490.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Khadaraka Canteen Kuthar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Khadaraka Canteen Kuthar', 'Kuthar', '', 490.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Khadaraka Canteen Kuthar')));

-- Kulukki Bazar (Rs. 1000.00)
UPDATE `shops` SET `pending_amount` = 1000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Kulukki Bazar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Kulukki Bazar', 'Thokkottu', '', 1000.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Kulukki Bazar')));

-- Liyas Furniture Thirthahalli (Rs. 2030.00)
UPDATE `shops` SET `pending_amount` = 2030.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Liyas Furniture Thirthahalli'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Liyas Furniture Thirthahalli', 'Thirthahalli', '', 2030.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Liyas Furniture Thirthahalli')));

-- M A Stall (Rs. 480.00)
UPDATE `shops` SET `pending_amount` = 480.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M A Stall'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'M A Stall', 'Yenepoya', '', 480.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M A Stall')));

-- M K Super Bazar (Rs. 2455.00)
UPDATE `shops` SET `pending_amount` = 2455.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M K Super Bazar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'M K Super Bazar', 'Manchikatte', '', 2455.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M K Super Bazar')));

-- M R bakes (Rs. 630.00)
UPDATE `shops` SET `pending_amount` = 630.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M R bakes'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'M R bakes', 'Gerukatte', '', 630.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M R bakes')));

-- M S Store (Rs. 5340.00)
UPDATE `shops` SET `pending_amount` = 5340.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M S Store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'M S Store', 'Maripalla', '', 5340.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M S Store')));

-- M.charmuri point Thokkottu (Rs. 385.00)
UPDATE `shops` SET `pending_amount` = 385.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M.charmuri point Thokkottu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'M.charmuri point Thokkottu', 'Thokkotu', '', 385.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M.charmuri point Thokkottu')));

-- M.M Bazar Thumbe (Rs. 900.00)
UPDATE `shops` SET `pending_amount` = 900.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M.M Bazar Thumbe'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'M.M Bazar Thumbe', 'Thumbe', '', 900.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M.M Bazar Thumbe')));

-- Malabar Hot Chips Kaikamba (Rs. 1950.00)
UPDATE `shops` SET `pending_amount` = 1950.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Malabar Hot Chips Kaikamba'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Malabar Hot Chips Kaikamba', 'Kaikamba', '', 1950.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Malabar Hot Chips Kaikamba')));

-- Mallika Store (Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mallika Store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Mallika Store', 'Someshwara', '', 250.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mallika Store')));

-- Manasa Bar (Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Manasa Bar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Manasa Bar', 'Pumpwell', '', 500.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Manasa Bar')));

-- Mangala Genereal store jeppu (Rs. 815.00)
UPDATE `shops` SET `pending_amount` = 815.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala Genereal store jeppu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Mangala Genereal store jeppu', 'Jeppu', '', 815.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala Genereal store jeppu')));

-- Mangala shop (Rs. 2420.00)
UPDATE `shops` SET `pending_amount` = 2420.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala shop'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Mangala shop', 'Fathima beach', '', 2420.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala shop')));

-- Mangalore Sweets (Rs. 685.00)
UPDATE `shops` SET `pending_amount` = 685.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangalore Sweets'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Mangalore Sweets', 'Thokkottu', '', 685.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangalore Sweets')));

-- Menpadavu cafeteria (Rs. 650.00)
UPDATE `shops` SET `pending_amount` = 650.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Menpadavu cafeteria'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Menpadavu cafeteria', 'Puthur', '', 650.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Menpadavu cafeteria')));

-- Metro market (Rs. 2000.00)
UPDATE `shops` SET `pending_amount` = 2000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Metro market'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Metro market', 'Salethur', '', 2000.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Metro market')));

-- MFC Falneer (Rs. 3125.00)
UPDATE `shops` SET `pending_amount` = 3125.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('MFC Falneer'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'MFC Falneer', 'Falneer', '', 3125.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('MFC Falneer')));

-- Mohan Canteen (Rs. 110.00)
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mohan Canteen'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Mohan Canteen', 'Yenepoya', '', 110.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mohan Canteen')));

-- Mukkachery store (Rs. 390.00)
UPDATE `shops` SET `pending_amount` = 390.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mukkachery store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Mukkachery store', 'Ullal', '', 390.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mukkachery store')));

-- N.K.A Traders (Rs. 7875.00)
UPDATE `shops` SET `pending_amount` = 7875.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('N.K.A Traders'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'N.K.A Traders', 'KUDROLI', '', 7875.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('N.K.A Traders')));

-- Naizee Avilmilk (Rs. 795.00)
UPDATE `shops` SET `pending_amount` = 795.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Naizee Avilmilk'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Naizee Avilmilk', 'Mithabail', '', 795.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Naizee Avilmilk')));

-- National Bakery (Rs. 390.00)
UPDATE `shops` SET `pending_amount` = 390.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'National Bakery', 'K C Road', '', 390.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery')));

-- National Bakery Mukkacheri (Rs. 780.00)
UPDATE `shops` SET `pending_amount` = 780.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery Mukkacheri'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'National Bakery Mukkacheri', 'Mukkacheri', '', 780.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery Mukkacheri')));

-- National traders (Rs. 1784.00)
UPDATE `shops` SET `pending_amount` = 1784.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National traders'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'National traders', 'Mukkacheri', '', 1784.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National traders')));

-- Natural Juice (Rs. 710.00)
UPDATE `shops` SET `pending_amount` = 710.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Natural Juice'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Natural Juice', 'Thokkottu', '', 710.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Natural Juice')));

-- Nefisa kitchen sambarthota (Rs. 240.00)
UPDATE `shops` SET `pending_amount` = 240.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Nefisa kitchen sambarthota'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Nefisa kitchen sambarthota', 'Sambarthota', '', 240.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Nefisa kitchen sambarthota')));

-- New Bengaluru Ayyangar Bakery Attavara (Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('New Bengaluru Ayyangar Bakery Attavara'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'New Bengaluru Ayyangar Bakery Attavara', 'Attavara', '', 500.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('New Bengaluru Ayyangar Bakery Attavara')));

-- PACE Café (Rs. 895.00)
UPDATE `shops` SET `pending_amount` = 895.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('PACE Café'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'PACE Café', 'PA College', '', 895.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('PACE Café')));

-- Pintos General store (Rs. 1975.00)
UPDATE `shops` SET `pending_amount` = 1975.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Pintos General store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Pintos General store', 'Kankanady', '', 1975.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Pintos General store')));

-- quality bakery and cool drinks (Rs. 2000.00)
UPDATE `shops` SET `pending_amount` = 2000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('quality bakery and cool drinks'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'quality bakery and cool drinks', 'Puthur', '', 2000.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('quality bakery and cool drinks')));

-- R J general store (Rs. 400.00)
UPDATE `shops` SET `pending_amount` = 400.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('R J general store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'R J general store', 'Kasaba bengere', '', 400.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('R J general store')));

-- R M B madeena supermarket (Rs. 465.00)
UPDATE `shops` SET `pending_amount` = 465.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('R M B madeena supermarket'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'R M B madeena supermarket', 'kalladka', '', 465.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('R M B madeena supermarket')));

-- Rahman Thattukada Mudipu (Rs. 494.00)
UPDATE `shops` SET `pending_amount` = 494.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rahman Thattukada Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Rahman Thattukada Mudipu', 'Mudipu', '', 494.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rahman Thattukada Mudipu')));

-- Rahmaniya Store (Rs. 615.00)
UPDATE `shops` SET `pending_amount` = 615.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rahmaniya Store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Rahmaniya Store', 'Gerukatte', '', 615.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rahmaniya Store')));

-- Ranjith Uppinangady (Rs. 38310.00)
UPDATE `shops` SET `pending_amount` = 38310.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ranjith Uppinangady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ranjith Uppinangady', 'Uppinangady', '', 38310.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ranjith Uppinangady')));

-- Razi hotel (Rs. 1080.00)
UPDATE `shops` SET `pending_amount` = 1080.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Razi hotel'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Razi hotel', 'Bengere', '', 1080.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Razi hotel')));

-- Rida Canteen Deralakatte (Rs. 110.00)
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rida Canteen Deralakatte'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Rida Canteen Deralakatte', 'Deralakatte', '', 110.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rida Canteen Deralakatte')));

-- Rifayi Store Harekala (Rs. 585.00)
UPDATE `shops` SET `pending_amount` = 585.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rifayi Store Harekala'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Rifayi Store Harekala', 'Harekala', '', 585.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rifayi Store Harekala')));

-- Royal Restaurant Mangalore (Rs. 4025.00)
UPDATE `shops` SET `pending_amount` = 4025.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Royal Restaurant Mangalore'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Royal Restaurant Mangalore', 'Mangalore', '', 4025.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Royal Restaurant Mangalore')));

-- Rumans Live snacks (Rs. 360.00)
UPDATE `shops` SET `pending_amount` = 360.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rumans Live snacks'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Rumans Live snacks', 'Kanachur', '', 360.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rumans Live snacks')));

-- S K bakery (Rs. 1370.00)
UPDATE `shops` SET `pending_amount` = 1370.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S K bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'S K bakery', 'Kasaba bengere', '', 1370.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S K bakery')));

-- S M Mahammad Mudipu (Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Mahammad Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'S M Mahammad Mudipu', 'Mudipu', '', 500.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Mahammad Mudipu')));

-- S M Silicon Nitte (Rs. 1170.00)
UPDATE `shops` SET `pending_amount` = 1170.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Silicon Nitte'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'S M Silicon Nitte', 'Nitte', '', 1170.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Silicon Nitte')));

-- S R store (Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S R store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'S R store', 'Bengere', '', 500.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S R store')));

-- S.L.J Iyyangar Bakery Valencia (Rs. 765.00)
UPDATE `shops` SET `pending_amount` = 765.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S.L.J Iyyangar Bakery Valencia'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'S.L.J Iyyangar Bakery Valencia', 'Valencia', '', 765.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S.L.J Iyyangar Bakery Valencia')));

-- Salma Bakery (Rs. 60988.00)
UPDATE `shops` SET `pending_amount` = 60988.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Salma Bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Salma Bakery', 'Natekal', '', 60988.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Salma Bakery')));

-- Sameer store (Rs. 560.00)
UPDATE `shops` SET `pending_amount` = 560.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sameer store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Sameer store', 'K C Nagar', '', 560.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sameer store')));

-- Sathco bakery (Rs. 400.00)
UPDATE `shops` SET `pending_amount` = 400.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sathco bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Sathco bakery', 'Malar', '', 400.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sathco bakery')));

-- SB Bakery (Rs. 770.00)
UPDATE `shops` SET `pending_amount` = 770.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('SB Bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'SB Bakery', 'Thokkotu', '', 770.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('SB Bakery')));

-- Shakthi Traders(wholesale) (Rs. 2830.00)
UPDATE `shops` SET `pending_amount` = 2830.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shakthi Traders(wholesale)'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shakthi Traders(wholesale)', 'Mangalore', '', 2830.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shakthi Traders(wholesale)')));

-- Sham Juice P A (Rs. 270.00)
UPDATE `shops` SET `pending_amount` = 270.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sham Juice P A'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Sham Juice P A', 'PA College', '', 270.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sham Juice P A')));

-- Shameer Sir (Rs. 575.00)
UPDATE `shops` SET `pending_amount` = 575.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shameer Sir'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shameer Sir', '', '', 575.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shameer Sir')));

-- Shareef Canteen (Rs. 14.00)
UPDATE `shops` SET `pending_amount` = 14.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shareef Canteen'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shareef Canteen', '', '', 14.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shareef Canteen')));

-- Shettys Canteen (Rs. 785.00)
UPDATE `shops` SET `pending_amount` = 785.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shettys Canteen'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shettys Canteen', 'Yenepoya', '', 785.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shettys Canteen')));

-- Shiva shakthi general store (Rs. 1035.00)
UPDATE `shops` SET `pending_amount` = 1035.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shiva shakthi general store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shiva shakthi general store', 'Someshwara', '', 1035.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shiva shakthi general store')));

-- Shree durga sweets (Rs. 2775.00)
UPDATE `shops` SET `pending_amount` = 2775.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree durga sweets'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shree durga sweets', 'Puthur', '', 2775.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree durga sweets')));

-- Shree Kateel Hotel kankanady (Rs. 1365.00)
UPDATE `shops` SET `pending_amount` = 1365.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Kateel Hotel kankanady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shree Kateel Hotel kankanady', 'Kankanady', '', 1365.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Kateel Hotel kankanady')));

-- Shree Santeri Ullal (Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Santeri Ullal'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shree Santeri Ullal', 'Ullal', '', 250.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Santeri Ullal')));

-- Shree Shantheri Store (Rs. 135.00)
UPDATE `shops` SET `pending_amount` = 135.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Shantheri Store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Shree Shantheri Store', 'ULLAL', '', 135.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Shantheri Store')));

-- SMK Fruits General store (Rs. 555.00)
UPDATE `shops` SET `pending_amount` = 555.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('SMK Fruits General store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'SMK Fruits General store', 'Sajipa', '', 555.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('SMK Fruits General store')));

-- Star One Bakery (Rs. 245.00)
UPDATE `shops` SET `pending_amount` = 245.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Star One Bakery'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Star One Bakery', 'Gramachavadi', '', 245.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Star One Bakery')));

-- Super Duper Jyothi (Rs. 1000.00)
UPDATE `shops` SET `pending_amount` = 1000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Super Duper Jyothi'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Super Duper Jyothi', 'Jyothi', '', 1000.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Super Duper Jyothi')));

-- Surya Amber (Rs. 4455.00)
UPDATE `shops` SET `pending_amount` = 4455.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Surya Amber'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Surya Amber', 'Nitte', '', 4455.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Surya Amber')));

-- Sweet Land (Rs. 1000.00)
UPDATE `shops` SET `pending_amount` = 1000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sweet Land'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Sweet Land', 'Derlakatte', '', 1000.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sweet Land')));

-- T.H Sweets & Fruits (Rs. 1665.00)
UPDATE `shops` SET `pending_amount` = 1665.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('T.H Sweets & Fruits'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'T.H Sweets & Fruits', 'Sajipa', '', 1665.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('T.H Sweets & Fruits')));

-- Taj Canteen Mudipu (Rs. 11730.00)
UPDATE `shops` SET `pending_amount` = 11730.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taj Canteen Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Taj Canteen Mudipu', 'Mudipu', '', 11730.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taj Canteen Mudipu')));

-- Talakala super bazar hosangady (Rs. 1440.00)
UPDATE `shops` SET `pending_amount` = 1440.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Talakala super bazar hosangady'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Talakala super bazar hosangady', 'Hosangady', '', 1440.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Talakala super bazar hosangady')));

-- Talapady toll stall (Rs. 1120.00)
UPDATE `shops` SET `pending_amount` = 1120.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Talapady toll stall'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Talapady toll stall', 'Talapady', '', 1120.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Talapady toll stall')));

-- Taqwa Mudipu (Rs. 2750.00)
UPDATE `shops` SET `pending_amount` = 2750.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taqwa Mudipu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Taqwa Mudipu', 'Mudipu', '', 2750.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taqwa Mudipu')));

-- Thalassery cafe (Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Thalassery cafe'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Thalassery cafe', 'Kankanady', '', 250.00, 20, 13, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Thalassery cafe')));

-- Thattukada (Rs. 1080.00)
UPDATE `shops` SET `pending_amount` = 1080.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Thattukada'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Thattukada', 'Golden spoon', '', 1080.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Thattukada')));

-- U.P Bawa Traders , Kallapu (Rs. 515.00)
UPDATE `shops` SET `pending_amount` = 515.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('U.P Bawa Traders , Kallapu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'U.P Bawa Traders , Kallapu', 'Kallapu', '', 515.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('U.P Bawa Traders , Kallapu')));

-- Ullal Canteen (Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ullal Canteen'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Ullal Canteen', 'Ullal', '', 500.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ullal Canteen')));

-- Umar store (Rs. 1270.00)
UPDATE `shops` SET `pending_amount` = 1270.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Umar store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Umar store', 'Bokkapatna', '', 1270.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Umar store')));

-- Vittla bazar (Rs. 4600.00)
UPDATE `shops` SET `pending_amount` = 4600.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Vittla bazar'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Vittla bazar', 'Vittal', '', 4600.00, 22, 12, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Vittla bazar')));

-- Waves resto cafe (Rs. 1800.00)
UPDATE `shops` SET `pending_amount` = 1800.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Waves resto cafe'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Waves resto cafe', 'Fathima beach', '', 1800.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Waves resto cafe')));

-- YEM FIVE General store (Rs. 880.00)
UPDATE `shops` SET `pending_amount` = 880.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('YEM FIVE General store'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'YEM FIVE General store', 'Valachil', '', 880.00, NULL, NULL, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('YEM FIVE General store')));

-- Zam Zam Canteen Kallapu (Rs. 1270.00)
UPDATE `shops` SET `pending_amount` = 1270.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Zam Zam Canteen Kallapu'));
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`) SELECT 'Zam Zam Canteen Kallapu', 'Kallapu', '', 1270.00, 21, 11, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Zam Zam Canteen Kallapu')));
