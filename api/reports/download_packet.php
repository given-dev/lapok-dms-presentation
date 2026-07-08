<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/report_packets.php';

$user = require_permission('reports');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('Method not allowed', 405);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_error('id is required');
}

$row = report_fetch_packet($id);
if (!$row) {
    json_error('Report not found', 404);
}

$role = $user['role'];
$userId = (int) $user['id'];
$canView = report_can_access_role($role, $row['to_role'], true, $userId, (int) $row['from_user_id'])
    || ($role === 'admin');

if (!$canView) {
    json_error('Insufficient permissions', 403);
}

$abs = dirname(__DIR__, 2) . '/' . ltrim((string) $row['file_path'], '/');
if (!is_file($abs)) {
    json_error('PDF file missing on server', 404);
}

if ($role === $row['to_role'] || ($role === 'admin' && $row['to_role'] === 'executive')) {
    try {
        report_mark_read($id, $role);
    } catch (Throwable) {
        // non-fatal
    }
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . basename((string) $row['file_name']) . '"');
header('Content-Length: ' . filesize($abs));
readfile($abs);
exit;
