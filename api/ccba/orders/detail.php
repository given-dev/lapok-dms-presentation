<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/ccba.php';

require_roles(['admin', 'manager', 'executive']);

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    json_error('id is required');
}

$order = ccba_fetch_order($orderId);
if (!$order) {
    json_error('Order not found', 404);
}

json_ok(['order' => $order]);
