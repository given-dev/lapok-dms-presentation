<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';
require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';

$user = require_login();
if (!in_array($user['role'], ['cadet', 'field_user'], true)) {
    json_error('Cadet access only', 403);
}

$pdo = db();
$trip = cadet_fetch_today_trip($pdo, (int) $user['id']);

$tripId = $trip ? (int) $trip['id'] : 0;
$productGroups = depot_cadet_product_groups($tripId > 0 ? $tripId : null);

$submitted = null;
if ($trip && $trip['status'] === 'returned') {
    $submitted = cadet_parse_report_note($trip['notes'] ?? null);
}

$totalLoaded = 0;
$productCount = 0;
foreach ($productGroups as $group) {
    foreach ($group['products'] ?? [] as $product) {
        $totalLoaded += (int) ($product['qty_loaded'] ?? 0);
        $productCount++;
    }
}

$reportStatus = 'no_trip';
if ($trip) {
    $reportStatus = cadet_trip_report_submitted($trip) ? 'submitted' : 'pending';
}

$hour = (int) date('G');
$min = (int) date('i');
$pastCutoff = ($hour * 60 + $min) > (19 * 60 + 30);

json_ok([
    'trip' => $trip ? [
        'id' => (int) $trip['id'],
        'registration' => $trip['registration'],
        'route_name' => $trip['route_name'] ?? $trip['route_area'],
        'status' => $trip['status'],
        'returned_at' => $trip['returned_at'],
        'vehicle_type' => $trip['vehicle_type'] ?? 'truck',
    ] : null,
    'product_groups' => $productGroups,
    'submitted_report' => $submitted,
    'summary' => [
        'total_loaded' => $totalLoaded,
        'product_lines' => $productCount,
        'report_status' => $reportStatus,
        'sales_total' => (float) ($submitted['sales_total'] ?? 0),
        'cash_handed' => (float) ($submitted['cash_handed'] ?? 0),
        'flags' => $submitted['flags'] ?? [],
        'past_cutoff' => $pastCutoff,
        'cutoff_label' => '7:30 PM',
    ],
]);
