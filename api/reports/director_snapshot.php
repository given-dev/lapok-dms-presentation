<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

$user = require_login();
if (!in_array($user['role'], ['executive', 'manager', 'accountant', 'admin'], true)) {
    json_error('Insufficient permissions', 403);
}

$month = trim($_GET['month'] ?? '');
if ($month !== '') {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        json_error('Invalid month');
    }
    json_ok(depot_director_snapshot_monthly($month));
}

$date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date');
}

json_ok(depot_director_snapshot($date));
