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

/** @param array<string, mixed> $opts */
function notify_role_users(string $role, string $title, string $body, array $opts = []): int
{
    $stmt = db()->prepare(
        'SELECT id FROM users WHERE role = ? AND is_active = 1'
    );
    $stmt->execute([$role]);
    $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (notify_user((int) $row['id'], $title, $body, $opts)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Bell alert when a manager PDF pack arrives for executives.
 * Deduped per recipient + packet via #exec_pack_{id}# tag in body.
 *
 * @param array<string, mixed> $packet
 */
function notify_executives_of_pack(array $packet, int $senderId): int
{
    $packetId = (int) ($packet['id'] ?? 0);
    if ($packetId <= 0) {
        return 0;
    }
    if (($packet['to_role'] ?? '') !== 'executive') {
        return 0;
    }
    $tag = '#exec_pack_' . $packetId . '#';
    $ref = trim((string) ($packet['packet_ref'] ?? $packet['title'] ?? 'Manager brief'));
    $date = (string) ($packet['report_date'] ?? date('Y-m-d'));
    $title = 'Executive brief ready';
    $body = $ref . ' for ' . $date . '  -  open PDF reports to view and acknowledge. ' . $tag;
    $opts = [
        'sender_id' => $senderId > 0 ? $senderId : null,
        'sender_role' => 'manager',
        'severity' => 'warning',
        'link_page' => 'report-exchange',
    ];

    $stmt = db()->query(
        "SELECT id FROM users WHERE role = 'executive' AND is_active = 1"
    );
    $count = 0;
    $check = db()->prepare(
        'SELECT id FROM user_notifications WHERE recipient_id = ? AND body LIKE ? LIMIT 1'
    );
    foreach ($stmt->fetchAll() as $row) {
        $uid = (int) $row['id'];
        $check->execute([$uid, '%' . $tag . '%']);
        if ($check->fetch()) {
            continue;
        }
        if (notify_user($uid, $title, $body, $opts)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Backfill bell items for unacked executive packs (e.g. sent before this feature).
 * Scoped to one recipient so polling does not fan out to every executive.
 */
function notifications_sync_executive_packs(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $stmt = db()->prepare(
            "SELECT id, title, packet_ref, report_date, from_user_id, to_role, status
             FROM report_packets
             WHERE to_role = 'executive' AND status IN ('sent','read')
             ORDER BY sent_at DESC, id DESC
             LIMIT 15"
        );
        $stmt->execute();
        $check = db()->prepare(
            'SELECT id FROM user_notifications WHERE recipient_id = ? AND body LIKE ? LIMIT 1'
        );
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $packetId = (int) ($row['id'] ?? 0);
            if ($packetId <= 0) {
                continue;
            }
            $tag = '#exec_pack_' . $packetId . '#';
            $check->execute([$userId, '%' . $tag . '%']);
            if ($check->fetch()) {
                continue;
            }
            $ref = trim((string) ($row['packet_ref'] ?? $row['title'] ?? 'Manager brief'));
            $date = (string) ($row['report_date'] ?? date('Y-m-d'));
            notify_user($userId, 'Executive brief ready', $ref . ' for ' . $date . '  -  open PDF reports to view and acknowledge. ' . $tag, [
                'sender_id' => (int) ($row['from_user_id'] ?? 0) ?: null,
                'sender_role' => 'manager',
                'severity' => 'warning',
                'link_page' => 'report-exchange',
            ]);
        }
    } catch (Throwable) {
        // report_packets or notifications table may be missing
    }
}

/** @return list<array<string, mixed>> */
function notifications_fetch_for_user(int $userId, int $limit = 30, bool $unreadOnly = true): array
{
    $readFilter = $unreadOnly ? ' AND n.is_read = 0' : '';
    $stmt = db()->prepare(
        "SELECT n.*, u.full_name AS sender_name
         FROM user_notifications n
         LEFT JOIN users u ON u.id = n.sender_id
         WHERE n.recipient_id = ?{$readFilter}
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
        'body' => preg_replace('/\s*#[a-z0-9_]+#\s*$/i', '', (string) $row['body']) ?? (string) $row['body'],
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
