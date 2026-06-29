<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'executive']);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
$table = trim($_GET['table'] ?? '');

$sql = "SELECT a.*, u.full_name AS user_name
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE 1=1";
$params = [];

if ($table !== '') {
    $sql .= ' AND a.table_name = ?';
    $params[] = $table;
}

$sql .= ' ORDER BY a.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();

$total = count($all);
$offset = ($page - 1) * $perPage;
$entries = array_slice($all, $offset, $perPage);

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
