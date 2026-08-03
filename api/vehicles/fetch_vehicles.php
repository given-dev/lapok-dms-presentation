<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';

require_login();

$includeInactive = (int) ($_GET['include_inactive'] ?? 0) === 1;
$includeRemains = (int) ($_GET['include_remains'] ?? 0) === 1;
$where = $includeInactive ? '1=1' : 'v.is_active = 1';

$stmt = db()->query(
    "SELECT v.*,
            d.full_name AS driver_name,
            c.full_name AS cadet_name
     FROM vehicles v
     LEFT JOIN users d ON d.id = v.driver_id
     LEFT JOIN users c ON c.id = v.cadet_id
     WHERE {$where}
     ORDER BY v.vehicle_type, v.registration"
);

$vehicles = $stmt->fetchAll();
if ($includeRemains) {
    foreach ($vehicles as &$v) {
        $v['remains'] = depot_vehicle_remains_by_rdc_key((int) $v['id']);
    }
    unset($v);
}

json_ok(['vehicles' => $vehicles]);
