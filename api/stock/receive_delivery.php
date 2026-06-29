<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();

$deliveryDate = $body['delivery_date'] ?? date('Y-m-d');
$deliveryTime = $body['delivery_time'] ?? null;
$waybill = trim($body['waybill'] ?? '');
$invoice = trim($body['invoice_number'] ?? '');
$truckPlate = trim($body['truck_plate'] ?? '');
$driverName = trim($body['driver_name'] ?? '');
$condition = $body['condition_note'] ?? 'good';
$temperature = $body['temperature'] ?? 'cold';
$notes = trim($body['notes'] ?? '');
$items = $body['items'] ?? [];

if (!is_array($items) || count($items) === 0) {
    json_error('At least one product line is required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO supplier_deliveries
         (delivery_date, delivery_time, waybill, invoice_number, truck_plate, driver_name, received_by, condition_note, temperature, notes, created_by, ccba_order_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ccbaOrderId = isset($body['ccba_order_id']) ? (int) $body['ccba_order_id'] : null;
    if ($ccbaOrderId <= 0) {
        $ccbaOrderId = null;
    }
    $stmt->execute([
        $deliveryDate, $deliveryTime, $waybill, $invoice, $truckPlate, $driverName,
        $user['id'], $condition, $temperature, $notes, $user['id'], $ccbaOrderId,
    ]);
    $deliveryId = (int) $pdo->lastInsertId();

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qtyOrdered = (int) ($item['qty_ordered'] ?? 0);
        $qtyDelivered = (int) ($item['qty_delivered'] ?? 0);
        $batchNumber = trim($item['batch_number'] ?? '');
        $expiryDate = $item['expiry_date'] ?? '';
        $unitCost = (float) ($item['unit_cost'] ?? 0);

        if ($productId <= 0 || $qtyDelivered <= 0 || $batchNumber === '' || $expiryDate === '') {
            throw new RuntimeException('Each item needs product_id, qty_delivered, batch_number, expiry_date');
        }

        $itemStmt = $pdo->prepare(
            'INSERT INTO supplier_delivery_items
             (delivery_id, product_id, qty_ordered, qty_delivered, batch_number, expiry_date, unit_cost)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $itemStmt->execute([$deliveryId, $productId, $qtyOrdered, $qtyDelivered, $batchNumber, $expiryDate, $unitCost]);

        $batchStmt = $pdo->prepare(
            'SELECT id FROM batches WHERE product_id = ? AND batch_number = ?'
        );
        $batchStmt->execute([$productId, $batchNumber]);
        $existing = $batchStmt->fetch();

        if ($existing) {
            $upd = $pdo->prepare(
                'UPDATE batches SET qty_warehouse = qty_warehouse + ?, unit_cost = ?, delivery_id = ? WHERE id = ?'
            );
            $upd->execute([$qtyDelivered, $unitCost, $deliveryId, $existing['id']]);
            $batchId = (int) $existing['id'];
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO batches (product_id, batch_number, expiry_date, qty_warehouse, unit_cost, delivery_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$productId, $batchNumber, $expiryDate, $qtyDelivered, $unitCost, $deliveryId]);
            $batchId = (int) $pdo->lastInsertId();
        }

        log_stock_movement($productId, $batchId, 'stock_in', $qtyDelivered, 'supplier_delivery', $deliveryId, $user['id'], 'Coca-Cola delivery');
    }

    audit_log($user['id'], 'supplier_deliveries', $deliveryId, 'create', null, ['waybill' => $waybill]);
    $pdo->commit();

    json_ok(['delivery_id' => $deliveryId], 201);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
