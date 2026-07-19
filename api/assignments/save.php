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
$routes = $body['routes'] ?? null;
if ($vehicleId <= 0 || !is_array($routes)) {
    json_error('vehicle_id and routes are required');
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
        $pdo->prepare('UPDATE vehicle_route_assignments SET cadet_id = NULL, updated_by = ? WHERE cadet_id = ? AND vehicle_id <> ?')
            ->execute([$user['id'], $cadetId, $vehicleId]);
        $pdo->prepare('UPDATE vehicles SET cadet_id = NULL WHERE cadet_id = ? AND id <> ?')->execute([$cadetId, $vehicleId]);
        $pdo->prepare('UPDATE users SET vehicle_id = NULL WHERE id = ?')->execute([$cadetId]);
    }
    $upsert = $pdo->prepare(
        'INSERT INTO vehicle_route_assignments (vehicle_id, cadet_id, day_of_week, route_area, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE cadet_id = VALUES(cadet_id), route_area = VALUES(route_area), updated_by = VALUES(updated_by)'
    );
    for ($day = 1; $day <= 6; $day++) {
        $route = trim((string) ($routes[(string) $day] ?? $routes[$day] ?? ''));
        if (mb_strlen($route) > 500) throw new RuntimeException('A route is too long');
        $upsert->execute([$vehicleId, $cadetId, $day, $route, $user['id']]);
    }
    $pdo->prepare('UPDATE vehicles SET cadet_id = ? WHERE id = ?')->execute([$cadetId, $vehicleId]);
    if ($cadetId) $pdo->prepare('UPDATE users SET vehicle_id = ? WHERE id = ?')->execute([$vehicleId, $cadetId]);
    audit_log($user['id'], 'vehicle_route_assignments', $vehicleId, 'assign', null, [
        'vehicle_id' => $vehicleId, 'cadet_id' => $cadetId, 'routes' => $routes,
    ]);
    $pdo->commit();
    json_ok(['vehicle_id' => $vehicleId, 'cadet_id' => $cadetId]);
} catch (RuntimeException $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
