<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('Product ID is required');
}

$stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$old = $stmt->fetch();
if (!$old) {
    json_error('Product not found', 404);
}

$name = trim($body['name'] ?? $old['name']);
$sku = trim($body['sku'] ?? $old['sku']);
$unitPrice = isset($body['unit_price']) ? (float) $body['unit_price'] : (float) $old['unit_price'];
$minStock = isset($body['min_stock']) ? (int) $body['min_stock'] : (int) $old['min_stock'];
$isActive = isset($body['is_active']) ? (int) (bool) $body['is_active'] : (int) $old['is_active'];

$upd = db()->prepare(
    'UPDATE products SET name = ?, sku = ?, unit_price = ?, min_stock = ?, is_active = ? WHERE id = ?'
);
$upd->execute([$name, $sku, $unitPrice, $minStock, $isActive, $id]);

audit_log($user['id'], 'products', $id, 'update', $old, [
    'name' => $name, 'sku' => $sku, 'unit_price' => $unitPrice, 'min_stock' => $minStock,
]);

json_ok(['product_id' => $id]);
