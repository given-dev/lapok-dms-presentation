<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';
require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';

require_login();

depot_ensure_warehouse_products();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(200, max(10, (int) ($_GET['per_page'] ?? 100)));
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
    $name = (string) $row['name'];
    $sku = (string) ($row['sku'] ?? '');
    $brand = '';
    foreach (depot_manager_warehouse_catalog() as $def) {
        if (strcasecmp((string) $def['sku'], $sku) === 0) {
            $brand = (string) $def['brand'];
            break;
        }
    }

    return [
        'product_id' => (int) $row['product_id'],
        'name' => $name,
        'sku' => $sku,
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
        'category' => $brand !== '' ? $brand : depot_category_for_product($name, $sku),
        'brand' => $brand,
    ];
}, $pageRows);

$order = array_flip(depot_stock_brand_order());
usort($stock, function ($a, $b) use ($order) {
    $ca = $order[$a['category'] ?? ''] ?? 99;
    $cb = $order[$b['category'] ?? ''] ?? 99;
    if ($ca !== $cb) {
        return $ca <=> $cb;
    }
    return strcasecmp((string) $a['name'], (string) $b['name']);
});

// Summary over all filtered rows (not just the current page).
$totalWarehouse = (int) array_sum(array_map(static fn($r) => (int) $r['warehouse_qty'], $rows));

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
