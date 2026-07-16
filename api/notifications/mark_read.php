<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

$user = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : null;
$markAll = !empty($body['all']);

try {
    $updated = $markAll
        ? notifications_mark_read((int) $user['id'])
        : notifications_mark_read((int) $user['id'], $ids);
} catch (Throwable $e) {
    json_error('Notifications not available  -  run migration 011.', 500);
}

json_ok([
    'marked' => $updated,
    'unread_count' => notifications_unread_count((int) $user['id']),
]);
