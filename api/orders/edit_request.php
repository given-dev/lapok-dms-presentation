<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager', 'cadet', 'field_user']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$orderId = (int) ($body['order_id'] ?? 0);
$requestType = $body['request_type'] ?? '';
$reason = trim($body['reason'] ?? '');
$details = trim($body['details'] ?? '');

if ($orderId <= 0 || !in_array($requestType, ['edit', 'cancel'], true) || $reason === '') {
    json_error('order_id, request_type (edit|cancel), and reason are required');
}

$stmt = db()->prepare('SELECT id, user_id FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    json_error('Order not found', 404);
}

if (in_array($user['role'], ['cadet', 'field_user'], true) && (int) $order['user_id'] !== $user['id']) {
    json_error('You can only request changes on your own orders', 403);
}

$ins = db()->prepare(
    'INSERT INTO edit_requests (order_id, user_id, request_type, reason, details) VALUES (?, ?, ?, ?, ?)'
);
$ins->execute([$orderId, $user['id'], $requestType, $reason, $details]);
$id = (int) db()->lastInsertId();

audit_log($user['id'], 'edit_requests', $id, 'create', null, [
    'order_id' => $orderId, 'request_type' => $requestType,
]);

json_ok(['request_id' => $id], 201);
