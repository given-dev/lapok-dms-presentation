<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$email = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if ($email === '' || $password === '') {
    json_error('Email and password are required');
}

$stmt = db()->prepare(
    'SELECT id, full_name, email, password_hash, role, vehicle_id, default_route, is_active
     FROM users WHERE email = ? LIMIT 1'
);
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !(int) $user['is_active']) {
    usleep(250000);
    json_error('Invalid credentials', 401);
}

if (!password_verify($password, $user['password_hash'])) {
    usleep(250000);
    json_error('Invalid credentials', 401);
}

if ($user['role'] === 'driver') {
    json_error('Driver access is disabled in this build', 403);
}

login_user($user);
audit_log((int) $user['id'], 'users', (int) $user['id'], 'login');

json_ok([
    'user' => current_user(),
]);
