<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/depot_catalog.php';

require_roles(['admin', 'manager']);

$date = trim($_GET['date'] ?? date('Y-m-d'));

[$dayStart, $dayEnd] = day_bounds($date);

$stmt = db()->prepare(
    "SELECT dt.id, dt.vehicle_id, dt.status, dt.dispatched_at, dt.acknowledged_at, dt.returned_at, dt.route_area,
            v.registration, v.vehicle_type,
            COALESCE(driver.full_name, cadet.full_name) AS crew_name,
            driver.full_name AS driver_name, cadet.full_name AS cadet_name,
            (SELECT COALESCE(SUM(qty_loaded), 0) FROM trip_load_items WHERE trip_id = dt.id) AS load_qty
     FROM (
         SELECT * FROM delivery_trips WHERE dispatched_at >= ? AND dispatched_at < ?
         UNION
         SELECT * FROM delivery_trips WHERE returned_at IS NOT NULL AND returned_at >= ? AND returned_at < ?
     ) dt
     JOIN vehicles v ON v.id = dt.vehicle_id
     LEFT JOIN users driver ON driver.id = dt.driver_id
     LEFT JOIN users cadet ON cadet.id = dt.cadet_id
     ORDER BY dt.dispatched_at DESC"
);
$stmt->execute([$dayStart, $dayEnd, $dayStart, $dayEnd]);
$trips = $stmt->fetchAll();

// Per-trip load detail (what the cadet went with) — used by the view-load modal
// and the edit-load modal. Batched into one query instead of N round-trips.
$itemsByTrip = [];
$tripIds = array_map(static fn(array $t): int => (int) $t['id'], $trips);
if ($tripIds) {
    $ph = implode(',', array_fill(0, count($tripIds), '?'));
    $itemStmt = db()->prepare(
        "SELECT tli.trip_id, tli.product_id, tli.qty_loaded, tli.qty_sold, tli.qty_returned, p.name, p.sku
         FROM trip_load_items tli
         JOIN products p ON p.id = tli.product_id
         WHERE tli.trip_id IN ({$ph})
         ORDER BY tli.trip_id ASC, tli.id ASC"
    );
    $itemStmt->execute($tripIds);
    foreach ($itemStmt->fetchAll() as $row) {
        $itemsByTrip[(int) $row['trip_id']][] = $row;
    }
}

foreach ($trips as &$t) {
    $items = [];
    $breakdown = [];
    foreach ($itemsByTrip[(int) $t['id']] ?? [] as $row) {
        $key = depot_map_product_to_rdc_key((string) $row['name'], (string) $row['sku']) ?? 'product_' . (int) $row['product_id'];
        $qty = (int) $row['qty_loaded'];
        $items[] = [
            'product_id' => (int) $row['product_id'],
            'name' => $row['name'],
            'sku' => $row['sku'],
            'rdc_key' => $key,
            'qty_loaded' => $qty,
            'qty_sold' => (int) $row['qty_sold'],
            'qty_returned' => (int) $row['qty_returned'],
        ];
        $breakdown[$key] = ($breakdown[$key] ?? 0) + $qty;
    }
    $t['load_items'] = $items;
    $t['load_breakdown'] = $breakdown;
}
unset($t);

// DEPOT / KAMDINI sales columns from the RDC sheet for the same date, so the
// manager can enter/see depot sales right alongside the dispatch log.
$depotSheet = null;
$depotSalesStmt = db()->prepare(
    'SELECT status, sales_json FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1'
);
$depotSalesStmt->execute([$date]);
$ds = $depotSalesStmt->fetch();
if ($ds) {
    $lines = [];
    foreach (json_decode((string) ($ds['sales_json'] ?? '[]'), true) ?: [] as $line) {
        $qty = is_array($line['qty'] ?? null) ? $line['qty'] : [];
        $lines[] = [
            'label' => (string) ($line['label'] ?? ''),
            'rdc_key' => (string) ($line['rdc_key'] ?? ''),
            'price' => (float) ($line['price'] ?? 0),
            'depot_qty' => (float) ($qty['depot'] ?? 0),
            'kamdini_qty' => (float) ($qty['kamdini'] ?? 0),
        ];
    }
    $depotSheet = [
        'balance_date' => $date,
        'status' => (string) ($ds['status'] ?? 'draft'),
        'sales' => $lines,
        'depot_total' => array_sum(array_map(fn($l) => $l['depot_qty'], $lines)),
        'kamdini_total' => array_sum(array_map(fn($l) => $l['kamdini_qty'], $lines)),
    ];
}

json_ok([
    'trips' => $trips,
    'depot_sheet' => $depotSheet,
    'date' => $date,
]);
