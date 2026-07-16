<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';

$user = require_login();
if (!in_array($user['role'], ['cadet', 'field_user'], true)) {
    json_error('Cadet access only', 403);
}

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month. Use YYYY-MM.');
}

$start = $month . '-01';
$end = date('Y-m-d', strtotime($start . ' +1 month'));

$stmt = db()->prepare(
    "SELECT dt.id, dt.vehicle_id, dt.route_area, dt.returned_at, dt.notes,
            v.registration, r.name AS route_name
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN routes r ON r.id = dt.route_id
     WHERE (dt.cadet_id = ? OR dt.driver_id = ?)
       AND dt.status = 'returned'
       AND dt.returned_at >= ?
       AND dt.returned_at < ?
       AND dt.notes LIKE '%[CADET_REPORT]%'
     ORDER BY dt.returned_at DESC, dt.id DESC"
);
$stmt->execute([(int) $user['id'], (int) $user['id'], $start, $end]);

$reports = [];
foreach ($stmt->fetchAll() as $row) {
    $report = cadet_parse_report_note($row['notes'] ?? null);
    if (!$report) {
        continue;
    }
    $salesLines = [];
    foreach (($report['sales_lines'] ?? []) as $line) {
        if (!is_array($line) || (int) ($line['qty_sold'] ?? 0) <= 0) {
            continue;
        }
        $salesLines[] = [
            'label' => (string) ($line['rdc_label'] ?? $line['product_name'] ?? 'Product'),
            'qty_sold' => (int) ($line['qty_sold'] ?? 0),
            'amount' => (float) ($line['amount'] ?? 0),
        ];
    }

    $returnedAt = (string) ($row['returned_at'] ?? '');
    $aux = cadet_normalize_auxiliary($report);
    $reports[] = [
        'trip_id' => (int) $row['id'],
        'vehicle_id' => (int) $row['vehicle_id'],
        'date' => $returnedAt !== '' ? date('Y-m-d', strtotime($returnedAt)) : null,
        'returned_at' => $returnedAt,
        'vehicle' => (string) ($row['registration'] ?? 'Vehicle'),
        'route' => (string) ($row['route_name'] ?? $row['route_area'] ?? 'Route'),
        'sales_total' => (float) ($report['sales_total'] ?? 0),
        'cash_handed' => (float) ($report['cash_handed'] ?? 0),
        'auxiliary' => $aux,
        'fuel_expense' => $aux['fuel'],
        'lunch_expense' => $aux['lunch'],
        'discount' => $aux['discount'],
        'shortage' => $aux['shortage'],
        'repairs_expense' => $aux['repairs'],
        'other_expense' => (float) ($report['other_expense'] ?? 0),
        'note' => (string) ($report['note'] ?? ''),
        'flags' => array_values(array_map('strval', $report['flags'] ?? [])),
        'sales_lines' => $salesLines,
        'locked' => true,
        'corrected_at' => $report['corrected_at'] ?? null,
        'corrected_by_name' => $report['corrected_by_name'] ?? null,
    ];
}

json_ok([
    'month' => $month,
    'reports' => $reports,
    'count' => count($reports),
    'read_only' => true,
]);
