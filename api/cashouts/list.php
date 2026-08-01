<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/cashouts.php';

$user = require_login();

if (!role_can($user['role'], 'cashouts') && !role_can($user['role'], 'cashouts_view')) {
    json_error('Insufficient permissions', 403);
}

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$all = cashout_can_view_all((string) $user['role']);

$cadetId = $all ? null : (int) $user['id'];

$rows = cashout_list(db(), $cadetId, $status !== '' ? $status : null, $search);

$open = array_values(array_filter($rows, fn($r) => $r['status'] === 'open'));
$settled = array_values(array_filter($rows, fn($r) => $r['status'] === 'settled'));

json_ok([
    'cashouts' => $rows,
    'open' => $open,
    'settled' => $settled,
    'summary' => [
        'open_count' => count($open),
        'open_balance' => round(array_sum(array_column($open, 'balance')), 2),
        'total_out' => round(array_sum(array_column($rows, 'amount_out')), 2),
        'total_paid' => round(array_sum(array_column($rows, 'paid_total')), 2),
    ],
    'view_all' => $all,
]);
