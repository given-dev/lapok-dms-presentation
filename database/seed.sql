-- LAPOK DMS — Seed data for development / testing
USE lapok_dms;

-- Password for all demo users: password123
-- bcrypt hash of 'password123'
SET @pwd = '$2b$10$3xHUjFwksE7tVvFzWi/PLONB21xT4aWVyZVNDMQGUhWeGe5.hDiky';

-- Vehicles first (no FK deps except we'll update users after)
INSERT INTO vehicles (id, registration, vehicle_type, make_model, capacity, status, current_route) VALUES
(1, 'KCA 201T', 'truck', 'Isuzu NPR', 150, 'on_route', 'Kampala Central'),
(2, 'KCB 774Y', 'truck', 'Mercedes Sprinter', 120, 'on_route', 'Mukono'),
(3, 'TUK-001', 'tuktuk', 'Bajaj RE', 40, 'on_route', 'Owino / Katwe'),
(4, 'TUK-002', 'tuktuk', 'Bajaj RE', 40, 'available', NULL);

INSERT INTO users (id, full_name, email, password_hash, role, national_id, phone, vehicle_id, default_route) VALUES
(1, 'John Okello', 'admin@lapok.ug', @pwd, 'admin', 'CM90000000001', '+256 700 000001', NULL, NULL),
(2, 'Mary Atim', 'executive@lapok.ug', @pwd, 'executive', 'CM90100000002', '+256 700 000002', NULL, NULL),
(3, 'Sarah Nakato', 'manager@lapok.ug', @pwd, 'manager', 'CM90200145672', '+256 772 100001', NULL, NULL),
(4, 'Grace Apio', 'accountant@lapok.ug', @pwd, 'accountant', 'CM92030987654', '+256 703 300003', NULL, NULL),
(5, 'David Ssemuju', 'cadet@lapok.ug', @pwd, 'cadet', 'CM87101234567', '+256 754 200002', 3, 'Owino / Katwe'),
(6, 'Ruth Nambi', 'driver@lapok.ug', @pwd, 'driver', 'CM95041122334', '+256 701 500005', 1, 'Kampala Central');

UPDATE vehicles SET driver_id = 6, cadet_id = NULL WHERE id = 1;
UPDATE vehicles SET driver_id = NULL, cadet_id = NULL WHERE id = 2;
UPDATE vehicles SET driver_id = NULL, cadet_id = 5 WHERE id = 3;
UPDATE vehicles SET driver_id = NULL, cadet_id = NULL WHERE id = 4;

INSERT INTO products (id, name, sku, unit_price, min_stock) VALUES
(1, 'Coke 500ml', 'CK-500', 20000, 80),
(2, 'Coke 1L', 'CK-1L', 22000, 100),
(3, 'Fanta Orange', 'FT-OR', 18000, 80),
(4, 'Sprite 500ml', 'SP-500', 19000, 80),
(5, 'Sprite 1L', 'SP-1L', 20000, 80),
(6, 'Novida', 'NV-OR', 20000, 100);

INSERT INTO customers (id, name, phone, location, category, credit_balance) VALUES
(1, 'Nandos Supermarket', '+256 772 555 001', 'Kampala Road, Shop 14', 'regular', 0),
(2, 'Owino Market', NULL, 'Owino Market, Stall 22', 'occasional', 160000),
(3, 'Katwe Shop', '+256 701 334 455', 'Katwe Main St', 'occasional', 120000),
(4, 'Kampala Mall', '+256 772 888 002', 'Kampala Mall', 'vip', 0);

INSERT INTO routes (id, name, zone, description) VALUES
(1, 'Owino / Katwe', 'Central', 'Owino market through Katwe'),
(2, 'Kampala Central', 'Central', 'CBD delivery route'),
(3, 'Mukono', 'Eastern', 'Mukono town route');

INSERT INTO route_stops (route_id, customer_id, stop_order) VALUES
(1, 1, 1), (1, 2, 2), (1, 3, 3);

-- Initial supplier delivery + batches
INSERT INTO supplier_deliveries (id, delivery_date, delivery_time, waybill, invoice_number, truck_plate, driver_name, received_by, notes, created_by)
VALUES (1, '2026-05-04', '09:00:00', 'WB-2026-0504-001', 'INV-CC-0504-0091', 'UBA 223K', 'Coca-Cola Driver', 2, '2 crates Fanta short-delivered.', 2);

INSERT INTO supplier_delivery_items (delivery_id, product_id, qty_ordered, qty_delivered, batch_number, expiry_date, unit_cost) VALUES
(1, 1, 300, 300, 'CC500-MAY26-A', '2026-11-30', 12000),
(1, 3, 100, 98, 'FTOR-MAY26-A', '2026-10-15', 11000),
(1, 6, 200, 200, 'NVOR-MAY26-A', '2026-12-01', 10000);

INSERT INTO batches (product_id, batch_number, expiry_date, qty_warehouse, qty_on_vehicles, unit_cost, delivery_id) VALUES
(1, 'CC500-MAY26-A', '2026-11-30', 42, 38, 12000, 1),
(2, 'CK1L-JUN26-A', '2026-12-15', 210, 40, 13000, NULL),
(3, 'FTOR-MAY26-A', '2026-10-15', 98, 22, 11000, 1),
(4, 'SP500-JUN26-A', '2026-11-20', 155, 35, 11500, NULL),
(5, 'SP1L-MAY26-A', '2026-09-30', 18, 12, 12000, NULL),
(6, 'NVOR-MAY26-A', '2026-12-01', 310, 60, 10000, 1);

INSERT INTO stock_movements (product_id, batch_id, movement_type, qty, reference_type, reference_id, user_id, notes) VALUES
(1, 1, 'stock_in', 300, 'supplier_delivery', 1, 2, 'Initial delivery'),
(3, 3, 'stock_in', 98, 'supplier_delivery', 1, 2, 'Initial delivery'),
(6, 6, 'stock_in', 200, 'supplier_delivery', 1, 2, 'Initial delivery');

-- Active trip for TUK-001
INSERT INTO delivery_trips (id, vehicle_id, driver_id, cadet_id, route_id, route_area, status, dispatched_at, odometer_start)
VALUES (1, 3, NULL, 5, 1, 'Owino / Katwe', 'on_route', '2026-05-07 09:10:00', 45230);

INSERT INTO trip_load_items (trip_id, product_id, batch_id, qty_loaded, qty_sold) VALUES
(1, 1, 1, 15, 12),
(1, 3, 3, 10, 8),
(1, 6, 6, 10, 6),
(1, 4, 4, 5, 2);

-- Sample orders
INSERT INTO orders (id, order_ref, customer_id, user_id, trip_id, vehicle_id, status, payment_type, amount_total, amount_paid, created_at) VALUES
(1, 'RCP-0507-1001', 1, 5, 1, 3, 'confirmed', 'cash', 200000, 200000, '2026-05-07 10:32:00'),
(2, 'RCP-0507-1002', 2, 5, 1, 3, 'pending', 'credit', 160000, 0, '2026-05-07 11:48:00'),
(3, 'RCP-0507-1003', 3, 5, 1, 3, 'pending', 'credit', 120000, 0, '2026-05-07 13:15:00'),
(4, 'RCP-0507-1004', 1, 5, 1, 3, 'confirmed', 'cash', 80000, 80000, '2026-05-07 14:02:00'),
(5, 'RCP-0507-3809', 3, 5, 1, 3, 'pending', 'credit', 108000, 0, '2026-05-07 12:55:00');

INSERT INTO order_items (order_id, product_id, qty, unit_price, subtotal) VALUES
(1, 1, 10, 20000, 200000),
(2, 1, 8, 20000, 160000),
(3, 3, 6, 20000, 120000),
(4, 1, 4, 20000, 80000),
(5, 3, 6, 18000, 108000);

INSERT INTO edit_requests (order_id, user_id, request_type, reason, details, status) VALUES
(2, 5, 'edit', 'Customer returned 2 crates', 'Coke 500ml ×12→×10', 'pending'),
(5, 5, 'cancel', 'Customer did not pay', 'Fanta Orange ×6', 'pending');

INSERT INTO audit_log (user_id, table_name, record_id, action, new_values) VALUES
(3, 'orders', 1, 'confirm', '{"status":"confirmed"}'),
(5, 'orders', 2, 'create', '{"order_ref":"RCP-0507-1002"}');

-- Trip awaiting accountant cash confirmation (Phase 4)
INSERT INTO delivery_trips (id, vehicle_id, driver_id, cadet_id, route_id, route_area, status, dispatched_at, returned_at, odometer_start, odometer_end, cash_reported, fuel_cost)
VALUES (2, 3, NULL, 5, 1, 'Owino / Katwe', 'returned', '2026-05-06 08:00:00', '2026-05-06 17:30:00', 45100, 45180, 480000, 25000);
