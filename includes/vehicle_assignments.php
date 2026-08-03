<?php
declare(strict_types=1);

function assignment_day_number(?DateTimeInterface $date = null): int
{
    $date = $date ?: new DateTimeImmutable('now');
    return (int) $date->format('N');
}

function vehicle_assignment_for_day(PDO $pdo, int $vehicleId, int $dayNumber): ?array
{
    $stmt = $pdo->prepare(
        "SELECT a.*, u.full_name AS cadet_name, v.registration
         FROM vehicle_route_assignments a
         JOIN vehicles v ON v.id = a.vehicle_id
         LEFT JOIN users u ON u.id = a.cadet_id
         WHERE a.vehicle_id = ? AND a.day_of_week = ? LIMIT 1"
    );
    $stmt->execute([$vehicleId, $dayNumber]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function sync_user_vehicle_assignment(PDO $pdo, int $userId, ?int $vehicleId, ?string $routeArea, int $actorId): void
{
    $route = trim((string) ($routeArea ?? ''));
    $pdo->prepare('UPDATE vehicles SET cadet_id = NULL WHERE cadet_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM vehicle_route_assignments WHERE cadet_id = ?')->execute([$userId]);
    if (!$vehicleId) {
        return;
    }
    $pdo->prepare('UPDATE vehicles SET cadet_id = ? WHERE id = ?')->execute([$userId, $vehicleId]);
    $upsert = $pdo->prepare(
        'INSERT INTO vehicle_route_assignments (vehicle_id, cadet_id, day_of_week, route_area, updated_by)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE cadet_id = VALUES(cadet_id), route_area = VALUES(route_area), updated_by = VALUES(updated_by)'
    );
    for ($day = 1; $day <= 6; $day++) {
        $upsert->execute([$vehicleId, $userId, $day, $route, $actorId]);
    }
}
