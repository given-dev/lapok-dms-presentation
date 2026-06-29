<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager', 'cadet', 'field_user']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$customerId = isset($body['customer_id']) ? (int) $body['customer_id'] : null;
$paymentType = $body['payment_type'] ?? 'cash';
$amountPaid = (float) ($body['amount_paid'] ?? 0);
$efrisRef = trim($body['efris_ref'] ?? '') ?: null;
$tripId = isset($body['trip_id']) ? (int) $body['trip_id'] : null;
$vehicleId = isset($body['vehicle_id']) ? (int) $body['vehicle_id'] : ($user['vehicle_id'] ?? null);
$items = $body['items'] ?? [];
$notes = trim($body['notes'] ?? '');

if (!in_array($paymentType, ['cash', 'credit'], true)) {
    json_error('Invalid payment type');
}

if (!is_array($items) || count($items) === 0) {
    json_error('At least one order item is required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $total = 0;
    $lineItems = [];

    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('Invalid item: product_id and qty required');
        }

        $pStmt = $pdo->prepare('SELECT unit_price, name FROM products WHERE id = ? AND is_active = 1');
        $pStmt->execute([$productId]);
        $product = $pStmt->fetch();
        if (!$product) {
            throw new RuntimeException("Product #{$productId} not found");
        }

        $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : (float) $product['unit_price'];
        $subtotal = $unitPrice * $qty;
        $total += $subtotal;
        $lineItems[] = [
            'product_id' => $productId,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
        ];
    }

    if ($paymentType === 'cash' && $amountPaid <= 0) {
        $amountPaid = $total;
    }

    $orderRef = generate_order_ref();
    $status = 'pending';

    $stmt = $pdo->prepare(
        'INSERT INTO orders (order_ref, customer_id, user_id, trip_id, vehicle_id, status, payment_type, amount_total, amount_paid, efris_ref, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $orderRef, $customerId, $user['id'], $tripId, $vehicleId,
        $status, $paymentType, $total, $amountPaid, $efrisRef, $notes,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $itemIns = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, qty, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($lineItems as $li) {
        $itemIns->execute([$orderId, $li['product_id'], $li['qty'], $li['unit_price'], $li['subtotal']]);
    }

    if ($paymentType === 'credit' && $customerId) {
        $upd = $pdo->prepare('UPDATE customers SET credit_balance = credit_balance + ? WHERE id = ?');
        $upd->execute([$total - $amountPaid, $customerId]);
    }

    audit_log($user['id'], 'orders', $orderId, 'create', null, [
        'order_ref' => $orderRef, 'amount_total' => $total, 'status' => $status,
    ]);

    $pdo->commit();

    json_ok([
        'order_id' => $orderId,
        'order_ref' => $orderRef,
        'amount_total' => $total,
        'status' => $status,
    ], 201);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
