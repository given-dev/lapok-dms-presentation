<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/ccba.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$orderId = (int) ($body['order_id'] ?? 0);
$markReady = !empty($body['mark_ready']);
$items = $body['items'] ?? [];
$notes = trim($body['notes'] ?? '');
$requestedDate = trim($body['requested_delivery_date'] ?? '');

if (!is_array($items) || count($items) === 0) {
    json_error('At least one order line is required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $isNew = $orderId <= 0;
    if ($orderId > 0) {
        $check = $pdo->prepare('SELECT id, status FROM ccba_orders WHERE id = ? FOR UPDATE');
        $check->execute([$orderId]);
        $existing = $check->fetch();
        if (!$existing) {
            throw new RuntimeException('Order not found');
        }
        if (!ccba_editable_status((string) $existing['status'])) {
            throw new RuntimeException('Order can no longer be edited');
        }
    } else {
        $lapokRef = generate_lapok_ccba_ref();
        $ins = $pdo->prepare(
            'INSERT INTO ccba_orders (lapok_ref, status, submission_mode, created_by, notes, requested_delivery_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $reqDate = $requestedDate !== '' ? $requestedDate : null;
        $ins->execute([$lapokRef, 'draft', 'assisted_portal', $user['id'], $notes ?: null, $reqDate]);
        $orderId = (int) $pdo->lastInsertId();
        ccba_log_status($orderId, 'draft', 'lapok', (int) $user['id'], 'Draft created in Lapok');
    }

    $upd = $pdo->prepare(
        'UPDATE ccba_orders SET notes = ?, requested_delivery_date = ?, updated_at = NOW() WHERE id = ?'
    );
    $upd->execute([
        $notes !== '' ? $notes : null,
        $requestedDate !== '' ? $requestedDate : null,
        $orderId,
    ]);

    $pdo->prepare('DELETE FROM ccba_order_items WHERE ccba_order_id = ?')->execute([$orderId]);

    $itemStmt = $pdo->prepare(
        'INSERT INTO ccba_order_items (ccba_order_id, product_id, ccba_sku_code, qty_requested, unit_cost_estimate)
         VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($items as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        $qty = (int) ($line['qty_requested'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $ccbaSku = trim($line['ccba_sku_code'] ?? '');
        if ($ccbaSku === '') {
            $map = $pdo->prepare('SELECT ccba_sku_code FROM ccba_product_map WHERE product_id = ? AND is_active = 1 LIMIT 1');
            $map->execute([$productId]);
            $mapped = $map->fetch();
            $ccbaSku = $mapped['ccba_sku_code'] ?? null;
        }
        $unitCost = (float) ($line['unit_cost_estimate'] ?? 0);
        if ($unitCost <= 0) {
            $p = $pdo->prepare('SELECT unit_price FROM products WHERE id = ?');
            $p->execute([$productId]);
            $prod = $p->fetch();
            $unitCost = $prod ? (float) $prod['unit_price'] * 0.6 : 0;
        }
        $itemStmt->execute([$orderId, $productId, $ccbaSku ?: null, $qty, $unitCost]);
    }

    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM ccba_order_items WHERE ccba_order_id = ' . (int) $orderId
    )->fetchColumn();
    if ($count === 0) {
        throw new RuntimeException('At least one line with quantity is required');
    }

    if ($markReady) {
        $pdo->prepare('UPDATE ccba_orders SET status = ? WHERE id = ?')->execute(['ready_for_ccba', $orderId]);
        ccba_log_status($orderId, 'ready_for_ccba', 'manager', (int) $user['id'], 'Ready for CCBA submission');
    }

    audit_log((int) $user['id'], 'ccba_orders', $orderId, $isNew ? 'create' : 'update');

    $pdo->commit();
    json_ok(['order' => ccba_fetch_order($orderId)], $isNew ? 201 : 200);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 400);
}
