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
// Closing stock must count the stock cadets returned this evening (unsold remains).
$warehouseLines = depot_stock_lines_from_warehouse($date, $type === 'closing');
// Sales come from cadet reports (trip sales), never typed into the stock book.
$warehouseLines = depot_apply_cadet_sales_from_trips($warehouseLines, $date);

// Opening stock = yesterday's CLOSING stock, which is calculated automatically as
// warehouse ledger + cadet returns (it is never stored until the manager saves closing).
// So carry the live ledger forward, including returns from the previous evening.
if ($type === 'opening' && (!$snapshot || empty($snapshot['lines']))) {
    $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $returns = depot_cadet_returns_for_date($prevDate);
    $ledgerTotal = 0;
    foreach ($warehouseLines as &$line) {
        $carried = (int) ($line['qty'] ?? 0) + (int) ($returns[(int) ($line['product_id'] ?? 0)] ?? 0);
        $ledgerTotal += $carried;
        $line['opening'] = $carried;
        $line['qty'] = 0;
        $line['sales'] = 0;
    }
    unset($line);

    // If the warehouse ledger is empty (fresh install), fall back to the most recent
    // saved closing/opening snapshot so quantities keep picking up automatically.
    if ($ledgerTotal <= 0) {
        $carryFrom = null;
        for ($i = 1; $i <= 7; $i++) {
            $cand = date('Y-m-d', strtotime($date . " -{$i} day"));
            foreach (['closing', 'opening'] as $t) {
                $prev = depot_snapshot_fetch($cand, $t);
                if ($prev && !empty($prev['lines'])) {
                    $carryFrom = $prev['lines'];
                    break 2;
                }
            }
        }
        if ($carryFrom) {
            $carry = depot_merge_snapshot_onto_catalog($carryFrom);
            foreach ($carry as &$line) {
                $carried = (int) ($line['closing'] ?? 0);
                if ($carried <= 0) {
                    $carried = (int) ($line['opening'] ?? 0);
                }
                if ($carried <= 0) {
                    $carried = (int) ($line['qty'] ?? 0);
                }
                $line['opening'] = $carried;
                $line['qty'] = 0;
                $line['sales'] = 0;
            }
            unset($line);
            $warehouseLines = depot_apply_purchases_from_deliveries($carry, $date);
            $warehouseLines = depot_apply_cadet_sales_from_trips($warehouseLines, $date);
        }
    }
}

if ($snapshot && !empty($snapshot['lines'])) {
    // Always rebuild onto current LAPOK BOOK flavor catalog so legacy SKUs
    // (PREDATOR GOLD / POWERPLAY) do not appear beside the new ENERGY rows.
    $snapshot['lines'] = depot_merge_snapshot_onto_catalog($snapshot['lines']);
    $snapshot['lines'] = depot_apply_purchases_from_deliveries($snapshot['lines'], $date);
    // Sales always mirror cadet reports, even on previously saved snapshots.
    $snapshot['lines'] = depot_apply_cadet_sales_from_trips($snapshot['lines'], $date);
}

json_ok([
    'date' => $date,
    'type' => $type,
    'snapshot' => $snapshot,
    'suggested_lines' => $warehouseLines,
    'purchase_source' => 'coca_cola_delivery',
]);
