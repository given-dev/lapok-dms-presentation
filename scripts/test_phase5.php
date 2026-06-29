<?php
declare(strict_types=1);

/**
 * Phase 5 smoke test — run: php scripts/test_phase5.php
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/stock.php';

echo "LAPOK DMS — Phase 5 tests\n";
echo str_repeat('-', 40) . "\n";

try {
    $pdo = db();

    $revenue = (float) $pdo->query(
        "SELECT COALESCE(SUM(amount_total),0) FROM orders WHERE status IN ('confirmed','delivered','dispatched')"
    )->fetchColumn();
    echo "[OK] Total revenue (confirmed+): UGX " . number_format($revenue) . "\n";

    $receivables = (float) $pdo->query(
        'SELECT COALESCE(SUM(credit_balance),0) FROM customers WHERE is_active = 1'
    )->fetchColumn();
    echo "[OK] Total receivables: UGX " . number_format($receivables) . "\n";

    $stockRows = count($pdo->query(stock_summary_query())->fetchAll());
    echo "[OK] Stock report rows: {$stockRows}\n";

    $topProduct = $pdo->query(
        "SELECT p.name, SUM(oi.qty) cartons FROM order_items oi
         JOIN products p ON p.id = oi.product_id GROUP BY p.id ORDER BY cartons DESC LIMIT 1"
    )->fetch();
    echo "[OK] Top product: {$topProduct['name']} ({$topProduct['cartons']} cartons)\n";

    $vehiclePerf = (int) $pdo->query(
        'SELECT COUNT(DISTINCT vehicle_id) FROM orders WHERE vehicle_id IS NOT NULL'
    )->fetchColumn();
    echo "[OK] Vehicles with sales: {$vehiclePerf}\n";

    echo "\nPhase 5 checks passed.\n";
    exit(0);
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}
