<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['manager', 'admin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}
$body = read_json_body();

$month = trim((string) ($body['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month, expected YYYY-MM', 422);
}
$rows = $body['units'] ?? null;
if (!is_array($rows)) {
    json_error('units is required (array of { key, soda_units, water_units })', 422);
}

$pdo = db();

// Resolve unit keys to vehicle_id (NULL = DEPOT) and validate vehicles.
$vehicleKeys = [];
foreach (['DEPOT'] as $k) {
    $vehicleKeys[$k] = null;
}
$vehicles = $pdo->query('SELECT id FROM vehicles WHERE is_active = 1')->fetchAll();
foreach ($vehicles as $v) {
    $vehicleKeys['vehicle_' . $v['id']] = (int) $v['id'];
}

$parsed = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $key = (string) ($row['key'] ?? '');
    if (!array_key_exists($key, $vehicleKeys)) {
        json_error("Unknown sales unit: {$key}", 422);
    }
    $parsed[] = [
        'vehicle_id' => $vehicleKeys[$key],
        'soda_units' => max(0, (float) ($row['soda_units'] ?? 0)),
        'water_units' => max(0, (float) ($row['water_units'] ?? 0)),
    ];
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM sales_targets WHERE target_month = ?')->execute([$month]);
    $insert = $pdo->prepare(
        'INSERT INTO sales_targets (target_month, category, vehicle_id, target_units)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($parsed as $p) {
        if ($p['soda_units'] > 0) {
            $insert->execute([$month, 'SODA', $p['vehicle_id'], $p['soda_units']]);
        }
        if ($p['water_units'] > 0) {
            $insert->execute([$month, 'WATER', $p['vehicle_id'], $p['water_units']]);
        }
    }
    audit_log($user['id'], 'sales_targets', null, 'set_month', null, [
        'target_month' => $month,
        'unit_count' => count($parsed),
    ]);
    $pdo->commit();
    json_ok(['month' => $month, 'saved_units' => count($parsed)]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
