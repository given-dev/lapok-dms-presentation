<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('Method not allowed', 405);
}

require_role('admin');

$stmt = db()->query(
    'SELECT u.id, u.name, u.email, u.role, u.national_id, u.phone, u.is_active,
            u.created_at,
            v.code  AS vehicle_code, v.reg_plate AS vehicle_plate, v.type AS vehicle_type,
            r.name  AS route_name
     FROM users u
     LEFT JOIN vehicles v ON v.id = u.vehicle_id
     LEFT JOIN routes   r ON r.id = u.route_id
     ORDER BY u.name ASC'
);

$rows = array_map(static function (array $row): array {
    return [
        'id'          => (int) $row['id'],
        'name'        => $row['name'],
        'email'       => $row['email'],
        'role'        => $row['role'],
        'national_id' => $row['national_id'],
        'phone'       => $row['phone'],
        'is_active'   => (bool) $row['is_active'],
        'created_at'  => $row['created_at'],
        'vehicle'     => $row['vehicle_code']
            ? ['code' => $row['vehicle_code'], 'plate' => $row['vehicle_plate'], 'type' => $row['vehicle_type']]
            : null,
        'route'       => $row['route_name'] ?? null,
    ];
}, $stmt->fetchAll());

json_success(['users' => $rows]);
