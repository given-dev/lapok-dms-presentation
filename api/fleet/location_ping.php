<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();

if (!is_field_role($user['role'])) {
    json_error('Only drivers and cadets can send location pings', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$lat = isset($body['latitude']) ? (float) $body['latitude'] : null;
$lng = isset($body['longitude']) ? (float) $body['longitude'] : null;
$accuracy = isset($body['accuracy_m']) ? (int) $body['accuracy_m'] : null;
$speed = isset($body['speed_kmh']) ? (float) $body['speed_kmh'] : null;
$heading = isset($body['heading']) ? (int) $body['heading'] : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    json_error('Valid latitude and longitude are required');
}

$pdo = db();
$userId = (int) $user['id'];

$tripStmt = $pdo->prepare(
    "SELECT dt.id AS trip_id, dt.vehicle_id
     FROM delivery_trips dt
     WHERE (dt.driver_id = ? OR dt.cadet_id = ?)
       AND dt.status IN ('dispatched', 'on_route')
     ORDER BY dt.dispatched_at DESC
     LIMIT 1"
);
$tripStmt->execute([$userId, $userId]);
$trip = $tripStmt->fetch();

$vehicleId = $trip ? (int) $trip['vehicle_id'] : (int) ($user['vehicle_id'] ?? 0);
if ($vehicleId <= 0) {
    json_error('No active vehicle trip to attach location');
}

$tripId = $trip ? (int) $trip['trip_id'] : null;

$ins = $pdo->prepare(
    'INSERT INTO vehicle_location_pings
     (vehicle_id, trip_id, user_id, latitude, longitude, accuracy_m, speed_kmh, heading, source)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$ins->execute([$vehicleId, $tripId, $userId, $lat, $lng, $accuracy, $speed, $heading, 'gps']);

json_ok([
    'vehicle_id' => $vehicleId,
    'trip_id' => $tripId,
    'recorded_at' => date('c'),
]);
