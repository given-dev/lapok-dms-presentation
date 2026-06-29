<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$name = trim($body['full_name'] ?? '');
$email = trim($body['email'] ?? '');
$password = $body['password'] ?? '';
$role = $body['role'] ?? 'field_user';
$nationalId = trim($body['national_id'] ?? '') ?: null;
$phone = trim($body['phone'] ?? '') ?: null;
$vehicleId = !empty($body['vehicle_id']) ? (int) $body['vehicle_id'] : null;
$defaultRoute = trim($body['default_route'] ?? '') ?: null;

$validRoles = ['admin', 'executive', 'manager', 'accountant', 'field_user', 'driver', 'cadet'];
if ($name === '' || $email === '' || $password === '' || !in_array($role, $validRoles, true)) {
    json_error('full_name, email, password, and valid role are required');
}

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = db()->prepare(
    'INSERT INTO users (full_name, email, password_hash, role, national_id, phone, vehicle_id, default_route)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$name, $email, $hash, $role, $nationalId, $phone, $vehicleId, $defaultRoute]);
$id = (int) db()->lastInsertId();

audit_log($user['id'], 'users', $id, 'create', null, ['email' => $email, 'role' => $role]);

json_ok(['user_id' => $id], 201);
