-- Rename field demo emails to role-based addresses (cadet@ / driver@)
USE lapok_dms;

UPDATE users SET email = 'cadet@lapok.ug' WHERE email = 'david@lapok.ug';
UPDATE users SET email = 'driver@lapok.ug' WHERE email = 'ruth@lapok.ug';

UPDATE vehicles SET driver_id = (SELECT id FROM users WHERE email = 'driver@lapok.ug' LIMIT 1), cadet_id = NULL WHERE id = 1;
UPDATE vehicles SET driver_id = NULL, cadet_id = (SELECT id FROM users WHERE email = 'cadet@lapok.ug' LIMIT 1) WHERE id = 3;
