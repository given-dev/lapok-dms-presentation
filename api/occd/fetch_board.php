<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/occd_boards.php';

require_roles(['admin', 'manager']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('Method not allowed', 405);
}

$date = trim($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    json_error('Invalid date  -  use YYYY-MM-DD');
}

$type = trim($_GET['type'] ?? 'all');
$allowed = ['inventory_board', 'occd_dashboard', 'all'];
if (!in_array($type, $allowed, true)) {
    json_error('Invalid type');
}

$pdo = db();

try {
    if ($type === 'all') {
        json_ok([
            'board_date' => $date,
            'inventory_board' => occd_board_for_date($pdo, $date, 'inventory_board'),
            'occd_dashboard' => occd_board_for_date($pdo, $date, 'occd_dashboard'),
        ]);
    }
    json_ok(occd_board_for_date($pdo, $date, $type));
} catch (Throwable $e) {
    json_error('Could not load boards  -  run migration 003_occd_daily_boards.sql if tables are missing.', 500);
}
