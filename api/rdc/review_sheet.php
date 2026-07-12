<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_balancing.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_comments.php';

$user = require_permission('rdc_review');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim($body['balance_date'] ?? '');
$action = trim($body['action'] ?? '');
$note = trim($body['note'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('balance_date is required');
}

$allowed = ['start_review', 'approve', 'reject', 'reopen'];
if (!in_array($action, $allowed, true)) {
    json_error('Invalid review action');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$stmt->execute([$date]);
$row = $stmt->fetch();
if (!$row) {
    json_error('No RDC sheet found for that date', 404);
}

$from = $row['status'];
$to = match ($action) {
    'start_review' => 'under_review',
    'approve' => 'approved',
    'reject' => 'rejected',
    'reopen' => 'reopened',
};

$allowedFrom = match ($action) {
    'start_review' => ['submitted', 'reopened'],
    'approve' => ['submitted', 'under_review', 'reopened'],
    'reject' => ['submitted', 'under_review', 'reopened'],
    'reopen' => ['submitted', 'under_review', 'approved', 'rejected'],
};

if (!in_array($from, $allowedFrom, true)) {
    json_error("Cannot {$action} when status is {$from}", 400);
}

$upd = $pdo->prepare(
    "UPDATE rdc_daily_sheets
     SET status = ?,
         reviewed_by = ?,
         reviewed_at = NOW(),
         review_note = ?
     WHERE id = ?"
);
$upd->execute([$to, (int) $user['id'], $note !== '' ? $note : null, (int) $row['id']]);

$threadBody = $note !== ''
    ? $note
    : match ($action) {
        'start_review' => 'Started review',
        'approve' => 'Approved',
        'reject' => 'Rejected',
        'reopen' => 'Reopened for accountant',
    };
rdc_comments_add($pdo, $date, (int) $user['id'], $threadBody, $action);

audit_log((int) $user['id'], 'rdc_daily_sheets', (int) $row['id'], 'review_' . $action, null, [
    'balance_date' => $date,
    'from_status' => $from,
    'to_status' => $to,
    'note' => $note,
]);

$stmt->execute([$date]);
$updated = $stmt->fetch();

json_ok([
    'sheet' => rdc_sheet_to_response($updated),
    'message' => 'RDC sheet review updated',
]);
