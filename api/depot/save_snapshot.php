<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/depot_finance.php';

$user = require_login();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

try {
    date_default_timezone_set('Africa/Kampala');
} catch (Throwable) {
}

$body = read_json_body();
$date = trim((string) ($body['date'] ?? date('Y-m-d')));
$type = trim((string) ($body['type'] ?? ''));
$lines = $body['lines'] ?? [];
$notes = trim((string) ($body['notes'] ?? '')) ?: null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date');
}
if (!in_array($type, ['opening', 'closing'], true)) {
    json_error('Invalid snapshot type');
}
// Manager owns both opening (7am) and closing (7pm) stock counts. RDC views only.
if (!in_array($user['role'], ['manager', 'admin'], true)) {
    json_error('Only the manager can submit opening and closing stock', 403);
}

// Closing stock for today unlocks at 6:30 PM (local server time).
if ($type === 'closing' && $date === date('Y-m-d')) {
    $mins = ((int) date('G')) * 60 + (int) date('i');
    $opensAt = 18 * 60 + 30;
    if ($mins < $opensAt) {
        json_error('Closing stock opens at 6:30 PM. Come back later to enter and save.', 403);
    }
}
if (!is_array($lines) || !count($lines)) {
    json_error('Add at least one stock line');
}

$clean = [];
foreach ($lines as $line) {
    $pid = (int) ($line['product_id'] ?? 0);
    $qty = (int) ($line['qty'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $clean[] = [
        'product_id' => $pid,
        'product_name' => trim((string) ($line['product_name'] ?? '')),
        'sku' => trim((string) ($line['sku'] ?? '')),
        'qty' => max(0, $qty),
    ];
}
if (!count($clean)) {
    json_error('No valid stock lines');
}

$pdo = db();
$stmt = $pdo->prepare(
    'INSERT INTO depot_stock_snapshots (snapshot_date, snapshot_type, lines_json, notes, submitted_by, submitted_at)
     VALUES (?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
       lines_json = VALUES(lines_json),
       notes = VALUES(notes),
       submitted_by = VALUES(submitted_by),
       submitted_at = NOW()'
);
$stmt->execute([
    $date,
    $type,
    json_encode($clean, JSON_UNESCAPED_UNICODE),
    $notes,
    (int) $user['id'],
]);

audit_log((int) $user['id'], 'depot_stock_snapshots', null, 'save_' . $type, null, [
    'date' => $date,
    'lines' => count($clean),
]);

json_ok([
    'saved' => true,
    'snapshot' => depot_snapshot_fetch($date, $type),
]);
