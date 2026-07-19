<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

$user = require_login();
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 25)));

try {
    if (($user['role'] ?? '') === 'executive') {
        notifications_sync_executive_packs((int) $user['id']);
    }
    $items = notifications_fetch_for_user((int) $user['id'], $limit, true);
    $history = notifications_fetch_for_user((int) $user['id'], $limit, false);
    $unread = notifications_unread_count((int) $user['id']);
} catch (Throwable) {
    json_ok([
        'items' => [],
        'history' => [],
        'unread_count' => 0,
        'can_send' => notification_can_send($user['role']),
        'migration_required' => true,
    ]);
    exit;
}

json_ok([
    'items' => $items,
    'history' => $history,
    'unread_count' => $unread,
    'can_send' => notification_can_send($user['role']),
]);
