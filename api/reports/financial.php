<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_roles(['admin', 'executive', 'accountant', 'manager']);

$year = (int) ($_GET['year'] ?? (int) date('Y'));
$month = (int) ($_GET['month'] ?? 0);

$revenueSql = "SELECT COALESCE(SUM(amount_total), 0) FROM orders
               WHERE status IN ('confirmed','delivered','dispatched')";
$expenseSql = "SELECT COALESCE(SUM(fuel_cost), 0) FROM delivery_trips WHERE fuel_cost IS NOT NULL";

if ($month > 0) {
    $revenueSql .= " AND YEAR(created_at) = {$year} AND MONTH(created_at) = {$month}";
    $expenseSql .= " AND YEAR(dispatched_at) = {$year} AND MONTH(dispatched_at) = {$month}";
} else {
    $revenueSql .= " AND YEAR(created_at) = {$year}";
    $expenseSql .= " AND YEAR(dispatched_at) = {$year}";
}

$revenue = (float) db()->query($revenueSql)->fetchColumn();
$expenses = (float) db()->query($expenseSql)->fetchColumn();
$profit = $revenue - $expenses;

$receivables = db()->query(
    "SELECT id, name, phone, location, credit_balance
     FROM customers
     WHERE credit_balance > 0 AND is_active = 1
     ORDER BY credit_balance DESC"
)->fetchAll();

$totalReceivables = array_sum(array_column($receivables, 'credit_balance'));

$monthly = db()->prepare(
    "SELECT MONTH(created_at) AS month,
            COALESCE(SUM(amount_total), 0) AS revenue
     FROM orders
     WHERE status IN ('confirmed','delivered','dispatched') AND YEAR(created_at) = ?
     GROUP BY MONTH(created_at)
     ORDER BY month"
);
$monthly->execute([$year]);
$revenueByMonth = $monthly->fetchAll();

$expMonthly = db()->prepare(
    "SELECT MONTH(dispatched_at) AS month,
            COALESCE(SUM(fuel_cost), 0) AS expenses
     FROM delivery_trips
     WHERE fuel_cost IS NOT NULL AND YEAR(dispatched_at) = ?
     GROUP BY MONTH(dispatched_at)
     ORDER BY month"
);
$expMonthly->execute([$year]);
$expensesByMonth = $expMonthly->fetchAll();

$cartonsMtd = (int) db()->query(
    "SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ('confirmed','delivered','dispatched')
       AND YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())"
)->fetchColumn();

json_ok([
    'revenue' => $revenue,
    'expenses' => $expenses,
    'profit' => $profit,
    'cartons_mtd' => $cartonsMtd,
    'total_receivables' => (float) $totalReceivables,
    'receivables' => $receivables,
    'revenue_by_month' => $revenueByMonth,
    'expenses_by_month' => $expensesByMonth,
]);
