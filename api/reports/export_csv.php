<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_roles(['admin', 'executive', 'manager', 'accountant']);

$type = $_GET['type'] ?? 'sales';
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

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
$stmt = db()->prepare(
    "SELECT o.created_at, o.order_ref, c.name AS customer, o.amount_total, o.status, o.payment_type
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     WHERE o.created_at BETWEEN ? AND ?
     ORDER BY o.created_at"
);
$stmt->execute([$from, $to . ' 23:59:59']);
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['created_at'], $row['order_ref'], $row['customer'],
        $row['amount_total'], $row['status'], $row['payment_type'],
    ]);
}
exit;
