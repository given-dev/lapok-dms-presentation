<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_permission('cash_confirm');

$stmt = db()->query(
    "SELECT dt.id, dt.dispatched_at, dt.returned_at, dt.cash_reported, dt.cash_collected,
            dt.route_area, dt.status, dt.fuel_cost,
            v.registration AS vehicle_reg,
            cadet.full_name AS cadet_name,
            driver.full_name AS driver_name
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN users cadet ON cadet.id = dt.cadet_id
     LEFT JOIN users driver ON driver.id = dt.driver_id
     WHERE dt.status IN ('returned', 'completed')
       AND (
         dt.cash_collected IS NULL
         OR DATE(dt.returned_at) = CURDATE()
       )
     ORDER BY dt.returned_at DESC
     LIMIT 80"
);

$trips = $stmt->fetchAll();
foreach ($trips as &$t) {
    $t['variance'] = (float) ($t['cash_collected'] ?? 0) - (float) ($t['cash_reported'] ?? 0);
    if ($t['cash_collected'] === null) {
        $t['variance'] = null;
    }
}
unset($t);

json_ok(['trips' => $trips]);
