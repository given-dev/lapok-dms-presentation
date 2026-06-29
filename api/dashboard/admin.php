<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

require_roles(['admin', 'executive', 'manager', 'accountant']);

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

json_ok([
    'warehouse_cartons' => $warehouse,
    'revenue_today' => $revenueToday,
    'cartons_today' => $cartonsToday,
    'revenue_mtd' => $revenueMtd,
    'pending_orders' => $pendingOrders,
    'pending_requests' => $pendingRequests,
    'vehicles_out' => $vehiclesOut,
    'vehicles_total' => $vehiclesTotal,
    'low_stock' => get_low_stock_alerts(),
]);
