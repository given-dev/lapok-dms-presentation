<?php
declare(strict_types=1);

require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/depot_catalog.php';
require_once __DIR__ . '/cadet_reports.php';

// Shared datetime range helpers (also defined in bootstrap.php for API endpoints);
// guarded so this file is self-contained when loaded without bootstrap (CLI/cron).
if (!function_exists('day_bounds')) {
    function day_bounds(string $date): array
    {
        return [$date . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($date . ' +1 day'))];
    }
}
if (!function_exists('period_bounds')) {
    function period_bounds(string $from, string $to): array
    {
        return [$from . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($to . ' +1 day'))];
    }
}

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

function depot_stock_lines_from_warehouse(?string $date = null, bool $includeCadetReturns = false): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $ensured = depot_ensure_warehouse_products();
    $qtyById = [];
    foreach (db()->query(stock_summary_query())->fetchAll() as $row) {
        $qtyById[(int) $row['product_id']] = (int) ($row['warehouse_qty'] ?? 0);
    }

    $returnsById = [];
    if ($includeCadetReturns && $date !== null && $date !== '') {
        $returnsById = depot_cadet_returns_for_date($date);
    }

    $purchaseById = [];
    if ($date !== null && $date !== '') {
        $purchaseById = depot_purchases_from_deliveries($date);
    }

    $lines = [];
    foreach ($ensured as $row) {
        $productId = (int) $row['product_id'];
        $brand = (string) ($row['brand'] ?? $row['category'] ?? '');
        $returns = (int) ($returnsById[$productId] ?? 0);
        // Closing stock = the live warehouse ledger. Cadet remainders are restocked
        // back into the ledger when the trip's report is applied (dispatch_return),
        // so they must NOT be added a second time here.
        $closing = (int) ($qtyById[$productId] ?? 0);
        $lines[] = [
            'product_id' => $productId,
            'product_name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'brand' => $brand,
            'qty' => $includeCadetReturns ? $closing : (int) ($qtyById[$productId] ?? 0),
            'opening' => 0,
            'purchase' => (int) ($purchaseById[$productId] ?? 0),
            'sales' => 0,
            'closing' => $includeCadetReturns ? $closing : 0,
            'returns' => $returns,
            'category' => $brand,
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'rdc_key' => (string) ($row['rdc_key'] ?? ''),
            'sort' => (int) ($row['sort'] ?? 999),
            'purchase_source' => 'coca_cola_delivery',
        ];
    }
    return depot_sort_lines_by_category($lines);
}

/** Total units each product's cadets returned on a given date (unsold evening stock). */
function depot_cadet_returns_for_date(string $date): array
{
    [$start, $end] = day_bounds($date);
    $stmt = db()->prepare(
        "SELECT tli.product_id, COALESCE(SUM(tli.qty_returned), 0) AS ret
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE dt.status IN ('returned','completed') AND dt.returned_at >= ? AND dt.returned_at < ?
         GROUP BY tli.product_id"
    );
    $stmt->execute([$start, $end]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['product_id']] = (int) ($row['ret'] ?? 0);
    }
    return $out;
}

/** Total units each product's cadets sold on a given date (from submitted trip reports). */
function depot_cadet_sales_for_date(string $date): array
{
    [$start, $end] = day_bounds($date);
    $stmt = db()->prepare(
        "SELECT tli.product_id, COALESCE(SUM(tli.qty_sold), 0) AS sold
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE dt.status IN ('returned','completed') AND dt.returned_at >= ? AND dt.returned_at < ?
         GROUP BY tli.product_id"
    );
    $stmt->execute([$start, $end]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['product_id']] = (int) ($row['sold'] ?? 0);
    }
    return $out;
}

/**
 * Consolidated sales revenue per returned date (RDC sheet primary, cadet trip reports as fallback).
 * The RDC sheet is the book; if a sheet does not exist for a day, cadet report totals cover it.
 *
 * @return array<string, float> balance_date => UGX revenue
 */
function depot_sales_revenue_by_day(string $from, string $to): array
{
    $pdo = db();
    $out = [];
    $stmt = $pdo->prepare(
        'SELECT balance_date, sales_total FROM rdc_daily_sheets WHERE balance_date BETWEEN ? AND ?'
    );
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['balance_date']] = (float) $row['sales_total'];
    }

    [$start, $end] = period_bounds($from, $to);
    $tStmt = $pdo->prepare(
        "SELECT DATE(returned_at) AS d, notes FROM delivery_trips
         WHERE status IN ('returned','completed') AND returned_at >= ? AND returned_at < ?"
    );
    $tStmt->execute([$start, $end]);
    foreach ($tStmt->fetchAll() as $row) {
        $parsed = cadet_parse_report_note($row['notes'] ?? null);
        $revenue = (float) ($parsed['sales_total'] ?? 0);
        if ($revenue > 0) {
            $day = $row['d'];
            $out[$day] = max($out[$day] ?? 0.0, $revenue);
        }
    }
    ksort($out);
    return $out;
}

/** Cartons sold (qty_sold) per returned date from submitted trip reports. */
function depot_cartons_sold_by_day(string $from, string $to): array
{
    $pdo = db();
    [$start, $end] = period_bounds($from, $to);
    $stmt = $pdo->prepare(
        "SELECT DATE(dt.returned_at) AS d, COALESCE(SUM(tli.qty_sold), 0) AS sold
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE dt.status IN ('returned','completed') AND dt.returned_at >= ? AND dt.returned_at < ?
         GROUP BY DATE(dt.returned_at)"
    );
    $stmt->execute([$start, $end]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['d']] = (int) $row['sold'];
    }
    return $out;
}

/**
 * Build the WHERE clause + params for trips returned in a period with optional filters.
 *
 * @return array{0: string, 1: list<mixed>}
 */
function depot_trip_filter_where(string $from, string $to, int $routeId = 0, int $vehicleId = 0, int $userId = 0): array
{
    [$start, $end] = period_bounds($from, $to);
    $where = ["dt.status IN ('returned','completed')", 'dt.returned_at >= ? AND dt.returned_at < ?'];
    $params = [$start, $end];
    if ($routeId > 0) {
        $where[] = 'dt.route_id = ?';
        $params[] = $routeId;
    }
    if ($vehicleId > 0) {
        $where[] = 'dt.vehicle_id = ?';
        $params[] = $vehicleId;
    }
    if ($userId > 0) {
        $where[] = '(dt.cadet_id = ? OR dt.driver_id = ?)';
        $params[] = $userId;
        $params[] = $userId;
    }
    return [$where, $params];
}

/**
 * Revenue per returned date from cadet trip reports, with optional trip filters.
 * Useful for filter-aware reports (per-vehicle / per-route / per-user).
 *
 * @return array<string, float>
 */
function depot_trip_revenue_by_day(string $from, string $to, int $routeId = 0, int $vehicleId = 0, int $userId = 0): array
{
    [$where, $params] = depot_trip_filter_where($from, $to, $routeId, $vehicleId, $userId);
    $stmt = db()->prepare(
        'SELECT DATE(dt.returned_at) AS d, dt.notes FROM delivery_trips dt WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $parsed = cadet_parse_report_note($row['notes'] ?? null);
        $revenue = (float) ($parsed['sales_total'] ?? 0);
        if ($revenue > 0) {
            $out[$row['d']] = ($out[$row['d']] ?? 0.0) + $revenue;
        }
    }
    ksort($out);
    return $out;
}

/** Cartons sold per returned date from cadet trip reports, with optional trip filters. */
function depot_trip_cartons_by_day(string $from, string $to, int $routeId = 0, int $vehicleId = 0, int $userId = 0): array
{
    [$where, $params] = depot_trip_filter_where($from, $to, $routeId, $vehicleId, $userId);
    $stmt = db()->prepare(
        "SELECT DATE(dt.returned_at) AS d, COALESCE(SUM(tli.qty_sold), 0) AS sold
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE " . implode(' AND ', $where) . "
         GROUP BY DATE(dt.returned_at)"
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['d']] = (int) $row['sold'];
    }
    return $out;
}

/**
 * Sales come from cadet reports and are never typed into the stock book.
 * Overlay cadet-derived sales onto stock lines so the book always matches what cadets reported.
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function depot_apply_cadet_sales_from_trips(array $lines, ?string $date = null): array
{
    if ($date === null || $date === '') {
        return $lines;
    }
    $soldById = depot_cadet_sales_for_date($date);
    foreach ($lines as &$line) {
        $pid = (int) ($line['product_id'] ?? 0);
        $line['sales'] = (int) ($soldById[$pid] ?? 0);
        $line['sales_source'] = 'cadet_reports';
    }
    unset($line);
    return $lines;
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
        $sa = (int) ($a['sort'] ?? PHP_INT_MAX);
        $sb = (int) ($b['sort'] ?? PHP_INT_MAX);
        if ($sa !== $sb) {
            return $sa <=> $sb;
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
 * Soda (CSD) vs Water (WATER) sales units + revenue MTD from RDC daily sheets.
 * The DEPOT column on each sheet line is the all-vehicle total.
 *
 * @return array{soda_units: float, water_units: float, soda_revenue: float, water_revenue: float}
 */
function depot_sales_split_mtd(string $from, string $to): array
{
    $out = ['soda_units' => 0.0, 'water_units' => 0.0, 'soda_revenue' => 0.0, 'water_revenue' => 0.0];
    $stmt = db()->prepare(
        'SELECT sales_json FROM rdc_daily_sheets WHERE balance_date BETWEEN ? AND ?'
    );
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        foreach (json_decode((string) ($row['sales_json'] ?? '[]'), true) ?: [] as $line) {
            $target = depot_target_classify($line);
            if ($target === null) {
                continue;
            }
            // Sum the whole sales unit (DEPOT column + every cadet vehicle column),
            // so the overall SODA / WATER totals include what each cadet sold.
            $qty = 0.0;
            $qtyMap = is_array($line['qty'] ?? null) ? $line['qty'] : [];
            foreach ($qtyMap as $col => $q) {
                if ($col === 'depot' || $col === 'kamdini' || str_starts_with($col, 'vehicle_')) {
                    $qty += (float) $q;
                }
            }
            if ($qty <= 0) {
                continue;
            }
            $out[$target . '_units'] += $qty;
            $out[$target . '_revenue'] += $qty * (float) ($line['price'] ?? 0);
        }
    }
    return $out;
}

/**
 * Per sales unit (DEPOT column + each active vehicle column) soda/water units MTD.
 * Reads every RDC sheet between $from and $to; keys are the sheet qty column names.
 *
 * @return array<string, array{soda: float, water: float}> e.g. ['depot' => [...], 'vehicle_2' => [...]]
 */
function depot_sales_split_by_unit_mtd(string $from, string $to): array
{
    $units = [];
    $stmt = db()->prepare('SELECT sales_json FROM rdc_daily_sheets WHERE balance_date BETWEEN ? AND ?');
    $stmt->execute([$from, $to]);
    foreach ($stmt->fetchAll() as $row) {
        foreach (json_decode((string) ($row['sales_json'] ?? '[]'), true) ?: [] as $line) {
            $target = depot_target_classify($line);
            if ($target === null) {
                continue;
            }
            $qtyMap = is_array($line['qty'] ?? null) ? $line['qty'] : [];
            foreach ($qtyMap as $col => $qty) {
                $col = (string) $col;
                if ($col !== 'depot' && $col !== 'kamdini' && !str_starts_with($col, 'vehicle_')) {
                    continue;
                }
                $q = (float) $qty;
                if ($q <= 0) {
                    continue;
                }
                $units[$col][$target] = ($units[$col][$target] ?? 0.0) + $q;
            }
        }
    }
    return $units;
}

/**
 * sales_targets rows for a month keyed by unit: 'DEPOT' or the vehicle id string.
 *
 * @return array<string, array{soda: float, water: float}>
 */
function depot_targets_for_month(string $month): array
{
    $out = [];
    $stmt = db()->prepare('SELECT vehicle_id, category, target_units FROM sales_targets WHERE target_month = ?');
    $stmt->execute([$month]);
    foreach ($stmt->fetchAll() as $t) {
        $unit = $t['vehicle_id'] === null ? 'DEPOT' : ((int) $t['vehicle_id'] === 0 ? 'KAMDINI' : (string) (int) $t['vehicle_id']);
        $out[$unit][strtolower((string) $t['category'])] = (float) $t['target_units'];
    }
    return $out;
}

/**
 * Per-unit target vs actual breakdown for the executive board (DEPOT first, then trucks, then tuktuk).
 *
 * @return list<array<string, mixed>>
 */
function depot_sales_target_breakdown(string $from, string $to, string $month): array
{
    $pdo = db();
    $vehicles = $pdo->query(
        "SELECT v.id, v.registration, v.vehicle_type, v.cadet_id, u.full_name AS cadet_name
         FROM vehicles v
         LEFT JOIN users u ON u.id = v.cadet_id
         WHERE v.is_active = 1
         ORDER BY v.vehicle_type, v.registration"
    )->fetchAll();
    $actuals = depot_sales_split_by_unit_mtd($from, $to);
    $targets = depot_targets_for_month($month);

    $rows = [];
    $rows[] = depot_target_breakdown_row('depot', null, 'DEPOT', null, $targets, $actuals);
    $rows[] = depot_target_breakdown_row('kamdini', 0, 'KAMDINI', null, $targets, $actuals, true);
    foreach ($vehicles as $v) {
        $short = '';
        if (!empty($v['cadet_name'])) {
            $parts = preg_split('/\s+/', trim((string) $v['cadet_name']));
            $short = strtoupper($parts[0] ?? '');
        }
        $label = strtoupper((string) $v['registration']) . ($short !== '' ? ' - ' . $short : '');
        $rows[] = depot_target_breakdown_row(
            'vehicle_' . $v['id'],
            (int) $v['id'],
            $label,
            (string) $v['vehicle_type'],
            $targets,
            $actuals
        );
    }
    return $rows;
}

/** @return array<string, mixed> */
function depot_target_breakdown_row(string $key, ?int $vehicleId, string $label, ?string $vehicleType, array $targets, array $actuals, ?bool $isDepotOverride = null): array
{
    $isDepot = $isDepotOverride ?? ($vehicleId === null);
    $targetKey = $vehicleId === null
        ? 'DEPOT'
        : ((int) $vehicleId === 0 ? 'KAMDINI' : (string) $vehicleId);
    $actual = $actuals[$key] ?? ['soda' => 0.0, 'water' => 0.0];
    $sodaTarget = (float) ($targets[$targetKey]['soda'] ?? 0);
    $waterTarget = (float) ($targets[$targetKey]['water'] ?? 0);
    $sodaUnits = (float) ($actual['soda'] ?? 0);
    $waterUnits = (float) ($actual['water'] ?? 0);
    $pct = static function (float $actual, float $target): float {
        return $target > 0 ? round(($actual / $target) * 100, 1) : 0.0;
    };
    return [
        'key' => $key,
        'vehicle_id' => $vehicleId,
        'label' => $label,
        'vehicle_type' => $vehicleType,
        'is_depot' => $isDepot,
        'soda_target' => $sodaTarget,
        'water_target' => $waterTarget,
        'soda_units' => $sodaUnits,
        'water_units' => $waterUnits,
        'soda_pct' => $pct($sodaUnits, $sodaTarget),
        'water_pct' => $pct($waterUnits, $waterTarget),
        'total_pct' => $pct($sodaUnits + $waterUnits, $sodaTarget + $waterTarget),
    ];
}

/**
 * Target vs actual for a single sales unit (a cadet's vehicle, or the depot with vehicleId = null).
 * Used by the cadet dashboard ("am I meeting my target?") and depot read views.
 *
 * @return array<string, mixed>
 */
function depot_unit_target_actual(string $from, string $to, string $month, ?int $vehicleId): array
{
    $key = $vehicleId === null ? 'DEPOT' : 'vehicle_' . $vehicleId;
    $targets = depot_targets_for_month($month);
    $actuals = depot_sales_split_by_unit_mtd($from, $to);
    return depot_target_breakdown_row($key, $vehicleId, '', null, $targets, $actuals);
}

/** Sum the `amounts` map of every row in an RDC JSON column (cash_out / recoveries / expenses). */
function depot_sheet_json_sum(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        foreach (is_array($row['amounts'] ?? null) ? $row['amounts'] : [] as $v) {
            $total += (float) $v;
        }
    }
    return round($total, 2);
}

/** Sum one RDC sheet JSON column (cash_out_json / recoveries_json) across a date range. */
function depot_sheet_json_total(string $column, string $from, string $to): float
{
    if (!in_array($column, ['cash_out_json', 'recoveries_json'], true)) {
        return 0.0;
    }
    $stmt = db()->prepare("SELECT $column FROM rdc_daily_sheets WHERE balance_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    $total = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $total += depot_sheet_json_sum(json_decode((string) ($row[$column] ?? '[]'), true) ?: []);
    }
    return round($total, 2);
}

/**
 * Cash still out (CSO) as of a date = every cash-out on the approved RDC sheets up to that date,
 * minus every recovery on those sheets up to that date, clipped at zero. The RDC daily sheet is
 * the consolidated source of truth (it also absorbs the cashout ledger via prefill), so previous
 * months roll forward automatically — recurring data, no manual carry-forward needed.
 */
function depot_cash_still_out_as_of(string $asOfDate): float
{
    $stmt = db()->prepare('SELECT cash_out_json, recoveries_json FROM rdc_daily_sheets WHERE balance_date <= ?');
    $stmt->execute([$asOfDate]);
    $issued = 0.0;
    $recovered = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $issued += depot_sheet_json_sum(json_decode((string) ($row['cash_out_json'] ?? '[]'), true) ?: []);
        $recovered += depot_sheet_json_sum(json_decode((string) ($row['recoveries_json'] ?? '[]'), true) ?: []);
    }
    return round(max(0.0, $issued - $recovered), 2);
}

/**
 * CSO monthly history for the last $months months (oldest -> newest, ending at $asOfDate
 * for the current month). Used to keep the executive updated on recurring outstanding cash.
 *
 * @return list<array{month: string, cso: float}>
 */
function depot_cash_still_out_history(string $asOfDate, int $months = 6): array
{
    // Anchor month arithmetic on the 1st so month-end dates (31st) don't roll over.
    $anchor = substr($asOfDate, 0, 7) . '-01';
    $rows = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-{$i} months", strtotime($anchor)));
        $end = date('Y-m-t', strtotime($m . '-01'));
        $asOf = $i === 0 ? $asOfDate : $end;
        $rows[] = ['month' => $m, 'cso' => round(depot_cash_still_out_as_of($asOf), 2)];
    }
    return $rows;
}

/**
 * Sum one RDC expense line across MTD (DEPOT column). Used for DISCOUNT on the executive board.
 */
function depot_expense_line_mtd(string $from, string $to, string $wantLabel): float
{
    $stmt = db()->prepare(
        'SELECT expenses_json FROM rdc_daily_sheets WHERE balance_date BETWEEN ? AND ?'
    );
    $stmt->execute([$from, $to]);
    $total = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        foreach (json_decode((string) ($row['expenses_json'] ?? '[]'), true) ?: [] as $line) {
            if (strtoupper((string) ($line['label'] ?? '')) === $wantLabel) {
                $total += (float) ($line['amounts']['depot'] ?? 0);
            }
        }
    }
    return $total;
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

    [$start, $end] = day_bounds($date);

    $cashVarStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS(COALESCE(cash_collected, 0) - COALESCE(cash_reported, 0))), 0)
         FROM delivery_trips
         WHERE status IN ('returned','completed') AND returned_at >= ? AND returned_at < ?
           AND cash_collected IS NOT NULL"
    );
    $cashVarStmt->execute([$start, $end]);
    $cashShortage = (float) $cashVarStmt->fetchColumn();

    $stockVarStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS((tli.qty_loaded - tli.qty_sold) - tli.qty_returned)), 0)
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE dt.status = 'returned' AND dt.returned_at >= ? AND dt.returned_at < ?"
    );
    $stockVarStmt->execute([$start, $end]);
    $stockShortageUnits = (int) $stockVarStmt->fetchColumn();

    $tripStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN status IN ('dispatched','on_route') THEN 1 ELSE 0 END) AS out_now,
            SUM(CASE WHEN status = 'returned' AND returned_at >= ? AND returned_at < ? THEN 1 ELSE 0 END) AS returned_today,
            SUM(CASE WHEN status = 'returned' AND returned_at >= ? AND returned_at < ? AND cash_collected IS NULL THEN 1 ELSE 0 END) AS cash_pending
         FROM delivery_trips
         WHERE (dispatched_at >= ? AND dispatched_at < ?) OR (returned_at >= ? AND returned_at < ?)"
    );
    $tripStmt->execute([$start, $end, $start, $end, $start, $end, $start, $end]);
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

function depot_director_snapshot_monthly(string $month): array
{
    $pdo = db();
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $from = $month . '-01 00:00:00';
    $to = date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59';

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

    $rdcStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(expenses_total),0) AS exp, COALESCE(SUM(grand_total),0) AS rev,
                COALESCE(SUM(variance),0) AS var, COUNT(*) AS n,
                MAX(CASE WHEN status = \'approved\' THEN status END) AS approved,
                MAX(status) AS any_status
         FROM rdc_daily_sheets
         WHERE balance_date BETWEEN ? AND ?'
    );
    $rdcStmt->execute([substr($from, 0, 10), substr($to, 0, 10)]);
    $rdcRow = $rdcStmt->fetch() ?: ['exp' => 0, 'rev' => 0, 'var' => 0, 'n' => 0, 'approved' => null, 'any_status' => null];
    $rdcExpenses = (float) ($rdcRow['exp'] ?? 0);
    $rdcRevenue = (float) ($rdcRow['rev'] ?? 0);
    $rdcVariance = (float) ($rdcRow['var'] ?? 0);
    $rdcCount = (int) ($rdcRow['n'] ?? 0);
    $rdcStatus = $rdcCount > 0
        ? ((string) ($rdcRow['approved'] ?? '') !== '' ? 'approved' : (string) ($rdcRow['any_status'] ?? 'draft'))
        : 'missing';

    $variableExpenses = $rdcExpenses + $fuelCost;
    $fixed = depot_fixed_costs_for_month($month);
    $fixedTotal = depot_monthly_fixed_total($fixed);
    $totalExpenses = $variableExpenses + $fixedTotal;

    $bookRevenue = $rdcRevenue > 0 ? $rdcRevenue : $revenue;
    $grossProfit = $bookRevenue - $variableExpenses;
    $netOperating = $bookRevenue - $totalExpenses;
    $expenseRatio = $bookRevenue > 0 ? round(($totalExpenses / $bookRevenue) * 100, 1) : 0.0;

    [$mStart, $mEnd] = period_bounds(substr($from, 0, 10), substr($to, 0, 10));

    $cashVarStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS(COALESCE(cash_collected, 0) - COALESCE(cash_reported, 0))), 0)
         FROM delivery_trips
         WHERE status IN ('returned','completed') AND returned_at >= ? AND returned_at < ?
           AND cash_collected IS NOT NULL"
    );
    $cashVarStmt->execute([$mStart, $mEnd]);
    $cashShortage = (float) $cashVarStmt->fetchColumn();

    $stockVarStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(ABS((tli.qty_loaded - tli.qty_sold) - tli.qty_returned)), 0)
         FROM trip_load_items tli
         JOIN delivery_trips dt ON dt.id = tli.trip_id
         WHERE dt.status = 'returned' AND dt.returned_at >= ? AND dt.returned_at < ?"
    );
    $stockVarStmt->execute([$mStart, $mEnd]);
    $stockShortageUnits = (int) $stockVarStmt->fetchColumn();

    return [
        'date' => $month,
        'monthly' => true,
        'revenue' => [
            'orders' => $revenue,
            'rdc_booked' => $rdcRevenue,
            'used' => $bookRevenue,
        ],
        'expenses' => [
            'variable' => $variableExpenses,
            'rdc_operating' => $rdcExpenses,
            'fuel' => $fuelCost,
            'fixed_daily' => 0.0,
            'fixed_monthly' => $fixedTotal,
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
            'opening_submitted' => null,
            'closing_submitted' => null,
            'rdc_status' => $rdcStatus,
            'readiness' => null,
            'trips_out' => null,
            'trips_returned' => null,
            'cash_handovers_pending' => null,
            'monthly' => true,
        ],
        'opening_snapshot' => null,
        'closing_snapshot' => null,
    ];
}
