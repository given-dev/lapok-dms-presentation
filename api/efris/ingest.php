<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/efris.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();

// Device webhook: X-EFRIS-KEY header. Admins may test ingest while logged in.
$deviceAuth = efris_ingest_authorized();
$user = current_user();
$adminTest = $user && in_array($user['role'], ['admin', 'manager'], true);

if (!$deviceAuth && !$adminTest) {
    json_error('Unauthorized  -  device API key or admin session required', 401);
}

try {
    $source = $deviceAuth ? 'device_push' : 'manual';
    $result = efris_ingest_receipt($body, $source);

    if (!empty($result['duplicate'])) {
        json_ok($result, 200);
    }

    json_ok($result, 201);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
