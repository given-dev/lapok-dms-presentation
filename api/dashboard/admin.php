<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_permission('dashboard');
if (!in_array($user['role'], ['admin', 'manager', 'accountant'], true)) {
    json_error('Insufficient permissions', 403);
}

$pdo = db();

$warehouse = (int) $pdo->query(
    'SELECT COALESCE(SUM(qty_warehouse), 0) FROM batches'
)->fetchColumn();

$revenueToday = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount_total), 0) FROM orders
     WHERE status IN ('confirmed','delivered','dispatched') AND DATE(created_at) = CURDATE()"
)->fetchColumn();

$cartonsToday = (int) $pdo->query(
    "SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.status IN ('confirmed','delivered','dispatched') AND DATE(o.created_at) = CURDATE()"
)->fetchColumn();

$revenueMtd = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount_total), 0) FROM orders
     WHERE status IN ('confirmed','delivered','dispatched')
       AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
)->fetchColumn();

$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$pendingRequests = (int) $pdo->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'")->fetchColumn();

$vehiclesOut = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'on_route'")->fetchColumn();
$vehiclesTotal = (int) $pdo->query("SELECT COUNT(*) FROM vehicles WHERE is_active = 1")->fetchColumn();

$lowStock = get_low_stock_alerts();

$activeUsers = 0;
$inactiveUsers = 0;
try {
    $activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
    $inactiveUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 0")->fetchColumn();
} catch (Throwable) {
}

$cashPending = 0;
try {
    $cashPending = (int) $pdo->query(
        "SELECT COUNT(*) FROM delivery_trips WHERE status = 'returned' AND cash_collected IS NULL"
    )->fetchColumn();
} catch (Throwable) {
}

$cadetFlags = 0;
try {
    require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';
    $cadetTrips = $pdo->query(
        "SELECT notes FROM delivery_trips
         WHERE status = 'returned' AND DATE(returned_at) = CURDATE()
         LIMIT 30"
    )->fetchAll();
    foreach ($cadetTrips as $r) {
        $flags = cadet_parse_report_note($r['notes'] ?? null)['flags'] ?? [];
        if ($flags) {
            $cadetFlags++;
        }
    }
} catch (Throwable) {
}

$welfareOpen = 0;
try {
    require_once dirname(__DIR__, 2) . '/includes/staff_welfare.php';
    $welfareOpen = (int) (welfare_summary()['open_count'] ?? 0);
} catch (Throwable) {
}

// Align with exceptions/fetch.php summary.total composition
$exceptionCount = count($lowStock) + $cashPending + $pendingRequests + $pendingOrders + $cadetFlags + $welfareOpen;

$execBriefsOpen = 0;
$rdcPending = 0;
try {
    $execBriefsOpen = (int) $pdo->query(
        "SELECT COUNT(*) FROM report_packets
         WHERE to_role = 'executive' AND status IN ('sent','read')"
    )->fetchColumn();
    $rdcPending = (int) $pdo->query(
        "SELECT COUNT(*) FROM rdc_daily_sheets WHERE status IN ('submitted','under_review')"
    )->fetchColumn();
} catch (Throwable) {
}

$receivablesTotal = 0.0;
$receivablesCount = 0;
try {
    $recv = $pdo->query(
        "SELECT COALESCE(SUM(credit_balance),0) AS total, COUNT(*) AS cnt
         FROM customers WHERE is_active = 1 AND credit_balance > 0"
    )->fetch() ?: [];
    $receivablesTotal = (float) ($recv['total'] ?? 0);
    $receivablesCount = (int) ($recv['cnt'] ?? 0);
} catch (Throwable) {
}

$auditToday = 0;
try {
    $auditToday = (int) $pdo->query(
        'SELECT COUNT(*) FROM audit_log WHERE DATE(created_at) = CURDATE()'
    )->fetchColumn();
} catch (Throwable) {
}

json_ok([
    'warehouse_cartons' => $warehouse,
    'revenue_today' => $revenueToday,
    'cartons_today' => $cartonsToday,
    'revenue_mtd' => $revenueMtd,
    'pending_orders' => $pendingOrders,
    'pending_requests' => $pendingRequests,
    'vehicles_out' => $vehiclesOut,
    'vehicles_total' => $vehiclesTotal,
    'low_stock' => $lowStock,
    'low_stock_count' => count($lowStock),
    'cash_pending' => $cashPending,
    'cadet_flags' => $cadetFlags,
    'active_users' => $activeUsers,
    'inactive_users' => $inactiveUsers,
    'exception_count' => $exceptionCount,
    'welfare_open_count' => $welfareOpen,
    'exec_briefs_open' => $execBriefsOpen,
    'rdc_pending_review' => $rdcPending,
    'receivables_total' => $receivablesTotal,
    'receivables_count' => $receivablesCount,
    'audit_today' => $auditToday,
]);
