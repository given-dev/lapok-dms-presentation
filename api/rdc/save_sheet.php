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
    json_error('balance_date is required (YYYY-MM-DD)');
}

$columns = rdc_build_columns();
$sales = $body['sales'] ?? [];
$recoveries = $body['recoveries'] ?? [];
$expenses = $body['expenses'] ?? [];
$cashOut = $body['cash_out'] ?? [];
$cashActual = $body['cash_actual'] ?? [];
$expectedOverride = isset($body['expected_amount']) ? (float) $body['expected_amount'] : null;
$notes = trim($body['notes'] ?? '');

$totals = rdc_compute_totals([
    'sales' => $sales,
    'recoveries' => $recoveries,
    'expenses' => $expenses,
    'cash_actual' => $cashActual,
    'expected_amount' => $expectedOverride,
]);

$pdo = db();
$existing = $pdo->prepare('SELECT id, status FROM rdc_daily_sheets WHERE balance_date = ?');
$existing->execute([$date]);
$prev = $existing->fetch();

if ($prev && $prev['status'] === 'submitted' && $user['role'] !== 'admin') {
    json_error('Submitted sheets cannot be edited. Contact admin to reopen.', 403);
}

$payload = [
    json_encode($sales, JSON_UNESCAPED_UNICODE),
    json_encode($recoveries, JSON_UNESCAPED_UNICODE),
    json_encode($expenses, JSON_UNESCAPED_UNICODE),
    json_encode($cashOut, JSON_UNESCAPED_UNICODE),
    json_encode($cashActual, JSON_UNESCAPED_UNICODE),
    $totals['sales_total'],
    $totals['recovery_total'],
    $totals['expenses_total'],
    $totals['grand_total'],
    $totals['expected_amount'],
    $totals['actual_total'],
    $totals['variance'],
    json_encode($columns, JSON_UNESCAPED_UNICODE),
    $notes !== '' ? $notes : null,
    (int) $user['id'],
];

if ($prev) {
    $upd = $pdo->prepare(
        'UPDATE rdc_daily_sheets SET
            sales_json = ?, recoveries_json = ?, expenses_json = ?, cash_out_json = ?,
            cash_actual_json = ?, sales_total = ?, recovery_total = ?, expenses_total = ?,
            grand_total = ?, expected_amount = ?, actual_total = ?, variance = ?,
            columns_json = ?, notes = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $upd->execute([...$payload, (int) $prev['id']]);
    $id = (int) $prev['id'];
} else {
    $ins = $pdo->prepare(
        'INSERT INTO rdc_daily_sheets
         (balance_date, sales_json, recoveries_json, expenses_json, cash_out_json, cash_actual_json,
          sales_total, recovery_total, expenses_total, grand_total, expected_amount, actual_total,
          variance, columns_json, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$date, ...$payload]);
    $id = (int) $pdo->lastInsertId();
}

audit_log((int) $user['id'], 'rdc_daily_sheets', $id, $prev ? 'update' : 'create', null, [
    'balance_date' => $date,
    'variance' => $totals['variance'],
]);

$stmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

json_ok(['sheet' => rdc_sheet_to_response($row), 'message' => 'Daily balancing saved']);
