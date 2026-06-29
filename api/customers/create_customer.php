<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$name = trim($body['name'] ?? '');
$phone = trim($body['phone'] ?? '') ?: null;
$location = trim($body['location'] ?? '') ?: null;
$category = $body['category'] ?? 'occasional';

if ($name === '') {
    json_error('Customer name is required');
}

if (!in_array($category, ['occasional', 'regular', 'vip'], true)) {
    json_error('Invalid category');
}

$stmt = db()->prepare(
    'INSERT INTO customers (name, phone, location, category) VALUES (?, ?, ?, ?)'
);
$stmt->execute([$name, $phone, $location, $category]);
$id = (int) db()->lastInsertId();

audit_log($user['id'], 'customers', $id, 'create', null, ['name' => $name]);

json_ok(['customer_id' => $id], 201);
