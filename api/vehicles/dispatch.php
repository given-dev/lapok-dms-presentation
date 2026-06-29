<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$vehicleId = (int) ($body['vehicle_id'] ?? 0);
$driverId = isset($body['driver_id']) ? (int) $body['driver_id'] : null;
$cadetId = isset($body['cadet_id']) ? (int) $body['cadet_id'] : null;
$routeId = isset($body['route_id']) ? (int) $body['route_id'] : null;
$routeArea = trim($body['route_area'] ?? '');
$odometerStart = isset($body['odometer_start']) ? (int) $body['odometer_start'] : null;
$notes = trim($body['notes'] ?? '');
$loadItems = $body['load_items'] ?? [];
$orderIds = $body['order_ids'] ?? [];

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

    $tripStmt = $pdo->prepare(
        'INSERT INTO delivery_trips (vehicle_id, driver_id, cadet_id, route_id, route_area, status, odometer_start, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $tripStmt->execute([
        $vehicleId, $driverId, $cadetId, $routeId, $routeArea ?: $vehicle['current_route'],
        'dispatched', $odometerStart, $notes,
    ]);
    $tripId = (int) $pdo->lastInsertId();

    $totalLoad = 0;
    foreach ($loadItems as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('Each load item needs product_id and qty');
        }

        deduct_warehouse_stock($productId, $qty, $user['id'], 'trip', $tripId);

        $batchStmt = $pdo->prepare(
            'SELECT id, qty_warehouse FROM batches WHERE product_id = ? AND qty_warehouse >= 0 ORDER BY expiry_date ASC LIMIT 1'
        );
        $batchStmt->execute([$productId]);
        $batch = $batchStmt->fetch();

        if ($batch) {
            $pdo->prepare('UPDATE batches SET qty_on_vehicles = qty_on_vehicles + ? WHERE id = ?')
                ->execute([$qty, $batch['id']]);
        }

        $pdo->prepare(
            'INSERT INTO trip_load_items (trip_id, product_id, batch_id, qty_loaded) VALUES (?, ?, ?, ?)'
        )->execute([$tripId, $productId, $batch['id'] ?? null, $qty]);

        $totalLoad += $qty;
    }

    $pdo->prepare('UPDATE vehicles SET status = ?, current_route = ?, driver_id = ?, cadet_id = ? WHERE id = ?')
        ->execute(['on_route', $routeArea ?: $vehicle['current_route'], $driverId, $cadetId, $vehicleId]);

    if (is_array($orderIds) && count($orderIds) > 0) {
        foreach ($orderIds as $oid) {
            $oid = (int) $oid;
            $pdo->prepare(
                'UPDATE orders SET status = ?, trip_id = ?, vehicle_id = ? WHERE id = ? AND status IN (?, ?)'
            )->execute(['dispatched', $tripId, $vehicleId, $oid, 'confirmed', 'pending']);
        }
    }

    audit_log($user['id'], 'delivery_trips', $tripId, 'dispatch', null, [
        'vehicle_id' => $vehicleId, 'total_load' => $totalLoad,
    ]);

    $pdo->commit();

    json_ok([
        'trip_id' => $tripId,
        'vehicle_id' => $vehicleId,
        'total_load' => $totalLoad,
        'status' => 'dispatched',
    ], 201);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
