<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

require_permission('reports');

$days = min(90, max(7, (int) ($_GET['days'] ?? 30)));

$fromDate = date('Y-m-d', strtotime("-{$days} days"));
$salesByDay = depot_sales_revenue_by_day($fromDate, date('Y-m-d'));

$expStmt = db()->prepare(
    "SELECT DATE(dispatched_at) AS d, COALESCE(SUM(fuel_cost), 0) AS total
     FROM delivery_trips
     WHERE fuel_cost IS NOT NULL AND dispatched_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(dispatched_at)
     ORDER BY d"
);
$expStmt->execute([$days]);
$expByDay = $expStmt->fetchAll();

$productShare = db()->query(
    "SELECT p.name, COALESCE(SUM(tli.qty_sold), 0) AS cartons
     FROM trip_load_items tli
     JOIN delivery_trips dt ON dt.id = tli.trip_id
     JOIN products p ON p.id = tli.product_id
     WHERE dt.status IN ('returned','completed')
       AND YEAR(dt.returned_at) = YEAR(CURDATE()) AND MONTH(dt.returned_at) = MONTH(CURDATE())
     GROUP BY p.id, p.name
     ORDER BY cartons DESC"
)->fetchAll();

// Build aligned day arrays
$labels = [];
$sales = [];
$expenses = [];
$profit = [];

$eMap = [];
foreach ($expByDay as $r) {
    $eMap[$r['d']] = (float) $r['total'];
}

for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('j', strtotime($d));
    $s = ($salesByDay[$d] ?? 0) / 1000000;
    $e = ($eMap[$d] ?? 0) / 1000000;
    $sales[] = round($s, 2);
    $expenses[] = round($e, 2);
    $profit[] = round($s - $e, 2);
}

$monthlyLabels = [];
$monthlySales = [];
$monthlyExpenses = [];
$monthlyProfit = [];

$chartFrom = date('Y-m-01', strtotime('-4 months'));
$chartSalesByDay = depot_sales_revenue_by_day($chartFrom, date('Y-m-d'));
for ($m = 4; $m >= 0; $m--) {
    $ts = strtotime(date('Y-m-01') . " -{$m} months");
    $monthKey = date('Y-m', $ts);
    $monthStart = date('Y-m-01', $ts);
    $monthEnd = date('Y-m-t', $ts);
    $monthlyLabels[] = date('M', $ts);

    $monthlySales[] = round(array_sum(array_filter(
        $chartSalesByDay,
        static fn(string $day): bool => str_starts_with($day, $monthKey),
        ARRAY_FILTER_USE_KEY
    )) / 1000000, 2);

    $eStmt = db()->prepare(
        "SELECT COALESCE(SUM(fuel_cost), 0) FROM delivery_trips
         WHERE fuel_cost IS NOT NULL AND dispatched_at >= ? AND dispatched_at < ?"
    );
    $eStmt->execute(period_bounds($monthStart, $monthEnd));
    $monthlyExpenses[] = round(((float) $eStmt->fetchColumn()) / 1000000, 2);
}

$monthlyProfit = array_map(
    static fn($s, $e) => round($s - $e, 2),
    $monthlySales,
    $monthlyExpenses
);

$monthly = [
    'labels' => $monthlyLabels,
    'sales' => $monthlySales,
    'expenses' => $monthlyExpenses,
    'profit' => $monthlyProfit,
];

json_ok([
    'labels' => $labels,
    'sales' => $sales,
    'expenses' => $expenses,
    'profit' => $profit,
    'product_share' => $productShare,
    'monthly' => $monthly,
]);
