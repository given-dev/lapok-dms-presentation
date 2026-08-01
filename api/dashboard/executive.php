<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

$user = require_permission('dashboard');
if (!in_array($user['role'], ['executive', 'admin'], true)) {
    json_error('Insufficient permissions', 403);
}

$pdo = db();

$warehouse = (int) $pdo->query(
    'SELECT COALESCE(SUM(b.qty_warehouse), 0)
     FROM batches b
     INNER JOIN products p ON p.id = b.product_id AND p.is_active = 1'
)->fetchColumn();

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$monthStart = date('Y-m-01');
$prevMonthStart = date('Y-m-01', strtotime('first day of last month'));
$prevMonthEnd = date('Y-m-t', strtotime('last day of last month'));

$revenueByDay = depot_sales_revenue_by_day($monthStart, $today);
$cartonsByDay = depot_cartons_sold_by_day($monthStart, $today);
$revenueToday = $revenueByDay[$today] ?? 0.0;
$revenueYesterday = depot_sales_revenue_by_day($yesterday, $yesterday)[$yesterday] ?? 0.0;
$cartonsToday = $cartonsByDay[$today] ?? 0;
$cartonsYesterday = depot_cartons_sold_by_day($yesterday, $yesterday)[$yesterday] ?? 0;
$revenueMtd = array_sum($revenueByDay);

$revenuePrevMtd = 0.0;
foreach (depot_sales_revenue_by_day($prevMonthStart, $prevMonthEnd) as $day => $rev) {
    if ((int) substr($day, 8, 2) <= (int) date('d')) {
        $revenuePrevMtd += $rev;
    }
}

$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$pendingRequests = (int) $pdo->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'")->fetchColumn();
$vehiclesOut = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'on_route'")->fetchColumn();
$vehiclesTotal = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();

$pct = static function (float $current, float $base): float {
    if ($base <= 0.0) {
        return $current > 0 ? 100.0 : 0.0;
    }
    return round((($current - $base) / $base) * 100, 1);
};

$unreadBriefs = 0;
$latestBrief = null;
try {
    $unreadBriefs = (int) $pdo->query(
        "SELECT COUNT(*) FROM report_packets
         WHERE to_role = 'executive' AND status IN ('sent','read')"
    )->fetchColumn();
    $latestBrief = $pdo->query(
        "SELECT id, packet_ref, title, status, report_date, sent_at
         FROM report_packets
         WHERE to_role = 'executive'
         ORDER BY sent_at DESC LIMIT 1"
    )->fetch() ?: null;
} catch (Throwable) {
}

$exceptionCount = 0;
try {
    $exceptionCount = (int) $pdo->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'")->fetchColumn();
    $exceptionCount += count(get_low_stock_alerts());
} catch (Throwable) {
}

$receivablesTotal = 0.0;
$receivablesCount = 0;
try {
    $recv = $pdo->query(
        "SELECT COALESCE(SUM(credit_balance),0) AS total,
                COUNT(*) AS cnt
         FROM customers WHERE is_active = 1 AND credit_balance > 0"
    )->fetch() ?: [];
    $receivablesTotal = (float) ($recv['total'] ?? 0);
    $receivablesCount = (int) ($recv['cnt'] ?? 0);
} catch (Throwable) {
}

$welfareOpen = 0;
try {
    require_once dirname(__DIR__, 2) . '/includes/staff_welfare.php';
    $welfareOpen = (int) (welfare_summary()['open_count'] ?? 0);
} catch (Throwable) {
}

$director = null;
try {
    $director = depot_director_snapshot(date('Y-m-d'));
} catch (Throwable) {
}

json_ok([
    'warehouse_cartons' => $warehouse,
    'revenue_today' => $revenueToday,
    'revenue_yesterday' => $revenueYesterday,
    'revenue_today_delta_pct' => $pct($revenueToday, $revenueYesterday),
    'cartons_today' => $cartonsToday,
    'cartons_yesterday' => $cartonsYesterday,
    'cartons_today_delta_pct' => $pct((float) $cartonsToday, (float) $cartonsYesterday),
    'revenue_mtd' => $revenueMtd,
    'revenue_prev_mtd' => $revenuePrevMtd,
    'revenue_mtd_delta_pct' => $pct($revenueMtd, $revenuePrevMtd),
    'pending_orders' => $pendingOrders,
    'pending_requests' => $pendingRequests,
    'vehicles_out' => $vehiclesOut,
    'vehicles_total' => $vehiclesTotal,
    'low_stock' => get_low_stock_alerts(),
    'unread_briefs' => $unreadBriefs,
    'latest_brief' => $latestBrief,
    'exception_count' => $exceptionCount,
    'receivables_total' => $receivablesTotal,
    'receivables_count' => $receivablesCount,
    'welfare_open_count' => $welfareOpen,
    'director' => $director ? [
        'readiness' => $director['controls']['readiness'] ?? null,
        'opening_submitted' => !empty($director['controls']['opening_submitted']),
        'closing_submitted' => !empty($director['controls']['closing_submitted']),
        'rdc_status' => $director['controls']['rdc_status'] ?? null,
        'net_operating' => $director['profit']['net_operating'] ?? 0,
        'expense_ratio_pct' => $director['profit']['expense_ratio_pct'] ?? 0,
        'shortage_flag_ugx' => $director['shortages']['total_flag_ugx'] ?? 0,
    ] : null,
]);
