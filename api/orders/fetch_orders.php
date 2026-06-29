<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_login();

$status = trim($_GET['status'] ?? '');
$customerId = (int) ($_GET['customer_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));

$sql = "SELECT o.*, c.name AS customer_name, u.full_name AS user_name,
               v.registration AS vehicle_reg
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        JOIN users u ON u.id = o.user_id
        LEFT JOIN vehicles v ON v.id = o.vehicle_id
        WHERE 1=1";
$params = [];

if ($status !== '') {
    $sql .= ' AND o.status = ?';
    $params[] = $status;
}

if ($customerId > 0) {
    $sql .= ' AND o.customer_id = ?';
    $params[] = $customerId;
}

// Field users see only their orders
if (in_array($user['role'], ['field_user', 'cadet', 'driver'], true)) {
    $sql .= ' AND o.user_id = ?';
    $params[] = $user['id'];
}

$sql .= ' ORDER BY o.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();

$total = count($all);
$offset = ($page - 1) * $perPage;
$orders = array_slice($all, $offset, $perPage);

$orderIds = array_column($orders, 'id');
$itemsByOrder = [];

if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = db()->prepare(
        "SELECT oi.*, p.name AS product_name, p.sku
         FROM order_items oi
         JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id IN ({$placeholders})"
    );
    $itemStmt->execute($orderIds);
    foreach ($itemStmt->fetchAll() as $item) {
        $itemsByOrder[$item['order_id']][] = $item;
    }
}

$result = array_map(function ($o) use ($itemsByOrder) {
    return [
        'id' => (int) $o['id'],
        'order_ref' => $o['order_ref'],
        'customer_id' => $o['customer_id'] ? (int) $o['customer_id'] : null,
        'customer_name' => $o['customer_name'],
        'user_id' => (int) $o['user_id'],
        'user_name' => $o['user_name'],
        'vehicle_id' => $o['vehicle_id'] ? (int) $o['vehicle_id'] : null,
        'vehicle_reg' => $o['vehicle_reg'],
        'status' => $o['status'],
        'payment_type' => $o['payment_type'],
        'amount_total' => (float) $o['amount_total'],
        'amount_paid' => (float) $o['amount_paid'],
        'efris_ref' => $o['efris_ref'],
        'created_at' => $o['created_at'],
        'confirmed_at' => $o['confirmed_at'],
        'items' => $itemsByOrder[$o['id']] ?? [],
    ];
}, $orders);

json_ok([
    'orders' => $result,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
]);
