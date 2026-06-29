<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_permission('routes_write');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$routeId = (int) ($body['route_id'] ?? 0);
$stops = $body['stops'] ?? [];

if ($routeId <= 0 || !is_array($stops)) {
    json_error('route_id and stops array are required');
}

$pdo = db();
$pdo->beginTransaction();

try {
    $pdo->prepare('DELETE FROM route_stops WHERE route_id = ?')->execute([$routeId]);

    $ins = $pdo->prepare(
        'INSERT INTO route_stops (route_id, customer_id, stop_order) VALUES (?, ?, ?)'
    );
    $order = 1;
    foreach ($stops as $stop) {
        $customerId = (int) ($stop['customer_id'] ?? $stop);
        if ($customerId <= 0) {
            continue;
        }
        $ins->execute([$routeId, $customerId, $order++]);
    }

    audit_log($user['id'], 'route_stops', $routeId, 'assign', null, ['stop_count' => $order - 1]);
    $pdo->commit();
    json_ok(['route_id' => $routeId, 'stop_count' => $order - 1]);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error($e->getMessage(), 500);
}
