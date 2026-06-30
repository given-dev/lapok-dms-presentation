<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_view') && !role_can($user['role'], 'rdc_review')) {
    json_error('Insufficient permissions', 403);
}

$month = trim($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month — use YYYY-MM');
}

try {
    $stmt = db()->prepare(
        "SELECT s.balance_date, s.status, s.sales_total, s.recovery_total, s.expenses_total,
                s.grand_total, s.expected_amount, s.actual_total, s.variance, s.submitted_at,
                s.reviewed_at, s.review_note, u.full_name AS reviewed_by_name
         FROM rdc_daily_sheets s
         LEFT JOIN users u ON u.id = s.reviewed_by
         WHERE DATE_FORMAT(s.balance_date, '%Y-%m') = ?
         ORDER BY balance_date DESC"
    );
    $stmt->execute([$month]);
    $sheets = $stmt->fetchAll();
} catch (Throwable $e) {
    json_error('RDC tables not ready — run migrations 008 and 009.', 500);
}

json_ok(['month' => $month, 'sheets' => $sheets]);
