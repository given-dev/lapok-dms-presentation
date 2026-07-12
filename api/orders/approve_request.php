<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$actionRaw = strtolower(trim((string) ($body['action'] ?? '')));
$action = match ($actionRaw) {
    'approve', 'approved' => 'approve',
    'reject', 'rejected' => 'reject',
    default => '',
};
$requestId = (int) ($body['request_id'] ?? 0);

if ($requestId <= 0 || $action === '') {
    json_error('request_id and action (approve|reject) are required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare('SELECT * FROM edit_requests WHERE id = ? AND status = ? FOR UPDATE');
    $stmt->execute([$requestId, 'pending']);
    $request = $stmt->fetch();

    if (!$request) {
        throw new RuntimeException('Pending request not found');
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $upd = $pdo->prepare(
        'UPDATE edit_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
    );
    $upd->execute([$newStatus, $user['id'], $requestId]);

    if ($action === 'approve' && $request['request_type'] === 'cancel') {
        $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $orderStmt->execute([$request['order_id']]);
        $order = $orderStmt->fetch();

        if ($order && $order['status'] !== 'cancelled') {
            $itemStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
            $itemStmt->execute([$order['id']]);
            foreach ($itemStmt->fetchAll() as $item) {
                if (in_array($order['status'], ['dispatched', 'delivered'], true)) {
                    restore_warehouse_stock(
                        (int) $item['product_id'],
                        (int) $item['qty'],
                        $item['batch_id'] ? (int) $item['batch_id'] : null,
                        $user['id'],
                        'order',
                        (int) $order['id']
                    );
                }
            }

            $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute(['cancelled', $order['id']]);
        }
    }

    audit_log($user['id'], 'edit_requests', $requestId, $action, ['status' => 'pending'], ['status' => $newStatus]);

    $pdo->commit();
    json_ok(['request_id' => $requestId, 'status' => $newStatus]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 400);
}
