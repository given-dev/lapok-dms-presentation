<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_permission('dashboard');

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month, expected YYYY-MM', 422);
}

$pdo = db();
$vehicles = $pdo->query(
    "SELECT v.id, v.registration, v.vehicle_type, v.cadet_id, u.full_name AS cadet_name
     FROM vehicles v
     LEFT JOIN users u ON u.id = v.cadet_id
     WHERE v.is_active = 1
     ORDER BY v.vehicle_type, v.registration"
)->fetchAll();

$targets = [];
$stmt = $pdo->prepare('SELECT vehicle_id, category, target_units, target_revenue FROM sales_targets WHERE target_month = ?');
$stmt->execute([$month]);
foreach ($stmt->fetchAll() as $t) {
    $unit = $t['vehicle_id'] === null ? 'DEPOT' : ((int) $t['vehicle_id'] === 0 ? 'KAMDINI' : 'vehicle_' . (int) $t['vehicle_id']);
    $targets[$unit][strtolower((string) $t['category'])] = [
        'units' => (float) $t['target_units'],
        'revenue' => (float) $t['target_revenue'],
    ];
}

$rows = [];
$rows[] = [
    'key' => 'DEPOT',
    'vehicle_id' => null,
    'label' => 'DEPOT',
    'vehicle_type' => null,
    'cadet_name' => null,
    'is_depot' => true,
    'soda_units' => $targets['DEPOT']['soda']['units'] ?? 0.0,
    'water_units' => $targets['DEPOT']['water']['units'] ?? 0.0,
];
$rows[] = [
    'key' => 'KAMDINI',
    'vehicle_id' => 0,
    'label' => 'KAMDINI',
    'vehicle_type' => null,
    'cadet_name' => null,
    'is_depot' => true,
    'soda_units' => $targets['KAMDINI']['soda']['units'] ?? 0.0,
    'water_units' => $targets['KAMDINI']['water']['units'] ?? 0.0,
];
foreach ($vehicles as $v) {
    $key = 'vehicle_' . $v['id'];
    $rows[] = [
        'key' => $key,
        'vehicle_id' => (int) $v['id'],
        'label' => strtoupper((string) $v['registration']),
        'vehicle_type' => $v['vehicle_type'],
        'cadet_name' => $v['cadet_name'],
        'is_depot' => false,
        'soda_units' => $targets[$key]['soda']['units'] ?? 0.0,
        'water_units' => $targets[$key]['water']['units'] ?? 0.0,
    ];
}

json_ok([
    'month' => $month,
    'units' => $rows,
]);
