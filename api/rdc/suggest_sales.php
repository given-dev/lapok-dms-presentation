<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

require_permission('rdc_balancing');

$date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date');
}

try {
$template = rdc_new_sheet_template($date);
$suggested = rdc_suggest_sales_from_orders($date);

foreach ($template['sales'] as &$line) {
    $label = strtoupper($line['label']);
    foreach ($suggested['depot'] as $d) {
        if (rdc_label_matches_product($label, (string) $d['product_name'])) {
            $line['qty']['depot'] = (float) ($line['qty']['depot'] ?? 0) + (float) $d['qty'];
        }
    }
    foreach ($suggested['by_vehicle'] as $v) {
        if (!$v['vehicle_id']) {
            continue;
        }
        $key = 'vehicle_' . $v['vehicle_id'];
        if (!isset($line['qty'][$key])) {
            continue;
        }
        if (rdc_label_matches_product($label, (string) $v['product_name'])) {
            $line['qty'][$key] = (float) ($line['qty'][$key] ?? 0) + (float) $v['qty'];
        }
    }
}
unset($line);

$totals = rdc_compute_totals([
    'sales' => $template['sales'],
    'recoveries' => $template['recoveries'],
    'expenses' => $template['expenses'],
    'cash_actual' => $template['cash_actual'],
]);

json_ok([
    'sales' => $template['sales'],
    'totals' => $totals,
    'order_count' => count($suggested['by_vehicle']) + count($suggested['depot']),
]);
} catch (Throwable $e) {
    json_error('Could not import sales: ' . $e->getMessage(), 500);
}
