<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
$table = trim($_GET['table'] ?? '');
$action = strtoupper(trim($_GET['action'] ?? ''));
$userQuery = trim($_GET['user'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$sql = "SELECT a.*, u.full_name AS user_name
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE 1=1";
$params = [];

if ($table !== '') {
    $sql .= ' AND a.table_name = ?';
    $params[] = $table;
}
if ($action !== '') {
    $sql .= ' AND UPPER(a.action) = ?';
    $params[] = $action;
}
if ($userQuery !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $userQuery . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($from !== '') {
    $sql .= ' AND a.created_at >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= ' AND a.created_at < ?';
    $params[] = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
}

$sql .= ' ORDER BY a.created_at DESC';

$countStmt = db()->prepare(str_replace('SELECT a.*, u.full_name AS user_name', 'SELECT COUNT(*) AS total', $sql));
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = db()->prepare($sql . ' LIMIT ? OFFSET ?');
$stmt->execute([...$params, $perPage, $offset]);
$entries = $stmt->fetchAll();

foreach ($entries as &$e) {
    $e['old_values'] = $e['old_values'] ? json_decode($e['old_values'], true) : null;
    $e['new_values'] = $e['new_values'] ? json_decode($e['new_values'], true) : null;
}
unset($e);

json_ok([
    'entries' => $entries,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil(max(1, $total) / $perPage),
    ],
]);
