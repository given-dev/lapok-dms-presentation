<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

// Executives get a read-only view; only the admin can change assignments.
$user = require_roles(['admin', 'manager', 'executive']);
$pdo = db();
$rows = $pdo->query(
    "SELECT v.id AS vehicle_id, v.registration, v.vehicle_type,
            v.cadet_id, v.route_area, u.full_name AS cadet_name
     FROM vehicles v
     LEFT JOIN users u ON u.id = v.cadet_id
     WHERE v.is_active = 1
     ORDER BY v.vehicle_type, v.registration"
)->fetchAll();
$cadets = $pdo->query(
    "SELECT id, full_name FROM users
     WHERE role IN ('cadet','field_user') AND is_active = 1 ORDER BY full_name"
)->fetchAll();

json_ok([
    'assignments' => $rows,
    'cadets' => $cadets,
    'can_edit' => $user['role'] === 'admin',
]);
