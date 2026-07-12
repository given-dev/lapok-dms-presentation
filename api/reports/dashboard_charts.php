<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_permission('reports');

$days = min(90, max(7, (int) ($_GET['days'] ?? 30)));

$salesStmt = db()->prepare(
    "SELECT DATE(created_at) AS d, COALESCE(SUM(amount_total), 0) AS total
     FROM orders
     WHERE status IN ('confirmed','delivered','dispatched')
       AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(created_at)
     ORDER BY d"
);
$salesStmt->execute([$days]);
$salesByDay = $salesStmt->fetchAll();

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
    "SELECT p.name, SUM(oi.qty) AS cartons
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN products p ON p.id = oi.product_id
     WHERE o.status IN ('confirmed','delivered','dispatched')
       AND YEAR(o.created_at) = YEAR(CURDATE()) AND MONTH(o.created_at) = MONTH(CURDATE())
     GROUP BY p.id, p.name
     ORDER BY cartons DESC"
)->fetchAll();

// Build aligned day arrays
$labels = [];
$sales = [];
$expenses = [];
$profit = [];

$sMap = [];
foreach ($salesByDay as $r) {
    $sMap[$r['d']] = (float) $r['total'];
}
$eMap = [];
foreach ($expByDay as $r) {
    $eMap[$r['d']] = (float) $r['total'];
}

for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('j', strtotime($d));
    $s = ($sMap[$d] ?? 0) / 1000000;
    $e = ($eMap[$d] ?? 0) / 1000000;
    $sales[] = round($s, 2);
    $expenses[] = round($e, 2);
    $profit[] = round($s - $e, 2);
}

$monthlyLabels = [];
$monthlySales = [];
$monthlyExpenses = [];
$monthlyProfit = [];
for ($m = 4; $m >= 0; $m--) {
    $ts = strtotime(date('Y-m-01') . " -{$m} months");
    $monthStart = date('Y-m-01', $ts);
    $monthEnd = date('Y-m-t', $ts);
    $monthlyLabels[] = date('M', $ts);

    $sStmt = db()->prepare(
        "SELECT COALESCE(SUM(amount_total), 0) FROM orders
         WHERE status IN ('confirmed','delivered','dispatched')
           AND DATE(created_at) BETWEEN ? AND ?"
    );
    $sStmt->execute([$monthStart, $monthEnd]);
    $sVal = ((float) $sStmt->fetchColumn()) / 1000000;

    $eStmt = db()->prepare(
        "SELECT COALESCE(SUM(fuel_cost), 0) FROM delivery_trips
         WHERE fuel_cost IS NOT NULL AND DATE(dispatched_at) BETWEEN ? AND ?"
    );
    $eStmt->execute([$monthStart, $monthEnd]);
    $eVal = ((float) $eStmt->fetchColumn()) / 1000000;

    $monthlySales[] = round($sVal, 2);
    $monthlyExpenses[] = round($eVal, 2);
    $monthlyProfit[] = round($sVal - $eVal, 2);
}

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
