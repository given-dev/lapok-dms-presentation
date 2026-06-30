<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_month_end.php';

$user = require_login();
if (!rdc_month_end_can_edit($user['role'])) {
    json_error('Only accountant can edit month-end workspace', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$month = trim((string) ($body['month'] ?? date('Y-m')));
$state = $body['state'] ?? null;

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month — use YYYY-MM');
}
if (!is_array($state)) {
    json_error('Missing state payload');
}

try {
    $saved = rdc_month_end_save($month, $state, (int) $user['id']);
} catch (Throwable $e) {
    json_error('Month-end tables not ready — run migration 012_rdc_ops_sync.sql', 500);
}

json_ok([
    'month' => $month,
    'state' => $saved['state'],
    'updated_by_name' => $saved['updated_by_name'] ?? $user['full_name'],
    'updated_at' => $saved['updated_at'],
    'message' => 'Month-end workspace saved.',
]);
