<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);

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

$upd = db()->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
$upd->execute([$id]);

audit_log($user['id'], 'products', $id, 'deactivate', $old, ['is_active' => 0]);

json_ok(['message' => 'Product deactivated']);
