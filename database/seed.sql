-- LAPOK DMS baseline accounts and fleet master data.
-- Operational transactions, stock quantities, customers, routes, reports, and
-- notifications are intentionally not seeded. Enter them through the live UI.
USE lapok_dms;

-- Initial local password: password123. Change every account password before use.
SET @pwd = '$2b$10$3xHUjFwksE7tVvFzWi/PLONB21xT4aWVyZVNDMQGUhWeGe5.hDiky';

INSERT INTO vehicles
  (id, registration, vehicle_type, make_model, capacity, status, current_route, is_active)
VALUES
  (1, 'KCA 201T', 'truck', 'Isuzu NPR', 150, 'available', NULL, 1),
  (2, 'KCB 774Y', 'truck', 'Mercedes Sprinter', 120, 'available', NULL, 1),
  (3, 'TUK-001', 'tuktuk', 'Bajaj RE', 40, 'available', NULL, 1),
  (4, 'TUK-002', 'tuktuk', 'Bajaj RE', 40, 'available', NULL, 1)
ON DUPLICATE KEY UPDATE
  registration = VALUES(registration),
  vehicle_type = VALUES(vehicle_type),
  make_model = VALUES(make_model),
  capacity = VALUES(capacity),
  is_active = 1;

INSERT INTO users
  (id, full_name, email, password_hash, role, national_id, phone, vehicle_id, default_route, is_active)
VALUES
  (1, 'John Okello', 'admin@lapok.ug', @pwd, 'admin', 'CM90000000001', '+256 700 000001', NULL, NULL, 1),
  (2, 'Mary Atim', 'executive@lapok.ug', @pwd, 'executive', 'CM90100000002', '+256 700 000002', NULL, NULL, 1),
  (3, 'Sarah Nakato', 'manager@lapok.ug', @pwd, 'manager', 'CM90200145672', '+256 772 100001', NULL, NULL, 1),
  (4, 'Grace Apio', 'accountant@lapok.ug', @pwd, 'accountant', 'CM92030987654', '+256 703 300003', NULL, NULL, 1),
  (5, 'David Ssemuju', 'cadet@lapok.ug', @pwd, 'cadet', 'CM87101234567', '+256 754 200002', 3, NULL, 1),
  (6, 'Ruth Nambi', 'driver@lapok.ug', @pwd, 'driver', 'CM95041122334', '+256 701 500005', 1, NULL, 1)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  email = VALUES(email),
  role = VALUES(role),
  national_id = VALUES(national_id),
  phone = VALUES(phone),
  is_active = 1;

UPDATE vehicles SET driver_id = 6, cadet_id = NULL WHERE id = 1;
UPDATE vehicles SET driver_id = NULL, cadet_id = 5 WHERE id = 3;
