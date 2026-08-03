<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';
require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$tripId = (int) ($body['trip_id'] ?? 0);
$items = $body['items'] ?? [];

if ($tripId <= 0) {
    json_error('trip_id is required');
}
if (!is_array($items)) {
    json_error('items must be an array of { product_id, qty }');
}

$normalized = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $productId = (int) ($item['product_id'] ?? 0);
    $qty = (int) ($item['qty'] ?? 0);
    if ($productId <= 0 || $qty < 0) {
        json_error('Each item needs a product_id and a non-negative qty');
    }
    if ($qty > 100000) {
        json_error('Load quantity is unreasonably high');
    }
    $normalized[$productId] = $qty;
}

if (count($normalized) === 0) {
    json_error('At least one load item is required');
}
if (array_sum($normalized) <= 0) {
    json_error('Total load quantity cannot be zero');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $tStmt = $pdo->prepare(
        "SELECT dt.*, v.registration, v.vehicle_type, u.full_name AS cadet_name
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         LEFT JOIN users u ON u.id = dt.cadet_id
         WHERE dt.id = ? LIMIT 1 FOR UPDATE"
    );
    $tStmt->execute([$tripId]);
    $trip = $tStmt->fetch();
    if (!$trip) {
        throw new RuntimeException('Trip not found');
    }
    if (!in_array((string) $trip['status'], ['dispatched', 'on_route'], true)) {
        throw new RuntimeException('Only open trips (dispatched / on route) can have their load edited');
    }

    $lineStmt = $pdo->prepare(
        'SELECT id, product_id, batch_id, qty_loaded, qty_sold, qty_returned
         FROM trip_load_items WHERE trip_id = ? ORDER BY id ASC'
    );
    $lineStmt->execute([$tripId]);
    $lines = $lineStmt->fetchAll();

    foreach ($lines as $line) {
        if ((int) $line['qty_sold'] > 0 || (int) $line['qty_returned'] > 0) {
            throw new RuntimeException('This trip already has sales/returns recorded  -  load can no longer be edited');
        }
    }

    $previousTotal = array_sum(array_map(fn($l) => (int) $l['qty_loaded'], $lines));

    // 1) Reverse the current load back into the warehouse (cancels the deduction).
    $reverseBatch = $pdo->prepare('UPDATE batches SET qty_warehouse = qty_warehouse + ?, qty_on_vehicles = qty_on_vehicles - ? WHERE id = ?');
    foreach ($lines as $line) {
        $qty = (int) $line['qty_loaded'];
        if ($qty <= 0) {
            continue;
        }
        $productId = (int) $line['product_id'];
        $batchId = $line['batch_id'] !== null ? (int) $line['batch_id'] : null;
        if ($batchId) {
            $reverseBatch->execute([$qty, $qty, $batchId]);
        } else {
            restore_warehouse_stock($productId, $qty, null, (int) $user['id'], 'trip', $tripId);
        }
        log_stock_movement($productId, $batchId, 'cancel_restore', $qty, 'trip', $tripId, (int) $user['id'], 'Edit dispatch - reverse previous load');
        $pdo->prepare('UPDATE trip_load_items SET qty_loaded = 0, batch_id = NULL WHERE id = ?')->execute([(int) $line['id']]);
    }

    // 2) Re-deduct the corrected load.
    $totalLoad = 0;
    foreach ($normalized as $productId => $qty) {
        $qty = (int) $qty;
        if ($qty <= 0) {
            continue;
        }
        deduct_warehouse_stock($productId, $qty, (int) $user['id'], 'trip', $tripId);

        $batchStmt = $pdo->prepare(
            'SELECT id FROM batches WHERE product_id = ? AND qty_warehouse >= 0 ORDER BY expiry_date ASC, id ASC LIMIT 1'
        );
        $batchStmt->execute([$productId]);
        $batch = $batchStmt->fetch();
        $batchId = $batch ? (int) $batch['id'] : null;
        if ($batchId) {
            $pdo->prepare('UPDATE batches SET qty_on_vehicles = qty_on_vehicles + ? WHERE id = ?')
                ->execute([$qty, $batchId]);
        }

        $findStmt = $pdo->prepare(
            'SELECT id FROM trip_load_items WHERE trip_id = ? AND product_id = ? LIMIT 1'
        );
        $findStmt->execute([$tripId, $productId]);
        $existing = $findStmt->fetch();
        if ($existing) {
            $pdo->prepare('UPDATE trip_load_items SET qty_loaded = ?, batch_id = ? WHERE id = ?')
                ->execute([$qty, $batchId, (int) $existing['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO trip_load_items (trip_id, product_id, batch_id, qty_loaded) VALUES (?, ?, ?, ?)'
            )->execute([$tripId, $productId, $batchId, $qty]);
        }
        $totalLoad += $qty;
    }

    audit_log((int) $user['id'], 'delivery_trips', $tripId, 'edit_load', null, [
        'vehicle_id' => (int) ($trip['vehicle_id'] ?? 0),
        'previous_load' => $previousTotal,
        'new_load' => $totalLoad,
        'delta' => $totalLoad - $previousTotal,
    ]);

    $pdo->commit();

    $cadetId = (int) ($trip['cadet_id'] ?? 0);
    if ($cadetId > 0 && $totalLoad !== $previousTotal) {
        try {
            require_once dirname(__DIR__, 2) . '/includes/notifications.php';
            notify_user($cadetId, 'Dispatch load corrected', sprintf(
                '%s load edited: %d crates (was %d). Your report should reflect the corrected load.',
                (string) ($trip['registration'] ?? 'Vehicle'),
                $totalLoad,
                $previousTotal
            ), [
                'sender_id' => (int) $user['id'],
                'sender_role' => $user['role'],
                'severity' => 'info',
                'link_page' => 'cadet-dashboard',
            ]);
        } catch (Throwable) {
        }
    }

    json_ok([
        'trip_id' => $tripId,
        'previous_load' => $previousTotal,
        'total_load' => $totalLoad,
        'status' => 'edited',
    ], 200);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
