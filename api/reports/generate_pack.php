<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/report_packets.php';

$user = require_roles(['admin', 'accountant', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim($body['report_date'] ?? date('Y-m-d'));
$title = trim($body['title'] ?? '');
$notes = trim($body['notes'] ?? '') ?: null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid report_date');
}

$role = $user['role'];
if ($role === 'admin') {
    json_error('Use manager or accountant account to generate packs', 403);
}

try {
    $packet = report_generate_pack($role, (int) $user['id'], $date, $title ?: null, $notes);
    json_ok(['packet' => $packet]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
