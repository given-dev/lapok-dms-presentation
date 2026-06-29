<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';

require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$productId = (int) ($body['product_id'] ?? 0);
$ccbaSku = trim($body['ccba_sku_code'] ?? '');
$packDesc = trim($body['ccba_pack_desc'] ?? '') ?: null;

if ($productId <= 0) {
    json_error('product_id required');
}

$pdo = db();
if ($ccbaSku === '') {
    $pdo->prepare('DELETE FROM ccba_product_map WHERE product_id = ?')->execute([$productId]);
    json_ok(['product_id' => $productId, 'removed' => true]);
}

$stmt = $pdo->prepare(
    'INSERT INTO ccba_product_map (product_id, ccba_sku_code, ccba_pack_desc, is_active)
     VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE ccba_sku_code = VALUES(ccba_sku_code), ccba_pack_desc = VALUES(ccba_pack_desc), is_active = 1'
);
$stmt->execute([$productId, $ccbaSku, $packDesc]);

json_ok(['product_id' => $productId, 'ccba_sku_code' => $ccbaSku]);
