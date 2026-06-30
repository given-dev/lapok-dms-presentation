<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('User ID is required');
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$old = $stmt->fetch();
if (!$old) {
    json_error('User not found', 404);
}

$validRoles = ['admin', 'executive', 'manager', 'accountant', 'field_user', 'driver', 'cadet'];
$name = trim($body['full_name'] ?? $old['full_name']);
$email = trim($body['email'] ?? $old['email']);
$role = $body['role'] ?? $old['role'];
$nationalId = array_key_exists('national_id', $body) ? trim($body['national_id'] ?? '') ?: null : $old['national_id'];
$phone = array_key_exists('phone', $body) ? trim($body['phone'] ?? '') ?: null : $old['phone'];
$vehicleId = array_key_exists('vehicle_id', $body) ? ($body['vehicle_id'] ? (int) $body['vehicle_id'] : null) : $old['vehicle_id'];
$defaultRoute = array_key_exists('default_route', $body) ? trim($body['default_route'] ?? '') ?: null : $old['default_route'];
$isActive = isset($body['is_active']) ? (int) (bool) $body['is_active'] : (int) $old['is_active'];

if (!in_array($role, $validRoles, true)) {
    json_error('Invalid role');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Valid email is required');
}
$emailStmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
$emailStmt->execute([$email, $id]);
if ($emailStmt->fetch()) {
    json_error('Email is already in use');
}

db()->prepare(
    'UPDATE users SET full_name = ?, email = ?, role = ?, national_id = ?, phone = ?, vehicle_id = ?, default_route = ?, is_active = ? WHERE id = ?'
)->execute([$name, $email, $role, $nationalId, $phone, $vehicleId, $defaultRoute, $isActive, $id]);

if (!empty($body['password'])) {
    $hash = password_hash($body['password'], PASSWORD_BCRYPT);
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
}

audit_log(
    $user['id'],
    'users',
    $id,
    'update',
    ['email' => $old['email'], 'role' => $old['role'], 'is_active' => (int) $old['is_active']],
    ['email' => $email, 'role' => $role, 'is_active' => $isActive]
);

json_ok(['user_id' => $id]);
