<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/efris.php';

$user = require_roles(['admin', 'manager', 'cadet', 'field_user']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$receiptId = (int) ($body['receipt_id'] ?? 0);
$customerId = (int) ($body['customer_id'] ?? 0);

if ($receiptId <= 0 || $customerId <= 0) {
    json_error('receipt_id and customer_id are required');
}

try {
    $result = efris_link_receipt($receiptId, $customerId, $user);
    json_ok($result);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
