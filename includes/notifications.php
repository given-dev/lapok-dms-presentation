<?php
declare(strict_types=1);

/** Roles allowed to send notifications to field staff. */
function notification_can_send(string $role): bool
{
    return in_array($role, ['admin', 'manager', 'accountant', 'executive'], true);
}

/** @param array<string, mixed> $opts */
function notify_user(int $recipientId, string $title, string $body, array $opts = []): ?int
{
    if ($recipientId <= 0 || trim($title) === '' || trim($body) === '') {
        return null;
    }
    $severity = $opts['severity'] ?? 'info';
    if (!in_array($severity, ['info', 'warning', 'danger'], true)) {
        $severity = 'info';
    }
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO user_notifications
         (recipient_id, sender_id, sender_role, title, body, severity, link_page)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $recipientId,
        isset($opts['sender_id']) ? (int) $opts['sender_id'] : null,
        $opts['sender_role'] ?? null,
        $title,
        $body,
        $severity,
        $opts['link_page'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** @param array<string, mixed> $opts */
function notify_cadets(string $title, string $body, array $opts = []): int
{
    $stmt = db()->query(
        "SELECT id FROM users WHERE role IN ('cadet','field_user') AND is_active = 1"
    );
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (notify_user((int) $row['id'], $title, $body, $opts)) {
            $count++;
        }
    }
    return $count;
}

/** @return list<array<string, mixed>> */
function notifications_fetch_for_user(int $userId, int $limit = 30): array
{
    $stmt = db()->prepare(
        "SELECT n.*, u.full_name AS sender_name
         FROM user_notifications n
         LEFT JOIN users u ON u.id = n.sender_id
         WHERE n.recipient_id = ?
         ORDER BY n.created_at DESC
         LIMIT ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return array_map('notification_to_response', $stmt->fetchAll() ?: []);
}

function notifications_unread_count(int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM user_notifications WHERE recipient_id = ? AND is_read = 0'
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function notifications_mark_read(int $userId, ?array $ids = null): int
{
    if ($ids) {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (!$ids) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "UPDATE user_notifications
             SET is_read = 1, read_at = NOW()
             WHERE recipient_id = ? AND id IN ($placeholders) AND is_read = 0"
        );
        $stmt->execute(array_merge([$userId], $ids));
        return $stmt->rowCount();
    }
    $stmt = db()->prepare(
        'UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE recipient_id = ? AND is_read = 0'
    );
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/** @return array<string, mixed> */
function notification_to_response(array $row): array
{
    $role = $row['sender_role'] ?? '';
    $from = $row['sender_name'] ?? null;
    if (!$from && $role) {
        $from = ucfirst(str_replace('_', ' ', $role));
    }
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'body' => $row['body'],
        'severity' => $row['severity'],
        'link_page' => $row['link_page'],
        'is_read' => (bool) $row['is_read'],
        'read_at' => $row['read_at'],
        'created_at' => $row['created_at'],
        'from' => $from ?: 'System',
        'sender_role' => $role,
    ];
}

function notification_role_label(string $role): string
{
    $map = [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'accountant' => 'Accountant (RDC)',
        'executive' => 'Executive',
    ];
    return $map[$role] ?? ucfirst($role);
}
