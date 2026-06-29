<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();

if (!role_can($user['role'], 'route_own')) {
    json_error('Insufficient permissions', 403);
}

$routeId = user_route_id($user['id'], $user['default_route'] ?? null);

if (!$routeId) {
    json_ok(['route' => null, 'stops' => [], 'trip' => null]);
}

$rStmt = db()->prepare('SELECT * FROM routes WHERE id = ?');
$rStmt->execute([$routeId]);
$route = $rStmt->fetch();

$stStmt = db()->prepare(
    "SELECT rs.stop_order, c.id AS customer_id, c.name, c.phone, c.location, c.category,
            (SELECT o.amount_total FROM orders o WHERE o.customer_id = c.id ORDER BY o.created_at DESC LIMIT 1) AS last_amount,
            (SELECT o.created_at FROM orders o WHERE o.customer_id = c.id ORDER BY o.created_at DESC LIMIT 1) AS last_order_at,
            (SELECT o.status FROM orders o WHERE o.customer_id = c.id ORDER BY o.created_at DESC LIMIT 1) AS last_order_status
     FROM route_stops rs
     JOIN customers c ON c.id = rs.customer_id
     WHERE rs.route_id = ?
     ORDER BY rs.stop_order"
);
$stStmt->execute([$routeId]);
$stops = $stStmt->fetchAll();

$tStmt = db()->prepare(
    "SELECT dt.*, v.registration AS vehicle_reg, v.vehicle_type
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     WHERE (dt.cadet_id = ? OR dt.driver_id = ?) AND dt.status IN ('dispatched','on_route')
     ORDER BY dt.dispatched_at DESC LIMIT 1"
);
$tStmt->execute([$user['id'], $user['id']]);
$trip = $tStmt->fetch() ?: null;

json_ok([
    'route' => $route,
    'stops' => $stops,
    'trip' => $trip,
]);
