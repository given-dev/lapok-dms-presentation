<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/stock.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim($body['snapshot_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid snapshot_date');
}

$pdo = db();
$sql = stock_summary_query();
$rows = $pdo->query($sql)->fetchAll();
$count = 0;

$ins = $pdo->prepare(
    'INSERT INTO ccba_stock_snapshots (snapshot_date, product_id, qty_warehouse, qty_on_vehicles, sync_status, created_by)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE qty_warehouse = VALUES(qty_warehouse), qty_on_vehicles = VALUES(qty_on_vehicles),
       sync_status = VALUES(sync_status), created_by = VALUES(created_by)'
);

foreach ($rows as $row) {
    $ins->execute([
        $date,
        (int) $row['product_id'],
        (int) $row['warehouse_qty'],
        (int) $row['on_vehicles_qty'],
        'pending',
        $user['id'],
    ]);
    $count++;
}

json_ok(['snapshot_date' => $date, 'products' => $count, 'message' => 'Warehouse snapshot saved for CCBA sync.']);
