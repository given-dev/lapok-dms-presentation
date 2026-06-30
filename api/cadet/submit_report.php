<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';
require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_login();
if (!in_array($user['role'], ['cadet', 'field_user'], true)) {
    json_error('Cadet access only', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$fuel = max(0, (float) ($body['fuel_expense'] ?? 0));
$other = max(0, (float) ($body['other_expense'] ?? 0));
$cashHanded = (float) ($body['cash_handed'] ?? 0);
$note = trim((string) ($body['note'] ?? ''));
$salesInput = is_array($body['sales_lines'] ?? null) ? $body['sales_lines'] : [];

$pdo = db();
$tStmt = $pdo->prepare(
    "SELECT id, vehicle_id FROM delivery_trips
     WHERE (cadet_id = ? OR driver_id = ?) AND status IN ('dispatched','on_route')
     ORDER BY dispatched_at DESC LIMIT 1"
);
$tStmt->execute([(int) $user['id'], (int) $user['id']]);
$tripRow = $tStmt->fetch();
$tripId = (int) ($tripRow['id'] ?? 0);
if ($tripId <= 0) {
    json_error('No active trip found. Ask manager to dispatch your vehicle first.');
}

$catalogByKey = [];
$loaded = depot_trip_loaded_by_rdc_key($tripId);
foreach (depot_rdc_sales_catalog() as $row) {
    $catalogByKey[$row['key']] = array_merge($row, [
        'unit_price' => (float) $row['price'],
        'qty_loaded' => (int) ($loaded[$row['key']] ?? 0),
    ]);
}

$enrichedInput = [];
foreach ($salesInput as $line) {
    if (!is_array($line)) {
        continue;
    }
    $key = (string) ($line['rdc_key'] ?? '');
    if ($key !== '' && isset($catalogByKey[$key])) {
        $line['qty_loaded'] = $catalogByKey[$key]['qty_loaded'];
    }
    $enrichedInput[] = $line;
}

$salesLines = cadet_normalize_sales_lines($enrichedInput, $catalogByKey);
$salesTotal = array_sum(array_map(fn($line) => (float) $line['amount'], $salesLines));

$flags = cadet_compute_flags($salesTotal, $cashHanded, $fuel, $other, $note, $salesLines);
if (in_array('needs_note', $flags, true)) {
    json_error('Add a short note explaining the cash/sales difference.');
}

$report = [
    'sales_total' => $salesTotal,
    'sales_lines' => $salesLines,
    'fuel_expense' => $fuel,
    'other_expense' => $other,
    'cash_handed' => $cashHanded,
    'note' => $note,
    'flags' => $flags,
    'submitted_at' => date('c'),
    'cadet_id' => (int) $user['id'],
    'cadet_name' => $user['full_name'],
];
$notes = '[CADET_REPORT] ' . json_encode($report, JSON_UNESCAPED_UNICODE);
if ($note !== '') {
    $notes .= "\n" . $note;
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE delivery_trips
         SET fuel_cost = ?, cash_reported = ?, notes = ?, status = ?, returned_at = NOW()
         WHERE id = ?'
    )->execute([$fuel + $other, $cashHanded, $notes, 'returned', $tripId]);

    cadet_apply_trip_sales($pdo, $tripId, $salesLines);

    $mergeResult = rdc_merge_cadet_report($pdo, $tripId, $report);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

audit_log((int) $user['id'], 'delivery_trips', $tripId, 'cadet_report', null, $report);

try {
    require_once dirname(__DIR__, 2) . '/includes/notifications.php';
    if (count($flags) > 0) {
        notify_user((int) $user['id'], 'RDC flagged your report', cadet_flag_labels($flags), [
            'sender_role' => 'accountant',
            'severity' => in_array('cash_variance', $flags, true) ? 'danger' : 'warning',
            'link_page' => 'cadet-daily',
        ]);
    } else {
        notify_user((int) $user['id'], 'Report received by RDC', 'Today\'s sales and cash are in the depot balancing sheet.', [
            'sender_role' => 'accountant',
            'severity' => 'info',
            'link_page' => 'cadet-dashboard',
        ]);
    }
} catch (Throwable) {
}

try {
    require_once dirname(__DIR__, 2) . '/includes/report_packets.php';
    report_create_field_eod($tripId, (int) $user['id'], $user['role'], $cashHanded, $notes);
} catch (Throwable) {
}

$message = count($flags) > 0
    ? 'Submitted. RDC flagged: ' . cadet_flag_labels($flags)
    : 'Submitted to RDC. No issues flagged.';
if (!empty($mergeResult['merged'])) {
    $message .= ' Consolidated into today\'s balancing sheet.';
} elseif (($mergeResult['reason'] ?? '') === 'sheet_locked') {
    $message .= ' Today\'s sheet is already submitted — tell accountant to add manually.';
}

json_ok([
    'trip_id' => $tripId,
    'flags' => $flags,
    'flagged_for_rdc' => count($flags) > 0,
    'sales_total' => $salesTotal,
    'rdc_consolidated' => !empty($mergeResult['merged']),
    'rdc_balance_date' => $mergeResult['balance_date'] ?? null,
    'message' => $message,
]);
