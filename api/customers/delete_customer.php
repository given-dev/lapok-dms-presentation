<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$id = (int) (read_json_body()['id'] ?? 0);
if ($id <= 0) {
    json_error('Customer ID is required');
}

$stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$old = $stmt->fetch();
if (!$old) {
    json_error('Customer not found', 404);
}

db()->prepare('UPDATE customers SET is_active = 0 WHERE id = ?')->execute([$id]);
audit_log($user['id'], 'customers', $id, 'deactivate', $old, ['is_active' => 0]);

json_ok(['message' => 'Customer deactivated']);
