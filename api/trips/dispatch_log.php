<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_roles(['admin', 'manager']);

$date = trim($_GET['date'] ?? date('Y-m-d'));

$stmt = db()->prepare(
    "SELECT dt.id, dt.status, dt.dispatched_at, dt.returned_at, dt.route_area,
            v.registration, v.vehicle_type,
            COALESCE(driver.full_name, cadet.full_name) AS crew_name,
            driver.full_name AS driver_name, cadet.full_name AS cadet_name,
            (SELECT COALESCE(SUM(qty_loaded), 0) FROM trip_load_items WHERE trip_id = dt.id) AS load_qty
     FROM delivery_trips dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN users driver ON driver.id = dt.driver_id
     LEFT JOIN users cadet ON cadet.id = dt.cadet_id
     WHERE DATE(dt.dispatched_at) = ? OR (dt.returned_at IS NOT NULL AND DATE(dt.returned_at) = ?)
     ORDER BY dt.dispatched_at DESC"
);
$stmt->execute([$date, $date]);

json_ok(['trips' => $stmt->fetchAll(), 'date' => $date]);
