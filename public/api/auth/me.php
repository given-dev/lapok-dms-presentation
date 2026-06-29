<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('Method not allowed', 405);
}

$user = current_user();

if ($user === null) {
    json_success(['authenticated' => false, 'user' => null]);
}

$stmt = db()->prepare(
    'SELECT u.id, u.name, u.email, u.role, u.phone, u.route_id, u.vehicle_id,
            r.name AS route_name, v.code AS vehicle_code, v.reg_plate, v.type AS vehicle_type
     FROM users u
     LEFT JOIN routes r ON r.id = u.route_id
     LEFT JOIN vehicles v ON v.id = u.vehicle_id
     WHERE u.id = :id'
);
$stmt->execute(['id' => $user['id']]);
$profile = $stmt->fetch() ?: $user;

json_success(['authenticated' => true, 'user' => $profile]);
