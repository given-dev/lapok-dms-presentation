<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();
if (!in_array($user['role'], ['manager', 'admin'], true)) {
    json_error('Insufficient permissions', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$month = trim((string) ($body['cost_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_error('Invalid month — use YYYY-MM');
}

$rent = max(0, (float) ($body['rent_ugx'] ?? 0));
$salaries = max(0, (float) ($body['salaries_ugx'] ?? 0));
$utilities = max(0, (float) ($body['utilities_ugx'] ?? 0));
$security = max(0, (float) ($body['security_ugx'] ?? 0));
$other = max(0, (float) ($body['other_ugx'] ?? 0));
$notes = trim((string) ($body['notes'] ?? '')) ?: null;

db()->prepare(
    'INSERT INTO depot_fixed_costs
     (cost_month, rent_ugx, salaries_ugx, utilities_ugx, security_ugx, other_ugx, notes, updated_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       rent_ugx = VALUES(rent_ugx),
       salaries_ugx = VALUES(salaries_ugx),
       utilities_ugx = VALUES(utilities_ugx),
       security_ugx = VALUES(security_ugx),
       other_ugx = VALUES(other_ugx),
       notes = VALUES(notes),
       updated_by = VALUES(updated_by),
       updated_at = NOW()'
)->execute([$month, $rent, $salaries, $utilities, $security, $other, $notes, (int) $user['id']]);

audit_log((int) $user['id'], 'depot_fixed_costs', null, 'save', null, ['month' => $month]);

json_ok(['saved' => true, 'cost_month' => $month]);
