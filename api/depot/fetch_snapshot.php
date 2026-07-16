<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

$user = require_login();
if (!in_array($user['role'], ['manager', 'accountant', 'executive', 'admin'], true)) {
    json_error('Insufficient permissions', 403);
}

$date = trim($_GET['date'] ?? date('Y-m-d'));
$type = trim($_GET['type'] ?? 'opening');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date');
}
if (!in_array($type, ['opening', 'closing'], true)) {
    json_error('Invalid snapshot type');
}

$snapshot = depot_snapshot_fetch($date, $type);
$warehouseLines = depot_stock_lines_from_warehouse($date);
if ($snapshot && !empty($snapshot['lines'])) {
    // Always rebuild onto current LAPOK BOOK flavor catalog so legacy SKUs
    // (PREDATOR GOLD / POWERPLAY) do not appear beside the new ENERGY rows.
    $snapshot['lines'] = depot_merge_snapshot_onto_catalog($snapshot['lines']);
    $snapshot['lines'] = depot_apply_purchases_from_deliveries($snapshot['lines'], $date);
}

json_ok([
    'date' => $date,
    'type' => $type,
    'snapshot' => $snapshot,
    'suggested_lines' => $warehouseLines,
    'purchase_source' => 'coca_cola_delivery',
]);
