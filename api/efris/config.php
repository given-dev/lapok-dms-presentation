<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/efris.php';

require_permission('efris_view');

$configKeys = ['integration_mode', 'seller_tin', 'default_device_serial'];
$config = [];
foreach ($configKeys as $key) {
    $config[$key] = efris_config($key);
}
$config['ingest_configured'] = efris_config('ingest_api_key') !== '';

$products = db()->query(
    'SELECT p.id, p.name, p.sku, p.unit_price, m.efris_item_code, m.efris_item_name
     FROM products p
     LEFT JOIN efris_product_map m ON m.product_id = p.id AND m.is_active = 1
     WHERE p.is_active = 1
     ORDER BY p.name'
)->fetchAll();

$maps = [];
foreach ($products as $p) {
    $maps[] = [
        'product_id' => (int) $p['id'],
        'name' => $p['name'],
        'sku' => $p['sku'],
        'unit_price' => (float) $p['unit_price'],
        'efris_item_code' => $p['efris_item_code'],
        'efris_item_name' => $p['efris_item_name'],
        'mapped' => $p['efris_item_code'] !== null,
    ];
}

json_ok([
    'config' => $config,
    'product_maps' => $maps,
    'ingest_endpoint' => '/api/efris/ingest.php',
]);
