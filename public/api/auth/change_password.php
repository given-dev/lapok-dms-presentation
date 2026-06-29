<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Method not allowed', 405);
}

$user = require_login();
$body = read_json_body();

$current = (string) ($body['current_password'] ?? '');
$new = (string) ($body['new_password'] ?? '');
$confirm = (string) ($body['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
    json_fail('All password fields are required', 422);
}

if (strlen($new) < 8) {
    json_fail('New password must be at least 8 characters', 422);
}

if ($new !== $confirm) {
    json_fail('New passwords do not match', 422);
}

try {
    change_password((int) $user['id'], $current, $new);
    json_success(['message' => 'Password updated']);
} catch (AuthException $e) {
    json_fail($e->getMessage(), $e->httpStatus);
}
