<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/report_packets.php';

$user = require_roles(['admin', 'executive']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$id = (int) ($body['packet_id'] ?? 0);
if ($id <= 0) {
    json_error('packet_id is required');
}

try {
    report_acknowledge($id, (int) $user['id'], $user['role']);
    $row = report_fetch_packet($id);
    json_ok(['packet' => $row ? report_format_packet($row, $user['role'], (int) $user['id']) : null]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
