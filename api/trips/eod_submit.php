<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();

if (!role_can($user['role'], 'eod') && !role_can($user['role'], 'dashboard_own')) {
    json_error('Insufficient permissions', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$tripId = (int) ($body['trip_id'] ?? 0);
$odometerEnd = isset($body['odometer_end']) ? (int) $body['odometer_end'] : null;
$fuelCost = isset($body['fuel_cost']) ? (float) $body['fuel_cost'] : null;
$cashReported = (float) ($body['cash_reported'] ?? 0);
$notes = trim($body['notes'] ?? '') ?: null;
$returns = $body['returns'] ?? [];
$closeMeta = $body['close_meta'] ?? null;
if (is_array($closeMeta)) {
    $metaText = json_encode($closeMeta, JSON_UNESCAPED_UNICODE);
    if ($metaText) {
        $notes = trim(($notes ? $notes . "\n" : '') . '[CADCLOSE] ' . $metaText);
    }
}

$pdo = db();

if ($tripId <= 0) {
    $t = $pdo->prepare(
        "SELECT id FROM delivery_trips
         WHERE (cadet_id = ? OR driver_id = ?) AND status IN ('dispatched','on_route')
         ORDER BY dispatched_at DESC LIMIT 1"
    );
    $t->execute([$user['id'], $user['id']]);
    $row = $t->fetch();
    if (!$row) {
        json_error('No active trip found');
    }
    $tripId = (int) $row['id'];
}

$stmt = $pdo->prepare('SELECT * FROM delivery_trips WHERE id = ?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();
if (!$trip) {
    json_error('Trip not found', 404);
}

if ((int) $trip['cadet_id'] !== $user['id'] && (int) $trip['driver_id'] !== $user['id'] && $user['role'] !== 'admin') {
    json_error('Not your trip', 403);
}

$pdo->prepare(
    'UPDATE delivery_trips SET odometer_end = ?, fuel_cost = ?, cash_reported = ?, notes = ?, status = ?, returned_at = NOW() WHERE id = ?'
)->execute([$odometerEnd, $fuelCost, $cashReported, $notes, 'returned', $tripId]);

if (is_array($returns)) {
    foreach ($returns as $ret) {
        $productId = (int) ($ret['product_id'] ?? 0);
        $qty = (int) ($ret['qty_returned'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        $pdo->prepare(
            'UPDATE trip_load_items SET qty_returned = ? WHERE trip_id = ? AND product_id = ?'
        )->execute([$qty, $tripId, $productId]);
    }
}

audit_log($user['id'], 'delivery_trips', $tripId, 'eod_submit', null, [
    'cash_reported' => $cashReported,
    'status' => 'returned',
    'checkpoint' => is_array($closeMeta) ? ($closeMeta['checkpoint'] ?? 'close') : 'close',
]);

try {
    require_once dirname(__DIR__, 2) . '/includes/report_packets.php';
    report_create_field_eod($tripId, (int) $user['id'], $user['role'], $cashReported, $notes);
} catch (Throwable) {
    // EOD still succeeds if report packet fails (e.g. before migration).
}

json_ok(['trip_id' => $tripId, 'status' => 'returned', 'cash_reported' => $cashReported]);
