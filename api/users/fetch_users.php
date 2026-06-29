<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_roles(['admin', 'manager']);

$stmt = db()->query(
    "SELECT u.id, u.full_name, u.email, u.role, u.national_id, u.phone,
            u.vehicle_id, u.default_route, u.is_active, u.created_at,
            v.registration AS vehicle_reg
     FROM users u
     LEFT JOIN vehicles v ON v.id = u.vehicle_id
     ORDER BY u.full_name"
);

json_ok(['users' => $stmt->fetchAll()]);
