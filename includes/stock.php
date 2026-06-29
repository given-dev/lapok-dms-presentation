<?php
declare(strict_types=1);

/**
 * Stock helper functions
 */

function stock_summary_query(): string
{
    return "
        SELECT
            p.id AS product_id,
            p.name,
            p.sku,
            p.unit_price,
            p.min_stock,
            COALESCE(SUM(b.qty_warehouse), 0) AS warehouse_qty,
            COALESCE(SUM(b.qty_on_vehicles), 0) AS on_vehicles_qty,
            COALESCE(SUM(b.qty_warehouse), 0) + COALESCE(SUM(b.qty_on_vehicles), 0) AS total_qty,
            (
                SELECT b2.batch_number
                FROM batches b2
                WHERE b2.product_id = p.id AND b2.qty_warehouse > 0
                ORDER BY b2.expiry_date ASC
                LIMIT 1
            ) AS nearest_batch,
            (
                SELECT b2.expiry_date
                FROM batches b2
                WHERE b2.product_id = p.id AND (b2.qty_warehouse > 0 OR b2.qty_on_vehicles > 0)
                ORDER BY b2.expiry_date ASC
                LIMIT 1
            ) AS nearest_expiry,
            (
                SELECT COALESCE(SUM(oi.qty), 0)
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE oi.product_id = p.id
                  AND o.status IN ('confirmed', 'delivered')
                  AND DATE(o.created_at) = CURDATE()
            ) AS sold_today
        FROM products p
        LEFT JOIN batches b ON b.product_id = p.id
        WHERE p.is_active = 1
        GROUP BY p.id, p.name, p.sku, p.unit_price, p.min_stock
        ORDER BY p.name
    ";
}

function log_stock_movement(
    int $productId,
    ?int $batchId,
    string $type,
    int $qty,
    ?string $refType,
    ?int $refId,
    ?int $userId,
    ?string $notes = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO stock_movements (product_id, batch_id, movement_type, qty, reference_type, reference_id, user_id, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$productId, $batchId, $type, $qty, $refType, $refId, $userId, $notes]);
}

function deduct_warehouse_stock(int $productId, int $qty, ?int $userId, string $refType, int $refId): void
{
    if ($qty <= 0) {
        return;
    }

    $remaining = $qty;
    $stmt = db()->prepare(
        'SELECT id, qty_warehouse FROM batches
         WHERE product_id = ? AND qty_warehouse > 0
         ORDER BY expiry_date ASC, id ASC'
    );
    $stmt->execute([$productId]);
    $batches = $stmt->fetchAll();

    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }
        $available = (int) $batch['qty_warehouse'];
        if ($available <= 0) {
            continue;
        }
        $take = min($available, $remaining);
        $upd = db()->prepare('UPDATE batches SET qty_warehouse = qty_warehouse - ? WHERE id = ?');
        $upd->execute([$take, $batch['id']]);
        log_stock_movement($productId, (int) $batch['id'], 'dispatch', -$take, $refType, $refId, $userId, 'Dispatch deduction');
        $remaining -= $take;
    }

    if ($remaining > 0) {
        throw new RuntimeException("Insufficient warehouse stock for product #{$productId}");
    }
}

function restore_warehouse_stock(int $productId, int $qty, ?int $batchId, ?int $userId, string $refType, int $refId): void
{
    if ($qty <= 0) {
        return;
    }

    if ($batchId) {
        $upd = db()->prepare('UPDATE batches SET qty_warehouse = qty_warehouse + ? WHERE id = ?');
        $upd->execute([$qty, $batchId]);
        log_stock_movement($productId, $batchId, 'cancel_restore', $qty, $refType, $refId, $userId, 'Order cancellation restore');
        return;
    }

    $stmt = db()->prepare(
        'SELECT id FROM batches WHERE product_id = ? ORDER BY expiry_date DESC LIMIT 1'
    );
    $stmt->execute([$productId]);
    $batch = $stmt->fetch();
    if ($batch) {
        $upd = db()->prepare('UPDATE batches SET qty_warehouse = qty_warehouse + ? WHERE id = ?');
        $upd->execute([$qty, $batch['id']]);
        log_stock_movement($productId, (int) $batch['id'], 'cancel_restore', $qty, $refType, $refId, $userId);
    }
}

function get_low_stock_alerts(): array
{
    $sql = stock_summary_query();
    $rows = db()->query($sql)->fetchAll();
    $alerts = [];
    foreach ($rows as $row) {
        if ((int) $row['warehouse_qty'] < (int) $row['min_stock']) {
            $alerts[] = [
                'product_id' => (int) $row['product_id'],
                'name' => $row['name'],
                'warehouse_qty' => (int) $row['warehouse_qty'],
                'min_stock' => (int) $row['min_stock'],
            ];
        }
    }
    return $alerts;
}
