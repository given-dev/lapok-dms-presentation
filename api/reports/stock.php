<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

require_permission('stock_view');

$expiryDays = (int) ($_GET['expiry_days'] ?? 30);

$stock = db()->query(stock_summary_query())->fetchAll();

$movements = db()->query(
    "SELECT sm.created_at, sm.movement_type, sm.qty, p.name AS product_name, u.full_name AS user_name
     FROM stock_movements sm
     JOIN products p ON p.id = sm.product_id
     LEFT JOIN users u ON u.id = sm.user_id
     ORDER BY sm.created_at DESC LIMIT 100"
)->fetchAll();

$expiring = db()->prepare(
    "SELECT b.batch_number, b.expiry_date, b.qty_warehouse, p.name AS product_name
     FROM batches b
     JOIN products p ON p.id = b.product_id
     WHERE b.qty_warehouse > 0 AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
     ORDER BY b.expiry_date"
);
$expiring->execute([$expiryDays]);

json_ok([
    'stock_levels' => $stock,
    'low_stock' => get_low_stock_alerts(),
    'expiring_batches' => $expiring->fetchAll(),
    'recent_movements' => $movements,
]);
