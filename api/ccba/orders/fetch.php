<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';

$user = require_roles(['admin', 'manager', 'executive']);

$status = trim($_GET['status'] ?? '');
$limit = min(100, max(10, (int) ($_GET['limit'] ?? 50)));

$sql = 'SELECT o.id, o.lapok_ref, o.status, o.submission_mode, o.ccba_order_no, o.ccba_po_no,
               o.requested_delivery_date, o.submitted_at, o.confirmed_at, o.created_at, o.notes,
               u.full_name AS created_by_name,
               (SELECT COUNT(*) FROM ccba_order_items i WHERE i.ccba_order_id = o.id) AS line_count,
               (SELECT COALESCE(SUM(i.qty_requested * i.unit_cost_estimate), 0)
                FROM ccba_order_items i WHERE i.ccba_order_id = o.id) AS est_total
        FROM ccba_orders o
        JOIN users u ON u.id = o.created_by
        WHERE 1=1';
$params = [];

if ($status !== '') {
    $sql .= ' AND o.status = ?';
    $params[] = $status;
}

$sql .= ' ORDER BY o.created_at DESC LIMIT ' . $limit;

$stmt = db()->prepare($sql);
$stmt->execute($params);

json_ok(['orders' => $stmt->fetchAll()]);
