<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/occd_boards.php';

$user = require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim($body['board_date'] ?? '');
$type = trim($body['board_type'] ?? '');
$payload = $body['payload'] ?? null;
$submit = !empty($body['submit']);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid board_date');
}
if (!in_array($type, ['inventory_board', 'occd_dashboard'], true)) {
    json_error('Invalid board_type');
}
if (!is_array($payload)) {
    json_error('payload is required');
}

$status = $submit ? 'submitted' : 'draft';
$pdo = db();

try {
    $existing = occd_fetch_board_row($pdo, $date, $type);
    if ($existing && $existing['status'] === 'submitted') {
        json_error('This board was already submitted for the day. Contact admin to reopen.');
    }

    $status = $submit ? 'submitted' : 'draft';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        json_error('Invalid payload');
    }

    $submittedAt = $submit ? date('Y-m-d H:i:s') : null;
    $submittedBy = $submit ? (int) $user['id'] : null;

    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE manager_daily_boards
             SET status = ?, payload_json = ?, submitted_by = ?, submitted_at = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $json, $submittedBy, $submittedAt, $existing['id']]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO manager_daily_boards (board_date, board_type, status, payload_json, submitted_by, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$date, $type, $status, $json, $submittedBy, $submittedAt]);
    }

    json_ok(occd_board_for_date($pdo, $date, $type));
} catch (Throwable $e) {
    json_error('Could not save board — run migration 003_occd_daily_boards.sql if tables are missing.', 500);
}
