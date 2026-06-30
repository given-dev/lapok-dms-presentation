<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

$user = require_login();
if (!notification_can_send($user['role'])) {
    json_error('Insufficient permissions', 403);
}

$stmt = db()->query(
    "SELECT id, full_name, email, role
     FROM users
     WHERE role IN ('cadet','field_user') AND is_active = 1
     ORDER BY full_name"
);

json_ok(['recipients' => $stmt->fetchAll()]);
