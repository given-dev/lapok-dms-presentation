<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_permission('routes_write');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$name = trim($body['name'] ?? '');
$zone = trim($body['zone'] ?? '') ?: null;
$description = trim($body['description'] ?? '') ?: null;

if ($name === '') {
    json_error('Route name is required');
}

$stmt = db()->prepare('INSERT INTO routes (name, zone, description) VALUES (?, ?, ?)');
$stmt->execute([$name, $zone, $description]);
$id = (int) db()->lastInsertId();

audit_log($user['id'], 'routes', $id, 'create', null, compact('name', 'zone'));

json_ok(['route_id' => $id], 201);
