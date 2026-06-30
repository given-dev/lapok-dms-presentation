<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_month_end.php';

$user = require_login();
if (!rdc_month_end_can_view($user['role'])) {
    json_error('Insufficient permissions', 403);
}

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month — use YYYY-MM');
}

try {
    $record = rdc_month_end_fetch($month);
} catch (Throwable $e) {
    json_error('Month-end tables not ready — run migration 012_rdc_ops_sync.sql', 500);
}

json_ok([
    'month' => $month,
    'state' => $record['state'] ?? rdc_month_end_default_state(),
    'updated_by_name' => $record['updated_by_name'] ?? null,
    'updated_at' => $record['updated_at'] ?? null,
    'read_only' => !rdc_month_end_can_edit($user['role']),
]);
