-- Ensure depot sales-book products exist for manager stock taking + dispatch.
-- ENERGY is stored as separate SKUs for the manager; cadet/RDC report rolls them into ENERGY.

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

-- Stock quantities are entered only through deliveries and stock-taking workflows.

-- Hide legacy SKUs that are not on the depot sales book
UPDATE products SET is_active = 0 WHERE sku IN ('CK-500', 'FT-OR', 'SP-500', 'SP-1L', 'NV-OR');
