<?php
declare(strict_types=1);

/** RDC sheet comment threads (manager review notes over time). */

function rdc_comments_table_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $pdo->query('SELECT 1 FROM rdc_sheet_comments LIMIT 1');
        $ready = true;
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/**
 * @return list<array<string, mixed>>
 */
function rdc_comments_list(PDO $pdo, string $balanceDate): array
{
    if (!rdc_comments_table_ready($pdo)) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT c.id, c.balance_date, c.body, c.action_tag, c.created_at,
                u.full_name AS author_name, u.role AS author_role
         FROM rdc_sheet_comments c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.balance_date = ?
         ORDER BY c.created_at ASC, c.id ASC'
    );
    $stmt->execute([$balanceDate]);
    return $stmt->fetchAll() ?: [];
}

function rdc_comments_count(PDO $pdo, string $balanceDate): int
{
    if (!rdc_comments_table_ready($pdo)) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rdc_sheet_comments WHERE balance_date = ?');
    $stmt->execute([$balanceDate]);
    return (int) $stmt->fetchColumn();
}

function rdc_comments_add(
    PDO $pdo,
    string $balanceDate,
    int $userId,
    string $body,
    ?string $actionTag = null
): ?array {
    $body = trim($body);
    if ($body === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $balanceDate)) {
        return null;
    }
    if (!rdc_comments_table_ready($pdo)) {
        return null;
    }
    $tag = $actionTag !== null && $actionTag !== '' ? substr($actionTag, 0, 32) : null;
    $pdo->prepare(
        'INSERT INTO rdc_sheet_comments (balance_date, user_id, body, action_tag) VALUES (?, ?, ?, ?)'
    )->execute([$balanceDate, $userId, substr($body, 0, 1000), $tag]);

    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'SELECT c.id, c.balance_date, c.body, c.action_tag, c.created_at,
                u.full_name AS author_name, u.role AS author_role
         FROM rdc_sheet_comments c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
