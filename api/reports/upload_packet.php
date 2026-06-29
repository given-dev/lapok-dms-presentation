<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/report_packets.php';

$user = require_roles(['admin', 'accountant', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$title = trim($_POST['title'] ?? '');
$summary = trim($_POST['summary'] ?? '') ?: null;
$reportDate = trim($_POST['report_date'] ?? date('Y-m-d'));
$notes = trim($_POST['notes'] ?? '') ?: null;
$parentId = isset($_POST['parent_packet_id']) ? (int) $_POST['parent_packet_id'] : null;

if ($title === '') {
    json_error('title is required');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    json_error('Invalid report_date');
}
if (!isset($_FILES['pdf']) || !is_array($_FILES['pdf'])) {
    json_error('pdf file is required');
}

$role = $user['role'];
if ($role === 'admin') {
    json_error('Use manager or accountant account to upload packs', 403);
}
$toRole = report_next_recipient($role);
if (!$toRole) {
    json_error('Your role cannot send reports upward');
}

try {
    $packet = report_forward_upload(
        (int) $user['id'],
        $role,
        $toRole,
        $title,
        $summary,
        $reportDate,
        $_FILES['pdf'],
        $parentId,
        $notes
    );
    json_ok(['packet' => $packet]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 400);
}
