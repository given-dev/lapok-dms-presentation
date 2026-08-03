<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}
$body = read_json_body();
$vehicleId = (int) ($body['vehicle_id'] ?? 0);
$cadetId = !empty($body['cadet_id']) ? (int) $body['cadet_id'] : null;
$routeArea = trim((string) ($body['route_area'] ?? ''));
if ($vehicleId <= 0) {
    json_error('vehicle_id is required');
}
if (mb_strlen($routeArea) > 500) {
    json_error('Route is too long');
}
$pdo = db();
$vehicle = $pdo->prepare('SELECT id FROM vehicles WHERE id = ? AND is_active = 1');
$vehicle->execute([$vehicleId]);
if (!$vehicle->fetch()) json_error('Vehicle not found', 404);
if ($cadetId) {
    $cadet = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role IN ('cadet','field_user') AND is_active = 1");
    $cadet->execute([$cadetId]);
    if (!$cadet->fetch()) json_error('Active cadet not found', 422);
}

$pdo->beginTransaction();
try {
    if ($cadetId) {
        $pdo->prepare('UPDATE vehicles SET cadet_id = NULL WHERE cadet_id = ?')->execute([$cadetId]);
        $pdo->prepare('UPDATE users SET vehicle_id = NULL WHERE id = ?')->execute([$cadetId]);
    }
    $pdo->prepare('UPDATE vehicles SET cadet_id = ?, route_area = ? WHERE id = ?')
        ->execute([$cadetId, $routeArea, $vehicleId]);
    if ($cadetId) {
        $pdo->prepare('UPDATE users SET vehicle_id = ? WHERE id = ?')->execute([$vehicleId, $cadetId]);
    }
    audit_log($user['id'], 'vehicles', $vehicleId, 'assign', null, [
        'cadet_id' => $cadetId, 'route_area' => $routeArea,
    ]);
    $pdo->commit();
    json_ok(['vehicle_id' => $vehicleId, 'cadet_id' => $cadetId, 'route_area' => $routeArea]);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
