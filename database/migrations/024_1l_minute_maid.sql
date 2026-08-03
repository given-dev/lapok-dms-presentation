-- 024: Re-add 1L Minute Maid to the depot stock book / dispatch list.
-- The RDC sales pack `mm_1l` (1LITRES M/MAID) needs warehouse products so it can
-- receive deliveries and show up in the dispatch load list after 400ML M.MAID.
-- Keep in sync with includes/depot_catalog.php (depot_manager_warehouse_catalog()).

INSERT INTO products (name, sku, unit_price, min_stock, is_active) VALUES
('MM MANGO 1L', '1L-MM-MANGO', 25500, 40, 1),
('MM BERRY 1L', '1L-MM-BERRY', 25500, 40, 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  unit_price = VALUES(unit_price),
  min_stock = VALUES(min_stock),
  is_active = 1;
