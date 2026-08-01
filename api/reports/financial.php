<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

require_permission('reports_financial');

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$routeId = (int) ($_GET['route_id'] ?? 0);
$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$userId = (int) ($_GET['user_id'] ?? 0);

// Revenue / cartons come from the RDC sheet (with cadet report fallback) when
// unfiltered; per-vehicle/route/user reports read the submitted trip reports.
$useFilters = ($routeId > 0 || $vehicleId > 0 || $userId > 0);
if ($useFilters) {
    $revenueByDay = depot_trip_revenue_by_day($from, $to, $routeId, $vehicleId, $userId);
    $cartonsByDay = depot_trip_cartons_by_day($from, $to, $routeId, $vehicleId, $userId);
} else {
    $revenueByDay = depot_sales_revenue_by_day($from, $to);
    $cartonsByDay = depot_cartons_sold_by_day($from, $to);
}
$revenue = array_sum($revenueByDay);
$cartonsTotal = array_sum($cartonsByDay);

$revenueByMonth = [];
foreach ($revenueByDay as $day => $rev) {
    $month = substr($day, 0, 7);
    $revenueByMonth[$month] = ($revenueByMonth[$month] ?? 0.0) + $rev;
}
$revenueByMonthRows = [];
foreach ($revenueByMonth as $month => $rev) {
    $revenueByMonthRows[] = ['month' => $month, 'revenue' => round($rev, 2)];
}

$tripWhere = ['t.fuel_cost IS NOT NULL', 't.dispatched_at BETWEEN ? AND ?'];
$tripParams = [$from . ' 00:00:00', $to . ' 23:59:59'];

if ($routeId > 0) {
    $tripWhere[] = 't.route_id = ?';
    $tripParams[] = $routeId;
}
if ($vehicleId > 0) {
    $tripWhere[] = 't.vehicle_id = ?';
    $tripParams[] = $vehicleId;
}
if ($userId > 0) {
    $tripWhere[] = '(t.cadet_id = ? OR t.driver_id = ?)';
    $tripParams[] = $userId;
    $tripParams[] = $userId;
}

$expenseStmt = db()->prepare(
    'SELECT COALESCE(SUM(t.fuel_cost), 0) FROM delivery_trips t WHERE ' . implode(' AND ', $tripWhere)
);
$expenseStmt->execute($tripParams);
$expenses = (float) $expenseStmt->fetchColumn();
$profit = $revenue - $expenses;

$expMonthlyStmt = db()->prepare(
    "SELECT DATE_FORMAT(t.dispatched_at, '%Y-%m') AS month,
            COALESCE(SUM(t.fuel_cost), 0) AS expenses
     FROM delivery_trips t
     WHERE " . implode(' AND ', $tripWhere) . "
     GROUP BY DATE_FORMAT(t.dispatched_at, '%Y-%m')
     ORDER BY month"
);
$expMonthlyStmt->execute($tripParams);
$expensesByMonth = $expMonthlyStmt->fetchAll();

$receivables = db()->query(
    "SELECT id, name, phone, location, credit_balance
     FROM customers
     WHERE credit_balance > 0 AND is_active = 1
     ORDER BY credit_balance DESC"
)->fetchAll();

$totalReceivables = array_sum(array_column($receivables, 'credit_balance'));

json_ok([
    'revenue' => round($revenue, 2),
    'expenses' => $expenses,
    'profit' => round($profit, 2),
    'cartons_mtd' => $cartonsTotal,
    'from' => $from,
    'to' => $to,
    'total_receivables' => (float) $totalReceivables,
    'receivables' => $receivables,
    'revenue_by_month' => $revenueByMonthRows,
    'expenses_by_month' => $expensesByMonth,
]);
