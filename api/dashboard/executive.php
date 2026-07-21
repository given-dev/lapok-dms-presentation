<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

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

$revenueToday = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount_total), 0) FROM orders
     WHERE status IN ('confirmed','delivered','dispatched') AND DATE(created_at) = CURDATE()"
)->fetchColumn();

$revenueYesterday = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount_total), 0) FROM orders
     WHERE status IN ('confirmed','delivered','dispatched') AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
)->fetchColumn();

$cartonsToday = (int) $pdo->query(
    "SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ('confirmed','delivered','dispatched') AND DATE(o.created_at) = CURDATE()"
)->fetchColumn();

$cartonsYesterday = (int) $pdo->query(
    "SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ('confirmed','delivered','dispatched')
       AND DATE(o.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
)->fetchColumn();

$revenueMtd = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount_total), 0) FROM orders
     WHERE status IN ('confirmed','delivered','dispatched')
       AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
)->fetchColumn();

$revenuePrevMtd = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount_total), 0) FROM orders
     WHERE status IN ('confirmed','delivered','dispatched')
       AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
       AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
       AND DAY(created_at) <= DAY(CURDATE())"
)->fetchColumn();

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
    require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';
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
