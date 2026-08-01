<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cashouts.php';

$user = require_permission('cashouts');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$customerId = (int) ($body['customer_id'] ?? 0);
$amountOut = (float) ($body['amount_out'] ?? 0);
$notes = trim((string) ($body['notes'] ?? ''));

if ($customerId <= 0) {
    json_error('Customer is required');
}

$pdo = db();
$tripId = null;
$tStmt = $pdo->prepare(
    "SELECT id FROM delivery_trips
     WHERE cadet_id = ? AND status IN ('dispatched','on_route','returned')
     ORDER BY dispatched_at DESC LIMIT 1"
);
$tStmt->execute([(int) $user['id']]);
$trip = $tStmt->fetch();
if ($trip) {
    $tripId = (int) $trip['id'];
}

try {
    $cashout = cashout_create($pdo, $customerId, (int) $user['id'], $amountOut, $tripId, $notes);
} catch (RuntimeException $e) {
    json_error($e->getMessage());
}

audit_log((int) $user['id'], 'customer_cashouts', (int) ($cashout['id'] ?? 0), 'create', null, $cashout);

json_ok(['cashout' => $cashout], 201);
