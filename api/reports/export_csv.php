<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/branded_export.php';

$user = require_login();

$type = $_GET['type'] ?? 'sales';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$permByType = [
    'receivables' => 'customers_balance',
    'stock' => 'stock_view',
    'sales' => 'reports_sales',
    'rdc_sheet' => 'rdc_balancing',
];
$required = $permByType[$type] ?? 'reports_sales';
if (!role_can($user['role'], $required)) {
    json_error('Insufficient permissions', 403);
}

$routeId = (int) ($_GET['route_id'] ?? 0);
$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$userId = (int) ($_GET['user_id'] ?? 0);
$who = trim((string) ($user['full_name'] ?? $user['email'] ?? 'User'));

$titles = [
    'receivables' => 'Customer receivables',
    'stock' => 'Warehouse stock',
    'sales' => 'Sales report',
    'rdc_sheet' => 'RDC daily sheet',
];
$reportTitle = $titles[$type] ?? 'Report export';

/**
 * @param list<string> $headers
 * @param list<list<mixed>> $rows
 * @param array<string, string> $meta
 */
function export_branded_report(
    string $reportTitle,
    array $headers,
    array $rows,
    array $meta,
    string $who,
    string $subtitle = ''
): void {
    branded_export_send($reportTitle, $headers, $rows, [
        'subtitle' => $subtitle !== '' ? $subtitle : 'Official depot export',
        'meta' => $meta,
        'generated_by' => 'Exported by ' . $who,
        'filename' => 'Outpost-DMS-' . branded_export_slug($reportTitle) . '-' . date('Ymd-Hi') . '.xlsx',
    ]);
}

if ($type === 'receivables') {
    $headers = ['Customer', 'Phone', 'Location', 'Balance (UGX)'];
    $rows = [];
    $total = 0.0;
    $dbRows = db()->query(
        "SELECT name, phone, location, credit_balance FROM customers
         WHERE credit_balance > 0 AND is_active = 1 ORDER BY credit_balance DESC"
    )->fetchAll();
    foreach ($dbRows as $r) {
        $bal = (float) $r['credit_balance'];
        $total += $bal;
        $rows[] = [$r['name'], $r['phone'], $r['location'], $bal];
    }
    export_branded_report($reportTitle, $headers, $rows, [
        'As of' => date('d M Y'),
        'Customers owing' => (string) count($rows),
        'Total outstanding' => 'UGX ' . number_format($total, 0),
    ], $who, 'Outstanding customer credit balances');
}

if ($type === 'rdc_sheet') {
    $date = trim($_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid date']);
        exit;
    }
    $stmt = db()->prepare(
        'SELECT balance_date, status, sales_total, recovery_total, expenses_total, grand_total,
                expected_amount, actual_total, variance, notes, submitted_at
         FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1'
    );
    $stmt->execute([$date]);
    $row = $stmt->fetch();

    $labels = [
        'balance_date' => 'Balance date',
        'status' => 'Status',
        'sales_total' => 'Sales total (UGX)',
        'recovery_total' => 'Recovery total (UGX)',
        'expenses_total' => 'Expenses total (UGX)',
        'grand_total' => 'Grand total (UGX)',
        'expected_amount' => 'Expected amount (UGX)',
        'actual_total' => 'Actual total (UGX)',
        'variance' => 'Variance (UGX)',
        'notes' => 'Notes',
        'submitted_at' => 'Submitted at',
    ];
    $headers = ['Field', 'Value'];
    $rows = [];
    if (!$row) {
        $rows[] = ['Balance date', $date];
        $rows[] = ['Status', 'No sheet for this date'];
    } else {
        foreach ($labels as $key => $label) {
            $rows[] = [$label, $row[$key] ?? ''];
        }
    }
    export_branded_report($reportTitle, $headers, $rows, [
        'Sheet date' => $date,
        'Status' => (string) ($row['status'] ?? 'none'),
    ], $who, 'Daily balancing summary for manager review');
}

if ($type === 'stock') {
    require_once dirname(__DIR__, 2) . '/includes/stock.php';
    $headers = ['Product', 'SKU', 'Warehouse', 'On vehicles', 'Min stock', 'Nearest expiry'];
    $rows = [];
    $warehouseTotal = 0;
    foreach (db()->query(stock_summary_query())->fetchAll() as $r) {
        $warehouseTotal += (int) $r['warehouse_qty'];
        $rows[] = [
            $r['name'],
            $r['sku'],
            (int) $r['warehouse_qty'],
            (int) $r['on_vehicles_qty'],
            (int) $r['min_stock'],
            $r['nearest_expiry'] ?: '—',
        ];
    }
    export_branded_report($reportTitle, $headers, $rows, [
        'As of' => date('d M Y H:i'),
        'SKUs' => (string) count($rows),
        'Warehouse cartons' => (string) $warehouseTotal,
    ], $who, 'Current warehouse and vehicle stock levels');
}

// default: sales
$headers = ['Date', 'Order ref', 'Customer', 'Amount (UGX)', 'Status', 'Payment'];
$where = ['o.created_at BETWEEN ? AND ?'];
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($routeId > 0) {
    $where[] = 'o.trip_id IN (SELECT id FROM delivery_trips WHERE route_id = ?)';
    $params[] = $routeId;
}
if ($vehicleId > 0) {
    $where[] = 'o.vehicle_id = ?';
    $params[] = $vehicleId;
}
if ($userId > 0) {
    $where[] = 'o.user_id = ?';
    $params[] = $userId;
}
$stmt = db()->prepare(
    "SELECT o.created_at, o.order_ref, c.name AS customer, o.amount_total, o.status, o.payment_type
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY o.created_at"
);
$stmt->execute($params);
$rows = [];
$salesTotal = 0.0;
while ($row = $stmt->fetch()) {
    $amt = (float) $row['amount_total'];
    $salesTotal += $amt;
    $rows[] = [
        $row['created_at'],
        $row['order_ref'],
        $row['customer'] ?: '—',
        $amt,
        $row['status'],
        $row['payment_type'],
    ];
}
export_branded_report($reportTitle, $headers, $rows, [
    'Period' => date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to)),
    'Orders' => (string) count($rows),
    'Total sales' => 'UGX ' . number_format($salesTotal, 0),
], $who, 'Sales for the selected period');
