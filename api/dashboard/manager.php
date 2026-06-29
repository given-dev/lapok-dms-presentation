<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/stock.php';

require_roles(['admin', 'manager']);

$pdo = db();
$today = date('Y-m-d');

$pendingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$pendingEdits = (int) $pdo->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'pending'")->fetchColumn();

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

$cashPending = (int) $pdo->query(
    "SELECT COUNT(*) FROM delivery_trips WHERE status = 'returned' AND cash_collected IS NULL"
)->fetchColumn();

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

json_ok([
    'pending_orders' => $pendingOrders,
    'pending_edit_requests' => $pendingEdits,
    'low_stock_count' => count($lowStock),
    'low_stock' => $lowStock,
    'cash_pending_confirmation' => $cashPending,
    'accountant_pack' => $pack,
    'executive_brief_today' => $brief,
    'boards_today' => [
        'inventory' => $occdInv['status'] ?? null,
        'occd' => $occdBoard['status'] ?? null,
    ],
]);
