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
$vehicleId = (int) ($body['vehicle_id'] ?? 0);
$loadItems = $body['load_items'] ?? [];

if ($vehicleId <= 0) {
    json_error('vehicle_id is required');
}
if (!is_array($loadItems) || count($loadItems) === 0) {
    json_error('At least one load item is required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $vStmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ? AND is_active = 1 FOR UPDATE');
    $vStmt->execute([$vehicleId]);
    $vehicle = $vStmt->fetch();
    if (!$vehicle) {
        throw new RuntimeException('Vehicle not found');
    }
    if (in_array((string) $vehicle['status'], ['available', 'maintenance', 'inactive'], true)) {
        throw new RuntimeException('Vehicle has no open trip. Dispatch it first before reloading.');
    }

    $tStmt = $pdo->prepare(
        "SELECT id, cadet_id, route_area FROM delivery_trips
         WHERE vehicle_id = ? AND status IN ('dispatched','on_route')
         ORDER BY dispatched_at DESC LIMIT 1 FOR UPDATE"
    );
    $tStmt->execute([$vehicleId]);
    $trip = $tStmt->fetch();
    if (!$trip) {
        throw new RuntimeException('No active trip found for this vehicle.');
    }
    $tripId = (int) $trip['id'];

    $totalReload = 0;
    foreach ($loadItems as $item) {
        $rdcKey = trim((string) ($item['rdc_key'] ?? ''));
        $qty = (int) ($item['qty'] ?? 0);

        if ($rdcKey !== '') {
            if ($qty <= 0) {
                throw new RuntimeException('Each load item needs rdc_key and qty');
            }
            if ($qty > 100000) {
                throw new RuntimeException('Load quantity is unreasonably high');
            }
            $parts = depot_split_pack_qty($rdcKey, $qty);
            if (count($parts) === 0) {
                throw new RuntimeException("No warehouse stock for pack '{$rdcKey}'");
            }
        } else {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                throw new RuntimeException('Each load item needs rdc_key or product_id, and qty');
            }
            if ($qty > 100000) {
                throw new RuntimeException('Load quantity is unreasonably high');
            }
            $parts = [['product_id' => $productId, 'qty' => $qty]];
        }

        foreach ($parts as $part) {
            $productId = (int) $part['product_id'];
            $partQty = (int) $part['qty'];
            if ($partQty <= 0) {
                continue;
            }

            deduct_warehouse_stock($productId, $partQty, $user['id'], 'trip', $tripId);

            $batchStmt = $pdo->prepare(
                'SELECT id FROM batches WHERE product_id = ? AND qty_warehouse >= 0 ORDER BY expiry_date ASC LIMIT 1'
            );
            $batchStmt->execute([$productId]);
            $batch = $batchStmt->fetch();
            $batchId = $batch ? (int) $batch['id'] : null;

            if ($batchId) {
                $pdo->prepare('UPDATE batches SET qty_on_vehicles = qty_on_vehicles + ? WHERE id = ?')
                    ->execute([$partQty, $batchId]);
            }

            $findStmt = $pdo->prepare(
                'SELECT id FROM trip_load_items WHERE trip_id = ? AND product_id = ? AND batch_id <=> ? LIMIT 1'
            );
            $findStmt->execute([$tripId, $productId, $batchId]);
            $existing = $findStmt->fetch();

            if ($existing) {
                $pdo->prepare('UPDATE trip_load_items SET qty_loaded = qty_loaded + ? WHERE id = ?')
                    ->execute([$partQty, (int) $existing['id']]);
            } else {
                $pdo->prepare(
                    'INSERT INTO trip_load_items (trip_id, product_id, batch_id, qty_loaded) VALUES (?, ?, ?, ?)'
                )->execute([$tripId, $productId, $batchId, $partQty]);
            }

            $totalReload += $partQty;
        }
    }

    audit_log((int) $user['id'], 'delivery_trips', $tripId, 'reload', null, [
        'vehicle_id' => $vehicleId,
        'total_reload' => $totalReload,
    ]);

    $pdo->commit();

    $cadetId = (int) ($trip['cadet_id'] ?? 0);
    if ($cadetId > 0) {
        try {
            require_once dirname(__DIR__, 2) . '/includes/notifications.php';
            notify_user($cadetId, 'Vehicle reloaded', sprintf(
                '%s reloaded with %d crates. Keep your report going for the full day.',
                $vehicle['registration'],
                $totalReload
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
        'vehicle_id' => $vehicleId,
        'total_reload' => $totalReload,
        'status' => (string) $vehicle['status'],
    ], 201);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
