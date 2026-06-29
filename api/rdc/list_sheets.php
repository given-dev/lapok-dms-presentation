<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_view')) {
    json_error('Insufficient permissions', 403);
}

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month — use YYYY-MM');
}

$stmt = db()->prepare(
    "SELECT balance_date, status, sales_total, recovery_total, expenses_total,
            grand_total, expected_amount, actual_total, variance, submitted_at
     FROM rdc_daily_sheets
     WHERE DATE_FORMAT(balance_date, '%Y-%m') = ?
     ORDER BY balance_date DESC"
);
$stmt->execute([$month]);
$sheets = $stmt->fetchAll();

json_ok(['month' => $month, 'sheets' => $sheets]);
