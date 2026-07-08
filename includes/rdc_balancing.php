<?php
declare(strict_types=1);

/** Default product/brand rows matching the depot Excel workbook. */
function rdc_default_sales_lines(): array
{
    require_once __DIR__ . '/depot_catalog.php';
    return array_map(
        fn(array $row) => [
            'label' => $row['label'],
            'price' => (float) $row['price'],
            'category' => $row['category'],
            'rdc_key' => $row['key'],
        ],
        depot_rdc_sales_catalog()
    );
}

/** @param list<array<string, mixed>> $sales */
function rdc_enrich_sales_lines(array $sales): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $byLabel = depot_catalog_by_label();
    $order = array_flip(depot_category_order());

    foreach ($sales as &$line) {
        $labelKey = strtoupper(trim((string) ($line['label'] ?? '')));
        if (isset($byLabel[$labelKey])) {
            $line['category'] = $byLabel[$labelKey]['category'];
            $line['rdc_key'] = $byLabel[$labelKey]['key'];
        } elseif (empty($line['category'])) {
            $line['category'] = 'OTHER';
        }
    }
    unset($line);

    usort($sales, function ($a, $b) use ($order) {
        $ca = $order[$a['category'] ?? 'OTHER'] ?? 99;
        $cb = $order[$b['category'] ?? 'OTHER'] ?? 99;
        if ($ca !== $cb) {
            return $ca <=> $cb;
        }
        return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
    });

    return $sales;
}

function rdc_default_expense_lines(): array
{
    return [
        'FUEL', 'LUNCH', 'DISCOUNT', 'SHORTAGE/EXCESS', 'URA / EFRIS',
        'STAFF SALARY', 'PAPER ROLL', 'PARKING', 'REPAIR',
        'TRUCKS AND TUKTUK REPAIR', 'TRANSPORT', 'PROMOTION', 'OTHER',
    ];
}

/** @return array<int, array<string, mixed>> */
function rdc_build_columns(): array
{
    $cols = [
        ['key' => 'depot', 'label' => 'DEPOT', 'section' => 'all'],
    ];

    $vehicles = db()->query(
        "SELECT v.id, v.registration, v.vehicle_type, v.cadet_id, u.full_name AS cadet_name
         FROM vehicles v
         LEFT JOIN users u ON u.id = v.cadet_id
         WHERE v.is_active = 1
         ORDER BY v.vehicle_type, v.registration"
    )->fetchAll();
    foreach ($vehicles as $v) {
        $label = strtoupper((string) $v['registration']);
        if (!empty($v['cadet_name'])) {
            $parts = preg_split('/\s+/', trim((string) $v['cadet_name']));
            $label .= ' · ' . strtoupper($parts[0] ?? 'CADET');
        }
        $cols[] = [
            'key' => 'vehicle_' . $v['id'],
            'label' => $label,
            'section' => 'sales_expense',
            'vehicle_id' => (int) $v['id'],
            'cadet_id' => $v['cadet_id'] ? (int) $v['cadet_id'] : null,
            'registration' => (string) $v['registration'],
        ];
    }

    $cadets = db()->query(
        "SELECT id, full_name FROM users WHERE role IN ('cadet','field_user') ORDER BY full_name"
    )->fetchAll();
    foreach ($cadets as $c) {
        $parts = preg_split('/\s+/', trim((string) $c['full_name']));
        $short = strtoupper($parts[0] ?? 'AGENT');
        $cols[] = [
            'key' => 'cadet_' . $c['id'],
            'label' => $short,
            'section' => 'recovery_cash',
            'user_id' => (int) $c['id'],
        ];
    }

    $cols[] = ['key' => 'momo', 'label' => 'MOMO', 'section' => 'cash'];
    $cols[] = ['key' => 'cash_at_hand', 'label' => 'CASH AT HAND', 'section' => 'cash'];

    return $cols;
}

/** @return array<string, float> */
function rdc_empty_amounts(array $columns): array
{
    $amounts = [];
    foreach ($columns as $col) {
        $amounts[$col['key']] = 0.0;
    }
    return $amounts;
}

/**
 * @param array<int, array<string, mixed>> $columns
 * @return array<int, array<string, mixed>>
 */
function rdc_blank_sales_lines(array $columns): array
{
    $salesCols = array_values(array_filter(
        $columns,
        fn($c) => $c['key'] === 'depot' || str_starts_with($c['key'], 'vehicle_')
    ));
    $keys = array_column($salesCols, 'key');

    $lines = [];
    foreach (rdc_default_sales_lines() as $tpl) {
        $qty = array_fill_keys($keys, 0);
        $lines[] = [
            'label' => $tpl['label'],
            'price' => (float) $tpl['price'],
            'category' => $tpl['category'] ?? 'OTHER',
            'rdc_key' => $tpl['rdc_key'] ?? null,
            'qty' => $qty,
        ];
    }
    return $lines;
}

/** @param array<int, array<string, mixed>> $columns */
function rdc_blank_expense_lines(array $columns): array
{
    $expCols = array_values(array_filter(
        $columns,
        fn($c) => in_array($c['section'], ['all', 'sales_expense'], true)
            || $c['key'] === 'depot'
            || str_starts_with($c['key'], 'vehicle_')
    ));
    $keys = array_unique(array_merge(
        ['depot'],
        array_column(array_filter($expCols, fn($c) => str_starts_with($c['key'], 'vehicle_')), 'key')
    ));
    $keys[] = 'other';

    $lines = [];
    foreach (rdc_default_expense_lines() as $label) {
        $amounts = [];
        foreach ($keys as $k) {
            $amounts[$k] = 0.0;
        }
        $lines[] = ['label' => $label, 'amounts' => $amounts];
    }
    return $lines;
}

/** @param array<int, array<string, mixed>> $lines */
function rdc_calc_sales_total(array $lines): float
{
    $total = 0.0;
    foreach ($lines as $line) {
        $price = (float) ($line['price'] ?? 0);
        $qtySum = array_sum(array_map('floatval', $line['qty'] ?? []));
        $total += $qtySum * $price;
    }
    return round($total, 2);
}

/** @param array<int, array<string, mixed>> $rows */
function rdc_sum_row_amounts(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        foreach ($row['amounts'] ?? [] as $v) {
            $total += (float) $v;
        }
    }
    return round($total, 2);
}

/** @param array<string, float> $amounts */
function rdc_sum_amounts_map(array $amounts): float
{
    return round(array_sum(array_map('floatval', $amounts)), 2);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function rdc_compute_totals(array $payload): array
{
    $salesTotal = rdc_calc_sales_total($payload['sales'] ?? []);
    $recoveryTotal = rdc_sum_row_amounts($payload['recoveries'] ?? []);
    $expensesTotal = rdc_sum_row_amounts($payload['expenses'] ?? []);
    $grandTotal = round($salesTotal + $recoveryTotal, 2);
    $expected = isset($payload['expected_amount'])
        ? (float) $payload['expected_amount']
        : round($grandTotal - $expensesTotal, 2);
    $actual = rdc_sum_amounts_map($payload['cash_actual'] ?? []);
    $variance = round($expected - $actual, 2);

    return [
        'sales_total' => $salesTotal,
        'recovery_total' => $recoveryTotal,
        'expenses_total' => $expensesTotal,
        'grand_total' => $grandTotal,
        'expected_amount' => $expected,
        'actual_total' => $actual,
        'variance' => $variance,
    ];
}

/** @return array<string, mixed> */
function rdc_new_sheet_template(string $date): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $columns = rdc_build_columns();
    $salesCols = array_values(array_filter(
        $columns,
        fn($c) => $c['key'] === 'depot' || str_starts_with($c['key'], 'vehicle_')
    ));
    $recoveryCols = array_values(array_filter(
        $columns,
        fn($c) => $c['key'] === 'depot' || str_starts_with($c['key'], 'cadet_')
    ));
    $cashCols = array_values(array_filter(
        $columns,
        fn($c) => in_array($c['key'], ['depot', 'momo', 'cash_at_hand'], true)
            || str_starts_with($c['key'], 'cadet_')
            || str_starts_with($c['key'], 'vehicle_')
    ));

    return array_merge([
        'balance_date' => $date,
        'status' => 'draft',
        'columns' => $columns,
        'sales_columns' => $salesCols,
        'recovery_columns' => $recoveryCols,
        'cash_columns' => $cashCols,
        'product_categories' => depot_category_order(),
        'sales' => rdc_enrich_sales_lines(rdc_blank_sales_lines($columns)),
        'recoveries' => [['label' => '', 'amounts' => rdc_empty_amounts($recoveryCols)]],
        'expenses' => rdc_blank_expense_lines($columns),
        'cash_out' => [['label' => '', 'amounts' => rdc_empty_amounts($recoveryCols)]],
        'cash_actual' => rdc_empty_amounts($cashCols),
        'expected_amount' => 0,
        'notes' => '',
    ], rdc_compute_totals([
        'sales' => rdc_enrich_sales_lines(rdc_blank_sales_lines($columns)),
        'recoveries' => [],
        'expenses' => rdc_blank_expense_lines($columns),
        'cash_actual' => rdc_empty_amounts($cashCols),
        'expected_amount' => 0,
    ]));
}

/** @return array<string, mixed> */
function rdc_sheet_to_response(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'balance_date' => $row['balance_date'],
        'status' => $row['status'],
        'columns' => json_decode($row['columns_json'], true) ?: [],
        'sales' => rdc_enrich_sales_lines(json_decode($row['sales_json'], true) ?: []),
        'recoveries' => json_decode($row['recoveries_json'], true) ?: [],
        'expenses' => json_decode($row['expenses_json'], true) ?: [],
        'cash_out' => json_decode($row['cash_out_json'] ?? '[]', true) ?: [],
        'cash_actual' => json_decode($row['cash_actual_json'], true) ?: [],
        'sales_total' => (float) $row['sales_total'],
        'recovery_total' => (float) $row['recovery_total'],
        'expenses_total' => (float) $row['expenses_total'],
        'grand_total' => (float) $row['grand_total'],
        'expected_amount' => (float) $row['expected_amount'],
        'actual_total' => (float) $row['actual_total'],
        'variance' => (float) $row['variance'],
        'notes' => $row['notes'],
        'submitted_at' => $row['submitted_at'],
        'reviewed_at' => $row['reviewed_at'] ?? null,
        'review_note' => $row['review_note'] ?? null,
        'reviewed_by' => isset($row['reviewed_by']) ? (int) $row['reviewed_by'] : null,
        'product_categories' => depot_category_order(),
    ];
}

/** Suggest sales qty from Lapok orders for a date. */
function rdc_suggest_sales_from_orders(string $date): array
{
    $stmt = db()->prepare(
        "SELECT p.name AS product_name, o.vehicle_id, SUM(oi.qty) AS qty, AVG(oi.unit_price) AS unit_price
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         JOIN products p ON p.id = oi.product_id
         WHERE DATE(o.created_at) = ? AND o.status NOT IN ('cancelled','draft')
         GROUP BY p.id, p.name, o.vehicle_id"
    );
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();

    $depotStmt = db()->prepare(
        "SELECT p.name AS product_name, SUM(oi.qty) AS qty, AVG(oi.unit_price) AS unit_price
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         JOIN products p ON p.id = oi.product_id
         WHERE DATE(o.created_at) = ? AND o.status NOT IN ('cancelled','draft') AND o.vehicle_id IS NULL
         GROUP BY p.id, p.name"
    );
    $depotStmt->execute([$date]);
    $depotRows = $depotStmt->fetchAll();

    return ['by_vehicle' => $rows, 'depot' => $depotRows];
}

function rdc_label_matches_product(string $label, string $productName): bool
{
    $a = preg_replace('/[^A-Z0-9]/', '', strtoupper($label));
    $b = preg_replace('/[^A-Z0-9]/', '', strtoupper($productName));
    if ($a === '' || $b === '') {
        return false;
    }
    return str_contains($a, $b) || str_contains($b, $a)
        || similar_text($a, $b) / max(strlen($a), strlen($b)) > 0.55;
}

function rdc_system_accountant_id(PDO $pdo): int
{
    $id = (int) ($pdo->query(
        "SELECT id FROM users WHERE role = 'accountant' AND is_active = 1 ORDER BY id LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }
    return (int) ($pdo->query(
        "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1"
    )->fetchColumn() ?: 1);
}

/**
 * Ensure sales/expense/cash structures include every current vehicle column.
 *
 * @param array<string, mixed> $sheet
 */
function rdc_ensure_sheet_vehicle_columns(array &$sheet, array $columns): void
{
    $vehicleKeys = [];
    foreach ($columns as $col) {
        if (str_starts_with((string) ($col['key'] ?? ''), 'vehicle_')) {
            $vehicleKeys[] = $col['key'];
        }
    }
    if (!$vehicleKeys) {
        return;
    }

    foreach ($sheet['sales'] ?? [] as &$line) {
        if (!isset($line['qty']) || !is_array($line['qty'])) {
            $line['qty'] = [];
        }
        foreach ($vehicleKeys as $key) {
            if (!array_key_exists($key, $line['qty'])) {
                $line['qty'][$key] = 0;
            }
        }
    }
    unset($line);

    foreach ($sheet['expenses'] ?? [] as &$row) {
        if (!isset($row['amounts']) || !is_array($row['amounts'])) {
            $row['amounts'] = [];
        }
        foreach ($vehicleKeys as $key) {
            if (!array_key_exists($key, $row['amounts'])) {
                $row['amounts'][$key] = 0;
            }
        }
    }
    unset($row);

    if (!isset($sheet['cash_actual']) || !is_array($sheet['cash_actual'])) {
        $sheet['cash_actual'] = [];
    }
    foreach ($vehicleKeys as $key) {
        if (!array_key_exists($key, $sheet['cash_actual'])) {
            $sheet['cash_actual'][$key] = 0;
        }
    }
}

/** @return list<string> */
function rdc_vehicle_column_keys(array $columns): array
{
    return array_values(array_filter(
        array_column($columns, 'key'),
        fn($key) => str_starts_with((string) $key, 'vehicle_')
    ));
}

/**
 * @param array<string, mixed> $sheet
 */
function rdc_clear_vehicle_cadet_slots(array &$sheet, array $vehicleKeys): void
{
    foreach ($sheet['sales'] ?? [] as &$line) {
        foreach ($vehicleKeys as $key) {
            if (isset($line['qty'][$key])) {
                $line['qty'][$key] = 0;
            }
        }
    }
    unset($line);

    foreach ($sheet['expenses'] ?? [] as &$row) {
        foreach ($vehicleKeys as $key) {
            if (isset($row['amounts'][$key])) {
                $row['amounts'][$key] = 0;
            }
        }
    }
    unset($row);

    foreach ($vehicleKeys as $key) {
        $sheet['cash_actual'][$key] = 0;
    }
}

/**
 * @param array<string, mixed> $sheet
 * @param array<string, mixed> $report
 */
function rdc_apply_cadet_report_to_sheet(array &$sheet, int $vehicleId, array $report): void
{
    require_once __DIR__ . '/depot_catalog.php';
    if ($vehicleId <= 0) {
        return;
    }

    $vehicleKey = 'vehicle_' . $vehicleId;
    $catalogByKey = depot_catalog_by_key();
    $byLabel = depot_catalog_by_label();

    foreach ($report['sales_lines'] ?? [] as $line) {
        $key = (string) ($line['rdc_key'] ?? '');
        if ($key === '' && !empty($line['rdc_label'])) {
            $catRow = $byLabel[strtoupper((string) $line['rdc_label'])] ?? null;
            $key = $catRow['key'] ?? '';
        }
        $qty = (int) ($line['qty_sold'] ?? 0);
        if ($key === '' || $qty <= 0) {
            continue;
        }
        $label = (string) ($catalogByKey[$key]['label'] ?? $line['rdc_label'] ?? '');
        foreach ($sheet['sales'] as &$salesLine) {
            if (strcasecmp((string) ($salesLine['label'] ?? ''), $label) !== 0) {
                continue;
            }
            if (!isset($salesLine['qty'][$vehicleKey])) {
                $salesLine['qty'][$vehicleKey] = 0;
            }
            $salesLine['qty'][$vehicleKey] = (float) $qty;
            break;
        }
        unset($salesLine);
    }

    $fuel = (float) ($report['fuel_expense'] ?? 0);
    $other = (float) ($report['other_expense'] ?? 0);
    if (!isset($sheet['expenses']) || !is_array($sheet['expenses'])) {
        $sheet['expenses'] = [];
    }
    // Must foreach the real $sheet['expenses'] array — `?? []` creates a temporary copy
    // so fuel/other never persist when assigned by reference.
    foreach ($sheet['expenses'] as &$expLine) {
        $label = strtoupper((string) ($expLine['label'] ?? ''));
        if ($fuel > 0 && $label === 'FUEL') {
            $expLine['amounts'][$vehicleKey] = $fuel;
        }
        // Put cadet "other" expense on OTHER only (not TRANSPORT) to avoid double-count
        if ($other > 0 && $label === 'OTHER') {
            $expLine['amounts'][$vehicleKey] = $other;
        }
    }
    unset($expLine);

    $cash = (float) ($report['cash_handed'] ?? 0);
    if ($cash > 0) {
        $sheet['cash_actual'][$vehicleKey] = $cash;
    }
}

/**
 * Rebuild RDC sheet vehicle columns from all cadet reports for a date.
 *
 * @return array<string, mixed>
 */
function rdc_sync_cadet_reports_into_sheet(PDO $pdo, string $date, bool $persist = true): array
{
    $columns = rdc_build_columns();
    $vehicleKeys = rdc_vehicle_column_keys($columns);
    $reports = rdc_cadet_reports_for_date($date);

    $existing = $pdo->prepare('SELECT * FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $existing->execute([$date]);
    $row = $existing->fetch();
    $locked = $row && in_array($row['status'], ['submitted', 'under_review', 'approved', 'rejected'], true);

    if ($locked) {
        return [
            'synced' => false,
            'reason' => 'sheet_locked',
            'reports' => count($reports),
            'vehicles' => [],
        ];
    }

    if ($row) {
        $sheet = [
            'sales' => json_decode($row['sales_json'], true) ?: [],
            'recoveries' => json_decode($row['recoveries_json'], true) ?: [],
            'expenses' => json_decode($row['expenses_json'], true) ?: [],
            'cash_out' => json_decode($row['cash_out_json'] ?? '[]', true) ?: [],
            'cash_actual' => json_decode($row['cash_actual_json'], true) ?: [],
            'expected_amount' => (float) $row['expected_amount'],
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    } else {
        $template = rdc_new_sheet_template($date);
        $sheet = [
            'sales' => $template['sales'],
            'recoveries' => $template['recoveries'],
            'expenses' => $template['expenses'],
            'cash_out' => $template['cash_out'],
            'cash_actual' => $template['cash_actual'],
            'expected_amount' => 0,
            'notes' => '',
        ];
    }

    rdc_ensure_sheet_vehicle_columns($sheet, $columns);
    rdc_clear_vehicle_cadet_slots($sheet, $vehicleKeys);

    $vehiclesApplied = [];
    foreach ($reports as $entry) {
        $vehicleId = (int) ($entry['vehicle_id'] ?? 0);
        $report = $entry['report'] ?? null;
        if ($vehicleId <= 0 || !is_array($report)) {
            continue;
        }
        rdc_apply_cadet_report_to_sheet($sheet, $vehicleId, $report);
        $vehiclesApplied[] = [
            'vehicle_id' => $vehicleId,
            'vehicle_key' => 'vehicle_' . $vehicleId,
            'registration' => $entry['registration'] ?? '',
            'cadet_name' => $entry['cadet_name'] ?? '',
            'sales_total' => (float) ($report['sales_total'] ?? 0),
            'cash_handed' => (float) ($report['cash_handed'] ?? 0),
            'flags' => $report['flags'] ?? [],
        ];
    }

    $notes = trim($sheet['notes'] ?? '');
    $syncStamp = '[CADET_VEHICLE_SYNC] ' . date('Y-m-d H:i') . ' · ' . count($vehiclesApplied) . ' vehicle(s)';
    if (!str_contains($notes, '[CADET_VEHICLE_SYNC]')) {
        $notes = trim($notes . "\n" . $syncStamp);
    } else {
        $notes = preg_replace('/\[CADET_VEHICLE_SYNC\].*$/m', $syncStamp, $notes) ?? $notes;
    }

    if (!$persist) {
        return [
            'synced' => true,
            'persisted' => false,
            'reports' => count($reports),
            'vehicles' => $vehiclesApplied,
        ];
    }

    $totals = rdc_compute_totals([
        'sales' => $sheet['sales'],
        'recoveries' => $sheet['recoveries'],
        'expenses' => $sheet['expenses'],
        'cash_actual' => $sheet['cash_actual'],
        'expected_amount' => $sheet['expected_amount'] ?? null,
    ]);

    $fields = [
        json_encode($sheet['sales'], JSON_UNESCAPED_UNICODE),
        json_encode($sheet['recoveries'], JSON_UNESCAPED_UNICODE),
        json_encode($sheet['expenses'], JSON_UNESCAPED_UNICODE),
        json_encode($sheet['cash_out'], JSON_UNESCAPED_UNICODE),
        json_encode($sheet['cash_actual'], JSON_UNESCAPED_UNICODE),
        $totals['sales_total'],
        $totals['recovery_total'],
        $totals['expenses_total'],
        $totals['grand_total'],
        $totals['expected_amount'],
        $totals['actual_total'],
        $totals['variance'],
        json_encode($columns, JSON_UNESCAPED_UNICODE),
        $notes !== '' ? $notes : null,
    ];

    if ($row) {
        $pdo->prepare(
            'UPDATE rdc_daily_sheets SET
                sales_json = ?, recoveries_json = ?, expenses_json = ?, cash_out_json = ?,
                cash_actual_json = ?, sales_total = ?, recovery_total = ?, expenses_total = ?,
                grand_total = ?, expected_amount = ?, actual_total = ?, variance = ?,
                columns_json = ?, notes = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute(array_merge($fields, [(int) $row['id']]));
        $sheetId = (int) $row['id'];
    } else {
        $creatorId = rdc_system_accountant_id($pdo);
        $pdo->prepare(
            'INSERT INTO rdc_daily_sheets
             (balance_date, sales_json, recoveries_json, expenses_json, cash_out_json, cash_actual_json,
              sales_total, recovery_total, expenses_total, grand_total, expected_amount, actual_total,
              variance, columns_json, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge([$date], $fields, [$creatorId]));
        $sheetId = (int) $pdo->lastInsertId();
    }

    return [
        'synced' => true,
        'persisted' => true,
        'sheet_id' => $sheetId,
        'reports' => count($reports),
        'vehicles' => $vehiclesApplied,
        'totals' => $totals,
    ];
}

/**
 * Merge a submitted cadet report into today's RDC balancing sheet.
 *
 * @return array<string, mixed>
 */
function rdc_merge_cadet_report(PDO $pdo, int $tripId, array $report): array
{
    $tStmt = $pdo->prepare('SELECT id, vehicle_id, cadet_id, returned_at FROM delivery_trips WHERE id = ?');
    $tStmt->execute([$tripId]);
    $trip = $tStmt->fetch();
    if (!$trip) {
        return ['merged' => false, 'reason' => 'trip_not_found'];
    }

    $vehicleId = (int) ($trip['vehicle_id'] ?? 0);
    $cadetId = (int) ($report['cadet_id'] ?? $trip['cadet_id'] ?? 0);
    if ($vehicleId <= 0 || $cadetId <= 0) {
        return ['merged' => false, 'reason' => 'missing_vehicle_or_cadet'];
    }

    $balanceDate = date('Y-m-d', strtotime((string) ($trip['returned_at'] ?? 'now')));
    $sync = rdc_sync_cadet_reports_into_sheet($pdo, $balanceDate, true);

    if (!($sync['synced'] ?? false)) {
        return array_merge(['merged' => false, 'balance_date' => $balanceDate], $sync);
    }

    return [
        'merged' => true,
        'balance_date' => $balanceDate,
        'sheet_id' => $sync['sheet_id'] ?? null,
        'vehicle_key' => 'vehicle_' . $vehicleId,
        'vehicle_id' => $vehicleId,
        'cadet_id' => $cadetId,
        'vehicles' => $sync['vehicles'] ?? [],
        'totals' => $sync['totals'] ?? null,
    ];
}

/**
 * RDC corrects a submitted cadet report (source of truth on the trip), then re-syncs the sheet.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function rdc_update_cadet_report(PDO $pdo, int $tripId, array $body, int $editorId, string $editorName): array
{
    require_once __DIR__ . '/cadet_reports.php';
    require_once __DIR__ . '/depot_catalog.php';

    $stmt = $pdo->prepare(
        "SELECT dt.*, v.registration, u.full_name AS cadet_name
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         LEFT JOIN users u ON u.id = dt.cadet_id
         WHERE dt.id = ? LIMIT 1"
    );
    $stmt->execute([$tripId]);
    $trip = $stmt->fetch();
    if (!$trip) {
        throw new RuntimeException('Cadet trip not found');
    }
    if ((string) $trip['status'] !== 'returned') {
        throw new RuntimeException('Only returned cadet reports can be corrected');
    }

    $existing = cadet_parse_report_note($trip['notes'] ?? null);
    if (!$existing) {
        throw new RuntimeException('No cadet report found on this trip');
    }

    $balanceDate = date('Y-m-d', strtotime((string) ($trip['returned_at'] ?? 'now')));
    $lock = $pdo->prepare('SELECT status FROM rdc_daily_sheets WHERE balance_date = ? LIMIT 1');
    $lock->execute([$balanceDate]);
    $sheetStatus = $lock->fetchColumn();
    if ($sheetStatus && in_array($sheetStatus, ['submitted', 'under_review', 'approved', 'rejected'], true)) {
        throw new RuntimeException('Today\'s RDC sheet is locked — reopen it before correcting cadet reports');
    }

    $catalogByKey = [];
    $loaded = depot_trip_loaded_by_rdc_key($tripId);
    foreach (depot_rdc_sales_catalog() as $row) {
        $catalogByKey[$row['key']] = array_merge($row, [
            'unit_price' => (float) $row['price'],
            'qty_loaded' => (int) ($loaded[$row['key']] ?? 0),
        ]);
    }

    $salesInput = is_array($body['sales_lines'] ?? null) ? $body['sales_lines'] : [];
    $enriched = [];
    foreach ($salesInput as $line) {
        if (!is_array($line)) {
            continue;
        }
        $key = (string) ($line['rdc_key'] ?? '');
        if ($key !== '' && isset($catalogByKey[$key])) {
            $line['qty_loaded'] = $catalogByKey[$key]['qty_loaded'];
        }
        $enriched[] = $line;
    }
    $salesLines = cadet_normalize_sales_lines($enriched, $catalogByKey);
    $salesTotal = array_sum(array_map(fn($line) => (float) $line['amount'], $salesLines));

    $fuel = max(0, (float) ($body['fuel_expense'] ?? 0));
    $other = max(0, (float) ($body['other_expense'] ?? 0));
    $cashHanded = max(0, (float) ($body['cash_handed'] ?? 0));
    $note = trim((string) ($body['note'] ?? ''));

    $flags = cadet_compute_flags($salesTotal, $cashHanded, $fuel, $other, $note, $salesLines);
    $report = [
        'sales_total' => $salesTotal,
        'sales_lines' => $salesLines,
        'fuel_expense' => $fuel,
        'other_expense' => $other,
        'cash_handed' => $cashHanded,
        'note' => $note,
        'flags' => $flags,
        'submitted_at' => $existing['submitted_at'] ?? date('c'),
        'cadet_id' => (int) ($existing['cadet_id'] ?? $trip['cadet_id'] ?? 0),
        'cadet_name' => (string) ($existing['cadet_name'] ?? $trip['cadet_name'] ?? 'Cadet'),
        'corrected_at' => date('c'),
        'corrected_by' => $editorId,
        'corrected_by_name' => $editorName,
    ];

    $notes = '[CADET_REPORT] ' . json_encode($report, JSON_UNESCAPED_UNICODE);
    if ($note !== '') {
        $notes .= "\n" . $note;
    }
    $notes .= "\n[RDC_CORRECTED] " . date('Y-m-d H:i') . ' by ' . $editorName;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE delivery_trips
             SET fuel_cost = ?, cash_reported = ?, notes = ?
             WHERE id = ?'
        )->execute([$fuel + $other, $cashHanded, $notes, $tripId]);

        // Reset sold qty then re-apply corrected lines
        $pdo->prepare('UPDATE trip_load_items SET qty_sold = 0, qty_returned = qty_loaded WHERE trip_id = ?')
            ->execute([$tripId]);
        cadet_apply_trip_sales($pdo, $tripId, $salesLines);

        $sync = rdc_sync_cadet_reports_into_sheet($pdo, $balanceDate, true);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit_log($editorId, 'delivery_trips', $tripId, 'rdc_correct_cadet_report', $existing, $report);

    try {
        require_once __DIR__ . '/notifications.php';
        $cadetId = (int) ($report['cadet_id'] ?? 0);
        if ($cadetId > 0) {
            notify_user($cadetId, 'RDC corrected your report', sprintf(
                'Accountant updated trip #%d (%s). New sales UGX %s, cash UGX %s.',
                $tripId,
                (string) ($trip['registration'] ?? 'vehicle'),
                number_format($salesTotal, 0),
                number_format($cashHanded, 0)
            ), [
                'sender_id' => $editorId,
                'sender_role' => 'accountant',
                'severity' => count($flags) > 0 ? 'warning' : 'info',
                'link_page' => 'cadet-daily',
            ]);
        }
    } catch (Throwable) {
    }

    return [
        'trip_id' => $tripId,
        'balance_date' => $balanceDate,
        'report' => $report,
        'sync' => $sync,
        'flags' => $flags,
    ];
}

/** @return list<array<string, mixed>> */
function rdc_cadet_reports_for_date(string $date): array
{
    require_once __DIR__ . '/cadet_reports.php';
    $stmt = db()->prepare(
        "SELECT dt.id, dt.vehicle_id, dt.cadet_id, dt.returned_at, dt.notes, dt.cash_reported,
                v.registration, u.full_name AS cadet_name
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         LEFT JOIN users u ON u.id = dt.cadet_id
         WHERE dt.status = 'returned' AND DATE(dt.returned_at) = ?
           AND dt.notes LIKE '%[CADET_REPORT]%'
         ORDER BY dt.returned_at ASC"
    );
    $stmt->execute([$date]);
    $reports = [];
    foreach ($stmt->fetchAll() as $row) {
        $parsed = cadet_parse_report_note($row['notes'] ?? null);
        if (!$parsed) {
            continue;
        }
        $reports[] = [
            'trip_id' => (int) $row['id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'vehicle_key' => 'vehicle_' . (int) $row['vehicle_id'],
            'cadet_id' => (int) ($row['cadet_id'] ?? 0),
            'registration' => $row['registration'],
            'cadet_name' => $row['cadet_name'] ?: ($parsed['cadet_name'] ?? 'Cadet'),
            'returned_at' => $row['returned_at'],
            'sales_total' => (float) ($parsed['sales_total'] ?? 0),
            'cash_handed' => (float) ($parsed['cash_handed'] ?? $row['cash_reported'] ?? 0),
            'fuel_expense' => (float) ($parsed['fuel_expense'] ?? 0),
            'other_expense' => (float) ($parsed['other_expense'] ?? 0),
            'note' => (string) ($parsed['note'] ?? ''),
            'corrected_at' => $parsed['corrected_at'] ?? null,
            'corrected_by_name' => $parsed['corrected_by_name'] ?? null,
            'flags' => $parsed['flags'] ?? [],
            'sales_lines' => $parsed['sales_lines'] ?? [],
            'report' => $parsed,
        ];
    }
    return $reports;
}
