-- 023: Update product unit prices to the 2026-08-03 price list.
-- Keep in sync with includes/depot_catalog.php (depot_rdc_sales_catalog()
-- and depot_manager_warehouse_catalog()).

-- 400ML M.MAIDS: 25,500 -> 25,000
UPDATE products SET unit_price = 25000 WHERE sku IN ('400-MM-MANGO','400-MM-BERRY','400-MM-APPLE','400-MM-ORANGE');

-- PET-1L: 12,500 -> 15,000
UPDATE products SET unit_price = 15000 WHERE sku = '1L-COKE';

-- REFRESH-500ML: 15,000 -> 10,000
UPDATE products SET unit_price = 10000 WHERE sku IN ('500-RF-MANGO','500-RF-ORANGE');
