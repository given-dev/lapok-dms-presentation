<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
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

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="lapok-' . $type . '-' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');

if ($type === 'receivables') {
    fputcsv($out, ['Customer', 'Phone', 'Location', 'Balance (UGX)']);
    $rows = db()->query(
        "SELECT name, phone, location, credit_balance FROM customers
         WHERE credit_balance > 0 AND is_active = 1 ORDER BY credit_balance DESC"
    )->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'], $r['phone'], $r['location'], $r['credit_balance']]);
    }
    exit;
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
    fputcsv($out, ['Field', 'Value']);
    if (!$row) {
        fputcsv($out, ['balance_date', $date]);
        fputcsv($out, ['status', 'no sheet']);
        exit;
    }
    foreach ($row as $k => $v) {
        fputcsv($out, [$k, $v]);
    }
    exit;
}

if ($type === 'stock') {
    require_once dirname(__DIR__, 2) . '/includes/stock.php';
    fputcsv($out, ['Product', 'SKU', 'Warehouse', 'On vehicles', 'Min stock', 'Nearest expiry']);
    foreach (db()->query(stock_summary_query())->fetchAll() as $r) {
        fputcsv($out, [
            $r['name'], $r['sku'], $r['warehouse_qty'], $r['on_vehicles_qty'],
            $r['min_stock'], $r['nearest_expiry'],
        ]);
    }
    exit;
}

// default: sales
fputcsv($out, ['Date', 'Order ref', 'Customer', 'Amount (UGX)', 'Status', 'Payment']);
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
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['created_at'], $row['order_ref'], $row['customer'],
        $row['amount_total'], $row['status'], $row['payment_type'],
    ]);
}
exit;
