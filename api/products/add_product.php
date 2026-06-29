<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$name = trim($body['name'] ?? '');
$sku = trim($body['sku'] ?? '');
$unitPrice = (float) ($body['unit_price'] ?? 0);
$minStock = (int) ($body['min_stock'] ?? 80);

if ($name === '' || $sku === '') {
    json_error('Name and SKU are required');
}

$stmt = db()->prepare(
    'INSERT INTO products (name, sku, unit_price, min_stock) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$name, $sku, $unitPrice, $minStock]);
$id = (int) db()->lastInsertId();

audit_log($user['id'], 'products', $id, 'create', null, [
    'name' => $name, 'sku' => $sku, 'unit_price' => $unitPrice, 'min_stock' => $minStock,
]);

json_ok(['product_id' => $id], 201);
