<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$orderId = (int) ($body['order_id'] ?? 0);

if ($orderId <= 0) {
    json_error('order_id is required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order not found');
    }

    if ($order['status'] !== 'pending') {
        throw new RuntimeException('Only pending orders can be confirmed');
    }

    $upd = $pdo->prepare(
        'UPDATE orders SET status = ?, confirmed_at = NOW(), confirmed_by = ? WHERE id = ?'
    );
    $upd->execute(['confirmed', $user['id'], $orderId]);

    audit_log($user['id'], 'orders', $orderId, 'confirm', ['status' => 'pending'], ['status' => 'confirmed']);

    $pdo->commit();
    json_ok(['order_id' => $orderId, 'status' => 'confirmed']);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 400);
}
