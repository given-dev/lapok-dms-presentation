<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$user = current_user();
if ($user) {
    audit_log($user['id'], 'users', $user['id'], 'logout');
}
logout_user();
json_ok(['message' => 'Logged out']);
