<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

require_roles(['admin', 'manager']);

$pdo = db();
$today = date('Y-m-d');

$cacheKey = 'manager_dashboard:' . $today;
if (function_exists('apcu_fetch')) {
    $cached = apcu_fetch($cacheKey);
    if (is_array($cached)) {
        json_ok($cached);
    }
}

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM orders WHERE status = 'pending') AS pending_orders,
        (SELECT COUNT(*) FROM edit_requests WHERE status = 'pending') AS pending_edit_requests,
        (SELECT COUNT(*) FROM delivery_trips WHERE status = 'returned' AND cash_collected IS NULL) AS cash_pending_confirmation"
)->fetch() ?: [];

$pendingOrders = (int) ($counts['pending_orders'] ?? 0);
$pendingEdits = (int) ($counts['pending_edit_requests'] ?? 0);

$lowStock = $pdo->query(
    'SELECT p.name, p.sku,
            COALESCE(SUM(b.qty_warehouse), 0) AS warehouse_qty, p.min_stock
     FROM products p
     LEFT JOIN batches b ON b.product_id = p.id
     WHERE p.is_active = 1
     GROUP BY p.id
     HAVING warehouse_qty < p.min_stock
     ORDER BY warehouse_qty ASC
     LIMIT 10'
)->fetchAll();

$cashPending = (int) ($counts['cash_pending_confirmation'] ?? 0);

$accountantPack = $pdo->prepare(
    "SELECT id, packet_ref, title, status, sent_at FROM report_packets
     WHERE to_role = 'manager' AND report_type = 'accountant_pack' AND report_date = ?
     ORDER BY sent_at DESC LIMIT 1"
);
$accountantPack->execute([$today]);
$pack = $accountantPack->fetch() ?: null;

$executiveBrief = $pdo->prepare(
    "SELECT id, packet_ref, status, sent_at FROM report_packets
     WHERE from_role = 'manager' AND to_role = 'executive' AND report_date = ?
     ORDER BY sent_at DESC LIMIT 1"
);
$executiveBrief->execute([$today]);
$brief = $executiveBrief->fetch() ?: null;

$invBoard = $pdo->prepare(
    "SELECT status FROM manager_daily_boards WHERE board_date = ? AND board_type = 'inventory_board' LIMIT 1"
);
$invBoard->execute([$today]);
$occdInv = $invBoard->fetch();

$occdDash = $pdo->prepare(
    "SELECT status FROM manager_daily_boards WHERE board_date = ? AND board_type = 'occd_dashboard' LIMIT 1"
);
$occdDash->execute([$today]);
$occdBoard = $occdDash->fetch();

$cadetReportFlags = 0;
$welfareOpenCount = 0;
try {
    require_once dirname(__DIR__, 2) . '/includes/cadet_reports.php';
    $cadetRows = $pdo->query(
        "SELECT dt.notes FROM delivery_trips dt
         WHERE dt.status = 'returned' AND DATE(dt.returned_at) = CURDATE()
           AND dt.notes LIKE '%[CADET_REPORT]%'"
    )->fetchAll();
    foreach ($cadetRows as $row) {
        $parsed = cadet_parse_report_note($row['notes'] ?? null);
        if (!empty($parsed['flags'])) {
            $cadetReportFlags++;
        }
    }
} catch (Throwable) {
}

try {
    require_once dirname(__DIR__, 2) . '/includes/staff_welfare.php';
    $welfareOpenCount = welfare_summary()['open_count'] ?? 0;
} catch (Throwable) {
}

$payload = [
    'pending_orders' => $pendingOrders,
    'pending_edit_requests' => $pendingEdits,
    'low_stock_count' => count($lowStock),
    'low_stock' => $lowStock,
    'cash_pending_confirmation' => $cashPending,
    'cadet_report_flags' => $cadetReportFlags,
    'welfare_open_count' => $welfareOpenCount,
    'accountant_pack' => $pack,
    'executive_brief_today' => $brief,
    'boards_today' => [
        'inventory' => $occdInv['status'] ?? null,
        'occd' => $occdBoard['status'] ?? null,
    ],
];

if (function_exists('apcu_store')) {
    apcu_store($cacheKey, $payload, 15);
}

json_ok($payload);
