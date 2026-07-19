<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager']);
$pdo = db();
$rows = $pdo->query(
    "SELECT v.id AS vehicle_id, v.registration, v.vehicle_type,
            a.day_of_week, a.route_area, a.cadet_id, u.full_name AS cadet_name
     FROM vehicles v
     LEFT JOIN vehicle_route_assignments a ON a.vehicle_id = v.id
     LEFT JOIN users u ON u.id = a.cadet_id
     WHERE v.is_active = 1
     ORDER BY v.id, a.day_of_week"
)->fetchAll();
$cadets = $pdo->query(
    "SELECT id, full_name FROM users
     WHERE role IN ('cadet','field_user') AND is_active = 1 ORDER BY full_name"
)->fetchAll();

json_ok([
    'assignments' => $rows,
    'cadets' => $cadets,
    'can_edit' => $user['role'] === 'admin',
    'today_day_number' => (int) date('N'),
]);
