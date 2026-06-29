<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

require_login();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
$search = trim($_GET['search'] ?? '');
$lowOnly = ($_GET['low_only'] ?? '') === '1';

$sql = stock_summary_query();
$rows = db()->query($sql)->fetchAll();

if ($search !== '') {
    $q = mb_strtolower($search);
    $rows = array_values(array_filter($rows, function ($r) use ($q) {
        return str_contains(mb_strtolower($r['name']), $q)
            || str_contains(mb_strtolower($r['sku']), $q);
    }));
}

if ($lowOnly) {
    $rows = array_values(array_filter($rows, fn($r) => (int) $r['warehouse_qty'] < (int) $r['min_stock']));
}

$total = count($rows);
$offset = ($page - 1) * $perPage;
$pageRows = array_slice($rows, $offset, $perPage);

$stock = array_map(function ($row) {
    $warehouse = (int) $row['warehouse_qty'];
    $min = (int) $row['min_stock'];
    $expiry = $row['nearest_expiry'];
    $expiringSoon = false;
    if ($expiry) {
        $days = (int) ((strtotime($expiry) - time()) / 86400);
        $expiringSoon = $days <= 30;
    }
    $levelPct = $min > 0 ? min(100, round(($warehouse / $min) * 100)) : 100;

    return [
        'product_id' => (int) $row['product_id'],
        'name' => $row['name'],
        'sku' => $row['sku'],
        'unit_price' => (float) $row['unit_price'],
        'min_stock' => $min,
        'warehouse_qty' => $warehouse,
        'on_vehicles_qty' => (int) $row['on_vehicles_qty'],
        'total_qty' => (int) $row['total_qty'],
        'sold_today' => (int) $row['sold_today'],
        'nearest_batch' => $row['nearest_batch'],
        'nearest_expiry' => $expiry,
        'expiring_soon' => $expiringSoon,
        'low_stock' => $warehouse < $min,
        'level_percent' => $levelPct,
    ];
}, $pageRows);

$totalWarehouse = array_sum(array_column($stock, 'warehouse_qty'));

json_ok([
    'stock' => $stock,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
    'summary' => [
        'total_warehouse_cartons' => $totalWarehouse,
        'low_stock_count' => count(get_low_stock_alerts()),
    ],
    'alerts' => get_low_stock_alerts(),
]);
