<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_permission('cash_confirm');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$tripId = (int) ($body['trip_id'] ?? 0);
$cashCollected = (float) ($body['cash_collected'] ?? 0);

if ($tripId <= 0) {
    json_error('trip_id is required');
}

$stmt = db()->prepare('SELECT * FROM delivery_trips WHERE id = ?');
$stmt->execute([$tripId]);
$trip = $stmt->fetch();

if (!$trip) {
    json_error('Trip not found', 404);
}

if ($trip['status'] !== 'returned') {
    json_error('Trip must be returned before cash confirmation');
}

$reported = (float) ($trip['cash_reported'] ?? 0);
$variance = $cashCollected - $reported;

db()->prepare(
    'UPDATE delivery_trips SET cash_collected = ?, status = ? WHERE id = ?'
)->execute([$cashCollected, 'completed', $tripId]);

audit_log($user['id'], 'delivery_trips', $tripId, 'cash_confirm', [
    'cash_reported' => $reported,
], [
    'cash_collected' => $cashCollected,
    'variance' => $variance,
    'status' => 'completed',
]);

json_ok([
    'trip_id' => $tripId,
    'cash_reported' => $reported,
    'cash_collected' => $cashCollected,
    'variance' => $variance,
    'status' => 'completed',
]);
