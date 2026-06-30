<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_permission('exceptions_view');

$pdo = db();
$items = [];

$low = $pdo->query(
    'SELECT p.name AS ref, p.sku, COALESCE(SUM(b.qty_warehouse), 0) AS qty, p.min_stock
     FROM products p LEFT JOIN batches b ON b.product_id = p.id
     WHERE p.is_active = 1 GROUP BY p.id HAVING qty < p.min_stock LIMIT 15'
)->fetchAll();
foreach ($low as $r) {
    $items[] = [
        'type' => 'stock',
        'reference' => $r['sku'] . ' — ' . $r['name'],
        'severity' => (int) $r['qty'] < (int) $r['min_stock'] / 2 ? 'high' : 'medium',
        'owner' => 'Manager',
        'status' => 'open',
        'detail' => $r['qty'] . ' / min ' . $r['min_stock'],
    ];
}

$cash = $pdo->query(
    "SELECT dt.id, v.registration, dt.cash_reported, dt.returned_at
     FROM delivery_trips dt JOIN vehicles v ON v.id = dt.vehicle_id
     WHERE dt.status = 'returned' AND dt.cash_collected IS NULL
     ORDER BY dt.returned_at DESC LIMIT 10"
)->fetchAll();
foreach ($cash as $r) {
    $items[] = [
        'type' => 'cash',
        'reference' => 'Trip #' . $r['id'] . ' · ' . $r['registration'],
        'severity' => 'medium',
        'owner' => 'Accountant',
        'status' => 'investigating',
        'detail' => 'Reported UGX ' . number_format((float) $r['cash_reported']),
    ];
}

$edits = $pdo->query(
    "SELECT er.id, o.order_ref, u.full_name, er.request_type, er.reason
     FROM edit_requests er
     JOIN orders o ON o.id = er.order_id
     JOIN users u ON u.id = er.user_id
     WHERE er.status = 'pending' ORDER BY er.created_at DESC LIMIT 10"
)->fetchAll();
foreach ($edits as $r) {
    $items[] = [
        'type' => 'edit_request',
        'reference' => $r['order_ref'],
        'severity' => $r['request_type'] === 'cancel' ? 'high' : 'medium',
        'owner' => 'Manager',
        'status' => 'open',
        'detail' => $r['full_name'] . ' — ' . $r['reason'],
    ];
}

$pending = $pdo->query(
    "SELECT o.order_ref, u.full_name, o.amount_total, o.created_at
     FROM orders o JOIN users u ON u.id = o.user_id
     WHERE o.status = 'pending' ORDER BY o.created_at DESC LIMIT 10"
)->fetchAll();
foreach ($pending as $r) {
    $items[] = [
        'type' => 'sale',
        'reference' => $r['order_ref'],
        'severity' => 'medium',
        'owner' => 'Manager',
        'status' => 'open',
        'detail' => $r['full_name'] . ' · UGX ' . number_format((float) $r['amount_total']),
    ];
}

require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';
$cadetTrips = $pdo->query(
    "SELECT dt.id, dt.cash_reported, dt.fuel_cost, dt.notes, dt.returned_at,
            v.registration, u.full_name AS cadet_name
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN users u ON u.id = dt.cadet_id
     WHERE dt.status = 'returned' AND DATE(dt.returned_at) = CURDATE()
     ORDER BY dt.returned_at DESC LIMIT 15"
)->fetchAll();
foreach ($cadetTrips as $r) {
    $report = cadet_parse_report_note($r['notes'] ?? null);
    $flags = $report['flags'] ?? [];
    if (!$flags) {
        continue;
    }
    $note = trim((string) ($report['note'] ?? ''));
    $detail = cadet_flag_labels($flags) . ' · ' . cadet_sales_summary($report['sales_lines'] ?? [])
        . ' · UGX ' . number_format((float) $r['cash_reported']);
    if ($note !== '') {
        $detail .= ' · Note: ' . $note;
    }
    $items[] = [
        'type' => 'cadet_report',
        'reference' => ($r['cadet_name'] ?: 'Cadet') . ' · ' . $r['registration'],
        'severity' => in_array('cash_variance', $flags, true) ? 'high' : 'medium',
        'owner' => 'Accountant',
        'status' => 'open',
        'detail' => $detail,
        'trip_id' => (int) $r['id'],
    ];
}

try {
    require_once dirname(__DIR__, 2) . '/includes/staff_welfare.php';
    foreach (welfare_list_entries('open', 10) as $w) {
        $items[] = [
            'type' => 'welfare',
            'reference' => $w['staff'] . ' — ' . ucfirst(str_replace('_', ' ', (string) $w['type'])),
            'severity' => (float) ($w['amount'] ?? 0) >= 500000 ? 'high' : 'medium',
            'owner' => 'Accountant',
            'status' => 'open',
            'detail' => ($w['notes'] ?: 'No notes') . ' · UGX ' . number_format((float) ($w['amount'] ?? 0)),
            'welfare_id' => (int) $w['id'],
        ];
    }
} catch (Throwable) {
    // Migration 012 not applied yet
}

json_ok([
    'summary' => [
        'stock' => count(array_filter($items, fn($i) => $i['type'] === 'stock')),
        'cash' => count(array_filter($items, fn($i) => $i['type'] === 'cash')),
        'edit_request' => count(array_filter($items, fn($i) => $i['type'] === 'edit_request')),
        'sale' => count(array_filter($items, fn($i) => $i['type'] === 'sale')),
        'cadet_report' => count(array_filter($items, fn($i) => $i['type'] === 'cadet_report')),
        'welfare' => count(array_filter($items, fn($i) => $i['type'] === 'welfare')),
        'total' => count($items),
    ],
    'items' => $items,
]);
