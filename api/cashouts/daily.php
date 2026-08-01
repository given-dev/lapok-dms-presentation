<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cashouts.php';

$user = require_login();

if (!role_can($user['role'], 'cashouts') && !role_can($user['role'], 'cashouts_view')) {
    json_error('Insufficient permissions', 403);
}

$pdo = db();
$date = date('Y-m-d');

$totals = cashout_daily_totals($pdo, $date);
$cadetId = (int) $user['id'];

$all = cashout_can_view_all((string) $user['role']);
$givenToday = (float) ($totals['cash_out'][$cadetId] ?? 0);
$recoveredToday = (float) ($totals['recoveries'][$cadetId] ?? 0);

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(balance), 0) AS open_balance, COUNT(*) AS open_count
     FROM customer_cashouts
     WHERE cadet_id = ? AND status = 'open'"
);
$stmt->execute([$cadetId]);
$open = $stmt->fetch();

json_ok([
    'date' => $date,
    'view_all' => $all,
    'given_out_today' => $givenToday,
    'recovered_today' => $recoveredToday,
    'open_balance' => (float) ($open['open_balance'] ?? 0),
    'open_count' => (int) ($open['open_count'] ?? 0),
]);
