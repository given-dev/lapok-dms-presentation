<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_permission('reports_financial');

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$routeId = (int) ($_GET['route_id'] ?? 0);
$vehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$userId = (int) ($_GET['user_id'] ?? 0);

$orderWhere = ["o.status IN ('confirmed','delivered','dispatched')", 'o.created_at BETWEEN ? AND ?'];
$orderParams = [$from . ' 00:00:00', $to . ' 23:59:59'];
$tripWhere = ['t.fuel_cost IS NOT NULL', 't.dispatched_at BETWEEN ? AND ?'];
$tripParams = [$from . ' 00:00:00', $to . ' 23:59:59'];

if ($routeId > 0) {
    $orderWhere[] = 'o.trip_id IN (SELECT id FROM delivery_trips WHERE route_id = ?)';
    $orderParams[] = $routeId;
    $tripWhere[] = 't.route_id = ?';
    $tripParams[] = $routeId;
}
if ($vehicleId > 0) {
    $orderWhere[] = 'o.vehicle_id = ?';
    $orderParams[] = $vehicleId;
    $tripWhere[] = 't.vehicle_id = ?';
    $tripParams[] = $vehicleId;
}
if ($userId > 0) {
    $orderWhere[] = 'o.user_id = ?';
    $orderParams[] = $userId;
    $tripWhere[] = '(t.cadet_id = ? OR t.driver_id = ?)';
    $tripParams[] = $userId;
    $tripParams[] = $userId;
}

$revenueStmt = db()->prepare(
    'SELECT COALESCE(SUM(o.amount_total), 0) FROM orders o WHERE ' . implode(' AND ', $orderWhere)
);
$revenueStmt->execute($orderParams);
$revenue = (float) $revenueStmt->fetchColumn();

$expenseStmt = db()->prepare(
    'SELECT COALESCE(SUM(t.fuel_cost), 0) FROM delivery_trips t WHERE ' . implode(' AND ', $tripWhere)
);
$expenseStmt->execute($tripParams);
$expenses = (float) $expenseStmt->fetchColumn();
$profit = $revenue - $expenses;

$receivables = db()->query(
    "SELECT id, name, phone, location, credit_balance
     FROM customers
     WHERE credit_balance > 0 AND is_active = 1
     ORDER BY credit_balance DESC"
)->fetchAll();

$totalReceivables = array_sum(array_column($receivables, 'credit_balance'));

$monthly = db()->prepare(
    "SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS month,
            COALESCE(SUM(o.amount_total), 0) AS revenue
     FROM orders o
     WHERE " . implode(' AND ', $orderWhere) . "
     GROUP BY DATE_FORMAT(o.created_at, '%Y-%m')
     ORDER BY month"
);
$monthly->execute($orderParams);
$revenueByMonth = $monthly->fetchAll();

$expMonthly = db()->prepare(
    "SELECT DATE_FORMAT(t.dispatched_at, '%Y-%m') AS month,
            COALESCE(SUM(t.fuel_cost), 0) AS expenses
     FROM delivery_trips t
     WHERE " . implode(' AND ', $tripWhere) . "
     GROUP BY DATE_FORMAT(t.dispatched_at, '%Y-%m')
     ORDER BY month"
);
$expMonthly->execute($tripParams);
$expensesByMonth = $expMonthly->fetchAll();

$cartonsStmt = db()->prepare(
    'SELECT COALESCE(SUM(oi.qty), 0)
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE ' . implode(' AND ', $orderWhere)
);
$cartonsStmt->execute($orderParams);
$cartonsMtd = (int) $cartonsStmt->fetchColumn();

json_ok([
    'revenue' => $revenue,
    'expenses' => $expenses,
    'profit' => $profit,
    'cartons_mtd' => $cartonsMtd,
    'from' => $from,
    'to' => $to,
    'total_receivables' => (float) $totalReceivables,
    'receivables' => $receivables,
    'revenue_by_month' => $revenueByMonth,
    'expenses_by_month' => $expensesByMonth,
]);
