<?php

/**
 * One cadet + one route per vehicle (no per-day assignments).
 * The cadet lives on vehicles.cadet_id and the route on vehicles.route_area.
 */

function sync_user_vehicle_assignment(PDO $pdo, int $userId, ?int $vehicleId, ?string $routeArea): void
{
    $route = trim((string) ($routeArea ?? ''));
    $pdo->prepare('UPDATE vehicles SET cadet_id = NULL WHERE cadet_id = ?')->execute([$userId]);
    if (!$vehicleId) {
        $pdo->prepare('UPDATE users SET vehicle_id = NULL WHERE id = ?')->execute([$userId]);
        return;
    }
    if ($route !== '') {
        $pdo->prepare('UPDATE vehicles SET cadet_id = ?, route_area = ? WHERE id = ?')
            ->execute([$userId, $route, $vehicleId]);
    } else {
        $pdo->prepare('UPDATE vehicles SET cadet_id = ? WHERE id = ?')->execute([$userId, $vehicleId]);
    }
    $pdo->prepare('UPDATE users SET vehicle_id = ? WHERE id = ?')->execute([$vehicleId, $userId]);
}
