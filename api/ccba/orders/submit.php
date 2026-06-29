<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/ccba.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$orderId = (int) ($body['order_id'] ?? 0);

if ($orderId <= 0) {
    json_error('order_id is required');
}

$stmt = db()->prepare('SELECT id, status FROM ccba_orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    json_error('Order not found', 404);
}

if (!in_array($order['status'], ['draft', 'ready_for_ccba'], true)) {
    json_error('Only draft or ready orders can be submitted to CCBA');
}

$upd = db()->prepare(
    "UPDATE ccba_orders SET status = 'submitted_to_ccba', submitted_at = NOW() WHERE id = ?"
);
$upd->execute([$orderId]);

ccba_log_status($orderId, 'submitted_to_ccba', 'manager', (int) $user['id'], 'Submitted via MyCCBA portal (assisted)');

audit_log((int) $user['id'], 'ccba_orders', $orderId, 'submit');

json_ok([
    'order' => ccba_fetch_order($orderId),
    'portal_url' => ccba_portal_url(),
]);
