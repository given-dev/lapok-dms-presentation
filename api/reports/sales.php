<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

require_permission('reports_sales');

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$routeId = (int) ($_GET['route_id'] ?? 0);
$driverId = (int) ($_GET['driver_id'] ?? 0);
$cadetId = (int) ($_GET['cadet_id'] ?? 0);
$userId = (int) ($_GET['user_id'] ?? 0);
$groupBy = $_GET['group_by'] ?? 'day';

// Sales come from submitted cadet / field trip reports (returned/completed trips).
$where = ["dt.status IN ('returned','completed')", 'DATE(dt.returned_at) BETWEEN ? AND ?'];
$params = [$from, $to];

if ($vehicleId > 0) {
    $where[] = 'dt.vehicle_id = ?';
    $params[] = $vehicleId;
}
if ($routeId > 0) {
    $where[] = 'dt.route_id = ?';
    $params[] = $routeId;
}
if ($driverId > 0) {
    $where[] = 'dt.driver_id = ?';
    $params[] = $driverId;
}
if ($cadetId > 0) {
    $where[] = 'dt.cadet_id = ?';
    $params[] = $cadetId;
}
if ($userId > 0) {
    $where[] = '(dt.cadet_id = ? OR dt.driver_id = ?)';
    $params[] = $userId;
    $params[] = $userId;
}

$whereSql = implode(' AND ', $where);

$tripStmt = db()->prepare(
    "SELECT dt.id, dt.vehicle_id, dt.notes, DATE(dt.returned_at) AS d
     FROM delivery_trips dt WHERE {$whereSql}"
);
$tripStmt->execute($params);
$trips = $tripStmt->fetchAll();
$tripIds = array_map('intval', array_column($trips, 'id'));

$tripRevenue = [];
$tripDay = [];
foreach ($trips as $t) {
    $parsed = cadet_parse_report_note($t['notes'] ?? null);
    $tripRevenue[(int) $t['id']] = (float) ($parsed['sales_total'] ?? 0);
    $tripDay[(int) $t['id']] = $t['d'];
}

$cartonsByTrip = [];
if ($tripIds) {
    $ph = implode(',', array_fill(0, count($tripIds), '?'));
    $cStmt = db()->prepare(
        "SELECT trip_id, COALESCE(SUM(qty_sold), 0) AS sold
         FROM trip_load_items WHERE trip_id IN ({$ph}) GROUP BY trip_id"
    );
    $cStmt->execute($tripIds);
    foreach ($cStmt->fetchAll() as $r) {
        $cartonsByTrip[(int) $r['trip_id']] = (int) $r['sold'];
    }
}

$dateFmt = match ($groupBy) {
    'week' => 'Y-\WW',
    'month' => 'Y-m',
    default => 'Y-m-d',
};

$byPeriod = [];
$byVehicle = [];
foreach ($trips as $t) {
    $id = (int) $t['id'];
    $period = date($dateFmt, strtotime($t['d']));
    $cartons = $cartonsByTrip[$id] ?? 0;
    $revenue = $tripRevenue[$id] ?? 0.0;

    $byPeriod[$period] = $byPeriod[$period] ?? ['cartons' => 0, 'revenue' => 0.0];
    $byPeriod[$period]['cartons'] += $cartons;
    $byPeriod[$period]['revenue'] += $revenue;

    $vid = (int) $t['vehicle_id'];
    if ($vid > 0) {
        $byVehicle[$vid] = $byVehicle[$vid] ?? ['trips' => 0, 'cartons' => 0, 'revenue' => 0.0];
        $byVehicle[$vid]['trips']++;
        $byVehicle[$vid]['cartons'] += $cartons;
        $byVehicle[$vid]['revenue'] += $revenue;
    }
}

$byPeriodRows = [];
foreach ($byPeriod as $period => $agg) {
    $byPeriodRows[] = ['period' => $period, 'cartons' => $agg['cartons'], 'revenue' => $agg['revenue']];
}
usort($byPeriodRows, static fn($a, $b) => strcmp((string) $a['period'], (string) $b['period']));

$vehicleRows = [];
if ($byVehicle) {
    $ph = implode(',', array_fill(0, count($byVehicle), '?'));
    $vStmt = db()->prepare(
        "SELECT id, registration FROM vehicles WHERE id IN ({$ph}) ORDER BY registration"
    );
    $vStmt->execute(array_keys($byVehicle));
    foreach ($vStmt->fetchAll() as $v) {
        $agg = $byVehicle[(int) $v['id']];
        $vehicleRows[] = [
            'registration' => $v['registration'],
            'trips' => $agg['trips'],
            'cartons' => $agg['cartons'],
            'revenue' => $agg['revenue'],
        ];
    }
}

$productStmt = db()->prepare(
    "SELECT p.name, COALESCE(SUM(tli.qty_sold), 0) AS cartons,
            COALESCE(SUM(tli.qty_sold * p.unit_price), 0) AS revenue
     FROM trip_load_items tli
     JOIN delivery_trips dt ON dt.id = tli.trip_id
     JOIN products p ON p.id = tli.product_id
     WHERE {$whereSql}
     GROUP BY p.id, p.name
     ORDER BY revenue DESC"
);
$productStmt->execute($params);
$byProduct = $productStmt->fetchAll();

$summary = [
    'revenue' => array_sum($tripRevenue),
    'trips' => count($trips),
    'cartons' => array_sum($cartonsByTrip),
];

json_ok([
    'from' => $from,
    'to' => $to,
    'summary' => $summary,
    'by_period' => $byPeriodRows,
    'by_product' => $byProduct,
    'by_vehicle' => $vehicleRows,
]);
