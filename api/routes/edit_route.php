<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

$user = require_permission('routes_write');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$id = (int) ($body['id'] ?? 0);
if ($id <= 0) {
    json_error('Route ID is required');
}

$stmt = db()->prepare('SELECT * FROM routes WHERE id = ?');
$stmt->execute([$id]);
$old = $stmt->fetch();
if (!$old) {
    json_error('Route not found', 404);
}

$name = trim($body['name'] ?? $old['name']);
$zone = array_key_exists('zone', $body) ? (trim($body['zone'] ?? '') ?: null) : $old['zone'];
$description = array_key_exists('description', $body) ? (trim($body['description'] ?? '') ?: null) : $old['description'];
$isActive = isset($body['is_active']) ? (int) (bool) $body['is_active'] : (int) $old['is_active'];

db()->prepare('UPDATE routes SET name = ?, zone = ?, description = ?, is_active = ? WHERE id = ?')
    ->execute([$name, $zone, $description, $isActive, $id]);

audit_log($user['id'], 'routes', $id, 'update', $old, compact('name', 'zone', 'is_active'));

json_ok(['route_id' => $id]);
