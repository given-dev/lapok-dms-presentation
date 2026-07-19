-- Keep the six baseline operational accounts.
USE lapok_dms;

UPDATE vehicles SET driver_id = NULL WHERE driver_id IN (SELECT id FROM users WHERE email IN ('joseph@lapok.ug', 'moses@lapok.ug'));
UPDATE vehicles SET cadet_id = NULL WHERE cadet_id IN (SELECT id FROM users WHERE email IN ('joseph@lapok.ug', 'moses@lapok.ug'));
UPDATE delivery_trips SET driver_id = NULL WHERE driver_id IN (SELECT id FROM users WHERE email IN ('joseph@lapok.ug', 'moses@lapok.ug'));
UPDATE delivery_trips SET cadet_id = NULL WHERE cadet_id IN (SELECT id FROM users WHERE email IN ('joseph@lapok.ug', 'moses@lapok.ug'));

UPDATE vehicles SET driver_id = (SELECT id FROM users WHERE email = 'driver@lapok.ug' LIMIT 1), cadet_id = NULL WHERE id = 1;
UPDATE vehicles SET driver_id = NULL, cadet_id = (SELECT id FROM users WHERE email = 'cadet@lapok.ug' LIMIT 1) WHERE id = 3;

DELETE FROM users WHERE email IN ('joseph@lapok.ug', 'moses@lapok.ug');
