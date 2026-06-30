<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

$user = require_login();
if (!in_array($user['role'], ['manager', 'accountant', 'executive', 'admin'], true)) {
    json_error('Insufficient permissions', 403);
}

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month');
}

$fixed = depot_fixed_costs_for_month($month);
json_ok([
    'fixed' => $fixed,
    'monthly_total' => depot_monthly_fixed_total($fixed),
]);
