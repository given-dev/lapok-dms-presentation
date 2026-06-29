<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$productId = (int) ($body['product_id'] ?? 0);
$code = trim($body['efris_item_code'] ?? '');
$name = trim($body['efris_item_name'] ?? '') ?: null;

if ($productId <= 0 || $code === '') {
    json_error('product_id and efris_item_code are required');
}

$pStmt = db()->prepare('SELECT id, name FROM products WHERE id = ? AND is_active = 1');
$pStmt->execute([$productId]);
if (!$pStmt->fetch()) {
    json_error('Product not found', 404);
}

$stmt = db()->prepare(
    'INSERT INTO efris_product_map (product_id, efris_item_code, efris_item_name)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE efris_item_code = VALUES(efris_item_code),
                             efris_item_name = VALUES(efris_item_name),
                             is_active = 1,
                             updated_at = NOW()'
);
$stmt->execute([$productId, $code, $name]);

audit_log($user['id'], 'efris_product_map', $productId, 'upsert', null, [
    'efris_item_code' => $code,
]);

json_ok(['product_id' => $productId, 'efris_item_code' => $code]);
