<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_login();

$search = trim($_GET['search'] ?? '');

$sql = 'SELECT c.*,
        (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id) AS last_order_at,
        (SELECT COALESCE(SUM(o.amount_total), 0) FROM orders o WHERE o.customer_id = c.id AND o.status != ?) AS lifetime_total
        FROM customers c
        WHERE c.is_active = 1';
$params = ['cancelled'];

// Field roles: customers on their assigned route only
if (is_field_role($user['role'])) {
    $routeId = user_route_id($user['id'], $user['default_route'] ?? null);
    if ($routeId) {
        $sql .= ' AND c.id IN (SELECT customer_id FROM route_stops WHERE route_id = ?)';
        $params[] = $routeId;
    } else {
        $sql .= ' AND 1=0';
    }
}

if ($search !== '') {
    $sql .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.location LIKE ?)';
    $q = '%' . $search . '%';
    $params = array_merge($params, [$q, $q, $q]);
}

$sql .= ' ORDER BY c.name';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

json_ok(['customers' => $customers]);
