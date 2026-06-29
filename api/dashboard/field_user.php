<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

$user = require_login();

if (!role_can($user['role'], 'dashboard_own')) {
    json_error('Insufficient permissions', 403);
}

$pdo = db();

$tStmt = $pdo->prepare(
    "SELECT dt.*, v.registration, v.vehicle_type, v.capacity, v.make_model,
            r.name AS route_name
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN routes r ON r.id = dt.route_id
     WHERE (dt.cadet_id = ? OR dt.driver_id = ?) AND dt.status IN ('dispatched','on_route','returned')
     ORDER BY dt.dispatched_at DESC LIMIT 1"
);
$tStmt->execute([$user['id'], $user['id']]);
$trip = $tStmt->fetch();

$load = [];
$totalLoaded = 0;
$totalSold = 0;
$totalRemaining = 0;

if ($trip) {
    $lStmt = $pdo->prepare(
        "SELECT tli.*, p.name AS product_name
         FROM trip_load_items tli
         JOIN products p ON p.id = tli.product_id
         WHERE tli.trip_id = ?"
    );
    $lStmt->execute([$trip['id']]);
    $load = $lStmt->fetchAll();
    foreach ($load as $item) {
        $totalLoaded += (int) $item['qty_loaded'];
        $totalSold += (int) $item['qty_sold'];
        $totalRemaining += (int) $item['qty_loaded'] - (int) $item['qty_sold'];
    }
}

$oStmt = $pdo->prepare(
    "SELECT o.id, o.order_ref, o.amount_total, o.status, o.created_at, c.name AS customer_name
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     WHERE o.user_id = ? AND DATE(o.created_at) = CURDATE()
     ORDER BY o.created_at DESC"
);
$oStmt->execute([$user['id']]);
$todayOrders = $oStmt->fetchAll();

$revenueToday = array_sum(array_map(fn($o) => (float) $o['amount_total'], $todayOrders));
$confirmedRevenue = array_sum(array_map(
    fn($o) => in_array($o['status'], ['confirmed', 'delivered'], true) ? (float) $o['amount_total'] : 0,
    $todayOrders
));

$routeId = $trip['route_id'] ?? user_route_id($user['id'], $user['default_route'] ?? null);
$stopCount = 0;
if ($routeId) {
    $sc = $pdo->prepare('SELECT COUNT(*) FROM route_stops WHERE route_id = ?');
    $sc->execute([$routeId]);
    $stopCount = (int) $sc->fetchColumn();
}

json_ok([
    'trip' => $trip,
    'load' => $load,
    'summary' => [
        'total_loaded' => $totalLoaded,
        'total_sold' => $totalSold,
        'total_remaining' => $totalRemaining,
        'revenue_today' => $revenueToday,
        'confirmed_revenue' => $confirmedRevenue,
        'receipts_today' => count($todayOrders),
        'stops_total' => $stopCount,
    ],
    'orders_today' => $todayOrders,
]);
