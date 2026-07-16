<?php
declare(strict_types=1);

/** LAPOK book page 2 — auxiliary information rows (per vehicle). */
function cadet_auxiliary_defaults(): array
{
    return [
        'fuel' => 0.0,
        'lunch' => 0.0,
        'discount' => 0.0,
        'shortage' => 0.0,
        'repairs' => 0.0,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{fuel: float, lunch: float, discount: float, shortage: float, repairs: float}
 */
function cadet_normalize_auxiliary(array $input): array
{
    $aux = cadet_auxiliary_defaults();
    if (isset($input['auxiliary']) && is_array($input['auxiliary'])) {
        foreach (array_keys($aux) as $key) {
            $aux[$key] = max(0.0, (float) ($input['auxiliary'][$key] ?? 0));
        }
        return $aux;
    }
    $aux['fuel'] = max(0.0, (float) ($input['fuel_expense'] ?? 0));
    $aux['lunch'] = max(0.0, (float) ($input['lunch_expense'] ?? 0));
    $aux['discount'] = max(0.0, (float) ($input['discount'] ?? 0));
    $aux['shortage'] = max(0.0, (float) ($input['shortage'] ?? 0));
    $aux['repairs'] = max(0.0, (float) ($input['repairs_expense'] ?? $input['other_expense'] ?? 0));
    return $aux;
}

/** @param array{fuel: float, lunch: float, discount: float, shortage: float, repairs: float} $aux */
function cadet_auxiliary_total(array $aux): float
{
    return round(array_sum(array_map('floatval', $aux)), 2);
}

/** @param array<string, mixed> $report */
function cadet_attach_auxiliary(array $report, array $aux): array
{
    $report['auxiliary'] = $aux;
    $report['fuel_expense'] = $aux['fuel'];
    $report['lunch_expense'] = $aux['lunch'];
    $report['discount'] = $aux['discount'];
    $report['shortage'] = $aux['shortage'];
    $report['repairs_expense'] = $aux['repairs'];
    $report['other_expense'] = round($aux['lunch'] + $aux['discount'] + $aux['shortage'] + $aux['repairs'], 2);
    return $report;
}

function cadet_parse_report_note(?string $notes): ?array
{
    if (!$notes || !str_contains($notes, '[CADET_REPORT]')) {
        return null;
    }
    if (!preg_match('/\[CADET_REPORT\]\s*(\{.*\})/s', $notes, $m)) {
        return null;
    }
    $data = json_decode($m[1], true);
    if (!is_array($data)) {
        return null;
    }
    return cadet_attach_auxiliary($data, cadet_normalize_auxiliary($data));
}

/**
 * @param array<string, array<string, mixed>> $catalogByKey
 * @return list<array<string, mixed>>
 */
function cadet_normalize_sales_lines(array $lines, array $catalogByKey): array
{
    require_once __DIR__ . '/depot_catalog.php';
    $byLabel = depot_catalog_by_label();
    $normalized = [];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $key = (string) ($line['rdc_key'] ?? '');
        if ($key === '' && !empty($line['rdc_label'])) {
            $catRow = $byLabel[strtoupper((string) $line['rdc_label'])] ?? null;
            $key = $catRow['key'] ?? '';
        }
        $key = depot_normalize_rdc_key($key);
        if ($key === '' && !empty($line['rdc_label'])) {
            $mapped = depot_map_product_to_rdc_key((string) $line['rdc_label']);
            $key = $mapped ?? '';
        }
        $qtySold = max(0, (int) ($line['qty_sold'] ?? 0));
        if ($key === '' || $qtySold <= 0 || !isset($catalogByKey[$key])) {
            continue;
        }
        $catalog = $catalogByKey[$key];
        $unitPrice = (float) ($catalog['unit_price'] ?? $catalog['price'] ?? 0);
        // Cadet-side amounts are always server-computed from the fixed catalog price.
        // This prevents browser edits/tampering from changing stored sales value.
        $amount = $qtySold * $unitPrice;
        $normalized[] = [
            'rdc_key' => $key,
            'rdc_label' => (string) ($catalog['label'] ?? ''),
            'category' => (string) ($catalog['category'] ?? ''),
            'qty_loaded' => (int) ($line['qty_loaded'] ?? $catalog['qty_loaded'] ?? 0),
            'qty_sold' => $qtySold,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ];
    }
    return $normalized;
}

/**
 * @param list<array<string, mixed>> $salesLines
 */
function cadet_compute_flags(float $sales, float $cash, float $fuel, float $other, ?string $note, array $salesLines = []): array
{
    $flags = [];
    $expenses = $fuel + $other;
    if ($sales > 0 && abs($sales - $cash) > 5000) {
        $flags[] = 'cash_variance';
    }
    if ($sales > 0 && $expenses > ($sales * 0.35)) {
        $flags[] = 'high_expense';
    }
    if ($sales <= 0) {
        $flags[] = 'missing_sales';
    }
    foreach ($salesLines as $line) {
        $loaded = (int) ($line['qty_loaded'] ?? 0);
        $sold = (int) ($line['qty_sold'] ?? 0);
        if ($loaded > 0 && $sold > $loaded) {
            $flags[] = 'oversold';
            break;
        }
    }
    $hour = (int) date('G');
    $min = (int) date('i');
    if ($hour * 60 + $min > (19 * 60 + 30)) {
        $flags[] = 'late_submit';
    }
    if (in_array('cash_variance', $flags, true) && trim((string) $note) === '') {
        $flags[] = 'needs_note';
    }
    return array_values(array_unique($flags));
}

function cadet_flag_labels(array $flags): string
{
    $map = [
        'cash_variance' => 'Cash vs sales mismatch',
        'high_expense' => 'High expenses vs sales',
        'missing_sales' => 'Sales not recorded',
        'oversold' => 'Sold more than loaded',
        'late_submit' => 'Submitted after 7:30 PM',
        'needs_note' => 'Explanation required',
    ];
    return implode('; ', array_map(fn($f) => $map[$f] ?? $f, $flags));
}

function cadet_sales_summary(array $salesLines): string
{
    if (!$salesLines) {
        return 'No product sales';
    }
    $parts = [];
    foreach ($salesLines as $line) {
        $name = (string) ($line['rdc_label'] ?? $line['product_name'] ?? 'Product');
        $qty = (int) ($line['qty_sold'] ?? 0);
        $parts[] = $name . ' ×' . $qty;
    }
    return implode(', ', $parts);
}

/** @param list<array<string, mixed>> $salesLines */
function cadet_apply_trip_sales(PDO $pdo, int $tripId, array $salesLines): void
{
    require_once __DIR__ . '/depot_catalog.php';
    if (!$salesLines) {
        return;
    }

    $loadStmt = $pdo->prepare(
        'SELECT tli.product_id, tli.qty_loaded, p.name, p.sku
         FROM trip_load_items tli
         JOIN products p ON p.id = tli.product_id
         WHERE tli.trip_id = ?'
    );
    $loadStmt->execute([$tripId]);
    $loads = $loadStmt->fetchAll();
    if (!$loads) {
        return;
    }

    $soldByKey = [];
    foreach ($salesLines as $line) {
        $soldByKey[$line['rdc_key']] = (int) ($line['qty_sold'] ?? 0);
    }

    $applied = [];
    foreach ($loads as $row) {
        $key = depot_map_product_to_rdc_key((string) $row['name'], (string) $row['sku']);
        if (!$key || !isset($soldByKey[$key]) || isset($applied[$key])) {
            continue;
        }
        $qtySold = $soldByKey[$key];
        $qtyLoaded = (int) $row['qty_loaded'];
        $pdo->prepare(
            'UPDATE trip_load_items SET qty_sold = ?, qty_returned = ? WHERE trip_id = ? AND product_id = ?'
        )->execute([
            $qtySold,
            max(0, $qtyLoaded - $qtySold),
            $tripId,
            (int) $row['product_id'],
        ]);
        $applied[$key] = true;
    }
}

function cadet_today_date(?DateTimeInterface $when = null): string
{
    return ($when ?? new DateTimeImmutable('now'))->format('Y-m-d');
}

/**
 * Today's operative trip for a cadet/driver (ignores stale seed/demo trips from other dates).
 *
 * @return array<string, mixed>|null
 */
function cadet_fetch_today_trip(PDO $pdo, int $userId, ?string $date = null): ?array
{
    $date ??= cadet_today_date();
    $stmt = $pdo->prepare(
        "SELECT dt.*, v.registration, v.vehicle_type, r.name AS route_name
         FROM delivery_trips dt
         JOIN vehicles v ON v.id = dt.vehicle_id
         LEFT JOIN routes r ON r.id = dt.route_id
         WHERE (dt.cadet_id = ? OR dt.driver_id = ?)
           AND dt.status IN ('dispatched','on_route','returned')
           AND (DATE(dt.dispatched_at) = ? OR DATE(dt.returned_at) = ?)
         ORDER BY FIELD(dt.status, 'on_route', 'dispatched', 'returned'), dt.dispatched_at DESC
         LIMIT 1"
    );
    $stmt->execute([$userId, $userId, $date, $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @param array<string, mixed>|null $trip */
function cadet_trip_report_submitted(?array $trip): bool
{
    if (!$trip || ($trip['status'] ?? '') !== 'returned') {
        return false;
    }
    return cadet_parse_report_note($trip['notes'] ?? null) !== null;
}

function cadet_report_submitted_today(PDO $pdo, int $userId, ?string $date = null): bool
{
    return cadet_trip_report_submitted(cadet_fetch_today_trip($pdo, $userId, $date));
}

/** Cadets who have a trip today but have not submitted today's report yet. */
function cadet_pending_report_user_ids(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id
         FROM users u
         JOIN delivery_trips dt ON dt.cadet_id = u.id
         WHERE u.is_active = 1
           AND u.role IN ('cadet','field_user')
           AND (DATE(dt.dispatched_at) = ? OR DATE(dt.returned_at) = ?)
           AND (
                dt.status IN ('dispatched','on_route')
                OR (
                    dt.status IN ('returned','completed')
                    AND (dt.notes IS NULL OR dt.notes NOT LIKE '%[CADET_REPORT]%')
                )
           )"
    );
    $stmt->execute([$date, $date]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}
