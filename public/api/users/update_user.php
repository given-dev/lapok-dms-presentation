<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed', 405);
}

$actor = require_role('admin');

$body = read_json_body();

$id        = isset($body['id']) ? (int) $body['id'] : null;
if (!$id)  json_fail('User ID is required');

$name      = sanitize_string($body['name'] ?? '');
$email     = strtolower(sanitize_string($body['email'] ?? ''));
$role      = sanitize_string($body['role'] ?? '');
$phone     = sanitize_string($body['phone'] ?? '') ?: null;
$nationalId = sanitize_string($body['national_id'] ?? '') ?: null;
$vehicleId  = isset($body['vehicle_id']) && $body['vehicle_id'] !== '' ? (int) $body['vehicle_id'] : null;
$routeId    = isset($body['route_id'])   && $body['route_id']   !== '' ? (int) $body['route_id']   : null;
$isActive   = isset($body['is_active']) ? (int) (bool) $body['is_active'] : null;

$allowedRoles = ['admin', 'executive', 'manager', 'accountant', 'driver', 'cadet', 'field_user'];

if (!$name)                              json_fail('Full name is required');
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_fail('Valid email is required');
if ($role && !in_array($role, $allowedRoles, true)) json_fail('Invalid role');

// Fetch existing for audit diff
$existing = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$existing->execute(['id' => $id]);
$old = $existing->fetch();
if (!$old) json_fail('User not found', 404);

// Check email uniqueness (excluding this user)
$check = db()->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
$check->execute(['email' => $email, 'id' => $id]);
if ($check->fetch()) json_fail('Email is already in use by another user');

$updates = ['name = :name', 'email = :email', 'phone = :phone', 'national_id = :national_id',
            'vehicle_id = :vehicle_id', 'route_id = :route_id'];
$params  = ['name' => $name, 'email' => $email, 'phone' => $phone,
            'national_id' => $nationalId, 'vehicle_id' => $vehicleId,
            'route_id' => $routeId, 'id' => $id];

if ($role) { $updates[] = 'role = :role'; $params['role'] = $role; }
if ($isActive !== null) { $updates[] = 'is_active = :is_active'; $params['is_active'] = $isActive; }

// Optional password reset
if (!empty($body['new_password'])) {
    if (strlen($body['new_password']) < 8) json_fail('Password must be at least 8 characters');
    $updates[] = 'password_hash = :hash';
    $params['hash'] = password_hash($body['new_password'], PASSWORD_BCRYPT);
}

$stmt = db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id');
$stmt->execute($params);

audit_log((int) $actor['id'], 'users', $id, 'update',
    ['name' => $old['name'], 'role' => $old['role'], 'is_active' => $old['is_active']],
    ['name' => $name, 'role' => $role ?: $old['role'], 'is_active' => $isActive ?? $old['is_active']]
);

json_success(['updated' => true]);
