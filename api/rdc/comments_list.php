<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_comments.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_view') && !role_can($user['role'], 'rdc_review')) {
    json_error('Insufficient permissions', 403);
}

$date = trim($_GET['balance_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('balance_date is required (YYYY-MM-DD)');
}

$pdo = db();
if (!rdc_comments_table_ready($pdo)) {
    json_ok([
        'balance_date' => $date,
        'comments' => [],
        'setup_needed' => true,
        'message' => 'Run migration 014_rdc_review_comments.sql to enable comment threads.',
    ]);
}

json_ok([
    'balance_date' => $date,
    'comments' => rdc_comments_list($pdo, $date),
    'setup_needed' => false,
]);
