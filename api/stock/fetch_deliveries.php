<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_permission('stock_view');

$date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date');
}

$stmt = db()->prepare(
    'SELECT sd.id, sd.delivery_date, sd.delivery_time, sd.waybill, sd.invoice_number,
            sd.truck_plate, sd.driver_name, sd.notes, sd.ccba_order_id,
            sd.confirm_status, sd.confirmed_at, sd.confirm_note,
            co.lapok_ref AS ccba_lapok_ref, co.ccba_order_no,
            u.full_name AS received_by_name,
            cu.full_name AS confirmed_by_name
     FROM supplier_deliveries sd
     LEFT JOIN users u ON u.id = sd.received_by
     LEFT JOIN users cu ON cu.id = sd.confirmed_by
     LEFT JOIN ccba_orders co ON co.id = sd.ccba_order_id
     WHERE sd.delivery_date = ?
     ORDER BY sd.delivery_time DESC, sd.id DESC'
);
$stmt->execute([$date]);

$deliveries = [];
$pendingConfirm = 0;
foreach ($stmt->fetchAll() as $row) {
    $id = (int) $row['id'];
    $status = (string) ($row['confirm_status'] ?? 'pending_confirm');
    if ($status === 'pending_confirm') {
        $pendingConfirm++;
    }
    $items = db()->prepare(
        'SELECT sdi.*, p.name AS product_name, p.sku
         FROM supplier_delivery_items sdi
         JOIN products p ON p.id = sdi.product_id
         WHERE sdi.delivery_id = ?'
    );
    $items->execute([$id]);
    $deliveries[] = array_merge($row, [
        'confirm_status' => $status,
        'items' => $items->fetchAll(),
    ]);
}

json_ok([
    'date' => $date,
    'deliveries' => $deliveries,
    'pending_confirm' => $pendingConfirm,
]);
