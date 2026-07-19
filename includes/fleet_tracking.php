<?php
declare(strict_types=1);

/** Configured Lapok depot coordinates for the Gulu branch. */
function fleet_depot_coords(): array
{
    return ['lat' => 2.7726, 'lng' => 32.2988];
}

/** @return array<int, array<string, mixed>> */
function fleet_route_stops(int $routeId): array
{
    $stmt = db()->prepare(
        'SELECT rs.stop_order, c.id AS customer_id, c.name, c.location, c.latitude, c.longitude
         FROM route_stops rs
         JOIN customers c ON c.id = rs.customer_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order'
    );
    $stmt->execute([$routeId]);
    $stops = $stmt->fetchAll();
    $out = [];
    foreach ($stops as $i => $stop) {
        $lat = $stop['latitude'] !== null ? (float) $stop['latitude'] : null;
        $lng = $stop['longitude'] !== null ? (float) $stop['longitude'] : null;
        $out[] = [
            'stop_order' => (int) $stop['stop_order'],
            'customer_id' => (int) $stop['customer_id'],
            'name' => $stop['name'],
            'location' => $stop['location'],
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
    return $out;
}

function fleet_latest_ping(int $vehicleId): ?array
{
    $stmt = db()->prepare(
        'SELECT latitude, longitude, accuracy_m, speed_kmh, heading, source, recorded_at, user_id, trip_id
         FROM vehicle_location_pings
         WHERE vehicle_id = ?
         ORDER BY recorded_at DESC
         LIMIT 1'
    );
    $stmt->execute([$vehicleId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'lat' => (float) $row['latitude'],
        'lng' => (float) $row['longitude'],
        'accuracy_m' => $row['accuracy_m'] !== null ? (int) $row['accuracy_m'] : null,
        'speed_kmh' => $row['speed_kmh'] !== null ? (float) $row['speed_kmh'] : null,
        'heading' => $row['heading'] !== null ? (int) $row['heading'] : null,
        'source' => $row['source'],
        'recorded_at' => $row['recorded_at'],
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'trip_id' => $row['trip_id'] !== null ? (int) $row['trip_id'] : null,
    ];
}

/** Estimate position along route from today's completed stop count. */
function fleet_estimate_position(int $tripId, int $routeId, array $stops): array
{
    if (count($stops) === 0) {
        return fleet_depot_coords();
    }

    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT o.customer_id) AS visited
         FROM orders o
         WHERE o.trip_id = ? AND DATE(o.created_at) = CURDATE()
           AND o.status IN ('confirmed', 'pending')"
    );
    $stmt->execute([$tripId]);
    $visited = (int) ($stmt->fetch()['visited'] ?? 0);
    $idx = min(max($visited, 0), count($stops) - 1);
    $stop = $stops[$idx];
    return ['lat' => $stop['lat'], 'lng' => $stop['lng']];
}

function fleet_resolve_route_id(?int $routeId, ?string $routeArea): ?int
{
    if ($routeId) {
        return $routeId;
    }
    if (!$routeArea) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM routes WHERE name = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$routeArea]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}

/** @return array<int, array<string, mixed>> */
function fleet_active_tracking_payload(): array
{
    $sql = "
        SELECT v.id AS vehicle_id, v.registration, v.vehicle_type, v.status AS vehicle_status,
               v.current_route, v.capacity,
               d.id AS driver_id, d.full_name AS driver_name, d.phone AS driver_phone,
               c.id AS cadet_id, c.full_name AS cadet_name, c.phone AS cadet_phone,
               dt.id AS trip_id, dt.status AS trip_status, dt.route_id, dt.route_area,
               dt.dispatched_at, r.name AS route_name, r.zone AS route_zone,
               r.latitude AS route_lat, r.longitude AS route_lng
        FROM vehicles v
        LEFT JOIN users d ON d.id = v.driver_id
        LEFT JOIN users c ON c.id = v.cadet_id
        LEFT JOIN delivery_trips dt ON dt.vehicle_id = v.id
            AND dt.status IN ('dispatched', 'on_route')
        LEFT JOIN routes r ON r.id = dt.route_id
        WHERE v.is_active = 1
          AND (dt.id IS NOT NULL OR v.status = 'on_route')
        ORDER BY v.vehicle_type, v.registration
    ";
    $rows = db()->query($sql)->fetchAll();
    $fleet = [];
    $seen = [];

    foreach ($rows as $row) {
        $vid = (int) $row['vehicle_id'];
        if (isset($seen[$vid])) {
            continue;
        }
        $seen[$vid] = true;

        $routeId = fleet_resolve_route_id(
            $row['route_id'] !== null ? (int) $row['route_id'] : null,
            $row['route_area'] ?: $row['current_route']
        );
        $stops = $routeId ? fleet_route_stops($routeId) : [];

        $ping = fleet_latest_ping($vid);
        $position = null;
        $positionSource = 'unavailable';

        if ($ping && strtotime((string) $ping['recorded_at']) > time() - 86400) {
            $position = ['lat' => $ping['lat'], 'lng' => $ping['lng']];
            $positionSource = $ping['source'];
        }

        $routePath = array_values(array_map(
            fn($s) => [$s['lat'], $s['lng']],
            array_filter($stops, fn($s) => $s['lat'] !== null && $s['lng'] !== null)
        ));
        if (count($routePath) > 0) {
            $depot = fleet_depot_coords();
            array_unshift($routePath, [$depot['lat'], $depot['lng']]);
        }

        $fleet[] = [
            'vehicle_id' => $vid,
            'registration' => $row['registration'],
            'vehicle_type' => $row['vehicle_type'],
            'vehicle_status' => $row['vehicle_status'],
            'capacity' => (int) $row['capacity'],
            'driver' => $row['driver_id'] ? [
                'id' => (int) $row['driver_id'],
                'name' => $row['driver_name'],
                'phone' => $row['driver_phone'],
                'role' => 'driver',
            ] : null,
            'cadet' => $row['cadet_id'] ? [
                'id' => (int) $row['cadet_id'],
                'name' => $row['cadet_name'],
                'phone' => $row['cadet_phone'],
                'role' => 'cadet',
            ] : null,
            'trip' => $row['trip_id'] ? [
                'id' => (int) $row['trip_id'],
                'status' => $row['trip_status'],
                'dispatched_at' => $row['dispatched_at'],
            ] : null,
            'route' => $routeId ? [
                'id' => $routeId,
                'name' => $row['route_name'] ?: $row['route_area'] ?: $row['current_route'],
                'zone' => $row['route_zone'],
                'stops' => $stops,
                'path' => $routePath,
            ] : null,
            'position' => $position,
            'position_source' => $positionSource,
            'last_ping' => $ping,
        ];
    }

    return $fleet;
}
