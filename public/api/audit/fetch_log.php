<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_fail('Method not allowed', 405);
}

require_role('admin');

$limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

$stmt = db()->prepare(
    'SELECT a.id, a.table_name, a.record_id, a.action, a.old_values, a.new_values, a.logged_at,
            u.name AS user_name, u.email AS user_email
     FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.logged_at DESC
     LIMIT :limit'
);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

$rows = array_map(static function (array $row): array {
    return [
        'id'         => (int) $row['id'],
        'user_name'  => $row['user_name'],
        'user_email' => $row['user_email'],
        'table_name' => $row['table_name'],
        'record_id'  => $row['record_id'] ? (int) $row['record_id'] : null,
        'action'     => $row['action'],
        'old_values' => $row['old_values'] ? json_decode($row['old_values'], true) : null,
        'new_values' => $row['new_values'] ? json_decode($row['new_values'], true) : null,
        'logged_at'  => $row['logged_at'],
    ];
}, $stmt->fetchAll());

json_success(['entries' => $rows]);
