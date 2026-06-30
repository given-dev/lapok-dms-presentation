<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_view')) {
    json_error('Insufficient permissions', 403);
}

$ok = true;
$checks = [];

try {
    db()->query('SELECT 1 FROM rdc_daily_sheets LIMIT 1');
    $checks['rdc_daily_sheets'] = true;
} catch (Throwable $e) {
    $ok = false;
    $checks['rdc_daily_sheets'] = false;
}

try {
    $row = db()->query("SHOW COLUMNS FROM rdc_daily_sheets LIKE 'review_note'")->fetch();
    $checks['rdc_review_workflow'] = (bool) $row;
    if (!$checks['rdc_review_workflow']) {
        $ok = false;
    }
} catch (Throwable $e) {
    $ok = false;
    $checks['rdc_review_workflow'] = false;
}

try {
    db()->query('SELECT 1 FROM rdc_month_end LIMIT 1');
    db()->query('SELECT 1 FROM staff_welfare_entries LIMIT 1');
    $checks['rdc_ops_sync'] = true;
} catch (Throwable $e) {
    $checks['rdc_ops_sync'] = false;
}

$message = 'Accountant module is ready.';
if (!$checks['rdc_daily_sheets'] || !($checks['rdc_review_workflow'] ?? false)) {
    $ok = false;
    $message = 'Run migrations 008_rdc_daily_balancing.sql and 009_rdc_review_workflow.sql';
} elseif (!($checks['rdc_ops_sync'] ?? false)) {
    $message = 'Core RDC ready. Run migration 012_rdc_ops_sync.sql for month-end and welfare sync.';
}

json_ok([
    'live' => $ok,
    'checks' => $checks,
    'message' => $message,
]);
