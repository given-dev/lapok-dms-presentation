-- Remove seeded/demo operations while preserving users, vehicles, product catalogue,
-- and genuine transactions such as the 2026-07-19 cadet submission and RDC sheet.
USE lapok_dms;

START TRANSACTION;

-- Seeded PDF chain and notification backfill tied to it.
DELETE FROM user_notifications
WHERE body LIKE '%RPT-MGR-20260507-001%'
   OR (title = 'submit' AND body = 'hello')
   OR (title = 'hey' AND body = 'hi')
   OR (title = 'rest' AND body = 'you need rest');

DELETE FROM report_packets
WHERE packet_ref IN (
  'RPT-EOD-20260507-001',
  'RPT-ACC-20260507-001',
  'RPT-MGR-20260507-001'
);

-- Seeded May sales, requests, trip loads, and trips.
DELETE FROM orders
WHERE order_ref IN (
  'RCP-0507-1001', 'RCP-0507-1002', 'RCP-0507-1003',
  'RCP-0507-1004', 'RCP-0507-3809'
);

DELETE FROM vehicle_location_pings;

DELETE FROM delivery_trips
WHERE id IN (1, 2, 3)
  AND notes IS NULL
  AND dispatched_at IN (
    '2026-05-07 09:10:00',
    '2026-05-06 08:00:00',
    '2026-07-19 08:52:47'
  );

-- Seeded warehouse receipt and all artificial starting quantities.
DELETE FROM stock_movements
WHERE reference_type = 'supplier_delivery'
  AND reference_id = 1
  AND notes = 'Initial delivery';

DELETE FROM batches
WHERE batch_number LIKE 'INIT-%'
   OR batch_number IN (
    'RGB300-MAY26-A','PET300-JUN26-A','PET500-MAY26-A','CK1L-JUN26-A',
    'PET2L-MAY26-A','ENGOLD-MAY26-A','ENMANGO-JUN26-A','ENPLAY-JUN26-A',
    'MM400-JUN26-A','MM1L-JUN26-A','RF250-JUN26-A','RW500B-JUN26-A',
    'RW500S-JUN26-A','RW1500-JUN26-A','J20-JUN26-A','J10-JUN26-A',
    'BOT-JUN26-A','SHL-JUN26-A'
  );

DELETE FROM supplier_deliveries
WHERE waybill = 'WB-2026-0504-001'
  AND invoice_number = 'INV-CC-0504-0091';

-- Seeded route/customer master records were never entered through the live UI.
DELETE FROM customers
WHERE (id = 1 AND name = 'Nandos Supermarket')
   OR (id = 2 AND name = 'Owino Market')
   OR (id = 3 AND name = 'Katwe Shop')
   OR (id = 4 AND name = 'Kampala Mall');

DELETE FROM routes
WHERE (id = 1 AND name = 'Owino / Katwe')
   OR (id = 2 AND name = 'Kampala Central')
   OR (id = 3 AND name = 'Mukono');

-- Only the two audit rows inserted by database/seed.sql.
DELETE FROM audit_log
WHERE (id = 1 AND table_name = 'orders' AND record_id = 1 AND action = 'confirm')
   OR (id = 2 AND table_name = 'orders' AND record_id = 2 AND action = 'create');

-- No vehicle should remain visually on-route after its artificial trip is removed.
UPDATE vehicles v
SET v.status = 'available', v.current_route = NULL
WHERE NOT EXISTS (
  SELECT 1 FROM delivery_trips dt
  WHERE dt.vehicle_id = v.id AND dt.status IN ('dispatched', 'on_route')
);

COMMIT;
