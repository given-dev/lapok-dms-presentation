<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_view')) {
    json_error('Insufficient permissions', 403);
}

$date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date — use YYYY-MM-DD');
}

$stmt = db()->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$stmt->execute([$date]);
$row = $stmt->fetch();

if (!$row) {
    $template = rdc_new_sheet_template($date);
    $canEdit = role_can($user['role'], 'rdc_balancing');
    json_ok([
        'sheet' => $template,
        'is_new' => true,
        'read_only' => !$canEdit,
    ]);
    exit;
}

$canEdit = role_can($user['role'], 'rdc_balancing');
$readOnly = !$canEdit || ($row['status'] === 'submitted' && $user['role'] !== 'admin');

json_ok([
    'sheet' => rdc_sheet_to_response($row),
    'is_new' => false,
    'read_only' => $readOnly,
]);
