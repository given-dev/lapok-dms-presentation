<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/efris.php';

$user = require_roles(['admin', 'manager', 'executive', 'cadet', 'field_user']);

$status = trim($_GET['status'] ?? 'pending');
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

$allowed = ['pending', 'pending_link', 'unmapped', 'linked', 'all'];
if (!in_array($status, $allowed, true)) {
    json_error('Invalid status filter');
}

$params = [];
$where = '1=1';

if ($status === 'pending') {
    $where = "r.status IN ('pending_link','unmapped')";
} elseif ($status !== 'all') {
    $where = 'r.status = ?';
    $params[] = $status;
}

// Field users see today's receipts for their vehicle/trip context; admins see all recent.
if (!in_array($user['role'], ['admin', 'manager', 'executive'], true)) {
    $where .= ' AND DATE(r.fiscal_timestamp) = CURDATE()';
}

$sql = "SELECT r.id, r.efris_invoice_no, r.device_serial, r.fiscal_timestamp, r.amount_total,
               r.payment_type, r.status, r.source, r.order_id, r.customer_id, r.created_at,
               c.name AS customer_name, o.order_ref
        FROM efris_receipts r
        LEFT JOIN customers c ON c.id = r.customer_id
        LEFT JOIN orders o ON o.id = r.order_id
        WHERE {$where}
        ORDER BY r.fiscal_timestamp DESC
        LIMIT {$limit}";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$receipts = $stmt->fetchAll();

$ids = array_map(fn($r) => (int) $r['id'], $receipts);
$itemsByReceipt = [];

if (count($ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $iStmt = db()->prepare(
        "SELECT ri.*, p.name AS product_name
         FROM efris_receipt_items ri
         LEFT JOIN products p ON p.id = ri.product_id
         WHERE ri.receipt_id IN ({$placeholders})
         ORDER BY ri.id"
    );
    $iStmt->execute($ids);
    foreach ($iStmt->fetchAll() as $item) {
        $rid = (int) $item['receipt_id'];
        $itemsByReceipt[$rid][] = [
            'id' => (int) $item['id'],
            'product_id' => $item['product_id'] ? (int) $item['product_id'] : null,
            'product_name' => $item['product_name'] ?? $item['item_name'],
            'efris_item_code' => $item['efris_item_code'],
            'qty' => (int) $item['qty'],
            'unit_price' => (float) $item['unit_price'],
            'subtotal' => (float) $item['subtotal'],
            'map_status' => $item['map_status'],
        ];
    }
}

$out = [];
foreach ($receipts as $r) {
    $rid = (int) $r['id'];
    $out[] = [
        'id' => $rid,
        'efris_invoice_no' => $r['efris_invoice_no'],
        'device_serial' => $r['device_serial'],
        'fiscal_timestamp' => $r['fiscal_timestamp'],
        'amount_total' => (float) $r['amount_total'],
        'payment_type' => $r['payment_type'],
        'status' => $r['status'],
        'source' => $r['source'],
        'order_id' => $r['order_id'] ? (int) $r['order_id'] : null,
        'order_ref' => $r['order_ref'],
        'customer_id' => $r['customer_id'] ? (int) $r['customer_id'] : null,
        'customer_name' => $r['customer_name'],
        'items' => $itemsByReceipt[$rid] ?? [],
    ];
}

json_ok([
    'receipts' => $out,
    'pending_count' => count(array_filter($out, fn($r) => in_array($r['status'], ['pending_link', 'unmapped'], true))),
]);
