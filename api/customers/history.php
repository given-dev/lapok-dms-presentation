<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_login();

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    json_error('customer_id is required');
}

$cStmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$cStmt->execute([$customerId]);
$customer = $cStmt->fetch();

if (!$customer) {
    json_error('Customer not found', 404);
}

$oStmt = db()->prepare(
    "SELECT o.id, o.order_ref, o.status, o.amount_total, o.amount_paid, o.payment_type, o.created_at
     FROM orders o
     WHERE o.customer_id = ?
     ORDER BY o.created_at DESC
     LIMIT 50"
);
$oStmt->execute([$customerId]);
$orders = $oStmt->fetchAll();

json_ok([
    'customer' => $customer,
    'orders' => $orders,
    'order_count' => count($orders),
]);
