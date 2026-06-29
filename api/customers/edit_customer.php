<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_permission('customers_write');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('Customer ID is required');
}

$stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$old = $stmt->fetch();
if (!$old) {
    json_error('Customer not found', 404);
}

$name = trim($body['name'] ?? $old['name']);
$phone = array_key_exists('phone', $body) ? trim($body['phone'] ?? '') ?: null : $old['phone'];
$location = array_key_exists('location', $body) ? trim($body['location'] ?? '') ?: null : $old['location'];
$category = $body['category'] ?? $old['category'];

if (!in_array($category, ['occasional', 'regular', 'vip'], true)) {
    json_error('Invalid category');
}

$upd = db()->prepare(
    'UPDATE customers SET name = ?, phone = ?, location = ?, category = ? WHERE id = ?'
);
$upd->execute([$name, $phone, $location, $category, $id]);

audit_log($user['id'], 'customers', $id, 'update', $old, compact('name', 'phone', 'location', 'category'));

json_ok(['customer_id' => $id]);
