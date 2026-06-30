<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_permission('reports_sales');

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$routeId = (int) ($_GET['route_id'] ?? 0);
$driverId = (int) ($_GET['driver_id'] ?? 0);
$cadetId = (int) ($_GET['cadet_id'] ?? 0);
$userId = (int) ($_GET['user_id'] ?? 0);
$groupBy = $_GET['group_by'] ?? 'day';

$params = [$from, $to . ' 23:59:59'];
$where = "o.status IN ('confirmed','delivered','dispatched') AND o.created_at BETWEEN ? AND ?";

if ($vehicleId > 0) {
    $where .= ' AND o.vehicle_id = ?';
    $params[] = $vehicleId;
}
if ($routeId > 0) {
    $where .= ' AND o.trip_id IN (SELECT id FROM delivery_trips WHERE route_id = ?)';
    $params[] = $routeId;
}
if ($driverId > 0) {
    $where .= ' AND o.trip_id IN (SELECT id FROM delivery_trips WHERE driver_id = ?)';
    $params[] = $driverId;
}
if ($cadetId > 0) {
    $where .= ' AND o.user_id = ?';
    $params[] = $cadetId;
}
if ($userId > 0) {
    $where .= ' AND o.user_id = ?';
    $params[] = $userId;
}

$dateFmt = match ($groupBy) {
    'week' => '%Y-%u',
    'month' => '%Y-%m',
    default => '%Y-%m-%d',
};

$dailyStmt = db()->prepare(
    "SELECT DATE_FORMAT(o.created_at, '{$dateFmt}') AS period,
            COUNT(DISTINCT o.id) AS order_count,
            COALESCE(SUM(o.amount_total), 0) AS revenue,
            COALESCE(SUM(oi.qty), 0) AS cartons
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE {$where}
     GROUP BY period
     ORDER BY period"
);
$dailyStmt->execute($params);
$byPeriod = $dailyStmt->fetchAll();

$productStmt = db()->prepare(
    "SELECT p.name, SUM(oi.qty) AS cartons, SUM(oi.subtotal) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN products p ON p.id = oi.product_id
     WHERE {$where}
     GROUP BY p.id, p.name
     ORDER BY revenue DESC"
);
$productStmt->execute($params);
$byProduct = $productStmt->fetchAll();

$vehicleStmt = db()->prepare(
    "SELECT v.registration, COUNT(DISTINCT dt.id) AS trips,
            COALESCE(SUM(oi.qty), 0) AS cartons,
            COALESCE(SUM(o.amount_total), 0) AS revenue
     FROM orders o
     JOIN vehicles v ON v.id = o.vehicle_id
     LEFT JOIN delivery_trips dt ON dt.id = o.trip_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE {$where}
     GROUP BY v.id, v.registration
     ORDER BY revenue DESC"
);
$vehicleStmt->execute($params);
$byVehicle = $vehicleStmt->fetchAll();

$totals = db()->prepare(
    "SELECT COALESCE(SUM(o.amount_total), 0) AS revenue,
            COUNT(DISTINCT o.id) AS orders,
            COALESCE(SUM(oi.qty), 0) AS cartons
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE {$where}"
);
$totals->execute($params);
$summary = $totals->fetch();

json_ok([
    'from' => $from,
    'to' => $to,
    'summary' => $summary,
    'by_period' => $byPeriod,
    'by_product' => $byProduct,
    'by_vehicle' => $byVehicle,
]);
