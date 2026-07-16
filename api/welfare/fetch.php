<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/staff_welfare.php';

$user = require_login();
if (!welfare_can_view($user['role'])) {
    json_error('Insufficient permissions', 403);
}

$status = trim($_GET['status'] ?? '');
$limit = (int) ($_GET['limit'] ?? 100);

try {
    $entries = welfare_list_entries($status !== '' ? $status : null, $limit);
    $summary = welfare_summary();
} catch (Throwable $e) {
    json_error('Welfare tables not ready  -  run migration 012_rdc_ops_sync.sql', 500);
}

json_ok([
    'entries' => $entries,
    'summary' => $summary,
    'read_only' => !welfare_can_write($user['role']),
]);
