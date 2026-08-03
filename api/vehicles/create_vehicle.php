<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$registration = strtoupper(trim($body['registration'] ?? ''));
$vehicleType = $body['vehicle_type'] ?? 'truck';
$makeModel = trim($body['make_model'] ?? '') ?: null;
$capacity = (int) ($body['capacity'] ?? 0);

if ($registration === '' || !in_array($vehicleType, ['truck', 'tuktuk'], true) || $capacity < 1) {
    json_error('registration, valid vehicle_type, and capacity >= 1 are required');
}

$pdo = db();
$dup = $pdo->prepare('SELECT id FROM vehicles WHERE registration = ? LIMIT 1');
$dup->execute([$registration]);
if ($dup->fetch()) {
    json_error('A vehicle with that registration already exists', 409);
}

$pdo->prepare('INSERT INTO vehicles (registration, vehicle_type, make_model, capacity) VALUES (?, ?, ?, ?)')
    ->execute([$registration, $vehicleType, $makeModel, $capacity]);
$id = (int) $pdo->lastInsertId();

audit_log(
    (int) $user['id'],
    'vehicles',
    $id,
    'create',
    null,
    ['registration' => $registration, 'vehicle_type' => $vehicleType, 'capacity' => $capacity]
);

json_ok(['vehicle_id' => $id], 201);
