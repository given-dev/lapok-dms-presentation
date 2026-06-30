<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$current = (string) ($body['current_password'] ?? '');
$new = (string) ($body['new_password'] ?? '');

if ($current === '' || !password_meets_policy($new)) {
    json_error('Use a strong new password (min 10 chars with upper, lower, and number)');
}

$stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if (!$row || !password_verify($current, $row['password_hash'])) {
    json_error('Current password is incorrect', 401);
}

$hash = password_hash($new, PASSWORD_BCRYPT);
db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);

audit_log($user['id'], 'users', $user['id'], 'password_change');

json_ok(['message' => 'Password updated']);
