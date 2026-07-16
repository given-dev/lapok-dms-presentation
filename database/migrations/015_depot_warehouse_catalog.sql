-- Ensure depot sales-book products exist for manager stock taking + dispatch.
-- ENERGY is stored as separate SKUs for the manager; cadet/RDC report rolls them into ENERGY.
USE lapok_dms;

INSERT INTO products (name, sku, unit_price, min_stock, is_active) VALUES
('300ML RGB', 'RGB-300', 18500, 80, 1),
('300ML PET', 'PET-300', 10000, 80, 1),
('PET-500ML', 'PET-500', 15000, 80, 1),
('1L COCA-COLA', 'CK-1L', 12500, 80, 1),
('PET-2000ML', 'PET-2000', 25500, 60, 1),
('PREDATOR GOLD', 'EN-GOLD', 17500, 40, 1),
('PREDATOR MANGO', 'EN-MANGO', 17500, 40, 1),
('POWERPLAY', 'EN-PLAY', 17500, 40, 1),
('400ML M.MAIDS', 'MM-400', 25500, 60, 1),
('1LITRES M/MAIDS', 'MM-1L', 25500, 60, 1),
('REFRESH-250ML', 'RF-250', 10000, 60, 1),
('RWENZORI 500MLS-BOX', 'RW-500-BOX', 17400, 80, 1),
('RWENZORI 500MLS-SHRINKS', 'RW-500-SHR', 10000, 80, 1),
('RWENZORI 1.5MLS-BOX', 'RW-1500', 18600, 60, 1),
('JUMBO 20L', 'JUMBO-20', 10800, 20, 1),
('JUMBO 10L', 'JUMBO-10', 5500, 20, 1),
('BOTTLES', 'BOTTLES', 400, 50, 1),
('SHELLS', 'SHELLS', 6400, 40, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  unit_price = VALUES(unit_price),
  min_stock = VALUES(min_stock),
  is_active = 1;

-- Starter warehouse batches for any catalog SKU that has no batch yet
INSERT INTO batches (product_id, batch_number, expiry_date, qty_warehouse, qty_on_vehicles, unit_cost)
SELECT p.id, CONCAT('INIT-', p.sku), DATE_ADD(CURDATE(), INTERVAL 180 DAY),
       CASE
         WHEN p.sku IN ('JUMBO-20', 'JUMBO-10') THEN 30
         WHEN p.sku = 'BOTTLES' THEN 200
         WHEN p.sku LIKE 'EN-%' THEN 60
         ELSE 100
       END,
       0,
       ROUND(p.unit_price * 0.6, 2)
FROM products p
WHERE p.sku IN (
  'RGB-300','PET-300','PET-500','CK-1L','PET-2000',
  'EN-GOLD','EN-MANGO','EN-PLAY',
  'MM-400','MM-1L','RF-250',
  'RW-500-BOX','RW-500-SHR','RW-1500',
  'JUMBO-20','JUMBO-10','BOTTLES','SHELLS'
)
AND NOT EXISTS (SELECT 1 FROM batches b WHERE b.product_id = p.id);

-- Hide legacy demo SKUs that are not on the depot sales book
UPDATE products SET is_active = 0 WHERE sku IN ('CK-500', 'FT-OR', 'SP-500', 'SP-1L', 'NV-OR');
