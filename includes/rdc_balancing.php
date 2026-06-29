<?php
declare(strict_types=1);

/** Default product/brand rows matching the depot Excel workbook. */
function rdc_default_sales_lines(): array
{
    return [
        ['label' => '300ML', 'price' => 18500],
        ['label' => 'PET-330ML', 'price' => 10000],
        ['label' => 'PET-500ML', 'price' => 15000],
        ['label' => 'PET-2000ML', 'price' => 25500],
        ['label' => '400ML M.MAIDS', 'price' => 25000],
        ['label' => '1LITRES M/MAIDS', 'price' => 25500],
        ['label' => 'PREDATOR', 'price' => 17500],
        ['label' => 'REFRESH-250ML', 'price' => 10000],
        ['label' => 'REFRESH-500ML', 'price' => 15000],
        ['label' => 'RWENZORI 500MLS-BOX', 'price' => 17400],
        ['label' => 'RWENZORI 500MLS-SHRINKS', 'price' => 10000],
        ['label' => 'RWENZORI 1.5MLS-BOX', 'price' => 18600],
        ['label' => 'JUMBO-BIG', 'price' => 10800],
        ['label' => 'JUMBO-SMALL', 'price' => 8000],
        ['label' => 'BOTTLES', 'price' => 400],
        ['label' => 'SHELL', 'price' => 6400],
    ];
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
        "SELECT id, registration, vehicle_type FROM vehicles ORDER BY vehicle_type, registration"
    )->fetchAll();
    foreach ($vehicles as $v) {
        $cols[] = [
            'key' => 'vehicle_' . $v['id'],
            'label' => strtoupper((string) $v['registration']),
            'section' => 'sales_expense',
            'vehicle_id' => (int) $v['id'],
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
    ));

    return [
        'balance_date' => $date,
        'status' => 'draft',
        'columns' => $columns,
        'sales_columns' => $salesCols,
        'recovery_columns' => $recoveryCols,
        'cash_columns' => $cashCols,
        'sales' => rdc_blank_sales_lines($columns),
        'recoveries' => [['label' => '', 'amounts' => rdc_empty_amounts($recoveryCols)]],
        'expenses' => rdc_blank_expense_lines($columns),
        'cash_out' => [['label' => '', 'amounts' => rdc_empty_amounts($recoveryCols)]],
        'cash_actual' => rdc_empty_amounts($cashCols),
        'expected_amount' => 0,
        'notes' => '',
        ...rdc_compute_totals([
            'sales' => rdc_blank_sales_lines($columns),
            'recoveries' => [],
            'expenses' => rdc_blank_expense_lines($columns),
            'cash_actual' => rdc_empty_amounts($cashCols),
            'expected_amount' => 0,
        ]),
    ];
}

/** @return array<string, mixed> */
function rdc_sheet_to_response(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'balance_date' => $row['balance_date'],
        'status' => $row['status'],
        'columns' => json_decode($row['columns_json'], true) ?: [],
        'sales' => json_decode($row['sales_json'], true) ?: [],
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
