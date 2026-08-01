<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cashouts.php';

$user = require_login();

if (!role_can($user['role'], 'cashouts') && !role_can($user['role'], 'cashouts_view')) {
    json_error('Insufficient permissions', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$cashoutId = (int) ($body['cashout_id'] ?? 0);
$amount = (float) ($body['amount'] ?? 0);
$paidOn = trim((string) ($body['paid_on'] ?? date('Y-m-d')));

if ($cashoutId <= 0) {
    json_error('cashout_id is required');
}

$pdo = db();
$cashout = cashout_find($pdo, $cashoutId);
if (!$cashout) {
    json_error('Cash out not found', 404);
}

// Field roles may only recover on their own cashouts; staff may record any.
$all = cashout_can_view_all((string) $user['role']);
if (!$all && (int) $cashout['cadet_id'] !== (int) $user['id']) {
    json_error('You can only record recoveries on your own cash outs', 403);
}

try {
    $result = cashout_record_recovery($pdo, $cashoutId, (int) $user['id'], $amount, $paidOn);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), $e->getCode() === 404 ? 404 : 400);
}

audit_log((int) $user['id'], 'cashout_payments', $cashoutId, 'recovery', null, [
    'amount' => $amount,
    'paid_on' => $paidOn,
    'balance' => $result['balance'],
    'settled' => $result['settled'],
]);

json_ok($result);
