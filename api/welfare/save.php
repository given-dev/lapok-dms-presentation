<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/staff_welfare.php';

$user = require_login();
if (!welfare_can_write($user['role'])) {
    json_error('Insufficient permissions', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();

try {
    $entry = welfare_save_entry($body, (int) $user['id']);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, "doesn't exist") || str_contains($msg, 'staff_welfare_entries')) {
        json_error('Welfare tables not ready  -  run migration 012_rdc_ops_sync.sql', 500);
    }
    throw $e;
}

json_ok([
    'entry' => $entry,
    'message' => !empty($body['id']) ? 'Welfare entry updated.' : 'Welfare entry saved.',
]);
