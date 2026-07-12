<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/rdc_comments.php';

$user = require_login();
if (!role_can($user['role'], 'rdc_balancing') && !role_can($user['role'], 'rdc_review')) {
    json_error('Insufficient permissions', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = read_json_body();
$date = trim((string) ($body['balance_date'] ?? ''));
$text = trim((string) ($body['body'] ?? $body['note'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('balance_date is required');
}
if ($text === '') {
    json_error('Comment text is required');
}

$pdo = db();
$exists = $pdo->prepare('SELECT id FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
$exists->execute([$date]);
if (!$exists->fetch()) {
    json_error('No RDC sheet found for that date', 404);
}

if (!rdc_comments_table_ready($pdo)) {
    json_error('Comment threads need migration 014_rdc_review_comments.sql', 500);
}

$comment = rdc_comments_add($pdo, $date, (int) $user['id'], $text, 'comment');
if (!$comment) {
    json_error('Could not save comment', 500);
}

audit_log((int) $user['id'], 'rdc_sheet_comments', (int) $comment['id'], 'add', null, [
    'balance_date' => $date,
]);

json_ok([
    'comment' => $comment,
    'comments' => rdc_comments_list($pdo, $date),
]);
