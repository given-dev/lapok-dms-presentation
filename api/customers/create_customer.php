<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();

if (!role_can($user['role'], 'customers_write') && !role_can($user['role'], 'customers_write_own')) {
    json_error('Insufficient permissions', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$name = trim($body['name'] ?? '');
$phone = trim($body['phone'] ?? '') ?: null;
$nin = trim($body['nin'] ?? '') ?: null;
$location = trim($body['location'] ?? '') ?: null;
$category = $body['category'] ?? 'occasional';

if ($name === '') {
    json_error('Customer name is required');
}

if (!in_array($category, ['occasional', 'regular', 'vip'], true)) {
    json_error('Invalid category');
}

$stmt = db()->prepare(
    'INSERT INTO customers (name, phone, nin, location, category) VALUES (?, ?, ?, ?, ?)'
);
$stmt->execute([$name, $phone, $nin, $location, $category]);
$id = (int) db()->lastInsertId();

audit_log($user['id'], 'customers', $id, 'create', null, compact('name', 'phone', 'nin', 'location'));

json_ok(['customer_id' => $id], 201);
