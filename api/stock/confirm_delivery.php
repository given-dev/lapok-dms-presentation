<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$deliveryId = (int) ($body['delivery_id'] ?? 0);
$action = trim((string) ($body['action'] ?? 'confirm'));
$note = trim((string) ($body['note'] ?? ''));

if ($deliveryId <= 0) {
    json_error('delivery_id is required');
}
if (!in_array($action, ['confirm', 'reject'], true)) {
    json_error('action must be confirm or reject');
}

$pdo = db();

// Ensure confirmation columns exist (soft-fail message if migration missing)
try {
    $stmt = $pdo->prepare('SELECT * FROM supplier_deliveries WHERE id = ? LIMIT 1');
    $stmt->execute([$deliveryId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    json_error('Deliveries table not ready', 500);
}

if (!$row) {
    json_error('Delivery not found', 404);
}

if (!array_key_exists('confirm_status', $row)) {
    json_error('Run migration 013_delivery_confirmation.sql to enable delivery confirmation', 500);
}

$status = (string) ($row['confirm_status'] ?? 'pending_confirm');
if ($status === 'confirmed' && $action === 'confirm') {
    json_ok(['delivery_id' => $deliveryId, 'confirm_status' => 'confirmed', 'message' => 'Already confirmed']);
    exit;
}

$newStatus = $action === 'confirm' ? 'confirmed' : 'rejected';
$pdo->prepare(
    'UPDATE supplier_deliveries
     SET confirm_status = ?, confirmed_by = ?, confirmed_at = NOW(), confirm_note = ?
     WHERE id = ?'
)->execute([
    $newStatus,
    (int) $user['id'],
    $note !== '' ? $note : null,
    $deliveryId,
]);

audit_log((int) $user['id'], 'supplier_deliveries', $deliveryId, 'delivery_' . $action, [
    'from' => $status,
], [
    'to' => $newStatus,
    'note' => $note,
]);

// Soft notify accountants when manager confirms
if ($newStatus === 'confirmed') {
    try {
        require_once dirname(__DIR__, 2) . '/includes/notifications.php';
        $waybill = (string) ($row['waybill'] ?? ('#' . $deliveryId));
        foreach ($pdo->query("SELECT id FROM users WHERE role='accountant' AND is_active=1") as $acc) {
            notify_user((int) $acc['id'], 'Delivery confirmed by manager', sprintf(
                'Manager confirmed Coca-Cola delivery %s for %s.',
                $waybill,
                (string) ($row['delivery_date'] ?? date('Y-m-d'))
            ), [
                'sender_id' => (int) $user['id'],
                'sender_role' => 'manager',
                'severity' => 'info',
                'link_page' => 'accountant-rdc-hub',
            ]);
        }
    } catch (Throwable) {
    }
}

json_ok([
    'delivery_id' => $deliveryId,
    'confirm_status' => $newStatus,
    'message' => $newStatus === 'confirmed' ? 'Delivery confirmed' : 'Delivery rejected',
]);
