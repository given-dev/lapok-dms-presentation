<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

$user = require_login();
if (!notification_can_send($user['role'])) {
    json_error('Your role cannot send notifications', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$title = trim((string) ($body['title'] ?? ''));
$message = trim((string) ($body['message'] ?? $body['body'] ?? ''));
$recipientId = (int) ($body['recipient_id'] ?? 0);
$broadcastCadets = !empty($body['broadcast_cadets']);
$severity = $body['severity'] ?? 'info';
$linkPage = trim((string) ($body['link_page'] ?? ''));

if ($title === '' || $message === '') {
    json_error('Title and message are required');
}
if (strlen($title) > 160) {
    json_error('Title is too long (max 160 characters)');
}

$opts = [
    'sender_id' => (int) $user['id'],
    'sender_role' => $user['role'],
    'severity' => $severity,
    'link_page' => $linkPage !== '' ? $linkPage : null,
];

$pdo = db();
$sent = 0;

try {
    if ($broadcastCadets) {
        $sent = notify_cadets($title, $message, $opts);
    } elseif ($recipientId > 0) {
        $u = $pdo->prepare(
            "SELECT id, role FROM users WHERE id = ? AND is_active = 1
             AND role IN ('cadet','field_user','driver')"
        );
        $u->execute([$recipientId]);
        $target = $u->fetch();
        if (!$target) {
            json_error('Recipient must be an active cadet or field user');
        }
        if (notify_user($recipientId, $title, $message, $opts)) {
            $sent = 1;
        }
    } else {
        json_error('recipient_id or broadcast_cadets is required');
    }
} catch (Throwable $e) {
    json_error('Could not send — run migration 011_user_notifications.sql', 500);
}

json_ok([
    'sent' => $sent,
    'message' => $sent === 1 ? 'Notification sent' : "Notification sent to {$sent} cadets",
]);
