<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('Method not allowed', 405);
}

require_login();
$pdo = db();

$products = $pdo->query(
    'SELECT p.id AS product_id, p.name, p.sku, p.min_stock, p.unit_price,
            COALESCE(SUM(b.qty_remaining), 0) AS warehouse_qty,
            MIN(CASE WHEN b.qty_remaining > 0 AND b.expiry_date IS NOT NULL THEN b.expiry_date END) AS nearest_expiry,
            GROUP_CONCAT(DISTINCT CASE WHEN b.qty_remaining > 0 THEN b.batch_number END ORDER BY b.expiry_date SEPARATOR ", ") AS batch_numbers
     FROM products p
     LEFT JOIN batches b ON b.product_id = p.id AND b.qty_remaining > 0
     WHERE p.is_active = 1
     GROUP BY p.id, p.name, p.sku, p.min_stock, p.unit_price
     ORDER BY p.name'
)->fetchAll();

$vehicleStock = $pdo->query(
    'SELECT p.id AS product_id,
            COALESCE(SUM(CASE WHEN d.status = "out" THEN di.qty_crates ELSE 0 END), 0) AS with_vehicles
     FROM products p
     LEFT JOIN dispatch_items di ON di.product_id = p.id
     LEFT JOIN dispatches d ON d.id = di.dispatch_id
     WHERE p.is_active = 1
     GROUP BY p.id'
)->fetchAll();

$vehicleMap = [];
foreach ($vehicleStock as $row) {
    $vehicleMap[(int) $row['product_id']] = (int) $row['with_vehicles'];
}

$soldToday = $pdo->query(
    'SELECT si.product_id, COALESCE(SUM(si.qty_crates), 0) AS sold_today
     FROM sale_items si
     JOIN sales s ON s.id = si.sale_id
     WHERE DATE(s.sold_at) = CURDATE() AND s.status != "cancelled"
     GROUP BY si.product_id'
)->fetchAll();

$soldMap = [];
foreach ($soldToday as $row) {
    $soldMap[(int) $row['product_id']] = (int) $row['sold_today'];
}

$items = [];
$lowStock = [];
$expiryAlerts = [];
$today = new DateTimeImmutable('today');

foreach ($products as $p) {
    $pid = (int) $p['product_id'];
    $warehouse = (int) $p['warehouse_qty'];
    $min = (int) $p['min_stock'];
    $withVehicles = $vehicleMap[$pid] ?? 0;
    $sold = $soldMap[$pid] ?? 0;

    $level = 'ok';
    if ($warehouse < $min) {
        $level = 'low';
        $lowStock[] = ['name' => $p['name'], 'warehouse' => $warehouse, 'min' => $min];
    } elseif ($warehouse < (int) ($min * 1.5)) {
        $level = 'mid';
    }

    $expiryWarning = false;
    if (!empty($p['nearest_expiry'])) {
        $expiry = new DateTimeImmutable($p['nearest_expiry']);
        $days = (int) $today->diff($expiry)->format('%r%a');
        if ($days >= 0 && $days <= 30) {
            $expiryWarning = true;
            $expiryAlerts[] = [
                'name'        => $p['name'],
                'expiry_date' => $p['nearest_expiry'],
                'days_left'   => $days,
            ];
        }
    }

    $items[] = [
        'product_id'     => $pid,
        'name'           => $p['name'],
        'sku'            => $p['sku'],
        'warehouse'      => $warehouse,
        'with_vehicles'  => $withVehicles,
        'sold_today'     => $sold,
        'min_stock'      => $min,
        'unit_price'     => (float) $p['unit_price'],
        'level'          => $level,
        'batch_numbers'  => $p['batch_numbers'],
        'nearest_expiry' => $p['nearest_expiry'],
        'expiry_warning' => $expiryWarning,
    ];
}

json_success([
    'items'     => $items,
    'summary'   => [
        'total_skus'      => count($items),
        'warehouse_total' => array_sum(array_column($items, 'warehouse')),
        'low_stock_count' => count($lowStock),
        'loaded_total'    => array_sum(array_column($items, 'with_vehicles')),
    ],
    'low_stock'    => $lowStock,
    'expiry_alerts'=> $expiryAlerts,
]);
