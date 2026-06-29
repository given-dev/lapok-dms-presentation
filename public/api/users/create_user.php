<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed', 405);
}

$actor = require_role('admin');

$body = read_json_body();

$name       = sanitize_string($body['name'] ?? '');
$email      = strtolower(sanitize_string($body['email'] ?? ''));
$password   = $body['password'] ?? '';
$role       = sanitize_string($body['role'] ?? 'field_user');
$nationalId = sanitize_string($body['national_id'] ?? '') ?: null;
$phone      = sanitize_string($body['phone'] ?? '') ?: null;
$vehicleId  = isset($body['vehicle_id']) && $body['vehicle_id'] !== '' ? (int) $body['vehicle_id'] : null;
$routeId    = isset($body['route_id'])   && $body['route_id']   !== '' ? (int) $body['route_id']   : null;

$allowedRoles = ['admin', 'executive', 'manager', 'accountant', 'driver', 'cadet', 'field_user'];

if (!$name)                          json_fail('Full name is required');
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_fail('Valid email is required');
if (strlen($password) < 8)           json_fail('Password must be at least 8 characters');
if (!in_array($role, $allowedRoles, true)) json_fail('Invalid role');

// Check email uniqueness
$check = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$check->execute(['email' => $email]);
if ($check->fetch()) {
    json_fail('Email is already registered');
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$insert = db()->prepare(
    'INSERT INTO users (name, email, password_hash, role, national_id, phone, vehicle_id, route_id, is_active)
     VALUES (:name, :email, :hash, :role, :national_id, :phone, :vehicle_id, :route_id, 1)'
);
$insert->execute([
    'name'        => $name,
    'email'       => $email,
    'hash'        => $hash,
    'role'        => $role,
    'national_id' => $nationalId,
    'phone'       => $phone,
    'vehicle_id'  => $vehicleId,
    'route_id'    => $routeId,
]);

$newId = (int) db()->lastInsertId();

audit_log((int) $actor['id'], 'users', $newId, 'create', null, [
    'name'  => $name,
    'email' => $email,
    'role'  => $role,
]);

json_success(['id' => $newId], 201);
