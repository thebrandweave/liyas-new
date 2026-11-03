-- =============================================================================
-- LIYAS PRODUCTION SQL QUERY: ADD SHOPS & PENDING DUES
-- Total Creditor Shops : 147 (113 Existing Updated + 34 New Inserted)
-- Total Outstanding Dues: Rs. 357,847.00
-- =============================================================================

-- STEP 1: CREATE COLUMN & INDEX IF NOT EXISTS (Compatible with all MySQL/MariaDB versions)
SET @dbname = DATABASE();
SET @tablename = 'shops';
SET @colname = 'pending_amount';
SET @sqlCol = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @colname) > 0, 'SELECT 1', 'ALTER TABLE `shops` ADD COLUMN `pending_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `phone`'));
PREPARE stmtCol FROM @sqlCol; EXECUTE stmtCol; DEALLOCATE PREPARE stmtCol;

SET @idxname = 'idx_shops_pending';
SET @sqlIdx = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @idxname) > 0, 'SELECT 1', 'ALTER TABLE `shops` ADD INDEX `idx_shops_pending` (`pending_amount`)'));
PREPARE stmtIdx FROM @sqlIdx; EXECUTE stmtIdx; DEALLOCATE PREPARE stmtIdx;

-- =============================================================================
-- STEP 2: UPDATE PENDING DUES FOR EXISTING SHOPS (113 SHOPS)
-- =============================================================================

-- ID 4: Hashtag restaurant (Loc: Kanachur, Due: Rs. 795.00)
UPDATE `shops` SET `pending_amount` = 795.00 WHERE `id` = 4 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hashtag restaurant'));

-- ID 14: Feel leban (Loc: Yenepoya, Due: Rs. 1585.00)
UPDATE `shops` SET `pending_amount` = 1585.00 WHERE `id` = 14 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel leban'));

-- ID 22: Shree Shantheri Store (Loc: ULLAL, Due: Rs. 135.00)
UPDATE `shops` SET `pending_amount` = 135.00 WHERE `id` = 22 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Shantheri Store'));

-- ID 23: Juice corner (Loc: Thokkotu, Due: Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE `id` = 23 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Juice corner'));

-- ID 26: Charmuru point Kanachur (Loc: Kanachur, Due: Rs. 1105.00)
UPDATE `shops` SET `pending_amount` = 1105.00 WHERE `id` = 26 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuru point Kanachur'));

-- ID 29: Rumans Live snacks (Loc: Kanachur, Due: Rs. 360.00)
UPDATE `shops` SET `pending_amount` = 360.00 WHERE `id` = 29 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rumans Live snacks'));

-- ID 33: Ganesh store (Loc: Yenepoya, Due: Rs. 1590.00)
UPDATE `shops` SET `pending_amount` = 1590.00 WHERE `id` = 33 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ganesh store'));

-- ID 40: SB Bakery (Loc: Thokkotu, Due: Rs. 770.00)
UPDATE `shops` SET `pending_amount` = 770.00 WHERE `id` = 40 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('SB Bakery'));

-- ID 43: Garden shop (Loc: Thokkotu, Due: Rs. 740.00)
UPDATE `shops` SET `pending_amount` = 740.00 WHERE `id` = 43 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Garden shop'));

-- ID 46: Thalassery cafe (Loc: Kankanady, Due: Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE `id` = 46 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Thalassery cafe'));

-- ID 75: National traders (Loc: Mukkacheri, Due: Rs. 1784.00)
UPDATE `shops` SET `pending_amount` = 1784.00 WHERE `id` = 75 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National traders'));

-- ID 78: M.charmuri point Thokkottu (Loc: Thokkotu, Due: Rs. 385.00)
UPDATE `shops` SET `pending_amount` = 385.00 WHERE `id` = 78 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M.charmuri point Thokkottu'));

-- ID 92: Bake Berry Café (Loc: Thokkottu, Due: Rs. 260.00)
UPDATE `shops` SET `pending_amount` = 260.00 WHERE `id` = 92 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bake Berry Café'));

-- ID 96: Rahmaniya Store (Loc: Gerukatte, Due: Rs. 615.00)
UPDATE `shops` SET `pending_amount` = 615.00 WHERE `id` = 96 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rahmaniya Store'));

-- ID 110: A H Sweets (Loc: Madaka, Due: Rs. 2490.00)
UPDATE `shops` SET `pending_amount` = 2490.00 WHERE `id` = 110 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A H Sweets'));

-- ID 129: Ullal Canteen (Loc: Ullal, Due: Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE `id` = 129 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ullal Canteen'));

-- ID 140: SMK Fruits General store (Loc: Sajipa, Due: Rs. 555.00)
UPDATE `shops` SET `pending_amount` = 555.00 WHERE `id` = 140 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('SMK Fruits General store'));

-- ID 152: Herfi Restaurant (Loc: Thumbe, Due: Rs. 915.00)
UPDATE `shops` SET `pending_amount` = 915.00 WHERE `id` = 152 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Herfi Restaurant'));

-- ID 158: Food Court (Loc: Kankanady, Due: Rs. 1190.00)
UPDATE `shops` SET `pending_amount` = 1190.00 WHERE `id` = 158 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Food Court'));

-- ID 160: Ayyangar Bakery Kankanady (Loc: Kankanady, Due: Rs. 400.00)
UPDATE `shops` SET `pending_amount` = 400.00 WHERE `id` = 160 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ayyangar Bakery Kankanady'));

-- ID 163: Surya Amber (Loc: Nitte, Due: Rs. 4455.00)
UPDATE `shops` SET `pending_amount` = 4455.00 WHERE `id` = 163 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Surya Amber'));

-- ID 168: K M Dine Hotel (Loc: K C Road, Due: Rs. 1500.00)
UPDATE `shops` SET `pending_amount` = 1500.00 WHERE `id` = 168 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('K M Dine Hotel'));

-- ID 169: National Bakery (Loc: K C Road, Due: Rs. 390.00)
UPDATE `shops` SET `pending_amount` = 390.00 WHERE `id` = 169 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery'));

-- ID 173: Gray Lane Kitchen Talapady (Loc: Talapady, Due: Rs. 2110.00)
UPDATE `shops` SET `pending_amount` = 2110.00 WHERE `id` = 173 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Gray Lane Kitchen Talapady'));

-- ID 177: A R Hotel (Loc: Ullal, Due: Rs. 3730.00)
UPDATE `shops` SET `pending_amount` = 3730.00 WHERE `id` = 177 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A R Hotel'));

-- ID 187: Mangalore Sweets (Loc: Thokkottu, Due: Rs. 685.00)
UPDATE `shops` SET `pending_amount` = 685.00 WHERE `id` = 187 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangalore Sweets'));

-- ID 197: Kulukki Bazar (Loc: Thokkottu, Due: Rs. 1000.00)
UPDATE `shops` SET `pending_amount` = 1000.00 WHERE `id` = 197 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Kulukki Bazar'));

-- ID 204: Naizee Avilmilk (Loc: Mithabail, Due: Rs. 795.00)
UPDATE `shops` SET `pending_amount` = 795.00 WHERE `id` = 204 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Naizee Avilmilk'));

-- ID 211: Foodies kitchen Sajipa (Loc: Sajipa, Due: Rs. 290.00)
UPDATE `shops` SET `pending_amount` = 290.00 WHERE `id` = 211 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Foodies kitchen Sajipa'));

-- ID 223: Manasa Bar (Loc: Pumpwell, Due: Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE `id` = 223 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Manasa Bar'));

-- ID 258: Mallika Store (Loc: Someshwara, Due: Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE `id` = 258 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mallika Store'));

-- ID 260: Café Just Bake Ullal (Loc: Ullal, Due: Rs. 650.00)
UPDATE `shops` SET `pending_amount` = 650.00 WHERE `id` = 260 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Café Just Bake Ullal'));

-- ID 263: Pintos General store (Loc: Kankanady, Due: Rs. 1975.00)
UPDATE `shops` SET `pending_amount` = 1975.00 WHERE `id` = 263 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Pintos General store'));

-- ID 275: Sameer store (Loc: K C Nagar, Due: Rs. 560.00)
UPDATE `shops` SET `pending_amount` = 560.00 WHERE `id` = 275 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sameer store'));

-- ID 281: Mukkachery store (Loc: Ullal, Due: Rs. 390.00)
UPDATE `shops` SET `pending_amount` = 390.00 WHERE `id` = 281 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mukkachery store'));

-- ID 288: Salma Bakery (Loc: Natekal, Due: Rs. 60988.00)
UPDATE `shops` SET `pending_amount` = 60988.00 WHERE `id` = 288 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Salma Bakery'));

-- ID 297: T.H Sweets & Fruits (Loc: Sajipa, Due: Rs. 1665.00)
UPDATE `shops` SET `pending_amount` = 1665.00 WHERE `id` = 297 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('T.H Sweets & Fruits'));

-- ID 301: Bharath Bakery (Loc: Benkye Sajipa, Due: Rs. 5670.00)
UPDATE `shops` SET `pending_amount` = 5670.00 WHERE `id` = 301 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Bakery'));

-- ID 311: M.M Bazar Thumbe (Loc: Thumbe, Due: Rs. 900.00)
UPDATE `shops` SET `pending_amount` = 900.00 WHERE `id` = 311 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M.M Bazar Thumbe'));

-- ID 313: Famous Bakery (Loc: Pumpwell, Due: Rs. 5000.00)
UPDATE `shops` SET `pending_amount` = 5000.00 WHERE `id` = 313 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Famous Bakery'));

-- ID 329: Kallapu Medical (Loc: Kallapu, Due: Rs. 1199.00)
UPDATE `shops` SET `pending_amount` = 1199.00 WHERE `id` = 329 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Kallapu Medical'));

-- ID 330: Thattukada (Loc: Golden spoon, Due: Rs. 1080.00)
UPDATE `shops` SET `pending_amount` = 1080.00 WHERE `id` = 330 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Thattukada'));

-- ID 341: C.M Bakery Mudipu (Loc: Mudipu, Due: Rs. 770.00)
UPDATE `shops` SET `pending_amount` = 770.00 WHERE `id` = 341 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C.M Bakery Mudipu'));

-- ID 348: Rahman Thattukada Mudipu (Loc: Mudipu, Due: Rs. 494.00)
UPDATE `shops` SET `pending_amount` = 494.00 WHERE `id` = 348 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rahman Thattukada Mudipu'));

-- ID 349: Taj Canteen Mudipu (Loc: Mudipu, Due: Rs. 11730.00)
UPDATE `shops` SET `pending_amount` = 11730.00 WHERE `id` = 349 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taj Canteen Mudipu'));

-- ID 357: PACE Café (Loc: PA College, Due: Rs. 895.00)
UPDATE `shops` SET `pending_amount` = 895.00 WHERE `id` = 357 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('PACE Café'));

-- ID 361: Dream Sports Academy (Loc: Vittal, Due: Rs. 2965.00)
UPDATE `shops` SET `pending_amount` = 2965.00 WHERE `id` = 361 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Dream Sports Academy'));

-- ID 367: Natural Juice (Loc: Thokkottu, Due: Rs. 710.00)
UPDATE `shops` SET `pending_amount` = 710.00 WHERE `id` = 367 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Natural Juice'));

-- ID 378: Chat N Chill (Loc: Hosangady, Due: Rs. 2965.00)
UPDATE `shops` SET `pending_amount` = 2965.00 WHERE `id` = 378 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Chat N Chill'));

-- ID 397: Star One Bakery (Loc: Gramachavadi, Due: Rs. 245.00)
UPDATE `shops` SET `pending_amount` = 245.00 WHERE `id` = 397 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Star One Bakery'));

-- ID 411: S.L.J Iyyangar Bakery Valencia (Loc: Valencia, Due: Rs. 765.00)
UPDATE `shops` SET `pending_amount` = 765.00 WHERE `id` = 411 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S.L.J Iyyangar Bakery Valencia'));

-- ID 420: Mohan Canteen (Loc: Yenepoya, Due: Rs. 110.00)
UPDATE `shops` SET `pending_amount` = 110.00 WHERE `id` = 420 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mohan Canteen'));

-- ID 421: Shettys Canteen (Loc: Yenepoya, Due: Rs. 785.00)
UPDATE `shops` SET `pending_amount` = 785.00 WHERE `id` = 421 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shettys Canteen'));

-- ID 437: M S Store (Loc: Maripalla, Due: Rs. 5340.00)
UPDATE `shops` SET `pending_amount` = 5340.00 WHERE `id` = 437 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M S Store'));

-- ID 439: YEM FIVE General store (Loc: Valachil, Due: Rs. 880.00)
UPDATE `shops` SET `pending_amount` = 880.00 WHERE `id` = 439 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('YEM FIVE General store'));

-- ID 442: 7 Bakes Mudipu (Loc: Mudipu, Due: Rs. 260.00)
UPDATE `shops` SET `pending_amount` = 260.00 WHERE `id` = 442 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('7 Bakes Mudipu'));

-- ID 448: Crunchys Café (Loc: Vittla, Due: Rs. 720.00)
UPDATE `shops` SET `pending_amount` = 720.00 WHERE `id` = 448 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Crunchys Café'));

-- ID 452: H Z Store (Loc: K C Road, Due: Rs. 560.00)
UPDATE `shops` SET `pending_amount` = 560.00 WHERE `id` = 452 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('H Z Store'));

-- ID 457: Café Point Pumpwell (Loc: Pumpwell, Due: Rs. 2500.00)
UPDATE `shops` SET `pending_amount` = 2500.00 WHERE `id` = 457 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Café Point Pumpwell'));

-- ID 471: Charmuri Point Nitte (Loc: Nitte, Due: Rs. 270.00)
UPDATE `shops` SET `pending_amount` = 270.00 WHERE `id` = 471 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuri Point Nitte'));

-- ID 491: Hotel Swagath & General store (Loc: Inoli, Due: Rs. 1450.00)
UPDATE `shops` SET `pending_amount` = 1450.00 WHERE `id` = 491 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Swagath & General store'));

-- ID 494: C.M Store Gerukatte (Loc: Gerukatte, Due: Rs. 1260.00)
UPDATE `shops` SET `pending_amount` = 1260.00 WHERE `id` = 494 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C.M Store Gerukatte'));

-- ID 502: Zam Zam Canteen Kallapu (Loc: Kallapu, Due: Rs. 1270.00)
UPDATE `shops` SET `pending_amount` = 1270.00 WHERE `id` = 502 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Zam Zam Canteen Kallapu'));

-- ID 507: Charmuri Point Adyar (Loc: Adyar, Due: Rs. 1200.00)
UPDATE `shops` SET `pending_amount` = 1200.00 WHERE `id` = 507 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Charmuri Point Adyar'));

-- ID 508: HM Book Hive (Loc: Nitte, Due: Rs. 5810.00)
UPDATE `shops` SET `pending_amount` = 5810.00 WHERE `id` = 508 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('HM Book Hive'));

-- ID 514: Babas Resto (Loc: Manjeshwar, Due: Rs. 540.00)
UPDATE `shops` SET `pending_amount` = 540.00 WHERE `id` = 514 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Babas Resto'));

-- ID 527: New Bengaluru Ayyangar Bakery Attavara (Loc: Attavara, Due: Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE `id` = 527 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('New Bengaluru Ayyangar Bakery Attavara'));

-- ID 546: Umar store (Loc: Bokkapatna, Due: Rs. 1270.00)
UPDATE `shops` SET `pending_amount` = 1270.00 WHERE `id` = 546 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Umar store'));

-- ID 547: N.K.A Traders (Loc: KUDROLI, Due: Rs. 7875.00)
UPDATE `shops` SET `pending_amount` = 7875.00 WHERE `id` = 547 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('N.K.A Traders'));

-- ID 576: K G N Mart (Loc: Mudipu, Due: Rs. 1253.00)
UPDATE `shops` SET `pending_amount` = 1253.00 WHERE `id` = 576 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('K G N Mart'));

-- ID 580: Hotel Hira (Loc: Deralakatte, Due: Rs. 4500.00)
UPDATE `shops` SET `pending_amount` = 4500.00 WHERE `id` = 580 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Hira'));

-- ID 585: 7 Bakes Deralakatte (Loc: Deralakatte, Due: Rs. 550.00)
UPDATE `shops` SET `pending_amount` = 550.00 WHERE `id` = 585 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('7 Bakes Deralakatte'));

-- ID 587: Sweet Land (Loc: Derlakatte, Due: Rs. 1000.00)
UPDATE `shops` SET `pending_amount` = 1000.00 WHERE `id` = 587 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sweet Land'));

-- ID 589: Fish World (Loc: Kodialbail Mangalore, Due: Rs. 2025.00)
UPDATE `shops` SET `pending_amount` = 2025.00 WHERE `id` = 589 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fish World'));

-- ID 601: Cream Cafe (Loc: Mudipu, Due: Rs. 250.00)
UPDATE `shops` SET `pending_amount` = 250.00 WHERE `id` = 601 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Cream Cafe'));

-- ID 603: Famous Cafe (Loc: Kanachur, Due: Rs. 1425.00)
UPDATE `shops` SET `pending_amount` = 1425.00 WHERE `id` = 603 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Famous Cafe'));

-- ID 615: Fruits Shope Majbooth (Loc: Yenepoya, Due: Rs. 780.00)
UPDATE `shops` SET `pending_amount` = 780.00 WHERE `id` = 615 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fruits Shope Majbooth'));

-- ID 617: Eat Bay Manjeshwar (Loc: Manjeshwar, Due: Rs. 6030.00)
UPDATE `shops` SET `pending_amount` = 6030.00 WHERE `id` = 617 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Eat Bay Manjeshwar'));

-- ID 627: Hotel Darbar (Loc: Kallapu, Due: Rs. 1950.00)
UPDATE `shops` SET `pending_amount` = 1950.00 WHERE `id` = 627 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hotel Darbar'));

-- ID 629: Buns Hotel K C Road (Loc: K C Road, Due: Rs. 390.00)
UPDATE `shops` SET `pending_amount` = 390.00 WHERE `id` = 629 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Buns Hotel K C Road'));

-- ID 633: Sathco bakery (Loc: Malar, Due: Rs. 400.00)
UPDATE `shops` SET `pending_amount` = 400.00 WHERE `id` = 633 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sathco bakery'));

-- ID 635: M A Stall (Loc: Yenepoya, Due: Rs. 480.00)
UPDATE `shops` SET `pending_amount` = 480.00 WHERE `id` = 635 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M A Stall'));

-- ID 640: M R bakes (Loc: Gerukatte, Due: Rs. 630.00)
UPDATE `shops` SET `pending_amount` = 630.00 WHERE `id` = 640 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M R bakes'));

-- ID 648: Imthiyaz Customer (Loc: Ukkuda Masjid, Due: Rs. 2124.00)
UPDATE `shops` SET `pending_amount` = 2124.00 WHERE `id` = 648 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Imthiyaz Customer'));

-- ID 654: quality bakery and cool drinks (Loc: Puthur, Due: Rs. 2000.00)
UPDATE `shops` SET `pending_amount` = 2000.00 WHERE `id` = 654 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('quality bakery and cool drinks'));

-- ID 656: Break time (Loc: Puthur, Due: Rs. 18000.00)
UPDATE `shops` SET `pending_amount` = 18000.00 WHERE `id` = 656 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Break time'));

-- ID 672: Food point (Loc: Kanachur, Due: Rs. 1280.00)
UPDATE `shops` SET `pending_amount` = 1280.00 WHERE `id` = 672 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Food point'));

-- ID 689: R M B madeena supermarket (Loc: kalladka, Due: Rs. 465.00)
UPDATE `shops` SET `pending_amount` = 465.00 WHERE `id` = 689 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('R M B madeena supermarket'));

-- ID 708: Fathima mini supermarket (Loc: Harekala, Due: Rs. 1415.00)
UPDATE `shops` SET `pending_amount` = 1415.00 WHERE `id` = 708 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fathima mini supermarket'));

-- ID 716: Ashraf store (Loc: Kudroli, Due: Rs. 1700.00)
UPDATE `shops` SET `pending_amount` = 1700.00 WHERE `id` = 716 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf store'));

-- ID 718: F F store (Loc: Kasaba bengere, Due: Rs. 520.00)
UPDATE `shops` SET `pending_amount` = 520.00 WHERE `id` = 718 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('F F store'));

-- ID 726: R J general store (Loc: Kasaba bengere, Due: Rs. 400.00)
UPDATE `shops` SET `pending_amount` = 400.00 WHERE `id` = 726 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('R J general store'));

-- ID 727: F S sweets (Loc: Kasaba bengere, Due: Rs. 5860.00)
UPDATE `shops` SET `pending_amount` = 5860.00 WHERE `id` = 727 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('F S sweets'));

-- ID 740: Metro market (Loc: Salethur, Due: Rs. 2000.00)
UPDATE `shops` SET `pending_amount` = 2000.00 WHERE `id` = 740 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Metro market'));

-- ID 745: Talapady toll stall (Loc: Talapady, Due: Rs. 1120.00)
UPDATE `shops` SET `pending_amount` = 1120.00 WHERE `id` = 745 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Talapady toll stall'));

-- ID 746: Bhagavathi general store (Loc: Talapady, Due: Rs. 550.00)
UPDATE `shops` SET `pending_amount` = 550.00 WHERE `id` = 746 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bhagavathi general store'));

-- ID 756: Mangala shop (Loc: Fathima beach, Due: Rs. 2420.00)
UPDATE `shops` SET `pending_amount` = 2420.00 WHERE `id` = 756 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala shop'));

-- ID 757: Waves resto cafe (Loc: Fathima beach, Due: Rs. 1800.00)
UPDATE `shops` SET `pending_amount` = 1800.00 WHERE `id` = 757 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Waves resto cafe'));

-- ID 764: M K Super Bazar (Loc: Manchikatte, Due: Rs. 2455.00)
UPDATE `shops` SET `pending_amount` = 2455.00 WHERE `id` = 764 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('M K Super Bazar'));

-- ID 776: Shree durga sweets (Loc: Puthur, Due: Rs. 2775.00)
UPDATE `shops` SET `pending_amount` = 2775.00 WHERE `id` = 776 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree durga sweets'));

-- ID 777: Menpadavu cafeteria (Loc: Puthur, Due: Rs. 650.00)
UPDATE `shops` SET `pending_amount` = 650.00 WHERE `id` = 777 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Menpadavu cafeteria'));

-- ID 778: Vittla bazar (Loc: Vittal, Due: Rs. 4600.00)
UPDATE `shops` SET `pending_amount` = 4600.00 WHERE `id` = 778 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Vittla bazar'));

-- ID 780: Fish Land (Loc: Kankandy, Due: Rs. 2040.00)
UPDATE `shops` SET `pending_amount` = 2040.00 WHERE `id` = 780 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fish Land'));

-- ID 794: S R store (Loc: Bengere, Due: Rs. 500.00)
UPDATE `shops` SET `pending_amount` = 500.00 WHERE `id` = 794 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S R store'));

-- ID 822: Shiva shakthi general store (Loc: Someshwara, Due: Rs. 1035.00)
UPDATE `shops` SET `pending_amount` = 1035.00 WHERE `id` = 822 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shiva shakthi general store'));

-- ID 853: Talakala super bazar hosangady (Loc: Hosangady, Due: Rs. 1440.00)
UPDATE `shops` SET `pending_amount` = 1440.00 WHERE `id` = 853 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Talakala super bazar hosangady'));

-- ID 859: Razi hotel (Loc: Bengere, Due: Rs. 1080.00)
UPDATE `shops` SET `pending_amount` = 1080.00 WHERE `id` = 859 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Razi hotel'));

-- ID 871: Chatti Kanji (Loc: Golden spoon, Due: Rs. 1275.00)
UPDATE `shops` SET `pending_amount` = 1275.00 WHERE `id` = 871 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Chatti Kanji'));

-- ID 887: S K bakery (Loc: Kasaba bengere, Due: Rs. 1370.00)
UPDATE `shops` SET `pending_amount` = 1370.00 WHERE `id` = 887 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S K bakery'));

-- ID 911: Hameed Liyas (Loc: Ira, Due: Rs. 1440.00)
UPDATE `shops` SET `pending_amount` = 1440.00 WHERE `id` = 911 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Hameed Liyas'));

-- ID 912: Day Fresh (Loc: Salethur, Due: Rs. 6440.00)
UPDATE `shops` SET `pending_amount` = 6440.00 WHERE `id` = 912 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Day Fresh'));

-- ID 916: food catters ullal (Loc: Ullal, Due: Rs. 9200.00)
UPDATE `shops` SET `pending_amount` = 9200.00 WHERE `id` = 916 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('food catters ullal'));

-- ID 927: A.N.A Super Bazar (Loc: Manjanady, Due: Rs. 3880.00)
UPDATE `shops` SET `pending_amount` = 3880.00 WHERE `id` = 927 OR TRIM(LOWER(`shop_name`)) = TRIM(LOWER('A.N.A Super Bazar'));

-- =============================================================================
-- STEP 3: INSERT NEW CREDITOR SHOPS (34 SHOPS)
-- (Inserts if shop doesn't exist, or updates pending_amount if already registered)
-- =============================================================================

-- Shakthi Traders(wholesale) (Loc: Mangalore, Due: Rs. 2830.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Shakthi Traders(wholesale)', 'Mangalore', '', 2830.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shakthi Traders(wholesale)')));
UPDATE `shops` SET `pending_amount` = 2830.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shakthi Traders(wholesale)'));

-- C/O Bathish (Due: Rs. 1200.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'C/O Bathish', '', '', 1200.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C/O Bathish')));
UPDATE `shops` SET `pending_amount` = 1200.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('C/O Bathish'));

-- Shareef Canteen (Due: Rs. 14.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Shareef Canteen', '', '', 14.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shareef Canteen')));
UPDATE `shops` SET `pending_amount` = 14.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shareef Canteen'));

-- Ranjith Uppinangady (Loc: Uppinangady, Due: Rs. 38310.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Ranjith Uppinangady', 'Uppinangady', '', 38310.00, 22, 12, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ranjith Uppinangady')));
UPDATE `shops` SET `pending_amount` = 38310.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ranjith Uppinangady'));

-- Shameer Sir (Due: Rs. 575.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Shameer Sir', '', '', 575.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shameer Sir')));
UPDATE `shops` SET `pending_amount` = 575.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shameer Sir'));

-- MFC Falneer (Loc: Falneer, Due: Rs. 3125.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'MFC Falneer', 'Falneer', '', 3125.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('MFC Falneer')));
UPDATE `shops` SET `pending_amount` = 3125.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('MFC Falneer'));

-- Liyas Furniture Thirthahalli (Loc: Thirthahalli, Due: Rs. 2030.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Liyas Furniture Thirthahalli', 'Thirthahalli', '', 2030.00, 22, 12, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Liyas Furniture Thirthahalli')));
UPDATE `shops` SET `pending_amount` = 2030.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Liyas Furniture Thirthahalli'));

-- Khadaraka Canteen Kuthar (Loc: Kuthar, Due: Rs. 490.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Khadaraka Canteen Kuthar', 'Kuthar', '', 490.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Khadaraka Canteen Kuthar')));
UPDATE `shops` SET `pending_amount` = 490.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Khadaraka Canteen Kuthar'));

-- Junaid Customer (Due: Rs. 1753.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Junaid Customer', '', '', 1753.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Junaid Customer')));
UPDATE `shops` SET `pending_amount` = 1753.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Junaid Customer'));

-- S M Mahammad Mudipu (Loc: Mudipu, Due: Rs. 500.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'S M Mahammad Mudipu', 'Mudipu', '', 500.00, 22, 12, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Mahammad Mudipu')));
UPDATE `shops` SET `pending_amount` = 500.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Mahammad Mudipu'));

-- Karavali Bkery Malar (Loc: Malar, Due: Rs. 2756.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Karavali Bkery Malar', 'Malar', '', 2756.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Karavali Bkery Malar')));
UPDATE `shops` SET `pending_amount` = 2756.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Karavali Bkery Malar'));

-- Feel Leban Hosangady (Loc: Hosangady, Due: Rs. 1955.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Feel Leban Hosangady', 'Hosangady', '', 1955.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel Leban Hosangady')));
UPDATE `shops` SET `pending_amount` = 1955.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Feel Leban Hosangady'));

-- Sham Juice P A (Loc: PA College, Due: Rs. 270.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Sham Juice P A', 'PA College', '', 270.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sham Juice P A')));
UPDATE `shops` SET `pending_amount` = 270.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Sham Juice P A'));

-- Fidha Bakes Hosangady (Loc: Hosangady, Due: Rs. 1100.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Fidha Bakes Hosangady', 'Hosangady', '', 1100.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fidha Bakes Hosangady')));
UPDATE `shops` SET `pending_amount` = 1100.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fidha Bakes Hosangady'));

-- Davood Mudipu (Loc: Mudipu, Due: Rs. 3132.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Davood Mudipu', 'Mudipu', '', 3132.00, 22, 12, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Davood Mudipu')));
UPDATE `shops` SET `pending_amount` = 3132.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Davood Mudipu'));

-- Ashraf Liyas (Due: Rs. 1150.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Ashraf Liyas', '', '', 1150.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf Liyas')));
UPDATE `shops` SET `pending_amount` = 1150.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashraf Liyas'));

-- Taqwa Mudipu (Loc: Mudipu, Due: Rs. 2750.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Taqwa Mudipu', 'Mudipu', '', 2750.00, 22, 12, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taqwa Mudipu')));
UPDATE `shops` SET `pending_amount` = 2750.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Taqwa Mudipu'));

-- Mangala Genereal store jeppu (Loc: Jeppu, Due: Rs. 815.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Mangala Genereal store jeppu', 'Jeppu', '', 815.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala Genereal store jeppu')));
UPDATE `shops` SET `pending_amount` = 815.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Mangala Genereal store jeppu'));

-- Rifayi Store Harekala (Loc: Harekala, Due: Rs. 585.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Rifayi Store Harekala', 'Harekala', '', 585.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rifayi Store Harekala')));
UPDATE `shops` SET `pending_amount` = 585.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rifayi Store Harekala'));

-- Ashfaq store kasaba (Loc: Kasaba bengere, Due: Rs. 600.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Ashfaq store kasaba', 'Kasaba bengere', '', 600.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashfaq store kasaba')));
UPDATE `shops` SET `pending_amount` = 600.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Ashfaq store kasaba'));

-- Malabar Hot Chips Kaikamba (Loc: Kaikamba, Due: Rs. 1950.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Malabar Hot Chips Kaikamba', 'Kaikamba', '', 1950.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Malabar Hot Chips Kaikamba')));
UPDATE `shops` SET `pending_amount` = 1950.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Malabar Hot Chips Kaikamba'));

-- Bharath Super Bazar Manjeshwar (Loc: Manjeshwar, Due: Rs. 110.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Bharath Super Bazar Manjeshwar', 'Manjeshwar', '', 110.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Super Bazar Manjeshwar')));
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Bharath Super Bazar Manjeshwar'));

-- Aishwarya Bakery kallapu (Loc: Kallapu, Due: Rs. 1850.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Aishwarya Bakery kallapu', 'Kallapu', '', 1850.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Aishwarya Bakery kallapu')));
UPDATE `shops` SET `pending_amount` = 1850.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Aishwarya Bakery kallapu'));

-- U.P Bawa Traders , Kallapu (Loc: Kallapu, Due: Rs. 515.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'U.P Bawa Traders , Kallapu', 'Kallapu', '', 515.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('U.P Bawa Traders , Kallapu')));
UPDATE `shops` SET `pending_amount` = 515.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('U.P Bawa Traders , Kallapu'));

-- Shree Santeri Ullal (Loc: Ullal, Due: Rs. 250.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Shree Santeri Ullal', 'Ullal', '', 250.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Santeri Ullal')));
UPDATE `shops` SET `pending_amount` = 250.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Santeri Ullal'));

-- Shree Kateel Hotel kankanady (Loc: Kankanady, Due: Rs. 1365.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Shree Kateel Hotel kankanady', 'Kankanady', '', 1365.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Kateel Hotel kankanady')));
UPDATE `shops` SET `pending_amount` = 1365.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Shree Kateel Hotel kankanady'));

-- Super Duper Jyothi (Loc: Jyothi, Due: Rs. 1000.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Super Duper Jyothi', 'Jyothi', '', 1000.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Super Duper Jyothi')));
UPDATE `shops` SET `pending_amount` = 1000.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Super Duper Jyothi'));

-- Royal Restaurant Mangalore (Loc: Mangalore, Due: Rs. 4025.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Royal Restaurant Mangalore', 'Mangalore', '', 4025.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Royal Restaurant Mangalore')));
UPDATE `shops` SET `pending_amount` = 4025.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Royal Restaurant Mangalore'));

-- Fayaz Customer (Due: Rs. 8090.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Fayaz Customer', '', '', 8090.00, NULL, NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fayaz Customer')));
UPDATE `shops` SET `pending_amount` = 8090.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Fayaz Customer'));

-- Rida Canteen Deralakatte (Loc: Deralakatte, Due: Rs. 110.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Rida Canteen Deralakatte', 'Deralakatte', '', 110.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rida Canteen Deralakatte')));
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Rida Canteen Deralakatte'));

-- S M Silicon Nitte (Loc: Nitte, Due: Rs. 1170.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'S M Silicon Nitte', 'Nitte', '', 1170.00, 20, 13, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Silicon Nitte')));
UPDATE `shops` SET `pending_amount` = 1170.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('S M Silicon Nitte'));

-- Abbobakkar shop montepadav (Loc: Montepadav, Due: Rs. 110.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Abbobakkar shop montepadav', 'Montepadav', '', 110.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Abbobakkar shop montepadav')));
UPDATE `shops` SET `pending_amount` = 110.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Abbobakkar shop montepadav'));

-- Nefisa kitchen sambarthota (Loc: Sambarthota, Due: Rs. 240.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'Nefisa kitchen sambarthota', 'Sambarthota', '', 240.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Nefisa kitchen sambarthota')));
UPDATE `shops` SET `pending_amount` = 240.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('Nefisa kitchen sambarthota'));

-- National Bakery Mukkacheri (Loc: Mukkacheri, Due: Rs. 780.00)
INSERT INTO `shops` (`shop_name`, `location`, `phone`, `pending_amount`, `zone_id`, `vehicle_id`, `created_at`, `updated_at`)
SELECT 'National Bakery Mukkacheri', 'Mukkacheri', '', 780.00, 21, 11, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM `shops` WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery Mukkacheri')));
UPDATE `shops` SET `pending_amount` = 780.00 WHERE TRIM(LOWER(`shop_name`)) = TRIM(LOWER('National Bakery Mukkacheri'));

-- =============================================================================
-- STEP 4: VERIFICATION QUERY
-- =============================================================================
SELECT COUNT(*) AS total_creditor_shops, FORMAT(SUM(pending_amount), 2) AS total_pending_dues FROM `shops` WHERE `pending_amount` > 0;
