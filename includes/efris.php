<?php
declare(strict_types=1);

function efris_config(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT config_value FROM efris_config WHERE config_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && trim((string) $row['config_value']) !== '') {
            return trim((string) $row['config_value']);
        }
    } catch (Throwable) {
        // Table may not exist before migration.
    }
    return $default;
}

function efris_ingest_authorized(): bool
{
    $expected = efris_config('ingest_api_key');
    if ($expected === '') {
        return false;
    }
    $header = $_SERVER['HTTP_X_EFRIS_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    return hash_equals($expected, (string) $header);
}

/** @return array{product_id:int,name:string,unit_price:float}|null */
function efris_resolve_product(?string $itemCode, ?string $itemName): ?array
{
    $pdo = db();
    if ($itemCode !== null && trim($itemCode) !== '') {
        $stmt = $pdo->prepare(
            'SELECT p.id AS product_id, p.name, p.unit_price
             FROM efris_product_map m
             JOIN products p ON p.id = m.product_id AND p.is_active = 1
             WHERE m.efris_item_code = ? AND m.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([trim($itemCode)]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'product_id' => (int) $row['product_id'],
                'name' => $row['name'],
                'unit_price' => (float) $row['unit_price'],
            ];
        }
    }

    if ($itemName !== null && trim($itemName) !== '') {
        $stmt = $pdo->prepare(
            'SELECT id AS product_id, name, unit_price FROM products
             WHERE is_active = 1 AND name = ? LIMIT 1'
        );
        $stmt->execute([trim($itemName)]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'product_id' => (int) $row['product_id'],
                'name' => $row['name'],
                'unit_price' => (float) $row['unit_price'],
            ];
        }
    }

    return null;
}

/** @return array<int, array<string, mixed>> */
function efris_active_trip_for_user(int $userId): ?array
{
    $stmt = db()->prepare(
        "SELECT dt.*, v.registration
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         WHERE (dt.cadet_id = ? OR dt.driver_id = ?)
           AND dt.status IN ('dispatched','on_route','returned')
         ORDER BY dt.dispatched_at DESC
         LIMIT 1"
    );
    $stmt->execute([$userId, $userId]);
    $trip = $stmt->fetch();
    return $trip ?: null;
}

/**
 * Import a fiscal receipt from device push or EFRIS API payload.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function efris_ingest_receipt(array $body, string $source = 'device_push'): array
{
    $invoiceNo = trim((string) ($body['efris_invoice_no'] ?? $body['invoice_no'] ?? ''));
    if ($invoiceNo === '') {
        throw new RuntimeException('efris_invoice_no is required');
    }

    $pdo = db();
    $dup = $pdo->prepare('SELECT id, status, order_id FROM efris_receipts WHERE efris_invoice_no = ? LIMIT 1');
    $dup->execute([$invoiceNo]);
    $existing = $dup->fetch();
    if ($existing) {
        return [
            'receipt_id' => (int) $existing['id'],
            'duplicate' => true,
            'status' => $existing['status'],
            'order_id' => $existing['order_id'] ? (int) $existing['order_id'] : null,
        ];
    }

    $items = $body['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('At least one line item is required');
    }

    $fiscalTs = trim((string) ($body['fiscal_timestamp'] ?? $body['fiscal_date'] ?? ''));
    if ($fiscalTs === '') {
        $fiscalTs = date('Y-m-d H:i:s');
    }

    $payment = strtolower(trim((string) ($body['payment_type'] ?? 'cash')));
    if (!in_array($payment, ['cash', 'credit', 'other'], true)) {
        $payment = 'cash';
    }

    $deviceSerial = trim((string) ($body['device_serial'] ?? '')) ?: null;
    $amountTotal = isset($body['amount_total']) ? (float) $body['amount_total'] : 0.0;

    $lineRows = [];
    $computedTotal = 0.0;
    $hasUnmapped = false;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $code = isset($item['efris_item_code']) ? trim((string) $item['efris_item_code']) : null;
        $name = trim((string) ($item['item_name'] ?? $item['name'] ?? 'Item'));
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $subtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : $unitPrice * $qty;
        $computedTotal += $subtotal;

        $product = efris_resolve_product($code, $name);
        $mapStatus = $product ? 'mapped' : 'unmapped';
        if (!$product) {
            $hasUnmapped = true;
        }

        $lineRows[] = [
            'product_id' => $product['product_id'] ?? null,
            'efris_item_code' => $code,
            'item_name' => $product['name'] ?? $name,
            'qty' => $qty,
            'unit_price' => $unitPrice > 0 ? $unitPrice : ($product['unit_price'] ?? 0),
            'subtotal' => $subtotal,
            'map_status' => $mapStatus,
        ];
    }

    if (count($lineRows) === 0) {
        throw new RuntimeException('No valid line items in payload');
    }

    if ($amountTotal <= 0) {
        $amountTotal = $computedTotal;
    }

    $status = $hasUnmapped ? 'unmapped' : 'pending_link';
    $vehicleId = isset($body['vehicle_id']) ? (int) $body['vehicle_id'] : null;
    $tripId = isset($body['trip_id']) ? (int) $body['trip_id'] : null;

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO efris_receipts
             (efris_invoice_no, device_serial, fiscal_timestamp, amount_total, payment_type, status, source, vehicle_id, trip_id, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $invoiceNo,
            $deviceSerial,
            $fiscalTs,
            $amountTotal,
            $payment,
            $status,
            $source,
            $vehicleId ?: null,
            $tripId ?: null,
            json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $receiptId = (int) $pdo->lastInsertId();

        $itemIns = $pdo->prepare(
            'INSERT INTO efris_receipt_items
             (receipt_id, product_id, efris_item_code, item_name, qty, unit_price, subtotal, map_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lineRows as $line) {
            $itemIns->execute([
                $receiptId,
                $line['product_id'],
                $line['efris_item_code'],
                $line['item_name'],
                $line['qty'],
                $line['unit_price'],
                $line['subtotal'],
                $line['map_status'],
            ]);
        }

        $pdo->commit();

        return [
            'receipt_id' => $receiptId,
            'efris_invoice_no' => $invoiceNo,
            'status' => $status,
            'amount_total' => $amountTotal,
            'duplicate' => false,
            'unmapped_items' => $hasUnmapped,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Link a pending fiscal receipt to a Lapok customer and create an order.
 *
 * @return array<string, mixed>
 */
function efris_link_receipt(int $receiptId, int $customerId, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM efris_receipts WHERE id = ? FOR UPDATE');
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch();
        if (!$receipt) {
            throw new RuntimeException('Fiscal receipt not found');
        }
        if ($receipt['status'] === 'linked') {
            throw new RuntimeException('Receipt already linked to Lapok');
        }
        if ($receipt['status'] === 'ignored') {
            throw new RuntimeException('Receipt was marked as ignored');
        }

        $cStmt = $pdo->prepare('SELECT id, name FROM customers WHERE id = ? AND is_active = 1');
        $cStmt->execute([$customerId]);
        $customer = $cStmt->fetch();
        if (!$customer) {
            throw new RuntimeException('Customer not found');
        }

        $itemsStmt = $pdo->prepare('SELECT * FROM efris_receipt_items WHERE receipt_id = ?');
        $itemsStmt->execute([$receiptId]);
        $lines = $itemsStmt->fetchAll();
        if (count($lines) === 0) {
            throw new RuntimeException('Receipt has no line items');
        }

        foreach ($lines as $line) {
            if ($line['map_status'] !== 'mapped' || !$line['product_id']) {
                throw new RuntimeException(
                    'Cannot link: unmapped product "' . $line['item_name'] . '". Ask admin to map EFRIS item codes.'
                );
            }
        }

        $trip = efris_active_trip_for_user((int) $user['id']);
        $tripId = $trip ? (int) $trip['id'] : ($receipt['trip_id'] ? (int) $receipt['trip_id'] : null);
        $vehicleId = $user['vehicle_id'] ?? null;
        if ($trip) {
            $vehicleId = (int) $trip['vehicle_id'];
        } elseif ($receipt['vehicle_id']) {
            $vehicleId = (int) $receipt['vehicle_id'];
        }

        $paymentType = $receipt['payment_type'] === 'credit' ? 'credit' : 'cash';
        $total = (float) $receipt['amount_total'];
        $amountPaid = $paymentType === 'cash' ? $total : 0.0;
        $orderRef = generate_order_ref();

        $orderIns = $pdo->prepare(
            'INSERT INTO orders (order_ref, customer_id, user_id, trip_id, vehicle_id, status, payment_type, amount_total, amount_paid, efris_ref, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $orderIns->execute([
            $orderRef,
            $customerId,
            $user['id'],
            $tripId,
            $vehicleId,
            'pending',
            $paymentType,
            $total,
            $amountPaid,
            $receipt['efris_invoice_no'],
            'Imported from fiscal device',
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $oiIns = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, qty, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $oiIns->execute([
                $orderId,
                (int) $line['product_id'],
                (int) $line['qty'],
                (float) $line['unit_price'],
                (float) $line['subtotal'],
            ]);

            if ($tripId) {
                $tliUpd = $pdo->prepare(
                    'UPDATE trip_load_items SET qty_sold = qty_sold + ?
                     WHERE trip_id = ? AND product_id = ?'
                );
                $tliUpd->execute([(int) $line['qty'], $tripId, (int) $line['product_id']]);
            }
        }

        if ($paymentType === 'credit') {
            $balance = $total - $amountPaid;
            if ($balance > 0) {
                $upd = $pdo->prepare('UPDATE customers SET credit_balance = credit_balance + ? WHERE id = ?');
                $upd->execute([$balance, $customerId]);
            }
        }

        $linkUpd = $pdo->prepare(
            "UPDATE efris_receipts
             SET status = 'linked', order_id = ?, customer_id = ?, linked_by = ?, trip_id = ?, vehicle_id = ?, linked_at = NOW()
             WHERE id = ?"
        );
        $linkUpd->execute([$orderId, $customerId, $user['id'], $tripId, $vehicleId, $receiptId]);

        audit_log((int) $user['id'], 'efris_receipts', $receiptId, 'link', null, [
            'order_id' => $orderId,
            'order_ref' => $orderRef,
            'customer_id' => $customerId,
            'efris_invoice_no' => $receipt['efris_invoice_no'],
        ]);

        $pdo->commit();

        return [
            'receipt_id' => $receiptId,
            'order_id' => $orderId,
            'order_ref' => $orderRef,
            'efris_ref' => $receipt['efris_invoice_no'],
            'customer_name' => $customer['name'],
            'status' => 'linked',
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
