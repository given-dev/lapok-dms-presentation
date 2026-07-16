<?php
declare(strict_types=1);

require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/depot_catalog.php';

function depot_snapshot_fetch(string $date, string $type): ?array
{
    $stmt = db()->prepare(
        'SELECT s.*, u.full_name AS submitted_by_name
         FROM depot_stock_snapshots s
         LEFT JOIN users u ON u.id = s.submitted_by
         WHERE s.snapshot_date = ? AND s.snapshot_type = ?
         LIMIT 1'
    );
    $stmt->execute([$date, $type]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['lines'] = json_decode((string) ($row['lines_json'] ?? '[]'), true) ?: [];
    return $row;
}

function depot_fixed_costs_for_month(string $month): array
{
    $stmt = db()->prepare('SELECT * FROM depot_fixed_costs WHERE cost_month = ? LIMIT 1');
    $stmt->execute([$month]);
    $row = $stmt->fetch();
    if (!$row) {
        return [
            'cost_month' => $month,
            'rent_ugx' => 0,
            'salaries_ugx' => 0,
            'utilities_ugx' => 0,
            'security_ugx' => 0,
            'other_ugx' => 0,
            'notes' => null,
        ];
    }
    return $row;
}

function depot_monthly_fixed_total(array $fixed): float
{
    return (float) ($fixed['rent_ugx'] ?? 0)
        + (float) ($fixed['salaries_ugx'] ?? 0)
        + (float) ($fixed['utilities_ugx'] ?? 0)
        + (float) ($fixed['security_ugx'] ?? 0)
        + (float) ($fixed['other_ugx'] ?? 0);
}

function depot_daily_fixed_allocation(string $date, array $fixed): float
{
    $month = substr($date, 0, 7);
    $parts = explode('-', $month);
    $year = (int) ($parts[0] ?? date('Y'));
    $mon = (int) ($parts[1] ?? date('m'));
    $days = (int) date('t', mktime(0, 0, 0, $mon, 1, $year));
    if ($days <= 0) {
        return 0.0;
    }
    return depot_monthly_fixed_total($fixed) / $days;
}

/**
 * Purchase qty for the stock book = sum of Coca-Cola supplier deliveries that day.
 * Excludes manager-rejected waybills. Keyed by product_id.
 *
 * @return array<int, int>
 */
function depot_purchases_from_deliveries(string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }

    $pdo = db();
    $hasConfirm = false;
    try {
        $hasConfirm = (bool) $pdo->query("SHOW COLUMNS FROM supplier_deliveries LIKE 'confirm_status'")->fetch();
    } catch (Throwable) {
        $hasConfirm = false;
    }

    $sql = 'SELECT sdi.product_id, COALESCE(SUM(sdi.qty_delivered), 0) AS qty
            FROM supplier_delivery_items sdi
            JOIN supplier_deliveries sd ON sd.id = sdi.delivery_id
            WHERE sd.delivery_date = ?';
    if ($hasConfirm) {
        $sql .= " AND COALESCE(sd.confirm_status, 'pending_confirm') <> 'rejected'";
    }
    $sql .= ' GROUP BY sdi.product_id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['product_id']] = (int) $row['qty'];
    }
    return $out;
}

/**
 * Overlay purchase from Coca-Cola deliveries onto stock book lines (source of truth).
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function depot_apply_purchases_from_deliveries(array $lines, string $date): array
{
    $purchases = depot_purchases_from_deliveries($date);
    foreach ($lines as &$line) {
        $pid = (int) ($line['product_id'] ?? 0);
        $line['purchase'] = (int) ($purchases[$pid] ?? 0);
        $line['purchase_source'] = 'coca_cola_delivery';
    }
    unset($line);
    return $lines;
}

function depot_stock_lines_from_warehouse(?string $date = null): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $ensured = depot_ensure_warehouse_products();
    $qtyById = [];
    foreach (db()->query(stock_summary_query())->fetchAll() as $row) {
        $qtyById[(int) $row['product_id']] = (int) ($row['warehouse_qty'] ?? 0);
    }

    $purchaseById = [];
    if ($date !== null && $date !== '') {
        $purchaseById = depot_purchases_from_deliveries($date);
    }

    $lines = [];
    foreach ($ensured as $row) {
        $productId = (int) $row['product_id'];
        $brand = (string) ($row['brand'] ?? $row['category'] ?? '');
        $lines[] = [
            'product_id' => $productId,
            'product_name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'brand' => $brand,
            'qty' => (int) ($qtyById[$productId] ?? 0),
            'opening' => 0,
            'purchase' => (int) ($purchaseById[$productId] ?? 0),
            'sales' => 0,
            'closing' => 0,
            'category' => $brand,
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'rdc_key' => (string) ($row['rdc_key'] ?? ''),
            'purchase_source' => 'coca_cola_delivery',
        ];
    }
    return depot_sort_lines_by_category($lines);
}

/** @param list<array<string, mixed>> $lines */
function depot_sort_lines_by_category(array $lines): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $brandOrder = function_exists('depot_stock_brand_order')
        ? array_flip(depot_stock_brand_order())
        : array_flip(depot_category_order());
    usort($lines, function ($a, $b) use ($brandOrder) {
        $ca = (string) ($a['brand'] ?? $a['category'] ?? 'OTHER');
        $cb = (string) ($b['brand'] ?? $b['category'] ?? 'OTHER');
        $ia = $brandOrder[$ca] ?? 99;
        $ib = $brandOrder[$cb] ?? 99;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }
        return strcasecmp(
            (string) ($a['product_name'] ?? $a['name'] ?? ''),
            (string) ($b['product_name'] ?? $b['name'] ?? '')
        );
    });
    return $lines;
}

/** @param list<array<string, mixed>> $lines */
function depot_enrich_stock_lines(array $lines): array
{
    foreach ($lines as &$line) {
        if (empty($line['brand']) && empty($line['category'])) {
            $line['category'] = depot_category_for_product(
                (string) ($line['product_name'] ?? $line['name'] ?? ''),
                (string) ($line['sku'] ?? '')
            );
            $line['brand'] = $line['category'];
        } elseif (empty($line['brand'])) {
            $line['brand'] = (string) $line['category'];
        } elseif (empty($line['category'])) {
            $line['category'] = (string) $line['brand'];
        }
    }
    unset($line);
    return depot_sort_lines_by_category($lines);
}

/** Map retired warehouse SKUs onto the current LAPOK BOOK page 1 SKUs. */
function depot_legacy_stock_sku_map(): array
{
    return [
        'EN-PREDATOR' => 'EN-GOLD',
        'EN-PLAY' => 'EN-POWERPLAY',
        'RGB-300' => '300-COKE',
        'PET-300' => '330-COKE',
        'PET-500' => '500-COKE',
        'PET-2000' => '2L-COKE',
        'CK-1L' => '1L-COKE',
        'MM-400' => '400-MM-MANGO',
        'MM-1L' => '1L-MM-MANGO',
        'RF-250' => '280-RF-MANGO',
        'RW-500-BOX' => 'RW-500-X24',
        'RW-500-SHR' => 'RW-SHRINX',
        'RW-1500' => 'RW-1500-X12',
        'JUMBO-20' => 'RW-5000-X4',
        'JUMBO-10' => 'RW-JUMBO',
        'BOTTLES' => 'EMPTY-300',
        'SHELLS' => 'EMPTY-SHELL',
        'POWERPLAY' => 'EN-POWERPLAY',
    ];
}

/**
 * Rebuild stock lines from the current flavor catalog and carry forward saved counts.
 * Drops deactivated / duplicate legacy rows (e.g. PREDATOR GOLD + PREDATOR).
 *
 * @param list<array<string, mixed>> $savedLines
 * @return list<array<string, mixed>>
 */
function depot_merge_snapshot_onto_catalog(array $savedLines): array
{
    $catalog = depot_stock_lines_from_warehouse();
    $bySku = [];
    $byId = [];
    foreach ($catalog as $line) {
        $bySku[strtoupper((string) $line['sku'])] = $line;
        $byId[(int) $line['product_id']] = &$bySku[strtoupper((string) $line['sku'])];
    }
    unset($line);

    $legacy = depot_legacy_stock_sku_map();
    foreach ($savedLines as $saved) {
        $pid = (int) ($saved['product_id'] ?? 0);
        $sku = strtoupper(trim((string) ($saved['sku'] ?? '')));
        if (isset($legacy[$sku])) {
            $sku = strtoupper($legacy[$sku]);
        }
        $target = null;
        if ($pid > 0 && isset($byId[$pid])) {
            $target = &$byId[$pid];
        } elseif ($sku !== '' && isset($bySku[$sku])) {
            $target = &$bySku[$sku];
        } else {
            unset($target);
            continue;
        }

        $opening = (int) ($saved['opening'] ?? (($saved['closing'] ?? null) === null ? ($saved['qty'] ?? 0) : ($saved['opening'] ?? 0)));
        $closing = (int) ($saved['closing'] ?? 0);
        if (!isset($saved['closing']) && isset($saved['qty']) && isset($saved['opening']) === false) {
            // Older snapshots only stored qty — treat as the count for that snapshot type later in UI.
            $opening = (int) ($saved['qty'] ?? 0);
        }
        $sales = (int) ($saved['sales'] ?? 0);

        $target['opening'] = max((int) ($target['opening'] ?? 0), $opening);
        // Purchase is driven by Coca-Cola deliveries — do not carry manual snapshot values.
        $target['sales'] = max((int) ($target['sales'] ?? 0), $sales);
        $target['closing'] = max((int) ($target['closing'] ?? 0), $closing);
        $target['qty'] = max((int) ($target['qty'] ?? 0), (int) ($saved['qty'] ?? max($opening, $closing)));
        unset($target);
    }

    return array_values($bySku);
}

function depot_director_snapshot(string $date): array
{
    $pdo = db();
    $month = substr($date, 0, 7);
    $from = $date . ' 00:00:00';
    $to = $date . ' 23:59:59';

    $revStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount_total), 0) FROM orders
         WHERE status IN ('confirmed','delivered','dispatched')
           AND created_at BETWEEN ? AND ?"
    );
    $revStmt->execute([$from, $to]);
    $revenue = (float) $revStmt->fetchColumn();

    $fuelStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(fuel_cost), 0) FROM delivery_trips
         WHERE dispatched_at BETWEEN ? AND ?"
    );
    $fuelStmt->execute([$from, $to]);
    $fuelCost = (float) $fuelStmt->fetchColumn();

    $rdcStmt = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $rdcStmt->execute([$date]);
    $rdc = $rdcStmt->fetch() ?: null;
    $rdcExpenses = $rdc ? (float) ($rdc['expenses_total'] ?? 0) : 0.0;
    $rdcVariance = $rdc ? (float) ($rdc['variance'] ?? 0) : 0.0;
    $rdcRevenue = $rdc ? (float) ($rdc['grand_total'] ?? 0) : 0.0;
    $rdcStatus = $rdc ? (string) ($rdc['status'] ?? 'draft') : 'missing';

    $variableExpenses = $rdcExpenses + $fuelCost;
    $fixed = depot_fixed_costs_for_month($month);
    $fixedDaily = depot_daily_fixed_allocation($date, $fixed);
    $totalExpenses = $variableExpenses + $fixedDaily;

    $bookRevenue = $rdcRevenue > 0 ? $rdcRevenue : $revenue;
    $grossProfit = $bookRevenue - $variableExpenses;
    $netOperating = $bookRevenue - $totalExpenses;
    $expenseRatio = $bookRevenue > 0 ? round(($totalExpenses / $bookRevenue) * 100, 1) : 0.0;

    $cashVarStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS(COALESCE(cash_collected, 0) - COALESCE(cash_reported, 0))), 0)
         FROM delivery_trips
         WHERE status = 'returned' AND DATE(returned_at) = ?"
    );
    $cashVarStmt->execute([$date]);
    $cashShortage = (float) $cashVarStmt->fetchColumn();

    $stockVarStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS((tli.qty_loaded - tli.qty_sold) - tli.qty_returned)), 0)
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE dt.status = 'returned' AND DATE(dt.returned_at) = ?"
    );
    $stockVarStmt->execute([$date]);
    $stockShortageUnits = (int) $stockVarStmt->fetchColumn();

    $tripStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN status IN ('dispatched','on_route') THEN 1 ELSE 0 END) AS out_now,
            SUM(CASE WHEN status = 'returned' AND DATE(returned_at) = ? THEN 1 ELSE 0 END) AS returned_today,
            SUM(CASE WHEN status = 'returned' AND DATE(returned_at) = ? AND cash_collected IS NULL THEN 1 ELSE 0 END) AS cash_pending
         FROM delivery_trips
         WHERE DATE(dispatched_at) = ? OR DATE(returned_at) = ?"
    );
    $tripStmt->execute([$date, $date, $date, $date]);
    $tripStats = $tripStmt->fetch() ?: ['out_now' => 0, 'returned_today' => 0, 'cash_pending' => 0];

    $opening = depot_snapshot_fetch($date, 'opening');
    $closing = depot_snapshot_fetch($date, 'closing');

    $hour = (int) date('G');
    $minute = (int) date('i');
    $nowMins = $hour * 60 + $minute;
    $closeDue = 19 * 60;
    $closeLate = 19 * 60 + 30;

    $readiness = 'on_track';
    if (!$closing && $nowMins >= $closeLate) {
        $readiness = 'late';
    } elseif (!$closing && $nowMins >= $closeDue) {
        $readiness = 'due';
    } elseif (!$opening) {
        $readiness = 'opening_missing';
    }

    return [
        'date' => $date,
        'revenue' => [
            'orders' => $revenue,
            'rdc_booked' => $rdcRevenue,
            'used' => $bookRevenue,
        ],
        'expenses' => [
            'variable' => $variableExpenses,
            'rdc_operating' => $rdcExpenses,
            'fuel' => $fuelCost,
            'fixed_daily' => round($fixedDaily, 2),
            'fixed_monthly' => depot_monthly_fixed_total($fixed),
            'total' => round($totalExpenses, 2),
            'fixed_breakdown' => [
                'rent' => (float) ($fixed['rent_ugx'] ?? 0),
                'salaries' => (float) ($fixed['salaries_ugx'] ?? 0),
                'utilities' => (float) ($fixed['utilities_ugx'] ?? 0),
                'security' => (float) ($fixed['security_ugx'] ?? 0),
                'other' => (float) ($fixed['other_ugx'] ?? 0),
            ],
        ],
        'profit' => [
            'gross' => round($grossProfit, 2),
            'net_operating' => round($netOperating, 2),
            'expense_ratio_pct' => $expenseRatio,
        ],
        'shortages' => [
            'cash_variance_ugx' => $cashShortage,
            'stock_variance_units' => $stockShortageUnits,
            'rdc_variance_ugx' => abs($rdcVariance),
            'total_flag_ugx' => round($cashShortage + abs($rdcVariance), 2),
        ],
        'controls' => [
            'opening_submitted' => (bool) $opening,
            'closing_submitted' => (bool) $closing,
            'rdc_status' => $rdcStatus,
            'readiness' => $readiness,
            'trips_out' => (int) ($tripStats['out_now'] ?? 0),
            'trips_returned' => (int) ($tripStats['returned_today'] ?? 0),
            'cash_handovers_pending' => (int) ($tripStats['cash_pending'] ?? 0),
        ],
        'opening_snapshot' => $opening,
        'closing_snapshot' => $closing,
    ];
}
