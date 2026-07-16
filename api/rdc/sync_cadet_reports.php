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
$date = trim($body['balance_date'] ?? $_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date  -  use YYYY-MM-DD');
}

try {
    $result = rdc_sync_cadet_reports_into_sheet(db(), $date, true);
} catch (Throwable $e) {
    json_error('Could not sync cadet reports: ' . $e->getMessage(), 500);
}

$stmt = db()->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$stmt->execute([$date]);
$row = $stmt->fetch();

json_ok([
    'sync' => $result,
    'sheet' => $row ? rdc_sheet_to_response($row) : null,
    'message' => ($result['synced'] ?? false)
        ? 'Cadet data synced into vehicle columns for ' . $date
        : 'Sheet locked or no cadet reports to sync',
]);
