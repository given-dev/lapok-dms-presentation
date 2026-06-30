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
    'SELECT COALESCE(SUM(qty_warehouse), 0) FROM batches'
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
]);
