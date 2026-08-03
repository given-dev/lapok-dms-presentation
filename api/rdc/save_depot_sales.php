<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_roles(['admin', 'manager']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim($body['balance_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('balance_date is required (YYYY-MM-DD)');
}

// Map of rdc_key => qty for each depot sales column.
$depot = $body['depot'] ?? [];
$kamdini = $body['kamdini'] ?? [];
if (!is_array($depot) || !is_array($kamdini)) {
    json_error('depot and kamdini must be objects of rdc_key => qty');
}
$depot = array_map('intval', $depot);
$kamdini = array_map('intval', $kamdini);

$pdo = db();
$existing = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$existing->execute([$date]);
$row = $existing->fetch();

$role = (string) ($user['role'] ?? '');
$isAdmin = $role === 'admin';

if ($row) {
    $status = (string) ($row['status'] ?? 'draft');
    if (in_array($status, ['approved', 'rejected'], true) && !$isAdmin) {
        json_error('This sheet is already approved/rejected  -  depot sales can no longer be edited', 403);
    }
    if (in_array($status, ['submitted', 'under_review'], true) && !$isAdmin) {
        json_error('This sheet is submitted for review  -  reopen it before editing depot sales', 403);
    }
}

$pdo->beginTransaction();
try {
    if ($row) {
        $sales = json_decode((string) ($row['sales_json'] ?? '[]'), true) ?: [];
        $recoveries = json_decode((string) ($row['recoveries_json'] ?? '[]'), true) ?: [];
        $expenses = json_decode((string) ($row['expenses_json'] ?? '[]'), true) ?: [];
        $cashOut = json_decode((string) ($row['cash_out_json'] ?? '[]'), true) ?: [];
        $cashActual = json_decode((string) ($row['cash_actual_json'] ?? '[]'), true) ?: [];
        $notes = (string) ($row['notes'] ?? '');
        $columns = json_decode((string) ($row['columns_json'] ?? '[]'), true) ?: [];
    } else {
        $template = rdc_new_sheet_template($date);
        $sales = $template['sales'];
        $recoveries = $template['recoveries'];
        $expenses = $template['expenses'];
        $cashOut = $template['cash_out'];
        $cashActual = $template['cash_actual'];
        $notes = '';
        $columns = $template['columns'];
    }

    // Apply the depot + kamdini quantities onto the matching catalog lines only.
    foreach ($sales as &$line) {
        $key = (string) ($line['rdc_key'] ?? '');
        if ($key === '') {
            continue;
        }
        if (!isset($line['qty']) || !is_array($line['qty'])) {
            $line['qty'] = [];
        }
        $line['qty']['depot'] = (float) ($depot[$key] ?? 0);
        $line['qty']['kamdini'] = (float) ($kamdini[$key] ?? 0);
    }
    unset($line);

    $totals = rdc_compute_totals([
        'sales' => $sales,
        'recoveries' => $recoveries,
        'expenses' => $expenses,
        'cash_out' => $cashOut,
        'cash_actual' => $cashActual,
    ]);

    $stamp = '[DEPOT_SALES] ' . date('Y-m-d H:i') . ' by ' . ($user['full_name'] ?? $role);
    $notes = trim($notes);
    if (!str_contains($notes, '[DEPOT_SALES]')) {
        $notes = trim($notes . "\n" . $stamp);
    } else {
        $notes = preg_replace('/\[DEPOT_SALES\].*$/m', $stamp, $notes) ?? $notes;
    }

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

    if ($row) {
        $pdo->prepare(
            'UPDATE rdc_daily_sheets SET
                sales_json = ?, recoveries_json = ?, expenses_json = ?, cash_out_json = ?,
                cash_actual_json = ?, sales_total = ?, recovery_total = ?, expenses_total = ?,
                grand_total = ?, expected_amount = ?, actual_total = ?, variance = ?,
                columns_json = ?, notes = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute(array_merge($sheetFields, [(int) $row['id']]));
        $id = (int) $row['id'];
    } else {
        $creatorId = rdc_system_accountant_id($pdo);
        $pdo->prepare(
            'INSERT INTO rdc_daily_sheets
             (balance_date, sales_json, recoveries_json, expenses_json, cash_out_json, cash_actual_json,
              sales_total, recovery_total, expenses_total, grand_total, expected_amount, actual_total,
              variance, columns_json, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge([$date], $sheetFields, [$creatorId]));
        $id = (int) $pdo->lastInsertId();
    }

    audit_log((int) $user['id'], 'rdc_daily_sheets', $id, 'update', null, [
        'balance_date' => $date,
        'depot_sales' => 'update_depot_kamdini',
    ]);

    $pdo->commit();

    $stmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE id = ?');
    $stmt->execute([$id]);

    json_ok(['sheet' => rdc_sheet_to_response($stmt->fetch()), 'message' => 'Depot sales saved']);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not save depot sales: ' . $e->getMessage(), 500);
}
