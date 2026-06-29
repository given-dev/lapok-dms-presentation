<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

require_permission('routes');

$stmt = db()->query(
    "SELECT r.*,
            (SELECT COUNT(*) FROM route_stops rs WHERE rs.route_id = r.id) AS stop_count
     FROM routes r
     WHERE r.is_active = 1
     ORDER BY r.zone, r.name"
);
$routes = $stmt->fetchAll();

foreach ($routes as &$route) {
    $st = db()->prepare(
        "SELECT rs.id, rs.stop_order, rs.customer_id, c.name AS customer_name, c.location
         FROM route_stops rs
         JOIN customers c ON c.id = rs.customer_id
         WHERE rs.route_id = ?
         ORDER BY rs.stop_order"
    );
    $st->execute([$route['id']]);
    $route['stops'] = $st->fetchAll();
    $route['id'] = (int) $route['id'];
    $route['stop_count'] = (int) $route['stop_count'];
}
unset($route);

json_ok(['routes' => $routes]);
