<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_permission('rdc_balancing');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim($body['balance_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('balance_date is required');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ?');
$stmt->execute([$date]);
$row = $stmt->fetch();

if (!$row) {
    json_error('Save the sheet as draft before submitting', 400);
}

if (!in_array($row['status'], ['draft', 'reopened'], true)) {
    json_error('Only draft or reopened sheets can be submitted', 400);
}

$upd = $pdo->prepare(
    "UPDATE rdc_daily_sheets
     SET status = 'submitted',
         submitted_by = ?,
         submitted_at = NOW(),
         reviewed_by = NULL,
         reviewed_at = NULL,
         review_note = NULL
     WHERE id = ?"
);
$upd->execute([(int) $user['id'], (int) $row['id']]);

audit_log((int) $user['id'], 'rdc_daily_sheets', (int) $row['id'], 'submit', null, [
    'balance_date' => $date,
]);

$stmt->execute([$date]);
$row = $stmt->fetch();

json_ok(['sheet' => rdc_sheet_to_response($row), 'message' => 'Daily balancing submitted to manager']);
