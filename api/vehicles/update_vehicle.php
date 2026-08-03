<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_roles(['admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$id = (int) ($body['id'] ?? 0);
$isActive = (int) (bool) ($body['is_active'] ?? null);

if ($id <= 0) {
    json_error('Vehicle ID is required');
}

$stmt = db()->prepare('SELECT id, registration, is_active FROM vehicles WHERE id = ?');
$stmt->execute([$id]);
$old = $stmt->fetch();
if (!$old) {
    json_error('Vehicle not found', 404);
}

$pdo = db();
if (!$isActive) {
    $pdo->prepare('UPDATE vehicles SET status = ?, is_active = 0 WHERE id = ?')
        ->execute(['inactive', $id]);
} else {
    $pdo->prepare('UPDATE vehicles SET status = ?, is_active = 1 WHERE id = ?')
        ->execute(['available', $id]);
}

audit_log(
    (int) $user['id'],
    'vehicles',
    $id,
    $isActive ? 'activate' : 'retire',
    ['is_active' => (int) $old['is_active']],
    ['registration' => $old['registration'], 'is_active' => $isActive]
);

json_ok(['vehicle_id' => $id, 'is_active' => $isActive]);
