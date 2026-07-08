<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';

$user = require_permission('rdc_balancing');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$tripId = (int) ($body['trip_id'] ?? 0);
if ($tripId <= 0) {
    json_error('trip_id is required');
}

try {
    $result = rdc_update_cadet_report(
        db(),
        $tripId,
        $body,
        (int) $user['id'],
        (string) ($user['full_name'] ?? 'Accountant')
    );
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    json_error('Could not update cadet report: ' . $e->getMessage(), 500);
}

$date = (string) ($result['balance_date'] ?? date('Y-m-d'));
$stmt = db()->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$stmt->execute([$date]);
$row = $stmt->fetch();

json_ok([
    'result' => $result,
    'sheet' => $row ? rdc_sheet_to_response($row) : null,
    'cadet_consolidation' => [
        'reports_today' => count(rdc_cadet_reports_for_date($date)),
        'reports' => rdc_cadet_reports_for_date($date),
    ],
    'message' => 'Cadet report corrected and RDC sheet updated.',
]);
