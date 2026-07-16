<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_login();
$canBalance = role_can($user['role'], 'rdc_balancing');
$canReview = role_can($user['role'], 'rdc_review');
if (!$canBalance && !$canReview) {
    json_error('Insufficient permissions', 403);
}

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

$pdo = db();
$existing = $pdo->prepare('SELECT id, status, sales_json FROM rdc_daily_sheets WHERE balance_date = ?');
$existing->execute([$date]);
$prev = $existing->fetch();

$role = (string) ($user['role'] ?? '');
$prevStatus = (string) ($prev['status'] ?? 'draft');
$lockedStatuses = ['submitted', 'under_review', 'approved', 'rejected'];
$locked = $prev && in_array($prevStatus, $lockedStatuses, true);
$managerMayEdit = $canReview
    && in_array($role, ['manager', 'admin'], true)
    && in_array($prevStatus, ['submitted', 'under_review'], true);

if ($locked) {
    if ($role === 'admin' && $canBalance) {
        // Admin override
    } elseif ($managerMayEdit) {
        // Manager correcting received sheet
    } else {
        json_error('Submitted sheets cannot be edited by RDC. Manager can correct while reviewing, or reopen for RDC.', 403);
    }
} elseif (!$canBalance) {
    json_error('Only the accountant can edit draft RDC sheets', 403);
}

// Only admin may change unit prices  -  keep existing / catalog prices for accountants/managers
if ($role !== 'admin' && is_array($sales)) {
    $lockedByKey = [];
    $lockedByLabel = [];
    $prevSales = $prev ? (json_decode((string) ($prev['sales_json'] ?? '[]'), true) ?: []) : [];
    foreach ($prevSales as $line) {
        if (!is_array($line)) {
            continue;
        }
        $key = (string) ($line['rdc_key'] ?? '');
        $label = strtoupper(trim((string) ($line['label'] ?? '')));
        $price = (float) ($line['price'] ?? 0);
        if ($key !== '') {
            $lockedByKey[$key] = $price;
        }
        if ($label !== '') {
            $lockedByLabel[$label] = $price;
        }
    }
    require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';
    foreach (depot_rdc_sales_catalog() as $row) {
        $lockedByKey[$row['key']] = (float) $row['price'];
        $lockedByLabel[strtoupper((string) $row['label'])] = (float) $row['price'];
    }
    foreach ($sales as &$line) {
        if (!is_array($line)) {
            continue;
        }
        $key = (string) ($line['rdc_key'] ?? '');
        $label = strtoupper(trim((string) ($line['label'] ?? '')));
        if ($key !== '' && array_key_exists($key, $lockedByKey)) {
            $line['price'] = $lockedByKey[$key];
        } elseif ($label !== '' && array_key_exists($label, $lockedByLabel)) {
            $line['price'] = $lockedByLabel[$label];
        }
    }
    unset($line);
}

try {
$totals = rdc_compute_totals([
    'sales' => $sales,
    'recoveries' => $recoveries,
    'expenses' => $expenses,
    'cash_actual' => $cashActual,
    'expected_amount' => $expectedOverride,
]);

$sheetFields = [
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
];

if ($prev) {
    // Opening a manager correction moves submitted → under_review
    $setUnderReview = $managerMayEdit && $prevStatus === 'submitted';
    if ($setUnderReview) {
        $upd = $pdo->prepare(
            'UPDATE rdc_daily_sheets SET
                sales_json = ?, recoveries_json = ?, expenses_json = ?, cash_out_json = ?,
                cash_actual_json = ?, sales_total = ?, recovery_total = ?, expenses_total = ?,
                grand_total = ?, expected_amount = ?, actual_total = ?, variance = ?,
                columns_json = ?, notes = ?, status = ?, reviewed_by = ?, reviewed_at = NOW(),
                review_note = COALESCE(review_note, ?), updated_at = NOW()
             WHERE id = ?'
        );
        $upd->execute(array_merge($sheetFields, [
            'under_review',
            (int) $user['id'],
            'Manager edited received sheet',
            (int) $prev['id'],
        ]));
    } else {
        $upd = $pdo->prepare(
            'UPDATE rdc_daily_sheets SET
                sales_json = ?, recoveries_json = ?, expenses_json = ?, cash_out_json = ?,
                cash_actual_json = ?, sales_total = ?, recovery_total = ?, expenses_total = ?,
                grand_total = ?, expected_amount = ?, actual_total = ?, variance = ?,
                columns_json = ?, notes = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $upd->execute(array_merge($sheetFields, [(int) $prev['id']]));
    }
    $id = (int) $prev['id'];
} else {
    if (!$canBalance) {
        json_error('Only the accountant can create a new RDC sheet', 403);
    }
    $ins = $pdo->prepare(
        'INSERT INTO rdc_daily_sheets
         (balance_date, sales_json, recoveries_json, expenses_json, cash_out_json, cash_actual_json,
          sales_total, recovery_total, expenses_total, grand_total, expected_amount, actual_total,
          variance, columns_json, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute(array_merge([$date], $sheetFields, [(int) $user['id']]));
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
} catch (Throwable $e) {
    json_error('Could not save sheet: ' . $e->getMessage(), 500);
}
