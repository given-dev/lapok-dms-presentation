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

// KPI month defaults to the CURRENT month. The executive can pass ?kpi_month=YYYY-MM
// to review a previous month (sales, targets, cash flow). Targets only appear once the
// manager has entered them — no placeholders or fallback. Future months are rejected.
$kpiMonth = date('Y-m');
if (!empty($_GET['kpi_month']) && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['kpi_month']) && $_GET['kpi_month'] <= $kpiMonth) {
    $kpiMonth = $_GET['kpi_month'];
}
$kpiStart = $kpiMonth . '-01';
$kpiEnd = $kpiMonth === date('Y-m') ? $today : date('Y-m-t', strtotime($kpiStart));

$revenueByDay = depot_sales_revenue_by_day($monthStart, $today);
$cartonsByDay = depot_cartons_sold_by_day($monthStart, $today);
$revenueToday = $revenueByDay[$today] ?? 0.0;
$revenueYesterday = depot_sales_revenue_by_day($yesterday, $yesterday)[$yesterday] ?? 0.0;
$cartonsToday = $cartonsByDay[$today] ?? 0;
$cartonsYesterday = depot_cartons_sold_by_day($yesterday, $yesterday)[$yesterday] ?? 0;
$revenueMtd = array_sum($kpiMonth === date('Y-m') ? $revenueByDay : depot_sales_revenue_by_day($kpiStart, $kpiEnd));

// Previous-month comparison: same elapsed days for the current month, full month for a past month.
$prevStart = date('Y-m-01', strtotime('-1 month', strtotime($kpiStart)));
$prevEnd = date('Y-m-t', strtotime($prevStart));
$revenuePrevMtd = 0.0;
foreach (depot_sales_revenue_by_day($prevStart, $prevEnd) as $day => $rev) {
    if ($kpiMonth === date('Y-m') && (int) substr($day, 8, 2) > (int) date('d')) {
        continue;
    }
    $revenuePrevMtd += $rev;
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

// — Sales vs target (soda / water) MTD, from RDC sheets + sales_targets —
$salesSplit = depot_sales_split_mtd($kpiStart, $kpiEnd);
$targets = ['soda_units' => 0.0, 'water_units' => 0.0];
try {
    $stmt = $pdo->prepare('SELECT category, target_units FROM sales_targets WHERE target_month = ?');
    $stmt->execute([$kpiMonth]);
    foreach ($stmt->fetchAll() as $t) {
        $key = $t['category'] === 'SODA' ? 'soda_units' : 'water_units';
        $targets[$key] += (float) $t['target_units'];
    }
} catch (Throwable) {
}

$achievedPct = static function (float $actual, float $target): float {
    return $target > 0 ? round(($actual / $target) * 100, 1) : 0.0;
};
$sodaPct = $achievedPct($salesSplit['soda_units'], $targets['soda_units']);
$waterPct = $achievedPct($salesSplit['water_units'], $targets['water_units']);
$totalUnits = $salesSplit['soda_units'] + $salesSplit['water_units'];
$totalTarget = $targets['soda_units'] + $targets['water_units'];

// Per-unit breakdown (DEPOT + each cadet/vehicle) — mirrors the per-salesperson rows of the monthly report.
$breakdown = [];
try {
    $breakdown = depot_sales_target_breakdown($kpiStart, $kpiEnd, $kpiMonth);
} catch (Throwable) {
}

// — Cash flow: cash out / recovery / cash still out (CSO) —
// CSO is recurring: it is recomputed from all cash-out + payment history, so previous
// months roll forward automatically (no manual carry-forward). cso_history keeps the
// executive updated with the last six months.
$cashFlow = ['cash_out_mtd' => 0.0, 'recovery_mtd' => 0.0, 'cso_open' => 0.0, 'cso_opening_bf' => 0.0, 'cso_history' => []];
try {
    // CSO is recurring: recomputed from the consolidated RDC sheets (cash-out minus recoveries),
    // so previous months roll forward automatically (the sheet absorbs the cashout ledger via prefill).
    $csoAsOf = $kpiMonth === date('Y-m') ? $today : $kpiEnd;
    $cashFlow['cash_out_mtd'] = depot_sheet_json_total('cash_out_json', $kpiStart, $kpiEnd);
    $cashFlow['recovery_mtd'] = depot_sheet_json_total('recoveries_json', $kpiStart, $kpiEnd);
    $cashFlow['cso_open'] = depot_cash_still_out_as_of($csoAsOf);
    $cashFlow['cso_opening_bf'] = depot_cash_still_out_as_of(date('Y-m-t', strtotime('last day of last month', strtotime($kpiStart))));
    $cashFlow['cso_history'] = depot_cash_still_out_history($csoAsOf, 6);
} catch (Throwable) {
}
$cashFlow['cso_cumulative'] = $cashFlow['cso_open'];

$discountMtd = depot_expense_line_mtd($kpiStart, $kpiEnd, 'DISCOUNT');

json_ok([
    'kpi_month' => $kpiMonth,
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
    'sales_split' => [
        'soda_units' => $salesSplit['soda_units'],
        'water_units' => $salesSplit['water_units'],
        'soda_revenue' => round($salesSplit['soda_revenue'], 2),
        'water_revenue' => round($salesSplit['water_revenue'], 2),
        'soda_target' => $targets['soda_units'],
        'water_target' => $targets['water_units'],
        'soda_pct' => $sodaPct,
        'water_pct' => $waterPct,
        'total_units' => round($totalUnits, 1),
        'total_target' => round($totalTarget, 1),
        'total_pct' => $achievedPct($totalUnits, $totalTarget),
        'by_unit' => $breakdown,
    ],
    'cash_flow' => $cashFlow,
    'discount_mtd' => round($discountMtd, 2),
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
