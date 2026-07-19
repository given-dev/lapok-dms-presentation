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
