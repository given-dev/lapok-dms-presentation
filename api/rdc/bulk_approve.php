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
$dates = $body['balance_dates'] ?? [];
$note = trim((string) ($body['note'] ?? ''));

if (!is_array($dates) || !$dates) {
    json_error('balance_dates array is required');
}

$clean = [];
foreach ($dates as $d) {
    $d = trim((string) $d);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $clean[$d] = true;
    }
}
$clean = array_keys($clean);
if (!$clean) {
    json_error('No valid balance_dates provided');
}

$pdo = db();
$allowedFrom = ['submitted', 'under_review', 'reopened'];
$approved = [];
$skipped = [];

$sel = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$upd = $pdo->prepare(
    "UPDATE rdc_daily_sheets
     SET status = 'approved',
         reviewed_by = ?,
         reviewed_at = NOW(),
         review_note = ?
     WHERE id = ?"
);

foreach ($clean as $date) {
    $sel->execute([$date]);
    $row = $sel->fetch();
    if (!$row) {
        $skipped[] = ['balance_date' => $date, 'reason' => 'not found'];
        continue;
    }
    $from = (string) $row['status'];
    if (!in_array($from, $allowedFrom, true)) {
        $skipped[] = ['balance_date' => $date, 'reason' => 'status is ' . $from];
        continue;
    }
    $upd->execute([(int) $user['id'], $note !== '' ? $note : null, (int) $row['id']]);
    if ($note !== '') {
        rdc_comments_add($pdo, $date, (int) $user['id'], $note, 'approve');
    } else {
        rdc_comments_add($pdo, $date, (int) $user['id'], 'Bulk approved', 'approve');
    }
    audit_log((int) $user['id'], 'rdc_daily_sheets', (int) $row['id'], 'review_approve_bulk', null, [
        'balance_date' => $date,
        'from_status' => $from,
        'to_status' => 'approved',
        'note' => $note,
    ]);
    $approved[] = $date;
}

json_ok([
    'approved' => $approved,
    'skipped' => $skipped,
    'approved_count' => count($approved),
    'message' => count($approved)
        ? ('Approved ' . count($approved) . ' sheet(s).')
        : 'No sheets were approved.',
]);
