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
$ccbaOrderNo = trim($body['ccba_order_no'] ?? '');
$ccbaPoNo = trim($body['ccba_po_no'] ?? '');

if ($orderId <= 0 || $ccbaOrderNo === '') {
    json_error('order_id and ccba_order_no are required');
}

$stmt = db()->prepare('SELECT id, status FROM ccba_orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    json_error('Order not found', 404);
}

if (!in_array($order['status'], ['submitted_to_ccba', 'ccba_acknowledged', 'draft', 'ready_for_ccba'], true)) {
    json_error('Order cannot accept CCBA confirmation in current status');
}

$upd = db()->prepare(
    "UPDATE ccba_orders
     SET status = 'ccba_confirmed', ccba_order_no = ?, ccba_po_no = COALESCE(NULLIF(?, ''), ccba_po_no), confirmed_at = NOW()
     WHERE id = ?"
);
$upd->execute([$ccbaOrderNo, $ccbaPoNo, $orderId]);

ccba_store_ref($orderId, 'ccba_order_no', $ccbaOrderNo, 'ccba_portal');
if ($ccbaPoNo !== '') {
    ccba_store_ref($orderId, 'ccba_po', $ccbaPoNo, 'ccba_portal');
}

ccba_log_status(
    $orderId,
    'ccba_confirmed',
    'manager',
    (int) $user['id'],
    'CCBA order confirmed',
    ['ccba_order_no' => $ccbaOrderNo, 'ccba_po_no' => $ccbaPoNo ?: null]
);

audit_log((int) $user['id'], 'ccba_orders', $orderId, 'confirm_ref', null, ['ccba_order_no' => $ccbaOrderNo]);

json_ok(['order' => ccba_fetch_order($orderId)]);
