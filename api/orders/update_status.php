<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$orderId = (int) ($body['order_id'] ?? 0);
$newStatus = $body['status'] ?? '';

$allowed = ['draft', 'pending', 'confirmed', 'dispatched', 'delivered', 'cancelled'];
if ($orderId <= 0 || !in_array($newStatus, $allowed, true)) {
    json_error('order_id and valid status are required');
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

    $oldStatus = $order['status'];

  // Lifecycle transitions
    $validTransitions = [
        'draft' => ['pending', 'cancelled'],
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['dispatched', 'delivered', 'cancelled'],
        'dispatched' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    if (!in_array($newStatus, $validTransitions[$oldStatus] ?? [], true)) {
        throw new RuntimeException("Cannot transition from {$oldStatus} to {$newStatus}");
    }

    if ($newStatus === 'cancelled') {
        $itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $itemStmt->execute([$orderId]);
        foreach ($itemStmt->fetchAll() as $item) {
            if (in_array($oldStatus, ['dispatched', 'delivered'], true)) {
                restore_warehouse_stock(
                    (int) $item['product_id'],
                    (int) $item['qty'],
                    $item['batch_id'] ? (int) $item['batch_id'] : null,
                    $user['id'],
                    'order',
                    $orderId
                );
            }
        }

        if ($order['payment_type'] === 'credit' && $order['customer_id']) {
            $balance = (float) $order['amount_total'] - (float) $order['amount_paid'];
            if ($balance > 0) {
                $upd = $pdo->prepare('UPDATE customers SET credit_balance = GREATEST(0, credit_balance - ?) WHERE id = ?');
                $upd->execute([$balance, $order['customer_id']]);
            }
        }
    }

    $extra = '';
    if ($newStatus === 'delivered') {
        $extra = ', delivered_at = NOW()';
    }

    $upd = $pdo->prepare("UPDATE orders SET status = ?{$extra} WHERE id = ?");
    $upd->execute([$newStatus, $orderId]);

    audit_log($user['id'], 'orders', $orderId, 'status_change', ['status' => $oldStatus], ['status' => $newStatus]);

    $pdo->commit();
    json_ok(['order_id' => $orderId, 'status' => $newStatus]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 400);
}
