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

function depot_stock_lines_from_warehouse(): array
{
    $lines = [];
    foreach (db()->query(stock_summary_query())->fetchAll() as $row) {
        $lines[] = [
            'product_id' => (int) $row['product_id'],
            'product_name' => (string) $row['name'],
            'sku' => (string) ($row['sku'] ?? ''),
            'qty' => (int) ($row['warehouse_qty'] ?? 0),
            'category' => depot_category_for_product((string) $row['name'], (string) ($row['sku'] ?? '')),
        ];
    }
    return depot_sort_lines_by_category($lines);
}

/** @param list<array<string, mixed>> $lines */
function depot_sort_lines_by_category(array $lines): array
{
    $order = array_flip(depot_category_order());
    usort($lines, function ($a, $b) use ($order) {
        $ca = $order[$a['category'] ?? 'OTHER'] ?? 99;
        $cb = $order[$b['category'] ?? 'OTHER'] ?? 99;
        if ($ca !== $cb) {
            return $ca <=> $cb;
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
        if (empty($line['category'])) {
            $line['category'] = depot_category_for_product(
                (string) ($line['product_name'] ?? $line['name'] ?? ''),
                (string) ($line['sku'] ?? '')
            );
        }
    }
    unset($line);
    return depot_sort_lines_by_category($lines);
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
